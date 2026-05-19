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
