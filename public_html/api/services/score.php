<?php
/**
 * Score Service
 * 점수 로그 조회, 수동 점수 조정, 점수 재계산
 */

function handleScoreLogs() {
    requireAdmin();
    $memberId = (int)($_GET['member_id'] ?? 0);
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT sl.*, a.name AS operator_name
        FROM score_logs sl
        LEFT JOIN admins a ON sl.created_by = a.id
        WHERE sl.member_id = ?
        ORDER BY sl.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$memberId]);
    jsonSuccess(['logs' => $stmt->fetchAll()]);
}

function handleScoreAdjust($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $memberId = (int)($input['member_id'] ?? 0);
    $scoreChange = (int)($input['score_change'] ?? 0);
    $reasonDetail = trim($input['reason_detail'] ?? '') ?: null;

    if (!$memberId || !$scoreChange) jsonError('member_id, score_change 필요');

    $db = getDB();
    $stmt = $db->prepare("SELECT current_score FROM member_scores WHERE member_id = ?");
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    $beforeScore = $row ? (int)$row['current_score'] : 0;
    $afterScore = $beforeScore + $scoreChange;

    $db->prepare("
        INSERT INTO score_logs (member_id, score_change, before_score, after_score, reason_type, reason_detail, created_by)
        VALUES (?, ?, ?, ?, 'manual_adjustment', ?, ?)
    ")->execute([$memberId, $scoreChange, $beforeScore, $afterScore, $reasonDetail, $admin['admin_id']]);

    $db->prepare("
        INSERT INTO member_scores (member_id, current_score, last_calculated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE current_score = VALUES(current_score), last_calculated_at = NOW()
    ")->execute([$memberId, $afterScore]);

    jsonSuccess([
        'before_score' => $beforeScore,
        'after_score' => $afterScore,
    ], '점수가 조정되었습니다.');
}

function handleScoreRecalculate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $memberId = (int)($input['member_id'] ?? 0);
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();
    $newScore = recalculateMemberScore($db, $memberId, $admin['admin_id']);
    if ($newScore === null) jsonError('회원을 찾을 수 없습니다.');
    jsonSuccess(['current_score' => $newScore], '점수가 재계산되었습니다.');
}
