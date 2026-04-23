<?php
/**
 * 소리튠 부트캠프(BOOT) - Google Sheets API 클래스
 * 서비스 계정 JWT 인증, 읽기 전용
 */
class GoogleSheets {
    private $credentials;
    private $accessToken;
    private $tokenExpiry = 0;

    public function __construct() {
        $keyFile = dirname(__DIR__) . '/keys/google-sheets-service-account.json';
        if (!file_exists($keyFile)) {
            throw new Exception("Google Sheets 키 파일 없음: {$keyFile}");
        }
        $this->credentials = json_decode(file_get_contents($keyFile), true);
        if (!$this->credentials || empty($this->credentials['private_key'])) {
            throw new Exception("잘못된 서비스 계정 키 파일");
        }
    }

    private function getAccessToken(): string {
        if ($this->accessToken && $this->tokenExpiry > time() + 60) {
            return $this->accessToken;
        }

        $now = time();
        $expiry = $now + 3600;

        $header = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = $this->b64url(json_encode([
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now, 'exp' => $expiry,
        ]));

        $pk = openssl_pkey_get_private($this->credentials['private_key']);
        if (!$pk) throw new Exception("개인 키 로드 실패: " . openssl_error_string());

        openssl_sign("{$header}.{$claim}", $sig, $pk, 'SHA256');
        $jwt = "{$header}.{$claim}." . $this->b64url($sig);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (empty($resp['access_token'])) {
            throw new Exception("액세스 토큰 실패: " . json_encode($resp));
        }

        $this->accessToken = $resp['access_token'];
        $this->tokenExpiry = $expiry;
        return $this->accessToken;
    }

    /**
     * 시트의 지정 range 값을 2차원 배열로 반환 (READONLY scope).
     * @param string $sheetId  스프레드시트 ID
     * @param string $a1Range  'Sheet1!A1:G500' 형식
     */
    public function getValues(string $sheetId, string $a1Range): array {
        $token = $this->getAccessToken();
        $url = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s',
            rawurlencode($sheetId),
            rawurlencode($a1Range)
        );
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            throw new RuntimeException("Sheets API error {$code}: {$resp}");
        }
        $data = json_decode($resp, true);
        return $data['values'] ?? [];
    }

    public function getSheetData(string $spreadsheetId, string $sheetTitle): array {
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/"
            . urlencode($sheetTitle) . "?valueRenderOption=FORMATTED_VALUE";
        $data = $this->httpGet($url);
        return $data['values'] ?? [];
    }

    public function getSheets(string $spreadsheetId): array {
        $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}?fields=properties,sheets.properties";
        $data = $this->httpGet($url);
        $sheets = [];
        foreach ($data['sheets'] ?? [] as $s) {
            $sheets[] = $s['properties']['title'];
        }
        return $sheets;
    }

    private function httpGet(string $url): array {
        $token = $this->getAccessToken();
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) throw new Exception("HTTP 오류: {$err}");
        $data = json_decode($resp, true);
        if ($code >= 400) throw new Exception("API 오류({$code}): " . ($data['error']['message'] ?? $resp));
        return $data ?? [];
    }

    private function b64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
