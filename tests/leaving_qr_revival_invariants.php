<?php
/**
 * leaving 회원이 revival(복습스터디/패자부활) QR 세션에서:
 *  - qrRecordRevival 게이트 통과
 *  - 부활 대상 점수 (<= -10) 일 때 +7점 부활 보너스 받음
 *  - qr_attendance / revival_logs / score_logs INSERT
 *  - member_status 는 'leaving' 그대로 유지 (OOM→active UPDATE 가 leaving 에 무관)
 *
 * 사용: php tests/leaving_qr_revival_invariants.php
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
    // cohort + leaving 회원 (group_id=NULL)
    $cohortLabel = 'TEST_LREV_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, '나간복습', 'lrev', 'leaving', 1, 1, CURDATE())")
       ->execute([$cohortId]);
    $memberId = (int)$db->lastInsertId();

    // 점수 -15 시드 (eligible — SCORE_REVIVAL_ELIGIBLE=-10 이하)
    // last_calculated_at=NOW() 로 ensureMemberScoreFresh 가 재계산 skip 하게.
    $db->prepare("INSERT INTO member_scores (member_id, current_score, last_calculated_at)
                  VALUES (?, -15, NOW())")
       ->execute([$memberId]);

    // revival QR session
    $sessionCode = 'TLREV' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO qr_sessions
        (cohort_id, session_code, status, session_type, admin_id, expires_at, created_at)
        VALUES (?, ?, 'active', 'revival', 1, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())")
       ->execute([$cohortId, $sessionCode]);
    $sessionId = (int)$db->lastInsertId();

    $session = [
        'id' => $sessionId,
        'cohort_id' => $cohortId,
        'session_code' => $sessionCode,
        'status' => 'active',
        'session_type' => 'revival',
        'admin_id' => 1,
    ];

    $result = qrRecordRevival($db, $session, $memberId, null, '127.0.0.1', 'test');

    t('qrRecordRevival ok=true (leaving 통과)', !empty($result['ok']));
    t('not_eligible 아님 (점수 -15 ≤ -10)', empty($result['not_eligible']));
    t('이미 처리됨 아님', empty($result['already']));
    t('before_score = -15', ($result['before_score'] ?? null) === -15);
    t('after_score = -8 (+7 보너스)', ($result['after_score'] ?? null) === -8);
    t('bonus = +7', ($result['bonus'] ?? null) === 7);

    // qr_attendance row 검증
    $att = $db->prepare("SELECT COUNT(*) FROM qr_attendance WHERE qr_session_id = ? AND member_id = ?");
    $att->execute([$sessionId, $memberId]);
    t('qr_attendance 행 1개', (int)$att->fetchColumn() === 1);

    // revival_logs row 검증
    $rev = $db->prepare("SELECT COUNT(*) FROM revival_logs WHERE qr_session_id = ? AND member_id = ?");
    $rev->execute([$sessionId, $memberId]);
    t('revival_logs 행 1개', (int)$rev->fetchColumn() === 1);

    // score_logs row 검증 (reason_type='revival_adjustment')
    $sl = $db->prepare("SELECT COUNT(*) FROM score_logs
                         WHERE member_id = ? AND reason_type = 'revival_adjustment'");
    $sl->execute([$memberId]);
    t('score_logs revival_adjustment 행 1개', (int)$sl->fetchColumn() === 1);

    // member_scores after 검증
    $ms = $db->prepare("SELECT current_score FROM member_scores WHERE member_id = ?");
    $ms->execute([$memberId]);
    t('member_scores.current_score = -8', (int)$ms->fetchColumn() === -8);

    // 핵심: member_status 는 'leaving' 그대로 유지 (auto-restore 가 OOM 만 매칭하므로)
    $stat = $db->prepare("SELECT member_status FROM bootcamp_members WHERE id = ?");
    $stat->execute([$memberId]);
    t('member_status = leaving 유지 (auto-restore 가 OOM 만 매칭)',
      $stat->fetchColumn() === 'leaving');
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
