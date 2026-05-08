<?php
/**
 * boot.soritune.com - 기수 task / curriculum_items 복제
 * 11기 → 12기 같은 1회성 cohort transition 시 사용.
 *
 * 사용:
 *   php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run
 *   php migrate_clone_cohort_tasks.php --from=11기 --to=12기
 *   php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --force
 *   php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --allow-missing-roles
 *
 * 멱등: 대상 cohort 에 이미 task/curriculum 있으면 abort. --force 로 DELETE 후 재생성.
 * dry-run: BEGIN/INSERT 후 ROLLBACK. 변경 없음.
 *
 * 매핑 규칙:
 *   - role IN (head, coach, sub_coach, subhead1, subhead2): admin_roles JOIN (cohort=대상기수)
 *   - role = operation: 11기 assignee_admin_id 그대로 (cohort=NULL 인 binnie4·wannie 공유)
 *   - role IN (leader, subleader): bootcamp_members (cohort_id=대상기수, member_role=role, is_active=1)
 *   - leader unassigned (NULL): NULL 그대로 단일 row 복제
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/public_html/config.php';

$opts = getopt('', ['from:', 'to:', 'dry-run', 'force', 'allow-missing-roles', 'help']);

if (isset($opts['help']) || empty($opts['from']) || empty($opts['to'])) {
    fwrite(STDERR, "사용: php migrate_clone_cohort_tasks.php --from=<cohort> --to=<cohort> [--dry-run] [--force] [--allow-missing-roles]\n");
    fwrite(STDERR, "예: php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run\n");
    exit(1);
}

$fromCohort = (string)$opts['from'];
$toCohort   = (string)$opts['to'];
$dryRun     = isset($opts['dry-run']);
$force      = isset($opts['force']);
$allowMissingRoles = isset($opts['allow-missing-roles']);

$db = getDB();
$dbName = $db->query("SELECT DATABASE()")->fetchColumn();

echo "=== Cohort Clone: {$fromCohort} → {$toCohort} ===\n";
echo "DB: {$dbName}\n";
echo "Mode: " . ($dryRun ? 'DRY-RUN' : 'APPLY') . ($force ? ' (FORCE)' : '') . "\n\n";
