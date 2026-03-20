<?php
/**
 * Entrance Service
 * 입장 체크 조회/저장 — 리더가 자기 조원의 입장 여부를 관리
 */

/**
 * 입장 체크 목록 조회
 * GET ?action=entrance_list&cohort_id=X&group_id=Y
 */
function handleEntranceList() {
    $admin = requireAdmin(['leader', 'subleader', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2', 'operation']);
    $db = getDB();

    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$groupId) jsonError('group_id 필요');

    // 리더는 자기 조만 조회 가능
    $leaderGroup = getLeaderGroupScope($admin);
    if ($leaderGroup && $leaderGroup !== $groupId) {
        jsonError('담당 조의 회원만 조회할 수 있습니다.', 403);
    }

    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.entered
        FROM bootcamp_members bm
        WHERE bm.group_id = ? AND bm.is_active = 1
        ORDER BY bm.real_name, bm.nickname
    ");
    $stmt->execute([$groupId]);
    jsonSuccess(['members' => $stmt->fetchAll()]);
}

/**
 * 입장 여부 일괄 저장
 * POST ?action=entrance_save
 * body: { group_id: N, entries: [ { member_id: N, entered: 0|1 }, ... ] }
 */
function handleEntranceSave($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['leader', 'subleader']);
    $input = getJsonInput();

    $groupId = (int)($input['group_id'] ?? 0);
    $entries = $input['entries'] ?? [];

    if (!$groupId) jsonError('group_id 필요');
    if (empty($entries)) jsonError('저장할 데이터가 없습니다.');

    $db = getDB();

    // 리더는 자기 조만 수정 가능
    $leaderGroup = getLeaderGroupScope($admin);
    if (!$leaderGroup || $leaderGroup !== $groupId) {
        jsonError('담당 조의 회원만 수정할 수 있습니다.', 403);
    }

    // 요청된 member_id들이 실제로 해당 조 소속인지 검증
    $memberIds = array_map(fn($e) => (int)($e['member_id'] ?? 0), $entries);
    $memberIds = array_filter($memberIds, fn($id) => $id > 0);
    if (empty($memberIds)) jsonError('유효한 회원 ID가 없습니다.');

    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $stmt = $db->prepare("
        SELECT id FROM bootcamp_members
        WHERE id IN ($placeholders) AND group_id = ? AND is_active = 1
    ");
    $stmt->execute([...$memberIds, $groupId]);
    $validIds = array_column($stmt->fetchAll(), 'id');

    // 유효하지 않은 ID가 있으면 거부
    $invalidIds = array_diff($memberIds, $validIds);
    if (!empty($invalidIds)) {
        jsonError('담당 조에 속하지 않는 회원이 포함되어 있습니다: ' . implode(', ', $invalidIds), 403);
    }

    // 일괄 업데이트
    $updateStmt = $db->prepare("UPDATE bootcamp_members SET entered = ? WHERE id = ? AND group_id = ?");
    $db->beginTransaction();
    try {
        $updated = 0;
        foreach ($entries as $entry) {
            $mid = (int)($entry['member_id'] ?? 0);
            $entered = (int)($entry['entered'] ?? 0) ? 1 : 0;
            if ($mid > 0) {
                $updateStmt->execute([$entered, $mid, $groupId]);
                $updated += $updateStmt->rowCount();
            }
        }
        $db->commit();
        jsonSuccess(['updated' => $updated], '입장 체크가 저장되었습니다.');
    } catch (Exception $e) {
        $db->rollBack();
        jsonError('저장 중 오류가 발생했습니다.', 500);
    }
}
