<?php
/**
 * boot.soritune.com - Solapi 클라이언트
 * - HMAC-SHA256 인증 (date+salt 서명)
 * - 알림톡 / LMS 페이로드 빌드
 * - DRY_RUN 분기는 호출자(dispatcher)에서 처리. 이 파일은 순수 HTTP/페이로드 책임만.
 */

const SOLAPI_BASE = 'https://api.solapi.com';

/** keys/solapi.json 로드 (캐시) */
function solapiLoadKeys(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $path = dirname(__DIR__, 3) . '/keys/solapi.json';
    if (!file_exists($path)) {
        throw new RuntimeException("keys/solapi.json 없음: {$path}");
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data) || empty($data['apiKey']) || empty($data['apiSecret'])) {
        throw new RuntimeException('keys/solapi.json 형식 오류 (apiKey/apiSecret 필수)');
    }
    $cache = $data + [
        'defaultPfId'     => '',
        'defaultFrom'     => '',
        'dry_run_default' => false,
    ];
    return $cache;
}

/**
 * 솔라피 인증 헤더 생성. 형식:
 *   HMAC-SHA256 apiKey=..., date=..., salt=..., signature=...
 * signature = HMAC-SHA256(date + salt, secret)
 */
function solapiBuildAuthHeader(string $apiKey, string $secret, string $isoDate, string $salt): string {
    $signature = hash_hmac('sha256', $isoDate . $salt, $secret);
    return "HMAC-SHA256 apiKey={$apiKey}, date={$isoDate}, salt={$salt}, signature={$signature}";
}

/** 알림톡 단일 메시지 페이로드 빌드 */
function solapiBuildAlimtalkPayload(
    string $to,
    string $from,
    string $pfId,
    string $templateId,
    array  $variables
): array {
    return [
        'to'   => $to,
        'from' => $from,
        'type' => 'ATA',
        'kakaoOptions' => [
            'pfId'       => $pfId,
            'templateId' => $templateId,
            'variables'  => (object)$variables,  // 빈 배열도 객체로 직렬화
        ],
    ];
}

/** LMS(장문 SMS) 단일 메시지 페이로드 빌드 (폴백 활성화 후 사용) */
function solapiBuildLmsPayload(string $to, string $from, string $text): array {
    return [
        'to'   => $to,
        'from' => $from,
        'type' => 'LMS',
        'text' => $text,
    ];
}

/**
 * send-many/detail 호출. messages는 페이로드 배열의 배열.
 * @return array ['ok'=>bool, 'http_code'=>int, 'body'=>string, 'parsed'=>array|null]
 *  - HTTP 5xx/timeout/네트워크 오류 → ok=false, http_code=0 또는 5xx
 *  - HTTP 4xx → ok=false, http_code=4xx
 *  - HTTP 200 → ok=true (개별 메시지 status는 호출자가 parsed에서 매핑)
 */
function solapiSendMany(array $messages): array {
    $keys = solapiLoadKeys();
    $url  = SOLAPI_BASE . '/messages/v4/send-many/detail';
    $isoDate = gmdate('Y-m-d\TH:i:s\Z');
    $salt    = bin2hex(random_bytes(16));
    $auth    = solapiBuildAuthHeader($keys['apiKey'], $keys['apiSecret'], $isoDate, $salt);

    $body = json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: {$auth}",
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'http_code' => 0, 'body' => $err, 'parsed' => null];
    }
    $parsed = json_decode($resp, true);
    return [
        'ok'        => $http >= 200 && $http < 300,
        'http_code' => $http,
        'body'      => $resp,
        'parsed'    => is_array($parsed) ? $parsed : null,
    ];
}
