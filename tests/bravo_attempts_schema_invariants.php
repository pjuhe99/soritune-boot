<?php
/**
 * bravo_attempts / bravo_answers 스키마 불변식. DEV DB.
 * 사용: php tests/bravo_attempts_schema_invariants.php
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

foreach (['bravo_attempts', 'bravo_answers'] as $tbl) {
    $exists = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$tbl}'")->fetchColumn();
    t("{$tbl} 테이블 존재", (int)$exists === 1);
    if ((int)$exists !== 1) { echo "\n결과: {$pass} pass, {$fail} fail\n"; exit(1); }
}

// bravo_attempts
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_attempts") as $c) $cols[$c['Field']] = $c;
foreach (['id','exam_id','member_key','member_id','attempt_no','question_ids','status','ot_checked_at','started_at','submitted_at'] as $col) {
    t("bravo_attempts.{$col} 존재", isset($cols[$col]));
}
t('attempts.exam_id NOT NULL', $cols['exam_id']['Null'] === 'NO');
t('attempts.member_key NOT NULL', $cols['member_key']['Null'] === 'NO');
t('attempts.member_id NOT NULL', $cols['member_id']['Null'] === 'NO');
t('attempts.question_ids NOT NULL', $cols['question_ids']['Null'] === 'NO');
t('attempts.status ENUM 2값', stripos($cols['status']['Type'], "enum('in_progress','submitted')") === 0);
t('attempts.status 기본 in_progress', $cols['status']['Default'] === 'in_progress');
t('attempts.ot_checked_at NULL 허용', $cols['ot_checked_at']['Null'] === 'YES');
t('attempts.submitted_at NULL 허용', $cols['submitted_at']['Null'] === 'YES');

$idx = $db->query("SHOW INDEX FROM bravo_attempts WHERE Key_name='uk_ba_exam_user_no'")->fetchAll();
t('(exam_id,member_key,attempt_no) UNIQUE', count($idx) === 3 && (int)$idx[0]['Non_unique'] === 0);
$ix1 = $db->query("SHOW INDEX FROM bravo_attempts WHERE Key_name='idx_ba_exam_user'")->fetchAll();
t('idx_ba_exam_user 중복 인덱스 없음 (UNIQUE prefix 로 커버)', count($ix1) === 0);
$ix2 = $db->query("SHOW INDEX FROM bravo_attempts WHERE Key_name='idx_ba_member'")->fetchAll();
t('idx_ba_member 인덱스 존재', count($ix2) === 1);

// bravo_answers
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_answers") as $c) $cols[$c['Field']] = $c;
foreach (['id','attempt_id','question_id','seq','audio_path','audio_mime','duration_ms','retake_used','answered_at'] as $col) {
    t("bravo_answers.{$col} 존재", isset($cols[$col]));
}
t('answers.attempt_id NOT NULL', $cols['attempt_id']['Null'] === 'NO');
t('answers.question_id NOT NULL', $cols['question_id']['Null'] === 'NO');
t('answers.audio_path NOT NULL', $cols['audio_path']['Null'] === 'NO');
t('answers.duration_ms NULL 허용', $cols['duration_ms']['Null'] === 'YES');
t('answers.retake_used 기본 0', (string)$cols['retake_used']['Default'] === '0');

$idx = $db->query("SHOW INDEX FROM bravo_answers WHERE Key_name='uk_bans_attempt_question'")->fetchAll();
t('(attempt_id,question_id) UNIQUE', count($idx) === 2 && (int)$idx[0]['Non_unique'] === 0);
$ix3 = $db->query("SHOW INDEX FROM bravo_answers WHERE Key_name='idx_bans_question'")->fetchAll();
t('idx_bans_question 인덱스 존재', count($ix3) === 1);

// 업로드 디렉토리
t('bravo_uploads/answers 디렉토리 존재', is_dir(__DIR__ . '/../bravo_uploads/answers'));

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
