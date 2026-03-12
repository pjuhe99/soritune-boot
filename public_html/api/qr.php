<?php
/**
 * boot.soritune.com - QR Attendance API
 * 코치용 + 학생 공개용 QR 출석 엔드포인트
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/bootcamp_functions.php';
require_once __DIR__ . '/../includes/coin_functions.php';
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
    $admin = requireAdmin(['coach', 'operation']);
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

    $db->prepare("
        INSERT INTO qr_sessions (session_code, session_type, admin_id, cohort_id, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$sessionCode, $sessionType, $admin['admin_id'], $cohortId, $expiresAt]);

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
    $admin = requireAdmin(['coach', 'operation']);
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
    $admin = requireAdmin(['coach', 'operation']);
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
        WHERE cohort_id = ? AND is_active = 1 AND member_status != 'withdrawn'
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

    jsonSuccess(['groups' => $stmt->fetchAll()]);
    break;

// ── 조원 목록 (공개, 유효 세션 필요) ──
case 'group_members':
    $code = trim($_GET['code'] ?? '');
    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$code || !$groupId) jsonError('code와 group_id가 필요합니다.');

    $db = getDB();
    $session = getActiveSession($db, $code);
    if (!$session || $session['status'] !== 'active') {
        jsonError('유효하지 않은 세션입니다.');
    }

    $stmt = $db->prepare("
        SELECT id, nickname FROM bootcamp_members
        WHERE group_id = ? AND cohort_id = ? AND is_active = 1 AND member_status != 'withdrawn'
        ORDER BY nickname
    ");
    $stmt->execute([$groupId, $session['cohort_id']]);

    jsonSuccess(['members' => $stmt->fetchAll()]);
    break;

// ── 출석 기록 (공개) ──
case 'record':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);

    $input = getJsonInput();
    $code = trim($input['session_code'] ?? '');
    $memberId = (int)($input['member_id'] ?? 0);
    if (!$code || !$memberId) jsonError('session_code와 member_id가 필요합니다.');

    $db = getDB();
    $session = getActiveSession($db, $code);
    if (!$session || $session['status'] !== 'active') {
        jsonError('세션이 만료되었거나 종료되었습니다.');
    }

    // 멤버 확인
    $memberStmt = $db->prepare("
        SELECT id, nickname, group_id, cohort_id FROM bootcamp_members
        WHERE id = ? AND cohort_id = ? AND is_active = 1 AND member_status != 'withdrawn'
    ");
    $memberStmt->execute([$memberId, $session['cohort_id']]);
    $member = $memberStmt->fetch();
    if (!$member) jsonError('유효하지 않은 회원입니다.');

    // 중복 출석 체크
    $dupStmt = $db->prepare("
        SELECT id FROM qr_attendance WHERE qr_session_id = ? AND member_id = ?
    ");
    $dupStmt->execute([$session['id'], $memberId]);
    $already = (bool)$dupStmt->fetch();

    if (!$already) {
        // qr_attendance 기록
        $db->prepare("
            INSERT IGNORE INTO qr_attendance (qr_session_id, member_id, group_id, ip_address)
            VALUES (?, ?, ?, ?)
        ")->execute([$session['id'], $memberId, $member['group_id'], getClientIP()]);

        // 복습클래스 연결 여부 확인
        $studyLink = $db->prepare("SELECT id, study_date FROM study_sessions WHERE qr_session_id = ?");
        $studyLink->execute([$session['id']]);
        $studyRow = $studyLink->fetch();

        if ($studyRow) {
            // 복습클래스 출석 → bookclub_join 체크
            $missionCode = 'bookclub_join';
            $checkDate = $studyRow['study_date'];
            $sourceRef = 'study_qr:' . $studyRow['id'];
        } else {
            // 일반 줌 출석 → zoom_daily 체크
            $missionCode = 'zoom_daily';
            $checkDate = date('Y-m-d');
            $sourceRef = 'qr_session:' . $code;
        }

        $missionTypeId = getMissionTypeId($db, $missionCode);
        if ($missionTypeId) {
            saveCheck(
                $db,
                $memberId,
                $checkDate,
                $missionTypeId,
                1,                              // status = pass
                'manual',                       // source (QR은 manual 취급, automation보다 우선)
                $sourceRef,
                $session['admin_id'] ? (int)$session['admin_id'] : null
            );
        }
    }

    jsonSuccess([
        'member_name' => $member['nickname'],
        'already'     => $already,
    ]);
    break;

// ── 패자부활 QR 처리 (공개) ──
case 'revival_record':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);

    $input = getJsonInput();
    $code = trim($input['session_code'] ?? '');
    $memberId = (int)($input['member_id'] ?? 0);
    if (!$code || !$memberId) jsonError('session_code와 member_id가 필요합니다.');

    $db = getDB();
    $session = getActiveSession($db, $code);
    if (!$session || $session['status'] !== 'active') {
        jsonError('세션이 만료되었거나 종료되었습니다.');
    }
    if (($session['session_type'] ?? '') !== 'revival') {
        jsonError('패자부활 세션이 아닙니다.');
    }

    // 멤버 확인
    $memberStmt = $db->prepare("
        SELECT id, nickname, group_id, cohort_id FROM bootcamp_members
        WHERE id = ? AND cohort_id = ? AND is_active = 1 AND member_status != 'withdrawn'
    ");
    $memberStmt->execute([$memberId, $session['cohort_id']]);
    $member = $memberStmt->fetch();
    if (!$member) jsonError('유효하지 않은 회원입니다.');

    // 중복 체크: 같은 세션 내 동일 IP + 동일 User-Agent
    $clientIP = getClientIP();
    $clientUA = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $dupStmt = $db->prepare("
        SELECT id FROM qr_attendance
        WHERE qr_session_id = ? AND ip_address = ? AND user_agent = ?
    ");
    $dupStmt->execute([$session['id'], $clientIP, $clientUA]);
    if ($dupStmt->fetch()) {
        jsonError('이 기기에서 이미 패자부활 처리를 완료했습니다.');
    }

    // 현재 점수 조회
    $scoreStmt = $db->prepare("SELECT current_score FROM member_scores WHERE member_id = ?");
    $scoreStmt->execute([$memberId]);
    $scoreRow = $scoreStmt->fetch();
    $beforeScore = $scoreRow ? (int)$scoreRow['current_score'] : 0;

    // 대상 여부 확인: -15점 이하만
    if ($beforeScore > SCORE_REVIVAL_ELIGIBLE) {
        jsonSuccess([
            'member_name'  => $member['nickname'],
            'not_eligible' => true,
            'current_score' => $beforeScore,
        ], '패자부활전 대상이 아닙니다. (현재 점수: ' . $beforeScore . '점)');
        break;
    }

    // 점수 반영: 현재 점수 + 7
    $afterScore = $beforeScore + SCORE_REVIVAL_BONUS;
    $change = SCORE_REVIVAL_BONUS;
    $adminId = $session['admin_id'] ? (int)$session['admin_id'] : null;
    $note = 'QR 패자부활 (세션: ' . $code . ')';

    // revival_logs 기록
    $db->prepare("
        INSERT INTO revival_logs (member_id, before_score, after_score, note, created_by)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$memberId, $beforeScore, $afterScore, $note, $adminId]);

    // score_logs 기록
    $db->prepare("
        INSERT INTO score_logs (member_id, score_change, before_score, after_score, reason_type, reason_detail, created_by)
        VALUES (?, ?, ?, ?, 'revival_adjustment', ?, ?)
    ")->execute([$memberId, $change, $beforeScore, $afterScore, $note, $adminId]);

    // member_scores 갱신
    $db->prepare("
        INSERT INTO member_scores (member_id, current_score, last_calculated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE current_score = VALUES(current_score), last_calculated_at = NOW()
    ")->execute([$memberId, $afterScore]);

    // 조관리 제외 상태 해제
    if ($afterScore > SCORE_OUT_THRESHOLD) {
        $db->prepare("UPDATE bootcamp_members SET member_status = 'active' WHERE id = ? AND member_status = 'out_of_group_management'")
           ->execute([$memberId]);
    }

    // qr_attendance 기록 (스캔 이력 + 중복 방지용)
    $db->prepare("
        INSERT INTO qr_attendance (qr_session_id, member_id, group_id, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$session['id'], $memberId, $member['group_id'], $clientIP, $clientUA]);

    jsonSuccess([
        'member_name'   => $member['nickname'],
        'not_eligible'  => false,
        'before_score'  => $beforeScore,
        'after_score'   => $afterScore,
        'bonus'         => $change,
    ], $member['nickname'] . '님 패자부활 처리 완료! (' . $beforeScore . ' → ' . $afterScore . ')');
    break;

default:
    jsonError('알 수 없는 action: ' . $action, 404);
}
