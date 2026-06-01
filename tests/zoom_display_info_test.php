<?php
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/includes/bootcamp_functions.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// 1. 특강: 컬럼에 id/pw 둘 다 있음 → 그대로
$r = zoomDisplayInfo([
    'zoom_meeting_id' => '87444618976',
    'zoom_join_url'   => 'https://us02web.zoom.us/j/81330750588?pwd=duq',
    'zoom_password'   => '415217',
]);
t('lecture_columns', $r['zoom_meeting_id_display'] === '87444618976' && $r['zoom_password_display'] === '415217');

// 2. 복습 고정방: id NULL → URL /j/ 파싱
$r = zoomDisplayInfo([
    'zoom_meeting_id' => null,
    'zoom_join_url'   => 'https://us02web.zoom.us/j/82511251269?pwd=Ol9HUZJ',
    'zoom_password'   => null,
]);
t('parse_id_from_url', $r['zoom_meeting_id_display'] === '82511251269');

// 3. pw NULL + fallback → fallback 사용
$r = zoomDisplayInfo([
    'zoom_meeting_id' => null,
    'zoom_join_url'   => 'https://us02web.zoom.us/j/82511251269?pwd=Ol9',
    'zoom_password'   => null,
], '600091');
t('password_fallback', $r['zoom_password_display'] === '600091');

// 4. pw NULL + fallback 없음 → null (줄 생략)
$r = zoomDisplayInfo([
    'zoom_meeting_id' => null,
    'zoom_join_url'   => 'https://us02web.zoom.us/j/82511251269?pwd=Ol9',
    'zoom_password'   => null,
]);
t('no_password_no_fallback', $r['zoom_password_display'] === null);

// 5. fallback 빈 문자열 → null (빈 값 시드 케이스)
$r = zoomDisplayInfo([
    'zoom_meeting_id' => '82511251269',
    'zoom_join_url'   => 'https://us02web.zoom.us/j/82511251269',
    'zoom_password'   => null,
], '');
t('empty_fallback_is_null', $r['zoom_password_display'] === null);

// 6. id/url 모두 없음 → id null
$r = zoomDisplayInfo(['zoom_meeting_id' => null, 'zoom_join_url' => null, 'zoom_password' => null]);
t('no_id_no_url', $r['zoom_meeting_id_display'] === null);

// 7. /j/ 없는 이상 URL → id null (크래시 안 함)
$r = zoomDisplayInfo([
    'zoom_meeting_id' => null,
    'zoom_join_url'   => 'https://example.com/weird',
    'zoom_password'   => null,
]);
t('malformed_url_no_crash', $r['zoom_meeting_id_display'] === null);

// 8. 빈 문자열 컬럼 → URL 파싱으로 폴백
$r = zoomDisplayInfo([
    'zoom_meeting_id' => '',
    'zoom_join_url'   => 'https://us02web.zoom.us/j/82511251269?pwd=x',
    'zoom_password'   => '',
], '600091');
t('empty_string_columns', $r['zoom_meeting_id_display'] === '82511251269' && $r['zoom_password_display'] === '600091');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
