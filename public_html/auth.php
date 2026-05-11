<?php
/**
 * boot.soritune.com - Session Management & RBAC
 * 2-tier: member (30 days) / admin (24 hours)
 * V2: multi-role support via admin_roles table
 */

require_once __DIR__ . '/config.php';

// ── Session Configuration ──────────────────────────────────

define('SESSION_SAVE_BASE', '/var/lib/php/sessions/boot');

define('SESSION_CONFIGS', [
    'member' => [
        'cookie_name' => 'BOOT_MEMBER_SID',
        'lifetime'    => 86400 * 30,  // 30 days
        'samesite'    => 'Lax',
        'save_path'   => SESSION_SAVE_BASE . '/member',
    ],
    'admin' => [
        'cookie_name' => 'BOOT_ADMIN_SID',
        'lifetime'    => 86400,       // 24 hours
        'samesite'    => 'Lax',
        'save_path'   => SESSION_SAVE_BASE . '/admin',
    ],
]);

// ── Session Helpers ────────────────────────────────────────

function startSessionFor(string $tier): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() === SESSION_CONFIGS[$tier]['cookie_name']) return;
        session_write_close();
    }
    $cfg = SESSION_CONFIGS[$tier];
    if (!is_dir($cfg['save_path'])) {
        mkdir($cfg['save_path'], 0700, true);
    }
    session_save_path($cfg['save_path']);
    session_name($cfg['cookie_name']);
    // tier 전환 시 직전 세션의 session_id() 가 남아있으면 PHP 가 쿠키 값보다 그 SID 를 우선해서
    // 새 tier 의 SID 로 재사용 → 쿠키가 잘못된 SID 로 덮어써짐. 매번 정확한 SID 로 강제 세팅.
    // 우선순위: 이번 요청에서 이미 emit 된 Set-Cookie (login_phone 처럼 admin→member→admin 시
    // step 1 의 새 admin SID 를 step 3 에서 재사용해야 함) > $_COOKIE > '' (PHP 가 새로 생성).
    $sid = '';
    foreach (headers_list() as $h) {
        if (preg_match('/^Set-Cookie:\s*' . preg_quote($cfg['cookie_name'], '/') . '=([^;]+)/i', $h, $m)) {
            $sid = $m[1];
        }
    }
    if ($sid === '') $sid = $_COOKIE[$cfg['cookie_name']] ?? '';
    session_id($sid);
    ini_set('session.gc_maxlifetime', $cfg['lifetime']);
    session_set_cookie_params([
        'lifetime' => $cfg['lifetime'],
        'path'     => '/',
        'secure'   => true,
        'httponly'  => true,
        'samesite' => $cfg['samesite'],
    ]);
    session_start();
}

function destroySession(string $tier): void {
    startSessionFor($tier);
    $_SESSION = [];
    $cfg = SESSION_CONFIGS[$tier];
    if (ini_get('session.use_cookies')) {
        setcookie($cfg['cookie_name'], '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => true,
            'httponly'  => true,
            'samesite' => $cfg['samesite'],
        ]);
    }
    session_destroy();
}

// ── Member Session ─────────────────────────────────────────

function loginMember(int $id, string $name, string $cohort, ?string $nickname = null, array $accessibleCohorts = []): void {
    startSessionFor('member');
    session_regenerate_id(true);
    $_SESSION['member_id']   = $id;
    $_SESSION['member_name'] = $name;
    $_SESSION['cohort']      = $cohort;
    $_SESSION['nickname']    = $nickname;
    $_SESSION['accessible_cohorts'] = $accessibleCohorts;
    session_write_close();
}

