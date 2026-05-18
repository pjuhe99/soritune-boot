<?php
/**
 * boot.soritune.com - DB Migration: notices 테이블 생성.
 *
 * 멱등: information_schema 조회 후 테이블 없을 때만 CREATE.
 *
 * 실행:
 *   cd /root/boot-dev   && php migrate_notices.php   (DEV)
 *   cd /root/boot-prod  && php migrate_notices.php   (PROD, 사용자 명시 후)
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/public_html/config.php';
$db = getDB();
$dbName = $db->query("SELECT DATABASE()")->fetchColumn();

echo "=== boot.soritune.com DB Migration: notices ===\n\n";
echo "DB: {$dbName}\n\n";

function tableExists(PDO $db, string $dbName, string $table): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    ");
    $stmt->execute([$dbName, $table]);
    return (bool)$stmt->fetchColumn();
}

if (tableExists($db, $dbName, 'notices')) {
    echo "  - notices 테이블 이미 존재 (skip)\n";
} else {
    // cohorts.id, admins.id 는 모두 `INT UNSIGNED` (기존 스키마 컨벤션).
    // FK 가 형성되려면 참조 컬럼 타입/부호/길이가 정확히 일치해야 하므로
    // cohort_id, created_by_admin_id (그리고 일관성 위해 PK id) 도 UNSIGNED 로 둔다.
    // (spec 본문의 `INT` 표기는 FK 요구사항을 만족하려면 `INT UNSIGNED` 로 읽어야 한다.)
    $db->exec("
        CREATE TABLE notices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cohort_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            body_markdown TEXT NOT NULL,
            is_visible TINYINT(1) NOT NULL DEFAULT 1,
            created_by_admin_id INT UNSIGNED NOT NULL,
            created_by_admin_name VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY idx_cohort_visible_created (cohort_id, is_visible, created_at),
            CONSTRAINT fk_notices_cohort  FOREIGN KEY (cohort_id) REFERENCES cohorts(id),
            CONSTRAINT fk_notices_admin   FOREIGN KEY (created_by_admin_id) REFERENCES admins(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  - notices 테이블 생성 완료\n";
}

echo "\n검증:\n";
$cols = $db->query("
    SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notices'
     ORDER BY ORDINAL_POSITION
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    printf("  %-25s %-25s NULL=%s\n",
        $c['COLUMN_NAME'], $c['COLUMN_TYPE'], $c['IS_NULLABLE']);
}
echo "\n완료.\n";
