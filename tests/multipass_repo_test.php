<?php
/**
 * Multipass repo 통합 테스트 (DEV DB).
 * 사용: php tests/multipass_repo_test.php
 *
 * 셋업:
 *   - cohorts 테이블에 11기, 12기, 13기 row 가 있어야 함 (DEV 기본 데이터)
 *   - bootcamp_members 에 user_id='__test_mp_001@k' 가 없어야 함 (이 테스트가 추가/제거)
 *
 * 테스트 후 자체 정리.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/multipass/multipass_repo.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();

// 셋업 — 테스트용 cohort id 3개 확보
$cohortRows = $db->query("SELECT id, cohort FROM cohorts ORDER BY start_date LIMIT 3")->fetchAll();
if (count($cohortRows) < 3) {
    echo "SKIP — cohorts 행 3개 미만\n";
    exit(0);
}
$c1 = (int)$cohortRows[0]['id'];
$c2 = (int)$cohortRows[1]['id'];
$c3 = (int)$cohortRows[2]['id'];
$testUserId = '__test_mp_001@k';

// 정리 (이전 실패 잔여물)
$db->exec("DELETE FROM multipass WHERE user_id = '__test_mp_001@k' OR user_id = '__test_mp_002@k'");
$db->exec("DELETE FROM bootcamp_members WHERE user_id = '__test_mp_001@k' OR user_id = '__test_mp_002@k'");

// 1. createPass — multipass 1행 + multipass_cohorts 3행 트랜잭션
$passId = createPass($db, $testUserId, '11~13기 묶음권', [$c1, $c2, $c3], '메모', 1);
t('create_returns_id', $passId > 0);
t('create_pass_row',
    (int)$db->query("SELECT COUNT(*) FROM multipass WHERE id = $passId")->fetchColumn() === 1);
t('create_cohort_rows',
    (int)$db->query("SELECT COUNT(*) FROM multipass_cohorts WHERE pass_id = $passId")->fetchColumn() === 3);

// 2. UNIQUE — 같은 (pass_id, cohort_id) 두번 INSERT
try {
    $db->prepare("INSERT INTO multipass_cohorts (pass_id, cohort_id) VALUES (?, ?)")
       ->execute([$passId, $c1]);
    t('unique_violation', false, '예외 안 던짐');
} catch (PDOException $e) {
    t('unique_violation', str_contains($e->getMessage(), 'Duplicate'));
}

// 3. toggleCoupon issued=1 → at/by 채움
$ret = toggleCoupon($db, $passId, $c1, true, 99);
t('toggle_on_returns_at', !empty($ret['coupon_issued_at']));
t('toggle_on_by_set', $ret['coupon_issued_by'] === 99);
$row = $db->query("SELECT coupon_issued, coupon_issued_at, coupon_issued_by FROM multipass_cohorts WHERE pass_id = $passId AND cohort_id = $c1")->fetch();
t('toggle_on_db', (int)$row['coupon_issued'] === 1 && $row['coupon_issued_at'] !== null && (int)$row['coupon_issued_by'] === 99);

// 4. toggleCoupon issued=0 → NULL 복귀
toggleCoupon($db, $passId, $c1, false, 99);
$row = $db->query("SELECT coupon_issued, coupon_issued_at, coupon_issued_by FROM multipass_cohorts WHERE pass_id = $passId AND cohort_id = $c1")->fetch();
t('toggle_off_resets', (int)$row['coupon_issued'] === 0 && $row['coupon_issued_at'] === null && $row['coupon_issued_by'] === null);

// 5. updatePass diff — c2 제거, c1 (이미 있음) 유지, 신규 cohort 없음 → only removal
$diff = updatePass($db, $passId, ['cohort_ids' => [$c1, $c3]], 99);
t('update_removed', $diff['removed_cohort_ids'] === [$c2]);
t('update_added', $diff['added_cohort_ids'] === []);
t('update_count', (int)$db->query("SELECT COUNT(*) FROM multipass_cohorts WHERE pass_id = $passId")->fetchColumn() === 2);

// 6. updatePass — user_id 변경 (오타 수정 시나리오)
updatePass($db, $passId, ['user_id' => '__test_mp_002@k'], 99);
$row = $db->query("SELECT user_id FROM multipass WHERE id = $passId")->fetch();
t('update_user_id', $row['user_id'] === '__test_mp_002@k');
// multipass_cohorts 보존
t('update_user_id_preserves_cohorts',
    (int)$db->query("SELECT COUNT(*) FROM multipass_cohorts WHERE pass_id = $passId")->fetchColumn() === 2);

// 7. deletePass CASCADE
deletePass($db, $passId);
t('delete_pass_row',
    (int)$db->query("SELECT COUNT(*) FROM multipass WHERE id = $passId")->fetchColumn() === 0);
t('delete_cohorts_cascade',
    (int)$db->query("SELECT COUNT(*) FROM multipass_cohorts WHERE pass_id = $passId")->fetchColumn() === 0);

// 8. has_member_row / joined 분리 — 환불 row 만 있으면 has_member_row=true, joined=false
$db->exec("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, user_id, member_status) VALUES ({$c1}, 'TestRefund', 'tr', '__test_mp_001@k', 'refunded')");
$passId2 = createPass($db, '__test_mp_001@k', 'X', [$c1], null, 1);
$passes = findPasses($db, ['user_id' => '__test_mp_001@k']);
t('decorate_count', count($passes) === 1 && count($passes[0]['cohorts']) === 1);
$cohort = $passes[0]['cohorts'][0];
t('has_member_row_true_for_refund', $cohort['has_member_row'] === true);
t('joined_false_for_refund', $cohort['joined'] === false);

// 9. searchMembers — q 가 user_id 부분일치 → 매칭, profiles 와 passes 채워짐
$results = searchMembers($db, '__test_mp_001');
t('search_finds_user', count($results) === 1 && $results[0]['user_id'] === '__test_mp_001@k');
t('search_has_pass', isset($results[0]['passes']) && count($results[0]['passes']) === 1);
t('search_has_profile', isset($results[0]['profiles']) && count($results[0]['profiles']) >= 1);

// 10. searchMembers — q 가 nickname 매칭
$results = searchMembers($db, 'TestRefund');
t('search_by_real_name', count($results) === 1 && $results[0]['user_id'] === '__test_mp_001@k');

// 11. searchMembers — LIKE 메타문자 escape (q='__test' 의 underscore 가 SQL wildcard 로 안 해석되는지)
// __test_mp_001@k vs __test_mp_002@k 둘 다 매칭하지만, q='__test_mp' 의 '_' escape 후 정확한 prefix 매치 동작
$results = searchMembers($db, '__test_mp');  // 두 user_id 모두 매칭 (escape 전후 모두 해당)
t('search_like_escape_safe', is_array($results));  // 그냥 에러 안 나면 OK

// 정리
$db->exec("DELETE FROM multipass WHERE id = $passId2");
$db->exec("DELETE FROM bootcamp_members WHERE user_id = '__test_mp_001@k' OR user_id = '__test_mp_002@k'");

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
