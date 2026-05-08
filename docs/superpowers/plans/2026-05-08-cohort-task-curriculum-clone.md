# Cohort Task / Curriculum Clone — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** boot 의 11기 task 544건과 curriculum_items 139건을 12기로 1회성 복제하는 PHP CLI 스크립트를 만든다. assignee 매핑은 `admin_roles` (M:N) 기반 fan-out, 날짜는 시작일 기준 +49일 shift, dry-run/force/멱등성 안전장치 포함.

**Architecture:** 1개 PHP 파일(`migrate_clone_cohort_tasks.php`) + 1개 invariant test(`tests/cohort_clone_invariants.php`). 단일 트랜잭션 안에서 (1) 11기 task 를 (role, title, content, day-offset) 로 dedupe → templates, (2) role 별 12기 후보 결정(`admin_roles` JOIN, `bootcamp_members` 또는 cohort=NULL operation 보존), (3) template × 후보 cartesian 으로 12기 row 생성, (4) curriculum INSERT...SELECT 로 단순 date-shift. dry-run 시 ROLLBACK, 성공 시 COMMIT.

**Tech Stack:** PHP 8 + MariaDB. boot 의 기존 migrate 스크립트 패턴(`migrate_qr_audit.php`, `migrate_backfill_user_id.php`) 따름. invariant 테스트는 `tests/transaction_invariants.php` 패턴(outer transaction + rollback).

**Spec:** `docs/superpowers/specs/2026-05-08-cohort-task-curriculum-clone-design.md`

---

## File Structure

**신규 파일:**
- `migrate_clone_cohort_tasks.php` — CLI 마이그레이션 스크립트 (boot-dev 루트)
- `tests/cohort_clone_invariants.php` — 헬퍼·dry-run 통합 테스트

**수정 파일:** 없음

---

## Task 1: 스크립트 스켈레톤 + CLI 인자 파서 + 사용법

**Files:**
- Create: `migrate_clone_cohort_tasks.php`

- [ ] **Step 1: 빈 스크립트 생성 + 헤더 + CLI guard + 인자 파서**

```php
<?php
/**
 * boot.soritune.com - 기수 task / curriculum_items 복제
 * 11기 → 12기 같은 1회성 cohort transition 시 사용.
 *
 * 사용:
 *   php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run
 *   php migrate_clone_cohort_tasks.php --from=11기 --to=12기
 *   php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --force
 *   php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --allow-missing-roles
 *
 * 멱등: 대상 cohort 에 이미 task/curriculum 있으면 abort. --force 로 DELETE 후 재생성.
 * dry-run: BEGIN/INSERT 후 ROLLBACK. 변경 없음.
 *
 * 매핑 규칙:
 *   - role IN (head, coach, sub_coach, subhead1, subhead2): admin_roles JOIN (cohort=대상기수)
 *   - role = operation: 11기 assignee_admin_id 그대로 (cohort=NULL 인 binnie4·wannie 공유)
 *   - role IN (leader, subleader): bootcamp_members (cohort_id=대상기수, member_role=role, is_active=1)
 *   - leader unassigned (NULL): NULL 그대로 단일 row 복제
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/public_html/config.php';

$opts = getopt('', ['from:', 'to:', 'dry-run', 'force', 'allow-missing-roles', 'help']);

if (isset($opts['help']) || empty($opts['from']) || empty($opts['to'])) {
    fwrite(STDERR, "사용: php migrate_clone_cohort_tasks.php --from=<cohort> --to=<cohort> [--dry-run] [--force] [--allow-missing-roles]\n");
    fwrite(STDERR, "예: php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run\n");
    exit(1);
}

$fromCohort = (string)$opts['from'];
$toCohort   = (string)$opts['to'];
$dryRun     = isset($opts['dry-run']);
$force      = isset($opts['force']);
$allowMissingRoles = isset($opts['allow-missing-roles']);

$db = getDB();
$dbName = $db->query("SELECT DATABASE()")->fetchColumn();

echo "=== Cohort Clone: {$fromCohort} → {$toCohort} ===\n";
echo "DB: {$dbName}\n";
echo "Mode: " . ($dryRun ? 'DRY-RUN' : 'APPLY') . ($force ? ' (FORCE)' : '') . "\n\n";
```

- [ ] **Step 2: 실행 확인**

Run: `cd /root/boot-dev && php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run`
Expected:
```
=== Cohort Clone: 11기 → 12기 ===
DB: SORITUNECOM_DEV_BOOT
Mode: DRY-RUN
```
(이후 단계가 없어 그대로 정상 종료)

- [ ] **Step 3: 인자 누락 시 에러**

Run: `php migrate_clone_cohort_tasks.php`
Expected (stderr):
```
사용: php migrate_clone_cohort_tasks.php --from=<cohort> --to=<cohort> [--dry-run] [--force] [--allow-missing-roles]
...
```
exit code 1.

- [ ] **Step 4: Commit**

```bash
git add migrate_clone_cohort_tasks.php
git commit -m "feat(cohort-clone): script skeleton + CLI args"
```

