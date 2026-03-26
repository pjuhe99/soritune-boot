<?php
/**
 * Member Page Service
 * 사용자 페이지 전용 읽기 API (과제 이력, 진도, 부티즈, 이벤트 로그)
 * 관리자 API(check.php)와 분리 — 회원 인증만 사용, 수정 기능 없음
 */

// ══════════════════════════════════════════════════════════════
// 공통 이벤트 로그 함수
// ══════════════════════════════════════════════════════════════

/** 허용된 이벤트 이름 목록 */
const ALLOWED_EVENTS = [
    'open_tab_calendar',
    'open_tab_assignments',
    'open_tab_curriculum',
    'open_tab_members',
    'click_calendar_stage_filter',
    'open_curriculum_item',
    'click_members_filter',
    'open_issue_report',
    'submit_issue_report',
    'open_my_issues',
];

/**
 * 이벤트 로그 저장 (공통)
 * 실패해도 예외를 던지지 않음 — 화면 기능에 영향 없도록
 *
 * @param int         $memberId
 * @param string      $eventName  ALLOWED_EVENTS 중 하나
 * @param string|null $eventValue 필터값, 항목ID 등
 * @param array|null  $meta       추가 메타 (JSON으로 저장)
 */
function logMemberEvent(int $memberId, string $eventName, ?string $eventValue = null, ?array $meta = null): void {
    try {
        $db = getDB();
        $cohortStmt = $db->prepare("SELECT cohort_id FROM bootcamp_members WHERE id = ?");
        $cohortStmt->execute([$memberId]);
        $cohortId = (int)$cohortStmt->fetchColumn();

        $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

        $db->prepare("
            INSERT INTO member_event_logs (member_id, cohort_id, event_name, event_value, meta_json, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([$memberId, $cohortId, $eventName, $eventValue, $metaJson]);
    } catch (\Throwable $e) {
        // 로그 실패는 무시 — 화면 기능 우선
        error_log("logMemberEvent failed: " . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════
// API Handlers
// ══════════════════════════════════════════════════════════════

/**
 * 과제 이력 요약 통계 (완료율, 연속 완료일수)
 */
function handleMemberChecksSummary() {
    $member = requireMember();
    $memberId = $member['member_id'];
    $db = getDB();
    $today = date('Y-m-d');

    // 회원의 cohort 기간 조회
    $cohortStmt = $db->prepare("
        SELECT c.start_date, c.end_date
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.id = ? AND bm.is_active = 1
    ");
    $cohortStmt->execute([$memberId]);
    $cohortInfo = $cohortStmt->fetch(PDO::FETCH_ASSOC);
    $cohortStart = $cohortInfo['start_date'] ?? '1970-01-01';
    $cohortEnd = $cohortInfo['end_date'] ?? $today;
    $endDate = min($today, $cohortEnd);

    // 전체 데이터 조회 (cohort 기간 내만)
    $stmt = $db->prepare("
        SELECT mmc.check_date, mt.code, mmc.status
        FROM member_mission_checks mmc
        JOIN mission_types mt ON mmc.mission_type_id = mt.id
        WHERE mmc.member_id = ? AND mmc.check_date >= ? AND mmc.check_date <= ?
    ");
    $stmt->execute([$memberId, $cohortStart, $endDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $optionalCodes = ['hamemmal', 'bookclub_join', 'bookclub_open'];

    // 날짜별 줌/데일리 상태 먼저 수집
    $zoomDailyByDate = [];
    foreach ($rows as $r) {
        if ($r['code'] === 'zoom_daily' || $r['code'] === 'daily_mission') {
            $zoomDailyByDate[$r['check_date']][$r['code']] = (int)$r['status'];
        }
    }

    // 날짜별 그룹핑 + 필터 적용
    $grouped = [];
    foreach ($rows as $r) {
        $code = $r['code'];
        $status = (int)$r['status'];
        $date = $r['check_date'];

        // 선택미션: 미완료면 제외
        if (in_array($code, $optionalCodes) && $status === 0) continue;

        // 말까미션: 월요일이 아니면 제외
        if ($code === 'speak_mission' && (int)date('N', strtotime($date)) !== 1) continue;

        // 데일리미션: 줌특강과 합산하므로 개별 카운트 제외
        if ($code === 'daily_mission') continue;

        // 줌특강: 줌 또는 데일리 중 하나라도 완료면 완료
        if ($code === 'zoom_daily') {
            $zoomStatus = $zoomDailyByDate[$date]['zoom_daily'] ?? 0;
            $dailyStatus = $zoomDailyByDate[$date]['daily_mission'] ?? 0;
            $status = ($zoomStatus || $dailyStatus) ? 1 : 0;
        }

        $grouped[$date][] = $status;
    }

    // 통계 계산
    $totalAll = 0;
    $totalDone = 0;
    $perfectDays = 0;
    $streak = 0;
    $streakBroken = false;

    krsort($grouped); // 날짜 역순 (streak 계산용)

    foreach ($grouped as $date => $statuses) {
        $dayTotal = count($statuses);
        $dayDone = array_sum($statuses);
        $totalAll += $dayTotal;
        $totalDone += $dayDone;

        if ($dayDone === $dayTotal && $dayTotal > 0) {
            $perfectDays++;
            if (!$streakBroken) $streak++;
        } else {
            $streakBroken = true;
        }
    }

    jsonSuccess([
        'total_checks' => $totalAll,
        'total_done' => $totalDone,
        'completion_rate' => $totalAll > 0 ? round($totalDone / $totalAll * 100) : 0,
        'total_days' => count($grouped),
        'perfect_days' => $perfectDays,
        'current_streak' => $streak,
    ]);
}

/**
 * 회원 본인의 과제 이력 조회 (읽기 전용)
 */
function handleMemberChecks() {
    $member = requireMember();
    $memberId = $member['member_id'];

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $db = getDB();
    $today = date('Y-m-d');

    // 회원의 cohort 정보
    $cohortStmt = $db->prepare("
        SELECT c.start_date, c.end_date, bm.stage_no
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.id = ? AND bm.is_active = 1
    ");
    $cohortStmt->execute([$memberId]);
    $cohortInfo = $cohortStmt->fetch(PDO::FETCH_ASSOC);
    $cohortStart = $cohortInfo['start_date'] ?? '1970-01-01';
    $cohortEnd = $cohortInfo['end_date'] ?? $today;
    $endDate = min($today, $cohortEnd);

    // 미션 타입 목록
    $missionTypes = $db->query(
        "SELECT id, code, name FROM mission_types WHERE is_active = 1 ORDER BY display_order"
    )->fetchAll(PDO::FETCH_ASSOC);
    $missionMap = [];
    foreach ($missionTypes as $mt) {
        $missionMap[(int)$mt['id']] = $mt;
    }

    // 전체 날짜 수 (cohort 기간 내만)
    $countStmt = $db->prepare("
        SELECT COUNT(DISTINCT check_date) AS cnt
        FROM member_mission_checks
        WHERE member_id = ? AND check_date >= ? AND check_date <= ?
    ");
    $countStmt->execute([$memberId, $cohortStart, $endDate]);
    $totalDates = (int)$countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalDates / $limit));

    // 날짜 목록 (최신순, cohort 기간 내만)
    $dateStmt = $db->prepare("
        SELECT DISTINCT check_date
        FROM member_mission_checks
        WHERE member_id = ? AND check_date >= ? AND check_date <= ?
        ORDER BY check_date DESC
        LIMIT ? OFFSET ?
    ");
    $dateStmt->execute([$memberId, $cohortStart, $endDate, $limit, $offset]);
    $dates = $dateStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($dates)) {
        jsonSuccess([
            'dates' => [],
            'page' => $page,
            'total_pages' => $totalPages,
            'mission_types' => $missionTypes,
        ]);
        return;
    }

    // 선택미션 코드 (완료일 때만 표시)
    $optionalCodes = ['hamemmal', 'bookclub_join', 'bookclub_open'];
    // 월요일만 표시하는 미션 코드
    $mondayOnlyCodes = ['speak_mission'];

    // 체크 데이터 일괄 조회 (mission_types JOIN으로 code 포함)
    $ph = implode(',', array_fill(0, count($dates), '?'));
    $checkStmt = $db->prepare("
        SELECT mmc.check_date, mmc.mission_type_id, mmc.status, mmc.source,
               mt.code AS mission_code
        FROM member_mission_checks mmc
        JOIN mission_types mt ON mmc.mission_type_id = mt.id
        WHERE mmc.member_id = ? AND mmc.check_date IN ({$ph})
        ORDER BY mmc.check_date DESC, mt.display_order
    ");
    $checkStmt->execute(array_merge([$memberId], $dates));
    $rows = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

    // 날짜별 그룹핑 + 필터 적용
    $grouped = [];
    // 줌/데일리 표시 필터용: 날짜별 status 먼저 수집
    $zoomDailyStatus = []; // $zoomDailyStatus[$date] = ['zoom_daily' => 0|1|null, 'daily_mission' => 0|1|null]
    foreach ($rows as $row) {
        $code = $row['mission_code'];
        $d = $row['check_date'];
        if ($code === 'zoom_daily' || $code === 'daily_mission') {
            $zoomDailyStatus[$d][$code] = (int)$row['status'];
        }
    }

    foreach ($rows as $row) {
        $d = $row['check_date'];
        $typeId = (int)$row['mission_type_id'];
        $code = $row['mission_code'];
        $status = (int)$row['status'];
        $mt = $missionMap[$typeId] ?? null;

        // 선택미션: 미완료면 숨김
        if (in_array($code, $optionalCodes) && $status === 0) continue;

        // 말까미션: 월요일이 아니면 숨김
        if (in_array($code, $mondayOnlyCodes) && (int)date('N', strtotime($d)) !== 1) continue;

        // 줌 특강 / 데일리미션 표시 필터
        // - 데일리미션 미완료(0) → 항상 숨김
        // - 줌 특강 미완료(0) + 데일리미션 완료(1) → 줌 숨김
        if ($code === 'daily_mission' && $status === 0) continue;
        if ($code === 'zoom_daily' && $status === 0
            && ($zoomDailyStatus[$d]['daily_mission'] ?? 0) === 1) continue;

        $grouped[$d][] = [
            'mission_type_id'   => $typeId,
            'mission_code'      => $code,
            'mission_name'      => $mt ? $mt['name'] : '(알 수 없음)',
            'status'            => $status,
            'source'            => $row['source'],
        ];
    }

    $result = [];
    foreach ($dates as $d) {
        $result[] = [
            'date'   => $d,
            'checks' => $grouped[$d] ?? [],
        ];
    }

    jsonSuccess([
        'dates' => $result,
        'page' => $page,
        'total_pages' => $totalPages,
        'mission_types' => $missionTypes,
        'cohort_start' => $cohortInfo['start_date'] ?? null,
        'stage_no' => $cohortInfo ? (int)$cohortInfo['stage_no'] : null,
    ]);
}

/**
 * 월별 진도 달력 데이터 조회 (회원용)
 */
function handleMemberCurriculum() {
    $member = requireMember();
    $cohort = $member['cohort'];

    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) jsonError('month 형식: YYYY-MM');

    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));

    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, target_date, task_type, note, sort_order
        FROM curriculum_items
        WHERE cohort = ? AND target_date BETWEEN ? AND ?
        ORDER BY target_date, sort_order ASC, id ASC
    ");
    $stmt->execute([$cohort, $startDate, $endDate]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as &$item) {
        $item['task_type_label'] = CURRICULUM_TYPE_LABELS[$item['task_type']] ?? $item['task_type'];
    }
    unset($item);

    jsonSuccess([
        'month' => $month,
        'items' => $items,
    ]);
}

/**
 * 진도 항목 상세 조회 + 열람 로그 저장
 */
function handleMemberCurriculumDetail() {
    $member = requireMember();
    $memberId = $member['member_id'];
    $itemId = (int)($_GET['item_id'] ?? 0);
    if (!$itemId) jsonError('item_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, cohort, target_date, task_type, note, sort_order
        FROM curriculum_items
        WHERE id = ?
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) jsonError('항목을 찾을 수 없습니다.', 404);

    $item['task_type_label'] = CURRICULUM_TYPE_LABELS[$item['task_type']] ?? $item['task_type'];

    // 열람 로그
    logMemberEvent($memberId, 'open_curriculum_item', (string)$itemId);

    jsonSuccess(['item' => $item]);
}

/**
 * 다른 부티즈 목록 (같은 cohort, 본인 제외)
 */
function handleMemberBootees() {
    $member = requireMember();
    $memberId = $member['member_id'];

    $db = getDB();

    $myStmt = $db->prepare("SELECT cohort_id, group_id FROM bootcamp_members WHERE id = ?");
    $myStmt->execute([$memberId]);
    $myInfo = $myStmt->fetch(PDO::FETCH_ASSOC);
    if (!$myInfo) jsonError('회원 정보를 찾을 수 없습니다.');

    $cohortId = (int)$myInfo['cohort_id'];
    $myGroupId = $myInfo['group_id'] ? (int)$myInfo['group_id'] : null;

    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.group_id, bg.name AS group_name,
               COALESCE(ms.current_score, 0) AS score,
               COALESCE(mcb.current_coin, 0) AS coin,
               COALESCE(mhs_p.completed_bootcamp_count, mhs_u.completed_bootcamp_count, 0) AS completed_count,
               COALESCE(mhs_p.bravo_grade, mhs_u.bravo_grade) AS bravo_grade
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        LEFT JOIN member_coin_balances mcb ON bm.id = mcb.member_id
        LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
        LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
        WHERE bm.cohort_id = ?
          AND bm.is_active = 1
          AND bm.member_status = 'active'
        ORDER BY coin DESC, bm.nickname ASC
    ");
    $stmt->execute([$cohortId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($members as &$m) {
        $m['group_id'] = $m['group_id'] ? (int)$m['group_id'] : null;
        $m['score'] = (int)$m['score'];
        $m['coin'] = (int)$m['coin'];
        $m['completed_count'] = (int)$m['completed_count'];
    }
    unset($m);

    jsonSuccess([
        'members' => $members,
        'my_group_id' => $myGroupId,
        'my_member_id' => (int)$memberId,
    ]);
}

/**
 * 범용 이벤트 로그 저장 API
 * 프론트에서 POST로 호출
 */
function handleMemberEventLog($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $member = requireMember();
    $input = getJsonInput();

    $eventName = trim($input['event_name'] ?? '');
    if (!$eventName) jsonError('event_name 필요');

    // 화이트리스트 검증
    if (!in_array($eventName, ALLOWED_EVENTS, true)) {
        jsonError('유효하지 않은 event_name');
    }

    $eventValue = isset($input['event_value']) ? trim((string)$input['event_value']) : null;

    logMemberEvent($member['member_id'], $eventName, $eventValue);

    jsonSuccess([]);
}
