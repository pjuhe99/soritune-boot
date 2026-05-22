<?php
/**
 * expelled 회원의 cafe_member_key 매핑이 있어도 ingestCafePosts 가
 * 자동 체크를 INSERT 하지 않는지.
 *
 * 사용: php tests/expelled_cafe_ingest_invariants.php
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
    $cohortLabel = 'TEST_XCAF_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $memberKey = 'TXPL_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, real_name, nickname, cafe_member_key, member_status, is_active, stage_no, joined_at)
        VALUES (?, '퇴출', 'x', ?, 'expelled', 1, 1, CURDATE())")
       ->execute([$cohortId, $memberKey]);
    $memberId = (int)$db->lastInsertId();

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    ingestCafePosts([
        [
            'cafe_article_id' => 'TXCAF_' . bin2hex(random_bytes(3)),
            'title' => 'test post',
            'member_key' => $memberKey,
            'nickname' => 'expelled_user',
            'board_type' => 'inner33',
            'posted_at' => $now,
            'assignment_date' => $today,
        ],
    ]);

    // member_mission_checks INSERT 안 됨
    $cnt = $db->prepare("SELECT COUNT(*) FROM member_mission_checks WHERE member_id = ? AND check_date = ?");
    $cnt->execute([$memberId, $today]);
    t('expelled 회원의 카페 자동체크 INSERT 안 됨', (int)$cnt->fetchColumn() === 0);
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
