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
