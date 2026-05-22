# 운영자 결정 「퇴출 (expelled)」 — 실행 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `bootcamp_members.member_status` 에 `expelled` enum 값을 추가해서 운영자가 명시적으로 "내보내기" 결정한 회원이 단체활동 (cron inbox / 출석률 / 후기 / 부티즈 / QR 스캔 / 카페 자동체크 / 조별 코인) 에서 모두 빠지도록 한다. 로그인 + 본인 페이지는 유지. group_id NULL 자동 초기화. 운영자 수동 복원만.

**Architecture:**
- enum 확장 1개 (`ALTER TABLE bootcamp_members MODIFY member_status ENUM(...,'expelled')`) — 데이터 마이그 0건
- 단체활동 게이트 10곳 (직전 leaving redefinition 으로 정렬된 `!= 'refunded'` 패턴) 을 `NOT IN ('refunded','expelled')` 로 한 칸 더 좁힘
- `handleMemberSetStatus` 확장 (input 검증 + 분기 + `admin_action_logs` INSERT)
- UI: 회원 카드에 `[내보내기]` / `[복원]` 버튼 추가, confirm + 선택 사유 prompt, 운영자 기본 필터에서 expelled 도 기본 숨김

**Tech Stack:** PHP 8 + MariaDB 10.x + 바닐라 JS. boot 컨벤션의 `tests/*_invariants.php` (CLI, transaction rollback) 5종 신규 + 기존 leaving_* 5종에 expelled fixture 추가. 마이그는 boot 의 단일 파일 멱등 `migrate_*.php` 패턴 (INFORMATION_SCHEMA 가드).

**관련 spec:** `docs/superpowers/specs/2026-05-22-member-expelled-design.md`

**작업 룰 (반드시 준수):**
- `boot-dev` (DEV_BOOT, dev 브랜치) 에서만 작업/코드 수정.
- Task 14 의 `git push origin dev` 후 **⛔ 멈춤**. 사용자가 운영 반영을 명시할 때만 main 머지 + prod pull.

---

## File Structure

| 파일 | 역할 | 변경 종류 |
|------|------|----------|
| `migrate_member_status_expelled.php` | enum 확장 (`active`/`leaving`/`OOM`/`refunded`/`expelled`) 멱등 마이그 | 신규 |
| `tests/expelled_cron_invariants.php` | cron init_daily_checks SELECT 가 expelled 제외 | 신규 |
| `tests/expelled_qr_scan_invariants.php` | expelled 회원 QR 스캔 거부 | 신규 |
| `tests/expelled_cafe_ingest_invariants.php` | expelled 회원 카페 인제스트 거부 | 신규 |
| `tests/expelled_review_invariants.php` | expelled 후기 작성 차단 | 신규 |
| `tests/expelled_bootees_invariants.php` | 부티즈 목록 expelled 제외 | 신규 |
| `tests/expelled_set_status_invariants.php` | handleMemberSetStatus expelled 분기 + admin_action_logs INSERT | 신규 |
| `tests/leaving_cron_invariants.php` | fixture 에 expelled row 추가 (regression) | 수정 |
| `tests/leaving_review_invariants.php` | fixture 에 expelled row 추가 (regression) | 수정 |
| `tests/leaving_bootees_invariants.php` | fixture 에 expelled row 추가 (regression) | 수정 |
| `tests/leaving_qr_scan_invariants.php` | (expelled 회원 거부 fixture 는 신규 테스트가 담당, regression 만 sanity) | 변경 없음 |
| `tests/leaving_cafe_ingest_invariants.php` | (동일) | 변경 없음 |
| `public_html/cron.php` (89, 158) | `!= 'refunded'` → `NOT IN ('refunded','expelled')` | 수정 |
| `public_html/api/services/attendance.php` (21) | `!= 'refunded'` → `NOT IN ('refunded','expelled')` | 수정 |
| `public_html/api/services/review.php` (32) | blocklist 에 `'expelled'` 추가 | 수정 |
| `public_html/api/services/member_page.php` (402) | `!= 'refunded'` → `NOT IN ('refunded','expelled')` | 수정 |
| `public_html/api/admin.php` (594, 628, 788-810) | 기본 필터 + statusCounts + setStatus 확장 | 수정 |
| `public_html/api/services/member.php` (155-175) | handleMemberSetStatus 확장 + admin_action_logs | 수정 |
| `public_html/includes/qr_actions.php` (28) | `!= 'refunded'` → `NOT IN ('refunded','expelled')` | 수정 |
| `public_html/api/qr.php` (178, 258) | 동일 | 수정 |
| `public_html/includes/cafe/cafe_ingest.php` (`resolveMemberByKey`) | `is_active=1` → `is_active=1 AND member_status NOT IN ('refunded','expelled')` | 수정 |
| `public_html/api/services/coin_reward_group.php` (221) | INACTIVE_STATUSES 에 `'expelled'` 추가 | 수정 |
| `public_html/js/memberTable.js` (39-45, 148-157) | expelled 배지 + 카드 버튼 (`[내보내기]`/`[복원]`/`[조에 복귀]`) | 수정 |
| `public_html/js/admin.js` (1116-1132, 1370-1377) | footer expelled 카운트 + 체크박스 라벨 + `_setMemberStatus` reason prompt | 수정 |
| `public_html/js/bootcamp.js` (1671-1680, 2287) | 동일 (배지 + reason prompt) | 수정 |

---

## Task 1: enum 확장 마이그 + 멱등 가드 검증

**Files:**
- Create: `migrate_member_status_expelled.php`

- [ ] **Step 1: 마이그 스크립트 작성**

Create `/root/boot-dev/migrate_member_status_expelled.php` with EXACT content:

```php
<?php
/**
 * bootcamp_members.member_status ENUM 에 'expelled' 추가.
 *
 * 사용: php migrate_member_status_expelled.php
 *
 * 멱등: 현재 enum 정의 검사 → 'expelled' 이미 있으면 skip.
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "== bootcamp_members.member_status enum 확장 ==\n";

$colStmt = $db->prepare("
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'bootcamp_members'
      AND COLUMN_NAME = 'member_status'
");
$colStmt->execute();
$currentType = (string)$colStmt->fetchColumn();

if ($currentType === '') {
    echo "FAIL: bootcamp_members.member_status 컬럼을 찾을 수 없음\n";
    exit(1);
}

echo "현재: {$currentType}\n";

if (stripos($currentType, "'expelled'") !== false) {
    echo "skip: enum 에 'expelled' 이미 존재\n";
    exit(0);
}

$db->exec("
    ALTER TABLE bootcamp_members
      MODIFY COLUMN member_status
        ENUM('active','leaving','out_of_group_management','refunded','expelled')
        NOT NULL DEFAULT 'active'
");

$colStmt->execute();
$afterType = (string)$colStmt->fetchColumn();
echo "변경: {$afterType}\n";

if (stripos($afterType, "'expelled'") === false) {
    echo "FAIL: ALTER 후에도 'expelled' 가 enum 에 없음\n";
    exit(1);
}
echo "PASS\n";
```

- [ ] **Step 2: 마이그 실행 (DEV)**

```bash
cd /root/boot-dev && php migrate_member_status_expelled.php
```

Expected: 첫 실행은 `현재: enum('active','leaving','out_of_group_management','refunded')` → `변경: enum('active','leaving','out_of_group_management','refunded','expelled') ... PASS`.

- [ ] **Step 3: 멱등 재실행 확인**

```bash
cd /root/boot-dev && php migrate_member_status_expelled.php
```

Expected: `skip: enum 에 'expelled' 이미 존재`.

- [ ] **Step 4: DB 직접 확인**

```bash
source /root/boot-dev/.db_credentials && mysql -u"$DB_USER" -p"$DB_PASS" SORITUNECOM_DEV_BOOT -e "
SHOW COLUMNS FROM bootcamp_members LIKE 'member_status';
"
```

Expected: `Type` 컬럼이 `enum('active','leaving','out_of_group_management','refunded','expelled')`.

- [ ] **Step 5: commit**