function getMemberSession(): ?array {
    startSessionFor('member');
    if (empty($_SESSION['member_id'])) {
        session_write_close();
        return null;
    }

    // 구버전 세션 inline 마이그레이션: accessible_cohorts 가 없으면 현재 cohort 1개로 채움
    if (!isset($_SESSION['accessible_cohorts']) || !is_array($_SESSION['accessible_cohorts'])) {
        $_SESSION['accessible_cohorts'] = [[
            'member_id'    => (int)$_SESSION['member_id'],
            'cohort_id'    => null, // 미상 — switch 시도 시 fallback 처리
            'cohort_label' => $_SESSION['cohort'] ?? '',
        ]];
    }

    $data = [
        'member_id'          => $_SESSION['member_id'],
        'member_name'        => $_SESSION['member_name'],
        'cohort'             => $_SESSION['cohort'],
        'nickname'           => $_SESSION['nickname'] ?? null,
        'accessible_cohorts' => $_SESSION['accessible_cohorts'],
    ];
    session_write_close();
    return $data;
}

function requireMember(): array {
    $s = getMemberSession();
    if (!$s) jsonError('로그인이 필요합니다.', 401);
    return $s;
}

/**
 * 세션의 member_id 를 같은 사람의 다른 cohort row 로 swap.
 * accessible_cohorts 안에 cohort_id 가 있어야 함.
 *
 * @return bool 성공 여부
 */
