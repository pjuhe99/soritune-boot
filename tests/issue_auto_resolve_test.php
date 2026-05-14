<?php
/**
 * Issue auto-resolve 단위/통합 테스트 (CLI).
 *
 * 사용: php tests/issue_auto_resolve_test.php
 *
 * 사전: DEV DB. 테스트 회원 user_id 는 '__test_iar_%' 로 prefix.
 * 모든 테스트는 setUp 에서 fresh fixture 를 만들고 tearDown 에서 삭제한다.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/issue_report.php';

$db = getDB();
$pass = 0; $fail = 0;

function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// ── 픽스처 헬퍼 ──
function setupFixture(PDO $db): array {
    teardownFixture($db);

    $cohort = $db->query("SELECT id, start_date FROM cohorts WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1")->fetch();
    if (!$cohort) { echo "SKIP — active cohort 없음\n"; exit(0); }

    $db->prepare("
        INSERT INTO bootcamp_members (cohort_id, real_name, nickname, user_id, member_status)
        VALUES (?, 'IAR테스트', 'iar01', '__test_iar_01@k', 'active')
    ")->execute([(int)$cohort['id']]);
    $memberId = (int)$db->lastInsertId();

    return [
        'cohort_id'  => (int)$cohort['id'],
        'cohort_start' => $cohort['start_date'],
        'member_id'  => $memberId,
    ];
}

function teardownFixture(PDO $db): void {
    $db->exec("DELETE FROM issue_report_logs WHERE issue_id IN (SELECT id FROM issue_reports WHERE member_id IN (SELECT id FROM bootcamp_members WHERE user_id LIKE '__test_iar_%'))");
    $db->exec("DELETE FROM issue_reports WHERE member_id IN (SELECT id FROM bootcamp_members WHERE user_id LIKE '__test_iar_%')");
    $db->exec("DELETE FROM member_mission_checks WHERE member_id IN (SELECT id FROM bootcamp_members WHERE user_id LIKE '__test_iar_%')");
    $db->exec("DELETE FROM bootcamp_members WHERE user_id LIKE '__test_iar_%'");
}

function insertIssue(PDO $db, int $memberId, int $cohortId, string $issueType, ?string $createdAt = null): int {
    $createdAt = $createdAt ?: date('Y-m-d H:i:s');
    $db->prepare("
        INSERT INTO issue_reports (member_id, cohort_id, issue_type, status, created_at)
        VALUES (?, ?, ?, 'pending', ?)
    ")->execute([$memberId, $cohortId, $issueType, $createdAt]);
    return (int)$db->lastInsertId();
}

function insertCheck(PDO $db, int $memberId, int $cohortId, string $missionCode, string $date, int $status): void {
    $missionId = (int)$db->query("SELECT id FROM mission_types WHERE code = " . $db->quote($missionCode))->fetchColumn();
    if (!$missionId) throw new RuntimeException("mission_types.$missionCode 없음");
    $db->prepare("
        INSERT INTO member_mission_checks (member_id, cohort_id, mission_type_id, check_date, status, source)
        VALUES (?, ?, ?, ?, ?, 'manual')
    ")->execute([$memberId, $cohortId, $missionId, $date, $status]);
}

// ── T1: unsupported issue_type ──
{
    $fx = setupFixture($db);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'study_create');
    $r = inspectIssueMission($db, $issueId);
    t('T1 unsupported.status', $r['mission_status'] === 'unsupported');
    t('T1 unsupported.no checks', empty($r['unchecked_dates']) && empty($r['checked_dates']));
    teardownFixture($db);
}

// ── T2: no_data — 검사 범위에 row 없음 ──
{
    $fx = setupFixture($db);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $r = inspectIssueMission($db, $issueId);
    t('T2 no_data.status', $r['mission_status'] === 'no_data', "got: {$r['mission_status']}");
    t('T2 no_data.range from', !empty($r['inspected_range']['from']));
    t('T2 no_data.range to', !empty($r['inspected_range']['to']));
    teardownFixture($db);
}

// ── T3: all_checked — 범위 내 모든 row status=1 ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $today, 1);
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $yesterday, 1);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $r = inspectIssueMission($db, $issueId);
    t('T3 all_checked.status', $r['mission_status'] === 'all_checked', "got: {$r['mission_status']}");
    t('T3 all_checked.dates', count($r['checked_dates']) === 2);
    t('T3 all_checked.no unchecked', empty($r['unchecked_dates']));
    teardownFixture($db);
}

// ── T4: has_unchecked — 하나라도 status=0 ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $today, 1);
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $yesterday, 0);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $r = inspectIssueMission($db, $issueId);
    t('T4 has_unchecked.status', $r['mission_status'] === 'has_unchecked');
    t('T4 has_unchecked.dates', $r['unchecked_dates'] === [$yesterday]);
    teardownFixture($db);
}

// ── T5: cohort.start_date 가 7일 전보다 더 최근이면 range.from 이 좁혀짐 ──
{
    $fx = setupFixture($db);
    // 문의 created_at 을 cohort.start_date 당일로 강제 → 7일 lookback 은
    // cohort 시작일 이전이라 반드시 cohort.start_date 로 clamp 되어야 한다.
    $createdAt = $fx['cohort_start'] . ' 12:00:00';
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33', $createdAt);
    $r = inspectIssueMission($db, $issueId);
    t('T5 range.from clamped to cohort_start',
       $r['inspected_range']['from'] === $fx['cohort_start'],
       "from={$r['inspected_range']['from']} cohort_start={$fx['cohort_start']}");
    t('T5 range.to is created date',
       $r['inspected_range']['to'] === $fx['cohort_start']);
    teardownFixture($db);
}

// ── T6: 다른 issue_type 도 매핑됨 (malkka → speak_mission) ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'speak_mission', $today, 1);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'malkka');
    $r = inspectIssueMission($db, $issueId);
    t('T6 malkka all_checked', $r['mission_status'] === 'all_checked', "got: {$r['mission_status']}");
    teardownFixture($db);
}

// ── T7: attemptAutoResolveIssue all_checked → resolved ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $today, 1);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $r = attemptAutoResolveIssue($db, $issueId, /*adminId*/ 1);
    t('T7 ok=true', $r['ok'] === true, json_encode($r));
    $row = $db->query("SELECT status, admin_note, resolved_by FROM issue_reports WHERE id={$issueId}")->fetch();
    t('T7 status=resolved', $row['status'] === 'resolved');
    t('T7 admin_note prefix', is_string($row['admin_note']) && strpos($row['admin_note'], 'auto:') === 0);
    t('T7 resolved_by', (int)$row['resolved_by'] === 1);
    $logCnt = (int)$db->query("SELECT COUNT(*) FROM issue_report_logs WHERE issue_id={$issueId} AND new_status='resolved'")->fetchColumn();
    t('T7 audit log', $logCnt === 1);
    teardownFixture($db);
}

