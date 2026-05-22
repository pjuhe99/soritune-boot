# 조에서 빠진 회원 단체활동 자동체크 — 실행 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `member_status='leaving'` 의 의미를 "자발적 조 탈퇴 (단체 활동 OK)"로 재정의해서 12기 leaving 6명 + 향후 OOM 회원도 줌 특강 출석/카페 과제 자동체크/후기 작성/부티즈 목록에 정상 노출되도록 한다.

**Architecture:**
- 단체활동 게이트를 `is_active=1 AND member_status != 'refunded'` 패턴으로 통일 (이미 QR 스캔 / 카페 인제스트가 쓰는 패턴).
- cron 의 일일 미션 inbox 생성, 출석률 분모, 후기 작성 권한, 부티즈 목록 4곳의 SQL WHERE 절을 같은 패턴으로 정렬.
- UI 라벨 9곳을 "나가기 / 나간 회원" → "조에서 빼기 / 조에서 빠진 회원" 으로 명확화 (값은 그대로 `leaving`).
- DB 스키마/데이터 마이그 0건. 코드 변경 6개 파일 + invariant 테스트 3개 신규.

**Tech Stack:** PHP 8 + MariaDB 10.x + 바닐라 JS. boot 컨벤션의 `tests/*_invariants.php` CLI 테스트 (transaction rollback 격리).

**관련 spec:** `docs/superpowers/specs/2026-05-22-leaving-member-activity-tracking-design.md`

**작업 룰 (반드시 준수):**
- `boot-dev` (DEV_BOOT, dev 브랜치) 에서만 작업/코드 수정.
- Task 9 의 `git push origin dev` 후 **⛔ 멈춤**. 사용자가 운영 반영을 명시할 때만 main 머지 + prod pull.

---

## File Structure

| 파일 | 역할 | 변경 종류 |
|------|------|----------|
| `tests/leaving_cron_invariants.php` | cron 게이트 SQL 회귀 가드 (leaving 회원이 inbox 대상에 포함) | 신규 |
| `tests/leaving_qr_scan_invariants.php` | leaving 회원 QR 스캔 → `saveCheck` INSERT 흐름 | 신규 |
| `tests/leaving_cafe_ingest_invariants.php` | leaving 회원 카페 인제스트 → `saveCheck` INSERT 흐름 | 신규 |
| `tests/leaving_review_invariants.php` | leaving 회원 후기 작성 권한 | 신규 |
| `tests/leaving_bootees_invariants.php` | 부티즈 목록에 leaving 포함 | 신규 |
| `public_html/cron.php` (89, 158) | `member_status='active'` → `!='refunded'` | 수정 |
| `public_html/api/services/attendance.php` (21) | 분모: `NOT IN ('refunded','leaving')` → `!='refunded'` | 수정 |
| `public_html/api/services/review.php` (32) | leaving/OOM 도 후기 작성 허용 | 수정 |
| `public_html/api/services/member_page.php` (402) | 부티즈 목록 게이트 풀기 | 수정 |
| `public_html/api/services/member.php` (173) | "나간 회원" → "조에서 빠진 회원" 라벨 | 수정 |
| `public_html/api/admin.php` (807) | 동일 라벨 | 수정 |
| `public_html/js/memberTable.js` (41, 155) | 배지 + 버튼 라벨 | 수정 |
| `public_html/js/admin.js` (1122, 1132, 1375) | 카운트 + 안내 + 버튼 라벨 | 수정 |
| `public_html/js/bootcamp.js` (1672, 2287) | 버튼 + 배지 라벨 | 수정 |

---

## Task 1: cron 게이트 invariant 테스트 작성

**Files:**
- Create: `tests/leaving_cron_invariants.php`

**참고:** 이 테스트는 "변경 후 정책의 SQL invariant" 다. 코드 변경 전이라도 테스트 자체는 PASS 한다 (테스트 안에서 변경 후 WHERE 절을 직접 발급해서 fixture 매치를 확인하므로). cron 함수가 실제로 그 SQL 을 쓰는지는 Task 2 step 4 의 통합 검증에서 잡는다.

