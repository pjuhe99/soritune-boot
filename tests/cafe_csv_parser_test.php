<?php
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/includes/cafe/cafe_csv_parser.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// 1. 기본 (헤더 없음)
$r = parseCafeCsv("리사조,김명식,그릭이,https://example.com/1");
t('basic',
    count($r['rows']) === 1
    && $r['rows'][0] === ['group' => '리사조', 'name' => '김명식', 'nick' => '그릭이', 'url' => 'https://example.com/1']
    && empty($r['errors']));

// 2. 헤더 행 skip
$r = parseCafeCsv("조,이름,닉,링크\n리사조,김명식,그릭이,https://example.com/1");
t('header_skip', count($r['rows']) === 1 && $r['rows'][0]['name'] === '김명식');

// 3. 빈 줄 무시
$r = parseCafeCsv("\n리사조,김명식,그릭이,https://example.com/1\n\n무이조,이서연,서연쓰,https://example.com/2\n");
t('blank_lines', count($r['rows']) === 2);

// 4. 닉 빈 칸
$r = parseCafeCsv("리사조,김명식,,https://example.com/1");
t('empty_nick',
    count($r['rows']) === 1
    && $r['rows'][0]['nick'] === ''
    && $r['rows'][0]['name'] === '김명식');

// 5. 큰따옴표 이스케이프
$r = parseCafeCsv("\"리사조\",\"김, 명식\",\"그릭이\",\"https://example.com/1\"");
t('quoted_comma',
    count($r['rows']) === 1
    && $r['rows'][0]['name'] === '김, 명식');

// 6. 컬럼 수 부족
$r = parseCafeCsv("리사조,김명식,그릭이");
t('missing_col',
    count($r['rows']) === 0
    && count($r['errors']) === 1
    && $r['errors'][0]['reason'] === 'missing_columns');

// 7. BOM 제거
$r = parseCafeCsv("\xEF\xBB\xBF리사조,김명식,그릭이,https://example.com/1");
t('bom',
    count($r['rows']) === 1
    && $r['rows'][0]['group'] === '리사조');

// 8. 100행 상한
$lines = [];
for ($i = 0; $i < 105; $i++) $lines[] = "리사조,name{$i},nick{$i},https://example.com/{$i}";
$r = parseCafeCsv(implode("\n", $lines));
t('max_rows',
    count($r['rows']) === 100
    && count($r['errors']) === 1
    && $r['errors'][0]['reason'] === 'batch_too_large');

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
