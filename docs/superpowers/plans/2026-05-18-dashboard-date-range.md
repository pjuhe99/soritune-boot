# 운영 대시보드 날짜 범위 셀렉터 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** boot.soritune.com `/operation` 대시보드에 시작/종료 날짜 셀렉터를 추가해 사용자가 임의 구간의 과제율을 볼 수 있게 한다. 디폴트 구간은 `scoring_start ~ today` (적응기간 중에는 `cohort_start ~ today`)로 변경하고, 새로고침 시 디폴트로 초기화한다.

**Architecture:**
- 백엔드: `computeDashboardStats()` 시그니처에 `?string $reqStart, ?string $reqEnd` 인자 추가. 빈 값일 때 서버가 디폴트 산출. 응답 키 `display_start`/`scoring_end` 제거, `agg_start`/`agg_end`/`default_start`/`default_end`/`cohort_start`/`is_default_range` 신규.
- 프론트엔드: `loadDashboard()`에 toolbar 안 date input 2개 + [기본값] 버튼. input 변경 → 350ms debounce → fetch. 디폴트는 input 비워서 서버 디폴트 사용.
- 데이터 흐름: 단일 호출처(`bootcamp.js`)이므로 응답 키 변경 안전. 점수 분포·경고 멤버는 누적이라 영향 없음.

