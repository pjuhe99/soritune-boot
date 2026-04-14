<?php
/**
 * Study Service
 * 복습스터디(스터디) 생성/조회/상세/취소/QR출석
 */

/**
 * 스터디용 조 목록 (회원 접근 가능)
 */
function handleStudyGroups() {
    $member = requireMember();
    $db = getDB();
    $cohortId = getMemberCohortId($db, $member['member_id']);
    if (!$cohortId) jsonError('기수 정보를 찾을 수 없습니다.');

    $stmt = $db->prepare("SELECT id, name FROM bootcamp_groups WHERE cohort_id = ? ORDER BY name");
    $stmt->execute([$cohortId]);
    jsonSuccess(['groups' => $stmt->fetchAll()]);
}

/**
 * 스터디용 조원 목록 (회원 접근 가능, 검색 지원)
 */
function handleStudyMembers() {
    $member = requireMember();
    $db = getDB();
    $cohortId = getMemberCohortId($db, $member['member_id']);
    if (!$cohortId) jsonError('기수 정보를 찾을 수 없습니다.');

    $where = ["bm.cohort_id = ?", "bm.is_active = 1", "bm.member_status != 'refunded'"];
    $params = [$cohortId];

    if (!empty($_GET['group_id'])) {
        $where[] = "bm.group_id = ?";
        $params[] = (int)$_GET['group_id'];
    }
    if (!empty($_GET['keyword'])) {
        $kw = '%' . trim($_GET['keyword']) . '%';
        $where[] = "(bm.nickname LIKE ? OR bm.real_name LIKE ?)";
        $params[] = $kw;
        $params[] = $kw;
    }

    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.group_id, bg.name AS group_name
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bg.name, bm.nickname
    ");
    $stmt->execute($params);
    jsonSuccess(['members' => $stmt->fetchAll()]);
}

/**
 * 월별 스터디 목록 (달력용)
 */
function handleStudySessions() {
    $member = requireMember();
    $month = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) jsonError('month 형식: YYYY-MM');

    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));

    $db = getDB();

    // 회원의 cohort_id 조회
    $cohortId = getMemberCohortId($db, $member['member_id']);
    if (!$cohortId) jsonError('기수 정보를 찾을 수 없습니다.');

    $stmt = $db->prepare("
        SELECT ss.id, ss.title, ss.level, ss.study_date, ss.start_time, ss.end_time,
               ss.status, ss.zoom_status, ss.host_member_id,
               bm.nickname AS host_nickname,
               (SELECT COUNT(*) FROM qr_attendance qa WHERE qa.qr_session_id = ss.qr_session_id) AS participant_count
        FROM study_sessions ss
        JOIN bootcamp_members bm ON ss.host_member_id = bm.id
        WHERE ss.cohort_id = ?
          AND ss.study_date BETWEEN ? AND ?
          AND ss.status != 'cancelled'
        ORDER BY ss.study_date, ss.start_time
    ");
    $stmt->execute([$cohortId, $startDate, $endDate]);
    $sessions = $stmt->fetchAll();

    jsonSuccess([
        'month' => $month,
        'sessions' => $sessions,
    ]);
}

/**
 * 스터디 상세
 */
