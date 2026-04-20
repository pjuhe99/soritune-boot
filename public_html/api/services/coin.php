<?php
/**
 * Coin Service
 * 코인 잔액 조회, 적립/차감, 로그
 */

function handleCoinBalance() {
    requireAdmin();
    $memberId = (int)($_GET['member_id'] ?? 0);
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT bm.nickname, bm.real_name, COALESCE(mcb.current_coin, 0) AS current_coin
        FROM bootcamp_members bm
        LEFT JOIN member_coin_balances mcb ON bm.id = mcb.member_id
        WHERE bm.id = ?
    ");
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    if (!$row) jsonError('회원을 찾을 수 없습니다.');
    jsonSuccess($row);
}

function handleCoinChange($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $memberId    = (int)($input['member_id'] ?? 0);
    $cycleId     = (int)($input['cycle_id'] ?? 0);
    $coinChange  = (int)($input['coin_change'] ?? 0);
    $reasonType  = trim($input['reason_type'] ?? '');
    $reasonDetail = trim($input['reason_detail'] ?? '') ?: null;

    if (!$memberId || !$cycleId || !$coinChange || !$reasonType) {
        jsonError('member_id, cycle_id, coin_change, reason_type 필요');
    }

    $db = getDB();

    // cycle 존재 확인
    $cStmt = $db->prepare("SELECT id FROM coin_cycles WHERE id = ?");
    $cStmt->execute([$cycleId]);
    if (!$cStmt->fetch()) jsonError('존재하지 않는 cycle');

    // 차감일 때 earned 부족 방지
    if ($coinChange < 0) {
        $mStmt = $db->prepare("SELECT earned_coin, used_coin FROM member_cycle_coins WHERE member_id = ? AND cycle_id = ?");
        $mStmt->execute([$memberId, $cycleId]);
        $row = $mStmt->fetch();
        $current = $row ? ((int)$row['earned_coin'] - (int)$row['used_coin']) : 0;
        if ($current + $coinChange < 0) {
            jsonError("해당 cycle의 잔액({$current})을 초과 차감할 수 없습니다.");
        }
    }

    $result = applyCoinChange($db, $memberId, $cycleId, $coinChange, $reasonType, $reasonDetail, $admin['admin_id']);

    // 현재 전체 잔액 조회
    $balStmt = $db->prepare("SELECT current_coin FROM member_coin_balances WHERE member_id = ?");
    $balStmt->execute([$memberId]);
    $afterCoin = (int)($balStmt->fetchColumn() ?: 0);
    $beforeCoin = $afterCoin - $result['applied'];

    jsonSuccess([
        'before_coin' => $beforeCoin,
        'after_coin'  => $afterCoin,
        'applied'     => $result['applied'],
    ], '코인이 처리되었습니다.');
}

function handleCoinLogs() {
    requireAdmin();
    $memberId = (int)($_GET['member_id'] ?? 0);
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT cl.*, a.name AS operator_name
        FROM coin_logs cl
        LEFT JOIN admins a ON cl.created_by = a.id
        WHERE cl.member_id = ?
        ORDER BY cl.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$memberId]);
    jsonSuccess(['logs' => $stmt->fetchAll()]);
}
