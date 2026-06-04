<?php
/**
 * Migration: BRAVO 7차 슬라이스 — bravo_answer_grades / bravo_attempt_grades (관리자 채점)
 * 실행: php migrate_bravo_grades.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS. 추가형(기존 테이블 미수정).
 */
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: bravo_answer_grades / bravo_attempt_grades ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_answer_grades (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    answer_id       INT UNSIGNED NOT NULL COMMENT 'bravo_answers.id',
    attempt_id      INT UNSIGNED NOT NULL COMMENT '비정규화 — 목록 집계용',
    accuracy        ENUM('correct','partial','wrong') NOT NULL COMMENT '정답도 (1/0.5/0)',
    chunk_ok        TINYINT(1) NOT NULL COMMENT '핵심청크 포함 (1/0)',
    response_rating ENUM('good','normal','poor') NOT NULL COMMENT '반응속도 (1/0.5/0)',
    fluency_rating  ENUM('good','normal','poor') NOT NULL COMMENT '유창성 (1/0.5/0)',
    completion_ok   TINYINT(1) NULL COMMENT '발화완성도 — B2/B3만, B1은 NULL',
    score           DECIMAL(5,2) NOT NULL COMMENT '판정 시점 환산 점수 스냅샷',
    n_denominator   SMALLINT UNSIGNED NOT NULL COMMENT '환산에 사용한 분모 N (확정 시 일관성 검증/재환산용)',
    memo            VARCHAR(255) NULL COMMENT '문항 메모',
    graded_by       INT UNSIGNED NOT NULL,
    graded_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bag_answer (answer_id),
    KEY idx_bag_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_answer_grades 생성 완료\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_attempt_grades (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id        INT UNSIGNED NOT NULL COMMENT 'bravo_attempts.id — 행 존재 = 확정',
    total_score       DECIMAL(5,2) NOT NULL COMMENT '확정 시점 합산 스냅샷',
    passing_score     DECIMAL(5,2) NOT NULL COMMENT '확정 시점 합격선 스냅샷',
    result            ENUM('pass','fail') NOT NULL,
    result_overridden TINYINT(1) NOT NULL DEFAULT 0,
    override_reason   VARCHAR(255) NULL COMMENT '오버라이드 시 필수',
    memo              TEXT NULL COMMENT '전체 채점 메모',
    confirmed_by      INT UNSIGNED NOT NULL,
    confirmed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_batg_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_attempt_grades 생성 완료\n";

echo "\n=== Migration 완료 ===\n";
