<?php
/**
 * BRAVO released 훅 + 누적 quota 테스트. DEV DB 트랜잭션 롤백.
 * 사용: php tests/bravo_grade_policy_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
define('BRAVO_UPLOAD_ROOT', sys_get_temp_dir() . '/bravo_policy_test_' . getmypid());
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

$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TGP_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1)")
       ->execute(["{$tag}기", $tag]);
    $cohortId = (int)$db->lastInsertId();
    $mkMember = function (int $i) use ($db, $cohortId, $tag): int {
        $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
           ->execute([$cohortId, "{$tag}회원{$i}", "{$tag}닉{$i}", '0100000030' . $i, "{$tag}_uid{$i}"]);
        $mid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute(["{$tag}_uid{$i}"]);
        return $mid;
    };
    $J_MAX  = ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good','completion_ok'=>1];
    $J_ZERO = ['accuracy'=>'wrong','chunk_ok'=>0,'response_rating'=>'poor','fluency_rating'=>'poor','completion_ok'=>0];
    $gradeAttempt = function (int $memberId, int $examId, array $qids, array $judge, string $result) use ($db): array {
        $acc = bravoAttemptExamAccess($db, $memberId, $examId);
        if (isset($acc['error'])) throw new RuntimeException('access: ' . $acc['error']);
        $r = bravoAttemptStart($db, $acc['exam'], $acc['ctx']['row'], $acc['member_key'], false);
        if (isset($r['error'])) throw new RuntimeException('start: ' . $r['error']);
        $attempt = $r['attempt'];
        foreach ($qids as $q) {
            $f = tempnam(sys_get_temp_dir(), 'tgp_'); file_put_contents($f, 'audio');
            bravoAnswerStore($db, $attempt, $q, $f, 'audio/webm', 'webm', 3000, false);
        }
        bravoAttemptSubmit($db, $attempt);
        $attempt = bravoAttemptGet($db, (int)$attempt['id']);
        foreach ($qids as $q) {
            $aid = (int)$db->query("SELECT id FROM bravo_answers WHERE attempt_id=" . (int)$attempt['id'] . " AND question_id=" . (int)$q)->fetchColumn();
            bravoGradeSave($db, $attempt, $acc['exam'], $aid, $judge, 99);
        }
        $c = bravoAttemptConfirm($db, $attempt, $acc['exam'], ['result' => $result], 99);
        if (isset($c['error'])) throw new RuntimeException('confirm: ' . $c['error']);
        return ['attempt' => $attempt, 'member_key' => $acc['member_key']];
    };
    $mkExam = function (int $level, string $suffix) use ($db, $tag): array {
        $id = bravoExamCreate($db, ['title'=>"{$tag} {$suffix}",'bravo_level'=>$level,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
        $q = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>$level,'korean_text'=>"{$tag} q{$suffix}",'english_text'=>'a','accepted_answers'=>'','target_chunks'=>'c','difficulty'=>'easy','is_active'=>1], 99);
        bravoExamQuestionSet($db, $id, [$q]);
        return [$id, [$q]];
    };

    // ── released 훅: pass 승급 / fail 제외 ──
    [$examA, $qA] = $mkExam(1, 'A');
    $m1 = $mkMember(1); $m2 = $mkMember(2);
    $r1 = $gradeAttempt($m1, $examA, $qA, $J_MAX, 'pass');
    $r2 = $gradeAttempt($m2, $examA, $qA, $J_ZERO, 'fail');
    $key1 = $r1['member_key']; $key2 = $r2['member_key'];
    t('released 전: 등급 미변동', bravoGradeCurrentLevel($db, $key1) === 0);

    // bravoExamUpdate 경유 released 전환 (관리자 저장 경로와 동일)
    $examRow = $db->query("SELECT * FROM bravo_exams WHERE id=" . $examA)->fetch(PDO::FETCH_ASSOC);
    $upd = array_merge($examRow, ['status' => 'released']);
    bravoExamUpdate($db, $examA, $upd);
    t('released 훅: pass 승급', bravoGradeCurrentLevel($db, $key1) === 1);
    t('released 훅: fail 제외', bravoGradeCurrentLevel($db, $key2) === 0);
    $passLog = $db->prepare("SELECT * FROM bravo_grade_log WHERE member_key = ? AND source = 'exam_pass'");
    $passLog->execute([$key1]);
    $pl = $passLog->fetch(PDO::FETCH_ASSOC);
    t('exam_pass 로그 (ref=exam_id)', $pl && (int)$pl['ref_id'] === $examA);

    // 재전환 멱등 (released → closed → released)
    bravoExamUpdate($db, $examA, array_merge($upd, ['status' => 'closed']));
    $logsBefore = (int)$db->query("SELECT COUNT(*) FROM bravo_grade_log WHERE source='exam_pass'")->fetchColumn();
    bravoExamUpdate($db, $examA, $upd);
    $logsAfter = (int)$db->query("SELECT COUNT(*) FROM bravo_grade_log WHERE source='exam_pass'")->fetchColumn();
    t('released 재전환 멱등 (승급 로그 미증가)', $logsBefore === $logsAfter && bravoGradeCurrentLevel($db, $key1) === 1);

    // 이미 상위 등급 보유자 no-op
    bravoGradeSet($db, $key2, 3, 'admin_adjust', 99, null);
    bravoExamUpdate($db, $examA, array_merge($upd, ['status' => 'closed']));
    bravoExamUpdate($db, $examA, $upd);
    t('상위 등급 보유자 no-op', bravoGradeCurrentLevel($db, $key2) === 3);

    // 합격 승급 후 강등한 회원을 released 재전환이 재승급하면 안 됨 (외부 리뷰 Important)
    bravoGradeDemote($db, $key1); // B1 → 0 (examA=B1 합격으로 승급됐던 키)
    bravoExamUpdate($db, $examA, array_merge($upd, ['status' => 'closed']));
    bravoExamUpdate($db, $examA, $upd); // 재전환
    t('재전환이 강등자 재승급 안 함', bravoGradeCurrentLevel($db, $key1) === 0);

    // ── 누적 quota ──
    $m3 = $mkMember(3);
    $key3 = "{$tag}_uid3";
    $q0 = bravoAttemptQuotaForLevel($db, $key3, 1);
    t('quota 초기 (0/3)', $q0['used'] === 0 && $q0['limit'] === 3 && $q0['left'] === 3);
    // 서로 다른 B1 시험 2개 합산
    [$examB, $qB] = $mkExam(1, 'B');
    [$examC, $qC] = $mkExam(1, 'C');
    $gradeAttempt($m3, $examB, $qB, $J_ZERO, 'fail');
    $gradeAttempt($m3, $examC, $qC, $J_ZERO, 'fail');
    $q2 = bravoAttemptQuotaForLevel($db, $key3, 1);
    t('quota 두 시험 합산 (2/3)', $q2['used'] === 2 && $q2['left'] === 1);
    // extra 부여 시 한도 증가 (Task 2 시점에는 attempt 흐름이 bravo_member_grades 행을 생성 안 하므로 UPSERT)
    $db->prepare("INSERT INTO bravo_member_grades (member_key, extra_attempts_1) VALUES (?, 2) ON DUPLICATE KEY UPDATE extra_attempts_1 = 2")->execute([$key3]);
    $q3 = bravoAttemptQuotaForLevel($db, $key3, 1);
    t('extra 부여 (limit 5)', $q3['limit'] === 5 && $q3['left'] === 3);
    // 다른 등급은 독립
    $qLv2 = bravoAttemptQuotaForLevel($db, $key3, 2);
    t('등급별 독립 (B2 0/3)', $qLv2['used'] === 0 && $qLv2['limit'] === 3);

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

if (is_dir(BRAVO_UPLOAD_ROOT)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BRAVO_UPLOAD_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname()); }
    @rmdir(BRAVO_UPLOAD_ROOT);
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
