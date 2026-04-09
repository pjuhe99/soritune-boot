<?php
/**
 * Integration Service
 * n8n 외부 연동 (체크, 카페 게시글), 연동 현황/로그
 */

function handleIntegrationCheck($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireApiKey();
    $input = getJsonInput();

    $memberId = (int)($input['member_id'] ?? 0);
    $memberKey = $input['member_key'] ?? null;
    $checkDate = $input['check_date'] ?? '';
    $missionCode = $input['mission_type_code'] ?? '';
    $status = isset($input['status']) ? (bool)$input['status'] : null;
    $sourceRef = $input['source_ref'] ?? null;

    $db = getDB();

    if (!$memberId && $memberKey) {
        $memberId = resolveMemberByKey($db, $memberKey);
        if (!$memberId) jsonError("매핑되지 않은 member_key: {$memberKey}", 422);
    }

    if (!$memberId || !$checkDate || !$missionCode || $status === null)
        jsonError('member_id 또는 member_key, check_date, mission_type_code, status 필요');

    $missionTypeId = getMissionTypeId($db, $missionCode);
    if (!$missionTypeId) jsonError("유효하지 않은 mission_type_code: {$missionCode}");

    $result = saveCheck($db, $memberId, $checkDate, $missionTypeId, $status, 'automation', $sourceRef, null);
    jsonSuccess($result);
}

function handleIntegrationCheckBulk($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireApiKey();
    $input = getJsonInput();
    $items = $input['items'] ?? [];
    $executionId = $input['execution_id'] ?? null;
    if (empty($items)) jsonError('items 필요');

    $db = getDB();
    $results = ['success' => 0, 'skipped' => 0, 'error' => 0, 'unmapped' => 0];
    $unmappedKeys = [];
    $errorDetails = [];
    $memberKeyCache = [];

    foreach ($items as $idx => $item) {
        $memberId = (int)($item['member_id'] ?? 0);
        $memberKey = $item['member_key'] ?? null;
        $checkDate = $item['check_date'] ?? '';
        $missionCode = $item['mission_type_code'] ?? '';
        $status = isset($item['status']) ? (bool)$item['status'] : null;
        $sourceRef = $item['source_ref'] ?? null;

        if (!$memberId && $memberKey) {
            if (isset($memberKeyCache[$memberKey])) {
                $memberId = $memberKeyCache[$memberKey];
            } else {
                $memberId = resolveMemberByKey($db, $memberKey);
                $memberKeyCache[$memberKey] = $memberId;
            }

            if (!$memberId) {
                $results['unmapped']++;
                $nickname = $item['nickname'] ?? '';
                $unmappedKeys[$memberKey] = $nickname;
                continue;
            }
        }

        if (!$memberId || !$checkDate || !$missionCode || $status === null) {
            $results['error']++;
            $errorDetails[] = ['index' => $idx, 'member_key' => $memberKey, 'reason' => 'missing required fields'];
            continue;
        }

        $missionTypeId = getMissionTypeId($db, $missionCode);
        if (!$missionTypeId) {
            $results['error']++;
            $errorDetails[] = ['index' => $idx, 'member_key' => $memberKey, 'reason' => "invalid mission_type_code: {$missionCode}"];
            continue;
        }

        $r = saveCheck($db, $memberId, $checkDate, $missionTypeId, $status, 'automation', $sourceRef, null);
        if ($r['action'] === 'skipped') $results['skipped']++;
        elseif ($r['action'] === 'error') $results['error']++;
        else $results['success']++;
    }

    logIntegration($db, $executionId, $results, $unmappedKeys, $errorDetails);

    $response = $results;
    if (!empty($unmappedKeys)) {
        $response['details']['unmapped_keys'] = $unmappedKeys;
    }
    if (!empty($errorDetails)) {
        $response['details']['errors'] = $errorDetails;
    }

    jsonSuccess($response);
}

function handleIntegrationMemberMap() {
    requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, nickname, real_name, cafe_member_key, group_id, stage_no
        FROM bootcamp_members
        WHERE cohort_id = ? AND is_active = 1
        ORDER BY cafe_member_key IS NULL DESC, nickname
    ");
    $stmt->execute([$cohortId]);
    $members = $stmt->fetchAll();

    $mapped = []; $unmapped = [];
    foreach ($members as $m) {
        if ($m['cafe_member_key']) {
            $mapped[] = $m;
        } else {
            $unmapped[] = $m;
        }
    }

    jsonSuccess(['mapped' => $mapped, 'unmapped' => $unmapped]);
}

