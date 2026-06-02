<?php
/**
 * BRAVO 시험 CRUD 통합 테스트. DEV DB transaction rollback.
 * 사용: php tests/bravo_exam_service_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/bravo.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();
$db->beginTransaction();
try {
    $label = 'TEST_EXAM_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$label, $label]);
    $cohortId = (int)$db->lastInsertId();

    // --- create (period, 특정 기수) ---
    $id = bravoExamCreate($db, [
        'title' => '통합 BRAVO 2',
        'bravo_level' => 2,
        'exam_mode' => 'period',
        'start_at' => '2026-06-01 10:00:00',
        'end_at' => '2026-06-02 10:00:00',
        'result_release_at' => '2026-06-12 10:00:00',
        'attempt_limit' => 3,
        'target_type' => 'cohort',
        'target_cohort_id' => $cohortId,
        'status' => 'preparing',
    ], 99);
    t('create 반환 id > 0', $id > 0);

    $list = bravoExamList($db, ['target_cohort_id' => $cohortId]);
    $row = null; foreach ($list as $r) if ((int)$r['id'] === $id) $row = $r;
    t('list 에 생성된 시험 존재', $row !== null);
    t('level_name 조인', ($row['level_name'] ?? '') === 'BRAVO 2', $row['level_name'] ?? '(null)');
    t('cohort 라벨 조인', ($row['target_cohort_label'] ?? '') === $label);
    t('status preparing', $row['status'] === 'preparing');
    t('start_at 저장', strpos((string)$row['start_at'], '2026-06-01 10:00:00') === 0);

    // --- update (status 변경 + always 전환 → 날짜 NULL 정규화) ---
    bravoExamUpdate($db, $id, [
        'title' => '통합 BRAVO 2 (수정)',
        'bravo_level' => 2,
        'exam_mode' => 'always',
        'start_at' => '2026-06-01 10:00:00',
        'end_at' => '2026-06-02 10:00:00',
        'result_release_at' => '2026-06-12 10:00:00',
        'attempt_limit' => 1,
        'target_type' => 'all',
        'target_cohort_id' => null,
        'status' => 'open',
    ]);
    $list2 = bravoExamList($db);
    $row2 = null; foreach ($list2 as $r) if ((int)$r['id'] === $id) $row2 = $r;
    t('update 제목 반영', $row2['title'] === '통합 BRAVO 2 (수정)');
    t('update status open', $row2['status'] === 'open');
    t('always 모드 start_at NULL 정규화', $row2['start_at'] === null);
    t('always 모드 end_at NULL 정규화', $row2['end_at'] === null);
    t('always 모드 result_release_at NULL 정규화', $row2['result_release_at'] === null);
    t('update target all → cohort_id NULL', $row2['target_cohort_id'] === null);
    t('attempt_limit 1 반영', (int)$row2['attempt_limit'] === 1);

    // --- delete ---
    bravoExamDelete($db, $id);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM bravo_exams WHERE id = " . (int)$id)->fetchColumn();
    t('delete 후 row 0', $cnt === 0, 'cnt=' . $cnt);

    $db->rollBack();
} catch (\Throwable $e) {
    $db->rollBack();
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
