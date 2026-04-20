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
               rg.name AS reward_group_name,
               (SELECT COUNT(*) FROM member_cycle_coins mcc WHERE mcc.cycle_id = cc.id) AS member_count,
               (SELECT COALESCE(SUM(mcc2.earned_coin), 0) FROM member_cycle_coins mcc2 WHERE mcc2.cycle_id = cc.id) AS total_earned
        FROM coin_cycles cc
        LEFT JOIN reward_groups rg ON cc.reward_group_id = rg.id
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
        WHERE bm.is_active = 1 AND bm.member_status != 'refunded'
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
        WHERE member_role IN ('leader', 'subleader') AND is_active = 1 AND member_status != 'refunded'
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

/**
 * leader/subleader → 자기 조의 group_id 자동 결정.
 * 나머지 롤 → 요청 파라미터의 group_id 사용 (필수).
 *
 * 조장/부조장은 member.php 로그인 플로우로 admin 세션을 생성 — `admin_id`가 곧 `bootcamp_members.id`.
 * 일반 관리자(operation/coach/head/subhead*)는 `admin_id`가 `admins.id`.
 *
 * @return int|null group_id, 실패 시 null
 */
function resolveCheerGroupId($db, $admin, $requestedGroupId) {
    if (hasRole($admin, 'leader') || hasRole($admin, 'subleader')) {
        // admin_id IS bootcamp_members.id (member.php loginAdmin 호출 결과)
        $mStmt = $db->prepare("SELECT group_id FROM bootcamp_members WHERE id = ? AND is_active = 1");
        $mStmt->execute([$admin['admin_id']]);
        $gid = (int)($mStmt->fetchColumn() ?: 0);
        return $gid ?: null;
    }
    // operation / coach / head / subhead*: request group_id 필수
    return $requestedGroupId ?: null;
}

/**
 * admin 세션에서 회원 ID 추출 (granted_by 용).
 * leader/subleader: admin_id=member_id
 * 나머지: admins.member_id (NULL 가능)
 */
function resolveGrantorMemberId($db, $admin) {
    if (hasRole($admin, 'leader') || hasRole($admin, 'subleader')) {
        return (int)$admin['admin_id'];
    }
    $stmt = $db->prepare("SELECT member_id FROM admins WHERE id = ?");
    $stmt->execute([$admin['admin_id']]);
    $mid = (int)($stmt->fetchColumn() ?: 0);
    return $mid ?: null;
}

function handleCoinCheerAward($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['leader', 'subleader', 'operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    $cycleId = (int)($input['cycle_id'] ?? 0);
    $reqGroupId = (int)($input['group_id'] ?? 0);
    $targetIds = $input['target_member_ids'] ?? [];
    if (!$cycleId || empty($targetIds)) jsonError('cycle_id, target_member_ids 필요');

    $db = getDB();
    $groupId = resolveCheerGroupId($db, $admin, $reqGroupId);
    if (!$groupId) jsonError('group_id가 필요하거나, 조장 계정의 조 정보가 없습니다.');

    $grantedByMemberId = resolveGrantorMemberId($db, $admin); // NULL 가능 (관리자 회원 미연결)

    $result = grantCheerAward($db, $cycleId, $groupId, $targetIds, $grantedByMemberId, $admin['admin_id']);
    if (isset($result['error'])) jsonError($result['error']);

    jsonSuccess($result, "응원상 {$result['granted']}명 지급 완료");
}

function handleCoinCheerStatus() {
    $admin = requireAdmin(['leader', 'subleader', 'operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $cycleId = (int)($_GET['cycle_id'] ?? 0);
    $reqGroupId = (int)($_GET['group_id'] ?? 0);
    if (!$cycleId) jsonError('cycle_id 필요');

    $db = getDB();
    $groupId = resolveCheerGroupId($db, $admin, $reqGroupId);
    if (!$groupId) jsonError('group_id가 필요하거나, 조장 계정의 조 정보가 없습니다.');

    // group 정보
    $gStmt = $db->prepare("
        SELECT bg.id, bg.name, bg.cohort_id, c.cohort AS cohort_name
        FROM bootcamp_groups bg
        JOIN cohorts c ON c.id = bg.cohort_id
        WHERE bg.id = ?
    ");
    $gStmt->execute([$groupId]);
    $group = $gStmt->fetch();
    if (!$group) jsonError('조를 찾을 수 없습니다.');

    // 지급 내역
    $stmt = $db->prepare("
        SELECT lca.target_member_id, bm.nickname, bm.real_name, lca.coin_amount, lca.created_at,
               gb.nickname AS granted_by_nickname
        FROM leader_cheer_awards lca
        JOIN bootcamp_members bm ON lca.target_member_id = bm.id
        LEFT JOIN bootcamp_members gb ON lca.granted_by_member_id = gb.id
        WHERE lca.cycle_id = ? AND lca.group_id = ?
        ORDER BY lca.created_at
    ");
    $stmt->execute([$cycleId, $groupId]);
    $awards = $stmt->fetchAll();

    // 조원 (이미 지급받은 사람 제외)
    $awardedIds = array_map(fn($a) => (int)$a['target_member_id'], $awards);
    $memberQuery = "
        SELECT id, nickname, real_name, member_role
        FROM bootcamp_members
        WHERE group_id = ? AND is_active = 1 AND member_status != 'refunded'
    ";
    $memberParams = [$groupId];
    if ($awardedIds) {
        $ph = implode(',', array_fill(0, count($awardedIds), '?'));
        $memberQuery .= " AND id NOT IN ($ph)";
        $memberParams = array_merge($memberParams, $awardedIds);
    }
    $memberQuery .= " ORDER BY nickname";
    $mStmt = $db->prepare($memberQuery);
    $mStmt->execute($memberParams);
    $members = $mStmt->fetchAll();

    jsonSuccess([
        'group'       => $group,
        'awards'      => $awards,
        'members'     => $members,
        'max_targets' => COIN_CHEER_MAX_TARGETS,
        'remaining'   => COIN_CHEER_MAX_TARGETS - count($awards),
    ]);
}

/**
 * active cohort의 조 목록 (응원상 지급용 picker)
 */
function handleCoinCheerGroups() {
    requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $db = getDB();
    $stmt = $db->query("
        SELECT bg.id, bg.name, bg.stage_no, bg.cohort_id, c.cohort AS cohort_name
        FROM bootcamp_groups bg
        JOIN cohorts c ON c.id = bg.cohort_id
        WHERE c.is_active = 1 AND bg.status = 'active'
        ORDER BY bg.stage_no, bg.name
    ");
    jsonSuccess(['groups' => $stmt->fetchAll()]);
}
