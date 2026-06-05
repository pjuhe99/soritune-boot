<?php
/**
 * BRAVO 채점 서비스 테스트. DEV DB 통합(트랜잭션 롤백).
 * 사용: php tests/bravo_grading_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
define('BRAVO_UPLOAD_ROOT', sys_get_temp_dir() . '/bravo_grading_test_' . getmypid());
require_once __DIR__ . '/../public_html/api/services/bravo.php';
require_once __DIR__ . '/../public_html/api/services/bravo_questions.php';
require_once __DIR__ . '/../public_html/api/services/bravo_exam_questions.php';
require_once __DIR__ . '/../public_html/api/services/bravo_attempts.php';
require_once __DIR__ . '/../public_html/api/services/bravo_grading.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// ── 환산 순수함수 (DB 불필요) ──
$J_MAX  = ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good','completion_ok'=>1];
$J_ZERO = ['accuracy'=>'wrong','chunk_ok'=>0,'response_rating'=>'poor','fluency_rating'=>'poor','completion_ok'=>0];
$J_MIX  = ['accuracy'=>'partial','chunk_ok'=>1,'response_rating'=>'normal','fluency_rating'=>'poor','completion_ok'=>1];

t('B1 만점 문항 (N=20)', bravoGradeScore(1, 20, $J_MAX) === 5.0);                  // 100/20
t('B1 0점 문항', bravoGradeScore(1, 20, $J_ZERO) === 0.0);
t('B1 completion 무시', bravoGradeScore(1, 20, array_merge($J_MAX, ['completion_ok'=>0])) === 5.0); // 가중치 0
t('B2 만점 문항 (N=20)', bravoGradeScore(2, 20, $J_MAX) === 5.0);
t('B3 만점 문항 (N=20)', bravoGradeScore(3, 20, $J_MAX) === 5.0);
// B1 혼합: (60*0.5 + 20*1 + 10*0.5 + 10*0)/20 = (30+20+5)/20 = 2.75
t('B1 혼합 판정', bravoGradeScore(1, 20, $J_MIX) === 2.75);
// B2 혼합: (45*0.5 + 20*1 + 15*0.5 + 15*0 + 5*1)/20 = (22.5+20+7.5+5)/20 = 2.75
t('B2 혼합 판정', bravoGradeScore(2, 20, $J_MIX) === 2.75);
// N=3 반올림: B1 만점 = 100/3 = 33.333... → 33.33
t('N=3 반올림', bravoGradeScore(1, 3, $J_MAX) === 33.33);
t('N=0 방어', bravoGradeScore(1, 0, $J_MAX) === 0.0);
t('미정의 등급 방어', bravoGradeScore(9, 20, $J_MAX) === 0.0);

// 판정 검증
t('B1 검증 통과 (completion 없이)', bravoGradeValidate(1, ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good']) === []);
t('B2 completion 누락 거부', bravoGradeValidate(2, ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good']) !== []);
t('잘못된 accuracy 거부', bravoGradeValidate(1, ['accuracy'=>'great','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good']) !== []);
t('chunk_ok 누락 거부', bravoGradeValidate(1, ['accuracy'=>'correct','response_rating'=>'good','fluency_rating'=>'good']) !== []);

// Range 파싱
t('Range 정상 0-99', bravoAudioRangeParse('bytes=0-99', 1000) === [0, 99]);
t('Range 중간', bravoAudioRangeParse('bytes=500-', 1000) === [500, 999]);
t('Range suffix', bravoAudioRangeParse('bytes=-100', 1000) === [900, 999]);
t('Range end 초과 클램프', bravoAudioRangeParse('bytes=0-5000', 1000) === [0, 999]);
t('Range 비정상 → null', bravoAudioRangeParse('bytes=abc', 1000) === null);
t('Range 멀티 → null', bravoAudioRangeParse('bytes=0-1,5-9', 1000) === null);
t('Range start 초과 → null', bravoAudioRangeParse('bytes=1000-', 1000) === null);
t('Range null 헤더', bravoAudioRangeParse(null, 1000) === null);

// ── DB 통합 ──
$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TGR_' . bin2hex(random_bytes(3));

    // 셋업: 기수+회원(자격)+시험(B2, open)+문제 3개+배정+응시+답안 3건+submit
    // ⚠️ cohorts/bootcamp_members INSERT 컬럼은 tests/bravo_attempt_test.php 의 셋업을 그대로 본뜰 것
    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1)")
       ->execute(["{$tag}기", $tag]);
    $cohortId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
       ->execute([$cohortId, "{$tag}응시자", "{$tag}닉", '01000000099', "{$tag}_uid"]);
    $memberId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute(["{$tag}_uid"]);
    bravoGradeSet($db, "{$tag}_uid", 1, 'admin_adjust', 99, null); // B2 이전등급 요건

    $examId = bravoExamCreate($db, ['title'=>"{$tag} 시험",'bravo_level'=>2,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    $qids = [];
    foreach ([1,2,3] as $i) {
        $qids[] = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>2,'korean_text'=>"{$tag} q{$i}",'english_text'=>"answer {$i}",'accepted_answers'=>"alt {$i}",'target_chunks'=>"chunk {$i}",'difficulty'=>'easy','is_active'=>1], 99);
    }
    bravoExamQuestionSet($db, $examId, $qids);

    $acc = bravoAttemptExamAccess($db, $memberId, $examId);
    $r = bravoAttemptStart($db, $acc['exam'], $acc['ctx']['row'], $acc['member_key'], false);
    $attempt = $r['attempt'];
    $answerIds = [];
    foreach ($qids as $q) {
        $f = tempnam(sys_get_temp_dir(), 'tgr_'); file_put_contents($f, 'audio');
        bravoAnswerStore($db, $attempt, $q, $f, 'audio/webm', 'webm', 3000, false);
        $aid = $db->query("SELECT id FROM bravo_answers WHERE attempt_id=" . (int)$attempt['id'] . " AND question_id=" . (int)$q)->fetchColumn();
        $answerIds[$q] = (int)$aid;
    }
    bravoAttemptSubmit($db, $attempt);
    $attempt = bravoAttemptGet($db, (int)$attempt['id']);
    $exam = $acc['exam'];
    t('셋업 정상', $attempt['status'] === 'submitted' && count($answerIds) === 3);

    // save: in_progress attempt 거부 검증용 별도 응시
    $examId2 = bravoExamCreate($db, ['title'=>"{$tag} 진행중",'bravo_level'=>2,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    bravoExamQuestionSet($db, $examId2, [$qids[0]]);
    $acc2 = bravoAttemptExamAccess($db, $memberId, $examId2);
    $r2 = bravoAttemptStart($db, $acc2['exam'], $acc2['ctx']['row'], $acc2['member_key'], false);
    $ipAttempt = $r2['attempt'];
    $f = tempnam(sys_get_temp_dir(), 'tgr_'); file_put_contents($f, 'audio');
    bravoAnswerStore($db, $ipAttempt, $qids[0], $f, 'audio/webm', 'webm', 1000, false);
    $ipAnswerId = (int)$db->query("SELECT id FROM bravo_answers WHERE attempt_id=" . (int)$ipAttempt['id'])->fetchColumn();
    $g = bravoGradeSave($db, $ipAttempt, $acc2['exam'], $ipAnswerId, ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good','completion_ok'=>1], 99);
    t('비submitted 채점 거부', isset($g['error']));

    // save 정상 (B2, N=3): 만점 문항 = 100/3 = 33.33
    $J = ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good','completion_ok'=>1];
    $g = bravoGradeSave($db, $attempt, $exam, $answerIds[$qids[0]], $J + ['memo'=>'좋음'], 99);
    t('save 정상', !isset($g['error']) && $g['score'] === 33.33 && $g['graded_count'] === 1 && $g['total_count'] === 3, json_encode($g));
    $row = $db->query("SELECT * FROM bravo_answer_grades WHERE answer_id=" . $answerIds[$qids[0]])->fetch(PDO::FETCH_ASSOC);
    t('grade 행 (n_denominator/메모/graded_by)', (int)$row['n_denominator'] === 3 && $row['memo'] === '좋음' && (int)$row['graded_by'] === 99);

    // 재판정 갱신 (upsert)
    $g = bravoGradeSave($db, $attempt, $exam, $answerIds[$qids[0]], ['accuracy'=>'wrong','chunk_ok'=>0,'response_rating'=>'poor','fluency_rating'=>'poor','completion_ok'=>0], 99);
    t('재판정 갱신', !isset($g['error']) && $g['score'] === 0.0 && $g['graded_count'] === 1);

    // B2 completion 누락 거부 / 스냅샷 밖 answer 거부
    $g = bravoGradeSave($db, $attempt, $exam, $answerIds[$qids[1]], ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good'], 99);
    t('B2 completion 누락 거부', isset($g['error']));
    $g = bravoGradeSave($db, $attempt, $exam, 99999999, $J, 99);
    t('미존재 answer 거부', isset($g['error']));

    // summary (1문항만 판정 → auto_result null)
    $sum = bravoGradingSummary($db, $attempt, 2);
    t('summary 진행중', $sum['graded_count'] === 1 && $sum['total_count'] === 3 && $sum['auto_result'] === null);

    // ── confirm: 미완 거부 → 완료 → 자동 합불·passing_score 스냅샷 ──
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'pass'], 99);
    t('미완 confirm 거부', isset($r['error']) && $r['missing_count'] === 2);

    // 나머지 2문항 판정: q1=0점(이미 재판정으로 wrong), q2/q3 만점 → 총점 0 + 33.33 + 33.33 = 66.66 ≥ 65 → pass
    foreach ([$qids[1], $qids[2]] as $q) {
        bravoGradeSave($db, $attempt, $exam, $answerIds[$q], $J, 99);
    }
    $sum = bravoGradingSummary($db, $attempt, 2);
    t('전 문항 판정 후 auto_result', $sum['auto_result'] === 'pass' && $sum['total_so_far'] === 66.66, json_encode($sum));

    // 오버라이드 사유 누락 거부 (auto=pass 인데 fail 요청)
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'fail'], 99);
    t('오버라이드 사유 누락 거부', isset($r['error']));

    // 정상 확정 (auto 그대로)
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'pass', 'memo' => '전체 메모'], 99);
    t('confirm 정상', !isset($r['error']) && $r['total_score'] === 66.66 && $r['result'] === 'pass', json_encode($r));
    $cg = bravoAttemptGradeGet($db, (int)$attempt['id']);
    t('확정 행 (passing_score 스냅샷·비오버라이드)', $cg && (float)$cg['passing_score'] === 65.0 && (int)$cg['result_overridden'] === 0 && $cg['memo'] === '전체 메모');

    // 확정 후 판정 save 거부 / 중복 확정 거부
    $g = bravoGradeSave($db, $attempt, $exam, $answerIds[$qids[0]], $J, 99);
    t('확정 후 save 거부', isset($g['error']));
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'pass'], 99);
    t('중복 확정 거부', isset($r['error']));

    // cancel → 재확정 (오버라이드: auto=pass 를 fail 로 + 사유)
    $r = bravoAttemptConfirmCancel($db, $attempt, $exam);
    // cancel 직후 — q3 삭제 전 시점이라 grade 3건 보존 확인
    t('cancel 정상 (판정 보존)', !isset($r['error']) && bravoAttemptGradeGet($db, (int)$attempt['id']) === null
        && (int)$db->query("SELECT COUNT(*) FROM bravo_answer_grades WHERE attempt_id=" . (int)$attempt['id'])->fetchColumn() === 3);
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'fail', 'override_reason' => '발음 불명확 재검토'], 99);
    t('오버라이드 확정', !isset($r['error']) && $r['result'] === 'fail');
    $cg = bravoAttemptGradeGet($db, (int)$attempt['id']);
    t('오버라이드 기록', (int)$cg['result_overridden'] === 1 && $cg['override_reason'] === '발음 불명확 재검토');

    // released 후 cancel 거부
    $db->prepare("UPDATE bravo_exams SET status='released' WHERE id=?")->execute([$examId]);
    $examReleased = $db->query("SELECT * FROM bravo_exams WHERE id=" . (int)$examId)->fetch(PDO::FETCH_ASSOC);
    $r = bravoAttemptConfirmCancel($db, $attempt, $examReleased);
    t('released 후 cancel 거부', isset($r['error']));
    $db->prepare("UPDATE bravo_exams SET status='closed' WHERE id=?")->execute([$examId]);

    // ── 문항 삭제 엣지: 유령 grade 제외 + 자동 재환산 ──
    bravoAttemptConfirmCancel($db, $attempt, $db->query("SELECT * FROM bravo_exams WHERE id=" . (int)$examId)->fetch(PDO::FETCH_ASSOC));
    bravoQuestionDelete($db, $qids[2]); // q3 삭제 → N: 3→2, q3 grade 는 유령
    $sum = bravoGradingSummary($db, $attempt, 2);
    t('유령 grade 제외 집계', $sum['total_count'] === 2 && $sum['graded_count'] === 2);
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'pass'], 99);
    // 재환산: q1=wrong(0점, N=2 여도 0), q2=만점 → 100/2=50. 총점 50 ≥ 65? 아니오 → auto=fail. pass 요청은 오버라이드 사유 필요 → 거부
    t('재환산 후 auto 변동 → 오버라이드 사유 요구', isset($r['error']));
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'fail'], 99);
    t('재환산 확정 (단일 N)', !isset($r['error']) && $r['total_score'] === 50.0, json_encode($r));
    $regraded = $db->query("SELECT n_denominator FROM bravo_answer_grades g JOIN bravo_answers a ON g.answer_id=a.id WHERE g.attempt_id=" . (int)$attempt['id'] . " AND a.question_id=" . (int)$qids[1])->fetchColumn();
    t('재환산 n_denominator 갱신', (int)$regraded === 2);

    // ── 목록 ──
    $exams = bravoGradingExamList($db);
    $mine = null;
    foreach ($exams as $e) { if ((int)$e['id'] === $examId) $mine = $e; }
    t('exam_list 포함 + 카운트', $mine !== null && (int)$mine['counts']['total'] === 1 && (int)$mine['counts']['confirmed'] === 1);

    $list = bravoGradingAttemptList($db, $examId);
    t('attempt_list', count($list) === 1 && $list[0]['member_name'] === "{$tag}응시자"
        && (int)$list[0]['graded_count'] === 2 && (int)$list[0]['total_count'] === 2
        && $list[0]['confirmed'] !== null && $list[0]['confirmed']['result'] === 'fail');

    // ── detail ──
    $d = bravoGradingDetail($db, $attempt, $exam);
    t('detail items (현존 2문항 + 정답 포함)', count($d['items']) === 2
        && $d['items'][0]['question']['english_text'] === 'answer 1'
        && $d['items'][0]['grade'] !== null && $d['items'][1]['grade'] !== null);
    t('detail 메타', $d['passing_score'] === 65.0 && isset($d['weights']['accuracy']) && $d['confirmed'] !== null);

    // ── cascade: 시험 삭제 → grades 정리 ──
    bravoExamDelete($db, $examId);
    t('시험 삭제 → answer_grades 0건', (int)$db->query("SELECT COUNT(*) FROM bravo_answer_grades WHERE attempt_id=" . (int)$attempt['id'])->fetchColumn() === 0);
    t('시험 삭제 → attempt_grades 0건', (int)$db->query("SELECT COUNT(*) FROM bravo_attempt_grades WHERE attempt_id=" . (int)$attempt['id'])->fetchColumn() === 0);

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

// tmp 정리
if (is_dir(BRAVO_UPLOAD_ROOT)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BRAVO_UPLOAD_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname()); }
    @rmdir(BRAVO_UPLOAD_ROOT);
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
