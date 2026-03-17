<?php
/**
 * Group Assignment Service
 * 조 배정: 조장 관리, 조 CRUD(단계별), 자동 배정, 수동 이동
 */

// ══════════════════════════════════════════════════════════
// 조장 관리
// ══════════════════════════════════════════════════════════

/**
 * 조장 후보 목록 (해당 cohort/stage에서 leader 역할 가능한 회원)
 */
function handleLeaderCandidates() {
    requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');
    $stageNo = (int)($_GET['stage_no'] ?? 0);

    $db = getDB();
    $where = ["bm.cohort_id = ?", "bm.is_active = 1"];
    $params = [$cohortId];
    if ($stageNo) {
        $where[] = "bm.stage_no = ?";
        $params[] = $stageNo;
    }

    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.stage_no, bm.member_role, bm.group_id,
               bg.name AS group_name,
               (SELECT COUNT(*) FROM bootcamp_groups g2 WHERE g2.leader_member_id = bm.id) AS leader_group_count
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bm.member_role DESC, bm.nickname
    ");
    $stmt->execute($params);
    jsonSuccess(['candidates' => $stmt->fetchAll()]);
}

/**
 * 조장 지정 (member_role을 leader로 변경)
 */
function handleLeaderAssign($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $memberId = (int)($input['member_id'] ?? 0);
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();

    // 이미 다른 그룹의 leader인지 확인
    $stmt = $db->prepare("SELECT COUNT(*) FROM bootcamp_groups WHERE leader_member_id = ?");
    $stmt->execute([$memberId]);
    if ((int)$stmt->fetchColumn() > 0) {
        jsonError('이미 다른 조의 조장으로 지정된 회원입니다.');
    }

    $db->prepare("UPDATE bootcamp_members SET member_role = 'leader' WHERE id = ?")->execute([$memberId]);
    jsonSuccess([], '조장으로 지정되었습니다.');
}

/**
 * 조장 해제 (member_role을 member로 변경)
 */
function handleLeaderUnassign($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $memberId = (int)($input['member_id'] ?? 0);
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();

    // 이 회원이 그룹의 leader로 연결되어 있으면 해제 불가
    $stmt = $db->prepare("SELECT id, name FROM bootcamp_groups WHERE leader_member_id = ?");
    $stmt->execute([$memberId]);
    $linkedGroup = $stmt->fetch();
    if ($linkedGroup) {
        jsonError("'{$linkedGroup['name']}' 조의 조장으로 연결되어 있어 해제할 수 없습니다. 먼저 조에서 조장을 변경해주세요.");
    }

    $db->prepare("UPDATE bootcamp_members SET member_role = 'member' WHERE id = ?")->execute([$memberId]);
    jsonSuccess([], '조장이 해제되었습니다.');
}

// ══════════════════════════════════════════════════════════
// 조 CRUD (단계별 확장)
// ══════════════════════════════════════════════════════════

/**
 * 조 목록 (단계별 필터 + 통계)
 */
