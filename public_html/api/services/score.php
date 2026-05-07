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
    $result = adjustMemberScore($db, $memberId, $scoreChange, $reasonDetail, (int)$admin['admin_id']);

    jsonSuccess([
        'before_score' => $result['before_score'],
        'after_score' => $result['after_score'],
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
