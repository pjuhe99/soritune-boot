<?php
declare(strict_types=1);
/**
 * 주어진 member_id 들의 cafe_member_key 로 적재된 unmapped cafe_posts 를
 * 백필하고 미션 saveCheck 소급 호출.
 *
 * - paste 일괄 적용: 방금 등록한 회원 1명에 대해 호출
 * - handleCafeRemapUnmapped: 전체 활성 회원 ID 들에 대해 호출 (보드 폴링 누락분 복구)
 *
 * @return array{
 *   remapped:int, missions_saved:int,
 *   by_member:array<int,array{remapped:int, missions_saved:int}>
 * }
 */

require_once __DIR__ . '/../bootcamp_functions.php';

function backfillPostsForMembers(PDO $db, array $memberIds): array {
    $result = ['remapped' => 0, 'missions_saved' => 0, 'by_member' => []];
    if (empty($memberIds)) return $result;

    // 1) 회원 → cafe_member_key 매핑 조회 (key IS NULL 인 회원 제외)
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $memberStmt = $db->prepare("
        SELECT id, cafe_member_key FROM bootcamp_members
        WHERE id IN ({$placeholders}) AND cafe_member_key IS NOT NULL
    ");
    $memberStmt->execute(array_values($memberIds));

    $keyToMember = [];
    foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $keyToMember[$r['cafe_member_key']] = (int)$r['id'];
        $result['by_member'][(int)$r['id']] = ['remapped' => 0, 'missions_saved' => 0];
    }
    if (empty($keyToMember)) return $result;

    // 2) 그 키들로 적재된 unmapped cafe_posts 조회
    $keys = array_keys($keyToMember);
    $keyPlaceholders = implode(',', array_fill(0, count($keys), '?'));
    $postStmt = $db->prepare("
        SELECT id, cafe_article_id, member_key, board_type, assignment_date
        FROM cafe_posts
        WHERE member_id IS NULL AND member_key IN ({$keyPlaceholders})
    ");
    $postStmt->execute($keys);

    $updateStmt = $db->prepare("UPDATE cafe_posts SET member_id = ?, mission_checked = 1 WHERE id = ?");

    foreach ($postStmt->fetchAll(PDO::FETCH_ASSOC) as $post) {
        $memberId = $keyToMember[$post['member_key']];
        $updateStmt->execute([$memberId, $post['id']]);
        $result['remapped']++;
        $result['by_member'][$memberId]['remapped']++;

        if (!empty($post['board_type']) && !empty($post['assignment_date'])) {
            $missionTypeId = getMissionTypeId($db, $post['board_type']);
            if ($missionTypeId) {
                $r = saveCheck(
                    $db, $memberId, $post['assignment_date'], $missionTypeId,
                    true, 'automation', "cafe:{$post['cafe_article_id']}", null
                );
                if (isset($r['action']) && in_array($r['action'], ['created', 'updated'], true)) {
                    $result['missions_saved']++;
                    $result['by_member'][$memberId]['missions_saved']++;
                }
            }
        }
    }

    // 닉 백필: 방금 매핑된 회원들의 최신 cafe_posts.nickname 으로 cafe_nickname 1회 갱신.
    // post 가 없는 회원은 자연 skip (다음 cron poll 에서 ingest sync 가 채움).
    if (!empty($keyToMember)) {
        $nickStmt = $db->prepare("
            SELECT nickname FROM cafe_posts
            WHERE member_key = ? AND nickname IS NOT NULL
            ORDER BY posted_at DESC, id DESC LIMIT 1
        ");
        $memberNickStmt = $db->prepare("
            UPDATE bootcamp_members
               SET cafe_nickname = ?
             WHERE id = ?
               AND (cafe_nickname IS NULL OR cafe_nickname <> ?)
        ");
        foreach ($keyToMember as $key => $mid) {
            $nickStmt->execute([$key]);
            $latestNick = $nickStmt->fetchColumn();
            if ($latestNick) {
                $memberNickStmt->execute([$latestNick, $mid, $latestNick]);
            }
        }
    }

    return $result;
}
