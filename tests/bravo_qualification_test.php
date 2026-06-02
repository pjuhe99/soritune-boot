<?php
/**
 * BRAVO 자격계산 순수 함수 단위 테스트.
 * 사용: php tests/bravo_qualification_test.php
 * DB 불필요 (순수 함수). bravo_levels 임계는 픽스처로 주입.
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

// 픽스처: bravo_levels 임계 (실제 시드와 동일 형태)
$levels = [
    ['level'=>1,'required_review_count'=>3],
    ['level'=>2,'required_review_count'=>6],
    ['level'=>3,'required_review_count'=>10],
];

// bravoEffectiveReviewCount: override 우선, NULL이면 completed
t('유효회독 override 우선', bravoEffectiveReviewCount(8, 2) === 8);
t('유효회독 override NULL이면 completed', bravoEffectiveReviewCount(null, 5) === 5);
t('유효회독 override 0도 유효(자동 아님)', bravoEffectiveReviewCount(0, 9) === 0);

// bravoAutoEligibleLevels: 회독수 임계 (경계)
t('자동 0회독 → 없음', bravoAutoEligibleLevels(0, $levels) === []);
t('자동 2회독 → 없음', bravoAutoEligibleLevels(2, $levels) === []);
t('자동 3회독 → [1]', bravoAutoEligibleLevels(3, $levels) === [1]);
t('자동 5회독 → [1]', bravoAutoEligibleLevels(5, $levels) === [1]);
t('자동 6회독 → [1,2]', bravoAutoEligibleLevels(6, $levels) === [1,2]);
t('자동 9회독 → [1,2]', bravoAutoEligibleLevels(9, $levels) === [1,2]);
t('자동 10회독 → [1,2,3]', bravoAutoEligibleLevels(10, $levels) === [1,2,3]);
t('자동 15회독 → [1,2,3]', bravoAutoEligibleLevels(15, $levels) === [1,2,3]);

// bravoEligibleLevels: 자동 ∪ 수동부여, 정렬·중복제거
t('최종 자동만 (override NULL, completed 6, grant 없음)',
    bravoEligibleLevels(null, 6, [], $levels) === [1,2]);
t('최종 수동부여 합집합 (자동 [1] + grant [3])',
    bravoEligibleLevels(null, 3, [3], $levels) === [1,3]);
t('최종 수동부여 중복제거 (자동 [1,2] + grant [1])',
    bravoEligibleLevels(null, 6, [1], $levels) === [1,2]);
t('최종 override가 자동 좌우 (override 10, completed 1)',
    bravoEligibleLevels(10, 1, [], $levels) === [1,2,3]);
t('최종 grant만 (자동 없음 + grant [2])',
    bravoEligibleLevels(null, 0, [2], $levels) === [2]);

// bravoParseGrantedLevels: SET 문자열 → int 배열
t('parse null → []', bravoParseGrantedLevels(null) === []);
t('parse 빈문자열 → []', bravoParseGrantedLevels('') === []);
t('parse "1,3" → [1,3]', bravoParseGrantedLevels('1,3') === [1,3]);
t('parse "3,1" → [1,3] (정렬)', bravoParseGrantedLevels('3,1') === [1,3]);
t('parse "1,4" → [1] (무효값 필터)', bravoParseGrantedLevels('1,4') === [1]);

// bravoFormatGrantedLevels: int 배열 → SET 문자열
t('format [] → ""', bravoFormatGrantedLevels([]) === '');
t('format [3,1,1] → "1,3" (중복제거+정렬)', bravoFormatGrantedLevels([3,1,1]) === '1,3');
t('format [1,4] → "1" (무효값 필터)', bravoFormatGrantedLevels([1,4]) === '1');

// round-trip 불변
t('round-trip format∘parse [1,3]', bravoParseGrantedLevels(bravoFormatGrantedLevels([1,3])) === [1,3]);

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
