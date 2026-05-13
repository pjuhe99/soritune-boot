<?php
/**
 * Multipass HTTP API 통합 테스트.
 *
 * 사용:
 *   ADMIN_COOKIE='PHPSESSID_ADMIN=...' DEV_BASE='https://dev-boot.soritune.com' php tests/multipass_api_test.php
 *
 * 사전:
 *   - operation 권한 admin 으로 로그인된 PHPSESSID_ADMIN 쿠키 필요
 *   - DEV cohorts 에 11기/12기/13기 동등 row 존재
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
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        echo "cURL error: $err\n";
        return ['code' => 0, 'body' => null];
    }
    $body = substr($raw, $info['header_size']);
    return ['code' => $info['http_code'], 'body' => json_decode($body, true) ?? ['raw' => $body]];
}

$base = rtrim($base, '/');
$api = $base . '/api/admin.php';
$h = ["Cookie: $cookie", "Content-Type: application/json"];

// 셋업 — DEV cohorts 에서 가장 최근 3 row 사용
require_once __DIR__ . '/../public_html/config.php';
$db = getDB();
$cohortRows = $db->query("SELECT id, cohort FROM cohorts ORDER BY start_date DESC LIMIT 3")->fetchAll();
if (count($cohortRows) < 3) { echo "SKIP — cohorts < 3\n"; exit(0); }
[$c1, $c2, $c3] = array_map(fn($r) => (int)$r['id'], $cohortRows);
$db->exec("DELETE FROM multipass WHERE user_id LIKE '__test_api_mp%'");
$db->exec("DELETE FROM bootcamp_members WHERE user_id LIKE '__test_api_mp%'");
$db->exec("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, user_id, member_status) VALUES ($c1, 'API홍길동', 'apihg', '__test_api_mp@k', 'active')");

// 1. multipass_create
$r = req('POST', "$api?action=multipass_create", $h, [
    'user_id' => '__test_api_mp@k', 'product_name' => 'API테스트권', 'cohort_ids' => [$c1, $c2, $c3],
]);
t('create_200', $r['code'] === 200 && !empty($r['body']['success']));
$passId = $r['body']['id'] ?? 0;
t('create_id', $passId > 0);

// 2. multipass_search_member q=user_id
$r = req('GET', "$api?action=multipass_search_member&q=__test_api_mp", $h);
t('search_200', $r['code'] === 200);
t('search_member_count', count($r['body']['members'] ?? []) === 1);
$cohorts = $r['body']['members'][0]['passes'][0]['cohorts'] ?? [];
t('search_cohort_count', count($cohorts) === 3);
$c1Found = false;
foreach ($cohorts as $c) if ($c['cohort_id'] === $c1) { $c1Found = true; t('joined_for_active', $c['joined'] === true && $c['has_member_row'] === true); }
if (!$c1Found) t('joined_for_active', false, 'c1 row 없음');

// 3. multipass_search_member q=nickname
$r = req('GET', "$api?action=multipass_search_member&q=apihg", $h);
t('search_by_nickname', count($r['body']['members'] ?? []) === 1);

// 4. has_member_row=true / joined=false (refunded 만)
$db->exec("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, user_id, member_status) VALUES ($c2, 'API환불', 'apirf', '__test_api_mp@k', 'refunded')");
$r = req('GET', "$api?action=multipass_search_member&q=__test_api_mp", $h);
$cohorts = $r['body']['members'][0]['passes'][0]['cohorts'] ?? [];
foreach ($cohorts as $c) if ($c['cohort_id'] === $c2) {
    t('has_member_row_for_refund', $c['has_member_row'] === true);
    t('joined_false_for_refund', $c['joined'] === false);
}

// 5. toggle_coupon on
$r = req('POST', "$api?action=multipass_toggle_coupon", $h, ['pass_id' => $passId, 'cohort_id' => $c2, 'issued' => true]);
t('toggle_on_200', $r['code'] === 200);
t('toggle_on_at', !empty($r['body']['coupon_issued_at']));

// 6. toggle_coupon off
$r = req('POST', "$api?action=multipass_toggle_coupon", $h, ['pass_id' => $passId, 'cohort_id' => $c2, 'issued' => false]);
t('toggle_off_at_null', $r['body']['coupon_issued_at'] === null);

// 7. update — user_id 변경
$r = req('POST', "$api?action=multipass_update", $h, ['id' => $passId, 'user_id' => '__test_api_mp2@k']);
t('update_user_id_200', $r['code'] === 200 && !empty($r['body']['success']));
$row = $db->query("SELECT user_id FROM multipass WHERE id = $passId")->fetch();
t('update_user_id_db', $row['user_id'] === '__test_api_mp2@k');

// 8. update — cohort_ids diff (c1, c3 만 → c2 제거)
$r = req('POST', "$api?action=multipass_update", $h, ['id' => $passId, 'cohort_ids' => [$c1, $c3]]);
t('update_diff_removed', in_array($c2, $r['body']['removed_cohort_ids'] ?? []));

// 9. delete CASCADE
$r = req('POST', "$api?action=multipass_delete", $h, ['id' => $passId]);
t('delete_200', $r['code'] === 200);
t('delete_cohorts_gone',
    (int)$db->query("SELECT COUNT(*) FROM multipass_cohorts WHERE pass_id = $passId")->fetchColumn() === 0);

// 10. bulk_validate — 정상 + WARN_NO_MEMBER + ERROR_COHORT_LABEL
// cohort 라벨 매칭을 위해 DEV cohorts 의 실제 숫자 라벨 사용
$cLabel = (function() use ($db) {
    foreach ($db->query("SELECT cohort FROM cohorts")->fetchAll(PDO::FETCH_COLUMN) as $c) {
        if (preg_match('/^(\d+)/', $c, $m)) return $m[1];
    }
    return '11';
})();
$r = req('POST', "$api?action=multipass_bulk_validate", $h, [
    'rows' => [
        ['row' => 1, 'user_id' => '__test_api_mp@k',  'product_name' => 'V1', 'cohort_labels' => [$cLabel]],
        ['row' => 2, 'user_id' => '__test_unknown@k', 'product_name' => 'V2', 'cohort_labels' => [$cLabel]],
        ['row' => 3, 'user_id' => '__test_api_mp@k',  'product_name' => 'V3', 'cohort_labels' => ['예비']],
    ],
]);
t('bulk_validate_200', $r['code'] === 200);
$rows = $r['body']['rows'] ?? [];
t('bulk_validate_ok', ($rows[0]['status'] ?? '') === 'OK');
t('bulk_validate_warn', ($rows[1]['status'] ?? '') === 'WARN_NO_MEMBER');
t('bulk_validate_err',  ($rows[2]['status'] ?? '') === 'ERROR_COHORT_LABEL');

// 11. bulk_apply — OK + WARN_NO_MEMBER 적용
$applyRows = array_filter($rows, fn($r) => !str_starts_with($r['status'], 'ERROR_'));
$r = req('POST', "$api?action=multipass_bulk_apply", $h, ['rows' => array_values($applyRows)]);
t('bulk_apply_200', $r['code'] === 200);
t('bulk_apply_count', ($r['body']['applied'] ?? 0) === 2);

// 정리
$db->exec("DELETE FROM multipass WHERE user_id LIKE '__test_api_mp%' OR user_id LIKE '__test_api_mp2%' OR user_id LIKE '__test_unknown%'");
$db->exec("DELETE FROM bootcamp_members WHERE user_id LIKE '__test_api_mp%'");

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
