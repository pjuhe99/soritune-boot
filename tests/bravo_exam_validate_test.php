<?php
/**
 * BRAVO 시험 검증 순수 함수 단위 테스트. DB 불필요.
 * 사용: php tests/bravo_exam_validate_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/api/services/bravo.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

function valid(): array {
    return [
        'title' => '6월 BRAVO 1',
        'bravo_level' => 1,
        'exam_mode' => 'period',
        'start_at' => '2026-06-01 10:00:00',
        'end_at' => '2026-06-02 10:00:00',
        'result_release_at' => '2026-06-12 10:00:00',
        'attempt_limit' => 3,
        'target_type' => 'all',
        'target_cohort_id' => null,
        'status' => 'preparing',
    ];
}

t('정상 period → 에러 없음', bravoValidateExam(valid()) === []);

$d = valid(); $d['title'] = '   ';
t('빈 제목 에러', in_array('시험명을 입력해주세요.', bravoValidateExam($d), true));

$d = valid(); $d['bravo_level'] = 4;
t('level 범위 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['exam_mode'] = 'weird';
t('mode 범위 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['status'] = 'nope';
t('status 범위 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['attempt_limit'] = 0;
t('attempt_limit<1 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['start_at'] = null;
t('period 시작일 누락 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['start_at'] = '2026-06-03 10:00:00'; // start > end
t('start > end 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['result_release_at'] = '2026-06-01 09:00:00'; // release < end
t('release < end 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['exam_mode'] = 'always'; $d['start_at'] = null; $d['end_at'] = null; $d['result_release_at'] = null;
t('always 모드 날짜 없어도 통과', bravoValidateExam($d) === []);

$d = valid(); $d['target_type'] = 'cohort'; $d['target_cohort_id'] = null;
t('cohort 타겟 cohort_id 누락 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['target_type'] = 'cohort'; $d['target_cohort_id'] = 5;
t('cohort 타겟 cohort_id 있으면 통과', bravoValidateExam($d) === []);

$d = valid(); $d['target_type'] = 'bogus';
t('target_type 범위 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); unset($d['status']);
t('status 누락은 preparing 으로 허용(통과)', bravoValidateExam($d) === []);

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
