<?php
/**
 * 1단계 fixed Zoom URL 변경에 따라 lecture_events / lecture_schedules
 * 의 zoom_join_url 을 옛 URL → 새 URL 로 일괄 갱신.
 *
 * 1회성. 실행:
 *   cd /root/boot-dev && php migrate_update_stage1_zoom.php           # dry-run
 *   cd /root/boot-dev && php migrate_update_stage1_zoom.php --apply  # 실제 적용
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/public_html/config.php';

const OLD_URL = 'https://us02web.zoom.us/j/83473209444?pwd=VBcLqDQ5FlbkgT9ZHcu3pYhVFdh02h.1';
const NEW_URL = 'https://us02web.zoom.us/j/89537166991?pwd=VGL2QC07b1Xja7Es3S1670Wdqu2MOs.1';

$apply = in_array('--apply', $argv ?? [], true);

$db = getDB();

$tables = ['lecture_events', 'lecture_schedules'];
$summary = [];

foreach ($tables as $tbl) {
    $stmt = $db->prepare("SELECT id, status FROM {$tbl} WHERE zoom_join_url = ?");
    $stmt->execute([OLD_URL]);
    $rows = $stmt->fetchAll();
    $summary[$tbl] = $rows;
    echo "{$tbl}: " . count($rows) . "건 매칭\n";
    foreach ($rows as $r) {
        echo sprintf("  id=%d status=%s\n", $r['id'], $r['status']);
    }
}

$total = array_sum(array_map('count', $summary));
if ($total === 0) {
    echo "\n변경 대상 없음. 종료.\n";
    exit(0);
}

if (!$apply) {
    echo "\n--apply 옵션 없음. dry-run 종료.\n";
    exit(0);
}

echo "\n실제 적용 시작...\n";
$db->beginTransaction();
try {
    foreach ($tables as $tbl) {
        $upd = $db->prepare("UPDATE {$tbl} SET zoom_join_url = ? WHERE zoom_join_url = ?");
        $upd->execute([NEW_URL, OLD_URL]);
        echo "  {$tbl}: " . $upd->rowCount() . "건 갱신\n";
    }
    $db->commit();
    echo "완료.\n";
} catch (\Throwable $ex) {
    $db->rollBack();
    echo "롤백. 오류: " . $ex->getMessage() . "\n";
    exit(1);
}
