<?php
/**
 * 매칭 로직 통합 테스트. DEV DB transaction → 마지막에 rollback.
 * 사용: php tests/cafe_bulk_match_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/cafe/cafe_bulk_match.php';

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
    $cohortLabel = 'TEST_M_' . bin2hex(random_bytes(3));
    $stmt = $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))");
    $stmt->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    // 테스트 groups
    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, stage_no, code) VALUES (?, '리사조', 1, 'tm_lisa'), (?, '무이조', 1, 'tm_mui')")
       ->execute([$cohortId, $cohortId]);
    $groupLisa = (int)$db->query("SELECT id FROM bootcamp_groups WHERE cohort_id={$cohortId} AND code='tm_lisa'")->fetchColumn();
    $groupMui  = (int)$db->query("SELECT id FROM bootcamp_groups WHERE cohort_id={$cohortId} AND code='tm_mui'")->fetchColumn();

    // 테스트 회원
    $insMember = $db->prepare("
        INSERT INTO bootcamp_members
            (cohort_id, group_id, real_name, nickname, cafe_member_key, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 'active', 1, 1, CURDATE())
    ");
    $insMember->execute([$cohortId, $groupLisa, '김명식', '그릭이', null]);
    $kim = (int)$db->lastInsertId();
    $insMember->execute([$cohortId, $groupLisa, '김명식', '명식이', null]);  // 동명이인
    $kim2 = (int)$db->lastInsertId();
    $insMember->execute([$cohortId, $groupMui, '이서연', '서연쓰', null]);
    $lee = (int)$db->lastInsertId();
    $insMember->execute([$cohortId, $groupMui, '박지원', '지원지원', 'EXISTING_KEY_A']);
    $park = (int)$db->lastInsertId();

    // 1. ALREADY_MAPPED_SAME (키 + 조 + 이름 모두 일치)
    $r = matchCandidates($db, $cohortId, 'EXISTING_KEY_A', '무이조', '박지원', '지원지원');
    t('already_mapped_same',
        $r['status'] === 'ALREADY_MAPPED_SAME'
        && $r['existing_member']['id'] === $park);

    // 2. ALREADY_MAPPED_DIFF (키는 박지원, paste 는 다른 조/이름)
    $r = matchCandidates($db, $cohortId, 'EXISTING_KEY_A', '리사조', '김명식', '그릭이');
    t('already_mapped_diff',
        $r['status'] === 'ALREADY_MAPPED_DIFF'
        && $r['existing_member']['id'] === $park);

    // 3. HIGH (조+이름 1명)
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_X', '무이조', '이서연', '서연쓰');
    t('high',
        $r['status'] === 'HIGH'
        && count($r['candidates']) === 1
        && $r['candidates'][0]['member_id'] === $lee);

    // 4. MID_MULTI (조+이름이 2명)
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_Y', '리사조', '김명식', null);
    t('mid_multi_in_group',
        $r['status'] === 'MID_MULTI'
        && count($r['candidates']) === 2);

    // 5. MID (조 없을 때 이름 정확 일치 1명)
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_Z', null, '이서연', null);
    t('mid_no_group',
        $r['status'] === 'MID'
        && $r['candidates'][0]['member_id'] === $lee);

    // 6. LOW (LIKE)
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_W', null, '이서', null);
    t('low_like',
        $r['status'] === 'LOW'
        && count($r['candidates']) >= 1);

    // 7. NO_MATCH
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_V', '리사조', '없는사람', null);
    t('no_match', $r['status'] === 'NO_MATCH' && count($r['candidates']) === 0);

    // 8. 비활성 회원 제외
    $db->prepare("UPDATE bootcamp_members SET member_status='leaving' WHERE id=?")->execute([$lee]);
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_U', '무이조', '이서연', null);
    t('inactive_excluded', $r['status'] === 'NO_MATCH');

    // 9. '조' 글자 빠진 group 입력도 매칭
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_T', '리사', '그릭이', null);
    t('group_without_cho_suffix',
        $r['status'] === 'HIGH'
        && $r['candidates'][0]['member_id'] === $kim);

    echo "\n{$pass} pass, {$fail} fail\n";
} finally {
    $db->rollBack();
}

exit($fail > 0 ? 1 : 0);
