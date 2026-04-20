<?php
/**
 * boot.soritune.com - Cheer Award 조별 쿼터 마이그
 * - leader_cheer_awards에 group_id 추가
 * - 유니크 키: (cycle_id, leader_member_id, target_member_id) → (cycle_id, group_id, target_member_id)
 * - leader_member_id → granted_by_member_id 컬럼 rename
 *
 * 실행: php migrate_cheer_award_groups.php
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Cheer Award 조별 쿼터 Migration ===\n\n";

$cols = $db->query("SHOW COLUMNS FROM leader_cheer_awards")->fetchAll(PDO::FETCH_COLUMN);

// 1. group_id 컬럼 추가
echo "[1] group_id 컬럼 추가...\n";
if (!in_array('group_id', $cols)) {
    $db->exec("ALTER TABLE leader_cheer_awards ADD COLUMN group_id INT UNSIGNED NOT NULL AFTER cycle_id");
    $db->exec("ALTER TABLE leader_cheer_awards ADD KEY idx_lca_group (group_id)");
    $db->exec("ALTER TABLE leader_cheer_awards ADD CONSTRAINT fk_lca_group FOREIGN KEY (group_id) REFERENCES bootcamp_groups(id) ON DELETE CASCADE");
    echo "  - 완료\n";
} else {
    echo "  - 이미 존재\n";
}

// 2. leader_member_id → granted_by_member_id rename
echo "\n[2] leader_member_id → granted_by_member_id rename...\n";
if (in_array('leader_member_id', $cols)) {
    // FK/인덱스 먼저 drop
    $indexes = $db->query("SHOW INDEX FROM leader_cheer_awards")->fetchAll(PDO::FETCH_ASSOC);
    $constraints = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leader_cheer_awards'
                                 AND COLUMN_NAME = 'leader_member_id' AND REFERENCED_TABLE_NAME IS NOT NULL")
                    ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($constraints as $fkName) {
        $db->exec("ALTER TABLE leader_cheer_awards DROP FOREIGN KEY `$fkName`");
        echo "  - FK drop: $fkName\n";
    }
    // 구 유니크키 drop
    try { $db->exec("ALTER TABLE leader_cheer_awards DROP INDEX uk_leader_target"); echo "  - uk_leader_target drop\n"; } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE leader_cheer_awards DROP INDEX idx_lca_leader"); echo "  - idx_lca_leader drop\n"; } catch (Throwable $e) {}

    $db->exec("ALTER TABLE leader_cheer_awards
               CHANGE COLUMN leader_member_id granted_by_member_id INT UNSIGNED NOT NULL
               COMMENT '실행자 member_id (조장/부조장/운영/코치/총괄/부총괄)'");
    $db->exec("ALTER TABLE leader_cheer_awards ADD KEY idx_lca_granted_by (granted_by_member_id)");
    $db->exec("ALTER TABLE leader_cheer_awards ADD CONSTRAINT fk_lca_granted_by FOREIGN KEY (granted_by_member_id) REFERENCES bootcamp_members(id) ON DELETE CASCADE");
    echo "  - rename + FK 재생성 완료\n";
} else {
    echo "  - 이미 rename됨\n";
}

// 3. 새 유니크 키
echo "\n[3] uk_group_target 유니크 키...\n";
$indexes = $db->query("SHOW INDEX FROM leader_cheer_awards WHERE Key_name='uk_group_target'")->fetchAll();
if (!$indexes) {
    $db->exec("ALTER TABLE leader_cheer_awards ADD UNIQUE KEY uk_group_target (cycle_id, group_id, target_member_id)");
    echo "  - 완료\n";
} else {
    echo "  - 이미 존재\n";
}

// 4. granted_by_member_id NULL 허용 (관리자 계정이 회원 미연결인 경우 대비)
echo "\n[4] granted_by_member_id NULL 허용...\n";
$col = $db->query("SHOW COLUMNS FROM leader_cheer_awards LIKE 'granted_by_member_id'")->fetch();
if ($col && $col['Null'] === 'NO') {
    $db->exec("ALTER TABLE leader_cheer_awards MODIFY COLUMN granted_by_member_id INT UNSIGNED NULL COMMENT '실행자 member_id (관리자 미연결 시 NULL)'");
    echo "  - NULL 허용 완료\n";
} else {
    echo "  - 이미 NULL 허용\n";
}

echo "\n=== Migration 완료 ===\n";