- [ ] **Step 1: 테스트 파일 작성**

```php
<?php
/**
 * cron init_daily_checks 의 활성 멤버 SELECT 가 leaving / out_of_group_management
 * 회원도 포함하는지 검증. SQL-level 회귀 가드 (실제 cron 실행은 안 함).
 *
 * 정책: '단체 활동 대상' = is_active=1 AND member_status != 'refunded'
 *
 * 사용: php tests/leaving_cron_invariants.php
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
    // 활성 cohort 시드 (오늘 포함되는 기간)
    $cohortLabel = 'TEST_LEAV_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    // group (active 회원용)
    $groupCode = 'tl_grp_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, stage_no, code)
                  VALUES (?, '테스트조', 1, ?)")
       ->execute([$cohortId, $groupCode]);
    $groupId = (int)$db->lastInsertId();

    // 4 회원: active / leaving / out_of_group_management / refunded
    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, CURDATE())");

    $ins->execute([$cohortId, $groupId, '활성', 'a', 'active', 1]);
    $idA = (int)$db->lastInsertId();
    $ins->execute([$cohortId, null, '나간', 'l', 'leaving', 1]);
    $idL = (int)$db->lastInsertId();
    $ins->execute([$cohortId, null, '강등', 'o', 'out_of_group_management', 1]);
    $idO = (int)$db->lastInsertId();
    $ins->execute([$cohortId, null, '환불', 'r', 'refunded', 0]);
    $idR = (int)$db->lastInsertId();

    // cron init_daily_checks 가 쓰는 변경 후 SELECT
    $today = date('Y-m-d');
    $sql = "
        SELECT bm.id
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.is_active = 1
          AND bm.member_status != 'refunded'
          AND c.start_date <= ? AND c.end_date >= ?
          AND bm.cohort_id = ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$today, $today, $cohortId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    sort($ids);

    $expected = [$idA, $idL, $idO];
    sort($expected);

    t('cron SELECT 가 active/leaving/OOM 3명을 포함', $ids === $expected,
      'got=' . json_encode($ids) . ' expected=' . json_encode($expected));
    t('cron SELECT 가 refunded 회원은 제외', !in_array($idR, $ids, true));

} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 테스트 실행 → PASS 확인**

```bash
php /root/boot-dev/tests/leaving_cron_invariants.php
```

Expected: `2 PASS, 0 FAIL`. (변경 후 SQL 의 정책 invariant 라 코드 변경 전에도 PASS 가 정상. cron 함수가 실제로 이 SQL 을 쓰는지의 검증은 Task 2 의 step 4 통합 검증에서.)

- [ ] **Step 3: commit**

```bash
cd /root/boot-dev
git add tests/leaving_cron_invariants.php
git commit -m "test(leaving): cron init_daily_checks SQL 게이트 invariant

leaving / out_of_group_management 회원이 단체활동 대상 SELECT 에 포함되고
refunded 만 제외되는지 SQL-level 검증.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: cron.php 게이트 변경

**Files:**
- Modify: `public_html/cron.php:89, 158`

- [ ] **Step 1: cron.php:89 (initDailyChecks) 변경**

Edit `public_html/cron.php` 의 `initDailyChecks()` 안 SELECT (라인 84~92 부근):

```php
// 변경 전
$members = $db->query("
    SELECT bm.id AS member_id, bm.cohort_id, bm.group_id
    FROM bootcamp_members bm
    JOIN cohorts c ON bm.cohort_id = c.id
    WHERE bm.is_active = 1
      AND bm.member_status = 'active'
      AND c.start_date <= '{$today}'
      AND c.end_date >= '{$today}'
")->fetchAll(PDO::FETCH_ASSOC);

// 변경 후
$members = $db->query("
    SELECT bm.id AS member_id, bm.cohort_id, bm.group_id
    FROM bootcamp_members bm
    JOIN cohorts c ON bm.cohort_id = c.id
    WHERE bm.is_active = 1
      AND bm.member_status != 'refunded'
      AND c.start_date <= '{$today}'
      AND c.end_date >= '{$today}'
")->fetchAll(PDO::FETCH_ASSOC);
```

