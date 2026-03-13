<?php
/**
 * Migration: member_history_stats 집계 테이블 생성
 *
 * 실행: php migrate_member_stats.php
 *
 * bootcamp_members와 독립된 집계 전용 테이블.
 * phone/user_id 기준으로 동일인의 크로스 cohort 통계를 저장한다.
 * 원본은 항상 bootcamp_members에 있으므로 이 테이블은 언제든 재생성 가능.
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: member_history_stats ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS member_history_stats (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone                       VARCHAR(20)  DEFAULT NULL,
    user_id                     VARCHAR(100) DEFAULT NULL,
    stage1_participation_count  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1단계 참여 cohort 수',
    stage2_participation_count  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '2단계 참여 cohort 수',
    completed_bootcamp_count    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '완주한 cohort 수 (종료+활성)',
    bravo_grade                 VARCHAR(10)  DEFAULT NULL COMMENT 'Bravo 1 / Bravo 2 / Bravo 3 / NULL',
    last_calculated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_mhs_phone    (phone),
    UNIQUE KEY uk_mhs_user_id  (user_id),
    KEY idx_mhs_bravo          (bravo_grade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "member_history_stats 테이블 생성 완료\n";
echo "\n=== Migration 완료 ===\n";
echo "다음 단계: php backfill_member_stats.php 실행\n";
