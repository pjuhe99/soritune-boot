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
// 점수 시스템 상수
// ══════════════════════════════════════════════════════════════

const SCORE_START = 0;               // 시작 점수
const SCORE_ADAPTATION_DAYS = 3;     // 적응기간 (무감점)
const SCORE_OUT_THRESHOLD = -25;     // 조관리 제외 기준
const SCORE_REVIVAL_CANDIDATE = -13; // 패자부활 후보 표시 기준
const SCORE_REVIVAL_ELIGIBLE = -15;  // 패자부활 참여 가능 기준
const SCORE_REVIVAL_AFTER = -10;     // 패자부활 후 점수

// ══════════════════════════════════════════════════════════════
// Service Functions
// ══════════════════════════════════════════════════════════════

/**
 * 감점 규칙 (하루 단위)
 * codes 중 하나라도 수행(status=1)이면 감점 없음
 * weekday: 특정 요일에만 적용 (0=일, 1=월, ..., 3=수, 6=토). null이면 매일.
 */
function getPenaltyRules() {
    return [
        ['codes' => ['zoom_daily', 'daily_mission'], 'penalty' => -1, 'weekday' => null],
        ['codes' => ['inner33'], 'penalty' => -1, 'weekday' => null],
        ['codes' => ['speak_mission'], 'penalty' => -2, 'weekday' => 3], // 수요일만
    ];
}

/**
 * 미션 타입 코드→ID 매핑 (캐시)
 */
function getMissionCodeToIdMap($db) {
    static $map = null;
    if ($map !== null) return $map;
    $stmt = $db->query("SELECT id, code FROM mission_types WHERE is_active = 1");
    $map = [];
    foreach ($stmt->fetchAll() as $mt) {
        $map[$mt['code']] = (int)$mt['id'];
    }
    return $map;
}

/**
 * 연속 과제 미수행 일수 계산
 * 가장 최근 체크 날짜부터 역순으로, 점수 대상 미션이 모두 미수행인 연속 일수
 */
function calcConsecutiveMissDays($byDate, $codeToId) {
    $scoredCodes = ['zoom_daily', 'daily_mission', 'inner33', 'speak_mission'];
    $dates = array_keys($byDate);
    rsort($dates);

    $consecutive = 0;
    foreach ($dates as $date) {
        $missions = $byDate[$date];
        $anyDone = false;
        foreach ($scoredCodes as $code) {
            $typeId = $codeToId[$code] ?? null;
            if ($typeId && ($missions[$typeId] ?? 0) === 1) {
                $anyDone = true;
                break;
            }
        }
        if (!$anyDone) {
            $consecutive++;
        } else {
            break;
        }
    }
    return $consecutive;
}

/**
 * 회원 점수 전체 재계산 (v2 - 감점 기반)
 *
 * current_score = SCORE_START + penalty_sum + revival_delta + manual_delta
 */
