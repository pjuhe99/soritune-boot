<?php
/**
 * boot.soritune.com - QR action 헬퍼
 * api/qr.php 의 record / revival_record 본문을 추출.
 */

require_once __DIR__ . '/bootcamp_functions.php';
require_once __DIR__ . '/coin_functions.php';

/**
 * QR 출석 처리 — reserve-first 패턴.
 *
 * @return array {
 *   ok: bool,
 *   already?: bool,
 *   member_name?: string,
 *   error?: string,
 *   http_status?: int,    // error 일 때
 * }
 */
function qrRecordAttendance(PDO $db, array $session, int $memberId, ?int $actorMemberId, string $clientIp, string $userAgent): array {
    // 멤버 검증
    $memberStmt = $db->prepare("
        SELECT id, nickname, group_id, cohort_id FROM bootcamp_members
        WHERE id = ? AND cohort_id = ? AND is_active = 1 AND member_status != 'refunded'
    ");
    $memberStmt->execute([$memberId, $session['cohort_id']]);
    $member = $memberStmt->fetch();
    if (!$member) {
        return ['ok' => false, 'error' => '유효하지 않은 회원입니다.', 'http_status' => 400];
    }

    // Reserve-first: UNIQUE 가드 가장 먼저
    $insert = $db->prepare("
        INSERT IGNORE INTO qr_attendance (qr_session_id, member_id, actor_member_id, group_id, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([$session['id'], $memberId, $actorMemberId, $member['group_id'], $clientIp, $userAgent]);

    if ($insert->rowCount() === 0) {
        // 이미 처리된 회원
        return ['ok' => true, 'already' => true, 'member_name' => $member['nickname']];
    }

    // 첫 처리: saveCheck 부수 효과
    $studyLink = $db->prepare("SELECT id, study_date FROM study_sessions WHERE qr_session_id = ?");
    $studyLink->execute([$session['id']]);
    $studyRow = $studyLink->fetch();

    if ($studyRow) {
        $missionCode = 'bookclub_join';
        $checkDate = $studyRow['study_date'];
        $sourceRef = 'study_qr:' . $studyRow['id'];
    } else {
        $missionCode = 'zoom_daily';
        $checkDate = date('Y-m-d');
        $sourceRef = 'qr_session:' . $session['session_code'];
    }

    $missionTypeId = getMissionTypeId($db, $missionCode);
    if ($missionTypeId) {
        saveCheck(
            $db,
            $memberId,
            $checkDate,
            $missionTypeId,
            1,                              // pass
            'manual',                       // QR 은 manual (automation 보다 우선)
            $sourceRef,
            $session['admin_id'] ? (int)$session['admin_id'] : null
        );
    }

    return ['ok' => true, 'already' => false, 'member_name' => $member['nickname']];
}