---

## Task 2: cohort 룩업 + day-offset 계산

**Files:**
- Modify: `migrate_clone_cohort_tasks.php` (Step 1 의 `echo "Mode: ..."` 다음에 이어서)

- [ ] **Step 1: cohort 정보 가져오기 + day-offset 계산**

Step 1 의 `echo "Mode: ..." . "\n\n";` 직후에 추가:

```php
// ── cohort 정보 ─────────────────────────────────────────────
$cohortStmt = $db->prepare("SELECT id, cohort, start_date, end_date FROM cohorts WHERE cohort = ?");

$cohortStmt->execute([$fromCohort]);
$fromRow = $cohortStmt->fetch(PDO::FETCH_ASSOC);
if (!$fromRow) {
    fwrite(STDERR, "원본 cohort '{$fromCohort}' 가 cohorts 테이블에 없습니다\n");
    exit(2);
}

$cohortStmt->execute([$toCohort]);
$toRow = $cohortStmt->fetch(PDO::FETCH_ASSOC);
if (!$toRow) {
    fwrite(STDERR, "대상 cohort '{$toCohort}' 가 cohorts 테이블에 없습니다\n");
    exit(2);
}

$fromStart = new DateTime($fromRow['start_date']);
$toStart   = new DateTime($toRow['start_date']);
$dayOffset = (int)$fromStart->diff($toStart)->format('%r%a');

echo "[cohort 정보]\n";
echo "  {$fromCohort} 시작: {$fromRow['start_date']}\n";
echo "  {$toCohort} 시작: {$toRow['start_date']}\n";
echo "  Day shift: " . ($dayOffset >= 0 ? "+{$dayOffset}" : (string)$dayOffset) . "일\n\n";
```

- [ ] **Step 2: 실행 확인 (DEV)**

Run: `cd /root/boot-dev && php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run`
Expected:
```
[cohort 정보]
  11기 시작: 2026-03-10
  12기 시작: 2026-05-11
  Day shift: +62일
```
(DEV 의 11기 start_date 가 PROD 와 다르다 — DEV 는 stub. PROD 검증은 별도 단계)

- [ ] **Step 3: cohort 누락 시 abort 확인**

Run: `php migrate_clone_cohort_tasks.php --from=99기 --to=12기 --dry-run`
Expected stderr: `원본 cohort '99기' 가 cohorts 테이블에 없습니다`, exit 2.

- [ ] **Step 4: Commit**

```bash
git add migrate_clone_cohort_tasks.php
git commit -m "feat(cohort-clone): cohort lookup + day-offset"
```

---

## Task 3: 사전 검증 (멱등 가드 + role 후보 검증)

**Files:**
- Modify: `migrate_clone_cohort_tasks.php` (Task 2 끝에 이어서)

- [ ] **Step 1: 대상 cohort 가 비어있는지 확인 + role 후보 조회 헬퍼**

Task 2 의 `echo "  Day shift: ..." . "\n\n";` 직후에 추가:

```php
// ── 멱등 가드: 대상 cohort 에 이미 데이터 있나? ───────────────
$countTasksStmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE cohort = ?");
$countTasksStmt->execute([$toCohort]);
$nTasks = (int)$countTasksStmt->fetchColumn();

$countCurStmt = $db->prepare("SELECT COUNT(*) FROM curriculum_items WHERE cohort = ?");
$countCurStmt->execute([$toCohort]);
$nCur = (int)$countCurStmt->fetchColumn();

if (($nTasks > 0 || $nCur > 0) && !$force) {
    fwrite(STDERR, "대상 cohort '{$toCohort}' 에 이미 task {$nTasks}건 / curriculum {$nCur}건 존재.\n");
    fwrite(STDERR, "  --force 사용하거나 수동 삭제 후 재실행하세요.\n");
    exit(3);
}

if ($nTasks > 0 || $nCur > 0) {
    echo "[--force] 대상 기존 데이터 task {$nTasks} + curriculum {$nCur} 건 삭제 예정\n\n";
}

// ── 11기 source 데이터 사전 통계 ────────────────────────────
$srcRoleStats = $db->prepare("
    SELECT role,
           COUNT(*) AS n,
           SUM(assignee_admin_id IS NOT NULL) AS admin_assigned,
           SUM(assignee_member_id IS NOT NULL) AS member_assigned,
           SUM(assignee_admin_id IS NULL AND assignee_member_id IS NULL) AS unassigned
    FROM tasks WHERE cohort = ?
    GROUP BY role ORDER BY role
");
$srcRoleStats->execute([$fromCohort]);
$srcRoles = $srcRoleStats->fetchAll(PDO::FETCH_ASSOC);

echo "[원본 task 분포 ({$fromCohort})]\n";
printf("  %-12s %6s %6s %6s %6s\n", 'role', 'n', 'admin', 'member', 'null');
foreach ($srcRoles as $r) {
    printf("  %-12s %6d %6d %6d %6d\n",
        $r['role'], $r['n'], $r['admin_assigned'], $r['member_assigned'], $r['unassigned']);
}
echo "\n";

$countCurStmt->execute([$fromCohort]);
$srcCur = (int)$countCurStmt->fetchColumn();
echo "[원본 curriculum_items ({$fromCohort})]: {$srcCur}건\n\n";

// ── 12기 후보 검증 (role 별 ≥ 1) ──────────────────────────
$srcUsedRoles = array_column($srcRoles, 'role');

$candidateCounts = [];
foreach ($srcUsedRoles as $role) {
    $candidateCounts[$role] = countCandidates($db, $role, $toCohort);
}

echo "[대상 후보 ({$toCohort})]\n";
foreach ($candidateCounts as $role => $count) {
    printf("  %-12s %6d명\n", $role, $count);
}
echo "\n";

$missing = array_filter($candidateCounts, fn($c) => $c === 0);
if ($missing && !$allowMissingRoles) {
    fwrite(STDERR, "다음 role 의 대상 후보가 0명입니다: " . implode(', ', array_keys($missing)) . "\n");
    fwrite(STDERR, "  admin_roles 등록 후 재실행하거나 --allow-missing-roles 사용\n");
    exit(4);
}
```

