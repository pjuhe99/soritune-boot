<?php
/**
 * Task 그룹 일괄 수정/삭제 API 통합 테스트.
 *
 * 사용:
 *   ADMIN_COOKIE='PHPSESSID_ADMIN=...' DEV_BASE='https://dev-boot.soritune.com' \
 *     php tests/task_group_api_test.php
 *
 * 사전:
 *   - operation 권한 admin 으로 로그인된 PHPSESSID_ADMIN 쿠키 필요
 *   - DEV cohorts 에 12기 row 존재 (chip 활성)
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
$api = $base . '/api/admin.php';
$h = ["Cookie: $cookie", "Content-Type: application/json"];

// ── Fixture ─────────────────────────────────────
// 안전하게 isolated cohort 사용: 가장 최근 cohort
$cohortRow = $db->query("SELECT cohort FROM cohorts ORDER BY start_date DESC LIMIT 1")->fetch();
if (!$cohortRow) { echo "SKIP — cohorts 비어있음\n"; exit(0); }
$cohort = $cohortRow['cohort'];

// 시드 task: 같은 (cohort, title='__test_group__', role='operation') 5개 row
$titleA = '__test_group__';
$db->prepare("DELETE FROM tasks WHERE title LIKE '__test_group%'")->execute();
$insert = $db->prepare("
    INSERT INTO tasks (title, role, assignee_admin_id, completed, start_date, end_date, content_markdown, cohort)
    VALUES (?, 'operation', NULL, ?, ?, ?, ?, ?)
");
$dates = ['2099-01-01','2099-01-02','2099-01-03','2099-01-04','2099-01-05'];
foreach ($dates as $i => $d) {
    // 마지막 2개는 완료 처리 (group_delete 정책 검증용)
    $completed = ($i >= 3) ? 1 : 0;
    $insert->execute([$titleA, $completed, $d, $d, '본문 v1', $cohort]);
}

try {
    // ── Test: task_group_get ────────────────────────
    $r = req('POST', "$api?action=task_group_get", $h, [
        'cohort' => $cohort, 'title' => $titleA, 'role' => 'operation',
    ]);
    t('task_group_get returns 200', $r['code'] === 200, "code={$r['code']}");
    t('task_group_get returns title', ($r['body']['title'] ?? null) === $titleA, json_encode($r['body']));
    t('task_group_get returns content_markdown', ($r['body']['content_markdown'] ?? null) === '본문 v1');

    // 없는 그룹
    $r = req('POST', "$api?action=task_group_get", $h, [
        'cohort' => $cohort, 'title' => '__no_such_group__', 'role' => 'operation',
    ]);
    t('task_group_get 404 on missing group', $r['code'] === 404, "code={$r['code']}");

    // ── Test: all_tasks_grouped ─────────────────────
    $r = req('GET', "$api?action=all_tasks_grouped&filter_role=all", $h);
    t('all_tasks_grouped returns 200', $r['code'] === 200, "code={$r['code']}");

    $groups = $r['body']['groups'] ?? [];
    $ourGroup = null;
    foreach ($groups as $g) {
        if ($g['title'] === $titleA && $g['role'] === 'operation' && $g['cohort'] === $cohort) {
            $ourGroup = $g; break;
        }
    }
    t('all_tasks_grouped includes seeded group', $ourGroup !== null);
    t('all_tasks_grouped total_count=5', $ourGroup && (int)$ourGroup['total_count'] === 5,
       $ourGroup ? json_encode($ourGroup) : 'not found');
    t('all_tasks_grouped done_count=2', $ourGroup && (int)$ourGroup['done_count'] === 2);
    t('all_tasks_grouped min_start_date=2099-01-01', $ourGroup && $ourGroup['min_start_date'] === '2099-01-01');
    t('all_tasks_grouped max_end_date=2099-01-05', $ourGroup && $ourGroup['max_end_date'] === '2099-01-05');
    t('all_tasks_grouped assignee_count>=1', $ourGroup && (int)$ourGroup['assignee_count'] >= 1);

} finally {
    // ── Cleanup ─────────────────────────────────────
    $db->prepare("DELETE FROM tasks WHERE title LIKE '__test_group%'")->execute();
}

echo "\n결과: PASS={$pass}  FAIL={$fail}\n";
exit($fail ? 1 : 0);
