<?php
/**
 * Task 결과물 제출 API 통합 테스트.
 *
 * 사용:
 *   ADMIN_COOKIE='PHPSESSID_ADMIN=...' DEV_BASE='https://dev-boot.soritune.com' \
 *     php tests/task_submission_api_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

$base = getenv('DEV_BASE') ?: 'https://dev-boot.soritune.com';
$cookie = getenv('ADMIN_COOKIE') ?: '';
if (!$cookie) { echo "ADMIN_COOKIE 환경변수 필수\n"; exit(2); }

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

function req(string $method, string $url, array $headers, ?array $json = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    if ($raw === false) return ['code' => 0, 'body' => null];
    $body = substr($raw, $info['header_size']);
    return ['code' => $info['http_code'], 'body' => json_decode($body, true) ?? ['raw' => $body]];
}

require_once __DIR__ . '/../public_html/config.php';
$db = getDB();

$base = rtrim($base, '/');
$api  = $base . '/api/admin.php';
$h    = ["Cookie: $cookie", "Content-Type: application/json"];

// ── Fixture: requires_submission=1 row 1개, requires_submission=0 row 1개
$cohortRow = $db->query("SELECT cohort FROM cohorts ORDER BY start_date DESC LIMIT 1")->fetch();
if (!$cohortRow) { echo "SKIP — cohorts 비어있음\n"; exit(0); }
$cohort = $cohortRow['cohort'];

$db->prepare("DELETE FROM tasks WHERE title LIKE '__sub_test%'")->execute();
$db->exec("
    INSERT INTO tasks (title, role, completed, requires_submission, start_date, end_date, content_markdown, cohort)
    VALUES
      ('__sub_test_req', 'operation', 0, 1, '2099-06-01', '2099-06-01', NULL, " . $db->quote($cohort) . "),
      ('__sub_test_opt', 'operation', 0, 0, '2099-06-02', '2099-06-02', NULL, " . $db->quote($cohort) . ")
");
$reqId = (int)$db->query("SELECT id FROM tasks WHERE title='__sub_test_req'")->fetchColumn();
$optId = (int)$db->query("SELECT id FROM tasks WHERE title='__sub_test_opt'")->fetchColumn();

try {
    // ── toggle_task: requires_submission=1 + 텍스트 없음 → 400 ──
    $r = req('POST', "$api?action=toggle_task", $h, ['task_id' => $reqId, 'completed' => true]);
    t('toggle_task 텍스트 없이 → 400', $r['code'] === 400, "code={$r['code']}");

    // ── toggle_task: 빈 문자열 → 400 ──
    $r = req('POST', "$api?action=toggle_task", $h, ['task_id' => $reqId, 'completed' => true, 'submission_text' => '   ']);
    t('toggle_task 빈 문자열 → 400', $r['code'] === 400, "code={$r['code']}");

    // ── toggle_task: 정상 텍스트 → 200 ──
    $r = req('POST', "$api?action=toggle_task", $h, ['task_id' => $reqId, 'completed' => true, 'submission_text' => '결과물 v1']);
    t('toggle_task 정상 → 200', $r['code'] === 200);
    $row = $db->query("SELECT completed, submission_text, submitted_at FROM tasks WHERE id=$reqId")->fetch();
    t('  완료 + 텍스트 저장', (int)$row['completed'] === 1 && $row['submission_text'] === '결과물 v1');
    t('  submitted_at set', $row['submitted_at'] !== null);

    // ── toggle_task: uncheck → 텍스트 보존 ──
    $r = req('POST', "$api?action=toggle_task", $h, ['task_id' => $reqId, 'completed' => false]);
    t('toggle_task uncheck → 200', $r['code'] === 200);
    $row = $db->query("SELECT completed, submission_text FROM tasks WHERE id=$reqId")->fetch();
    t('  미완료 + 텍스트 보존', (int)$row['completed'] === 0 && $row['submission_text'] === '결과물 v1');

    // ── toggle_task: 다시 check + 새 텍스트 → 덮어쓰기 ──
    $r = req('POST', "$api?action=toggle_task", $h, ['task_id' => $reqId, 'completed' => true, 'submission_text' => '결과물 v2']);
    t('toggle_task 재완료 → 200', $r['code'] === 200);
    $row = $db->query("SELECT submission_text FROM tasks WHERE id=$reqId")->fetch();
    t('  텍스트 덮어쓰기', $row['submission_text'] === '결과물 v2');

    // ── task_submission_update: requires_submission=0 row → 400 ──
    $r = req('POST', "$api?action=task_submission_update", $h, ['task_id' => $optId, 'submission_text' => 'x']);
    t('task_submission_update on flag=0 row → 400', $r['code'] === 400);

    // ── task_submission_update: 정상 → 텍스트만 갱신, completed 미변경 ──
    $r = req('POST', "$api?action=task_submission_update", $h, ['task_id' => $reqId, 'submission_text' => '결과물 v3']);
    t('task_submission_update 정상 → 200', $r['code'] === 200);
    $row = $db->query("SELECT completed, submission_text FROM tasks WHERE id=$reqId")->fetch();
    t('  텍스트 갱신', $row['submission_text'] === '결과물 v3');
    t('  completed 미변경', (int)$row['completed'] === 1);

    // ── task_group_update: 0→1 → 묶음 모든 row 갱신, 기존 completed/text 보존 ──
    // 추가 fixture: '__sub_test_grp' 묶음 3 row (1개는 completed+text)
    $db->prepare("DELETE FROM tasks WHERE title = '__sub_test_grp'")->execute();
    $db->exec("
        INSERT INTO tasks (title, role, completed, requires_submission, submission_text, submitted_at, start_date, end_date, content_markdown, cohort)
        VALUES
          ('__sub_test_grp', 'operation', 1, 0, '기존 텍스트', NOW(), '2099-07-01', '2099-07-01', NULL, " . $db->quote($cohort) . "),
          ('__sub_test_grp', 'operation', 0, 0, NULL, NULL, '2099-07-02', '2099-07-02', NULL, " . $db->quote($cohort) . "),
          ('__sub_test_grp', 'operation', 0, 0, NULL, NULL, '2099-07-03', '2099-07-03', NULL, " . $db->quote($cohort) . ")
    ");
    $r = req('POST', "$api?action=task_group_update", $h, [
        'cohort' => $cohort, 'title' => '__sub_test_grp', 'role' => 'operation',
        'new_title' => '__sub_test_grp', 'new_content_markdown' => '', 'requires_submission' => 1,
    ]);
    t('task_group_update 0→1 → 200', $r['code'] === 200);
    $rows = $db->query("SELECT requires_submission, completed, submission_text FROM tasks WHERE title='__sub_test_grp' ORDER BY start_date")->fetchAll();
    t('  3 row 모두 requires_submission=1', count(array_filter($rows, fn($r) => (int)$r['requires_submission'] === 1)) === 3);
    t('  기존 completed=1 보존', (int)$rows[0]['completed'] === 1);
    t('  기존 submission_text 보존', $rows[0]['submission_text'] === '기존 텍스트');

    // ── task_group_update: 1→0 → 묶음 모든 row 갱신, 기존 submission_text 보존 ──
    $r = req('POST', "$api?action=task_group_update", $h, [
        'cohort' => $cohort, 'title' => '__sub_test_grp', 'role' => 'operation',
        'new_title' => '__sub_test_grp', 'new_content_markdown' => '', 'requires_submission' => 0,
    ]);
    t('task_group_update 1→0 → 200', $r['code'] === 200);
    $rows = $db->query("SELECT requires_submission, submission_text FROM tasks WHERE title='__sub_test_grp' ORDER BY start_date")->fetchAll();
    t('  3 row 모두 requires_submission=0', count(array_filter($rows, fn($r) => (int)$r['requires_submission'] === 0)) === 3);
    t('  기존 submission_text 보존', $rows[0]['submission_text'] === '기존 텍스트');

    // ── task_group_get: requires_submission 응답 포함 ──
    $r = req('POST', "$api?action=task_group_get", $h, [
        'cohort' => $cohort, 'title' => '__sub_test_grp', 'role' => 'operation',
    ]);
    t('task_group_get → 200', $r['code'] === 200);
    t('  requires_submission 필드 포함', isset($r['body']['requires_submission']));
    t('  값=0 (방금 1→0 했으므로)', (int)($r['body']['requires_submission'] ?? -1) === 0);

    // ── task_group_rows: row 응답에 submission 컬럼 포함 ──
    $r = req('GET', "$api?action=task_group_rows&cohort=" . urlencode($cohort) . "&title=__sub_test_grp&role=operation&only_incomplete=0&only_until_today=0", $h);
    t('task_group_rows → 200', $r['code'] === 200);
    $row0 = ($r['body']['rows'] ?? [])[0] ?? null;
    t('  row 에 requires_submission 포함', $row0 && array_key_exists('requires_submission', $row0));
    t('  row 에 submission_text 포함',     $row0 && array_key_exists('submission_text', $row0));
    t('  row 에 submitted_at 포함',        $row0 && array_key_exists('submitted_at', $row0));

} finally {
    $db->prepare("DELETE FROM tasks WHERE title LIKE '__sub_test%'")->execute();
}

echo "\n=== {$pass} PASS / {$fail} FAIL ===\n";
exit($fail === 0 ? 0 : 1);
