<?php
/**
 * bootcamp_members.cafe_nickname 컬럼 추가 + 1회 백필.
 *
 * 사용: php migrate_cafe_nickname.php
 *
 * 멱등: 컬럼 존재 시 ALTER skip / `<>` 가드로 동일값 UPDATE skip.
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

echo "== bootcamp_members.cafe_nickname 마이그 ==\n";

if (!columnExists($db, 'bootcamp_members', 'cafe_nickname')) {
    echo "ALTER: cafe_nickname VARCHAR(100) NULL 추가...\n";
    $db->exec("
        ALTER TABLE bootcamp_members
          ADD COLUMN cafe_nickname VARCHAR(100) NULL DEFAULT NULL
            COMMENT '네이버 카페 닉네임 (cafe_posts.nickname 최신값으로 cron/upsert 시 동기화)'
          AFTER cafe_member_key
    ");
} else {
    echo "skip: cafe_nickname 이미 존재\n";
}

echo "백필: cafe_member_key 매핑된 회원의 최신 cafe_posts.nickname 으로 채움\n";
$db->beginTransaction();
try {
    $stmt = $db->prepare("
        UPDATE bootcamp_members bm
        JOIN (
          SELECT cp.member_key, cp.nickname
          FROM cafe_posts cp
          WHERE cp.nickname IS NOT NULL AND cp.member_key IS NOT NULL
          AND cp.id = (
            SELECT cp2.id FROM cafe_posts cp2
            WHERE cp2.member_key = cp.member_key AND cp2.nickname IS NOT NULL
            ORDER BY cp2.posted_at DESC, cp2.id DESC LIMIT 1
          )
        ) latest ON latest.member_key = bm.cafe_member_key
        SET bm.cafe_nickname = latest.nickname
        WHERE bm.cafe_member_key IS NOT NULL
          AND (bm.cafe_nickname IS NULL OR bm.cafe_nickname <> latest.nickname)
    ");
    $stmt->execute();
    $updated = $stmt->rowCount();
    $db->commit();
    echo "백필 완료: {$updated} row\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "FAIL: 백필 rollback — " . $e->getMessage() . "\n";
    exit(1);
}

$total    = (int)$db->query("SELECT COUNT(*) FROM bootcamp_members WHERE cafe_member_key IS NOT NULL")->fetchColumn();
$withNick = (int)$db->query("SELECT COUNT(*) FROM bootcamp_members WHERE cafe_member_key IS NOT NULL AND cafe_nickname IS NOT NULL")->fetchColumn();
echo "검증: cafe_member_key 매핑 {$total} / 그 중 cafe_nickname 채워진 {$withNick}\n";
echo "PASS\n";