function recalculateMemberScore($db, $memberId, $adminId = null) {
    // 회원 정보 조회
    $member = $db->prepare("SELECT id, cohort_id, stage_no FROM bootcamp_members WHERE id = ?");
    $member->execute([$memberId]);
    $member = $member->fetch();
    if (!$member) return null;

    // 기수 시작일 조회 → 적응기간 계산
    $cohort = $db->prepare("SELECT start_date FROM cohorts WHERE id = ?");
    $cohort->execute([$member['cohort_id']]);
    $cohortRow = $cohort->fetch();
    $adaptationEnd = $cohortRow
        ? date('Y-m-d', strtotime($cohortRow['start_date'] . ' + ' . (SCORE_ADAPTATION_DAYS - 1) . ' days'))
        : null;

    // 미션 타입 코드→ID 매핑
    $codeToId = getMissionCodeToIdMap($db);

    // 해당 회원의 체크 조회 → 날짜별 그룹핑
    $checks = $db->prepare("
        SELECT check_date, mission_type_id, status FROM member_mission_checks
        WHERE member_id = ? AND check_date > ?
        ORDER BY check_date
    ");
    $checks->execute([$memberId, $adaptationEnd ?? '1900-01-01']);
    $byDate = [];
    foreach ($checks->fetchAll() as $c) {
        $byDate[$c['check_date']][(int)$c['mission_type_id']] = (int)$c['status'];
    }

    // 감점 계산: 해당 회원에게 체크 기록이 있는 날짜만 대상
    // 명시적으로 status=0인 기록만 감점 (레코드 없음 = 미확인 = 감점 없음)
    $rules = getPenaltyRules();
    $penaltySum = 0;
    foreach ($byDate as $date => $missions) {
        $dow = (int)date('w', strtotime($date)); // 0=일, 1=월, ..., 3=수, 6=토

        foreach ($rules as $rule) {
            // 특정 요일 제한이 있으면 해당 요일만 감점 적용
            if ($rule['weekday'] !== null && $dow !== $rule['weekday']) continue;

            // 이 규칙에 해당하는 미션 중 하나라도 기록이 있는지 확인
            $hasRecord = false;
            $passed = false;
            foreach ($rule['codes'] as $code) {
                $typeId = $codeToId[$code] ?? null;
                if ($typeId && isset($missions[$typeId])) {
                    $hasRecord = true;
                    if ($missions[$typeId] === 1) {
                        $passed = true;
                        break;
                    }
                }
            }
            // 기록이 있고 하나도 통과 못한 경우에만 감점
            if ($hasRecord && !$passed) {
                $penaltySum += $rule['penalty'];
            }
        }
    }

    // revival_adjustment 반영
    $revivals = $db->prepare("
        SELECT SUM(after_score - before_score) AS revival_delta
        FROM revival_logs WHERE member_id = ?
    ");
    $revivals->execute([$memberId]);
    $revivalDelta = (int)($revivals->fetch()['revival_delta'] ?? 0);

    // manual_adjustment 반영
    $manuals = $db->prepare("
        SELECT SUM(score_change) AS manual_delta
        FROM score_logs WHERE member_id = ? AND reason_type = 'manual_adjustment'
    ");
    $manuals->execute([$memberId]);
    $manualDelta = (int)($manuals->fetch()['manual_delta'] ?? 0);

    $finalScore = SCORE_START + $penaltySum + $revivalDelta + $manualDelta;

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

    // 조관리 제외 상태 자동 갱신
    if ($finalScore <= SCORE_OUT_THRESHOLD) {
        $db->prepare("UPDATE bootcamp_members SET member_status = 'out_of_group_management' WHERE id = ? AND member_status = 'active'")
           ->execute([$memberId]);
    } else {
        $db->prepare("UPDATE bootcamp_members SET member_status = 'active' WHERE id = ? AND member_status = 'out_of_group_management'")
           ->execute([$memberId]);
    }

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
function saveCheck($db, $memberId, $checkDate, $missionTypeId, $status, $source, $sourceRef, $adminId, $skipRecalc = false) {
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

    // 점수 재계산 (bulk save에서는 건너뛰고 마지막에 일괄 처리)
    if ($action !== 'unchanged' && !$skipRecalc) {
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

/**
 * cafe_member_key → member_id 변환 (활성 기수 내에서 조회)
 */
function resolveMemberByKey($db, $cafeKey) {
    if (!$cafeKey) return null;
    $stmt = $db->prepare("
        SELECT id FROM bootcamp_members
        WHERE cafe_member_key = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$cafeKey]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

/**
 * integration_logs 저장
 */
function logIntegration($db, $executionId, $results, $unmappedKeys, $errorDetails) {
    $db->prepare("
        INSERT INTO integration_logs
            (execution_id, total_received, total_success, total_skipped, total_error, total_unmapped, unmapped_keys, error_details)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $executionId,
        $results['success'] + $results['skipped'] + $results['error'] + $results['unmapped'],
        $results['success'],
        $results['skipped'],
        $results['error'],
        $results['unmapped'],
        !empty($unmappedKeys) ? json_encode($unmappedKeys, JSON_UNESCAPED_UNICODE) : null,
        !empty($errorDetails) ? json_encode($errorDetails, JSON_UNESCAPED_UNICODE) : null,
    ]);
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
    $admin = requireAdmin(['operation', 'coach']);
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
    $admin = requireAdmin(['operation', 'coach']);
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
    $admin = requireAdmin(['operation', 'coach']);
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
               COALESCE(mcb.current_coin, 0) AS current_coin,
               CASE WHEN bm.cafe_member_key IS NOT NULL THEN 1 ELSE 0 END AS is_cafe_mapped
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
    $admin = requireAdmin(['operation', 'coach']);
    $input = getJsonInput();
    $cohortId = (int)($input['cohort_id'] ?? 0);
    $nickname = trim($input['nickname'] ?? '');
    if (!$cohortId || !$nickname) jsonError('cohort_id, nickname 필요');

    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO bootcamp_members (user_id, cohort_id, group_id, nickname, real_name, cafe_member_key, member_role, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $input['user_id'] ?? null,
        $cohortId,
        $input['group_id'] ?? null,
        $nickname,
        trim($input['real_name'] ?? '') ?: null,
        trim($input['cafe_member_key'] ?? '') ?: null,
        $input['member_role'] ?? 'member',
        (int)($input['stage_no'] ?? 1),
        $input['joined_at'] ?? date('Y-m-d'),
    ]);
    $newId = (int)$db->lastInsertId();

    // member_scores, member_coin_balances 초기화
    $db->prepare("INSERT INTO member_scores (member_id, current_score) VALUES (?, ?)")->execute([$newId, SCORE_START]);
    $db->prepare("INSERT INTO member_coin_balances (member_id, current_coin) VALUES (?, 0)")->execute([$newId]);

    jsonSuccess(['id' => $newId], '회원이 추가되었습니다.');
    break;

case 'member_update':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'coach']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $fields = []; $params = [];
    foreach (['nickname', 'real_name', 'cafe_member_key'] as $f) {
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
    $admin = requireAdmin(['operation', 'coach']);
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
    $changedMembers = []; // 변경된 회원 ID 추적

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

        // skipRecalc=true: 개별 재계산 건너뛰기
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

    // 변경된 회원만 한 번씩 재계산
    foreach (array_keys($changedMembers) as $mid) {
        recalculateMemberScore($db, $mid, $admin['admin_id']);
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

    // 오늘 체크 데이터
    $checks = [];
    // 최근 10일 체크 데이터 (연속 미수행 계산용)
    $recentByMember = [];
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

        // 최근 경고 노트 조회
        $stmt = $db->prepare("
            SELECT member_id, MAX(created_at) AS last_note_at
            FROM member_warning_notes
            WHERE member_id IN ({$ph}) AND warning_date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
            GROUP BY member_id
        ");
        $stmt->execute($memberIds);
        $warningNotes = [];
        foreach ($stmt->fetchAll() as $w) {
            $warningNotes[$w['member_id']] = $w['last_note_at'];
        }
    }

    $missionTypes = $db->query("SELECT id, code, name FROM mission_types WHERE is_active = 1 ORDER BY display_order")->fetchAll();
    $codeToId = getMissionCodeToIdMap($db);

    // 연속 미수행 일수 계산
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
        'warning_notes' => $warningNotes ?? [],
        'thresholds' => [
            'revival_candidate' => SCORE_REVIVAL_CANDIDATE,
            'revival_eligible' => SCORE_REVIVAL_ELIGIBLE,
            'out' => SCORE_OUT_THRESHOLD,
        ],
    ]);
    break;

// ── Revival Candidates (탈락 대상) ──────────────────────────

case 'revival_candidates':
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');

    $db = getDB();
    $where = ["bm.cohort_id = ?", "bm.is_active = 1", "COALESCE(ms.current_score, 0) <= " . SCORE_REVIVAL_ELIGIBLE];
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
    $admin = requireAdmin(['operation', 'coach']);
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

    if ($beforeScore > SCORE_REVIVAL_ELIGIBLE) jsonError('패자부활 기준(' . SCORE_REVIVAL_ELIGIBLE . ') 이하가 아닙니다. 현재 점수: ' . $beforeScore);

    $afterScore = SCORE_REVIVAL_AFTER;

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

    // 조관리 제외 상태 해제 (부활 후 기준 초과 시)
    if ($afterScore > SCORE_OUT_THRESHOLD) {
        $db->prepare("UPDATE bootcamp_members SET member_status = 'active' WHERE id = ? AND member_status = 'out_of_group_management'")
           ->execute([$memberId]);
    }

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
    $admin = requireAdmin(['operation', 'coach']);
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
    $admin = requireAdmin(['operation', 'coach']);
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
    $admin = requireAdmin(['operation', 'coach']);
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
    $memberKey = $input['member_key'] ?? null;
    $checkDate = $input['check_date'] ?? '';
    $missionCode = $input['mission_type_code'] ?? '';
    $status = isset($input['status']) ? (bool)$input['status'] : null;
    $sourceRef = $input['source_ref'] ?? null;

    $db = getDB();

    // member_key → member_id 변환 (member_id가 없을 때)
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
    break;

case 'integration_check_bulk':
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

    // member_key → member_id 매핑 캐시 (같은 요청 내 중복 조회 방지)
    $memberKeyCache = [];

    foreach ($items as $idx => $item) {
        $memberId = (int)($item['member_id'] ?? 0);
        $memberKey = $item['member_key'] ?? null;
        $checkDate = $item['check_date'] ?? '';
        $missionCode = $item['mission_type_code'] ?? '';
        $status = isset($item['status']) ? (bool)$item['status'] : null;
        $sourceRef = $item['source_ref'] ?? null;

        // member_key → member_id 변환
        if (!$memberId && $memberKey) {
            if (isset($memberKeyCache[$memberKey])) {
                $memberId = $memberKeyCache[$memberKey];
            } else {
                $memberId = resolveMemberByKey($db, $memberKey);
                $memberKeyCache[$memberKey] = $memberId; // null이어도 캐시
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

    // 실행 이력 로그 저장
    logIntegration($db, $executionId, $results, $unmappedKeys, $errorDetails);

    $response = $results;
    if (!empty($unmappedKeys)) {
        $response['details']['unmapped_keys'] = $unmappedKeys;
    }
    if (!empty($errorDetails)) {
        $response['details']['errors'] = $errorDetails;
    }

    jsonSuccess($response);
    break;

// ── Integration Member Map (회원 매핑 현황) ───────────────────

case 'integration_member_map':
    requireAdmin(['operation', 'coach']);
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
    break;

// ── Integration Logs (연동 실행 이력) ──────────────────────────

case 'integration_logs':
    requireAdmin(['operation', 'coach']);
    $db = getDB();
    $stmt = $db->query("
        SELECT * FROM integration_logs
        ORDER BY created_at DESC
        LIMIT 50
    ");
    jsonSuccess(['logs' => $stmt->fetchAll()]);
    break;

// ── Warning Notes (경고 노트) ─────────────────────────────────

case 'warning_notes':
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
    break;

case 'warning_note_create':
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'leader', 'coach']);
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
    break;

// ──────────────────────────────────────────────────────────────

default:
    jsonError('Unknown action', 404);
}
