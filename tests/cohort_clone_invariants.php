<?php
/**
 * cohort clone migration 인보리언트 테스트
 * 사용: php tests/cohort_clone_invariants.php
 *
 * 함수 단위 검증 + outer transaction 으로 부작용 격리.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

define('CLONE_LIB_ONLY', true);
require_once __DIR__ . '/../migrate_clone_cohort_tasks.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; }
    else { $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n"; }
}

$db = getDB();

$db->beginTransaction();
try {
    $hasSource = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE cohort='11기'")->fetchColumn();
    if ($hasSource === 0) {
        echo "SKIP  no 11기 source tasks\n";
        $db->rollBack();
        exit(0);
    }

    $fromStart = $db->query("SELECT start_date FROM cohorts WHERE cohort='11기'")->fetchColumn();
    $templates = extractTemplates($db, '11기', $fromStart);
    t('extractTemplates returns array', is_array($templates));
    t('extractTemplates count > 0', count($templates) > 0);

    $totalSrcRows = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE cohort='11기'")->fetchColumn();
    $sumSrc = array_sum(array_column($templates, 'src_row_count'));
    t('dedupe src_row_count sum equals source row count',
      $sumSrc === $totalSrcRows,
      "templates sum={$sumSrc}, source={$totalSrcRows}");

    foreach (['head','coach','sub_coach','operation','leader','subleader'] as $role) {
        $n = countCandidates($db, $role, '12기');
        t("countCandidates({$role}) >= 0", $n >= 0, "n={$n}");
    }

    $opCands = countCandidates($db, 'operation', '12기');
    $opNullStmt = $db->query("
        SELECT COUNT(*) FROM admins WHERE cohort IS NULL AND role='operation' AND is_active=1
    ");
    $opNullAdmins = (int)$opNullStmt->fetchColumn();
    t('operation candidates = cohort=NULL operation admins',
      $opCands === $opNullAdmins,
      "candidates={$opCands}, null_admins={$opNullAdmins}");

    $opTpl = null;
    foreach ($templates as $tpl) {
        if ($tpl['role'] === 'operation' && !empty($tpl['src_assignee_admin_ids'])) {
            $opTpl = $tpl;
            break;
        }
    }
    if ($opTpl) {
        $opCandsResolved = resolveCandidates($db, $opTpl, '12기');
        $resolvedAdminIds = array_column(array_filter($opCandsResolved, fn($c) => isset($c['admin_id'])), 'admin_id');
        sort($resolvedAdminIds);
        $expected = $opTpl['src_assignee_admin_ids'];
        sort($expected);
        t('operation template preserves src admin ids',
          $resolvedAdminIds === $expected,
          'resolved=' . json_encode($resolvedAdminIds) . ' src=' . json_encode($expected));
    } else {
        echo "SKIP  no operation template with admin assignee\n";
    }

    $db->rollBack();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
