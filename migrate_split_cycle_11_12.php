<?php
/**
 * boot.soritune.com - 11기 → 12기 Cycle 분리 + Reward Group 설정 마이그
 *
 * 실행:
 *   php migrate_split_cycle_11_12.php --dry-run --cycle11=2 --cycle12-end=2026-05-17
 *   php migrate_split_cycle_11_12.php --execute --cycle11=2 --cycle12-end=2026-05-17 --group-name="11-12기 리워드"
 */

require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/includes/coin_functions.php';

$opts = getopt('', ['dry-run', 'execute', 'cycle11:', 'cycle12-end:', 'group-name:']);
$dryRun    = isset($opts['dry-run']);
$execute   = isset($opts['execute']);
$cycle11   = (int)($opts['cycle11']  ?? 0);
$cycle12End = $opts['cycle12-end'] ?? '';
$groupName = $opts['group-name'] ?? '11-12기 리워드';

if ((!$dryRun && !$execute) || !$cycle11 || !$cycle12End) {
    fwrite(STDERR, "Usage: php migrate_split_cycle_11_12.php --dry-run|--execute --cycle11=ID --cycle12-end=YYYY-MM-DD [--group-name=NAME]\n");
    exit(2);
}

$cycle11End   = '2026-04-19';
$cycle12Start = '2026-04-20';

$db = getDB();

echo "=== 11기 → 12기 분리 마이그 ===\n";
echo "  모드: " . ($dryRun ? 'DRY-RUN' : 'EXECUTE') . "\n";
echo "  11기 cycle_id: $cycle11\n";
echo "  12기 기간: $cycle12Start ~ $cycle12End\n";
echo "  Reward Group: $groupName\n\n";

// cycle11 확인
$stmt = $db->prepare("SELECT * FROM coin_cycles WHERE id = ?");
$stmt->execute([$cycle11]);
$c11 = $stmt->fetch();
if (!$c11) { fwrite(STDERR, "cycle11 ($cycle11)을 찾을 수 없습니다.\n"); exit(1); }
echo "  [확인] 11기: {$c11['name']} ({$c11['start_date']}~{$c11['end_date']})\n";

// 12기 end_date 일요일 검증
if ((int)date('w', strtotime($cycle12End)) !== 0) {
    fwrite(STDERR, "cycle12 end_date ($cycle12End)는 일요일이어야 합니다.\n"); exit(1);
}

// 11기 logs 전수 조회 후 분류
$logStmt = $db->prepare("
    SELECT id, member_id, reason_type, reason_detail, coin_change, DATE(created_at) AS d
    FROM coin_logs
    WHERE cycle_id = ?
    ORDER BY id
");
$logStmt->execute([$cycle11]);
$allLogs = $logStmt->fetchAll();

$movingLogIds = [];
$ambiguous = [];
$errorsByType = [];
$movedByType = [];

foreach ($allLogs as $log) {
    $rtype = $log['reason_type'];
    $moveIt = false;

    if ($rtype === 'leader_coin') {
        // 리더코인은 "11기 역할 보상"이라 언제 버튼을 눌렀든 11기에 귀속.
        // 12기 리더코인은 12기 마감 후 별도 [리더코인] batch로 지급.
    } elseif (in_array($rtype, ['study_open', 'study_join'])) {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $log['reason_detail'] ?? '', $m)) {
            if ($m[1] >= $cycle12Start) $moveIt = true;
        } elseif ($log['d'] >= $cycle12Start) {
            // 파싱 실패 + 4/20 이후 created_at → 운영자 수동 확인 필요
            $ambiguous[] = $log;
        }
        // 파싱 실패 + 4/19 이전 created_at → 11기에 남김 (fix_study_open_duty 등 legacy 조정)
    } elseif (in_array($rtype, ['perfect_attendance', 'hamemmal_bonus', 'cheer_award', 'manual_adjustment', 'reward_distribution'])) {
        if ($log['d'] >= $cycle12Start) {
            $errorsByType[$rtype] = ($errorsByType[$rtype] ?? 0) + 1;
        }
    }

    if ($moveIt) {
        $movingLogIds[] = (int)$log['id'];
        $movedByType[$rtype] = ($movedByType[$rtype] ?? 0) + 1;
    }
}

