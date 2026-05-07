# Cohort Switcher Chip — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** boot 에 11기·12기 동시 active 를 허용하고 헤더의 기수 전환 칩으로 멤버·어드민이 보기/쓰기 컨텍스트를 즉시 swap 할 수 있게 한다. 기수 전환기마다 반복되는 "12기 강의 추가가 막힌다" 문제를 영구적으로 해소한다.

**Architecture:** `bootcamp_members` 가 이미 cohort 별로 row 분리되어 있다는 점을 활용한다. 칩 클릭 = 같은 phone 의 다른 cohort row 로 `$_SESSION['member_id']` 를 swap. 어드민은 view filter (`admin_view_cohort_id`). cohort 결정 로직은 `resolveAdminCohortId()` 헬퍼로 통일.

**Tech Stack:** PHP 8 + MariaDB + 순수 JS. 테스트는 boot 의 invariant CLI 패턴 (`tests/*.php`).

**Spec:** `docs/superpowers/specs/2026-05-07-cohort-switcher-chip-design.md`

---

## Pre-step (선택, 코드 변경 0): P1 즉시 차단 해제

운영자가 어드민 → "기수 관리" 탭에서 **12기 활성/비활성 토글로 12기를 활성화**. 그 즉시 `lecture.php` 의 `is_active = 1` 검증 통과 → 코치가 12기 강의/이벤트 추가 가능. 이전 로그인 충돌은 이미 코드에서 WHERE 필터로 보호되어 있고, 11기 종료(4/23) 후라 신규 등록 대부분 12기로 들어감. P2 코드 배포 전 임시 대응으로 사용 가능.

---

## File Structure

**신규 파일:**
- `public_html/js/cohort-chip.js` — 공통 chip 컴포넌트 (DOM 생성, dropdown, switch API, reload)
- `tests/cohort_switch_invariants.php` — 헬퍼·endpoint smoke 테스트

**수정 파일:**
- `public_html/auth.php` — 헬퍼, login, swap, getEffectiveCohort 확장
- `public_html/api/member.php` — login 통합, switch_cohort 신규
- `public_html/api/admin.php` — switch_cohort 신규
- `public_html/js/member.js` — 헤더 chip 부착
- `public_html/js/admin.js` — cohort-bar 제거, chip 부착, "기수 관리" 탭에 운영 기수 설정 버튼
- `public_html/index.php`, `public_html/operation/index.php`, `coach/index.php`, `head/index.php`, `leader/index.php` — `<script src="/js/cohort-chip.js">`
- `public_html/css/common.css` — `.cohort-chip` 스타일
- `public_html/api/services/*.php` — `resolveAdminCohortId` 적용 (dashboard, attendance, check, lecture, study, review, revival, curriculum, member, member_bulk, integration, issue_report, group_assignment, qr)

---

## Task 1: auth.php — cohort label/id 헬퍼 추가

**Files:**
- Modify: `public_html/auth.php` (끝에 추가)

- [ ] **Step 1: 신규 헬퍼 함수 추가**

`public_html/auth.php` 끝부분 (`Phone Normalization` 섹션 뒤) 에 추가:

```php
// ── Cohort Resolution Helpers ──────────────────────────────

/**
 * cohort.id → cohort 라벨 ('12기')
 */
function getCohortLabelById(int $id): ?string {
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    $stmt = getDB()->prepare("SELECT cohort FROM cohorts WHERE id = ?");
    $stmt->execute([$id]);
    $label = $stmt->fetchColumn();
    return $cache[$id] = ($label !== false ? $label : null);
}

/**
 * cohort 라벨 ('12기') → cohort.id
 */
function getCohortIdByLabel(string $label): ?int {
    static $cache = [];
    if (isset($cache[$label])) return $cache[$label];
    $stmt = getDB()->prepare("SELECT id FROM cohorts WHERE cohort = ?");
    $stmt->execute([$label]);
    $id = $stmt->fetchColumn();
    return $cache[$label] = ($id !== false ? (int)$id : null);
}

/**
 * 어드민 view cohort 결정.
 * 명시 cohort_id → admin_view_cohort_id → (supportsAll ? null : settings.current_cohort)
 *
 * @param int|null $explicit  request 에서 전달된 cohort_id (0 = 미지정)
 * @param array    $session   admin session
 * @param bool     $supportsAll  true 면 view 가 null('전체') 일 때 null 반환, 아니면 settings fallback
 */
function resolveAdminCohortId(?int $explicit, array $session, bool $supportsAll = false): ?int {
    if ($explicit !== null && $explicit > 0) return $explicit;
    $view = $session['admin_view_cohort_id'] ?? null;
    if ($view !== null) return $view;
    if ($supportsAll) return null;
    $label = getSetting('current_cohort');
    return $label ? getCohortIdByLabel($label) : null;
}
```

- [ ] **Step 2: 테스트 파일 생성** `tests/cohort_switch_invariants.php`

