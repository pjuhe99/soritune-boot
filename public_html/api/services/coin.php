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
    $admin = requireAdmin(['operation', 'coach']);
    $input = getJsonInput();
    $memberId = (int)($input['member_id'] ?? 0);
    $coinChange = (int)($input['coin_change'] ?? 0);
    $reasonType = trim($input['reason_type'] ?? '');
    $reasonDetail = trim($input['reason_detail'] ?? '') ?: null;

    if (!$memberId || !$coinChange || !$reasonType) jsonError('member_id, coin_change, reason_type 필요');

    $db = getDB();

    $stmt = $db->prepare("SELECT current_coin FROM member_coin_balances WHERE member_id = ?");
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    $beforeCoin = $row ? (int)$row['current_coin'] : 0;
    $afterCoin = $beforeCoin + $coinChange;

    if ($afterCoin < 0) jsonError('코인이 부족합니다. 현재: ' . $beforeCoin);
    if ($afterCoin > 200) jsonError('최대 보유 코인(200)을 초과합니다. 현재: ' . $beforeCoin);

    $db->prepare("
        INSERT INTO coin_logs (member_id, coin_change, before_coin, after_coin, reason_type, reason_detail, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$memberId, $coinChange, $beforeCoin, $afterCoin, $reasonType, $reasonDetail, $admin['admin_id']]);

    $db->prepare("
        INSERT INTO member_coin_balances (member_id, current_coin)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE current_coin = VALUES(current_coin)
    ")->execute([$memberId, $afterCoin]);

    jsonSuccess([
        'before_coin' => $beforeCoin,
        'after_coin' => $afterCoin,
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
