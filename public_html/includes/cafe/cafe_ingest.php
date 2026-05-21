<?php
/**
 * cafe_posts ingestion + 보드 메타 조회 헬퍼.
 *
 * 의존:
 *   - getDB(), getSetting() ← config.php
 *   - resolveMemberByKey(), getMissionTypeId(), saveCheck() ← bootcamp_functions.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../bootcamp_functions.php';

/**
 * posted_at(KST 'Y-m-d H:i:s') → 과제 날짜.
 * 컷오프: 매일 07:00 KST.
 *   00:00:00 ~ 07:00:00 → 전날
 *   07:00:01 ~ 23:59:59 → 당일
 * (posted_at - 7시간 - 1초) 의 KST 날짜로 환산.
 */
function cafeAssignmentDateForPostedAt(string $postedAt): string {
    $dt = new DateTime($postedAt, new DateTimeZone('Asia/Seoul'));
    $dt->modify('-7 hours -1 second');
    return $dt->format('Y-m-d');
}

/**
 * 활성 보드 목록 (cafe_board_map.is_active=1).
 * @return array<int, array{menu_id:string, board_type:string}>
 */
function cafeFetchActiveBoards(): array {
    $db = getDB();
    $rows = $db->query("SELECT menu_id, board_type FROM cafe_board_map WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => [
        'menu_id'    => (string)$r['menu_id'],
        'board_type' => (string)$r['board_type'],
    ], $rows);
}

/**
 * cafe_article_id 가 cafe_posts 에 이미 존재하는지.
 * 신규 article 만 ingest 로 보내기 위한 사전 필터 (cron 측 최적화).
 */
function cafeArticleExists(string $articleId): bool {
    static $stmt = null;
    if ($stmt === null) {
        $stmt = getDB()->prepare("SELECT 1 FROM cafe_posts WHERE cafe_article_id = ? LIMIT 1");
    }
    $stmt->execute([$articleId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * 카페 게시글 일괄 ingestion.
 * - cafe_posts UPSERT (UNIQUE uk_cafe_article 로 dedupe)
 * - member_key 매칭되면 member_id 채움
 * - member_id + board_type + assignment_date 모두 있으면 saveCheck (source='automation', source_ref="cafe:{id}")
 *
 * `inserted` 의미: UPSERT 실행 횟수 (기존 호환). 실제 신규 INSERT 수 아님.
 * 호출 측에서 사전 필터하면 (cafeArticleExists) 신규 행 수와 일치.
 */
function ingestCafePosts(array $posts): array {
    $db = getDB();
    $results = ['inserted' => 0, 'skipped' => 0, 'error' => 0, 'unmapped' => 0];
    $unmappedKeys = [];

    // menu_id → board_type 매핑 로드
    $boardMapStmt = $db->query("SELECT menu_id, board_type FROM cafe_board_map WHERE is_active = 1");
    $boardMap = [];
    foreach ($boardMapStmt->fetchAll() as $bm) {
        $boardMap[(string)$bm['menu_id']] = $bm['board_type'];
    }

    $insertStmt = $db->prepare("
        INSERT INTO cafe_posts (cafe_article_id, title, member_key, nickname, board_type, posted_at, member_id, mission_checked, assignment_date, raw_data)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            nickname = VALUES(nickname),
            member_id = VALUES(member_id),
            mission_checked = VALUES(mission_checked),
            assignment_date = VALUES(assignment_date)
    ");

    // 닉 sync: 동일값 update 회피 가드 + PHP 측 batch cache 로 query 자체를 줄임.
    $updateNickStmt = $db->prepare("
        UPDATE bootcamp_members
           SET cafe_nickname = ?
         WHERE id = ?
           AND (cafe_nickname IS NULL OR cafe_nickname <> ?)
    ");

    $memberKeyCache = [];
    $memberCafeNickCache = [];

    foreach ($posts as $post) {
        $articleId      = $post['cafe_article_id'] ?? $post['article_id'] ?? '';
        $title          = $post['title']           ?? '';
        $memberKey      = $post['member_key']      ?? null;
        $nickname       = $post['nickname']        ?? null;
        $postedAt       = $post['posted_at']       ?? null;
        $missionChecked = (int)($post['mission_checked'] ?? 0);
        $assignmentDate = $post['assignment_date'] ?? null;

        $boardType = $post['board_type'] ?? null;
        if (!$boardType && isset($post['menu_id'])) {
            $boardType = $boardMap[(string)$post['menu_id']] ?? null;
        }

        if (!$articleId) {
            $results['error']++;
            continue;
        }

        $memberId = null;
        if ($memberKey) {
            if (isset($memberKeyCache[$memberKey])) {
                $memberId = $memberKeyCache[$memberKey];
            } else {
                $memberId = resolveMemberByKey($db, $memberKey);
                $memberKeyCache[$memberKey] = $memberId;
            }
            if (!$memberId) {
                $results['unmapped']++;
                $unmappedKeys[$memberKey] = $nickname ?? '';
            }
        }

        try {
            $insertStmt->execute([
                $articleId,
                $title,
                $memberKey,
                $nickname,
                $boardType,
                $postedAt,
                $memberId,
                $missionChecked,
                $assignmentDate,
                !empty($post) ? json_encode($post, JSON_UNESCAPED_UNICODE) : null,
            ]);
            $results['inserted']++;

            // 닉 sync: 매핑된 회원 + 비어있지 않은 닉만, batch 안 같은 회원은 1회만.
            if ($memberId && $nickname !== null && $nickname !== '') {
                if (!isset($memberCafeNickCache[$memberId])
                    || $memberCafeNickCache[$memberId] !== $nickname) {
                    $updateNickStmt->execute([$nickname, $memberId, $nickname]);
                    $memberCafeNickCache[$memberId] = $nickname;
                }
            }

            if ($memberId && $boardType && $assignmentDate) {
                $missionTypeId = getMissionTypeId($db, $boardType);
                if ($missionTypeId) {
                    saveCheck($db, $memberId, $assignmentDate, $missionTypeId, true, 'automation', "cafe:{$articleId}", null);
                }
            }
        } catch (PDOException $e) {
            $results['error']++;
        }
    }

    if (!empty($unmappedKeys)) {
        $results['unmapped_keys'] = $unmappedKeys;
    }
    return $results;
}
