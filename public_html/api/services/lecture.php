<?php
/**
 * Lecture Service
 * 코치 강의 스케줄 생성/조회/상세/취소 + Zoom webhook
 */

// ══════════════════════════════════════════════════════════════
// Handlers
// ══════════════════════════════════════════════════════════════

/**
 * 코치 목록 (강의 생성 드롭다운용)
 * coach, sub_coach, head, subhead1, subhead2 역할을 가진 admin만 반환
 */
function handleLectureCoaches() {
    requireAdmin(['operation', 'head', 'subhead1', 'subhead2']);
    $db = getDB();

    $stmt = $db->query("
        SELECT DISTINCT a.id, a.name
        FROM admins a
        JOIN admin_roles ar ON a.id = ar.admin_id
        WHERE a.is_active = 1
          AND ar.role IN ('coach', 'sub_coach', 'head', 'subhead1', 'subhead2')
        ORDER BY a.name
    ");
    jsonSuccess(['coaches' => $stmt->fetchAll()]);
}

/**
 * 강의 스케줄 생성
 * 반복 규칙 저장 → 개별 세션 생성 → n8n webhook 호출
 */
function handleLectureScheduleCreate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    // ── 입력값 추출 ──
    $coachAdminId = (int)($input['coach_admin_id'] ?? 0);
    $cohortId     = (int)($input['cohort_id'] ?? 0);
    $stage        = (int)($input['stage'] ?? 0);
    $weekdays     = $input['weekdays'] ?? [];    // array of ints: [1,3,5]
    $startTime    = trim($input['start_time'] ?? '');  // "HH:MM"
    $hostAccount  = trim($input['host_account'] ?? '');

    // ── 입력값 검증 ──
    if (!$coachAdminId) jsonError('담당 코치를 선택해주세요.');
    if (!$cohortId)     jsonError('수업 기수를 선택해주세요.');
    if (!in_array($stage, [1, 2], true)) jsonError('단계를 선택해주세요 (1단계 또는 2단계).');
    if (!is_array($weekdays) || empty($weekdays)) jsonError('요일을 1개 이상 선택해주세요.');
    if (!preg_match('/^\d{2}:\d{2}$/', $startTime)) jsonError('시작 시간 형식: HH:MM');
    if (!in_array($hostAccount, ['coach1', 'coach2'], true)) jsonError('호스트 계정을 선택해주세요.');

    // 요일 검증 (1=월 ~ 7=일)
    $validWeekdays = [];
    foreach ($weekdays as $wd) {
        $wd = (int)$wd;
        if ($wd < 1 || $wd > 7) jsonError("잘못된 요일 값: {$wd}");
        $validWeekdays[] = $wd;
    }
    $validWeekdays = array_unique($validWeekdays);
    sort($validWeekdays);

    // 시간 범위 검증
    [$h, $m] = explode(':', $startTime);
    if ((int)$h < 0 || (int)$h > 23 || (int)$m < 0 || (int)$m > 59) {
        jsonError('유효하지 않은 시간입니다.');
    }

    $db = getDB();

    // ── 코치 존재 확인 ──
    $coachRow = $db->prepare("
        SELECT a.id, a.name
        FROM admins a
        JOIN admin_roles ar ON a.id = ar.admin_id
        WHERE a.id = ? AND a.is_active = 1
          AND ar.role IN ('coach', 'sub_coach', 'head', 'subhead1', 'subhead2')
        LIMIT 1
    ");
    $coachRow->execute([$coachAdminId]);
    $coach = $coachRow->fetch();
    if (!$coach) jsonError('유효한 코치를 찾을 수 없습니다.');

    // ── cohort 존재 + 기간 확인 ──
    $cohortRow = $db->prepare("SELECT * FROM cohorts WHERE id = ? AND is_active = 1");
    $cohortRow->execute([$cohortId]);
    $cohort = $cohortRow->fetch();
    if (!$cohort) jsonError('유효한 기수를 찾을 수 없습니다.');

    $cohortStart = $cohort['start_date'];
    $cohortEnd   = $cohort['end_date'];

    // ── 세션 날짜 계산 ──
    $sessionDates = generateLectureDates($cohortStart, $cohortEnd, $validWeekdays);
    if (empty($sessionDates)) {
        jsonError('선택한 요일에 해당하는 날짜가 기수 기간 내에 없습니다.');
    }

    // ── host 중복 검사 ──
    $startTimeFull = $startTime . ':00';
    $overlap = checkLectureOverlap($db, $sessionDates, $startTimeFull, $hostAccount);
    if ($overlap) {
        $dayNames = ['', '월', '화', '수', '목', '금', '토', '일'];
        $dow = (int)date('N', strtotime($overlap['lecture_date']));
        $dayLabel = $dayNames[$dow] ?? '';
        jsonError("중복: {$overlap['lecture_date']}({$dayLabel}) {$overlap['title']} — 같은 시간·호스트 계정에 이미 강의가 있습니다.");
    }

    // ── schedule 저장 ──
    $weekdaysStr = implode(',', $validWeekdays);
    $db->prepare("
        INSERT INTO lecture_schedules
            (cohort_id, coach_admin_id, stage, weekdays, start_time, duration_minutes,
             host_account, zoom_status, status, created_by)
        VALUES (?, ?, ?, ?, ?, 60, ?, 'ready', 'active', ?)
    ")->execute([
        $cohortId, $coachAdminId, $stage, $weekdaysStr,
        $startTimeFull, $hostAccount, $admin['admin_id'],
    ]);
    $scheduleId = (int)$db->lastInsertId();

    // ── 개별 세션 일괄 생성 ──
    $timeLabel = $startTime; // "HH:MM"
    $coachName = $coach['name'];
    $stageLabel = $stage . '단계';
    $title = "[{$timeLabel}] {$coachName} {$stageLabel} 강의";

    // end_time = start_time + duration
    $startDt = new DateTime("2000-01-01 {$startTimeFull}");
    $endDt = clone $startDt;
    $endDt->modify('+60 minutes');
    $endTimeFull = $endDt->format('H:i:s');

    $insertStmt = $db->prepare("
        INSERT INTO lecture_sessions
            (schedule_id, cohort_id, coach_admin_id, lecture_date, start_time, end_time,
             stage, host_account, title, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
    ");

    $count = 0;
    foreach ($sessionDates as $date) {
        $insertStmt->execute([
            $scheduleId, $cohortId, $coachAdminId,
            $date, $startTimeFull, $endTimeFull,
            $stage, $hostAccount, $title,
        ]);
        $count++;
    }

    // ── 단계별 고정 Zoom URL 설정 ──
    $zoomJoinUrl = getFixedZoomUrl($stage);

    $db->prepare("
        UPDATE lecture_schedules
        SET zoom_join_url = ?, zoom_status = 'ready', zoom_error_message = NULL
        WHERE id = ?
    ")->execute([$zoomJoinUrl, $scheduleId]);

    jsonSuccess([
        'schedule_id'   => $scheduleId,
        'session_count' => $count,
        'title'         => $title,
        'zoom_join_url' => $zoomJoinUrl,
    ], '강의 스케줄이 생성되었습니다.');
}

/**
 * 월별 강의 세션 목록 (달력용)
 * member 또는 admin 인증
 */
function handleLectureSessions() {
    // dual-auth: admin이면 admin, 아니면 member
    $cohortId = resolveLectureAuth();

    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) jsonError('month 형식: YYYY-MM');

    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));

    $db = getDB();

    $params = [$startDate, $endDate];
    $cohortWhere = '';
    if ($cohortId) {
        $cohortWhere = 'AND ls.cohort_id = ?';
        $params[] = $cohortId;
    }

    $stmt = $db->prepare("
        SELECT ls.id, ls.schedule_id, ls.title, ls.lecture_date, ls.start_time, ls.end_time,
               ls.stage, ls.host_account, ls.status, ls.coach_admin_id,
               a.name AS coach_name,
               lsch.zoom_status
        FROM lecture_sessions ls
        JOIN admins a ON ls.coach_admin_id = a.id
        JOIN lecture_schedules lsch ON ls.schedule_id = lsch.id
        WHERE ls.lecture_date BETWEEN ? AND ?
          AND ls.status = 'active'
          {$cohortWhere}
        ORDER BY ls.lecture_date, ls.start_time
    ");
    $stmt->execute($params);

    jsonSuccess([
        'month'    => $month,
        'sessions' => $stmt->fetchAll(),
    ]);
}

/**
 * 강의 세션 상세 (Zoom URL 포함)
 */
function handleLectureSessionDetail() {
    resolveLectureAuth();

    $sessionId = (int)($_GET['session_id'] ?? 0);
    if (!$sessionId) jsonError('session_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT ls.*, a.name AS coach_name,
               lsch.zoom_meeting_id, lsch.zoom_join_url, lsch.zoom_start_url,
               lsch.zoom_password, lsch.zoom_status, lsch.zoom_error_message,
               lsch.host_account AS schedule_host_account
        FROM lecture_sessions ls
        JOIN admins a ON ls.coach_admin_id = a.id
        JOIN lecture_schedules lsch ON ls.schedule_id = lsch.id
        WHERE ls.id = ?
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session) jsonError('강의를 찾을 수 없습니다.', 404);

    // admin이 아니면 zoom_start_url 숨김
    $isAdmin = false;
    if (!empty($_COOKIE['BOOT_ADMIN_SID'])) {
        $adminData = getAdminSession();
        if ($adminData) $isAdmin = true;
    }
    if (!$isAdmin) {
        unset($session['zoom_start_url']);
    }

    jsonSuccess(['session' => $session]);
}

/**
 * 강의 스케줄 취소 (미래 세션 일괄 취소)
 */
function handleLectureScheduleCancel($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    $scheduleId = (int)($input['schedule_id'] ?? 0);
    if (!$scheduleId) jsonError('schedule_id 필요');

    // from_date: 프론트에서 전달된 기준 날짜 (해당 세션의 lecture_date)
    $fromDate = $input['from_date'] ?? null;
    if ($fromDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
        jsonError('잘못된 날짜 형식입니다.');
    }
    if (!$fromDate) $fromDate = date('Y-m-d'); // fallback: 오늘

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM lecture_schedules WHERE id = ? AND status = 'active'");
    $stmt->execute([$scheduleId]);
    $schedule = $stmt->fetch();
    if (!$schedule) jsonError('활성 상태의 스케줄을 찾을 수 없습니다.', 404);

    // 기준 날짜 이후 세션 취소 (해당 날짜 포함)
    $db->prepare("
        UPDATE lecture_sessions SET status = 'cancelled'
        WHERE schedule_id = ? AND lecture_date >= ? AND status = 'active'
    ")->execute([$scheduleId, $fromDate]);
    $cancelledCount = $db->prepare("SELECT ROW_COUNT()")->fetchColumn();

    // 남은 active 세션이 없으면 스케줄도 취소
    $remaining = $db->prepare("
        SELECT COUNT(*) FROM lecture_sessions WHERE schedule_id = ? AND status = 'active'
    ");
    $remaining->execute([$scheduleId]);
    if ((int)$remaining->fetchColumn() === 0) {
        $db->prepare("UPDATE lecture_schedules SET status = 'cancelled' WHERE id = ?")->execute([$scheduleId]);
    }

    jsonSuccess([
        'cancelled_sessions' => (int)$cancelledCount,
    ], '강의 스케줄이 취소되었습니다.');
}

/**
 * Zoom 재설정 (스케줄 단위) — 단계별 고정 URL로 업데이트
 */
function handleLectureZoomRetry($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    $scheduleId = (int)($input['schedule_id'] ?? 0);
    if (!$scheduleId) jsonError('schedule_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT lsch.*, a.name AS coach_name
        FROM lecture_schedules lsch
        JOIN admins a ON lsch.coach_admin_id = a.id
        WHERE lsch.id = ? AND lsch.status = 'active'
    ");
    $stmt->execute([$scheduleId]);
    $schedule = $stmt->fetch();
    if (!$schedule) jsonError('스케줄을 찾을 수 없습니다.', 404);

    $zoomJoinUrl = getFixedZoomUrl((int)$schedule['stage']);

    $db->prepare("
        UPDATE lecture_schedules
        SET zoom_join_url = ?, zoom_status = 'ready', zoom_error_message = NULL
        WHERE id = ?
    ")->execute([$zoomJoinUrl, $scheduleId]);

    jsonSuccess([
        'zoom_join_url' => $zoomJoinUrl,
    ], 'Zoom URL이 설정되었습니다.');
}


// ══════════════════════════════════════════════════════════════
// Lecture Events (1회성 이벤트)
// ══════════════════════════════════════════════════════════════

/**
 * 이벤트 생성 — 1회성 날짜 + 직접 제목 + 컬러 + n8n Zoom
 */
function handleLectureEventCreate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    // ── 입력값 추출 ──
    $coachAdminId = (int)($input['coach_admin_id'] ?? 0);
    $cohortId     = (int)($input['cohort_id'] ?? 0);
    $stage        = (int)($input['stage'] ?? 0);
    $eventDate    = trim($input['event_date'] ?? '');
    $startTime    = trim($input['start_time'] ?? '');
    $title        = trim($input['title'] ?? '');
    $color        = trim($input['color'] ?? 'coral');
    $hostAccount  = trim($input['host_account'] ?? '');

    // ── 입력값 검증 ──
    if (!$coachAdminId) jsonError('담당 코치를 선택해주세요.');
    if (!$cohortId)     jsonError('수업 기수를 선택해주세요.');
    if (!in_array($stage, [1, 2], true)) jsonError('단계를 선택해주세요.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) jsonError('날짜를 선택해주세요.');
    if (!preg_match('/^\d{2}:\d{2}$/', $startTime)) jsonError('시작 시간 형식: HH:MM');
    if (!$title || mb_strlen($title) > 200) jsonError('제목을 입력해주세요 (최대 200자).');
    $validColors = ['coral', 'amber', 'violet', 'teal', 'slate'];
    if (!in_array($color, $validColors, true)) jsonError('유효한 색상을 선택해주세요.');
    if (!in_array($hostAccount, ['coach1', 'coach2'], true)) jsonError('호스트 계정을 선택해주세요.');

    [$h, $m] = explode(':', $startTime);
    if ((int)$h < 0 || (int)$h > 23 || (int)$m < 0 || (int)$m > 59) {
        jsonError('유효하지 않은 시간입니다.');
    }

    $db = getDB();

    // ── 코치 확인 ──
    $stmt = $db->prepare("
        SELECT a.id, a.name FROM admins a
        JOIN admin_roles ar ON a.id = ar.admin_id
        WHERE a.id = ? AND a.is_active = 1
          AND ar.role IN ('coach','sub_coach','head','subhead1','subhead2')
        LIMIT 1
    ");
    $stmt->execute([$coachAdminId]);
    $coach = $stmt->fetch();
    if (!$coach) jsonError('유효한 코치를 찾을 수 없습니다.');

    // ── cohort 확인 ──
    $stmt = $db->prepare("SELECT * FROM cohorts WHERE id = ? AND is_active = 1");
    $stmt->execute([$cohortId]);
    if (!$stmt->fetch()) jsonError('유효한 기수를 찾을 수 없습니다.');

    // ── host 중복 검사 (lecture_sessions + lecture_events 둘 다) ──
    $startTimeFull = $startTime . ':00';

    // 기존 특강 세션과 겹침
    $overlap = checkLectureOverlap($db, [$eventDate], $startTimeFull, $hostAccount);
    if ($overlap) {
        jsonError("중복: {$overlap['lecture_date']} {$overlap['title']} — 같은 시간·호스트 계정에 이미 강의가 있습니다.");
    }
    // 기존 이벤트와 겹침
    $overlapEvt = checkEventOverlap($db, $eventDate, $startTimeFull, $hostAccount);
    if ($overlapEvt) {
        jsonError("중복: {$overlapEvt['event_date']} {$overlapEvt['title']} — 같은 시간·호스트 계정에 이미 이벤트가 있습니다.");
    }

    // ── end_time 계산 ──
    $startDt = new DateTime("2000-01-01 {$startTimeFull}");
    $endDt = clone $startDt;
    $endDt->modify('+60 minutes');
    $endTimeFull = $endDt->format('H:i:s');

    // ── INSERT ──
    $db->prepare("
        INSERT INTO lecture_events
            (cohort_id, coach_admin_id, stage, event_date, start_time, end_time,
             title, color, host_account, zoom_status, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'active', ?)
    ")->execute([
        $cohortId, $coachAdminId, $stage, $eventDate,
        $startTimeFull, $endTimeFull,
        $title, $color, $hostAccount, $admin['admin_id'],
    ]);
    $eventId = (int)$db->lastInsertId();

    // ── n8n webhook → Zoom 생성 ──
    $zoomResult = callLectureEventZoomWebhook($db, $eventId, [
        'event_id'    => $eventId,
        'title'       => $title,
        'event_date'  => $eventDate,
        'start_time'  => $startTime,
        'end_time'    => $endDt->format('H:i'),
        'duration'    => 60,
        'host_account' => $hostAccount,
        'scheduled_at' => (new DateTime("{$eventDate} {$startTimeFull}", new DateTimeZone('Asia/Seoul')))->format('c'),
    ]);

    jsonSuccess([
        'event_id'     => $eventId,
        'title'        => $title,
        'zoom_join_url' => $zoomResult['zoom_join_url'] ?? null,
        'zoom_status'  => $zoomResult['success'] ? 'ready' : 'pending',
    ], '이벤트가 생성되었습니다.');
}

/**
 * 이벤트 취소
 */
function handleLectureEventCancel($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    $eventId = (int)($input['event_id'] ?? 0);
    if (!$eventId) jsonError('event_id 필요');

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM lecture_events WHERE id = ? AND status = 'active'");
    $stmt->execute([$eventId]);
    if (!$stmt->fetch()) jsonError('활성 상태의 이벤트를 찾을 수 없습니다.', 404);

    $db->prepare("UPDATE lecture_events SET status = 'cancelled' WHERE id = ?")->execute([$eventId]);
    jsonSuccess([], '이벤트가 취소되었습니다.');
}

/**
 * 이벤트 상세 (Zoom URL 포함)
 */
function handleLectureEventDetail() {
    resolveLectureAuth();

    $eventId = (int)($_GET['event_id'] ?? 0);
    if (!$eventId) jsonError('event_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT le.*, a.name AS coach_name
        FROM lecture_events le
        JOIN admins a ON le.coach_admin_id = a.id
        WHERE le.id = ?
    ");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    if (!$event) jsonError('이벤트를 찾을 수 없습니다.', 404);

    // admin이 아니면 zoom_start_url 숨김
    $isAdmin = false;
    if (!empty($_COOKIE['BOOT_ADMIN_SID'])) {
        $adminData = getAdminSession();
        if ($adminData) $isAdmin = true;
    }
    if (!$isAdmin) {
        unset($event['zoom_start_url']);
    }

    jsonSuccess(['event' => $event]);
}

/**
 * 이벤트 Zoom 재시도
 */
function handleLectureEventZoomRetry($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    $eventId = (int)($input['event_id'] ?? 0);
    if (!$eventId) jsonError('event_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT le.*, a.name AS coach_name
        FROM lecture_events le
        JOIN admins a ON le.coach_admin_id = a.id
        WHERE le.id = ? AND le.status = 'active'
    ");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    if (!$event) jsonError('이벤트를 찾을 수 없습니다.', 404);

    $startTime = substr($event['start_time'], 0, 5);
    $endTime   = substr($event['end_time'], 0, 5);

    $zoomResult = callLectureEventZoomWebhook($db, $eventId, [
        'event_id'     => $eventId,
        'title'        => $event['title'],
        'event_date'   => $event['event_date'],
        'start_time'   => $startTime,
        'end_time'     => $endTime,
        'duration'     => 60,
        'host_account' => $event['host_account'],
        'scheduled_at' => (new DateTime("{$event['event_date']} {$event['start_time']}", new DateTimeZone('Asia/Seoul')))->format('c'),
    ]);

    if (!$zoomResult['success']) {
        jsonError('Zoom 재시도 실패: ' . ($zoomResult['error'] ?? '알 수 없는 오류'));
    }

    jsonSuccess([
        'zoom_join_url' => $zoomResult['zoom_join_url'] ?? null,
    ], 'Zoom URL이 설정되었습니다.');
}

/**
 * 월별 이벤트 목록 (달력용) — lecture_sessions와 별도
 */
function handleLectureEvents() {
    $cohortId = resolveLectureAuth();

    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) jsonError('month 형식: YYYY-MM');

    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));

    $db = getDB();
    $params = [$startDate, $endDate];
    $cohortWhere = '';
    if ($cohortId) {
        $cohortWhere = 'AND le.cohort_id = ?';
        $params[] = $cohortId;
    }

    $stmt = $db->prepare("
        SELECT le.id, le.title, le.event_date, le.start_time, le.end_time,
               le.stage, le.host_account, le.color, le.status,
               le.coach_admin_id, le.zoom_status,
               a.name AS coach_name
        FROM lecture_events le
        JOIN admins a ON le.coach_admin_id = a.id
        WHERE le.event_date BETWEEN ? AND ?
          AND le.status = 'active'
          {$cohortWhere}
        ORDER BY le.event_date, le.start_time
    ");
    $stmt->execute($params);

    jsonSuccess([
        'month'  => $month,
        'events' => $stmt->fetchAll(),
    ]);
}

// ══════════════════════════════════════════════════════════════
// Internal Helpers
// ══════════════════════════════════════════════════════════════

/**
 * dual-auth: admin이면 cohort_id를 GET에서 받거나 null, member이면 자동 resolve
 * @return int|null cohort_id (null = 전체)
 */
function resolveLectureAuth(): ?int {
    // admin 시도
    if (!empty($_COOKIE['BOOT_ADMIN_SID'])) {
        try {
            $admin = getAdminSession();   // reads + closes session
            if ($admin) {
                $cid = (int)($_GET['cohort_id'] ?? 0);
                return $cid ?: null;
            }
        } catch (\Exception $e) {}
    }

    // member
    $member = requireMember();
    $db = getDB();
    $cohortId = getMemberCohortId($db, $member['member_id']);
    if (!$cohortId) jsonError('기수 정보를 찾을 수 없습니다.');
    return $cohortId;
}

/**
 * cohort 기간 + 선택 요일로 날짜 목록 생성
 * @param string $startDate  YYYY-MM-DD
 * @param string $endDate    YYYY-MM-DD
 * @param int[]  $weekdays   ISO weekday (1=Mon..7=Sun)
 * @return string[]          날짜 배열 (YYYY-MM-DD)
 */
function generateLectureDates(string $startDate, string $endDate, array $weekdays): array {
    $dates = [];
    $current = new DateTime($startDate);
    $end = new DateTime($endDate);

    while ($current <= $end) {
        $dow = (int)$current->format('N'); // 1=Mon..7=Sun
        if (in_array($dow, $weekdays, true)) {
            $dates[] = $current->format('Y-m-d');
        }
        $current->modify('+1 day');
    }

    return $dates;
}

/**
 * host 중복 검사
 * 같은 날짜 + 같은 시작 시간 + 같은 host_account인 active 세션이 있으면 반환
 */
function checkLectureOverlap(PDO $db, array $dates, string $startTime, string $hostAccount, ?int $excludeScheduleId = null): ?array {
    if (empty($dates)) return null;

    $placeholders = implode(',', array_fill(0, count($dates), '?'));
    $params = $dates;
    $params[] = $startTime;
    $params[] = $hostAccount;

    $excludeClause = '';
    if ($excludeScheduleId) {
        $excludeClause = 'AND ls.schedule_id != ?';
        $params[] = $excludeScheduleId;
    }

    $stmt = $db->prepare("
        SELECT ls.id, ls.lecture_date, ls.title, ls.start_time, ls.host_account
        FROM lecture_sessions ls
        WHERE ls.lecture_date IN ({$placeholders})
          AND ls.start_time = ?
          AND ls.host_account = ?
          AND ls.status = 'active'
          {$excludeClause}
        LIMIT 1
    ");
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

/**
 * 단계별 고정 Zoom URL 반환
 */
function getFixedZoomUrl(int $stage): string {
    $urls = [
        1 => 'https://us02web.zoom.us/j/81330750588?pwd=duqguPLdaLRSJel2ZGoCwGYtcKaAFi.1',
        2 => 'https://us02web.zoom.us/j/83575089340?pwd=mxHTGfd2ImbRxNv46KbCVPPKUlM7Ql.1',
    ];
    return $urls[$stage] ?? $urls[1];
}

/**
 * 이벤트 간 host 중복 검사
 */
function checkEventOverlap(PDO $db, string $eventDate, string $startTime, string $hostAccount, ?int $excludeEventId = null): ?array {
    $params = [$eventDate, $startTime, $hostAccount];
    $excludeClause = '';
    if ($excludeEventId) {
        $excludeClause = 'AND le.id != ?';
        $params[] = $excludeEventId;
    }

    $stmt = $db->prepare("
        SELECT le.id, le.event_date, le.title, le.start_time, le.host_account
        FROM lecture_events le
        WHERE le.event_date = ?
          AND le.start_time = ?
          AND le.host_account = ?
          AND le.status = 'active'
          {$excludeClause}
        LIMIT 1
    ");
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

/**
 * n8n webhook 호출하여 이벤트 Zoom meeting 생성
 */
function callLectureEventZoomWebhook(PDO $db, int $eventId, array $payload): array {
    $webhookUrl = getSetting('lecture_zoom_webhook_url');
    if (!$webhookUrl) {
        $db->prepare("
            UPDATE lecture_events SET zoom_status = 'failed', zoom_error_message = 'webhook URL 미설정'
            WHERE id = ?
        ")->execute([$eventId]);
        return ['success' => false, 'error' => 'Zoom webhook URL이 설정되지 않았습니다.'];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode < 200 || $httpCode >= 300) {
        $errorMsg = $curlError ?: "HTTP {$httpCode}";
        $db->prepare("
            UPDATE lecture_events SET zoom_status = 'failed', zoom_error_message = ?
            WHERE id = ?
        ")->execute([mb_substr($errorMsg, 0, 500), $eventId]);
        return ['success' => false, 'error' => $errorMsg];
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['success'])) {
        $errorMsg = $data['error'] ?? $data['message'] ?? 'n8n 응답 파싱 실패';
        $db->prepare("
            UPDATE lecture_events SET zoom_status = 'failed', zoom_error_message = ?
            WHERE id = ?
        ")->execute([mb_substr($errorMsg, 0, 500), $eventId]);
        return ['success' => false, 'error' => $errorMsg];
    }

    $db->prepare("
        UPDATE lecture_events
        SET zoom_meeting_id = ?, zoom_join_url = ?, zoom_start_url = ?, zoom_password = ?,
            zoom_status = 'ready', zoom_error_message = NULL
        WHERE id = ?
    ")->execute([
        $data['zoom_meeting_id'] ?? null,
        $data['zoom_join_url'] ?? null,
        $data['zoom_start_url'] ?? null,
        $data['zoom_password'] ?? null,
        $eventId,
    ]);

    return [
        'success' => true,
        'zoom_join_url' => $data['zoom_join_url'] ?? null,
    ];
}
