<?php
/**
 * boot.soritune.com - Database Migration V2
 * - admin_roles 테이블 (다중 role 지원)
 * - cohorts 테이블
 * - tasks/guides ENUM 확장 (subhead1, subhead2)
 *
 * Run once: php migrate_v2.php
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== boot.soritune.com DB Migration V2 ===\n\n";

// 1. admin_roles 테이블 생성
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_roles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id INT UNSIGNED NOT NULL,
            role ENUM('leader','coach','head','subhead1','subhead2','operation') NOT NULL,
            UNIQUE KEY uk_admin_role (admin_id, role),
            CONSTRAINT fk_admin_roles_admin FOREIGN KEY (admin_id)
                REFERENCES admins(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] admin_roles 테이블 생성\n";
} catch (PDOException $e) {
    echo "[FAIL] admin_roles: {$e->getMessage()}\n";
}

// 2. 기존 admins.role 데이터를 admin_roles로 마이그레이션
try {
    $stmt = $db->exec("
        INSERT IGNORE INTO admin_roles (admin_id, role)
        SELECT id, role FROM admins
    ");
    echo "[OK] 기존 role 데이터 마이그레이션 ({$stmt}건)\n";
} catch (PDOException $e) {
    echo "[FAIL] role 마이그레이션: {$e->getMessage()}\n";
}

// 3. tasks.role ENUM 확장
try {
    $db->exec("
        ALTER TABLE tasks MODIFY role ENUM('leader','coach','head','subhead1','subhead2','operation') NOT NULL
    ");
    echo "[OK] tasks.role ENUM 확장\n";
} catch (PDOException $e) {
    echo "[FAIL] tasks.role: {$e->getMessage()}\n";
}

// 4. guides.role ENUM 확장
try {
    $db->exec("
        ALTER TABLE guides MODIFY role ENUM('leader','coach','head','subhead1','subhead2','operation') NOT NULL
    ");
    echo "[OK] guides.role ENUM 확장\n";
} catch (PDOException $e) {
    echo "[FAIL] guides.role: {$e->getMessage()}\n";
}

// 5. cohorts 테이블 생성
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS cohorts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cohort VARCHAR(30) NOT NULL UNIQUE COMMENT '기수명 (예: 1기)',
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] cohorts 테이블 생성\n";
} catch (PDOException $e) {
    echo "[FAIL] cohorts: {$e->getMessage()}\n";
}

echo "\nMigration V2 complete.\n";
