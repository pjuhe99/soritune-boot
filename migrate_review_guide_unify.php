<?php
/**
 * boot.soritune.com - 후기 가이드 통일 마이그
 * - review_guide 키 UPSERT (최종 문구)
 * - review_cafe_guide / review_blog_guide 삭제
 * - 삭제 전 기존 값 stdout 덤프 (수동 롤백용 백업)
 *
 * 실행: php migrate_review_guide_unify.php
 * Dry-run: php migrate_review_guide_unify.php --dry-run
 */

require_once __DIR__ . '/public_html/config.php';

$dryRun = in_array('--dry-run', $argv);
$db = getDB();

$newGuide = <<<'MD'
🟠 작성 위치
- 카페: 소리튠 공식 카페 "소리블록 부트캠프 경험담"
- 블로그: 본인 네이버 블로그/티스토리 등 (전체공개 필수)

🟠 필수 해시태그
- 제목 또는 본문에 아래 해시태그를 반드시 포함해주세요.
  #소리튠영어 #영어스피킹 #소리블록 #소리튠부트캠프 #소리튠부트캠프방법

🟠 분량/내용 기준
- 글자 수: 공백 포함 600자 이상 (학습 동기, 구체적 훈련 방법 혹은 팁, 변화된 점 포함)
- 사진 첨부: 직접 촬영/캡처한 이미지 3장 이상 필수

🟠 적립 안내
- 작성한 글의 URL을 아래 해당 칸(카페/블로그)에 입력하면 확인 후 5코인이 적립됩니다.
- 12기 수강생에 한해, 12기 코인으로 사용 가능합니다.

⚠️ 코인 회수 및 반려 기준 (필독)
아래 기준에 미달하면 적립된 코인이 회수될 수 있습니다.

- 분량 미달: 글자 수 600자 미만 또는 사진 3장 미만인 경우
- 부정 행위: 타인의 글/사진 도용, 적립 후 3개월 이내 삭제/비공개 전환
- 확인 불가: 링크 오류 또는 친구공개/비공개 글로 설정된 경우
MD;

echo "=== Review Guide Unify Migration" . ($dryRun ? " [DRY-RUN]" : "") . " ===\n\n";

try {
    // 0. 기존 값 백업 (stdout 덤프)
    echo "[0] 기존 review_cafe_guide / review_blog_guide 백업 (복원용 SQL):\n";
    $backup = $db->query("SELECT content_key, content_markdown FROM system_contents WHERE content_key IN ('review_cafe_guide','review_blog_guide')")->fetchAll();
    if (!$backup) {
        echo "  - 해당 키 없음 (이미 제거되었거나 최초 설치 상태).\n";
    }
    foreach ($backup as $row) {
        $escaped = str_replace("'", "''", $row['content_markdown']);
        echo "  -- {$row['content_key']}\n";
        echo "  INSERT INTO system_contents (content_key, content_markdown) VALUES ('{$row['content_key']}', '{$escaped}') ON DUPLICATE KEY UPDATE content_markdown=VALUES(content_markdown);\n";
    }
    echo "\n";

    if (!$dryRun) $db->beginTransaction();

    // 1. review_guide UPSERT
    echo "[1] review_guide UPSERT...\n";
    if (!$dryRun) {
        $db->prepare("
            INSERT INTO system_contents (content_key, content_markdown) VALUES ('review_guide', ?)
            ON DUPLICATE KEY UPDATE content_markdown = VALUES(content_markdown)
        ")->execute([$newGuide]);
    }
    echo "  - " . ($dryRun ? "upsert 예정" : "upsert 완료") . "\n";

    // 2. 구 키 DELETE
    echo "[2] review_cafe_guide / review_blog_guide 삭제...\n";
    if (!$dryRun) {
        $db->exec("DELETE FROM system_contents WHERE content_key IN ('review_cafe_guide','review_blog_guide')");
    }
    echo "  - " . ($dryRun ? "삭제 예정" : "삭제 완료") . "\n";

    // 3. 검증
    echo "[3] 검증...\n";
    $countNew = (int)$db->query("SELECT COUNT(*) FROM system_contents WHERE content_key = 'review_guide'")->fetchColumn();
    $countOld = (int)$db->query("SELECT COUNT(*) FROM system_contents WHERE content_key IN ('review_cafe_guide','review_blog_guide')")->fetchColumn();
    echo "  - review_guide: {$countNew}건 (기대: 1)\n";
    echo "  - 구 키 잔존: {$countOld}건 (기대: 0, dry-run이면 2 가능)\n";

    if (!$dryRun && $db->inTransaction()) $db->commit();
    echo "\n완료" . ($dryRun ? " (dry-run)" : "") . ".\n";
} catch (Throwable $e) {
    if (!$dryRun && $db->inTransaction()) $db->rollBack();
    echo "\n실패: " . $e->getMessage() . "\n";
    exit(1);
}
