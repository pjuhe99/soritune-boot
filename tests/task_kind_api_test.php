<?php
/**
 * task_create assignment_kind (role/everyone/person) API 통합 테스트.
 * (cohort_people_search 통합은 Task 4 에서 같은 파일에 추가 예정.)
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
$stmt = $db->prepare("
    SELECT COUNT(*) FROM tasks
     WHERE title = ? AND group_kind = 'role' AND group_scope = 'coach'
");
$stmt->execute([$testTitle . '-role']);
$cnt = (int)$stmt->fetchColumn();
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
$stmt = $db->prepare("
    SELECT COUNT(*) FROM tasks
     WHERE title = ? AND group_kind = 'everyone' AND group_scope IS NULL
");
$stmt->execute([$testTitle . '-everyone']);
$cnt = (int)$stmt->fetchColumn();
t('kind=everyone row 의 group_scope NULL', $cnt >= 1, "cnt={$cnt}");

// ── kind=person, type=admin ─────
$stmt = $db->prepare("
    SELECT a.id FROM admins a
     JOIN admin_roles ar ON a.id = ar.admin_id
     WHERE a.is_active = 1 AND ar.role IN ('coach','sub_coach','head','subhead1','subhead2','operation')
       AND (a.cohort = ? OR a.cohort IS NULL)
     LIMIT 1
");
$stmt->execute([$cohort]);
$adminId = (int)$stmt->fetchColumn();
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
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM tasks
         WHERE title = ?
           AND group_kind = 'person'
           AND group_scope = ?
           AND assignee_admin_id = ?
    ");
    $stmt->execute([$testTitle . '-person-admin', "admin:{$adminId}", $adminId]);
    $cnt = (int)$stmt->fetchColumn();
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

// ── cohort_people_search ─────
$me = $db->query("SELECT name FROM admins WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$me) {
    t('cohort_people_search seed 존재', false, 'admins 활성 row 0');
} else {
    $q = mb_substr($me, 0, 1);
    $r = req('GET', "{$base}/api/admin.php?action=cohort_people_search&cohort=" . rawurlencode($cohort) . "&q=" . rawurlencode($q), $h);
    t('cohort_people_search 200',
        $r['code'] === 200 && !empty($r['body']['success']),
        'code=' . $r['code']);
    $people = $r['body']['people'] ?? null;
    t('cohort_people_search 결과 ≥ 1', is_array($people) && count($people) >= 1);
    $first = is_array($people) ? ($people[0] ?? null) : null;
    t('cohort_people_search 응답 shape',
        is_array($first)
        && in_array($first['type'] ?? null, ['admin','member'], true)
        && isset($first['id'], $first['name'], $first['role_labels']));
}

// q 빈 문자열 → 에러
$r = req('GET', "{$base}/api/admin.php?action=cohort_people_search&cohort=" . rawurlencode($cohort) . "&q=", $h);
t('cohort_people_search q 빈 거부', !empty($r['body']['error']));

// ── today_tasks / all_tasks_grouped 응답 필드 (Task 6) ─────
$r = req('GET', "{$base}/api/admin.php?action=today_tasks", $h);
if (!empty($r['body']['tasks'])) {
    $first = $r['body']['tasks'][0];
    t('today_tasks 응답에 group_kind 포함',
        array_key_exists('group_kind', $first), 'keys=' . implode(',', array_keys($first)));
}

$r = req('GET', "{$base}/api/admin.php?action=all_tasks_grouped&filter_role=all", $h);
if (!empty($r['body']['groups'])) {
    $first = $r['body']['groups'][0];
    t('all_tasks_grouped 응답에 group_kind 포함',
        array_key_exists('group_kind', $first));
    t('all_tasks_grouped 응답에 person_name 포함',
        array_key_exists('person_name', $first));
}

$r = req('GET', "{$base}/api/admin.php?action=all_tasks_grouped&filter_role=kind:everyone", $h);
t('filter_role=kind:everyone 200', $r['code'] === 200 && !empty($r['body']['success']));
if (!empty($r['body']['groups'])) {
    $first = $r['body']['groups'][0];
    t('kind:everyone row 의 group_kind=everyone',
        ($first['group_kind'] ?? null) === 'everyone',
        'got=' . ($first['group_kind'] ?? 'NULL'));
}
$r = req('GET', "{$base}/api/admin.php?action=all_tasks_grouped&filter_role=kind:person", $h);
t('filter_role=kind:person 200', $r['code'] === 200 && !empty($r['body']['success']));
if (!empty($r['body']['groups'])) {
    $first = $r['body']['groups'][0];
    t('kind:person row 의 group_kind=person',
        ($first['group_kind'] ?? null) === 'person',
        'got=' . ($first['group_kind'] ?? 'NULL'));
    t('kind:person row 의 person_name 비어있지 않음',
        !empty($first['person_name']),
        'got=' . ($first['person_name'] ?? 'NULL'));
}

cleanup($db, $cohort, $testTitle);
echo "\n--- {$pass} pass / {$fail} fail ---\n";
exit($fail ? 1 : 0);
