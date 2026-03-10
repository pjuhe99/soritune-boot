<?php
/**
 * boot.soritune.com - Database Migration V4
 * - cafe_posts 테이블 생성 (카페 게시글 이력 저장)
 *
 * Run once: php migrate_v4_cafe_posts.php
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== boot.soritune.com DB Migration V4 (Cafe Posts) ===\n\n";

// 1. cafe_posts 테이블
echo "[1] cafe_posts 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS cafe_posts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cafe_article_id VARCHAR(50) NOT NULL COMMENT '카페 게시글 ID',
    title           VARCHAR(500) NOT NULL COMMENT '게시글 제목',
    member_key      VARCHAR(100) DEFAULT NULL COMMENT '작성자 카페 memberKey',
    nickname        VARCHAR(100) DEFAULT NULL COMMENT '작성자 카페 닉네임',
    board_type      VARCHAR(50) DEFAULT NULL COMMENT '게시판 구분 (speak_mission, inner33, daily_mission 등)',
    posted_at       DATETIME DEFAULT NULL COMMENT '게시글 업로드 일시',
    member_id       INT UNSIGNED DEFAULT NULL COMMENT '매핑된 bootcamp_members.id',
    mission_checked TINYINT(1) NOT NULL DEFAULT 0 COMMENT '미션 체크 반영 여부',
    raw_data        JSON DEFAULT NULL COMMENT 'n8n에서 보낸 원본 데이터',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_cafe_article (cafe_article_id),
    KEY idx_cp_member_key (member_key),
    KEY idx_cp_member_id (member_id),
    KEY idx_cp_board_type (board_type),
    KEY idx_cp_posted_at (posted_at),
    KEY idx_cp_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

echo "\n=== Migration V4 완료 ===\n";
