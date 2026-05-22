<?php
/**
 * cron init_daily_checks 의 활성 멤버 SELECT 가 leaving / out_of_group_management
 * 회원도 포함하는지 검증. SQL-level 회귀 가드 (실제 cron 실행은 안 함).
 *
 * 정책: '단체 활동 대상' = is_active=1 AND member_status NOT IN ('refunded','expelled')
 *
 * 사용: php tests/leaving_cron_invariants.php
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
    // 활성 cohort 시드 (오늘 포함되는 기간)
    $cohortLabel = 'TEST_LEAV_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    // group (active 회원용)
    $groupCode = 'tl_grp_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, stage_no, code)
                  VALUES (?, '테스트조', 1, ?)")
       ->execute([$cohortId, $groupCode]);
    $groupId = (int)$db->lastInsertId();

    // 4 회원: active / leaving / out_of_group_management / refunded
    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, CURDATE())");

    $ins->execute([$cohortId, $groupId, '활성', 'a', 'active', 1]);
    $idA = (int)$db->lastInsertId();
    $ins->execute([$cohortId, null, '나간', 'l', 'leaving', 1]);
    $idL = (int)$db->lastInsertId();
    $ins->execute([$cohortId, null, '강등', 'o', 'out_of_group_management', 1]);
    $idO = (int)$db->lastInsertId();
    $ins->execute([$cohortId, null, '환불', 'r', 'refunded', 0]);
    $idR = (int)$db->lastInsertId();
    // 운영자 수정 등으로 is_active=1 인 채로 환불 상태가 된 케이스 (rare)
    // — NOT IN ('refunded','expelled') 가드의 본 효과를 검증
    $ins->execute([$cohortId, null, '활성환불', 'ra', 'refunded', 1]);
    $idRactive = (int)$db->lastInsertId();
    $ins->execute([$cohortId, null, '퇴출', 'x', 'expelled', 1]);
    $idX = (int)$db->lastInsertId();

    // cron init_daily_checks 가 쓰는 변경 후 SELECT
    $today = date('Y-m-d');
    $sql = "
        SELECT bm.id
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.is_active = 1
          AND bm.member_status NOT IN ('refunded','expelled')
          AND c.start_date <= ? AND c.end_date >= ?
          AND bm.cohort_id = ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$today, $today, $cohortId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    sort($ids);

    $expected = [$idA, $idL, $idO];
    sort($expected);

    t('cron SELECT 가 active/leaving/OOM 3명을 포함', $ids === $expected,
      'got=' . json_encode($ids) . ' expected=' . json_encode($expected));
    t('cron SELECT 가 refunded 회원은 제외', !in_array($idR, $ids, true));
    t('cron SELECT 가 is_active=1 인 refunded 도 제외 (member_status 가드 효과)',
      !in_array($idRactive, $ids, true));
    t('cron SELECT 가 expelled(is_active=1) 도 제외 (member_status 가드 효과)',
      !in_array($idX, $ids, true));

} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
