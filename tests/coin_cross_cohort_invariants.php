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

// 12기 dual-enrollment 회원 1명 표본 (user_id 기준).
// 11기 sibling 이 cycle_11 mcc 를 실제로 보유한 회원으로 한정 — 그렇지 않으면
// "widened mcc branch" 버그가 invariant 에 검출되지 않음.
$sample = $db->query("
    SELECT bm.user_id, bm.cohort_id, bm.id AS member_id
    FROM bootcamp_members bm
    WHERE bm.user_id IS NOT NULL AND bm.user_id != ''
      AND bm.cohort_id = (SELECT id FROM cohorts WHERE cohort = '12기' LIMIT 1)
      AND EXISTS (
        SELECT 1 FROM bootcamp_members bm2
        WHERE bm2.user_id = bm.user_id
          AND bm2.cohort_id < bm.cohort_id
      )
      AND EXISTS (
        SELECT 1 FROM bootcamp_members bm2
        JOIN member_cycle_coins mcc2 ON mcc2.member_id = bm2.id
        JOIN coin_cycles cc2 ON cc2.id = mcc2.cycle_id
        WHERE bm2.user_id = bm.user_id
          AND bm2.cohort_id < bm.cohort_id
          AND cc2.name = '11기'
      )
    LIMIT 1
")->fetch();
if (!$sample) {
    echo "SKIP  INV-6/-5 (12기 dual + 11기 sibling cycle_11 mcc 없음)\n";
}

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


// ══════════════════════════════════════════════════════════════
// INV-5: 12기 chip 시 cycle_11(rg 3) 미포함
// ══════════════════════════════════════════════════════════════

if ($sample) {
    $siblings = findCoinSiblingMemberIds($db, (int)$sample['member_id']);
    $groupIds = getDisplayedRewardGroupIds($db, (int)$sample['member_id'], $siblings);

    // 11기 rg 의 cycle 만 가진 rg 가 displayed 에 들어 있으면 안 됨
    $rg11Only = $db->query("
        SELECT rg.id
        FROM reward_groups rg
        WHERE rg.status = 'open'
          AND NOT EXISTS (
            SELECT 1 FROM coin_cycles cc
            WHERE cc.reward_group_id = rg.id AND cc.name = '12기'
          )
          AND EXISTS (
            SELECT 1 FROM coin_cycles cc
            WHERE cc.reward_group_id = rg.id AND cc.name = '11기'
          )
    ")->fetchAll(PDO::FETCH_COLUMN);

    $intersect = array_intersect($groupIds, $rg11Only);
    t('INV-5 12기 chip displayed_groups 에 rg(11기 only) 미포함',
        empty($intersect),
        '겹치는 rg_id: ' . json_encode(array_values($intersect)));

    // 12기 rg (cycle name='12기' 보유) 는 반드시 displayed 에 있어야
    $rg12 = $db->query("
        SELECT DISTINCT rg.id
        FROM reward_groups rg
        JOIN coin_cycles cc ON cc.reward_group_id = rg.id AND cc.name = '12기'
        WHERE rg.status = 'open'
    ")->fetchAll(PDO::FETCH_COLUMN);
    $missing12 = array_diff($rg12, $groupIds);
    t('INV 12기 chip displayed_groups 에 cycle_12 보유 rg 모두 포함',
        empty($missing12),
        '빠진 rg_id: ' . json_encode(array_values($missing12)));
}

// 11기 chip 회원 — rg 3 + rg 4 모두 displayed
$s11 = $db->query("
    SELECT bm.id FROM bootcamp_members bm
    JOIN cohorts c ON c.id = bm.cohort_id
    JOIN member_cycle_coins mcc ON mcc.member_id = bm.id
    JOIN coin_cycles cc ON cc.id = mcc.cycle_id
    WHERE c.cohort = '11기'
    GROUP BY bm.id
    HAVING COUNT(DISTINCT cc.reward_group_id) >= 2
    LIMIT 1
")->fetch();

if ($s11) {
    $sib11 = findCoinSiblingMemberIds($db, (int)$s11['id']);
    $g11 = getDisplayedRewardGroupIds($db, (int)$s11['id'], $sib11);
    t('INV-3 11기 chip 회원 displayed 개수 ≥ 2 (mcc 보유 rg 모두)',
        count($g11) >= 2);
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
