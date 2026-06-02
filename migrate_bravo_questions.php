<?php
/**
 * Migration: BRAVO 4차 슬라이스 — bravo_questions(문제은행) + bravo_exam_ot(시험별 OT)
 * 실행: php migrate_bravo_questions.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS. 추가형(기존 테이블 미수정).
 */
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: bravo_questions + bravo_exam_ot ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_questions (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_type           TINYINT UNSIGNED NOT NULL COMMENT '유형 1/2/3',
    bravo_level             TINYINT UNSIGNED NOT NULL COMMENT '1/2/3 (bravo_levels.level)',
    source                  VARCHAR(60) DEFAULT NULL COMMENT '출제 원천 (자유텍스트)',
    korean_text             TEXT NOT NULL COMMENT '한국어 제시 문장',
    english_text            TEXT NOT NULL COMMENT '기준 영어 정답 문장',
    target_chunks           VARCHAR(255) DEFAULT NULL COMMENT '타겟 청크',
    accepted_answers        TEXT DEFAULT NULL COMMENT '허용 정답 (1줄 1개)',
    reference_speech_sec    DECIMAL(4,1) DEFAULT NULL COMMENT '기준 발화 시간(초)',
    response_time_limit_sec DECIMAL(4,1) DEFAULT NULL COMMENT '반응 속도 기준(초)',
    difficulty              ENUM('easy','normal','hard') NOT NULL DEFAULT 'normal' COMMENT '쉬움/보통/어려움',
    is_active               TINYINT(1) NOT NULL DEFAULT 1 COMMENT '활성/비활성',
    created_by              INT UNSIGNED DEFAULT NULL COMMENT '생성 admin id',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_bq_type_level (question_type, bravo_level),
    KEY idx_bq_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_questions 생성 완료\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_exam_ot (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id       INT UNSIGNED NOT NULL COMMENT 'bravo_exams.id (1:1)',
    title         VARCHAR(120) DEFAULT NULL COMMENT 'OT 제목',
    intro_text    TEXT DEFAULT NULL COMMENT '전체 시험 안내문',
    video_url     VARCHAR(500) DEFAULT NULL COMMENT 'OT 영상 URL (선택)',
    type1_text    TEXT DEFAULT NULL COMMENT '유형 1 안내문',
    type2_text    TEXT DEFAULT NULL COMMENT '유형 2 안내문',
    type3_text    TEXT DEFAULT NULL COMMENT '유형 3 안내문',
    require_check TINYINT(1) NOT NULL DEFAULT 1 COMMENT '필수 확인 체크 ON/OFF',
    updated_by    INT UNSIGNED DEFAULT NULL COMMENT '마지막 수정 admin id',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bravo_exam_ot_exam (exam_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_exam_ot 생성 완료\n";

echo "\n=== Migration 완료 ===\n";
