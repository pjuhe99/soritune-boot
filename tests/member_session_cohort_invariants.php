<?php
/**
 * 회원 세션 cohort 검증 인보리언트.
 *
 * 비활성 cohort 의 옛 회원 세션이 살아있어 잘못된 기수 화면이 노출되던 문제(한미경/01099815874
 * 케이스)를 차단하는 getMemberSession 의 cohort 활성 검증을 회귀로 잡는다.
 *
 * 사용: php tests/member_session_cohort_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/auth.php';

// CLI 에서 setcookie/session_start 가 헤더 출력하더라도 stdout 흐름 깨지지 않게 버퍼링
ob_start();

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; ob_end_flush(); echo "PASS  {$name}\n"; ob_start(); }
    else { $fail++; ob_end_flush(); echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n"; ob_start(); }
}

$db = getDB();

// ── 픽스처 자동 탐색 (DEV/PROD 어느 쪽에서 돌려도 동작) ──

// 활성 cohort 의 활성 회원 1명
$activeMember = $db->query("
    SELECT bm.id
    FROM bootcamp_members bm
    JOIN cohorts c ON c.id = bm.cohort_id
    WHERE c.is_active = 1
      AND bm.is_active = 1
      AND bm.member_status = 'active'
    LIMIT 1
")->fetch();

// 비활성 cohort 의 bm.is_active=1 회원 1명 (옛 세션이 살아있는 시나리오)
$inactiveCohortMember = $db->query("
    SELECT bm.id, c.cohort
    FROM bootcamp_members bm
    JOIN cohorts c ON c.id = bm.cohort_id
    WHERE c.is_active = 0
      AND bm.is_active = 1
    LIMIT 1
")->fetch();

if (!$activeMember || !$inactiveCohortMember) {
    echo "SKIP  fixture missing (active=" . ($activeMember ? 'Y' : 'N') . " inactive=" . ($inactiveCohortMember ? 'Y' : 'N') . ")\n";
    exit(2);
}

$ACTIVE_ID = (int)$activeMember['id'];
$INACTIVE_ID = (int)$inactiveCohortMember['id'];

// ── 검증 1: 핵심 SQL — 활성/비활성 cohort 판별 ──

$sql = "
    SELECT 1
    FROM bootcamp_members bm
    JOIN cohorts c ON c.id = bm.cohort_id
    WHERE bm.id = ?
      AND c.is_active = 1
      AND (bm.is_active = 1 OR bm.member_status = 'leaving')
    LIMIT 1
";

$stmt = $db->prepare($sql);
$stmt->execute([$ACTIVE_ID]);
t('SQL: 활성 cohort active 회원 → 매치', (bool)$stmt->fetchColumn());

$stmt = $db->prepare($sql);
$stmt->execute([$INACTIVE_ID]);
t('SQL: 비활성 cohort 회원 → 미매치', !$stmt->fetchColumn());

$stmt = $db->prepare($sql);
$stmt->execute([99999999]);
t('SQL: 존재 안 하는 member_id → 미매치', !$stmt->fetchColumn());

// 활성 cohort 의 leaving 회원도 통과해야 함 (멤버 본인이 환불·탈락 정산 받을 때까지 세션 유지)
$leaving = $db->query("
    SELECT bm.id
    FROM bootcamp_members bm
    JOIN cohorts c ON c.id = bm.cohort_id
    WHERE c.is_active = 1 AND bm.member_status = 'leaving' AND bm.is_active = 0
    LIMIT 1
")->fetch();
if ($leaving) {
    $stmt = $db->prepare($sql);
    $stmt->execute([(int)$leaving['id']]);
    t('SQL: 활성 cohort leaving (bm.is_active=0) → 매치', (bool)$stmt->fetchColumn());
} else {
    echo "SKIP  활성 cohort leaving 회원 픽스처 없음\n";
}

// 활성 cohort 의 refunded / out_of_group_management 회원은 통과 X (bm.is_active=1 이라도)
$refunded = $db->query("
    SELECT bm.id
    FROM bootcamp_members bm
    JOIN cohorts c ON c.id = bm.cohort_id
    WHERE c.is_active = 1
      AND bm.member_status IN ('refunded','out_of_group_management')
      AND bm.is_active = 1
    LIMIT 1
")->fetch();
if ($refunded) {
    $stmt = $db->prepare($sql);
    $stmt->execute([(int)$refunded['id']]);
    // bm.is_active=1 이라 통과. 의도적으로 통과. 다만 status='leaving' 만 별도 통과 룰을 보장.
    t('SQL: 활성 cohort refunded but is_active=1 → 매치 (현재 정책)', (bool)$stmt->fetchColumn());
} else {
    echo "SKIP  refunded/out_of_group_management active 픽스처 없음\n";
}

// ── 검증 2: getMemberSession 통합 동작 ──
// CLI 에서 startSessionFor 가 사용하는 save_path 가 권한 거부면 skip.

$cfg = SESSION_CONFIGS['member'];
if (!is_dir($cfg['save_path']) && !@mkdir($cfg['save_path'], 0700, true)) {
    echo "SKIP  getMemberSession integration (cannot create session dir {$cfg['save_path']})\n";
} else if (!is_writable($cfg['save_path'])) {
    echo "SKIP  getMemberSession integration (session dir not writable {$cfg['save_path']})\n";
} else {
    // 케이스 A: 세션이 비어있으면 null
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $_COOKIE = []; // 쿠키 SID 흔적 제거
    $_SESSION = [];
    $resA = getMemberSession();
    t('getMemberSession: 세션 없음 → null', $resA === null);

    // 케이스 B: 비활성 cohort 회원 세션 → null + destroySession
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $_COOKIE = [];
    // 새 세션에 비활성 cohort 회원 ID 박기
    session_save_path($cfg['save_path']);
    session_name($cfg['cookie_name']);
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $_SESSION['member_id']   = $INACTIVE_ID;
    $_SESSION['member_name'] = 'fixture';
    $_SESSION['cohort']      = '11기';
    $_SESSION['accessible_cohorts'] = [];
    session_write_close();
    // 쿠키도 흉내 (startSessionFor 가 $_COOKIE 에서 SID 가져옴)
    $sid = session_id();
    if ($sid) $_COOKIE[$cfg['cookie_name']] = $sid;

    $resB = getMemberSession();
    t('getMemberSession: 비활성 cohort 회원 → null', $resB === null);

    // 케이스 C: 활성 cohort 회원 세션 → 정상 데이터 반환
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $_COOKIE = [];
    session_save_path($cfg['save_path']);
    session_name($cfg['cookie_name']);
    if (session_status() === PHP_SESSION_NONE) @session_start();
    $_SESSION['member_id']   = $ACTIVE_ID;
    $_SESSION['member_name'] = 'fixture';
    $_SESSION['cohort']      = '12기';
    $_SESSION['accessible_cohorts'] = [];
    session_write_close();
    $sid = session_id();
    if ($sid) $_COOKIE[$cfg['cookie_name']] = $sid;

    $resC = getMemberSession();
    t('getMemberSession: 활성 cohort 회원 → 데이터 반환', is_array($resC) && (int)$resC['member_id'] === $ACTIVE_ID);
}

ob_end_flush();
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
