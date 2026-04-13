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
    ensureScoresFresh($db, $cohortId);
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
               bm.participation_count,
               COALESCE(mhs_p.stage1_participation_count, mhs_u.stage1_participation_count, 0) AS stage1_participation_count,
               COALESCE(mhs_p.stage2_participation_count, mhs_u.stage2_participation_count, 0) AS stage2_participation_count,
               COALESCE(mhs_p.completed_bootcamp_count, mhs_u.completed_bootcamp_count, 0) AS completed_bootcamp_count,
               COALESCE(mhs_p.bravo_grade, mhs_u.bravo_grade) AS bravo_grade
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        LEFT JOIN member_coin_balances mcb ON bm.id = mcb.member_id
        LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
        LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
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
    $newId = createMember($db, [
        'cohort_id'       => $cohortId,
        'nickname'        => $nickname,
        'real_name'       => trim($input['real_name'] ?? '') ?: null,
        'phone'           => trim($input['phone'] ?? '') ?: null,
        'user_id'         => $input['user_id'] ?? null,
        'group_id'        => $input['group_id'] ?? null,
        'cafe_member_key' => trim($input['cafe_member_key'] ?? '') ?: null,
        'member_role'     => $input['member_role'] ?? 'member',
        'stage_no'        => (int)($input['stage_no'] ?? 1),
        'joined_at'       => $input['joined_at'] ?? date('Y-m-d'),
    ]);

    jsonSuccess(['id' => $newId], '회원이 추가되었습니다.');
}

function handleMemberUpdate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $fields = []; $params = [];
    foreach (['nickname', 'real_name', 'cafe_member_key', 'kakao_link'] as $f) {
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

    // 변경 전 phone/user_id 보존 (갱신 대상 판별용)
    $before = getMemberIdentifiers($db, $id);

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

    // 리더 코인은 코인 관리 화면에서 수동 일괄 지급 (역할 변경 시 자동 지급하지 않음)

    // 집계 테이블 갱신 (stats 영향 필드 변경 시)
    $statsFields = ['stage_no', 'is_active', 'phone', 'user_id', 'cohort_id'];
    $needsRefresh = false;
    foreach ($statsFields as $f) {
        if (isset($input[$f])) { $needsRefresh = true; break; }
    }
    if ($needsRefresh) {
        // 변경 전 인물의 stats도 갱신 (phone/user_id가 바뀐 경우)
        refreshMemberStats($db, $before['phone'], $before['user_id']);
        // 변경 후 인물의 stats 갱신
        refreshMemberStatsById($db, $id);
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

    // 삭제 전 식별자 보존
    $ident = getMemberIdentifiers($db, $id);

    $db->prepare("DELETE FROM bootcamp_members WHERE id = ?")->execute([$id]);

    // 삭제 후 해당 인물의 stats 갱신
    refreshMemberStats($db, $ident['phone'], $ident['user_id']);

    jsonSuccess([], '회원이 삭제되었습니다.');
}

// calcParticipationCount()는 member_create.php에서 공통 정의됨