- [ ] **Step 2: countCandidates() 헬퍼 함수 추가**

스크립트 맨 끝에 추가 (require_once 가 위에 있으므로 함수 정의는 어디든 OK 이지만 가독성 위해 require_once 직후, `$opts = getopt(...)` 직전에 두면 좋음. 또는 파일 맨 아래도 가능):

```php
/**
 * 특정 role 의 대상 cohort 후보 수.
 * - admin role (head/coach/sub_coach/subhead1/subhead2): admin_roles JOIN (admins.cohort = $toCohort)
 * - operation: cohort=NULL 인 active operation admin (binnie4/wannie 등)
 * - leader/subleader: bootcamp_members (cohort_id, member_role)
 */
function countCandidates(PDO $db, string $role, string $toCohort): int {
    if (in_array($role, ['head', 'coach', 'sub_coach', 'subhead1', 'subhead2'], true)) {
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT a.id)
            FROM admins a JOIN admin_roles ar ON ar.admin_id = a.id
            WHERE a.cohort = ? AND ar.role = ? AND a.is_active = 1
        ");
        $stmt->execute([$toCohort, $role]);
        return (int)$stmt->fetchColumn();
    }
    if ($role === 'operation') {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM admins
            WHERE cohort IS NULL AND role = 'operation' AND is_active = 1
        ");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }
    if (in_array($role, ['leader', 'subleader'], true)) {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM bootcamp_members bm
            JOIN cohorts c ON c.id = bm.cohort_id
            WHERE c.cohort = ? AND bm.member_role = ? AND bm.is_active = 1
        ");
        $stmt->execute([$toCohort, $role]);
        return (int)$stmt->fetchColumn();
    }
    return 0;
}
```

- [ ] **Step 3: 실행 확인 (DEV)**

Run: `cd /root/boot-dev && php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run`
Expected (DEV stub data):
```
[원본 task 분포 (11기)]
  role             n  admin member   null
  leader          14      0     14      0

[원본 curriculum_items (11기)]: 9건

[대상 후보 (12기)]
  leader          ?명
```
(12기 후보 0명일 가능성 높음 → exit 4 with `--allow-missing-roles` 권유)

- [ ] **Step 4: --allow-missing-roles 동작 확인**

Run: `php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run --allow-missing-roles`
Expected: abort 안 하고 진행 (다음 task 가 없어 그대로 종료)

- [ ] **Step 5: Commit**

```bash
git add migrate_clone_cohort_tasks.php
git commit -m "feat(cohort-clone): pre-validation + candidate count helper"
```

---

## Task 4: Template 추출 (dedupe by role+title+day-offset+content)

**Files:**
- Modify: `migrate_clone_cohort_tasks.php`

- [ ] **Step 1: extractTemplates() 함수 추가**

`countCandidates()` 함수 뒤에 추가:

```php
/**
 * 원본 cohort 의 task 를 (role, title, content_markdown, start_day_offset, end_day_offset) 로 dedupe.
 * 각 template 은 11기 assignee 정보(operation 보존용) 와 source row 수 함께 보관.
 *
 * @return array<int, array{
 *   role: string,
 *   title: string,
 *   content_markdown: ?string,
 *   start_day_offset: int,
 *   end_day_offset: int,
 *   src_assignee_admin_ids: int[],   // 이 template 에 매핑된 11기 admin id 들 (operation 보존용)
 *   src_has_unassigned: bool,         // 이 template 에 NULL assignee row 있었나 (leader unassigned 보존용)
 *   src_row_count: int
 * }>
 */
function extractTemplates(PDO $db, string $fromCohort, string $fromStart): array {
    $stmt = $db->prepare("
        SELECT role, title, content_markdown,
               DATEDIFF(start_date, ?) AS start_day_offset,
               DATEDIFF(end_date, ?)   AS end_day_offset,
               assignee_admin_id, assignee_member_id
        FROM tasks
        WHERE cohort = ?
        ORDER BY start_day_offset, role, title, id
    ");
    $stmt->execute([$fromStart, $fromStart, $fromCohort]);

    $templates = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = sha1(implode('|', [
            $r['role'],
            $r['title'],
            $r['content_markdown'] ?? '',
            (string)$r['start_day_offset'],
            (string)$r['end_day_offset'],
        ]));
        if (!isset($templates[$key])) {
            $templates[$key] = [
                'role' => $r['role'],
                'title' => $r['title'],
                'content_markdown' => $r['content_markdown'],
                'start_day_offset' => (int)$r['start_day_offset'],
                'end_day_offset'   => (int)$r['end_day_offset'],
                'src_assignee_admin_ids' => [],
                'src_has_unassigned' => false,
                'src_row_count' => 0,
            ];
        }
        $t = &$templates[$key];
        $t['src_row_count']++;
        if ($r['assignee_admin_id'] !== null) {
            $aid = (int)$r['assignee_admin_id'];
            if (!in_array($aid, $t['src_assignee_admin_ids'], true)) {
                $t['src_assignee_admin_ids'][] = $aid;
            }
        }
        if ($r['assignee_admin_id'] === null && $r['assignee_member_id'] === null) {
            $t['src_has_unassigned'] = true;
        }
        unset($t);
    }
    return array_values($templates);
}
```

- [ ] **Step 2: 호출 + 통계 출력**

Task 3 의 `if ($missing && !$allowMissingRoles) { ... exit(4); }` 블록 직후에 추가:

```php
// ── Template 추출 ─────────────────────────────────────────
$templates = extractTemplates($db, $fromCohort, $fromRow['start_date']);

$tplByRole = [];
foreach ($templates as $tpl) {
    $tplByRole[$tpl['role']] = ($tplByRole[$tpl['role']] ?? 0) + 1;
}

echo "[Template 추출] " . count($templates) . " templates (dedupe 결과)\n";
foreach ($tplByRole as $role => $cnt) {
    printf("  %-12s %6d templates\n", $role, $cnt);
}
echo "\n";
```

- [ ] **Step 3: DEV 검증**

Run: `cd /root/boot-dev && php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run --allow-missing-roles`
Expected:
```
[Template 추출] 7 templates (dedupe 결과)
  leader            7 templates
```
(DEV 14 row 중 leader 7명 × 2 day = 7 templates × 2 = 14, dedupe 시 7 이 정상)

- [ ] **Step 4: Commit**

```bash
git add migrate_clone_cohort_tasks.php
git commit -m "feat(cohort-clone): template extraction with dedup"
```

---

## Task 5: 후보 resolver (admin/member/operation)

**Files:**
- Modify: `migrate_clone_cohort_tasks.php`

- [ ] **Step 1: resolveCandidates() 함수 추가**

`extractTemplates()` 함수 뒤에 추가:

```php
/**
 * 특정 template 에 대한 12기 row 생성 후보 목록.
 *
 * @return array<int, array{admin_id?: int, member_id?: int, src_admin_id?: int}>
 *   - admin_id 만 채워진 행: assignee_admin_id 로 INSERT
 *   - member_id 만 채워진 행: assignee_member_id 로 INSERT
 *   - 둘 다 NULL: unassigned 복제
 *   - operation 의 경우: 11기 assignee_admin_id 그대로 사용 (cohort=NULL)
 */
function resolveCandidates(PDO $db, array $template, string $toCohort): array {
    $role = $template['role'];

    // operation: 11기 assignee 보존 (없으면 NULL 그대로)
    if ($role === 'operation') {
        if (empty($template['src_assignee_admin_ids']) && !$template['src_has_unassigned']) {
            return [];
        }
        $out = [];
        foreach ($template['src_assignee_admin_ids'] as $aid) {
            $out[] = ['admin_id' => $aid];
        }
        if ($template['src_has_unassigned']) {
            $out[] = []; // NULL row
        }
        return $out;
    }

    // admin role: admin_roles JOIN
    if (in_array($role, ['head', 'coach', 'sub_coach', 'subhead1', 'subhead2'], true)) {
        $stmt = $db->prepare("
            SELECT DISTINCT a.id
            FROM admins a JOIN admin_roles ar ON ar.admin_id = a.id
            WHERE a.cohort = ? AND ar.role = ? AND a.is_active = 1
            ORDER BY a.id
        ");
        $stmt->execute([$toCohort, $role]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map(fn($id) => ['admin_id' => (int)$id], $ids);
    }

    // member role: leader/subleader
    if (in_array($role, ['leader', 'subleader'], true)) {
        $out = [];
        // 11기에서 member assigned template → 12기 멤버 fan-out
        // 11기에서 unassigned template → NULL 그대로 1 row
        if ($template['src_has_unassigned'] && empty($template['src_assignee_admin_ids'])) {
            // src 가 NULL 만 있는 경우 (11기 leader unassigned 81 row 케이스)
            // assignee 정보 없음 — fan-out 안 하고 NULL 1 row
            return [[]];
        }
        $stmt = $db->prepare("
            SELECT bm.id
            FROM bootcamp_members bm JOIN cohorts c ON c.id = bm.cohort_id
            WHERE c.cohort = ? AND bm.member_role = ? AND bm.is_active = 1
            ORDER BY bm.id
        ");
        $stmt->execute([$toCohort, $role]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map(fn($id) => ['member_id' => (int)$id], $ids);
    }

    return [];
}
```

