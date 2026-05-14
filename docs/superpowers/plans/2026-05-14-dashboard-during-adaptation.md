# 어드민 대시보드 — 적응기간(1~3일차) 노출 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** boot 어드민 시스템 대시보드를 기수 1~3일차(적응기간)에도 표시되게 한다. 데이터 집계 시작점을 `scoring_start` → `cohort.start_date`로 옮기고, 적응기간 안내 배지를 본문 최상단에 추가한다.

**Architecture:** `handleDashboardStats()`에서 비즈니스 로직을 `computeDashboardStats($db, $cohortId, $cohortStart, $cohortEnd, $todayKST)`라는 결정적 함수로 분리(=test 가능). 응답에 `display_start`, `adaptation_active` 필드 추가. 프론트는 기존 `notice notice-warning` 컴포넌트를 재사용해 `adaptation_active=true`일 때만 배지 한 줄 렌더링.

**Tech Stack:** PHP 8 + PDO, vanilla JS, CSS tokens (이미 정의된 `notice` 컴포넌트 재사용)

**Spec:** `docs/superpowers/specs/2026-05-14-dashboard-during-adaptation-design.md`

---

## File Map

| 파일 | 변경 / 책임 |
|------|------------|
| `public_html/api/services/dashboard.php` | `computeDashboardStats()` 신규 함수 추출 + `display_start`/`adaptation_active` 응답 필드 + 집계 범위를 `display_start` 기준으로 |
| `public_html/js/bootcamp.js` (`renderDashboard` 함수) | 헤더 라벨 변수 교체, 본문 최상단 적응기간 안내 1줄 삽입 |
| `tests/dashboard_adaptation_test.php` | 신규 CLI 단위 테스트. 픽스처(기수+회원+체크) 생성 후 다양한 `$todayKST`로 `computeDashboardStats()` 직접 호출 |

`bootcamp.css`나 `components.css` 변경 없음 — 기존 `notice notice-warning` 클래스를 그대로 사용.
캐시버스터: 코드베이스가 `v('/path')` 헬퍼로 mtime 기반 자동 갱신이라 별도 bump 불필요.

---

## Task 1: 테스트 스캐폴딩 + 순수 함수 추출 (Pure Refactor)

**Goal:** 동작은 그대로 두고 `computeDashboardStats()` 함수를 분리해서 테스트가 가능하게 한다.

**Files:**
- Modify: `public_html/api/services/dashboard.php` (전체 함수 구조 재배치)
- Create: `tests/dashboard_adaptation_test.php`

- [ ] **Step 1: Create test fixture scaffolding**

`tests/dashboard_adaptation_test.php`를 새로 만든다:

