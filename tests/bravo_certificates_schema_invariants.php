<?php
/**
 * bravo_certificates 스키마 불변식. DEV DB.
 * 사용: php tests/bravo_certificates_schema_invariants.php
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

$exists = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'bravo_certificates'")->fetchColumn();
t('bravo_certificates 테이블 존재', (int)$exists === 1);
if ((int)$exists !== 1) { echo "\n결과: {$pass} pass, {$fail} fail\n"; exit(1); }

$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_certificates") as $c) $cols[$c['Field']] = $c;
foreach (['id','attempt_id','cert_no','member_name','bravo_level','passed_on','issued_at'] as $col) {
    t("certificates.{$col} 존재", isset($cols[$col]));
}
t('attempt_id NOT NULL', $cols['attempt_id']['Null'] === 'NO');
t('cert_no NOT NULL', $cols['cert_no']['Null'] === 'NO');
t('member_name NOT NULL', $cols['member_name']['Null'] === 'NO');
t('bravo_level NOT NULL', $cols['bravo_level']['Null'] === 'NO');
t('passed_on NOT NULL + DATE', $cols['passed_on']['Null'] === 'NO' && stripos($cols['passed_on']['Type'], 'date') === 0);
t('cert_no VARCHAR(40)', stripos($cols['cert_no']['Type'], 'varchar(40)') === 0);

$idx = $db->query("SHOW INDEX FROM bravo_certificates WHERE Key_name='uk_bc_attempt'")->fetchAll();
t('attempt_id UNIQUE', count($idx) === 1 && (int)$idx[0]['Non_unique'] === 0);
$idx = $db->query("SHOW INDEX FROM bravo_certificates WHERE Key_name='uk_bc_cert_no'")->fetchAll();
t('cert_no UNIQUE', count($idx) === 1 && (int)$idx[0]['Non_unique'] === 0);
$ix = $db->query("SHOW INDEX FROM bravo_certificates WHERE Key_name='idx_bc_level_date'")->fetchAll();
t('idx_bc_level_date 비유니크 2컬럼', count($ix) === 2 && (int)$ix[0]['Non_unique'] === 1);

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
