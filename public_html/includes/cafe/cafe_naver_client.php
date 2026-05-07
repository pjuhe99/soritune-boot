<?php
/**
 * 네이버 카페 공개 listing API 호출.
 * - 인증 없음 (공개 보드)
 * - 응답 파싱하여 cafe_posts 표준 형태로 정규화 [{cafe_article_id, title, member_key, nickname, posted_at}]
 *
 * 검증된 endpoint (2026-05-06 기준):
 *   GET https://apis.naver.com/cafe-web/cafe2/ArticleListV2dot1.json
 *       ?search.clubid={CAFE_CLUB_ID}
 *       &search.menuid={menu_id}
 *       &search.queryType=lastArticle
 *       &search.page=1
 *       &search.perPage={N}
 */

declare(strict_types=1);

const CAFE_CLUB_ID = 23243775;  // 소리튠영어 카페
const CAFE_NAVER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

/**
 * 보드의 최근 게시글 목록 조회.
 *
 * @return array<int, array{cafe_article_id:string, title:string, member_key:string, nickname:string, posted_at:string}>
 *         memberKey/articleId/timestamp 누락된 article 은 결과에서 제외 (다음 회차 재시도).
 * @throws RuntimeException HTTP/parse 실패 시
 */
function cafeFetchBoardArticles(string $menuId, int $perPage = 20): array {
    $url = 'https://apis.naver.com/cafe-web/cafe2/ArticleListV2dot1.json'
         . '?search.clubid=' . CAFE_CLUB_ID
         . '&search.menuid=' . urlencode($menuId)
         . '&search.queryType=lastArticle'
         . '&search.page=1'
         . '&search.perPage=' . max(1, $perPage);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . CAFE_NAVER_USER_AGENT],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new RuntimeException("Naver cURL: {$err}");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Naver HTTP {$code}: " . substr((string)$resp, 0, 300));
    }

    $data   = json_decode((string)$resp, true);
    $status = $data['message']['status'] ?? '';
    if ($status !== '200') {
        $errMsg = $data['message']['error']['msg'] ?? 'unknown';
        throw new RuntimeException("Naver API status={$status}: {$errMsg}");
    }

    $articles = $data['message']['result']['articleList'] ?? [];
    $out = [];
    foreach ($articles as $a) {
        $articleId = $a['articleId']          ?? null;
        $memberKey = $a['memberKey']          ?? null;
        $ts        = $a['writeDateTimestamp'] ?? null;
        if (!$articleId || !$memberKey || !$ts) continue;
        $postedAtDt = (new DateTime('@' . (int)floor($ts / 1000)))->setTimezone(new DateTimeZone('Asia/Seoul'));
        $out[] = [
            'cafe_article_id' => (string)$articleId,
            'title'           => (string)($a['subject']        ?? ''),
            'member_key'      => (string)$memberKey,
            'nickname'        => (string)($a['writerNickname'] ?? ''),
            'posted_at'       => $postedAtDt->format('Y-m-d H:i:s'),
        ];
    }
    return $out;
}