```php
<?php
/**
 * Dashboard 적응기간 노출 — 단위 테스트 (CLI).
 *
 * 사용: php tests/dashboard_adaptation_test.php
 *
 * 사전: DEV DB. 테스트 회원 user_id 는 '__test_dba_%' prefix.
 * fixture cohort.start_date 는 고정값 ('2026-01-01') 사용.
 * computeDashboardStats() 의 $todayKST 파라미터로 다양한 시점 시뮬레이션.
 *
 * member_scores 는 last_calculated_at=오늘로 미리 박아 ensureScoresFresh 를 no-op 화.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
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
    $db->exec("DELETE FROM member_mission_checks WHERE member_id IN (SELECT id FROM bootcamp_members WHERE user_id LIKE '__test_dba_%')");
    $db->exec("DELETE FROM member_scores WHERE member_id IN (SELECT id FROM bootcamp_members WHERE user_id LIKE '__test_dba_%')");
    $db->exec("DELETE FROM bootcamp_members WHERE user_id LIKE '__test_dba_%'");
    $db->exec("DELETE FROM cohorts WHERE code = '__test_dba'");
}

function setupFixture(PDO $db, string $cohortStart, string $cohortEnd = '2099-12-31'): array {
    teardownFixture($db);
    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active) VALUES (?, ?, ?, ?, 1)")
       ->execute(['__test_dba_cohort', '__test_dba', $cohortStart, $cohortEnd]);
    $cohortId = (int)$db->lastInsertId();

    $memberIds = [];
    for ($i = 1; $i <= 2; $i++) {
        $db->prepare("
            INSERT INTO bootcamp_members (cohort_id, real_name, nickname, user_id, member_status, is_active)
            VALUES (?, ?, ?, ?, 'active', 1)
        ")->execute([$cohortId, "테스트{$i}", "test{$i}", "__test_dba_{$i}@k"]);
        $mid = (int)$db->lastInsertId();
        $memberIds[] = $mid;
        $db->prepare("INSERT INTO member_scores (member_id, current_score, last_calculated_at) VALUES (?, 0, NOW())")
           ->execute([$mid]);
    }

    return ['cohort_id' => $cohortId, 'cohort_start' => $cohortStart, 'cohort_end' => $cohortEnd, 'member_ids' => $memberIds];
}

// Task 1 baseline: cohort_end = '2099-12-31' (먼 미래) → scoring_end 분기에서 yesterday 채택, 변경 없음.
}

// ── 테스트 시작 ──
echo "── Task 1 baseline ──\n";
$fx = setupFixture($db, '2026-01-01');

// today = '2026-01-15' (15일차) — 기존 동작: scoring_start = 2026-01-04, scoring_end = 2026-01-14, total_days = 11
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-15');
t('baseline: scoring_start = 2026-01-04', ($r['scoring_start'] ?? null) === '2026-01-04');
t('baseline: scoring_end = 2026-01-14', ($r['scoring_end'] ?? null) === '2026-01-14');
t('baseline: total_days = 11', (int)($r['total_days'] ?? -1) === 11);
t('baseline: cohort_summary non-null', !empty($r['cohort_summary']));
t('baseline: member_count = 2', (int)($r['cohort_summary']['member_count'] ?? -1) === 2);

teardownFixture($db);
echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: Run baseline test to confirm it fails (function not defined yet)**

```bash
php tests/dashboard_adaptation_test.php
```

Expected: FATAL "Call to undefined function computeDashboardStats()" 또는 인자 불일치.

- [ ] **Step 3: Refactor `dashboard.php` — extract `computeDashboardStats()`**

`public_html/api/services/dashboard.php` 전체를 다음 구조로 재작성. **로직은 그대로**, `requireAdmin`/`resolveAdminCohortId`/`jsonError`/`jsonSuccess`만 wrapper로 빼고 나머지 계산을 결정적 함수로 분리한다.

```php
<?php
/**
 * Dashboard Service
 * 기수 전체·조별·멤버별 과제율 및 점수 현황 대시보드
 */

function handleDashboardStats() {
    $admin = requireAdmin();
    $explicit = (int)($_GET['cohort_id'] ?? 0);
    $cohortId = resolveAdminCohortId($explicit ?: null, $admin, false);
    if (!$cohortId) jsonError('활성 기수를 찾을 수 없습니다.');
    $db = getDB();

    $stmt = $db->prepare("SELECT start_date, end_date FROM cohorts WHERE id = ?");
    $stmt->execute([$cohortId]);
    $cohort = $stmt->fetch();
    if (!$cohort) jsonError('기수를 찾을 수 없습니다.');

    $todayKST = date('Y-m-d');
    jsonSuccess(computeDashboardStats($db, $cohortId, $cohort['start_date'], $cohort['end_date'] ?? null, $todayKST));
}

/**
 * 대시보드 통계 산출 (테스트 가능한 결정적 함수).
 *
 * @param PDO    $db
 * @param int    $cohortId
 * @param string $cohortStart  YYYY-MM-DD
 * @param ?string $cohortEnd   YYYY-MM-DD or null
 * @param string $todayKST     YYYY-MM-DD (오늘, KST 기준)
 * @return array  응답 dict (jsonSuccess 의 data 부분)
 */
