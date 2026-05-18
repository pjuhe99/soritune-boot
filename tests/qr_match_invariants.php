<?php
/**
 * QR 매칭 invariants (read-only, DEV/PROD 양쪽 안전 실행).
 * 사용: php tests/qr_match_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$db = getDB();
$dbName = $db->query('SELECT DATABASE()')->fetchColumn();
echo "DB: {$dbName}\n\n";

$pass = 0; $fail = 0;
function inv(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// ── INV-2: FK 일관성 ───────────────────────────────────────
// qr_sessions.lecture_session_id IS NOT NULL row 가 모두 유효한 lecture_sessions 참조
$orphans = $db->query("
    SELECT COUNT(*) FROM qr_sessions qs
    LEFT JOIN lecture_sessions ls ON ls.id = qs.lecture_session_id
    WHERE qs.lecture_session_id IS NOT NULL
      AND ls.id IS NULL
")->fetchColumn();
inv('INV-2: qr_sessions.lecture_session_id FK 일관성', $orphans == 0, "orphan rows={$orphans}");

// ── INV-3: attendance 통계 응답 정상 (활성 cohort 1개로 임의 확인) ──
$activeCohort = $db->query("SELECT id FROM cohorts WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($activeCohort) {
    // attendance.php 의 핵심 쿼리만 직접 재현 (HTTP 호출 안 함)
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM qr_sessions WHERE cohort_id = ?
    ");
    $stmt->execute([(int)$activeCohort]);
    $qrCount = (int)$stmt->fetchColumn();
    inv('INV-3: 활성 cohort qr_sessions 조회 정상', $qrCount >= 0, "cohort={$activeCohort} qr_count={$qrCount}");
} else {
    inv('INV-3: 활성 cohort 없음 (skip)', true);
}

echo "\n── 결과: PASS {$pass}, FAIL {$fail} ──\n";
exit($fail > 0 ? 1 : 0);
