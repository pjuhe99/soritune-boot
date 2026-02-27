<?php
/**
 * boot.soritune.com - Member API
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
    $stmt = $db->prepare('SELECT * FROM members WHERE name = ? AND RIGHT(phone, 4) = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$name, $phoneLast]);
    $member = $stmt->fetch();

    if (!$member) jsonError('일치하는 회원 정보가 없습니다.');

    $db->prepare('UPDATE members SET last_login_at = NOW() WHERE id = ?')->execute([$member['id']]);
    loginMember($member['id'], $member['name'], $member['cohort']);

    jsonSuccess([
        'member' => [
            'member_id'   => $member['id'],
            'member_name' => $member['name'],
            'cohort'      => $member['cohort'],
            'point'       => (int)$member['point'],
        ],
    ], '로그인 성공');
    break;

case 'check_session':
    $s = getMemberSession();
    if ($s) {
        // Fetch fresh member data
        $db = getDB();
        $stmt = $db->prepare('SELECT id, name, cohort, point FROM members WHERE id = ? AND is_active = 1');
        $stmt->execute([$s['member_id']]);
        $member = $stmt->fetch();
        if ($member) {
            jsonSuccess([
                'logged_in' => true,
                'member' => [
                    'member_id'   => (int)$member['id'],
                    'member_name' => $member['name'],
                    'cohort'      => $member['cohort'],
                    'point'       => (int)$member['point'],
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
    $stmt = $db->prepare('SELECT id, name, cohort, point FROM members WHERE id = ?');
    $stmt->execute([$s['member_id']]);
    $member = $stmt->fetch();
    jsonSuccess(['member' => $member]);
    break;

default:
    jsonError('Unknown action', 404);
}
