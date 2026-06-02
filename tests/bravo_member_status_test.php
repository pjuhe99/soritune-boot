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

    $db->rollBack();
} catch (\Throwable $e) {
    $db->rollBack();
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
