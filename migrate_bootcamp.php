<?php
/**
 * boot.soritune.com - Bootcamp System Migration
 * 부트캠프 핵심 기능 DB 스키마 생성
 *
 * 실행: php migrate_bootcamp.php
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Bootcamp System Migration ===\n\n";

// ──────────────────────────────────────────────
// 1. 기존 cohorts 테이블 확장
// ──────────────────────────────────────────────
echo "[1] cohorts 테이블 확장...\n";

// code 컬럼 추가
$cols = $db->query("SHOW COLUMNS FROM cohorts")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('code', $cols)) {
    $db->exec("ALTER TABLE cohorts ADD COLUMN code VARCHAR(30) NULL AFTER cohort");
    // 기존 데이터: cohort 값을 code로 복사
    $db->exec("UPDATE cohorts SET code = cohort WHERE code IS NULL");
    $db->exec("ALTER TABLE cohorts MODIFY COLUMN code VARCHAR(30) NOT NULL");
    $db->exec("ALTER TABLE cohorts ADD UNIQUE KEY uk_cohort_code (code)");
    echo "  - code 컬럼 추가 완료\n";
} else {
    echo "  - code 컬럼 이미 존재\n";
}

if (!in_array('is_active', $cols)) {
    $db->exec("ALTER TABLE cohorts ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER end_date");
    echo "  - is_active 컬럼 추가 완료\n";
} else {
    echo "  - is_active 컬럼 이미 존재\n";
}

// ──────────────────────────────────────────────
// 2. bootcamp_groups
// ──────────────────────────────────────────────
echo "\n[2] bootcamp_groups 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bootcamp_groups (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cohort_id   INT UNSIGNED NOT NULL,
    name        VARCHAR(50) NOT NULL,
    code        VARCHAR(30) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_cohort_group_code (cohort_id, code),
    CONSTRAINT fk_bg_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 3. bootcamp_members
// ──────────────────────────────────────────────
echo "\n[3] bootcamp_members 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bootcamp_members (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED DEFAULT NULL COMMENT '기존 members 테이블 FK (연결 가능)',
    cohort_id   INT UNSIGNED NOT NULL,
    group_id    INT UNSIGNED DEFAULT NULL,
    nickname    VARCHAR(50) NOT NULL,
    real_name   VARCHAR(50) DEFAULT NULL,
    member_role ENUM('member','leader','subleader') NOT NULL DEFAULT 'member',
    stage_no    TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=1단계, 2=2단계',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    joined_at   DATE DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_bm_cohort (cohort_id),
    KEY idx_bm_group (group_id),
    KEY idx_bm_stage (stage_no),
    KEY idx_bm_nickname (nickname),
    KEY idx_bm_user (user_id),
    CONSTRAINT fk_bm_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
    CONSTRAINT fk_bm_group FOREIGN KEY (group_id) REFERENCES bootcamp_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 4. mission_types
// ──────────────────────────────────────────────
echo "\n[4] mission_types 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS mission_types (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code          VARCHAR(50) NOT NULL,
    name          VARCHAR(100) NOT NULL,
    description   TEXT DEFAULT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_mt_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 5. mission_score_rules
// ──────────────────────────────────────────────
echo "\n[5] mission_score_rules 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS mission_score_rules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cohort_id       INT UNSIGNED DEFAULT NULL COMMENT 'NULL=전체 기수 공통',
    stage_no        TINYINT UNSIGNED DEFAULT NULL COMMENT 'NULL=전체 단계 공통',
    mission_type_id INT UNSIGNED NOT NULL,
    success_score   INT NOT NULL DEFAULT 1,
    fail_score      INT NOT NULL DEFAULT -1,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_msr_cohort (cohort_id),
    KEY idx_msr_mission (mission_type_id),
    CONSTRAINT fk_msr_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
    CONSTRAINT fk_msr_mission FOREIGN KEY (mission_type_id) REFERENCES mission_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 6. member_mission_checks
// ──────────────────────────────────────────────
echo "\n[6] member_mission_checks 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS member_mission_checks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id       INT UNSIGNED NOT NULL,
    cohort_id       INT UNSIGNED NOT NULL,
    group_id        INT UNSIGNED DEFAULT NULL,
    check_date      DATE NOT NULL,
    mission_type_id INT UNSIGNED NOT NULL,
    status          TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=성공, 0=실패',
    source          ENUM('manual','automation') NOT NULL DEFAULT 'manual',
    source_ref      VARCHAR(200) DEFAULT NULL COMMENT '외부 참조값 (n8n 실행id 등)',
    created_by      INT UNSIGNED DEFAULT NULL COMMENT 'admins.id',
    updated_by      INT UNSIGNED DEFAULT NULL COMMENT 'admins.id',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_member_date_mission (member_id, check_date, mission_type_id),
    KEY idx_mmc_cohort_date (cohort_id, check_date),
    KEY idx_mmc_group (group_id),
    KEY idx_mmc_mission (mission_type_id),
    CONSTRAINT fk_mmc_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmc_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE CASCADE,
    CONSTRAINT fk_mmc_group FOREIGN KEY (group_id) REFERENCES bootcamp_groups(id) ON DELETE SET NULL,
    CONSTRAINT fk_mmc_mission FOREIGN KEY (mission_type_id) REFERENCES mission_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 7. member_scores
// ──────────────────────────────────────────────
echo "\n[7] member_scores 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS member_scores (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id           INT UNSIGNED NOT NULL,
    current_score       INT NOT NULL DEFAULT 0,
    last_calculated_at  DATETIME DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_ms_member (member_id),
    CONSTRAINT fk_ms_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 8. score_logs
// ──────────────────────────────────────────────
echo "\n[8] score_logs 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS score_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id       INT UNSIGNED NOT NULL,
    score_change    INT NOT NULL,
    before_score    INT NOT NULL,
    after_score     INT NOT NULL,
    reason_type     VARCHAR(50) NOT NULL COMMENT 'mission_success, mission_fail, revival_adjustment, manual_adjustment, recalculation',
    reason_detail   VARCHAR(500) DEFAULT NULL,
    reference_table VARCHAR(50) DEFAULT NULL,
    reference_id    INT UNSIGNED DEFAULT NULL,
    created_by      INT UNSIGNED DEFAULT NULL COMMENT 'admins.id',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_sl_member (member_id),
    KEY idx_sl_created (created_at),
    KEY idx_sl_reason (reason_type),
    CONSTRAINT fk_sl_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 9. revival_logs
// ──────────────────────────────────────────────
echo "\n[9] revival_logs 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS revival_logs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id     INT UNSIGNED NOT NULL,
    before_score  INT NOT NULL,
    after_score   INT NOT NULL DEFAULT -7,
    note          TEXT DEFAULT NULL,
    created_by    INT UNSIGNED NOT NULL COMMENT 'admins.id',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_rl_member (member_id),
    KEY idx_rl_created (created_at),
    CONSTRAINT fk_rl_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 10. member_coin_balances
// ──────────────────────────────────────────────
echo "\n[10] member_coin_balances 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS member_coin_balances (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id     INT UNSIGNED NOT NULL,
    current_coin  INT NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_mcb_member (member_id),
    CONSTRAINT fk_mcb_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 11. coin_logs
// ──────────────────────────────────────────────
echo "\n[11] coin_logs 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS coin_logs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id     INT UNSIGNED NOT NULL,
    coin_change   INT NOT NULL,
    before_coin   INT NOT NULL,
    after_coin    INT NOT NULL,
    reason_type   VARCHAR(50) NOT NULL COMMENT 'leader_coin, study_open, study_join, completion_bonus, event_reward, manual_adjustment, redemption',
    reason_detail VARCHAR(500) DEFAULT NULL,
    created_by    INT UNSIGNED DEFAULT NULL COMMENT 'admins.id',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_cl_member (member_id),
    KEY idx_cl_created (created_at),
    KEY idx_cl_reason (reason_type),
    CONSTRAINT fk_cl_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 12. admin_action_logs
// ──────────────────────────────────────────────
echo "\n[12] admin_action_logs 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS admin_action_logs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_admin_id  INT UNSIGNED DEFAULT NULL COMMENT 'admins.id',
    action_type     VARCHAR(50) NOT NULL,
    target_table    VARCHAR(50) NOT NULL,
    target_id       INT UNSIGNED DEFAULT NULL,
    payload_json    JSON DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_aal_actor (actor_admin_id),
    KEY idx_aal_action (action_type),
    KEY idx_aal_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ──────────────────────────────────────────────
// 13. integration_api_keys (n8n 연동용)
// ──────────────────────────────────────────────
echo "\n[13] integration_api_keys 테이블...\n";

$db->exec("
CREATE TABLE IF NOT EXISTS integration_api_keys (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL COMMENT 'n8n-production 등',
    api_key     VARCHAR(64) NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_api_key (api_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// ══════════════════════════════════════════════
// SEED DATA
// ══════════════════════════════════════════════
echo "\n=== Seed Data ===\n\n";

// mission_types
echo "[Seed] mission_types...\n";
$missionTypes = [
    ['zoom_daily',    '줌특강/데일리미션', '줌특강 or 데일리 미션 참여', 1],
    ['inner33',       '내멋33 미션',      '내멋33 미션 수행',         2],
    ['speak_mission', '말까미션',         '말까미션 수행',             3],
    ['hamemmal',      '하멈말',           '하멈말 참여',              4],
    ['bookclub_join', '복클 참여',        '북클럽 참여',              5],
    ['bookclub_open', '복클 개설',        '북클럽 개설',              6],
];

$stmt = $db->prepare("
    INSERT IGNORE INTO mission_types (code, name, description, display_order)
    VALUES (?, ?, ?, ?)
");
foreach ($missionTypes as $mt) {
    $stmt->execute($mt);
}
echo "  - " . count($missionTypes) . "건 처리\n";

// mission_score_rules (공통 기본 규칙)
echo "\n[Seed] mission_score_rules (공통 기본)...\n";
$missionIds = $db->query("SELECT id, code FROM mission_types")->fetchAll(PDO::FETCH_KEY_PAIR);

$ruleStmt = $db->prepare("
    INSERT IGNORE INTO mission_score_rules (cohort_id, stage_no, mission_type_id, success_score, fail_score)
    VALUES (NULL, NULL, ?, ?, ?)
");
foreach ($missionIds as $id => $code) {
    // 기존 규칙이 있는지 확인
    $exists = $db->prepare("SELECT id FROM mission_score_rules WHERE cohort_id IS NULL AND stage_no IS NULL AND mission_type_id = ?");
    $exists->execute([$id]);
    if (!$exists->fetch()) {
        $ruleStmt->execute([$id, 1, -1]);
    }
}
echo "  - 완료\n";

// integration API key 생성
echo "\n[Seed] integration_api_keys...\n";
$exists = $db->query("SELECT id FROM integration_api_keys LIMIT 1")->fetch();
if (!$exists) {
    $apiKey = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO integration_api_keys (name, api_key) VALUES (?, ?)")
       ->execute(['n8n-production', $apiKey]);
    echo "  - API Key 생성: {$apiKey}\n";
    echo "  - (이 키를 n8n 설정에 사용하세요)\n";
} else {
    echo "  - 이미 존재\n";
}

echo "\n=== Migration 완료 ===\n";
