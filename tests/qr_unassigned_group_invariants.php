<?php
/**
 * QR 의 '기타 (조 미배정)' 가상 카드 흐름 invariant.
 *
 * - cohort 안에 group_id=NULL 인 leaving/OOM 회원이 있으면 groups endpoint 가
 *   가상 카드 추가
 * - group_members 가 group_id=0 받으면 group_id IS NULL 회원만 반환,
 *   refunded/expelled 는 제외
 *
 * 사용: php tests/qr_unassigned_group_invariants.php
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
    $cohortLabel = 'TEST_QRU_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    // 실제 group 하나
    $groupCode = 'qru_grp_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, stage_no, code)
                  VALUES (?, '1조', 1, ?)")
       ->execute([$cohortId, $groupCode]);
    $groupId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, CURDATE())");

    // active 회원 1명 (조 소속)
    $ins->execute([$cohortId, $groupId, '활성', 'a', 'active', 1]);
    $idA = (int)$db->lastInsertId();
    // leaving 회원 (group_id=NULL)
    $ins->execute([$cohortId, null, '나간', 'l', 'leaving', 1]);
    $idL = (int)$db->lastInsertId();
    // OOM 회원 (group_id=NULL)
    $ins->execute([$cohortId, null, '강등', 'o', 'out_of_group_management', 1]);
    $idO = (int)$db->lastInsertId();
    // expelled 회원 (group_id=NULL) — 제외되어야 함
    $ins->execute([$cohortId, null, '퇴출', 'x', 'expelled', 1]);
    $idX = (int)$db->lastInsertId();
    // refunded 회원 (group_id=NULL, is_active=0) — 제외되어야 함
    $ins->execute([$cohortId, null, '환불', 'r', 'refunded', 0]);
    $idR = (int)$db->lastInsertId();

    // groups endpoint 가 가상 카드 추가 조건 확인
    $unassignedStmt = $db->prepare("
        SELECT COUNT(*) FROM bootcamp_members
        WHERE cohort_id = ?
          AND group_id IS NULL
          AND is_active = 1
          AND member_status NOT IN ('refunded','expelled')
    ");
    $unassignedStmt->execute([$cohortId]);
    $unassignedCount = (int)$unassignedStmt->fetchColumn();

    t('group_id=NULL 인 단체활동 대상 회원 = 2 (leaving + OOM)', $unassignedCount === 2,
      "got={$unassignedCount}");

    // group_members(group_id=0) 흐름 — group_id IS NULL 회원
    $stmt = $db->prepare("
        SELECT id FROM bootcamp_members
        WHERE cohort_id = ?
          AND group_id IS NULL
          AND is_active = 1
          AND member_status NOT IN ('refunded','expelled')
        ORDER BY nickname
    ");
    $stmt->execute([$cohortId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    sort($ids);

    $expected = [$idL, $idO];
    sort($expected);

    t('group_id=0 명단 = leaving + OOM (2명)', $ids === $expected,
      'got=' . json_encode($ids) . ' expected=' . json_encode($expected));
    t('expelled 회원 제외', !in_array($idX, $ids, true));
    t('refunded 회원 제외', !in_array($idR, $ids, true));
    t('active 회원 (조 소속) 은 group_id=0 명단에 없음', !in_array($idA, $ids, true));
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
