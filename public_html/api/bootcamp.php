<?php
/**
 * boot.soritune.com - Bootcamp API
 * 관리자용 + 외부 연동용 부트캠프 핵심 기능 API
 */

require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');

$action = getAction();
$method = getMethod();

// ══════════════════════════════════════════════════════════════
// Service Functions
// ══════════════════════════════════════════════════════════════

/**
 * 점수 규칙 조회 (cohort/stage 우선순위 적용)
 * 우선순위: cohort+stage > cohort > stage > 공통
 */
function getScoreRule($db, $missionTypeId, $cohortId, $stageNo) {
    $stmt = $db->prepare("
        SELECT success_score, fail_score FROM mission_score_rules
        WHERE mission_type_id = ? AND is_active = 1
          AND (cohort_id = ? OR cohort_id IS NULL)
          AND (stage_no = ? OR stage_no IS NULL)
        ORDER BY
            (cohort_id IS NOT NULL) DESC,
            (stage_no IS NOT NULL) DESC
        LIMIT 1
    ");
    $stmt->execute([$missionTypeId, $cohortId, $stageNo]);
    return $stmt->fetch() ?: ['success_score' => 1, 'fail_score' => -1];
}

/**
 * 회원 점수 전체 재계산
 */
function recalculateMemberScore($db, $memberId, $adminId = null) {
    // 회원 정보 조회
    $member = $db->prepare("SELECT id, cohort_id, stage_no FROM bootcamp_members WHERE id = ?");
    $member->execute([$memberId]);
    $member = $member->fetch();
    if (!$member) return null;

    // 모든 유효 체크 조회
    $checks = $db->prepare("
        SELECT mission_type_id, status FROM member_mission_checks
        WHERE member_id = ?
    ");
    $checks->execute([$memberId]);
    $allChecks = $checks->fetchAll();

    // 미션 체크 기반 점수 합산
    $score = 0;
    foreach ($allChecks as $check) {
        $rule = getScoreRule($db, $check['mission_type_id'], $member['cohort_id'], $member['stage_no']);
        $score += $check['status'] ? $rule['success_score'] : $rule['fail_score'];
    }

    // revival_adjustment 반영: 패자부활전 로그에서 차이분 합산
    $revivals = $db->prepare("
        SELECT SUM(after_score - before_score) AS revival_delta
        FROM revival_logs WHERE member_id = ?
    ");
    $revivals->execute([$memberId]);
    $revivalDelta = (int)($revivals->fetch()['revival_delta'] ?? 0);

    // manual_adjustment 반영: score_logs에서 수동 조정분 합산
    $manuals = $db->prepare("
        SELECT SUM(score_change) AS manual_delta
        FROM score_logs WHERE member_id = ? AND reason_type = 'manual_adjustment'
    ");
    $manuals->execute([$memberId]);
    $manualDelta = (int)($manuals->fetch()['manual_delta'] ?? 0);

    $finalScore = $score + $revivalDelta + $manualDelta;

    // 현재 점수 조회 (before_score 로그용)
    $current = $db->prepare("SELECT current_score FROM member_scores WHERE member_id = ?");
    $current->execute([$memberId]);
    $currentRow = $current->fetch();
    $beforeScore = $currentRow ? (int)$currentRow['current_score'] : 0;

    // member_scores upsert
    $db->prepare("
        INSERT INTO member_scores (member_id, current_score, last_calculated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE current_score = VALUES(current_score), last_calculated_at = NOW()
    ")->execute([$memberId, $finalScore]);

    // recalculation 로그 (변동이 있을 때만)
    if ($finalScore !== $beforeScore) {
        $db->prepare("
            INSERT INTO score_logs (member_id, score_change, before_score, after_score, reason_type, reason_detail, created_by)
            VALUES (?, ?, ?, ?, 'recalculation', '전체 재계산', ?)
        ")->execute([$memberId, $finalScore - $beforeScore, $beforeScore, $finalScore, $adminId]);
    }

    return $finalScore;
}

/**
 * 체크 저장 (upsert) + 점수 반영
 * manual > automation 우선순위 적용
 */
function saveCheck($db, $memberId, $checkDate, $missionTypeId, $status, $source, $sourceRef, $adminId) {
    // 기존 체크 조회
    $existing = $db->prepare("
        SELECT id, status, source FROM member_mission_checks
        WHERE member_id = ? AND check_date = ? AND mission_type_id = ?
    ");
    $existing->execute([$memberId, $checkDate, $missionTypeId]);
    $existingRow = $existing->fetch();

    // manual > automation 우선순위: 기존이 manual이고 새 소스가 automation이면 스킵
    if ($existingRow && $existingRow['source'] === 'manual' && $source === 'automation') {
        return ['action' => 'skipped', 'reason' => 'manual data exists'];
    }

    // 회원 정보 (cohort_id, group_id)
    $member = $db->prepare("SELECT cohort_id, group_id FROM bootcamp_members WHERE id = ?");
    $member->execute([$memberId]);
    $memberRow = $member->fetch();
    if (!$memberRow) return ['action' => 'error', 'reason' => 'member not found'];

    $statusVal = $status ? 1 : 0;

    if ($existingRow) {
        // 값이 같으면 source만 업데이트할 수 있으나, 일단 업데이트 진행
        $db->prepare("
            UPDATE member_mission_checks
            SET status = ?, source = ?, source_ref = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$statusVal, $source, $sourceRef, $adminId, $existingRow['id']]);
        $action = ((int)$existingRow['status'] !== $statusVal) ? 'updated' : 'unchanged';
    } else {
        $db->prepare("
            INSERT INTO member_mission_checks
                (member_id, cohort_id, group_id, check_date, mission_type_id, status, source, source_ref, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$memberId, $memberRow['cohort_id'], $memberRow['group_id'], $checkDate, $missionTypeId, $statusVal, $source, $sourceRef, $adminId]);
        $action = 'created';
    }

    // 점수 재계산
    if ($action !== 'unchanged') {
        recalculateMemberScore($db, $memberId, $adminId);
    }

    return ['action' => $action];
}

/**
 * mission_type_code → id 변환
 */
function getMissionTypeId($db, $code) {
    $stmt = $db->prepare("SELECT id FROM mission_types WHERE code = ? AND is_active = 1");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

/**
 * 리더의 조 스코핑: 리더이면 자기 bootcamp_group_id 반환, operation이면 null(제한 없음)
 */
function getLeaderGroupScope($admin) {
    if (hasRole($admin, 'operation') || hasRole($admin, 'coach')) return null;
    return $admin['bootcamp_group_id'] ?? null;
}

/**
 * 리더가 해당 member_id에 접근 가능한지 확인
 */
function verifyMemberAccess($db, $memberId, $groupId) {
    if (!$groupId) return true; // operation (no restriction)
    $stmt = $db->prepare("SELECT id FROM bootcamp_members WHERE id = ? AND group_id = ?");
    $stmt->execute([$memberId, $groupId]);
    return (bool)$stmt->fetch();
}

/**
 * 외부 연동 API 키 인증
 */
function requireApiKey() {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!$key) jsonError('API key required', 401);

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM integration_api_keys WHERE api_key = ? AND is_active = 1");
    $stmt->execute([$key]);
    if (!$stmt->fetch()) jsonError('Invalid API key', 401);
}


// ══════════════════════════════════════════════════════════════
// API Routes
// ══════════════════════════════════════════════════════════════

switch ($action) {

// ── Cohorts ──────────────────────────────────────────────────

case 'cohorts':
    requireAdmin();
    $db = getDB();
    $stmt = $db->query("SELECT * FROM cohorts ORDER BY start_date DESC");
    jsonSuccess(['cohorts' => $stmt->fetchAll()]);
    break;

// ── Groups ───────────────────────────────────────────────────

case 'groups':
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM bootcamp_groups WHERE cohort_id = ? ORDER BY name");
    $stmt->execute([$cohortId]);
    jsonSuccess(['groups' => $stmt->fetchAll()]);
    break;

case 'group_create':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $cohortId = (int)($input['cohort_id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $code = trim($input['code'] ?? '');
    if (!$cohortId || !$name || !$code) jsonError('cohort_id, name, code 필요');

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, code) VALUES (?, ?, ?)");
    $stmt->execute([$cohortId, $name, $code]);
    jsonSuccess(['id' => (int)$db->lastInsertId()], '조가 추가되었습니다.');
    break;

case 'group_update':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $fields = []; $params = [];
    foreach (['name', 'code'] as $f) {
        if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = trim($input[$f]); }
    }
    if (!$fields) jsonError('수정할 내용 없음');
    $params[] = $id;
    $db = getDB();
    $db->prepare("UPDATE bootcamp_groups SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    jsonSuccess([], '조 정보가 수정되었습니다.');
    break;

case 'group_delete':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $db = getDB();
    $db->prepare("DELETE FROM bootcamp_groups WHERE id = ?")->execute([$id]);
    jsonSuccess([], '조가 삭제되었습니다.');
    break;

// ── Members ──────────────────────────────────────────────────

case 'members':
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');

    $db = getDB();
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
               COALESCE(mcb.current_coin, 0) AS current_coin
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        LEFT JOIN member_coin_balances mcb ON bm.id = mcb.member_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bg.name, bm.nickname
    ");
    $stmt->execute($params);
    jsonSuccess(['members' => $stmt->fetchAll()]);
    break;

case 'member_create':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $cohortId = (int)($input['cohort_id'] ?? 0);
    $nickname = trim($input['nickname'] ?? '');
    if (!$cohortId || !$nickname) jsonError('cohort_id, nickname 필요');

    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO bootcamp_members (user_id, cohort_id, group_id, nickname, real_name, member_role, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $input['user_id'] ?? null,
        $cohortId,
        $input['group_id'] ?? null,
        $nickname,
        trim($input['real_name'] ?? '') ?: null,
        $input['member_role'] ?? 'member',
        (int)($input['stage_no'] ?? 1),
        $input['joined_at'] ?? date('Y-m-d'),
    ]);
    $newId = (int)$db->lastInsertId();

    // member_scores, member_coin_balances 초기화
    $db->prepare("INSERT INTO member_scores (member_id, current_score) VALUES (?, 0)")->execute([$newId]);
    $db->prepare("INSERT INTO member_coin_balances (member_id, current_coin) VALUES (?, 0)")->execute([$newId]);

    jsonSuccess(['id' => $newId], '회원이 추가되었습니다.');
    break;

case 'member_update':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $fields = []; $params = [];
    foreach (['nickname', 'real_name'] as $f) {
        if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = trim($input[$f]) ?: null; }
    }
    foreach (['cohort_id', 'group_id', 'user_id'] as $f) {
        if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = $input[$f] ? (int)$input[$f] : null; }
    }
    if (isset($input['member_role'])) { $fields[] = "member_role = ?"; $params[] = $input['member_role']; }
    if (isset($input['stage_no'])) { $fields[] = "stage_no = ?"; $params[] = (int)$input['stage_no']; }
    if (isset($input['is_active'])) { $fields[] = "is_active = ?"; $params[] = $input['is_active'] ? 1 : 0; }
    if (!$fields) jsonError('수정할 내용 없음');

    $params[] = $id;
    $db = getDB();
    $db->prepare("UPDATE bootcamp_members SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    jsonSuccess([], '회원 정보가 수정되었습니다.');
    break;

case 'member_delete':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $db = getDB();
    $db->prepare("DELETE FROM bootcamp_members WHERE id = ?")->execute([$id]);
    jsonSuccess([], '회원이 삭제되었습니다.');
    break;

// ── Mission Types ────────────────────────────────────────────

case 'mission_types':
    requireAdmin();
    $db = getDB();
    $stmt = $db->query("SELECT * FROM mission_types WHERE is_active = 1 ORDER BY display_order");
    jsonSuccess(['mission_types' => $stmt->fetchAll()]);
    break;

// ── Checklist (체크리스트 조회) ───────────────────────────────

case 'checklist':
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    $date = $_GET['date'] ?? date('Y-m-d');
    if (!$cohortId) jsonError('cohort_id 필요');

    $db = getDB();

    // 회원 필터
    $where = ["bm.cohort_id = ?", "bm.is_active = 1"];
    $params = [$cohortId];
    if (!empty($_GET['group_id'])) { $where[] = "bm.group_id = ?"; $params[] = (int)$_GET['group_id']; }
    if (!empty($_GET['stage_no'])) { $where[] = "bm.stage_no = ?"; $params[] = (int)$_GET['stage_no']; }

    // 회원 목록 + 점수
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

    // 해당 날짜의 체크 데이터
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

    // 미션 타입
    $missionTypes = $db->query("SELECT id, code, name FROM mission_types WHERE is_active = 1 ORDER BY display_order")->fetchAll();

    jsonSuccess([
        'date' => $date,
        'members' => $members,
        'checks' => $checks,
        'mission_types' => $missionTypes,
    ]);
    break;

// ── Check Save (체크 저장) ───────────────────────────────────

case 'check_save':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'leader', 'coach']);
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
    break;

// ── Check Bulk Save (체크 일괄 저장) ─────────────────────────

case 'check_bulk_save':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'leader', 'coach']);
    $input = getJsonInput();
    $checkDate = $input['check_date'] ?? '';
    $items = $input['items'] ?? [];

    if (!$checkDate || empty($items)) jsonError('check_date, items 필요');

    $db = getDB();
    $leaderGroup = getLeaderGroupScope($admin);
    $results = ['success' => 0, 'skipped' => 0, 'error' => 0];

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

        $r = saveCheck($db, $memberId, $checkDate, $missionTypeId, $status, 'manual', null, $admin['admin_id']);
        if ($r['action'] === 'skipped') $results['skipped']++;
        elseif ($r['action'] === 'error') $results['error']++;
        else $results['success']++;
    }

    jsonSuccess($results, "처리 완료: {$results['success']}건 저장, {$results['skipped']}건 스킵, {$results['error']}건 오류");
    break;

// ── Status Board (현황판) ────────────────────────────────────

case 'status_board':
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

    // 체크 데이터
    $checks = [];
    if ($memberIds) {
        $ph = implode(',', array_fill(0, count($memberIds), '?'));
        $stmt = $db->prepare("
            SELECT member_id, mission_type_id, status
            FROM member_mission_checks
            WHERE member_id IN ({$ph}) AND check_date = ?
        ");
        $stmt->execute(array_merge($memberIds, [$date]));
        foreach ($stmt->fetchAll() as $c) {
            $checks[$c['member_id']][$c['mission_type_id']] = (int)$c['status'];
        }
    }

    $missionTypes = $db->query("SELECT id, code, name FROM mission_types WHERE is_active = 1 ORDER BY display_order")->fetchAll();

    jsonSuccess([
        'date' => $date,
        'members' => $members,
        'checks' => $checks,
        'mission_types' => $missionTypes,
        'elimination_threshold' => -10,
    ]);
    break;

// ── Revival Candidates (탈락 대상) ──────────────────────────

case 'revival_candidates':
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');

    $db = getDB();
    $where = ["bm.cohort_id = ?", "bm.is_active = 1", "COALESCE(ms.current_score, 0) <= -10"];
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
        ORDER BY ms.current_score ASC, bg.name, bm.nickname
    ");
    $stmt->execute($params);
    jsonSuccess(['candidates' => $stmt->fetchAll()]);
    break;

// ── Revival Process (패자부활전 처리) ────────────────────────

case 'revival_process':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $memberId = (int)($input['member_id'] ?? 0);
    $note = trim($input['note'] ?? '') ?: null;
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();

    // 현재 점수 조회
    $stmt = $db->prepare("SELECT current_score FROM member_scores WHERE member_id = ?");
    $stmt->execute([$memberId]);
    $scoreRow = $stmt->fetch();
    $beforeScore = $scoreRow ? (int)$scoreRow['current_score'] : 0;

    if ($beforeScore > -10) jsonError('탈락 기준(-10) 이하가 아닙니다. 현재 점수: ' . $beforeScore);

    $afterScore = -7;

    // revival_logs 저장
    $db->prepare("
        INSERT INTO revival_logs (member_id, before_score, after_score, note, created_by)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$memberId, $beforeScore, $afterScore, $note, $admin['admin_id']]);

    // score_logs 저장
    $change = $afterScore - $beforeScore;
    $db->prepare("
        INSERT INTO score_logs (member_id, score_change, before_score, after_score, reason_type, reason_detail, created_by)
        VALUES (?, ?, ?, ?, 'revival_adjustment', ?, ?)
    ")->execute([$memberId, $change, $beforeScore, $afterScore, $note, $admin['admin_id']]);

    // member_scores 갱신
    $db->prepare("
        INSERT INTO member_scores (member_id, current_score, last_calculated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE current_score = VALUES(current_score), last_calculated_at = NOW()
    ")->execute([$memberId, $afterScore]);

    jsonSuccess([
        'before_score' => $beforeScore,
        'after_score' => $afterScore,
    ], '패자부활전 처리가 완료되었습니다.');
    break;

// ── Revival Logs (패자부활전 이력) ───────────────────────────

case 'revival_logs':
    requireAdmin();
    $db = getDB();

    $where = ["1=1"];
    $params = [];
    if (!empty($_GET['member_id'])) { $where[] = "rl.member_id = ?"; $params[] = (int)$_GET['member_id']; }
    if (!empty($_GET['cohort_id'])) { $where[] = "bm.cohort_id = ?"; $params[] = (int)$_GET['cohort_id']; }
    if (!empty($_GET['group_id'])) { $where[] = "bm.group_id = ?"; $params[] = (int)$_GET['group_id']; }
    if (!empty($_GET['stage_no'])) { $where[] = "bm.stage_no = ?"; $params[] = (int)$_GET['stage_no']; }

    $stmt = $db->prepare("
        SELECT rl.*, bm.nickname, bm.real_name, bg.name AS group_name, a.name AS operator_name
        FROM revival_logs rl
        JOIN bootcamp_members bm ON rl.member_id = bm.id
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN admins a ON rl.created_by = a.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY rl.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    jsonSuccess(['logs' => $stmt->fetchAll()]);
    break;

// ── Coins (코인 잔액 조회) ───────────────────────────────────

case 'coin_balance':
    requireAdmin();
    $memberId = (int)($_GET['member_id'] ?? 0);
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT bm.nickname, bm.real_name, COALESCE(mcb.current_coin, 0) AS current_coin
        FROM bootcamp_members bm
        LEFT JOIN member_coin_balances mcb ON bm.id = mcb.member_id
        WHERE bm.id = ?
    ");
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    if (!$row) jsonError('회원을 찾을 수 없습니다.');
    jsonSuccess($row);
    break;

// ── Coin Change (코인 적립/차감) ─────────────────────────────

case 'coin_change':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $memberId = (int)($input['member_id'] ?? 0);
    $coinChange = (int)($input['coin_change'] ?? 0);
    $reasonType = trim($input['reason_type'] ?? '');
    $reasonDetail = trim($input['reason_detail'] ?? '') ?: null;

    if (!$memberId || !$coinChange || !$reasonType) jsonError('member_id, coin_change, reason_type 필요');

    $db = getDB();

    // 현재 코인 조회
    $stmt = $db->prepare("SELECT current_coin FROM member_coin_balances WHERE member_id = ?");
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    $beforeCoin = $row ? (int)$row['current_coin'] : 0;
    $afterCoin = $beforeCoin + $coinChange;

    // 범위 제한
    if ($afterCoin < 0) jsonError('코인이 부족합니다. 현재: ' . $beforeCoin);
    if ($afterCoin > 200) jsonError('최대 보유 코인(200)을 초과합니다. 현재: ' . $beforeCoin);

    // coin_logs 저장
    $db->prepare("
        INSERT INTO coin_logs (member_id, coin_change, before_coin, after_coin, reason_type, reason_detail, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$memberId, $coinChange, $beforeCoin, $afterCoin, $reasonType, $reasonDetail, $admin['admin_id']]);

    // member_coin_balances upsert
    $db->prepare("
        INSERT INTO member_coin_balances (member_id, current_coin)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE current_coin = VALUES(current_coin)
    ")->execute([$memberId, $afterCoin]);

    jsonSuccess([
        'before_coin' => $beforeCoin,
        'after_coin' => $afterCoin,
    ], '코인이 처리되었습니다.');
    break;

// ── Score Logs ───────────────────────────────────────────────

case 'score_logs':
    requireAdmin();
    $memberId = (int)($_GET['member_id'] ?? 0);
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT sl.*, a.name AS operator_name
        FROM score_logs sl
        LEFT JOIN admins a ON sl.created_by = a.id
        WHERE sl.member_id = ?
        ORDER BY sl.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$memberId]);
    jsonSuccess(['logs' => $stmt->fetchAll()]);
    break;

// ── Coin Logs ────────────────────────────────────────────────

case 'coin_logs':
    requireAdmin();
    $memberId = (int)($_GET['member_id'] ?? 0);
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT cl.*, a.name AS operator_name
        FROM coin_logs cl
        LEFT JOIN admins a ON cl.created_by = a.id
        WHERE cl.member_id = ?
        ORDER BY cl.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$memberId]);
    jsonSuccess(['logs' => $stmt->fetchAll()]);
    break;

// ── Score Manual Adjustment (수동 점수 조정) ─────────────────

case 'score_adjust':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $memberId = (int)($input['member_id'] ?? 0);
    $scoreChange = (int)($input['score_change'] ?? 0);
    $reasonDetail = trim($input['reason_detail'] ?? '') ?: null;

    if (!$memberId || !$scoreChange) jsonError('member_id, score_change 필요');

    $db = getDB();
    $stmt = $db->prepare("SELECT current_score FROM member_scores WHERE member_id = ?");
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    $beforeScore = $row ? (int)$row['current_score'] : 0;
    $afterScore = $beforeScore + $scoreChange;

    // score_logs
    $db->prepare("
        INSERT INTO score_logs (member_id, score_change, before_score, after_score, reason_type, reason_detail, created_by)
        VALUES (?, ?, ?, ?, 'manual_adjustment', ?, ?)
    ")->execute([$memberId, $scoreChange, $beforeScore, $afterScore, $reasonDetail, $admin['admin_id']]);

    // member_scores upsert
    $db->prepare("
        INSERT INTO member_scores (member_id, current_score, last_calculated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE current_score = VALUES(current_score), last_calculated_at = NOW()
    ")->execute([$memberId, $afterScore]);

    jsonSuccess([
        'before_score' => $beforeScore,
        'after_score' => $afterScore,
    ], '점수가 조정되었습니다.');
    break;

// ── Score Recalculate (점수 재계산) ──────────────────────────

case 'score_recalculate':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $memberId = (int)($input['member_id'] ?? 0);
    if (!$memberId) jsonError('member_id 필요');

    $db = getDB();
    $newScore = recalculateMemberScore($db, $memberId, $admin['admin_id']);
    if ($newScore === null) jsonError('회원을 찾을 수 없습니다.');
    jsonSuccess(['current_score' => $newScore], '점수가 재계산되었습니다.');
    break;

// ══════════════════════════════════════════════════════════════
// 외부 연동 API (n8n용)
// ══════════════════════════════════════════════════════════════

case 'integration_check':
    if ($method !== 'POST') jsonError('POST only', 405);
    requireApiKey();
    $input = getJsonInput();

    $memberId = (int)($input['member_id'] ?? 0);
    $checkDate = $input['check_date'] ?? '';
    $missionCode = $input['mission_type_code'] ?? '';
    $status = isset($input['status']) ? (bool)$input['status'] : null;
    $sourceRef = $input['source_ref'] ?? null;

    if (!$memberId || !$checkDate || !$missionCode || $status === null)
        jsonError('member_id, check_date, mission_type_code, status 필요');

    $db = getDB();
    $missionTypeId = getMissionTypeId($db, $missionCode);
    if (!$missionTypeId) jsonError("유효하지 않은 mission_type_code: {$missionCode}");

    $result = saveCheck($db, $memberId, $checkDate, $missionTypeId, $status, 'automation', $sourceRef, null);
    jsonSuccess($result);
    break;

case 'integration_check_bulk':
    if ($method !== 'POST') jsonError('POST only', 405);
    requireApiKey();
    $input = getJsonInput();
    $items = $input['items'] ?? [];
    if (empty($items)) jsonError('items 필요');

    $db = getDB();
    $results = ['success' => 0, 'skipped' => 0, 'error' => 0];

    foreach ($items as $item) {
        $memberId = (int)($item['member_id'] ?? 0);
        $checkDate = $item['check_date'] ?? '';
        $missionCode = $item['mission_type_code'] ?? '';
        $status = isset($item['status']) ? (bool)$item['status'] : null;
        $sourceRef = $item['source_ref'] ?? null;

        if (!$memberId || !$checkDate || !$missionCode || $status === null) { $results['error']++; continue; }

        $missionTypeId = getMissionTypeId($db, $missionCode);
        if (!$missionTypeId) { $results['error']++; continue; }

        $r = saveCheck($db, $memberId, $checkDate, $missionTypeId, $status, 'automation', $sourceRef, null);
        if ($r['action'] === 'skipped') $results['skipped']++;
        elseif ($r['action'] === 'error') $results['error']++;
        else $results['success']++;
    }

    jsonSuccess($results);
    break;

// ──────────────────────────────────────────────────────────────

default:
    jsonError('Unknown action', 404);
}
