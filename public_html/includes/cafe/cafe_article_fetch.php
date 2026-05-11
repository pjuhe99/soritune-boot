<?php
declare(strict_types=1);
/**
 * 네이버 카페 article API 호출 → writer 정보 추출.
 *
 * 기존 api/admin.php fetch_cafe_info 액션(cURL 블록)을 함수로 추출.
 * 호출자가 try/catch 로 CafeArticleFetchException 잡아 응답 사유 표시.
 */

class CafeArticleFetchException extends RuntimeException {}

function fetchCafeArticleInfo(string $articleId, int $clubId = 23243775): array {
    if (!preg_match('/^\d+$/', $articleId)) {
        throw new CafeArticleFetchException('invalid article id');
    }
    $buid = 'a968c143-ebd4-46bb-82ff-5f11230389c5';
    $url = "https://article.cafe.naver.com/gw/v4/cafes/{$clubId}/articles/{$articleId}"
         . "?fromList=true&menuId=292&tc=cafe_article_list&useCafeId=true&buid={$buid}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err !== '') throw new CafeArticleFetchException("network: {$err}");
    if ($httpCode !== 200) throw new CafeArticleFetchException("HTTP {$httpCode}");

    $data = json_decode((string)$resp, true);
    if (isset($data['result']['errorCode'])) {
        throw new CafeArticleFetchException((string)($data['result']['message'] ?? '게시글 접근 불가'));
    }
    $writer = $data['result']['article']['writer'] ?? null;
    if (!$writer || !isset($writer['memberKey'])) {
        throw new CafeArticleFetchException('writer 또는 memberKey 정보 없음');
    }
    return [
        'member_key' => (string)$writer['memberKey'],
        'nick'       => (string)($writer['nick'] ?? ''),
    ];
}
