<?php
/**
 * growth_record_submissions 스키마 invariants.
 * 사용: cd /root/boot-dev && php tests/growth_record_schema_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$db = getDB();
$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// 테이블 존재
t('table exists', (bool)$db->query("SHOW TABLES LIKE 'growth_record_submissions'")->fetch());

// 컬럼
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM growth_record_submissions")->fetchAll() as $c) $cols[$c['Field']] = $c;
foreach (['id','member_id','cohort_id','url','before_file','after_file','before_orig_name','after_orig_name',
          'before_mime','after_mime','consent_agreed_at','submitted_at','cancelled_at','cancelled_by',
          'cancel_reason','active_member_id'] as $f) {
    t("column {$f}", isset($cols[$f]));
}
t('consent_agreed_at NOT NULL', ($cols['consent_agreed_at']['Null'] ?? '') === 'NO');
t('active_member_id is generated', stripos($cols['active_member_id']['Extra'] ?? '', 'GENERATED') !== false
    || stripos($cols['active_member_id']['Extra'] ?? '', 'PERSISTENT') !== false
    || stripos($cols['active_member_id']['Extra'] ?? '', 'STORED') !== false);

// unique 키
$uq = false;
foreach ($db->query("SHOW INDEX FROM growth_record_submissions")->fetchAll() as $ix) {
    if ($ix['Key_name'] === 'uq_active_member' && (int)$ix['Non_unique'] === 0) $uq = true;
}
t('uq_active_member unique key', $uq);

// FK
$fks = $db->query("
    SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'growth_record_submissions'
      AND REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll(PDO::FETCH_COLUMN);
t('fk member', in_array('fk_growth_member', $fks, true));
t('fk cohort', in_array('fk_growth_cohort', $fks, true));

// system_contents 키
$keys = $db->query("SELECT content_key FROM system_contents WHERE content_key LIKE 'growth_record_%'")
           ->fetchAll(PDO::FETCH_COLUMN);
foreach (['growth_record_enabled','growth_record_deadline','growth_record_guide'] as $k) {
    t("system_contents {$k}", in_array($k, $keys, true));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
