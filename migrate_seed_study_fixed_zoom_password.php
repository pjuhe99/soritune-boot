<?php
/**
 * settings 에 study_fixed_zoom_password (복습스터디 고정 줌방 입력용 숫자 비밀번호) 시드.
 * 빈 값으로 행만 생성 — 실제 비번 값은 운영자가 UPDATE로 채운다.
 *
 *   php migrate_seed_study_fixed_zoom_password.php --db=dev
 *   php migrate_seed_study_fixed_zoom_password.php --db=prod
 *
 * 멱등: 행이 이미 있으면 value는 건드리지 않고 description만 보정.
 */
$opts = getopt('', ['db:']);
$dbTarget = $opts['db'] ?? 'dev';

$path = $dbTarget === 'prod' ? '/root/boot-prod/.db_credentials' : '/root/boot-dev/.db_credentials';
if (!is_readable($path)) die("Credentials not found: {$path}\n");
$env = [];
foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_contains($line, '=')) {
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
}
$dsn = "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4";
$pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->prepare("
    INSERT INTO settings (`key`, `value`, `description`)
    VALUES ('study_fixed_zoom_password', '', '복습스터디 고정 줌방 입력용 숫자 비밀번호')
    ON DUPLICATE KEY UPDATE `description` = VALUES(`description`)
")->execute();

$row = $pdo->query("SELECT `key`, `value`, `description` FROM settings WHERE `key`='study_fixed_zoom_password'")->fetch(PDO::FETCH_ASSOC);
echo "[{$dbTarget}] seeded: key={$row['key']} value='{$row['value']}' desc={$row['description']}\n";
echo "→ 실제 비번 설정: UPDATE settings SET value='<숫자>' WHERE `key`='study_fixed_zoom_password';\n";
