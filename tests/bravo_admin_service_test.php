<?php
/**
 * BRAVO 관리자 서비스 통합 테스트. DEV DB transaction rollback.
 * 사용: php tests/bravo_admin_service_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/bravo.php';

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
    // 테스트 cohort + 회원 2명 (user_id 보유)
    $label = 'TEST_BRV_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$label, $label]);
    $cohortId = (int)$db->lastInsertId();

    $uidA = 'brv_a_' . bin2hex(random_bytes(3));
    $uidB = 'brv_b_' . bin2hex(random_bytes(3));
    $ins = $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, member_status, is_active, stage_no, joined_at) VALUES (?, ?, ?, ?, ?, 'active', 1, 1, CURDATE())");
    $ins->execute([$cohortId, '김알파', '알파', '01000000001', $uidA]);
    $ins->execute([$cohortId, '이베타', '베타', '01000000002', $uidB]);

    // member_history_stats: A는 user_id-row 로 completed 7, B는 phone-row 로 completed 2
    $db->prepare("INSERT INTO member_history_stats (user_id, stage1_participation_count, stage2_participation_count, completed_bootcamp_count, last_calculated_at) VALUES (?, 0, 0, 7, NOW())")
       ->execute([$uidA]);
    $db->prepare("INSERT INTO member_history_stats (phone, stage1_participation_count, stage2_participation_count, completed_bootcamp_count, last_calculated_at) VALUES (?, 0, 0, 2, NOW())")
       ->execute(['01000000002']);

    // --- bravoMemberList: 기수 회원 + 자동 completed + 계산 등급 ---
    $list = bravoMemberList($db, $label);
    $byUid = [];
    foreach ($list as $r) $byUid[$r['user_id']] = $r;

    t('list 2명 반환', count($list) === 2, 'count=' . count($list));
    t('A completed 7 (user_id-row)', (int)$byUid[$uidA]['completed_bootcamp_count'] === 7);
    t('A override 없음 → 유효회독 7 → 등급 [1,2]', $byUid[$uidA]['eligible_levels'] === [1,2], json_encode($byUid[$uidA]['eligible_levels']));
    t('B completed 2 (phone-row 폴백)', (int)$byUid[$uidB]['completed_bootcamp_count'] === 2);
    t('B 등급 없음', $byUid[$uidB]['eligible_levels'] === []);

    // --- bravoMemberUpsert: A에 override 10 + grant [3] + notes ---
    bravoMemberUpsert($db, $uidA, 10, [3], '예외 승인', 99);
    $list2 = bravoMemberList($db, $label);
    $a2 = null; foreach ($list2 as $r) if ($r['user_id'] === $uidA) $a2 = $r;
    t('A override 10 반영', (int)$a2['review_count_override'] === 10);
    t('A granted_levels [3]', $a2['granted_levels'] === [3], json_encode($a2['granted_levels']));
    t('A notes 반영', $a2['notes'] === '예외 승인');
    t('A 등급 override10 ∪ grant3 → [1,2,3]', $a2['eligible_levels'] === [1,2,3], json_encode($a2['eligible_levels']));

    // --- upsert 멱등: 같은 user_id 재호출 시 update (중복 row 없음) ---
    bravoMemberUpsert($db, $uidA, null, [], '메모수정', 99);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM bravo_member_settings WHERE user_id = " . $db->quote($uidA))->fetchColumn();
    t('upsert 멱등 (row 1개)', $cnt === 1, 'cnt=' . $cnt);
    $list3 = bravoMemberList($db, $label);
    $a3 = null; foreach ($list3 as $r) if ($r['user_id'] === $uidA) $a3 = $r;
    t('A override NULL 복귀 → 자동 7 → [1,2]', $a3['eligible_levels'] === [1,2], json_encode($a3['eligible_levels']));
    t('A granted 비움', $a3['granted_levels'] === []);

    $db->rollBack();
} catch (\Throwable $e) {
    $db->rollBack();
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