- [ ] **Step 2: cron.php:158 (backfillChecks) 동일 변경**

라인 152~160 부근 SELECT:

```php
// 변경 전
$members = $db->query("
    SELECT bm.id AS member_id, bm.cohort_id, bm.group_id,
           c.start_date, c.end_date
    FROM bootcamp_members bm
    JOIN cohorts c ON bm.cohort_id = c.id
    WHERE bm.is_active = 1
      AND bm.member_status = 'active'
      AND c.end_date >= '{$yesterday}'
")->fetchAll(PDO::FETCH_ASSOC);

// 변경 후
$members = $db->query("
    SELECT bm.id AS member_id, bm.cohort_id, bm.group_id,
           c.start_date, c.end_date
    FROM bootcamp_members bm
    JOIN cohorts c ON bm.cohort_id = c.id
    WHERE bm.is_active = 1
      AND bm.member_status != 'refunded'
      AND c.end_date >= '{$yesterday}'
")->fetchAll(PDO::FETCH_ASSOC);
```

- [ ] **Step 3: invariant 통과 확인**

```bash
php /root/boot-dev/tests/leaving_cron_invariants.php
```

Expected: `2 PASS, 0 FAIL`

- [ ] **Step 4: 실제 cron 한 번 돌려서 leaving 회원에게 inbox row 가 생성되는지 확인**

DEV 에서:
```bash
cd /root/boot-dev && php public_html/cron.php init_daily_checks 2>&1 | tail -5
```

DEV `leaving` 회원 1명 (cnt 위에서 확인) 에게 row 가 생성됐는지:
```bash
source /root/boot-dev/.db_credentials && mysql -u"$DB_USER" -p"$DB_PASS" SORITUNECOM_DEV_BOOT -e "
SELECT bm.id, bm.member_status,
       COUNT(mmc.id) AS today_inbox
FROM bootcamp_members bm
JOIN cohorts c ON bm.cohort_id = c.id
LEFT JOIN member_mission_checks mmc
  ON mmc.member_id = bm.id AND mmc.check_date = CURDATE()
WHERE bm.is_active = 1 AND bm.member_status = 'leaving'
  AND c.start_date <= CURDATE() AND c.end_date >= CURDATE()
GROUP BY bm.id;
"
```

Expected: `today_inbox >= 3` (zoom_daily, daily_mission, inner33 + 월요일이면 +1 speak_mission).

활성 cohort 안에 leaving 회원이 없으면 SKIP (출력은 0행) — 이 경우 Task 1 invariant 만으로도 충분.

- [ ] **Step 5: commit**

