<?php
/**
 * boot.soritune.com - Member API
 * Uses bootcamp_members table with cohorts FK
 */

require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');

$action = getAction();
$method = getMethod();

switch ($action) {

case 'login':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $input = getJsonInput();
    $name      = trim($input['name'] ?? '');
    $phoneLast = trim($input['phone_last4'] ?? '');

    if (!$name || !$phoneLast) jsonError('이름과 전화번호 뒷자리를 입력해주세요.');
    if (strlen($phoneLast) !== 4 || !ctype_digit($phoneLast)) jsonError('전화번호 뒷자리 4자리를 입력해주세요.');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT bm.*, c.cohort,
               COALESCE(NULLIF(bm.kakao_link, ''), bg.kakao_link) AS kakao_link,
               bg.name AS group_name
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        WHERE bm.real_name = ? AND RIGHT(bm.phone, 4) = ? AND bm.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$name, $phoneLast]);
    $member = $stmt->fetch();

    if (!$member) jsonError('일치하는 회원 정보가 없습니다.');

    loginMember($member['id'], $member['real_name'], $member['cohort']);

    // Get current score + coin
    $scoreStmt = $db->prepare('SELECT current_score FROM member_scores WHERE member_id = ?');
    $scoreStmt->execute([$member['id']]);
    $scoreRow = $scoreStmt->fetch();
    $score = $scoreRow ? (int)$scoreRow['current_score'] : 0;

    $coinStmt = $db->prepare('SELECT current_coin FROM member_coin_balances WHERE member_id = ?');
    $coinStmt->execute([$member['id']]);
    $coin = (int)($coinStmt->fetchColumn() ?: 0);

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
        ],
    ], '로그인 성공');
    break;

case 'check_session':
    $s = getMemberSession();
    if ($s) {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT bm.id, bm.real_name, bm.nickname,
                   COALESCE(NULLIF(bm.kakao_link, ''), bg.kakao_link) AS kakao_link,
                   c.cohort, bg.name AS group_name
            FROM bootcamp_members bm
            JOIN cohorts c ON bm.cohort_id = c.id
            LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
            WHERE bm.id = ? AND bm.is_active = 1
        ");
        $stmt->execute([$s['member_id']]);
        $member = $stmt->fetch();
        if ($member) {
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

default:
    jsonError('Unknown action', 404);
}
