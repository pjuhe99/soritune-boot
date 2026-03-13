<?php
/**
 * Member Service
 * 회원 CRUD, 참여 횟수 계산
 */

function handleMembers() {
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');

    $db = getDB();
    $where = ["bm.cohort_id = ?"];
    $params = [$cohortId];

    if (!empty($_GET['group_id'])) { $where[] = "bm.group_id = ?"; $params[] = (int)$_GET['group_id']; }
    if (!empty($_GET['stage_no'])) { $where[] = "bm.stage_no = ?"; $params[] = (int)$_GET['stage_no']; }
    if (!empty($_GET['keyword'])) {
        $kw = '%' . trim($_GET['keyword']) . '%';
        $where[] = "(bm.nickname LIKE ? OR bm.real_name LIKE ?)";
        $params[] = $kw; $params[] = $kw;
    }

    $stmt = $db->prepare("
        SELECT bm.*, bg.name AS group_name,
               COALESCE(ms.current_score, 0) AS current_score,
               COALESCE(mcb.current_coin, 0) AS current_coin,
               CASE WHEN bm.cafe_member_key IS NOT NULL THEN 1 ELSE 0 END AS is_cafe_mapped,
               bm.participation_count
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        LEFT JOIN member_coin_balances mcb ON bm.id = mcb.member_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bg.name, bm.nickname
    ");
    $stmt->execute($params);
    jsonSuccess(['members' => $stmt->fetchAll()]);
}

function handleMemberCreate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $cohortId = (int)($input['cohort_id'] ?? 0);
    $nickname = trim($input['nickname'] ?? '');
    if (!$cohortId || !$nickname) jsonError('cohort_id, nickname 필요');

    $db = getDB();
    $phone = trim($input['phone'] ?? '') ?: null;
    $userId = $input['user_id'] ?? null;
    $participationCount = calcParticipationCount($db, $phone, $userId, $cohortId);

    $stmt = $db->prepare("
        INSERT INTO bootcamp_members (user_id, cohort_id, group_id, nickname, real_name, phone, cafe_member_key, member_role, stage_no, joined_at, participation_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $cohortId,
        $input['group_id'] ?? null,
        $nickname,
        trim($input['real_name'] ?? '') ?: null,
        $phone,
        trim($input['cafe_member_key'] ?? '') ?: null,
        $input['member_role'] ?? 'member',
        (int)($input['stage_no'] ?? 1),
        $input['joined_at'] ?? date('Y-m-d'),
        $participationCount,
    ]);
    $newId = (int)$db->lastInsertId();

    // member_scores, member_coin_balances 초기화
    $db->prepare("INSERT INTO member_scores (member_id, current_score) VALUES (?, ?)")->execute([$newId, SCORE_START]);
    $db->prepare("INSERT INTO member_coin_balances (member_id, current_coin) VALUES (?, 0)")->execute([$newId]);

    jsonSuccess(['id' => $newId], '회원이 추가되었습니다.');
}

function handleMemberUpdate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $fields = []; $params = [];
    foreach (['nickname', 'real_name', 'cafe_member_key'] as $f) {
        if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = trim($input[$f]) ?: null; }
    }
    foreach (['cohort_id', 'group_id', 'user_id'] as $f) {
        if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = $input[$f] ? (int)$input[$f] : null; }
    }
    if (isset($input['member_role'])) { $fields[] = "member_role = ?"; $params[] = $input['member_role']; }
    if (isset($input['stage_no'])) { $fields[] = "stage_no = ?"; $params[] = (int)$input['stage_no']; }
    if (isset($input['is_active'])) { $fields[] = "is_active = ?"; $params[] = $input['is_active'] ? 1 : 0; }
    if (!$fields) jsonError('수정할 내용 없음');

    $db = getDB();

    // role 변경 감지 (코인 처리용)
    $beforeRole = null;
    if (isset($input['member_role'])) {
        $brStmt = $db->prepare("SELECT member_role FROM bootcamp_members WHERE id = ?");
        $brStmt->execute([$id]);
        $brRow = $brStmt->fetch();
        $beforeRole = $brRow ? $brRow['member_role'] : null;
    }

    $params[] = $id;
    $db->prepare("UPDATE bootcamp_members SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    // role이 실제로 변경되었으면 코인 처리
    if ($beforeRole !== null && $beforeRole !== $input['member_role'] && function_exists('handleRoleChangeCoin')) {
        handleRoleChangeCoin($db, $id, $beforeRole, $input['member_role'], $admin['admin_id']);
    }

    jsonSuccess([], '회원 정보가 수정되었습니다.');
}

function handleMemberDelete($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $db = getDB();
    $db->prepare("DELETE FROM bootcamp_members WHERE id = ?")->execute([$id]);
    jsonSuccess([], '회원이 삭제되었습니다.');
}

/**
 * 참여 횟수 계산: 다른 cohort에 같은 phone 또는 user_id가 있는 수 + 1
 */
function calcParticipationCount($db, $phone, $userId, $cohortId) {
    if (!$phone && !$userId) return 1;

    $conds = [];
    $params = [];
    if ($phone) { $conds[] = "(bm.phone = ? AND bm.phone != '')"; $params[] = $phone; }
    if ($userId) { $conds[] = "(bm.user_id = ? AND bm.user_id != '')"; $params[] = $userId; }
    $params[] = $cohortId;

    $stmt = $db->prepare(
        "SELECT COUNT(DISTINCT bm.cohort_id) AS cnt FROM bootcamp_members bm WHERE (" . implode(' OR ', $conds) . ") AND bm.cohort_id != ?"
    );
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() + 1;
}
