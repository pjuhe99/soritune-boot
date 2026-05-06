<?php
/**
 * Zoom Server-to-Server OAuth + 미팅 API 클라이언트.
 * - keys/zoom.json 에서 자격증명 로드
 * - settings.zoom_oauth_token 에 access_token 캐시 (만료 60초 전부터 갱신)
 * - 401 발생 시 토큰 무효화 + 1회 재시도
 */

declare(strict_types=1);

const ZOOM_API_BASE                  = 'https://api.zoom.us/v2';
const ZOOM_OAUTH_BASE                = 'https://zoom.us/oauth';
const ZOOM_TOKEN_REFRESH_BUFFER_SEC  = 60;

/**
 * keys/zoom.json 로드 (정적 캐시).
 * 기대 형식: {"accountId":"...","clientId":"...","clientSecret":"..."}
 */
function zoomLoadKeys(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $path = dirname(__DIR__, 3) . '/keys/zoom.json';
    if (!file_exists($path)) {
        throw new RuntimeException("keys/zoom.json 없음: {$path}");
    }
    $raw = file_get_contents($path);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)
        || empty($data['accountId'])
        || empty($data['clientId'])
        || empty($data['clientSecret'])) {
        throw new RuntimeException('keys/zoom.json 형식 오류 (accountId/clientId/clientSecret 필수)');
    }
    $cache = $data;
    return $cache;
}
