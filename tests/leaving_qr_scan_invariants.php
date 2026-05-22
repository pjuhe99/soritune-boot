<?php
/**
 * leaving 회원이 QR 스캔 시 qrRecordAttendance 가 정상 통과하고
 * member_mission_checks 에 status=1 으로 INSERT 되는지.
 *
 * 사용: php tests/leaving_qr_scan_invariants.php
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
    // cohort + member (leaving, group_id=NULL)
    $cohortLabel = 'TEST_QR_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, '나간', 'l', 'leaving', 1, 1, CURDATE())")
       ->execute([$cohortId]);
    $memberId = (int)$db->lastInsertId();

    // QR session (zoom_daily 매핑되는 일반 세션, study_sessions 연결 없음)
    // session_type enum: 'attendance'|'revival'; admin_id NOT NULL → 0 (테스트 픽스처)
    // expires_at NOT NULL → 1시간 후
    $sessionCode = 'TQR' . bin2hex(random_bytes(3));
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

    // 호출
    $result = qrRecordAttendance($db, $session, $memberId, null, '127.0.0.1', 'test');

    t('qrRecordAttendance ok=true', !empty($result['ok']));
    t('이미 처리됨 아님', empty($result['already']));

    // member_mission_checks 검증
    $row = $db->prepare("
        SELECT mmc.status, mt.code
        FROM member_mission_checks mmc
        JOIN mission_types mt ON mt.id = mmc.mission_type_id
        WHERE mmc.member_id = ? AND mmc.check_date = CURDATE()
    ");
    $row->execute([$memberId]);
    $rows = $row->fetchAll(PDO::FETCH_ASSOC);

    $zoomRow = null;
    foreach ($rows as $r) {
        if ($r['code'] === 'zoom_daily') { $zoomRow = $r; break; }
    }
    t('zoom_daily 체크 INSERT 됨', $zoomRow !== null);
    t('zoom_daily status=1', $zoomRow && (int)$zoomRow['status'] === 1);

    // qr_attendance 검증
    $att = $db->prepare("SELECT COUNT(*) FROM qr_attendance WHERE qr_session_id = ? AND member_id = ?");
    $att->execute([$sessionId, $memberId]);
    t('qr_attendance 행 1개', (int)$att->fetchColumn() === 1);
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