- [ ] **Step 2: 호출 + 예상 row 수 계산**

Task 4 의 `echo "\n";` (template 출력 끝부분) 직후에 추가:

```php
// ── 12기 row 예상 카운트 ──────────────────────────────────
$plannedRowsByRole = [];
$plannedTotal = 0;
$plan = []; // [template, candidates]

foreach ($templates as $tpl) {
    $cands = resolveCandidates($db, $tpl, $toCohort);
    if (empty($cands)) {
        if ($allowMissingRoles) continue;
        // 위에서 검증했지만 안전망
        fwrite(STDERR, "role '{$tpl['role']}' 후보 없음 (template: {$tpl['title']})\n");
        exit(4);
    }
    $plan[] = ['template' => $tpl, 'candidates' => $cands];
    $plannedRowsByRole[$tpl['role']] = ($plannedRowsByRole[$tpl['role']] ?? 0) + count($cands);
    $plannedTotal += count($cands);
}

echo "[12기 task 예상 row]\n";
foreach ($plannedRowsByRole as $role => $cnt) {
    printf("  %-12s %6d rows\n", $role, $cnt);
}
echo "  " . str_repeat('-', 25) . "\n";
printf("  %-12s %6d rows\n\n", 'TOTAL', $plannedTotal);
```

- [ ] **Step 3: DEV 검증**

Run: `cd /root/boot-dev && php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run --allow-missing-roles`
Expected: `[12기 task 예상 row]` 섹션 출력. DEV 12기 leader 0명이면 leader templates 가 모두 skip 되어 `TOTAL 0 rows`. DEV 12기 leader N명이면 `leader 7N rows / TOTAL 7N rows` (template 7개 × N명).

verify with:
```bash
source .db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT bm.member_role, COUNT(*) FROM bootcamp_members bm
JOIN cohorts c ON c.id=bm.cohort_id
WHERE c.cohort='12기' AND bm.is_active=1 GROUP BY bm.member_role;
"
```

- [ ] **Step 4: Commit**

```bash
git add migrate_clone_cohort_tasks.php
git commit -m "feat(cohort-clone): candidate resolver per role"
```

---

## Task 6: Task row 생성 + Curriculum INSERT...SELECT + 트랜잭션

**Files:**
- Modify: `migrate_clone_cohort_tasks.php`

- [ ] **Step 1: 트랜잭션 + force cleanup + task INSERT**

Task 5 의 `printf("  %-12s %6d rows\n\n", 'TOTAL', ...);` 직후에 추가:

```php
// ── 트랜잭션 시작 ─────────────────────────────────────────
$db->beginTransaction();

try {
    // --force: 기존 12기 데이터 삭제
    if ($force) {
        $delTasks = $db->prepare("DELETE FROM tasks WHERE cohort = ?");
        $delTasks->execute([$toCohort]);
        $deletedTasks = $delTasks->rowCount();

        $delCur = $db->prepare("DELETE FROM curriculum_items WHERE cohort = ?");
        $delCur->execute([$toCohort]);
        $deletedCur = $delCur->rowCount();

        echo "[--force] 삭제: tasks {$deletedTasks}건, curriculum_items {$deletedCur}건\n\n";
    }

    // ── Task INSERT ────────────────────────────────────────
    $insertTask = $db->prepare("
        INSERT INTO tasks (
            title, role, assignee_admin_id, assignee_member_id, completed,
            start_date, end_date, content_markdown, cohort
        ) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)
    ");

    $toBase = new DateTimeImmutable($toRow['start_date']);
    $taskInserted = 0;
    foreach ($plan as $entry) {
        $tpl = $entry['template'];
        $newStart = $toBase->modify("+{$tpl['start_day_offset']} day")->format('Y-m-d');
        $newEnd   = $toBase->modify("+{$tpl['end_day_offset']} day")->format('Y-m-d');

        foreach ($entry['candidates'] as $cand) {
            $insertTask->execute([
                $tpl['title'],
                $tpl['role'],
                $cand['admin_id']  ?? null,
                $cand['member_id'] ?? null,
                $newStart,
                $newEnd,
                $tpl['content_markdown'],
                $toCohort,
            ]);
            $taskInserted++;
        }
    }
    echo "[Task INSERT] {$taskInserted} rows\n";

    // ── Curriculum INSERT...SELECT (date shift) ───────────
    $curInsert = $db->prepare("
        INSERT INTO curriculum_items (cohort, target_date, task_type, note, sort_order, created_by)
        SELECT ?, DATE_ADD(target_date, INTERVAL ? DAY), task_type, note, sort_order, NULL
        FROM curriculum_items
        WHERE cohort = ?
    ");
    $curInsert->execute([$toCohort, $dayOffset, $fromCohort]);
    $curInserted = $curInsert->rowCount();
    echo "[Curriculum INSERT] {$curInserted} rows\n\n";

    // ── 검증 ───────────────────────────────────────────────
    $verifyStmt = $db->prepare("SELECT role, COUNT(*) AS n FROM tasks WHERE cohort = ? GROUP BY role ORDER BY role");
    $verifyStmt->execute([$toCohort]);
    echo "[검증] 12기 task 분포:\n";
    foreach ($verifyStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        printf("  %-12s %6d rows\n", $r['role'], $r['n']);
    }

    $verifyCur = $db->prepare("SELECT COUNT(*) FROM curriculum_items WHERE cohort = ?");
    $verifyCur->execute([$toCohort]);
    echo "[검증] 12기 curriculum: " . (int)$verifyCur->fetchColumn() . " rows\n\n";

    if ($dryRun) {
        $db->rollBack();
        echo "[DRY-RUN] ROLLBACK 완료. 변경 없음.\n";
    } else {
        $db->commit();
        echo "[APPLY] COMMIT 완료.\n";
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(5);
}

echo "\nDone.\n";
```

