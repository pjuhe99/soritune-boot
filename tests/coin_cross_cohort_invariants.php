<?php
/**
 * 코인 cross-cohort view 인보리언트
 * 사용: php tests/coin_cross_cohort_invariants.php
 *
 * read-only. PROD/DEV 어디서 돌려도 데이터 변경 없음.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/coin_functions.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; }
    else { $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n"; }
}

$db = getDB();

// ══════════════════════════════════════════════════════════════
// INV-6: findCoinSiblingMemberIds 가 cohort_id < 현재 만 반환
// ══════════════════════════════════════════════════════════════

// 12기 dual-enrollment 회원 1명 표본 (user_id 기준)
$sample = $db->query("
    SELECT user_id, cohort_id, id AS member_id
    FROM bootcamp_members
    WHERE user_id IS NOT NULL AND user_id != ''
      AND cohort_id = (SELECT id FROM cohorts WHERE cohort = '12기' LIMIT 1)
      AND user_id IN (
        SELECT user_id FROM bootcamp_members
        WHERE user_id IS NOT NULL AND user_id != ''
        GROUP BY user_id HAVING COUNT(*) >= 2
      )
    LIMIT 1
")->fetch();

if ($sample) {
    $siblings = findCoinSiblingMemberIds($db, (int)$sample['member_id']);

    t('INV-6 첫 원소가 currentMemberId',
        !empty($siblings) && (int)$siblings[0]['member_id'] === (int)$sample['member_id']);

    $earlierOnly = true;
    foreach ($siblings as $s) {
        if ((int)$s['member_id'] === (int)$sample['member_id']) continue;
        if ((int)$s['cohort_id'] >= (int)$sample['cohort_id']) { $earlierOnly = false; break; }
    }
    t('INV-6 siblings 는 cohort_id < 현재 만', $earlierOnly);

    t('INV-6 dual-enrollment 회원은 sibling ≥ 1건 (자기 외)',
        count($siblings) >= 2);
} else {
    echo "SKIP  INV-6 (dual-enrollment 12기 회원 없음)\n";
}

// user_id 없는 회원 → 자기만 반환
$noUser = $db->query("
    SELECT id FROM bootcamp_members
    WHERE user_id IS NULL OR user_id = ''
    LIMIT 1
")->fetch();
if ($noUser) {
    $siblings = findCoinSiblingMemberIds($db, (int)$noUser['id']);
    t('INV-6 user_id 비어 있으면 자기만', count($siblings) === 1);
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
