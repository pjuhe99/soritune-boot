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
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $r = inspectIssueMission($db, $issueId);
    $expectedFromMin = $fx['cohort_start'];
    t('T5 range >= cohort.start_date', $r['inspected_range']['from'] >= $expectedFromMin,
       "from={$r['inspected_range']['from']} cohort_start={$expectedFromMin}");
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

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
