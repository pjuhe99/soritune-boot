<?php
/**
 * boot.soritune.com - Database Migration
 * Run once: php migrate.php
 * Then delete this file.
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

$tables = [
    // 1. admins
    "CREATE TABLE IF NOT EXISTS admins (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        login_id VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('leader', 'coach', 'head', 'operation') NOT NULL,
        cohort VARCHAR(30) DEFAULT NULL COMMENT 'NULL for operation',
        team VARCHAR(50) DEFAULT NULL COMMENT 'For leader',
        class_time VARCHAR(50) DEFAULT NULL COMMENT 'For coach',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_role (role),
        INDEX idx_cohort (cohort)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 2. members
    "CREATE TABLE IF NOT EXISTS members (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        login_id VARCHAR(50) DEFAULT NULL,
        phone VARCHAR(20) NOT NULL,
        cohort VARCHAR(30) NOT NULL,
        point INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        last_login_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_cohort (cohort),
        INDEX idx_name_phone (name, phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 3. tasks
    "CREATE TABLE IF NOT EXISTS tasks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        role ENUM('leader', 'coach', 'head', 'operation') NOT NULL,
        assignee_admin_id INT UNSIGNED DEFAULT NULL,
        completed TINYINT(1) NOT NULL DEFAULT 0,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        content_markdown LONGTEXT DEFAULT NULL,
        cohort VARCHAR(30) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_role_dates (role, start_date, end_date),
        INDEX idx_cohort (cohort),
        INDEX idx_overdue (end_date, completed),
        INDEX idx_assignee (assignee_admin_id),
        CONSTRAINT fk_tasks_assignee FOREIGN KEY (assignee_admin_id) REFERENCES admins(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 4. guides
    "CREATE TABLE IF NOT EXISTS guides (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        url VARCHAR(500) NOT NULL,
        role ENUM('leader', 'coach', 'head', 'operation') NOT NULL,
        note TEXT DEFAULT NULL,
        cohort VARCHAR(30) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_role_cohort (role, cohort)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 5. calendar
    "CREATE TABLE IF NOT EXISTS calendar (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        week_label VARCHAR(100) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        content TEXT DEFAULT NULL,
        cohort VARCHAR(30) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_dates_cohort (start_date, end_date, cohort)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 6. settings
    "CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(100) PRIMARY KEY,
        `value` TEXT NOT NULL,
        description VARCHAR(200) DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

echo "=== boot.soritune.com DB Migration ===\n\n";

foreach ($tables as $sql) {
    preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $sql, $m);
    $name = $m[1] ?? '?';
    try {
        $db->exec($sql);
        echo "[OK] {$name}\n";
    } catch (PDOException $e) {
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
    }
}

echo "\nMigration complete.\n";
