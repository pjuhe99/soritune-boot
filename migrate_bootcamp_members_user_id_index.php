<?php
/**
 * boot.soritune.com — Database Migration: bootcamp_members.user_id 인덱스
 *
 * 코인 cross-cohort view (spec 2026-05-11) 의 sibling lookup 핫패스를 위해
 * `user_id` 단일 컬럼 인덱스를 추가한다. findCoinSiblingMemberIds 가 매 로그인 /
 * check_session / dashboard 호출마다 user_id 로 WHERE 조회를 하기 때문에,
 * cohort 전체 row 를 스캔하지 않도록 인덱스가 필요하다.
 *
 * Run once: php migrate_bootcamp_members_user_id_index.php
 * Idempotent (이미 인덱스 있으면 skip).
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== boot.soritune.com DB Migration: bootcamp_members.user_id index ===\n\n";

echo "[1] 기존 idx_bm_user_id 인덱스 존재 여부 확인...\n";
$existing = $db->query("
    SELECT INDEX_NAME
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'bootcamp_members'
      AND INDEX_NAME = 'idx_bm_user_id'
    LIMIT 1
")->fetch();

if ($existing) {
    echo "    이미 존재 — skip.\n";
} else {
    echo "    없음 — ALTER TABLE 으로 추가...\n";
    $db->exec("ALTER TABLE bootcamp_members ADD INDEX idx_bm_user_id (user_id)");
    echo "    OK\n";
}

echo "\n[2] 검증 — EXPLAIN 으로 user_id 조회가 인덱스 사용 확인...\n";
$rows = $db->query("
    EXPLAIN SELECT id, cohort_id FROM bootcamp_members
    WHERE user_id = 'oh_nakazawa' AND cohort_id < 12
")->fetchAll();
foreach ($rows as $r) {
    echo "    table={$r['table']} type={$r['type']} key={$r['key']} rows={$r['rows']}\n";
}

echo "\nDone.\n";