- [ ] **Step 2: DEV dry-run 전체 흐름 확인**

Run: `cd /root/boot-dev && php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run --allow-missing-roles`
Expected: 전체 [cohort 정보] → [원본 분포] → [후보] → [Template] → [예상 row] → [Task INSERT N] → [Curriculum INSERT 9] → [검증] → ROLLBACK 메시지가 모두 출력되고, 종료 후 `SELECT COUNT(*) FROM tasks WHERE cohort='12기'` 가 변하지 않음 (DEV 12기는 원래 0이라 0 유지).

- [ ] **Step 3: DEV 실제 적용 후 rollback 검증**

```bash
cd /root/boot-dev
source .db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT COUNT(*) FROM tasks WHERE cohort='12기'; SELECT COUNT(*) FROM curriculum_items WHERE cohort='12기';"
# 둘 다 0
php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --allow-missing-roles
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT COUNT(*) FROM tasks WHERE cohort='12기'; SELECT COUNT(*) FROM curriculum_items WHERE cohort='12기';"
# DEV 11기 stub 기준 N rows / 9 rows
```
Expected: APPLY 후 12기 row 가 채워짐.

- [ ] **Step 4: 멱등성 확인 (재실행 시 abort)**

```bash
php migrate_clone_cohort_tasks.php --from=11기 --to=12기
```
Expected stderr: `대상 cohort '12기' 에 이미 task N건 / curriculum 9건 존재. --force 사용...`, exit 3.

- [ ] **Step 5: --force 재실행**

```bash
php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --allow-missing-roles --force
```
Expected: `[--force] 삭제: tasks N건, curriculum_items 9건` 출력 후 같은 결과 재생성.

- [ ] **Step 6: DEV 클린업 (테스트 데이터 제거)**

```bash
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "DELETE FROM tasks WHERE cohort='12기'; DELETE FROM curriculum_items WHERE cohort='12기';"
```

- [ ] **Step 7: Commit**

```bash
git add migrate_clone_cohort_tasks.php
git commit -m "feat(cohort-clone): task/curriculum insertion + transaction"
```

---

## Task 7: Invariant 테스트

**Files:**
- Create: `tests/cohort_clone_invariants.php`

- [ ] **Step 1: main script 에 lib-only 가드 추가**

`migrate_clone_cohort_tasks.php` 의 `if (php_sapi_name() !== 'cli')` 블록 직후, `require_once __DIR__ . '/public_html/config.php';` 직전에 추가:

```php
// 테스트 등에서 require_once 시 main 흐름 실행 방지 (함수 정의는 그대로 노출됨)
if (defined('CLONE_LIB_ONLY')) {
    require_once __DIR__ . '/public_html/config.php';
    return;
}
```

PHP 는 top-level `function foo() {}` 선언을 컴파일 시점에 등록하므로 early `return` 후에도 `extractTemplates()`, `resolveCandidates()`, `countCandidates()` 는 호출 가능.

- [ ] **Step 2: 테스트 스크립트 작성**

