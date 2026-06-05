<?php
/**
 * Migration: BRAVO 6차 슬라이스 — bravo_attempts / bravo_answers (회원 응시)
 * 실행: php migrate_bravo_attempts.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS. 추가형(기존 테이블 미수정).
 * 업로드 디렉토리(bravo_uploads/)도 생성 — SELinux/소유권 안내 출력.
 */
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: bravo_attempts / bravo_answers ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_attempts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id       INT UNSIGNED NOT NULL COMMENT 'bravo_exams.id',
    member_key    VARCHAR(120) NOT NULL COMMENT '횟수 집계 키: user_id, 없으면 p:<전화> 폴백',
    member_id     INT UNSIGNED NOT NULL COMMENT '세션의 bootcamp_members.id (기수 맥락)',
    attempt_no    TINYINT UNSIGNED NOT NULL COMMENT '이 시험에서 이 사람의 n번째 응시 (1~attempt_limit)',
    question_ids  TEXT NOT NULL COMMENT '시작 시점 배정 문제 스냅샷 JSON [qid,...] (순서 보존)',
    status        ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
    ot_checked_at DATETIME NULL COMMENT '필수확인체크 시각 (require_check=1인 시험만)',
    started_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at  DATETIME NULL,
    UNIQUE KEY uk_ba_exam_user_no (exam_id, member_key, attempt_no),
    KEY idx_ba_member (member_id),
    KEY idx_ba_member_key (member_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_attempts 생성 완료\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_answers (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id   INT UNSIGNED NOT NULL COMMENT 'bravo_attempts.id',
    question_id  INT UNSIGNED NOT NULL COMMENT 'bravo_questions.id',
    seq          SMALLINT UNSIGNED NOT NULL COMMENT '제시 순서 (스냅샷 인덱스 0-base)',
    audio_path   VARCHAR(255) NOT NULL COMMENT '저장 루트 기준 상대경로',
    audio_mime   VARCHAR(50) NOT NULL COMMENT 'audio/webm | audio/mp4 | audio/ogg',
    duration_ms  INT UNSIGNED NULL COMMENT '녹음 길이 (클라이언트 보고값, 채점 참고)',
    retake_used  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '재녹음 사용 여부',
    answered_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bans_attempt_question (attempt_id, question_id),
    KEY idx_bans_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_answers 생성 완료\n";

// ── 업로드 디렉토리 (docroot 밖 — 직접 URL 접근 불가) ──
$uploadRoot = __DIR__ . '/bravo_uploads/answers';
if (!is_dir($uploadRoot)) {
    if (mkdir($uploadRoot, 0750, true)) {
        echo "업로드 디렉토리 생성: {$uploadRoot}\n";
    } else {
        echo "⚠️ 업로드 디렉토리 생성 실패: {$uploadRoot} — 수동 생성 필요\n";
    }
} else {
    echo "업로드 디렉토리 이미 존재: {$uploadRoot}\n";
}

echo "\n⚠️ 아래 셋업을 root 로 수동 실행하세요 (멱등):\n";
echo "  chown -R apache:apache " . __DIR__ . "/bravo_uploads\n";
echo "  semanage fcontext -a -t httpd_sys_rw_content_t '" . __DIR__ . "/bravo_uploads(/.*)?'\n";
echo "  restorecon -Rv " . __DIR__ . "/bravo_uploads\n";
echo "  (SELinux 미설정 시 PHP-FPM 쓰기 실패 — 과거 vhost 로그 디렉토리 사고 참조)\n";

echo "\n=== Migration 완료 ===\n";
