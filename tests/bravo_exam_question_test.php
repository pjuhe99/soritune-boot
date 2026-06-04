<?php
/**
 * BRAVO 시험-문제 배정 서비스 테스트. DEV DB 통합(트랜잭션 롤백).
 * 사용: php tests/bravo_exam_question_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/bravo.php';
require_once __DIR__ . '/../public_html/api/services/bravo_questions.php';
require_once __DIR__ . '/../public_html/api/services/bravo_exam_questions.php';

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
    $tag = 'TEQ_' . bin2hex(random_bytes(3));
    $examId = bravoExamCreate($db, [
        'title'=>"{$tag} 시험",'bravo_level'=>1,'exam_mode'=>'always',
        'attempt_limit'=>3,'target_type'=>'all','status'=>'preparing',
    ], 99);
    $q1 = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>1,'korean_text'=>"{$tag} q1",'english_text'=>'q1','difficulty'=>'easy','is_active'=>1], 99);
    $q2 = bravoQuestionCreate($db, ['question_type'=>2,'bravo_level'=>1,'korean_text'=>"{$tag} q2",'english_text'=>'q2','difficulty'=>'normal','is_active'=>1], 99);
    $q3 = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>1,'korean_text'=>"{$tag} q3",'english_text'=>'q3','difficulty'=>'hard','is_active'=>1], 99);
    t('셋업 id 정상', $examId>0 && $q1>0 && $q2>0 && $q3>0);

    // 배정 없음
    t('초기 배정 없음', bravoExamQuestionAssignedIds($db, $examId) === []);

    // set [q1, q3] — 순서 보존
    bravoExamQuestionSet($db, $examId, [$q1, $q3]);
    t('set 후 assignedIds 순서', bravoExamQuestionAssignedIds($db, $examId) === [$q1, $q3], json_encode(bravoExamQuestionAssignedIds($db, $examId)));
    $list = bravoExamQuestionList($db, $examId);
    t('list 2건 + 조인내용', count($list)===2 && $list[0]['english_text']==='q1' && (int)$list[0]['display_order']===0 && $list[1]['english_text']==='q3' && (int)$list[1]['display_order']===1);

    // re-set [q2, q1, q3] — 멱등 교체, 중복 없음, display_order 갱신
    bravoExamQuestionSet($db, $examId, [$q2, $q1, $q3]);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM bravo_exam_questions WHERE exam_id=".(int)$examId)->fetchColumn();
    t('re-set 3건(중복 없음)', $cnt === 3, 'cnt='.$cnt);
    t('re-set 순서 [q2,q1,q3]', bravoExamQuestionAssignedIds($db, $examId) === [$q2, $q1, $q3]);

    // 미존재 id 필터 (존재하지 않는 id 는 set 에서 무시)
    $ghost = 99999999;
    bravoExamQuestionSet($db, $examId, [$q1, $ghost, $q3]);
    t('미존재 id 필터', bravoExamQuestionAssignedIds($db, $examId) === [$q1, $q3], json_encode(bravoExamQuestionAssignedIds($db, $examId)));

    // 빈 set → 전체 비움
    bravoExamQuestionSet($db, $examId, []);
    t('빈 set → 0건', bravoExamQuestionAssignedIds($db, $examId) === []);

    // 중복 입력 → 1건으로
    bravoExamQuestionSet($db, $examId, [$q1, $q1, $q2]);
    t('중복 입력 dedup', bravoExamQuestionAssignedIds($db, $examId) === [$q1, $q2]);

    // ── cascade: 시험 삭제 시 junction 제거 ──
    bravoExamQuestionSet($db, $examId, [$q1, $q2, $q3]);
    t('cascade 전 3건', count(bravoExamQuestionAssignedIds($db, $examId)) === 3);
    bravoExamDelete($db, $examId);
    t('시험 삭제 → junction 0건', (int)$db->query("SELECT COUNT(*) FROM bravo_exam_questions WHERE exam_id=".(int)$examId)->fetchColumn() === 0);

    // ── cascade: 문제 삭제 시 모든 배정에서 제거 ──
    $examId2 = bravoExamCreate($db, ['title'=>"{$tag} 시험2",'bravo_level'=>1,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'preparing'], 99);
    bravoExamQuestionSet($db, $examId2, [$q1, $q2]);
    bravoQuestionDelete($db, $q1);
    t('문제 삭제 → 해당 배정 제거', bravoExamQuestionAssignedIds($db, $examId2) === [$q2], json_encode(bravoExamQuestionAssignedIds($db, $examId2)));

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage());
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