```php
<?php
/**
 * cohort clone migration 인보리언트 테스트
 * 사용: php tests/cohort_clone_invariants.php
 *
 * 함수 단위 검증 + outer transaction 으로 부작용 격리.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

define('CLONE_LIB_ONLY', true);
require_once __DIR__ . '/../migrate_clone_cohort_tasks.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; }
    else { $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n"; }
}

$db = getDB();

// ── extractTemplates: dedupe 동작 ──
$db->beginTransaction();
try {
    // 11기 source 가 있는지 확인
    $hasSource = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE cohort='11기'")->fetchColumn();
    if ($hasSource === 0) {
        echo "SKIP  no 11기 source tasks\n";
        $db->rollBack();
        exit(0);
    }

    $fromStart = $db->query("SELECT start_date FROM cohorts WHERE cohort='11기'")->fetchColumn();
    $templates = extractTemplates($db, '11기', $fromStart);
    t('extractTemplates returns array', is_array($templates));
    t('extractTemplates count > 0', count($templates) > 0);

    // dedupe: src_row_count 합 == 원본 row 수
    $totalSrcRows = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE cohort='11기'")->fetchColumn();
    $sumSrc = array_sum(array_column($templates, 'src_row_count'));
    t('dedupe src_row_count sum equals source row count',
      $sumSrc === $totalSrcRows,
      "templates sum={$sumSrc}, source={$totalSrcRows}");

    // ── countCandidates: role 별 후보 수 ≥ 0 ──
    foreach (['head','coach','sub_coach','operation','leader','subleader'] as $role) {
        $n = countCandidates($db, $role, '12기');
        t("countCandidates({$role}) >= 0", $n >= 0, "n={$n}");
    }

    // ── operation role 후보: cohort=NULL admin 만 ──
    $opCands = countCandidates($db, 'operation', '12기');
    $opNullStmt = $db->query("
        SELECT COUNT(*) FROM admins WHERE cohort IS NULL AND role='operation' AND is_active=1
    ");
    $opNullAdmins = (int)$opNullStmt->fetchColumn();
    t('operation candidates = cohort=NULL operation admins',
      $opCands === $opNullAdmins,
      "candidates={$opCands}, null_admins={$opNullAdmins}");

    // ── resolveCandidates: operation 은 src admin id 그대로 ──
    $opTpl = null;
    foreach ($templates as $tpl) {
        if ($tpl['role'] === 'operation' && !empty($tpl['src_assignee_admin_ids'])) {
            $opTpl = $tpl;
            break;
        }
    }
    if ($opTpl) {
        $opCandsResolved = resolveCandidates($db, $opTpl, '12기');
        $resolvedAdminIds = array_column(array_filter($opCandsResolved, fn($c) => isset($c['admin_id'])), 'admin_id');
        sort($resolvedAdminIds);
        $expected = $opTpl['src_assignee_admin_ids'];
        sort($expected);
        t('operation template preserves src admin ids',
          $resolvedAdminIds === $expected,
          'resolved=' . json_encode($resolvedAdminIds) . ' src=' . json_encode($expected));
    } else {
        echo "SKIP  no operation template with admin assignee\n";
    }

    $db->rollBack();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 3: 테스트 실행 (DEV)**

Run: `cd /root/boot-dev && php tests/cohort_clone_invariants.php`
Expected (DEV stub 데이터 기준):
```
PASS  extractTemplates returns array
PASS  extractTemplates count > 0
PASS  dedupe src_row_count sum equals source row count
PASS  countCandidates(head) >= 0
... (모두 PASS)
N passed, 0 failed
```

- [ ] **Step 4: Commit**

```bash
git add migrate_clone_cohort_tasks.php tests/cohort_clone_invariants.php
git commit -m "test(cohort-clone): invariants for templates/candidates/operation preservation"
```

---

## Task 8: DEV 전체 검증 + dev push (사용자 확인 게이트)

**Files:** 변경 없음

- [ ] **Step 1: DEV 에서 dry-run + 실제 실행 + force 재실행 + cleanup 시나리오 확인**

```bash
cd /root/boot-dev
# 1. dry-run
php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run --allow-missing-roles
# 변경 없음 확인
source .db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT COUNT(*) FROM tasks WHERE cohort='12기'"
# 0

# 2. 실제 적용
php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --allow-missing-roles
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT COUNT(*) FROM tasks WHERE cohort='12기'; SELECT role, COUNT(*) FROM tasks WHERE cohort='12기' GROUP BY role; SELECT COUNT(*) FROM curriculum_items WHERE cohort='12기';"

# 3. 멱등성 (재실행 abort)
php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --allow-missing-roles
# exit 3

# 4. force 재실행
php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --allow-missing-roles --force

# 5. invariant 테스트
php tests/cohort_clone_invariants.php
# 모두 PASS

