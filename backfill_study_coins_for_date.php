<?php
/**
 * boot.soritune.com - 특정 날짜 복습스터디 코인 backfill
 *
 * 미션 체크(bookclub_open / bookclub_join) status=1 인데
 * coin_logs 에 발급 기록이 없는 회원에게 소급 발급.
 *
 * processCoinForCheck() 의 cap / duty 가드를 그대로 재현. 멱등.
 *
 * 실행: php backfill_study_coins_for_date.php --date=YYYY-MM-DD [--dry-run]
 *   --date 생략 시 오늘(KST) 기준
 *
 * 사용 예: php backfill_study_coins_for_date.php --date=2026-05-18 --dry-run
 *         php backfill_study_coins_for_date.php --date=2026-05-18
 */

require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/includes/coin_functions.php';

$dryRun = in_array('--dry-run', $argv);
$date   = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--date=')) {
        $date = substr($a, 7);
    }
}
if (!$date) {
    $date = (new DateTime('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "[ERROR] 날짜 형식 오류: {$date}\n");
    exit(1);
}

echo "=== 복습스터디 코인 backfill ===\n";
echo "대상 날짜: {$date}\n";
echo $dryRun ? "[DRY-RUN]\n\n" : "[실제 적용]\n\n";

$db = getDB();

// cycle 조회
$cycle = getCycleForDate($db, $date);
if (!$cycle) {
    fwrite(STDERR, "[ERROR] {$date} 를 포함하는 coin_cycle 이 없음. cycle.end_date 를 먼저 연장하세요.\n");
    exit(1);
}
$cycleId = (int)$cycle['id'];
echo "Cycle: id={$cycleId} name={$cycle['name']} ({$cycle['start_date']} ~ {$cycle['end_date']})\n\n";

// mission_type_id 매핑
$codes = ['bookclub_open', 'bookclub_join'];
$placeholders = implode(',', array_fill(0, count($codes), '?'));
$stmt = $db->prepare("SELECT id, code FROM mission_types WHERE code IN ({$placeholders})");
$stmt->execute($codes);
$codeToTypeId = [];
$typeIdToCode = [];
foreach ($stmt->fetchAll() as $r) {
    $codeToTypeId[$r['code']] = (int)$r['id'];
    $typeIdToCode[(int)$r['id']] = $r['code'];
}
foreach ($codes as $c) {
    if (!isset($codeToTypeId[$c])) {
        fwrite(STDERR, "[ERROR] mission_types.code={$c} 없음.\n");
        exit(1);
    }
}

// 해당 날짜 status=1 체크 조회
$stmt = $db->prepare("
    SELECT mmc.member_id, mmc.mission_type_id, mt.code AS mission_code
    FROM member_mission_checks mmc
    JOIN mission_types mt ON mt.id = mmc.mission_type_id
    WHERE mmc.check_date = ?
      AND mt.code IN ('bookclub_open','bookclub_join')
      AND mmc.status = 1
    ORDER BY mt.code, mmc.member_id
");
$stmt->execute([$date]);
$checks = $stmt->fetchAll();

echo "status=1 체크 행: " . count($checks) . " 건\n\n";

// 이미 발급된 (member, code, date) 조회 (멱등)
$stmt = $db->prepare("
    SELECT member_id, reason_type, reason_detail
    FROM coin_logs
    WHERE reason_type IN ('study_open','study_join')
      AND reason_detail LIKE ?
");
$stmt->execute(['%check ' . $date]);
$alreadyIssued = [];
foreach ($stmt->fetchAll() as $r) {
    // reason_detail: "bookclub_open check 2026-05-18" or "bookclub_join check 2026-05-18"
    if (preg_match('/^(bookclub_(?:open|join))\s+check\s+/', $r['reason_detail'], $m)) {
        $alreadyIssued[$r['member_id'] . '|' . $m[1]] = true;
    }
}
echo "기존 발급(중복 가드): " . count($alreadyIssued) . " 건\n\n";

$stats = [
    'issued_open' => 0, 'issued_open_coin' => 0,
    'issued_join' => 0, 'issued_join_coin' => 0,
    'skip_already' => 0,
    'skip_duty'    => 0,
    'skip_cap'     => 0,
    'skip_applied0'=> 0,
];

foreach ($checks as $row) {
    $memberId = (int)$row['member_id'];
    $code     = $row['mission_code'];
    $key      = "{$memberId}|{$code}";

    if (isset($alreadyIssued[$key])) {
        $stats['skip_already']++;
        continue;
    }

    $config = $code === 'bookclub_open'
        ? ['amount' => COIN_STUDY_OPEN_AMOUNT, 'counter' => 'study_open_count', 'max' => COIN_STUDY_OPEN_MAX, 'reason' => 'study_open']
        : ['amount' => COIN_STUDY_JOIN_AMOUNT, 'counter' => 'study_join_count', 'max' => COIN_STUDY_JOIN_MAX, 'reason' => 'study_join'];

    // 카운터 cap
    $mcc = getOrCreateMemberCycleCoins($db, $memberId, $cycleId);
    $currentCount = (int)$mcc[$config['counter']];
    if ($currentCount >= $config['max']) {
        $stats['skip_cap']++;
        continue;
    }

    // bookclub_open: duty 가드
    if ($code === 'bookclub_open') {
        $dutyCount = getStudyOpenDutyCount($db, $memberId);
        if ($dutyCount > 0) {
            $totalChecks = countMissionChecksInCycle($db, $memberId, 'bookclub_open', $cycle['start_date'], $cycle['end_date']);
            if ($totalChecks <= $dutyCount) {
                $stats['skip_duty']++;
                continue;
            }
        }
    }

    if ($dryRun) {
        if ($code === 'bookclub_open') {
            $stats['issued_open']++;
            $stats['issued_open_coin'] += $config['amount'];
        } else {
            $stats['issued_join']++;
            $stats['issued_join_coin'] += $config['amount'];
        }
        continue;
    }

    // 실제 발급
    $db->beginTransaction();
    try {
        $result = applyCoinChange(
            $db, $memberId, $cycleId, $config['amount'], $config['reason'],
            "{$code} check {$date}", null
        );
        if ($result['applied'] > 0) {
            $db->prepare("
                UPDATE member_cycle_coins SET {$config['counter']} = {$config['counter']} + 1
                WHERE member_id = ? AND cycle_id = ?
            ")->execute([$memberId, $cycleId]);
            $db->commit();
            if ($code === 'bookclub_open') {
                $stats['issued_open']++;
                $stats['issued_open_coin'] += $result['applied'];
            } else {
                $stats['issued_join']++;
                $stats['issued_join_coin'] += $result['applied'];
            }
        } else {
            $db->commit();
            $stats['skip_applied0']++;
        }
    } catch (\Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "[ERROR] member={$memberId} code={$code}: " . $e->getMessage() . "\n");
    }
}

echo "── 결과 ──\n";
echo "bookclub_open 발급: {$stats['issued_open']} 명 (코인 {$stats['issued_open_coin']})\n";
echo "bookclub_join 발급: {$stats['issued_join']} 명 (코인 {$stats['issued_join_coin']})\n";
echo "이미 발급(skip): {$stats['skip_already']}\n";
echo "duty 가드(skip): {$stats['skip_duty']}\n";
echo "cap 가드(skip): {$stats['skip_cap']}\n";
echo "max_coin 가득(skip): {$stats['skip_applied0']}\n";

if ($dryRun) echo "\n[DRY-RUN 종료. 실제 적용은 --dry-run 빼고 실행]\n";
