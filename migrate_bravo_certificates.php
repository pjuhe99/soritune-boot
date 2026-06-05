<?php
/**
 * Migration: BRAVO 8차 슬라이스 — bravo_certificates (결과 발표·인증서)
 * 실행: php migrate_bravo_certificates.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS. 추가형(기존 테이블 미수정).
 * 인증서 행은 영구 보존 — bravoExamDelete cascade 에서 의도적으로 제외 (cert_no 진위 확인 근거).
 */
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: bravo_certificates ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_certificates (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id  INT UNSIGNED NOT NULL COMMENT 'bravo_attempts.id — 응시당 1발급 (FK 없음, 영구 보존)',
    cert_no     VARCHAR(40) NOT NULL COMMENT 'BRAVO{level}-{YYYYMMDD}-{seq4} (예: BRAVO2-20260612-0001)',
    member_name VARCHAR(50) NOT NULL COMMENT '발급 시점 회원명 스냅샷 (개명 후에도 인증서 불변)',
    bravo_level TINYINT UNSIGNED NOT NULL COMMENT '등급 스냅샷',
    passed_on   DATE NOT NULL COMMENT '합격일 = exam.result_release_at 의 날짜 (NULL 이면 발급일)',
    issued_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bc_attempt (attempt_id),
    UNIQUE KEY uk_bc_cert_no (cert_no),
    KEY idx_bc_level_date (bravo_level, passed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_certificates 생성 완료\n";

echo "\n=== Migration 완료 ===\n";
