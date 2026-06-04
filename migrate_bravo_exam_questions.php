<?php
/**
 * Migration: BRAVO 5차 슬라이스 — bravo_exam_questions (시험↔문제 N:M 배정)
 * 실행: php migrate_bravo_exam_questions.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS. 추가형(기존 테이블 미수정).
 */
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: bravo_exam_questions ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_exam_questions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id       INT UNSIGNED NOT NULL COMMENT 'bravo_exams.id',
    question_id   INT UNSIGNED NOT NULL COMMENT 'bravo_questions.id',
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '제시 순서 (저장 시 제출 리스트 인덱스)',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_beq_exam_question (exam_id, question_id),
    KEY idx_beq_exam (exam_id),
    KEY idx_beq_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_exam_questions 생성 완료\n";

echo "\n=== Migration 완료 ===\n";
