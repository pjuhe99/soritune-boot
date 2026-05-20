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
require_once __DIR__ . '/../includes/multipass/multipass_repo.php';
require_once __DIR__ . '/../includes/multipass/multipass_csv_parser.php';
require_once __DIR__ . '/../includes/multipass/multipass_bulk.php';
require_once __DIR__ . '/services/notice.php';
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
    $s = getAdminSession();

    jsonSuccess([
        'admin' => [
            'admin_id'             => $admin['id'],
            'admin_name'           => $admin['name'],
            'admin_roles'          => $roles,
            'cohort'               => $admin['cohort'],
            'team'                 => $admin['team'],
            'class_time'           => $admin['class_time'],
            'bootcamp_group_id'    => $bcGroupId,
            'admin_view_cohort_id' => $s['admin_view_cohort_id'] ?? null,
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

    // Find active bootcamp_member with leader/subleader role by phone.
    // 같은 휴대폰에 이전 기수 row 가 살아있는 dual 케이스: 활성 cohort 의 가장 최근 row 만 선택.
    $stmt = $db->prepare("
        SELECT bm.id, bm.real_name, bm.nickname, bm.member_role, bm.group_id, bm.cohort_id, c.cohort,
               bg.name AS group_name
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        WHERE REPLACE(REPLACE(bm.phone, '-', ''), ' ', '') = ?
          AND bm.is_active = 1
          AND c.is_active = 1
          AND bm.member_role IN ('leader', 'subleader')
        ORDER BY bm.cohort_id DESC
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
    $s = getAdminSession();

    jsonSuccess([
        'admin' => [
            'admin_id'             => $member['id'],
            'admin_name'           => $displayName,
            'admin_roles'          => [$role],
            'cohort'               => $member['cohort'],
            'team'                 => $member['group_name'],
            'class_time'           => null,
            'bootcamp_group_id'    => $bcGroupId,
            'admin_view_cohort_id' => $s['admin_view_cohort_id'] ?? null,
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
    if (hasAnyRole($admin, ['operation','head','subhead1','subhead2'])) {
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
    if (hasAnyRole($admin, ['operation','head','subhead1','subhead2'])) {
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
    $submissionText = isset($input['submission_text']) ? trim((string)$input['submission_text']) : null;

    if (!$taskId) jsonError('task_id가 필요합니다.');

    $db = getDB();

    // 현재 row 의 requires_submission 조회 (검증용)
    $check = $db->prepare('SELECT requires_submission FROM tasks WHERE id = ?');
    $check->execute([$taskId]);
    $row = $check->fetch();
    if (!$row) jsonError('해당 task 를 찾을 수 없습니다.', 404);

    if ($completed === 1 && (int)$row['requires_submission'] === 1) {
        if ($submissionText === null || $submissionText === '') {
            jsonError('결과물을 입력해주세요.', 400);
        }
    }

    if ($completed === 1 && $submissionText !== null && $submissionText !== '') {
        // 완료 + 텍스트 있음 → 텍스트와 시각 함께 갱신
        $stmt = $db->prepare('
            UPDATE tasks
               SET completed = 1, submission_text = ?, submitted_at = NOW(), updated_at = NOW()
             WHERE id = ?
        ');
        $stmt->execute([$submissionText, $taskId]);
    } else {
        // 미완료 또는 (완료 + 텍스트 없음 + requires_submission=0) → completed 만 갱신, 텍스트 보존
        $stmt = $db->prepare('UPDATE tasks SET completed = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$completed, $taskId]);
    }

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

case 'switch_cohort':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    requireAdmin();
    $input = getJsonInput();
    $rawCohortId = $input['cohort_id'] ?? null;
    $cohortId = ($rawCohortId === null || $rawCohortId === '') ? null : (int)$rawCohortId;

    // null = '전체'. 그 외에는 active cohort 인지 검증.
    if ($cohortId !== null) {
        if ($cohortId <= 0) jsonError('cohort_id 형식 오류');
        $stmt = getDB()->prepare("SELECT 1 FROM cohorts WHERE id = ? AND is_active = 1");
        $stmt->execute([$cohortId]);
        if (!$stmt->fetchColumn()) jsonError('해당 기수가 활성 상태가 아닙니다.', 403);
    }

    startSessionFor('admin');
    $_SESSION['admin_view_cohort_id'] = $cohortId;
    session_write_close();
    jsonSuccess(['view_cohort_id' => $cohortId], '기수 보기가 전환되었습니다.');
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
    $stmt = $db->prepare('INSERT INTO cohorts (cohort, code, start_date, end_date) VALUES (?, ?, ?, ?)');
    $stmt->execute([$cohort, $cohort, $startDate, $endDate]);
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

case 'cohort_activate':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('기수 ID가 필요합니다.');

    $db = getDB();
    $db->prepare('UPDATE cohorts SET is_active = 1 WHERE id = ?')->execute([$id]);
    jsonSuccess([], '기수가 활성화되었습니다.');
    break;

// ── Member CRUD (operation only) — uses bootcamp_members ────

case 'member_list':
    $admin = requireAdmin(['operation']);
    $cohort = getEffectiveCohort($admin);
    $includeInactive = !empty($_GET['include_inactive']);
    $db = getDB();
    $cStmt = $db->prepare("SELECT id FROM cohorts WHERE cohort = ? LIMIT 1");
    $cStmt->execute([$cohort]);
    $cRow = $cStmt->fetch();
    if ($cRow) ensureScoresFresh($db, (int)$cRow['id']);

    $where = ["c.cohort = ?"];
    $params = [$cohort];
    if (!$includeInactive) {
        $where[] = "bm.member_status = 'active'";
    }
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
        WHERE " . implode(' AND ', $where) . "
        ORDER BY bm.real_name
    ");
    $stmt->execute($params);
    $members = $stmt->fetchAll();

    $countStmt = $db->prepare("
        SELECT bm.member_status, COUNT(*) AS cnt
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE c.cohort = ?
        GROUP BY bm.member_status
    ");
    $countStmt->execute([$cohort]);
    $statusCounts = ['active' => 0, 'leaving' => 0, 'refunded' => 0, 'out_of_group_management' => 0];
    foreach ($countStmt->fetchAll() as $row) {
        $statusCounts[$row['member_status']] = (int)$row['cnt'];
    }

    jsonSuccess(['members' => $members, 'status_counts' => $statusCounts]);
    break;

case 'member_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $realName = trim($input['name'] ?? $input['real_name'] ?? '');
    $nickname = trim($input['nickname'] ?? '');
    $phone    = trim($input['phone'] ?? '');
    $userId   = trim($input['user_id'] ?? '') ?: null;
    $cohortIdInput = isset($input['cohort_id']) ? (int)$input['cohort_id'] : 0;
    $cohort   = trim($input['cohort'] ?? '') ?: getEffectiveCohort($admin);
    $groupId  = !empty($input['group_id']) ? (int)$input['group_id'] : null;
    $stageNo  = isset($input['stage_no']) ? (int)$input['stage_no'] : 1;

    if (!$realName) jsonError('이름을 입력해주세요.');
    if (!$userId) jsonError('아이디를 입력해주세요.');

    $db = getDB();
    if ($cohortIdInput > 0) {
        $stmt = $db->prepare('SELECT id FROM cohorts WHERE id = ?');
        $stmt->execute([$cohortIdInput]);
        $cohortRow = $stmt->fetch();
        if (!$cohortRow) jsonError('해당 기수가 존재하지 않습니다.');
    } else {
        $stmt = $db->prepare('SELECT id FROM cohorts WHERE cohort = ?');
        $stmt->execute([$cohort]);
        $cohortRow = $stmt->fetch();
        if (!$cohortRow) jsonError('해당 기수가 존재하지 않습니다.');
    }

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
    require_once __DIR__ . '/../includes/cafe/cafe_article_fetch.php';
    $articleId = $_GET['article_id'] ?? '';
    if (!$articleId) jsonError('게시글 번호가 필요합니다.');

    try {
        $info = fetchCafeArticleInfo($articleId);
    } catch (CafeArticleFetchException $e) {
        jsonError($e->getMessage());
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT id, real_name FROM bootcamp_members WHERE cafe_member_key = ?');
    $stmt->execute([$info['member_key']]);
    $existingMember = $stmt->fetch(PDO::FETCH_ASSOC);

    jsonSuccess([
        'data' => [
            'memberKey'      => $info['member_key'],
            'nick'           => $info['nick'],
            'existingMember' => $existingMember ?: null,
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

case 'cafe_bulk_parse':
    $admin = requireAdmin(['operation']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);

    require_once __DIR__ . '/../includes/cafe/cafe_csv_parser.php';
    require_once __DIR__ . '/../includes/cafe/cafe_link_parser.php';
    require_once __DIR__ . '/../includes/cafe/cafe_article_fetch.php';
    require_once __DIR__ . '/../includes/cafe/cafe_bulk_match.php';

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $csv = (string)($input['csv'] ?? '');
    if (trim($csv) === '') jsonError('CSV 가 비어있습니다.');

    $parsed = parseCafeCsv($csv);
    if (count($parsed['rows']) === 0 && count($parsed['errors']) === 0) {
        jsonError('파싱 결과 행이 없습니다.');
    }

    $cohortId = resolveAdminCohortId(null, $admin, false);
    if (!$cohortId) jsonError('cohort 컨텍스트가 없습니다. chip 으로 cohort 선택하세요.');

    $db = getDB();

    $rowsOut = [];
    $rowNum = 0;
    $seenArticle = [];

    foreach ($parsed['rows'] as $r) {
        $rowNum++;
        $out = [
            'row'   => $rowNum,
            'group' => $r['group'],
            'name'  => $r['name'],
            'nick'  => $r['nick'],
            'url'   => $r['url'],
        ];

        // 링크 파싱
        $link = parseCafeLink($r['url']);
        if ($link['error'] !== null) {
            $out['status'] = $link['error'] === 'wrong_cafe' ? 'WRONG_CAFE' : 'INVALID_LINK';
            $out['error']  = $link['error'];
            $rowsOut[] = $out;
            continue;
        }
        $out['article_id'] = $link['article_id'];

        // batch 안 중복
        if (isset($seenArticle[$link['article_id']])) {
            $out['status'] = 'DUPLICATE_IN_BATCH';
            $rowsOut[] = $out;
            continue;
        }
        $seenArticle[$link['article_id']] = true;

        // 카페 API
        try {
            $info = fetchCafeArticleInfo($link['article_id']);
        } catch (CafeArticleFetchException $e) {
            $out['status'] = 'CAFE_FETCH_FAIL';
            $out['error']  = $e->getMessage();
            $rowsOut[] = $out;
            continue;
        }
        $out['member_key'] = $info['member_key'];
        $out['cafe_nick']  = $info['nick'];

        // 매칭
        $match = matchCandidates(
            $db, $cohortId, $info['member_key'],
            $r['group'] !== '' ? $r['group'] : null,
            $r['name'],
            $r['nick']  !== '' ? $r['nick']  : null
        );
        $out['status']          = $match['status'];
        $out['candidates']      = $match['candidates'];
        $out['existing_member'] = $match['existing_member'];

        $rowsOut[] = $out;
    }

    // CSV 파싱 에러 행
    foreach ($parsed['errors'] as $err) {
        $rowsOut[] = [
            'row'    => $err['row'],
            'status' => 'CSV_ERROR',
            'error'  => $err['reason'],
        ];
    }

    // 요약
    $summary = ['total' => count($rowsOut), 'high' => 0, 'mid' => 0, 'low' => 0, 'fail' => 0, 'skip' => 0];
    foreach ($rowsOut as $r) {
        $s = $r['status'] ?? '';
        if ($s === 'HIGH') $summary['high']++;
        elseif (in_array($s, ['MID', 'MID_MULTI'], true)) $summary['mid']++;
        elseif ($s === 'LOW') $summary['low']++;
        elseif (in_array($s, ['ALREADY_MAPPED_SAME', 'DUPLICATE_IN_BATCH'], true)) $summary['skip']++;
        elseif (in_array($s, ['INVALID_LINK', 'WRONG_CAFE', 'CAFE_FETCH_FAIL', 'CSV_ERROR', 'NO_MATCH', 'ALREADY_MAPPED_DIFF'], true)) {
            // NO_MATCH / DIFF 는 실패 아니라 어드민 처리 대기 → fail 카운트엔 안 넣음
            if (in_array($s, ['INVALID_LINK', 'WRONG_CAFE', 'CAFE_FETCH_FAIL', 'CSV_ERROR'], true)) $summary['fail']++;
        }
    }

    jsonSuccess(['data' => ['rows' => $rowsOut, 'summary' => $summary]]);
    break;

case 'cafe_bulk_apply':
    $admin = requireAdmin(['operation']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);

    require_once __DIR__ . '/../includes/cafe/cafe_bulk_apply.php';

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $rows = $input['rows'] ?? [];
    if (!is_array($rows) || empty($rows)) jsonError('적용할 행이 없습니다.');
    if (count($rows) > 100) jsonError('한 번에 100행 까지만 적용 가능합니다.');

    $db = getDB();
    $out = applyCafeBulkMapping($db, $rows);

    // cron.log INFO (작업 흐름 추적용)
    $logLine = '[' . date('Y-m-d H:i:s') . '] cafe_bulk_apply: '
             . 'applied=' . $out['summary']['applied']
             . ' skipped=' . $out['summary']['skipped']
             . ' failed=' . $out['summary']['failed']
             . ' by=admin#' . ($admin['admin_id'] ?? $admin['id'] ?? '?') . "\n";
    $logFile = dirname(__DIR__, 2) . '/logs/cron.log';
    if (is_writable(dirname($logFile))) {
        @file_put_contents($logFile, $logLine, FILE_APPEND);
    }

    jsonSuccess(['data' => $out]);
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

// ── Task Group CRUD (operation/head/subhead1/subhead2) ─────
// 묶음 키 = (cohort, title, role)

case 'task_group_get':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $input = getJsonInput();
    $cohort = trim($input['cohort'] ?? '');
    $title  = trim($input['title']  ?? '');
    $role   = trim($input['role']   ?? '');
    if (!$cohort || !$title || !$role) jsonError('cohort/title/role 필수.');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT title, content_markdown, requires_submission
          FROM tasks
         WHERE cohort = ? AND title = ? AND role = ?
         ORDER BY start_date ASC
         LIMIT 1
    ");
    $stmt->execute([$cohort, $title, $role]);
    $row = $stmt->fetch();
    if (!$row) jsonError('해당 묶음을 찾을 수 없습니다.', 404);
    jsonSuccess([
        'title' => $row['title'],
        'content_markdown' => $row['content_markdown'],
        'requires_submission' => (int)$row['requires_submission'],
    ]);
    break;

case 'all_tasks_grouped':
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $cohort = getEffectiveCohort($admin);
    $filterRole = $_GET['filter_role'] ?? '';
    $adminId = $admin['admin_id'];

    // 기존 all_tasks 의 필터 SQL 을 그대로 GROUP BY 로 변환
    $db = getDB();
    if ($filterRole === 'mine') {
        $stmt = $db->prepare("
            SELECT t.cohort, t.title, t.role,
                   COUNT(*)                                AS total_count,
                   SUM(t.completed)                        AS done_count,
                   MIN(t.start_date)                       AS min_start_date,
                   MAX(t.end_date)                         AS max_end_date,
                   MAX(t.requires_submission)              AS requires_submission,
                   COUNT(DISTINCT CASE
                           WHEN t.assignee_admin_id IS NOT NULL OR t.assignee_member_id IS NOT NULL
                           THEN CONCAT_WS(':', COALESCE(t.assignee_admin_id, '_'), COALESCE(t.assignee_member_id, '_'))
                         END) AS assignee_count
              FROM tasks t
             WHERE t.cohort = ?
               AND (t.assignee_admin_id = ? OR t.assignee_member_id = ?)
             GROUP BY t.cohort, t.title, t.role
             ORDER BY t.role, MIN(t.start_date) DESC, t.title
        ");
        $stmt->execute([$cohort, $adminId, $adminId]);
    } elseif ($filterRole && $filterRole !== 'all') {
        $stmt = $db->prepare("
            SELECT t.cohort, t.title, t.role,
                   COUNT(*)                                AS total_count,
                   SUM(t.completed)                        AS done_count,
                   MIN(t.start_date)                       AS min_start_date,
                   MAX(t.end_date)                         AS max_end_date,
                   MAX(t.requires_submission)              AS requires_submission,
                   COUNT(DISTINCT CASE
                           WHEN t.assignee_admin_id IS NOT NULL OR t.assignee_member_id IS NOT NULL
                           THEN CONCAT_WS(':', COALESCE(t.assignee_admin_id, '_'), COALESCE(t.assignee_member_id, '_'))
                         END) AS assignee_count
              FROM tasks t
             WHERE t.cohort = ? AND t.role = ?
             GROUP BY t.cohort, t.title, t.role
             ORDER BY MIN(t.start_date) DESC, t.title
        ");
        $stmt->execute([$cohort, $filterRole]);
    } else {
        $stmt = $db->prepare("
            SELECT t.cohort, t.title, t.role,
                   COUNT(*)                                AS total_count,
                   SUM(t.completed)                        AS done_count,
                   MIN(t.start_date)                       AS min_start_date,
                   MAX(t.end_date)                         AS max_end_date,
                   MAX(t.requires_submission)              AS requires_submission,
                   COUNT(DISTINCT CASE
                           WHEN t.assignee_admin_id IS NOT NULL OR t.assignee_member_id IS NOT NULL
                           THEN CONCAT_WS(':', COALESCE(t.assignee_admin_id, '_'), COALESCE(t.assignee_member_id, '_'))
                         END) AS assignee_count
              FROM tasks t
             WHERE t.cohort = ?
             GROUP BY t.cohort, t.title, t.role
             ORDER BY t.role, MIN(t.start_date) DESC, t.title
        ");
        $stmt->execute([$cohort]);
    }
    jsonSuccess(['groups' => $stmt->fetchAll()]);
    break;

case 'task_group_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $input = getJsonInput();
    $cohort   = trim($input['cohort'] ?? '');
    $title    = trim($input['title']  ?? '');
    $role     = trim($input['role']   ?? '');
    $newTitle = trim($input['new_title'] ?? '');
    $newContent = trim($input['new_content_markdown'] ?? '');
    $newRequiresSubmission = !empty($input['requires_submission']) ? 1 : 0;
    if (!$cohort || !$title || !$role) jsonError('cohort/title/role 필수.');
    if ($newTitle === '') jsonError('새 제목을 입력해주세요.');

    $db = getDB();
    $stmt = $db->prepare("
        UPDATE tasks
           SET title = ?, content_markdown = ?, requires_submission = ?
         WHERE cohort = ? AND title = ? AND role = ?
    ");
    $stmt->execute([$newTitle, $newContent ?: null, $newRequiresSubmission, $cohort, $title, $role]);
    jsonSuccess(['affected_count' => $stmt->rowCount()], 'Task 묶음이 수정되었습니다.');
    break;

case 'task_group_delete':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $input = getJsonInput();
    $cohort = trim($input['cohort'] ?? '');
    $title  = trim($input['title']  ?? '');
    $role   = trim($input['role']   ?? '');
    if (!$cohort || !$title || !$role) jsonError('cohort/title/role 필수.');

    $db = getDB();
    $del = $db->prepare("
        DELETE FROM tasks
         WHERE cohort = ? AND title = ? AND role = ? AND completed = 0
    ");
    $del->execute([$cohort, $title, $role]);
    $deleted = $del->rowCount();

    $cnt = $db->prepare("
        SELECT COUNT(*) AS c
          FROM tasks
         WHERE cohort = ? AND title = ? AND role = ?
    ");
    $cnt->execute([$cohort, $title, $role]);
    $kept = (int)$cnt->fetch()['c'];

    jsonSuccess([
        'deleted_count' => $deleted,
        'kept_count'    => $kept,
    ], "{$deleted}개 삭제 / {$kept}개 보존");
    break;

case 'task_group_rows':
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $cohort = trim($_GET['cohort'] ?? '');
    $title  = trim($_GET['title']  ?? '');
    $role   = trim($_GET['role']   ?? '');
    if (!$cohort || !$title || !$role) jsonError('cohort/title/role 필수.');

    $onlyIncomplete = ($_GET['only_incomplete']  ?? '1') === '1';
    $onlyUntilToday = ($_GET['only_until_today'] ?? '1') === '1';

    $where  = "WHERE t.cohort = ? AND t.title = ? AND t.role = ?";
    $params = [$cohort, $title, $role];
    if ($onlyIncomplete) $where .= " AND t.completed = 0";
    if ($onlyUntilToday) $where .= " AND t.start_date <= CURDATE()";

    $db = getDB();
    $sql = "
        SELECT t.id,
               t.start_date,
               t.end_date,
               t.completed,
               t.requires_submission,
               t.submission_text,
               t.submitted_at,
               COALESCE(a.name, bm.real_name) AS assignee_name,
               CASE
                 WHEN t.assignee_admin_id  IS NOT NULL THEN 'admin'
                 WHEN t.assignee_member_id IS NOT NULL THEN 'member'
                 ELSE 'unassigned'
               END AS assignee_kind
          FROM tasks t
          LEFT JOIN admins a            ON t.assignee_admin_id  = a.id
          LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
          $where
         ORDER BY t.start_date ASC, assignee_name ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $today = $db->query("SELECT CURDATE() AS d")->fetch()['d'];

    jsonSuccess([
        'rows'         => $stmt->fetchAll(),
        'cutoff_today' => $today,
        'filters'      => [
            'only_incomplete'  => $onlyIncomplete,
            'only_until_today' => $onlyUntilToday,
        ],
    ]);
    break;

case 'task_submission_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin();
    $input = getJsonInput();
    $taskId = (int)($input['task_id'] ?? 0);
    $submissionText = isset($input['submission_text']) ? trim((string)$input['submission_text']) : '';

    if (!$taskId) jsonError('task_id가 필요합니다.', 400);
    if ($submissionText === '') jsonError('결과물을 입력해주세요.', 400);

    $db = getDB();
    $check = $db->prepare('SELECT requires_submission FROM tasks WHERE id = ?');
    $check->execute([$taskId]);
    $row = $check->fetch();
    if (!$row) jsonError('해당 task 를 찾을 수 없습니다.', 404);
    if ((int)$row['requires_submission'] !== 1) {
        jsonError('이 task 는 결과물 제출 대상이 아닙니다.', 400);
    }

    $stmt = $db->prepare('
        UPDATE tasks
           SET submission_text = ?, submitted_at = NOW(), updated_at = NOW()
         WHERE id = ?
    ');
    $stmt->execute([$submissionText, $taskId]);
    jsonSuccess([], '결과물이 수정되었습니다.');
    break;

// ── Task CRUD (operation/head/subhead1/subhead2 for create/update/delete) ─────

case 'task_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
    $input = getJsonInput();
    $title    = trim($input['title'] ?? '');
    $roles    = $input['roles'] ?? [];
    $content  = trim($input['content_markdown'] ?? '') ?: null;
    $cohort   = trim($input['cohort'] ?? '') ?: getEffectiveCohort($admin);
    $dateMode = $input['date_mode'] ?? 'direct';
    $requiresSubmission = !empty($input['requires_submission']) ? 1 : 0;

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

    $insertAdminStmt = $db->prepare('INSERT INTO tasks (title, role, assignee_admin_id, start_date, end_date, content_markdown, cohort, requires_submission) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $insertMemberStmt = $db->prepare('INSERT INTO tasks (title, role, assignee_member_id, start_date, end_date, content_markdown, cohort, requires_submission) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
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
                $insertAdminStmt->execute([$title, $role, null, $sd, $ed, $content, $cohort, $requiresSubmission]);
                $createdCount++;
            } else {
                $ins = $isMemberRole ? $insertMemberStmt : $insertAdminStmt;
                foreach ($assignees as $a) {
                    $ins->execute([$title, $role, $a['id'], $sd, $ed, $content, $cohort, $requiresSubmission]);
                    $createdCount++;
                }
            }
        }
    }

    jsonSuccess(['created_count' => $createdCount], "Task가 {$createdCount}개 생성되었습니다.");
    break;

case 'task_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
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
    $admin = requireAdmin(['operation','head','subhead1','subhead2']);
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

case 'retention_summary':
    handleRetentionSummary();
    break;

// ── Multipass (다회권) ──────────────────────────────────────

case 'multipass_list':
    requireAdmin(['operation']);
    $db = getDB();
    $filters = [];
    if (!empty($_GET['user_id']))      $filters['user_id']      = trim($_GET['user_id']);
    if (!empty($_GET['product_name'])) $filters['product_name'] = trim($_GET['product_name']);
    if (!empty($_GET['cohort_id']))    $filters['cohort_id']    = (int)$_GET['cohort_id'];
    jsonSuccess(['passes' => findPasses($db, $filters)]);
    break;

case 'multipass_get':
    requireAdmin(['operation']);
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonError('id 필수');
    $passes = findPasses(getDB(), ['pass_id' => $id]);
    if (!$passes) jsonError('찾을 수 없습니다.', 404);
    jsonSuccess(['pass' => $passes[0]]);
    break;

case 'multipass_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $userId      = trim($input['user_id'] ?? '');
    $productName = trim($input['product_name'] ?? '');
    $cohortIds   = $input['cohort_ids'] ?? [];
    $note        = $input['note'] ?? null;
    if ($userId === '')      jsonError('user_id 필수');
    if ($productName === '') jsonError('product_name 필수');
    if (!is_array($cohortIds) || !$cohortIds) jsonError('cohort_ids 필수');
    try {
        $passId = createPass(getDB(), $userId, $productName, $cohortIds, $note, (int)$admin['admin_id']);
        jsonSuccess(['id' => $passId], '다회권이 추가되었습니다.');
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) jsonError('이미 포함된 기수가 있습니다.');
        throw $e;
    }
    break;

case 'multipass_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonError('id 필수');
    $patch = [];
    foreach (['user_id', 'product_name', 'note'] as $k) {
        if (array_key_exists($k, $input)) $patch[$k] = $input[$k];
    }
    if (array_key_exists('cohort_ids', $input)) {
        if (!is_array($input['cohort_ids']) || !$input['cohort_ids']) jsonError('cohort_ids 비어있을 수 없습니다.');
        $patch['cohort_ids'] = $input['cohort_ids'];
    }
    try {
        $diff = updatePass(getDB(), $id, $patch, (int)$admin['admin_id']);
        jsonSuccess(['ok' => true, 'removed_cohort_ids' => $diff['removed_cohort_ids'], 'added_cohort_ids' => $diff['added_cohort_ids']], '수정되었습니다.');
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) jsonError('이미 포함된 기수가 있습니다.');
        throw $e;
    }
    break;

case 'multipass_delete':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonError('id 필수');
    deletePass(getDB(), $id);
    jsonSuccess(['ok' => true], '삭제되었습니다.');
    break;

case 'multipass_toggle_coupon':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $passId   = (int)($input['pass_id'] ?? 0);
    $cohortId = (int)($input['cohort_id'] ?? 0);
    $issued   = !empty($input['issued']);
    if (!$passId || !$cohortId) jsonError('pass_id, cohort_id 필수');
    $ret = toggleCoupon(getDB(), $passId, $cohortId, $issued, (int)$admin['admin_id']);
    jsonSuccess($ret);
    break;

case 'multipass_search_member':
    requireAdmin(['operation']);
    $q = trim($_GET['q'] ?? '');
    if ($q === '') jsonError('q 필수');
    jsonSuccess(['members' => searchMembers(getDB(), $q)]);
    break;

case 'multipass_bulk_validate':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $rows  = $input['rows'] ?? null;
    // CSV 텍스트가 들어오면 서버 측 파서 사용
    if ($rows === null && isset($input['csv'])) {
        $parsed = parseMultipassCsv((string)$input['csv']);
        $rows = $parsed['rows'];
        // 파싱 에러는 ERROR_PARSE 로 동봉
        foreach ($parsed['errors'] as $err) {
            $rows[] = ['row' => $err['row'], 'user_id' => '', 'product_name' => '', 'cohort_labels' => [], 'status' => 'ERROR_PARSE_' . strtoupper($err['reason'])];
        }
    }
    if (!is_array($rows)) jsonError('rows 또는 csv 필요');
    if (count($rows) > MULTIPASS_BULK_MAX_ROWS) jsonError('한 번에 ' . MULTIPASS_BULK_MAX_ROWS . '행 까지만 검증 가능합니다.');
    $result = validateMultipassBulk(getDB(), $rows);
    jsonSuccess($result);
    break;

case 'multipass_bulk_apply':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $rows  = $input['rows'] ?? null;
    if (!is_array($rows) || empty($rows)) jsonError('rows 필요');
    try {
        $result = applyMultipassBulk(getDB(), $rows, (int)$admin['admin_id']);
        jsonSuccess($result);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage());
    }
    break;

// ── Notices ────────────────────────────────────────────────

case 'notice_list':
    $admin = requireAdmin(['coach','sub_coach','head','subhead1','subhead2','operation']);
    if ($method !== 'GET') jsonError('GET만 허용됩니다.', 405);
    $cohortId = isset($_GET['cohort_id']) ? (int)$_GET['cohort_id'] : null;
    $cohortId = resolveAdminCohortId($cohortId, $admin);
    if (!$cohortId) jsonError('cohort_id 가 결정되지 않았습니다.');
    $rows = noticeListAdmin(getDB(), $cohortId);
    jsonSuccess(['notices' => $rows, 'cohort_id' => $cohortId]);
    break;

case 'notice_create':
    $admin = requireAdmin(['coach','sub_coach','head','subhead1','subhead2','operation']);
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $input = getJsonInput();
    $cohortId = isset($input['cohort_id']) ? (int)$input['cohort_id'] : null;
    $cohortId = resolveAdminCohortId($cohortId, $admin);
    if (!$cohortId) jsonError('cohort_id 가 결정되지 않았습니다.');
    $title  = (string)($input['title'] ?? '');
    $body   = (string)($input['body_markdown'] ?? '');
    $isVis  = isset($input['is_visible']) ? (int)$input['is_visible'] : 1;
    try {
        $id = noticeCreate(
            getDB(),
            $cohortId,
            (int)$admin['admin_id'],
            (string)$admin['admin_name'],
            $title, $body, $isVis
        );
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
    }
    jsonSuccess(['id' => $id]);
    break;

case 'notice_update':
    $admin = requireAdmin(['coach','sub_coach','head','subhead1','subhead2','operation']);
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $input = getJsonInput();
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if (!$id) jsonError('id가 필요합니다.');
    $cohortId = resolveAdminCohortId(null, $admin);
    if (!$cohortId) jsonError('cohort_id 가 결정되지 않았습니다.');
    $title = (string)($input['title'] ?? '');
    $body  = (string)($input['body_markdown'] ?? '');
    try {
        noticeUpdate(getDB(), $cohortId, $id, $title, $body);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
    }
    jsonSuccess(['ok' => true]);
    break;

case 'notice_toggle_visible':
    $admin = requireAdmin(['coach','sub_coach','head','subhead1','subhead2','operation']);
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $input = getJsonInput();
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if (!$id) jsonError('id가 필요합니다.');
    $isVis = isset($input['is_visible']) ? (int)$input['is_visible'] : -1;
    $cohortId = resolveAdminCohortId(null, $admin);
    if (!$cohortId) jsonError('cohort_id 가 결정되지 않았습니다.');
    try {
        $newV = noticeToggleVisible(getDB(), $cohortId, $id, $isVis);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
    }
    jsonSuccess(['ok' => true, 'is_visible' => $newV]);
    break;

case 'notice_delete':
    $admin = requireAdmin(['coach','sub_coach','head','subhead1','subhead2','operation']);
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $input = getJsonInput();
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if (!$id) jsonError('id가 필요합니다.');
    $cohortId = resolveAdminCohortId(null, $admin);
    if (!$cohortId) jsonError('cohort_id 가 결정되지 않았습니다.');
    try {
        noticeDelete(getDB(), $cohortId, $id);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 400);
    }
    jsonSuccess(['ok' => true]);
    break;

// ── Default ─────────────────────────────────────────────────

default:
    jsonError('Unknown action', 404);
}
