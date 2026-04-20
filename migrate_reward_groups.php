<?php
/**
 * boot.soritune.com - Reward Groups Migration
 * reward_groups + reward_group_distributions 테이블 생성
 * coin_cycles.reward_group_id FK 컬럼 추가
 *
 * 실행: php migrate_reward_groups.php
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Reward Groups Migration ===\n\n";

// 1. reward_groups
echo "[1] reward_groups 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS reward_groups (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(50) NOT NULL COMMENT '예: 11-12기 리워드',
    status           ENUM('open','distributed') NOT NULL DEFAULT 'open',
    distributed_at   DATETIME     DEFAULT NULL,
    distributed_by   INT UNSIGNED DEFAULT NULL COMMENT 'admins.id',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rg_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// 2. reward_group_distributions
echo "\n[2] reward_group_distributions 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS reward_group_distributions (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reward_group_id    INT UNSIGNED NOT NULL,
    member_id          INT UNSIGNED NOT NULL,
    total_amount       INT NOT NULL COMMENT '지급 확정 코인 합',
    cycle_breakdown    JSON NOT NULL COMMENT '예: {\"11기\": 50, \"12기\": 8}',
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_rgd (reward_group_id, member_id),
    KEY idx_rgd_member (member_id),
    CONSTRAINT fk_rgd_group  FOREIGN KEY (reward_group_id) REFERENCES reward_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_rgd_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// 3. coin_cycles.reward_group_id
echo "\n[3] coin_cycles.reward_group_id 추가...\n";
$cols = $db->query("SHOW COLUMNS FROM coin_cycles")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('reward_group_id', $cols)) {
    $db->exec("ALTER TABLE coin_cycles ADD COLUMN reward_group_id INT UNSIGNED NULL AFTER max_coin");
    $db->exec("ALTER TABLE coin_cycles ADD KEY idx_cc_rg (reward_group_id)");
    $db->exec("ALTER TABLE coin_cycles ADD CONSTRAINT fk_cc_rg FOREIGN KEY (reward_group_id) REFERENCES reward_groups(id) ON DELETE SET NULL");
    echo "  - 컬럼 + FK 추가 완료\n";
} else {
    echo "  - 이미 존재\n";
}

echo "\n=== Reward Groups Migration 완료 ===\n";
