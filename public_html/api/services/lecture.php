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
        VALUES (?, ?, ?, ?, ?, 60, ?, 'pending', 'active', ?)
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

    // ── n8n webhook 호출 (Zoom 생성) ──
    $zoomResult = callLectureZoomWebhook($db, $scheduleId, [
        'lecture_schedule_id' => $scheduleId,
        'title'              => $title,
        'type'               => 'recurring_lecture',
        'host_account'       => $hostAccount,
        'coach_name'         => $coachName,
        'stage'              => $stage,
        'start_time'         => $timeLabel,
        'duration'           => 60,
        'weekdays'           => $validWeekdays,
        'first_date'         => $sessionDates[0],
        'last_date'          => end($sessionDates),
        'scheduled_at'       => (new DateTime("{$sessionDates[0]} {$startTimeFull}", new DateTimeZone('Asia/Seoul')))->format('c'),
    ]);

    if ($zoomResult['success']) {
        jsonSuccess([
            'schedule_id'   => $scheduleId,
            'session_count' => $count,
            'title'         => $title,
            'zoom_join_url' => $zoomResult['zoom_join_url'] ?? null,
        ], '강의 스케줄이 생성되었습니다.');
    } else {
        jsonSuccess([
            'schedule_id'   => $scheduleId,
            'session_count' => $count,
            'title'         => $title,
            'zoom_status'   => 'failed',
            'zoom_error'    => $zoomResult['error'] ?? 'Zoom 생성 실패',
        ], '강의 스케줄이 생성되었지만 Zoom 생성에 실패했습니다. 상세에서 재시도할 수 있습니다.');
    }
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
 * Zoom 재생성 (스케줄 단위)
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
    if ($schedule['zoom_status'] === 'ready') jsonError('이미 Zoom이 생성되어 있습니다.');

    // 세션 날짜 범위
    $dateRange = $db->prepare("
        SELECT MIN(lecture_date) AS first_date, MAX(lecture_date) AS last_date
        FROM lecture_sessions WHERE schedule_id = ? AND status = 'active'
    ");
    $dateRange->execute([$scheduleId]);
    $range = $dateRange->fetch();

    $timeLabel = substr($schedule['start_time'], 0, 5);
    $stageLabel = $schedule['stage'] . '단계';
    $title = "[{$timeLabel}] {$schedule['coach_name']} {$stageLabel} 강의";

    $weekdays = array_map('intval', explode(',', $schedule['weekdays']));

    $zoomResult = callLectureZoomWebhook($db, $scheduleId, [
        'lecture_schedule_id' => $scheduleId,
        'title'              => $title,
        'type'               => 'recurring_lecture',
        'host_account'       => $schedule['host_account'],
        'coach_name'         => $schedule['coach_name'],
        'stage'              => (int)$schedule['stage'],
        'start_time'         => $timeLabel,
        'duration'           => (int)$schedule['duration_minutes'],
        'weekdays'           => $weekdays,
        'first_date'         => $range['first_date'] ?? '',
        'last_date'          => $range['last_date'] ?? '',
        'scheduled_at'       => $range['first_date']
            ? (new DateTime("{$range['first_date']} {$schedule['start_time']}", new DateTimeZone('Asia/Seoul')))->format('c')
            : '',
    ]);

    if ($zoomResult['success']) {
        jsonSuccess([
            'zoom_join_url' => $zoomResult['zoom_join_url'] ?? null,
        ], 'Zoom이 생성되었습니다.');
    } else {
        jsonError($zoomResult['error'] ?? 'Zoom 생성에 실패했습니다.');
    }
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
 * n8n webhook 호출 (강의용)
 * lecture_schedules 테이블의 zoom 필드를 업데이트
 */
function callLectureZoomWebhook(PDO $db, int $scheduleId, array $payload): array {
    $webhookUrl = getSetting('lecture_zoom_webhook_url');
    if (!$webhookUrl) {
        $db->prepare("
            UPDATE lecture_schedules SET zoom_status = 'failed', zoom_error_message = 'webhook URL 미설정'
            WHERE id = ?
        ")->execute([$scheduleId]);
        return ['success' => false, 'error' => 'Zoom webhook URL이 설정되지 않았습니다.'];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode < 200 || $httpCode >= 300) {
        $errorMsg = $curlError ?: "HTTP {$httpCode}";
        $db->prepare("
            UPDATE lecture_schedules SET zoom_status = 'failed', zoom_error_message = ?
            WHERE id = ?
        ")->execute([mb_substr($errorMsg, 0, 500), $scheduleId]);
        return ['success' => false, 'error' => $errorMsg];
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['success'])) {
        $errorMsg = $data['error'] ?? $data['message'] ?? 'n8n 응답 파싱 실패';
        $db->prepare("
            UPDATE lecture_schedules SET zoom_status = 'failed', zoom_error_message = ?
            WHERE id = ?
        ")->execute([mb_substr($errorMsg, 0, 500), $scheduleId]);
        return ['success' => false, 'error' => $errorMsg];
    }

    // Zoom 정보 저장
    $db->prepare("
        UPDATE lecture_schedules
        SET zoom_meeting_id = ?, zoom_join_url = ?, zoom_start_url = ?, zoom_password = ?,
            zoom_status = 'ready', zoom_error_message = NULL
        WHERE id = ?
    ")->execute([
        $data['zoom_meeting_id'] ?? null,
        $data['zoom_join_url'] ?? null,
        $data['zoom_start_url'] ?? null,
        $data['zoom_password'] ?? null,
        $scheduleId,
    ]);

    return [
        'success'      => true,
        'zoom_join_url' => $data['zoom_join_url'] ?? null,
    ];
}
