<?php
/**
 * boot.soritune.com - DB Migration: tasks 결과물 제출 컬럼 3개 추가.
 *
 * - requires_submission TINYINT(1) NOT NULL DEFAULT 0
 * - submission_text     TEXT NULL
 * - submitted_at        DATETIME NULL
 *
 * 멱등: information_schema 조회 후 컬럼 없을 때만 ADD.
 *
 * 실행:
 *   cd /root/boot-dev   && php migrate_tasks_submission.php   (DEV)
 *   cd /root/boot-prod  && php migrate_tasks_submission.php   (PROD, 사용자 명시 후)
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/public_html/config.php';
$db = getDB();

echo "=== boot.soritune.com DB Migration: tasks submission ===\n\n";

function colExists(\PDO $db, string $table, string $col): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $col]);
    return (bool)$stmt->fetchColumn();
}

$specs = [
    'requires_submission' => "ADD COLUMN requires_submission TINYINT(1) NOT NULL DEFAULT 0 AFTER completed",
    'submission_text'     => "ADD COLUMN submission_text TEXT NULL AFTER requires_submission",
    'submitted_at'        => "ADD COLUMN submitted_at DATETIME NULL AFTER submission_text",
];

foreach ($specs as $col => $clause) {
    if (colExists($db, 'tasks', $col)) {
        echo "  - tasks.$col 이미 존재 (skip)\n";
        continue;
    }
    $db->exec("ALTER TABLE tasks $clause");
    echo "  - tasks.$col 추가 완료\n";
}

echo "\n검증:\n";
$check = $db->query("
    SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks'
       AND COLUMN_NAME IN ('requires_submission','submission_text','submitted_at')
     ORDER BY ORDINAL_POSITION
")->fetchAll();
foreach ($check as $r) {
    printf("  %-22s %s NULL=%s DEFAULT=%s\n",
        $r['COLUMN_NAME'], $r['COLUMN_TYPE'], $r['IS_NULLABLE'], $r['COLUMN_DEFAULT'] ?? 'NULL');
}
echo "\n완료.\n";
