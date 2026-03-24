<?php
/**
 * boot.soritune.com - Database Migration: Lecture Events
 * - lecture_events: 1회성 이벤트 (특강 스케줄과 별도)
 *
 * Run once: php migrate_lecture_events.php
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== boot.soritune.com DB Migration: Lecture Events ===\n\n";

echo "[1] lecture_events 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS lecture_events (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cohort_id           INT UNSIGNED NOT NULL COMMENT '기수 ID',
    coach_admin_id      INT UNSIGNED NOT NULL COMMENT '담당 코치 admin ID',
    stage               TINYINT UNSIGNED DEFAULT NULL COMMENT '1, 2, or NULL(전체)',
    event_date          DATE NOT NULL COMMENT '이벤트 날짜',
    start_time          TIME NOT NULL,
    end_time            TIME NOT NULL,
    title               VARCHAR(200) NOT NULL COMMENT '직접 입력 제목',
    color               VARCHAR(20) NOT NULL DEFAULT 'coral' COMMENT '캘린더 칩 색상',
    host_account        ENUM('coach1','coach2') NOT NULL,
    zoom_join_url       VARCHAR(500) DEFAULT NULL,
    zoom_start_url      VARCHAR(500) DEFAULT NULL,
    zoom_meeting_id     VARCHAR(100) DEFAULT NULL,
    zoom_password       VARCHAR(100) DEFAULT NULL,
    zoom_status         ENUM('pending','ready','failed') NOT NULL DEFAULT 'pending',
    zoom_error_message  VARCHAR(500) DEFAULT NULL,
    status              ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    created_by          INT UNSIGNED NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_le_cohort_date (cohort_id, event_date),
    KEY idx_le_date_status (event_date, status),
    KEY idx_le_coach (coach_admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

echo "\n=== Migration Complete ===\n";
