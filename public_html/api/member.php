<?php
/**
 * boot.soritune.com - Member API
 * Uses bootcamp_members table with cohorts FK
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/bootcamp_functions.php';
header('Content-Type: application/json; charset=utf-8');

$action = getAction();
$method = getMethod();

switch ($action) {

case 'login':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $input = getJsonInput();
    $phoneRaw = trim($input['phone'] ?? '');

    if (!$phoneRaw) jsonError('휴대폰번호를 입력해주세요.');
    $phone = normalizePhone($phoneRaw);
    if (strlen($phone) < 10 || strlen($phone) > 11) jsonError('올바른 휴대폰번호를 입력해주세요. (10~11자리)');

    $db = getDB();
    $member = findMemberByPhone($db, $phone);

    if (!$member) jsonError('등록되지 않은 휴대폰번호입니다.');

    loginMember($member['id'], $member['real_name'], $member['cohort'], $member['nickname']);

    // 조장/부조장이면 admin 세션도 동시 생성 (조장 페이지에서 별도 로그인 불필요)
    if (in_array($member['member_role'] ?? '', ['leader', 'subleader'])) {
        $displayName = $member['nickname'] ?: $member['real_name'];
        $bcGroupId = $member['group_id'] ? (int)$member['group_id'] : null;
        loginAdmin($member['id'], $displayName, [$member['member_role']], $member['cohort'], $bcGroupId);
    }

    // Get current score + coin + completed count + bravo grade
    ensureMemberScoreFresh($db, $member['id']);
    $scoreStmt = $db->prepare('SELECT current_score FROM member_scores WHERE member_id = ?');
    $scoreStmt->execute([$member['id']]);
    $scoreRow = $scoreStmt->fetch();
    $score = $scoreRow ? (int)$scoreRow['current_score'] : 0;

    $coinStmt = $db->prepare('SELECT current_coin FROM member_coin_balances WHERE member_id = ?');
    $coinStmt->execute([$member['id']]);
    $coin = (int)($coinStmt->fetchColumn() ?: 0);

    $statsStmt = $db->prepare("
        SELECT COALESCE(mhs_p.completed_bootcamp_count, mhs_u.completed_bootcamp_count, 0) AS completed_bootcamp_count,
               COALESCE(mhs_p.bravo_grade, mhs_u.bravo_grade) AS bravo_grade
        FROM bootcamp_members bm
        LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
        LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
        WHERE bm.id = ?
    ");
    $statsStmt->execute([$member['id']]);
    $statsRow = $statsStmt->fetch();

    jsonSuccess([
        'member' => [
            'member_id'   => $member['id'],
            'member_name' => $member['real_name'],
            'nickname'    => $member['nickname'],
            'cohort'      => $member['cohort'],
            'group_name'  => $member['group_name'],
            'kakao_link'  => $member['kakao_link'] ?: null,
            'score'       => $score,
            'coin'        => $coin,
            'completed_count' => $statsRow ? (int)$statsRow['completed_bootcamp_count'] : 0,
            'bravo_grade' => $statsRow ? $statsRow['bravo_grade'] : null,
            'needs_nickname' => !hasNickname($member['nickname']),
            'member_role' => $member['member_role'] ?? 'member',
        ],
    ], '로그인 성공');
    break;

case 'check_session':
    $s = getMemberSession();
    if ($s) {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT bm.id, bm.real_name, bm.nickname, bm.phone, bm.user_id, bm.member_role,
                   COALESCE(NULLIF(bm.kakao_link, ''), bg.kakao_link) AS kakao_link,
                   c.cohort, bg.name AS group_name,
                   COALESCE(mhs_p.completed_bootcamp_count, mhs_u.completed_bootcamp_count, 0) AS completed_bootcamp_count,
                   COALESCE(mhs_p.bravo_grade, mhs_u.bravo_grade) AS bravo_grade
            FROM bootcamp_members bm
            JOIN cohorts c ON bm.cohort_id = c.id
            LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
            LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
            LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
            WHERE bm.id = ? AND (bm.is_active = 1 OR bm.member_status = 'leaving')
        ");
        $stmt->execute([$s['member_id']]);
        $member = $stmt->fetch();
        if ($member) {
            ensureMemberScoreFresh($db, $member['id']);
            $scoreStmt = $db->prepare('SELECT current_score FROM member_scores WHERE member_id = ?');
            $scoreStmt->execute([$member['id']]);
            $scoreRow = $scoreStmt->fetch();
            $score = $scoreRow ? (int)$scoreRow['current_score'] : 0;

            $coinStmt = $db->prepare('SELECT current_coin FROM member_coin_balances WHERE member_id = ?');
            $coinStmt->execute([$member['id']]);
            $coin = (int)($coinStmt->fetchColumn() ?: 0);

            jsonSuccess([
                'logged_in' => true,
                'member' => [
                    'member_id'   => (int)$member['id'],
                    'member_name' => $member['real_name'],
                    'nickname'    => $member['nickname'],
                    'cohort'      => $member['cohort'],
                    'group_name'  => $member['group_name'],
                    'kakao_link'  => $member['kakao_link'] ?: null,
                    'score'       => $score,
                    'coin'        => $coin,
                    'completed_count' => (int)$member['completed_bootcamp_count'],
                    'bravo_grade' => $member['bravo_grade'] ?: null,
                    'needs_nickname' => !hasNickname($member['nickname']),
                    'member_role' => $member['member_role'] ?? 'member',
                ],
            ]);
        }
    }
    jsonSuccess(['logged_in' => false]);
    break;

case 'logout':
    logoutMember();
    jsonSuccess([], '로그아웃 되었습니다.');
    break;

case 'dashboard':
    $s = requireMember();
    $db = getDB();
    $stmt = $db->prepare('
        SELECT bm.id, bm.real_name, bm.nickname, c.cohort
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.id = ?
    ');
    $stmt->execute([$s['member_id']]);
    $member = $stmt->fetch();

    ensureMemberScoreFresh($db, $s['member_id']);
    $scoreStmt = $db->prepare('SELECT current_score FROM member_scores WHERE member_id = ?');
    $scoreStmt->execute([$s['member_id']]);
    $scoreRow = $scoreStmt->fetch();
    $score = $scoreRow ? (int)$scoreRow['current_score'] : 0;

    $coinStmt = $db->prepare('SELECT current_coin FROM member_coin_balances WHERE member_id = ?');
    $coinStmt->execute([$s['member_id']]);
    $coin = (int)($coinStmt->fetchColumn() ?: 0);

    $member['score'] = $score;
    $member['coin'] = $coin;
    jsonSuccess(['member' => $member]);
    break;

case 'save_nickname':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $s = requireMember();
    $input = getJsonInput();
    $nickname = trim($input['nickname'] ?? '');

    if ($nickname === '') jsonError('닉네임을 입력해주세요.');
    if (mb_strlen($nickname) > 20) jsonError('닉네임은 20자 이내로 입력해주세요.');

    $db = getDB();
    $stmt = $db->prepare('UPDATE bootcamp_members SET nickname = ? WHERE id = ?');
    $stmt->execute([$nickname, $s['member_id']]);

    updateMemberNickname($nickname);

    jsonSuccess(['nickname' => $nickname], '닉네임이 저장되었습니다.');
    break;

default:
    jsonError('Unknown action', 404);
}
