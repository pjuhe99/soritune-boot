<?php
/**
 * 점수/코인 트랜잭션화 인보리언트 테스트
 * 사용: php tests/transaction_invariants.php
 *
 * 각 테스트는 outer transaction 으로 감싸지고 마지막에 rollback —
 * tx-guard 가 작동하면 outer rollback 만으로 모든 상태가 깨끗해져야 함.
 * tx-guard 가 없으면 내부 BEGIN 충돌(PDOException) 또는 내부 COMMIT 으로
 * outer rollback 이 무력화되어 잔존물이 남음.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/bootcamp_functions.php';
require_once __DIR__ . '/../public_html/includes/coin_functions.php';
require_once __DIR__ . '/../public_html/includes/qr_actions.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; }
    else { $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n"; }
}

$db = getDB();

// ── Setup ──
$db->beginTransaction();

try {
    $cohort = $db->query("SELECT id FROM cohorts WHERE cohort = '12기' LIMIT 1")->fetch();
    $cohortId = (int)$cohort['id'];

    $members = $db->prepare("
        SELECT id FROM bootcamp_members
        WHERE cohort_id = ? AND is_active = 1 AND member_status != 'refunded'
        LIMIT 1
    ");
    $members->execute([$cohortId]);
    $memberRow = $members->fetch();
    if (!$memberRow) {
        echo "SKIP  no active 12기 members in DEV\n";
        $db->rollBack();
        exit(0);
    }
    $memberId = (int)$memberRow['id'];

    // 활성 코인 사이클
    $cycle = $db->query("SELECT id, max_coin FROM coin_cycles WHERE status = 'active' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$cycle) {
        echo "SKIP  no active coin cycle in DEV\n";
        $db->rollBack();
        exit(0);
    }
    $cycleId = (int)$cycle['id'];
    $maxCoin = (int)$cycle['max_coin'];

    // member_cycle_coins 초기화
    $db->prepare("
        INSERT INTO member_cycle_coins (member_id, cycle_id, earned_coin, used_coin)
        VALUES (?, ?, 0, 0)
        ON DUPLICATE KEY UPDATE earned_coin = 0, used_coin = 0
    ")->execute([$memberId, $cycleId]);

    // ── Test 1: applyCoinChange 양수 정상 적립 ──
    $r1 = applyCoinChange($db, $memberId, $cycleId, 50, 'leader_coin', 'test', null);
    t('applyCoinChange: +50 정상 적립', $r1['after'] === 50 && $r1['applied'] === 50);

    $cnt = $db->prepare("SELECT COUNT(*) FROM coin_logs WHERE member_id = ? AND cycle_id = ? AND reason_detail = 'test'");
    $cnt->execute([$memberId, $cycleId]);
    t('applyCoinChange: coin_logs 1행', (int)$cnt->fetchColumn() === 1);

    // ── Test 2: applyCoinChange cap (atomic LEAST) ──
    // 잔액을 max-20 으로 조정
    $db->prepare("UPDATE member_cycle_coins SET earned_coin = ? WHERE member_id = ? AND cycle_id = ?")
       ->execute([$maxCoin - 20, $memberId, $cycleId]);

    $r2 = applyCoinChange($db, $memberId, $cycleId, 50, 'leader_coin', 'cap-test', null);
    t('applyCoinChange: cap 적용 시 max 까지만', $r2['after'] === $maxCoin && $r2['applied'] === 20);

    // ── Test 3: applyCoinChange 음수 (cap 무관) ──
    $db->prepare("UPDATE member_cycle_coins SET earned_coin = 100 WHERE member_id = ? AND cycle_id = ?")
       ->execute([$memberId, $cycleId]);

    $r3 = applyCoinChange($db, $memberId, $cycleId, -30, 'leader_coin', 'neg-test', null);
    t('applyCoinChange: 음수 정상 차감', $r3['after'] === 70 && $r3['applied'] === -30);

    // ── Test 4: applyCoinChange 0 (no-op) ──
    $beforeZero = $db->query("SELECT earned_coin FROM member_cycle_coins WHERE member_id = $memberId AND cycle_id = $cycleId")->fetchColumn();
    $r4 = applyCoinChange($db, $memberId, $cycleId, 0, 'leader_coin', 'zero-test', null);
    $afterZero = $db->query("SELECT earned_coin FROM member_cycle_coins WHERE member_id = $memberId AND cycle_id = $cycleId")->fetchColumn();
    t('applyCoinChange: 0 입력 no-op', $r4['applied'] === 0 && $beforeZero === $afterZero);

    $cntZero = $db->prepare("SELECT COUNT(*) FROM coin_logs WHERE member_id = ? AND cycle_id = ? AND reason_detail = 'zero-test'");
    $cntZero->execute([$memberId, $cycleId]);
    t('applyCoinChange: 0 입력 시 coin_logs 미생성', (int)$cntZero->fetchColumn() === 0);

    // ── Test 5: saveCheck nested tx ──
    $missionTypeId = (int)$db->query("SELECT id FROM mission_types WHERE code = 'zoom_daily' AND is_active = 1 LIMIT 1")->fetchColumn();
    if ($missionTypeId === 0) {
        t('saveCheck: nested tx (skipped, no zoom_daily mission_type)', true);
    } else {
        $today = date('Y-m-d');
        $sourceRef = 'tx-test-' . uniqid();
        $caughtException = null;
        try {
            saveCheck($db, $memberId, $today, $missionTypeId, 1, 'manual', $sourceRef, null);
        } catch (\Throwable $e) {
            $caughtException = $e;
        }
        t('saveCheck: nested tx 안에서 예외 없이 실행', $caughtException === null,
            $caughtException ? 'exception: ' . $caughtException->getMessage() : '');

        $mmcCnt = $db->prepare("SELECT COUNT(*) FROM member_mission_checks WHERE member_id = ? AND check_date = ? AND mission_type_id = ?");
        $mmcCnt->execute([$memberId, $today, $missionTypeId]);
        t('saveCheck: nested tx 안에서 mmc 1행 적용', (int)$mmcCnt->fetchColumn() === 1);
    }

    // ── Test 6: adjustMemberScore (Task 5 에서 정의) ──
    if (function_exists('adjustMemberScore')) {
        $beforeAdj = (int)($db->query("SELECT current_score FROM member_scores WHERE member_id = $memberId")->fetchColumn() ?: 0);
        $rAdj = adjustMemberScore($db, $memberId, -3, 'tx-test-adjust', null);
        t('adjustMemberScore: -3 정상 조정', $rAdj['after_score'] === $beforeAdj - 3);

        $logCnt = $db->prepare("SELECT COUNT(*) FROM score_logs WHERE member_id = ? AND reason_detail = 'tx-test-adjust'");
        $logCnt->execute([$memberId]);
        t('adjustMemberScore: score_logs 1행', (int)$logCnt->fetchColumn() === 1);
    } else {
        t('adjustMemberScore: 함수 정의 (Task 5 에서 추가)', false, 'function not yet defined');
        t('adjustMemberScore: score_logs (Task 5 에서 추가)', false, 'function not yet defined');
    }

    // ── Test 7: qrRecordAttendance nested tx ──
    // admin_id NOT NULL FK 충족 — admins 테이블 첫 레코드 사용
    $testAdminId = (int)$db->query("SELECT id FROM admins ORDER BY id LIMIT 1")->fetchColumn();
    $db->prepare("
        INSERT INTO qr_sessions (session_code, session_type, admin_id, cohort_id, status, expires_at, created_at)
        VALUES (?, 'attendance', ?, ?, 'active', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
    ")->execute(['txtest-' . uniqid(), $testAdminId, $cohortId]);
    $attSessionId = (int)$db->lastInsertId();
    $attSession = $db->query("SELECT * FROM qr_sessions WHERE id = $attSessionId")->fetch();

    $caughtAtt = null;
    try {
        $rAtt = qrRecordAttendance($db, $attSession, $memberId, $memberId, '127.0.0.1', 'test');
    } catch (\Throwable $e) {
        $caughtAtt = $e;
    }
    t('qrRecordAttendance: nested tx 안에서 예외 없이 실행', $caughtAtt === null,
        $caughtAtt ? 'exception: ' . $caughtAtt->getMessage() : '');

    // ── Test 8: qrRecordRevival nested tx ──
    $db->prepare("
        INSERT INTO qr_sessions (session_code, session_type, admin_id, cohort_id, status, expires_at, created_at)
        VALUES (?, 'revival', ?, ?, 'active', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
    ")->execute(['txtest-rev-' . uniqid(), $testAdminId, $cohortId]);
    $revSessionId = (int)$db->lastInsertId();
    $revSession = $db->query("SELECT * FROM qr_sessions WHERE id = $revSessionId")->fetch();

    $db->prepare("INSERT INTO member_scores (member_id, current_score, last_calculated_at) VALUES (?, -10, NOW()) ON DUPLICATE KEY UPDATE current_score = -10, last_calculated_at = NOW()")
       ->execute([$memberId]);

    $caughtRev = null;
    try {
        $rRev = qrRecordRevival($db, $revSession, $memberId, $memberId, '127.0.0.1', 'test');
    } catch (\Throwable $e) {
        $caughtRev = $e;
    }
    t('qrRecordRevival: nested tx 안에서 예외 없이 실행', $caughtRev === null,
        $caughtRev ? 'exception: ' . $caughtRev->getMessage() : '');

} finally {
    $db->rollBack();
}

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