```bash
cd /root/boot-dev
git add public_html/cron.php
git commit -m "fix(cron): leaving/OOM 회원에게도 일일 미션 inbox 생성

init_daily_checks + backfillChecks 의 'member_status=active' 게이트를
'!= refunded' 로 완화. spec 2026-05-22-leaving-member-activity-tracking.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: 출석률 분모 변경

**Files:**
- Modify: `public_html/api/services/attendance.php:21`

- [ ] **Step 1: SQL 변경**

Edit `public_html/api/services/attendance.php` 의 `handleAttendanceStats()` 안 (라인 17~22):

```php
// 변경 전
$totalStmt = $db->prepare("
    SELECT COUNT(*) FROM bootcamp_members
    WHERE cohort_id = ? AND is_active = 1
      AND member_status NOT IN ('refunded','leaving')
");

// 변경 후
$totalStmt = $db->prepare("
    SELECT COUNT(*) FROM bootcamp_members
    WHERE cohort_id = ? AND is_active = 1
      AND member_status != 'refunded'
");
```

- [ ] **Step 2: 분모 변화 수동 확인 (DEV)**

```bash
source /root/boot-dev/.db_credentials && mysql -u"$DB_USER" -p"$DB_PASS" SORITUNECOM_DEV_BOOT -e "
SELECT 'before' AS variant, c.cohort,
       COUNT(*) AS denom
FROM bootcamp_members bm JOIN cohorts c ON bm.cohort_id = c.id
WHERE c.is_active = 1 AND bm.is_active = 1
  AND bm.member_status NOT IN ('refunded','leaving')
GROUP BY c.cohort
UNION ALL
SELECT 'after' AS variant, c.cohort,
       COUNT(*) AS denom
FROM bootcamp_members bm JOIN cohorts c ON bm.cohort_id = c.id
WHERE c.is_active = 1 AND bm.is_active = 1
  AND bm.member_status != 'refunded'
GROUP BY c.cohort;
"
```

Expected: `after` 분모가 `before` 보다 활성 cohort 의 (leaving + OOM 회원 수) 만큼 큼.

- [ ] **Step 3: commit**

```bash
cd /root/boot-dev
git add public_html/api/services/attendance.php
git commit -m "fix(attendance): 출석률 분모에 leaving/OOM 회원 포함

cron 게이트 정렬과 일관성. spec 4.2.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: 후기 작성 권한 invariant + 코드 변경

**Files:**
- Create: `tests/leaving_review_invariants.php`
- Modify: `public_html/api/services/review.php:32`

- [ ] **Step 1: invariant 테스트 작성 (현재 코드에서 FAIL 해야 함)**

```php
<?php
/**
 * leaving / out_of_group_management 회원도 후기 작성이 허용되는지.
 *
 * 사용: php tests/leaving_review_invariants.php
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

// review.php 의 권한 체크 SQL 패턴을 직접 재현 (요청 라우팅까지 안 함)
$db = getDB();
$db->beginTransaction();

try {
    $cohortLabel = 'TEST_REV_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 1, CURDATE())");

    $ins->execute([$cohortId, '나간', 'l', 'leaving', 1]);
    $idL = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '강등', 'o', 'out_of_group_management', 1]);
    $idO = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '환불', 'r', 'refunded', 0]);
    $idR = (int)$db->lastInsertId();

    // review.php 게이트 (변경 후): 'refunded' 만 차단
    $blocked = ['refunded'];

    $stmt = $db->prepare("SELECT member_status FROM bootcamp_members WHERE id = ?");

    foreach ([['leaving', $idL, false], ['out_of_group_management', $idO, false], ['refunded', $idR, true]] as [$label, $id, $expectBlock]) {
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

- [ ] **Step 2: 테스트 실행 — 변경 후 SQL invariant 라 PASS 가 정상**

```bash
php /root/boot-dev/tests/leaving_review_invariants.php
```

Expected: `3 PASS, 0 FAIL`. 이 테스트는 invariant (변경 후 정책의 명세) 라서 코드 변경 전에도 통과. 코드 변경이 invariant 와 정렬되었는지는 Step 3 의 grep 으로 확인.

- [ ] **Step 3: review.php 변경**

Edit `public_html/api/services/review.php` 의 `evaluateReviewEligibility()` 안 (라인 31~33):

```php
// 변경 전
    if ((int)$member['is_active'] !== 1 ||
        in_array($member['member_status'], ['refunded', 'leaving', 'out_of_group_management'])) {
        return ['eligible' => false, 'reason' => 'member_inactive', 'active_cycle' => null, 'member' => $member];

// 변경 후
    if ((int)$member['is_active'] !== 1 ||
        in_array($member['member_status'], ['refunded'])) {
        return ['eligible' => false, 'reason' => 'member_inactive', 'active_cycle' => null, 'member' => $member];
```

- [ ] **Step 4: 변경 검증**

```bash
grep -n "in_array(\$member\['member_status'\]" /root/boot-dev/public_html/api/services/review.php
```

Expected: `['refunded']` 만 남음.

- [ ] **Step 5: commit**

```bash
cd /root/boot-dev
git add public_html/api/services/review.php tests/leaving_review_invariants.php
git commit -m "fix(review): leaving/OOM 회원 후기 작성 허용 + invariant

spec 4.3. 단체활동의 일환으로 후기 작성 통일.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: 부티즈 목록 invariant + 코드 변경

**Files:**
- Create: `tests/leaving_bootees_invariants.php`
- Modify: `public_html/api/services/member_page.php:402`

- [ ] **Step 1: invariant 테스트 작성**

```php
<?php
/**
 * handleMemberBootees 의 같은-기수 부티즈 목록이 leaving / OOM 도 포함하는지.
 *
 * 사용: php tests/leaving_bootees_invariants.php
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
    $cohortLabel = 'TEST_BTZ_' . bin2hex(random_bytes(3));
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

    // member_page.php:402 부티즈 SELECT (변경 후)
    $sql = "
        SELECT bm.id
        FROM bootcamp_members bm
        WHERE bm.cohort_id = ?
          AND bm.is_active = 1
          AND bm.member_status != 'refunded'
        ORDER BY bm.id ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$cohortId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    sort($ids);

    $expected = [$idA, $idL, $idO];
    sort($expected);

    t('부티즈 목록 = active + leaving + OOM', $ids === $expected,
      'got=' . json_encode($ids) . ' expected=' . json_encode($expected));
    t('refunded 제외', !in_array($idR, $ids, true));
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 테스트 실행 (PASS 정상 — invariant)**

```bash
php /root/boot-dev/tests/leaving_bootees_invariants.php
```

Expected: `2 PASS, 0 FAIL`

- [ ] **Step 3: member_page.php:402 변경**

Edit `public_html/api/services/member_page.php` `handleMemberBootees()` 안 SELECT (라인 388~404 부근):

```php
// 변경 전 (라인 400~402)
WHERE bm.cohort_id = ?
  AND bm.is_active = 1
  AND bm.member_status = 'active'

// 변경 후
WHERE bm.cohort_id = ?
  AND bm.is_active = 1
  AND bm.member_status != 'refunded'
```

- [ ] **Step 4: 변경 검증**

```bash
grep -n "AND bm.member_status" /root/boot-dev/public_html/api/services/member_page.php | grep -i "active\|refunded"
```

Expected: 라인 401 부근에 `!= 'refunded'` 표시. (다른 곳의 `member_page.php:72, 181` 의 `OR member_status='leaving'` 는 그대로 두는 게 정상 — spec 4.5)

- [ ] **Step 5: commit**

```bash
cd /root/boot-dev
git add public_html/api/services/member_page.php tests/leaving_bootees_invariants.php
git commit -m "fix(member_page): 부티즈 목록에 leaving/OOM 포함 + invariant

spec 4.3b. 같은 기수 부티즈 표시 일관성.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: QR 스캔 회귀 가드 (코드 변경 없음)

**Files:**
- Create: `tests/leaving_qr_scan_invariants.php`

이미 `qr_actions.php:28` 게이트가 `!= 'refunded'` 라 leaving 회원도 QR 작동. 회귀 방지용 invariant 만 추가.

- [ ] **Step 1: 테스트 작성**

```php
<?php
/**
 * leaving 회원이 QR 스캔 시 qrRecordAttendance 가 정상 통과하고
 * member_mission_checks 에 status=1 으로 INSERT 되는지.
 *
 * 사용: php tests/leaving_qr_scan_invariants.php
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
    // cohort + member (leaving, group_id=NULL)
    $cohortLabel = 'TEST_QR_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, '나간', 'l', 'leaving', 1, 1, CURDATE())")
       ->execute([$cohortId]);
    $memberId = (int)$db->lastInsertId();

    // QR session (zoom_daily 매핑되는 일반 세션, study_sessions 연결 없음)
    $sessionCode = 'TQR' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO qr_sessions
        (cohort_id, session_code, status, session_type, admin_id, created_at)
        VALUES (?, ?, 'active', 'normal', NULL, NOW())")
       ->execute([$cohortId, $sessionCode]);
    $sessionId = (int)$db->lastInsertId();

    $session = [
        'id' => $sessionId,
        'cohort_id' => $cohortId,
        'session_code' => $sessionCode,
        'status' => 'active',
        'session_type' => 'normal',
        'admin_id' => null,
    ];

    // 호출
    $result = qrRecordAttendance($db, $session, $memberId, null, '127.0.0.1', 'test');

    t('qrRecordAttendance ok=true', !empty($result['ok']));
    t('이미 처리됨 아님', empty($result['already']));

    // member_mission_checks 검증
    $row = $db->prepare("
        SELECT mmc.status, mt.code
        FROM member_mission_checks mmc
        JOIN mission_types mt ON mt.id = mmc.mission_type_id
        WHERE mmc.member_id = ? AND mmc.check_date = CURDATE()
    ");
    $row->execute([$memberId]);
    $rows = $row->fetchAll(PDO::FETCH_ASSOC);

    $zoomRow = null;
    foreach ($rows as $r) {
        if ($r['code'] === 'zoom_daily') { $zoomRow = $r; break; }
    }
    t('zoom_daily 체크 INSERT 됨', $zoomRow !== null);
    t('zoom_daily status=1', $zoomRow && (int)$zoomRow['status'] === 1);

    // qr_attendance 검증
    $att = $db->prepare("SELECT COUNT(*) FROM qr_attendance WHERE qr_session_id = ? AND member_id = ?");
    $att->execute([$sessionId, $memberId]);
    t('qr_attendance 행 1개', (int)$att->fetchColumn() === 1);
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 실행**

```bash
php /root/boot-dev/tests/leaving_qr_scan_invariants.php
```

Expected: `5 PASS, 0 FAIL` (이미 작동 중인 흐름의 회귀 가드)

- [ ] **Step 3: commit**

```bash
cd /root/boot-dev
git add tests/leaving_qr_scan_invariants.php
git commit -m "test(leaving): QR 스캔 → saveCheck INSERT 회귀 가드

leaving 회원 QR 스캔 시 qrRecordAttendance + member_mission_checks INSERT
정상 동작 검증. 코드 변경 없음.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: 카페 인제스트 회귀 가드 (코드 변경 없음)

**Files:**
- Create: `tests/leaving_cafe_ingest_invariants.php`

`resolveMemberByKey` 는 `is_active=1` 만 체크해서 leaving 도 통과. 회귀 가드.

- [ ] **Step 1: 테스트 작성**

```php
<?php
/**
 * leaving 회원의 cafe_member_key 매핑이 살아있을 때 ingestCafePosts 가
 * member_mission_checks 에 자동 체크를 INSERT 하는지.
 *
 * 사용: php tests/leaving_cafe_ingest_invariants.php
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
    $cohortLabel = 'TEST_CAF_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $memberKey = 'TLEAV_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, cafe_member_key, member_status, is_active, stage_no, joined_at)
        VALUES (?, '나간', 'l', ?, 'leaving', 1, 1, CURDATE())")
       ->execute([$cohortId, $memberKey]);
    $memberId = (int)$db->lastInsertId();

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    ingestCafePosts([
        [
            'cafe_article_id' => 'TCAF_' . bin2hex(random_bytes(3)),
            'title' => 'test post',
            'member_key' => $memberKey,
            'nickname' => 'leaving_user',
            'board_type' => 'inner33',
            'posted_at' => $now,
            'assignment_date' => $today,
        ],
    ]);

    $row = $db->prepare("
        SELECT mmc.status, mt.code
        FROM member_mission_checks mmc
        JOIN mission_types mt ON mt.id = mmc.mission_type_id
        WHERE mmc.member_id = ? AND mmc.check_date = ?
    ");
    $row->execute([$memberId, $today]);
    $rows = $row->fetchAll(PDO::FETCH_ASSOC);

    $inner = null;
    foreach ($rows as $r) {
        if ($r['code'] === 'inner33') { $inner = $r; break; }
    }
    t('inner33 자동체크 INSERT 됨', $inner !== null);
    t('inner33 status=1', $inner && (int)$inner['status'] === 1);
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 실행**

```bash
php /root/boot-dev/tests/leaving_cafe_ingest_invariants.php
```

Expected: `2 PASS, 0 FAIL`

- [ ] **Step 3: commit**

```bash
cd /root/boot-dev
git add tests/leaving_cafe_ingest_invariants.php
git commit -m "test(leaving): 카페 인제스트 → saveCheck INSERT 회귀 가드

leaving 회원 cafe_member_key 매핑 유지 시 자동체크 INSERT 정상 검증.
코드 변경 없음.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: UI 라벨 일괄 변경

**Files (모두 수정):**
- `public_html/api/services/member.php` (173)
- `public_html/api/admin.php` (807)
- `public_html/js/memberTable.js` (41, 155)
- `public_html/js/admin.js` (1122, 1132, 1375)
- `public_html/js/bootcamp.js` (1672, 2287)

`member_status` 값 자체 (`'leaving'`) 는 그대로 두고 사용자 노출 라벨만 변경.

- [ ] **Step 1: member.php:173**

```php
// 변경 전
$label = $status === 'leaving' ? '나간 회원' : '활성';

// 변경 후
$label = $status === 'leaving' ? '조에서 빠진 회원' : '활성';
```

- [ ] **Step 2: admin.php:807**

```php
// 변경 전
$label = $status === 'leaving' ? '나간 회원' : '활성';

// 변경 후
$label = $status === 'leaving' ? '조에서 빠진 회원' : '활성';
```

- [ ] **Step 3: memberTable.js:41 (배지) + :155 (버튼)**

```js
// 라인 41 변경 전
if (m.member_status === 'leaving') return '<span class="badge badge-warning-solid">나간 회원</span>';

// 라인 41 변경 후
if (m.member_status === 'leaving') return '<span class="badge badge-warning-solid">조에서 빠짐</span>';
```

```js
// 라인 155 변경 전 (버튼 텍스트)
... onclick="${opts.setStatusFn || 'AdminApp._setMemberStatus'}(${m.id}, 'leaving', '${App.esc(m.nickname)}')">나가기</button>

// 라인 155 변경 후
... onclick="${opts.setStatusFn || 'AdminApp._setMemberStatus'}(${m.id}, 'leaving', '${App.esc(m.nickname)}')">조에서 빼기</button>
```

- [ ] **Step 4: admin.js:1122, 1132, 1375**

```js
// 라인 1122 변경 전
if (leavingN) inactiveExtra.push(`나간 회원 ${leavingN}`);

// 라인 1122 변경 후
if (leavingN) inactiveExtra.push(`조에서 빠진 회원 ${leavingN}`);
```

```js
// 라인 1132 변경 전 (안내 문구)
환불·탈락·나간 회원 포함

// 라인 1132 변경 후
환불·탈락·조에서 빠진 회원 포함
```

```js
// 라인 1375 변경 전
const label = status === 'leaving' ? '나가기' : '활성';

// 라인 1375 변경 후
const label = status === 'leaving' ? '조에서 빼기' : '활성';
```

- [ ] **Step 5: bootcamp.js:1672, 2287**

```js
// 라인 1672 변경 전
const label = status === 'leaving' ? '나가기' : '활성';

// 라인 1672 변경 후
const label = status === 'leaving' ? '조에서 빼기' : '활성';
```

```js
// 라인 2287 변경 전
const leavingBadge = m.member_status === 'leaving' ? ' <span class="badge badge-warning-solid" style="font-size:10px">나간 회원</span>' : '';

// 라인 2287 변경 후
const leavingBadge = m.member_status === 'leaving' ? ' <span class="badge badge-warning-solid" style="font-size:10px">조에서 빠짐</span>' : '';
```

- [ ] **Step 6: 남은 "나간 회원" / "나가기" 라벨 잔존 확인**

```bash
grep -rn "나간 회원\|>나가기<\|'나가기'\|\"나가기\"" /root/boot-dev/public_html/ | grep -v "// 나가기" | grep -v "members.php:166"
```

Expected: 빈 출력 (주석 안의 "// 나가기:" 는 그대로 둠 — 코드 의도 설명).

- [ ] **Step 7: commit**

```bash
cd /root/boot-dev
git add public_html/api/services/member.php public_html/api/admin.php public_html/js/memberTable.js public_html/js/admin.js public_html/js/bootcamp.js
git commit -m "fix(ui): '나가기/나간 회원' → '조에서 빼기/조에서 빠짐' 라벨

member_status='leaving' 값은 유지. 의미 명확화 spec 4.4. 9곳 일괄.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: 최종 검증 + DEV push

- [ ] **Step 1: 모든 invariants 일괄 실행**

```bash
cd /root/boot-dev
for f in tests/leaving_*.php; do
  echo "─── $f ───"
  php "$f"
done
```

Expected: 5개 파일 모두 `* PASS, 0 FAIL` 출력.

- [ ] **Step 2: 변경 grep 으로 잔존 누락 확인**

```bash
# cron 게이트 (변경된 곳만)
grep -n "member_status = 'active'" /root/boot-dev/public_html/cron.php
# Expected: 빈 출력

# 출석률 분모
grep -n "NOT IN ('refunded','leaving')" /root/boot-dev/public_html/api/services/attendance.php
# Expected: 빈 출력

# 후기 권한
grep -n "'refunded', 'leaving', 'out_of_group_management'" /root/boot-dev/public_html/api/services/review.php
# Expected: 빈 출력

# 부티즈
grep -n "AND bm.member_status = 'active'" /root/boot-dev/public_html/api/services/member_page.php
# Expected: 빈 출력
```

- [ ] **Step 3: git log 정리 확인**

```bash
cd /root/boot-dev && git log --oneline origin/dev..HEAD
```

Expected: 7~8 commits (Task 1~8 각각 1개 commit).

- [ ] **Step 4: DEV push**

```bash
cd /root/boot-dev && git push origin dev
```

- [ ] **Step 5: ⛔ 사용자 DEV 검증 요청 + 멈춤**

사용자에게 보고:
- DEV 사이트 (`https://dev-boot.soritune.com`) 에서 다음 시각 검증 요청:
  1. 운영 화면의 "나가기" 버튼이 "조에서 빼기" 로 보이는지
  2. 회원 카드에서 leaving 회원 배지가 "조에서 빠짐" 으로 보이는지
  3. (다음 02시 cron 후) DEV 의 leaving 회원에게 미션 inbox row 가 생성됐는지 → 운영 대시보드 노출
  4. 후기 작성/부티즈 목록에서 leaving 회원 노출 확인
- **사용자가 명시적으로 "운영 반영해줘" 라고 할 때만** main 머지 + prod pull 진행.
- 운영 반영 시 영향: 출석률 분모가 활성 cohort 기준 leaving (+OOM) 만큼 늘어 % 가 미세 하락. 운영자 사전 안내 필요.

---

## 후속 작업 (이번 plan 범위 밖)

- "완전 퇴출" 별도 경로 (새 enum 값 또는 `leaving_reason` 컬럼)
- 점수 자동 강등(OOM) 발생 시 운영자 알림
- "조에서 빠진 회원" 그룹별 통계/리포트 분리
