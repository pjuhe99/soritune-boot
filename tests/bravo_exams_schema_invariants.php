<?php
/**
 * bravo_exams 스키마 검증. 사용: php tests/bravo_exams_schema_invariants.php
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

function colExists(PDO $db, string $table, string $col): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() === 1;
}

$tblStmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'bravo_exams'");
$tblStmt->execute();
t('bravo_exams 테이블 존재', (int)$tblStmt->fetchColumn() === 1);

foreach (['id','title','bravo_level','exam_mode','start_at','end_at','result_release_at','attempt_limit','target_type','target_cohort_id','status','created_by','created_at','updated_at'] as $col) {
    t("컬럼 {$col} 존재", colExists($db, 'bravo_exams', $col));
}

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
