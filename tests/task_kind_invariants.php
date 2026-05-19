<?php
/**
 * tasks.group_kind / group_scope 인보리언트.
 *
 * 사용: php tests/task_kind_invariants.php
 *
 * 룰:
 *   INV-G1: 모든 row 가 group_kind ∈ {role,everyone,person} 이고
 *           scope NULL 여부가 kind 와 일치.
 *   INV-G2: person 묶음은 묶음 키 당 distinct assignee 1명.
 *   INV-G3: everyone 묶음에 (admin_id|member_id, start_date, end_date) 중복 없음.
 *
 * ⚠️ SQL 동기화: public_html/api/admin.php 의 task_create / all_tasks_grouped
 *    의 group_kind/group_scope 패턴과 동일 가정을 검증한다.
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

// INV-G1
$bad = (int)$db->query("
    SELECT COUNT(*) FROM tasks
     WHERE (group_kind = 'role'     AND (group_scope IS NULL OR group_scope <> role))
        OR (group_kind = 'everyone' AND group_scope IS NOT NULL)
        OR (group_kind = 'person'   AND (group_scope IS NULL
                                         OR (group_scope NOT LIKE 'admin:%'
                                             AND group_scope NOT LIKE 'member:%')))
")->fetchColumn();
t('INV-G1 kind ↔ scope 일관', $bad === 0, "bad={$bad}");

// INV-G2
$g2 = (int)$db->query("
    SELECT COUNT(*) FROM (
      SELECT cohort, title, group_scope,
             COUNT(DISTINCT CONCAT_WS(':',
                 COALESCE(assignee_admin_id, '_'),
                 COALESCE(assignee_member_id, '_'))) AS distinct_assignees
        FROM tasks
       WHERE group_kind = 'person'
       GROUP BY cohort, title, group_scope
       HAVING distinct_assignees > 1
    ) x
")->fetchColumn();
t('INV-G2 person 묶음당 assignee 1명', $g2 === 0, "violators={$g2}");

// INV-G3
$g3 = (int)$db->query("
    SELECT COUNT(*) FROM (
      SELECT cohort, title, assignee_admin_id, assignee_member_id, start_date, end_date,
             COUNT(*) c
        FROM tasks
       WHERE group_kind = 'everyone'
       GROUP BY cohort, title, assignee_admin_id, assignee_member_id, start_date, end_date
       HAVING c > 1
    ) x
")->fetchColumn();
t('INV-G3 everyone 묶음 사람×기간 중복 없음', $g3 === 0, "violators={$g3}");

echo "\n--- {$pass} pass / {$fail} fail ---\n";
exit($fail ? 1 : 0);
