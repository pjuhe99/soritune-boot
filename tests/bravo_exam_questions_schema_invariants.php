<?php
/**
 * bravo_exam_questions 스키마 불변식. DEV DB.
 * 사용: php tests/bravo_exam_questions_schema_invariants.php
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

$exists = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'bravo_exam_questions'")->fetchColumn();
t('bravo_exam_questions 테이블 존재', (int)$exists === 1);
if ((int)$exists !== 1) { echo "\n결과: {$pass} pass, {$fail} fail\n"; exit(1); }

$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_exam_questions") as $c) $cols[$c['Field']] = $c;
foreach (['id','exam_id','question_id','display_order','created_at'] as $col) {
    t("bravo_exam_questions.{$col} 존재", isset($cols[$col]));
}
t('exam_id NOT NULL', $cols['exam_id']['Null'] === 'NO');
t('question_id NOT NULL', $cols['question_id']['Null'] === 'NO');
t('display_order 기본 0', (string)$cols['display_order']['Default'] === '0');

$idx = $db->query("SHOW INDEX FROM bravo_exam_questions WHERE Key_name='uk_beq_exam_question'")->fetchAll();
t('(exam_id,question_id) UNIQUE', count($idx) === 2 && (int)$idx[0]['Non_unique'] === 0);

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
