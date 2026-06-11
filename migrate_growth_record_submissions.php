<?php
/**
 * boot.soritune.com - 성장기록 제출(growth_record_submissions) 마이그
 * - growth_record_submissions 테이블 생성 (활성 제출 1회 unique 보장)
 * - system_contents에 토글/마감/가이드 키 3개 seed
 * 스펙: docs/superpowers/specs/2026-06-11-growth-record-submission-design.md
 *
 * 실행: php migrate_growth_record_submissions.php
 * Dry-run: php migrate_growth_record_submissions.php --dry-run
 */

require_once __DIR__ . '/public_html/config.php';

$dryRun = in_array('--dry-run', $argv);
$db = getDB();

echo "=== Growth Record Submissions Migration" . ($dryRun ? " [DRY-RUN]" : "") . " ===\n\n";

try {
    if (!$dryRun) $db->beginTransaction();

    echo "[1] growth_record_submissions 테이블 생성...\n";
    $stmt = $db->query("SHOW TABLES LIKE 'growth_record_submissions'");
    if ($stmt->fetch()) {
        echo "  - 이미 존재\n";
    } else {
        $sql = "CREATE TABLE growth_record_submissions (
            id                INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            member_id         INT UNSIGNED NOT NULL,
            cohort_id         INT UNSIGNED NOT NULL,
            url               VARCHAR(500) NOT NULL,
            before_file       VARCHAR(255) NOT NULL,
            after_file        VARCHAR(255) NOT NULL,
            before_orig_name  VARCHAR(255) NOT NULL,
            after_orig_name   VARCHAR(255) NOT NULL,
            before_mime       VARCHAR(50) NOT NULL,
            after_mime        VARCHAR(50) NOT NULL,
            consent_agreed_at DATETIME NOT NULL,
            submitted_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cancelled_at      DATETIME NULL,
            cancelled_by      INT UNSIGNED NULL,
            cancel_reason     VARCHAR(255) NULL,
            active_member_id  INT UNSIGNED AS (IF(cancelled_at IS NULL, member_id, NULL)) PERSISTENT,
            UNIQUE KEY uq_active_member (active_member_id),
            INDEX idx_submitted_at (submitted_at DESC),
            INDEX idx_member (member_id),
            INDEX idx_cohort (cohort_id),
            CONSTRAINT fk_growth_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id),
            CONSTRAINT fk_growth_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$dryRun) $db->exec($sql);
        echo "  - " . ($dryRun ? "생성 예정" : "생성 완료") . "\n";
    }

    echo "[2] system_contents 시드...\n";
    $guide = <<<MD
## 🌱 성장기록 미션 안내

5주간의 훈련 전후 변화를 **후기 글 + 사진 + Before/After 음성**으로 남겨주세요.
미션 완료 시 **현재 수강 기수 단계의 VOD가 5주 연장**됩니다. (제출 데이터 취합 후 **6월 17일(수) 일괄 반영**)

**제출 마감: 2026년 6월 16일(화)까지**

### 1) 후기 작성 (카페/블로그)

기존 후기 안내 기준대로 카페 또는 블로그에 작성해주세요. **직접 촬영/캡처한 사진 3장 이상 필수.**

아래 질문을 참고해 작성하면 좋아요:
1. 부트캠프 수강 전, 영어 소리에 대한 나의 고민은 무엇이었나요?
2. 5주간 훈련하면서 스스로 느낀 가장 큰 변화는 무엇인가요?
3. 다가올 13기 수강을 고민하는 동료들에게 선배로서 따뜻한 한마디를 남겨주세요!

### 2) Before / After 음성

- **Before 소리**: 소리튠영어를 막 시작했을 때의 가공되지 않은 소리
  (12기가 첫 수강이라면 1주차 말까미션 음성, 기존 수강생이라면 소리튠 시작 전 녹음 파일)
- **After 소리**: 이번 기수 진행 중 가장 자신 있는 과제 음성, 또는 5주차 말까미션 음성

음성 2개는 카페/블로그 글에도 첨부하고, **아래에서 파일로도 제출**해주세요.
카페/블로그 첨부 파일명은 `12기_닉네임_before`, `12기_닉네임_after` 형식을 권장합니다.
(아래에서 제출하는 파일은 시스템이 자동으로 이름을 정리하니 파일명을 맞추지 않아도 됩니다.)
MD;
    $seeds = [
        ['growth_record_enabled', 'on'],
        ['growth_record_deadline', '2026-06-16 23:59:59'],
        ['growth_record_guide', $guide],
    ];
    foreach ($seeds as [$key, $val]) {
        $check = $db->prepare("SELECT id FROM system_contents WHERE content_key = ?");
        $check->execute([$key]);
        if ($check->fetch()) {
            echo "  - {$key} : 이미 존재\n";
        } else {
            if (!$dryRun) {
                $db->prepare("INSERT INTO system_contents (content_key, content_markdown) VALUES (?, ?)")
                   ->execute([$key, $val]);
            }
            echo "  - {$key} : " . ($dryRun ? "삽입 예정" : "삽입 완료") . "\n";
        }
    }

    echo "[3] 검증...\n";
    $check = $db->query("SHOW TABLES LIKE 'growth_record_submissions'")->fetch();
    echo "  - growth_record_submissions 테이블: " . ($check ? "OK" : ($dryRun ? "(dry-run)" : "MISSING")) . "\n";

    if (!$dryRun && $db->inTransaction()) $db->commit();
    echo "\n완료" . ($dryRun ? " (dry-run)" : "") . ".\n";
} catch (Throwable $e) {
    if (!$dryRun && $db->inTransaction()) $db->rollBack();
    echo "\n실패: " . $e->getMessage() . "\n";
    exit(1);
}
