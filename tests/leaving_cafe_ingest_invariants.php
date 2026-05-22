<?php
/**
 * leaving 회원의 cafe_member_key 매핑이 살아있을 때 ingestCafePosts 가
 * member_mission_checks 에 자동 체크를 INSERT 하는지.
 *
 * 사용: php tests/leaving_cafe_ingest_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/cafe/cafe_ingest.php';

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
    $cohortLabel = 'TEST_CAF_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $memberKey = 'TLEAV_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, cafe_member_key, member_status, is_active, stage_no, joined_at)
        VALUES (?, '나간', 'l', ?, 'leaving', 1, 1, CURDATE())")
       ->execute([$cohortId, $memberKey]);
    $memberId = (int)$db->lastInsertId();

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    ingestCafePosts([
        [
            'cafe_article_id' => 'TCAF_' . bin2hex(random_bytes(3)),
            'title' => 'test post',
            'member_key' => $memberKey,
            'nickname' => 'leaving_user',
            'board_type' => 'inner33',
            'posted_at' => $now,
            'assignment_date' => $today,
        ],
    ]);

    $row = $db->prepare("
        SELECT mmc.status, mt.code
        FROM member_mission_checks mmc
        JOIN mission_types mt ON mt.id = mmc.mission_type_id
        WHERE mmc.member_id = ? AND mmc.check_date = ?
    ");
    $row->execute([$memberId, $today]);
    $rows = $row->fetchAll(PDO::FETCH_ASSOC);

    $inner = null;
    foreach ($rows as $r) {
        if ($r['code'] === 'inner33') { $inner = $r; break; }
    }
    t('inner33 자동체크 INSERT 됨', $inner !== null);
    t('inner33 status=1', $inner && (int)$inner['status'] === 1);
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