function handleGroupsWithStats() {
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');
    $stageNo = (int)($_GET['stage_no'] ?? 0);

    $db = getDB();
    $where = ["bg.cohort_id = ?"];
    $params = [$cohortId];
    if ($stageNo) {
        $where[] = "bg.stage_no = ?";
        $params[] = $stageNo;
    }

    $stmt = $db->prepare("
        SELECT bg.*,
               lm.nickname AS leader_nickname,
               lm.real_name AS leader_real_name,
               (SELECT sm.id FROM bootcamp_members sm WHERE sm.group_id = bg.id AND sm.member_role = 'subleader' AND sm.is_active = 1 LIMIT 1) AS subleader_member_id,
               (SELECT sm.nickname FROM bootcamp_members sm WHERE sm.group_id = bg.id AND sm.member_role = 'subleader' AND sm.is_active = 1 LIMIT 1) AS subleader_nickname,
               COUNT(bm.id) AS total_members,
               SUM(CASE WHEN bm.participation_count = 1 THEN 1 ELSE 0 END) AS new_members,
               SUM(CASE WHEN bm.participation_count > 1 THEN 1 ELSE 0 END) AS returning_members
        FROM bootcamp_groups bg
        LEFT JOIN bootcamp_members lm ON bg.leader_member_id = lm.id
        LEFT JOIN bootcamp_members bm ON bm.group_id = bg.id AND bm.is_active = 1
        WHERE " . implode(' AND ', $where) . "
        GROUP BY bg.id
        ORDER BY bg.stage_no, bg.name
    ");
    $stmt->execute($params);
    $groups = $stmt->fetchAll();

    // 미배정 인원 수
    $unWhere = ["bm.cohort_id = ?", "bm.group_id IS NULL", "bm.is_active = 1"];
    $unParams = [$cohortId];
    if ($stageNo) {
        $unWhere[] = "bm.stage_no = ?";
        $unParams[] = $stageNo;
    }
    $stmt2 = $db->prepare("SELECT COUNT(*) FROM bootcamp_members bm WHERE " . implode(' AND ', $unWhere));
    $stmt2->execute($unParams);
    $unassignedCount = (int)$stmt2->fetchColumn();

    jsonSuccess([
        'groups' => $groups,
        'unassigned_count' => $unassignedCount,
    ]);
}

/**
 * 조 생성 (단계 + 조장 필수)
 */
function handleGroupCreateExtended($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    $cohortId = (int)($input['cohort_id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $stageNo = (int)($input['stage_no'] ?? 0);
    $leaderMemberId = (int)($input['leader_member_id'] ?? 0);

    if (!$cohortId || !$name || !$stageNo) jsonError('cohort_id, name, stage_no 필요');
    if (!$leaderMemberId) jsonError('조장을 선택해주세요.');
    if ($stageNo < 1 || $stageNo > 2) jsonError('단계는 1 또는 2만 가능합니다.');

    $db = getDB();

    // 조장이 해당 cohort/stage 소속인지 확인
    $stmt = $db->prepare("SELECT id, stage_no FROM bootcamp_members WHERE id = ? AND cohort_id = ? AND is_active = 1");
    $stmt->execute([$leaderMemberId, $cohortId]);
    $leader = $stmt->fetch();
    if (!$leader) jsonError('유효하지 않은 조장입니다.');
    if ((int)$leader['stage_no'] !== $stageNo) {
        jsonError("{$stageNo}단계 조에는 {$stageNo}단계 조장만 가능합니다.");
    }

    // 이미 다른 조의 leader인지
    $stmt = $db->prepare("SELECT id FROM bootcamp_groups WHERE leader_member_id = ?");
    $stmt->execute([$leaderMemberId]);
    if ($stmt->fetch()) {
        jsonError('이 회원은 이미 다른 조의 조장입니다.');
    }

    // code 자동 생성 (cohort_id + stage + name 기반)
    $code = 'g' . $cohortId . 's' . $stageNo . '_' . preg_replace('/[^a-zA-Z0-9가-힣]/', '', $name);

    $db->prepare("
        INSERT INTO bootcamp_groups (cohort_id, name, code, stage_no, leader_member_id, status)
        VALUES (?, ?, ?, ?, ?, 'active')
    ")->execute([$cohortId, $name, $code, $stageNo, $leaderMemberId]);
    $groupId = (int)$db->lastInsertId();

    // 조장을 해당 조에 자동 배정
    $db->prepare("UPDATE bootcamp_members SET group_id = ?, group_assigned_at = NOW(), member_role = 'leader' WHERE id = ?")->execute([$groupId, $leaderMemberId]);

    jsonSuccess(['id' => $groupId], '조가 생성되었습니다.');
}

/**
 * 조 수정 (조장 변경 포함)
 */
function handleGroupUpdateExtended($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $db = getDB();

    // 기존 그룹 정보 조회
    $stmt = $db->prepare("SELECT * FROM bootcamp_groups WHERE id = ?");
    $stmt->execute([$id]);
    $group = $stmt->fetch();
    if (!$group) jsonError('조를 찾을 수 없습니다.');

    $fields = []; $params = [];

    if (isset($input['name'])) {
        $fields[] = "name = ?"; $params[] = trim($input['name']);
    }
    if (isset($input['kakao_link'])) {
        $fields[] = "kakao_link = ?"; $params[] = trim($input['kakao_link']) ?: null;
    }

    // 조장 변경
    if (isset($input['leader_member_id'])) {
        $newLeaderId = (int)$input['leader_member_id'];
        if ($newLeaderId && $newLeaderId !== (int)$group['leader_member_id']) {
            // 새 조장 유효성 검증
            $stmt = $db->prepare("SELECT id, stage_no FROM bootcamp_members WHERE id = ? AND cohort_id = ? AND is_active = 1");
            $stmt->execute([$newLeaderId, $group['cohort_id']]);
            $newLeader = $stmt->fetch();
            if (!$newLeader) jsonError('유효하지 않은 조장입니다.');
            if ((int)$newLeader['stage_no'] !== (int)$group['stage_no']) {
                jsonError("{$group['stage_no']}단계 조에는 {$group['stage_no']}단계 조장만 가능합니다.");
            }
            // 이미 다른 조의 leader인지
            $stmt = $db->prepare("SELECT id FROM bootcamp_groups WHERE leader_member_id = ? AND id != ?");
            $stmt->execute([$newLeaderId, $id]);
            if ($stmt->fetch()) jsonError('이 회원은 이미 다른 조의 조장입니다.');

            $fields[] = "leader_member_id = ?"; $params[] = $newLeaderId;

            // 기존 조장 role 해제
            if ($group['leader_member_id']) {
                $db->prepare("UPDATE bootcamp_members SET member_role = 'member' WHERE id = ? AND member_role = 'leader'")
                   ->execute([(int)$group['leader_member_id']]);
            }
            // 새 조장 role 설정 + 조 배정
            $db->prepare("UPDATE bootcamp_members SET member_role = 'leader', group_id = ?, group_assigned_at = NOW() WHERE id = ?")
               ->execute([$id, $newLeaderId]);
        }
    }

    // 부조장 변경
    $subleaderChanged = false;
    if (array_key_exists('subleader_member_id', $input)) {
        $newSubleaderId = $input['subleader_member_id'] ? (int)$input['subleader_member_id'] : null;

        // 기존 부조장 해제
        $db->prepare("UPDATE bootcamp_members SET member_role = 'member' WHERE group_id = ? AND member_role = 'subleader'")
           ->execute([$id]);

        // 새 부조장 지정
        if ($newSubleaderId) {
            // 해당 조 소속이고 조장이 아닌지 확인
            $stmt = $db->prepare("SELECT id, member_role FROM bootcamp_members WHERE id = ? AND group_id = ? AND is_active = 1");
            $stmt->execute([$newSubleaderId, $id]);
            $subMember = $stmt->fetch();
            if (!$subMember) jsonError('해당 조 소속 회원이 아닙니다.');
            if ($subMember['member_role'] === 'leader') jsonError('조장은 부조장으로 지정할 수 없습니다.');

            $db->prepare("UPDATE bootcamp_members SET member_role = 'subleader' WHERE id = ?")->execute([$newSubleaderId]);
        }
        $subleaderChanged = true;
    }

    if (!$fields && !$subleaderChanged) jsonError('수정할 내용 없음');
    if ($fields) {
        $params[] = $id;
        $db->prepare("UPDATE bootcamp_groups SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    }

    jsonSuccess([], '조 정보가 수정되었습니다.');
}

// ══════════════════════════════════════════════════════════
// 자동 배정
// ══════════════════════════════════════════════════════════

/**
 * 자동 배정 미리보기 (DB 저장 안 함)
 */
function handleAssignmentPreview() {
    requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    $stageNo = (int)($_GET['stage_no'] ?? 0);
    if (!$cohortId || !$stageNo) jsonError('cohort_id, stage_no 필요');

    $db = getDB();
    $result = generateAssignment($db, $cohortId, $stageNo);
    jsonSuccess($result);
}

/**
 * 자동 배정 확정 (DB 저장)
 */
function handleAssignmentConfirm($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $cohortId = (int)($input['cohort_id'] ?? 0);
    $stageNo = (int)($input['stage_no'] ?? 0);
    if (!$cohortId || !$stageNo) jsonError('cohort_id, stage_no 필요');

    $db = getDB();
    $result = generateAssignment($db, $cohortId, $stageNo);

    if (empty($result['assignments'])) {
        jsonError('배정할 대상이 없습니다.');
    }

    // 트랜잭션으로 일괄 저장
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("UPDATE bootcamp_members SET group_id = ?, group_assigned_at = NOW() WHERE id = ?");
        $count = 0;
        foreach ($result['assignments'] as $groupId => $memberIds) {
            foreach ($memberIds as $memberId) {
                $stmt->execute([$groupId, $memberId]);
                $count++;
            }
        }
        $db->commit();
        jsonSuccess(['assigned_count' => $count], "{$count}명이 조에 배정되었습니다.");
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('배정 중 오류가 발생했습니다: ' . $e->getMessage());
    }
}

/**
 * 자동 배정 로직 (미리보기/확정 공용)
 * 재수강/신규 분리 → 각각 셔플 → 라운드로빈 균등 배정
 */
function generateAssignment(PDO $db, int $cohortId, int $stageNo): array {
    // 1. 해당 단계의 조 목록 조회
    $stmt = $db->prepare("
        SELECT bg.id, bg.name, bg.leader_member_id,
               lm.nickname AS leader_nickname
        FROM bootcamp_groups bg
        LEFT JOIN bootcamp_members lm ON bg.leader_member_id = lm.id
        WHERE bg.cohort_id = ? AND bg.stage_no = ? AND bg.status = 'active'
        ORDER BY bg.name
    ");
    $stmt->execute([$cohortId, $stageNo]);
    $groups = $stmt->fetchAll();

    if (empty($groups)) {
        return [
            'groups' => [],
            'assignments' => [],
            'preview' => [],
            'unassigned' => [],
            'error' => '해당 단계에 생성된 조가 없습니다.',
        ];
    }

    // leader 없는 조 확인
    foreach ($groups as $g) {
        if (!$g['leader_member_id']) {
            return [
                'groups' => $groups,
                'assignments' => [],
                'preview' => [],
                'unassigned' => [],
                'error' => "'{$g['name']}' 조에 조장이 없습니다. 먼저 조장을 지정해주세요.",
            ];
        }
    }

    // 2. 미배정 회원 조회 (해당 cohort + stage + group_id IS NULL)
    $stmt = $db->prepare("
        SELECT id, nickname, real_name, participation_count, member_role
        FROM bootcamp_members
        WHERE cohort_id = ? AND stage_no = ? AND group_id IS NULL AND is_active = 1
              AND member_role != 'leader'
        ORDER BY id
    ");
    $stmt->execute([$cohortId, $stageNo]);
    $unassigned = $stmt->fetchAll();

    if (empty($unassigned)) {
        return [
            'groups' => $groups,
            'assignments' => [],
            'preview' => [],
            'unassigned' => [],
            'message' => '배정할 미배정 회원이 없습니다.',
        ];
    }

    // 3. 재수강/신규 분리
    $returning = [];
    $newMembers = [];
    foreach ($unassigned as $m) {
        if ((int)$m['participation_count'] > 1) {
            $returning[] = $m;
        } else {
            $newMembers[] = $m;
        }
    }

    // 4. 각각 셔플
    shuffle($returning);
    shuffle($newMembers);

    // 5. 그룹별 현재 인원 조회 (이미 배정된 인원 포함)
    $groupIds = array_column($groups, 'id');
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $stmt = $db->prepare("
        SELECT group_id, COUNT(*) AS cnt
        FROM bootcamp_members
        WHERE group_id IN ({$placeholders}) AND is_active = 1
        GROUP BY group_id
    ");
    $stmt->execute($groupIds);
    $currentCounts = [];
    foreach ($stmt->fetchAll() as $row) {
        $currentCounts[(int)$row['group_id']] = (int)$row['cnt'];
    }

    // 6. 라운드로빈 배정 (인원 적은 조 우선)
    $assignments = []; // groupId => [memberIds]
    $previewData = []; // groupId => { group info + assigned members }
    foreach ($groups as $g) {
        $gid = (int)$g['id'];
        $assignments[$gid] = [];
        $previewData[$gid] = [
            'group_id' => $gid,
            'group_name' => $g['name'],
            'leader_nickname' => $g['leader_nickname'],
            'existing_count' => $currentCounts[$gid] ?? 0,
            'new_assigned' => [],
            'returning_assigned' => [],
        ];
    }

    // 재수강 먼저 배정 (균등)
    assignRoundRobin($returning, $groups, $assignments, $previewData, $currentCounts, true);
    // 신규 배정 (균등)
    assignRoundRobin($newMembers, $groups, $assignments, $previewData, $currentCounts, false);

    // 미리보기용 통계 계산
    $preview = [];
    foreach ($groups as $g) {
        $gid = (int)$g['id'];
        $pd = $previewData[$gid];
        $preview[] = [
            'group_id' => $gid,
            'group_name' => $pd['group_name'],
            'leader_nickname' => $pd['leader_nickname'],
            'existing_count' => $pd['existing_count'],
            'new_assigned' => $pd['new_assigned'],
            'returning_assigned' => $pd['returning_assigned'],
            'total_after' => $pd['existing_count'] + count($pd['new_assigned']) + count($pd['returning_assigned']),
            'new_count' => count($pd['new_assigned']),
            'returning_count' => count($pd['returning_assigned']),
        ];
    }

    return [
        'groups' => $groups,
        'assignments' => $assignments,
        'preview' => $preview,
        'total_unassigned' => count($unassigned),
        'total_new' => count($newMembers),
        'total_returning' => count($returning),
    ];
}

/**
 * 라운드로빈 배정 헬퍼
 */
function assignRoundRobin(array $members, array $groups, array &$assignments, array &$previewData, array &$currentCounts, bool $isReturning): void {
    foreach ($members as $m) {
        // 현재 인원 가장 적은 조 찾기
        $minCount = PHP_INT_MAX;
        $targetGroupId = null;
        foreach ($groups as $g) {
            $gid = (int)$g['id'];
            $cnt = ($currentCounts[$gid] ?? 0) + count($assignments[$gid]);
            if ($cnt < $minCount) {
                $minCount = $cnt;
                $targetGroupId = $gid;
            }
        }
        if ($targetGroupId === null) continue;

        $assignments[$targetGroupId][] = (int)$m['id'];
        $memberInfo = ['id' => (int)$m['id'], 'nickname' => $m['nickname'], 'real_name' => $m['real_name']];
        if ($isReturning) {
            $previewData[$targetGroupId]['returning_assigned'][] = $memberInfo;
        } else {
            $previewData[$targetGroupId]['new_assigned'][] = $memberInfo;
        }
    }
}

// ══════════════════════════════════════════════════════════
// 배정 초기화
// ══════════════════════════════════════════════════════════

/**
 * 특정 단계의 배정 초기화 (조장 제외)
 */
function handleAssignmentReset($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $cohortId = (int)($input['cohort_id'] ?? 0);
    $stageNo = (int)($input['stage_no'] ?? 0);
    if (!$cohortId || !$stageNo) jsonError('cohort_id, stage_no 필요');

    $db = getDB();
    $stmt = $db->prepare("
        UPDATE bootcamp_members
        SET group_id = NULL, group_assigned_at = NULL
        WHERE cohort_id = ? AND stage_no = ? AND member_role != 'leader' AND is_active = 1
    ");
    $stmt->execute([$cohortId, $stageNo]);
    $count = $stmt->rowCount();

    jsonSuccess(['reset_count' => $count], "{$count}명의 조 배정이 초기화되었습니다.");
}

// ══════════════════════════════════════════════════════════
// 수동 이동
// ══════════════════════════════════════════════════════════

/**
 * 회원을 다른 조로 이동
 */
function handleMemberMove($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $memberId = (int)($input['member_id'] ?? 0);
    $targetGroupId = $input['target_group_id'] ?? null; // null = 미배정으로 이동
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();

    // 회원 정보
    $stmt = $db->prepare("SELECT id, stage_no, group_id, cohort_id FROM bootcamp_members WHERE id = ? AND is_active = 1");
    $stmt->execute([$memberId]);
    $member = $stmt->fetch();
    if (!$member) jsonError('회원을 찾을 수 없습니다.');

    if ($targetGroupId !== null) {
        $targetGroupId = (int)$targetGroupId;
        // 대상 조 정보
        $stmt = $db->prepare("SELECT id, stage_no, cohort_id FROM bootcamp_groups WHERE id = ?");
        $stmt->execute([$targetGroupId]);
        $targetGroup = $stmt->fetch();
        if (!$targetGroup) jsonError('대상 조를 찾을 수 없습니다.');

        // 같은 cohort인지
        if ((int)$targetGroup['cohort_id'] !== (int)$member['cohort_id']) {
            jsonError('같은 기수 내에서만 이동 가능합니다.');
        }

        // 같은 단계인지
        if ((int)$targetGroup['stage_no'] !== (int)$member['stage_no']) {
            jsonError('같은 단계 내에서만 이동 가능합니다.');
        }

        $db->prepare("UPDATE bootcamp_members SET group_id = ?, group_assigned_at = NOW() WHERE id = ?")
           ->execute([$targetGroupId, $memberId]);
    } else {
        // 미배정으로 이동
        $db->prepare("UPDATE bootcamp_members SET group_id = NULL, group_assigned_at = NULL WHERE id = ?")
           ->execute([$memberId]);
    }

    jsonSuccess([], '회원이 이동되었습니다.');
}

/**
 * 조별 회원 목록 (수동 이동용)
 */
function handleGroupMembers() {
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    $stageNo = (int)($_GET['stage_no'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');

    $db = getDB();
    $where = ["bm.cohort_id = ?", "bm.is_active = 1"];
    $params = [$cohortId];
    if ($stageNo) {
        $where[] = "bm.stage_no = ?";
        $params[] = $stageNo;
    }

    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.stage_no, bm.member_role,
               bm.group_id, bm.participation_count, bm.group_assigned_at,
               bg.name AS group_name
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bg.name, bm.member_role DESC, bm.nickname
    ");
    $stmt->execute($params);
    jsonSuccess(['members' => $stmt->fetchAll()]);
}

// ══════════════════════════════════════════════════════════
// 배정 현황 요약 (/head 대시보드용)
// ══════════════════════════════════════════════════════════

function handleAssignmentSummary() {
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');

    $db = getDB();

    // 단계별 인원
    $stmt = $db->prepare("
        SELECT stage_no,
               COUNT(*) AS total,
               SUM(CASE WHEN group_id IS NULL THEN 1 ELSE 0 END) AS unassigned,
               SUM(CASE WHEN group_id IS NOT NULL THEN 1 ELSE 0 END) AS assigned
        FROM bootcamp_members
        WHERE cohort_id = ? AND is_active = 1
        GROUP BY stage_no
    ");
    $stmt->execute([$cohortId]);
    $stageStats = $stmt->fetchAll();

    // 조별 인원 분포
    $stmt = $db->prepare("
        SELECT bg.id, bg.name, bg.stage_no, bg.leader_member_id,
               lm.nickname AS leader_nickname,
               (SELECT sm.nickname FROM bootcamp_members sm WHERE sm.group_id = bg.id AND sm.member_role = 'subleader' AND sm.is_active = 1 LIMIT 1) AS subleader_nickname,
               COUNT(bm.id) AS member_count,
               SUM(CASE WHEN bm.participation_count = 1 THEN 1 ELSE 0 END) AS new_count,
               SUM(CASE WHEN bm.participation_count > 1 THEN 1 ELSE 0 END) AS returning_count
        FROM bootcamp_groups bg
        LEFT JOIN bootcamp_members lm ON bg.leader_member_id = lm.id
        LEFT JOIN bootcamp_members bm ON bm.group_id = bg.id AND bm.is_active = 1
        WHERE bg.cohort_id = ? AND bg.status = 'active'
        GROUP BY bg.id
        ORDER BY bg.stage_no, bg.name
    ");
    $stmt->execute([$cohortId]);
    $groupStats = $stmt->fetchAll();

    jsonSuccess([
        'stage_stats' => $stageStats,
        'group_stats' => $groupStats,
    ]);
}
