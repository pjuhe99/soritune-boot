<?php
/**
 * Check Service
 * 체크리스트 조회, 체크 저장, 현황판
 */

function handleChecklist() {
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!$cohortId) jsonError('cohort_id 필요');

    $db = getDB();

    $where = ["bm.cohort_id = ?", "bm.is_active = 1"];
    $params = [$cohortId];
    if (!empty($_GET['group_id'])) { $where[] = "bm.group_id = ?"; $params[] = (int)$_GET['group_id']; }
    if (!empty($_GET['stage_no'])) { $where[] = "bm.stage_no = ?"; $params[] = (int)$_GET['stage_no']; }

    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
               bm.group_id, bg.name AS group_name,
               COALESCE(ms.current_score, 0) AS current_score
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bg.name, bm.nickname
    ");
    $stmt->execute($params);
    $members = $stmt->fetchAll();
    $memberIds = array_column($members, 'id');

    $checks = [];
    if ($memberIds) {
        $ph = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $db->prepare("
            SELECT member_id, mission_type_id, status, source
            FROM member_mission_checks
            WHERE member_id IN ({$ph}) AND check_date = ?
        ");
        $stmt->execute(array_merge($memberIds, [$date]));
        foreach ($stmt->fetchAll() as $c) {
            $checks[$c['member_id']][$c['mission_type_id']] = [
                'status' => (int)$c['status'],
                'source' => $c['source'],
            ];
        }
    }

    $missionTypes = $db->query("SELECT id, code, name FROM mission_types WHERE is_active = 1 ORDER BY display_order")->fetchAll();

    $cohortInfo = $db->prepare("SELECT start_date, end_date FROM cohorts WHERE id = ?");
    $cohortInfo->execute([$cohortId]);
    $ci = $cohortInfo->fetch();
    $scoringStart = $ci ? date('Y-m-d', strtotime($ci['start_date'] . ' + ' . SCORE_ADAPTATION_DAYS . ' days')) : null;
    $scoringEnd = $ci['end_date'] ?? null;

    jsonSuccess([
        'date' => $date,
        'members' => $members,
        'checks' => $checks,
        'mission_types' => $missionTypes,
        'scoring_start' => $scoringStart,
        'scoring_end' => $scoringEnd,
    ]);
}

function handleCheckSave($method, $admin) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $input = getJsonInput();

    $memberId = (int)($input['member_id'] ?? 0);
    $checkDate = $input['check_date'] ?? '';
    $missionCode = $input['mission_type_code'] ?? '';
    $status = isset($input['status']) ? (bool)$input['status'] : null;

    if (!$memberId || !$checkDate || !$missionCode || $status === null)
        jsonError('member_id, check_date, mission_type_code, status 필요');

    $db = getDB();
    $leaderGroup = getLeaderGroupScope($admin);
    if ($leaderGroup && !verifyMemberAccess($db, $memberId, $leaderGroup)) {
        jsonError('담당 조의 회원만 체크할 수 있습니다.', 403);
    }

    $missionTypeId = getMissionTypeId($db, $missionCode);
    if (!$missionTypeId) jsonError("유효하지 않은 mission_type_code: {$missionCode}");

    $result = saveCheck($db, $memberId, $checkDate, $missionTypeId, $status, 'manual', null, $admin['admin_id']);
    jsonSuccess($result, '체크가 저장되었습니다.');
}

function handleCheckBulkSave($method, $admin) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $input = getJsonInput();
    $checkDate = $input['check_date'] ?? '';
    $items = $input['items'] ?? [];

    if (!$checkDate || empty($items)) jsonError('check_date, items 필요');

    $db = getDB();
    $leaderGroup = getLeaderGroupScope($admin);
    $results = ['success' => 0, 'skipped' => 0, 'error' => 0];
    $changedMembers = [];

    foreach ($items as $item) {
        $memberId = (int)($item['member_id'] ?? 0);
        $missionCode = $item['mission_type_code'] ?? '';
        $status = isset($item['status']) ? (bool)$item['status'] : null;

        if (!$memberId || !$missionCode || $status === null) {
            $results['error']++;
            continue;
        }

        if ($leaderGroup && !verifyMemberAccess($db, $memberId, $leaderGroup)) {
            $results['error']++;
            continue;
        }

        $missionTypeId = getMissionTypeId($db, $missionCode);
        if (!$missionTypeId) { $results['error']++; continue; }

        $r = saveCheck($db, $memberId, $checkDate, $missionTypeId, $status, 'manual', null, $admin['admin_id'], true);
        if ($r['action'] === 'skipped') $results['skipped']++;
        elseif ($r['action'] === 'error') $results['error']++;
        else {
            $results['success']++;
            if ($r['action'] !== 'unchanged') {
                $changedMembers[$memberId] = true;
            }
        }
    }

    foreach (array_keys($changedMembers) as $mid) {
        recalculateMemberScore($db, $mid, $admin['admin_id']);
    }

    jsonSuccess($results, "처리 완료: {$results['success']}건 저장, {$results['skipped']}건 스킵, {$results['error']}건 오류");
}

