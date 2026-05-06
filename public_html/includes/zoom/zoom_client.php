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

/**
 * Server-to-Server OAuth access token 조회.
 * - settings.zoom_oauth_token 에서 캐시된 토큰을 읽고, 만료 60초 전이 아니면 재사용
 * - 만료/없음 또는 $forceRefresh=true 면 OAuth 호출 후 캐시 갱신
 *
 * @throws RuntimeException OAuth HTTP/parse 실패 시
 */
function zoomGetAccessToken(bool $forceRefresh = false): string {
    if (!$forceRefresh) {
        $cached = getSettingFresh('zoom_oauth_token');
        if ($cached) {
            $data = json_decode((string)$cached, true);
            if (is_array($data)
                && !empty($data['access_token'])
                && (int)($data['expires_at'] ?? 0) > time() + ZOOM_TOKEN_REFRESH_BUFFER_SEC) {
                return (string)$data['access_token'];
            }
        }
    }

    $keys = zoomLoadKeys();
    $url  = ZOOM_OAUTH_BASE . '/token?grant_type=account_credentials&account_id=' . urlencode($keys['accountId']);
    $auth = base64_encode($keys['clientId'] . ':' . $keys['clientSecret']);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new RuntimeException("Zoom OAuth cURL: {$err}");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Zoom OAuth HTTP {$code}: " . substr((string)$response, 0, 300));
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data) || empty($data['access_token']) || empty($data['expires_in'])) {
        throw new RuntimeException('Zoom OAuth 응답 파싱 실패');
    }

    // 캐시 저장 (실패해도 현재 요청은 진행)
    try {
        updateSetting('zoom_oauth_token', json_encode([
            'access_token' => $data['access_token'],
            'expires_at'   => time() + (int)$data['expires_in'],
        ], JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {
        error_log('zoom_oauth_token settings 저장 실패: ' . $e->getMessage());
    }

    return (string)$data['access_token'];
}
