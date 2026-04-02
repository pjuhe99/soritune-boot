<?php
/**
 * boot.soritune.com - 복습스터디 개설 의무 소급 정산
 *
 * 조장(4회)/부조장(2회) 의무 개설분에 대해 이미 지급된 코인을 회수하고
 * study_open_count를 보정합니다.
 *
 * 실행: php fix_study_open_duty.php [--dry-run]
 */

require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/includes/coin_functions.php';
require_once __DIR__ . '/public_html/includes/bootcamp_functions.php';

$dryRun = in_array('--dry-run', $argv);

echo "=== 복습스터디 개설 의무 소급 정산 ===\n";
echo $dryRun ? "[DRY-RUN 모드]\n\n" : "[실제 적용 모드]\n\n";

$db = getDB();

// 1. active cycle 조회
$cycle = getActiveCycle($db);
if (!$cycle) {
    echo "활성 사이클이 없습니다.\n";
    exit(1);
}

$cycleId    = (int)$cycle['id'];
$startDate  = $cycle['start_date'];
$endDate    = $cycle['end_date'];

echo "사이클: {$cycle['name']} ({$startDate} ~ {$endDate})\n\n";

// 2. 조장/부조장 목록
$stmt = $db->prepare("
    SELECT bm.id, bm.nickname, bm.real_name, bm.member_role,
           COALESCE(mcc.study_open_count, 0) AS study_open_count,
           COALESCE(mcc.earned_coin, 0) AS earned_coin
    FROM bootcamp_members bm
    LEFT JOIN member_cycle_coins mcc ON bm.id = mcc.member_id AND mcc.cycle_id = ?
    WHERE bm.is_active = 1 AND bm.member_status != 'withdrawn'
      AND bm.member_role IN ('leader', 'subleader')
    ORDER BY bm.member_role, bm.nickname
");
$stmt->execute([$cycleId]);
$members = $stmt->fetchAll();

if (empty($members)) {
    echo "대상 조장/부조장이 없습니다.\n";
    exit(0);
}

// 3. bookclub_open mission_type_id
$codeToId = getMissionCodeToIdMap($db);
$openTypeId = $codeToId['bookclub_open'] ?? null;
if (!$openTypeId) {
    echo "bookclub_open 미션 타입을 찾을 수 없습니다.\n";
    exit(1);
}

// 4. 소급 정산
$totalRevoked = 0;
$totalMembers = 0;

foreach ($members as $m) {
    $mid  = (int)$m['id'];
    $role = $m['member_role'];
    $duty = ($role === 'leader') ? COIN_STUDY_OPEN_DUTY_LEADER : COIN_STUDY_OPEN_DUTY_SUBLEADER;
    $currentOpenCount = (int)$m['study_open_count'];

    // 사이클 내 실제 bookclub_open 체크 수
    $chkStmt = $db->prepare("
        SELECT COUNT(*) FROM member_mission_checks
        WHERE member_id = ? AND mission_type_id = ? AND status = 1
          AND check_date >= ? AND check_date <= ?
    ");
    $chkStmt->execute([$mid, $openTypeId, $startDate, $endDate]);
    $totalChecks = (int)$chkStmt->fetchColumn();

    // 코인을 받으면 안 되는 횟수 = min(의무, 총 체크수) — 의무 이내 개설분
    // 실제 보정해야 할 횟수 = min(의무, 총 체크수) 와 현재 open_count의 차이
    // 예: 조장이 6번 열고 6번 코인 받음 → 의무4 → 정상은 2번만 받아야 → 4번분 회수
    $correctOpenCount = max(0, $totalChecks - $duty);
    $overCount = $currentOpenCount - $correctOpenCount;

    if ($overCount <= 0) {
        continue; // 보정 불필요
    }

    $revokeAmount = $overCount * COIN_STUDY_OPEN_AMOUNT;

    echo sprintf(
        "[%s] %s (%s) — 역할: %s, 의무: %d회, 총 개설: %d회, 지급된 코인횟수: %d → 정상: %d (-%d회, -%d코인)\n",
        $dryRun ? 'DRY' : 'FIX',
        $m['nickname'],
        $m['real_name'] ?: '-',
        $role,
        $duty,
        $totalChecks,
        $currentOpenCount,
        $correctOpenCount,
        $overCount,
        $revokeAmount
    );

    if (!$dryRun) {
        // 코인 차감
        applyCoinChange($db, $mid, $cycleId, -$revokeAmount, 'study_open',
            "의무개설 소급정산: {$role} 의무{$duty}회, {$overCount}회분 회수", null);

        // study_open_count 보정
        $db->prepare("
            UPDATE member_cycle_coins SET study_open_count = ?
            WHERE member_id = ? AND cycle_id = ?
        ")->execute([$correctOpenCount, $mid, $cycleId]);
    }

    $totalRevoked += $revokeAmount;
    $totalMembers++;
}

echo "\n=== 결과 ===\n";
echo "대상: {$totalMembers}명\n";
echo "총 회수 코인: {$totalRevoked}\n";
echo $dryRun ? "\n→ --dry-run 제거 후 재실행하면 실제 적용됩니다.\n" : "\n→ 적용 완료\n";
