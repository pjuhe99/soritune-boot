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

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
