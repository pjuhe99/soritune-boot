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

function loginMember(int $id, string $name, string $cohort, ?string $nickname = null): void {
    startSessionFor('member');
    session_regenerate_id(true);
    $_SESSION['member_id']   = $id;
    $_SESSION['member_name'] = $name;
    $_SESSION['cohort']      = $cohort;
    $_SESSION['nickname']    = $nickname;
    session_write_close();   // flush to disk + release lock before response
}

function getMemberSession(): ?array {
    startSessionFor('member');
    if (empty($_SESSION['member_id'])) {
        session_write_close();
        return null;
    }
    $data = [
        'member_id'   => $_SESSION['member_id'],
        'member_name' => $_SESSION['member_name'],
        'cohort'      => $_SESSION['cohort'],
        'nickname'    => $_SESSION['nickname'] ?? null,
    ];
    session_write_close();   // release lock so parallel requests don't block
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
        WHERE REPLACE(REPLACE(bm.phone, '-', ''), ' ', '') = ? AND bm.is_active = 1
        ORDER BY c.is_active DESC, bm.cohort_id DESC
        LIMIT 1
    ");
    $stmt->execute([$normalized]);
    return $stmt->fetch() ?: null;
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