function handleStudySessionDetail() {
    $member = requireMember();
    $sessionId = (int)($_GET['session_id'] ?? 0);
    if (!$sessionId) jsonError('session_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT ss.*, bm.nickname AS host_nickname
        FROM study_sessions ss
        JOIN bootcamp_members bm ON ss.host_member_id = bm.id
        WHERE ss.id = ? AND ss.status != 'cancelled'
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session) jsonError('스터디를 찾을 수 없습니다.', 404);

    // 참여자 목록 (QR 출석 기록)
    $participants = [];
    if ($session['qr_session_id']) {
        $pStmt = $db->prepare("
            SELECT qa.member_id, qa.scanned_at, bm.nickname, bg.name AS group_name
            FROM qr_attendance qa
            JOIN bootcamp_members bm ON qa.member_id = bm.id
            LEFT JOIN bootcamp_groups bg ON qa.group_id = bg.id
            WHERE qa.qr_session_id = ?
            ORDER BY qa.scanned_at
        ");
        $pStmt->execute([$session['qr_session_id']]);
        $participants = $pStmt->fetchAll();
    }

    $isHost = ((int)$session['host_member_id'] === (int)$member['member_id']);

    // zoom_start_url은 개설자에게만 노출
    if (!$isHost) {
        unset($session['zoom_start_url']);
    }
    // 비밀번호 미노출
    unset($session['password']);

    // 시간 기반 상태 계산
    $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    $startAt = new DateTime($session['study_date'] . ' ' . $session['start_time'], new DateTimeZone('Asia/Seoul'));
    $endAt = new DateTime($session['study_date'] . ' ' . $session['end_time'], new DateTimeZone('Asia/Seoul'));

    $canCancel = $isHost && $now < $startAt && $session['status'] === 'active';
    $canStartQr = $isHost && $session['status'] === 'active';
    $hasQrSession = !empty($session['qr_session_id']);

    jsonSuccess([
        'session' => $session,
        'participants' => $participants,
        'is_host' => $isHost,
        'can_cancel' => $canCancel,
        'can_start_qr' => $canStartQr,
        'has_qr_session' => $hasQrSession,
    ]);
}

/**
 * 스터디 생성
 */
function handleStudySessionCreate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $member = requireMember();
    $input = getJsonInput();

    // host_member_id가 없으면 로그인된 본인으로 자동 설정
    $hostMemberId = (int)($input['host_member_id'] ?? 0);
    if (!$hostMemberId) $hostMemberId = (int)$member['member_id'];
    $studyDate = $input['study_date'] ?? '';
    $startTime = $input['start_time'] ?? '';
    $level = (int)($input['level'] ?? 1);

    // 기본 검증
    if (!$studyDate || !$startTime) {
        jsonError('study_date, start_time 필요');
    }
    if (!in_array($level, [1, 2])) {
        jsonError('level은 1 또는 2만 가능합니다.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $studyDate)) {
        jsonError('study_date 형식: YYYY-MM-DD');
    }
    if (!preg_match('/^\d{2}:(00|30)$/', $startTime)) {
        jsonError('start_time은 00분 또는 30분 단위만 허용됩니다.');
    }

    // 과거 날짜 검증
    $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    $startAt = new DateTime("{$studyDate} {$startTime}", new DateTimeZone('Asia/Seoul'));
    if ($startAt <= $now) {
        jsonError('과거 시간에는 복습스터디를 생성할 수 없습니다.');
    }

    // 시작 3시간 전까지만 생성 가능
    $minCreateAt = clone $now;
    $minCreateAt->modify('+3 hours');
    if ($startAt < $minCreateAt) {
        jsonError('복습스터디는 시작 시간 3시간 전까지만 개설할 수 있습니다.');
    }

    // end_time = start_time + 1시간
    $endAt = clone $startAt;
    $endAt->modify('+1 hour');
    $endTime = $endAt->format('H:i:s');
    $startTimeFull = $startAt->format('H:i:s');

    $db = getDB();

    // 개설자(호스트) 회원 정보 조회
    $hostRow = $db->prepare("SELECT id, nickname, cohort_id FROM bootcamp_members WHERE id = ? AND is_active = 1");
    $hostRow->execute([$hostMemberId]);
    $hostData = $hostRow->fetch();
    if (!$hostData) jsonError('개설자 회원 정보를 찾을 수 없습니다.');

    $cohortId = (int)$hostData['cohort_id'];
    $nickname = $hostData['nickname'];

    // 시간 겹침 검증
    $overlap = checkTimeOverlap($db, $cohortId, $studyDate, $startTimeFull, $endTime);
    if ($overlap) {
        jsonError("이미 해당 날짜/시간에 예약된 복습스터디가 있습니다: {$overlap['title']}");
    }

    // 제목 자동 생성
    $timeLabel = substr($startTime, 0, 5); // "HH:MM"
    $levelLabel = "{$level}단계";
    $title = "[{$timeLabel}] {$levelLabel} {$nickname}님의 복습 스터디";

    // 고정 Zoom 링크 조회
    $fixedZoomUrl = getSetting('study_fixed_zoom_url');

    // DB 저장 (고정 Zoom 링크 사용 → 즉시 active/ready)
    $db->prepare("
        INSERT INTO study_sessions
            (cohort_id, host_member_id, level, title, study_date, start_time, end_time, status, zoom_status, zoom_join_url)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 'ready', ?)
    ")->execute([$cohortId, $hostMemberId, $level, $title, $studyDate, $startTimeFull, $endTime, $fixedZoomUrl]);

    $sessionId = (int)$db->lastInsertId();

    // 개설자 bookclub_open 미션 체크
    $openTypeId = getMissionTypeId($db, 'bookclub_open');
    if ($openTypeId) {
        saveCheck($db, $hostMemberId, $studyDate, $openTypeId, 1, 'automation', "study:{$sessionId}", null);
    }

    jsonSuccess([
        'session_id' => $sessionId,
        'title' => $title,
        'zoom_join_url' => $fixedZoomUrl,
    ], '복습스터디가 생성되었습니다.');
}

/**
 * Zoom 재시도 (호스트 본인 또는 관리자/운영자)
 */
function handleStudySessionRetryZoom($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $input = getJsonInput();
    $sessionId = (int)($input['session_id'] ?? 0);
    if (!$sessionId) jsonError('session_id 필요');

    // 인증: 관리자(operation/coach) 또는 일반 회원(호스트 본인)
    $isAdmin = false;
    $memberId = null;

    $adminHeader = $_SERVER['HTTP_X_ADMIN_AUTH'] ?? '';
    if ($adminHeader || !empty($_COOKIE['BOOT_ADMIN_SID'])) {
        // 관리자 인증 시도
        try {
            $admin = requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
            $isAdmin = true;
        } catch (\Exception $e) {
            // admin 인증 실패 시 member로 fallback
        }
    }

    if (!$isAdmin) {
        $member = requireMember();
        $memberId = $member['member_id'];
    }

    $db = getDB();
    $row = getStudySessionForRetry($db, $sessionId);

    // 권한 검증: 관리자가 아니면 호스트 본인만 가능
    if (!$isAdmin && (int)$row['host_member_id'] !== (int)$memberId) {
        jsonError('본인이 개설한 스터디만 재시도할 수 있습니다.', 403);
    }

    // 고정 Zoom 링크로 재설정
    $fixedZoomUrl = getSetting('study_fixed_zoom_url');
    $db->prepare("
        UPDATE study_sessions
        SET zoom_join_url = ?, zoom_status = 'ready', zoom_error_message = NULL, status = 'active'
        WHERE id = ?
    ")->execute([$fixedZoomUrl, $sessionId]);

    jsonSuccess([
        'zoom_join_url' => $fixedZoomUrl,
    ], 'Zoom 링크가 설정되었습니다.');
}

/**
 * Zoom 실패 세션 목록 (관리자 전용)
 */
function handleStudyZoomFailed() {
    requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $db = getDB();

    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    $where = ["ss.zoom_status IN ('failed', 'pending')", "ss.status IN ('pending', 'active')"];
    $params = [];

    if ($cohortId) {
        $where[] = "ss.cohort_id = ?";
        $params[] = $cohortId;
    }

    // 시작 시각이 아직 안 지난 세션만
    $where[] = "CONCAT(ss.study_date, ' ', ss.start_time) > NOW()";

    $stmt = $db->prepare("
        SELECT ss.id, ss.title, ss.study_date, ss.start_time, ss.end_time,
               ss.status, ss.zoom_status, ss.zoom_error_message,
               ss.host_member_id, bm.nickname AS host_nickname,
               ss.created_at
        FROM study_sessions ss
        JOIN bootcamp_members bm ON ss.host_member_id = bm.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ss.study_date, ss.start_time
    ");
    $stmt->execute($params);
    jsonSuccess(['sessions' => $stmt->fetchAll()]);
}

/**
 * 스터디 취소
 */
function handleStudySessionCancel($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $member = requireMember();
    $input = getJsonInput();

    $sessionId = (int)($input['session_id'] ?? 0);
    if (!$sessionId) jsonError('session_id 필요');

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM study_sessions WHERE id = ? AND status IN ('pending','active')");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session) jsonError('스터디를 찾을 수 없습니다.', 404);

    // 개설자 본인만 취소 가능
    if ((int)$session['host_member_id'] !== (int)$member['member_id']) {
        jsonError('본인이 개설한 복습스터디만 취소할 수 있습니다.', 403);
    }

    // 시작 30분 전까지만 취소 가능
    $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    $startAt = new DateTime($session['study_date'] . ' ' . $session['start_time'], new DateTimeZone('Asia/Seoul'));
    $cancelDeadline = clone $startAt;
    $cancelDeadline->modify('-30 minutes');
    if ($now >= $cancelDeadline) {
        jsonError('복습스터디 시작 30분 전부터는 취소할 수 없습니다.');
    }

    // cancelled 처리 (hard delete 아님)
    $db->prepare("UPDATE study_sessions SET status = 'cancelled' WHERE id = ?")->execute([$sessionId]);

    // QR 세션도 종료
    if ($session['qr_session_id']) {
        $db->prepare("UPDATE qr_sessions SET status = 'closed', closed_at = NOW() WHERE id = ? AND status = 'active'")
           ->execute([$session['qr_session_id']]);
    }

    // bookclub_open 미션 체크 해제
    $openTypeId = getMissionTypeId($db, 'bookclub_open');
    if ($openTypeId) {
        saveCheck($db, $member['member_id'], $session['study_date'], $openTypeId, 0, 'automation', "study_cancel:{$sessionId}", null);
    }

    jsonSuccess([], '복습스터디가 취소되었습니다.');
}

/**
 * 출석용 QR 세션 생성 (개설자 전용)
 */
function handleStudySessionQr($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $member = requireMember();
    $input = getJsonInput();

    $sessionId = (int)($input['session_id'] ?? 0);
    if (!$sessionId) jsonError('session_id 필요');

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM study_sessions WHERE id = ? AND status = 'active'");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session) jsonError('활성 상태의 스터디를 찾을 수 없습니다.', 404);

    // 개설자 확인
    if ((int)$session['host_member_id'] !== (int)$member['member_id']) {
        jsonError('본인이 개설한 복습스터디만 출석체크를 시작할 수 있습니다.', 403);
    }

    $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    $endAt = new DateTime($session['study_date'] . ' ' . $session['end_time'], new DateTimeZone('Asia/Seoul'));

    // 이미 QR 세션이 있으면 재사용
    if ($session['qr_session_id']) {
        $existingQr = $db->prepare("SELECT session_code, status, expires_at FROM qr_sessions WHERE id = ?");
        $existingQr->execute([$session['qr_session_id']]);
        $qr = $existingQr->fetch();
        if ($qr && $qr['status'] === 'active' && $qr['expires_at'] > $now->format('Y-m-d H:i:s')) {
            $scanUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/qr/?code=' . $qr['session_code'];
            jsonSuccess([
                'session_code' => $qr['session_code'],
                'scan_url' => $scanUrl,
                'expires_at' => $qr['expires_at'],
            ], '기존 QR 세션이 활성 상태입니다.');
        }
    }

    // 새 QR 세션 생성 (study용)
    $sessionCode = bin2hex(random_bytes(6));
    $expiresAt = $endAt->format('Y-m-d H:i:s');

    // admin_id=0 (회원이 생성, NOT NULL 제약), cohort_id는 study_session의 것
    $db->prepare("
        INSERT INTO qr_sessions (session_code, admin_id, cohort_id, expires_at, status)
        VALUES (?, 0, ?, ?, 'active')
    ")->execute([$sessionCode, $session['cohort_id'], $expiresAt]);

    $qrSessionId = (int)$db->lastInsertId();

    // study_sessions에 qr_session_id 연결
    $db->prepare("UPDATE study_sessions SET qr_session_id = ? WHERE id = ?")->execute([$qrSessionId, $sessionId]);

    $scanUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/qr/?code=' . $sessionCode;

    jsonSuccess([
        'session_code' => $sessionCode,
        'scan_url' => $scanUrl,
        'expires_at' => $expiresAt,
    ], 'QR 출석체크가 시작되었습니다.');
}

// ══════════════════════════════════════════════════════════════
// Internal Helpers
// ══════════════════════════════════════════════════════════════

/**
 * Zoom 재시도 대상 세션 조회 + 유효성 검증
 * 핸들러가 아닌 서비스 함수로 분리 → 관리자 UI, 배치 재시도 등에서 재사용
 */
function getStudySessionForRetry($db, $sessionId) {
    $stmt = $db->prepare("
        SELECT ss.*, bm.nickname AS host_nickname
        FROM study_sessions ss
        JOIN bootcamp_members bm ON ss.host_member_id = bm.id
        WHERE ss.id = ?
    ");
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch();
    if (!$row) jsonError('스터디를 찾을 수 없습니다.', 404);
    if ($row['zoom_status'] === 'ready') jsonError('이미 Zoom이 생성되어 있습니다.');
    if ($row['status'] === 'cancelled') jsonError('취소된 스터디입니다.');

    $startAt = new DateTime("{$row['study_date']} {$row['start_time']}", new DateTimeZone('Asia/Seoul'));
    $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    if ($now > $startAt) jsonError('시작 시각이 지난 스터디는 재시도할 수 없습니다.');

    return $row;
}

/**
 * Zoom webhook 재시도 실행 (세션 row 기반)
 * callZoomWebhook()을 감싸는 편의 함수
 */
function retryZoomForSession($db, $sessionRow) {
    $sessionId = (int)$sessionRow['id'];
    $timeLabel = substr($sessionRow['start_time'], 0, 5);
    $endLabel = substr($sessionRow['end_time'], 0, 5);

    return callZoomWebhook($db, $sessionId, [
        'study_session_id' => $sessionId,
        'title' => $sessionRow['title'],
        'study_date' => $sessionRow['study_date'],
        'start_time' => $timeLabel,
        'end_time' => $endLabel,
        'duration' => 60,
        'host_nickname' => $sessionRow['host_nickname'],
        'scheduled_at' => (new DateTime("{$sessionRow['study_date']} {$sessionRow['start_time']}", new DateTimeZone('Asia/Seoul')))->format('c'),
    ]);
}

// ══════════════════════════════════════════════════════════════
// Admin Study API
// ══════════════════════════════════════════════════════════════

/**
 * 어드민용 월별 스터디 목록
 */
function handleAdminStudySessions() {
    requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    $month = $_GET['month'] ?? date('Y-m');
    if (!$cohortId) jsonError('cohort_id 필요');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) jsonError('month 형식: YYYY-MM');

    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));

    $db = getDB();
    $stmt = $db->prepare("
        SELECT ss.id, ss.title, ss.level, ss.study_date, ss.start_time, ss.end_time,
               ss.status, ss.zoom_status, ss.host_member_id,
               bm.nickname AS host_nickname,
               (SELECT COUNT(*) FROM qr_attendance qa WHERE qa.qr_session_id = ss.qr_session_id) AS participant_count
        FROM study_sessions ss
        JOIN bootcamp_members bm ON ss.host_member_id = bm.id
        WHERE ss.cohort_id = ?
          AND ss.study_date BETWEEN ? AND ?
          AND ss.status != 'cancelled'
        ORDER BY ss.study_date, ss.start_time
    ");
    $stmt->execute([$cohortId, $startDate, $endDate]);
    jsonSuccess(['month' => $month, 'sessions' => $stmt->fetchAll()]);
}

/**
 * 어드민용 스터디 상세
 */
function handleAdminStudyDetail() {
    requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $sessionId = (int)($_GET['session_id'] ?? 0);
    if (!$sessionId) jsonError('session_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT ss.*, bm.nickname AS host_nickname, bm.real_name AS host_real_name
        FROM study_sessions ss
        JOIN bootcamp_members bm ON ss.host_member_id = bm.id
        WHERE ss.id = ?
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session) jsonError('스터디를 찾을 수 없습니다.', 404);

    unset($session['password']);

    // 참여자 목록
    $participants = [];
    if ($session['qr_session_id']) {
        $pStmt = $db->prepare("
            SELECT qa.member_id, qa.scanned_at, bm.nickname, bm.real_name, bg.name AS group_name
            FROM qr_attendance qa
            JOIN bootcamp_members bm ON qa.member_id = bm.id
            LEFT JOIN bootcamp_groups bg ON qa.group_id = bg.id
            WHERE qa.qr_session_id = ?
            ORDER BY qa.scanned_at
        ");
        $pStmt->execute([$session['qr_session_id']]);
        $participants = $pStmt->fetchAll();
    }

    jsonSuccess([
        'session' => $session,
        'participants' => $participants,
        'can_cancel' => in_array($session['status'], ['pending', 'active']),
    ]);
}

/**
 * 어드민용 스터디 생성 (회원 선택 가능, 3시간 제한 없음)
 */
function handleAdminStudyCreate($method, $admin) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $input = getJsonInput();

    $hostMemberId = (int)($input['host_member_id'] ?? 0);
    $studyDate = $input['study_date'] ?? '';
    $startTime = $input['start_time'] ?? '';
    $level = (int)($input['level'] ?? 1);

    if (!$hostMemberId) jsonError('host_member_id (회원) 필요');
    if (!$studyDate || !$startTime) jsonError('study_date, start_time 필요');
    if (!in_array($level, [1, 2])) jsonError('level은 1 또는 2만 가능합니다.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $studyDate)) jsonError('study_date 형식: YYYY-MM-DD');
    if (!preg_match('/^\d{2}:(00|30)$/', $startTime)) jsonError('start_time은 00분 또는 30분 단위만 허용됩니다.');

    // 과거 시간 체크
    $now = new DateTime('now', new DateTimeZone('Asia/Seoul'));
    $startAt = new DateTime("{$studyDate} {$startTime}", new DateTimeZone('Asia/Seoul'));
    if ($startAt <= $now) {
        jsonError('과거 시간에는 복습스터디를 생성할 수 없습니다.');
    }

    $endAt = clone $startAt;
    $endAt->modify('+1 hour');
    $endTime = $endAt->format('H:i:s');
    $startTimeFull = $startAt->format('H:i:s');

    $db = getDB();

    $hostRow = $db->prepare("SELECT id, nickname, cohort_id FROM bootcamp_members WHERE id = ? AND is_active = 1");
    $hostRow->execute([$hostMemberId]);
    $hostData = $hostRow->fetch();
    if (!$hostData) jsonError('회원 정보를 찾을 수 없습니다.');

    $cohortId = (int)$hostData['cohort_id'];
    $nickname = $hostData['nickname'];

    $overlap = checkTimeOverlap($db, $cohortId, $studyDate, $startTimeFull, $endTime);
    if ($overlap) {
        jsonError("이미 해당 날짜/시간에 예약된 복습스터디가 있습니다: {$overlap['title']}");
    }

    $timeLabel = substr($startTime, 0, 5);
    $title = "[{$timeLabel}] {$level}단계 {$nickname}님의 복습 스터디";
    $fixedZoomUrl = getSetting('study_fixed_zoom_url');

    $db->prepare("
        INSERT INTO study_sessions
            (cohort_id, host_member_id, level, title, study_date, start_time, end_time, status, zoom_status, zoom_join_url)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 'ready', ?)
    ")->execute([$cohortId, $hostMemberId, $level, $title, $studyDate, $startTimeFull, $endTime, $fixedZoomUrl]);

    $sessionId = (int)$db->lastInsertId();

    $openTypeId = getMissionTypeId($db, 'bookclub_open');
    if ($openTypeId) {
        saveCheck($db, $hostMemberId, $studyDate, $openTypeId, 1, 'automation', "study:{$sessionId}", null);
    }

    jsonSuccess([
        'session_id' => $sessionId,
        'title' => $title,
    ], '복습스터디가 생성되었습니다.');
}

/**
 * 어드민용 스터디 취소 (호스트 제한 없음, 시간 제한 없음)
 */
function handleAdminStudyCancel($method, $admin) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $input = getJsonInput();
    $sessionId = (int)($input['session_id'] ?? 0);
    if (!$sessionId) jsonError('session_id 필요');

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM study_sessions WHERE id = ? AND status IN ('pending','active')");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session) jsonError('스터디를 찾을 수 없거나 이미 취소되었습니다.', 404);

    $db->prepare("UPDATE study_sessions SET status = 'cancelled' WHERE id = ?")->execute([$sessionId]);

    if ($session['qr_session_id']) {
        $db->prepare("UPDATE qr_sessions SET status = 'closed', closed_at = NOW() WHERE id = ? AND status = 'active'")
           ->execute([$session['qr_session_id']]);
    }

    $openTypeId = getMissionTypeId($db, 'bookclub_open');
    if ($openTypeId) {
        saveCheck($db, $session['host_member_id'], $session['study_date'], $openTypeId, 0, 'automation', "study_cancel:{$sessionId}", null);
    }

    jsonSuccess([], '복습스터디가 취소되었습니다.');
}

/**
 * 어드민용 조 목록
 */
function handleAdminStudyGroups() {
    requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');

    $db = getDB();
    $stmt = $db->prepare("SELECT id, name FROM bootcamp_groups WHERE cohort_id = ? ORDER BY name");
    $stmt->execute([$cohortId]);
    jsonSuccess(['groups' => $stmt->fetchAll()]);
}

/**
 * 어드민용 회원 목록 (스터디 생성시 회원 선택용)
 */
function handleAdminStudyMembers() {
    requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');

    $where = ["bm.cohort_id = ?", "bm.is_active = 1", "bm.member_status != 'refunded'"];
    $params = [$cohortId];

    if (!empty($_GET['group_id'])) {
        $where[] = "bm.group_id = ?";
        $params[] = (int)$_GET['group_id'];
    }

    $db = getDB();
    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.group_id, bg.name AS group_name
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bg.name, bm.nickname
    ");
    $stmt->execute($params);
    jsonSuccess(['members' => $stmt->fetchAll()]);
}

// ══════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════

/**
 * 동일 시작시간 중복 검증
 * 같은 날짜, 같은 시작 시간에 이미 세션이 있으면 차단
 * (30분 간격 개설 허용 — 14:00과 14:30 동시 가능)
 */
function checkTimeOverlap($db, $cohortId, $studyDate, $startTime, $endTime, $excludeId = null) {
    $sql = "
        SELECT id, title, start_time, end_time
        FROM study_sessions
        WHERE cohort_id = ?
          AND study_date = ?
          AND start_time = ?
          AND status IN ('pending', 'active')
    ";
    $params = [$cohortId, $studyDate, $startTime];

    if ($excludeId) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(); // false if no overlap
}

/**
 * 회원의 cohort_id 조회
 */
function getMemberCohortId($db, $memberId) {
    $stmt = $db->prepare("SELECT cohort_id FROM bootcamp_members WHERE id = ? AND (is_active = 1 OR member_status = 'leaving')");
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['cohort_id'] : null;
}

/**
 * n8n webhook 호출하여 Zoom meeting 생성
 */
function callZoomWebhook($db, $sessionId, $payload) {
    $webhookUrl = getSetting('study_zoom_webhook_url');
    if (!$webhookUrl) {
        // webhook URL 미설정 시 pending 유지
        $db->prepare("
            UPDATE study_sessions SET zoom_status = 'failed', zoom_error_message = 'webhook URL 미설정'
            WHERE id = ?
        ")->execute([$sessionId]);
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
            UPDATE study_sessions SET zoom_status = 'failed', zoom_error_message = ?
            WHERE id = ?
        ")->execute([mb_substr($errorMsg, 0, 500), $sessionId]);
        return ['success' => false, 'error' => $errorMsg];
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['success'])) {
        $errorMsg = $data['error'] ?? $data['message'] ?? 'n8n 응답 파싱 실패';
        $db->prepare("
            UPDATE study_sessions SET zoom_status = 'failed', zoom_error_message = ?
            WHERE id = ?
        ")->execute([mb_substr($errorMsg, 0, 500), $sessionId]);
        return ['success' => false, 'error' => $errorMsg];
    }

    // Zoom 정보 저장 + status를 active로 전환
    $db->prepare("
        UPDATE study_sessions
        SET zoom_meeting_id = ?, zoom_join_url = ?, zoom_start_url = ?, zoom_password = ?,
            zoom_status = 'ready', zoom_error_message = NULL, status = 'active'
        WHERE id = ?
    ")->execute([
        $data['zoom_meeting_id'] ?? null,
        $data['zoom_join_url'] ?? null,
        $data['zoom_start_url'] ?? null,
        $data['zoom_password'] ?? null,
        $sessionId,
    ]);

    return [
        'success' => true,
        'zoom_join_url' => $data['zoom_join_url'] ?? null,
    ];
}
