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

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
