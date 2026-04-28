<?php
/**
 * boot.soritune.com - Admin API
 * Handles all admin actions: login, tasks, guides, calendar, CRUD
 * V2: multi-role support, auto-assign tasks, cohorts CRUD
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/coin_functions.php';
require_once __DIR__ . '/../includes/bootcamp_functions.php';
require_once __DIR__ . '/services/member_stats.php';
require_once __DIR__ . '/services/member_bulk.php';
require_once __DIR__ . '/services/retention.php';
header('Content-Type: application/json; charset=utf-8');

$action = getAction();
$method = getMethod();

switch ($action) {

// ── Auth ────────────────────────────────────────────────────

case 'login':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $input = getJsonInput();
    $loginId  = trim($input['login_id'] ?? '');
    $password = $input['password'] ?? '';

    if (!$loginId || !$password) jsonError('아이디와 비밀번호를 입력해주세요.');

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM admins WHERE login_id = ? AND is_active = 1');
    $stmt->execute([$loginId]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        jsonError('아이디 또는 비밀번호가 올바르지 않습니다.');
    }

    // Fetch roles from admin_roles table
    $stmt = $db->prepare('SELECT role FROM admin_roles WHERE admin_id = ?');
    $stmt->execute([$admin['id']]);
    $roles = array_column($stmt->fetchAll(), 'role');

    if (empty($roles)) {
        jsonError('할당된 역할이 없습니다. 관리자에게 문의해주세요.');
    }

    $db->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$admin['id']]);
    $bcGroupId = $admin['bootcamp_group_id'] ? (int)$admin['bootcamp_group_id'] : null;
    loginAdmin($admin['id'], $admin['name'], $roles, $admin['cohort'], $bcGroupId);

    jsonSuccess([
        'admin' => [
            'admin_id'    => $admin['id'],
            'admin_name'  => $admin['name'],
            'admin_roles' => $roles,
            'cohort'      => $admin['cohort'],
            'team'        => $admin['team'],
            'class_time'  => $admin['class_time'],
            'bootcamp_group_id' => $bcGroupId,
        ],
    ], '로그인 성공');
    break;

case 'login_phone':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $input = getJsonInput();
    $phone = trim($input['phone'] ?? '');
    if (!$phone) jsonError('휴대폰 번호를 입력해주세요.');

    $normalized = normalizePhone($phone);
    $db = getDB();

    // Find active bootcamp_member with leader/subleader role by phone
    $stmt = $db->prepare("
        SELECT bm.id, bm.real_name, bm.nickname, bm.member_role, bm.group_id, bm.cohort_id, c.cohort,
               bg.name AS group_name
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        WHERE REPLACE(REPLACE(bm.phone, '-', ''), ' ', '') = ?
          AND bm.is_active = 1
          AND bm.member_role IN ('leader', 'subleader')
        LIMIT 1
    ");
    $stmt->execute([$normalized]);
    $member = $stmt->fetch();

    if (!$member) {
        jsonError('등록된 조장/부조장 정보를 찾을 수 없습니다.');
    }

    // Map member_role to admin role
    $role = $member['member_role']; // 'leader' or 'subleader'
    $bcGroupId = $member['group_id'] ? (int)$member['group_id'] : null;
    $displayName = $member['nickname'] ?: $member['real_name'];

    // Login directly using bootcamp_member info (no admins table needed)
    loginAdmin($member['id'], $displayName, [$role], $member['cohort'], $bcGroupId);

    // 회원 세션도 동시 생성 (리더가 회원페이지에서 별도 로그인 불필요)
    loginMember($member['id'], $member['real_name'], $member['cohort'], $member['nickname']);

    jsonSuccess([
        'admin' => [
            'admin_id'    => $member['id'],
            'admin_name'  => $displayName,
            'admin_roles' => [$role],
            'cohort'      => $member['cohort'],
            'team'        => $member['group_name'],
            'class_time'  => null,
            'bootcamp_group_id' => $bcGroupId,
        ],
    ], '로그인 성공');
    break;

case 'logout':
    logoutAdmin();
    jsonSuccess([], '로그아웃 되었습니다.');
    break;

case 'change_password':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $s = getAdminSession();
    if (!$s) jsonError('로그인이 필요합니다.', 401);

    $input = getJsonInput();
    $currentPw = $input['current_password'] ?? '';
    $newPw     = $input['new_password'] ?? '';
    $confirmPw = $input['confirm_password'] ?? '';

    if (!$currentPw || !$newPw || !$confirmPw) jsonError('모든 항목을 입력해주세요.');
    if ($newPw !== $confirmPw) jsonError('새 비밀번호가 일치하지 않습니다.');
    if (mb_strlen($newPw) < 4) jsonError('새 비밀번호는 4자 이상이어야 합니다.');

    $db = getDB();
    $stmt = $db->prepare('SELECT password_hash FROM admins WHERE id = ? AND is_active = 1');
    $stmt->execute([$s['admin_id']]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($currentPw, $admin['password_hash'])) {
        jsonError('현재 비밀번호가 올바르지 않습니다.');
    }

    $newHash = password_hash($newPw, PASSWORD_DEFAULT);
    $db->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([$newHash, $s['admin_id']]);

    jsonSuccess([], '비밀번호가 변경되었습니다.');
    break;

case 'check_session':
    $s = getAdminSession();
    if ($s) {
        // DB에서 최신 bootcamp_group_id 반영
        $db = getDB();
        $stmt = $db->prepare('SELECT bootcamp_group_id FROM admins WHERE id = ? AND is_active = 1');
        $stmt->execute([$s['admin_id']]);
        $row = $stmt->fetch();
        if ($row) {
            $s['bootcamp_group_id'] = $row['bootcamp_group_id'] ? (int)$row['bootcamp_group_id'] : null;
            $_SESSION['bootcamp_group_id'] = $s['bootcamp_group_id'];
        }
        // 코치 담당 그룹 ID 목록
        $stmt2 = $db->prepare('SELECT group_id FROM coach_group_assignments WHERE admin_id = ?');
        $stmt2->execute([$s['admin_id']]);
        $s['assigned_group_ids'] = array_map('intval', array_column($stmt2->fetchAll(), 'group_id'));
        jsonSuccess(['logged_in' => true, 'admin' => $s]);
    } else {
        jsonSuccess(['logged_in' => false]);
    }
    break;

// ── Dashboard Data ──────────────────────────────────────────

case 'weekly_goals':
    $admin = requireAdmin();
    $cohort = getEffectiveCohort($admin);
    $today = date('Y-m-d');
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM calendar WHERE start_date <= ? AND end_date >= ? AND cohort = ? ORDER BY start_date LIMIT 1');
    $stmt->execute([$today, $today, $cohort]);
    $goal = $stmt->fetch();
    jsonSuccess(['goal' => $goal ?: null]);
    break;

case 'today_tasks':
    $admin = requireAdmin();
    $cohort = getEffectiveCohort($admin);
    $date = $_GET['date'] ?? date('Y-m-d');
    $roles = $admin['admin_roles'];
    $adminId = $admin['admin_id'];
    $filterRole = $_GET['filter_role'] ?? '';

    $db = getDB();
    if (hasRole($admin, 'operation')) {
        if ($filterRole === 'mine') {
            // My tasks only
            $stmt = $db->prepare("
                SELECT t.*, COALESCE(a.name, bm.real_name) AS assignee_name
                FROM tasks t
                LEFT JOIN admins a ON t.assignee_admin_id = a.id
                LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
                WHERE t.start_date <= ? AND t.end_date >= ? AND t.cohort = ?
                  AND t.assignee_admin_id = ?
                ORDER BY t.completed, t.end_date, t.title
            ");
            $stmt->execute([$date, $date, $cohort, $adminId]);
        } elseif ($filterRole && $filterRole !== 'all') {
            // Filter by specific role
            $stmt = $db->prepare("
                SELECT t.*, COALESCE(a.name, bm.real_name) AS assignee_name
                FROM tasks t
                LEFT JOIN admins a ON t.assignee_admin_id = a.id
                LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
                WHERE t.start_date <= ? AND t.end_date >= ? AND t.cohort = ?
                  AND t.role = ?
                ORDER BY t.completed, t.end_date, t.title
            ");
            $stmt->execute([$date, $date, $cohort, $filterRole]);
        } else {
            // All tasks
            $stmt = $db->prepare("
                SELECT t.*, COALESCE(a.name, bm.real_name) AS assignee_name
                FROM tasks t
                LEFT JOIN admins a ON t.assignee_admin_id = a.id
                LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
                WHERE t.start_date <= ? AND t.end_date >= ? AND t.cohort = ?
                ORDER BY t.completed, t.end_date, t.title
            ");
            $stmt->execute([$date, $date, $cohort]);
        }
    } else {
        // Non-operation: assigned to me, OR unassigned with my role
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $isMemberLogin = in_array('leader', $roles) || in_array('subleader', $roles);
        if ($isMemberLogin) {
            $stmt = $db->prepare("
                SELECT t.*, COALESCE(a.name, bm.real_name) AS assignee_name
                FROM tasks t
                LEFT JOIN admins a ON t.assignee_admin_id = a.id
                LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
                WHERE t.start_date <= ? AND t.end_date >= ?
                  AND (t.assignee_member_id = ?
                       OR (t.assignee_member_id IS NULL AND t.assignee_admin_id IS NULL AND t.role IN ({$placeholders})))
                  AND t.cohort = ?
                ORDER BY t.completed, t.end_date, t.title
            ");
        } else {
            $stmt = $db->prepare("
                SELECT t.*, COALESCE(a.name, bm.real_name) AS assignee_name
                FROM tasks t
                LEFT JOIN admins a ON t.assignee_admin_id = a.id
                LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
                WHERE t.start_date <= ? AND t.end_date >= ?
                  AND (t.assignee_admin_id = ?
                       OR (t.assignee_admin_id IS NULL AND t.role IN ({$placeholders})))
                  AND t.cohort = ?
                ORDER BY t.completed, t.end_date, t.title
            ");
        }
        $params = [$date, $date, $adminId];
        $params = array_merge($params, $roles);
        $params[] = $cohort;
        $stmt->execute($params);
    }
    jsonSuccess(['tasks' => $stmt->fetchAll()]);
    break;

case 'overdue_tasks':
    $admin = requireAdmin();
    $cohort = getEffectiveCohort($admin);
    $today = date('Y-m-d');
    $roles = $admin['admin_roles'];
    $adminId = $admin['admin_id'];
    $filterRole = $_GET['filter_role'] ?? '';

    $db = getDB();
    if (hasRole($admin, 'operation')) {
        if ($filterRole === 'mine') {
            $stmt = $db->prepare("
                SELECT t.*, COALESCE(a.name, bm.real_name) AS assignee_name
                FROM tasks t
                LEFT JOIN admins a ON t.assignee_admin_id = a.id
                LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
                WHERE t.end_date < ? AND t.completed = 0 AND t.cohort = ?
                  AND t.assignee_admin_id = ?
                ORDER BY t.end_date
            ");
            $stmt->execute([$today, $cohort, $adminId]);
        } elseif ($filterRole && $filterRole !== 'all') {
            $stmt = $db->prepare("
                SELECT t.*, COALESCE(a.name, bm.real_name) AS assignee_name
                FROM tasks t
                LEFT JOIN admins a ON t.assignee_admin_id = a.id
                LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
                WHERE t.end_date < ? AND t.completed = 0 AND t.cohort = ?
                  AND t.role = ?
                ORDER BY t.end_date
            ");
            $stmt->execute([$today, $cohort, $filterRole]);
        } else {
            $stmt = $db->prepare("
                SELECT t.*, COALESCE(a.name, bm.real_name) AS assignee_name
                FROM tasks t
                LEFT JOIN admins a ON t.assignee_admin_id = a.id
                LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
                WHERE t.end_date < ? AND t.completed = 0 AND t.cohort = ?
                ORDER BY t.end_date
            ");
            $stmt->execute([$today, $cohort]);
        }
    } else {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $isMemberLogin = in_array('leader', $roles) || in_array('subleader', $roles);
        if ($isMemberLogin) {
            $stmt = $db->prepare("
                SELECT t.*, COALESCE(a.name, bm.real_name) AS assignee_name
                FROM tasks t
                LEFT JOIN admins a ON t.assignee_admin_id = a.id
                LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
                WHERE t.end_date < ? AND t.completed = 0
                  AND (t.assignee_member_id = ?
                       OR (t.assignee_member_id IS NULL AND t.assignee_admin_id IS NULL AND t.role IN ({$placeholders})))
                  AND t.cohort = ?
                ORDER BY t.end_date
            ");
        } else {
            $stmt = $db->prepare("
                SELECT t.*, COALESCE(a.name, bm.real_name) AS assignee_name
                FROM tasks t
                LEFT JOIN admins a ON t.assignee_admin_id = a.id
                LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
                WHERE t.end_date < ? AND t.completed = 0
                  AND (t.assignee_admin_id = ?
                       OR (t.assignee_admin_id IS NULL AND t.role IN ({$placeholders})))
                  AND t.cohort = ?
                ORDER BY t.end_date
            ");
        }
        $params = [$today, $adminId];
        $params = array_merge($params, $roles);
        $params[] = $cohort;
        $stmt->execute($params);
    }
    jsonSuccess(['tasks' => $stmt->fetchAll()]);
    break;

case 'toggle_task':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin();
    $input = getJsonInput();
    $taskId = (int)($input['task_id'] ?? 0);
    $completed = !empty($input['completed']) ? 1 : 0;

    if (!$taskId) jsonError('task_id가 필요합니다.');

    $db = getDB();
    $stmt = $db->prepare('UPDATE tasks SET completed = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$completed, $taskId]);
    jsonSuccess([], $completed ? '완료 처리되었습니다.' : '미완료로 변경되었습니다.');
    break;

case 'all_tasks':
    $admin = requireAdmin();
    if (!hasRole($admin, 'operation')) jsonError('권한이 없습니다.', 403);
    $cohort = getEffectiveCohort($admin);
    $filterRole = $_GET['filter_role'] ?? '';
    $adminId = $admin['admin_id'];

    $db = getDB();
    if ($filterRole === 'mine') {
        $stmt = $db->prepare("
            SELECT t.*, COALESCE(a.name, bm.real_name) AS assignee_name
            FROM tasks t
            LEFT JOIN admins a ON t.assignee_admin_id = a.id
            LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
            WHERE t.cohort = ? AND (t.assignee_admin_id = ? OR t.assignee_member_id = ?)
            ORDER BY t.start_date DESC, t.title
        ");
        $stmt->execute([$cohort, $adminId, $adminId]);
    } elseif ($filterRole && $filterRole !== 'all') {
        $stmt = $db->prepare("
            SELECT t.*, COALESCE(a.name, bm.real_name) AS assignee_name
            FROM tasks t
            LEFT JOIN admins a ON t.assignee_admin_id = a.id
            LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
            WHERE t.cohort = ? AND t.role = ?
            ORDER BY t.start_date DESC, t.title
        ");
        $stmt->execute([$cohort, $filterRole]);
    } else {
        $stmt = $db->prepare("
            SELECT t.*, COALESCE(a.name, bm.real_name) AS assignee_name
            FROM tasks t
            LEFT JOIN admins a ON t.assignee_admin_id = a.id
            LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
            WHERE t.cohort = ?
            ORDER BY t.start_date DESC, t.title
        ");
        $stmt->execute([$cohort]);
    }
    jsonSuccess(['tasks' => $stmt->fetchAll()]);
    break;

// ── Guides ──────────────────────────────────────────────────

case 'guide_list':
    $admin = requireAdmin();
    $cohort = getEffectiveCohort($admin);
    $roles = $admin['admin_roles'];

    $db = getDB();
    if (hasRole($admin, 'operation')) {
        $stmt = $db->prepare('SELECT * FROM guides WHERE cohort = ? AND is_active = 1 ORDER BY sort_order, title');
        $stmt->execute([$cohort]);
    } else {
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $db->prepare("SELECT * FROM guides WHERE role IN ({$placeholders}) AND cohort = ? AND is_active = 1 ORDER BY sort_order, title");
        $params = array_merge($roles, [$cohort]);
        $stmt->execute($params);
    }
    jsonSuccess(['guides' => $stmt->fetchAll()]);
    break;

// ── Cohort Management (operation only) ──────────────────────

case 'change_cohort':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $newCohort = trim($input['cohort'] ?? '');
    if (!$newCohort) jsonError('기수를 입력해주세요.');
    updateSetting('current_cohort', $newCohort);
    jsonSuccess([], "기수가 '{$newCohort}'으로 변경되었습니다.");
    break;

case 'cohort_list':
    requireAdmin(['operation']);
    $db = getDB();
    // Get distinct cohorts from admins + tasks + cohorts table
    $stmt = $db->query("
        SELECT DISTINCT cohort FROM (
            SELECT cohort FROM admins WHERE cohort IS NOT NULL
            UNION
            SELECT cohort FROM tasks
            UNION
            SELECT cohort FROM cohorts
        ) AS c ORDER BY cohort
    ");
    $cohorts = array_column($stmt->fetchAll(), 'cohort');
    $current = getSetting('current_cohort');

    // Get cohorts table data
    $stmt2 = $db->query('SELECT * FROM cohorts ORDER BY cohort');
    $cohortDetails = $stmt2->fetchAll();

    jsonSuccess(['cohorts' => $cohorts, 'current_cohort' => $current, 'cohort_details' => $cohortDetails]);
    break;

case 'cohort_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $cohort    = trim($input['cohort'] ?? '');
    $startDate = $input['start_date'] ?? '';
    $endDate   = $input['end_date'] ?? '';

    if (!$cohort || !$startDate || !$endDate) jsonError('기수명, 시작일, 종료일을 모두 입력해주세요.');

    $db = getDB();
    $stmt = $db->prepare('INSERT INTO cohorts (cohort, start_date, end_date) VALUES (?, ?, ?)');
    $stmt->execute([$cohort, $startDate, $endDate]);
    jsonSuccess(['id' => (int)$db->lastInsertId()], '기수가 추가되었습니다.');
    break;

case 'cohort_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('기수 ID가 필요합니다.');

    $fields = [];
    $params = [];
    foreach (['cohort', 'start_date', 'end_date'] as $f) {
        if (isset($input[$f])) { $fields[] = "{$f} = ?"; $params[] = trim($input[$f]); }
    }
    if (!$fields) jsonError('수정할 내용이 없습니다.');

    $params[] = $id;
    $db = getDB();
    $db->prepare('UPDATE cohorts SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    jsonSuccess([], '기수 정보가 수정되었습니다.');
    break;

case 'cohort_delete':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('기수 ID가 필요합니다.');

    $db = getDB();
    $db->prepare('UPDATE cohorts SET is_active = 0 WHERE id = ?')->execute([$id]);
    jsonSuccess([], '기수가 비활성화되었습니다.');
    break;

// ── Member CRUD (operation only) — uses bootcamp_members ────

case 'member_list':
    $admin = requireAdmin(['operation']);
    $cohort = getEffectiveCohort($admin);
    $db = getDB();
    $cStmt = $db->prepare("SELECT id FROM cohorts WHERE cohort = ? LIMIT 1");
    $cStmt->execute([$cohort]);
    $cRow = $cStmt->fetch();
    if ($cRow) ensureScoresFresh($db, (int)$cRow['id']);
    $stmt = $db->prepare("
        SELECT bm.id, bm.real_name, bm.nickname, bm.phone, bm.user_id, bm.cafe_member_key,
               bm.cohort_id, c.cohort, bm.group_id, bg.name AS group_name,
               bm.member_role, bm.member_status, bm.stage_no, bm.is_active, bm.created_at,
               bm.participation_count, bm.entered,
               COALESCE(ms.current_score, 0) AS current_score,
               COALESCE(mcb.current_coin, 0) AS current_coin,
               COALESCE(mhs_u.stage1_participation_count, mhs_p.stage1_participation_count, 0) AS stage1_participation_count,
               COALESCE(mhs_u.stage2_participation_count, mhs_p.stage2_participation_count, 0) AS stage2_participation_count,
               COALESCE(mhs_u.completed_bootcamp_count, mhs_p.completed_bootcamp_count, 0) AS completed_bootcamp_count,
               COALESCE(mhs_u.bravo_grade, mhs_p.bravo_grade) AS bravo_grade
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        LEFT JOIN member_coin_balances mcb ON bm.id = mcb.member_id
        LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
        LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
        WHERE c.cohort = ?
        ORDER BY bm.real_name
    ");
    $stmt->execute([$cohort]);
    jsonSuccess(['members' => $stmt->fetchAll()]);
    break;

case 'member_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $realName = trim($input['name'] ?? $input['real_name'] ?? '');
    $nickname = trim($input['nickname'] ?? '');
    $phone    = trim($input['phone'] ?? '');
    $userId   = trim($input['user_id'] ?? '') ?: null;
    $cohort   = trim($input['cohort'] ?? '') ?: getEffectiveCohort($admin);
    $groupId  = !empty($input['group_id']) ? (int)$input['group_id'] : null;
    $stageNo  = isset($input['stage_no']) ? (int)$input['stage_no'] : 1;

    if (!$realName) jsonError('이름을 입력해주세요.');
    if (!$userId) jsonError('아이디를 입력해주세요.');

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM cohorts WHERE cohort = ?');
    $stmt->execute([$cohort]);
    $cohortRow = $stmt->fetch();
    if (!$cohortRow) jsonError('해당 기수가 존재하지 않습니다.');

    $newId = createMember($db, [
        'cohort_id' => (int)$cohortRow['id'],
        'nickname'  => $nickname,
        'real_name' => $realName,
        'phone'     => $phone,
        'user_id'   => $userId,
        'group_id'  => $groupId,
        'stage_no'  => $stageNo,
    ]);

    if (!empty($input['cafe_member_key'])) {
        $db->prepare('UPDATE bootcamp_members SET cafe_member_key = ? WHERE id = ?')->execute([trim($input['cafe_member_key']), $newId]);
    }

    jsonSuccess(['id' => $newId], '회원이 추가되었습니다.');
    break;

case 'member_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('회원 ID가 필요합니다.');

    $db = getDB();

    // 변경 전 phone/user_id 보존 (갱신 대상 판별용)
    $before = getMemberIdentifiers($db, $id);

    $fields = [];
    $params = [];
    // Map 'name' input to 'real_name' column
    if (isset($input['name'])) { $fields[] = 'real_name = ?'; $params[] = trim($input['name']); }
    if (isset($input['real_name'])) { $fields[] = 'real_name = ?'; $params[] = trim($input['real_name']); }
    foreach (['nickname', 'phone', 'user_id', 'member_role', 'cafe_member_key'] as $f) {
        if (isset($input[$f])) { $fields[] = "{$f} = ?"; $params[] = trim($input[$f]); }
    }
    if (isset($input['stage_no'])) { $fields[] = 'stage_no = ?'; $params[] = (int)$input['stage_no']; }
    if (isset($input['is_active'])) { $fields[] = 'is_active = ?'; $params[] = $input['is_active'] ? 1 : 0; }
    if (isset($input['cohort'])) {
        // Resolve cohort name to cohort_id
        $stmt = $db->prepare('SELECT id FROM cohorts WHERE cohort = ?');
        $stmt->execute([trim($input['cohort'])]);
        $cohortRow = $stmt->fetch();
        if (!$cohortRow) jsonError('해당 기수가 존재하지 않습니다.');
        $fields[] = 'cohort_id = ?';
        $params[] = (int)$cohortRow['id'];
    }
    if (array_key_exists('group_id', $input)) {
        $fields[] = 'group_id = ?';
        $params[] = $input['group_id'] ? (int)$input['group_id'] : null;
    }
    if (!$fields) jsonError('수정할 내용이 없습니다.');

    // role 변경 감지 (코인 처리용)
    $beforeRole = null;
    if (isset($input['member_role'])) {
        $brStmt = $db->prepare("SELECT member_role FROM bootcamp_members WHERE id = ?");
        $brStmt->execute([$id]);
        $brRow = $brStmt->fetch();
        $beforeRole = $brRow ? $brRow['member_role'] : null;
    }

    // cafe_member_key 중복 해소: 다른 회원에게 등록된 키를 가져오는 경우 기존 회원의 키 해제
    if (!empty($input['cafe_member_key'])) {
        $cafeKey = trim($input['cafe_member_key']);
        $db->prepare('UPDATE bootcamp_members SET cafe_member_key = NULL WHERE cafe_member_key = ? AND id != ?')
           ->execute([$cafeKey, $id]);
    }

    $params[] = $id;
    $db->prepare('UPDATE bootcamp_members SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

    // role이 실제로 변경되었으면 코인 처리
    if ($beforeRole !== null && $beforeRole !== $input['member_role'] && function_exists('handleRoleChangeCoin')) {
        handleRoleChangeCoin($db, $id, $beforeRole, $input['member_role'], $admin['admin_id']);
    }

    // 집계 테이블 갱신 (stats 영향 필드 변경 시)
    $statsFields = ['stage_no', 'is_active', 'phone', 'user_id', 'cohort'];
    $needsRefresh = false;
    foreach ($statsFields as $f) {
        if (isset($input[$f])) { $needsRefresh = true; break; }
    }
    if ($needsRefresh) {
        refreshMemberStats($db, $before['phone'], $before['user_id']);
        refreshMemberStatsById($db, $id);
    }

    jsonSuccess([], '회원 정보가 수정되었습니다.');
    break;

case 'member_delete':
    // 환불 처리 (소프트 삭제)
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('회원 ID가 필요합니다.');

    $db = getDB();
    $db->prepare("UPDATE bootcamp_members SET member_status = 'refunded', is_active = 0 WHERE id = ?")->execute([$id]);

    $ident = getMemberIdentifiers($db, $id);
    refreshMemberStats($db, $ident['phone'], $ident['user_id']);

    jsonSuccess([], '환불 처리되었습니다.');
    break;

case 'member_restore':
    // 환불 멤버 복원
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('회원 ID가 필요합니다.');

    $db = getDB();
    $db->prepare("UPDATE bootcamp_members SET member_status = 'active', is_active = 1 WHERE id = ? AND member_status = 'refunded'")->execute([$id]);

    jsonSuccess([], '회원이 복원되었습니다.');
    break;

case 'member_set_status':
    // 나가기 설정/해제
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    $status = $input['status'] ?? '';
    if (!$id) jsonError('회원 ID가 필요합니다.');
    if (!in_array($status, ['active', 'leaving'])) jsonError('유효하지 않은 상태입니다.');

    $db = getDB();
    if ($status === 'leaving') {
        // 나가기: is_active 유지(로그인 가능), 조 소속 해제
        $db->prepare("UPDATE bootcamp_members SET member_status = 'leaving', group_id = NULL WHERE id = ?")->execute([$id]);
    } else {
        // 활성 복원
        $db->prepare("UPDATE bootcamp_members SET member_status = 'active' WHERE id = ?")->execute([$id]);
    }

    $label = $status === 'leaving' ? '나간 회원' : '활성';
    jsonSuccess([], "'{$label}' 상태로 변경되었습니다.");
    break;

case 'fetch_cafe_info':
    $admin = requireAdmin(['operation']);
    $articleId = $_GET['article_id'] ?? '';
    if (!$articleId) jsonError('게시글 번호가 필요합니다.');
    
    $cafeId = 23243775;
    $buid = 'a968c143-ebd4-46bb-82ff-5f11230389c5';
    $url = "https://article.cafe.naver.com/gw/v4/cafes/{$cafeId}/articles/{$articleId}?fromList=true&menuId=292&tc=cafe_article_list&useCafeId=true&buid={$buid}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        jsonError("HTTP 오류: {$httpCode}");
    }
    
    $data = json_decode($response, true);
    if (isset($data['result']['errorCode'])) {
        jsonError($data['result']['message'] ?? '게시글 접근 불가');
    }
    
    if (!isset($data['result']['article']['writer'])) {
        jsonError('작성자 정보를 찾을 수 없습니다.');
    }
    
    $writer = $data['result']['article']['writer'];
    $memberKey = $writer['memberKey'] ?? '';
    $nick = $writer['nick'] ?? '';
    
    if (!$memberKey) {
        jsonError('memberKey를 추출할 수 없습니다.');
    }
    
    $db = getDB();
    $stmt = $db->prepare('SELECT id, real_name FROM bootcamp_members WHERE cafe_member_key = ?');
    $stmt->execute([$memberKey]);
    $existingMember = $stmt->fetch(PDO::FETCH_ASSOC);
    
    jsonSuccess([
        'data' => [
            'memberKey' => $memberKey,
            'nick' => $nick,
            'existingMember' => $existingMember ?: null
        ]
    ]);
    break;

case 'lookup_cafe_nick':
    $admin = requireAdmin(['operation']);
    $memberKey = trim($_GET['member_key'] ?? '');
    if (!$memberKey) jsonError('카페 유저 키가 필요합니다.');

    $cafeId = 23243775;
    $url = "https://cafe.naver.com/ca-fe/cafes/{$cafeId}/members/{$memberKey}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $nick = null;
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $nick = $data['result']['nickname'] ?? $data['result']['nick'] ?? $data['result']['memberNickname'] ?? null;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT id, real_name FROM bootcamp_members WHERE cafe_member_key = ?');
    $stmt->execute([$memberKey]);
    $existingMember = $stmt->fetch(PDO::FETCH_ASSOC);

    jsonSuccess([
        'data' => [
            'nick' => $nick,
            'existingMember' => $existingMember ?: null
        ]
    ]);
    break;

// ── Admin CRUD (operation only) ─────────────────────────────

case 'admin_list':
    $admin = requireAdmin(['operation']);
    $db = getDB();
    $stmt = $db->query('
        SELECT a.id, a.name, a.login_id, a.cohort, a.team, a.class_time, a.bootcamp_group_id,
               a.member_id, a.is_active, a.last_login_at,
               GROUP_CONCAT(ar.role ORDER BY ar.role) AS roles_csv,
               bg.name AS bootcamp_group_name,
               bm.nickname AS member_nickname,
               ms.current_score AS member_score
        FROM admins a
        LEFT JOIN admin_roles ar ON a.id = ar.admin_id
        LEFT JOIN bootcamp_groups bg ON a.bootcamp_group_id = bg.id
        LEFT JOIN bootcamp_members bm ON a.member_id = bm.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        GROUP BY a.id
        ORDER BY a.name
    ');
    $admins = $stmt->fetchAll();
    // Convert roles_csv to array
    foreach ($admins as &$a) {
        $a['roles'] = $a['roles_csv'] ? explode(',', $a['roles_csv']) : [];
        unset($a['roles_csv']);
    }
    unset($a);
    jsonSuccess(['admins' => $admins]);
    break;

case 'admin_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $name      = trim($input['name'] ?? '');
    $loginId   = trim($input['login_id'] ?? '');
    $password  = $input['password'] ?? '';
    $roles     = $input['roles'] ?? [];
    $cohort    = trim($input['cohort'] ?? '') ?: null;
    $team      = trim($input['team'] ?? '') ?: null;
    $classTime = trim($input['class_time'] ?? '') ?: null;

    if (!$name || !$loginId || !$password || empty($roles)) jsonError('필수 항목을 모두 입력해주세요.');

    $validRoles = ['leader', 'subleader', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2', 'operation'];
    foreach ($roles as $r) {
        if (!in_array($r, $validRoles)) jsonError("올바르지 않은 역할: {$r}");
    }

    if (in_array('operation', $roles)) $cohort = null;

    $memberId = !empty($input['member_id']) ? (int)$input['member_id'] : null;
    $bcGroupId = null;

    $db = getDB();

    // member_id → auto-set bootcamp_group_id
    if ($memberId) {
        $mStmt = $db->prepare('SELECT group_id FROM bootcamp_members WHERE id = ?');
        $mStmt->execute([$memberId]);
        $mRow = $mStmt->fetch();
        if (!$mRow) jsonError('해당 회원을 찾을 수 없습니다.');
        $bcGroupId = $mRow['group_id'] ? (int)$mRow['group_id'] : null;
    }

    // Check duplicate login_id
    $stmt = $db->prepare('SELECT id FROM admins WHERE login_id = ?');
    $stmt->execute([$loginId]);
    if ($stmt->fetch()) jsonError('이미 사용 중인 아이디입니다.');

    // Insert admin (role column = first role for backward compat)
    $primaryRole = $roles[0];
    $stmt = $db->prepare('INSERT INTO admins (name, login_id, password_hash, role, cohort, team, class_time, bootcamp_group_id, member_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $loginId, password_hash($password, PASSWORD_DEFAULT), $primaryRole, $cohort, $team, $classTime, $bcGroupId, $memberId]);
    $newId = (int)$db->lastInsertId();

    // Insert roles
    $stmt = $db->prepare('INSERT INTO admin_roles (admin_id, role) VALUES (?, ?)');
    foreach ($roles as $r) {
        $stmt->execute([$newId, $r]);
    }

    jsonSuccess(['id' => $newId], '관리자가 추가되었습니다.');
    break;

case 'admin_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('관리자 ID가 필요합니다.');

    $db = getDB();
    $fields = [];
    $params = [];
    foreach (['name', 'login_id', 'cohort', 'team', 'class_time'] as $f) {
        if (isset($input[$f])) {
            $val = trim($input[$f]);
            $fields[] = "{$f} = ?";
            $params[] = $val ?: null;
        }
    }
    if (array_key_exists('member_id', $input)) {
        $memberId = !empty($input['member_id']) ? (int)$input['member_id'] : null;
        $fields[] = 'member_id = ?';
        $params[] = $memberId;
        // Auto-sync bootcamp_group_id from member
        if ($memberId) {
            $mStmt = $db->prepare('SELECT group_id FROM bootcamp_members WHERE id = ?');
            $mStmt->execute([$memberId]);
            $mRow = $mStmt->fetch();
            if ($mRow) {
                $fields[] = 'bootcamp_group_id = ?';
                $params[] = $mRow['group_id'] ? (int)$mRow['group_id'] : null;
            }
        } else {
            $fields[] = 'bootcamp_group_id = ?';
            $params[] = null;
        }
    } elseif (array_key_exists('bootcamp_group_id', $input)) {
        $fields[] = 'bootcamp_group_id = ?';
        $params[] = $input['bootcamp_group_id'] ? (int)$input['bootcamp_group_id'] : null;
    }
    if (isset($input['is_active'])) { $fields[] = 'is_active = ?'; $params[] = $input['is_active'] ? 1 : 0; }
    if (!empty($input['password'])) {
        $fields[] = 'password_hash = ?';
        $params[] = password_hash($input['password'], PASSWORD_DEFAULT);
    }

    // Handle roles update
    if (isset($input['roles']) && is_array($input['roles'])) {
        $roles = $input['roles'];
        $validRoles = ['leader', 'subleader', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2', 'operation'];
        foreach ($roles as $r) {
            if (!in_array($r, $validRoles)) jsonError("올바르지 않은 역할: {$r}");
        }

        if (in_array('operation', $roles) && isset($input['cohort'])) {
            // Override cohort to null for operation
            $cohortIdx = array_search('cohort = ?', $fields);
            if ($cohortIdx !== false) {
                $params[$cohortIdx] = null;
            }
        }

        // Update primary role column
        $fields[] = 'role = ?';
        $params[] = $roles[0];

        // Update admin_roles table
        $db->prepare('DELETE FROM admin_roles WHERE admin_id = ?')->execute([$id]);
        $stmtRole = $db->prepare('INSERT INTO admin_roles (admin_id, role) VALUES (?, ?)');
        foreach ($roles as $r) {
            $stmtRole->execute([$id, $r]);
        }
    }

    if ($fields) {
        $params[] = $id;
        $db->prepare('UPDATE admins SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    }

    jsonSuccess([], '관리자 정보가 수정되었습니다.');
    break;

case 'member_candidates':
    $admin = requireAdmin(['operation']);
    $db = getDB();
    $cohortId = $_GET['cohort_id'] ?? null;
    if (!$cohortId) {
        $cohortId = getSetting('current_cohort');
    }
    $stmt = $db->prepare('
        SELECT bm.id, bm.nickname, bm.real_name, bm.group_id,
               bg.name AS group_name,
               a_linked.id AS linked_admin_id, a_linked.name AS linked_admin_name
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN admins a_linked ON a_linked.member_id = bm.id AND a_linked.is_active = 1
        WHERE bm.cohort_id = ? AND bm.is_active = 1
        ORDER BY bg.name, bm.nickname
    ');
    $stmt->execute([$cohortId]);
    jsonSuccess(['members' => $stmt->fetchAll()]);
    break;

case 'admin_delete':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('관리자 ID가 필요합니다.');
    if ($id === $admin['admin_id']) jsonError('본인 계정은 삭제할 수 없습니다.');

    $db = getDB();
    // 연결된 강의 세션 취소 처리
    $db->prepare("UPDATE lecture_sessions SET status = 'cancelled' WHERE coach_admin_id = ? AND status = 'active'")->execute([$id]);
    $db->prepare("UPDATE lecture_schedules SET status = 'cancelled' WHERE coach_admin_id = ? AND status = 'active'")->execute([$id]);
    // admin_roles: CASCADE, lecture_schedules.coach_admin_id: SET NULL, tasks.assignee_admin_id: SET NULL
    $db->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
    jsonSuccess([], '관리자가 삭제되었습니다.');
    break;

// ── Task CRUD (operation only for create/update/delete) ─────

case 'task_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $title    = trim($input['title'] ?? '');
    $roles    = $input['roles'] ?? [];
    $content  = trim($input['content_markdown'] ?? '') ?: null;
    $cohort   = trim($input['cohort'] ?? '') ?: getEffectiveCohort($admin);
    $dateMode = $input['date_mode'] ?? 'direct';

    if (!$title || empty($roles)) jsonError('제목과 역할을 입력해주세요.');

    $validRoles = ['leader', 'subleader', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2', 'operation'];
    foreach ($roles as $r) {
        if (!in_array($r, $validRoles)) jsonError("올바르지 않은 역할: {$r}");
    }

    $db = getDB();

    // Build date pairs based on mode
    $datePairs = []; // array of [start_date, end_date]

    if ($dateMode === 'direct') {
        $startDate = $input['start_date'] ?? '';
        $endDate   = $input['end_date'] ?? '';
        if (!$startDate || !$endDate) jsonError('시작일과 종료일을 입력해주세요.');
        $datePairs[] = [$startDate, $endDate];

    } elseif ($dateMode === 'week') {
        $weekNumber = (int)($input['week_number'] ?? 0);
        $weekday    = (int)($input['weekday'] ?? -1);
        if ($weekNumber < 1 || $weekday < 0 || $weekday > 6) jsonError('주차와 요일을 올바르게 입력해주세요.');

        // Get cohort start_date
        $stmt = $db->prepare('SELECT start_date FROM cohorts WHERE cohort = ?');
        $stmt->execute([$cohort]);
        $cohortRow = $stmt->fetch();
        if (!$cohortRow) jsonError('해당 기수의 시작일 정보가 없습니다. 기수 관리에서 먼저 설정해주세요.');

        $cohortStart = new DateTime($cohortRow['start_date']);
        // Week 1 starts on the Monday of the week containing cohort start_date
        // PHP: 1=Mon, 7=Sun. Input weekday: 0=Sun, 1=Mon, ..., 6=Sat
        $phpWeekday = $weekday === 0 ? 7 : $weekday; // Convert to ISO (1=Mon, 7=Sun)
        $startDayOfWeek = (int)$cohortStart->format('N'); // 1=Mon, 7=Sun
        // Monday of the week containing cohort start
        $weekMonday = clone $cohortStart;
        $weekMonday->modify('-' . ($startDayOfWeek - 1) . ' days');
        // Add (weekNumber - 1) weeks
        $weekMonday->modify('+' . ($weekNumber - 1) . ' weeks');
        // Target date = that week's Monday + (phpWeekday - 1) days
        $targetDate = clone $weekMonday;
        $targetDate->modify('+' . ($phpWeekday - 1) . ' days');
        $dateStr = $targetDate->format('Y-m-d');
        $datePairs[] = [$dateStr, $dateStr];

    } elseif ($dateMode === 'daily') {
        $repeatDays = $input['repeat_days'] ?? [];
        if (empty($repeatDays)) jsonError('반복할 요일을 선택해주세요.');

        // Get cohort date range
        $stmt = $db->prepare('SELECT start_date, end_date FROM cohorts WHERE cohort = ?');
        $stmt->execute([$cohort]);
        $cohortRow = $stmt->fetch();
        if (!$cohortRow) jsonError('해당 기수의 날짜 정보가 없습니다. 기수 관리에서 먼저 설정해주세요.');

        // Convert repeat_days (0=Sun,1=Mon,...,6=Sat) to PHP day-of-week format
        $current = new DateTime($cohortRow['start_date']);
        $end     = new DateTime($cohortRow['end_date']);
        while ($current <= $end) {
            $dow = (int)$current->format('w'); // 0=Sun, 6=Sat
            if (in_array($dow, $repeatDays)) {
                $dateStr = $current->format('Y-m-d');
                $datePairs[] = [$dateStr, $dateStr];
            }
            $current->modify('+1 day');
        }
        if (empty($datePairs)) jsonError('선택한 요일에 해당하는 날짜가 cohort 기간 내에 없습니다.');
    } else {
        jsonError('올바르지 않은 날짜 설정 방식입니다.');
    }

    $insertAdminStmt = $db->prepare('INSERT INTO tasks (title, role, assignee_admin_id, start_date, end_date, content_markdown, cohort) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insertMemberStmt = $db->prepare('INSERT INTO tasks (title, role, assignee_member_id, start_date, end_date, content_markdown, cohort) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $createdCount = 0;

    // Get cohort_id for bootcamp_members lookup
    $cohortIdStmt = $db->prepare('SELECT id FROM cohorts WHERE cohort = ?');
    $cohortIdStmt->execute([$cohort]);
    $cohortIdRow = $cohortIdStmt->fetch();
    $cohortId = $cohortIdRow ? (int)$cohortIdRow['id'] : null;

    foreach ($datePairs as [$sd, $ed]) {
        foreach ($roles as $role) {
            $isMemberRole = in_array($role, ['leader', 'subleader']);
            $assignees = [];

            if ($isMemberRole && $cohortId) {
                // Leader/subleader: lookup from bootcamp_members
                $stmt = $db->prepare('
                    SELECT id FROM bootcamp_members
                    WHERE member_role = ? AND is_active = 1 AND cohort_id = ?
                ');
                $stmt->execute([$role, $cohortId]);
                $assignees = $stmt->fetchAll();
            } else if (!$isMemberRole) {
                // Other roles: lookup from admins + admin_roles
                $stmt = $db->prepare('
                    SELECT a.id FROM admins a
                    JOIN admin_roles ar ON a.id = ar.admin_id
                    WHERE ar.role = ? AND a.is_active = 1
                      AND (a.cohort = ? OR a.cohort IS NULL)
                ');
                $stmt->execute([$role, $cohort]);
                $assignees = $stmt->fetchAll();
            }

            if (empty($assignees)) {
                $insertAdminStmt->execute([$title, $role, null, $sd, $ed, $content, $cohort]);
                $createdCount++;
            } else {
                $ins = $isMemberRole ? $insertMemberStmt : $insertAdminStmt;
                foreach ($assignees as $a) {
                    $ins->execute([$title, $role, $a['id'], $sd, $ed, $content, $cohort]);
                    $createdCount++;
                }
            }
        }
    }

    jsonSuccess(['created_count' => $createdCount], "Task가 {$createdCount}개 생성되었습니다.");
    break;

case 'task_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('Task ID가 필요합니다.');

    $fields = [];
    $params = [];
    foreach (['title', 'role', 'start_date', 'end_date', 'content_markdown', 'cohort'] as $f) {
        if (isset($input[$f])) { $fields[] = "{$f} = ?"; $params[] = trim($input[$f]) ?: null; }
    }
    if (array_key_exists('assignee_admin_id', $input)) {
        $fields[] = 'assignee_admin_id = ?';
        $params[] = $input['assignee_admin_id'] ? (int)$input['assignee_admin_id'] : null;
    }
    if (isset($input['completed'])) { $fields[] = 'completed = ?'; $params[] = $input['completed'] ? 1 : 0; }
    if (!$fields) jsonError('수정할 내용이 없습니다.');

    $params[] = $id;
    $db = getDB();
    $db->prepare('UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    jsonSuccess([], 'Task가 수정되었습니다.');
    break;

case 'task_delete':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('Task ID가 필요합니다.');

    $db = getDB();
    $db->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
    jsonSuccess([], 'Task가 삭제되었습니다.');
    break;

// ── Guide CRUD (operation only) ─────────────────────────────

case 'guide_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $title  = trim($input['title'] ?? '');
    $url    = trim($input['url'] ?? '');
    $role   = trim($input['role'] ?? '');
    $note   = trim($input['note'] ?? '') ?: null;
    $cohort = trim($input['cohort'] ?? '') ?: getEffectiveCohort($admin);
    $sort   = (int)($input['sort_order'] ?? 0);

    if (!$title || !$url || !$role) jsonError('필수 항목을 모두 입력해주세요.');

    $db = getDB();
    $stmt = $db->prepare('INSERT INTO guides (title, url, role, note, cohort, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$title, $url, $role, $note, $cohort, $sort]);
    jsonSuccess(['id' => (int)$db->lastInsertId()], '가이드가 추가되었습니다.');
    break;

case 'guide_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('가이드 ID가 필요합니다.');

    $fields = [];
    $params = [];
    foreach (['title', 'url', 'role', 'note', 'cohort'] as $f) {
        if (isset($input[$f])) { $fields[] = "{$f} = ?"; $params[] = trim($input[$f]) ?: null; }
    }
    if (isset($input['sort_order'])) { $fields[] = 'sort_order = ?'; $params[] = (int)$input['sort_order']; }
    if (isset($input['is_active'])) { $fields[] = 'is_active = ?'; $params[] = $input['is_active'] ? 1 : 0; }
    if (!$fields) jsonError('수정할 내용이 없습니다.');

    $params[] = $id;
    $db = getDB();
    $db->prepare('UPDATE guides SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    jsonSuccess([], '가이드가 수정되었습니다.');
    break;

case 'guide_delete':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('가이드 ID가 필요합니다.');

    $db = getDB();
    $db->prepare('DELETE FROM guides WHERE id = ?')->execute([$id]);
    jsonSuccess([], '가이드가 삭제되었습니다.');
    break;

// ── Calendar CRUD (operation only) ──────────────────────────

case 'calendar_list':
    $admin = requireAdmin();
    $cohort = getEffectiveCohort($admin);
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM calendar WHERE cohort = ? ORDER BY start_date');
    $stmt->execute([$cohort]);
    jsonSuccess(['calendar' => $stmt->fetchAll()]);
    break;

case 'calendar_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $label   = trim($input['week_label'] ?? '');
    $start   = $input['start_date'] ?? '';
    $end     = $input['end_date'] ?? '';
    $content = trim($input['content'] ?? '') ?: null;
    $cohort  = trim($input['cohort'] ?? '') ?: getEffectiveCohort($admin);

    if (!$label || !$start || !$end) jsonError('필수 항목을 모두 입력해주세요.');

    $db = getDB();
    $stmt = $db->prepare('INSERT INTO calendar (week_label, start_date, end_date, content, cohort) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$label, $start, $end, $content, $cohort]);
    jsonSuccess(['id' => (int)$db->lastInsertId()], '캘린더가 추가되었습니다.');
    break;

case 'calendar_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('캘린더 ID가 필요합니다.');

    $fields = [];
    $params = [];
    foreach (['week_label', 'start_date', 'end_date', 'content', 'cohort'] as $f) {
        if (isset($input[$f])) { $fields[] = "{$f} = ?"; $params[] = trim($input[$f]) ?: null; }
    }
    if (!$fields) jsonError('수정할 내용이 없습니다.');

    $params[] = $id;
    $db = getDB();
    $db->prepare('UPDATE calendar SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
    jsonSuccess([], '캘린더가 수정되었습니다.');
    break;

case 'calendar_delete':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('캘린더 ID가 필요합니다.');

    $db = getDB();
    $db->prepare('DELETE FROM calendar WHERE id = ?')->execute([$id]);
    jsonSuccess([], '캘린더가 삭제되었습니다.');
    break;

// ── Curriculum (진도 관리) ───────────────────────────────────

case 'curriculum_task_types':
    requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $types = [
        ['key' => 'progress',              'label' => '진도'],
        ['key' => 'event',                 'label' => '이벤트'],
        ['key' => 'lecture',               'label' => '강의 듣기'],
        ['key' => 'malkka_mission',        'label' => '말까미션'],
        ['key' => 'naemat33_mission',      'label' => '내맛33미션'],
        ['key' => 'zoom_or_daily_mission', 'label' => '줌 특강 / 데일리미션'],
        ['key' => 'hamummal',              'label' => '하멈말'],
    ];
    jsonSuccess(['task_types' => $types]);
    break;

case 'curriculum_list':
    $admin = requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $cohort = getEffectiveCohort($admin);
    $db = getDB();
    $stmt = $db->prepare('
        SELECT ci.*, a.name AS created_by_name
        FROM curriculum_items ci
        LEFT JOIN admins a ON ci.created_by = a.id
        WHERE ci.cohort = ?
        ORDER BY ci.target_date DESC, ci.sort_order ASC, ci.id DESC
    ');
    $stmt->execute([$cohort]);
    $items = $stmt->fetchAll();

    $typeLabels = [
        'progress'              => '진도',
        'event'                 => '이벤트',
        'lecture'               => '강의 듣기',
        'malkka_mission'        => '말까미션',
        'naemat33_mission'      => '내맛33미션',
        'zoom_or_daily_mission' => '줌 특강 / 데일리미션',
        'hamummal'              => '하멈말',
    ];
    foreach ($items as &$item) {
        $item['task_type_label'] = $typeLabels[$item['task_type']] ?? $item['task_type'];
    }
    unset($item);

    jsonSuccess(['items' => $items]);
    break;

case 'curriculum_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    $cohort     = trim($input['cohort'] ?? '') ?: getEffectiveCohort($admin);
    $targetDate = trim($input['target_date'] ?? '');
    $taskType   = trim($input['task_type'] ?? '');
    $note       = trim($input['note'] ?? '') ?: null;
    $sortOrder  = (int)($input['sort_order'] ?? 0);

    $validTypes = ['progress', 'event', 'lecture', 'malkka_mission', 'naemat33_mission', 'zoom_or_daily_mission', 'hamummal'];

    if (!$targetDate) jsonError('날짜를 입력해주세요.');
    if (!$taskType || !in_array($taskType, $validTypes)) jsonError('올바른 할 일 유형을 선택해주세요.');

    // 주차/요일 모드 지원: week_number + weekday가 있으면 target_date 계산
    if (empty($targetDate) || (!empty($input['week_number']) && isset($input['weekday']))) {
        $weekNumber = (int)($input['week_number'] ?? 0);
        $weekday    = (int)($input['weekday'] ?? -1);
        if ($weekNumber < 1 || $weekday < 0 || $weekday > 6) jsonError('주차와 요일을 올바르게 입력해주세요.');

        $db = getDB();
        $stmt = $db->prepare('SELECT start_date FROM cohorts WHERE cohort = ?');
        $stmt->execute([$cohort]);
        $cohortRow = $stmt->fetch();
        if (!$cohortRow) jsonError('해당 기수의 시작일 정보가 없습니다.');

        $cohortStart = new DateTime($cohortRow['start_date']);
        $phpWeekday = $weekday === 0 ? 7 : $weekday;
        $startDayOfWeek = (int)$cohortStart->format('N');
        $weekMonday = clone $cohortStart;
        $weekMonday->modify('-' . ($startDayOfWeek - 1) . ' days');
        $weekMonday->modify('+' . ($weekNumber - 1) . ' weeks');
        $targetDateObj = clone $weekMonday;
        $targetDateObj->modify('+' . ($phpWeekday - 1) . ' days');
        $targetDate = $targetDateObj->format('Y-m-d');
    }

    $db = getDB();
    $stmt = $db->prepare('
        INSERT INTO curriculum_items (cohort, target_date, task_type, note, sort_order, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$cohort, $targetDate, $taskType, $note, $sortOrder, $admin['admin_id']]);

    jsonSuccess(['id' => (int)$db->lastInsertId()], '진도가 추가되었습니다.');
    break;

case 'curriculum_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    $id         = (int)($input['id'] ?? 0);
    $cohort     = getEffectiveCohort($admin);
    $targetDate = trim($input['target_date'] ?? '');
    $taskType   = trim($input['task_type'] ?? '');
    $note       = trim($input['note'] ?? '') ?: null;
    $sortOrder  = (int)($input['sort_order'] ?? 0);

    $validTypes = ['progress', 'event', 'lecture', 'malkka_mission', 'naemat33_mission', 'zoom_or_daily_mission', 'hamummal'];

    if (!$id) jsonError('ID가 필요합니다.');
    if (!$targetDate) jsonError('날짜를 입력해주세요.');
    if (!$taskType || !in_array($taskType, $validTypes)) jsonError('올바른 할 일 유형을 선택해주세요.');

    // 주차/요일 모드 지원
    if (empty($targetDate) || (!empty($input['week_number']) && isset($input['weekday']))) {
        $weekNumber = (int)($input['week_number'] ?? 0);
        $weekday    = (int)($input['weekday'] ?? -1);
        if ($weekNumber < 1 || $weekday < 0 || $weekday > 6) jsonError('주차와 요일을 올바르게 입력해주세요.');

        $db = getDB();
        $stmt = $db->prepare('SELECT start_date FROM cohorts WHERE cohort = ?');
        $stmt->execute([$cohort]);
        $cohortRow = $stmt->fetch();
        if (!$cohortRow) jsonError('해당 기수의 시작일 정보가 없습니다.');

        $cohortStart = new DateTime($cohortRow['start_date']);
        $phpWeekday = $weekday === 0 ? 7 : $weekday;
        $startDayOfWeek = (int)$cohortStart->format('N');
        $weekMonday = clone $cohortStart;
        $weekMonday->modify('-' . ($startDayOfWeek - 1) . ' days');
        $weekMonday->modify('+' . ($weekNumber - 1) . ' weeks');
        $targetDateObj = clone $weekMonday;
        $targetDateObj->modify('+' . ($phpWeekday - 1) . ' days');
        $targetDate = $targetDateObj->format('Y-m-d');
    }

    $db = getDB();
    $stmt = $db->prepare('
        UPDATE curriculum_items SET target_date = ?, task_type = ?, note = ?, sort_order = ?
        WHERE id = ? AND cohort = ?
    ');
    $stmt->execute([$targetDate, $taskType, $note, $sortOrder, $id, $cohort]);

    if ($stmt->rowCount() === 0) jsonError('해당 항목을 찾을 수 없습니다.');
    jsonSuccess([], '진도가 수정되었습니다.');
    break;

case 'curriculum_delete':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('ID가 필요합니다.');

    $cohort = getEffectiveCohort($admin);
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM curriculum_items WHERE id = ? AND cohort = ?');
    $stmt->execute([$id, $cohort]);

    if ($stmt->rowCount() === 0) jsonError('해당 항목을 찾을 수 없습니다.');
    jsonSuccess([], '진도가 삭제되었습니다.');
    break;

// ── Member Event Logs (로그 대시보드) ─────────────────────────

case 'member_event_stats':
    requireAdmin(['operation', 'coach', 'head']);
    $db = getDB();
    $days = max(1, min(90, (int)($_GET['days'] ?? 7)));
    $cohortId = (int)($_GET['cohort_id'] ?? 0);

    $where = "created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $params = [$days];
    if ($cohortId) {
        $where .= " AND cohort_id = ?";
        $params[] = $cohortId;
    }

    // 이벤트별 집계
    $stmt = $db->prepare("
        SELECT event_name, COUNT(*) AS cnt, COUNT(DISTINCT member_id) AS unique_members
        FROM member_event_logs
        WHERE {$where}
        GROUP BY event_name
        ORDER BY cnt DESC
    ");
    $stmt->execute($params);
    $byEvent = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 일별 추이
    $stmt2 = $db->prepare("
        SELECT DATE(created_at) AS log_date, COUNT(*) AS cnt, COUNT(DISTINCT member_id) AS unique_members
        FROM member_event_logs
        WHERE {$where}
        GROUP BY DATE(created_at)
        ORDER BY log_date DESC
    ");
    $stmt2->execute($params);
    $byDate = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // 필터 값별 집계 (캘린더 필터, 부티즈 필터)
    $stmt3 = $db->prepare("
        SELECT event_name, event_value, COUNT(*) AS cnt
        FROM member_event_logs
        WHERE {$where} AND event_value IS NOT NULL
        GROUP BY event_name, event_value
        ORDER BY event_name, cnt DESC
    ");
    $stmt3->execute($params);
    $byValue = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess([
        'days' => $days,
        'by_event' => $byEvent,
        'by_date' => $byDate,
        'by_value' => $byValue,
    ]);
    break;

// ── Bulk Member Registration ────────────────────────────────

case 'member_bulk_validate':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $rows = $input['rows'] ?? [];
    $cohort = trim($input['cohort'] ?? '') ?: getEffectiveCohort($admin);

    if (empty($rows) || !is_array($rows)) jsonError('등록할 데이터가 없습니다.');
    if (count($rows) > 500) jsonError('한 번에 최대 500명까지 등록 가능합니다.');

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM cohorts WHERE cohort = ?');
    $stmt->execute([$cohort]);
    $cohortRow = $stmt->fetch();
    if (!$cohortRow) jsonError('해당 기수가 존재하지 않습니다.');

    $result = validateBulkMembers($rows, (int)$cohortRow['id']);
    jsonSuccess($result);
    break;

case 'member_bulk_register':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $members = $input['members'] ?? [];
    $cohort = trim($input['cohort'] ?? '') ?: getEffectiveCohort($admin);

    if (empty($members) || !is_array($members)) jsonError('등록할 데이터가 없습니다.');
    if (count($members) > 500) jsonError('한 번에 최대 500명까지 등록 가능합니다.');

    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM cohorts WHERE cohort = ?');
    $stmt->execute([$cohort]);
    $cohortRow = $stmt->fetch();
    if (!$cohortRow) jsonError('해당 기수가 존재하지 않습니다.');
    $cohortId = (int)$cohortRow['id'];

    $fileName   = trim($input['file_name'] ?? '') ?: null;
    $totalCount = (int)($input['total_count'] ?? count($members));
    $errorCount = (int)($input['error_count'] ?? 0);
    $dupCount   = (int)($input['duplicate_count'] ?? 0);

    // 최종 등록 전 재검증
    $validation = validateBulkMembers($members, $cohortId);
    if ($validation['summary']['error'] > 0) {
        jsonError('검증 실패한 데이터가 포함되어 있습니다. 다시 확인해주세요.');
    }

    try {
        $result = insertBulkMembers($validation['valid'], $cohortId, $admin['admin_id'], [
            'admin_name'      => $admin['admin_name'],
            'cohort_name'     => $cohort,
            'file_name'       => $fileName,
            'total_count'     => $totalCount,
            'error_count'     => $errorCount,
            'duplicate_count' => $dupCount,
        ]);
        jsonSuccess($result, "{$result['inserted']}명이 등록되었습니다.");
    } catch (\Exception $e) {
        jsonError('등록 중 오류가 발생했습니다: ' . $e->getMessage(), 500);
    }
    break;

case 'member_bulk_logs':
    $admin = requireAdmin(['operation']);
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, admin_name, cohort_name, file_name, total_count, success_count, error_count, duplicate_count, created_at
        FROM member_import_logs
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    jsonSuccess(['logs' => $stmt->fetchAll()]);
    break;

case 'member_bulk_template':
    $admin = requireAdmin(['operation']);
    jsonSuccess([
        'columns' => [
            ['key' => 'real_name', 'label' => '이름', 'required' => true, 'example' => '홍길동'],
            ['key' => 'nickname', 'label' => '닉네임', 'required' => true, 'example' => '길동이'],
            ['key' => 'user_id', 'label' => '아이디', 'required' => false, 'example' => '4114325139@n'],
            ['key' => 'phone', 'label' => '전화번호', 'required' => false, 'example' => '010-1234-5678'],
            ['key' => 'stage_no', 'label' => '단계', 'required' => false, 'example' => '1'],
        ],
    ]);
    break;

// ── Retention (operation only) ──────────────────────────────

case 'retention_pairs':
    handleRetentionPairs();
    break;

// ── Default ─────────────────────────────────────────────────

default:
    jsonError('Unknown action', 404);
}
