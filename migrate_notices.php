<?php
/**
 * boot.soritune.com - DB Migration: notices 테이블 생성.
 *
 * 멱등: information_schema 조회 후 테이블 없을 때만 CREATE.
 *       이미 존재 시 created_at/updated_at 의 DEFAULT/ON UPDATE 도 보정.
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

/**
 * notices.<col> 이 CURRENT_TIMESTAMP default (+ 필요 시 ON UPDATE) 를 갖고 있는지 검사.
 * $extraNeeded='' 이면 EXTRA 무시 (created_at 용).
 * $extraNeeded='on update CURRENT_TIMESTAMP' 이면 EXTRA 내 해당 토큰 포함 여부 확인 (updated_at 용).
 */
function ensureTimestampDefault(PDO $db, string $dbName, string $col, string $extraNeeded): bool {
    $stmt = $db->prepare("
        SELECT COLUMN_DEFAULT, EXTRA
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'notices' AND COLUMN_NAME = ?
    ");
    $stmt->execute([$dbName, $col]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $hasDefault = (stripos((string)$row['COLUMN_DEFAULT'], 'current_timestamp') !== false);
    $hasExtra   = ($extraNeeded === '' || stripos((string)$row['EXTRA'], $extraNeeded) !== false);
    return $hasDefault && $hasExtra;
}

if (tableExists($db, $dbName, 'notices')) {
    echo "  - notices 테이블 이미 존재 (skip)\n";
    // ── 기존 테이블 보정: created_at/updated_at 에 CURRENT_TIMESTAMP default/on-update 보장 ──
    if (!ensureTimestampDefault($db, $dbName, 'created_at', '')) {
        $db->exec("ALTER TABLE notices MODIFY COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        echo "  - notices.created_at DEFAULT CURRENT_TIMESTAMP 보정\n";
    }
    if (!ensureTimestampDefault($db, $dbName, 'updated_at', 'on update CURRENT_TIMESTAMP')) {
        $db->exec("ALTER TABLE notices MODIFY COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        echo "  - notices.updated_at DEFAULT+ON UPDATE 보정\n";
    }
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
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notices'
     ORDER BY ORDINAL_POSITION
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    printf("  %-25s %-25s NULL=%s DEFAULT=%s\n",
        $c['COLUMN_NAME'], $c['COLUMN_TYPE'], $c['IS_NULLABLE'], $c['COLUMN_DEFAULT'] ?? '(null)');
}
echo "\n완료.\n";