// ── T8: has_unchecked 거부 ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $today, 0);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $r = attemptAutoResolveIssue($db, $issueId, 1);
    t('T8 ok=false', $r['ok'] === false);
    t('T8 reason=not_eligible', $r['reason'] === 'not_eligible');
    $row = $db->query("SELECT status FROM issue_reports WHERE id={$issueId}")->fetch();
    t('T8 status unchanged', $row['status'] === 'pending');
    teardownFixture($db);
}

// ── T9: 이미 resolved 인 row → 거부 ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $today, 1);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $db->exec("UPDATE issue_reports SET status='resolved' WHERE id={$issueId}");
    $r = attemptAutoResolveIssue($db, $issueId, 1);
    t('T9 ok=false', $r['ok'] === false);
    t('T9 reason=not_pending', $r['reason'] === 'not_pending');
    teardownFixture($db);
}

// ── T10: bulk — 섞여 있는 pending 중 all_checked 만 resolve ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    // A: naemat33 + all_checked
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $today, 1);
    $idA = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');

    // B: zoom + has_unchecked (mission 다름)
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'zoom_daily', $today, 1);
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'zoom_daily', $yesterday, 0);
    $idB = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'zoom');

    // C: study_create + unsupported
    $idC = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'study_create');

    // D: malkka + no_data
    $idD = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'malkka');

    $r = bulkAutoResolveIssues($db, [$idA, $idB, $idC, $idD], /*adminId*/ 1);
    t('T10 resolved A only', $r['resolved_ids'] === [$idA], json_encode($r));
    t('T10 skipped 3', count($r['skipped']) === 3);

    $statuses = $db->query("SELECT id, status FROM issue_reports WHERE id IN ({$idA},{$idB},{$idC},{$idD}) ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
    t('T10 A resolved', $statuses[$idA] === 'resolved');
    t('T10 B pending',  $statuses[$idB] === 'pending');
    t('T10 C pending',  $statuses[$idC] === 'pending');
    t('T10 D pending',  $statuses[$idD] === 'pending');
    teardownFixture($db);
}

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
