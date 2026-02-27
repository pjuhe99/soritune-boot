<?php
/**
 * boot.soritune.com - Core Configuration
 * DB connection, utility functions
 */

date_default_timezone_set('Asia/Seoul');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/logs/php_error.log');

// ── DB Connection ──────────────────────────────────────────

function loadDbCredentials(): array {
    static $creds = null;
    if ($creds !== null) return $creds;

    $path = dirname(__DIR__) . '/.db_credentials';
    if (!file_exists($path)) {
        throw new RuntimeException('DB credentials file not found');
    }
    $creds = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $creds[trim($key)] = trim($val);
        }
    }
    return $creds;
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $c = loadDbCredentials();
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $c['DB_HOST'], $c['DB_NAME']);
    $pdo = new PDO($dsn, $c['DB_USER'], $c['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+09:00'");
    return $pdo;
}

// ── JSON Response Helpers ──────────────────────────────────

function jsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $code = 400): never {
    jsonResponse(['success' => false, 'error' => $message], $code);
}

function jsonSuccess(array $data = [], string $message = ''): never {
    $resp = ['success' => true];
    if ($message) $resp['message'] = $message;
    jsonResponse(array_merge($resp, $data));
}

// ── Request Helpers ────────────────────────────────────────

function getAction(): string {
    return $_GET['action'] ?? '';
}

function getMethod(): string {
    return $_SERVER['REQUEST_METHOD'];
}

function getJsonInput(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($ct, 'application/json')) {
        $raw = file_get_contents('php://input');
        if (!$raw) return [];
        $decoded = json_decode($raw, true);
        if ($decoded !== null) return $decoded;
        // ModSecurity may escape special chars (e.g. ! → \!); strip invalid escapes
        $cleaned = preg_replace('/\\\\([^"\\\\\\/bfnrtu])/', '$1', $raw);
        $decoded = json_decode($cleaned, true);
        return $decoded ?? [];
    }
    return $_POST;
}

function getClientIP(): string {
    return $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
}

// ── Settings ───────────────────────────────────────────────

function getSetting(string $key, mixed $default = null): mixed {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];

    $db = getDB();
    $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    $cache[$key] = $row ? $row['value'] : $default;
    return $cache[$key];
}

function updateSetting(string $key, string $value): void {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()');
    $stmt->execute([$key, $value]);
}

function clearSettingsCache(): void {
    // Called after updateSetting when cache refresh is needed within same request
    static $dummy = null;
    // getSetting uses its own static cache; for same-request updates,
    // we re-read by bypassing cache
}

function getSettingFresh(string $key, mixed $default = null): mixed {
    $db = getDB();
    $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

// ── Output Helpers ─────────────────────────────────────────

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
