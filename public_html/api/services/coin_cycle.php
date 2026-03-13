<?php
/**
 * Coin Cycle Service
 * cycle CRUD, 리더코인 일괄지급, 정산, 응원상
 */

// ── Cycle CRUD ──────────────────────────────────────────────

function handleCoinCycles() {
    requireAdmin();
    $db = getDB();
    $stmt = $db->query("
        SELECT cc.*,
               (SELECT COUNT(*) FROM member_cycle_coins mcc WHERE mcc.cycle_id = cc.id) AS member_count,
               (SELECT COALESCE(SUM(mcc2.earned_coin), 0) FROM member_cycle_coins mcc2 WHERE mcc2.cycle_id = cc.id) AS total_earned
        FROM coin_cycles cc
        ORDER BY cc.start_date DESC
    ");
    jsonSuccess(['cycles' => $stmt->fetchAll()]);
}

function handleCoinCycleCreate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();

    $name      = trim($input['name'] ?? '');
    $startDate = $input['start_date'] ?? '';
    $endDate   = $input['end_date'] ?? '';

    if (!$name || !$startDate || !$endDate) jsonError('name, start_date, end_date 필요');

    // end_date가 일요일인지 검증
    $endDow = (int)date('w', strtotime($endDate));
    if ($endDow !== 0) jsonError('end_date는 일요일이어야 합니다. (현재: ' . ['일','월','화','수','목','금','토'][$endDow] . '요일)');

    if ($startDate >= $endDate) jsonError('start_date는 end_date보다 앞이어야 합니다.');

    $db = getDB();

    // 날짜 겹침 체크
    $overlap = $db->prepare("
        SELECT id, name FROM coin_cycles
        WHERE start_date <= ? AND end_date >= ?
    ");
    $overlap->execute([$endDate, $startDate]);
    $dup = $overlap->fetch();
    if ($dup) jsonError("기간이 겹치는 cycle이 있습니다: {$dup['name']}");

    $db->prepare("
        INSERT INTO coin_cycles (name, start_date, end_date) VALUES (?, ?, ?)
    ")->execute([$name, $startDate, $endDate]);

    jsonSuccess(['id' => (int)$db->lastInsertId()], 'Coin cycle이 생성되었습니다.');
}

function handleCoinCycleUpdate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $fields = []; $params = [];
    if (isset($input['name'])) { $fields[] = "name = ?"; $params[] = trim($input['name']); }
    if (isset($input['start_date'])) { $fields[] = "start_date = ?"; $params[] = $input['start_date']; }
    if (isset($input['end_date'])) {
        $endDow = (int)date('w', strtotime($input['end_date']));
        if ($endDow !== 0) jsonError('end_date는 일요일이어야 합니다.');
        $fields[] = "end_date = ?"; $params[] = $input['end_date'];
    }
    if (!$fields) jsonError('수정할 내용 없음');

    $params[] = $id;
    $db = getDB();
    $db->prepare("UPDATE coin_cycles SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    jsonSuccess([], 'Coin cycle이 수정되었습니다.');
}

function handleCoinCycleClose($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $db = getDB();
    $stmt = $db->prepare("SELECT status FROM coin_cycles WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('cycle을 찾을 수 없습니다.');
    if ($row['status'] === 'closed') jsonError('이미 마감된 cycle입니다.');

    $db->prepare("UPDATE coin_cycles SET status = 'closed', closed_at = NOW() WHERE id = ?")->execute([$id]);
    jsonSuccess([], 'Coin cycle이 마감되었습니다.');
}

function handleCoinCycleMembers() {
    requireAdmin();
    $cycleId = (int)($_GET['cycle_id'] ?? 0);
    if (!$cycleId) jsonError('cycle_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT bm.id AS member_id, bm.nickname, bm.real_name, bm.member_role,
               bg.name AS group_name,
               COALESCE(mcc.earned_coin, 0) AS earned_coin,
               COALESCE(mcc.study_open_count, 0) AS study_open_count,
               COALESCE(mcc.study_join_count, 0) AS study_join_count,
               COALESCE(mcc.leader_coin_granted, 0) AS leader_coin_granted,
               COALESCE(mcc.perfect_attendance_granted, 0) AS perfect_attendance_granted,
               COALESCE(mcc.hamemmal_granted, 0) AS hamemmal_granted
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_cycle_coins mcc ON bm.id = mcc.member_id AND mcc.cycle_id = ?
        WHERE bm.is_active = 1 AND bm.member_status != 'withdrawn'
        ORDER BY bg.name, bm.nickname
    ");
    $stmt->execute([$cycleId]);
    jsonSuccess(['members' => $stmt->fetchAll()]);
}

// ── 리더 코인 일괄 지급 ────────────────────────────────────

function handleCoinLeaderGrant($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $cycleId = (int)($input['cycle_id'] ?? 0);
    if (!$cycleId) jsonError('cycle_id 필요');

    $db = getDB();

    // cycle 존재 확인
    $cycleStmt = $db->prepare("SELECT id FROM coin_cycles WHERE id = ?");
    $cycleStmt->execute([$cycleId]);
    if (!$cycleStmt->fetch()) jsonError('cycle을 찾을 수 없습니다.');

    // leader/subleader 회원 조회
    $members = $db->prepare("
        SELECT id, member_role FROM bootcamp_members
        WHERE member_role IN ('leader', 'subleader') AND is_active = 1 AND member_status != 'withdrawn'
    ");
    $members->execute();
    $leaders = $members->fetchAll();

    $results = ['granted' => 0, 'skipped' => 0];
    foreach ($leaders as $l) {
        $r = grantLeaderCoin($db, (int)$l['id'], $cycleId, $l['member_role'], $admin['admin_id']);
        if (isset($r['skipped'])) $results['skipped']++;
        else $results['granted']++;
    }

    jsonSuccess($results, "리더 코인 지급: {$results['granted']}명 지급, {$results['skipped']}명 스킵");
}

// ── 정산 ────────────────────────────────────────────────────

function handleCoinSettlementPreview() {
    requireAdmin(['operation']);
    $cycleId = (int)($_GET['cycle_id'] ?? 0);
    if (!$cycleId) jsonError('cycle_id 필요');

    $db = getDB();
    $result = previewSettlement($db, $cycleId);
    if (!$result) jsonError('cycle을 찾을 수 없습니다.');
    jsonSuccess($result);
}

function handleCoinSettlementExecute($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $cycleId = (int)($input['cycle_id'] ?? 0);
    if (!$cycleId) jsonError('cycle_id 필요');

    $db = getDB();
    $results = executeSettlement($db, $cycleId, $admin['admin_id']);
    if (isset($results['error'])) jsonError($results['error']);

    jsonSuccess($results,
        "정산 완료: 찐완주 {$results['perfect_attendance']}명, 하멈말 {$results['hamemmal']}명");
}

// ── 응원상 ──────────────────────────────────────────────────

function handleCoinCheerAward($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    // leader/subleader 인증 — admin으로 로그인한 조장
    $admin = requireAdmin(['leader', 'subleader', 'operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    $cycleId = (int)($input['cycle_id'] ?? 0);
    $targetIds = $input['target_member_ids'] ?? [];
    if (!$cycleId || empty($targetIds)) jsonError('cycle_id, target_member_ids 필요');

    // 조장의 member_id 확인 (admins.member_id)
    $db = getDB();
    $leaderMemberId = null;

    if (hasRole($admin, 'operation') || hasRole($admin, 'coach')) {
        // operation/coach는 leader_member_id를 직접 전달해야 함
        $leaderMemberId = (int)($input['leader_member_id'] ?? 0);
        if (!$leaderMemberId) jsonError('operation/coach는 leader_member_id를 지정해야 합니다.');
    } else {
        // leader/subleader는 자기 member_id 사용
        $adminRow = $db->prepare("SELECT member_id FROM admins WHERE id = ?");
        $adminRow->execute([$admin['admin_id']]);
        $aRow = $adminRow->fetch();
        $leaderMemberId = $aRow ? (int)$aRow['member_id'] : 0;
        if (!$leaderMemberId) jsonError('관리자 계정에 연결된 회원이 없습니다.');
    }

    $result = grantCheerAward($db, $cycleId, $leaderMemberId, $targetIds, $admin['admin_id']);
    if (isset($result['error'])) jsonError($result['error']);

    jsonSuccess($result, "응원상 {$result['granted']}명 지급 완료");
}

function handleCoinCheerStatus() {
    $admin = requireAdmin();
    $cycleId = (int)($_GET['cycle_id'] ?? 0);
    if (!$cycleId) jsonError('cycle_id 필요');

    $db = getDB();

    // 조장의 member_id
    $adminRow = $db->prepare("SELECT member_id FROM admins WHERE id = ?");
    $adminRow->execute([$admin['admin_id']]);
    $aRow = $adminRow->fetch();
    $leaderMemberId = $aRow ? (int)$aRow['member_id'] : 0;

    $awards = [];
    if ($leaderMemberId) {
        $stmt = $db->prepare("
            SELECT lca.target_member_id, bm.nickname, bm.real_name, lca.coin_amount, lca.created_at
            FROM leader_cheer_awards lca
            JOIN bootcamp_members bm ON lca.target_member_id = bm.id
            WHERE lca.cycle_id = ? AND lca.leader_member_id = ?
            ORDER BY lca.created_at
        ");
        $stmt->execute([$cycleId, $leaderMemberId]);
        $awards = $stmt->fetchAll();
    }

    jsonSuccess([
        'awards' => $awards,
        'max_targets' => COIN_CHEER_MAX_TARGETS,
        'remaining' => COIN_CHEER_MAX_TARGETS - count($awards),
    ]);
}