function computeDashboardStats(PDO $db, int $cohortId, string $cohortStart, ?string $cohortEnd, string $todayKST): array {
    $adaptationEnd = date('Y-m-d', strtotime($cohortStart . ' + ' . (SCORE_ADAPTATION_DAYS - 1) . ' days'));
    $scoringStart  = date('Y-m-d', strtotime($adaptationEnd . ' +1 day'));
    $yesterday     = date('Y-m-d', strtotime($todayKST . ' -1 day'));
    $scoringEnd    = ($cohortEnd && $cohortEnd < $yesterday) ? $cohortEnd : $yesterday;

    // Task 2 에서 변경됨: 현재는 기존 동작 보존을 위해 $scoringStart 사용
    $aggStart = $scoringStart;

    if ($aggStart > $scoringEnd) {
        return [
            'scoring_start' => $scoringStart,
            'scoring_end' => $scoringEnd,
            'total_days' => 0,
            'total_mondays' => 0,
            'cohort_summary' => null,
            'groups' => [],
            'members' => [],
            'score_distribution' => [],
            'score_warnings' => ['approaching' => [], 'revival_eligible' => [], 'out' => []],
        ];
    }

    $totalDays = 0;
    $totalMondays = 0;
    $current = $aggStart;
    while ($current <= $scoringEnd) {
        $totalDays++;
        if ((int)date('w', strtotime($current)) === 1) $totalMondays++;
        $current = date('Y-m-d', strtotime($current . ' +1 day'));
    }

    ensureScoresFresh($db, $cohortId);

    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
               bm.group_id, bm.member_status, bg.name AS group_name,
               COALESCE(ms.current_score, 0) AS current_score
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        WHERE bm.cohort_id = ? AND bm.is_active = 1
        ORDER BY bg.name, bm.nickname
    ");
    $stmt->execute([$cohortId]);
    $members = $stmt->fetchAll();
    $memberIds = array_column($members, 'id');

    if (empty($memberIds)) {
        return [
            'scoring_start' => $scoringStart,
            'scoring_end' => $scoringEnd,
            'total_days' => $totalDays,
            'total_mondays' => $totalMondays,
            'cohort_summary' => null,
            'groups' => [],
            'members' => [],
            'score_distribution' => [],
            'score_warnings' => ['approaching' => [], 'revival_eligible' => [], 'out' => []],
        ];
    }

    $codeToId = getMissionCodeToIdMap($db);
    $zoomId = $codeToId['zoom_daily'] ?? null;
    $dailyId = $codeToId['daily_mission'] ?? null;
    $inner33Id = $codeToId['inner33'] ?? null;
    $speakId = $codeToId['speak_mission'] ?? null;
    $bookOpenId = $codeToId['bookclub_open'] ?? null;
    $bookJoinId = $codeToId['bookclub_join'] ?? null;
    $hamemmalId = $codeToId['hamemmal'] ?? null;

    $ph = implode(',', array_fill(0, count($memberIds), '?'));
    $stmt = $db->prepare("
        SELECT member_id, check_date, mission_type_id, status
        FROM member_mission_checks
        WHERE member_id IN ({$ph})
          AND check_date BETWEEN ? AND ?
    ");
    $stmt->execute(array_merge($memberIds, [$aggStart, $scoringEnd]));

    $checkData = [];
    foreach ($stmt->fetchAll() as $c) {
        $checkData[(int)$c['member_id']][$c['check_date']][(int)$c['mission_type_id']] = (int)$c['status'];
    }

    $memberResults = [];
    $groupAgg = [];

    foreach ($members as $m) {
        $mid = (int)$m['id'];
        $gid = $m['group_id'] ? (int)$m['group_id'] : 0;
        $byDate = $checkData[$mid] ?? [];

        $zoomDone = 0;
        $inner33Done = 0;
        $speakDone = 0;
        $bookOpenCount = 0;
        $bookJoinCount = 0;
        $hamemmalCount = 0;

        $cur = $aggStart;
        while ($cur <= $scoringEnd) {
            $missions = $byDate[$cur] ?? [];
            $dow = (int)date('w', strtotime($cur));

            $zoomPass = false;
            if ($zoomId && ($missions[$zoomId] ?? 0) === 1) $zoomPass = true;
            if ($dailyId && ($missions[$dailyId] ?? 0) === 1) $zoomPass = true;
            if ($zoomPass) $zoomDone++;

            if ($inner33Id && ($missions[$inner33Id] ?? 0) === 1) $inner33Done++;
            if ($dow === 1 && $speakId && ($missions[$speakId] ?? 0) === 1) $speakDone++;
            if ($bookOpenId && ($missions[$bookOpenId] ?? 0) === 1) $bookOpenCount++;
            if ($bookJoinId && ($missions[$bookJoinId] ?? 0) === 1) $bookJoinCount++;
            if ($hamemmalId && ($missions[$hamemmalId] ?? 0) === 1) $hamemmalCount++;

            $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
        }

        $zoomRate = $totalDays > 0 ? round($zoomDone / $totalDays * 100, 1) : 0;
        $inner33Rate = $totalDays > 0 ? round($inner33Done / $totalDays * 100, 1) : 0;
        $speakRate = $totalMondays > 0 ? round($speakDone / $totalMondays * 100, 1) : 0;
        $avgRate = round(($zoomRate + $inner33Rate + $speakRate) / 3, 1);

        $memberResult = [
            'id' => $mid,
            'nickname' => $m['nickname'],
            'real_name' => $m['real_name'],
            'group_id' => $gid,
            'group_name' => $m['group_name'] ?? '',
            'member_role' => $m['member_role'],
            'current_score' => (int)$m['current_score'],
            'member_status' => $m['member_status'],
            'required' => [
                'zoom_daily' => ['done' => $zoomDone, 'total' => $totalDays, 'rate' => $zoomRate],
                'inner33' => ['done' => $inner33Done, 'total' => $totalDays, 'rate' => $inner33Rate],
                'speak_mission' => ['done' => $speakDone, 'total' => $totalMondays, 'rate' => $speakRate],
                'avg_rate' => $avgRate,
            ],
            'optional' => [
                'bookclub_open' => $bookOpenCount,
                'bookclub_join' => $bookJoinCount,
                'hamemmal' => $hamemmalCount,
            ],
        ];
        $memberResults[] = $memberResult;

        if (!isset($groupAgg[$gid])) {
            $groupAgg[$gid] = [
                'id' => $gid,
                'name' => $m['group_name'] ?? '미배정',
                'member_count' => 0,
                'zoom_sum' => 0, 'inner33_sum' => 0, 'speak_sum' => 0,
                'book_open_sum' => 0, 'book_join_sum' => 0, 'hamemmal_sum' => 0,
            ];
        }
        $groupAgg[$gid]['member_count']++;
        $groupAgg[$gid]['zoom_sum'] += $zoomDone;
        $groupAgg[$gid]['inner33_sum'] += $inner33Done;
        $groupAgg[$gid]['speak_sum'] += $speakDone;
        $groupAgg[$gid]['book_open_sum'] += $bookOpenCount;
        $groupAgg[$gid]['book_join_sum'] += $bookJoinCount;
        $groupAgg[$gid]['hamemmal_sum'] += $hamemmalCount;
    }

    $groupIds = array_keys($groupAgg);
    $coachMap = [];
    if ($groupIds) {
        $gph = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $db->prepare("
            SELECT cga.group_id, a.name
            FROM coach_group_assignments cga
            JOIN admins a ON cga.admin_id = a.id AND a.is_active = 1
            WHERE cga.group_id IN ({$gph})
            ORDER BY a.name
        ");
        $stmt->execute($groupIds);
        foreach ($stmt->fetchAll() as $row) {
            $gid = (int)$row['group_id'];
            $coachMap[$gid] = isset($coachMap[$gid]) ? $coachMap[$gid] . ', ' . $row['name'] : $row['name'];
        }
    }

    $groupResults = [];
    foreach ($groupAgg as $g) {
        $mc = $g['member_count'];
        $groupResults[] = [
            'id' => $g['id'],
            'name' => $g['name'],
            'coach' => $coachMap[$g['id']] ?? '',
            'member_count' => $mc,
            'zoom_daily_rate' => $zdr = ($mc * $totalDays > 0) ? round($g['zoom_sum'] / ($mc * $totalDays) * 100, 1) : 0,
            'inner33_rate' => $i3r = ($mc * $totalDays > 0) ? round($g['inner33_sum'] / ($mc * $totalDays) * 100, 1) : 0,
            'speak_rate' => $spr = ($mc * $totalMondays > 0) ? round($g['speak_sum'] / ($mc * $totalMondays) * 100, 1) : 0,
            'avg_rate' => round(($zdr + $i3r + $spr) / 3, 1),
            'optional_avg' => [
                'bookclub_open' => $mc > 0 ? round($g['book_open_sum'] / $mc, 1) : 0,
                'bookclub_join' => $mc > 0 ? round($g['book_join_sum'] / $mc, 1) : 0,
                'hamemmal' => $mc > 0 ? round($g['hamemmal_sum'] / $mc, 1) : 0,
            ],
        ];
    }
    usort($groupResults, fn($a, $b) => strcmp($a['name'], $b['name']));

    $totalMembers = count($members);
    $cohortZoomSum = array_sum(array_column($groupAgg, 'zoom_sum'));
    $cohortInner33Sum = array_sum(array_column($groupAgg, 'inner33_sum'));
    $cohortSpeakSum = array_sum(array_column($groupAgg, 'speak_sum'));
    $cohortBookOpenSum = array_sum(array_column($groupAgg, 'book_open_sum'));
    $cohortBookJoinSum = array_sum(array_column($groupAgg, 'book_join_sum'));
    $cohortHamemmalSum = array_sum(array_column($groupAgg, 'hamemmal_sum'));

    $cohortSummary = [
        'member_count' => $totalMembers,
        'zoom_daily_rate' => $csZdr = ($totalMembers * $totalDays > 0) ? round($cohortZoomSum / ($totalMembers * $totalDays) * 100, 1) : 0,
        'inner33_rate' => $csI3r = ($totalMembers * $totalDays > 0) ? round($cohortInner33Sum / ($totalMembers * $totalDays) * 100, 1) : 0,
        'speak_rate' => $csSpr = ($totalMembers * $totalMondays > 0) ? round($cohortSpeakSum / ($totalMembers * $totalMondays) * 100, 1) : 0,
        'avg_rate' => round(($csZdr + $csI3r + $csSpr) / 3, 1),
        'optional_avg' => [
            'bookclub_open' => $totalMembers > 0 ? round($cohortBookOpenSum / $totalMembers, 1) : 0,
            'bookclub_join' => $totalMembers > 0 ? round($cohortBookJoinSum / $totalMembers, 1) : 0,
            'hamemmal' => $totalMembers > 0 ? round($cohortHamemmalSum / $totalMembers, 1) : 0,
        ],
    ];

    $scoreBuckets = [
        ['range' => '0 ~ -4', 'min' => -4, 'max' => 0, 'count' => 0],
        ['range' => '-5 ~ -9', 'min' => -9, 'max' => -5, 'count' => 0],
        ['range' => '-10 ~ -14', 'min' => -14, 'max' => -10, 'count' => 0],
        ['range' => '-15 ~ -19', 'min' => -19, 'max' => -15, 'count' => 0],
        ['range' => '-20 ~ -24', 'min' => -24, 'max' => -20, 'count' => 0],
        ['range' => '-25 이하', 'min' => -9999, 'max' => -25, 'count' => 0],
    ];
    foreach ($members as $m) {
        $score = (int)$m['current_score'];
        foreach ($scoreBuckets as &$bucket) {
            if ($score >= $bucket['min'] && $score <= $bucket['max']) {
                $bucket['count']++;
                break;
            }
        }
        unset($bucket);
    }
    $scoreDistribution = array_map(fn($b) => ['range' => $b['range'], 'count' => $b['count']], $scoreBuckets);

    $approaching = [];
    $revivalEligible = [];
    $out = [];
    foreach ($memberResults as $mr) {
        $score = $mr['current_score'];
        $info = ['id' => $mr['id'], 'nickname' => $mr['nickname'], 'group_name' => $mr['group_name'], 'current_score' => $score];
        if ($score <= SCORE_OUT_THRESHOLD) {
            $out[] = $info;
        } elseif ($score <= SCORE_REVIVAL_ELIGIBLE) {
            $revivalEligible[] = $info;
        } elseif ($score <= SCORE_REVIVAL_CANDIDATE) {
            $approaching[] = $info;
        }
    }

    return [
        'scoring_start' => $scoringStart,
        'scoring_end' => $scoringEnd,
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
}
```

**핵심 변경점:**
- `handleDashboardStats()`는 auth + cohort row 조회만 담당. 나머지를 `computeDashboardStats()`로 위임
- `computeDashboardStats()`는 PDO + cohortId + cohort dates + **`$todayKST`** 를 받아 결정적으로 계산
- 모든 `date('Y-m-d', strtotime('-1 day'))` → `date('Y-m-d', strtotime($todayKST . ' -1 day'))` 로 변경
- 두 곳의 early-return을 `return [...]` (jsonSuccess 호출 제거)으로 바꿔 순수 함수화
- 마지막 `jsonSuccess([...])` → `return [...]`
- `$aggStart = $scoringStart` 변수 도입 (Task 2에서 $cohortStart로 바뀜)

- [ ] **Step 4: Run baseline test — should PASS**

```bash
php tests/dashboard_adaptation_test.php
```

Expected:
```
── Task 1 baseline ──
PASS  baseline: scoring_start = 2026-01-04
PASS  baseline: scoring_end = 2026-01-14
PASS  baseline: total_days = 11
PASS  baseline: cohort_summary non-null
PASS  baseline: member_count = 2

5 pass, 0 fail
```

- [ ] **Step 5: Smoke test handleDashboardStats unchanged (manual)**

DEV 어드민 화면에서 시스템 → 대시보드 클릭. 이전과 동일한 화면이 떠야 함 (12기 데이터, 동일 라벨/통계).

- [ ] **Step 6: Commit**

```bash
git add public_html/api/services/dashboard.php tests/dashboard_adaptation_test.php
git commit -m "refactor(dashboard): extract pure computeDashboardStats() for testing"
```

---

## Task 2: 적응기간 노출 — display_start, adaptation_active, 집계 시작점 변경

**Goal:** TDD로 1~3일차 노출 + 4일차 변경 안 됨 동시 검증, 한 번에 구현.

**Files:**
- Modify: `public_html/api/services/dashboard.php`
- Modify: `tests/dashboard_adaptation_test.php`

- [ ] **Step 1: 실패 테스트 추가 (5개 시나리오)**

`tests/dashboard_adaptation_test.php`의 baseline 직후, `teardownFixture` 직전에 다음 블록을 삽입:

```php
echo "\n── Task 2 adaptation states ──\n";

// 시나리오 A: 1일차 당일 (today == cohort.start_date)
// → scoring_end = yesterday < display_start → cohort_summary null, adaptation_active true
$fx = setupFixture($db, '2026-01-01');
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-01');
t('A 1일차: display_start = 2026-01-01', ($r['display_start'] ?? null) === '2026-01-01');
t('A 1일차: scoring_start = 2026-01-04', ($r['scoring_start'] ?? null) === '2026-01-04');
t('A 1일차: adaptation_active = true', ($r['adaptation_active'] ?? null) === true);
t('A 1일차: cohort_summary = null', $r['cohort_summary'] === null);
t('A 1일차: total_days = 0', (int)($r['total_days'] ?? -1) === 0);

// 시나리오 B: 2일차 (today = start + 1)
// → display_start ~ yesterday = start ~ start (1일), cohort_summary non-null, adaptation_active true
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-02');
t('B 2일차: display_start = 2026-01-01', ($r['display_start'] ?? null) === '2026-01-01');
t('B 2일차: adaptation_active = true', ($r['adaptation_active'] ?? null) === true);
t('B 2일차: cohort_summary non-null', !empty($r['cohort_summary']));
t('B 2일차: total_days = 1', (int)($r['total_days'] ?? -1) === 1);

// 시나리오 C: 3일차 (today = start + 2)
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-03');
t('C 3일차: adaptation_active = true', ($r['adaptation_active'] ?? null) === true);
t('C 3일차: total_days = 2', (int)($r['total_days'] ?? -1) === 2);

// 시나리오 D: 4일차 = scoring_start (today = start + 3) — 경계
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-04');
t('D 4일차: adaptation_active = false', ($r['adaptation_active'] ?? null) === false);
t('D 4일차: total_days = 3', (int)($r['total_days'] ?? -1) === 3);

// 시나리오 E: 5일차 이후 정상 (today = start + 4)
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-05');
t('E 5일차: adaptation_active = false', ($r['adaptation_active'] ?? null) === false);
t('E 5일차: display_start = 2026-01-01 (start)', ($r['display_start'] ?? null) === '2026-01-01');
t('E 5일차: total_days = 4', (int)($r['total_days'] ?? -1) === 4);

// 시나리오 F: 기존 baseline 시점도 adaptation_active = false 인지 확인 (regression)
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-15');
t('F baseline regression: adaptation_active = false', ($r['adaptation_active'] ?? null) === false);
t('F baseline regression: total_days = 14 (display_start 기준으로 늘어남)', (int)($r['total_days'] ?? -1) === 14);
```

**baseline 회귀 메모:** Task 1의 baseline은 `total_days = 11`을 기대했음 (scoring_start ~ yesterday). Task 2 후엔 display_start ~ yesterday = 14일. baseline 테스트 1줄도 같이 갱신해야 함.

baseline의 `total_days = 11` → `total_days = 14`로, `scoring_start = 2026-01-04` 라인은 유지(스펙상 scoring_start 의미는 그대로). 추가로:

```php
// baseline에서:
t('baseline: total_days = 14', (int)($r['total_days'] ?? -1) === 14);  // 11 → 14
```

- [ ] **Step 2: Run tests — Task 2 시나리오들이 실패해야 함**

```bash
php tests/dashboard_adaptation_test.php
```

Expected: 신규 추가 시나리오 A~F 다수 FAIL. `display_start`/`adaptation_active` 미정의로 null 비교 실패.

- [ ] **Step 3: `dashboard.php` 구현 변경**

`computeDashboardStats()` 안에서:

(a) `$aggStart = $scoringStart;` 라인을 다음으로 교체:

```php
$displayStart     = $cohortStart;
$adaptationActive = $todayKST < $scoringStart;
$aggStart         = $displayStart;
```

(b) 첫 early-return `if ($aggStart > $scoringEnd)` 블록의 반환 배열에 키 추가:

```php
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
```

(c) 두 번째 early-return `if (empty($memberIds))` 블록의 반환 배열에도 동일하게 `display_start` / `adaptation_active` 키 추가.

(d) 마지막 정상 return 배열에도 동일하게 추가:

```php
return [
    'display_start' => $displayStart,
    'scoring_start' => $scoringStart,
    'scoring_end' => $scoringEnd,
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

`$aggStart = $displayStart`이므로 SELECT WHERE BETWEEN과 집계 루프가 자동으로 `display_start` 기준이 됨. 별도 추가 변경 없음.

- [ ] **Step 4: Run tests — 모두 PASS**

```bash
php tests/dashboard_adaptation_test.php
```

Expected:
```
── Task 1 baseline ──
PASS  baseline: scoring_start = 2026-01-04
PASS  baseline: scoring_end = 2026-01-14
PASS  baseline: total_days = 14
PASS  baseline: cohort_summary non-null
PASS  baseline: member_count = 2

── Task 2 adaptation states ──
PASS  A 1일차: display_start = 2026-01-01
PASS  A 1일차: scoring_start = 2026-01-04
PASS  A 1일차: adaptation_active = true
PASS  A 1일차: cohort_summary = null
PASS  A 1일차: total_days = 0
PASS  B 2일차: display_start = 2026-01-01
PASS  B 2일차: adaptation_active = true
PASS  B 2일차: cohort_summary non-null
PASS  B 2일차: total_days = 1
PASS  C 3일차: adaptation_active = true
PASS  C 3일차: total_days = 2
PASS  D 4일차: adaptation_active = false
PASS  D 4일차: total_days = 3
PASS  E 5일차: adaptation_active = false
PASS  E 5일차: display_start = 2026-01-01 (start)
PASS  E 5일차: total_days = 4
PASS  F baseline regression: adaptation_active = false
PASS  F baseline regression: total_days = 14 (display_start 기준으로 늘어남)

20 pass, 0 fail
```

- [ ] **Step 5: Commit**

```bash
git add public_html/api/services/dashboard.php tests/dashboard_adaptation_test.php
git commit -m "feat(dashboard): 적응기간(1~3일차)에도 노출 + display_start/adaptation_active"
```

---

## Task 3: 프론트 — 라벨 변경 + 적응기간 안내 배지

**Goal:** `renderDashboard()`에서 헤더 라벨을 `display_start` 기준으로 바꾸고, `adaptation_active=true`이면 본문 최상단에 안내 1줄 추가.

**Files:**
- Modify: `public_html/js/bootcamp.js` (1902~1968 부근, `renderDashboard()`)

- [ ] **Step 1: 헤더 라벨 변수 교체 + 안내 배지 삽입**

`renderDashboard()` 함수에서:

(a) **변경 전 (L1940):**

```javascript
html += `
    <div class="db-section-title">기수 전체 과제율 <span style="font-weight:normal;font-size:var(--text-xs);color:var(--color-text-muted)">(${d.scoring_start} ~ ${d.scoring_end}, ${cs.member_count}명)</span></div>
```

**변경 후:**

```javascript
// 적응기간 안내 (감점은 아직 미적용)
if (d.adaptation_active) {
    html += `<div class="notice notice-warning" style="margin:0 0 var(--space-3)">⏳ 적응기간 중 — 감점은 ${d.scoring_start}부터 적용됩니다.</div>`;
}

html += `
    <div class="db-section-title">기수 전체 과제율 <span style="font-weight:normal;font-size:var(--text-xs);color:var(--color-text-muted)">(${d.display_start} ~ ${d.scoring_end}, ${cs.member_count}명)</span></div>
```

라벨의 `${d.scoring_start}` → `${d.display_start}` 한 곳만 교체. 안내 배지는 라벨 위에 추가.

- [ ] **Step 2: DEV 수동 확인 — 정상 시점 (regression)**

`https://dev-boot.soritune.com/operation/` → admin 로그인 → 시스템 → 대시보드.

확인:
- 12기 (적응기간 지남) → 적응기간 배지 **안 보임**
- 헤더 라벨이 기존과 동일하게 `(YYYY-MM-DD ~ YYYY-MM-DD, N명)` 형태로 표시 (단, 시작일은 cohort.start_date로 바뀌어 이전보다 3일 앞당겨짐)
- 표/조별/멤버별 카드/점수 분포/경고 정상

- [ ] **Step 3: DEV 수동 확인 — 적응기간 시뮬레이션**

DEV DB에서 12기의 `start_date`를 임시로 오늘 또는 어제로 1줄 UPDATE:

```sql
-- DEV ONLY
UPDATE cohorts SET start_date = CURDATE() WHERE id = <12기 id>;
```

확인:
- 어드민 시스템 → 대시보드 새로고침
- 1일차 당일이면 (오늘=start_date) → 본문 자체가 비어 "아직 채점 기간이 시작되지 않았습니다" (배지는 본문이 비어서 안 보임)
- start_date = 어제로 바꾸면 (2일차) → 적응기간 배지 + 본문 표시, 라벨 = `(어제 ~ 어제, N명)`
- start_date = 오늘-3 (4일차) → 배지 사라짐 (adaptation_active=false), 라벨 = `(오늘-3 ~ 어제, N명)`

검증 후 **원래 start_date로 즉시 복구**:

```sql
UPDATE cohorts SET start_date = '<원래 날짜>' WHERE id = <12기 id>;
```

- [ ] **Step 4: Commit**

```bash
git add public_html/js/bootcamp.js
git commit -m "feat(dashboard): 프론트 적응기간 안내 + display_start 라벨"
```

---

## Task 4: dev push + 사용자 확인 게이트

**Goal:** dev 브랜치에 푸시 후 사용자 DEV 확인 대기. 사용자가 운영 반영을 명시할 때까지 main 머지 X.

**Files:** (없음)

- [ ] **Step 1: 푸시**

```bash
git push origin dev
```

- [ ] **Step 2: 사용자 확인 요청**

다음 메시지로 사용자에게 안내:

> DEV 푸시 완료. `dev-boot.soritune.com/operation` → 시스템 → 대시보드에서 확인 부탁드립니다.
> - 12기(채점 중): 라벨 시작일이 cohort.start_date로 바뀌고 적응기간 배지 없음
> - 적응기간 시뮬레이션 필요하시면 알려주세요 (12기 start_date 임시 UPDATE)
>
> 운영 반영하시려면 "운영 반영해줘" 명시해주세요.

- [ ] **Step 3 (게이트): 사용자가 "운영 반영해줘" 등 명시한 경우에만 진행**

```bash
git checkout main
git merge dev
git push origin main
git checkout dev
```

운영 서버:

```bash
cd /root/boot-prod && git pull origin main
```

- [ ] **Step 4: PROD 스모크**

`boot.soritune.com/operation/` → 어드민 로그인 → 시스템 → 대시보드.

확인:
- 12기 적응기간 배지 없음
- 라벨이 `(cohort.start_date ~ yesterday, N명)`
- 통계/조별/멤버/점수 분포/경고 정상

PROD에서도 적응기간 시뮬레이션을 시도하려면 위와 동일하게 12기 start_date 임시 UPDATE → 즉시 복구. **운영자의 실제 사용 흐름을 깨지 않도록 1~2분 내 복구 필수.** (또는 시뮬레이션 생략 — PROD 회귀는 "현재 운영 중 기수가 변경 없이 동일하게 보임"으로 충분.)

---

## Self-Review Notes

- **Spec coverage:**
  - §3.1 모든 날짜 KST, display_start/scoring_start/adaptation_end/scoring_end/adaptation_active 정의 → Task 1·2 모두 반영
  - §3.2 시점별 동작 5케이스 → Task 2 시나리오 A~F 매핑 (1일차/2일차/3일차/4일차/5일차/15일차)
  - §3.3 응답 스키마 → Task 2 Step 3에서 세 return 경로 모두 갱신
  - §3.4 프론트 변경 → Task 3
  - §3.5 score/warning 적응기간 동작 → 별도 변경 없음(자연스레 0점 클러스터). Task 3 Step 3 검증에서 확인 가능
  - §5 테스트 → Task 2 시나리오로 5.1 자동 테스트 커버. 5.2 DEV 수동은 Task 3 Step 3
  - §6 엣지 → 1일차 당일 / cohort.end_date null / chip 다른 기수 — 모두 코드 경로 변경 없이 자연스레 처리됨
  - §7 배포 → Task 4
  - **비-목표(§8)**는 의도적으로 제외

- **Placeholder scan:** TBD/TODO 없음. 모든 step 코드 인라인.

- **Type consistency:** `computeDashboardStats($db, $cohortId, $cohortStart, $cohortEnd, $todayKST)` 시그니처 Task 1·2 일치. `display_start`/`adaptation_active` 응답 key 이름 일치.

- **CSS:** 스펙에서는 `.db-adaptation-notice` 신규 클래스를 언급했으나, 실측 결과 `notice notice-warning` 컴포넌트가 이미 존재 → 신규 클래스 추가 대신 재사용 (Plan 우선).

- **캐시버스터:** `v('/path')` 헬퍼가 mtime 기반 자동 갱신 → 별도 bump 없음.
