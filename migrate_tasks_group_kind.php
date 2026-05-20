<?php
/**
 * tasks.group_kind / group_scope 컬럼 추가 + 기존 row 백필.
 *
 * 사용: php migrate_tasks_group_kind.php
 *
 * 멱등: 컬럼 존재 시 ALTER skip / group_scope IS NULL 가드로 UPDATE skip.
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function indexExists(PDO $db, string $table, string $index): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?
         LIMIT 1
    ");
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}

echo "== tasks.group_kind / group_scope 마이그 ==\n";

if (!columnExists($db, 'tasks', 'group_kind')) {
    echo "ALTER: group_kind ENUM 추가...\n";
    $db->exec("
        ALTER TABLE tasks
          ADD COLUMN group_kind ENUM('role','everyone','person') NOT NULL DEFAULT 'role'
            AFTER role
    ");
} else {
    echo "skip: group_kind 이미 존재\n";
}

if (!columnExists($db, 'tasks', 'group_scope')) {
    echo "ALTER: group_scope VARCHAR(80) NULL 추가...\n";
    $db->exec("
        ALTER TABLE tasks
          ADD COLUMN group_scope VARCHAR(80) NULL
            AFTER group_kind
    ");
} else {
    echo "skip: group_scope 이미 존재\n";
}

if (!indexExists($db, 'tasks', 'idx_cohort_group')) {
    echo "INDEX: idx_cohort_group 추가...\n";
    $db->exec("CREATE INDEX idx_cohort_group ON tasks (cohort, title, group_kind, group_scope)");
} else {
    echo "skip: idx_cohort_group 이미 존재\n";
}

echo "백필: group_scope IS NULL → role 복사\n";
$db->beginTransaction();
try {
    $stmt = $db->prepare("
        UPDATE tasks
           SET group_kind = 'role', group_scope = role
         WHERE group_scope IS NULL
    ");
    $stmt->execute();
    $updated = $stmt->rowCount();
    $db->commit();
    echo "백필 완료: {$updated} row\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "FAIL: 백필 rollback — " . $e->getMessage() . "\n";
    exit(1);
}

$total = (int)$db->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
$nullScope = (int)$db->query("
    SELECT COUNT(*) FROM tasks
     WHERE group_kind = 'role' AND group_scope IS NULL
")->fetchColumn();
echo "검증: tasks 전체 {$total} / role-kind 인데 scope NULL = {$nullScope}\n";
if ($nullScope !== 0) {
    echo "FAIL: 백필 후에도 role-kind row 에 NULL scope 가 남아있음\n";
    exit(1);
}
echo "PASS\n";
