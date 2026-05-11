<?php
/**
 * 카페 링크 파서 unit 테스트.
 * 사용: php tests/cafe_link_parser_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/includes/cafe/cafe_link_parser.php';

$pass = 0; $fail = 0;
function t(string $name, $actual, $expected): void {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}\n   expected=" . var_export($expected, true) . "\n   actual=  " . var_export($actual, true) . "\n";
}

// PC 옛 단축형
t('pc_short', parseCafeLink('https://cafe.naver.com/themysticsoritune/321852'),
    ['article_id' => '321852', 'error' => null]);

// PC ca-fe 신
t('pc_cafe',  parseCafeLink('https://cafe.naver.com/ca-fe/cafes/23243775/articles/321852'),
    ['article_id' => '321852', 'error' => null]);

// 모바일 옛
t('m_short',  parseCafeLink('https://m.cafe.naver.com/themysticsoritune/321852'),
    ['article_id' => '321852', 'error' => null]);

// 모바일 ca-fe 신
t('m_cafe',   parseCafeLink('https://m.cafe.naver.com/ca-fe/web/cafes/23243775/articles/321852'),
    ['article_id' => '321852', 'error' => null]);

// 모바일 메뉴 경유
t('m_menu',   parseCafeLink('https://m.cafe.naver.com/ca-fe/web/cafes/23243775/menus/292/articles/321852'),
    ['article_id' => '321852', 'error' => null]);

// PC 쿼리 articleid
t('pc_query', parseCafeLink('https://cafe.naver.com/themysticsoritune?iframe_url=/ArticleRead.nhn%3Farticleid=321852'),
    ['article_id' => '321852', 'error' => null]);

// 다른 카페 clubId
t('wrong_cafe', parseCafeLink('https://cafe.naver.com/ca-fe/cafes/99999999/articles/321852'),
    ['article_id' => null, 'error' => 'wrong_cafe']);

// 잘못된 URL
t('not_cafe', parseCafeLink('https://google.com/whatever'),
    ['article_id' => null, 'error' => 'invalid']);

// 빈 문자열
t('empty',    parseCafeLink(''),
    ['article_id' => null, 'error' => 'empty']);

// 공백 trim
t('spaces',   parseCafeLink('  https://cafe.naver.com/themysticsoritune/321852  '),
    ['article_id' => '321852', 'error' => null]);

// 모바일 ca-fe alias (영문 카페 ID)
t('m_cafe_alias', parseCafeLink('https://m.cafe.naver.com/ca-fe/web/cafes/312edupot/articles/239692?art=foo&tc'),
    ['article_id' => '239692', 'error' => null]);

// PC ca-fe alias
t('pc_cafe_alias', parseCafeLink('https://cafe.naver.com/ca-fe/cafes/themysticsoritune/articles/239692'),
    ['article_id' => '239692', 'error' => null]);

// 영문 alias 안의 wrong cafe — parse는 통과, 사후 검증은 fetchCafeArticleInfo 에서
// (parse 단계에서 alias 가 우리 카페인지 알 수 없으므로 통과시킴)
t('m_cafe_alias_unknown', parseCafeLink('https://m.cafe.naver.com/ca-fe/web/cafes/someotheralias/articles/123'),
    ['article_id' => '123', 'error' => null]);

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
