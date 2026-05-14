<?php
/**
 * Dashboard 적응기간 노출 — 단위 테스트 (CLI).
 *
 * 사용: php tests/dashboard_adaptation_test.php
 *
 * 사전: DEV DB. 테스트 회원 user_id 는 '__test_dba_%' prefix, cohort code는 '__test_dba'.
 * fixture cohort.start_date 는 고정값 ('2026-01-01') 사용.
 * computeDashboardStats() 의 $todayKST 파라미터로 다양한 시점 시뮬레이션.
 *
 * member_scores 는 last_calculated_at=NOW() 로 미리 박아 ensureScoresFresh 를 no-op 화.
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

// ── 테스트 시작 ──
echo "── Task 1 baseline ──\n";
$fx = setupFixture($db, '2026-01-01');

// today = '2026-01-15' (15일차) — Task 1 기존 동작: scoring_start = 2026-01-04, scoring_end = 2026-01-14, total_days = 11
$r = computeDashboardStats($db, $fx['cohort_id'], $fx['cohort_start'], $fx['cohort_end'], '2026-01-15');
t('baseline: scoring_start = 2026-01-04', ($r['scoring_start'] ?? null) === '2026-01-04');
t('baseline: scoring_end = 2026-01-14', ($r['scoring_end'] ?? null) === '2026-01-14');
t('baseline: total_days = 14', (int)($r['total_days'] ?? -1) === 14);
t('baseline: cohort_summary non-null', !empty($r['cohort_summary']));
t('baseline: member_count = 2', (int)($r['cohort_summary']['member_count'] ?? -1) === 2);

echo "\n── Task 2 adaptation states ──\n";

// 시나리오 A: 1일차 당일 (today == cohort.start_date)
// → scoring_end = yesterday < display_start → cohort_summary null, adaptation_active true
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

teardownFixture($db);
echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
