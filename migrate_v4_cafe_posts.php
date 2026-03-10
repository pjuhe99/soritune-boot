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

// 2. cafe_board_map 테이블 (menuId → mission_type_code 매핑)
echo "\n[2] cafe_board_map 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS cafe_board_map (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_id         VARCHAR(50) NOT NULL COMMENT '카페 게시판 menuId',
    board_name      VARCHAR(100) NOT NULL COMMENT '게시판 이름 (표시용)',
    board_type      VARCHAR(50) NOT NULL COMMENT 'mission_type_code (speak_mission, inner33, daily_mission 등)',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,

    UNIQUE KEY uk_menu_id (menu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 테이블 생성 완료\n";

// 초기 데이터 삽입
$maps = [
    ['322', '2.내맛33미션 인증(매일)', 'inner33'],
    ['288', '1.데일리미션 인증(매일)', 'daily_mission'],
    ['290', '[루크조]말까미션 5주차', 'speak_mission'],
];
$mapStmt = $db->prepare("
    INSERT INTO cafe_board_map (menu_id, board_name, board_type)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE board_name = VALUES(board_name), board_type = VALUES(board_type)
");
foreach ($maps as [$menuId, $boardName, $boardType]) {
    $mapStmt->execute([$menuId, $boardName, $boardType]);
    echo "  - {$menuId}: {$boardName} → {$boardType}\n";
}

echo "\n=== Migration V4 완료 ===\n";
