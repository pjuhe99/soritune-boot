<?php
/**
 * boot.soritune.com - Notify 시스템 테이블 마이그레이션
 * 사용: php migrate_notify_tables.php
 * DEV/PROD 각각 한 번씩 실행. IF NOT EXISTS이므로 재실행 안전.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

$tables = [
    'notify_scenario_state' => "
        CREATE TABLE IF NOT EXISTS notify_scenario_state (
            scenario_key      VARCHAR(64)  NOT NULL PRIMARY KEY,
            is_active         TINYINT(1)   NOT NULL DEFAULT 0,
            is_running        TINYINT(1)   NOT NULL DEFAULT 0,
            running_since     DATETIME     NULL,
            last_run_at       DATETIME     NULL,
            last_run_status   VARCHAR(20)  NULL,
            last_batch_id     BIGINT       NULL,
            notes             TEXT         NULL,
            updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by        VARCHAR(64)  NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'notify_batch' => "
        CREATE TABLE IF NOT EXISTS notify_batch (
            id              BIGINT AUTO_INCREMENT PRIMARY KEY,
            scenario_key    VARCHAR(64)  NOT NULL,
            trigger_type    ENUM('schedule','manual','retry') NOT NULL,
            triggered_by    VARCHAR(64)  NULL,
            started_at      DATETIME     NOT NULL,
            finished_at     DATETIME     NULL,
            target_count    INT          NOT NULL DEFAULT 0,
            sent_count      INT          NOT NULL DEFAULT 0,
            failed_count    INT          NOT NULL DEFAULT 0,
            unknown_count   INT          NOT NULL DEFAULT 0,
            skipped_count   INT          NOT NULL DEFAULT 0,
            dry_run         TINYINT(1)   NOT NULL DEFAULT 0,
            status          ENUM('running','completed','partial','failed','no_targets') NOT NULL,
            error_message   TEXT         NULL,
            INDEX idx_scenario_started (scenario_key, started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'notify_message' => "
        CREATE TABLE IF NOT EXISTS notify_message (
            id                BIGINT AUTO_INCREMENT PRIMARY KEY,
            batch_id          BIGINT       NOT NULL,
            scenario_key      VARCHAR(64)  NOT NULL,
            row_key           VARCHAR(255) NOT NULL,
            phone             VARCHAR(20)  NOT NULL,
            name              VARCHAR(64)  NULL,
            template_id       VARCHAR(64)  NOT NULL,
            rendered_text     TEXT         NULL,
            channel_used      ENUM('alimtalk','lms','none') NOT NULL DEFAULT 'none',
            status            ENUM('queued','sent','failed','skipped','dry_run','unknown') NOT NULL DEFAULT 'queued',
            skip_reason       VARCHAR(64)  NULL,
            fail_reason       TEXT         NULL,
            solapi_message_id VARCHAR(64)  NULL,
            sent_at           DATETIME     NULL,
            processed_at      DATETIME     NULL,
            created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cooldown (scenario_key, phone, status, processed_at),
            INDEX idx_batch (batch_id),
            INDEX idx_solapi (solapi_message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
    'notify_preview' => "
        CREATE TABLE IF NOT EXISTS notify_preview (
            id            CHAR(32)     NOT NULL PRIMARY KEY,
            scenario_key  VARCHAR(64)  NOT NULL,
            dry_run       TINYINT(1)   NOT NULL,
            row_keys      JSON         NOT NULL,
            target_count  INT          NOT NULL,
            created_by    VARCHAR(64)  NOT NULL,
            created_at    DATETIME     NOT NULL,
            expires_at    DATETIME     NOT NULL,
            used_at       DATETIME     NULL,
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
];

foreach ($tables as $name => $sql) {
    try {
        $db->exec($sql);
        echo "OK   {$name}\n";
    } catch (Throwable $e) {
        echo "FAIL {$name}: " . $e->getMessage() . "\n";
        exit(1);
    }
}
echo "\nAll notify tables ready.\n";
