<?php
/**
 * boot.soritune.com - QR Attendance API
 * 코치용 + 학생 공개용 QR 출석 엔드포인트
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/bootcamp_functions.php';
require_once __DIR__ . '/../includes/coin_functions.php';
require_once __DIR__ . '/services/qr_match.php';
header('Content-Type: application/json; charset=utf-8');

$action = getAction();
$method = getMethod();

// ── QR 상수 ──
define('QR_SESSION_CODE_LENGTH', 12);       // bin2hex(random_bytes(6)) = 12자 hex
define('QR_DEFAULT_EXPIRY_MINUTES', 120);

// ── Helper: 세션 자동 만료 처리 ──
function autoExpireSession($db, $sessionId) {
    $db->prepare("
        UPDATE qr_sessions SET status = 'expired'
        WHERE id = ? AND status = 'active' AND expires_at <= NOW()
    ")->execute([$sessionId]);
}

// ── Helper: 세션 코드로 유효한 세션 조회 ──
function getActiveSession($db, $code) {
    $stmt = $db->prepare("
        SELECT id, session_code, session_type, admin_id, cohort_id, status, expires_at, created_at
        FROM qr_sessions WHERE session_code = ?
    ");
    $stmt->execute([$code]);
    $session = $stmt->fetch();
    if (!$session) return null;

    // 자동 만료
    if ($session['status'] === 'active' && $session['expires_at'] <= date('Y-m-d H:i:s')) {
        autoExpireSession($db, $session['id']);
        $session['status'] = 'expired';
    }

    return $session;
}

// ══════════════════════════════════════════════════════════════
// Action Router
// ══════════════════════════════════════════════════════════════

switch ($action) {

// ── 세션 생성 (코치 전용) ──
case 'create_session':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['coach', 'sub_coach', 'head', 'subhead1', 'subhead2', 'operation']);
    $db = getDB();

    $input = getJsonInput();
    $sessionType = ($input['session_type'] ?? 'attendance') === 'revival' ? 'revival' : 'attendance';

    // 코치의 기수 확인
    $cohort = getEffectiveCohort($admin);
    if (!$cohort) jsonError('기수 정보가 없습니다.');

    $cohortRow = $db->prepare("SELECT id FROM cohorts WHERE cohort = ? AND is_active = 1");
    $cohortRow->execute([$cohort]);
    $cohortData = $cohortRow->fetch();
    if (!$cohortData) jsonError('활성 기수를 찾을 수 없습니다.');
    $cohortId = (int)$cohortData['id'];

    // 같은 타입의 기존 활성 세션 종료
    $db->prepare("
        UPDATE qr_sessions SET status = 'closed', closed_at = NOW()
        WHERE admin_id = ? AND status = 'active' AND session_type = ?
    ")->execute([$admin['admin_id'], $sessionType]);

    // 새 세션 생성
    $sessionCode = bin2hex(random_bytes(QR_SESSION_CODE_LENGTH / 2));
    $expiryMinutes = (int)getSetting('qr_expiry_minutes', QR_DEFAULT_EXPIRY_MINUTES);
    $expiresAt = date('Y-m-d H:i:s', time() + $expiryMinutes * 60);

    // 당일 강의 자동 매칭 (Tier A → B → C cascade, services/qr_match.php)
    $lectureSessionId = null;
    if ($sessionType === 'attendance') {
        $lectureSessionId = findMatchingLectureSession(
            $db, (int)$admin['admin_id'], $cohortId
        );
    }

    $db->prepare("
        INSERT INTO qr_sessions (session_code, session_type, admin_id, cohort_id, lecture_session_id, expires_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$sessionCode, $sessionType, $admin['admin_id'], $cohortId, $lectureSessionId, $expiresAt]);

    $scanUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/qr/?code=' . $sessionCode;

    jsonSuccess([
        'session_code'  => $sessionCode,
        'session_type'  => $sessionType,
        'scan_url'      => $scanUrl,
        'expires_at'    => $expiresAt,
        'expiry_minutes' => $expiryMinutes,
    ], $sessionType === 'revival' ? '패자부활 QR 세션이 생성되었습니다.' : 'QR 세션이 생성되었습니다.');
    break;

// ── 세션 종료 (코치 전용) ──
case 'close_session':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['coach', 'sub_coach', 'head', 'subhead1', 'subhead2', 'operation']);
    $db = getDB();

    $input = getJsonInput();
    $sessionCode = trim($input['session_code'] ?? '');
    if (!$sessionCode) jsonError('session_code가 필요합니다.');

    $session = getActiveSession($db, $sessionCode);
    if (!$session) jsonError('세션을 찾을 수 없습니다.');

    // 본인 세션만 종료 가능 (operation은 모두 가능)
    if (!hasRole($admin, 'operation') && (int)$session['admin_id'] !== (int)$admin['admin_id']) {
        jsonError('본인이 생성한 세션만 종료할 수 있습니다.', 403);
    }

    $db->prepare("
        UPDATE qr_sessions SET status = 'closed', closed_at = NOW()
        WHERE id = ? AND status = 'active'
    ")->execute([$session['id']]);

    jsonSuccess([], '세션이 종료되었습니다.');
    break;

// ── 세션 상태 + 출석자 목록 (코치 전용) ──
case 'session_status':
    $admin = requireAdmin(['coach', 'sub_coach', 'head', 'subhead1', 'subhead2', 'operation']);
    $db = getDB();

    $queryType = ($_GET['session_type'] ?? 'attendance') === 'revival' ? 'revival' : 'attendance';

    // 코치의 활성 세션 조회 (타입별)
    $stmt = $db->prepare("
        SELECT id, session_code, session_type, cohort_id, status, expires_at, created_at
        FROM qr_sessions
        WHERE admin_id = ? AND status = 'active' AND session_type = ?
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$admin['admin_id'], $queryType]);
    $session = $stmt->fetch();

    if (!$session) {
        jsonSuccess(['has_session' => false]);
        break;
    }

    // 자동 만료 체크
    if ($session['expires_at'] <= date('Y-m-d H:i:s')) {
        autoExpireSession($db, $session['id']);
        jsonSuccess(['has_session' => false]);
        break;
    }

    // 출석자 목록
    $attendees = $db->prepare("
        SELECT qa.member_id, qa.scanned_at, qa.group_id,
               bm.nickname, bm.real_name,
               bg.name AS group_name
        FROM qr_attendance qa
        JOIN bootcamp_members bm ON bm.id = qa.member_id
        JOIN bootcamp_groups bg ON bg.id = qa.group_id
        WHERE qa.qr_session_id = ?
        ORDER BY qa.scanned_at DESC
    ");
    $attendees->execute([$session['id']]);

    // 전체 활성 멤버 수 (해당 기수)
    $totalStmt = $db->prepare("
        SELECT COUNT(*) AS cnt FROM bootcamp_members
        WHERE cohort_id = ? AND is_active = 1 AND member_status != 'refunded'
    ");
    $totalStmt->execute([$session['cohort_id']]);
    $totalMembers = (int)$totalStmt->fetch()['cnt'];

    $scanUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/qr/?code=' . $session['session_code'];

    jsonSuccess([
        'has_session'   => true,
        'session_code'  => $session['session_code'],
        'session_type'  => $session['session_type'] ?? 'attendance',
        'scan_url'      => $scanUrl,
        'expires_at'    => $session['expires_at'],
        'attendees'     => $attendees->fetchAll(),
        'total_members' => $totalMembers,
    ]);
    break;

// ── 세션 검증 (공개) ──
case 'verify':
    $code = trim($_GET['code'] ?? '');
    if (!$code) jsonError('code가 필요합니다.');

    $db = getDB();
    $session = getActiveSession($db, $code);

    if (!$session) {
        jsonSuccess(['valid' => false, 'reason' => 'not_found']);
        break;
    }

    if ($session['status'] !== 'active') {
        jsonSuccess(['valid' => false, 'reason' => $session['status']]);
        break;
    }

    jsonSuccess([
        'valid'        => true,
        'cohort_id'    => (int)$session['cohort_id'],
        'session_type' => $session['session_type'] ?? 'attendance',
    ]);
    break;

// ── 조 목록 (공개, 유효 세션 필요) ──
case 'groups':
    requireMember();
    $code = trim($_GET['code'] ?? '');
    if (!$code) jsonError('code가 필요합니다.');

    $db = getDB();
    $session = getActiveSession($db, $code);
    if (!$session || $session['status'] !== 'active') {
        jsonError('유효하지 않은 세션입니다.');
    }

    $stmt = $db->prepare("
        SELECT id, name, code FROM bootcamp_groups
        WHERE cohort_id = ?
        ORDER BY name
    ");
    $stmt->execute([$session['cohort_id']]);
    $groups = $stmt->fetchAll();

    // group_id=NULL 인 단체활동 대상 회원 (leaving/OOM) 이 있으면 가상 카드 추가
    $unassignedStmt = $db->prepare("
        SELECT COUNT(*) FROM bootcamp_members
        WHERE cohort_id = ?
          AND group_id IS NULL
          AND is_active = 1
          AND member_status != 'refunded'
    ");
    $unassignedStmt->execute([$session['cohort_id']]);
    $unassignedCount = (int)$unassignedStmt->fetchColumn();
    if ($unassignedCount > 0) {
        $groups[] = ['id' => 0, 'name' => '기타 (조 미배정)', 'code' => '_unassigned_'];
    }

    jsonSuccess(['groups' => $groups]);
    break;

// ── 조원 목록 (공개, 유효 세션 필요) ──
case 'group_members':
    requireMember();
    $code = trim($_GET['code'] ?? '');
    if (!$code || !isset($_GET['group_id'])) jsonError('code와 group_id가 필요합니다.');
    $groupId = (int)$_GET['group_id'];

    $db = getDB();
    $session = getActiveSession($db, $code);
    if (!$session || $session['status'] !== 'active') {
        jsonError('유효하지 않은 세션입니다.');
    }

    if ($groupId === 0) {
        // 가상 "기타 (조 미배정)" 카드 — group_id IS NULL 인 leaving/OOM 회원
        $stmt = $db->prepare("
            SELECT id, nickname FROM bootcamp_members
            WHERE cohort_id = ?
              AND group_id IS NULL
              AND is_active = 1
              AND member_status != 'refunded'
            ORDER BY nickname
        ");
        $stmt->execute([$session['cohort_id']]);
    } else {
        $stmt = $db->prepare("
            SELECT id, nickname FROM bootcamp_members
            WHERE group_id = ? AND cohort_id = ? AND is_active = 1 AND member_status != 'refunded'
            ORDER BY nickname
        ");
        $stmt->execute([$groupId, $session['cohort_id']]);
    }

    jsonSuccess(['members' => $stmt->fetchAll()]);
    break;

// ── 출석 기록 (공개) ──
case 'record':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);

    $s = requireMember();   // ← 회원 세션 강제
    $input = getJsonInput();
    $code = trim($input['session_code'] ?? '');
    $memberId = (int)($input['member_id'] ?? 0);
    if (!$code || !$memberId) jsonError('session_code와 member_id가 필요합니다.');

    $db = getDB();
    $session = getActiveSession($db, $code);
    if (!$session || $session['status'] !== 'active') {
        jsonError('세션이 만료되었거나 종료되었습니다.');
    }

    require_once __DIR__ . '/../includes/qr_actions.php';

    $result = qrRecordAttendance(
        $db,
        $session,
        $memberId,
        (int)$s['member_id'],
        getClientIP(),
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    );

    if (!$result['ok']) {
        jsonError($result['error'], $result['http_status'] ?? 400);
    }

    jsonSuccess([
        'member_name' => $result['member_name'],
        'already'     => $result['already'],
    ]);
    break;

// ── 패자부활 QR 처리 ──
case 'revival_record':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);

    $s = requireMember();   // ← 회원 세션 강제
    $input = getJsonInput();
    $code = trim($input['session_code'] ?? '');
    $memberId = (int)($input['member_id'] ?? 0);
    if (!$code || !$memberId) jsonError('session_code와 member_id가 필요합니다.');

    $db = getDB();
    $session = getActiveSession($db, $code);
    if (!$session || $session['status'] !== 'active') {
        jsonError('세션이 만료되었거나 종료되었습니다.');
    }

    require_once __DIR__ . '/../includes/qr_actions.php';

    $result = qrRecordRevival(
        $db,
        $session,
        $memberId,
        (int)$s['member_id'],
        getClientIP(),
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    );

    if (!$result['ok']) {
        jsonError($result['error'], $result['http_status'] ?? 400);
    }

    if (!empty($result['already'])) {
        jsonSuccess([
            'member_name' => $result['member_name'],
            'already' => true,
        ], '이미 패자부활이 처리되었습니다.');
    }

    if (!empty($result['not_eligible'])) {
        jsonSuccess([
            'member_name' => $result['member_name'],
            'not_eligible' => true,
            'current_score' => $result['current_score'],
        ], '패자부활전 대상이 아닙니다. (현재 점수: ' . $result['current_score'] . '점)');
    }

    jsonSuccess([
        'member_name'  => $result['member_name'],
        'not_eligible' => false,
        'before_score' => $result['before_score'],
        'after_score'  => $result['after_score'],
        'bonus'        => $result['bonus'],
    ], '패자부활 처리되었습니다. (+' . $result['bonus'] . '점)');
    break;

default:
    jsonError('알 수 없는 action: ' . $action, 404);
}
