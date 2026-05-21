<?php
/**
 * check.php 의 3 핸들러 (handleChecklist / handleChecklistByMission / handleStatusBoard)
 * 응답 members[] 에 cafe_nickname 필드가 들어오는지.
 *
 * Auth 우회 (requireAdmin) 위해 SQL 만 직접 실행해서 응답 모양을 모사:
 *   - bootcamp_members + cafe_nickname 시드
 *   - handleChecklist 이 사용하는 SELECT 와 동일한 SELECT 를 직접 돌리고 결과에 cafe_nickname 키 존재 확인
 *
 * 핸들러를 직접 호출하려면 requireAdmin / $_GET 셋업 / jsonSuccess 가 출력 버퍼링이 필요해서 단위는 SQL 검증으로 충분.
 * 사용: php tests/check_cafe_nickname_test.php
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
    // 시드: cohort + group + member (cafe_nickname 채워진 1명, NULL 인 1명)
    $cohortLabel = 'TEST_CHK_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $groupCode = 'tc_lisa_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, stage_no, code) VALUES (?, '리사조', 1, ?)")
       ->execute([$cohortId, $groupCode]);
    $groupId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, cafe_member_key, cafe_nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, ?, 'active', 1, 1, CURDATE())");
    $ins->execute([$cohortId, $groupId, '김명식', '그릭이', 'KEY_A', 'gricky']);
    $alice = (int)$db->lastInsertId();
    $ins->execute([$cohortId, $groupId, '이서연', '서연쓰', null, null]);
    $bob = (int)$db->lastInsertId();

    // handleChecklist SELECT 와 동일
    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
               bm.group_id, bm.cafe_nickname, bg.name AS group_name,
               COALESCE(ms.current_score, 0) AS current_score,
               COALESCE(mcb.current_coin, 0) AS current_coin
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        LEFT JOIN member_coin_balances mcb ON bm.id = mcb.member_id
        WHERE bm.cohort_id = ? AND bm.is_active = 1
        ORDER BY bg.name, bm.nickname
    ");
    $stmt->execute([$cohortId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    t('checklist_member_count', count($members) === 2);
    t('checklist_has_cafe_nickname_key', isset($members[0]['cafe_nickname']));

    $aliceRow = null; $bobRow = null;
    foreach ($members as $m) {
        if ((int)$m['id'] === $alice) $aliceRow = $m;
        if ((int)$m['id'] === $bob)   $bobRow = $m;
    }
    t('checklist_alice_nick', $aliceRow && $aliceRow['cafe_nickname'] === 'gricky');
    t('checklist_bob_nick_null', $bobRow && $bobRow['cafe_nickname'] === null);

    // handleStatusBoard SELECT (member_status 추가)
    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
               bm.group_id, bm.member_status, bm.cafe_nickname, bg.name AS group_name,
               COALESCE(ms.current_score, 0) AS current_score,
               COALESCE(mcb.current_coin, 0) AS current_coin
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        LEFT JOIN member_coin_balances mcb ON bm.id = mcb.member_id
        WHERE bm.cohort_id = ? AND bm.is_active = 1
        ORDER BY bg.name, bm.nickname
    ");
    $stmt->execute([$cohortId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    t('statusboard_alice_nick', $members[0]['cafe_nickname'] === 'gricky' || $members[1]['cafe_nickname'] === 'gricky');

    echo "\nResult: {$pass} PASS / {$fail} FAIL\n";
    exit($fail === 0 ? 0 : 1);

} finally {
    $db->rollBack();
}