```php
<?php
/**
 * Cohort Switch 인보리언트 테스트
 * 사용: php tests/cohort_switch_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/auth.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; }
    else { $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n"; }
}

$db = getDB();

// ── Cohort label/id 헬퍼 ──
$id12 = getCohortIdByLabel('12기');
t('getCohortIdByLabel(12기) returns int', is_int($id12) && $id12 > 0);

$label = getCohortLabelById($id12);
t('getCohortLabelById round-trips', $label === '12기');

t('getCohortIdByLabel(없는 기수) returns null', getCohortIdByLabel('999기') === null);

// ── resolveAdminCohortId ──
$session = ['admin_id' => 1, 'admin_view_cohort_id' => null];
$result = resolveAdminCohortId(0, $session, true);
t('resolveAdminCohortId(supportsAll, view=null) returns null', $result === null);

$result = resolveAdminCohortId(0, $session, false);
t('resolveAdminCohortId(no all, view=null) falls back to settings', is_int($result) && $result > 0);

$session['admin_view_cohort_id'] = $id12;
$result = resolveAdminCohortId(0, $session, false);
t('resolveAdminCohortId(view=12) returns 12', $result === $id12);

$result = resolveAdminCohortId(99, $session, false);
t('resolveAdminCohortId(explicit) overrides view', $result === 99);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 3: 테스트 실행**

Run: `cd /root/boot-dev && php tests/cohort_switch_invariants.php`
Expected: 모든 PASS

- [ ] **Step 4: Commit**

```bash
git add public_html/auth.php tests/cohort_switch_invariants.php
git commit -m "feat(cohort-chip): add cohort label/id helpers + resolveAdminCohortId"
```

---

## Task 2: auth.php — findMemberAccessibleRows

**Files:**
- Modify: `public_html/auth.php`
- Modify: `tests/cohort_switch_invariants.php` (테스트 추가)

- [ ] **Step 1: findMemberAccessibleRows 추가**

`auth.php` 의 `findMemberByPhone` 아래에 추가:

```php
/**
 * phone 으로 active cohort 의 모든 member row 반환 (cohort_id DESC).
 * findMemberByPhone 의 multi-row 버전.
 */
