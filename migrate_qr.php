<?php
/**
 * boot.soritune.com - Database Migration: QR Attendance
 * - qr_sessions: QR 출석 세션
 * - qr_attendance: QR 출석 기록 (member_mission_checks와 별도로 세션별 추적용)
 *
 * Run once: php migrate_qr.php
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== boot.soritune.com DB Migration: QR Attendance ===\n\n";

// 1. qr_sessions 테이블
echo "[1] qr_sessions 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS qr_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_code    VARCHAR(24) NOT NULL COMMENT '랜덤 세션 코드',
    admin_id        INT UNSIGNED NOT NULL COMMENT '생성한 코치 ID',
    cohort_id       INT UNSIGNED NOT NULL COMMENT '기수 ID',
    status          ENUM('active','expired','closed') NOT NULL DEFAULT 'active',
    expires_at      DATETIME NOT NULL COMMENT '만료 시각',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at       DATETIME DEFAULT NULL,

    UNIQUE KEY uk_session_code (session_code),
    KEY idx_qs_status_expires (status, expires_at),
    KEY idx_qs_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// 2. qr_attendance 테이블
echo "\n[2] qr_attendance 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS qr_attendance (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    qr_session_id   INT UNSIGNED NOT NULL,
    member_id       INT UNSIGNED NOT NULL,
    group_id        INT UNSIGNED NOT NULL,
    scanned_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address      VARCHAR(45) DEFAULT NULL,

    UNIQUE KEY uk_session_member (qr_session_id, member_id),
    KEY idx_qa_session (qr_session_id, scanned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

echo "\n=== Migration QR Attendance 완료 ===\n";
