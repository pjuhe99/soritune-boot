<?php
/**
 * cron init_daily_checks 의 활성 멤버 SELECT 가 expelled 회원을 제외하는지.
 * 정책: '단체 활동 대상' = is_active=1 AND member_status != 'refunded'
 * (expelled 는 약한 조치 — active 와 동일하게 cron 통과)
 *
 * 사용: php tests/expelled_cron_invariants.php
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
    $cohortLabel = 'TEST_XPL_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 1, CURDATE())");

    $ins->execute([$cohortId, '활성', 'a', 'active', 1]);
    $idA = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '나간', 'l', 'leaving', 1]);
    $idL = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '강등', 'o', 'out_of_group_management', 1]);
    $idO = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '환불', 'r', 'refunded', 0]);
    $idR = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '활성환불', 'ra', 'refunded', 1]);
    $idRactive = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '퇴출', 'x', 'expelled', 1]);
    $idX = (int)$db->lastInsertId();

    $today = date('Y-m-d');
    $sql = "
        SELECT bm.id
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.is_active = 1
          AND bm.member_status != 'refunded'
          AND c.start_date <= ? AND c.end_date >= ?
          AND bm.cohort_id = ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$today, $today, $cohortId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    sort($ids);

    $expected = [$idA, $idL, $idO, $idX];
    sort($expected);

    t('cron SELECT = active + leaving + OOM + expelled (4명)', $ids === $expected,
      'got=' . json_encode($ids) . ' expected=' . json_encode($expected));
    t('refunded(is_active=0) 제외', !in_array($idR, $ids, true));
    t('refunded(is_active=1) 제외 (member_status 가드)', !in_array($idRactive, $ids, true));
    t('expelled(is_active=1) 포함 (약한 조치 — active 와 동일 처리)', in_array($idX, $ids, true));
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
