<?php
/**
 * BRAVO 회원 상태 서비스 통합 테스트. DEV DB transaction rollback.
 * 사용: php tests/bravo_member_status_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/bravo.php';

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
    $label = 'TEST_MBRV_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$label, $label]);
    $cohortId = (int)$db->lastInsertId();

    $uid = 'mbrv_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, member_status, is_active, stage_no, joined_at) VALUES (?, ?, ?, ?, ?, 'active', 1, 1, CURDATE())")
       ->execute([$cohortId, '김회원', '회원닉', '01099998888', $uid]);
    $memberId = (int)$db->lastInsertId();

    // completed 6 (user_id-row) → 자동 eligible [1,2]
    $db->prepare("INSERT INTO member_history_stats (user_id, stage1_participation_count, stage2_participation_count, completed_bootcamp_count, last_calculated_at) VALUES (?, 0, 0, 6, NOW())")
       ->execute([$uid]);

    // 시험 시드
    $insExam = $db->prepare("INSERT INTO bravo_exams (title, bravo_level, exam_mode, start_at, end_at, result_release_at, attempt_limit, target_type, target_cohort_id, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,99)");
    $insExam->execute(['L1 오픈', 1, 'period', '2026-06-01 10:00:00', '2026-06-02 10:00:00', '2026-06-12 10:00:00', 3, 'all', null, 'open']);
    $insExam->execute(['L1 준비중', 1, 'period', '2026-07-01 10:00:00', '2026-07-02 10:00:00', '2026-07-12 10:00:00', 3, 'all', null, 'preparing']);
    $insExam->execute(['L2 종료', 2, 'period', '2026-05-01 10:00:00', '2026-05-02 10:00:00', '2026-05-12 10:00:00', 3, 'cohort', $cohortId, 'closed']);

    $st = bravoMemberStatus($db, $memberId);
    $by = [];
    foreach ($st['levels'] as $lv) $by[(int)$lv['level']] = $lv;

    t('levels 3개', count($st['levels']) === 3, 'count=' . count($st['levels']));
    t('member effective_review 6', (int)$st['member']['effective_review_count'] === 6);
    t('L1 eligible', $by[1]['eligible'] === true);
    t('L1 exam 존재', $by[1]['exam'] !== null);
    t('L1 exam status open (준비중 아님)', ($by[1]['exam']['status'] ?? '') === 'open', $by[1]['exam']['title'] ?? '(null)');
    t('L2 eligible', $by[2]['eligible'] === true);
    t('L2 exam status closed (기수 매칭)', ($by[2]['exam']['status'] ?? '') === 'closed');
    t('L3 ineligible (6<10)', $by[3]['eligible'] === false);
    t('L3 exam null', $by[3]['exam'] === null);

    // override 10 → L3 eligible
    $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute([$uid]);
    $st2 = bravoMemberStatus($db, $memberId);
    $by2 = [];
    foreach ($st2['levels'] as $lv) $by2[(int)$lv['level']] = $lv;
    t('override 10 → L3 eligible', $by2[3]['eligible'] === true);
    t('override 10 → effective_review 10', (int)$st2['member']['effective_review_count'] === 10);

    // ── slice6: levels[].attempts (카드 시험 exam_id 기준 집계) + member_key 헬퍼 ──
    $tag6 = 'ST6_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute(["{$tag6}기", $tag6]);
    $cohort6 = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, member_status, is_active, stage_no, joined_at) VALUES (?,?,?,?,?,'active',1,1,CURDATE())")
       ->execute([$cohort6, "{$tag6}회원", "{$tag6}닉", '01099990001', "{$tag6}_uid"]);
    $member6 = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute(["{$tag6}_uid"]);
    $exam6 = bravoExamCreate($db, ['title'=>"{$tag6} 시험",'bravo_level'=>1,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);

    $st = bravoMemberStatus($db, $member6);
    $lv1 = null;
    foreach ($st['levels'] as $lv) { if ((int)$lv['level'] === 1) $lv1 = $lv; }
    t('attempts 필드 존재', $lv1 !== null && array_key_exists('attempts', $lv1));
    t('exam 에 id/attempt_limit 포함', $lv1['exam'] !== null && isset($lv1['exam']['id']) && isset($lv1['exam']['attempt_limit']));
    t('attempts.exam_id = 카드 시험', (int)$lv1['attempts']['exam_id'] === (int)$lv1['exam']['id']);
    t('attempts 초기 used 0', (int)$lv1['attempts']['used'] === 0 && $lv1['attempts']['in_progress'] === null && $lv1['attempts']['submitted'] === false);

    // attempt 행 직접 삽입 후 반영 확인 (응시 서비스는 Task3 — 여기선 SQL 로)
    $db->prepare("INSERT INTO bravo_attempts (exam_id, member_key, member_id, attempt_no, question_ids) VALUES (?,?,?,1,'[1,2]')")
       ->execute([$exam6, "{$tag6}_uid", $member6]);
    $st2 = bravoMemberStatus($db, $member6);
    foreach ($st2['levels'] as $lv) { if ((int)$lv['level'] === 1) $lv1 = $lv; }
    t('in_progress 반영', (int)$lv1['attempts']['used'] === 1 && $lv1['attempts']['in_progress'] !== null && (int)$lv1['attempts']['in_progress']['total'] === 2);

    $db->prepare("UPDATE bravo_attempts SET status='submitted', submitted_at=NOW() WHERE exam_id=? AND member_key=?")->execute([$exam6, "{$tag6}_uid"]);
    $st3 = bravoMemberStatus($db, $member6);
    foreach ($st3['levels'] as $lv) { if ((int)$lv['level'] === 1) $lv1 = $lv; }
    t('submitted 반영', $lv1['attempts']['submitted'] === true && $lv1['attempts']['in_progress'] === null);

    // member_key 헬퍼 (순수)
    t('member_key user_id 우선', bravoAttemptMemberKey(['user_id' => 'abc', 'phone' => '01012345678']) === 'abc');
    t('member_key phone 폴백', bravoAttemptMemberKey(['user_id' => '', 'phone' => '01012345678']) === 'p:01012345678');
    t('member_key user_id 공백 폴백', bravoAttemptMemberKey(['user_id' => '  ', 'phone' => '010']) === 'p:010');

    $db->rollBack();
} catch (\Throwable $e) {
    $db->rollBack();
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
