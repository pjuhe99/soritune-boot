<?php
/**
 * 공통 회원 생성 함수
 * 단건 등록 / 일괄 등록 모두 이 함수를 사용
 */

require_once __DIR__ . '/member_stats.php';

if (!function_exists('calcParticipationCount')) {
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
}

/**
 * 회원 1명 생성 (단건/일괄 공용)
 *
 * @param PDO   $db
 * @param array $data  필수: cohort_id, nickname
 *                     선택: real_name, user_id, phone, group_id, cafe_member_key, member_role, stage_no, joined_at
 * @return int  생성된 member ID
 */
function createMember(PDO $db, array $data): int {
    $cohortId      = (int)$data['cohort_id'];
    $nickname      = $data['nickname'];
    $realName      = $data['real_name'] ?? null;
    $phone         = !empty($data['phone']) ? $data['phone'] : null;
    $userId        = !empty($data['user_id']) ? $data['user_id'] : null;
    $groupId       = !empty($data['group_id']) ? (int)$data['group_id'] : null;
    $cafeMemberKey = !empty($data['cafe_member_key']) ? $data['cafe_member_key'] : null;
    $memberRole    = $data['member_role'] ?? 'member';
    $stageNo       = (int)($data['stage_no'] ?? 1);
    $joinedAt      = $data['joined_at'] ?? date('Y-m-d');

    $participationCount = calcParticipationCount($db, $phone, $userId, $cohortId);

    $stmt = $db->prepare("
        INSERT INTO bootcamp_members (cohort_id, group_id, user_id, nickname, real_name, phone, cafe_member_key, member_role, stage_no, joined_at, participation_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $cohortId, $groupId, $userId, $nickname, $realName,
        $phone, $cafeMemberKey, $memberRole, $stageNo, $joinedAt,
        $participationCount,
    ]);
    $newId = (int)$db->lastInsertId();

    // member_scores, member_coin_balances 초기화
    $scoreStart = defined('SCORE_START') ? SCORE_START : 0;
    $db->prepare("INSERT INTO member_scores (member_id, current_score) VALUES (?, ?)")->execute([$newId, $scoreStart]);
    $db->prepare("INSERT INTO member_coin_balances (member_id, current_coin) VALUES (?, 0)")->execute([$newId]);

    // 집계 테이블 갱신
    refreshMemberStats($db, $phone, $userId);

    return $newId;
}
