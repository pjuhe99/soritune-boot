<?php
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/includes/multipass/multipass_csv_parser.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// 1. 헤더 자동 감지 (3컬럼)
$r = parseMultipassCsv("user_id,product_name,cohorts\n3937726826@k,11~13기 묶음권,\"11,12,13\"");
t('header_detect',
    count($r['rows']) === 1
    && $r['rows'][0]['user_id'] === '3937726826@k'
    && $r['rows'][0]['product_name'] === '11~13기 묶음권'
    && $r['rows'][0]['cohort_labels'] === ['11', '12', '13']);

// 2. 헤더 한글 별칭
$r = parseMultipassCsv("아이디,상품명,기수\n4114325139@n,5~7기,\"5|6|7\"");
t('header_korean',
    count($r['rows']) === 1
    && $r['rows'][0]['cohort_labels'] === ['5', '6', '7']);

// 3. 헤더 없는 첫 행도 데이터로 처리 (감지 키워드 없음)
$r = parseMultipassCsv("3937726826@k,11~13기,\"11,12,13\"");
t('no_header',
    count($r['rows']) === 1
    && $r['rows'][0]['user_id'] === '3937726826@k');

// 4. BOM 제거
$r = parseMultipassCsv("\xEF\xBB\xBFuser_id,product_name,cohorts\n3937726826@k,X,11");
t('bom',
    count($r['rows']) === 1
    && $r['rows'][0]['user_id'] === '3937726826@k');

// 5. cohort 분리 — 쉼표/파이프/슬래시 혼용
$r = parseMultipassCsv("3937726826@k,X,\"11, 12 / 13|14\"");
t('cohort_split',
    count($r['rows']) === 1
    && $r['rows'][0]['cohort_labels'] === ['11', '12', '13', '14']);

// 6. cohort 라벨에 "기" 포함도 숫자만 추출
$r = parseMultipassCsv("3937726826@k,X,\"11기,12기,13기\"");
t('cohort_with_suffix',
    count($r['rows']) === 1
    && $r['rows'][0]['cohort_labels'] === ['11', '12', '13']);

// 7. cohort 라벨에 숫자 없음 → 그 라벨은 cohort_labels 에 빈 문자열로 보존 (검증 단계가 식별 실패 처리)
$r = parseMultipassCsv("3937726826@k,X,\"11,예비\"");
t('cohort_unparseable',
    count($r['rows']) === 1
    && $r['rows'][0]['cohort_labels'] === ['11', '예비']
    && $r['rows'][0]['cohort_raw'] === ['11', '예비']);

// 8. 빈 줄 무시
$r = parseMultipassCsv("\n3937726826@k,X,11\n\n4114325139@n,Y,12\n");
t('blank_lines',
    count($r['rows']) === 2);

// 9. 컬럼 수 부족
$r = parseMultipassCsv("3937726826@k,11~13기");
t('missing_col',
    count($r['rows']) === 0
    && count($r['errors']) === 1
    && $r['errors'][0]['reason'] === 'missing_columns');

// 10. RFC 4180 큰따옴표 + 쉼표
$r = parseMultipassCsv('3937726826@k,"X, with, comma","11,12"');
t('quoted_comma',
    count($r['rows']) === 1
    && $r['rows'][0]['product_name'] === 'X, with, comma'
    && $r['rows'][0]['cohort_labels'] === ['11', '12']);

// 11. 공백 trim
$r = parseMultipassCsv("  3937726826@k  ,  X  ,  11  ");
t('trim',
    count($r['rows']) === 1
    && $r['rows'][0]['user_id'] === '3937726826@k'
    && $r['rows'][0]['product_name'] === 'X');

// 12. maxRows guard
$bigCsv = '';
for ($i = 0; $i < 250; $i++) $bigCsv .= "user{$i}@k,prod,11\n";
$r = parseMultipassCsv($bigCsv, 200);
t('max_rows_guard',
    count($r['rows']) <= 200
    && count($r['errors']) >= 1
    && in_array('batch_too_large', array_column($r['errors'], 'reason'), true));

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
