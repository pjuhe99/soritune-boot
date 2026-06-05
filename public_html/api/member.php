<?php
/**
 * boot.soritune.com - Member API
 * Uses bootcamp_members table with cohorts FK
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/bootcamp_functions.php';
require_once __DIR__ . '/../includes/coin_functions.php';
require_once __DIR__ . '/services/bravo.php';
require_once __DIR__ . '/services/bravo_attempts.php';
require_once __DIR__ . '/services/bravo_grading.php';
require_once __DIR__ . '/services/bravo_certificates.php';
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
    // 한국 휴대폰(10~11자리) + 국제번호(E.164 최대 15자리) 모두 허용.
    if (strlen($phone) < 7 || strlen($phone) > 15) jsonError('올바른 휴대폰번호를 입력해주세요. (7~15자리)');

    $db = getDB();
    $rows = findMemberAccessibleRows($db, $phone);
    if (!$rows) jsonError('등록되지 않은 휴대폰번호입니다.');

    $member = $rows[0]; // cohort_id DESC 정렬, 최신 cohort 가 default
    $accessible = array_map(fn($r) => [
        'member_id'    => (int)$r['id'],
        'cohort_id'    => (int)$r['cohort_id'],
        'cohort_label' => $r['cohort'],
    ], $rows);

    loginMember($member['id'], $member['real_name'], $member['cohort'], $member['nickname'], $accessible);

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

    $coin = getMemberDisplayedCoinTotal($db, (int)$member['id']);

    $statsStmt = $db->prepare("
        SELECT COALESCE(mhs_u.completed_bootcamp_count, mhs_p.completed_bootcamp_count, 0) AS completed_bootcamp_count,
               CASE WHEN bmg.current_level >= 1 THEN CONCAT('Bravo ', bmg.current_level) END AS bravo_grade
        FROM bootcamp_members bm
        LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
        LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
        LEFT JOIN bravo_member_grades bmg ON bmg.member_key = COALESCE(NULLIF(bm.user_id, ''), CONCAT('p:', bm.phone))
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
            'current_reward_group' => getCurrentRewardGroupForMember($db, $member['id']),
            'accessible_cohorts' => $accessible,
        ],
    ], '로그인 성공');
    break;

case 'switch_cohort':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    requireMember();
    $input = getJsonInput();
    $cohortId = (int)($input['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');

    if (!swapMemberCohort($cohortId)) {
        jsonError('해당 기수로 전환할 수 없습니다.', 403);
    }
    $member = getMemberSession();
    jsonSuccess(['member' => $member], '기수가 전환되었습니다.');
    break;

case 'check_session':
    $s = getMemberSession();
    if ($s) {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT bm.id, bm.real_name, bm.nickname, bm.phone, bm.user_id, bm.member_role,
                   COALESCE(NULLIF(bm.kakao_link, ''), bg.kakao_link) AS kakao_link,
                   c.cohort, bg.name AS group_name,
                   COALESCE(mhs_u.completed_bootcamp_count, mhs_p.completed_bootcamp_count, 0) AS completed_bootcamp_count,
                   CASE WHEN bmg.current_level >= 1 THEN CONCAT('Bravo ', bmg.current_level) END AS bravo_grade
            FROM bootcamp_members bm
            JOIN cohorts c ON bm.cohort_id = c.id
            LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
            LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
            LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
            LEFT JOIN bravo_member_grades bmg ON bmg.member_key = COALESCE(NULLIF(bm.user_id, ''), CONCAT('p:', bm.phone))
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

            $coin = getMemberDisplayedCoinTotal($db, (int)$member['id']);

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
                    'current_reward_group' => getCurrentRewardGroupForMember($db, (int)$member['id']),
                    'accessible_cohorts' => $s['accessible_cohorts'] ?? [],
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

    $coin = getMemberDisplayedCoinTotal($db, (int)$s['member_id']);

    $member['score'] = $score;
    $member['coin'] = $coin;
    $member['current_reward_group'] = getCurrentRewardGroupForMember($db, $s['member_id']);
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

case 'bravo_status':
    $s = requireMember();
    $db = getDB();
    jsonSuccess(bravoMemberStatus($db, (int)$s['member_id']));
    break;

case 'bravo_exam_intro':
    $s = requireMember();
    $examId = (isset($_GET['exam_id']) && is_numeric($_GET['exam_id'])) ? (int)$_GET['exam_id'] : 0;
    if ($examId < 1) jsonError('exam_id가 필요합니다.');
    $db = getDB();
    $acc = bravoAttemptExamAccess($db, (int)$s['member_id'], $examId);
    if (isset($acc['error'])) jsonError($acc['error'], $acc['code'] ?? 400);
    $exam = $acc['exam'];
    $ot = bravoOtGet($db, $examId);
    jsonSuccess([
        'exam' => [
            'id' => (int)$exam['id'], 'title' => $exam['title'], 'bravo_level' => (int)$exam['bravo_level'],
            'exam_mode' => $exam['exam_mode'], 'start_at' => $exam['start_at'], 'end_at' => $exam['end_at'],
            'result_release_at' => $exam['result_release_at'], 'attempt_limit' => (int)$exam['attempt_limit'],
        ],
        'ot' => $ot ? [
            'title' => $ot['title'], 'intro_text' => $ot['intro_text'], 'video_url' => $ot['video_url'],
            'type1_text' => $ot['type1_text'], 'type2_text' => $ot['type2_text'], 'type3_text' => $ot['type3_text'],
            'require_check' => (int)$ot['require_check'],
        ] : null,
        'question_count' => count(bravoExamQuestionAssignedIds($db, $examId)),
        'attempts' => bravoStatusAttempts($db, $exam, $acc['member_key']),
    ]);
    break;

case 'bravo_attempt_start':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $s = requireMember();
    $input = getJsonInput();
    $examId = (isset($input['exam_id']) && is_numeric($input['exam_id'])) ? (int)$input['exam_id'] : 0;
    if ($examId < 1) jsonError('exam_id가 필요합니다.');
    $db = getDB();
    $acc = bravoAttemptExamAccess($db, (int)$s['member_id'], $examId);
    if (isset($acc['error'])) jsonError($acc['error'], $acc['code'] ?? 400);
    $r = bravoAttemptStart($db, $acc['exam'], $acc['ctx']['row'], $acc['member_key'], !empty($input['ot_checked']));
    if (isset($r['error'])) jsonError($r['error']);
    $attempt = $r['attempt'];
    jsonSuccess([
        'attempt_id' => (int)$attempt['id'],
        'attempt_no' => (int)$attempt['attempt_no'],
        'resumed' => !empty($r['resumed']),
        'questions' => bravoAttemptQuestions($db, $attempt),
        'answered_ids' => bravoAttemptAnsweredIds($db, (int)$attempt['id']),
    ]);
    break;

case 'bravo_answer_save':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $s = requireMember();
    // multipart — getJsonInput 아님: $_POST + $_FILES
    $attemptId = (isset($_POST['attempt_id']) && is_numeric($_POST['attempt_id'])) ? (int)$_POST['attempt_id'] : 0;
    $questionId = (isset($_POST['question_id']) && is_numeric($_POST['question_id'])) ? (int)$_POST['question_id'] : 0;
    if ($attemptId < 1 || $questionId < 1) jsonError('attempt_id/question_id가 필요합니다.');
    $db = getDB();
    $attempt = bravoAttemptForMember($db, $attemptId, (int)$s['member_id']);
    if (!$attempt) jsonError('응시 기록을 찾을 수 없습니다.', 404);
    if ($attempt['status'] !== 'in_progress') jsonError('이미 제출된 응시입니다.');
    $exStmt = $db->prepare("SELECT id, exam_mode, start_at, end_at, status FROM bravo_exams WHERE id = ?");
    $exStmt->execute([(int)$attempt['exam_id']]);
    $exam = $exStmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam || $exam['status'] !== 'open' || !bravoAttemptSavePeriodOk($exam)) {
        jsonError('응시 기간이 종료되었습니다.');
    }
    if (empty($_FILES['audio'])) jsonError('녹음 파일이 없습니다.');
    $v = bravoAnswerValidateUpload($_FILES['audio']);
    if (isset($v['error'])) jsonError($v['error']);
    $durationMs = (isset($_POST['duration_ms']) && is_numeric($_POST['duration_ms'])) ? (int)$_POST['duration_ms'] : null;
    $r = bravoAnswerStore($db, $attempt, $questionId, $_FILES['audio']['tmp_name'], $v['mime'], $v['ext'], $durationMs, true);
    if (isset($r['error'])) jsonError($r['error']);
    jsonSuccess($r, '저장되었습니다.');
    break;

case 'bravo_attempt_submit':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $s = requireMember();
    $input = getJsonInput();
    $attemptId = (isset($input['attempt_id']) && is_numeric($input['attempt_id'])) ? (int)$input['attempt_id'] : 0;
    if ($attemptId < 1) jsonError('attempt_id가 필요합니다.');
    $db = getDB();
    $attempt = bravoAttemptForMember($db, $attemptId, (int)$s['member_id']);
    if (!$attempt) jsonError('응시 기록을 찾을 수 없습니다.', 404);
    $r = bravoAttemptSubmit($db, $attempt); // 기간 체크 없음(의도 — 스펙 §5)
    if (isset($r['error'])) jsonError($r['error']);
    jsonSuccess(['submitted' => true], '제출되었습니다.');
    break;

case 'bravo_certificate':
    $s = requireMember();
    $attemptId = (isset($_GET['attempt_id']) && is_numeric($_GET['attempt_id'])) ? (int)$_GET['attempt_id'] : 0;
    if ($attemptId < 1) jsonError('attempt_id가 필요합니다.');
    $db = getDB();
    $attempt = bravoAttemptForMember($db, $attemptId, (int)$s['member_id']);
    if (!$attempt) jsonError('응시 기록을 찾을 수 없습니다.', 404); // 타인 attempt 동일 거부
    $exStmt = $db->prepare("SELECT * FROM bravo_exams WHERE id = ?");
    $exStmt->execute([(int)$attempt['exam_id']]);
    $exam = $exStmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam) jsonError('시험을 찾을 수 없습니다.', 404);
    $deny = bravoCertificateEligible($exam, bravoAttemptGradeGet($db, $attemptId));
    if ($deny) jsonError($deny['error'], $deny['code']);
    $ctx = bravoMemberContext($db, (int)$s['member_id']);
    if (!$ctx) jsonError('회원 정보를 찾을 수 없습니다.', 500);
    $cert = bravoCertificateIssue($db, $attempt, $exam, $ctx['row']['real_name']);
    try {
        $r = bravoCertificateRender($cert);
    } catch (Throwable $e) {
        error_log('bravo_certificate render: ' . $e->getMessage());
        jsonError('인증서 생성에 실패했습니다. 관리자에게 문의해주세요.', 500);
    }
    $level = (int)$cert['bravo_level'];
    $ascii = "bravo{$level}_certificate.{$r['ext']}"; // ASCII 폴백
    $utf8  = "BRAVO{$level}_인증서_{$cert['member_name']}.{$r['ext']}"; // RFC5987 한글 병기
    header('Content-Type: ' . $r['mime']);
    header("Content-Disposition: attachment; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode($utf8));
    header('Cache-Control: private, no-store'); // 개인정보 포함 파일
    header('Content-Length: ' . strlen($r['bytes']));
    echo $r['bytes'];
    exit;

default:
    jsonError('Unknown action', 404);
}
