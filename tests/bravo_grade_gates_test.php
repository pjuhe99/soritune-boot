<?php
/**
 * BRAVO 응시 게이트 테스트 — 보유 차단·이전등급 요건·누적 quota·같은시험 재응시·동시 시작 race·phone-only.
 * DEV DB 트랜잭션 롤백. 사용: php tests/bravo_grade_gates_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
define('BRAVO_UPLOAD_ROOT', sys_get_temp_dir() . '/bravo_gates_test_' . getmypid());
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
    $tag = 'TGG_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1)")
       ->execute(["{$tag}기", $tag]);
    $cohortId = (int)$db->lastInsertId();
    // m1: user_id 회원 / mp: phone-only 회원
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
       ->execute([$cohortId, "{$tag}회원1", "{$tag}닉1", '01000000401', "{$tag}_uid1"]);
    $m1 = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute(["{$tag}_uid1"]);
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,NULL,1)")
       ->execute([$cohortId, "{$tag}폰온리", "{$tag}닉P", '01000000402']);
    $mp = (int)$db->lastInsertId();
    // phone-only 회원 자격: member_history_stats phone 행 (completed=10 → 전 레벨 eligible)
    $db->prepare("INSERT INTO member_history_stats (phone, completed_bootcamp_count, last_calculated_at) VALUES (?, 10, NOW())
                  ON DUPLICATE KEY UPDATE completed_bootcamp_count = 10")->execute(['01000000402']);
    $key1 = "{$tag}_uid1"; $keyP = 'p:01000000402';

    $mkExam = function (int $level, string $suffix, int $limit = 3) use ($db, $tag): array {
        $id = bravoExamCreate($db, ['title'=>"{$tag} {$suffix}",'bravo_level'=>$level,'exam_mode'=>'always','attempt_limit'=>$limit,'target_type'=>'all','status'=>'open'], 99);
        $q = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>$level,'korean_text'=>"{$tag} q{$suffix}",'english_text'=>'a','accepted_answers'=>'','target_chunks'=>'c','difficulty'=>'easy','is_active'=>1], 99);
        bravoExamQuestionSet($db, $id, [$q]);
        return [$id, [$q]];
    };
    $J_MAX  = ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good','completion_ok'=>1];
    $J_ZERO = ['accuracy'=>'wrong','chunk_ok'=>0,'response_rating'=>'poor','fluency_rating'=>'poor','completion_ok'=>0];
    // submit 까지 (확정은 호출부가)
    $submitAttempt = function (int $memberId, int $examId, array $qids) use ($db): array {
        $acc = bravoAttemptExamAccess($db, $memberId, $examId);
        if (isset($acc['error'])) return $acc;
        $r = bravoAttemptStart($db, $acc['exam'], $acc['ctx']['row'], $acc['member_key'], false);
        if (isset($r['error'])) return $r;
        $attempt = $r['attempt'];
        foreach ($qids as $q) {
            $f = tempnam(sys_get_temp_dir(), 'tgg_'); file_put_contents($f, 'audio');
            bravoAnswerStore($db, $attempt, $q, $f, 'audio/webm', 'webm', 3000, false);
        }
        bravoAttemptSubmit($db, $attempt);
        return ['attempt' => bravoAttemptGet($db, (int)$attempt['id']), 'member_key' => $acc['member_key'], 'exam' => $acc['exam']];
    };
    $confirmAs = function (array $sub, array $judge, string $result) use ($db): void {
        $attempt = $sub['attempt'];
        foreach (json_decode((string)$attempt['question_ids'], true) as $q) {
            $aid = (int)$db->query("SELECT id FROM bravo_answers WHERE attempt_id=" . (int)$attempt['id'] . " AND question_id=" . (int)$q)->fetchColumn();
            bravoGradeSave($db, $attempt, $sub['exam'], $aid, $judge, 99);
        }
        $c = bravoAttemptConfirm($db, $attempt, $sub['exam'], ['result' => $result], 99);
        if (isset($c['error'])) throw new RuntimeException('confirm: ' . $c['error']);
    };

    // ── ① 보유 차단 + 강등 해제 ──
    [$examA, $qA] = $mkExam(1, 'A');
    bravoGradeSet($db, $key1, 1, 'admin_adjust', 99, null); // B1 보유자
    $acc = bravoAttemptExamAccess($db, $m1, $examA);
    t('보유 차단 (403, 강등 안내)', isset($acc['error']) && ($acc['code'] ?? 0) === 403 && str_contains($acc['error'], '강등'), json_encode($acc));
    bravoGradeDemote($db, $key1); // B1 → 0
    $acc = bravoAttemptExamAccess($db, $m1, $examA);
    t('강등 후 차단 해제', !isset($acc['error']), $acc['error'] ?? '');

    // ── ② 이전등급 요건 ──
    [$examB2, $qB2] = $mkExam(2, 'B2');
    $acc = bravoAttemptExamAccess($db, $m1, $examB2);
    t('B2: B1 미보유 거부', isset($acc['error']) && str_contains($acc['error'], 'BRAVO 1'), json_encode($acc));
    bravoGradeSet($db, $key1, 1, 'admin_adjust', 99, null); // grandfather/관리자 부여도 인정
    $acc = bravoAttemptExamAccess($db, $m1, $examB2);
    t('B2: B1 보유 시 통과', !isset($acc['error']), $acc['error'] ?? '');
    // B1 은 요건 면제 (위 ① 에서 무등급 접근 이미 검증됨 — requires_previous_level=0)

    // ── ③ 누적 quota: 서로 다른 같은-등급 시험 합산 ──
    // m1 은 B1 보유 상태 → B1 시험은 보유 차단. quota 는 phone-only 회원으로 검증.
    [$examC, $qC] = $mkExam(1, 'C');
    [$examD, $qD] = $mkExam(1, 'D');
    $s1 = $submitAttempt($mp, $examC, $qC);
    t('phone-only 응시 시작/제출', isset($s1['attempt']), json_encode($s1));
    $confirmAs($s1, $J_ZERO, 'fail');
    $s2 = $submitAttempt($mp, $examD, $qD);
    $confirmAs($s2, $J_ZERO, 'fail');
    // 같은 시험 재응시 (확정 후 — 새 attempt_no)
    $s3 = $submitAttempt($mp, $examC, $qC);
    t('같은 시험 재응시 (확정 후, attempt_no 2)', isset($s3['attempt']) && (int)$s3['attempt']['attempt_no'] === 2, json_encode($s3['attempt'] ?? $s3));
    // 미확정 submitted 상태에선 같은 시험 재시작 차단
    $s4 = $submitAttempt($mp, $examC, $qC);
    t('미확정 submitted 재시작 차단', isset($s4['error']) && str_contains($s4['error'], '채점'), json_encode($s4));
    // 누적 3회 소진 → 다른 같은-등급 시험도 차단 (access 에서)
    $acc = bravoAttemptExamAccess($db, $mp, $examD);
    t('누적 3회 소진 차단 (시험 D 1회 + C 2회)', isset($acc['error']) && str_contains($acc['error'], '횟수'), json_encode($acc));
    // extra 부여 → 통과
    $db->prepare("UPDATE bravo_member_grades SET extra_attempts_1 = 1 WHERE member_key = ?")->execute([$keyP]);
    $acc = bravoAttemptExamAccess($db, $mp, $examD);
    t('extra 부여 후 통과', !isset($acc['error']), $acc['error'] ?? '');

    // ── ④ 동시 시작 race: 서로 다른 같은-등급 시험 2개 — mutex 직렬화 ──
    // testHook 으로 INSERT 직전에 같은 사람의 다른 시험 attempt 를 끼워넣어 quota 초과를 재현 시도.
    // mutex(bravoGradeLockRow)가 같은 트랜잭션 안이라 직렬화됨 — quota 재검사로 한쪽이 거부되어야 함.
    $db->prepare("UPDATE bravo_member_grades SET extra_attempts_1 = 0 WHERE member_key = ?")->execute([$keyP]);
    // 현재 keyP 의 B1 누적 = 3 (C2 + D1) → 이미 소진. extra 1 부여해 잔여 1 로 만들고 race 검증.
    $db->prepare("UPDATE bravo_member_grades SET extra_attempts_1 = 1 WHERE member_key = ?")->execute([$keyP]);
    $hooked = false;
    $accD = bravoAttemptExamAccess($db, $mp, $examD);
    $hook = function () use ($db, &$hooked, $examC, $keyP, $mp, $qC) {
        if ($hooked) return;
        $hooked = true;
        // 같은 트랜잭션(동일 커넥션) 안 — mutex 잠금 이후 시점이라 이 INSERT 는 직렬화 안쪽.
        // 다른 커넥션 시뮬은 CLI 단일 커넥션 한계로 불가 — 대신 'mutex 이후 카운트가 INSERT 직전 기준' 임을 검증:
        $db->prepare("INSERT INTO bravo_attempts (exam_id, member_key, member_id, attempt_no, question_ids) VALUES (?,?,?,?,?)")
           ->execute([$examC, $keyP, $mp, 3, json_encode($qC)]);
    };
    $r = bravoAttemptStart($db, $accD['exam'], $accD['ctx']['row'], $accD['member_key'], false, $hook);
    // hook 이 잔여 1 을 선점 → start 의 quota 재검사(잠금 후 카운트)가 이를 보고 거부해야 함
    t('race: mutex 후 quota 재검사로 거부', isset($r['error']) && str_contains($r['error'], '횟수'), json_encode($r));

    // ⑤ 같은 시험 더블클릭: 락 후 재확인 — 끼어든 in_progress 를 resumed 로 반환 (이중 차감 방지)
    $db->prepare("UPDATE bravo_member_grades SET extra_attempts_1 = 5 WHERE member_key = ?")->execute([$keyP]);
    $accD2 = bravoAttemptExamAccess($db, $mp, $examD);
    t('더블클릭 셋업 access', !isset($accD2['error']), $accD2['error'] ?? '');
    $hooked2 = false;
    $hook2 = function () use ($db, &$hooked2, $examD, $keyP, $mp, $qD) {
        if ($hooked2) return;
        $hooked2 = true;
        $no = (int)$db->query("SELECT COALESCE(MAX(attempt_no),0)+1 FROM bravo_attempts WHERE exam_id={$examD} AND member_key=" . $db->quote($keyP))->fetchColumn();
        $db->prepare("INSERT INTO bravo_attempts (exam_id, member_key, member_id, attempt_no, question_ids) VALUES (?,?,?,?,?)")
           ->execute([$examD, $keyP, $mp, $no, json_encode($qD)]);
    };
    $cntBefore = (int)$db->query("SELECT COUNT(*) FROM bravo_attempts WHERE exam_id={$examD} AND member_key=" . $db->quote($keyP))->fetchColumn();
    $r5 = bravoAttemptStart($db, $accD2['exam'], $accD2['ctx']['row'], $accD2['member_key'], false, $hook2);
    $cntAfter = (int)$db->query("SELECT COUNT(*) FROM bravo_attempts WHERE exam_id={$examD} AND member_key=" . $db->quote($keyP))->fetchColumn();
    t('더블클릭: 끼어든 attempt 를 resumed 반환 (신규 INSERT 0)', !isset($r5['error']) && !empty($r5['resumed']) && $cntAfter === $cntBefore + 1, json_encode(['r'=>$r5['resumed'] ?? null, 'before'=>$cntBefore, 'after'=>$cntAfter]));

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
