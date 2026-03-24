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

// 3. qr_sessions에 lecture_session_id 컬럼 추가
echo "\n[3] qr_sessions.lecture_session_id 컬럼 추가...\n";
$cols = $db->query("SHOW COLUMNS FROM qr_sessions LIKE 'lecture_session_id'")->fetchAll();
if (empty($cols)) {
    $db->exec("
        ALTER TABLE qr_sessions
        ADD COLUMN lecture_session_id INT UNSIGNED DEFAULT NULL
            COMMENT '연결된 강의 세션 (NULL이면 기타/수동 세션)'
            AFTER cohort_id,
        ADD KEY idx_qs_lecture (lecture_session_id)
    ");
    echo "  - 컬럼 추가 완료\n";

    // 기존 데이터 소급 매칭: 같은 코치 + 같은 날짜 + 같은 기수에서 시간이 가장 가까운 강의 매칭
    $db->exec("
        UPDATE qr_sessions qs
        JOIN (
            SELECT qs2.id AS qr_id, (
                SELECT ls.id
                FROM lecture_sessions ls
                WHERE ls.coach_admin_id = qs2.admin_id
                  AND ls.lecture_date = DATE(qs2.created_at)
                  AND ls.cohort_id = qs2.cohort_id
                  AND ls.status = 'active'
                ORDER BY ABS(TIMESTAMPDIFF(SECOND, ls.start_time, TIME(qs2.created_at))) ASC
                LIMIT 1
            ) AS matched_lecture_id
            FROM qr_sessions qs2
            WHERE qs2.session_type = 'attendance'
              AND qs2.admin_id > 0
        ) sub ON qs.id = sub.qr_id
        SET qs.lecture_session_id = sub.matched_lecture_id
        WHERE sub.matched_lecture_id IS NOT NULL
    ");
    $backfilled = $db->query("SELECT COUNT(*) FROM qr_sessions WHERE lecture_session_id IS NOT NULL")->fetchColumn();
    echo "  - 소급 매칭 완료: {$backfilled}건\n";
} else {
    echo "  - 이미 존재\n";
}

echo "\n=== Migration QR Attendance 완료 ===\n";
