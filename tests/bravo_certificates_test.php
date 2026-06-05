<?php
/**
 * BRAVO 인증서 서비스 테스트 (발급·렌더). DEV DB 통합(트랜잭션 롤백).
 * 사용: php tests/bravo_certificates_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
define('BRAVO_UPLOAD_ROOT', sys_get_temp_dir() . '/bravo_cert_test_' . getmypid());
require_once __DIR__ . '/../public_html/api/services/bravo.php';
require_once __DIR__ . '/../public_html/api/services/bravo_questions.php';
require_once __DIR__ . '/../public_html/api/services/bravo_exam_questions.php';
require_once __DIR__ . '/../public_html/api/services/bravo_attempts.php';
require_once __DIR__ . '/../public_html/api/services/bravo_grading.php';
require_once __DIR__ . '/../public_html/api/services/bravo_certificates.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// ── 순수 함수 (DB 불필요) ──
t('cert_no 형식', bravoCertificateCertNo(2, '2026-06-12', 1) === 'BRAVO2-20260612-0001');
t('cert_no seq 패딩', bravoCertificateCertNo(3, '2026-06-12', 42) === 'BRAVO3-20260612-0042');

// 발급조건 가드
$gPass = ['result' => 'pass'];
$gFail = ['result' => 'fail'];
t('eligible: released+pass 통과', bravoCertificateEligible(['status' => 'released'], $gPass) === null);
t('eligible: 미released 거부', (bravoCertificateEligible(['status' => 'open'], $gPass)['code'] ?? 0) === 403);
t('eligible: closed 거부', (bravoCertificateEligible(['status' => 'closed'], $gPass)['code'] ?? 0) === 403);
t('eligible: 불합격 거부', (bravoCertificateEligible(['status' => 'released'], $gFail)['code'] ?? 0) === 403);
t('eligible: 미확정 거부', (bravoCertificateEligible(['status' => 'released'], null)['code'] ?? 0) === 403);

// ── 렌더 (DB 불필요 — cert 배열만) ──
t('폰트 파일 존재 (Bold)', bravoCertFontPath('Bold') !== null);
t('폰트 파일 존재 (Regular)', bravoCertFontPath('Regular') !== null);
$cert = ['cert_no' => 'BRAVO2-20260612-0001', 'member_name' => '홍길동', 'bravo_level' => 2, 'passed_on' => '2026-06-12'];
$r = bravoCertificateRender($cert, true); // forcePng
t('렌더 PNG 폴백 시그니처', substr($r['bytes'], 0, 4) === "\x89PNG" && $r['mime'] === 'image/png' && $r['ext'] === 'png');
if (class_exists('Imagick')) {
    $r = bravoCertificateRender($cert);
    t('렌더 PDF 시그니처 (Imagick)', substr($r['bytes'], 0, 4) === '%PDF' && $r['mime'] === 'application/pdf' && $r['ext'] === 'pdf');
} else {
    t('렌더 PDF (Imagick 미가용 — PNG 확인)', bravoCertificateRender($cert)['ext'] === 'png');
}

// ── DB 통합 ──
$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TCT_' . bin2hex(random_bytes(3));

    // 셋업 헬퍼: 기수 1 + 회원 N (자격 override 10)
    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1)")
       ->execute(["{$tag}기", $tag]);
    $cohortId = (int)$db->lastInsertId();
    $mkMember = function (int $i) use ($db, $cohortId, $tag): int {
        $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
           ->execute([$cohortId, "{$tag}회원{$i}", "{$tag}닉{$i}", '0100000010' . $i, "{$tag}_uid{$i}"]);
        $mid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute(["{$tag}_uid{$i}"]);
        return $mid;
    };
    // 응시→제출→전문항 만점 판정→확정(pass) 헬퍼
    $J_MAX = ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good','completion_ok'=>1];
    $passAttempt = function (int $memberId, int $examId, array $qids) use ($db, $J_MAX): array {
        $acc = bravoAttemptExamAccess($db, $memberId, $examId);
        $r = bravoAttemptStart($db, $acc['exam'], $acc['ctx']['row'], $acc['member_key'], false);
        $attempt = $r['attempt'];
        foreach ($qids as $q) {
            $f = tempnam(sys_get_temp_dir(), 'tct_'); file_put_contents($f, 'audio');
            bravoAnswerStore($db, $attempt, $q, $f, 'audio/webm', 'webm', 3000, false);
        }
        bravoAttemptSubmit($db, $attempt);
        $attempt = bravoAttemptGet($db, (int)$attempt['id']);
        foreach ($qids as $q) {
            $aid = (int)$db->query("SELECT id FROM bravo_answers WHERE attempt_id=" . (int)$attempt['id'] . " AND question_id=" . (int)$q)->fetchColumn();
            bravoGradeSave($db, $attempt, $acc['exam'], $aid, $J_MAX, 99);
        }
        bravoAttemptConfirm($db, $attempt, $acc['exam'], ['result' => 'pass'], 99);
        return $attempt;
    };

    // B2 시험(always, open) + 문제 2개 + 배정
    $examId = bravoExamCreate($db, ['title'=>"{$tag} B2",'bravo_level'=>2,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    $qids = [];
    foreach ([1,2] as $i) {
        $qids[] = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>2,'korean_text'=>"{$tag} q{$i}",'english_text'=>"a {$i}",'accepted_answers'=>'','target_chunks'=>"c {$i}",'difficulty'=>'easy','is_active'=>1], 99);
    }
    bravoExamQuestionSet($db, $examId, $qids);

    $m1 = $mkMember(1); $m3 = $mkMember(3); $m4 = $mkMember(4);
    $at1 = $passAttempt($m1, $examId, $qids);
    $at3 = $passAttempt($m3, $examId, $qids);
    $at4 = $passAttempt($m4, $examId, $qids);
    $db->prepare("UPDATE bravo_exams SET status='released' WHERE id=?")->execute([$examId]);
    $exam = $db->query("SELECT * FROM bravo_exams WHERE id=" . $examId)->fetch(PDO::FETCH_ASSOC);
    t('셋업 정상', $exam['status'] === 'released');

    // 발급: always 모드 → result_release_at NULL → passed_on = 오늘
    $today = date('Y-m-d');
    $c1 = bravoCertificateIssue($db, $at1, $exam, "{$tag}회원1");
    t('발급 형식 (seq 0001, passed_on 오늘)', $c1['cert_no'] === 'BRAVO2-' . date('Ymd') . '-0001' && $c1['passed_on'] === $today, json_encode($c1));
    t('member_name 스냅샷', $c1['member_name'] === "{$tag}회원1");

    // 재호출 = 동일 행 (번호·이름 불변 — 개명 시뮬레이션: 다른 이름 전달해도 기존 행)
    $c1b = bravoCertificateIssue($db, $at1, $exam, '개명된이름');
    t('재호출 동일 행', (int)$c1b['id'] === (int)$c1['id'] && $c1b['cert_no'] === $c1['cert_no'] && $c1b['member_name'] === "{$tag}회원1");

    // 같은 (level, date) seq 증가
    $c3 = bravoCertificateIssue($db, $at3, $exam, "{$tag}회원3");
    t('seq 증가 0002', $c3['cert_no'] === 'BRAVO2-' . date('Ymd') . '-0002');

    // 23000 (uk_bc_cert_no) 충돌 → seq 재계산 1회 재시도
    $hooked = false;
    $hook = function () use ($db, &$hooked, $today) {
        if ($hooked) return;
        $hooked = true;
        $cnt = (int)$db->query("SELECT COUNT(*) FROM bravo_certificates WHERE bravo_level=2 AND passed_on='{$today}'")->fetchColumn();
        $no = sprintf('BRAVO2-%s-%04d', date('Ymd'), $cnt + 1);
        $db->prepare("INSERT INTO bravo_certificates (attempt_id, cert_no, member_name, bravo_level, passed_on) VALUES (?,?,?,?,?)")
           ->execute([99999998, $no, '경쟁자', 2, $today]);
    };
    $c4 = bravoCertificateIssue($db, $at4, $exam, "{$tag}회원4", $hook);
    t('23000 재시도 채번 (0003 선점 → 0004)', $c4['cert_no'] === 'BRAVO2-' . date('Ymd') . '-0004', $c4['cert_no'] ?? 'null');

    // passed_on = result_release_at 날짜 (period 모드, B3)
    $relAt = date('Y-m-d H:i:s', strtotime('+2 hours'));
    $examP = bravoExamCreate($db, ['title'=>"{$tag} B3",'bravo_level'=>3,'exam_mode'=>'period',
        'start_at'=>date('Y-m-d H:i:s', strtotime('-1 hour')), 'end_at'=>date('Y-m-d H:i:s', strtotime('+1 hour')),
        'result_release_at'=>$relAt, 'attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    $q3 = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>3,'korean_text'=>"{$tag} q3",'english_text'=>'a3','accepted_answers'=>'','target_chunks'=>'c3','difficulty'=>'easy','is_active'=>1], 99);
    bravoExamQuestionSet($db, $examP, [$q3]);
    $atP = $passAttempt($m1, $examP, [$q3]);
    $db->prepare("UPDATE bravo_exams SET status='released' WHERE id=?")->execute([$examP]);
    $examPRow = $db->query("SELECT * FROM bravo_exams WHERE id=" . $examP)->fetch(PDO::FETCH_ASSOC);
    $cP = bravoCertificateIssue($db, $atP, $examPRow, "{$tag}회원1");
    $relDate = date('Y-m-d', strtotime($relAt));
    t('passed_on = 발표일 + 레벨별 seq 독립', $cP['passed_on'] === $relDate && $cP['cert_no'] === 'BRAVO3-' . date('Ymd', strtotime($relAt)) . '-0001', json_encode($cP));

    // cascade 제외: 시험 삭제해도 인증서 영구 보존
    bravoExamDelete($db, $examP);
    $kept = (int)$db->query("SELECT COUNT(*) FROM bravo_certificates WHERE id=" . (int)$cP['id'])->fetchColumn();
    t('시험 삭제 후 인증서 보존', $kept === 1);

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
