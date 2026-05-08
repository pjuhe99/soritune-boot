<?php
/**
 * 12기 active lecture_events 의 zoom_join_url 을 단계별 fixed URL 로 백필.
 * 1회성. 실행:
 *   cd /root/boot-dev && php migrate_event_fixed_zoom.php           # dry-run
 *   cd /root/boot-dev && php migrate_event_fixed_zoom.php --apply  # 실제 적용
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/api/services/lecture.php'; // getFixedZoomUrl

$apply = in_array('--apply', $argv ?? [], true);

$db = getDB();

$stmt = $db->prepare("
    SELECT le.id, le.stage, le.event_date, le.title, le.zoom_status,
           LEFT(le.zoom_join_url, 60) AS url_prefix
    FROM lecture_events le
    JOIN cohorts c ON le.cohort_id = c.id
    WHERE c.cohort = '12기' AND le.status = 'active'
    ORDER BY le.event_date
");
$stmt->execute();
$events = $stmt->fetchAll();

if (!$events) {
    echo "12기 active 이벤트 없음. 종료.\n";
    exit(0);
}

echo "대상 이벤트 " . count($events) . "건:\n";
foreach ($events as $e) {
    $newUrl = getFixedZoomUrl((int)$e['stage']);
    $newPrefix = substr($newUrl, 0, 60);
    $changes = $e['url_prefix'] !== $newPrefix ? '⇒ 변경' : '동일(skip)';
    echo sprintf(
        "  id=%d stage=%s date=%s '%s' status=%s\n    before: %s\n    after : %s  %s\n",
        $e['id'], $e['stage'] ?? 'NULL', $e['event_date'], $e['title'],
        $e['zoom_status'], $e['url_prefix'], $newPrefix, $changes
    );
}

if (!$apply) {
    echo "\n--apply 옵션 없음. dry-run 종료.\n";
    exit(0);
}

echo "\n실제 적용 시작...\n";
$db->beginTransaction();
try {
    $upd = $db->prepare("
        UPDATE lecture_events
        SET zoom_join_url = ?, zoom_status = 'ready',
            zoom_error_message = NULL,
            zoom_meeting_id = NULL, zoom_password = NULL
        WHERE id = ?
    ");
    foreach ($events as $e) {
        $newUrl = getFixedZoomUrl((int)$e['stage']);
        $upd->execute([$newUrl, $e['id']]);
    }
    $db->commit();
    echo "완료: " . count($events) . "건 갱신.\n";
} catch (\Throwable $ex) {
    $db->rollBack();
    echo "롤백. 오류: " . $ex->getMessage() . "\n";
    exit(1);
}
