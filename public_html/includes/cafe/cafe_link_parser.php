<?php
/**
 * 카페 URL → article_id 정규식 추출.
 *
 * 지원 형식:
 *   https://cafe.naver.com/<board>/<articleId>
 *   https://cafe.naver.com/ca-fe/cafes/<clubId>/articles/<articleId>
 *   https://m.cafe.naver.com/<board>/<articleId>
 *   https://m.cafe.naver.com/ca-fe/web/cafes/<clubId>/articles/<articleId>
 *   https://m.cafe.naver.com/ca-fe/web/cafes/<clubId>/menus/<menuId>/articles/<articleId>
 *   https://cafe.naver.com/<board>?...articleid=<articleId>...
 *
 * @return array{article_id: string|null, error: 'empty'|'wrong_cafe'|'invalid'|null}
 */
declare(strict_types=1);

function parseCafeLink(string $url, int $expectedClubId = 23243775): array {
    $url = trim($url);
    if ($url === '') return ['article_id' => null, 'error' => 'empty'];

    // ca-fe (PC/모바일 공통) — clubId 검증 가능
    if (preg_match('#/cafes/(\d+)/(?:menus/\d+/)?articles/(\d+)#', $url, $m)) {
        if ((int)$m[1] !== $expectedClubId) {
            return ['article_id' => null, 'error' => 'wrong_cafe'];
        }
        return ['article_id' => $m[2], 'error' => null];
    }

    // 쿼리 articleid 형식 (literal ?/& 또는 URL-encoded %3F/%26)
    if (preg_match('~cafe\.naver\.com/[^?\s]+\?[^\s#]*(?:[?&]|%3F|%26)articleid=(\d+)~i', $url, $m)) {
        return ['article_id' => $m[1], 'error' => null];
    }

    // PC/모바일 옛 단축형
    if (preg_match('~^https?://(?:m\.)?cafe\.naver\.com/([\w-]+)/(\d+)(?:[/?#].*)?$~', $url, $m)) {
        return ['article_id' => $m[2], 'error' => null];
    }

    return ['article_id' => null, 'error' => 'invalid'];
}
