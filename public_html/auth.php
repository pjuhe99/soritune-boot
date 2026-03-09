<?php
/**
 * boot.soritune.com - Session Management & RBAC
 * 2-tier: member (30 days) / admin (24 hours)
 * V2: multi-role support via admin_roles table
 */

require_once __DIR__ . '/config.php';

// ── Session Configuration ──────────────────────────────────

define('SESSION_CONFIGS', [
    'member' => [
        'cookie_name' => 'BOOT_MEMBER_SID',
        'lifetime'    => 86400 * 30,  // 30 days
        'samesite'    => 'Lax',
    ],
    'admin' => [
        'cookie_name' => 'BOOT_ADMIN_SID',
        'lifetime'    => 86400,       // 24 hours
        'samesite'    => 'Lax',
    ],
]);

// ── Session Helpers ────────────────────────────────────────

function startSessionFor(string $tier): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() === SESSION_CONFIGS[$tier]['cookie_name']) return;
        session_write_close();
    }
    $cfg = SESSION_CONFIGS[$tier];
    session_name($cfg['cookie_name']);
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

function loginMember(int $id, string $name, string $cohort): void {
    startSessionFor('member');
    session_regenerate_id(true);
    $_SESSION['member_id']   = $id;
    $_SESSION['member_name'] = $name;
    $_SESSION['cohort']      = $cohort;
}

function getMemberSession(): ?array {
    startSessionFor('member');
    if (empty($_SESSION['member_id'])) return null;
    return [
        'member_id'   => $_SESSION['member_id'],
        'member_name' => $_SESSION['member_name'],
        'cohort'      => $_SESSION['cohort'],
    ];
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
}

function getAdminSession(): ?array {
    startSessionFor('admin');
    if (empty($_SESSION['admin_id'])) return null;
    return [
        'admin_id'    => $_SESSION['admin_id'],
        'admin_name'  => $_SESSION['admin_name'],
        'admin_roles' => $_SESSION['admin_roles'] ?? [],
        'cohort'      => $_SESSION['cohort'],
        'bootcamp_group_id' => $_SESSION['bootcamp_group_id'] ?? null,
    ];
}

function requireAdmin(array $allowedRoles = []): array {
    $s = getAdminSession();
    if (!$s) jsonError('로그인이 필요합니다.', 401);
    if ($allowedRoles && !hasAnyRole($s, $allowedRoles)) {
        jsonError('권한이 없습니다.', 403);
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
