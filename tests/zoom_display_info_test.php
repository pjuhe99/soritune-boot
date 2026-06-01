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

// ─────────────────────────────────────────────────────────────
// zoomRoomId() — 링크 /j/ 가 권위, URL 없을 때만 컬럼 폴백
// ─────────────────────────────────────────────────────────────

// URL /j/ 가 컬럼보다 우선 (공유방: 컬럼은 버려진 회의 ID)
t('roomid_url_over_column',
    zoomRoomId(['zoom_meeting_id' => '83919001215', 'zoom_join_url' => 'https://us02web.zoom.us/j/82511251269?pwd=x']) === '82511251269');

// URL 없으면 컬럼 사용
t('roomid_column_fallback',
    zoomRoomId(['zoom_meeting_id' => '82511251269', 'zoom_join_url' => null]) === '82511251269');

// /j/ 없는 이상 URL → 컬럼 폴백
t('roomid_malformed_url_column',
    zoomRoomId(['zoom_meeting_id' => '83902331826', 'zoom_join_url' => 'https://example.com/weird']) === '83902331826');

// 둘 다 없음 → null
t('roomid_none', zoomRoomId(['zoom_meeting_id' => null, 'zoom_join_url' => null]) === null);

// 빈 문자열 컬럼 + URL 없음 → null
t('roomid_empty', zoomRoomId(['zoom_meeting_id' => '', 'zoom_join_url' => '']) === null);

// ─────────────────────────────────────────────────────────────
// zoomRoomPasswordFromMap() — JSON 맵에서 방별 비번
// ─────────────────────────────────────────────────────────────
$map = '{"82511251269":"600091","81330750588":"123456"}';

t('map_hit',        zoomRoomPasswordFromMap($map, '82511251269') === '600091');
t('map_hit2',       zoomRoomPasswordFromMap($map, '81330750588') === '123456');
t('map_miss',       zoomRoomPasswordFromMap($map, '99999999999') === null);
t('map_empty_val',  zoomRoomPasswordFromMap('{"82511251269":""}', '82511251269') === null);
t('map_null_json',  zoomRoomPasswordFromMap(null, '82511251269') === null);
t('map_empty_json', zoomRoomPasswordFromMap('', '82511251269') === null);
t('map_bad_json',   zoomRoomPasswordFromMap('not json', '82511251269') === null);
t('map_null_room',  zoomRoomPasswordFromMap($map, null) === null);

// ─────────────────────────────────────────────────────────────
// zoomDisplayInfo() — 표시용 ID/비번
// ─────────────────────────────────────────────────────────────

// 1) 공유방(컬럼≠링크), 맵 없음 → id=링크, pw=null (컬럼 비번은 다른 방 것이라 무시)
$r = zoomDisplayInfo([
    'zoom_meeting_id' => '83220061612',
    'zoom_join_url'   => 'https://us02web.zoom.us/j/81330750588?pwd=x',
    'zoom_password'   => '999999',
]);
t('shared_no_map', $r['zoom_meeting_id_display'] === '81330750588' && $r['zoom_password_display'] === null);

// 2) 공유방 + 방별 맵 비번 제공 → pw=맵값
$r = zoomDisplayInfo([
    'zoom_meeting_id' => '83220061612',
    'zoom_join_url'   => 'https://us02web.zoom.us/j/81330750588?pwd=x',
    'zoom_password'   => '999999',
], '123456');
t('shared_with_map', $r['zoom_meeting_id_display'] === '81330750588' && $r['zoom_password_display'] === '123456');

// 3) 1회성 webhook 회의(컬럼==링크) + 맵 없음 → 컬럼 비번 신뢰
$r = zoomDisplayInfo([
    'zoom_meeting_id' => '84052105968',
    'zoom_join_url'   => 'https://us02web.zoom.us/j/84052105968?pwd=x',
    'zoom_password'   => '415217',
]);
t('unique_col_match', $r['zoom_meeting_id_display'] === '84052105968' && $r['zoom_password_display'] === '415217');

// 4) 맵 비번이 컬럼 비번보다 우선
$r = zoomDisplayInfo([
    'zoom_meeting_id' => '84052105968',
    'zoom_join_url'   => 'https://us02web.zoom.us/j/84052105968?pwd=x',
    'zoom_password'   => '415217',
], '777777');
t('map_overrides_col', $r['zoom_password_display'] === '777777');

// 5) URL 없음 + 컬럼 id==id + 컬럼 비번 → 컬럼 비번 사용
$r = zoomDisplayInfo([
    'zoom_meeting_id' => '82511251269',
    'zoom_join_url'   => null,
    'zoom_password'   => '555555',
]);
t('no_url_col_pw', $r['zoom_meeting_id_display'] === '82511251269' && $r['zoom_password_display'] === '555555');

// 6) 고정 복습방(컬럼 NULL) + 맵 비번 → id=링크, pw=맵값
$r = zoomDisplayInfo([
    'zoom_meeting_id' => null,
    'zoom_join_url'   => 'https://us02web.zoom.us/j/82511251269?pwd=Ol9',
    'zoom_password'   => null,
], '600091');
t('fixed_study_map', $r['zoom_meeting_id_display'] === '82511251269' && $r['zoom_password_display'] === '600091');

// 7) 빈 맵 비번('') → null 처리 후 컬럼 폴백 검사 (컬럼≠링크라 null)
$r = zoomDisplayInfo([
    'zoom_meeting_id' => '83220061612',
    'zoom_join_url'   => 'https://us02web.zoom.us/j/81330750588?pwd=x',
    'zoom_password'   => '999999',
], '');
t('empty_map_pw', $r['zoom_password_display'] === null);

// 8) id 전무 → 둘 다 null, 크래시 없음
$r = zoomDisplayInfo(['zoom_meeting_id' => null, 'zoom_join_url' => null, 'zoom_password' => null]);
t('no_id', $r['zoom_meeting_id_display'] === null && $r['zoom_password_display'] === null);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
