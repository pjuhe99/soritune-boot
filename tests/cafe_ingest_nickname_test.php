<?php
/**
 * ingestCafePosts() 가 bootcamp_members.cafe_nickname 을 sync 하는지.
 * DEV DB transaction rollback 으로 격리.
 * 사용: php tests/cafe_ingest_nickname_test.php
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
    // 시드: cohort + group + member with cafe_member_key
    $cohortLabel = 'TEST_NICK_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $groupCode = 'tn_lisa_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, stage_no, code) VALUES (?, '리사조', 1, ?)")
       ->execute([$cohortId, $groupCode]);
    $groupId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, cafe_member_key, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 'active', 1, 1, CURDATE())");
    $ins->execute([$cohortId, $groupId, '김명식', '그릭이', 'KEY_ALICE']);
    $alice = (int)$db->lastInsertId();
    $ins->execute([$cohortId, $groupId, '이서연', '서연쓰', null]); // 매핑 안 됨
    $bob = (int)$db->lastInsertId();

    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    // ── Case 1: 신규 post → cafe_nickname 채움
    ingestCafePosts([
        ['cafe_article_id' => 'TN_ART1', 'title' => 't1', 'member_key' => 'KEY_ALICE', 'nickname' => 'gricky',
         'board_type' => 'inner33', 'posted_at' => $now, 'assignment_date' => $today],
    ]);
    $nick = $db->query("SELECT cafe_nickname FROM bootcamp_members WHERE id={$alice}")->fetchColumn();
    t('case1_alice_nick_set', $nick === 'gricky', "got: " . var_export($nick, true));

    // ── Case 2: 같은 회원 글이 batch 안에 여러 개 → 마지막값으로 통일, UPDATE 1회만 (cache hit)
    ingestCafePosts([
        ['cafe_article_id' => 'TN_ART2', 'title' => 't2', 'member_key' => 'KEY_ALICE', 'nickname' => 'gricky2',
         'board_type' => 'inner33', 'posted_at' => $now, 'assignment_date' => $today],
        ['cafe_article_id' => 'TN_ART3', 'title' => 't3', 'member_key' => 'KEY_ALICE', 'nickname' => 'gricky2',
         'board_type' => 'inner33', 'posted_at' => $now, 'assignment_date' => $today],
    ]);
    $nick = $db->query("SELECT cafe_nickname FROM bootcamp_members WHERE id={$alice}")->fetchColumn();
    t('case2_alice_nick_updated', $nick === 'gricky2', "got: " . var_export($nick, true));

    // ── Case 3: 동일값 재호출 → 변경 없음 (rowCount 영향 검증은 직접 못하지만 nick 동일하면 OK)
    ingestCafePosts([
        ['cafe_article_id' => 'TN_ART4', 'title' => 't4', 'member_key' => 'KEY_ALICE', 'nickname' => 'gricky2',
         'board_type' => 'inner33', 'posted_at' => $now, 'assignment_date' => $today],
    ]);
    $nick = $db->query("SELECT cafe_nickname FROM bootcamp_members WHERE id={$alice}")->fetchColumn();
    t('case3_alice_nick_unchanged', $nick === 'gricky2');

    // ── Case 4: nickname 빈 문자열 → 변경 안 함
    ingestCafePosts([
        ['cafe_article_id' => 'TN_ART5', 'title' => 't5', 'member_key' => 'KEY_ALICE', 'nickname' => '',
         'board_type' => 'inner33', 'posted_at' => $now, 'assignment_date' => $today],
    ]);
    $nick = $db->query("SELECT cafe_nickname FROM bootcamp_members WHERE id={$alice}")->fetchColumn();
    t('case4_empty_nickname_noop', $nick === 'gricky2');

    // ── Case 5: member_key 미매핑 (bob) → bootcamp_members 변경 없음
    ingestCafePosts([
        ['cafe_article_id' => 'TN_ART6', 'title' => 't6', 'member_key' => 'KEY_BOB_UNMAPPED', 'nickname' => 'seoyeon',
         'board_type' => 'inner33', 'posted_at' => $now, 'assignment_date' => $today],
    ]);
    $bobNick = $db->query("SELECT cafe_nickname FROM bootcamp_members WHERE id={$bob}")->fetchColumn();
    t('case5_unmapped_member_untouched', $bobNick === null);

    echo "\nResult: {$pass} PASS / {$fail} FAIL\n";
    exit($fail === 0 ? 0 : 1);

} finally {
    $db->rollBack();
}
