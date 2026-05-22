<?php
/**
 * expelled 회원의 QR 스캔이 qrRecordAttendance 에서 거부되는지.
 *
 * 사용: php tests/expelled_qr_scan_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/qr_actions.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();
$db->beginTransaction();

try {
    $cohortLabel = 'TEST_XQR_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, '퇴출', 'x', 'expelled', 1, 1, CURDATE())")
       ->execute([$cohortId]);
    $memberId = (int)$db->lastInsertId();

    // qr_sessions 픽스처 (Task 6 의 leaving QR 테스트와 동일 schema 사용)
    $sessionCode = 'TXQR' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO qr_sessions
        (cohort_id, session_code, status, session_type, admin_id, expires_at, created_at)
        VALUES (?, ?, 'active', 'attendance', 0, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())")
       ->execute([$cohortId, $sessionCode]);
    $sessionId = (int)$db->lastInsertId();

    $session = [
        'id' => $sessionId,
        'cohort_id' => $cohortId,
        'session_code' => $sessionCode,
        'status' => 'active',
        'session_type' => 'attendance',
        'admin_id' => null,
    ];

    $result = qrRecordAttendance($db, $session, $memberId, null, '127.0.0.1', 'test');

    t('qrRecordAttendance ok=false (expelled 거부)', empty($result['ok']));

    // member_mission_checks INSERT 안 됨
    $cnt = $db->prepare("SELECT COUNT(*) FROM member_mission_checks WHERE member_id = ? AND check_date = CURDATE()");
    $cnt->execute([$memberId]);
    t('member_mission_checks INSERT 안 됨', (int)$cnt->fetchColumn() === 0);

    // qr_attendance INSERT 안 됨
    $att = $db->prepare("SELECT COUNT(*) FROM qr_attendance WHERE qr_session_id = ? AND member_id = ?");
    $att->execute([$sessionId, $memberId]);
    t('qr_attendance INSERT 안 됨', (int)$att->fetchColumn() === 0);
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
