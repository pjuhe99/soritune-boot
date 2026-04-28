<?php
/**
 * boot.soritune.com - notify_batch / notify_preview 에 bypass 컬럼 추가
 * 사용: php migrate_notify_bypass_columns.php
 * DEV/PROD 각각 실행. 멱등(컬럼 존재 시 skip).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/public_html/config.php';

$db = getDB();
$dbName = $db->query("SELECT DATABASE()")->fetchColumn();

function columnExists(PDO $db, string $dbName, string $table, string $col): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$dbName, $table, $col]);
    return (bool)$stmt->fetchColumn();
}

$alters = [
    ['notify_batch',   'bypass_cooldown',     "ALTER TABLE notify_batch   ADD COLUMN bypass_cooldown     TINYINT(1) NOT NULL DEFAULT 0 AFTER dry_run"],
    ['notify_batch',   'bypass_max_attempts', "ALTER TABLE notify_batch   ADD COLUMN bypass_max_attempts TINYINT(1) NOT NULL DEFAULT 0 AFTER bypass_cooldown"],
    ['notify_preview', 'bypass_cooldown',     "ALTER TABLE notify_preview ADD COLUMN bypass_cooldown     TINYINT(1) NOT NULL DEFAULT 0"],
    ['notify_preview', 'bypass_max_attempts', "ALTER TABLE notify_preview ADD COLUMN bypass_max_attempts TINYINT(1) NOT NULL DEFAULT 0"],
];

foreach ($alters as [$table, $col, $sql]) {
    if (columnExists($db, $dbName, $table, $col)) {
        echo "SKIP  {$table}.{$col} (이미 존재)\n";
        continue;
    }
    $db->exec($sql);
    echo "ADD   {$table}.{$col}\n";
}

echo "마이그레이션 완료 (DB: {$dbName})\n";
