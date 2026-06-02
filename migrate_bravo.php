<?php
/**
 * Migration: BRAVO 도전 시스템 1차 슬라이스 테이블
 * 실행: php migrate_bravo.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS + 시드 INSERT ... ON DUPLICATE KEY UPDATE
 * 기존 member_history_stats.bravo_grade 와 무관한 순수 추가형.
 */
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: BRAVO 도전 시스템 (기반) ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_levels (
    level                   TINYINT UNSIGNED PRIMARY KEY,
    name                    VARCHAR(20)  NOT NULL,
    required_review_count   TINYINT UNSIGNED NOT NULL,
    passing_score           TINYINT UNSIGNED NOT NULL,
    requires_previous_level TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'doc 7-2 권장 메타데이터. 1차 자동계산엔 미적용',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_levels 생성 완료\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_member_settings (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id               VARCHAR(100) NOT NULL,
    review_count_override TINYINT UNSIGNED DEFAULT NULL COMMENT 'NULL=자동(completed_bootcamp_count) 사용',
    granted_levels        SET('1','2','3') DEFAULT NULL COMMENT '수동부여 등급 (계산과 무관하게 응시 허용)',
    notes                 TEXT DEFAULT NULL,
    updated_by            INT UNSIGNED DEFAULT NULL COMMENT '마지막 수정 admin id',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bms_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_member_settings 생성 완료\n";

$seed = $db->prepare("
    INSERT INTO bravo_levels (level, name, required_review_count, passing_score, requires_previous_level)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        required_review_count = VALUES(required_review_count),
        passing_score = VALUES(passing_score),
        requires_previous_level = VALUES(requires_previous_level)
");
$seed->execute([1, 'BRAVO 1', 3, 50, 0]);
$seed->execute([2, 'BRAVO 2', 6, 65, 1]);
$seed->execute([3, 'BRAVO 3', 10, 80, 1]);
echo "bravo_levels 시드 완료 (3행)\n";

echo "\n=== Migration 완료 ===\n";
