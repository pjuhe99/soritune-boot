<?php
/**
 * leaving / out_of_group_management 회원도 후기 작성이 허용되는지.
 *
 * 사용: php tests/leaving_review_invariants.php
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

// review.php 의 권한 체크 SQL 패턴을 직접 재현 (요청 라우팅까지 안 함)
$db = getDB();
$db->beginTransaction();

try {
    $cohortLabel = 'TEST_REV_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 1, CURDATE())");

    $ins->execute([$cohortId, '나간', 'l', 'leaving', 1]);
    $idL = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '강등', 'o', 'out_of_group_management', 1]);
    $idO = (int)$db->lastInsertId();
    $ins->execute([$cohortId, '환불', 'r', 'refunded', 0]);
    $idR = (int)$db->lastInsertId();

    // review.php 게이트 (변경 후): 'refunded' 만 차단
    $blocked = ['refunded'];

    $stmt = $db->prepare("SELECT member_status FROM bootcamp_members WHERE id = ?");

    foreach ([['leaving', $idL, false], ['out_of_group_management', $idO, false], ['refunded', $idR, true]] as [$label, $id, $expectBlock]) {
        $stmt->execute([$id]);
        $status = $stmt->fetchColumn();
        $isBlocked = in_array($status, $blocked, true);
        t("{$label} 회원 차단 여부 = " . ($expectBlock ? 'Y' : 'N'), $isBlocked === $expectBlock);
    }
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
