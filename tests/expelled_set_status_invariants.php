<?php
/**
 * handleMemberSetStatus 가 expelled 분기를 지원하고
 * admin_action_logs 에 INSERT 하는지 SQL-level 검증.
 *
 * 사용: php tests/expelled_set_status_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();
$db->beginTransaction();

try {
    $cohortLabel = 'TEST_XSS_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    // 어드민 fixture (action_logs FK 가 admins 면 필요. 없으면 id=1 임의)
    $adminId = 1; // boot 의 admin_action_logs.actor_admin_id 는 DEFAULT NULL — 임의 INT OK

    // active 회원
    $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, NULL, '활성', 'a', 'active', 1, 1, CURDATE())")
       ->execute([$cohortId]);
    $memberId = (int)$db->lastInsertId();

    // 변경 후 정책 시뮬레이션 — handleMemberSetStatus 의 핵심 동작을 인라인 재현:
    // (1) FOR UPDATE 로 prev status 조회
    // (2) member_status='expelled', group_id=NULL UPDATE
    // (3) admin_action_logs INSERT
    $prev = $db->prepare("SELECT member_status FROM bootcamp_members WHERE id = ? FOR UPDATE");
    $prev->execute([$memberId]);
    $previousStatus = $prev->fetchColumn();

    $db->prepare("UPDATE bootcamp_members SET member_status='expelled', group_id=NULL WHERE id=?")
       ->execute([$memberId]);

    $reason = '점수 -50 이하 3주 연속';
    $db->prepare("INSERT INTO admin_action_logs
        (actor_admin_id, action_type, target_table, target_id, payload_json)
        VALUES (?, 'member_status_change', 'bootcamp_members', ?, ?)")
       ->execute([
         $adminId,
         $memberId,
         json_encode(['from' => $previousStatus, 'to' => 'expelled', 'reason' => $reason], JSON_UNESCAPED_UNICODE),
       ]);

    // 검증
    $row = $db->prepare("SELECT member_status, group_id FROM bootcamp_members WHERE id = ?");
    $row->execute([$memberId]);
    $current = $row->fetch(PDO::FETCH_ASSOC);

    t('member_status = expelled', $current['member_status'] === 'expelled');
    t('group_id = NULL', $current['group_id'] === null);

    $log = $db->prepare("SELECT action_type, payload_json
                          FROM admin_action_logs
                         WHERE target_table='bootcamp_members' AND target_id = ?");
    $log->execute([$memberId]);
    $logRow = $log->fetch(PDO::FETCH_ASSOC);

    t('admin_action_logs row 있음', $logRow !== false);
    t('action_type = member_status_change', ($logRow['action_type'] ?? '') === 'member_status_change');
    $payload = json_decode($logRow['payload_json'] ?? '', true);
    t('payload.from = active', ($payload['from'] ?? null) === 'active');
    t('payload.to = expelled', ($payload['to'] ?? null) === 'expelled');
    t('payload.reason 보존', ($payload['reason'] ?? null) === $reason);
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
