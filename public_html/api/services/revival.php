<?php
/**
 * Revival Service
 * 패자부활전 후보 조회, 처리, 이력
 */

function handleRevivalCandidates() {
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');

    $db = getDB();
    $where = ["bm.cohort_id = ?", "bm.is_active = 1", "COALESCE(ms.current_score, 0) <= " . SCORE_REVIVAL_ELIGIBLE];
    $params = [$cohortId];
    if (!empty($_GET['group_id'])) { $where[] = "bm.group_id = ?"; $params[] = (int)$_GET['group_id']; }

    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
               bm.group_id, bg.name AS group_name,
               COALESCE(ms.current_score, 0) AS current_score
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ms.current_score ASC, bg.name, bm.nickname
    ");
    $stmt->execute($params);
    jsonSuccess(['candidates' => $stmt->fetchAll()]);
}

function handleRevivalProcess($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'coach']);
    $input = getJsonInput();
    $memberId = (int)($input['member_id'] ?? 0);
    $note = trim($input['note'] ?? '') ?: null;
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();

    $stmt = $db->prepare("SELECT current_score FROM member_scores WHERE member_id = ?");
    $stmt->execute([$memberId]);
    $scoreRow = $stmt->fetch();
    $beforeScore = $scoreRow ? (int)$scoreRow['current_score'] : 0;

    if ($beforeScore > SCORE_REVIVAL_ELIGIBLE) jsonError('패자부활 기준(' . SCORE_REVIVAL_ELIGIBLE . ') 이하가 아닙니다. 현재 점수: ' . $beforeScore);

    $afterScore = SCORE_REVIVAL_AFTER;

    // revival_logs 저장
    $db->prepare("
        INSERT INTO revival_logs (member_id, before_score, after_score, note, created_by)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$memberId, $beforeScore, $afterScore, $note, $admin['admin_id']]);

    // score_logs 저장
    $change = $afterScore - $beforeScore;
    $db->prepare("
        INSERT INTO score_logs (member_id, score_change, before_score, after_score, reason_type, reason_detail, created_by)
        VALUES (?, ?, ?, ?, 'revival_adjustment', ?, ?)
    ")->execute([$memberId, $change, $beforeScore, $afterScore, $note, $admin['admin_id']]);

    // member_scores 갱신
    $db->prepare("
        INSERT INTO member_scores (member_id, current_score, last_calculated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE current_score = VALUES(current_score), last_calculated_at = NOW()
    ")->execute([$memberId, $afterScore]);

    // 조관리 제외 상태 해제
    if ($afterScore > SCORE_OUT_THRESHOLD) {
        $db->prepare("UPDATE bootcamp_members SET member_status = 'active' WHERE id = ? AND member_status = 'out_of_group_management'")
           ->execute([$memberId]);
    }

    jsonSuccess([
        'before_score' => $beforeScore,
        'after_score' => $afterScore,
    ], '패자부활전 처리가 완료되었습니다.');
}

function handleRevivalLogs() {
    requireAdmin();
    $db = getDB();

    $where = ["1=1"];
    $params = [];
    if (!empty($_GET['member_id'])) { $where[] = "rl.member_id = ?"; $params[] = (int)$_GET['member_id']; }
    if (!empty($_GET['cohort_id'])) { $where[] = "bm.cohort_id = ?"; $params[] = (int)$_GET['cohort_id']; }
    if (!empty($_GET['group_id'])) { $where[] = "bm.group_id = ?"; $params[] = (int)$_GET['group_id']; }

    $stmt = $db->prepare("
        SELECT rl.*, bm.nickname, bm.real_name, bg.name AS group_name, a.name AS operator_name
        FROM revival_logs rl
        JOIN bootcamp_members bm ON rl.member_id = bm.id
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN admins a ON rl.created_by = a.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY rl.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    jsonSuccess(['logs' => $stmt->fetchAll()]);
}
