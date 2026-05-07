<?php
/**
 * boot.soritune.com - qr_attendance + revival_logs 에 audit 컬럼 추가
 * 사용: php migrate_qr_audit.php
 * DEV/PROD 각각 실행. 멱등.
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

function indexExists(PDO $db, string $dbName, string $table, string $idx): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$dbName, $table, $idx]);
    return (bool)$stmt->fetchColumn();
}

$alters = [
    ['qr_attendance', 'col', 'actor_member_id',
        "ALTER TABLE qr_attendance ADD COLUMN actor_member_id INT UNSIGNED NULL COMMENT '실제 요청한 회원 (member_id 와 다르면 대리 출석)' AFTER member_id"],
    ['qr_attendance', 'idx', 'idx_qa_actor',
        "ALTER TABLE qr_attendance ADD KEY idx_qa_actor (actor_member_id)"],
    ['revival_logs',  'col', 'actor_member_id',
        "ALTER TABLE revival_logs ADD COLUMN actor_member_id INT UNSIGNED NULL AFTER member_id"],
    ['revival_logs',  'col', 'qr_session_id',
        "ALTER TABLE revival_logs ADD COLUMN qr_session_id INT UNSIGNED NULL AFTER actor_member_id"],
    ['revival_logs',  'idx', 'idx_rl_actor',
        "ALTER TABLE revival_logs ADD KEY idx_rl_actor (actor_member_id)"],
    ['revival_logs',  'idx', 'idx_rl_session',
        "ALTER TABLE revival_logs ADD KEY idx_rl_session (qr_session_id)"],
];

foreach ($alters as [$table, $kind, $name, $sql]) {
    $exists = ($kind === 'col')
        ? columnExists($db, $dbName, $table, $name)
        : indexExists($db, $dbName, $table, $name);
    if ($exists) {
        echo "SKIP  {$table}.{$kind}.{$name} (already exists)\n";
        continue;
    }
    $db->exec($sql);
    echo "ADD   {$table}.{$kind}.{$name}\n";
}

echo "\nDone. DB: {$dbName}\n";
