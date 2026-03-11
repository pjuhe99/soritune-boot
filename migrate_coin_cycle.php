<?php
/**
 * boot.soritune.com - Coin Cycle Migration
 * 코인 사이클 시스템 DB 스키마 생성
 *
 * 실행: php migrate_coin_cycle.php
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Coin Cycle Migration ===\n\n";

// ──────────────────────────────────────────────
// 1. coin_cycles
// ──────────────────────────────────────────────
echo "[1] coin_cycles 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS coin_cycles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(50) NOT NULL COMMENT '예: 3기 전반기',
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL COMMENT '일요일 기준 마감',
    max_coin    INT NOT NULL DEFAULT 200 COMMENT 'cycle당 최대 적립',
    status      ENUM('active','closed') NOT NULL DEFAULT 'active',
    closed_at   DATETIME DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_cycle_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 2. member_cycle_coins
// ──────────────────────────────────────────────
echo "\n[2] member_cycle_coins 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS member_cycle_coins (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id           INT UNSIGNED NOT NULL,
    cycle_id            INT UNSIGNED NOT NULL,
    earned_coin         INT NOT NULL DEFAULT 0 COMMENT '이번 cycle 누적 적립',
    used_coin           INT NOT NULL DEFAULT 0 COMMENT '사용/차감 (향후 확장)',
    study_open_count    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '복클개설 지급 횟수 (max 10)',
    study_join_count    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '복클참여 지급 횟수 (max 15)',
    leader_coin_granted TINYINT(1) NOT NULL DEFAULT 0 COMMENT '리더코인 지급 여부',
    perfect_attendance_granted TINYINT(1) NOT NULL DEFAULT 0 COMMENT '찐완주 지급 여부',
    hamemmal_granted    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '하멈말 지급 여부',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_member_cycle (member_id, cycle_id),
    KEY idx_mcc_cycle (cycle_id),
    CONSTRAINT fk_mcc_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_mcc_cycle FOREIGN KEY (cycle_id) REFERENCES coin_cycles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 3. leader_cheer_awards
// ──────────────────────────────────────────────
echo "\n[3] leader_cheer_awards 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS leader_cheer_awards (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_id            INT UNSIGNED NOT NULL,
    leader_member_id    INT UNSIGNED NOT NULL COMMENT '선택한 조장',
    target_member_id    INT UNSIGNED NOT NULL COMMENT '선택된 조원',
    coin_amount         INT NOT NULL DEFAULT 10,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_leader_target (cycle_id, leader_member_id, target_member_id),
    KEY idx_lca_cycle (cycle_id),
    KEY idx_lca_leader (leader_member_id),
    KEY idx_lca_target (target_member_id),
    CONSTRAINT fk_lca_cycle FOREIGN KEY (cycle_id) REFERENCES coin_cycles(id) ON DELETE CASCADE,
    CONSTRAINT fk_lca_leader FOREIGN KEY (leader_member_id) REFERENCES bootcamp_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_lca_target FOREIGN KEY (target_member_id) REFERENCES bootcamp_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 4. coin_logs에 cycle_id 컬럼 추가
// ──────────────────────────────────────────────
echo "\n[4] coin_logs에 cycle_id 추가...\n";

$cols = $db->query("SHOW COLUMNS FROM coin_logs")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('cycle_id', $cols)) {
    $db->exec("ALTER TABLE coin_logs ADD COLUMN cycle_id INT UNSIGNED DEFAULT NULL COMMENT 'coin_cycles FK' AFTER member_id");
    $db->exec("ALTER TABLE coin_logs ADD KEY idx_cl_cycle (cycle_id)");
    echo "  - cycle_id 컬럼 추가 완료\n";
} else {
    echo "  - cycle_id 컬럼 이미 존재\n";
}

echo "\n=== Coin Cycle Migration 완료 ===\n";