# 6. DEV 클린업 (PROD 적용은 사용자 게이트 후)
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "DELETE FROM tasks WHERE cohort='12기'; DELETE FROM curriculum_items WHERE cohort='12기';"
```

- [ ] **Step 2: dev 브랜치 push**

```bash
cd /root/boot-dev
git status
git log --oneline -10
git push origin dev
```

- [ ] **Step 3: ⛔ 사용자 확인 게이트**

dev push 완료 후 사용자님에게 다음 메시지로 확인 요청:

> "DEV 검증 완료. dev 브랜치 push 완료. PROD 반영 진행할까요?
> 진행 시: main 머지 → boot-prod git pull → PROD dry-run 실행 후 결과 보고 → 사용자님 OK 후 본 실행"

**STOP HERE — 사용자 명시적 요청 없이는 다음 task 진행 금지.**

---

## Task 9 (사용자 운영 반영 요청 후): main 머지 + PROD pull

**Files:** 변경 없음

- [ ] **Step 1: main 머지 + push**

```bash
cd /root/boot-dev
git checkout main
git merge dev
git push origin main
git checkout dev
```

- [ ] **Step 2: boot-prod 동기화**

```bash
cd /root/boot-prod
git pull origin main
git log --oneline -3
# 새 commit 들이 보여야 함
```

- [ ] **Step 3: PROD 파일 존재 확인**

```bash
ls -la /root/boot-prod/migrate_clone_cohort_tasks.php /root/boot-prod/tests/cohort_clone_invariants.php
```

---

## Task 10 (사용자 OK 후): PROD dry-run

**Files:** 변경 없음

- [ ] **Step 1: PROD 백업 (안전망)**

```bash
cd /root/boot-prod
source .db_credentials
mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" tasks curriculum_items > /tmp/boot_prod_tasks_curriculum_backup_$(date +%Y%m%d_%H%M%S).sql
ls -la /tmp/boot_prod_tasks_curriculum_backup_*.sql
```

- [ ] **Step 2: PROD dry-run 실행**

```bash
cd /root/boot-prod
php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run
```

Expected (PROD):
- `[원본 task 분포 (11기)]`: head 143 / coach 37 / sub_coach 24 / operation 19 / leader 321
- `[원본 curriculum_items (11기)]: 139건`
- `[대상 후보 (12기)]`: head 1, coach 3, sub_coach 7, operation 2, leader 9, subleader 8
- `[Template 추출]` ≈ 280+ templates
- `[12기 task 예상 row]` ≈ 569 (head ≈142 / coach ≈36 / sub_coach ≈21 / operation 19 / leader ≈351)
- `[Curriculum INSERT] 139 rows`
- `[검증]` 출력
- `[DRY-RUN] ROLLBACK 완료`

- [ ] **Step 3: PROD invariant 테스트**

```bash
php tests/cohort_clone_invariants.php
```
Expected: 모두 PASS.

- [ ] **Step 4: ⛔ 사용자 확인 게이트**

dry-run 결과 출력을 사용자님께 보여드리고:
> "위 dry-run 결과 OK 하시면 실제 INSERT 실행하겠습니다. 확인 부탁드려요."

**STOP — 사용자 명시 OK 없이 본 실행 금지.**

---

## Task 11 (사용자 OK 후): PROD 본 실행

**Files:** 변경 없음

- [ ] **Step 1: PROD 실 적용**

```bash
cd /root/boot-prod
php migrate_clone_cohort_tasks.php --from=11기 --to=12기
```

Expected: dry-run 과 동일 출력 + `[APPLY] COMMIT 완료.`

- [ ] **Step 2: 검증 (스펙 §8 체크리스트)**

```bash
source .db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'SQL'
SELECT cohort, COUNT(*) FROM tasks GROUP BY cohort;
SELECT role, COUNT(*) FROM tasks WHERE cohort='12기' GROUP BY role;
SELECT COUNT(*) FROM curriculum_items WHERE cohort='12기';
SELECT MIN(start_date), MAX(end_date) FROM tasks WHERE cohort='12기';
SELECT MIN(target_date), MAX(target_date) FROM curriculum_items WHERE cohort='12기';
SQL
```

Expected:
- 12기 tasks ≈ 569
- 12기 curriculum_items = 139
- 12기 task 날짜 범위 = 2026-05-09 ~ 2026-06-12 (11기 + 49일)
- 12기 curriculum 날짜 범위 = 2026-05-09 ~ 2026-06-12

- [ ] **Step 3: 어드민 페이지 시각 확인**

브라우저에서:
- `https://boot.soritune.com/head/` → 12기 task 표시
- `https://boot.soritune.com/operation/` → 12기 진도 카드 표시
- 강사 / 조장 페이지에서도 12기 task 노출 확인

사용자님께 시각 확인 요청 후 종료.

- [ ] **Step 4: 사용자 보고**

PROD 결과 통계 + 백업 파일 위치 (`/tmp/boot_prod_tasks_curriculum_backup_*.sql`) 알려드림.

---

## 비범위 (Out of Scope)

- 어드민 UI 버튼 (재사용성 ↑ 작업 시간 ↑ — YAGNI)
- task 본문 자동 업데이트 (예: "00기" placeholder 치환)
- 12기 → 13기 자동 복제 자동화 (현재 스크립트로 수동 실행 가능)
- 11기 → 12기 task 본문 내 링크/노션 URL 자동 치환

## 알려진 제한 (Known Limitations)

- 11기 일부 leader template 이 8명 중 5명 등 부분 fan-out 인 경우, dedupe 시 1 template 으로 합쳐져 12기 9명 전원에게 fan-out 됨 (= 더 광범위 할당). 사용자 의도와 일치.
- task 본문(content_markdown) 의 11기-specific 링크/날짜는 운영자가 어드민에서 수동 보정.
- curriculum_items 의 `progress` note 안의 강 번호는 운영자가 12기 시작 전 보정 필요.
