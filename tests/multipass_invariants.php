<?php
/**
 * Multipass PROD 인보리언트 검증.
 * 사용: php tests/multipass_invariants.php
 *
 * 데이터 무결성 검증. 1건이라도 위반하면 exit 1.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$db = getDB();
$pass = 0; $fail = 0;
function inv(string $name, int $expected, int $actual, string $sql = ''): void {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "PASS  {$name}  (= {$expected})\n"; return; }
    $fail++;
    echo "FAIL  {$name}  expected={$expected} actual={$actual}\n";
    if ($sql) echo "  SQL: {$sql}\n";
}

// INV-1: orphan multipass_cohorts (존재 안 하는 cohort_id)
$sql1 = "SELECT COUNT(*) FROM multipass_cohorts mc LEFT JOIN cohorts c ON mc.cohort_id = c.id WHERE c.id IS NULL";
inv('INV-1 orphan cohorts', 0, (int)$db->query($sql1)->fetchColumn(), $sql1);

// INV-2: 동일 (pass_id, cohort_id) 중복
$sql2 = "SELECT COUNT(*) FROM (SELECT pass_id, cohort_id FROM multipass_cohorts GROUP BY pass_id, cohort_id HAVING COUNT(*) > 1) t";
inv('INV-2 duplicate', 0, (int)$db->query($sql2)->fetchColumn(), $sql2);

// INV-3: coupon_issued=1 인데 coupon_issued_at 이 NULL
$sql3 = "SELECT COUNT(*) FROM multipass_cohorts WHERE coupon_issued = 1 AND coupon_issued_at IS NULL";
inv('INV-3 coupon at consistency', 0, (int)$db->query($sql3)->fetchColumn(), $sql3);

// INV-4: coupon_issued=0 인데 coupon_issued_at 또는 coupon_issued_by 가 NULL 아님
$sql4 = "SELECT COUNT(*) FROM multipass_cohorts WHERE coupon_issued = 0 AND (coupon_issued_at IS NOT NULL OR coupon_issued_by IS NOT NULL)";
inv('INV-4 coupon off cleanup', 0, (int)$db->query($sql4)->fetchColumn(), $sql4);

// INV-5: orphan pass_id (FK 가 잡고 있어야 0)
$sql5 = "SELECT COUNT(*) FROM multipass_cohorts mc LEFT JOIN multipass p ON mc.pass_id = p.id WHERE p.id IS NULL";
inv('INV-5 orphan pass', 0, (int)$db->query($sql5)->fetchColumn(), $sql5);

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
