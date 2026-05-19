# Task: head 권한 + Task 다중 부여 방식 (역할별/전체/특정인) 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** boot.soritune.com 의 Task 시스템에 (1) head/subhead1/subhead2 관리 권한·화면을 추가하고 (2) 부여 방식 3종(역할별/전체/특정 인물)을 지원한다.

**Architecture:** `tasks` 에 `group_kind` ENUM + `group_scope` VARCHAR 컬럼 2개 추가, 묶음 키 = `(cohort,title,group_kind,group_scope)`. 기존 row 는 `group_kind='role'/group_scope=role` 로 백필. API 는 `task_create` 에 `assignment_kind`/`target_person` 입력 추가 + `cohort_people_search` 신규 endpoint. JS 는 `canManageTasks()` 헬퍼로 4 role 권한 일원화, 생성 폼에 3-way 라디오, 관리 표 '대상' 컬럼으로 일반화.

**Tech Stack:** PHP 8 + MariaDB(PDO) / Vanilla JS + 기존 admin.js 모듈 / 인보리언트·통합 테스트는 CLI PHP

**Spec:** `docs/superpowers/specs/2026-05-19-task-head-role-and-multi-assign-design.md`

**메모리 룰 (memory/MEMORY.md):** 작업은 `boot-dev` (DEV_BOOT, dev 브랜치) 에서만. PROD 머지·prod pull 은 사용자 명시 후만. `.db_credentials` 는 PHP-FPM 비밀 파일 권한 룰 적용.

---

## File Structure

**Create**
- `migrate_tasks_group_kind.php` — DEV/PROD 1회 마이그레이션 스크립트 (ALTER + UPDATE 백필)
- `tests/task_kind_invariants.php` — 신규 부여 종류 인보리언트 (CLI)
- `tests/task_kind_api_test.php` — task_create / cohort_people_search 통합 테스트 (CLI, ADMIN_COOKIE 필요)
- `tests/task_permissions_test.php` — 4 role 권한 회귀 (CLI, role별 cookie 필요)

**Modify**
- `public_html/api/admin.php`
  - 권한 확장: 7개 task endpoint 의 `requireAdmin(['operation'])` 교체
  - `task_create` case 에 `assignment_kind`/`target_person` 분기 + `expandAssignees()` 인라인 헬퍼
  - `cohort_people_search` case 추가
  - `task_group_get/update/delete/task_group_rows` 의 식별 키 확장 (`group_kind`,`group_scope` 추가) + 하위호환 폴백
  - `all_tasks_grouped` GROUP BY 키 확장 + SELECT 에 `group_kind`/`group_scope`/`person_name` 추가 + `filter_role='kind:everyone'/'kind:person'` 분기
  - `today_tasks`/`overdue_tasks` SELECT 에 `t.group_kind`/`t.group_scope` 추가
- `public_html/js/admin.js`
  - `canManageTasks()` 헬퍼 신설 + 5개 `isOperation()` 호출 지점 교체
  - 탭 분기의 `else` (head) 패널에 "Task 관리" 탭 추가
  - `showTaskForm()` 에 3-way 라디오 + person 검색 패널 + payload 빌더 분기
  - `loadTasksMgmt()` 의 표 헤더/렌더 → '대상' 컬럼 + kind 필터 chip
  - `_editTaskGroup`/`_deleteTaskGroup`/`_toggleGroupExpand` 시그니처에 `group_kind`/`group_scope` 추가
  - `renderTaskCard()` 의 kind 배지 (📣 전체 / 👤 개인 지정)
- `tests/task_group_invariants.php` — INV-G1/G2/G3 추가, 기존 INV SQL 패턴에 `group_kind`/`group_scope` 반영
- `tests/task_group_api_test.php` — 묶음 식별 키 4-tuple 로 갱신 (기존 회귀)

**No change**
- `public_html/head/index.php` — 그대로. admin.js 가 탭을 동적 추가하므로 PHP 변경 없음.
- `public_html/operation/index.php` — 그대로.
- DB 스키마는 마이그레이션 스크립트로만 변경.

---

## Task 1: DB 컬럼 추가 + 백필 마이그 + 인보리언트 기준선

**Files:**
- Create: `/root/boot-dev/migrate_tasks_group_kind.php`
- Create: `/root/boot-dev/tests/task_kind_invariants.php`

- [ ] **Step 1: 마이그레이션 스크립트 작성**

Create `/root/boot-dev/migrate_tasks_group_kind.php`:

```php
<?php
/**
 * tasks.group_kind / group_scope 컬럼 추가 + 기존 row 백필.
 *
 * 사용: php migrate_tasks_group_kind.php
 *
 * 멱등: 컬럼 존재 시 ALTER skip / group_scope IS NULL 가드로 UPDATE skip.
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function indexExists(PDO $db, string $table, string $index): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?
         LIMIT 1
    ");
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}

echo "== tasks.group_kind / group_scope 마이그 ==\n";

if (!columnExists($db, 'tasks', 'group_kind')) {
    echo "ALTER: group_kind ENUM 추가...\n";
    $db->exec("
        ALTER TABLE tasks
          ADD COLUMN group_kind ENUM('role','everyone','person') NOT NULL DEFAULT 'role'
            AFTER role
    ");
} else {
    echo "skip: group_kind 이미 존재\n";
}

if (!columnExists($db, 'tasks', 'group_scope')) {
    echo "ALTER: group_scope VARCHAR(80) NULL 추가...\n";
    $db->exec("
        ALTER TABLE tasks
          ADD COLUMN group_scope VARCHAR(80) NULL
            AFTER group_kind
    ");
} else {
    echo "skip: group_scope 이미 존재\n";
}

if (!indexExists($db, 'tasks', 'idx_cohort_group')) {
    echo "INDEX: idx_cohort_group 추가...\n";
    $db->exec("CREATE INDEX idx_cohort_group ON tasks (cohort, title, group_kind, group_scope)");
} else {
    echo "skip: idx_cohort_group 이미 존재\n";
}

echo "백필: group_scope IS NULL → role 복사\n";
$db->beginTransaction();
$stmt = $db->prepare("
    UPDATE tasks
       SET group_kind = 'role', group_scope = role
     WHERE group_scope IS NULL
");
$stmt->execute();
$updated = $stmt->rowCount();
$db->commit();
echo "백필 완료: {$updated} row\n";

$total = (int)$db->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
$nullScope = (int)$db->query("
    SELECT COUNT(*) FROM tasks
     WHERE group_kind = 'role' AND group_scope IS NULL
")->fetchColumn();
echo "검증: tasks 전체 {$total} / role-kind 인데 scope NULL = {$nullScope}\n";
if ($nullScope !== 0) {
    echo "FAIL: 백필 후에도 role-kind row 에 NULL scope 가 남아있음\n";
    exit(1);
}
echo "PASS\n";
```

- [ ] **Step 2: DEV 에서 마이그 실행 + 검증**

Run:
```bash
cd /root/boot-dev && php migrate_tasks_group_kind.php
source .db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW CREATE TABLE tasks\G" | grep -E "group_kind|group_scope|idx_cohort_group"
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  SELECT group_kind, COUNT(*) FROM tasks GROUP BY group_kind;
  SELECT COUNT(*) FROM tasks WHERE group_kind='role' AND group_scope IS NULL;
"
```

Expected:
- "PASS" 출력
- `group_kind enum(...)`, `group_scope varchar(80)`, `idx_cohort_group` 표시
- `group_kind='role'` 카운트 = 전체 tasks 카운트, NULL scope = 0

- [ ] **Step 3: 멱등성 확인 — 한 번 더 실행**

Run: `cd /root/boot-dev && php migrate_tasks_group_kind.php`

Expected: 모든 ALTER/INDEX 가 "skip: ... 이미 존재", 백필 "0 row", "PASS"

- [ ] **Step 4: 신규 인보리언트 파일 작성**

Create `/root/boot-dev/tests/task_kind_invariants.php`:

```php
<?php
/**
 * tasks.group_kind / group_scope 인보리언트.
 *
 * 사용: php tests/task_kind_invariants.php
 *
 * 룰:
 *   INV-G1: 모든 row 가 group_kind ∈ {role,everyone,person} 이고
 *           scope NULL 여부가 kind 와 일치.
 *   INV-G2: person 묶음은 묶음 키 당 distinct assignee 1명.
 *   INV-G3: everyone 묶음에 (admin_id|member_id, start_date, end_date) 중복 없음.
 *
 * ⚠️ SQL 동기화: public_html/api/admin.php 의 task_create / all_tasks_grouped
 *    의 group_kind/group_scope 패턴과 동일 가정을 검증한다.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
$db = getDB();

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// INV-G1
$bad = (int)$db->query("
    SELECT COUNT(*) FROM tasks
     WHERE (group_kind = 'role'     AND (group_scope IS NULL OR group_scope <> role))
        OR (group_kind = 'everyone' AND group_scope IS NOT NULL)
        OR (group_kind = 'person'   AND (group_scope IS NULL
                                         OR (group_scope NOT LIKE 'admin:%'
                                             AND group_scope NOT LIKE 'member:%')))
")->fetchColumn();
t('INV-G1 kind ↔ scope 일관', $bad === 0, "bad={$bad}");

// INV-G2: person 묶음 키당 assignee 1명
$g2 = (int)$db->query("
    SELECT COUNT(*) FROM (
      SELECT cohort, title, group_scope,
             COUNT(DISTINCT CONCAT_WS(':',
                 COALESCE(assignee_admin_id, '_'),
                 COALESCE(assignee_member_id, '_'))) AS distinct_assignees
        FROM tasks
       WHERE group_kind = 'person'
       GROUP BY cohort, title, group_scope
       HAVING distinct_assignees > 1
    ) x
")->fetchColumn();
t('INV-G2 person 묶음당 assignee 1명', $g2 === 0, "violators={$g2}");

// INV-G3: everyone 묶음 내 (사람, 기간) 중복 없음
$g3 = (int)$db->query("
    SELECT COUNT(*) FROM (
      SELECT cohort, title, assignee_admin_id, assignee_member_id, start_date, end_date,
             COUNT(*) c
        FROM tasks
       WHERE group_kind = 'everyone'
       GROUP BY cohort, title, assignee_admin_id, assignee_member_id, start_date, end_date
       HAVING c > 1
    ) x
")->fetchColumn();
t('INV-G3 everyone 묶음 사람×기간 중복 없음', $g3 === 0, "violators={$g3}");

echo "\n--- {$pass} pass / {$fail} fail ---\n";
exit($fail ? 1 : 0);
```

- [ ] **Step 5: 인보리언트 실행 — PASS 3/3**

