<?php
/**
 * BRAVO 응시 서비스 테스트. DEV DB 통합(트랜잭션 롤백) + tmp 업로드 루트.
 * 사용: php tests/bravo_attempt_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
define('BRAVO_UPLOAD_ROOT', sys_get_temp_dir() . '/bravo_test_uploads_' . getmypid());
require_once __DIR__ . '/../public_html/api/services/bravo.php';
require_once __DIR__ . '/../public_html/api/services/bravo_questions.php';
require_once __DIR__ . '/../public_html/api/services/bravo_exam_questions.php';
require_once __DIR__ . '/../public_html/api/services/bravo_attempts.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

/** tmp 음성 파일 생성 (서비스는 viaUpload=false 경로로 rename) */
function makeTmpAudio(string $content = 'dummy-audio'): string {
    $p = tempnam(sys_get_temp_dir(), 'bta_');
    file_put_contents($p, $content);
    return $p;
}

$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TAT_' . bin2hex(random_bytes(3));

    // ── 셋업: 기수 + 회원 2명 (자격자/무자격자) + 시험 + 문제 3개 + 배정 ──
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute(["{$tag}기", $tag]);
    $cohortId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
       ->execute([$cohortId, "{$tag}응시자", "{$tag}닉", '01000000001', "{$tag}_uid"]);
    $memberId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
       ->execute([$cohortId, "{$tag}무자격", "{$tag}닉2", '01000000002', "{$tag}_uid2"]);
    $memberId2 = (int)$db->lastInsertId();
    // 자격: override 10회독 → 전 레벨 eligible
    $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute(["{$tag}_uid"]);

    $examId = bravoExamCreate($db, [
        'title'=>"{$tag} 시험",'bravo_level'=>1,'exam_mode'=>'always',
        'attempt_limit'=>3,'target_type'=>'all','status'=>'open',
    ], 99);
    $q1 = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>1,'korean_text'=>"{$tag} q1",'english_text'=>'q1','difficulty'=>'easy','is_active'=>1], 99);
    $q2 = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>1,'korean_text'=>"{$tag} q2",'english_text'=>'q2','difficulty'=>'easy','is_active'=>1], 99);
    $q3 = bravoQuestionCreate($db, ['question_type'=>2,'bravo_level'=>1,'korean_text'=>"{$tag} q3",'english_text'=>'q3','difficulty'=>'normal','is_active'=>1], 99);
    bravoExamQuestionSet($db, $examId, [$q1, $q2, $q3]);
    t('셋업 정상', $cohortId>0 && $memberId>0 && $examId>0 && $q1>0);

    // ── 접근 검증 ──
    $acc = bravoAttemptExamAccess($db, $memberId, 99999999);
    t('미존재 시험 거부', isset($acc['error']) && ($acc['code'] ?? 0) === 404);

    $prepId = bravoExamCreate($db, ['title'=>"{$tag} 준비중",'bravo_level'=>1,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'preparing'], 99);
    $acc = bravoAttemptExamAccess($db, $memberId, $prepId);
    t('preparing 시험 거부', isset($acc['error']));

    $otherCohortExam = bravoExamCreate($db, ['title'=>"{$tag} 타기수",'bravo_level'=>1,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'cohort','target_cohort_id'=>$cohortId+999999,'status'=>'open'], 99);
    $acc = bravoAttemptExamAccess($db, $memberId, $otherCohortExam);
    t('cohort 대상 불일치 거부', isset($acc['error']) && ($acc['code'] ?? 0) === 403);

    $acc = bravoAttemptExamAccess($db, $memberId2, $examId);
    t('자격 미달 거부', isset($acc['error']) && ($acc['code'] ?? 0) === 403);

    $pastExam = bravoExamCreate($db, ['title'=>"{$tag} 만료",'bravo_level'=>1,'exam_mode'=>'period','start_at'=>'2020-01-01 00:00','end_at'=>'2020-01-02 00:00','result_release_at'=>'2020-01-10 00:00','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    $acc = bravoAttemptExamAccess($db, $memberId, $pastExam);
    t('기간 만료 거부', isset($acc['error']));

    $acc = bravoAttemptExamAccess($db, $memberId, $examId);
    t('정상 접근 통과', isset($acc['exam']) && $acc['member_key'] === "{$tag}_uid");
    $exam = $acc['exam']; $mk = $acc['member_key']; $mrow = $acc['ctx']['row'];

    // ── start ──
    $r = bravoAttemptStart($db, $exam, $mrow, $mk, false);
    t('start 정상 (require_check 없음)', isset($r['attempt']) && (int)$r['attempt']['attempt_no'] === 1 && empty($r['resumed']));
    $attempt = $r['attempt'];
    $snap = json_decode($attempt['question_ids'], true);
    t('스냅샷 = 배정 순서', $snap === [$q1, $q2, $q3], json_encode($snap));

    $r2 = bravoAttemptStart($db, $exam, $mrow, $mk, false);
    t('start 재호출 → 동일 attempt (이어하기, 차감 없음)', isset($r2['attempt']) && (int)$r2['attempt']['id'] === (int)$attempt['id'] && !empty($r2['resumed']));

    // 동시 시작: 이미 in_progress 가 있으면 mutex 안에서도 최상단 이어하기로 반환
    $db->prepare("DELETE FROM bravo_attempts WHERE id = ?")->execute([(int)$attempt['id']]);
    // 먼저 in_progress 행을 삽입해 두면 bravoAttemptFindInProgress 가 최상단에서 잡아 resumed=true
    $db->prepare("INSERT INTO bravo_attempts (exam_id, member_key, member_id, attempt_no, question_ids) VALUES (?,?,?,1,?)")
       ->execute([$examId, $mk, $memberId, json_encode([$q1, $q2, $q3])]);
    $r3 = bravoAttemptStart($db, $exam, $mrow, $mk, true); // ot_checked=true (OT require_check 는 아직 미설정)
    t('이미 in_progress → 이어하기 반환 (resumed=true)', isset($r3['attempt']) && !empty($r3['resumed']));
    $db->prepare("DELETE FROM bravo_attempts WHERE exam_id = ? AND member_key = ?")->execute([$examId, $mk]);

    // require_check=1 시험: 미체크 거부 / 체크 시 ot_checked_at 기록
    bravoOtUpsert($db, $examId, ['exam_id'=>$examId, 'intro_text'=>'안내', 'require_check'=>1], 99);
    $r = bravoAttemptStart($db, $exam, $mrow, $mk, false);
    t('require_check 미체크 거부', isset($r['error']));
    $r = bravoAttemptStart($db, $exam, $mrow, $mk, true);
    t('체크 시 시작 + ot_checked_at', isset($r['attempt']) && $r['attempt']['ot_checked_at'] !== null);
    $attempt = $r['attempt'];

    // 배정 0건 시험 start 거부
    $emptyExam = bravoExamCreate($db, ['title'=>"{$tag} 빈배정",'bravo_level'=>1,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    $eAcc = bravoAttemptExamAccess($db, $memberId, $emptyExam);
    $r = bravoAttemptStart($db, $eAcc['exam'], $mrow, $mk, false);
    t('배정 0건 거부', isset($r['error']));

    // ── questions 페이로드 ──
    $qs = bravoAttemptQuestions($db, $attempt);
    t('questions 3건 + seq + 정답 미포함', count($qs) === 3 && (int)$qs[0]['seq'] === 0 && (int)$qs[0]['id'] === $q1
        && !isset($qs[0]['english_text']) && !isset($qs[0]['accepted_answers']));

    // ── answer 저장 ──
    $f = makeTmpAudio('rec-1');
    $r = bravoAnswerStore($db, $attempt, $q1, $f, 'audio/webm', 'webm', 4200, false);
    t('answer 저장', !isset($r['error']) && (int)$r['answered_count'] === 1 && $r['all_answered'] === false);
    $saved = $db->prepare("SELECT * FROM bravo_answers WHERE attempt_id=? AND question_id=?");
    $saved->execute([(int)$attempt['id'], $q1]);
    $row = $saved->fetch(PDO::FETCH_ASSOC);
    t('answer 행 내용', $row && (int)$row['seq'] === 0 && (int)$row['retake_used'] === 0 && (int)$row['duration_ms'] === 4200);
    t('파일 저장됨', is_file(BRAVO_UPLOAD_ROOT . '/' . $row['audio_path']));

    // 재녹음 1회 (교체 + retake_used=1)
    $f = makeTmpAudio('rec-1-retake');
    $r = bravoAnswerStore($db, $attempt, $q1, $f, 'audio/mp4', 'm4a', 3100, false);
    t('재녹음 교체', !isset($r['error']) && (int)$r['answered_count'] === 1);
    $saved->execute([(int)$attempt['id'], $q1]);
    $row = $saved->fetch(PDO::FETCH_ASSOC);
    t('재녹음 retake_used=1 + mime 갱신', (int)$row['retake_used'] === 1 && $row['audio_mime'] === 'audio/mp4');
    t('확장자 교체 시 옛 파일 정리', !is_file(BRAVO_UPLOAD_ROOT . '/answers/' . (int)$attempt['id'] . '/' . $q1 . '.webm')
        && is_file(BRAVO_UPLOAD_ROOT . '/answers/' . (int)$attempt['id'] . '/' . $q1 . '.m4a'));

    // 재녹음 2회째 거부
    $f = makeTmpAudio('rec-1-again');
    $r = bravoAnswerStore($db, $attempt, $q1, $f, 'audio/webm', 'webm', 1000, false);
    t('재녹음 2회 거부', isset($r['error']));
    @unlink($f);

    // 스냅샷 밖 문제 거부
    $f = makeTmpAudio('ghost');
    $r = bravoAnswerStore($db, $attempt, 99999999, $f, 'audio/webm', 'webm', 1000, false);
    t('스냅샷 밖 거부', isset($r['error']));
    @unlink($f);

    // ── submit: 미완료 거부 → 완료 후 성공 ──
    $r = bravoAttemptSubmit($db, $attempt);
    t('미완료 submit 거부 + missing', isset($r['error']) && count($r['missing']) === 2);

    foreach ([$q2, $q3] as $q) {
        $f = makeTmpAudio("rec-{$q}");
        $r = bravoAnswerStore($db, $attempt, $q, $f, 'audio/webm', 'webm', 2000, false);
    }
    t('마지막 저장 all_answered', $r['all_answered'] === true && (int)$r['answered_count'] === 3);

    $r = bravoAttemptSubmit($db, $attempt);
    t('submit 성공', !isset($r['error']) && !empty($r['submitted']));
    $cur = bravoAttemptGet($db, (int)$attempt['id']);
    t('status=submitted + submitted_at', $cur['status'] === 'submitted' && $cur['submitted_at'] !== null);

    $r = bravoAttemptSubmit($db, $cur);
    t('중복 submit 거부', isset($r['error']));
    $f = makeTmpAudio('late');
    $r = bravoAnswerStore($db, $cur, $q2, $f, 'audio/webm', 'webm', 1000, false);
    t('submitted 후 save 거부', isset($r['error']));
    @unlink($f);

    // 제출 후 재시작 잠금
    $r = bravoAttemptStart($db, $exam, $mrow, $mk, true);
    t('제출 후 재응시 잠금', isset($r['error']));

    // ── submit 은 기간 무관 (마감 직전 완주 구제) ──
    $pExamId = bravoExamCreate($db, ['title'=>"{$tag} 기간시험",'bravo_level'=>1,'exam_mode'=>'period',
        'start_at'=>date('Y-m-d H:i', strtotime('-1 hour')), 'end_at'=>date('Y-m-d H:i', strtotime('+1 hour')),
        'result_release_at'=>date('Y-m-d H:i', strtotime('+2 hour')),
        'attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    bravoExamQuestionSet($db, $pExamId, [$q1]);
    $pAcc = bravoAttemptExamAccess($db, $memberId, $pExamId);
    $r = bravoAttemptStart($db, $pAcc['exam'], $mrow, $mk, false);
    $pAttempt = $r['attempt'];
    $f = makeTmpAudio('p-rec');
    bravoAnswerStore($db, $pAttempt, $q1, $f, 'audio/webm', 'webm', 1000, false);
    // 기간 종료로 강제 변경 후 submit
    $db->prepare("UPDATE bravo_exams SET end_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', strtotime('-10 minutes')), $pExamId]);
    $pExamRow = $db->query("SELECT * FROM bravo_exams WHERE id = " . (int)$pExamId)->fetch(PDO::FETCH_ASSOC);
    t('기간 만료 후 save 게이트 false', bravoAttemptSavePeriodOk($pExamRow) === false);
    $r = bravoAttemptSubmit($db, bravoAttemptGet($db, (int)$pAttempt['id']));
    t('기간 만료 후 완비 submit 성공', !isset($r['error']));

    // ── 소유 검증 ──
    $own = bravoAttemptForMember($db, (int)$pAttempt['id'], $memberId);
    t('소유자 조회 성공', $own !== null);
    $own = bravoAttemptForMember($db, (int)$pAttempt['id'], $memberId2);
    t('타인 attempt 거부', $own === null);

    // ── 응시 횟수 한도 + 미확정 submitted: 재시작 차단 검증 ──
    // 미확정 submitted 가 있으면 access 게이트에서 '채점 진행 중' 차단 (횟수 소진보다 우선).
    $limitExam = bravoExamCreate($db, ['title'=>"{$tag} 한도",'bravo_level'=>1,'exam_mode'=>'always','attempt_limit'=>1,'target_type'=>'all','status'=>'open'], 99);
    bravoExamQuestionSet($db, $limitExam, [$q1]);
    $db->prepare("INSERT INTO bravo_attempts (exam_id, member_key, member_id, attempt_no, question_ids, status, submitted_at) VALUES (?,?,?,1,'[]','submitted',NOW())")
       ->execute([$limitExam, $mk, $memberId]);
    $lAcc = bravoAttemptExamAccess($db, $memberId, $limitExam);
    t('한도 소진+제출 → 채점 대기 거부', isset($lAcc['error']) && str_contains($lAcc['error'], '채점'));

    // ── cascade: 시험 삭제 → attempts/answers/파일 정리 ──
    $delDir = BRAVO_UPLOAD_ROOT . '/answers/' . (int)$pAttempt['id'];
    t('cascade 전 파일 존재', is_dir($delDir));
    bravoExamDelete($db, $pExamId);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM bravo_attempts WHERE exam_id = " . (int)$pExamId)->fetchColumn();
    t('시험 삭제 → attempts 0건', $cnt === 0);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM bravo_answers WHERE attempt_id = " . (int)$pAttempt['id'])->fetchColumn();
    t('시험 삭제 → answers 0건', $cnt === 0);
    t('시험 삭제 → 녹음 디렉토리 정리', !is_dir($delDir));

    // ── submit: 문제 전부 삭제 후 → 빈 출제 가드 거부 ──
    // bravoAttemptQuestions 는 스냅샷 question_ids ∩ 현존 bravo_questions 기준이므로
    // 문제 행 자체를 삭제해야 빈 배열로 유도됨 (exam_questions 배정 제거만으론 불충분).
    $noqExam = bravoExamCreate($db, ['title'=>"{$tag} 빈출제submit",'bravo_level'=>1,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    $tmpQ = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>1,'korean_text'=>"{$tag} tmpq",'english_text'=>'tmpq','difficulty'=>'easy','is_active'=>1], 99);
    bravoExamQuestionSet($db, $noqExam, [$tmpQ]);
    $nqAcc = bravoAttemptExamAccess($db, $memberId, $noqExam);
    $r = bravoAttemptStart($db, $nqAcc['exam'], $mrow, $mk, false);
    $nqAttempt = $r['attempt'];
    // 문제 행 자체 삭제 → bravoAttemptQuestions 가 빈 배열 반환
    bravoQuestionDelete($db, $tmpQ);
    $r = bravoAttemptSubmit($db, $nqAttempt);
    t('빈 출제 submit 거부', isset($r['error']) && !isset($r['missing']));

    // ── 업로드 검증 헬퍼 (finfo 는 실파일 기반이라 dummy 텍스트는 거부되는 것이 정상) ──
    $f = makeTmpAudio('plain-text');
    $v = bravoAnswerValidateUpload(['tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => filesize($f)]);
    t('비오디오 MIME 거부', isset($v['error']));
    @unlink($f);
    $v = bravoAnswerValidateUpload(['tmp_name' => '/nonexistent', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]);
    t('업로드 에러 거부', isset($v['error']));
    t('MIME 매핑', bravoAnswerAudioExt('audio/webm') === 'webm' && bravoAnswerAudioExt('video/webm') === 'webm'
        && bravoAnswerAudioExt('audio/mp4') === 'm4a' && bravoAnswerAudioExt('video/mp4') === 'm4a'
        && bravoAnswerAudioExt('audio/ogg') === 'ogg' && bravoAnswerAudioExt('text/plain') === null);

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

// tmp 업로드 루트 정리
if (is_dir(BRAVO_UPLOAD_ROOT)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BRAVO_UPLOAD_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname()); }
    @rmdir(BRAVO_UPLOAD_ROOT);
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