echo "\n[분석]\n";
echo "  총 11기 logs: " . count($allLogs) . "\n";
echo "  12기로 이동 대상: " . count($movingLogIds) . "\n";
echo "  이동 분포: " . json_encode($movedByType, JSON_UNESCAPED_UNICODE) . "\n";
echo "  파싱 모호: " . count($ambiguous) . "\n";
echo "  에러 reason_type (4/20 이후): " . json_encode($errorsByType, JSON_UNESCAPED_UNICODE) . "\n";

if (count($ambiguous) > 0) {
    echo "\n[에러] reason_detail에서 날짜 파싱 실패한 logs 있음. 중단.\n";
    foreach ($ambiguous as $l) { echo "  - log_id={$l['id']} type={$l['reason_type']} detail={$l['reason_detail']}\n"; }
    exit(1);
}
if (count($errorsByType) > 0) {
    echo "\n[에러] migration 범위 외 reason_type이 4/20 이후에 있음. 운영자 수동 확인 필요.\n";
    exit(1);
}

if ($dryRun) {
    echo "\n=== DRY-RUN 종료 (변경 없음) ===\n";
    exit(0);
}

// EXECUTE
$db->beginTransaction();
try {
    // 마이그 전 sanity — 영향 회원 총 잔액 스냅샷
    $affected = [];
    foreach ($allLogs as $l) $affected[(int)$l['member_id']] = true;
    $affectedIds = array_keys($affected);

    $beforeBalances = [];
    if ($affectedIds) {
        $ph = implode(',', array_fill(0, count($affectedIds), '?'));
        $bs = $db->prepare("SELECT member_id, COALESCE(SUM(earned_coin - used_coin), 0) AS t FROM member_cycle_coins WHERE member_id IN ($ph) GROUP BY member_id");
        $bs->execute($affectedIds);
        foreach ($bs->fetchAll() as $r) $beforeBalances[(int)$r['member_id']] = (int)$r['t'];
    }

    // 1. reward_groups
    $db->prepare("INSERT INTO reward_groups (name) VALUES (?)")->execute([$groupName]);
    $groupId = (int)$db->lastInsertId();
    echo "\n[1] reward_groups id=$groupId 생성\n";

    // 2. 11기 업데이트
    $db->prepare("UPDATE coin_cycles SET end_date=?, reward_group_id=? WHERE id=?")
       ->execute([$cycle11End, $groupId, $cycle11]);
    echo "[2] 11기 end_date=$cycle11End, reward_group_id=$groupId\n";

    // 3. 12기 생성
    $db->prepare("INSERT INTO coin_cycles (name, start_date, end_date, reward_group_id) VALUES (?, ?, ?, ?)")
       ->execute(['12기', $cycle12Start, $cycle12End, $groupId]);
    $cycle12 = (int)$db->lastInsertId();
    echo "[3] 12기 생성 id=$cycle12\n";

    // 4. 로그 이관
    if ($movingLogIds) {
        $ph = implode(',', array_fill(0, count($movingLogIds), '?'));
        $params = array_merge([$cycle12], $movingLogIds);
        $db->prepare("UPDATE coin_logs SET cycle_id=? WHERE id IN ($ph)")->execute($params);
        echo "[4] " . count($movingLogIds) . "건 로그 이관 완료\n";
    } else {
        echo "[4] 이관할 로그 없음\n";
    }

    // 5. 재계산
    echo "[5] 영향 회원 " . count($affectedIds) . "명, member_cycle_coins 재계산...\n";
    foreach ([$cycle11, $cycle12] as $cid) {
        foreach ($affectedIds as $mid) {
            recalcMemberCycleCoins($db, $mid, $cid);
        }
    }
    echo "    완료\n";

    // 6. syncMemberCoinBalance
    foreach ($affectedIds as $mid) syncMemberCoinBalance($db, $mid);
    echo "[6] member_coin_balances 동기화 완료\n";

    // 7. 사후 검증
    $afterBalances = [];
    if ($affectedIds) {
        $ph = implode(',', array_fill(0, count($affectedIds), '?'));
        $bs = $db->prepare("SELECT member_id, COALESCE(SUM(earned_coin - used_coin), 0) AS t FROM member_cycle_coins WHERE member_id IN ($ph) GROUP BY member_id");
        $bs->execute($affectedIds);
        foreach ($bs->fetchAll() as $r) $afterBalances[(int)$r['member_id']] = (int)$r['t'];
    }

    $mismatches = [];
    foreach ($beforeBalances as $mid => $b) {
        $a = $afterBalances[$mid] ?? 0;
        if ($a !== $b) $mismatches[] = "member_id=$mid before=$b after=$a";
    }
    if ($mismatches) {
        throw new RuntimeException("잔액 mismatch 발생: " . implode('; ', $mismatches));
    }
    echo "[7] 잔액 검증 통과 (영향 회원 " . count($affectedIds) . "명 모두 before == after)\n";

    $db->commit();
    echo "\n=== 마이그 완료 ===\n";
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "[에러] 롤백: " . $e->getMessage() . "\n");
    exit(1);
}

