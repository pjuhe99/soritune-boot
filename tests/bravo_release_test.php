<?php
/**
 * BRAVO 결과 공개·합격 차단 테스트. DEV DB 통합(트랜잭션 롤백).
 * 사용: php tests/bravo_release_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
define('BRAVO_UPLOAD_ROOT', sys_get_temp_dir() . '/bravo_release_test_' . getmypid());
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

$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TRL_' . bin2hex(random_bytes(3));

    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1)")
       ->execute(["{$tag}기", $tag]);
    $cohortId = (int)$db->lastInsertId();
    $mkMember = function (int $i) use ($db, $cohortId, $tag): int {
        $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
           ->execute([$cohortId, "{$tag}회원{$i}", "{$tag}닉{$i}", '0100000020' . $i, "{$tag}_uid{$i}"]);
        $mid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute(["{$tag}_uid{$i}"]);
        return $mid;
    };
    $J_MAX  = ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good','completion_ok'=>1];
    $J_ZERO = ['accuracy'=>'wrong','chunk_ok'=>0,'response_rating'=>'poor','fluency_rating'=>'poor','completion_ok'=>0];
    // 응시→제출→판정→확정 헬퍼 ($judge 만점/0점으로 합불 제어)
    $gradeAttempt = function (int $memberId, int $examId, array $qids, array $judge, string $result) use ($db): array {
        $acc = bravoAttemptExamAccess($db, $memberId, $examId);
        if (isset($acc['error'])) throw new RuntimeException('access: ' . $acc['error']);
        $r = bravoAttemptStart($db, $acc['exam'], $acc['ctx']['row'], $acc['member_key'], false);
        $attempt = $r['attempt'];
        foreach ($qids as $q) {
            $f = tempnam(sys_get_temp_dir(), 'trl_'); file_put_contents($f, 'audio');
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

    // B2 시험 examA (always, open) + 문제 2개. m1=pass(만점 100), m2=fail(0점)
    $examA = bravoExamCreate($db, ['title'=>"{$tag} A",'bravo_level'=>2,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    $qids = [];
    foreach ([1,2] as $i) {
        $qids[] = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>2,'korean_text'=>"{$tag} q{$i}",'english_text'=>"a {$i}",'accepted_answers'=>'','target_chunks'=>"c {$i}",'difficulty'=>'easy','is_active'=>1], 99);
    }
    bravoExamQuestionSet($db, $examA, $qids);
    $m1 = $mkMember(1); $m2 = $mkMember(2);
    // B2 이전등급 요건(B1) 충족 — admin_adjust 부여
    bravoGradeSet($db, "{$tag}_uid1", 1, 'admin_adjust', 99, null);
    bravoGradeSet($db, "{$tag}_uid2", 1, 'admin_adjust', 99, null);
    $r1 = $gradeAttempt($m1, $examA, $qids, $J_MAX, 'pass');
    $r2 = $gradeAttempt($m2, $examA, $qids, $J_ZERO, 'fail');
    $key1 = $r1['member_key']; $key2 = $r2['member_key'];
    $examARow = $db->query("SELECT * FROM bravo_exams WHERE id=" . $examA)->fetch(PDO::FETCH_ASSOC);

    // ── 발표 전 비공개 회귀 단언 (확정 존재해도 result 키 부재) ──
    $st = bravoStatusAttempts($db, $examARow, $key1);
    t('미released: result 키 부재', !array_key_exists('result', $st), json_encode($st));
    t('미released: 기존 필드 유지', $st['used'] === 1 && $st['submitted'] === true && $st['limit'] === 3);
    $ms = bravoMemberStatus($db, $m1);
    $lv2 = null; foreach ($ms['levels'] as $lv) { if ($lv['level'] === 2) $lv2 = $lv; }
    t('미released: held false', $lv2 !== null && $lv2['held'] === false); // m1 current_level=1 (admin_adjust) → 1>=2 false
    // 발표 전엔 같은 등급 다른 시험 차단도 없어야 함 (정보 누설 방지)
    $examB = bravoExamCreate($db, ['title'=>"{$tag} B",'bravo_level'=>2,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    bravoExamQuestionSet($db, $examB, $qids);
    $acc = bravoAttemptExamAccess($db, $m1, $examB);
    t('released 전 pass: 차단 안 함', !isset($acc['error']), $acc['error'] ?? '');

    // ── released 전환 (bravoExamUpdate 경유 — 훅 발동) ──
    $examARow = $db->query("SELECT * FROM bravo_exams WHERE id=" . $examA)->fetch(PDO::FETCH_ASSOC);
    bravoExamUpdate($db, $examA, array_merge($examARow, ['status' => 'released']));
    $examARow = $db->query("SELECT * FROM bravo_exams WHERE id=" . $examA)->fetch(PDO::FETCH_ASSOC);

    // 합격자 result 공개
    $st = bravoStatusAttempts($db, $examARow, $key1);
    t('released: pass result 공개', isset($st['result'])
        && $st['result']['result'] === 'pass'
        && $st['result']['total_score'] === 100.0
        && $st['result']['passing_score'] === 65.0
        && $st['result']['attempt_id'] === (int)$r1['attempt']['id']
        && $st['result']['cert_issued'] === false, json_encode($st['result'] ?? null));
    // 불합격자 result 공개
    $st2 = bravoStatusAttempts($db, $examARow, $key2);
    t('released: fail result 공개', isset($st2['result']) && $st2['result']['result'] === 'fail' && $st2['result']['total_score'] === 0.0);

    // cert_issued 반영
    bravoCertificateIssue($db, $r1['attempt'], $examARow, "{$tag}회원1");
    $st = bravoStatusAttempts($db, $examARow, $key1);
    t('cert_issued true 반영', $st['result']['cert_issued'] === true);

    // held (등급 보유 여부)
    $ms = bravoMemberStatus($db, $m1);
    $lv2 = null; foreach ($ms['levels'] as $lv) { if ($lv['level'] === 2) $lv2 = $lv; }
    t('released: 합격자 held true', $lv2 !== null && $lv2['held'] === true);
    $ms2 = bravoMemberStatus($db, $m2);
    $lv2b = null; foreach ($ms2['levels'] as $lv) { if ($lv['level'] === 2) $lv2b = $lv; }
    t('released: 불합격자 held false', $lv2b !== null && $lv2b['held'] === false);

    // ── 합격자 등급 차단 ──
    $acc = bravoAttemptExamAccess($db, $m1, $examB);
    t('합격자 같은 등급 차단 (403)', isset($acc['error']) && ($acc['code'] ?? 0) === 403 && str_contains($acc['error'], '보유'), json_encode($acc));
    // 불합격자는 같은 등급 다음 시험 허용 — 단 B2 이전등급(B1) 요건 충족 위해 B1 부여 필요
    bravoGradeSet($db, $key2, 1, 'admin_adjust', 99, null);
    $acc = bravoAttemptExamAccess($db, $m2, $examB);
    t('불합격자 통과', !isset($acc['error']), $acc['error'] ?? '');
    // m1 은 B2 보유 → B1 시험은 보유 차단(강등 안내) — 신규 게이트 정책
    $examC = bravoExamCreate($db, ['title'=>"{$tag} C",'bravo_level'=>1,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    $acc = bravoAttemptExamAccess($db, $m1, $examC);
    t('B2 보유자는 B1 차단(강등 필요)', isset($acc['error']) && str_contains($acc['error'], '강등'), $acc['error'] ?? '');

    // 등급 진실원 기준 직접 검증 (bravoHasReleasedPass 대체 — released 훅으로 승급된 등급)
    t('released 후 등급 취득 (m1 B2)', bravoGradeCurrentLevel($db, $key1) === 2);
    t('불합격자 등급 미상승 — admin 부여 B1 유지 (m2)', bravoGradeCurrentLevel($db, $key2) === 1);

    // ── released 인데 확정 없음 (채점 누락) → result 키 부재 (대기 유지) ──
    $m5 = $mkMember(5);
    bravoGradeSet($db, "{$tag}_uid5", 1, 'admin_adjust', 99, null); // B2 이전등급 요건
    $acc5 = bravoAttemptExamAccess($db, $m5, $examB);
    $r5 = bravoAttemptStart($db, $acc5['exam'], $acc5['ctx']['row'], $acc5['member_key'], false);
    $at5 = $r5['attempt'];
    foreach ($qids as $q) {
        $f = tempnam(sys_get_temp_dir(), 'trl_'); file_put_contents($f, 'audio');
        bravoAnswerStore($db, $at5, $q, $f, 'audio/webm', 'webm', 3000, false);
    }
    bravoAttemptSubmit($db, bravoAttemptGet($db, (int)$at5['id']));
    $examBRow = $db->query("SELECT * FROM bravo_exams WHERE id=" . $examB)->fetch(PDO::FETCH_ASSOC);
    bravoExamUpdate($db, $examB, array_merge($examBRow, ['status' => 'released']));
    $examBRow = $db->query("SELECT * FROM bravo_exams WHERE id=" . $examB)->fetch(PDO::FETCH_ASSOC);
    $st5 = bravoStatusAttempts($db, $examBRow, $acc5['member_key']);
    t('released+미확정: result 키 부재 (대기 유지)', !array_key_exists('result', $st5) && $st5['submitted'] === true);

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
