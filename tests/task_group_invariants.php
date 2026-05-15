<?php
/**
 * Task 그룹 일괄 수정/삭제 인보리언트.
 *
 * 사용:
 *   php tests/task_group_invariants.php
 *
 * (DEV DB 직접 조회 — 운영 cookie 불필요)
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
$db = getDB();

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// ── Setup: 같은 묶음 4 row + 다른 그룹 1 row ─────
$cohortRow = $db->query("SELECT cohort FROM cohorts ORDER BY start_date DESC LIMIT 1")->fetch();
if (!$cohortRow) { echo "SKIP — cohorts 비어있음\n"; exit(0); }
$cohort = $cohortRow['cohort'];

$db->prepare("DELETE FROM tasks WHERE title LIKE '__inv_grp%' OR title = '__inv_today'")->execute();

try {
    $ins = $db->prepare("
        INSERT INTO tasks (title, role, assignee_admin_id, completed, start_date, end_date, content_markdown, cohort)
        VALUES (?, 'operation', NULL, ?, ?, ?, 'c1', ?)
    ");
    $ins->execute(['__inv_grp_a', 0, '2099-02-01', '2099-02-01', $cohort]);
    $ins->execute(['__inv_grp_a', 0, '2099-02-02', '2099-02-02', $cohort]);
    $ins->execute(['__inv_grp_a', 1, '2099-02-03', '2099-02-03', $cohort]);
    $ins->execute(['__inv_grp_a', 1, '2099-02-04', '2099-02-04', $cohort]);
    $ins->execute(['__inv_grp_b', 0, '2099-02-01', '2099-02-01', $cohort]);

    // ── INV-1: group_update 시뮬레이트 — title 변경이 다른 그룹에 영향 없음 ──
    $db->prepare("
        UPDATE tasks SET title = '__inv_grp_a_v2', content_markdown = 'c2'
         WHERE cohort = ? AND title = '__inv_grp_a' AND role = 'operation'
    ")->execute([$cohort]);

    $other = $db->prepare("SELECT title, content_markdown FROM tasks WHERE cohort = ? AND title = '__inv_grp_b'");
    $other->execute([$cohort]);
    $otherRow = $other->fetch();
    t('INV-1 다른 그룹(__inv_grp_b) title 보존', $otherRow && $otherRow['title'] === '__inv_grp_b');
    t('INV-1 다른 그룹 content 보존', $otherRow && $otherRow['content_markdown'] === 'c1');

    $updated = $db->prepare("SELECT COUNT(*) c FROM tasks WHERE cohort = ? AND title = '__inv_grp_a_v2'");
    $updated->execute([$cohort]);
    t('INV-1 그룹 4 row 모두 새 title', (int)$updated->fetch()['c'] === 4);

    // ── INV-2: group_delete 시뮬레이트 — completed=1 row 보존 ──
    $db->prepare("
        DELETE FROM tasks
         WHERE cohort = ? AND title = '__inv_grp_a_v2' AND role = 'operation' AND completed = 0
    ")->execute([$cohort]);

    $leftover = $db->prepare("SELECT completed FROM tasks WHERE cohort = ? AND title = '__inv_grp_a_v2'");
    $leftover->execute([$cohort]);
    $rows = $leftover->fetchAll();
    t('INV-2 미완료 2개 삭제 (남은 row 2개)', count($rows) === 2);
    t('INV-2 남은 row 모두 completed=1',
       count($rows) === 2 && count(array_filter($rows, fn($r) => (int)$r['completed'] === 1)) === 2);

    // ── INV-3: today_tasks 가 그룹 변경 후에도 정상 row 단위 응답 ──
    //   (오늘 날짜에 매치되는 row 만 — 위 시드는 2099 라 매치 0 이지만,
    //    오늘 일자 시드 1개 추가해서 row 단위 SELECT 가 작동함을 확인)
    $today = date('Y-m-d');
    $ins->execute(['__inv_today', 0, $today, $today, $cohort]);

    $todayRows = $db->prepare("
        SELECT t.id, t.title, t.completed
          FROM tasks t
         WHERE t.cohort = ? AND t.start_date <= ? AND t.end_date >= ?
    ");
    $todayRows->execute([$cohort, $today, $today]);
    $found = false;
    foreach ($todayRows->fetchAll() as $r) {
        if ($r['title'] === '__inv_today') { $found = true; break; }
    }
    t('INV-3 today_tasks row 단위 응답 정상', $found);
} finally {
    // ── Cleanup ─────────────────────────────────────
    $db->prepare("DELETE FROM tasks WHERE title LIKE '__inv_grp%' OR title = '__inv_today'")->execute();
}

echo "\n결과: PASS={$pass}  FAIL={$fail}\n";
exit($fail ? 1 : 0);
