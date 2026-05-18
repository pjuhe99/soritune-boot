<?php
/**
 * Notice API 통합 테스트.
 *
 * 사용:
 *   ADMIN_COOKIE='PHPSESSID_ADMIN=...' \
 *   MEMBER_COOKIE='PHPSESSID_MEMBER=...' \
 *   DEV_BASE='https://dev-boot.soritune.com' \
 *     php tests/notice_api_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

$base   = rtrim(getenv('DEV_BASE') ?: 'https://dev-boot.soritune.com', '/');
$admC   = getenv('ADMIN_COOKIE') ?: '';
$memC   = getenv('MEMBER_COOKIE') ?: '';
if (!$admC || !$memC) { echo "ADMIN_COOKIE, MEMBER_COOKIE 환경변수 필수\n"; exit(2); }

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

$adminApi  = $base . '/api/admin.php?action=';
$memberApi = $base . '/api/bootcamp.php?action=';
$adminH  = ["Cookie: $admC",  "Content-Type: application/json"];
$memberH = ["Cookie: $memC", "Content-Type: application/json"];

// ── 1. create ──
$r = req('POST', $adminApi . 'notice_create', $adminH, [
    'title' => '__test 공지 ' . time(),
    'body_markdown' => '본문 *마크다운*',
    'is_visible' => 1,
]);
t('create 200', $r['code'] === 200, 'code=' . $r['code']);
$noticeId = $r['body']['id'] ?? 0;
t('create returned id', $noticeId > 0);

// ── 2. admin list 에 포함 ──
$r = req('GET', $adminApi . 'notice_list', $adminH);
t('admin list 200', $r['code'] === 200);
$ids = array_column($r['body']['notices'] ?? [], 'id');
t('admin list 에 새 공지 포함', in_array($noticeId, $ids));

// ── 3. member list 에 포함 ──
$r = req('GET', $memberApi . 'notices', $memberH);
t('member notices 200', $r['code'] === 200);
$mIds = array_column($r['body']['notices'] ?? [], 'id');
t('member 에서 새 공지 보임', in_array($noticeId, $mIds));
$mRow = current(array_filter($r['body']['notices'] ?? [], fn($n) => $n['id'] === $noticeId)) ?: null;
t('member row 에 is_visible 노출 안 됨', $mRow && !array_key_exists('is_visible', $mRow));
t('member row 에 admin_id 노출 안 됨', $mRow && !array_key_exists('created_by_admin_id', $mRow));

// ── 4. update ──
$r = req('POST', $adminApi . 'notice_update', $adminH, [
    'id' => $noticeId,
    'title' => '__test 수정됨',
    'body_markdown' => '수정 본문',
]);
t('update 200', $r['code'] === 200);

$r = req('GET', $adminApi . 'notice_list', $adminH);
$row = current(array_filter($r['body']['notices'] ?? [], fn($n) => $n['id'] === $noticeId)) ?: null;
t('update title 반영', $row && $row['title'] === '__test 수정됨');

// ── 5. toggle hidden → member 에서 안 보임 ──
$r = req('POST', $adminApi . 'notice_toggle_visible', $adminH, [
    'id' => $noticeId,
    'is_visible' => 0,
]);
t('toggle 0 200', $r['code'] === 200);

$r = req('GET', $memberApi . 'notices', $memberH);
$mIds = array_column($r['body']['notices'] ?? [], 'id');
t('hidden 공지 member 에서 안 보임', !in_array($noticeId, $mIds));

// ── 6. toggle visible → 다시 보임 ──
$r = req('POST', $adminApi . 'notice_toggle_visible', $adminH, [
    'id' => $noticeId,
    'is_visible' => 1,
]);
t('toggle 1 200', $r['code'] === 200);
$r = req('GET', $memberApi . 'notices', $memberH);
$mIds = array_column($r['body']['notices'] ?? [], 'id');
t('visible 공지 다시 보임', in_array($noticeId, $mIds));

// ── 7. 검증 위반 ──
$r = req('POST', $adminApi . 'notice_create', $adminH, [
    'title' => '',
    'body_markdown' => '본문',
    'is_visible' => 1,
]);
t('create title 빈 → 400', $r['code'] === 400);

$r = req('POST', $adminApi . 'notice_update', $adminH, [
    'id' => $noticeId,
    'title' => '',
    'body_markdown' => '본문',
]);
t('update title 빈 → 400', $r['code'] === 400);

// ── 8. cleanup ──
$r = req('POST', $adminApi . 'notice_delete', $adminH, ['id' => $noticeId]);
t('delete 200', $r['code'] === 200);

$r = req('GET', $adminApi . 'notice_list', $adminH);
$ids = array_column($r['body']['notices'] ?? [], 'id');
t('delete 후 list 미포함', !in_array($noticeId, $ids));

echo "\n=== {$pass} PASS / {$fail} FAIL ===\n";
exit($fail === 0 ? 0 : 1);
