<?php
/**
 * task_create / cohort_people_search API 통합 테스트.
 *
 * 사용:
 *   ADMIN_COOKIE='PHPSESSID_ADMIN=...op...' \
 *   DEV_BASE='https://dev-boot.soritune.com' \
 *     php tests/task_kind_api_test.php
 *
 * 사전:
 *   - operation 권한 admin 로그인 쿠키
 *   - cohorts 에 현재 cohort 존재 + 최소 1명의 활성 admin 또는 leader
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

$base   = rtrim(getenv('DEV_BASE') ?: 'https://dev-boot.soritune.com', '/');
$cookie = getenv('ADMIN_COOKIE') ?: '';
if (!$cookie) { echo "ADMIN_COOKIE 환경변수 필수\n"; exit(2); }

require_once __DIR__ . '/../public_html/config.php';
$db = getDB();

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}
function req(string $method, string $url, array $headers, ?array $json = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($body ?: '', true) ?? ['raw' => $body]];
}

$h = ['Cookie: ' . $cookie, 'Content-Type: application/json'];
$cohort = $db->query("SELECT cohort FROM cohorts ORDER BY start_date DESC LIMIT 1")->fetchColumn();
if (!$cohort) { echo "FAIL setup: cohorts 비어있음\n"; exit(3); }

$testTitle = '[TEST] kind-' . time();

// helper: 끝나면 정리
function cleanup(PDO $db, string $cohort, string $titlePrefix): void {
    $db->prepare("DELETE FROM tasks WHERE cohort = ? AND title LIKE ?")->execute([$cohort, $titlePrefix . '%']);
}
cleanup($db, $cohort, $testTitle);

// ── kind=role 회귀 ─────
$r = req('POST', "{$base}/api/admin.php?action=task_create", $h, [
    'title' => $testTitle . '-role',
    'assignment_kind' => 'role',
    'roles' => ['coach'],
    'cohort' => $cohort,
    'date_mode' => 'direct',
    'start_date' => date('Y-m-d'),
    'end_date'   => date('Y-m-d'),
]);
t('kind=role 생성 성공', $r['code'] === 200 && !empty($r['body']['success']), 'code=' . $r['code']);
$cnt = (int)$db->query("
    SELECT COUNT(*) FROM tasks
     WHERE title = '" . $testTitle . "-role' AND group_kind='role' AND group_scope='coach'
")->fetchColumn();
t('kind=role row 의 group_kind/scope 백필', $cnt >= 1, "cnt={$cnt}");

// ── kind=everyone ─────
$r = req('POST', "{$base}/api/admin.php?action=task_create", $h, [
    'title' => $testTitle . '-everyone',
    'assignment_kind' => 'everyone',
    'cohort' => $cohort,
    'date_mode' => 'direct',
    'start_date' => date('Y-m-d'),
    'end_date'   => date('Y-m-d'),
]);
t('kind=everyone 생성 성공', $r['code'] === 200 && !empty($r['body']['success']), 'code=' . $r['code']);
$cnt = (int)$db->query("
    SELECT COUNT(*) FROM tasks
     WHERE title = '" . $testTitle . "-everyone' AND group_kind='everyone' AND group_scope IS NULL
")->fetchColumn();
t('kind=everyone row 의 group_scope NULL', $cnt >= 1, "cnt={$cnt}");

// ── kind=person, type=admin ─────
$adminId = (int)$db->query("
    SELECT a.id FROM admins a
     JOIN admin_roles ar ON a.id = ar.admin_id
     WHERE a.is_active = 1 AND ar.role IN ('coach','sub_coach','head','subhead1','subhead2','operation')
       AND (a.cohort = '{$cohort}' OR a.cohort IS NULL)
     LIMIT 1
")->fetchColumn();
if (!$adminId) { echo "skip person/admin: 활성 admin 없음\n"; }
else {
    $r = req('POST', "{$base}/api/admin.php?action=task_create", $h, [
        'title' => $testTitle . '-person-admin',
        'assignment_kind' => 'person',
        'target_person' => ['type' => 'admin', 'id' => $adminId],
        'cohort' => $cohort,
        'date_mode' => 'direct',
        'start_date' => date('Y-m-d'),
        'end_date'   => date('Y-m-d'),
    ]);
    t('kind=person admin 생성 성공', $r['code'] === 200 && !empty($r['body']['success']), 'code=' . $r['code']);
    $cnt = (int)$db->query("
        SELECT COUNT(*) FROM tasks
         WHERE title = '" . $testTitle . "-person-admin'
           AND group_kind = 'person'
           AND group_scope = 'admin:{$adminId}'
           AND assignee_admin_id = {$adminId}
    ")->fetchColumn();
    t('kind=person admin row 의 scope/assignee 일치', $cnt === 1, "cnt={$cnt}");
}

// ── kind=person, target 비활성 거부 ─────
$r = req('POST', "{$base}/api/admin.php?action=task_create", $h, [
    'title' => $testTitle . '-person-bad',
    'assignment_kind' => 'person',
    'target_person' => ['type' => 'admin', 'id' => 99999999],
    'cohort' => $cohort,
    'date_mode' => 'direct',
    'start_date' => date('Y-m-d'),
    'end_date'   => date('Y-m-d'),
]);
t('kind=person 존재X 거부', !empty($r['body']['error']) || $r['code'] >= 400);

cleanup($db, $cohort, $testTitle);
echo "\n--- {$pass} pass / {$fail} fail ---\n";
exit($fail ? 1 : 0);
