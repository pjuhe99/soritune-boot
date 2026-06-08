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
    source_attempt_id INT UNSIGNED NULL COMMENT 'exam_pass 를 유발한 attempt.id — 재승급 멱등 기준(같은 attempt 재크레딧 차단, timestamp 비의존)',
    note        VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bgl_member (member_key, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_grade_log 생성 완료\n";

// 기존 테이블에 source_attempt_id 컬럼 보강 (멱등) — 재승급 멱등 기준을 timestamp→attempt_id 로 전환
$hasCol = $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bravo_grade_log' AND column_name = 'source_attempt_id'")->fetchColumn();
if ((int)$hasCol === 0) {
    $db->exec("ALTER TABLE bravo_grade_log ADD COLUMN source_attempt_id INT UNSIGNED NULL COMMENT 'exam_pass 를 유발한 attempt.id — 재승급 멱등 기준(같은 attempt 재크레딧 차단, timestamp 비의존)' AFTER ref_id");
    echo "bravo_grade_log.source_attempt_id 컬럼 추가\n";
} else {
    echo "bravo_grade_log.source_attempt_id 이미 존재\n";
}

// bravo_attempts.member_key 인덱스 (quota 누적 쿼리용) — 멱등
$has = $db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'bravo_attempts' AND index_name = 'idx_ba_member_key'")->fetchColumn();
if ((int)$has === 0) {
    $db->exec("ALTER TABLE bravo_attempts ADD KEY idx_ba_member_key (member_key)");
    echo "bravo_attempts.idx_ba_member_key 인덱스 추가\n";
} else {
    echo "bravo_attempts.idx_ba_member_key 이미 존재\n";
}

$r = bravoGradeBackfillFromLegacy($db);
echo "grandfather backfill: applied {$r['applied']}, skipped {$r['skipped']}\n";

echo "\n=== Migration 완료 ===\n";