function handleStatusBoard() {
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!$cohortId) jsonError('cohort_id 필요');

    $db = getDB();

    $where = ["bm.cohort_id = ?", "bm.is_active = 1"];
    $params = [$cohortId];
    if (!empty($_GET['group_id'])) { $where[] = "bm.group_id = ?"; $params[] = (int)$_GET['group_id']; }
    if (!empty($_GET['stage_no'])) { $where[] = "bm.stage_no = ?"; $params[] = (int)$_GET['stage_no']; }

    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
               bm.group_id, bm.member_status, bg.name AS group_name,
               COALESCE(ms.current_score, 0) AS current_score,
               COALESCE(mcb.current_coin, 0) AS current_coin
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        LEFT JOIN member_coin_balances mcb ON bm.id = mcb.member_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bg.name, bm.nickname
    ");
    $stmt->execute($params);
    $members = $stmt->fetchAll();
    $memberIds = array_column($members, 'id');

    $checks = [];
    $recentByMember = [];
    $warningNotes = [];
    if ($memberIds) {
        $ph = implode(',', array_fill(0, count($memberIds), '?'));

        // 오늘 체크
        $stmt = $db->prepare("
            SELECT member_id, mission_type_id, status
            FROM member_mission_checks
            WHERE member_id IN ({$ph}) AND check_date = ?
        ");
        $stmt->execute(array_merge($memberIds, [$date]));
        foreach ($stmt->fetchAll() as $c) {
            $checks[$c['member_id']][$c['mission_type_id']] = (int)$c['status'];
        }

        // 최근 10일 체크 (연속 미수행 계산)
        $stmt = $db->prepare("
            SELECT member_id, check_date, mission_type_id, status
            FROM member_mission_checks
            WHERE member_id IN ({$ph}) AND check_date >= DATE_SUB(CURDATE(), INTERVAL 10 DAY)
            ORDER BY check_date DESC
        ");
        $stmt->execute($memberIds);
        foreach ($stmt->fetchAll() as $c) {
            $recentByMember[$c['member_id']][$c['check_date']][(int)$c['mission_type_id']] = (int)$c['status'];
        }

        // 최근 경고 노트
        $stmt = $db->prepare("
            SELECT member_id, MAX(created_at) AS last_note_at
            FROM member_warning_notes
            WHERE member_id IN ({$ph}) AND warning_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
            GROUP BY member_id
        ");
        $stmt->execute($memberIds);
        foreach ($stmt->fetchAll() as $w) {
            $warningNotes[$w['member_id']] = $w['last_note_at'];
        }
    }

    $missionTypes = $db->query("SELECT id, code, name FROM mission_types WHERE is_active = 1 ORDER BY display_order")->fetchAll();
    $codeToId = getMissionCodeToIdMap($db);

    $missDays = [];
    foreach ($memberIds as $mid) {
        $byDate = $recentByMember[$mid] ?? [];
        $missDays[$mid] = calcConsecutiveMissDays($byDate, $codeToId);
    }

    jsonSuccess([
        'date' => $date,
        'members' => $members,
        'checks' => $checks,
        'mission_types' => $missionTypes,
        'miss_days' => $missDays,
        'warning_notes' => $warningNotes,
        'thresholds' => [
            'revival_candidate' => SCORE_REVIVAL_CANDIDATE,
            'revival_eligible' => SCORE_REVIVAL_ELIGIBLE,
            'out' => SCORE_OUT_THRESHOLD,
        ],
    ]);
}

function handleWarningNotes() {
    requireAdmin();
    $memberId = (int)($_GET['member_id'] ?? 0);
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT wn.*, a.name AS created_by_name
        FROM member_warning_notes wn
        LEFT JOIN admins a ON wn.created_by = a.id
        WHERE wn.member_id = ?
        ORDER BY wn.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$memberId]);
    jsonSuccess(['notes' => $stmt->fetchAll()]);
}

function handleWarningNoteCreate($method, $admin) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $input = getJsonInput();
    $memberId = (int)($input['member_id'] ?? 0);
    $note = trim($input['note'] ?? '');
    if (!$memberId || !$note) jsonError('member_id, note 필요');

    $db = getDB();
    $leaderGroup = getLeaderGroupScope($admin);
    if ($leaderGroup && !verifyMemberAccess($db, $memberId, $leaderGroup)) {
        jsonError('담당 조의 회원만 비고를 작성할 수 있습니다.', 403);
    }

    $db->prepare("
        INSERT INTO member_warning_notes (member_id, warning_date, note, created_by)
        VALUES (?, CURDATE(), ?, ?)
    ")->execute([$memberId, $note, $admin['admin_id']]);

    jsonSuccess([], '비고가 저장되었습니다.');
}
