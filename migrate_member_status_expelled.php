<?php
/**
 * bootcamp_members.member_status ENUM 에 'expelled' 추가.
 *
 * 사용: php migrate_member_status_expelled.php
 *
 * 멱등: 현재 enum 정의 검사 → 'expelled' 이미 있으면 skip.
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "== bootcamp_members.member_status enum 확장 ==\n";

$colStmt = $db->prepare("
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'bootcamp_members'
      AND COLUMN_NAME = 'member_status'
");
$colStmt->execute();
$currentType = (string)$colStmt->fetchColumn();

if ($currentType === '') {
    echo "FAIL: bootcamp_members.member_status 컬럼을 찾을 수 없음\n";
    exit(1);
}

echo "현재: {$currentType}\n";

if (stripos($currentType, "'expelled'") !== false) {
    echo "skip: enum 에 'expelled' 이미 존재\n";
    exit(0);
}

$db->exec("
    ALTER TABLE bootcamp_members
      MODIFY COLUMN member_status
        ENUM('active','leaving','out_of_group_management','refunded','expelled')
        NOT NULL DEFAULT 'active'
");

$colStmt->execute();
$afterType = (string)$colStmt->fetchColumn();
echo "변경: {$afterType}\n";

if (stripos($afterType, "'expelled'") === false) {
    echo "FAIL: ALTER 후에도 'expelled' 가 enum 에 없음\n";
    exit(1);
}
echo "PASS\n";