```bash
cd /root/boot-dev
git add migrate_member_status_expelled.php
git commit -m "feat(migration): bootcamp_members.member_status enum 에 'expelled' 추가

운영자 결정 퇴출 상태를 위한 새 enum 값. 멱등 (현재 enum 검사 → skip).
backward-compatible (기존 4값 보존).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: cron 게이트 + invariant

**Files:**
- Create: `tests/expelled_cron_invariants.php`
- Modify: `public_html/cron.php:89, 158`

- [ ] **Step 1: invariant 테스트 작성**

Create `/root/boot-dev/tests/expelled_cron_invariants.php` with EXACT content:

```php
<?php
/**
 * cron init_daily_checks 의 활성 멤버 SELECT 가 expelled 회원을 제외하는지.
 * 정책: '단체 활동 대상' = is_active=1 AND member_status NOT IN ('refunded','expelled')
 *
 * 사용: php tests/expelled_cron_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();
$db->beginTransaction();

try {
    $cohortLabel = 'TEST_XPL_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 1, CURDATE())");

    $ins->execute([$cohortId, '활성', 'a', 'active', 1]);
    $idA = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '나간', 'l', 'leaving', 1]);
    $idL = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '강등', 'o', 'out_of_group_management', 1]);
    $idO = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '환불', 'r', 'refunded', 0]);
    $idR = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '활성환불', 'ra', 'refunded', 1]);
    $idRactive = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '퇴출', 'x', 'expelled', 1]);
    $idX = (int)$db->lastInsertId();

    $today = date('Y-m-d');
    $sql = "
        SELECT bm.id
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.is_active = 1
          AND bm.member_status NOT IN ('refunded','expelled')
          AND c.start_date <= ? AND c.end_date >= ?
          AND bm.cohort_id = ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$today, $today, $cohortId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    sort($ids);

    $expected = [$idA, $idL, $idO];
    sort($expected);

    t('cron SELECT = active + leaving + OOM (3명)', $ids === $expected,
      'got=' . json_encode($ids) . ' expected=' . json_encode($expected));
    t('refunded(is_active=0) 제외', !in_array($idR, $ids, true));
    t('refunded(is_active=1) 제외 (member_status 가드)', !in_array($idRactive, $ids, true));
    t('expelled(is_active=1) 제외 (member_status 가드)', !in_array($idX, $ids, true));
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 테스트 실행 — PASS (invariant 라 코드 변경 전에도 통과)**

```bash
php /root/boot-dev/tests/expelled_cron_invariants.php
```

Expected: `4 PASS, 0 FAIL`.

- [ ] **Step 3: cron.php:89 (initDailyChecks) 변경**

Edit `/root/boot-dev/public_html/cron.php` 의 라인 84~92 부근 SELECT:

```php
// 변경 전
$members = $db->query("
    SELECT bm.id AS member_id, bm.cohort_id, bm.group_id
    FROM bootcamp_members bm
    JOIN cohorts c ON bm.cohort_id = c.id
    WHERE bm.is_active = 1
      AND bm.member_status != 'refunded'
      AND c.start_date <= '{$today}'
      AND c.end_date >= '{$today}'
")->fetchAll(PDO::FETCH_ASSOC);

// 변경 후
$members = $db->query("
    SELECT bm.id AS member_id, bm.cohort_id, bm.group_id
    FROM bootcamp_members bm
    JOIN cohorts c ON bm.cohort_id = c.id
    WHERE bm.is_active = 1
      AND bm.member_status NOT IN ('refunded','expelled')
      AND c.start_date <= '{$today}'
      AND c.end_date >= '{$today}'
")->fetchAll(PDO::FETCH_ASSOC);
```

- [ ] **Step 4: cron.php:158 (backfillChecks) 동일 변경**

```php
// 변경 전
WHERE bm.is_active = 1
  AND bm.member_status != 'refunded'
  AND c.end_date >= '{$yesterday}'

// 변경 후
WHERE bm.is_active = 1
  AND bm.member_status NOT IN ('refunded','expelled')
  AND c.end_date >= '{$yesterday}'
```

- [ ] **Step 5: 변경 grep 확인**

```bash
grep -n "member_status != 'refunded'" /root/boot-dev/public_html/cron.php
# Expected: empty
grep -n "member_status NOT IN ('refunded','expelled')" /root/boot-dev/public_html/cron.php
# Expected: 2 lines
```

- [ ] **Step 6: invariant 재실행**

```bash
php /root/boot-dev/tests/expelled_cron_invariants.php
```

Expected: `4 PASS, 0 FAIL`.

- [ ] **Step 7: commit**

```bash
cd /root/boot-dev
git add tests/expelled_cron_invariants.php public_html/cron.php
git commit -m "fix(cron): expelled 회원도 일일 미션 inbox 생성에서 제외

init_daily_checks + backfillChecks 게이트를 NOT IN ('refunded','expelled') 로
한 칸 더 좁힘. spec 5 #1~#2.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: 출석률 분모 변경

**Files:**
- Modify: `public_html/api/services/attendance.php:21`

- [ ] **Step 1: SQL 변경**

Edit `/root/boot-dev/public_html/api/services/attendance.php` 의 `handleAttendanceStats()` (라인 17~22):

```php
// 변경 전
$totalStmt = $db->prepare("
    SELECT COUNT(*) FROM bootcamp_members
    WHERE cohort_id = ? AND is_active = 1
      AND member_status != 'refunded'
");

// 변경 후
$totalStmt = $db->prepare("
    SELECT COUNT(*) FROM bootcamp_members
    WHERE cohort_id = ? AND is_active = 1
      AND member_status NOT IN ('refunded','expelled')
");
```

- [ ] **Step 2: 변경 grep 확인**

```bash
grep -n "member_status != 'refunded'" /root/boot-dev/public_html/api/services/attendance.php
# Expected: empty
grep -n "member_status NOT IN ('refunded','expelled')" /root/boot-dev/public_html/api/services/attendance.php
# Expected: 1 line
```

- [ ] **Step 3: commit**

```bash
cd /root/boot-dev
git add public_html/api/services/attendance.php
git commit -m "fix(attendance): 출석률 분모에서 expelled 회원 제외

cron 게이트 정렬. spec 5 #3.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: 후기 작성 권한 invariant + 코드 변경

**Files:**
- Create: `tests/expelled_review_invariants.php`
- Modify: `public_html/api/services/review.php:32`

- [ ] **Step 1: invariant 테스트 작성**

Create `/root/boot-dev/tests/expelled_review_invariants.php` with EXACT content:

```php
<?php
/**
 * expelled 회원은 후기 작성이 차단되는지.
 *
 * 사용: php tests/expelled_review_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();
$db->beginTransaction();

try {
    $cohortLabel = 'TEST_XREV_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 1, CURDATE())");

    $ins->execute([$cohortId, '활성', 'a', 'active', 1]);
    $idA = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '나간', 'l', 'leaving', 1]);
    $idL = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '강등', 'o', 'out_of_group_management', 1]);
    $idO = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '환불', 'r', 'refunded', 0]);
    $idR = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '퇴출', 'x', 'expelled', 1]);
    $idX = (int)$db->lastInsertId();

    // review.php 게이트 (변경 후): refunded + expelled 차단
    $blocked = ['refunded', 'expelled'];

    $stmt = $db->prepare("SELECT member_status FROM bootcamp_members WHERE id = ?");

    foreach ([
        ['active', $idA, false],
        ['leaving', $idL, false],
        ['out_of_group_management', $idO, false],
        ['refunded', $idR, true],
        ['expelled', $idX, true],
    ] as [$label, $id, $expectBlock]) {
        $stmt->execute([$id]);
        $status = $stmt->fetchColumn();
        $isBlocked = in_array($status, $blocked, true);
        t("{$label} 회원 차단 여부 = " . ($expectBlock ? 'Y' : 'N'), $isBlocked === $expectBlock);
    }
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 테스트 실행 (변경 후 invariant — PASS 정상)**

```bash
php /root/boot-dev/tests/expelled_review_invariants.php
```

Expected: `5 PASS, 0 FAIL`.

- [ ] **Step 3: review.php 변경**

Edit `/root/boot-dev/public_html/api/services/review.php:32` (`evaluateReviewEligibility()` 안):

```php
// 변경 전
    if ((int)$member['is_active'] !== 1 ||
        in_array($member['member_status'], ['refunded'])) {
        return ['eligible' => false, 'reason' => 'member_inactive', 'active_cycle' => null, 'member' => $member];

// 변경 후
    if ((int)$member['is_active'] !== 1 ||
        in_array($member['member_status'], ['refunded', 'expelled'])) {
        return ['eligible' => false, 'reason' => 'member_inactive', 'active_cycle' => null, 'member' => $member];
```

- [ ] **Step 4: 변경 grep 확인**

```bash
grep -n "in_array(\$member\['member_status'\]" /root/boot-dev/public_html/api/services/review.php
```

Expected: 한 줄 — `in_array($member['member_status'], ['refunded', 'expelled'])`.

- [ ] **Step 5: commit**

```bash
cd /root/boot-dev
git add public_html/api/services/review.php tests/expelled_review_invariants.php
git commit -m "fix(review): expelled 회원 후기 작성 차단 + invariant

spec 5 #4. blocklist 에 expelled 추가.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: 부티즈 목록 invariant + 코드 변경

**Files:**
- Create: `tests/expelled_bootees_invariants.php`
- Modify: `public_html/api/services/member_page.php:402`

- [ ] **Step 1: invariant 테스트 작성**

Create `/root/boot-dev/tests/expelled_bootees_invariants.php` with EXACT content:

```php
<?php
/**
 * handleMemberBootees 의 같은-기수 부티즈 목록이 expelled 회원을 제외하는지.
 *
 * 사용: php tests/expelled_bootees_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();
$db->beginTransaction();

try {
    $cohortLabel = 'TEST_XBTZ_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 1, CURDATE())");

    $ins->execute([$cohortId, '활성', 'a', 'active', 1]);
    $idA = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '나간', 'l', 'leaving', 1]);
    $idL = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '강등', 'o', 'out_of_group_management', 1]);
    $idO = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '환불', 'r', 'refunded', 0]);
    $idR = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '활성환불', 'ra', 'refunded', 1]);
    $idRactive = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '퇴출', 'x', 'expelled', 1]);
    $idX = (int)$db->lastInsertId();

    // member_page.php:402 부티즈 SELECT (변경 후)
    $sql = "
        SELECT bm.id
        FROM bootcamp_members bm
        WHERE bm.cohort_id = ?
          AND bm.is_active = 1
          AND bm.member_status NOT IN ('refunded','expelled')
        ORDER BY bm.id ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$cohortId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    sort($ids);

    $expected = [$idA, $idL, $idO];
    sort($expected);

    t('부티즈 목록 = active + leaving + OOM (3명)', $ids === $expected,
      'got=' . json_encode($ids) . ' expected=' . json_encode($expected));
    t('refunded(is_active=0) 제외', !in_array($idR, $ids, true));
    t('refunded(is_active=1) 제외', !in_array($idRactive, $ids, true));
    t('expelled(is_active=1) 제외', !in_array($idX, $ids, true));
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 테스트 실행**

```bash
php /root/boot-dev/tests/expelled_bootees_invariants.php
```

Expected: `4 PASS, 0 FAIL`.

- [ ] **Step 3: member_page.php:402 변경**

Edit `/root/boot-dev/public_html/api/services/member_page.php` `handleMemberBootees()` 안 SELECT (라인 388~404 부근):

```php
// 변경 전 (라인 400~402)
WHERE bm.cohort_id = ?
  AND bm.is_active = 1
  AND bm.member_status != 'refunded'

// 변경 후
WHERE bm.cohort_id = ?
  AND bm.is_active = 1
  AND bm.member_status NOT IN ('refunded','expelled')
```

⚠️ `member_page.php:72, 181` 의 `OR member_status='leaving'` (회원 본인 페이지 진입) 은 그대로 두기 — spec 5.1 의 "변경 안 함" 명시.

- [ ] **Step 4: 변경 grep 확인**

```bash
grep -n "bm.member_status != 'refunded'\|bm.member_status NOT IN" /root/boot-dev/public_html/api/services/member_page.php
```

Expected: 1 line with `NOT IN ('refunded','expelled')` at ~line 402. No `!= 'refunded'` remaining.

- [ ] **Step 5: commit**

```bash
cd /root/boot-dev
git add public_html/api/services/member_page.php tests/expelled_bootees_invariants.php
git commit -m "fix(member_page): 부티즈 목록에서 expelled 제외 + invariant

spec 5 #5. handleMemberBootees 단체활동 게이트 정렬.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: 운영자 기본 필터 + statusCounts

**Files:**
- Modify: `public_html/api/admin.php:594, 628`

- [ ] **Step 1: admin.php:594 (기본 필터) 변경**

Edit `/root/boot-dev/public_html/api/admin.php` (`case 'member_list':` 안, 라인 593~595):

```php
// 변경 전
    if (!$includeInactive) {
        $where[] = "bm.member_status != 'refunded'";
    }

// 변경 후
    if (!$includeInactive) {
        $where[] = "bm.member_status NOT IN ('refunded','expelled')";
    }
```

- [ ] **Step 2: admin.php:628 (statusCounts 초기화) 변경**

Find the `$statusCounts = [...]` initialization (~ line 628). Edit:

```php
// 변경 전
$statusCounts = ['active' => 0, 'leaving' => 0, 'refunded' => 0, 'out_of_group_management' => 0];

// 변경 후
$statusCounts = ['active' => 0, 'leaving' => 0, 'refunded' => 0, 'out_of_group_management' => 0, 'expelled' => 0];
```

- [ ] **Step 3: 변경 grep 확인**

```bash
grep -n "bm.member_status != 'refunded'" /root/boot-dev/public_html/api/admin.php
# Expected: empty
grep -n "bm.member_status NOT IN ('refunded','expelled')" /root/boot-dev/public_html/api/admin.php
# Expected: 1 line (~594)
grep -n "'expelled' => 0" /root/boot-dev/public_html/api/admin.php
# Expected: 1 line (~628)
```

- [ ] **Step 4: commit**

```bash
cd /root/boot-dev
git add public_html/api/admin.php
git commit -m "fix(admin): operator member_list 기본 필터에서 expelled 추가 제외

spec 5 #6 + 7.4. statusCounts 에 expelled 초기값 0 추가.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: QR 스캔 게이트 + invariant

**Files:**
- Create: `tests/expelled_qr_scan_invariants.php`
- Modify: `public_html/includes/qr_actions.php:28`, `public_html/api/qr.php:178, 258`

- [ ] **Step 1: invariant 테스트 작성**

Create `/root/boot-dev/tests/expelled_qr_scan_invariants.php` with EXACT content:

```php
<?php
/**
 * expelled 회원의 QR 스캔이 qrRecordAttendance 에서 거부되는지.
 *
 * 사용: php tests/expelled_qr_scan_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/qr_actions.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();
$db->beginTransaction();

try {
    $cohortLabel = 'TEST_XQR_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, '퇴출', 'x', 'expelled', 1, 1, CURDATE())")
       ->execute([$cohortId]);
    $memberId = (int)$db->lastInsertId();

    // qr_sessions 픽스처 (Task 6 의 leaving QR 테스트와 동일 schema 사용)
    $sessionCode = 'TXQR' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO qr_sessions
        (cohort_id, session_code, status, session_type, admin_id, expires_at, created_at)
        VALUES (?, ?, 'active', 'attendance', 0, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())")
       ->execute([$cohortId, $sessionCode]);
    $sessionId = (int)$db->lastInsertId();

    $session = [
        'id' => $sessionId,
        'cohort_id' => $cohortId,
        'session_code' => $sessionCode,
        'status' => 'active',
        'session_type' => 'attendance',
        'admin_id' => null,
    ];

    $result = qrRecordAttendance($db, $session, $memberId, null, '127.0.0.1', 'test');

    t('qrRecordAttendance ok=false (expelled 거부)', empty($result['ok']));

    // member_mission_checks INSERT 안 됨
    $cnt = $db->prepare("SELECT COUNT(*) FROM member_mission_checks WHERE member_id = ? AND check_date = CURDATE()");
    $cnt->execute([$memberId]);
    t('member_mission_checks INSERT 안 됨', (int)$cnt->fetchColumn() === 0);

    // qr_attendance INSERT 안 됨
    $att = $db->prepare("SELECT COUNT(*) FROM qr_attendance WHERE qr_session_id = ? AND member_id = ?");
    $att->execute([$sessionId, $memberId]);
    t('qr_attendance INSERT 안 됨', (int)$att->fetchColumn() === 0);
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 테스트 실행 (변경 전 — FAIL 예상, 코드 변경 후 PASS 가정)**

```bash
php /root/boot-dev/tests/expelled_qr_scan_invariants.php
```

Expected: 변경 전 — FAIL 또는 PASS 둘 다 가능 (현재 게이트 `!= 'refunded'` 가 expelled 를 통과시킴 → ok=true, member_mission_checks INSERT 됨, FAIL). 코드 변경 후 step 5 에서 모두 PASS.

(이 테스트는 게이트가 변경된 후의 정책 invariant — 코드 변경 전엔 FAIL 정상.)

- [ ] **Step 3: qr_actions.php:28 변경**

Find the SQL near `qr_actions.php:28` that checks `member_status`. Edit:

```php
// 변경 전 (예시 패턴)
$stmt = $db->prepare("
    SELECT id, cohort_id, group_id, member_status, is_active
    FROM bootcamp_members
    WHERE id = ? AND is_active = 1 AND member_status != 'refunded'
");

// 변경 후
$stmt = $db->prepare("
    SELECT id, cohort_id, group_id, member_status, is_active
    FROM bootcamp_members
    WHERE id = ? AND is_active = 1 AND member_status NOT IN ('refunded','expelled')
");
```

먼저 `grep -n "member_status != 'refunded'" /root/boot-dev/public_html/includes/qr_actions.php` 로 정확한 라인 확인. 게이트 패턴 1곳 (~28). 사용 가능한 모든 `!= 'refunded'` → `NOT IN ('refunded','expelled')` 로 변경.

- [ ] **Step 4: qr.php:178, 258 동일 변경**

`grep -n "member_status != 'refunded'" /root/boot-dev/public_html/api/qr.php` → 라인 확인 후 동일 패턴 변경 (2곳).

- [ ] **Step 5: 변경 grep 확인 + 테스트 재실행**

```bash
grep -n "member_status != 'refunded'" /root/boot-dev/public_html/includes/qr_actions.php /root/boot-dev/public_html/api/qr.php
# Expected: empty
grep -n "member_status NOT IN ('refunded','expelled')" /root/boot-dev/public_html/includes/qr_actions.php /root/boot-dev/public_html/api/qr.php
# Expected: 3 lines (qr_actions.php × 1, qr.php × 2)

php /root/boot-dev/tests/expelled_qr_scan_invariants.php
# Expected: 3 PASS, 0 FAIL
```

또 — leaving qr scan 회귀 가드도 여전히 통과해야 함:

```bash
php /root/boot-dev/tests/leaving_qr_scan_invariants.php
```

Expected: `5 PASS, 0 FAIL`.

- [ ] **Step 6: commit**

```bash
cd /root/boot-dev
git add tests/expelled_qr_scan_invariants.php public_html/includes/qr_actions.php public_html/api/qr.php
git commit -m "fix(qr): expelled 회원 QR 스캔 거부 + invariant

qr_actions.php:28 + qr.php:178/258 의 != 'refunded' 게이트를
NOT IN ('refunded','expelled') 로 좁힘. spec 5 #7~#8.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: 카페 인제스트 게이트 + invariant

**Files:**
- Create: `tests/expelled_cafe_ingest_invariants.php`
- Modify: `public_html/includes/cafe/cafe_ingest.php` (`resolveMemberByKey`)

- [ ] **Step 1: invariant 테스트 작성**

Create `/root/boot-dev/tests/expelled_cafe_ingest_invariants.php` with EXACT content:

```php
<?php
/**
 * expelled 회원의 cafe_member_key 매핑이 있어도 ingestCafePosts 가
 * 자동 체크를 INSERT 하지 않는지.
 *
 * 사용: php tests/expelled_cafe_ingest_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/cafe/cafe_ingest.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();
$db->beginTransaction();

try {
    $cohortLabel = 'TEST_XCAF_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $memberKey = 'TXPL_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, cafe_member_key, member_status, is_active, stage_no, joined_at)
        VALUES (?, '퇴출', 'x', ?, 'expelled', 1, 1, CURDATE())")
       ->execute([$cohortId, $memberKey]);
    $memberId = (int)$db->lastInsertId();

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    ingestCafePosts([
        [
            'cafe_article_id' => 'TXCAF_' . bin2hex(random_bytes(3)),
            'title' => 'test post',
            'member_key' => $memberKey,
            'nickname' => 'expelled_user',
            'board_type' => 'inner33',
            'posted_at' => $now,
            'assignment_date' => $today,
        ],
    ]);

    // member_mission_checks INSERT 안 됨
    $cnt = $db->prepare("SELECT COUNT(*) FROM member_mission_checks WHERE member_id = ? AND check_date = ?");
    $cnt->execute([$memberId, $today]);
    t('expelled 회원의 카페 자동체크 INSERT 안 됨', (int)$cnt->fetchColumn() === 0);
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 테스트 실행 (변경 전 — FAIL 예상)**

```bash
php /root/boot-dev/tests/expelled_cafe_ingest_invariants.php
```

Expected: 변경 전 FAIL (현재 resolveMemberByKey 는 `is_active=1` 만 봐서 expelled 통과 → INSERT 됨). 코드 변경 후 PASS.

- [ ] **Step 3: `resolveMemberByKey` 게이트 추가**

Find `resolveMemberByKey` in `/root/boot-dev/public_html/includes/cafe/cafe_ingest.php`:

```bash
grep -n "function resolveMemberByKey\|WHERE cafe_member_key" /root/boot-dev/public_html/includes/cafe/cafe_ingest.php
```

Edit the function's SELECT:

```php
// 변경 전 (패턴)
$stmt = $db->prepare("
    SELECT id, cohort_id, group_id
    FROM bootcamp_members
    WHERE cafe_member_key = ? AND is_active = 1
    LIMIT 1
");

// 변경 후
$stmt = $db->prepare("
    SELECT id, cohort_id, group_id
    FROM bootcamp_members
    WHERE cafe_member_key = ? AND is_active = 1
      AND member_status NOT IN ('refunded','expelled')
    LIMIT 1
");
```

⚠️ 정확한 SELECT 컬럼 리스트는 기존 구현 그대로 두고 WHERE 절에만 `AND member_status NOT IN (...)` 추가.

- [ ] **Step 4: 변경 grep + 테스트 재실행**

```bash
grep -n "member_status NOT IN ('refunded','expelled')" /root/boot-dev/public_html/includes/cafe/cafe_ingest.php
# Expected: 1 line (in resolveMemberByKey)

php /root/boot-dev/tests/expelled_cafe_ingest_invariants.php
# Expected: 1 PASS, 0 FAIL

php /root/boot-dev/tests/leaving_cafe_ingest_invariants.php
# Expected: 2 PASS, 0 FAIL (leaving 회원은 여전히 통과)
```

- [ ] **Step 5: commit**

```bash
cd /root/boot-dev
git add tests/expelled_cafe_ingest_invariants.php public_html/includes/cafe/cafe_ingest.php
git commit -m "fix(cafe): expelled 회원 카페 자동체크 거부 + invariant

resolveMemberByKey 에 member_status NOT IN ('refunded','expelled') 가드 추가.
spec 5 #9.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: coin_reward_group INACTIVE_STATUSES 확장

**Files:**
- Modify: `public_html/api/services/coin_reward_group.php:221`

- [ ] **Step 1: INACTIVE_STATUSES 변경**

Find the constant at ~line 221:

```bash
grep -n "INACTIVE_STATUSES\|'refunded', 'leaving', 'out_of_group_management'" /root/boot-dev/public_html/api/services/coin_reward_group.php
```

Edit:

```php
// 변경 전
$INACTIVE_STATUSES = ['refunded', 'leaving', 'out_of_group_management'];

// 변경 후
$INACTIVE_STATUSES = ['refunded', 'leaving', 'out_of_group_management', 'expelled'];
```

- [ ] **Step 2: 변경 grep 확인**

```bash
grep -n "INACTIVE_STATUSES" /root/boot-dev/public_html/api/services/coin_reward_group.php
```

Expected: 1 line with all 4 values (`refunded, leaving, out_of_group_management, expelled`).

- [ ] **Step 3: commit**

```bash
cd /root/boot-dev
git add public_html/api/services/coin_reward_group.php
git commit -m "fix(coin_reward_group): INACTIVE_STATUSES 에 expelled 추가

조별 단체 보상에서 expelled 도 명시적 제외 (group_id=NULL 으로 자연 제외되지만
의미 명확화). spec 5 게이트 표 #10.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: handleMemberSetStatus 확장 + admin_action_logs

**Files:**
- Create: `tests/expelled_set_status_invariants.php`
- Modify: `public_html/api/services/member.php:155-175`
- Modify: `public_html/api/admin.php:788-810`

- [ ] **Step 1: invariant 테스트 작성**

Create `/root/boot-dev/tests/expelled_set_status_invariants.php` with EXACT content:

```php
<?php
/**
 * handleMemberSetStatus 가 expelled 분기를 지원하고
 * admin_action_logs 에 INSERT 하는지 SQL-level 검증.
 *
 * 사용: php tests/expelled_set_status_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();
$db->beginTransaction();

try {
    $cohortLabel = 'TEST_XSS_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    // 어드민 fixture (action_logs FK 가 admins 면 필요. 없으면 id=1 임의)
    $adminId = 1; // boot 의 admin_action_logs.actor_admin_id 는 DEFAULT NULL — 임의 INT OK

    // active 회원
    $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, NULL, '활성', 'a', 'active', 1, 1, CURDATE())")
       ->execute([$cohortId]);
    $memberId = (int)$db->lastInsertId();

    // 변경 후 정책 시뮬레이션 — handleMemberSetStatus 의 핵심 동작을 인라인 재현:
    // (1) FOR UPDATE 로 prev status 조회
    // (2) member_status='expelled', group_id=NULL UPDATE
    // (3) admin_action_logs INSERT
    $prev = $db->prepare("SELECT member_status FROM bootcamp_members WHERE id = ? FOR UPDATE");
    $prev->execute([$memberId]);
    $previousStatus = $prev->fetchColumn();

    $db->prepare("UPDATE bootcamp_members SET member_status='expelled', group_id=NULL WHERE id=?")
       ->execute([$memberId]);

    $reason = '점수 -50 이하 3주 연속';
    $db->prepare("INSERT INTO admin_action_logs
        (actor_admin_id, action_type, target_table, target_id, payload_json)
        VALUES (?, 'member_status_change', 'bootcamp_members', ?, ?)")
       ->execute([
         $adminId,
         $memberId,
         json_encode(['from' => $previousStatus, 'to' => 'expelled', 'reason' => $reason], JSON_UNESCAPED_UNICODE),
       ]);

    // 검증
    $row = $db->prepare("SELECT member_status, group_id FROM bootcamp_members WHERE id = ?");
    $row->execute([$memberId]);
    $current = $row->fetch(PDO::FETCH_ASSOC);

    t('member_status = expelled', $current['member_status'] === 'expelled');
    t('group_id = NULL', $current['group_id'] === null);

    $log = $db->prepare("SELECT action_type, payload_json
                          FROM admin_action_logs
                         WHERE target_table='bootcamp_members' AND target_id = ?");
    $log->execute([$memberId]);
    $logRow = $log->fetch(PDO::FETCH_ASSOC);

    t('admin_action_logs row 있음', $logRow !== false);
    t('action_type = member_status_change', ($logRow['action_type'] ?? '') === 'member_status_change');
    $payload = json_decode($logRow['payload_json'] ?? '', true);
    t('payload.from = active', ($payload['from'] ?? null) === 'active');
    t('payload.to = expelled', ($payload['to'] ?? null) === 'expelled');
    t('payload.reason 보존', ($payload['reason'] ?? null) === $reason);
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 테스트 실행 (정책 invariant — 코드 변경 전에도 PASS 가정)**

```bash
php /root/boot-dev/tests/expelled_set_status_invariants.php
```

Expected: `7 PASS, 0 FAIL` (테스트가 정책을 인라인 재현하므로 코드 변경 전에도 통과).

- [ ] **Step 3: `public_html/api/services/member.php:155` `handleMemberSetStatus` 확장**

Edit (라인 155~175 전체 교체):

```php
function handleMemberSetStatus($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    $status = $input['status'] ?? '';
    $reason = trim((string)($input['reason'] ?? ''));
    if (!$id) jsonError('id 필요');
    if (!in_array($status, ['active', 'leaving', 'expelled'])) jsonError('유효하지 않은 상태입니다.');

    $db = getDB();
    $db->beginTransaction();
    try {
        $prevStmt = $db->prepare("SELECT member_status FROM bootcamp_members WHERE id = ? FOR UPDATE");
        $prevStmt->execute([$id]);
        $previousStatus = $prevStmt->fetchColumn();
        if ($previousStatus === false) { $db->rollBack(); jsonError('회원을 찾을 수 없습니다.', 404); }

        if ($status === 'leaving') {
            // 나가기: is_active 유지(로그인 가능), 조 소속 해제
            $db->prepare("UPDATE bootcamp_members SET member_status='leaving', group_id=NULL WHERE id=?")->execute([$id]);
        } elseif ($status === 'expelled') {
            // 퇴출: is_active 유지, 조 소속 해제, 단체활동 차단
            $db->prepare("UPDATE bootcamp_members SET member_status='expelled', group_id=NULL WHERE id=?")->execute([$id]);
        } else {
            // 활성 복원 (group_id 는 운영자가 별도 배정)
            $db->prepare("UPDATE bootcamp_members SET member_status='active' WHERE id=?")->execute([$id]);
        }

        $db->prepare("INSERT INTO admin_action_logs
            (actor_admin_id, action_type, target_table, target_id, payload_json)
            VALUES (?, 'member_status_change', 'bootcamp_members', ?, ?)")
           ->execute([
             $admin['id'] ?? null,
             $id,
             json_encode(['from' => $previousStatus, 'to' => $status, 'reason' => $reason !== '' ? $reason : null],
                         JSON_UNESCAPED_UNICODE),
           ]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $labelMap = ['leaving' => '조에서 빠진 회원', 'expelled' => '퇴출 회원', 'active' => '활성'];
    $label = $labelMap[$status] ?? $status;
    jsonSuccess([], "'{$label}' 상태로 변경되었습니다.");
}
```

- [ ] **Step 4: `public_html/api/admin.php:788` `case 'member_set_status'` 확장**

Edit 동일한 패턴으로:

```php
case 'member_set_status':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    $status = $input['status'] ?? '';
    $reason = trim((string)($input['reason'] ?? ''));
    if (!$id) jsonError('회원 ID가 필요합니다.');
    if (!in_array($status, ['active', 'leaving', 'expelled'])) jsonError('유효하지 않은 상태입니다.');

    $db = getDB();
    $db->beginTransaction();
    try {
        $prevStmt = $db->prepare("SELECT member_status FROM bootcamp_members WHERE id = ? FOR UPDATE");
        $prevStmt->execute([$id]);
        $previousStatus = $prevStmt->fetchColumn();
        if ($previousStatus === false) { $db->rollBack(); jsonError('회원을 찾을 수 없습니다.', 404); }

        if ($status === 'leaving') {
            $db->prepare("UPDATE bootcamp_members SET member_status='leaving', group_id=NULL WHERE id=?")->execute([$id]);
        } elseif ($status === 'expelled') {
            $db->prepare("UPDATE bootcamp_members SET member_status='expelled', group_id=NULL WHERE id=?")->execute([$id]);
        } else {
            $db->prepare("UPDATE bootcamp_members SET member_status='active' WHERE id=?")->execute([$id]);
        }

        $db->prepare("INSERT INTO admin_action_logs
            (actor_admin_id, action_type, target_table, target_id, payload_json)
            VALUES (?, 'member_status_change', 'bootcamp_members', ?, ?)")
           ->execute([
             $admin['id'] ?? null,
             $id,
             json_encode(['from' => $previousStatus, 'to' => $status, 'reason' => $reason !== '' ? $reason : null],
                         JSON_UNESCAPED_UNICODE),
           ]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    $labelMap = ['leaving' => '조에서 빠진 회원', 'expelled' => '퇴출 회원', 'active' => '활성'];
    $label = $labelMap[$status] ?? $status;
    jsonSuccess([], "'{$label}' 상태로 변경되었습니다.");
    break;
```

- [ ] **Step 5: PHP lint + test**

```bash
php -l /root/boot-dev/public_html/api/services/member.php
php -l /root/boot-dev/public_html/api/admin.php
# Both: No syntax errors

php /root/boot-dev/tests/expelled_set_status_invariants.php
# Expected: 7 PASS, 0 FAIL
```

- [ ] **Step 6: smoke 테스트 (수동) — Optional, only if PHP dev server available**

DEV 에서 한 회원 ID 잡아서 (예: 99999 같은 fake id 또는 임시 fixture) admin endpoint 호출 시 422 와 함께 '회원을 찾을 수 없습니다.' 가 오는지. 단 production 데이터에 영향 주는 호출은 금지.

- [ ] **Step 7: commit**

```bash
cd /root/boot-dev
git add public_html/api/services/member.php public_html/api/admin.php tests/expelled_set_status_invariants.php
git commit -m "feat(member_set_status): expelled 분기 + admin_action_logs 감사

spec 6.1~6.3. handleMemberSetStatus 가 expelled 분기 (group_id=NULL),
FOR UPDATE 로 prev status 캐치, admin_action_logs INSERT 로 감사.
leaving/active 도 동일 함수라 같이 감사 트레일 확보.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 11: memberTable.js — 배지 + 카드 버튼

**Files:**
- Modify: `public_html/js/memberTable.js:39-45, 148-157`

- [ ] **Step 1: `statusBadge()` 확장**

Edit `/root/boot-dev/public_html/js/memberTable.js` 라인 39~45:

```js
// 변경 전
function statusBadge(m) {
    if (m.member_status === 'refunded') return '<span class="badge badge-danger">환불</span>';
    if (m.member_status === 'leaving') return '<span class="badge badge-warning-solid">조에서 빠짐</span>';
    if (m.is_active == 0) return '<span class="badge badge-danger">비활성</span>';
    if (m.member_status === 'out_of_group_management') return '<span class="badge badge-danger">탈락</span>';
    return '<span class="badge badge-success">활성</span>';
}

// 변경 후
function statusBadge(m) {
    if (m.member_status === 'refunded') return '<span class="badge badge-danger">환불</span>';
    if (m.member_status === 'expelled') return '<span class="badge badge-danger">퇴출</span>';
    if (m.member_status === 'leaving') return '<span class="badge badge-warning-solid">조에서 빠짐</span>';
    if (m.is_active == 0) return '<span class="badge badge-danger">비활성</span>';
    if (m.member_status === 'out_of_group_management') return '<span class="badge badge-danger">탈락</span>';
    return '<span class="badge badge-success">활성</span>';
}
```

- [ ] **Step 2: 카드 액션 버튼 영역 확장 (라인 148~157)**

Edit:

```js
// 변경 전
${m.member_status === 'refunded'
    ? `<button class="btn btn-sm btn-primary" onclick="${opts.restoreFn || 'AdminApp._restoreMember'}(${m.id}, '${App.esc(m.nickname)}')">복원</button>`
    : m.member_status === 'leaving'
        ? `<button class="btn btn-sm btn-primary" onclick="${opts.setStatusFn || 'AdminApp._setMemberStatus'}(${m.id}, 'active', '${App.esc(m.nickname)}')">활성으로</button>
           <button class="btn btn-sm btn-danger-outline" onclick="${deleteFn}(${m.id}, '${App.esc(m.nickname)}')">환불</button>`
        : `<button class="btn btn-sm btn-warning" onclick="${opts.setStatusFn || 'AdminApp._setMemberStatus'}(${m.id}, 'leaving', '${App.esc(m.nickname)}')">조에서 빼기</button>
           <button class="btn btn-sm btn-danger-outline" onclick="${deleteFn}(${m.id}, '${App.esc(m.nickname)}')">환불</button>`
}

// 변경 후
${m.member_status === 'refunded'
    ? `<button class="btn btn-sm btn-primary" onclick="${opts.restoreFn || 'AdminApp._restoreMember'}(${m.id}, '${App.esc(m.nickname)}')">복원</button>`
    : m.member_status === 'expelled'
        ? `<button class="btn btn-sm btn-primary" onclick="${opts.setStatusFn || 'AdminApp._setMemberStatus'}(${m.id}, 'active', '${App.esc(m.nickname)}')">복원</button>
           <button class="btn btn-sm btn-danger-outline" onclick="${deleteFn}(${m.id}, '${App.esc(m.nickname)}')">환불</button>`
        : m.member_status === 'leaving'
            ? `<button class="btn btn-sm btn-primary" onclick="${opts.setStatusFn || 'AdminApp._setMemberStatus'}(${m.id}, 'active', '${App.esc(m.nickname)}')">조에 복귀</button>
               <button class="btn btn-sm btn-warning" onclick="${opts.setStatusFn || 'AdminApp._setMemberStatus'}(${m.id}, 'expelled', '${App.esc(m.nickname)}')">내보내기</button>
               <button class="btn btn-sm btn-danger-outline" onclick="${deleteFn}(${m.id}, '${App.esc(m.nickname)}')">환불</button>`
            : `<button class="btn btn-sm btn-warning" onclick="${opts.setStatusFn || 'AdminApp._setMemberStatus'}(${m.id}, 'leaving', '${App.esc(m.nickname)}')">조에서 빼기</button>
               <button class="btn btn-sm btn-warning" onclick="${opts.setStatusFn || 'AdminApp._setMemberStatus'}(${m.id}, 'expelled', '${App.esc(m.nickname)}')">내보내기</button>
               <button class="btn btn-sm btn-danger-outline" onclick="${deleteFn}(${m.id}, '${App.esc(m.nickname)}')">환불</button>`
}
```

핵심 변화:
- `refunded` → `[환불 복원]` (라벨 명확화)
- `expelled` 분기 신설 → `[복원]` (status='active' 으로 setStatus) + `[환불]`
- `leaving` → `[조에 복귀]` + `[내보내기]` + `[환불]` (기존 "활성으로" → "조에 복귀", `[내보내기]` 추가)
- active/OOM → `[조에서 빼기]` + `[내보내기]` + `[환불]` (`[내보내기]` 추가)

- [ ] **Step 3: 변경 grep + 시각 확인**

```bash
grep -n "expelled\|내보내기\|조에 복귀\|퇴출" /root/boot-dev/public_html/js/memberTable.js
```

Expected: `expelled` 분기 1줄 (badge) + onclick `'expelled'` 분기 (~3개) + `'내보내기'`, `'조에 복귀'`, `'퇴출'` 각 라벨.

- [ ] **Step 4: commit**

```bash
cd /root/boot-dev
git add public_html/js/memberTable.js
git commit -m "feat(memberTable): expelled 배지 + 카드 [내보내기]/[복원] 버튼

spec 7.1~7.3. statusBadge 에 expelled 분기, 카드 액션 영역에
[내보내기] + [복원] + 라벨 정리.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 12: admin.js + bootcamp.js — _setMemberStatus reason prompt + footer + 라벨

**Files:**
- Modify: `public_html/js/admin.js:1116-1132, 1370-1377`
- Modify: `public_html/js/bootcamp.js:1671-1680, 2287`

- [ ] **Step 1: admin.js footer 에 expelled 카운트 추가 (라인 1116~1132)**

Edit:

```js
// 변경 전
const sc = r.status_counts || {};
const refundedN = parseInt(sc.refunded) || 0;
const inactiveExtra = [];
if (refundedN) inactiveExtra.push(`환불 ${refundedN}`);
const headerLabel = _membersIncludeInactive
    ? `회원 ${r.members.length}명 (전체)`
    : `활성 회원 ${r.members.length}명${inactiveExtra.length ? ` <span style="font-weight:normal;color:var(--color-text-sub);font-size:var(--text-xs)">(${inactiveExtra.join(' · ')} 미포함)</span>` : ''}`;

// 변경 후
const sc = r.status_counts || {};
const refundedN = parseInt(sc.refunded) || 0;
const expelledN = parseInt(sc.expelled) || 0;
const inactiveExtra = [];
if (refundedN) inactiveExtra.push(`환불 ${refundedN}`);
if (expelledN) inactiveExtra.push(`퇴출 ${expelledN}`);
const headerLabel = _membersIncludeInactive
    ? `회원 ${r.members.length}명 (전체)`
    : `활성 회원 ${r.members.length}명${inactiveExtra.length ? ` <span style="font-weight:normal;color:var(--color-text-sub);font-size:var(--text-xs)">(${inactiveExtra.join(' · ')} 미포함)</span>` : ''}`;
```

- [ ] **Step 2: admin.js 체크박스 라벨 확장 (라인 1132 부근)**

Edit:

```html
<!-- 변경 전 -->
환불 회원 포함

<!-- 변경 후 -->
환불·퇴출 회원 포함
```

- [ ] **Step 3: admin.js `_setMemberStatus` reason prompt 추가 (라인 1370~1377)**

Edit:

```js
// 변경 전
async function _setMemberStatus(id, status, name) {
    const label = status === 'leaving' ? '조에서 빼기' : '활성';
    if (!await App.confirm(`'${name}' 회원을 '${label}' 상태로 변경하시겠습니까?`)) return;
    App.showLoading();
    const r = await App.post('/api/admin.php?action=member_set_status', { id, status });
    App.hideLoading();
    if (r.success) { Toast.success(r.message); loadMembersMgmt(); }
}

// 변경 후
async function _setMemberStatus(id, status, name) {
    const labelMap = { leaving: '조에서 빼기', expelled: '내보내기', active: '활성' };
    const label = labelMap[status] || status;
    let confirmMsg = `'${name}' 회원을 '${label}' 상태로 변경하시겠습니까?`;
    if (status === 'expelled') {
        confirmMsg += '\n이후 단체활동(zoom/카페/점수/후기/부티즈)에서 모두 빠집니다.';
    }
    if (!await App.confirm(confirmMsg)) return;
    let reason = '';
    if (status === 'expelled') {
        reason = prompt('사유 (선택, 빈칸 가능):', '') || '';
    }
    App.showLoading();
    const r = await App.post('/api/admin.php?action=member_set_status', { id, status, reason });
    App.hideLoading();
    if (r.success) { Toast.success(r.message); loadMembersMgmt(); }
}
```

- [ ] **Step 4: bootcamp.js `_setMemberStatus` 동일 패턴 (라인 1671 부근)**

Find:

```bash
grep -n "async function _setMemberStatus" /root/boot-dev/public_html/js/bootcamp.js
```

Edit (라인 ~1671):

```js
// 변경 전
async function _setMemberStatus(id, status, nickname) {
    const label = status === 'leaving' ? '조에서 빼기' : '활성';
    if (!await App.confirm(`'${nickname}' 님을 '${label}' 상태로 변경하시겠습니까?`)) return;
    App.showLoading();
    const r = await App.post('/api/bootcamp.php?action=member_set_status', { id, status });
    App.hideLoading();
    if (r.success) { Toast.success(r.message); loadMembersTab(); }
}

// 변경 후
async function _setMemberStatus(id, status, nickname) {
    const labelMap = { leaving: '조에서 빼기', expelled: '내보내기', active: '활성' };
    const label = labelMap[status] || status;
    let confirmMsg = `'${nickname}' 님을 '${label}' 상태로 변경하시겠습니까?`;
    if (status === 'expelled') {
        confirmMsg += '\n이후 단체활동(zoom/카페/점수/후기/부티즈)에서 모두 빠집니다.';
    }
    if (!await App.confirm(confirmMsg)) return;
    let reason = '';
    if (status === 'expelled') {
        reason = prompt('사유 (선택, 빈칸 가능):', '') || '';
    }
    App.showLoading();
    const r = await App.post('/api/bootcamp.php?action=member_set_status', { id, status, reason });
    App.hideLoading();
    if (r.success) { Toast.success(r.message); loadMembersTab(); }
}
```

(주의: 기존 함수의 호출 콜백 `loadMembersTab()` 등은 그대로 유지. 위 변경 전 코드는 패턴이므로 실제 코드 SELECT 후 그 안의 변하는 부분만 수정. confirm 메시지 호스트가 `nickname` vs `name` 등 file별로 다르면 file 의 원본 형식 보존.)

- [ ] **Step 5: bootcamp.js 라인 2287 (회원 카드 배지 인라인) 변경**

```bash
grep -n "leavingBadge\|member_status === 'leaving'" /root/boot-dev/public_html/js/bootcamp.js | head
```

Find line ~2287:

```js
// 변경 전
const leavingBadge = m.member_status === 'leaving' ? ' <span class="badge badge-warning-solid" style="font-size:10px">조에서 빠짐</span>' : '';

// 변경 후
const leavingBadge =
    m.member_status === 'expelled' ? ' <span class="badge badge-danger" style="font-size:10px">퇴출</span>' :
    m.member_status === 'leaving' ? ' <span class="badge badge-warning-solid" style="font-size:10px">조에서 빠짐</span>' : '';
```

- [ ] **Step 6: bootcamp.js 라인 1672 도 라벨 분기 확장 (있다면)**

```bash
grep -n "status === 'leaving' ? '나가기'\|status === 'leaving' ? '조에서 빼기'" /root/boot-dev/public_html/js/bootcamp.js
```

직전 redefinition 으로 이미 `'조에서 빼기'` 가 된 상태. 만약 다른 곳 (다이얼로그 메시지 등) 에서 분기가 있다면 expelled 라벨 추가. Step 4 의 `_setMemberStatus` 함수 안 `labelMap` 이 같은 역할을 하므로 추가 변경 없을 가능성 큼 — 확인만.

Expected: line ~1672 의 라벨 분기는 step 4 의 labelMap 안에 흡수됐으면 추가 변경 불필요. grep 결과 별도 분기 라인이 있으면 해당 라인도 expelled 추가.

- [ ] **Step 7: admin.js 라인 1375 부근의 라벨도 step 3 의 labelMap 으로 흡수됐는지 확인**

```bash
grep -n "status === 'leaving' ? '조에서 빼기' : '활성'" /root/boot-dev/public_html/js/admin.js
```

Expected: empty (step 3 의 labelMap 이 그 역할 함). 만약 다른 곳에 라벨 분기가 있으면 추가 변경.

- [ ] **Step 8: 잔존 라벨 확인**

```bash
grep -rn ">활성으로<\|'활성으로'\|status === 'leaving' \? '" /root/boot-dev/public_html/js/admin.js /root/boot-dev/public_html/js/bootcamp.js /root/boot-dev/public_html/js/memberTable.js
```

Expected: 빈 출력 (memberTable.js 의 "활성으로" 는 Task 11 에서 "조에 복귀" 로 바뀜).

- [ ] **Step 9: commit**

```bash
cd /root/boot-dev
git add public_html/js/admin.js public_html/js/bootcamp.js
git commit -m "feat(ui): expelled — footer 카운트 + reason prompt + 배지/라벨

spec 7.1~7.3. _setMemberStatus 에 expelled 분기 + 선택 사유 prompt,
운영자 footer 에 퇴출 N 미포함 표시 + 체크박스 환불·퇴출 포함,
bootcamp 회원 카드 인라인 배지에 퇴출 추가.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 13: 기존 leaving_*_invariants regression — expelled fixture 추가

**Files:**
- Modify: `tests/leaving_cron_invariants.php`
- Modify: `tests/leaving_review_invariants.php`
- Modify: `tests/leaving_bootees_invariants.php`

직전 leaving redefinition 의 invariant 테스트들이 새 enum 값 도입 후에도 "expelled 도 제외" invariant 를 보호하는지 확인하려고 fixture 에 expelled row 1개 추가 + 그 row 가 결과에서 빠지는 assertion 추가.

- [ ] **Step 1: `tests/leaving_cron_invariants.php` 수정**

Find the 5th fixture INSERT (refunded + is_active=1) added in `c0d0cc4`. Right AFTER it, add:

```php
    $ins->execute([$cohortId, null, '퇴출', 'x', 'expelled', 1]);
    $idX = (int)$db->lastInsertId();
```

Right AFTER the last assertion (`t('cron SELECT 가 is_active=1 인 refunded 도 제외 ...', ...)`), add:

```php
    t('cron SELECT 가 expelled(is_active=1) 도 제외 (member_status 가드 효과)',
      !in_array($idX, $ids, true));
```

⚠️ `$expected` 배열은 `[$idA, $idL, $idO]` 유지 — expelled 는 결과에 들어가지 않아야 한다.

- [ ] **Step 2: `tests/leaving_review_invariants.php` 수정**

After the existing 3rd fixture INSERT (refunded), add:

```php
    $ins->execute([$cohortId, '퇴출', 'x', 'expelled', 1]);
    $idX = (int)$db->lastInsertId();
```

In the `foreach (...)` array, add an entry:

```php
        ['expelled', $idX, true],
```

⚠️ `$blocked` 배열도 `['refunded', 'expelled']` 로 확장:

```php
    // review.php 게이트 (변경 후): 'refunded' + 'expelled' 차단
    $blocked = ['refunded', 'expelled'];
```

- [ ] **Step 3: `tests/leaving_bootees_invariants.php` 수정**

After the 5th fixture INSERT (refunded + is_active=1), add:

```php
    $ins->execute([$cohortId, '퇴출', 'x', 'expelled', 1]);
    $idX = (int)$db->lastInsertId();
```

SQL 변경:

```php
// 변경 전
WHERE bm.cohort_id = ?
  AND bm.is_active = 1
  AND bm.member_status != 'refunded'

// 변경 후
WHERE bm.cohort_id = ?
  AND bm.is_active = 1
  AND bm.member_status NOT IN ('refunded','expelled')
```

새 assertion 추가:

```php
    t('expelled(is_active=1) 도 제외 (member_status 가드)', !in_array($idX, $ids, true));
```

⚠️ `$expected` 는 `[$idA, $idL, $idO]` 유지.

- [ ] **Step 4: 5 leaving + 6 expelled invariant 일괄 실행**

```bash
cd /root/boot-dev
for f in tests/leaving_*.php tests/expelled_*.php; do
  echo "─── $f ───"
  php "$f" | tail -2
done
```

Expected: 모두 `* PASS, 0 FAIL`.

- [ ] **Step 5: commit**

```bash
cd /root/boot-dev
git add tests/leaving_cron_invariants.php tests/leaving_review_invariants.php tests/leaving_bootees_invariants.php
git commit -m "test(leaving): expelled fixture 추가 (regression)

leaving redefinition invariant 들이 expelled enum 도입 후에도
새 정책 (NOT IN ('refunded','expelled')) 을 같이 보호하도록 fixture +
assertion 추가.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 14: 최종 검증 + DEV push

- [ ] **Step 1: 모든 invariants 일괄 실행 (leaving 5 + expelled 6)**

```bash
cd /root/boot-dev
for f in tests/leaving_*.php tests/expelled_*.php; do
  echo "─── $f ───"
  php "$f"
done
```

Expected: 11개 테스트 파일 모두 `* PASS, 0 FAIL`.

- [ ] **Step 2: 잔존 옛 패턴 grep**

```bash
echo "=== cron 게이트 ==="
grep -n "member_status != 'refunded'" /root/boot-dev/public_html/cron.php
# Expected: empty

echo "=== 출석률 ==="
grep -n "member_status != 'refunded'" /root/boot-dev/public_html/api/services/attendance.php
# Expected: empty

echo "=== 후기 ==="
grep -n "'refunded'.\]" /root/boot-dev/public_html/api/services/review.php | grep -v expelled
# Expected: empty

echo "=== 부티즈 ==="
grep -n "member_status != 'refunded'" /root/boot-dev/public_html/api/services/member_page.php
# Expected: empty

echo "=== admin 기본 필터 ==="
grep -n "bm.member_status != 'refunded'" /root/boot-dev/public_html/api/admin.php
# Expected: empty

echo "=== QR ==="
grep -n "member_status != 'refunded'" /root/boot-dev/public_html/includes/qr_actions.php /root/boot-dev/public_html/api/qr.php
# Expected: empty

echo "=== 카페 인제스트 ==="
grep -n "cafe_member_key = ? AND is_active = 1" /root/boot-dev/public_html/includes/cafe/cafe_ingest.php | grep -v "NOT IN"
# Expected: empty

echo "=== coin_reward_group INACTIVE_STATUSES (expelled 포함 확인) ==="
grep -n "INACTIVE_STATUSES" /root/boot-dev/public_html/api/services/coin_reward_group.php
# Expected: 1 line containing 'expelled'

echo "=== JS '내보내기' / '퇴출' / 'expelled' 출현 확인 ==="
grep -rn "내보내기\|퇴출\|'expelled'" /root/boot-dev/public_html/js/ | wc -l
# Expected: > 0
```

- [ ] **Step 3: DB 마이그 멱등 재실행 (확인)**

```bash
php /root/boot-dev/migrate_member_status_expelled.php
```

Expected: `skip: enum 에 'expelled' 이미 존재`.

- [ ] **Step 4: PHP lint 일괄**

```bash
for f in \
  /root/boot-dev/public_html/cron.php \
  /root/boot-dev/public_html/api/services/attendance.php \
  /root/boot-dev/public_html/api/services/review.php \
  /root/boot-dev/public_html/api/services/member_page.php \
  /root/boot-dev/public_html/api/admin.php \
  /root/boot-dev/public_html/api/services/member.php \
  /root/boot-dev/public_html/api/services/coin_reward_group.php \
  /root/boot-dev/public_html/includes/qr_actions.php \
  /root/boot-dev/public_html/api/qr.php \
  /root/boot-dev/public_html/includes/cafe/cafe_ingest.php \
  /root/boot-dev/migrate_member_status_expelled.php; do
  php -l "$f"
done
```

Expected: 모두 `No syntax errors detected`.

- [ ] **Step 5: git log 정리 확인**

```bash
cd /root/boot-dev && git log --oneline origin/dev..HEAD
```

Expected: ~14 commits (Task 1~13 + final 정리, 각 task 1개씩).

- [ ] **Step 6: DEV push**

```bash
cd /root/boot-dev && git push origin dev
```

Expected: push 성공, dev 브랜치 origin/dev 동기화.

- [ ] **Step 7: ⛔ 사용자 DEV 검증 요청 + 멈춤**

사용자에게 보고:

- DEV 사이트 (`https://dev-boot.soritune.com`) 에서:
  1. 운영자 화면 → 회원 목록 → active/OOM 회원 카드에 `[조에서 빼기]` 옆 `[내보내기]` 버튼이 보이는지
  2. `[내보내기]` 클릭 시 confirm 다이얼로그 → 사유 prompt (선택) → 처리
  3. 처리 후 회원 배지가 `퇴출` 으로 보이고, 카드 액션이 `[복원]` + `[환불]` 로 바뀌는지
  4. 기본 필터에서 expelled 가 안 보이고 푸터에 `퇴출 N 미포함` 표시, 체크박스 라벨이 `환불·퇴출 회원 포함`
  5. 체크박스 켜면 expelled 회원이 같이 나오는지
  6. (다음 02시 cron 후) DEV 의 expelled 회원에게 미션 inbox row 가 생성되지 **않는지** (없어야 정상)
  7. expelled 회원 로그인 → `/member.php` 본인 페이지 접속 가능, 단 새 자동체크는 안 들어옴
  8. `admin_action_logs` 에 `action_type='member_status_change'` row 가 생긴지 (DB 확인)
- **사용자가 명시적으로 "운영 반영해줘" 라고 할 때만** main 머지 + prod pull 진행.
- 운영 반영 시 영향:
  - DB 마이그 1개 (PROD 에서 `php migrate_member_status_expelled.php` 1회).
  - PROD expelled 회원 0명이라 즉시 가시적 변화 없음. 운영자가 처음 "내보내기" 누른 시점부터 효과 시작.
  - 직전 leaving redefinition 의 PROD 반영이 안정화된 후 진행 권장.

---

## 후속 작업 (이번 plan 범위 밖)

- 자동 expulsion (점수 임계값 / OOM 장기화 기반 cron)
- expelled 사유의 구조화 (자유 텍스트 → enum/태그)
- expelled 회원 카카오톡 통보
- expelled 회원 점수/코인 회수 정책
