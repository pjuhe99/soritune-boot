<?php
/**
 * Task 결과물 제출 인보리언트.
 *
 * 사용:
 *   php tests/task_submission_invariants.php  (DEV/PROD DB 직접 조회)
 *
 * INV-S1: completed=1 AND requires_submission=1 → submission_text NOT NULL AND TRIM != ''
 * INV-S2: 같은 (cohort, title, role) 묶음 안 모든 row 의 requires_submission 동일
 * INV-S3: submission_text NOT NULL → submitted_at NOT NULL
 *
 * ⚠️ SQL 동기화 주의:
 *   API 변경 시 admin.php 의 toggle_task / task_submission_update /
 *   task_group_update 와 INV 정의가 일관되게 유지돼야 함.
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

// ── INV-S1: completed=1 + requires_submission=1 인데 텍스트 없음 = 위반 ──
$bad = $db->query("
    SELECT id, title, cohort, role
      FROM tasks
     WHERE completed = 1 AND requires_submission = 1
       AND (submission_text IS NULL OR TRIM(submission_text) = '')
")->fetchAll();
t('INV-S1 completed+requires → 텍스트 있음', count($bad) === 0,
    'violations: ' . json_encode($bad, JSON_UNESCAPED_UNICODE));

// ── INV-S2: 묶음 안 requires_submission 값 일관성 ──
$mixed = $db->query("
    SELECT cohort, title, role,
           MIN(requires_submission) AS min_v,
           MAX(requires_submission) AS max_v,
           COUNT(*) AS row_cnt
      FROM tasks
     GROUP BY cohort, title, role
    HAVING MIN(requires_submission) <> MAX(requires_submission)
")->fetchAll();
t('INV-S2 묶음 안 requires_submission 일관', count($mixed) === 0,
    'mixed groups: ' . json_encode($mixed, JSON_UNESCAPED_UNICODE));

// ── INV-S3: submission_text 있으면 submitted_at 도 있음 ──
$noTime = $db->query("
    SELECT id, title, cohort
      FROM tasks
     WHERE submission_text IS NOT NULL AND submitted_at IS NULL
")->fetchAll();
t('INV-S3 text→timestamp 동행', count($noTime) === 0,
    'violations: ' . json_encode($noTime, JSON_UNESCAPED_UNICODE));

echo "\n=== {$pass} PASS / {$fail} FAIL ===\n";
exit($fail === 0 ? 0 : 1);