**Tech Stack:** PHP 8 (PDO MySQL), Vanilla JS, 자체 테스트 러너 (php tests/*.php).

**Spec:** `docs/superpowers/specs/2026-05-18-dashboard-date-range-design.md`

---

## File Structure

**Modify:**
- `public_html/api/services/dashboard.php` — `handleDashboardStats`, `computeDashboardStats` 시그니처/로직/응답 키 변경
- `public_html/js/bootcamp.js` — `loadDashboard`/`renderDashboard` 함수에 date input toolbar + debounce fetch 추가
- `public_html/css/bootcamp.css` — `.db-daterange` 등 신규 스타일 추가
- `tests/dashboard_adaptation_test.php` — 응답 키 (`display_start`/`scoring_end` → `agg_start`/`agg_end`) 갱신

**Create:**
- `tests/dashboard_date_range_test.php` — 신규 단위 테스트 (사용자 지정 구간, clamp, 검증 케이스)
- `tests/dashboard_date_range_invariants.php` — INV-DR-1~5

---

### Task 1: 백엔드 — `computeDashboardStats` 시그니처 확장 및 디폴트/clamp/검증 로직

**Files:**
- Modify: `public_html/api/services/dashboard.php`
- Test: `tests/dashboard_date_range_test.php` (Task 2에서 추가)

이 Task는 다음 코드 변경만 (테스트는 Task 2에서 작성).

- [ ] **Step 1: `computeDashboardStats` 시그니처에 `$reqStart`/`$reqEnd` 추가**

`public_html/api/services/dashboard.php:33` 의 함수 시그니처 변경:

```php
function computeDashboardStats(
    PDO $db,
    int $cohortId,
    string $cohortStart,
    ?string $cohortEnd,
    string $todayKST,
    ?string $reqStart = null,
    ?string $reqEnd = null
): array {
```

- [ ] **Step 2: 디폴트/clamp/검증 로직 추가**

기존 함수 본문 (33~66 줄 부근) 의 다음 블록:

```php
    $adaptationEnd = date('Y-m-d', strtotime($cohortStart . ' + ' . (SCORE_ADAPTATION_DAYS - 1) . ' days'));
    $scoringStart  = date('Y-m-d', strtotime($adaptationEnd . ' +1 day'));
    $yesterday     = date('Y-m-d', strtotime($todayKST . ' -1 day'));
    $scoringEnd    = ($cohortEnd && $cohortEnd < $yesterday) ? $cohortEnd : $yesterday;

    $displayStart     = $cohortStart;
    $adaptationActive = $todayKST < $scoringStart;
    $aggStart         = $displayStart;

    if ($aggStart > $scoringEnd) {
        return [
            'display_start' => $displayStart,
            'scoring_start' => $scoringStart,
            'scoring_end' => $scoringEnd,
            'adaptation_active' => $adaptationActive,
            'total_days' => 0,
            'total_mondays' => 0,
            'cohort_summary' => null,
            'groups' => [],
            'members' => [],
            'score_distribution' => [],
            'score_warnings' => ['approaching' => [], 'revival_eligible' => [], 'out' => []],
        ];
    }
```

전체를 다음으로 교체:

```php
    $adaptationEnd    = date('Y-m-d', strtotime($cohortStart . ' + ' . (SCORE_ADAPTATION_DAYS - 1) . ' days'));
    $scoringStart     = date('Y-m-d', strtotime($adaptationEnd . ' +1 day'));
    $adaptationActive = $todayKST < $scoringStart;

    $defaultStart = $adaptationActive ? $cohortStart : $scoringStart;
    $defaultEnd   = $todayKST;
    if ($cohortEnd && $cohortEnd < $defaultEnd) $defaultEnd = $cohortEnd;

    $aggStart = $reqStart !== null && $reqStart !== '' ? $reqStart : $defaultStart;
    $aggEnd   = $reqEnd   !== null && $reqEnd   !== '' ? $reqEnd   : $defaultEnd;

    if ($aggStart < $cohortStart) $aggStart = $cohortStart;
    if ($aggEnd > $todayKST)      $aggEnd   = $todayKST;
    if ($cohortEnd && $aggEnd > $cohortEnd) $aggEnd = $cohortEnd;

    if ($aggStart > $aggEnd) jsonError('시작일이 종료일보다 이후입니다.');

    $isDefaultRange = ($aggStart === $defaultStart && $aggEnd === $defaultEnd);
```

> 참고: 기존 코드의 `if ($aggStart > $scoringEnd) { return [...빈응답...]; }` 가드 (43~57줄) 는 새 로직에서 `cohort_end < cohort_start` 같은 극단적 데이터 손상에서만 트리거된다. clamp 와 `jsonError` 가 일반 케이스를 모두 처리하므로 **이 가드 블록 자체를 삭제**한다. 이후 본문의 "memberIds 가 비었을 때" 빈 응답 블록(84줄 부근) 은 그대로 둔다 (자연스러운 빈 cohort 시나리오).

기존 `$displayStart`, `$scoringEnd`, `$yesterday` 변수 제거됨. 이후 `$aggStart`, `$aggEnd` 사용.

- [ ] **Step 3: 이후 본문에서 `$scoringEnd` → `$aggEnd` 치환**

`dashboard.php` 의 다음 위치를 `$scoringEnd` → `$aggEnd` 로 일괄 교체:

- 약 62줄 부근: `while ($current <= $scoringEnd) {` → `while ($current <= $aggEnd) {`
- 약 116줄 부근: `$stmt->execute(array_merge($memberIds, [$aggStart, $scoringEnd]));` → `$stmt->execute(array_merge($memberIds, [$aggStart, $aggEnd]));`
- 약 139줄 부근: `while ($cur <= $scoringEnd) {` → `while ($cur <= $aggEnd) {`

`$displayStart` 사용처도 모두 `$aggStart` 로 치환되어야 함 (이미 첫 블록에서 제거됨).

- [ ] **Step 4: 빈 응답(memberIds 없음) + 최종 응답 키 통일**

Step 2 에서 첫 가드 블록은 삭제되어, 빈 응답이 한 곳 (memberIds 가 비었을 때, 약 84줄) + 최종 응답 (약 298줄) 두 곳 남는다. 둘 다 다음 키 셋으로 통일:

```php
'agg_start' => $aggStart,
'agg_end' => $aggEnd,
'is_default_range' => $isDefaultRange,
'default_start' => $defaultStart,
'default_end' => $defaultEnd,
'cohort_start' => $cohortStart,
'scoring_start' => $scoringStart,
'adaptation_active' => $adaptationActive,
// ...(total_days 이하 기존 필드)
```

`display_start`/`scoring_end` 두 키는 모든 응답에서 제거.

최종 응답 (line 298 부근) 도 동일하게 변경:

```php
    return [
        'agg_start' => $aggStart,
        'agg_end' => $aggEnd,
        'is_default_range' => $isDefaultRange,
        'default_start' => $defaultStart,
        'default_end' => $defaultEnd,
        'cohort_start' => $cohortStart,
        'scoring_start' => $scoringStart,
        'adaptation_active' => $adaptationActive,
        'total_days' => $totalDays,
        'total_mondays' => $totalMondays,
        'cohort_summary' => $cohortSummary,
        'groups' => $groupResults,
        'members' => $memberResults,
        'score_distribution' => $scoreDistribution,
        'score_warnings' => [
            'approaching' => $approaching,
            'revival_eligible' => $revivalEligible,
            'out' => $out,
        ],
    ];
```

- [ ] **Step 5: `handleDashboardStats` 에 요청 파라미터 파싱 추가**

`public_html/api/services/dashboard.php:7` 의 `handleDashboardStats()` 전체를 다음으로 교체:

```php
function handleDashboardStats() {
    $admin = requireAdmin();
    $explicit = (int)($_GET['cohort_id'] ?? 0);
    $cohortId = resolveAdminCohortId($explicit ?: null, $admin, false);
    if (!$cohortId) jsonError('활성 기수를 찾을 수 없습니다.');

    $reqStart = trim((string)($_GET['start_date'] ?? ''));
    $reqEnd   = trim((string)($_GET['end_date'] ?? ''));
    foreach ([$reqStart, $reqEnd] as $d) {
        if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            jsonError('날짜 형식이 잘못되었습니다.');
        }
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT start_date, end_date FROM cohorts WHERE id = ?");
    $stmt->execute([$cohortId]);
    $cohort = $stmt->fetch();
    if (!$cohort) jsonError('기수를 찾을 수 없습니다.');

    $todayKST = date('Y-m-d');
    jsonSuccess(computeDashboardStats(
        $db, $cohortId,
        $cohort['start_date'], $cohort['end_date'] ?? null,
        $todayKST,
        $reqStart !== '' ? $reqStart : null,
        $reqEnd   !== '' ? $reqEnd   : null
    ));
}
```

- [ ] **Step 6: PHP syntax check**

Run: `php -l /root/boot-dev/public_html/api/services/dashboard.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/dashboard.php
git commit -m "feat(dashboard): 날짜 범위 셀렉터 백엔드 — agg_start/agg_end 응답 키 + 디폴트 산출/clamp"
```

---

### Task 2: 신규 단위 테스트 작성 + Task 1 코드와 함께 PASS 확인

**Files:**
- Create: `tests/dashboard_date_range_test.php`

- [ ] **Step 1: 테스트 파일 작성**

다음 내용으로 `tests/dashboard_date_range_test.php` 생성:

```php
<?php
/**
 * Dashboard 날짜 범위 셀렉터 — 단위 테스트 (CLI).
 *
 * 사용: php tests/dashboard_date_range_test.php
 *
 * 사전: DEV DB. fixture user_id prefix '__test_ddr_', cohort code '__test_ddr'.
 * computeDashboardStats() 의 $todayKST + $reqStart/$reqEnd 로 시뮬레이션.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/bootcamp_functions.php';
require_once __DIR__ . '/../public_html/api/services/dashboard.php';

$db = getDB();
$pass = 0; $fail = 0;

function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

function teardownFixture(PDO $db): void {
    $db->exec("DELETE FROM member_mission_checks WHERE member_id IN (SELECT id FROM bootcamp_members WHERE user_id LIKE '__test_ddr_%')");
    $db->exec("DELETE FROM member_scores WHERE member_id IN (SELECT id FROM bootcamp_members WHERE user_id LIKE '__test_ddr_%')");
    $db->exec("DELETE FROM bootcamp_members WHERE user_id LIKE '__test_ddr_%'");
    $db->exec("DELETE FROM cohorts WHERE code = '__test_ddr'");
}

function setupFixture(PDO $db, string $cohortStart, ?string $cohortEnd = '2099-12-31'): array {
    teardownFixture($db);
    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active) VALUES (?, ?, ?, ?, 1)")
       ->execute(['__test_ddr_cohort', '__test_ddr', $cohortStart, $cohortEnd]);
    $cohortId = (int)$db->lastInsertId();

    $memberIds = [];
    for ($i = 1; $i <= 2; $i++) {
        $db->prepare("
            INSERT INTO bootcamp_members (cohort_id, real_name, nickname, user_id, member_status, is_active)
            VALUES (?, ?, ?, ?, 'active', 1)
        ")->execute([$cohortId, "테스트{$i}", "ddr{$i}", "__test_ddr_{$i}@k"]);
        $mid = (int)$db->lastInsertId();
        $memberIds[] = $mid;
        $db->prepare("INSERT INTO member_scores (member_id, current_score, last_calculated_at) VALUES (?, 0, NOW())")
           ->execute([$mid]);
    }
    return ['cohort_id' => $cohortId, 'cohort_start' => $cohortStart, 'cohort_end' => $cohortEnd, 'member_ids' => $memberIds];
}

// ── 시나리오 1: 디폴트 (적응기간 후) ──
echo "── 1. default (after adaptation) ──\n";
$fx = setupFixture($db, '2026-01-01');
// today=2026-01-15, scoring_start=2026-01-04 → default = (2026-01-04, 2026-01-15)
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-15');
t('default agg_start = 2026-01-04', ($r['agg_start'] ?? null) === '2026-01-04');
t('default agg_end = 2026-01-15', ($r['agg_end'] ?? null) === '2026-01-15');
t('default is_default_range = true', ($r['is_default_range'] ?? null) === true);
t('default_start exposed = 2026-01-04', ($r['default_start'] ?? null) === '2026-01-04');
t('default_end exposed = 2026-01-15', ($r['default_end'] ?? null) === '2026-01-15');
t('cohort_start exposed = 2026-01-01', ($r['cohort_start'] ?? null) === '2026-01-01');
t('scoring_start exposed = 2026-01-04', ($r['scoring_start'] ?? null) === '2026-01-04');
t('display_start NOT exposed', !array_key_exists('display_start', $r));
t('scoring_end NOT exposed', !array_key_exists('scoring_end', $r));
t('total_days = 12 (4~15)', (int)($r['total_days'] ?? -1) === 12);

// ── 시나리오 2: 디폴트 (적응기간 중) ──
echo "\n── 2. default (during adaptation) ──\n";
// today=2026-01-02 (2일차), scoring_start=2026-01-04 → adaptation_active=true → default = (cohort_start, today)
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-02');
t('adaptation default agg_start = 2026-01-01', ($r['agg_start'] ?? null) === '2026-01-01');
t('adaptation default agg_end = 2026-01-02', ($r['agg_end'] ?? null) === '2026-01-02');
t('adaptation_active = true', ($r['adaptation_active'] ?? null) === true);
t('adaptation is_default_range = true', ($r['is_default_range'] ?? null) === true);
t('adaptation total_days = 2', (int)($r['total_days'] ?? -1) === 2);

// ── 시나리오 3: 사용자 지정 정상 ──
echo "\n── 3. user range (normal) ──\n";
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-15', '2026-01-05', '2026-01-10');
t('user agg_start = 2026-01-05', ($r['agg_start'] ?? null) === '2026-01-05');
t('user agg_end = 2026-01-10', ($r['agg_end'] ?? null) === '2026-01-10');
t('user is_default_range = false', ($r['is_default_range'] ?? null) === false);
t('user total_days = 6', (int)($r['total_days'] ?? -1) === 6);

// ── 시나리오 4: 시작 < cohort_start clamp ──
echo "\n── 4. clamp start to cohort_start ──\n";
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-15', '2025-12-01', '2026-01-10');
t('clamped agg_start = cohort_start (2026-01-01)', ($r['agg_start'] ?? null) === '2026-01-01');
t('clamped agg_end unchanged = 2026-01-10', ($r['agg_end'] ?? null) === '2026-01-10');

// ── 시나리오 5: 종료 > today clamp ──
echo "\n── 5. clamp end to today ──\n";
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-15', '2026-01-05', '2099-12-31');
t('clamped agg_end = today (2026-01-15)', ($r['agg_end'] ?? null) === '2026-01-15');

// ── 시나리오 6: cohort_end < today ──
echo "\n── 6. cohort_end before today ──\n";
$fx2 = setupFixture($db, '2026-01-01', '2026-01-10');
$r = computeDashboardStats($db, $fx2['cohort_id'], $fx2['cohort_start'], $fx2['cohort_end'], '2026-01-15');
t('cohort_end clamps default_end = 2026-01-10', ($r['default_end'] ?? null) === '2026-01-10');
t('cohort_end clamps agg_end = 2026-01-10', ($r['agg_end'] ?? null) === '2026-01-10');

// ── 시나리오 7: start > end → jsonError (sub-process 로 검증) ──
// jsonError 는 exit() 하므로 인라인 호출은 본 프로세스를 죽인다. 반드시 sub-process.
echo "\n── 7. start > end ──\n";
$fx3 = setupFixture($db, '2026-01-01');
$root = realpath(__DIR__ . '/..');
$snippet = "
require '{$root}/public_html/config.php';
require '{$root}/public_html/includes/bootcamp_functions.php';
require '{$root}/public_html/api/services/dashboard.php';
\$db = getDB();
\$cid = (int)\$db->query(\"SELECT id FROM cohorts WHERE code='__test_ddr' LIMIT 1\")->fetchColumn();
computeDashboardStats(\$db, \$cid, '2026-01-01', '2099-12-31', '2026-01-15', '2026-01-10', '2026-01-05');
";
$cmd = 'php -r ' . escapeshellarg($snippet) . ' 2>&1';
$body = shell_exec($cmd);
t('start > end emits error JSON',
    str_contains((string)$body, '시작일이 종료일보다 이후입니다')
    && str_contains((string)$body, '"success":false'));

teardownFixture($db);
echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 실행 → 모두 PASS 확인**

Run: `cd /root/boot-dev && php tests/dashboard_date_range_test.php`
Expected: 마지막 줄 `N pass, 0 fail` 로 종료 (대략 N=22 안팎).

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add tests/dashboard_date_range_test.php
git commit -m "test(dashboard): 날짜 범위 셀렉터 단위 테스트 (디폴트/clamp/검증)"
```

---

### Task 3: 기존 `dashboard_adaptation_test.php` 응답 키 갱신

**Files:**
- Modify: `tests/dashboard_adaptation_test.php`

기존 테스트가 `display_start`/`scoring_end` 키를 검증하는데, Task 1에서 제거되었으므로 `agg_start`/`agg_end` 로 갱신. 단, 기존 시맨틱 (cohort_start ~ yesterday 디폴트) 도 새 시맨틱 (scoring_start/cohort_start ~ today 디폴트) 으로 바뀌었으므로 기대값도 갱신.

- [ ] **Step 1: 테스트 기대값 재정렬**

`tests/dashboard_adaptation_test.php` 의 다음 단언들을 일괄 교체:

| 옛 | 새 |
|------|------|
| `($r['scoring_start'] ?? null) === '2026-01-04'` | 동일 (유지) |
| `($r['scoring_end'] ?? null) === '2026-01-14'` | `($r['agg_end'] ?? null) === '2026-01-15'` (today=01-15 포함) |
| `(int)($r['total_days'] ?? -1) === 11` | `12` (4~15 inclusive) |
| `'baseline: total_days = 14'` line: `=== 14` | `=== 12` (디폴트 시작이 scoring_start로 바뀜) |
| `'display_start' => null` → `'2026-01-01'` 단언 | `'agg_start'` 키로 변경, 적응기간 중에만 cohort_start와 같음 |

상세 변경:

**62줄 부근 (baseline):**
```php
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-15');
t('baseline: scoring_start = 2026-01-04', ($r['scoring_start'] ?? null) === '2026-01-04');
t('baseline: agg_end = 2026-01-15', ($r['agg_end'] ?? null) === '2026-01-15');
t('baseline: agg_start = 2026-01-04 (scoring_start)', ($r['agg_start'] ?? null) === '2026-01-04');
t('baseline: total_days = 12', (int)($r['total_days'] ?? -1) === 12);
t('baseline: cohort_summary non-null', !empty($r['cohort_summary']));
t('baseline: member_count = 2', (int)($r['cohort_summary']['member_count'] ?? -1) === 2);
t('baseline: is_default_range = true', ($r['is_default_range'] ?? null) === true);
```

**72줄 부근 (시나리오 A 1일차):**
```php
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-01');
t('A 1일차: agg_start = 2026-01-01 (cohort_start)', ($r['agg_start'] ?? null) === '2026-01-01');
t('A 1일차: agg_end = 2026-01-01 (today)', ($r['agg_end'] ?? null) === '2026-01-01');
t('A 1일차: scoring_start = 2026-01-04', ($r['scoring_start'] ?? null) === '2026-01-04');
t('A 1일차: adaptation_active = true', ($r['adaptation_active'] ?? null) === true);
t('A 1일차: total_days = 1 (start==today inclusive)', (int)($r['total_days'] ?? -1) === 1);
```

> 주의: 옛 테스트는 1일차에 `cohort_summary == null` 을 기대 (`aggStart > scoringEnd` 가드). 새 로직에선 `aggEnd = today = cohort_start` 이므로 가드 통과 → `cohort_summary` non-null. 단언 갱신.

**81줄 부근 (시나리오 B 2일차):**
```php
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-02');
t('B 2일차: agg_start = 2026-01-01', ($r['agg_start'] ?? null) === '2026-01-01');
t('B 2일차: agg_end = 2026-01-02', ($r['agg_end'] ?? null) === '2026-01-02');
t('B 2일차: adaptation_active = true', ($r['adaptation_active'] ?? null) === true);
t('B 2일차: cohort_summary non-null', !empty($r['cohort_summary']));
t('B 2일차: total_days = 2', (int)($r['total_days'] ?? -1) === 2);
```

**88줄 부근 (시나리오 C 3일차):**
```php
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-03');
t('C 3일차: adaptation_active = true', ($r['adaptation_active'] ?? null) === true);
t('C 3일차: total_days = 3', (int)($r['total_days'] ?? -1) === 3);
t('C 3일차: agg_end = 2026-01-03 (today)', ($r['agg_end'] ?? null) === '2026-01-03');
```

**93줄 부근 (시나리오 D 4일차 = scoring_start):**
```php
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-04');
t('D 4일차: adaptation_active = false', ($r['adaptation_active'] ?? null) === false);
t('D 4일차: agg_start = 2026-01-04 (scoring_start)', ($r['agg_start'] ?? null) === '2026-01-04');
t('D 4일차: agg_end = 2026-01-04', ($r['agg_end'] ?? null) === '2026-01-04');
t('D 4일차: total_days = 1', (int)($r['total_days'] ?? -1) === 1);
```

**98줄 부근 (시나리오 E 5일차):**
```php
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-05');
t('E 5일차: adaptation_active = false', ($r['adaptation_active'] ?? null) === false);
t('E 5일차: agg_start = 2026-01-04 (scoring_start)', ($r['agg_start'] ?? null) === '2026-01-04');
t('E 5일차: agg_end = 2026-01-05', ($r['agg_end'] ?? null) === '2026-01-05');
t('E 5일차: total_days = 2', (int)($r['total_days'] ?? -1) === 2);
```

**104줄 부근 (시나리오 F baseline regression):**
```php
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-15');
t('F baseline regression: adaptation_active = false', ($r['adaptation_active'] ?? null) === false);
t('F baseline regression: total_days = 12', (int)($r['total_days'] ?? -1) === 12);
```

- [ ] **Step 2: 실행 → 모두 PASS**

Run: `cd /root/boot-dev && php tests/dashboard_adaptation_test.php`
Expected: `N pass, 0 fail`.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add tests/dashboard_adaptation_test.php
git commit -m "test(dashboard): 적응기간 테스트를 agg_start/agg_end 키로 갱신"
```

---

### Task 4: 인보리언트 스크립트 작성

**Files:**
- Create: `tests/dashboard_date_range_invariants.php`

- [ ] **Step 1: 파일 작성**

```php
<?php
/**
 * Dashboard 날짜 범위 — 인보리언트 (PROD/DEV 양쪽 read-only 검증).
 *
 * 사용: php tests/dashboard_date_range_invariants.php
 *
 * INV-DR-1: agg_start ≤ agg_end (서버 가드 — 시작>종료는 jsonError)
 * INV-DR-2: agg_start ≥ cohort_start (clamp)
 * INV-DR-3: agg_end ≤ today (clamp)
 * INV-DR-4: 동일 요청 결정적
 * INV-DR-5: score_distribution/score_warnings 는 reqStart/reqEnd 와 무관
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/bootcamp_functions.php';
require_once __DIR__ . '/../public_html/api/services/dashboard.php';

$db = getDB();
$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// 활성 cohort 1개 자동 선택
$cohort = $db->query("SELECT id, start_date, end_date FROM cohorts WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1")->fetch();
if (!$cohort) { echo "(skip: 활성 cohort 없음)\n"; exit(0); }
$cid = (int)$cohort['id'];
$cs  = $cohort['start_date'];
$ce  = $cohort['end_date'];

$today = date('Y-m-d');

// INV-DR-1, 2, 3: clamp + 가드
$r = computeDashboardStats($db, $cid, $cs, $ce, $today, '1900-01-01', '2099-12-31');
t('INV-DR-2 agg_start >= cohort_start', $r['agg_start'] >= $cs);
t('INV-DR-3 agg_end <= today', $r['agg_end'] <= $today);
t('INV-DR-1 agg_start <= agg_end', $r['agg_start'] <= $r['agg_end']);

// INV-DR-4: 결정적
$r1 = computeDashboardStats($db, $cid, $cs, $ce, $today, null, null);
$r2 = computeDashboardStats($db, $cid, $cs, $ce, $today, null, null);
t('INV-DR-4 default 결정적 (agg)',
    $r1['agg_start'] === $r2['agg_start'] && $r1['agg_end'] === $r2['agg_end']);
t('INV-DR-4 default 결정적 (cohort_summary)',
    json_encode($r1['cohort_summary']) === json_encode($r2['cohort_summary']));

// INV-DR-5: score_distribution / score_warnings 는 reqStart/reqEnd 무관
$narrow = computeDashboardStats($db, $cid, $cs, $ce, $today, $today, $today);
$wide   = computeDashboardStats($db, $cid, $cs, $ce, $today, $cs, $today);
t('INV-DR-5 score_distribution invariant',
    json_encode($narrow['score_distribution']) === json_encode($wide['score_distribution']));
t('INV-DR-5 score_warnings invariant',
    json_encode($narrow['score_warnings']) === json_encode($wide['score_warnings']));

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 실행 → 모두 PASS**

Run: `cd /root/boot-dev && php tests/dashboard_date_range_invariants.php`
Expected: 활성 cohort 가 있으면 `7 pass, 0 fail`. 없으면 `(skip: 활성 cohort 없음)` 후 정상 종료.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add tests/dashboard_date_range_invariants.php
git commit -m "test(dashboard): 날짜 범위 인보리언트 (clamp/결정적/score 무관)"
```

---

### Task 5: 프론트엔드 — toolbar 에 date input 추가

**Files:**
- Modify: `public_html/js/bootcamp.js` (`loadDashboard` `renderDashboard` 함수 약 1888~1910 부근)

- [ ] **Step 1: `loadDashboard` toolbar HTML 확장**

`public_html/js/bootcamp.js:1888` 의 `loadDashboard` 함수 본문을 다음으로 교체:

```js
    async function loadDashboard(container) {
        const sec = container || document.getElementById('bc-tab-dashboard');
        if (!sec) return;

        sec.innerHTML = `
            <div class="bc-toolbar mt-md">
                <span class="bc-toolbar-title">대시보드</span>
                <div class="db-daterange">
                    <input type="date" id="db-date-start" class="db-date-input" aria-label="시작일">
                    <span class="db-date-sep">~</span>
                    <input type="date" id="db-date-end" class="db-date-input" aria-label="종료일">
                    <button type="button" class="btn btn-sm btn-secondary" id="db-date-reset">기본값</button>
                </div>
            </div>
            <div id="db-body"><div class="empty-state">로딩 중...</div></div>
        `;

        const startEl = sec.querySelector('#db-date-start');
        const endEl   = sec.querySelector('#db-date-end');
        const resetEl = sec.querySelector('#db-date-reset');

        let debounceTimer = null;
        let lastStart = '';
        let lastEnd = '';

        async function reload() {
            await renderDashboard(sec, { startDate: startEl.value, endDate: endEl.value });
            lastStart = startEl.value;
            lastEnd = endEl.value;
        }

        function schedule() {
            if (startEl.value && endEl.value && startEl.value > endEl.value) {
                if (typeof App !== 'undefined' && App.toast) App.toast('시작일이 종료일보다 이후입니다.', 'error');
                startEl.value = lastStart;
                endEl.value = lastEnd;
                return;
            }
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(reload, 350);
        }

        startEl.addEventListener('change', schedule);
        endEl.addEventListener('change', schedule);
        resetEl.addEventListener('click', () => {
            startEl.value = '';
            endEl.value = '';
            clearTimeout(debounceTimer);
            reload();
        });

        await renderDashboard(sec, {});
        lastStart = startEl.value;
        lastEnd = endEl.value;
    }
```

- [ ] **Step 2: `renderDashboard` 함수 진입부 (1902~1916줄) 만 교체**

대상은 `renderDashboard` 함수의 **선언 + 첫 fetch + 초기 가드** 까지. 함수의 HTML 빌드 부분 (1917줄 이하: `// 색상 클래스 결정` 주석부터 끝까지) 은 **그대로 유지**한다.

`public_html/js/bootcamp.js:1902` 부터 시작하는 다음 블록 (1916줄 `const cs = d.cohort_summary;` 까지):

```js
    async function renderDashboard(sec) {
        const body = sec.querySelector('#db-body') || sec;
        App.showLoading();
        const params = selectedCohortId ? { cohort_id: selectedCohortId } : {};
        const r = await App.get(API + 'dashboard_stats', params);
        App.hideLoading();

        if (!r.success || !r.cohort_summary) {
            body.innerHTML = '<div class="empty-state">아직 채점 기간이 시작되지 않았습니다.</div>';
            return;
        }

        const d = r;
        const cs = d.cohort_summary;
```

전체를 다음으로 **정확히** 교체:

```js
    async function renderDashboard(sec, opts = {}) {
        const body = sec.querySelector('#db-body') || sec;
        const startInput = sec.querySelector('#db-date-start');
        const endInput   = sec.querySelector('#db-date-end');

        App.showLoading();
        const params = selectedCohortId ? { cohort_id: selectedCohortId } : {};
        if (opts.startDate) params.start_date = opts.startDate;
        if (opts.endDate)   params.end_date   = opts.endDate;
        const r = await App.get(API + 'dashboard_stats', params);
        App.hideLoading();

        if (!r.success) {
            body.innerHTML = `<div class="empty-state">${App.esc(r.error || '불러오기 실패')}</div>`;
            return;
        }

        if (startInput && r.agg_start) startInput.value = r.agg_start;
        if (endInput   && r.agg_end)   endInput.value   = r.agg_end;
        if (startInput && r.cohort_start) startInput.min = r.cohort_start;
        if (endInput) endInput.max = new Date().toISOString().slice(0, 10);

        if (!r.cohort_summary) {
            body.innerHTML = '<div class="empty-state">아직 채점 기간이 시작되지 않았습니다.</div>';
            return;
        }

        const d = r;
        const cs = d.cohort_summary;
```

1917줄 이후 함수 본문 (`// 색상 클래스 결정` 주석부터 함수 닫힘 `}` 까지) 은 그대로 유지. Step 3 에서 그 안의 한 줄만 추가 교체한다.

- [ ] **Step 3: 섹션 1 헤더 텍스트 갱신**

같은 파일 1945줄 부근:

```js
            <div class="db-section-title">기수 전체 과제율 <span style="font-weight:normal;font-size:var(--text-xs);color:var(--color-text-muted)">(${d.display_start} ~ ${d.scoring_end}, ${cs.member_count}명)</span></div>
```

다음으로 교체:

```js
            <div class="db-section-title">기수 전체 과제율 <span style="font-weight:normal;font-size:var(--text-xs);color:var(--color-text-muted)">(${d.agg_start} ~ ${d.agg_end}, ${cs.member_count}명)</span></div>
```

- [ ] **Step 4: 적응기간 안내 — `d.scoring_start` 그대로 사용**

같은 파일 1941줄 부근의 적응기간 안내는 변경 없음 (이미 `${d.scoring_start}` 사용 중, 응답에 유지됨):

```js
        if (d.adaptation_active) {
            html += `<div class="notice notice-warning" style="margin:0 0 var(--space-3)">⏳ 적응기간 중 — 감점은 ${d.scoring_start}부터 적용됩니다.</div>`;
        }
```

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev
git add public_html/js/bootcamp.js
git commit -m "feat(dashboard): toolbar 에 시작/종료 date input + 디바운스 fetch"
```

---

### Task 6: CSS — date input 스타일

**Files:**
- Modify: `public_html/css/bootcamp.css`

- [ ] **Step 1: 신규 CSS 블록 추가**

`public_html/css/bootcamp.css` 의 `.bc-toolbar` 블록 다음 (약 350줄 부근, `.bc-toolbar` 관련 룰들 마지막) 에 다음을 추가:

```css
/* Dashboard date range selector */
.db-daterange { display: flex; align-items: center; gap: var(--space-2); margin-left: auto; }
.db-date-input {
    padding: 4px 8px;
    font-size: var(--text-sm);
    border: 1px solid var(--color-border);
    border-radius: 4px;
    background: #fff;
    color: var(--color-text);
}
.db-date-sep { color: var(--color-text-muted); }
@media (max-width: 640px) {
    .bc-toolbar { flex-wrap: wrap; }
    .db-daterange { margin-left: 0; width: 100%; }
    .db-date-input { flex: 1 1 0; min-width: 0; }
}
```

`--color-border`, `--color-text`, `--text-sm`, `--space-2`, `--color-text-muted` 토큰은 `public_html/css/common.css` 에 이미 정의돼 있음.

- [ ] **Step 2: Commit**

```bash
cd /root/boot-dev
git add public_html/css/bootcamp.css
git commit -m "feat(dashboard): date range selector CSS"
```

---

### Task 7: 회귀 검증 + DEV 푸시

**Files:** 변경 없음 (검증/푸시 단계).

- [ ] **Step 1: 단위 테스트 + 인보리언트 전체 PASS**

```bash
cd /root/boot-dev
php tests/dashboard_adaptation_test.php
php tests/dashboard_date_range_test.php
php tests/dashboard_date_range_invariants.php
```

각 마지막 줄 `N pass, 0 fail`.

- [ ] **Step 2: 기존 관련 테스트도 회귀 확인**

```bash
cd /root/boot-dev
php tests/cohort_switch_invariants.php
php tests/qr_auth_invariants.php
```

Expected: 둘 다 PASS (대시보드 변경이 cohort 스위치/QR 인증에 영향 없어야 함).

- [ ] **Step 3: DEV 브라우저 수동 검증**

`https://dev-boot.soritune.com/operation/#dashboard` 접속 후:

- [ ] toolbar 우측에 `[시작일][~][종료일][기본값]` 표시
- [ ] 디폴트로 input 에 scoring_start (또는 적응기간 중 cohort_start) ~ today 값 채워짐
- [ ] 섹션 1 헤더 `(YYYY-MM-DD ~ YYYY-MM-DD, N명)` 동기화
- [ ] 시작일을 cohort_start 로 직접 입력 → 350ms 후 과제율 갱신 (적응기간 포함 모드)
- [ ] 종료일을 어제로 변경 → 갱신 (분모 1일 감소)
- [ ] 시작일 > 종료일 입력 → 토스트 "시작일이 종료일보다 이후입니다." + 값 복원
- [ ] [기본값] 클릭 → 디폴트 복귀
- [ ] 점수 분포·경고 멤버는 날짜 바꿔도 동일 (확인용 비교)
- [ ] F5 새로고침 → 디폴트로 초기화
- [ ] 모바일 폭 (DevTools < 640px) → toolbar 두 줄 wrap

- [ ] **Step 4: 회원 본인 화면 / 코치 체크 페이지 회귀 확인**

`https://dev-boot.soritune.com/study/` 와 `https://dev-boot.soritune.com/coach/` 진입해 빈 화면·콘솔 에러 없는지 한 번 확인 (변경 무관 영역).

- [ ] **Step 5: dev 브랜치 push**

```bash
cd /root/boot-dev
git log --oneline origin/dev..dev
git push origin dev
```

- [ ] **Step 6: 사용자에게 DEV 검증 요청 후 PROD 머지 대기**

이 작업은 boot 배포 플로우에 따라 **dev push 후 멈춤**. 사용자가 "운영 반영해줘" 명시 시에만 main 머지 + prod pull 진행 ([[MEMORY.md]] 부트캠프 배포 플로우).

---

## Self-Review

**Spec coverage 점검:**
- §4 UX 설계 (toolbar, 디폴트, [기본값], 새로고침=디폴트): Task 5, 7 ✓
- §5 데이터 흐름: Task 1, 5 ✓
- §6 백엔드 변경 (시그니처/응답 키/clamp/검증): Task 1 ✓
- §7 프론트엔드 변경: Task 5 ✓
- §7 CSS: Task 6 ✓
- §8 에러 처리: Task 1 (서버 jsonError), Task 5 (JS 토스트+복원) ✓
- §9 단위 테스트: Task 2 ✓
- §9 회귀 (수동): Task 7 Step 3 ✓
- §9 인보리언트: Task 4 ✓
- §10 마이그 0건: 명시됨, 별도 Task 없음 ✓
- §11 롤백: 코드 revert — 별도 Task 없음 ✓

**Placeholder scan:** Task 5 Step 2의 `// (이하 기존 함수 본문 그대로 ...)` 주석은 placeholder 가 아니라 명시적 가이드 (실제 변경은 Step 3·4 한 줄 교체뿐). 다른 placeholder 없음.

**Type consistency:**
- 응답 키 `agg_start`/`agg_end`/`is_default_range`/`default_start`/`default_end`/`cohort_start`/`scoring_start`/`adaptation_active` — Task 1, 2, 3, 4, 5 일관 사용.
- `display_start`/`scoring_end` — Task 1 에서 제거, Task 2·3 단언에서도 제거/금지 단언 추가.
- JS `opts.startDate`/`opts.endDate` ↔ 서버 `start_date`/`end_date` (snake_case) — Task 5 에서 매핑 명시.