Run: `cd /root/boot-dev && php tests/task_kind_invariants.php`

Expected:
```
PASS  INV-G1 kind ↔ scope 일관
PASS  INV-G2 person 묶음당 assignee 1명
PASS  INV-G3 everyone 묶음 사람×기간 중복 없음
--- 3 pass / 0 fail ---
```

(아직 person/everyone row 가 없으므로 G2/G3 는 vacuously true)

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev
git add migrate_tasks_group_kind.php tests/task_kind_invariants.php
git commit -m "feat(task): tasks.group_kind/group_scope 컬럼 + 백필 마이그 + 인보리언트"
```

---

## Task 2: 권한 확장 (head/subhead1/subhead2 task 관리 endpoint)

**Files:**
- Modify: `/root/boot-dev/public_html/api/admin.php` (8 지점)

- [ ] **Step 1: 사이트 권한 헬퍼 결정 — 인라인 배열**

`admin.php` 의 다음 7 case 에서 `requireAdmin(['operation'])` 를 `requireAdmin(['operation','head','subhead1','subhead2'])` 로 교체한다.

추가로 `all_tasks_grouped` case 의 `if (!hasRole($admin, 'operation'))` 도 같이 확장.

- [ ] **Step 2: `task_group_get` 권한 확장 (admin.php:1216)**

기존:
```php
$admin = requireAdmin(['operation']);
```
신규:
```php
$admin = requireAdmin(['operation','head','subhead1','subhead2']);
```

- [ ] **Step 3: `all_tasks_grouped` 권한 확장 (admin.php:1242-1243)**

기존:
```php
$admin = requireAdmin();
if (!hasRole($admin, 'operation')) jsonError('권한이 없습니다.', 403);
```
신규:
```php
$admin = requireAdmin(['operation','head','subhead1','subhead2']);
```

- [ ] **Step 4: `task_group_update` / `task_group_delete` / `task_group_rows` 권한 확장 (admin.php:1311, 1334, 1364)**

세 case 모두 동일하게:
```php
$admin = requireAdmin(['operation','head','subhead1','subhead2']);
```

- [ ] **Step 5: `task_create` / `task_update` / `task_delete` 권한 확장 (admin.php:1446, 1579, 1604)**

세 case 모두 동일하게:
```php
$admin = requireAdmin(['operation','head','subhead1','subhead2']);
```

- [ ] **Step 6: `today_tasks` / `overdue_tasks` 의 isOperation 분기 확장 (admin.php:207, 290)**

`today_tasks` case 의 `if (hasRole($admin, 'operation'))` 를 다음으로 교체:
```php
if (hasAnyRole($admin, ['operation','head','subhead1','subhead2'])) {
```
`overdue_tasks` case 의 동일 분기도 같은 방식으로 교체.

이렇게 하면 head/subhead 도 `filter_role='mine|all|coach|...'` 사용 가능.

- [ ] **Step 7: 권한 회귀 통합 테스트 작성**

Create `/root/boot-dev/tests/task_permissions_test.php`:

```php
<?php
/**
 * Task 관리 endpoint 권한 회귀.
 *
 * 사용:
 *   OP_COOKIE='PHPSESSID_ADMIN=...op...' \
 *   HEAD_COOKIE='PHPSESSID_ADMIN=...head...' \
 *   COACH_COOKIE='PHPSESSID_ADMIN=...coach...' \
 *   DEV_BASE='https://dev-boot.soritune.com' \
 *     php tests/task_permissions_test.php
 *
 * 사전: operation 1명, head 또는 subhead 1명, coach 1명 각각 로그인 쿠키 필요.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

$base   = rtrim(getenv('DEV_BASE') ?: 'https://dev-boot.soritune.com', '/');
$op     = getenv('OP_COOKIE')    ?: '';
$head   = getenv('HEAD_COOKIE')  ?: '';
$coach  = getenv('COACH_COOKIE') ?: '';
if (!$op || !$head || !$coach) {
    echo "OP_COOKIE / HEAD_COOKIE / COACH_COOKIE 모두 필수\n"; exit(2);
}

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

function call(string $cookie, string $base, string $action, string $method = 'GET', ?array $json = null): int {
    $ch = curl_init("{$base}/api/admin.php?action={$action}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookie, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

// GET 권한 (operation/head 200, coach 403)
t('op 가 all_tasks_grouped 200',    call($op,    $base, 'all_tasks_grouped') === 200);
t('head 가 all_tasks_grouped 200',  call($head,  $base, 'all_tasks_grouped') === 200);
t('coach 가 all_tasks_grouped 403', call($coach, $base, 'all_tasks_grouped') === 403);

// POST 권한 (task_create) — empty payload 라도 401/403 시점이 권한 분기
$dummy = ['title' => '', 'roles' => [], 'date_mode' => 'direct'];
t('op 가 task_create 비 403',    call($op,    $base, 'task_create', 'POST', $dummy) !== 403);
t('head 가 task_create 비 403',  call($head,  $base, 'task_create', 'POST', $dummy) !== 403);
t('coach 가 task_create 403',    call($coach, $base, 'task_create', 'POST', $dummy) === 403);

echo "\n--- {$pass} pass / {$fail} fail ---\n";
exit($fail ? 1 : 0);
```

- [ ] **Step 8: 권한 테스트 실행 — PASS 6/6**

DEV 에서 operation/head/coach 각각의 PHPSESSID_ADMIN 쿠키를 얻은 뒤:
```bash
cd /root/boot-dev
OP_COOKIE='PHPSESSID_ADMIN=...' HEAD_COOKIE='PHPSESSID_ADMIN=...' COACH_COOKIE='PHPSESSID_ADMIN=...' \
  php tests/task_permissions_test.php
```

Expected: `6 pass / 0 fail`

쿠키 획득 어려우면 이 step 은 사용자에게 위임. 단 step 1~6 의 권한 코드 패치는 그래도 진행하고 step 8 만 사용자 확인 보류.

- [ ] **Step 9: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/admin.php tests/task_permissions_test.php
git commit -m "feat(task): head/subhead1/subhead2 권한 확장 (8 task endpoint)"
```

---

## Task 3: `task_create` 에 `assignment_kind` / `target_person` 분기 추가

**Files:**
- Modify: `/root/boot-dev/public_html/api/admin.php` (`task_create` case, 1444-1575)

- [ ] **Step 1: 통합 테스트 파일 작성 (먼저 작성 — TDD)**

Create `/root/boot-dev/tests/task_kind_api_test.php`:

```php
<?php
/**
 * task_create / cohort_people_search API 통합 테스트.
 *
 * 사용:
 *   ADMIN_COOKIE='PHPSESSID_ADMIN=...op...' \
 *   DEV_BASE='https://dev-boot.soritune.com' \
 *     php tests/task_kind_api_test.php
 *
 * 사전:
 *   - operation 권한 admin 로그인 쿠키
 *   - cohorts 에 현재 cohort 존재 + 최소 1명의 활성 admin 또는 leader
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

$base   = rtrim(getenv('DEV_BASE') ?: 'https://dev-boot.soritune.com', '/');
$cookie = getenv('ADMIN_COOKIE') ?: '';
if (!$cookie) { echo "ADMIN_COOKIE 환경변수 필수\n"; exit(2); }

require_once __DIR__ . '/../public_html/config.php';
$db = getDB();

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}
function req(string $method, string $url, array $headers, ?array $json = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($body ?: '', true) ?? ['raw' => $body]];
}

$h = ['Cookie: ' . $cookie, 'Content-Type: application/json'];
$cohort = $db->query("SELECT cohort FROM cohorts ORDER BY start_date DESC LIMIT 1")->fetchColumn();
if (!$cohort) { echo "FAIL setup: cohorts 비어있음\n"; exit(3); }

$testTitle = '[TEST] kind-' . time();

// helper: 끝나면 정리
function cleanup(PDO $db, string $cohort, string $titlePrefix): void {
    $db->prepare("DELETE FROM tasks WHERE cohort = ? AND title LIKE ?")->execute([$cohort, $titlePrefix . '%']);
}
cleanup($db, $cohort, $testTitle);

// ── kind=role 회귀 ─────
$r = req('POST', "{$base}/api/admin.php?action=task_create", $h, [
    'title' => $testTitle . '-role',
    'assignment_kind' => 'role',
    'roles' => ['coach'],
    'cohort' => $cohort,
    'date_mode' => 'direct',
    'start_date' => date('Y-m-d'),
    'end_date'   => date('Y-m-d'),
]);
t('kind=role 생성 성공', $r['code'] === 200 && !empty($r['body']['success']), 'code=' . $r['code']);
$cnt = (int)$db->query("
    SELECT COUNT(*) FROM tasks
     WHERE title = '" . $testTitle . "-role' AND group_kind='role' AND group_scope='coach'
")->fetchColumn();
t('kind=role row 의 group_kind/scope 백필', $cnt >= 1, "cnt={$cnt}");

// ── kind=everyone ─────
$r = req('POST', "{$base}/api/admin.php?action=task_create", $h, [
    'title' => $testTitle . '-everyone',
    'assignment_kind' => 'everyone',
    'cohort' => $cohort,
    'date_mode' => 'direct',
    'start_date' => date('Y-m-d'),
    'end_date'   => date('Y-m-d'),
]);
t('kind=everyone 생성 성공', $r['code'] === 200 && !empty($r['body']['success']), 'code=' . $r['code']);
$cnt = (int)$db->query("
    SELECT COUNT(*) FROM tasks
     WHERE title = '" . $testTitle . "-everyone' AND group_kind='everyone' AND group_scope IS NULL
")->fetchColumn();
t('kind=everyone row 의 group_scope NULL', $cnt >= 1, "cnt={$cnt}");

// ── kind=person, type=admin ─────
$adminId = (int)$db->query("
    SELECT a.id FROM admins a
     JOIN admin_roles ar ON a.id = ar.admin_id
     WHERE a.is_active = 1 AND ar.role IN ('coach','sub_coach','head','subhead1','subhead2','operation')
       AND (a.cohort = '{$cohort}' OR a.cohort IS NULL)
     LIMIT 1
")->fetchColumn();
if (!$adminId) { echo "skip person/admin: 활성 admin 없음\n"; }
else {
    $r = req('POST', "{$base}/api/admin.php?action=task_create", $h, [
        'title' => $testTitle . '-person-admin',
        'assignment_kind' => 'person',
        'target_person' => ['type' => 'admin', 'id' => $adminId],
        'cohort' => $cohort,
        'date_mode' => 'direct',
        'start_date' => date('Y-m-d'),
        'end_date'   => date('Y-m-d'),
    ]);
    t('kind=person admin 생성 성공', $r['code'] === 200 && !empty($r['body']['success']), 'code=' . $r['code']);
    $cnt = (int)$db->query("
        SELECT COUNT(*) FROM tasks
         WHERE title = '" . $testTitle . "-person-admin'
           AND group_kind = 'person'
           AND group_scope = 'admin:{$adminId}'
           AND assignee_admin_id = {$adminId}
    ")->fetchColumn();
    t('kind=person admin row 의 scope/assignee 일치', $cnt === 1, "cnt={$cnt}");
}

// ── kind=person, target 비활성 거부 ─────
$r = req('POST', "{$base}/api/admin.php?action=task_create", $h, [
    'title' => $testTitle . '-person-bad',
    'assignment_kind' => 'person',
    'target_person' => ['type' => 'admin', 'id' => 99999999],
    'cohort' => $cohort,
    'date_mode' => 'direct',
    'start_date' => date('Y-m-d'),
    'end_date'   => date('Y-m-d'),
]);
t('kind=person 존재X 거부', !empty($r['body']['error']) || $r['code'] >= 400);

cleanup($db, $cohort, $testTitle);
echo "\n--- {$pass} pass / {$fail} fail ---\n";
exit($fail ? 1 : 0);
```

(`cohort_people_search` 테스트는 Task 4 에서 같은 파일에 추가)

- [ ] **Step 2: 테스트 실행해서 실패 확인**

Run: `cd /root/boot-dev && ADMIN_COOKIE='PHPSESSID_ADMIN=...' php tests/task_kind_api_test.php`

Expected: `kind=role 생성 성공` 정도까진 PASS 가능 (assignment_kind 미사용 시 폴백 동작), 나머지 FAIL.

쿠키 획득 어려우면 step 7 의 직접-DB 검증으로 대체.

- [ ] **Step 3: `task_create` case 의 입력 파싱 수정 (admin.php:1444-1462)**

`admin.php` 의 `case 'task_create':` 블록 시작부:

기존:
```php
case 'task_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $input = getJsonInput();
    $title    = trim($input['title'] ?? '');
    $roles    = $input['roles'] ?? [];
    $content  = trim($input['content_markdown'] ?? '') ?: null;
    $cohort   = trim($input['cohort'] ?? '') ?: getEffectiveCohort($admin);
    $dateMode = $input['date_mode'] ?? 'direct';
    $requiresSubmission = !empty($input['requires_submission']) ? 1 : 0;

    if (!$title || empty($roles)) jsonError('제목과 역할을 입력해주세요.');

    $validRoles = ['leader', 'subleader', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2', 'operation'];
    foreach ($roles as $r) {
        if (!in_array($r, $validRoles)) jsonError("올바르지 않은 역할: {$r}");
    }

    $db = getDB();
```

신규:
```php
case 'task_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $input = getJsonInput();
    $title    = trim($input['title'] ?? '');
    $kind     = $input['assignment_kind'] ?? 'role'; // 'role' | 'everyone' | 'person'
    $roles    = $input['roles'] ?? [];
    $target   = $input['target_person'] ?? null;
    $content  = trim($input['content_markdown'] ?? '') ?: null;
    $cohort   = trim($input['cohort'] ?? '') ?: getEffectiveCohort($admin);
    $dateMode = $input['date_mode'] ?? 'direct';
    $requiresSubmission = !empty($input['requires_submission']) ? 1 : 0;

    if (!$title) jsonError('제목을 입력해주세요.');
    if (!in_array($kind, ['role','everyone','person'], true)) {
        jsonError("올바르지 않은 부여 방식: {$kind}");
    }

    $validRoles = ['leader', 'subleader', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2', 'operation'];

    if ($kind === 'role') {
        if (empty($roles)) jsonError('역할을 하나 이상 선택해주세요.');
        foreach ($roles as $r) {
            if (!in_array($r, $validRoles)) jsonError("올바르지 않은 역할: {$r}");
        }
    } elseif ($kind === 'person') {
        if (!is_array($target) || empty($target['type']) || empty($target['id'])) {
            jsonError('담당자를 선택해주세요.');
        }
        if (!in_array($target['type'], ['admin','member'], true)) {
            jsonError("올바르지 않은 담당자 타입: {$target['type']}");
        }
    }
    // kind=everyone 은 추가 필드 없음

    $db = getDB();
```

- [ ] **Step 4: 부여 펼침 로직 교체 (admin.php:1526-1572)**

기존 `$insertAdminStmt`/`$insertMemberStmt` INSERT 와 그 뒤의 `foreach ($datePairs as [$sd, $ed]) { foreach ($roles as $role) { ... } }` 블록을 다음으로 교체:

```php
    // INSERT 준비 (group_kind/group_scope 포함)
    $insertAdminStmt = $db->prepare('
        INSERT INTO tasks
          (title, role, group_kind, group_scope, assignee_admin_id, start_date, end_date,
           content_markdown, cohort, requires_submission)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $insertMemberStmt = $db->prepare('
        INSERT INTO tasks
          (title, role, group_kind, group_scope, assignee_member_id, start_date, end_date,
           content_markdown, cohort, requires_submission)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $createdCount = 0;

    // cohort_id 룩업 (bootcamp_members 용)
    $cohortIdStmt = $db->prepare('SELECT id FROM cohorts WHERE cohort = ?');
    $cohortIdStmt->execute([$cohort]);
    $cohortIdRow = $cohortIdStmt->fetch();
    $cohortId = $cohortIdRow ? (int)$cohortIdRow['id'] : null;

    /**
     * 부여 펼침 → array of ['role'=>..., 'admin_id'=>?, 'member_id'=>?, 'group_scope'=>?]
     */
    $assignments = []; // 사람 단위 (모든 date 에 공통)

    if ($kind === 'role') {
        foreach ($roles as $role) {
            $isMemberRole = in_array($role, ['leader', 'subleader'], true);
            $rows = [];
            if ($isMemberRole && $cohortId) {
                $stmt = $db->prepare("
                    SELECT id FROM bootcamp_members
                     WHERE member_role = ? AND is_active = 1 AND cohort_id = ?
                ");
                $stmt->execute([$role, $cohortId]);
                foreach ($stmt->fetchAll() as $row) {
                    $rows[] = ['role' => $role, 'admin_id' => null, 'member_id' => (int)$row['id']];
                }
            } elseif (!$isMemberRole) {
                $stmt = $db->prepare("
                    SELECT a.id FROM admins a
                     JOIN admin_roles ar ON a.id = ar.admin_id
                     WHERE ar.role = ? AND a.is_active = 1
                       AND (a.cohort = ? OR a.cohort IS NULL)
                ");
                $stmt->execute([$role, $cohort]);
                foreach ($stmt->fetchAll() as $row) {
                    $rows[] = ['role' => $role, 'admin_id' => (int)$row['id'], 'member_id' => null];
                }
            }
            if (empty($rows)) {
                // role placeholder (기존 동작)
                $rows[] = ['role' => $role, 'admin_id' => null, 'member_id' => null];
            }
            foreach ($rows as $r) {
                $r['group_scope'] = $role;
                $assignments[] = $r;
            }
        }
    } elseif ($kind === 'everyone') {
        // admin role 들 (operation/head/subhead1/subhead2/coach/sub_coach)
        $stmt = $db->prepare("
            SELECT DISTINCT a.id AS admin_id, ar.role AS role
              FROM admins a
              JOIN admin_roles ar ON a.id = ar.admin_id
             WHERE a.is_active = 1
               AND ar.role IN ('operation','head','subhead1','subhead2','coach','sub_coach')
               AND (a.cohort = ? OR a.cohort IS NULL)
        ");
        $stmt->execute([$cohort]);
        foreach ($stmt->fetchAll() as $row) {
            $assignments[] = [
                'role' => $row['role'], 'admin_id' => (int)$row['admin_id'],
                'member_id' => null, 'group_scope' => null,
            ];
        }
        // leader/subleader 들
        if ($cohortId) {
            $stmt = $db->prepare("
                SELECT id AS member_id, member_role AS role
                  FROM bootcamp_members
                 WHERE is_active = 1 AND cohort_id = ?
                   AND member_role IN ('leader','subleader')
            ");
            $stmt->execute([$cohortId]);
            foreach ($stmt->fetchAll() as $row) {
                $assignments[] = [
                    'role' => $row['role'], 'admin_id' => null,
                    'member_id' => (int)$row['member_id'], 'group_scope' => null,
                ];
            }
        }
        if (empty($assignments)) jsonError('이 기수에 활성 멤버가 없습니다.');
    } else { // kind === 'person'
        $type = $target['type'];
        $id   = (int)$target['id'];
        if ($type === 'admin') {
            $stmt = $db->prepare("
                SELECT a.id, MIN(ar.role) AS role
                  FROM admins a
                  JOIN admin_roles ar ON a.id = ar.admin_id
                 WHERE a.id = ? AND a.is_active = 1
                   AND (a.cohort = ? OR a.cohort IS NULL)
                 GROUP BY a.id
            ");
            $stmt->execute([$id, $cohort]);
            $row = $stmt->fetch();
            if (!$row) jsonError('해당 admin 을 찾을 수 없거나 비활성입니다.');
            $assignments[] = [
                'role' => $row['role'], 'admin_id' => $id, 'member_id' => null,
                'group_scope' => "admin:{$id}",
            ];
        } else { // member
            if (!$cohortId) jsonError('기수 정보를 찾을 수 없습니다.');
            $stmt = $db->prepare("
                SELECT id, member_role FROM bootcamp_members
                 WHERE id = ? AND is_active = 1 AND cohort_id = ?
                   AND member_role IN ('leader','subleader')
            ");
            $stmt->execute([$id, $cohortId]);
            $row = $stmt->fetch();
            if (!$row) jsonError('해당 멤버를 찾을 수 없거나 부여 대상이 아닙니다.');
            $assignments[] = [
                'role' => $row['member_role'], 'admin_id' => null, 'member_id' => $id,
                'group_scope' => "member:{$id}",
            ];
        }
    }

    // INSERT 펼침
    foreach ($datePairs as [$sd, $ed]) {
        foreach ($assignments as $a) {
            if ($a['admin_id'] !== null) {
                $insertAdminStmt->execute([
                    $title, $a['role'], $kind, $a['group_scope'],
                    $a['admin_id'], $sd, $ed, $content, $cohort, $requiresSubmission,
                ]);
            } elseif ($a['member_id'] !== null) {
                $insertMemberStmt->execute([
                    $title, $a['role'], $kind, $a['group_scope'],
                    $a['member_id'], $sd, $ed, $content, $cohort, $requiresSubmission,
                ]);
            } else {
                // role placeholder (assignee NULL)
                $insertAdminStmt->execute([
                    $title, $a['role'], $kind, $a['group_scope'],
                    null, $sd, $ed, $content, $cohort, $requiresSubmission,
                ]);
            }
            $createdCount++;
        }
    }

    jsonSuccess(['created_count' => $createdCount], "Task가 {$createdCount}개 생성되었습니다.");
    break;
```

- [ ] **Step 5: 테스트 재실행 — 적어도 4 PASS (cohort_people_search 미작성)**

Run: `cd /root/boot-dev && ADMIN_COOKIE='PHPSESSID_ADMIN=...' php tests/task_kind_api_test.php`

Expected: 위에 작성된 6 assertions 중 6/6 PASS (admin 1명이라도 있으면 person 분기까지).

쿠키 없으면 직접 DB 검증 — 다음 SQL 로 sanity check:
```bash
source /root/boot-dev/.db_credentials
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  SELECT group_kind, group_scope, COUNT(*) FROM tasks GROUP BY group_kind, group_scope
" | head -20
```
모든 row 가 `('role', role)` 만 있어야 함 (아직 신규 부여 안 한 상태).

- [ ] **Step 6: 인보리언트 재실행 — 회귀 확인**

Run: `cd /root/boot-dev && php tests/task_kind_invariants.php`

Expected: `3 pass / 0 fail`

- [ ] **Step 7: 기존 task_group_invariants.php 회귀**

Run: `cd /root/boot-dev && php tests/task_group_invariants.php`

Expected: 모든 항목 PASS (기존 SQL 이 group_kind/scope 추가로 깨지지 않았는지)

- [ ] **Step 8: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/admin.php tests/task_kind_api_test.php
git commit -m "feat(task): task_create 에 assignment_kind=everyone/person 분기 추가"
```

---

## Task 4: `cohort_people_search` endpoint

**Files:**
- Modify: `/root/boot-dev/public_html/api/admin.php` (신규 case 추가, `task_create` case 위 또는 아래)
- Modify: `/root/boot-dev/tests/task_kind_api_test.php` (assertions 추가)

- [ ] **Step 1: 신규 endpoint 추가**

`admin.php` 의 `case 'task_delete':` 블록 바로 뒤에 추가:

```php
case 'cohort_people_search':
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $cohort = trim($_GET['cohort'] ?? '') ?: getEffectiveCohort($admin);
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 1) jsonError('검색어를 입력해주세요.');
    if (!$cohort) jsonError('cohort 가 필요합니다.');

    $db = getDB();
    $cohortIdStmt = $db->prepare('SELECT id FROM cohorts WHERE cohort = ?');
    $cohortIdStmt->execute([$cohort]);
    $cohortIdRow = $cohortIdStmt->fetch();
    $cohortId = $cohortIdRow ? (int)$cohortIdRow['id'] : null;

    $like = '%' . $q . '%';
    $people = [];

    // admin 후보 (cohort 일치 또는 NULL)
    $stmt = $db->prepare("
        SELECT a.id, a.name,
               GROUP_CONCAT(ar.role ORDER BY ar.role SEPARATOR ',') AS roles
          FROM admins a
          JOIN admin_roles ar ON a.id = ar.admin_id
         WHERE a.is_active = 1
           AND ar.role IN ('operation','head','subhead1','subhead2','coach','sub_coach')
           AND (a.cohort = ? OR a.cohort IS NULL)
           AND a.name LIKE ?
         GROUP BY a.id, a.name
         ORDER BY a.name
         LIMIT 20
    ");
    $stmt->execute([$cohort, $like]);
    $roleLabels = [
        'leader'=>'조장','subleader'=>'부조장','coach'=>'메인강사','sub_coach'=>'서브강사',
        'head'=>'총괄코치','subhead1'=>'부총괄1','subhead2'=>'부총괄2','operation'=>'운영팀'
    ];
    foreach ($stmt->fetchAll() as $row) {
        $roles = $row['roles'] ? explode(',', $row['roles']) : [];
        $labels = array_map(fn($r) => $roleLabels[$r] ?? $r, $roles);
        $people[] = [
            'type' => 'admin',
            'id'   => (int)$row['id'],
            'name' => $row['name'],
            'role_labels' => implode(', ', $labels),
        ];
    }

    // member (leader/subleader) 후보
    if ($cohortId) {
        $stmt = $db->prepare("
            SELECT bm.id, bm.real_name AS name, bm.nickname,
                   bm.member_role, bg.group_no
              FROM bootcamp_members bm
              LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
             WHERE bm.is_active = 1
               AND bm.cohort_id = ?
               AND bm.member_role IN ('leader','subleader')
               AND (bm.real_name LIKE ? OR bm.nickname LIKE ?)
             ORDER BY bg.group_no, bm.real_name
             LIMIT 20
        ");
        $stmt->execute([$cohortId, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            $people[] = [
                'type'        => 'member',
                'id'          => (int)$row['id'],
                'name'        => $row['name'],
                'nickname'    => $row['nickname'],
                'role_labels' => $roleLabels[$row['member_role']] ?? $row['member_role'],
                'group_no'    => $row['group_no'] !== null ? (int)$row['group_no'] : null,
            ];
        }
    }

    // 합쳐서 최대 20개
    $people = array_slice($people, 0, 20);
    jsonSuccess(['people' => $people]);
    break;
```

- [ ] **Step 2: 통합 테스트 assertions 추가**

`tests/task_kind_api_test.php` 의 마지막 `cleanup` 줄 바로 위에 추가:

```php
// ── cohort_people_search ─────
$me = $db->query("SELECT name FROM admins WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if ($me) {
    $q = mb_substr($me, 0, 1);
    $r = req('GET', "{$base}/api/admin.php?action=cohort_people_search&cohort=" . rawurlencode($cohort) . "&q=" . rawurlencode($q), $h);
    t('cohort_people_search 200',
        $r['code'] === 200 && !empty($r['body']['success']),
        'code=' . $r['code']);
    t('cohort_people_search 결과 ≥ 1', is_array($r['body']['people'] ?? null) && count($r['body']['people']) >= 1);
}

// q 빈 문자열 → 에러
$r = req('GET', "{$base}/api/admin.php?action=cohort_people_search&cohort=" . rawurlencode($cohort) . "&q=", $h);
t('cohort_people_search q 빈 거부', !empty($r['body']['error']));
```

- [ ] **Step 3: 테스트 실행**

Run: `cd /root/boot-dev && ADMIN_COOKIE='PHPSESSID_ADMIN=...' php tests/task_kind_api_test.php`

Expected: 모두 PASS (총 8~9 assertions)

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/admin.php tests/task_kind_api_test.php
git commit -m "feat(task): cohort_people_search endpoint (admin+leader/subleader)"
```

---

## Task 5: 묶음 endpoint 식별 키 확장 (`task_group_get/update/delete/rows`)

**Files:**
- Modify: `/root/boot-dev/public_html/api/admin.php` (1214-1361)
- Modify: `/root/boot-dev/tests/task_group_invariants.php` (SQL 동기화 주석에 group_kind 반영)

- [ ] **Step 1: 4 case 모두 식별 키에 `group_kind`/`group_scope` 추가**

`task_group_get` (admin.php:1214):

기존:
```php
case 'task_group_get':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $input = getJsonInput();
    $cohort = trim($input['cohort'] ?? '');
    $title  = trim($input['title']  ?? '');
    $role   = trim($input['role']   ?? '');
    if (!$cohort || !$title || !$role) jsonError('cohort/title/role 필수.');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT title, content_markdown, requires_submission
          FROM tasks
         WHERE cohort = ? AND title = ? AND role = ?
         ORDER BY start_date ASC
         LIMIT 1
    ");
    $stmt->execute([$cohort, $title, $role]);
    $row = $stmt->fetch();
    if (!$row) jsonError('해당 묶음을 찾을 수 없습니다.', 404);
    jsonSuccess([
        'title' => $row['title'],
        'content_markdown' => $row['content_markdown'],
        'requires_submission' => (int)$row['requires_submission'],
    ]);
    break;
```

신규:
```php
case 'task_group_get':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $input = getJsonInput();
    $cohort     = trim($input['cohort'] ?? '');
    $title      = trim($input['title']  ?? '');
    $role       = trim($input['role']   ?? '');
    $groupKind  = $input['group_kind']  ?? 'role';
    $groupScope = array_key_exists('group_scope', $input) ? $input['group_scope'] : $role;
    if (!$cohort || !$title) jsonError('cohort/title 필수.');
    if (!in_array($groupKind, ['role','everyone','person'], true)) jsonError('올바르지 않은 group_kind.');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT title, content_markdown, requires_submission, group_kind, group_scope
          FROM tasks
         WHERE cohort = ? AND title = ?
           AND group_kind = ?
           AND (group_scope <=> ?)
         ORDER BY start_date ASC
         LIMIT 1
    ");
    $stmt->execute([$cohort, $title, $groupKind, $groupScope]);
    $row = $stmt->fetch();
    if (!$row) jsonError('해당 묶음을 찾을 수 없습니다.', 404);
    jsonSuccess([
        'title' => $row['title'],
        'content_markdown' => $row['content_markdown'],
        'requires_submission' => (int)$row['requires_submission'],
        'group_kind' => $row['group_kind'],
        'group_scope' => $row['group_scope'],
    ]);
    break;
```

NULL-safe 비교 `<=>` 로 `everyone` (scope NULL) 도 매칭됨. 하위호환: 입력에 `group_kind` 가 없으면 `'role'`, `group_scope` 가 없으면 `role` 값 그대로.

- [ ] **Step 2: `task_group_update` 식별 키 확장 (admin.php:1309)**

기존 식별 키 부분 (`WHERE cohort = ? AND title = ? AND role = ?`) 을 `WHERE cohort = ? AND title = ? AND group_kind = ? AND (group_scope <=> ?)` 로 변경.

전체 신규 case:
```php
case 'task_group_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $input = getJsonInput();
    $cohort     = trim($input['cohort'] ?? '');
    $title      = trim($input['title']  ?? '');
    $role       = trim($input['role']   ?? '');
    $groupKind  = $input['group_kind']  ?? 'role';
    $groupScope = array_key_exists('group_scope', $input) ? $input['group_scope'] : $role;
    $newTitle   = trim($input['new_title'] ?? '');
    $newContent = trim($input['new_content_markdown'] ?? '');
    $newRequiresSubmission = !empty($input['requires_submission']) ? 1 : 0;
    if (!$cohort || !$title) jsonError('cohort/title 필수.');
    if ($newTitle === '') jsonError('새 제목을 입력해주세요.');

    $db = getDB();
    $stmt = $db->prepare("
        UPDATE tasks
           SET title = ?, content_markdown = ?, requires_submission = ?
         WHERE cohort = ? AND title = ?
           AND group_kind = ?
           AND (group_scope <=> ?)
    ");
    $stmt->execute([$newTitle, $newContent ?: null, $newRequiresSubmission, $cohort, $title, $groupKind, $groupScope]);
    jsonSuccess(['affected_count' => $stmt->rowCount()], 'Task 묶음이 수정되었습니다.');
    break;
```

- [ ] **Step 3: `task_group_delete` 식별 키 확장 (admin.php:1332)**

```php
case 'task_group_delete':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $input = getJsonInput();
    $cohort     = trim($input['cohort'] ?? '');
    $title      = trim($input['title']  ?? '');
    $role       = trim($input['role']   ?? '');
    $groupKind  = $input['group_kind']  ?? 'role';
    $groupScope = array_key_exists('group_scope', $input) ? $input['group_scope'] : $role;
    if (!$cohort || !$title) jsonError('cohort/title 필수.');

    $db = getDB();
    $del = $db->prepare("
        DELETE FROM tasks
         WHERE cohort = ? AND title = ?
           AND group_kind = ?
           AND (group_scope <=> ?)
           AND completed = 0
    ");
    $del->execute([$cohort, $title, $groupKind, $groupScope]);
    $deleted = $del->rowCount();

    $cnt = $db->prepare("
        SELECT COUNT(*) AS c
          FROM tasks
         WHERE cohort = ? AND title = ?
           AND group_kind = ?
           AND (group_scope <=> ?)
    ");
    $cnt->execute([$cohort, $title, $groupKind, $groupScope]);
    $kept = (int)$cnt->fetch()['c'];

    jsonSuccess([
        'deleted_count' => $deleted,
        'kept_count'    => $kept,
    ], "{$deleted}개 삭제 / {$kept}개 보존");
    break;
```

- [ ] **Step 4: `task_group_rows` 식별 키 확장 (admin.php:1363)**

먼저 현재 구현 확인:
```bash
sed -n '1363,1413p' /root/boot-dev/public_html/api/admin.php
```

확인 후, `WHERE` 절의 `AND role = ?` 패턴을 `AND group_kind = ? AND (group_scope <=> ?)` 로 교체. `$role` 파라미터 자리에 `$groupKind, $groupScope` 두 개 바인딩.

기존:
```php
case 'task_group_rows':
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $cohort = trim($_GET['cohort'] ?? '');
    $title  = trim($_GET['title']  ?? '');
    $role   = trim($_GET['role']   ?? '');
    ...
    WHERE cohort = ? AND title = ? AND role = ?
    ...
    $stmt->execute([..., $cohort, $title, $role]);
```

신규:
```php
case 'task_group_rows':
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $cohort     = trim($_GET['cohort'] ?? '');
    $title      = trim($_GET['title']  ?? '');
    $role       = trim($_GET['role']   ?? '');
    $groupKind  = $_GET['group_kind']  ?? 'role';
    $groupScope = array_key_exists('group_scope', $_GET) ? $_GET['group_scope'] : $role;
    ...
    WHERE cohort = ? AND title = ?
      AND group_kind = ?
      AND (group_scope <=> ?)
    ...
    $stmt->execute([..., $cohort, $title, $groupKind, $groupScope]);
```

(나머지 SQL/필터/응답 본문 그대로)

- [ ] **Step 5: `task_group_invariants.php` SQL 동기화 주석/패턴 갱신**

`/root/boot-dev/tests/task_group_invariants.php` 의 INV-1/INV-2/INV-3 SQL 에서 `(cohort, title, role)` 매칭하던 부분을 `(cohort, title, group_kind, group_scope)` NULL-safe `<=>` 로 갱신. 주석의 "SQL 동기화 주의" 도 group_kind 포함하도록 한 줄 보강.

- [ ] **Step 6: 인보리언트 + API 테스트 실행**

```bash
cd /root/boot-dev
php tests/task_kind_invariants.php
php tests/task_group_invariants.php
ADMIN_COOKIE='PHPSESSID_ADMIN=...' php tests/task_group_api_test.php
ADMIN_COOKIE='PHPSESSID_ADMIN=...' php tests/task_kind_api_test.php
```

Expected: 모든 테스트 PASS

- [ ] **Step 7: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/admin.php tests/task_group_invariants.php
git commit -m "refactor(task): 묶음 식별 키를 (cohort,title,group_kind,group_scope) 로 일반화"
```

---

## Task 6: `all_tasks_grouped` 확장 + kind 필터 + `today_tasks`/`overdue_tasks` 응답 필드

**Files:**
- Modify: `/root/boot-dev/public_html/api/admin.php` (1241-1306, 198-280)

- [ ] **Step 1: `all_tasks_grouped` GROUP BY 확장 + person_name JOIN + filter_role kind 분기**

`admin.php` 의 `case 'all_tasks_grouped':` 전체를 다음으로 교체:

```php
case 'all_tasks_grouped':
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $cohort = getEffectiveCohort($admin);
    $filterRole = $_GET['filter_role'] ?? '';
    $adminId = $admin['admin_id'];

    $db = getDB();

    // 공통 SELECT (LEFT JOIN 으로 person_name 만들기)
    $selectCore = "
        SELECT t.cohort, t.title, t.role,
               t.group_kind, t.group_scope,
               COUNT(*)                                AS total_count,
               SUM(t.completed)                        AS done_count,
               MIN(t.start_date)                       AS min_start_date,
               MAX(t.end_date)                         AS max_end_date,
               MAX(t.requires_submission)              AS requires_submission,
               COUNT(DISTINCT CASE
                       WHEN t.assignee_admin_id IS NOT NULL OR t.assignee_member_id IS NOT NULL
                       THEN CONCAT_WS(':', COALESCE(t.assignee_admin_id, '_'), COALESCE(t.assignee_member_id, '_'))
                     END) AS assignee_count,
               COALESCE(MIN(adm.name), MIN(bm.real_name)) AS person_name
          FROM tasks t
          LEFT JOIN admins adm
                 ON t.group_kind = 'person'
                AND t.group_scope = CONCAT('admin:', adm.id)
          LEFT JOIN bootcamp_members bm
                 ON t.group_kind = 'person'
                AND t.group_scope = CONCAT('member:', bm.id)
    ";
    $groupBy = "
        GROUP BY t.cohort, t.title, t.role, t.group_kind, t.group_scope
    ";
    $orderBy = "
        ORDER BY t.role, MIN(t.start_date) DESC, t.title
    ";

    if ($filterRole === 'mine') {
        $stmt = $db->prepare($selectCore . "
            WHERE t.cohort = ?
              AND (t.assignee_admin_id = ? OR t.assignee_member_id = ?)
        " . $groupBy . $orderBy);
        $stmt->execute([$cohort, $adminId, $adminId]);
    } elseif ($filterRole === 'kind:everyone') {
        $stmt = $db->prepare($selectCore . "
            WHERE t.cohort = ? AND t.group_kind = 'everyone'
        " . $groupBy . " ORDER BY MIN(t.start_date) DESC, t.title");
        $stmt->execute([$cohort]);
    } elseif ($filterRole === 'kind:person') {
        $stmt = $db->prepare($selectCore . "
            WHERE t.cohort = ? AND t.group_kind = 'person'
        " . $groupBy . " ORDER BY MIN(t.start_date) DESC, t.title");
        $stmt->execute([$cohort]);
    } elseif ($filterRole && $filterRole !== 'all') {
        // 기존: 특정 role 필터 (group_kind='role' 만 매칭 — everyone 은 별도 필터)
        $stmt = $db->prepare($selectCore . "
            WHERE t.cohort = ?
              AND t.group_kind = 'role'
              AND t.role = ?
        " . $groupBy . " ORDER BY MIN(t.start_date) DESC, t.title");
        $stmt->execute([$cohort, $filterRole]);
    } else {
        $stmt = $db->prepare($selectCore . "
            WHERE t.cohort = ?
        " . $groupBy . $orderBy);
        $stmt->execute([$cohort]);
    }
    jsonSuccess(['groups' => $stmt->fetchAll()]);
    break;
```

- [ ] **Step 2: `today_tasks` SELECT 에 `group_kind`/`group_scope` 추가 (admin.php:198-278)**

`today_tasks` case 의 3개 SELECT (mine/role filter/all 분기) 와 non-operation 분기 2개의 모든 `SELECT t.* FROM tasks t ...` 또는 명시 컬럼 SELECT 에 `t.group_kind, t.group_scope` 가 결과에 포함되도록 한다.

기존 코드가 `SELECT t.*, ...` 인지 명시 컬럼인지 확인:
```bash
sed -n '202,278p' /root/boot-dev/public_html/api/admin.php
```

만약 명시 컬럼이면 각 SELECT 의 마지막 컬럼 자리에 `, t.group_kind, t.group_scope` 추가. `SELECT t.*` 면 자동 포함됨.

- [ ] **Step 3: `overdue_tasks` 동일 처리 (admin.php:281-357)**

`today_tasks` 와 동일하게, 모든 SELECT 결과에 `group_kind`/`group_scope` 포함되도록 보장.

- [ ] **Step 4: 통합 테스트로 응답 필드 확인**

`tests/task_kind_api_test.php` 마지막 cleanup 직전에 추가:

```php
// today_tasks 응답에 group_kind/group_scope 포함되는지
$r = req('GET', "{$base}/api/admin.php?action=today_tasks", $h);
if (!empty($r['body']['tasks'])) {
    $first = $r['body']['tasks'][0];
    t('today_tasks 응답에 group_kind 포함',
        array_key_exists('group_kind', $first), 'keys=' . implode(',', array_keys($first)));
}

// all_tasks_grouped 의 group_kind/scope/person_name
$r = req('GET', "{$base}/api/admin.php?action=all_tasks_grouped&filter_role=all", $h);
if (!empty($r['body']['groups'])) {
    $first = $r['body']['groups'][0];
    t('all_tasks_grouped 응답에 group_kind 포함',
        array_key_exists('group_kind', $first));
    t('all_tasks_grouped 응답에 person_name 포함',
        array_key_exists('person_name', $first));
}

// kind:everyone / kind:person 필터
$r = req('GET', "{$base}/api/admin.php?action=all_tasks_grouped&filter_role=kind:everyone", $h);
t('filter_role=kind:everyone 200', $r['code'] === 200 && !empty($r['body']['success']));
$r = req('GET', "{$base}/api/admin.php?action=all_tasks_grouped&filter_role=kind:person", $h);
t('filter_role=kind:person 200', $r['code'] === 200 && !empty($r['body']['success']));
```

- [ ] **Step 5: 테스트 실행**

Run: `cd /root/boot-dev && ADMIN_COOKIE='PHPSESSID_ADMIN=...' php tests/task_kind_api_test.php`

Expected: 모든 assertions PASS

- [ ] **Step 6: 기존 API 회귀**

```bash
ADMIN_COOKIE='PHPSESSID_ADMIN=...' php tests/task_group_api_test.php
```

Expected: 기존 PASS 그대로 유지

- [ ] **Step 7: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/admin.php tests/task_kind_api_test.php
git commit -m "feat(task): all_tasks_grouped 에 group_kind/person_name + kind 필터 추가"
```

---

## Task 7: JS `canManageTasks()` 헬퍼 + head 탭에 Task 관리 추가

**Files:**
- Modify: `/root/boot-dev/public_html/js/admin.js` (5 isOperation 사용 지점, head 분기 탭)

- [ ] **Step 1: `canManageTasks()` 헬퍼 추가**

`admin.js:23-25` 의 `isOperation()` 정의 바로 아래에 추가:

```js
function canManageTasks() {
    return admin && admin.admin_roles &&
        admin.admin_roles.some(r => ['operation','head','subhead1','subhead2'].includes(r));
}
```

- [ ] **Step 2: head 분기 탭 목록에 "Task 관리" 추가 (admin.js:280-317)**

`admin.js` 의 `else` (head) 분기 탭 패널 (현재 ~280-317 라인) 의 `tab-wrap` 안 첫 번째 탭(`대시보드`) 바로 뒤에:

기존:
```js
                            <button class="tab active" data-tab="#bc-tab-dashboard" data-hash="dashboard">대시보드</button>
                            <button class="tab" data-tab="#bc-tab-checklist" data-hash="checklist">체크리스트</button>
```

신규:
```js
                            <button class="tab active" data-tab="#bc-tab-dashboard" data-hash="dashboard">대시보드</button>
                            <button class="tab" data-tab="#tab-tasks-mgmt" data-hash="tasks">Task 관리</button>
                            <button class="tab" data-tab="#bc-tab-checklist" data-hash="checklist">체크리스트</button>
```

같은 분기 안의 `tab-content` 목록에도 추가:

기존:
```js
                        <div class="tab-content active" id="bc-tab-dashboard"></div>
                        <div class="tab-content" id="bc-tab-checklist"></div>
```

신규:
```js
                        <div class="tab-content active" id="bc-tab-dashboard"></div>
                        <div class="tab-content" id="tab-tasks-mgmt"></div>
                        <div class="tab-content" id="bc-tab-checklist"></div>
```

- [ ] **Step 3: `loadTasksMgmt` 가 head 탭에서도 로딩되도록 핸들러 연결**

`admin.js` 의 탭 핸들러 연결 부분 (Promise.all 또는 tab change listener) 찾기:
```bash
grep -n "loadTasksMgmt\|tab-tasks-mgmt" /root/boot-dev/public_html/js/admin.js
```

`loadTasksMgmt()` 호출 지점이 `isOperation()` 분기 안에 있다면, `canManageTasks()` 로 교체.

예시 (정확한 위치는 grep 결과 따라가기):
```js
// 기존
if (isOperation()) {
    await loadTasksMgmt();
}

// 신규
if (canManageTasks()) {
    await loadTasksMgmt();
}
```

- [ ] **Step 4: 대시보드 task filter / 카드 배지 isOperation 교체**

`admin.js:167`:
```js
${isOperation() ? '<div class="section" id="sec-task-filter"></div>' : ''}
```
→
```js
${canManageTasks() ? '<div class="section" id="sec-task-filter"></div>' : ''}
```

`admin.js:384, 386, 859, 878, 949`:
- `if (isOperation()) renderTaskFilter();` → `if (canManageTasks()) renderTaskFilter();`
- `if (isOperation())` (filter_role 파라미터 전송) — 동일 교체
- 카드 role 배지 노출 (라인 949) — `isOperation()` → `canManageTasks()`

단, `isOperation()` 자체는 다른 용도(코인/리텐션 등)로 살아남아야 하므로 함수는 그대로 둠. **5개 지점만 `canManageTasks()` 로 치환.** 정확한 지점:

| 라인 | 코드 |
|---|---|
| 167 | `${isOperation() ? '<div class="section" id="sec-task-filter"></div>' : ''}` |
| 384 | `if (isOperation()) renderTaskFilter();` |
| 859 | `if (isOperation()) params.filter_role = taskFilter;` |
| 878 | `if (isOperation()) overdueParams.filter_role = taskFilter;` |
| 949 | `${isOperation() ? `<span class="badge ...` |

(라인 386 `if (isOperation())` 는 다른 블록일 수 있으므로 grep 으로 정확히 확인 후 처리. task 관련 부분만 교체)

- [ ] **Step 5: DEV 에서 head 로 로그인 → Task 관리 탭 보이는지 확인**

수동:
- `https://dev-boot.soritune.com/head/` 에 head 계정으로 로그인
- 상단 탭에 "Task 관리" 보이는지 확인
- 클릭 → `loadTasksMgmt()` 가 호출되어 묶음 표 보여야 함 (아직 '대상' 컬럼은 Task 9 에서)

이 단계는 사용자 검증 필요 — 실패 사례 보고 받으면 fix.

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/admin.js
git commit -m "feat(task): canManageTasks 헬퍼 + head 페이지 Task 관리 탭"
```

---

## Task 8: 생성 폼 — 3-way 라디오 + 사람 검색

**Files:**
- Modify: `/root/boot-dev/public_html/js/admin.js` (`showTaskForm`, 1654~)

- [ ] **Step 1: `showTaskForm` 의 `roleSection` (1672-1680) 교체**

기존:
```js
roleSection = `
    <div class="form-group">
        <label class="form-label">담당 역할 * (복수 선택 가능)</label>
        <div style="display:flex;flex-wrap:wrap;padding:8px 0">
            ${renderRoleCheckboxes([], 'tf')}
        </div>
    </div>
`;
```

신규:
```js
roleSection = `
    <div class="form-group">
        <label class="form-label">부여 방식 *</label>
        <div style="display:flex;gap:16px;padding:4px 0">
            <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">
                <input type="radio" name="tf-kind" value="role" checked> 역할별
            </label>
            <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">
                <input type="radio" name="tf-kind" value="everyone"> 전체
            </label>
            <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">
                <input type="radio" name="tf-kind" value="person"> 특정 인물
            </label>
        </div>
    </div>
    <div id="tf-kind-role" class="tf-kind-section">
        <div class="form-group">
            <label class="form-label">담당 역할 * (복수 선택 가능)</label>
            <div style="display:flex;flex-wrap:wrap;padding:8px 0">
                ${renderRoleCheckboxes([], 'tf')}
            </div>
        </div>
    </div>
    <div id="tf-kind-everyone" class="tf-kind-section" style="display:none">
        <p class="text-muted" style="font-size:0.85rem;padding:8px 0">
            현재 기수의 활성 운영진(운영팀·총괄·부총괄·메인강사·서브강사) +
            조장·부조장 전원에게 부여됩니다. 각자 자기 화면에서 개별 체크합니다.
        </p>
    </div>
    <div id="tf-kind-person" class="tf-kind-section" style="display:none">
        <div class="form-group">
            <label class="form-label">담당자 *</label>
            <input type="text" class="form-input" id="tf-person-search"
                   placeholder="이름·닉네임으로 검색 (최소 1자)" autocomplete="off">
            <div id="tf-person-results" class="person-search-results"
                 style="border:1px solid var(--gray-200,#e5e5e5);border-radius:6px;margin-top:4px;max-height:200px;overflow-y:auto;display:none"></div>
            <input type="hidden" id="tf-person-type">
            <input type="hidden" id="tf-person-id">
            <div id="tf-person-selected" style="margin-top:8px"></div>
        </div>
    </div>
`;
```

- [ ] **Step 2: 라디오 change → 패널 토글 핸들러 (showTaskForm 의 `if (!isEdit)` 블록 안)**

`admin.js` 의 `if (!isEdit) {` 안 (현재 `modeRadios` 핸들링 직후) 에 추가:

```js
if (!isEdit) {
    // 기존 date-mode 라디오 핸들러 그대로 유지
    const modeRadios = document.querySelectorAll('input[name="tf-date-mode"]');
    modeRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            document.querySelectorAll('.tf-date-section').forEach(s => s.style.display = 'none');
            const target = document.getElementById('tf-mode-' + radio.value);
            if (target) target.style.display = '';
        });
    });

    // 신규: kind 라디오 핸들러
    const kindRadios = document.querySelectorAll('input[name="tf-kind"]');
    kindRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            document.querySelectorAll('.tf-kind-section').forEach(s => s.style.display = 'none');
            const target = document.getElementById('tf-kind-' + radio.value);
            if (target) target.style.display = '';
        });
    });

    // 사람 검색
    setupPersonSearch();
}
```

- [ ] **Step 3: `setupPersonSearch` 헬퍼 추가**

`admin.js` 의 `showTaskForm` 함수 정의 직전 또는 `renderRoleCheckboxes` 헬퍼 근처에 추가:

```js
function setupPersonSearch() {
    const input    = document.getElementById('tf-person-search');
    const results  = document.getElementById('tf-person-results');
    const selected = document.getElementById('tf-person-selected');
    const hType    = document.getElementById('tf-person-type');
    const hId      = document.getElementById('tf-person-id');
    if (!input || !results || !selected) return;

    let debounceId = null;

    input.addEventListener('input', () => {
        clearTimeout(debounceId);
        const q = input.value.trim();
        if (q.length < 1) {
            results.style.display = 'none';
            results.innerHTML = '';
            return;
        }
        debounceId = setTimeout(async () => {
            const r = await App.get('/api/admin.php?action=cohort_people_search', { q });
            if (!r.success) { results.style.display = 'none'; return; }
            const people = r.people || [];
            if (!people.length) {
                results.innerHTML = '<div style="padding:8px;color:var(--gray-600,#888);font-size:0.85rem">검색 결과 없음</div>';
                results.style.display = '';
                return;
            }
            results.innerHTML = people.map(p => {
                const sub = p.type === 'admin'
                    ? `<span class="text-muted">${App.esc(p.role_labels || '')}</span>`
                    : `<span class="text-muted">${p.group_no ? p.group_no + '조 ' : ''}${App.esc(p.role_labels || '')}${p.nickname ? ' · ' + App.esc(p.nickname) : ''}</span>`;
                return `
                    <div class="person-search-item" data-type="${p.type}" data-id="${p.id}"
                         data-name="${App.esc(p.name)}"
                         style="padding:8px;cursor:pointer;border-bottom:1px solid var(--gray-100,#f3f4f6)">
                        <strong>${App.esc(p.name)}</strong>
                        <span style="margin-left:6px;font-size:0.85rem">${sub}</span>
                    </div>
                `;
            }).join('');
            results.style.display = '';
            results.querySelectorAll('.person-search-item').forEach(el => {
                el.addEventListener('click', () => {
                    hType.value = el.dataset.type;
                    hId.value   = el.dataset.id;
                    selected.innerHTML = `
                        <span class="badge badge-info" style="padding:6px 10px">
                            👤 ${App.esc(el.dataset.name)}
                            <button type="button" id="tf-person-clear"
                                    style="margin-left:6px;background:transparent;border:none;cursor:pointer">×</button>
                        </span>
                    `;
                    document.getElementById('tf-person-clear').addEventListener('click', () => {
                        hType.value = ''; hId.value = '';
                        selected.innerHTML = '';
                    });
                    results.style.display = 'none';
                    results.innerHTML = '';
                    input.value = '';
                });
            });
        }, 300);
    });
}
```

- [ ] **Step 4: `tf-save` payload 빌더에 `assignment_kind` 분기**

`admin.js` 의 `tf-save` 클릭 핸들러 (`document.getElementById('tf-save').onclick = async () => { ... }`) 의 `else` (생성 모드) 블록 (`payload.roles = getCheckedRoles('tf');` 부근) 을 다음으로 교체:

```js
} else {
    const kind = document.querySelector('input[name="tf-kind"]:checked')?.value || 'role';
    payload.assignment_kind = kind;

    if (kind === 'role') {
        payload.roles = getCheckedRoles('tf');
        if (!payload.roles.length) return Toast.warning('역할을 하나 이상 선택해주세요.');
    } else if (kind === 'person') {
        const type = document.getElementById('tf-person-type').value;
        const id   = parseInt(document.getElementById('tf-person-id').value, 10);
        if (!type || !id) return Toast.warning('담당자를 선택해주세요.');
        payload.target_person = { type, id };
    }
    // kind === 'everyone' 은 추가 필드 없음

    const mode = document.querySelector('input[name="tf-date-mode"]:checked').value;
    payload.date_mode = mode;

    if (mode === 'direct') {
        payload.start_date = document.getElementById('tf-start').value;
        payload.end_date = document.getElementById('tf-end').value;
        if (!payload.start_date || !payload.end_date) return Toast.warning('시작일과 종료일을 입력해주세요.');
    } else if (mode === 'week') {
        payload.week_number = parseInt(document.getElementById('tf-week-num').value);
        payload.weekday = parseInt(document.getElementById('tf-weekday').value);
        if (!payload.week_number || isNaN(payload.weekday)) return Toast.warning('주차와 요일을 선택해주세요.');
    } else if (mode === 'daily') {
        payload.repeat_days = Array.from(document.querySelectorAll('.tf-daily-day:checked')).map(cb => parseInt(cb.value));
        if (!payload.repeat_days.length) return Toast.warning('반복할 요일을 하나 이상 선택해주세요.');
    }
}
```

- [ ] **Step 5: DEV 에서 폼 동작 수동 확인**

operation/head 로그인 → Task 관리 → "추가" 버튼:
- 라디오 3개 보이고 default `역할별`
- `전체` 선택 → 안내 문구만 보임
- `특정 인물` 선택 → 검색창 보이고, "김" 입력하면 후보 리스트 등장
- 후보 클릭 → 칩 표시, × 클릭 시 해제
- 각 라디오에서 날짜 모드도 정상 동작

저장 시 created_count 가 합리적인지:
- 역할별 coach 1 → coach 인원 수
- 전체 → 모든 사람 수
- 특정 1명 → 1

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/admin.js
git commit -m "feat(task): 생성 폼 3-way 라디오 + 사람 검색 (everyone/person)"
```

---

## Task 9: 관리 표 — '대상' 컬럼 + kind 필터 chip

**Files:**
- Modify: `/root/boot-dev/public_html/js/admin.js` (`loadTasksMgmt`, 1565-1650 + `_editTaskGroup`/`_deleteTaskGroup`)

- [ ] **Step 1: 필터 chip 목록 확장**

`admin.js:1574-1583` 의 filters 배열에 kind 필터 추가:

```js
const filters = [
    { key: 'mine', label: '내 Task' },
    { key: 'all', label: '전체' },
    { key: 'kind:everyone', label: '📣 전체 부여' },
    { key: 'kind:person',   label: '👤 개인 부여' },
    { key: 'coach', label: '메인강사' },
    { key: 'sub_coach', label: '서브강사' },
    { key: 'head', label: '총괄' },
    { key: 'leader', label: '조장' },
    { key: 'operation', label: '운영팀' },
];
```

- [ ] **Step 2: 표 헤더 '역할' → '대상'**

`admin.js:1614`:
```js
<thead><tr><th>제목</th><th>역할</th><th>담당자</th><th>기간</th><th>진행</th><th></th></tr></thead>
```
→
```js
<thead><tr><th>제목</th><th>대상</th><th>담당자</th><th>기간</th><th>진행</th><th></th></tr></thead>
```

- [ ] **Step 3: 표 row 의 '대상' 셀 렌더 헬퍼**

`admin.js` 의 `loadTasksMgmt` 함수 정의 시작 부분 (`async function loadTasksMgmt() {` 직후) 에 헬퍼 추가:

```js
function renderTargetCell(g) {
    if (g.group_kind === 'everyone') {
        return '<span class="badge badge-info">📣 전체</span>';
    }
    if (g.group_kind === 'person') {
        const name = g.person_name || '(삭제된 사용자)';
        return `<span class="badge badge-info">👤 ${App.esc(name)}</span>`;
    }
    // role (기본/하위호환)
    return `<span class="badge badge-primary">${App.esc(ROLE_LABELS[g.role] || g.role)}</span>`;
}
```

- [ ] **Step 4: 표 row 의 '역할' 셀 자리에 `renderTargetCell` 호출**

`admin.js:1624` (`<td><span class="badge badge-primary">${App.esc(ROLE_LABELS[g.role] || g.role)}</span></td>`):

```js
<td>${renderTargetCell(g)}</td>
```

- [ ] **Step 5: row dataset 에 group_kind/group_scope 보존**

`admin.js:1622` (`<tr class="group-row" data-cohort="..." data-title="..." data-role="..." ...>`):

```js
<tr class="group-row"
    data-cohort="${cohortAttr}"
    data-title="${titleAttr}"
    data-role="${roleAttr}"
    data-group-kind="${App.esc(g.group_kind || 'role')}"
    data-group-scope="${encodeURIComponent(g.group_scope ?? '')}"
    style="cursor:pointer">
```

`_editTaskGroup`/`_deleteTaskGroup` 호출자도 갱신 (admin.js:1629-1630):

```js
<button class="btn-icon" onclick="event.stopPropagation();AdminApp._editTaskGroup('${cohortAttr}','${titleAttr}','${roleAttr}','${App.esc(g.group_kind || 'role')}','${encodeURIComponent(g.group_scope ?? '')}')">수정</button>
<button class="btn-icon danger" onclick="event.stopPropagation();AdminApp._deleteTaskGroup('${cohortAttr}','${titleAttr}','${roleAttr}','${App.esc(g.group_kind || 'role')}','${encodeURIComponent(g.group_scope ?? '')}',${parseInt(g.total_count)||0},${parseInt(g.done_count)||0},${parseInt(g.assignee_count)||0})">삭제</button>
```

(`assignee_count` 도 함께 넘겨 삭제 확인 다이얼로그에 활용)

- [ ] **Step 6: `_editTaskGroup` / `_deleteTaskGroup` 시그니처 + 본문 갱신**

기존 시그니처 찾기:
```bash
grep -n "_editTaskGroup\|_deleteTaskGroup" /root/boot-dev/public_html/js/admin.js | head
```

`_editTaskGroup(cohort, title, role, groupKind, groupScopeEnc)` 로 확장:
- `cohort = decodeURIComponent(cohort)` 등 디코딩 유지
- `groupScope = groupScopeEnc === '' ? null : decodeURIComponent(groupScopeEnc)`
- API 호출 `task_group_get` 페이로드에 `group_kind`, `group_scope` 추가
- 응답 받은 데이터로 `showTaskForm({ groupKey: { cohort, title, role, group_kind: groupKind, group_scope: groupScope }, ... })` 호출
- `tf-save` 의 edit 분기에서 payload 에 `group_kind`, `group_scope` 포함

`_deleteTaskGroup` 도 동일하게 시그니처 확장 + 페이로드 추가. 추가로 확인 다이얼로그에 부여 방식 표시:
```js
const kindLabel =
    groupKind === 'everyone' ? '📣 전체 부여' :
    groupKind === 'person'   ? '👤 개인 부여' :
    ROLE_LABELS[role] || role;
// confirm 텍스트: `${kindLabel} 묶음 "${title}" 의 미완료 task ${incompleteCount}개를 삭제합니다.`
```

- [ ] **Step 7: `showTaskForm` 의 edit 분기 묶음 정보 박스 갱신**

`admin.js:1658-1670` 의 isEdit 분기 `<div><strong>역할</strong>: ...</div>` 자리에:

```js
const kindRow = data.groupKey.group_kind === 'everyone'
    ? `<div><strong>대상</strong>: <span class="badge badge-info">📣 전체</span></div>`
    : data.groupKey.group_kind === 'person'
    ? `<div><strong>대상</strong>: <span class="badge badge-info">👤 ${App.esc(data.personName || '(삭제된 사용자)')}</span></div>`
    : `<div><strong>역할</strong>: ${App.esc(ROLE_LABELS[data.groupKey.role] || data.groupKey.role)}</div>`;

roleSection = `
    <div class="form-group">
        <label class="form-label">묶음 정보</label>
        <div style="background:var(--gray-50,#f5f5f5);border:1px solid var(--gray-200,#e5e5e5);border-radius:8px;padding:12px;font-size:0.9rem;line-height:1.6">
            ${kindRow}
            <div><strong>기수</strong>: ${App.esc(data.groupKey.cohort)}</div>
            <div><strong>기간</strong>: ${App.esc(data.periodLabel || '-')}</div>
            <div><strong>총 ${data.totalCount || 0}개</strong> (완료 ${data.doneCount || 0} / 미완료 ${(data.totalCount || 0) - (data.doneCount || 0)})</div>
        </div>
        <p class="text-muted" style="font-size:0.8rem;margin-top:6px">* 부여 방식·범위·기간은 묶음 식별 정보라 일괄 수정 대상이 아닙니다. 변경하려면 삭제 후 다시 만들어주세요.</p>
    </div>
`;
```

`_editTaskGroup` 에서 `personName` 도 같이 넘겨야 함 — `task_group_get` 응답에 `person_name` 추가하거나, `all_tasks_grouped` 의 row 에서 가져온 `g.person_name` 을 dataset 으로 전달.

간단한 방법: `_editTaskGroup` 호출 시 `g.person_name` 도 인자에 추가하고, `data.personName` 으로 전달.

- [ ] **Step 8: DEV 수동 검증**

operation 로그인 → Task 관리:
- '대상' 컬럼이 새로 보이고 기존 묶음은 role 배지로 표시 (회귀)
- `📣 전체 부여` chip 클릭 → everyone 묶음만
- `👤 개인 부여` chip 클릭 → person 묶음만
- everyone 묶음 row 의 [수정] → 묶음 정보 박스에 "대상: 📣 전체"
- person 묶음 row 의 [수정] → "대상: 👤 이름"
- 수정 모달에서 제목 변경 → 저장 후 표에 반영

- [ ] **Step 9: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/admin.js
git commit -m "feat(task): 관리 표 '대상' 컬럼 + kind 필터 chip + 수정/삭제 시그니처 확장"
```

---

## Task 10: 카드 배지 — 📣 전체 / 👤 개인 지정

**Files:**
- Modify: `/root/boot-dev/public_html/js/admin.js` (`renderTaskCard`, 918-960)

- [ ] **Step 1: `renderTaskCard` 배지 추가**

`admin.js:946-950` 의 task-meta 부분:

기존:
```js
<div class="task-meta">
    <span>${task.start_date} ~ ${task.end_date}</span>
    ${task.assignee_name ? `<span class="badge badge-primary">${App.esc(task.assignee_name)}</span>` : ''}
    ${isOperation() ? `<span class="badge badge-primary">${App.esc(ROLE_LABELS[task.role] || task.role)}</span>` : ''}
```

신규:
```js
<div class="task-meta">
    <span>${task.start_date} ~ ${task.end_date}</span>
    ${task.assignee_name ? `<span class="badge badge-primary">${App.esc(task.assignee_name)}</span>` : ''}
    ${task.group_kind === 'everyone' ? `<span class="badge badge-info">📣 전체</span>` : ''}
    ${task.group_kind === 'person'   ? `<span class="badge badge-info">👤 개인 지정</span>` : ''}
    ${canManageTasks() ? `<span class="badge badge-primary">${App.esc(ROLE_LABELS[task.role] || task.role)}</span>` : ''}
```

`📣 전체` / `👤 개인 지정` 배지는 **모든 사용자** 에게 노출 (조장/코치도 인지 가능).

`canManageTasks()` 로 바꾼 마지막 role 배지는 관리자(operation/head/subhead) 시야 유지.

- [ ] **Step 2: DEV 수동 검증**

- operation 계정으로 `kind=everyone` task 1개, `kind=person` task 1개 생성
- 해당 task 가 부여된 사람(예: 조장 1명) 으로 로그인 (leader 페이지)
- 대시보드의 오늘의 Task 카드에 배지 확인:
  - everyone → `📣 전체`
  - person → `👤 개인 지정`
- 같은 task 를 operation 으로 보면 위 배지 + role 배지 둘 다 보여야 함

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/admin.js
git commit -m "feat(task): 카드 배지 📣 전체 / 👤 개인 지정 (모든 사용자 노출)"
```

---

## Task 11: 최종 회귀 + dev push

**Files:** (커밋 없음)

- [ ] **Step 1: 모든 인보리언트 + 단위 테스트 일괄 실행**

```bash
cd /root/boot-dev
php tests/task_kind_invariants.php
php tests/task_group_invariants.php
ADMIN_COOKIE='PHPSESSID_ADMIN=...' php tests/task_group_api_test.php
ADMIN_COOKIE='PHPSESSID_ADMIN=...' php tests/task_kind_api_test.php
OP_COOKIE='...' HEAD_COOKIE='...' COACH_COOKIE='...' php tests/task_permissions_test.php
```

Expected: 모두 PASS

테스트 쿠키 획득 어려우면 사용자에게 위임. 인보리언트(쿠키 불필요)만이라도 PASS 보고.

- [ ] **Step 2: DEV 화면 수동 시각 검증 체크리스트**

operation 계정으로:
- [ ] Task 관리 탭 진입, 기존 묶음들 '대상' 컬럼에 role 배지 (회귀)
- [ ] 추가 → 역할별 → coach 선택 → 저장 → 표에 1행 추가, 카운트 정상
- [ ] 추가 → 전체 → 저장 → 표에 `📣 전체` 묶음, 인원수 정상
- [ ] 추가 → 특정 인물 → 검색 → 1명 선택 → 저장 → 표에 `👤 이름` 묶음, 인원=1
- [ ] kind chip 필터링 동작
- [ ] 묶음 수정/삭제 정상 (모든 kind)
- [ ] 카드 배지 (📣/👤) 본인 시야와 다른 role 시야 모두 확인

head 계정으로:
- [ ] head 페이지에 Task 관리 탭 보임
- [ ] 추가/수정/삭제 가능
- [ ] operation 이 만든 묶음도 보임 (cohort 동일)

coach 계정으로:
- [ ] Task 관리 탭 안 보임 (회귀 — 페이지에 원래 없었음)
- [ ] 오늘의 Task 카드에 자기에게 배정된 task 가 kind 배지 포함해서 보임
- [ ] task_create POST 직접 호출 시 403

- [ ] **Step 3: dev push**

```bash
cd /root/boot-dev
git log --oneline origin/dev..HEAD
git push origin dev
```

Expected: 10개 commit (Task 1~10) push 성공.

- [ ] **Step 4: ⛔ 사용자 DEV 확인 요청**

다음 메시지로 사용자에게 보고:

> "DEV 작업 완료, `dev-boot.soritune.com` 에 반영했습니다. 위 체크리스트 + 본인이 떠올리는 시나리오로 확인 부탁드립니다. 인보리언트/단위테스트 모두 PASS. 운영 반영 명시 요청 전까지 main 머지·PROD 마이그 진행하지 않습니다."

**메모리 룰: 사용자가 "운영 반영해줘" 명시 시점까지 main 머지 절대 금지.**

---

## Task 12: PROD 머지 + PROD 마이그 (사용자 명시 후만 실행)

**Files:** (PROD 코드 머지 + DB 마이그)

- [ ] **Step 1: dev 에서 main 머지**

```bash
cd /root/boot-dev
git checkout main
git pull origin main
git merge --no-ff dev -m "Merge dev: head 권한 + Task 다중 부여 방식 (역할별/전체/특정인)"
git push origin main
git checkout dev
```

- [ ] **Step 2: PROD pull**

```bash
cd /root/boot-prod
git pull origin main
ls -la migrate_tasks_group_kind.php
```

Expected: 신규 마이그 파일 보임.

- [ ] **Step 3: PROD DB 마이그 실행**

```bash
cd /root/boot-prod
php migrate_tasks_group_kind.php
```

Expected:
- `ALTER: group_kind ENUM 추가...` / `ALTER: group_scope ... 추가...` / `INDEX: idx_cohort_group 추가...`
- 백필 N row (PROD 기준 현 task 수)
- `PASS`

- [ ] **Step 4: PROD 인보리언트 (있다면 PROD 에서도)**

```bash
cd /root/boot-prod
php tests/task_kind_invariants.php
php tests/task_group_invariants.php
```

Expected: 모두 PASS

- [ ] **Step 5: PROD 스모크**

운영팀 1명 + 총괄 1명 로그인 → Task 관리 → 기존 묶음 보임 → kind=role/everyone/person 각각 추가 → 부여된 사람 카드 보임 → 토글 → 묶음 수정 → 삭제 (미완료만).

- [ ] **Step 6: 작업 메모리 업데이트**

`memory/` 의 진행 중 작업 라인을 완료로 이동. 인사이트 (NULL group_scope NULL-safe `<=>` / placeholder 가 group_kind 도 'role' 로 박힌다는 점 등) 기록.

---

## Self-Review (작성자 메모)

- [x] **Spec coverage:** spec §2(데이터 모델)=Task 1, §3.1(task_create)=Task 3, §3.2(cohort_people_search)=Task 4, §3.3(묶음 endpoint 확장)=Task 5+6, §3.4(권한)=Task 2, §4.1(head 탭/canManageTasks)=Task 7, §4.2(생성 폼 라디오)=Task 8, §4.3(사람 검색)=Task 8, §4.4(대상 컬럼)=Task 9, §4.5(수정 모달)=Task 9, §4.6 / §3.3 LEFT JOIN person_name = Task 6+9, §5(테스트)=Task 1/3/4/6, §6(마이그 + 배포)=Task 1+11+12. 모든 spec 섹션이 task 로 매핑됨.
- [x] **No placeholders:** 모든 step 에 실제 코드/SQL/명령어. "TODO"/"적절한" 없음. (cookie 획득은 외부 의존이라 환경변수 패턴으로 명시.)
- [x] **Type consistency:** `canManageTasks()`/`group_kind`/`group_scope` 이름이 Task 1 이후 모든 task 에서 동일. `assignment_kind` (API) ↔ `tf-kind` (DOM) ↔ `group_kind` (DB) 의 3-레이어 매핑은 의도적으로 분리 (`assignment_kind` 는 입력 페이로드, `group_kind` 는 DB 컬럼명).
- [x] **DRY/YAGNI:** `task_groups` 별도 테이블, group_id FK, person 다중 부여 등은 spec 결정에 따라 의도적 제외.

---

## 실행 모드 선택

플랜 작성 완료, `docs/superpowers/plans/2026-05-19-task-head-role-and-multi-assign.md` 에 저장됨. 두 가지 실행 옵션:

1. **Subagent-Driven (권장)** — task 마다 fresh subagent 디스패치 + 2단계 리뷰 (spec/code-quality). 운영 가드 룰 (PROD 머지 보류 등) 자연스럽게 적용.
2. **Inline Execution** — 본 세션에서 batch 실행 + 체크포인트.

이 boot 작업 패턴 (CSS/JS/PHP 혼합 + DB 마이그 + 화면 검증 필요) 은 **Subagent-Driven** 가 메모리 룰 (dev push 후 대기) 과 잘 맞습니다.

어느 방식으로 진행할까요?