function swapMemberCohort(int $cohortId): bool {
    startSessionFor('member');
    if (empty($_SESSION['member_id'])) { session_write_close(); return false; }

    $accessible = $_SESSION['accessible_cohorts'] ?? [];
    $target = null;
    foreach ($accessible as $row) {
        if ((int)($row['cohort_id'] ?? 0) === $cohortId) { $target = $row; break; }
    }
    if (!$target) { session_write_close(); return false; }

    // 보안: row 의 member_id 가 실제 그 cohort 에 속하는지 재확인
    $db = getDB();
    $stmt = $db->prepare("
        SELECT bm.id, bm.real_name, c.cohort, bm.nickname
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.id = ? AND bm.cohort_id = ?
          AND (bm.is_active = 1 OR bm.member_status = 'leaving')
          AND c.is_active = 1
    ");
    $stmt->execute([(int)$target['member_id'], $cohortId]);
    $row = $stmt->fetch();
    if (!$row) { session_write_close(); return false; }

    session_regenerate_id(true);
    $_SESSION['member_id']   = (int)$row['id'];
    $_SESSION['member_name'] = $row['real_name'];
    $_SESSION['cohort']      = $row['cohort'];
    $_SESSION['nickname']    = $row['nickname'];
    // accessible_cohorts 는 그대로 유지
    session_write_close();
    return true;
}

function logoutMember(): void {
    destroySession('member');
}

// ── Admin Session ──────────────────────────────────────────

function loginAdmin(int $id, string $name, array $roles, ?string $cohort, ?int $bootcampGroupId = null): void {
    startSessionFor('admin');
    session_regenerate_id(true);
    $_SESSION['admin_id']    = $id;
    $_SESSION['admin_name']  = $name;
    $_SESSION['admin_roles'] = $roles;
    $_SESSION['cohort']      = $cohort;
    $_SESSION['bootcamp_group_id'] = $bootcampGroupId;

    // admin_view_cohort_id default = settings.current_cohort 매핑.
    // 매핑 cohort 가 inactive 면 가장 최근 active cohort.
    $defaultCohortId = null;
    $currentLabel = getSetting('current_cohort');
    if ($currentLabel) $defaultCohortId = getCohortIdByLabel($currentLabel);
    if (!$defaultCohortId) {
        $row = getDB()->query("SELECT id FROM cohorts WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1")->fetch();
        if ($row) $defaultCohortId = (int)$row['id'];
    }
    $_SESSION['admin_view_cohort_id'] = $defaultCohortId;

    session_write_close();
}

function getAdminSession(): ?array {
    startSessionFor('admin');
    if (empty($_SESSION['admin_id'])) {
        session_write_close();
        return null;
    }

    // 구버전 세션 마이그레이션
    if (!array_key_exists('admin_view_cohort_id', $_SESSION)) {
        $defaultCohortId = null;
        $currentLabel = getSetting('current_cohort');
        if ($currentLabel) $defaultCohortId = getCohortIdByLabel($currentLabel);
        $_SESSION['admin_view_cohort_id'] = $defaultCohortId;
    }

    $data = [
        'admin_id'             => $_SESSION['admin_id'],
        'admin_name'           => $_SESSION['admin_name'],
        'admin_roles'          => $_SESSION['admin_roles'] ?? [],
        'cohort'               => $_SESSION['cohort'],
        'bootcamp_group_id'    => $_SESSION['bootcamp_group_id'] ?? null,
        'admin_view_cohort_id' => $_SESSION['admin_view_cohort_id'] ?? null,
    ];
    session_write_close();
    return $data;
}

/**
 * leader/subleader 회원이 회원 세션은 살아있는데 admin 세션이 만료된 경우,
 * 회원 세션 정보를 바탕으로 admin 세션을 자동 재발급한다.
 *
 * 다른 admin role (operation/head/subhead/coach 등) 은 회원 세션이 없으므로
 * 영향 없음. 보안 수준은 회원 로그인 시 자동 loginAdmin 흐름 (member.php:login)
 * 과 동일.
 *
 * @return array|null 자동 재발급 성공 시 admin session 데이터, 아니면 null
 */
function maybeAutoLoginAdminFromMember(): ?array {
    $m = getMemberSession();
    if (!$m) return null;

    // 회원의 현재 role/cohort/group 재조회 (세션의 옛 값에 의존하지 않음)
    $db = getDB();
    $stmt = $db->prepare("
        SELECT bm.id, bm.real_name, bm.nickname, bm.member_role, bm.group_id, c.cohort
        FROM bootcamp_members bm
        JOIN cohorts c ON c.id = bm.cohort_id
        WHERE bm.id = ?
          AND (bm.is_active = 1 OR bm.member_status = 'leaving')
          AND c.is_active = 1
    ");
    $stmt->execute([(int)$m['member_id']]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $role = $row['member_role'] ?? '';
    if (!in_array($role, ['leader', 'subleader'], true)) return null;

    $displayName = $row['nickname'] ?: $row['real_name'];
    $bcGroupId = $row['group_id'] ? (int)$row['group_id'] : null;
    loginAdmin((int)$row['id'], $displayName, [$role], $row['cohort'], $bcGroupId);

    return getAdminSession();
}

function requireAdmin(array $allowedRoles = []): array {
    $s = getAdminSession() ?? maybeAutoLoginAdminFromMember();
    if (!$s) jsonError('로그인이 필요합니다.', 401);
    if ($allowedRoles && !hasAnyRole($s, $allowedRoles)) {
        $needRoles = implode(', ', $allowedRoles);
        $userRoles = implode(', ', $s['admin_roles'] ?? []);
        jsonError("권한이 없습니다. 필요 역할: [{$needRoles}], 현재 역할: [{$userRoles}]", 403);
    }
    return $s;
}

function logoutAdmin(): void {
    destroySession('admin');
}

// ── Role Helpers ───────────────────────────────────────────

function hasRole(array $session, string $role): bool {
    return in_array($role, $session['admin_roles'] ?? [], true);
}

function hasAnyRole(array $session, array $roles): bool {
    return !empty(array_intersect($session['admin_roles'] ?? [], $roles));
}

// ── Cohort Resolution ──────────────────────────────────────

function getEffectiveCohort(array $session): ?string {
    // admin_view_cohort_id 가 있으면 그 라벨 우선
    $viewId = $session['admin_view_cohort_id'] ?? null;
    if ($viewId !== null) {
        $label = getCohortLabelById($viewId);
        if ($label) return $label;
    }
    if (hasRole($session, 'operation')) {
        return getSetting('current_cohort');
    }
    return $session['cohort'];
}

// ── Phone Normalization ───────────────────────────────────

/**
 * 전화번호 정규화: 숫자만 남김
 */
function normalizePhone(string $phone): string {
    return preg_replace('/[^0-9]/', '', $phone);
}

/**
 * 전화번호로 활성 회원 조회
 */
function findMemberByPhone(PDO $db, string $phone): ?array {
    $normalized = normalizePhone($phone);
    if (!$normalized) return null;

    $stmt = $db->prepare("
        SELECT bm.*, c.cohort,
               COALESCE(NULLIF(bm.kakao_link, ''), bg.kakao_link) AS kakao_link,
               bg.name AS group_name
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        WHERE REPLACE(REPLACE(bm.phone, '-', ''), ' ', '') = ?
          AND (bm.is_active = 1 OR bm.member_status = 'leaving')
          AND c.is_active = 1
        ORDER BY bm.cohort_id DESC
        LIMIT 1
    ");
    $stmt->execute([$normalized]);
    return $stmt->fetch() ?: null;
}

/**
 * phone 으로 active cohort 의 모든 member row 반환 (cohort_id DESC).
 * findMemberByPhone 의 multi-row 버전.
 */
function findMemberAccessibleRows(PDO $db, string $phone): array {
    $normalized = normalizePhone($phone);
    if (!$normalized) return [];

    $stmt = $db->prepare("
        SELECT bm.*, c.cohort,
               COALESCE(NULLIF(bm.kakao_link, ''), bg.kakao_link) AS kakao_link,
               bg.name AS group_name
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        WHERE REPLACE(REPLACE(bm.phone, '-', ''), ' ', '') = ?
          AND (bm.is_active = 1 OR bm.member_status = 'leaving')
          AND c.is_active = 1
        ORDER BY bm.cohort_id DESC
    ");
    $stmt->execute([$normalized]);
    return $stmt->fetchAll();
}

// ── Nickname Helpers ──────────────────────────────────────

/**
 * 닉네임이 설정되어 있는지 확인
 */
function hasNickname(?string $nickname): bool {
    return $nickname !== null && trim($nickname) !== '';
}

/**
 * 세션의 닉네임 갱신
 */
function updateMemberNickname(string $nickname): void {
    startSessionFor('member');
    $_SESSION['nickname'] = $nickname;
    session_write_close();
}

// ── Cohort Resolution Helpers ──────────────────────────────

/**
 * cohort.id → cohort 라벨 ('12기')
 */
function getCohortLabelById(int $id): ?string {
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    $stmt = getDB()->prepare("SELECT cohort FROM cohorts WHERE id = ?");
    $stmt->execute([$id]);
    $label = $stmt->fetchColumn();
    return $cache[$id] = ($label !== false ? $label : null);
}

/**
 * cohort 라벨 ('12기') → cohort.id
 */
function getCohortIdByLabel(string $label): ?int {
    static $cache = [];
    if (isset($cache[$label])) return $cache[$label];
    $stmt = getDB()->prepare("SELECT id FROM cohorts WHERE cohort = ?");
    $stmt->execute([$label]);
    $id = $stmt->fetchColumn();
    return $cache[$label] = ($id !== false ? (int)$id : null);
}

/**
 * 어드민 view cohort 결정.
 * 명시 cohort_id → admin_view_cohort_id → (supportsAll ? null : settings.current_cohort)
 *
 * @param int|null $explicit  request 에서 전달된 cohort_id (0 = 미지정)
 * @param array    $session   admin session
 * @param bool     $supportsAll  true 면 view 가 null('전체') 일 때 null 반환, 아니면 settings fallback
 */
function resolveAdminCohortId(?int $explicit, array $session, bool $supportsAll = false): ?int {
    if ($explicit !== null && $explicit > 0) return $explicit;
    $view = $session['admin_view_cohort_id'] ?? null;
    if ($view !== null) return $view;
    if ($supportsAll) return null;
    $label = getSetting('current_cohort');
    return $label ? getCohortIdByLabel($label) : null;
}
