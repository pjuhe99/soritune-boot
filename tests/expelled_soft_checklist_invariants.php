<?php
/**
 * 체크리스트·현황판 의 include_expelled 토글이
 * handleChecklist / handleChecklistByMission / handleStatusBoard
 * 3개 핸들러 모두에서 일관되게 작동하는지 SQL-level 검증.
 *
 * 사용: php tests/expelled_soft_checklist_invariants.php
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
    $cohortLabel = 'TEST_XSOFT_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, code) VALUES (?, 'TEST_G', 'tg')")
       ->execute([$cohortId]);
    $groupId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 1, 1, CURDATE())");

    $ins->execute([$cohortId, $groupId, '활성', 'a', 'active']);
    $idA = (int)$db->lastInsertId();
    $ins->execute([$cohortId, $groupId, '퇴출', 'x', 'expelled']);  // group_id 보존된 신규 expel 케이스
    $idX = (int)$db->lastInsertId();

    // 체크리스트·현황판 WHERE 패턴 (handleChecklist / handleChecklistByMission / handleStatusBoard 공통)
    function runChecklistQuery(PDO $db, int $cohortId, bool $includeExpelled): array {
        $where = ["bm.cohort_id = ?", "bm.is_active = 1"];
        $params = [$cohortId];
        if (!$includeExpelled) {
            $where[] = "bm.member_status != 'expelled'";
        }
        $sql = "SELECT id FROM bootcamp_members bm WHERE " . implode(' AND ', $where) . " ORDER BY id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $idsDefault = runChecklistQuery($db, $cohortId, false);
    $idsToggleOn = runChecklistQuery($db, $cohortId, true);

    t('기본 (토글 off): active 만 보임', $idsDefault === [$idA],
      'got=' . json_encode($idsDefault));
    t('기본 (토글 off): expelled 안 보임', !in_array($idX, $idsDefault, true));
    t('토글 on: active + expelled 둘 다 보임', count($idsToggleOn) === 2 && in_array($idA, $idsToggleOn) && in_array($idX, $idsToggleOn),
      'got=' . json_encode($idsToggleOn));

    // group_id 필터까지 함께 걸어도 신규 expelled (group 보존) 는 토글로 보여야 함
    function runWithGroup(PDO $db, int $cohortId, int $groupId, bool $includeExpelled): array {
        $where = ["bm.cohort_id = ?", "bm.is_active = 1", "bm.group_id = ?"];
        $params = [$cohortId, $groupId];
        if (!$includeExpelled) $where[] = "bm.member_status != 'expelled'";
        $sql = "SELECT id FROM bootcamp_members bm WHERE " . implode(' AND ', $where) . " ORDER BY id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $idsGroupDefault = runWithGroup($db, $cohortId, $groupId, false);
    $idsGroupToggleOn = runWithGroup($db, $cohortId, $groupId, true);

    t('group 필터 + 토글 off: active 만', $idsGroupDefault === [$idA]);
    t('group 필터 + 토글 on: active + expelled (신규 케이스 group 보존)', count($idsGroupToggleOn) === 2);

    // 기존 expelled (group_id=NULL) 는 group 필터로 토글 켜도 안 보임 — spec §7.4 한계
    $ins2 = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, NULL, '구퇴출', 'oldx', 'expelled', 1, 1, CURDATE())");
    $ins2->execute([$cohortId]);
    $idOldX = (int)$db->lastInsertId();

    $idsGroupToggleOnWithOld = runWithGroup($db, $cohortId, $groupId, true);
    t('기존 expelled (group_id=NULL) 는 group 필터 + 토글 on 이어도 안 보임 (한계 §7.4)',
       !in_array($idOldX, $idsGroupToggleOnWithOld, true));

    $idsNoGroupToggleOn = runChecklistQuery($db, $cohortId, true);
    t('group 필터 없이 + 토글 on: 기존 expelled (group_id=NULL) 도 보임',
       in_array($idOldX, $idsNoGroupToggleOn, true));
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
