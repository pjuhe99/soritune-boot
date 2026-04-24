<?php
/**
 * boot.soritune.com - 후기 제출(review_submissions) 마이그
 * - review_submissions 테이블 생성
 * - system_contents에 토글/가이드 키 4개 seed (INSERT IGNORE)
 *
 * 실행: php migrate_review_submissions.php
 * Dry-run: php migrate_review_submissions.php --dry-run
 */

require_once __DIR__ . '/public_html/config.php';

$dryRun = in_array('--dry-run', $argv);
$db = getDB();

echo "=== Review Submissions Migration" . ($dryRun ? " [DRY-RUN]" : "") . " ===\n\n";

try {
    if (!$dryRun) $db->beginTransaction();

    // 1. review_submissions 테이블 생성
    echo "[1] review_submissions 테이블 생성...\n";
    $stmt = $db->query("SHOW TABLES LIKE 'review_submissions'");
    if ($stmt->fetch()) {
        echo "  - 이미 존재\n";
    } else {
        $sql = "CREATE TABLE review_submissions (
            id            INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            member_id     INT UNSIGNED NOT NULL,
            cycle_id      INT UNSIGNED NOT NULL,
            type          ENUM('cafe','blog') NOT NULL,
            url           VARCHAR(500) NOT NULL,
            coin_amount   INT NOT NULL DEFAULT 5,
            submitted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cancelled_at  DATETIME NULL,
            cancelled_by  INT UNSIGNED NULL,
            cancel_reason VARCHAR(255) NULL,
            INDEX idx_submitted_at (submitted_at DESC),
            INDEX idx_cycle_type (cycle_id, type),
            INDEX idx_member_cycle_type (member_id, cycle_id, type),
            CONSTRAINT fk_review_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id),
            CONSTRAINT fk_review_cycle  FOREIGN KEY (cycle_id)  REFERENCES coin_cycles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$dryRun) $db->exec($sql);
        echo "  - " . ($dryRun ? "생성 예정" : "생성 완료") . "\n";
    }

    // 2. system_contents 시드
    echo "[2] system_contents 시드...\n";
    $seeds = [
        ['review_cafe_enabled', 'on'],
        ['review_blog_enabled', 'on'],
        ['review_cafe_guide', "## 카페 후기 작성 안내\n\n1. 소리튠 공식 카페의 \"후기 게시판\"에 글을 올려주세요.\n2. 제목 또는 본문에 `#소리튠부트캠프12기` 해시태그를 포함해주세요.\n3. 본문에 학습 경험을 자유롭게 작성해주세요 (최소 3문장 권장).\n4. 작성한 글의 URL을 아래에 입력하면 **5코인**이 적립됩니다.\n\n※ 기수 중도 하차 시 적립된 코인은 지급되지 않습니다.\n※ 부실하거나 거짓으로 판단되는 후기는 운영자가 취소할 수 있으며, 이때 코인이 회수됩니다."],
        ['review_blog_guide', "## 블로그 후기 작성 안내\n\n1. 본인 블로그(네이버/티스토리/브런치 등)에 글을 올려주세요.\n2. 제목 또는 본문에 `#소리튠부트캠프12기` 해시태그를 포함해주세요.\n3. 본문에 학습 경험을 자유롭게 작성해주세요 (최소 3문장 권장).\n4. 작성한 글의 URL을 아래에 입력하면 **5코인**이 적립됩니다.\n\n※ 기수 중도 하차 시 적립된 코인은 지급되지 않습니다.\n※ 부실하거나 거짓으로 판단되는 후기는 운영자가 취소할 수 있으며, 이때 코인이 회수됩니다."],
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

    // 3. 검증
    echo "[3] 검증...\n";
    $check = $db->query("SHOW TABLES LIKE 'review_submissions'")->fetch();
    echo "  - review_submissions 테이블: " . ($check ? "OK" : "MISSING") . "\n";
    $keys = $db->query("SELECT content_key FROM system_contents WHERE content_key LIKE 'review_%'")
               ->fetchAll(PDO::FETCH_COLUMN);
    echo "  - system_contents 키: " . count($keys) . "개 (" . implode(', ', $keys) . ")\n";

    if (!$dryRun && $db->inTransaction()) $db->commit();
    echo "\n완료" . ($dryRun ? " (dry-run)" : "") . ".\n";
} catch (Throwable $e) {
    if (!$dryRun && $db->inTransaction()) $db->rollBack();
    echo "\n실패: " . $e->getMessage() . "\n";
    exit(1);
}
