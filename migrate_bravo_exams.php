<?php
/**
 * Migration: BRAVO 2차 슬라이스 — bravo_exams 테이블
 * 실행: php migrate_bravo_exams.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS. 추가형(기존 테이블 미수정).
 */
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: bravo_exams ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_exams (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title             VARCHAR(120) NOT NULL,
    bravo_level       TINYINT UNSIGNED NOT NULL COMMENT '1/2/3 (bravo_levels.level)',
    exam_mode         ENUM('period','always') NOT NULL DEFAULT 'period' COMMENT '기간제/상시',
    start_at          DATETIME DEFAULT NULL COMMENT '응시 시작 (period 필수)',
    end_at            DATETIME DEFAULT NULL COMMENT '응시 종료 (period 필수)',
    result_release_at DATETIME DEFAULT NULL COMMENT '결과 발표일 (period 필수)',
    attempt_limit     TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '응시 횟수',
    target_type       ENUM('all','cohort') NOT NULL DEFAULT 'all',
    target_cohort_id  INT UNSIGNED DEFAULT NULL COMMENT 'cohort 일 때 cohorts.id',
    status            ENUM('preparing','open','closed','released') NOT NULL DEFAULT 'preparing' COMMENT '준비중/오픈/종료/결과발표 (수동)',
    created_by        INT UNSIGNED DEFAULT NULL COMMENT '생성 admin id',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_bravo_exams_status (status),
    KEY idx_bravo_exams_cohort (target_cohort_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_exams 생성 완료\n";

echo "\n=== Migration 완료 ===\n";