function findMemberAccessibleRows(PDO $db, string $phone): array {
    $normalized = normalizePhone($phone);
    if (!$normalized) return [];

    $stmt = $db->prepare("
        SELECT bm.*, c.cohort,
               COALESCE(NULLIF(bm.kakao_link, ''), bg.kakao_link) AS kakao_link,
               bg.name AS group_name
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        WHERE REPLACE(REPLACE(bm.phone, '-', ''), ' ', '') = ?
          AND (bm.is_active = 1 OR bm.member_status = 'leaving')
          AND c.is_active = 1
        ORDER BY bm.cohort_id DESC
    ");
    $stmt->execute([$normalized]);
    return $stmt->fetchAll();
}
```

- [ ] **Step 2: 테스트 추가** `tests/cohort_switch_invariants.php` 에 다음 블록 추가 (마지막 echo 직전)

```php
// ── findMemberAccessibleRows ──
// DEV DB 에 phone 이 있는 회원 임의 1명 + multi-cohort 후보 필요. 없으면 skip.
$multi = $db->query("
    SELECT bm.phone
    FROM bootcamp_members bm
    JOIN cohorts c ON bm.cohort_id = c.id
    WHERE bm.is_active = 1 AND c.is_active = 1
      AND bm.phone IS NOT NULL AND bm.phone != ''
    GROUP BY bm.phone
    HAVING COUNT(DISTINCT bm.cohort_id) >= 2
    LIMIT 1
")->fetch();

if ($multi) {
    $rows = findMemberAccessibleRows($db, $multi['phone']);
    t('findMemberAccessibleRows multi-cohort 회원 시 2+ row', count($rows) >= 2);
    t('findMemberAccessibleRows cohort_id DESC 정렬', $rows[0]['cohort_id'] >= $rows[1]['cohort_id']);
} else {
    echo "SKIP  findMemberAccessibleRows multi-cohort (no test data)\n";
}

$rows = findMemberAccessibleRows($db, '99999999999');
t('findMemberAccessibleRows 미존재 phone → 빈 array', $rows === []);
```

- [ ] **Step 3: 실행 확인**

Run: `cd /root/boot-dev && php tests/cohort_switch_invariants.php`
Expected: 모두 PASS (또는 SKIP if no multi-cohort 테스트 데이터)

- [ ] **Step 4: Commit**

```bash
git add public_html/auth.php tests/cohort_switch_invariants.php
git commit -m "feat(cohort-chip): findMemberAccessibleRows for multi-cohort lookup"
```

---

## Task 3: auth.php — loginMember/getMemberSession 확장 + inline 마이그레이션

**Files:**
- Modify: `public_html/auth.php`

- [ ] **Step 1: loginMember 시그니처 확장**

기존 `loginMember()` 를 다음으로 교체 (시그니처 호환 유지: 신규 인자는 nullable):

```php
function loginMember(int $id, string $name, string $cohort, ?string $nickname = null, array $accessibleCohorts = []): void {
    startSessionFor('member');
    session_regenerate_id(true);
    $_SESSION['member_id']   = $id;
    $_SESSION['member_name'] = $name;
    $_SESSION['cohort']      = $cohort;
    $_SESSION['nickname']    = $nickname;
    $_SESSION['accessible_cohorts'] = $accessibleCohorts;
    session_write_close();
}
```

- [ ] **Step 2: getMemberSession inline 마이그레이션 추가**

기존 `getMemberSession()` 의 `$data` 빌드 직전 (또는 빌드 시) 에 누락된 `accessible_cohorts` 자동 채움 추가. 교체:

```php
function getMemberSession(): ?array {
    startSessionFor('member');
    if (empty($_SESSION['member_id'])) {
        session_write_close();
        return null;
    }

    // 구버전 세션 inline 마이그레이션: accessible_cohorts 가 없으면 현재 cohort 1개로 채움
    if (!isset($_SESSION['accessible_cohorts']) || !is_array($_SESSION['accessible_cohorts'])) {
        $_SESSION['accessible_cohorts'] = [[
            'member_id'    => (int)$_SESSION['member_id'],
            'cohort_id'    => null, // 미상 — switch 시도 시 fallback 처리
            'cohort_label' => $_SESSION['cohort'] ?? '',
        ]];
    }

    $data = [
        'member_id'          => $_SESSION['member_id'],
        'member_name'        => $_SESSION['member_name'],
        'cohort'             => $_SESSION['cohort'],
        'nickname'           => $_SESSION['nickname'] ?? null,
        'accessible_cohorts' => $_SESSION['accessible_cohorts'],
    ];
    session_write_close();
    return $data;
}
```

- [ ] **Step 3: 컴파일 에러 없음 확인**

Run: `cd /root/boot-dev && php -l public_html/auth.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: 기존 invariant 테스트 회귀 없음 확인**

Run: `cd /root/boot-dev && php tests/cohort_switch_invariants.php && php tests/qr_auth_invariants.php`
Expected: 모두 PASS

- [ ] **Step 5: Commit**

```bash
git add public_html/auth.php
git commit -m "feat(cohort-chip): loginMember/getMemberSession with accessible_cohorts"
```

---

## Task 4: auth.php — swapMemberCohort

**Files:**
- Modify: `public_html/auth.php`
- Modify: `tests/cohort_switch_invariants.php`

- [ ] **Step 1: swapMemberCohort 추가**

`logoutMember()` 위에 추가:

```php
/**
 * 세션의 member_id 를 같은 사람의 다른 cohort row 로 swap.
 * accessible_cohorts 안에 cohort_id 가 있어야 함.
 *
 * @return bool 성공 여부
 */
function swapMemberCohort(int $cohortId): bool {
    startSessionFor('member');
    if (empty($_SESSION['member_id'])) { session_write_close(); return false; }

    $accessible = $_SESSION['accessible_cohorts'] ?? [];
    $target = null;
    foreach ($accessible as $row) {
        if ((int)($row['cohort_id'] ?? 0) === $cohortId) { $target = $row; break; }
    }
    if (!$target) { session_write_close(); return false; }

    // 보안: row 의 member_id 가 실제 그 cohort 에 속하는지 재확인
    $db = getDB();
    $stmt = $db->prepare("
        SELECT bm.id, bm.real_name, c.cohort, bm.nickname
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.id = ? AND bm.cohort_id = ?
          AND (bm.is_active = 1 OR bm.member_status = 'leaving')
          AND c.is_active = 1
    ");
    $stmt->execute([(int)$target['member_id'], $cohortId]);
    $row = $stmt->fetch();
    if (!$row) { session_write_close(); return false; }

    session_regenerate_id(true);
    $_SESSION['member_id']   = (int)$row['id'];
    $_SESSION['member_name'] = $row['real_name'];
    $_SESSION['cohort']      = $row['cohort'];
    $_SESSION['nickname']    = $row['nickname'];
    // accessible_cohorts 는 그대로 유지
    session_write_close();
    return true;
}
```

- [ ] **Step 2: 테스트 추가** `tests/cohort_switch_invariants.php` 에 (마지막 echo 직전):

```php
// ── swapMemberCohort ──
// PHP CLI 에서 세션 시뮬레이션. session_start 가능한 환경 가정 (아니면 skip)
if (function_exists('session_start')) {
    if (session_status() === PHP_SESSION_NONE) {
        // CLI에서 명시적 session 디렉토리 사용
        $tmpDir = sys_get_temp_dir() . '/cohort_test_' . uniqid();
        @mkdir($tmpDir);
        session_save_path($tmpDir);
    }
    // 미존재 cohort_id → false
    $_SESSION = [
        'member_id' => 999999,
        'cohort' => '12기',
        'accessible_cohorts' => [['member_id' => 999999, 'cohort_id' => $id12, 'cohort_label' => '12기']],
    ];
    t('swapMemberCohort 비목록 cohort_id → false', swapMemberCohort(99999) === false);
}
```

- [ ] **Step 3: 실행**

Run: `cd /root/boot-dev && php tests/cohort_switch_invariants.php`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add public_html/auth.php tests/cohort_switch_invariants.php
git commit -m "feat(cohort-chip): swapMemberCohort with re-verification + session_regenerate_id"
```

---

## Task 5: /api/member.php — login 통합 + switch_cohort 신규

**Files:**
- Modify: `public_html/api/member.php`

- [ ] **Step 1: login 핸들러 수정**

`case 'login':` 블록 안에서 `findMemberByPhone` 호출 부분을 다음으로 교체:

```php
$rows = findMemberAccessibleRows($db, $phone);
if (!$rows) jsonError('등록되지 않은 휴대폰번호입니다.');

$member = $rows[0]; // cohort_id DESC 정렬, 최신 cohort 가 default
$accessible = array_map(fn($r) => [
    'member_id'    => (int)$r['id'],
    'cohort_id'    => (int)$r['cohort_id'],
    'cohort_label' => $r['cohort'],
], $rows);

loginMember($member['id'], $member['real_name'], $member['cohort'], $member['nickname'], $accessible);
```

응답 데이터 (jsonSuccess 직전 `'member' => [...]` 배열) 에 다음 키 추가:

```php
'accessible_cohorts' => $accessible,
```

- [ ] **Step 2: switch_cohort 핸들러 추가**

기존 `switch ($action) {` 안에 다른 case 옆에 추가:

```php
case 'switch_cohort':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    requireMember();
    $input = getJsonInput();
    $cohortId = (int)($input['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');

    if (!swapMemberCohort($cohortId)) {
        jsonError('해당 기수로 전환할 수 없습니다.', 403);
    }
    $member = getMemberSession();
    jsonSuccess(['member' => $member], '기수가 전환되었습니다.');
    break;
```

- [ ] **Step 3: 통합 smoke (브라우저)**

DEV (`https://dev-boot.soritune.com`) 에서:
1. multi-cohort 테스트 회원의 phone 으로 로그인
2. DevTools → Network → login 응답에서 `accessible_cohorts` 가 2+ 인지 확인
3. DevTools → Console:
   ```js
   await fetch('/api/member.php?action=switch_cohort', {
     method: 'POST',
     headers: {'Content-Type': 'application/json'},
     body: JSON.stringify({cohort_id: <11기 cohort.id>}),
   }).then(r => r.json())
   ```
   응답: `{success: true, member: {cohort: "11기"}}`
4. 페이지 reload → 헤더 cohort 표시가 "11기"
5. 다시 switch_cohort 로 12기 호출 → 12기로 복귀

- [ ] **Step 4: Commit**

```bash
git add public_html/api/member.php
git commit -m "feat(cohort-chip): /api/member.php login + switch_cohort"
```

---

## Task 6: auth.php — loginAdmin/getAdminSession 확장 + getEffectiveCohort 확장

**Files:**
- Modify: `public_html/auth.php`

- [ ] **Step 1: loginAdmin 확장**

기존 `loginAdmin()` 을 다음으로 교체:

```php
function loginAdmin(int $id, string $name, array $roles, ?string $cohort, ?int $bootcampGroupId = null): void {
    startSessionFor('admin');
    session_regenerate_id(true);
    $_SESSION['admin_id']    = $id;
    $_SESSION['admin_name']  = $name;
    $_SESSION['admin_roles'] = $roles;
    $_SESSION['cohort']      = $cohort;
    $_SESSION['bootcamp_group_id'] = $bootcampGroupId;

    // admin_view_cohort_id default = settings.current_cohort 매핑.
    // 매핑 cohort 가 inactive 면 가장 최근 active cohort.
    $defaultCohortId = null;
    $currentLabel = getSetting('current_cohort');
    if ($currentLabel) $defaultCohortId = getCohortIdByLabel($currentLabel);
    if (!$defaultCohortId) {
        $row = getDB()->query("SELECT id FROM cohorts WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1")->fetch();
        if ($row) $defaultCohortId = (int)$row['id'];
    }
    $_SESSION['admin_view_cohort_id'] = $defaultCohortId;

    session_write_close();
}
```

- [ ] **Step 2: getAdminSession inline 마이그레이션**

기존 `getAdminSession()` 의 $data 빌드 직전에 누락된 필드 채움:

```php
function getAdminSession(): ?array {
    startSessionFor('admin');
    if (empty($_SESSION['admin_id'])) {
        session_write_close();
        return null;
    }

    // 구버전 세션 마이그레이션
    if (!array_key_exists('admin_view_cohort_id', $_SESSION)) {
        $defaultCohortId = null;
        $currentLabel = getSetting('current_cohort');
        if ($currentLabel) $defaultCohortId = getCohortIdByLabel($currentLabel);
        $_SESSION['admin_view_cohort_id'] = $defaultCohortId;
    }

    $data = [
        'admin_id'             => $_SESSION['admin_id'],
        'admin_name'           => $_SESSION['admin_name'],
        'admin_roles'          => $_SESSION['admin_roles'] ?? [],
        'cohort'               => $_SESSION['cohort'],
        'bootcamp_group_id'    => $_SESSION['bootcamp_group_id'] ?? null,
        'admin_view_cohort_id' => $_SESSION['admin_view_cohort_id'] ?? null,
    ];
    session_write_close();
    return $data;
}
```

- [ ] **Step 3: getEffectiveCohort 확장**

기존:
```php
function getEffectiveCohort(array $session): ?string {
    if (hasRole($session, 'operation')) {
        return getSetting('current_cohort');
    }
    return $session['cohort'];
}
```

→ 다음으로 교체:
```php
function getEffectiveCohort(array $session): ?string {
    // admin_view_cohort_id 가 있으면 그 라벨 우선
    $viewId = $session['admin_view_cohort_id'] ?? null;
    if ($viewId !== null) {
        $label = getCohortLabelById($viewId);
        if ($label) return $label;
    }
    if (hasRole($session, 'operation')) {
        return getSetting('current_cohort');
    }
    return $session['cohort'];
}
```

- [ ] **Step 4: 컴파일 확인 + 회귀 테스트**

Run: `cd /root/boot-dev && php -l public_html/auth.php && php tests/cohort_switch_invariants.php`
Expected: 모두 PASS

- [ ] **Step 5: Commit**

```bash
git add public_html/auth.php
git commit -m "feat(cohort-chip): loginAdmin/getAdminSession with admin_view_cohort_id"
```

---

## Task 7: /api/admin.php — switch_cohort 신규

**Files:**
- Modify: `public_html/api/admin.php`

- [ ] **Step 1: switch_cohort 핸들러 추가**

`/api/admin.php` 의 `switch ($action) {` 안에 적절한 위치 (예: `change_cohort` 옆) 에 추가:

```php
case 'switch_cohort':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    requireAdmin();
    $input = getJsonInput();
    $rawCohortId = $input['cohort_id'] ?? null;
    $cohortId = ($rawCohortId === null || $rawCohortId === '' ) ? null : (int)$rawCohortId;

    // null = '전체'. 그 외에는 active cohort 인지 검증.
    if ($cohortId !== null) {
        if ($cohortId <= 0) jsonError('cohort_id 형식 오류');
        $stmt = getDB()->prepare("SELECT 1 FROM cohorts WHERE id = ? AND is_active = 1");
        $stmt->execute([$cohortId]);
        if (!$stmt->fetchColumn()) jsonError('해당 기수가 활성 상태가 아닙니다.', 403);
    }

    startSessionFor('admin');
    $_SESSION['admin_view_cohort_id'] = $cohortId;
    session_write_close();
    jsonSuccess(['view_cohort_id' => $cohortId], '기수 보기가 전환되었습니다.');
    break;
```

- [ ] **Step 2: 통합 smoke (브라우저)**

DEV `https://dev-boot.soritune.com/operation/`:
1. 운영자 로그인
2. DevTools Console:
   ```js
   await fetch('/api/admin.php?action=switch_cohort', {
     method: 'POST',
     headers: {'Content-Type': 'application/json'},
     body: JSON.stringify({cohort_id: 11}),
   }).then(r => r.json())
   ```
   응답: `{success: true, view_cohort_id: 11}`
3. `cohort_id: null` 호출 → `view_cohort_id: null` ('전체')
4. inactive cohort id 호출 → 403

- [ ] **Step 3: Commit**

```bash
git add public_html/api/admin.php
git commit -m "feat(cohort-chip): /api/admin.php switch_cohort endpoint"
```

---

## Task 8: 어드민 service API 들에 resolveAdminCohortId 적용

**Files:**
- Modify: 다수 (아래 목록)

목적: cohort_id 가 0/미지정일 때 `admin_view_cohort_id` 우선, 없으면 settings.current_cohort fallback. 페이지마다 행위 보존.

- [ ] **Step 1: 패턴 정의**

기존 패턴:
```php
$cohortId = (int)($_GET['cohort_id'] ?? 0);
if (!$cohortId) {
    $stmt = $db->query("SELECT id FROM cohorts WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1");
    $cohortId = (int)$stmt->fetchColumn();
    if (!$cohortId) jsonError('활성 기수를 찾을 수 없습니다.');
}
```

→ 신규 패턴 (각 핸들러 진입부):
```php
$session = getAdminSession() ?: requireAdmin(); // requireAdmin 이미 호출된 경우 직접 $admin 변수 사용
$explicit = (int)($_GET['cohort_id'] ?? 0);
$cohortId = resolveAdminCohortId($explicit ?: null, $session, /* supportsAll */ false);
if (!$cohortId) jsonError('활성 기수를 찾을 수 없습니다.');
```

(이미 `$admin = requireAdmin(...)` 으로 받은 핸들러는 `resolveAdminCohortId($explicit ?: null, $admin, false)` 로 호출).

- [ ] **Step 2: 적용 대상 파일** (한 파일씩 수정 후 brower smoke)

각 파일에서 `(int)($_GET['cohort_id'] ?? 0)` 또는 `(int)($input['cohort_id'] ?? 0)` 패턴을 위 신규 패턴으로 교체. 단, **자체 cohort 셀렉터 UI 가 있는 파일** (group_assignment.php, retention.php, notify*.php) 은 `$explicit` 가 항상 명시되어 들어오므로 변화 없음 — 변경 생략 가능.

대상:
- `public_html/api/services/dashboard.php`
- `public_html/api/services/attendance.php`
- `public_html/api/services/check.php` (4 곳)
- `public_html/api/services/lecture.php` (3 곳: handleLectureCoaches, handleLectureSessions [resolveLectureAuth 내], handleLectureEvents)
- `public_html/api/services/study.php` (4 곳)
- `public_html/api/services/review.php`
- `public_html/api/services/revival.php`
- `public_html/api/services/curriculum.php`
- `public_html/api/services/member.php` (2 곳)
- `public_html/api/services/member_bulk.php`
- `public_html/api/services/integration.php`
- `public_html/api/services/issue_report.php`
- `public_html/api/qr.php`

- [ ] **Step 3: lecture.php is_active=1 검사 완화**

`handleLectureScheduleCreate` (line 99) 와 `handleLectureEventCreate` (line 399) 의 다음 부분:
```php
$cohortRow = $db->prepare("SELECT * FROM cohorts WHERE id = ? AND is_active = 1");
```
→ 다음으로 변경 (existence + active 별도 체크, 안내 메시지 명확화):
```php
$cohortRow = $db->prepare("SELECT *, is_active FROM cohorts WHERE id = ?");
```
그 아래 검증:
```php
$cohort = $cohortRow->fetch();
if (!$cohort) jsonError('유효한 기수를 찾을 수 없습니다.');
if (!$cohort['is_active']) jsonError("'{$cohort['cohort']}' 는 비활성 기수입니다. 어드민에서 활성화 후 다시 시도해주세요.");
```

(완화 자체보다는 멀티 active 시 자연 통과가 핵심. 단일 active 가정에서도 더 친절한 에러 메시지)

- [ ] **Step 4: 회귀 smoke**

DEV 에서 각 어드민 탭 1개씩 진입 후 cohort_id 를 query string 없이 호출 → 정상 데이터 표시. (수동, 또는 기존 invariant 테스트로 dashboard/check/study 회귀 확인)

Run: `cd /root/boot-dev && php tests/qr_auth_invariants.php && php tests/retention_invariants.php && php tests/transaction_invariants.php`
Expected: 모두 PASS

- [ ] **Step 5: Commit**

```bash
git add public_html/api/services/ public_html/api/qr.php
git commit -m "feat(cohort-chip): apply resolveAdminCohortId across admin services + lecture.php msg"
```

---

## Task 9: js/cohort-chip.js 공통 모듈

**Files:**
- Create: `public_html/js/cohort-chip.js`

- [ ] **Step 1: 신규 파일 생성**

```js
/* ══════════════════════════════════════════════════════════════
   CohortChip — 헤더의 기수 전환 칩 (회원/어드민 공용)
   ══════════════════════════════════════════════════════════════ */
window.CohortChip = (() => {
    /**
     * @param {Object} opts
     *   - container: 부착할 DOM (button 으로 교체 또는 append)
     *   - currentLabel: 현재 표시 라벨 ('12기')
     *   - options: [{cohort_id: int|null, label: string}]   ('전체' 면 cohort_id=null)
     *   - apiUrl: switch_cohort 호출 URL
     *   - onSwitched: function(cohortId) — switch 성공 후 reload 직전 호출 (선택)
     */
    function attach({ container, currentLabel, options, apiUrl, onSwitched }) {
        if (!container) return;

        container.innerHTML = '';
        container.classList.add('cohort-chip');
        container.setAttribute('type', 'button');

        const labelSpan = document.createElement('span');
        labelSpan.className = 'cohort-chip-label';
        labelSpan.textContent = currentLabel;
        container.appendChild(labelSpan);

        const arrow = document.createElement('span');
        arrow.className = 'cohort-chip-arrow';
        arrow.textContent = '▾';
        container.appendChild(arrow);

        const dropdown = document.createElement('div');
        dropdown.className = 'cohort-chip-dropdown';
        dropdown.style.display = 'none';
        options.forEach(opt => {
            const item = document.createElement('div');
            item.className = 'cohort-chip-item';
            const isCurrent = opt.label === currentLabel;
            item.innerHTML = `<span>${opt.label}</span>${isCurrent ? '<span class="check">✓</span>' : ''}`;
            item.onclick = async () => {
                if (isCurrent) { close(); return; }
                try {
                    const r = await fetch(apiUrl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        credentials: 'same-origin',
                        body: JSON.stringify({cohort_id: opt.cohort_id}),
                    });
                    const j = await r.json();
                    if (!j.success) { alert(j.error || '전환 실패'); return; }
                    if (typeof onSwitched === 'function') onSwitched(opt.cohort_id);
                    location.reload();
                } catch (e) { alert('네트워크 오류'); }
            };
            dropdown.appendChild(item);
        });
        container.appendChild(dropdown);

        function close() { dropdown.style.display = 'none'; }
        function open() { dropdown.style.display = ''; }

        container.addEventListener('click', (e) => {
            e.stopPropagation();
            if (dropdown.style.display === 'none') open(); else close();
        });
        document.addEventListener('click', close);

        return { close };
    }

    return { attach };
})();
```

- [ ] **Step 2: 컴파일 확인 (브라우저 콘솔)**

DEV 페이지에 임시로 `<script src="/js/cohort-chip.js"></script>` 부착 후 콘솔에서:
```js
typeof CohortChip.attach === 'function'  // true
```

- [ ] **Step 3: Commit**

```bash
git add public_html/js/cohort-chip.js
git commit -m "feat(cohort-chip): shared CohortChip JS module"
```

---

## Task 10: js/member.js — 헤더 chip 부착

**Files:**
- Modify: `public_html/js/member.js`
- Modify: `public_html/index.php`

- [ ] **Step 1: index.php 에 script 추가**

`public_html/index.php` 의 `<script src="/js/common.js...">` 다음 줄에 추가:

```html
<script src="/js/cohort-chip.js<?= v('/js/cohort-chip.js') ?>"></script>
```

- [ ] **Step 2: member.js header 분기**

`showDashboard()` 함수 안 `<div class="member-cohort">${App.esc(member.cohort)}</div>` 부분을 다음으로 교체:

```js
const accessible = member.accessible_cohorts || [];
const isMulti = accessible.length >= 2;
const cohortMarkup = isMulti
    ? `<button class="member-cohort cohort-chip-host" id="btn-member-cohort"></button>`
    : `<div class="member-cohort">${App.esc(member.cohort)}</div>`;
```

→ 헤더 markup 의 cohort div 자리에 `${cohortMarkup}` 사용:
```js
<div class="member-header">
    <div class="header-title">소리튠 부트캠프</div>
    ${cohortMarkup}
</div>
```

`MemberHome.render(...)` 호출 직전에 chip 초기화:
```js
if (isMulti) {
    CohortChip.attach({
        container: document.getElementById('btn-member-cohort'),
        currentLabel: member.cohort,
        options: accessible.map(a => ({cohort_id: a.cohort_id, label: a.cohort_label})),
        apiUrl: '/api/member.php?action=switch_cohort',
    });
}
```

- [ ] **Step 3: 통합 smoke**

DEV (`https://dev-boot.soritune.com/`):
1. 단일 cohort 회원으로 로그인 → 헤더에 정적 "12기" 표시 (chip 아님)
2. 다중 cohort 회원 로그인 → 헤더에 "12기 ▾" 칩 표시. 클릭 시 dropdown 열림. 11기 선택 → reload → 헤더 "11기 ▾"

- [ ] **Step 4: Commit**

```bash
git add public_html/js/member.js public_html/index.php
git commit -m "feat(cohort-chip): member header chip when multi-cohort enrollment"
```

---

## Task 11: js/admin.js — cohort-bar 제거 + chip 부착

**Files:**
- Modify: `public_html/js/admin.js`
- Modify: `public_html/operation/index.php`, `coach/index.php`, `head/index.php`, `leader/index.php`

- [ ] **Step 1: 4 개 admin entry 페이지에 script 추가**

각 파일에서 `<script src="/js/admin.js...">` 직전 줄에 다음 추가:
```html
<script src="/js/cohort-chip.js<?= v('/js/cohort-chip.js') ?>"></script>
```

- [ ] **Step 2: admin.js 헤더 markup 변경**

`admin-header-right` 안에 chip 자리 추가. 기존:
```js
<div class="admin-header-right">
    <span class="admin-name">${App.esc(admin.admin_name)}</span>
    ${role === 'leader' ? '...' : ''}
    ${role !== 'leader' ? '<button class="btn-change-pw" id="btn-change-pw">비밀번호 변경</button>' : ''}
    <button class="btn-logout" id="btn-logout">로그아웃</button>
</div>
```

→ admin-name 앞에 chip host 추가:
```js
<div class="admin-header-right">
    <button class="cohort-chip-host" id="btn-admin-cohort"></button>
    <span class="admin-name">${App.esc(admin.admin_name)}</span>
    ...
</div>
```

- [ ] **Step 3: cohort-bar 제거**

`${isOperation() ? '<div class="cohort-bar" id="cohort-bar"></div>' : ''}` 라인 삭제.
`async function loadCohortBar()` 또는 `cohort-bar` 를 채우는 함수 호출 코드도 모두 삭제.

- [ ] **Step 4: chip 초기화**

`loadCohortBar` 가 있던 호출 자리 또는 헤더 렌더 직후에 다음 추가:

```js
async function initAdminCohortChip() {
    const container = document.getElementById('btn-admin-cohort');
    if (!container) return;

    const r = await App.get('/api/bootcamp.php?action=cohorts');
    const cohorts = (r.cohorts || []).filter(c => c.is_active);
    const viewId = admin.admin_view_cohort_id;
    const currentLabel = viewId
        ? (cohorts.find(c => c.id == viewId)?.cohort || '전체')
        : '전체';

    const options = [
        ...cohorts.map(c => ({cohort_id: parseInt(c.id), label: c.cohort})),
        {cohort_id: null, label: '전체'},
    ];

    CohortChip.attach({
        container,
        currentLabel,
        options,
        apiUrl: '/api/admin.php?action=switch_cohort',
    });
}
initAdminCohortChip();
```

`getMemberSession` /admin session 응답에 `admin_view_cohort_id` 가 포함되어 있어야 함 (Task 6 에서 추가 완료).

- [ ] **Step 5: 어드민 세션 응답 확인**

`/api/admin.php?action=session` 또는 admin 초기화 endpoint 가 `admin_view_cohort_id` 를 반환하는지 확인. 없으면 그 endpoint 응답에 `'admin_view_cohort_id' => $admin['admin_view_cohort_id']` 추가.

- [ ] **Step 6: 통합 smoke**

DEV `/operation/`, `/coach/`, `/head/`, `/leader/`:
1. 헤더에 chip 표시
2. 클릭 → 11기/12기/전체 옵션
3. 11기 선택 → reload → 페이지 데이터가 11기 기준
4. "전체" 선택 → reload → 회원 관리 등 multi-cohort 페이지 모든 기수 표시, 단일-only 페이지는 fallback + 토스트

- [ ] **Step 7: Commit**

```bash
git add public_html/js/admin.js public_html/operation public_html/coach public_html/head public_html/leader
git commit -m "feat(cohort-chip): admin header chip + remove cohort-bar"
```

---

## Task 12: settings.current_cohort UI 를 "기수 관리" 탭으로 이동

**Files:**
- Modify: `public_html/js/admin.js` (cohort 관리 탭 렌더링 부분)

- [ ] **Step 1: 기수 관리 탭에 "운영 기수로 설정" 버튼 추가**

기존 cohort 목록 렌더링 (admin.js 안 cohort 관리 탭) 에서 각 cohort row 의 마지막 셀에 다음 버튼 추가:

```js
const isCurrent = c.cohort === currentCohortLabel;
const setBtn = isCurrent
    ? '<span class="badge-current">현재 운영 기수</span>'
    : `<button class="btn btn-sm btn-secondary set-current-cohort" data-cohort="${App.esc(c.cohort)}">운영 기수로 설정</button>`;
```

버튼 핸들러:
```js
document.querySelectorAll('.set-current-cohort').forEach(btn => {
    btn.onclick = async () => {
        if (!confirm(`'${btn.dataset.cohort}' 를 운영 기수로 설정하시겠습니까? (시스템 default)`)) return;
        const r = await App.post('/api/admin.php?action=change_cohort', {cohort: btn.dataset.cohort});
        if (r.success) {
            Toast.success(r.message || '설정되었습니다.');
            location.reload();
        }
    };
});
```

(`change_cohort` API 자체는 기존 코드 유지. 호출 위치만 이동)

- [ ] **Step 2: smoke**

DEV `/operation/` → "기수 관리" 탭 → cohort 목록에서 현재 아닌 cohort 옆 "운영 기수로 설정" 버튼 클릭 → 확인 → settings 변경 → reload.

- [ ] **Step 3: Commit**

```bash
git add public_html/js/admin.js
git commit -m "feat(cohort-chip): move settings.current_cohort UI to 기수 관리 tab"
```

---

## Task 13: CSS — chip 스타일

**Files:**
- Modify: `public_html/css/common.css`

- [ ] **Step 1: 스타일 추가**

`common.css` 끝부분에 추가:

```css
/* ══════════════════ Cohort Chip ══════════════════ */
.cohort-chip {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 999px;
    color: inherit;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}
.cohort-chip:hover { background: rgba(255, 255, 255, 0.2); }
.cohort-chip-arrow { font-size: 10px; opacity: 0.7; }
.cohort-chip-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    right: 0;
    min-width: 120px;
    background: #fff;
    color: #111;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 1000;
    overflow: hidden;
}
.cohort-chip-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    font-size: 14px;
    cursor: pointer;
}
.cohort-chip-item:hover { background: #f3f4f6; }
.cohort-chip-item .check { color: #16a34a; font-weight: 700; }

/* member-cohort 가 chip-host 일 때 기존 정적 라벨 스타일 무시 */
button.member-cohort.cohort-chip-host { background: rgba(255, 255, 255, 0.12); }
```

- [ ] **Step 2: 시각 확인**

DEV 다중 cohort 회원으로 로그인 → 헤더 chip 정상 표시 + dropdown 열기/닫기 부드럽게.
어드민 페이지에서도 동일.

- [ ] **Step 3: Commit**

```bash
git add public_html/css/common.css
git commit -m "feat(cohort-chip): chip styles"
```

---

## Task 14: DEV 통합 smoke 검증

**Files:** (검증만, 코드 변경 없음)

- [ ] **Step 1: 시나리오 1 — 단일 cohort 회원**

12기에만 등록된 회원으로 `/` 로그인 → 헤더에 정적 "12기" (chip 아님). 대시보드/스터디/QR 모두 정상.

- [ ] **Step 2: 시나리오 2 — 다중 cohort 회원**

(필요시 DEV DB 에 11기·12기 양쪽 active enrollment 인 테스트 회원 1명 시드)

- 로그인 → 헤더 "12기 ▾" chip
- chip → 11기 선택 → reload → 헤더 "11기 ▾"
- 대시보드/조 정보/스터디/QR 모두 11기 데이터로 표시
- 점수/코인이 11기 row 의 값
- 칩 → 12기 → reload → 12기 데이터로 복귀

- [ ] **Step 3: 시나리오 3 — 운영자 chip**

`/operation/` 로그인:
- chip "12기 ▾" (settings.current_cohort 기준)
- 11기 선택 → reload → 출석/체크리스트 11기 데이터
- "전체" 선택 → reload → 회원 관리 multi-cohort 표시. 대시보드 진입 시 fallback + 토스트

- [ ] **Step 4: 시나리오 4 — 코치 chip**

`/coach/` 로그인 → chip 동작 확인. 권한 없는 cohort 데이터는 backend 차단되는지 확인.

- [ ] **Step 5: 시나리오 5 — 특강 추가**

`/coach/` chip 12기 → 특강 관리 탭 → 새 강의 추가 → 12기 cohort 로 성공.
chip 11기 → 새 강의 추가 → 11기 cohort 로 성공.

- [ ] **Step 6: 시나리오 6 — 운영 기수 변경 (기수 관리 탭)**

`/operation/` → 기수 관리 → 11기 옆 "운영 기수로 설정" → 확인 → 새 로그인 admin 의 default chip 이 "11기".

- [ ] **Step 7: 시나리오 7 — 기존 invariant 회귀**

Run: `cd /root/boot-dev && php tests/cohort_switch_invariants.php && php tests/qr_auth_invariants.php && php tests/retention_invariants.php && php tests/transaction_invariants.php`
Expected: 모두 PASS

- [ ] **Step 8: 발견된 이슈 fix 및 커밋** (반복)

문제 발견 시 별도 fix commit 추가.

---

## Task 15: dev push + 사용자 확인

**Files:** (push 만)

- [ ] **Step 1: 변경 요약**

```bash
cd /root/boot-dev && git log --oneline origin/dev..HEAD
```
모든 task commit 이 깔끔히 쌓여 있는지 확인.

- [ ] **Step 2: dev push**

```bash
cd /root/boot-dev && git push origin dev
```

- [ ] **Step 3: 사용자 확인 요청**

CLAUDE.md 규칙: dev push 후 반드시 멈추고 사용자에게 dev 검증 요청. 사용자가 "운영 반영해줘" 등 명시적으로 요청한 경우에만 main 머지 + prod pull 진행.

운영 반영 시 추가로 필요한 작업:
- 운영자가 어드민 → 기수 관리 → 12기 활성화 (P1 미실행 상태였다면)
- PROD smoke (시나리오 1~6)
- 24시간 모니터링

---

## Self-review 결과

- **Spec coverage:** §1~§7 모두 task 대응 완료. P1 즉시 차단 해제는 Pre-step.
- **Placeholder:** 없음. 모든 step 에 실제 코드 또는 명령 포함.
- **Type consistency:** `accessible_cohorts` 키명 (`member_id`, `cohort_id`, `cohort_label`) Tasks 2/3/4/5 에서 일관. `admin_view_cohort_id` Tasks 6/7/11 일관. `resolveAdminCohortId(?int $explicit, array $session, bool $supportsAll)` 시그니처 Tasks 1/8 일관.
