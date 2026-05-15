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
    t('all_tasks_grouped assignee_count=0 (전부 미배정 fixture)', $ourGroup && (int)$ourGroup['assignee_count'] === 0,
       $ourGroup ? json_encode($ourGroup) : 'not found');

    // ── Test: task_group_update ─────────────────────
    $newTitle = '__test_group__v2';
    $r = req('POST', "$api?action=task_group_update", $h, [
        'cohort' => $cohort, 'title' => $titleA, 'role' => 'operation',
        'new_title' => $newTitle, 'new_content_markdown' => '본문 v2',
    ]);
    t('task_group_update returns 200', $r['code'] === 200, "code={$r['code']}");
    t('task_group_update affected_count=5', (int)($r['body']['affected_count'] ?? 0) === 5,
       json_encode($r['body']));

    // 모든 row 가 새 title/content 로 바뀌었는지 DB 직접 확인
    $verify = $db->prepare("SELECT title, content_markdown FROM tasks WHERE cohort = ? AND role = 'operation' AND start_date BETWEEN '2099-01-01' AND '2099-01-05'");
    $verify->execute([$cohort]);
    $rows = $verify->fetchAll();
    $allRenamed = !empty($rows) && count(array_filter($rows, fn($r) => $r['title'] === $newTitle)) === count($rows);
    $allContent = !empty($rows) && count(array_filter($rows, fn($r) => $r['content_markdown'] === '본문 v2')) === count($rows);
    t('task_group_update 모든 row title 변경', $allRenamed);
    t('task_group_update 모든 row content 변경', $allContent);

    // 잘못된 입력 — new_title 빈 문자
    $r = req('POST', "$api?action=task_group_update", $h, [
        'cohort' => $cohort, 'title' => $newTitle, 'role' => 'operation',
        'new_title' => '', 'new_content_markdown' => 'x',
    ]);
    t('task_group_update 빈 new_title 거부', $r['code'] === 400);

    // 옛 title 로 다시 update 시도 → 매칭 0
    $r = req('POST', "$api?action=task_group_update", $h, [
        'cohort' => $cohort, 'title' => $titleA, 'role' => 'operation',
        'new_title' => '__test_group__v3', 'new_content_markdown' => 'v3',
    ]);
    t('task_group_update 옛 title 매칭 0', (int)($r['body']['affected_count'] ?? -1) === 0);

    // 다음 단계 (delete) 테스트 위해 v2 → titleA 로 복원
    $db->prepare("UPDATE tasks SET title = ?, content_markdown = '본문 v1' WHERE title = ?")
       ->execute([$titleA, $newTitle]);

    // ── Test: task_group_delete (completed=0 만, completed=1 보존) ──
    $r = req('POST', "$api?action=task_group_delete", $h, [
        'cohort' => $cohort, 'title' => $titleA, 'role' => 'operation',
    ]);
    t('task_group_delete returns 200', $r['code'] === 200, "code={$r['code']}");
    t('task_group_delete deleted_count=3', (int)($r['body']['deleted_count'] ?? -1) === 3,
       json_encode($r['body']));
    t('task_group_delete kept_count=2', (int)($r['body']['kept_count'] ?? -1) === 2);

    // DB 검증 — completed=1 row 만 남아야 함
    $verify = $db->prepare("SELECT completed FROM tasks WHERE cohort = ? AND title = ? AND role = 'operation'");
    $verify->execute([$cohort, $titleA]);
    $leftover = $verify->fetchAll();
    $allCompleted = !empty($leftover) && count(array_filter($leftover, fn($r) => (int)$r['completed'] === 1)) === count($leftover);
    t('task_group_delete 후 남은 row 모두 completed=1', $allCompleted, json_encode($leftover));

    // 모두 완료된 그룹에 대해 다시 호출 → deleted_count=0, kept_count=2
    $r = req('POST', "$api?action=task_group_delete", $h, [
        'cohort' => $cohort, 'title' => $titleA, 'role' => 'operation',
    ]);
    t('task_group_delete 모두 완료 그룹 deleted=0', (int)($r['body']['deleted_count'] ?? -1) === 0);
    t('task_group_delete 모두 완료 그룹 kept=2',    (int)($r['body']['kept_count'] ?? -1) === 2);

    // ── Test: task_group_rows ──────────────────────
    // 시드: __test_group__ 그룹은 task_group_delete 호출 후 completed=1 만 2 row 남음 (2099-01-04, 2099-01-05).
    // 미래(2099) 날짜라 only_until_today=1 이면 0 row.

    // 1) only_incomplete=0 only_until_today=0 → 2 row (남은 완료 row 모두)
    $qsBase = "cohort=" . urlencode($cohort) . "&title=" . urlencode($titleA) . "&role=operation";
    $r = req('GET', "$api?action=task_group_rows&{$qsBase}&only_incomplete=0&only_until_today=0", $h);
    t('task_group_rows 200', $r['code'] === 200, "code={$r['code']}");
    t('task_group_rows 전체 → 2 row', count($r['body']['rows'] ?? []) === 2, json_encode($r['body']));

    // 2) only_incomplete=1 → 0 row (남은 row 모두 completed=1)
    $r = req('GET', "$api?action=task_group_rows&{$qsBase}&only_incomplete=1&only_until_today=0", $h);
    t('task_group_rows 미완료만 → 0 row', count($r['body']['rows'] ?? []) === 0);

    // 3) only_until_today=1 + 미래(2099) 시드 → 0 row
    $r = req('GET', "$api?action=task_group_rows&{$qsBase}&only_incomplete=0&only_until_today=1", $h);
    t('task_group_rows 오늘까지 + 미래시드 → 0 row', count($r['body']['rows'] ?? []) === 0);

    // 4) 응답 필드 cutoff_today 존재
    $r = req('GET', "$api?action=task_group_rows&{$qsBase}&only_incomplete=0&only_until_today=0", $h);
    t('task_group_rows cutoff_today 필드', !empty($r['body']['cutoff_today']),
       'cutoff_today=' . ($r['body']['cutoff_today'] ?? 'MISSING'));

    // 5) 응답 row 형식 — assignee_kind ENUM
    $r = req('GET', "$api?action=task_group_rows&{$qsBase}&only_incomplete=0&only_until_today=0", $h);
    $rows = $r['body']['rows'] ?? [];
    $validKinds = ['admin', 'member', 'unassigned'];
    $allKindsValid = !empty($rows) && count(array_filter($rows, fn($r) => in_array($r['assignee_kind'] ?? '', $validKinds))) === count($rows);
    t('task_group_rows assignee_kind 모두 valid', $allKindsValid);

} finally {
    // ── Cleanup ─────────────────────────────────────
    $db->prepare("DELETE FROM tasks WHERE title LIKE '__test_group%'")->execute();
}

echo "\n결과: PASS={$pass}  FAIL={$fail}\n";
exit($fail ? 1 : 0);
