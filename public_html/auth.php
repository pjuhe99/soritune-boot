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
    session_write_close();   // flush to disk + release lock before response
}

function getAdminSession(): ?array {
    startSessionFor('admin');
    if (empty($_SESSION['admin_id'])) {
        session_write_close();
        return null;
    }
    $data = [
        'admin_id'    => $_SESSION['admin_id'],
        'admin_name'  => $_SESSION['admin_name'],
        'admin_roles' => $_SESSION['admin_roles'] ?? [],
        'cohort'      => $_SESSION['cohort'],
        'bootcamp_group_id' => $_SESSION['bootcamp_group_id'] ?? null,
    ];
    session_write_close();   // release lock so parallel requests don't block
    return $data;
}

function requireAdmin(array $allowedRoles = []): array {
    $s = getAdminSession();
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
