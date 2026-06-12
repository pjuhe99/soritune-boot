<?php
/**
 * 감점 구간 경계 — 단위 테스트 (CLI).
 *
 * 사용: php tests/score_last_day_test.php
 *
 * 규칙: 적응기간(첫 SCORE_ADAPTATION_DAYS일)과 기수 종료일 당일은 감점 제외.
 * 감점 구간 = (start_date + 적응일수) ~ min(end_date - 1일, 어제).
 *
 * fixture: 체크 row 없는 회원 → 구간 내 매일 zoom/daily -1, inner33 -1, 월요일 speak -2.
 * cohort 2026-05-11(월) ~ 2026-05-25(월) 고정 — 오늘이 2026-05-26 이후면 항상 유효.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/bootcamp_functions.php';

$db = getDB();
$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

/** 감점 구간 기대 점수 수계산 (체크 전무 가정): 매일 -2, 월요일 추가 -2 */
function expectedScore(string $scoringStart, string $scoringEnd): int {
    $sum = 0;
    for ($d = $scoringStart; $d <= $scoringEnd; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $sum -= 2;
        if ((int)date('w', strtotime($d)) === 1) $sum -= 2;
    }
    return $sum;
}

function makeFixture(PDO $db, string $start, string $end): array {
    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date) VALUES ('__SLD기', ?, ?, ?)")
       ->execute(['__sld_' . bin2hex(random_bytes(3)), $start, $end]);
    $cohortId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, nickname, real_name, is_active) VALUES (?, '__sld닉', '__sld명', 1)")
       ->execute([$cohortId]);
    return [$cohortId, (int)$db->lastInsertId()];
}

function cleanFixture(PDO $db, int $cohortId, int $memberId): void {
    $db->prepare("DELETE FROM score_logs WHERE member_id = ?")->execute([$memberId]);
    $db->prepare("DELETE FROM member_scores WHERE member_id = ?")->execute([$memberId]);
    $db->prepare("DELETE FROM bootcamp_members WHERE id = ?")->execute([$memberId]);
    $db->prepare("DELETE FROM cohorts WHERE id = ?")->execute([$cohortId]);
}

$yesterday = date('Y-m-d', strtotime('-1 day'));

// ── 종료된 기수: 종료일 당일 감점 제외 ──
[$cid, $mid] = makeFixture($db, '2026-05-11', '2026-05-25');
try {
    $score = recalculateMemberScore($db, $mid);
    // 감점 구간 2026-05-14 ~ 2026-05-24 (종료일 05-25 제외)
    $want = expectedScore('2026-05-14', '2026-05-24');
    t('종료 기수: 종료일 제외', $score === $want, "got {$score}, want {$want}");
    t('종료 기수: 종료일 포함이면 불일치', $score !== expectedScore('2026-05-14', '2026-05-25'));
} finally {
    cleanFixture($db, $cid, $mid);
}

// ── 진행 중 기수 (종료일 미래): 어제까지 감점 ──
[$cid, $mid] = makeFixture($db, '2026-05-11', date('Y-m-d', strtotime('+10 days')));
try {
    $score = recalculateMemberScore($db, $mid);
    $want = expectedScore('2026-05-14', $yesterday);
    t('진행 기수: 어제까지', $score === $want, "got {$score}, want {$want}");
} finally {
    cleanFixture($db, $cid, $mid);
}

// ── 종료일이 오늘인 기수: 어제까지 감점 (오늘=종료일은 어차피 미래 아님이지만 제외 대상) ──
[$cid, $mid] = makeFixture($db, '2026-05-11', date('Y-m-d'));
try {
    $score = recalculateMemberScore($db, $mid);
    $want = expectedScore('2026-05-14', $yesterday);
    t('종료일=오늘: 어제까지', $score === $want, "got {$score}, want {$want}");
} finally {
    cleanFixture($db, $cid, $mid);
}

// ── 종료일이 어제인 기수: 종료일(어제) 제외 → 그제까지 ──
[$cid, $mid] = makeFixture($db, '2026-05-11', $yesterday);
try {
    $score = recalculateMemberScore($db, $mid);
    $want = expectedScore('2026-05-14', date('Y-m-d', strtotime('-2 days')));
    t('종료일=어제: 그제까지', $score === $want, "got {$score}, want {$want}");
} finally {
    cleanFixture($db, $cid, $mid);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
