<?php
/**
 * boot.soritune.com - Group Assignment Migration
 * 조 배정 기능을 위한 DB 스키마 변경
 *
 * 실행: php migrate_group_assignment.php
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Group Assignment Migration ===\n\n";

// ──────────────────────────────────────────────
// 1. bootcamp_groups 테이블 확장
// ──────────────────────────────────────────────
echo "[1] bootcamp_groups 테이블 확장...\n";

$cols = array_column($db->query("SHOW COLUMNS FROM bootcamp_groups")->fetchAll(), 'Field');

if (!in_array('stage_no', $cols)) {
    $db->exec("ALTER TABLE bootcamp_groups ADD COLUMN stage_no TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=1단계, 2=2단계' AFTER name");
    echo "  - stage_no 컬럼 추가 완료\n";
} else {
    echo "  - stage_no 컬럼 이미 존재\n";
}

if (!in_array('leader_member_id', $cols)) {
    $db->exec("ALTER TABLE bootcamp_groups ADD COLUMN leader_member_id INT UNSIGNED DEFAULT NULL COMMENT '조장 member_id' AFTER stage_no");
    echo "  - leader_member_id 컬럼 추가 완료\n";
} else {
    echo "  - leader_member_id 컬럼 이미 존재\n";
}

if (!in_array('status', $cols)) {
    $db->exec("ALTER TABLE bootcamp_groups ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|archived' AFTER leader_member_id");
    echo "  - status 컬럼 추가 완료\n";
} else {
    echo "  - status 컬럼 이미 존재\n";
}

// kakao_link 컬럼 확인 (기존에 있을 수 있음)
if (!in_array('kakao_link', $cols)) {
    $db->exec("ALTER TABLE bootcamp_groups ADD COLUMN kakao_link VARCHAR(255) DEFAULT NULL AFTER status");
    echo "  - kakao_link 컬럼 추가 완료\n";
}

// ──────────────────────────────────────────────
// 2. bootcamp_members 테이블 확장
// ──────────────────────────────────────────────
echo "\n[2] bootcamp_members 테이블 확장...\n";

$mcols = array_column($db->query("SHOW COLUMNS FROM bootcamp_members")->fetchAll(), 'Field');

if (!in_array('group_assigned_at', $mcols)) {
    $db->exec("ALTER TABLE bootcamp_members ADD COLUMN group_assigned_at DATETIME DEFAULT NULL COMMENT '조 배정 시각' AFTER group_id");
    // 기존에 group_id가 있는 회원은 현재 시각으로 설정
    $db->exec("UPDATE bootcamp_members SET group_assigned_at = NOW() WHERE group_id IS NOT NULL");
    echo "  - group_assigned_at 컬럼 추가 완료\n";
} else {
    echo "  - group_assigned_at 컬럼 이미 존재\n";
}

// ──────────────────────────────────────────────
// 3. 기존 그룹에 leader_member_id 동기화
// ──────────────────────────────────────────────
echo "\n[3] 기존 그룹의 leader_member_id 동기화...\n";

$updated = $db->exec("
    UPDATE bootcamp_groups bg
    SET bg.leader_member_id = (
        SELECT bm.id FROM bootcamp_members bm
        WHERE bm.group_id = bg.id AND bm.member_role = 'leader' AND bm.is_active = 1
        LIMIT 1
    )
    WHERE bg.leader_member_id IS NULL
");
echo "  - {$updated}개 그룹 동기화 완료\n";

echo "\n=== Migration Complete ===\n";
