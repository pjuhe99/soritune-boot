<?php
/**
 * issue auto-resolve 인보리언트.
 * 사용: php tests/issue_auto_resolve_invariants.php
 *
 * 자동 해결로 status='resolved' 가 된 row 의 무결성을 검증.
 * (admin_note 가 'auto: ...' prefix 인 row 만 대상.)
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

// INV-1: auto: 마커가 있는 row 는 모두 status='resolved'
$sql1 = "SELECT COUNT(*) FROM issue_reports WHERE admin_note LIKE 'auto:%' AND status <> 'resolved'";
inv('INV-1 auto-marker only on resolved', 0, (int)$db->query($sql1)->fetchColumn(), $sql1);

// INV-2: auto resolve 된 row 는 resolved_by / resolved_at 모두 NOT NULL
$sql2 = "SELECT COUNT(*) FROM issue_reports WHERE admin_note LIKE 'auto:%' AND (resolved_by IS NULL OR resolved_at IS NULL)";
inv('INV-2 auto resolve completeness', 0, (int)$db->query($sql2)->fetchColumn(), $sql2);

// INV-3: auto resolve 된 row 는 issue_report_logs 에 changed_by_type='admin', new_status='resolved' 로그가 최소 1건
$sql3 = "
SELECT COUNT(*) FROM (
  SELECT ir.id
  FROM issue_reports ir
  LEFT JOIN issue_report_logs il
    ON il.issue_id = ir.id AND il.new_status = 'resolved' AND il.changed_by_type = 'admin'
  WHERE ir.admin_note LIKE 'auto:%'
  GROUP BY ir.id
  HAVING COUNT(il.id) = 0
) t";
inv('INV-3 auto resolve has admin log', 0, (int)$db->query($sql3)->fetchColumn(), $sql3);

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
