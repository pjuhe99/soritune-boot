<?php
/**
 * Member Page Service
 * 사용자 페이지 전용 읽기 API (과제 이력, 탭 로그 등)
 * 관리자 API(check.php)와 분리 — 회원 인증만 사용, 수정 기능 없음
 */

/**
 * 회원 본인의 과제 이력 조회 (읽기 전용)
 * - today까지만 노출 (서버 기준)
 * - 최신순 정렬
 * - 페이지네이션 지원
 */
function handleMemberChecks() {
    $member = requireMember();
    $memberId = $member['member_id'];

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $db = getDB();
    $today = date('Y-m-d');

    // 회원의 cohort 정보 (적응기간 계산용)
    $cohortStmt = $db->prepare("
        SELECT c.start_date, c.end_date, bm.stage_no
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.id = ? AND bm.is_active = 1
    ");
    $cohortStmt->execute([$memberId]);
    $cohortInfo = $cohortStmt->fetch();

    // 미션 타입 목록
    $missionTypes = $db->query(
        "SELECT id, code, name FROM mission_types WHERE is_active = 1 ORDER BY display_order"
    )->fetchAll(PDO::FETCH_ASSOC);
    $missionMap = [];
    foreach ($missionTypes as $mt) {
        $missionMap[(int)$mt['id']] = $mt;
    }

    // 전체 날짜 수 (총 페이지 계산용)
    $countStmt = $db->prepare("
        SELECT COUNT(DISTINCT check_date) AS cnt
        FROM member_mission_checks
        WHERE member_id = ? AND check_date <= ?
    ");
    $countStmt->execute([$memberId, $today]);
    $totalDates = (int)$countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalDates / $limit));

    // 날짜 목록 (최신순, 페이지네이션)
    $dateStmt = $db->prepare("
        SELECT DISTINCT check_date
        FROM member_mission_checks
        WHERE member_id = ? AND check_date <= ?
        ORDER BY check_date DESC
        LIMIT ? OFFSET ?
    ");
    $dateStmt->execute([$memberId, $today, $limit, $offset]);
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

    // 해당 날짜들의 체크 데이터 일괄 조회
    $ph = implode(',', array_fill(0, count($dates), '?'));
    $checkStmt = $db->prepare("
        SELECT check_date, mission_type_id, status, source
        FROM member_mission_checks
        WHERE member_id = ? AND check_date IN ({$ph})
        ORDER BY check_date DESC
    ");
    $checkStmt->execute(array_merge([$memberId], $dates));
    $rows = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

    // 날짜별 그룹핑
    $grouped = [];
    foreach ($rows as $row) {
        $d = $row['check_date'];
        $typeId = (int)$row['mission_type_id'];
        $mt = $missionMap[$typeId] ?? null;

        $grouped[$d][] = [
            'mission_type_id'   => $typeId,
            'mission_code'      => $mt ? $mt['code'] : null,
            'mission_name'      => $mt ? $mt['name'] : '(알 수 없음)',
            'status'            => (int)$row['status'],
            'source'            => $row['source'],
        ];
    }

    // 날짜 배열 구성
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
 * curriculum_items를 해당 월의 전체 일자에 대해 반환
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

    // 열람 로그 저장
    $cohortStmt = $db->prepare("SELECT cohort_id FROM bootcamp_members WHERE id = ?");
    $cohortStmt->execute([$memberId]);
    $cohortId = (int)$cohortStmt->fetchColumn();

    $db->prepare("
        INSERT INTO member_page_logs (member_id, cohort_id, tab_name, viewed_at)
        VALUES (?, ?, ?, NOW())
    ")->execute([$memberId, $cohortId, 'curriculum_item:' . $itemId]);

    jsonSuccess(['item' => $item]);
}

/**
 * 탭 진입 로그 저장
 * member_page_logs 테이블에 기록
 */
function handleMemberPageLog($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $member = requireMember();
    $input = getJsonInput();

    $tabName = trim($input['tab_name'] ?? '');
    if (!$tabName) jsonError('tab_name 필요');

    // 허용 탭 목록 (화이트리스트)
    $allowedTabs = ['calendar', 'assignments', 'curriculum', 'members'];
    if (!in_array($tabName, $allowedTabs, true)) {
        jsonError('유효하지 않은 tab_name');
    }

    $db = getDB();
    $memberId = $member['member_id'];

    // cohort_id 조회
    $cohortStmt = $db->prepare("SELECT cohort_id FROM bootcamp_members WHERE id = ?");
    $cohortStmt->execute([$memberId]);
    $cohortId = (int)$cohortStmt->fetchColumn();

    $db->prepare("
        INSERT INTO member_page_logs (member_id, cohort_id, tab_name, viewed_at)
        VALUES (?, ?, ?, NOW())
    ")->execute([$memberId, $cohortId, $tabName]);

    jsonSuccess([]);
}
