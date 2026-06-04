<?php
/**
 * bravo_answer_grades / bravo_attempt_grades 스키마 불변식. DEV DB.
 * 사용: php tests/bravo_grades_schema_invariants.php
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

foreach (['bravo_answer_grades', 'bravo_attempt_grades'] as $tbl) {
    $exists = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$tbl}'")->fetchColumn();
    t("{$tbl} 테이블 존재", (int)$exists === 1);
    if ((int)$exists !== 1) { echo "\n결과: {$pass} pass, {$fail} fail\n"; exit(1); }
}

// bravo_answer_grades
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_answer_grades") as $c) $cols[$c['Field']] = $c;
foreach (['id','answer_id','attempt_id','accuracy','chunk_ok','response_rating','fluency_rating','completion_ok','score','n_denominator','memo','graded_by','graded_at'] as $col) {
    t("answer_grades.{$col} 존재", isset($cols[$col]));
}
t('accuracy ENUM 3값', stripos($cols['accuracy']['Type'], "enum('correct','partial','wrong')") === 0);
t('response_rating ENUM 3값', stripos($cols['response_rating']['Type'], "enum('good','normal','poor')") === 0);
t('fluency_rating ENUM 3값', stripos($cols['fluency_rating']['Type'], "enum('good','normal','poor')") === 0);
t('completion_ok NULL 허용', $cols['completion_ok']['Null'] === 'YES');
t('score NOT NULL', $cols['score']['Null'] === 'NO');
t('n_denominator NOT NULL', $cols['n_denominator']['Null'] === 'NO');
t('accuracy NOT NULL', $cols['accuracy']['Null'] === 'NO');
t('chunk_ok NOT NULL', $cols['chunk_ok']['Null'] === 'NO');
t('response_rating NOT NULL', $cols['response_rating']['Null'] === 'NO');
t('fluency_rating NOT NULL', $cols['fluency_rating']['Null'] === 'NO');
t('graded_by NOT NULL', $cols['graded_by']['Null'] === 'NO');
$idx = $db->query("SHOW INDEX FROM bravo_answer_grades WHERE Key_name='uk_bag_answer'")->fetchAll();
t('answer_id UNIQUE', count($idx) === 1 && (int)$idx[0]['Non_unique'] === 0);
$ix = $db->query("SHOW INDEX FROM bravo_answer_grades WHERE Key_name='idx_bag_attempt'")->fetchAll();
t('idx_bag_attempt 비유니크', count($ix) === 1 && (int)$ix[0]['Non_unique'] === 1);

// bravo_attempt_grades
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_attempt_grades") as $c) $cols[$c['Field']] = $c;
foreach (['id','attempt_id','total_score','passing_score','result','result_overridden','override_reason','memo','confirmed_by','confirmed_at'] as $col) {
    t("attempt_grades.{$col} 존재", isset($cols[$col]));
}
t('result ENUM 2값', stripos($cols['result']['Type'], "enum('pass','fail')") === 0);
t('passing_score NOT NULL', $cols['passing_score']['Null'] === 'NO');
t('total_score NOT NULL', $cols['total_score']['Null'] === 'NO');
t('result NOT NULL', $cols['result']['Null'] === 'NO');
t('confirmed_by NOT NULL', $cols['confirmed_by']['Null'] === 'NO');
t('result_overridden 기본 0', (string)$cols['result_overridden']['Default'] === '0');
$idx = $db->query("SHOW INDEX FROM bravo_attempt_grades WHERE Key_name='uk_batg_attempt'")->fetchAll();
t('attempt_id UNIQUE', count($idx) === 1 && (int)$idx[0]['Non_unique'] === 0);

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
