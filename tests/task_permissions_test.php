<?php
/**
 * Task 관리 endpoint 권한 회귀.
 *
 * 사용:
 *   OP_COOKIE='PHPSESSID_ADMIN=...op...' \
 *   HEAD_COOKIE='PHPSESSID_ADMIN=...head...' \
 *   COACH_COOKIE='PHPSESSID_ADMIN=...coach...' \
 *   DEV_BASE='https://dev-boot.soritune.com' \
 *     php tests/task_permissions_test.php
 *
 * 사전: operation 1명, head 또는 subhead 1명, coach 1명 각각 로그인 쿠키 필요.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

$base   = rtrim(getenv('DEV_BASE') ?: 'https://dev-boot.soritune.com', '/');
$op     = getenv('OP_COOKIE')    ?: '';
$head   = getenv('HEAD_COOKIE')  ?: '';
$coach  = getenv('COACH_COOKIE') ?: '';
if (!$op || !$head || !$coach) {
    echo "OP_COOKIE / HEAD_COOKIE / COACH_COOKIE 모두 필수\n"; exit(2);
}

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

function call(string $cookie, string $base, string $action, string $method = 'GET', ?array $json = null): int {
    $ch = curl_init("{$base}/api/admin.php?action={$action}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookie, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

// GET 권한 (operation/head 200, coach 403)
t('op 가 all_tasks_grouped 200',    call($op,    $base, 'all_tasks_grouped') === 200);
t('head 가 all_tasks_grouped 200',  call($head,  $base, 'all_tasks_grouped') === 200);
t('coach 가 all_tasks_grouped 403', call($coach, $base, 'all_tasks_grouped') === 403);

// POST 권한 (task_create) — empty payload 라도 401/403 시점이 권한 분기
$dummy = ['title' => '', 'roles' => [], 'date_mode' => 'direct'];
t('op 가 task_create 비 403',    call($op,    $base, 'task_create', 'POST', $dummy) !== 403);
t('head 가 task_create 비 403',  call($head,  $base, 'task_create', 'POST', $dummy) !== 403);
t('coach 가 task_create 403',    call($coach, $base, 'task_create', 'POST', $dummy) === 403);

echo "\n--- {$pass} pass / {$fail} fail ---\n";
exit($fail ? 1 : 0);
