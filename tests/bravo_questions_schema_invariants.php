<?php
/**
 * bravo_questions + bravo_exam_ot 스키마 불변식. DEV DB.
 * 사용: php tests/bravo_questions_schema_invariants.php
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

$qCols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_questions") as $c) $qCols[$c['Field']] = $c;
foreach (['id','question_type','bravo_level','source','korean_text','english_text','target_chunks',
          'accepted_answers','reference_speech_sec','response_time_limit_sec','difficulty','is_active','created_by'] as $col) {
    t("bravo_questions.{$col} 존재", isset($qCols[$col]));
}
t('difficulty ENUM', strpos($qCols['difficulty']['Type'], "enum('easy','normal','hard')") !== false, $qCols['difficulty']['Type']);
t('korean_text NOT NULL', $qCols['korean_text']['Null'] === 'NO');
t('english_text NOT NULL', $qCols['english_text']['Null'] === 'NO');
t('is_active 기본 1', (string)$qCols['is_active']['Default'] === '1');

$oCols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_exam_ot") as $c) $oCols[$c['Field']] = $c;
foreach (['id','exam_id','title','intro_text','video_url','type1_text','type2_text','type3_text','require_check'] as $col) {
    t("bravo_exam_ot.{$col} 존재", isset($oCols[$col]));
}
$idx = $db->query("SHOW INDEX FROM bravo_exam_ot WHERE Key_name='uk_bravo_exam_ot_exam'")->fetchAll();
t('exam_id UNIQUE 인덱스', count($idx) === 1 && (int)$idx[0]['Non_unique'] === 0);

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
