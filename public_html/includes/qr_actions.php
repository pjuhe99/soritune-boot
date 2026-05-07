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

/**
 * QR 패자부활 처리 — reserve-first 패턴.
 *
 * @return array {
 *   ok: bool,
 *   already?: bool,
 *   not_eligible?: bool,
 *   member_name?: string,
 *   before_score?: int,
 *   after_score?: int,
 *   bonus?: int,
 *   current_score?: int,    // not_eligible 일 때
 *   error?: string,
 *   http_status?: int,
 * }
 */
function qrRecordRevival(PDO $db, array $session, int $memberId, ?int $actorMemberId, string $clientIp, string $userAgent): array {
    if (($session['session_type'] ?? '') !== 'revival') {
        return ['ok' => false, 'error' => '패자부활 세션이 아닙니다.', 'http_status' => 400];
    }

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
        return ['ok' => true, 'already' => true, 'member_name' => $member['nickname']];
    }

    // 점수 최신화 + 부적격 체크
    ensureMemberScoreFresh($db, $memberId);
    $scoreStmt = $db->prepare("SELECT current_score FROM member_scores WHERE member_id = ?");
    $scoreStmt->execute([$memberId]);
    $scoreRow = $scoreStmt->fetch();
    $beforeScore = $scoreRow ? (int)$scoreRow['current_score'] : 0;

    if ($beforeScore > SCORE_REVIVAL_ELIGIBLE) {
        // 가드 row 는 이미 만들어졌음 — 의도된 동작 (재진입 차단)
        return [
            'ok' => true,
            'not_eligible' => true,
            'member_name' => $member['nickname'],
            'current_score' => $beforeScore,
        ];
    }

    // 점수 +7 적용
    $afterScore = $beforeScore + SCORE_REVIVAL_BONUS;
    $change = SCORE_REVIVAL_BONUS;
    $sessionAdminId = $session['admin_id'] ? (int)$session['admin_id'] : null;
    $note = 'QR 패자부활 (세션: ' . $session['session_code'] . ')';

    // revival_logs (qr_session_id 포함, actor 기록)
    $db->prepare("
        INSERT INTO revival_logs (member_id, actor_member_id, qr_session_id, before_score, after_score, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$memberId, $actorMemberId, $session['id'], $beforeScore, $afterScore, $note, $sessionAdminId]);

    // score_logs
    $db->prepare("
        INSERT INTO score_logs (member_id, score_change, before_score, after_score, reason_type, reason_detail, created_by)
        VALUES (?, ?, ?, ?, 'revival_adjustment', ?, ?)
    ")->execute([$memberId, $change, $beforeScore, $afterScore, $note, $sessionAdminId]);

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

    return [
        'ok' => true,
        'already' => false,
        'not_eligible' => false,
        'member_name' => $member['nickname'],
        'before_score' => $beforeScore,
        'after_score' => $afterScore,
        'bonus' => $change,
    ];
}
