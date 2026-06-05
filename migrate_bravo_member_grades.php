<?php
/**
 * Migration: BRAVO 등급 단일화 — bravo_member_grades / bravo_grade_log + grandfather backfill
 * 실행: php migrate_bravo_member_grades.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS + backfill 은 기존 등급이 같거나 높으면 skip.
 * ⚠️ 운영 반영 시 git pull 보다 먼저 실행 (스펙 §11 — 새 코드는 이 테이블을 즉시 조회).
 */
require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/api/services/bravo_grades.php';

$db = getDB();

echo "=== Migration: bravo_member_grades / bravo_grade_log ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_member_grades (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_key       VARCHAR(120) NOT NULL COMMENT 'user_id ?: p:<phone> — bravoAttemptMemberKey 와 동일 규약',
    current_level    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=무등급, 1~3',
    extra_attempts_1 TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'B1 추가 응시 횟수 (관리자 부여 — 유료 정책 운영 수동)',
    extra_attempts_2 TINYINT UNSIGNED NOT NULL DEFAULT 0,
    extra_attempts_3 TINYINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bmg_member (member_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_member_grades 생성 완료\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_grade_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_key  VARCHAR(120) NOT NULL,
    from_level  TINYINT UNSIGNED NOT NULL,
    to_level    TINYINT UNSIGNED NOT NULL,
    source      ENUM('grandfather','exam_pass','self_demotion','admin_adjust') NOT NULL,
    ref_id      INT UNSIGNED NULL COMMENT 'exam_pass=exam_id, admin_adjust=admin_id, 그 외 NULL',
    note        VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bgl_member (member_key, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_grade_log 생성 완료\n";

$r = bravoGradeBackfillFromLegacy($db);
echo "grandfather backfill: applied {$r['applied']}, skipped {$r['skipped']}\n";

echo "\n=== Migration 완료 ===\n";
