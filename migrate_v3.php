<?php
/**
 * boot.soritune.com - Database Migration V3
 * - bootcamp_members.cafe_member_key 컬럼 추가 (n8n 연동용)
 * - bootcamp_members.member_status 컬럼 추가 (없으면)
 * - integration_logs 테이블 생성 (n8n 실행 이력)
 *
 * Run once: php migrate_v3.php
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== boot.soritune.com DB Migration V3 (n8n Integration) ===\n\n";

// 1. bootcamp_members.cafe_member_key 추가
echo "[1] bootcamp_members.cafe_member_key 컬럼...\n";
$cols = $db->query("SHOW COLUMNS FROM bootcamp_members")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('cafe_member_key', $cols)) {
    $db->exec("ALTER TABLE bootcamp_members ADD COLUMN cafe_member_key VARCHAR(100) DEFAULT NULL COMMENT '네이버 카페 memberKey' AFTER real_name");
    $db->exec("ALTER TABLE bootcamp_members ADD UNIQUE KEY uk_cafe_member_key (cafe_member_key)");
    echo "  - cafe_member_key 컬럼 + 유니크 인덱스 추가 완료\n";
} else {
    echo "  - cafe_member_key 컬럼 이미 존재\n";
}

// 2. bootcamp_members.member_status 추가 (코드에서 사용 중이나 마이그레이션 누락 확인)
echo "\n[2] bootcamp_members.member_status 컬럼...\n";
if (!in_array('member_status', $cols)) {
    $db->exec("ALTER TABLE bootcamp_members ADD COLUMN member_status ENUM('active','out_of_group_management','withdrawn') NOT NULL DEFAULT 'active' COMMENT '회원 상태' AFTER is_active");
    echo "  - member_status 컬럼 추가 완료\n";
} else {
    echo "  - member_status 컬럼 이미 존재\n";
}

// 3. integration_logs 테이블 (n8n 실행 이력)
echo "\n[3] integration_logs 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS integration_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_id    VARCHAR(100) DEFAULT NULL COMMENT 'n8n execution ID',
    total_received  INT UNSIGNED NOT NULL DEFAULT 0,
    total_success   INT UNSIGNED NOT NULL DEFAULT 0,
    total_skipped   INT UNSIGNED NOT NULL DEFAULT 0,
    total_error     INT UNSIGNED NOT NULL DEFAULT 0,
    total_unmapped  INT UNSIGNED NOT NULL DEFAULT 0,
    unmapped_keys   JSON DEFAULT NULL COMMENT '매핑 실패한 member_key 목록',
    error_details   JSON DEFAULT NULL COMMENT '오류 상세',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_il_execution (execution_id),
    KEY idx_il_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

echo "\n=== Migration V3 완료 ===\n";
