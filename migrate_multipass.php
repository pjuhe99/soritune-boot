<?php
/**
 * boot.soritune.com - Database Migration: Multipass (다회권)
 * - multipass: user_id 별 다회권 권리
 * - multipass_cohorts: 다회권 × 포함 기수 + 쿠폰 발급 상태
 *
 * Run once: php migrate_multipass.php  (DEV)
 *           php migrate_multipass.php  (PROD, 코드 push 전에 먼저 실행)
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== boot.soritune.com DB Migration: Multipass ===\n\n";

echo "[1] multipass 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS multipass (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       VARCHAR(100) NOT NULL  COMMENT '소리튠 아이디 (식별축, FK 안 검)',
    product_name  VARCHAR(100) NOT NULL  COMMENT '예: \"11~13기 묶음권\"',
    note          TEXT NULL              COMMENT '운영 메모',
    created_by    INT UNSIGNED NULL      COMMENT 'admins.id',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mp_user_id (user_id),
    KEY idx_mp_product (product_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

echo "\n[2] multipass_cohorts 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS multipass_cohorts (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pass_id           INT UNSIGNED NOT NULL,
    cohort_id         INT UNSIGNED NOT NULL,
    coupon_issued     TINYINT(1) NOT NULL DEFAULT 0,
    coupon_issued_at  DATETIME NULL,
    coupon_issued_by  INT UNSIGNED NULL,
    note              VARCHAR(255) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pass_cohort (pass_id, cohort_id),
    KEY idx_mpc_cohort (cohort_id),
    CONSTRAINT fk_mpc_pass   FOREIGN KEY (pass_id)   REFERENCES multipass(id) ON DELETE CASCADE,
    CONSTRAINT fk_mpc_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

echo "\n[검증] SHOW CREATE TABLE multipass\n";
$row = $db->query('SHOW CREATE TABLE multipass')->fetch();
echo $row['Create Table'] . "\n";

echo "\n[검증] SHOW CREATE TABLE multipass_cohorts\n";
$row = $db->query('SHOW CREATE TABLE multipass_cohorts')->fetch();
echo $row['Create Table'] . "\n";

echo "\n=== 완료 ===\n";
