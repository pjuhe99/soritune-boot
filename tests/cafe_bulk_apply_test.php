<?php
/**
 * paste 적용 함수 통합 테스트. DEV DB transaction rollback.
 * 사용: php tests/cafe_bulk_apply_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/cafe/cafe_bulk_apply.php';

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
    // 테스트 cohort (cohorts.code UNIQUE NOT NULL + end_date NOT NULL)
    $cohortLabel = 'TEST_A_' . bin2hex(random_bytes(3));
    $stmt = $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))");
    $stmt->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $groupCode = 'ta_lisa_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, stage_no, code) VALUES (?, '리사조', 1, ?)")
       ->execute([$cohortId, $groupCode]);
    $groupId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, cafe_member_key, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 'active', 1, 1, CURDATE())");
    $ins->execute([$cohortId, $groupId, '김명식', '그릭이', null]);
    $alice = (int)$db->lastInsertId();
    $ins->execute([$cohortId, $groupId, '이서연', '서연쓰', 'OLD_KEY_TO_DISPLACE']);
    $bob = (int)$db->lastInsertId();
    $ins->execute([$cohortId, $groupId, '박지원', '지원지원', null]);
    $charlie = (int)$db->lastInsertId();

    // unmapped cafe_posts 시드 (alice 의 키로 등록될 게시글 3건 + bob 키로 1건)
    $insPost = $db->prepare("INSERT INTO cafe_posts
        (cafe_article_id, title, member_key, nickname, board_type, posted_at, member_id, mission_checked, assignment_date, raw_data)
        VALUES (?, ?, ?, ?, ?, ?, NULL, 0, ?, NULL)");

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $insPost->execute(['TA_ART1', 't1', 'NEW_KEY_ALICE', 'gricky', 'inner33',       $now, $today]);
    $insPost->execute(['TA_ART2', 't2', 'NEW_KEY_ALICE', 'gricky', 'daily_mission', $now, $today]);
    $insPost->execute(['TA_ART3', 't3', 'NEW_KEY_ALICE', 'gricky', null,            $now, null]); // board_type 없음 → saveCheck X
    $insPost->execute(['TA_ART4', 't4', 'NEW_KEY_BOB',   'seoyeon', 'inner33',      $now, $today]); // 다른 키

    // Case 1: alice 에 신규 키 등록 → 3건 백필 + 2건 saveCheck (#3은 board_type 없음)
    $r = applyCafeBulkMapping($db, [
        ['row' => 1, 'article_id' => 'TA_ART1', 'member_key' => 'NEW_KEY_ALICE', 'cafe_nick' => 'gricky', 'target_member_id' => $alice],
    ]);
    t('case1_summary', $r['summary']['applied'] === 1 && $r['summary']['skipped'] === 0 && $r['summary']['failed'] === 0);
    t('case1_backfill', $r['results'][0]['backfilled_posts'] === 3);
    t('case1_missions', $r['results'][0]['missions_saved'] === 2);

    // 적용 후 DB 검증
    $aliceKey = $db->query("SELECT cafe_member_key FROM bootcamp_members WHERE id={$alice}")->fetchColumn();
    t('case1_key_set', $aliceKey === 'NEW_KEY_ALICE');
    $aliceBackfilled = (int)$db->query("SELECT COUNT(*) FROM cafe_posts WHERE member_id={$alice}")->fetchColumn();
    t('case1_posts', $aliceBackfilled === 3);
    $aliceMissions = (int)$db->query("SELECT COUNT(*) FROM member_mission_checks WHERE member_id={$alice} AND source_ref LIKE 'cafe:%'")->fetchColumn();
    t('case1_mission_rows', $aliceMissions === 2);

    // Case 2: charlie 에 'OLD_KEY_TO_DISPLACE' 등록 → bob 의 키 NULL 해제 + bob 의 과거 글은 charlie 로 백필
    $r = applyCafeBulkMapping($db, [
        ['row' => 2, 'article_id' => 'TA_ART4', 'member_key' => 'OLD_KEY_TO_DISPLACE', 'cafe_nick' => 'seoyeon', 'target_member_id' => $charlie],
    ]);
    t('case2_diff_applied', $r['results'][0]['status'] === 'applied_diff' && $r['results'][0]['displaced'] === 1);
    $bobKey = $db->query("SELECT cafe_member_key FROM bootcamp_members WHERE id={$bob}")->fetchColumn();
    t('case2_bob_displaced', $bobKey === null);
    $charlieKey = $db->query("SELECT cafe_member_key FROM bootcamp_members WHERE id={$charlie}")->fetchColumn();
    t('case2_charlie_set', $charlieKey === 'OLD_KEY_TO_DISPLACE');

    // Case 3: target_member_id 0 (어드민 미선택) → skipped
    $r = applyCafeBulkMapping($db, [
        ['row' => 3, 'article_id' => 'TA_ART5', 'member_key' => 'WHATEVER', 'cafe_nick' => 'x', 'target_member_id' => 0],
    ]);
    t('case3_skipped', $r['summary']['skipped'] === 1 && $r['results'][0]['status'] === 'skipped');

    // Case 4: 행 실패 격리 (존재하지 않는 target_member_id → FK constraint 없지만 UPDATE 영향 0)
    // 트랜잭션 자체는 commit 되지만 행 결과는 정상. 다른 행 영향 없음 검증.
    $r = applyCafeBulkMapping($db, [
        ['row' => 4, 'article_id' => 'TA_ART6', 'member_key' => 'NEW_KEY_X', 'cafe_nick' => 'x', 'target_member_id' => 99999999],
        ['row' => 5, 'article_id' => 'TA_ART7', 'member_key' => 'NEW_KEY_Y', 'cafe_nick' => 'y', 'target_member_id' => $alice],
    ]);
    t('case4_other_row_unaffected', $r['summary']['applied'] >= 1);

    echo "\n{$pass} pass, {$fail} fail\n";
} finally {
    $db->rollBack();
}

exit($fail > 0 ? 1 : 0);
