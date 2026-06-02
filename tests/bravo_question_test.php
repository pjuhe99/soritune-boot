<?php
/**
 * BRAVO 문제은행 서비스 테스트. 순수함수 + DEV DB 통합(트랜잭션 롤백).
 * 사용: php tests/bravo_question_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/bravo_questions.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// ── 순수: bravoQuestionValidate ──
$valid = ['question_type'=>1,'bravo_level'=>1,'korean_text'=>'안녕','english_text'=>'hi','difficulty'=>'easy'];
t('유효 입력 통과', bravoQuestionValidate($valid) === []);
t('type 범위 밖', in_array('문제 유형은 1/2/3 중 하나여야 합니다.', bravoQuestionValidate(['question_type'=>4]+$valid), true));
t('level 범위 밖', in_array('BRAVO 등급은 1/2/3 중 하나여야 합니다.', bravoQuestionValidate(['bravo_level'=>9]+$valid), true));
t('korean 빈값', in_array('한국어 문장을 입력해주세요.', bravoQuestionValidate(['korean_text'=>'  ']+$valid), true));
t('english 빈값', in_array('기준 영어 문장을 입력해주세요.', bravoQuestionValidate(['english_text'=>'']+$valid), true));
t('difficulty 무효', in_array('난이도가 올바르지 않습니다.', bravoQuestionValidate(['difficulty'=>'x']+$valid), true));
t('reference_speech_sec 음수', count(bravoQuestionValidate(['reference_speech_sec'=>-1]+$valid)) === 1);
t('reference_speech_sec 빈값 허용', bravoQuestionValidate(['reference_speech_sec'=>'']+$valid) === []);

// ── 순수: bravoQuestionPersistData ──
$p = bravoQuestionPersistData([
    'question_type'=>'2','bravo_level'=>'3','source'=>'  줌특강 PPT ','korean_text'=>'  한 ','english_text'=>' en ',
    'target_chunks'=>'','accepted_answers'=>" a\nb ",'reference_speech_sec'=>'4.5','response_time_limit_sec'=>'',
    'difficulty'=>'hard','is_active'=>'1',
]);
t('persist type 캐스팅', $p['question_type'] === 2);
t('persist source trim', $p['source'] === '줌특강 PPT');
t('persist korean trim', $p['korean_text'] === '한');
t('persist 빈 target_chunks → null', $p['target_chunks'] === null);
t('persist accepted_answers trim 보존', $p['accepted_answers'] === "a\nb");
t('persist ref_sec 숫자', $p['reference_speech_sec'] === 4.5);
t('persist 빈 resp_sec → null', $p['response_time_limit_sec'] === null);
t('persist difficulty', $p['difficulty'] === 'hard');
t('persist is_active 1', $p['is_active'] === 1);
$p2 = bravoQuestionPersistData(['difficulty'=>'bogus']);
t('persist difficulty 기본 normal', $p2['difficulty'] === 'normal');
t('persist is_active 미전달 → 0', $p2['is_active'] === 0);

// ── 순수: 경계값 커버리지 ──
t('difficulties 집합', bravoQuestionDifficulties() === ['easy','normal','hard']);
t('persist is_active 문자열 0 → 0', bravoQuestionPersistData(['is_active'=>'0'])['is_active'] === 0);
t('reference_speech_sec 0 허용', bravoQuestionValidate(['reference_speech_sec'=>0]+$valid) === []);
t('persist reference_speech_sec 0 → 0.0', bravoQuestionPersistData(['reference_speech_sec'=>'0'])['reference_speech_sec'] === 0.0);
t('difficulty 미전달 → 에러 없음', bravoQuestionValidate(['question_type'=>1,'bravo_level'=>1,'korean_text'=>'k','english_text'=>'e']) === []);

// ── 통합: CRUD (DEV DB, 트랜잭션 롤백) ──
$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TQ_' . bin2hex(random_bytes(3));
    $id1 = bravoQuestionCreate($db, [
        'question_type'=>1,'bravo_level'=>1,'source'=>'VOD','korean_text'=>"{$tag} 안녕",'english_text'=>'hello',
        'difficulty'=>'easy','is_active'=>1,'reference_speech_sec'=>'3.0',
    ], 99);
    $id2 = bravoQuestionCreate($db, [
        'question_type'=>2,'bravo_level'=>3,'korean_text'=>"{$tag} 비활성",'english_text'=>'inactive',
        'difficulty'=>'hard','is_active'=>0,
    ], 99);
    t('create id 반환', $id1 > 0 && $id2 > 0 && $id1 !== $id2);

    $kw = bravoQuestionList($db, ['keyword'=>$tag]);
    t('keyword 필터 2건', count($kw) === 2, 'count=' . count($kw));
    t('정렬 id DESC', (int)$kw[0]['id'] === $id2);

    $byLevel = bravoQuestionList($db, ['keyword'=>$tag, 'bravo_level'=>1]);
    t('level 필터 1건', count($byLevel) === 1 && (int)$byLevel[0]['id'] === $id1);

    $active = bravoQuestionList($db, ['keyword'=>$tag, 'is_active'=>0]);
    t('is_active=0 필터 1건', count($active) === 1 && (int)$active[0]['id'] === $id2);

    $byType = bravoQuestionList($db, ['keyword'=>$tag, 'question_type'=>2]);
    t('type 필터 1건', count($byType) === 1 && (int)$byType[0]['id'] === $id2);

    bravoQuestionUpdate($db, $id1, [
        'question_type'=>3,'bravo_level'=>2,'korean_text'=>"{$tag} 수정",'english_text'=>'edited',
        'difficulty'=>'normal','is_active'=>0,
    ]);
    $one = bravoQuestionList($db, ['keyword'=>$tag, 'question_type'=>3]);
    t('update 반영', count($one) === 1 && $one[0]['english_text'] === 'edited' && (int)$one[0]['bravo_level'] === 2);

    bravoQuestionDelete($db, $id1);
    bravoQuestionDelete($db, $id2);
    t('delete 후 0건', count(bravoQuestionList($db, ['keyword'=>$tag])) === 0);

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage());
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