function handleIntegrationLogs() {
    requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $db = getDB();
    $stmt = $db->query("
        SELECT * FROM integration_logs
        ORDER BY created_at DESC
        LIMIT 50
    ");
    jsonSuccess(['logs' => $stmt->fetchAll()]);
}

function handleIntegrationCafePosts($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireApiKey();
    $input = getJsonInput();
    $posts = $input['posts'] ?? [];
    if (empty($posts)) jsonError('posts 필요');

    $db = getDB();
    $results = ['inserted' => 0, 'skipped' => 0, 'error' => 0, 'unmapped' => 0];
    $unmappedKeys = [];

    // menu_id → board_type 매핑 로드
    $boardMapStmt = $db->query("SELECT menu_id, board_type FROM cafe_board_map WHERE is_active = 1");
    $boardMap = [];
    foreach ($boardMapStmt->fetchAll() as $bm) {
        $boardMap[$bm['menu_id']] = $bm['board_type'];
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

    $memberKeyCache = [];

    foreach ($posts as $post) {
        $articleId = $post['cafe_article_id'] ?? $post['article_id'] ?? '';
        $title = $post['title'] ?? '';
        $memberKey = $post['member_key'] ?? null;
        $nickname = $post['nickname'] ?? null;
        $postedAt = $post['posted_at'] ?? null;
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

            // 매핑된 회원 + board_type이 미션코드와 일치 + assignment_date가 있으면 자동 체크
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

    $response = $results;
    if (!empty($unmappedKeys)) {
        $response['details']['unmapped_keys'] = $unmappedKeys;
    }
    jsonSuccess($response);
}

function handleCafeRemapUnmapped($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);

    $db = getDB();

    // 1. cafe_posts에서 member_id IS NULL이지만 member_key가 있는 게시글 중,
    //    현재 bootcamp_members에 매핑된 것을 찾아 업데이트
    $unmappedStmt = $db->query("
        SELECT cp.id, cp.cafe_article_id, cp.member_key, cp.board_type, cp.assignment_date
        FROM cafe_posts cp
        WHERE cp.member_id IS NULL
          AND cp.member_key IS NOT NULL
          AND cp.member_key IN (
              SELECT cafe_member_key FROM bootcamp_members WHERE cafe_member_key IS NOT NULL AND is_active = 1
          )
    ");
    $unmappedPosts = $unmappedStmt->fetchAll();

    if (empty($unmappedPosts)) {
        jsonSuccess(['remapped' => 0, 'checked' => 0, 'message' => '재매핑할 게시글이 없습니다.']);
        return;
    }

    $updateStmt = $db->prepare("UPDATE cafe_posts SET member_id = ?, mission_checked = 1 WHERE id = ?");
    $remapped = 0;
    $checked = 0;
    $memberKeyCache = [];

    foreach ($unmappedPosts as $post) {
        $memberKey = $post['member_key'];

        if (isset($memberKeyCache[$memberKey])) {
            $memberId = $memberKeyCache[$memberKey];
        } else {
            $memberId = resolveMemberByKey($db, $memberKey);
            $memberKeyCache[$memberKey] = $memberId;
        }

        if (!$memberId) continue;

        // cafe_posts 업데이트
        $updateStmt->execute([$memberId, $post['id']]);
        $remapped++;

        // 체크리스트 반영
        if ($post['board_type'] && $post['assignment_date']) {
            $missionTypeId = getMissionTypeId($db, $post['board_type']);
            if ($missionTypeId) {
                $r = saveCheck($db, $memberId, $post['assignment_date'], $missionTypeId, true, 'automation', "cafe:{$post['cafe_article_id']}", null);
                if (in_array($r['action'], ['created', 'updated'])) {
                    $checked++;
                }
            }
        }
    }

    jsonSuccess([
        'remapped' => $remapped,
        'checked' => $checked,
        'message' => "{$remapped}건 재매핑, {$checked}건 체크리스트 반영 완료",
    ]);
}

function handleCafePosts() {
    requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $db = getDB();

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;

    $where = '1=1';
    $params = [];

    if (!empty($_GET['board_type'])) {
        $where .= ' AND cp.board_type = ?';
        $params[] = $_GET['board_type'];
    }
    if (!empty($_GET['date'])) {
        $where .= ' AND DATE(cp.posted_at) = ?';
        $params[] = $_GET['date'];
    }
    if (!empty($_GET['date_from'])) {
        $where .= ' AND DATE(cp.posted_at) >= ?';
        $params[] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $where .= ' AND DATE(cp.posted_at) <= ?';
        $params[] = $_GET['date_to'];
    }
    if (isset($_GET['mapped']) && $_GET['mapped'] !== '') {
        if ($_GET['mapped'] === '1') {
            $where .= ' AND cp.member_id IS NOT NULL';
        } else {
            $where .= ' AND cp.member_id IS NULL';
        }
    }
    if (!empty($_GET['keyword'])) {
        $where .= ' AND (cp.title LIKE ? OR cp.nickname LIKE ?)';
        $kw = '%' . $_GET['keyword'] . '%';
        $params[] = $kw;
        $params[] = $kw;
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM cafe_posts cp WHERE {$where}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;
    $stmt = $db->prepare("
        SELECT cp.*, bm.nickname AS member_nickname, bm.real_name AS member_real_name
        FROM cafe_posts cp
        LEFT JOIN bootcamp_members bm ON cp.member_id = bm.id
        WHERE {$where}
        ORDER BY cp.posted_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    $statsStmt2 = $db->prepare("
        SELECT board_type, COUNT(*) AS cnt,
               SUM(CASE WHEN member_id IS NOT NULL THEN 1 ELSE 0 END) AS mapped_cnt
        FROM cafe_posts
        GROUP BY board_type
    ");
    $statsStmt2->execute();
    $stats = $statsStmt2->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess([
        'posts' => $posts,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'stats' => $stats,
    ]);
}
