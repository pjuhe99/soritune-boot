<?php
/**
 * bravo_member_grades / bravo_grade_log 스키마 불변식. DEV DB.
 * 사용: php tests/bravo_member_grades_schema_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();

foreach (['bravo_member_grades', 'bravo_grade_log'] as $tbl) {
    $exists = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$tbl}'")->fetchColumn();
    t("{$tbl} 테이블 존재", (int)$exists === 1);
    if ((int)$exists !== 1) { echo "\n결과: {$pass} pass, {$fail} fail\n"; exit(1); }
}

// bravo_member_grades
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_member_grades") as $c) $cols[$c['Field']] = $c;
foreach (['id','member_key','current_level','extra_attempts_1','extra_attempts_2','extra_attempts_3','updated_at'] as $col) {
    t("grades.{$col} 존재", isset($cols[$col]));
}
t('current_level 기본 0', (string)$cols['current_level']['Default'] === '0');
t('extra_attempts_1 기본 0', (string)$cols['extra_attempts_1']['Default'] === '0');
t('member_key VARCHAR(120) NOT NULL', stripos($cols['member_key']['Type'], 'varchar(120)') === 0 && $cols['member_key']['Null'] === 'NO');
$idx = $db->query("SHOW INDEX FROM bravo_member_grades WHERE Key_name='uk_bmg_member'")->fetchAll();
t('member_key UNIQUE', count($idx) === 1 && (int)$idx[0]['Non_unique'] === 0);

// bravo_grade_log
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_grade_log") as $c) $cols[$c['Field']] = $c;
foreach (['id','member_key','from_level','to_level','source','ref_id','note','created_at'] as $col) {
    t("log.{$col} 존재", isset($cols[$col]));
}
t('source ENUM 4값', stripos($cols['source']['Type'], "enum('grandfather','exam_pass','self_demotion','admin_adjust')") === 0);
t('ref_id NULL 허용', $cols['ref_id']['Null'] === 'YES');
$ix = $db->query("SHOW INDEX FROM bravo_grade_log WHERE Key_name='idx_bgl_member'")->fetchAll();
t('idx_bgl_member 2컬럼 비유니크', count($ix) === 2 && (int)$ix[0]['Non_unique'] === 1);

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