// ── helper ──
function recalcMemberCycleCoins($db, $memberId, $cycleId) {
    $s1 = $db->prepare("SELECT COALESCE(SUM(coin_change), 0) FROM coin_logs WHERE member_id=? AND cycle_id=?");
    $s1->execute([$memberId, $cycleId]);
    // earned_coin = raw log sum. 음수 가능 (split으로 check/uncheck 비대칭 된 cycle 대비).
    // 플로어하면 양쪽 cycle 합계가 원래와 어긋남.
    $earned = (int)$s1->fetchColumn();

    // study_open/join count = (체크 로그 수) - (체크 해제 로그 수), floor 0.
    // processCoinForCheck 의도와 일치: 현재 활성 체크 건수.
    $s2 = $db->prepare("
        SELECT
          SUM(CASE WHEN coin_change > 0 THEN 1 WHEN coin_change < 0 THEN -1 ELSE 0 END) AS net
        FROM coin_logs WHERE member_id=? AND cycle_id=? AND reason_type='study_open'
    ");
    $s2->execute([$memberId, $cycleId]);
    $openCount = max(0, (int)($s2->fetchColumn() ?: 0));

    $s3 = $db->prepare("
        SELECT
          SUM(CASE WHEN coin_change > 0 THEN 1 WHEN coin_change < 0 THEN -1 ELSE 0 END) AS net
        FROM coin_logs WHERE member_id=? AND cycle_id=? AND reason_type='study_join'
    ");
    $s3->execute([$memberId, $cycleId]);
    $joinCount = max(0, (int)($s3->fetchColumn() ?: 0));

    $s4 = $db->prepare("SELECT COUNT(*) FROM coin_logs WHERE member_id=? AND cycle_id=? AND reason_type='leader_coin' AND coin_change > 0");
    $s4->execute([$memberId, $cycleId]);
    $leaderGranted = (int)$s4->fetchColumn() > 0 ? 1 : 0;

    $s5 = $db->prepare("SELECT COUNT(*) FROM coin_logs WHERE member_id=? AND cycle_id=? AND reason_type='perfect_attendance' AND coin_change > 0");
    $s5->execute([$memberId, $cycleId]);
    $paGranted = (int)$s5->fetchColumn() > 0 ? 1 : 0;

    $s6 = $db->prepare("SELECT COUNT(*) FROM coin_logs WHERE member_id=? AND cycle_id=? AND reason_type='hamemmal_bonus' AND coin_change > 0");
    $s6->execute([$memberId, $cycleId]);
    $hmGranted = (int)$s6->fetchColumn() > 0 ? 1 : 0;

    $s7 = $db->prepare("SELECT used_coin FROM member_cycle_coins WHERE member_id=? AND cycle_id=?");
    $s7->execute([$memberId, $cycleId]);
    $usedExisting = (int)($s7->fetchColumn() ?: 0);

    // 로그가 빠져나간 cycle의 기존 row가 stale 상태로 남는 것 방지 → 항상 UPSERT.
    // 빈 row가 생겨도 earned=0 / used=0이라 무해.

    $db->prepare("
        INSERT INTO member_cycle_coins
            (member_id, cycle_id, earned_coin, used_coin, study_open_count, study_join_count, leader_coin_granted, perfect_attendance_granted, hamemmal_granted)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            earned_coin = VALUES(earned_coin),
            study_open_count = VALUES(study_open_count),
            study_join_count = VALUES(study_join_count),
            leader_coin_granted = VALUES(leader_coin_granted),
            perfect_attendance_granted = VALUES(perfect_attendance_granted),
            hamemmal_granted = VALUES(hamemmal_granted)
    ")->execute([$memberId, $cycleId, $earned, $usedExisting, $openCount, $joinCount, $leaderGranted, $paGranted, $hmGranted]);
}
