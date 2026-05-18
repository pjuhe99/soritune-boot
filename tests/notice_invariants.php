<?php
/**
 * Notices 인보리언트.
 *
 * 사용:
 *   cd /root/boot-dev && php tests/notice_invariants.php
 *
 * INV-N1: 모든 notices.cohort_id 가 cohorts.id 에 존재 (FK 보장이지만 명시)
 * INV-N2: 모든 notices.created_by_admin_id 가 admins.id 에 존재
 * INV-N3: 모든 notices.is_visible 값이 0/1
 * INV-N4: 모든 notices.title 가 trim 후 빈 문자열 아님
 * INV-N5: 모든 notices.body_markdown 가 trim 후 빈 문자열 아님
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

$bad = $db->query("
    SELECT n.id FROM notices n
    LEFT JOIN cohorts c ON c.id = n.cohort_id
    WHERE c.id IS NULL
")->fetchAll();
t('INV-N1 cohort FK', count($bad) === 0, 'violations: ' . json_encode($bad));

$bad = $db->query("
    SELECT n.id FROM notices n
    LEFT JOIN admins a ON a.id = n.created_by_admin_id
    WHERE a.id IS NULL
")->fetchAll();
t('INV-N2 admin FK', count($bad) === 0, 'violations: ' . json_encode($bad));

$bad = $db->query("SELECT id, is_visible FROM notices WHERE is_visible NOT IN (0, 1)")->fetchAll();
t('INV-N3 is_visible 0/1', count($bad) === 0, 'violations: ' . json_encode($bad));

$bad = $db->query("SELECT id FROM notices WHERE TRIM(title) = ''")->fetchAll();
t('INV-N4 title 비어있지 않음', count($bad) === 0, 'violations: ' . json_encode($bad));

$bad = $db->query("SELECT id FROM notices WHERE TRIM(body_markdown) = ''")->fetchAll();
t('INV-N5 body 비어있지 않음', count($bad) === 0, 'violations: ' . json_encode($bad));

echo "\n=== {$pass} PASS / {$fail} FAIL ===\n";
exit($fail === 0 ? 0 : 1);
