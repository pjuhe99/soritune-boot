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

// ── cohort 정보 ─────────────────────────────────────────────
$cohortStmt = $db->prepare("SELECT id, cohort, start_date, end_date FROM cohorts WHERE cohort = ?");

$cohortStmt->execute([$fromCohort]);
$fromRow = $cohortStmt->fetch(PDO::FETCH_ASSOC);
if (!$fromRow) {
    fwrite(STDERR, "원본 cohort '{$fromCohort}' 가 cohorts 테이블에 없습니다\n");
    exit(2);
}

$cohortStmt->execute([$toCohort]);
$toRow = $cohortStmt->fetch(PDO::FETCH_ASSOC);
if (!$toRow) {
    fwrite(STDERR, "대상 cohort '{$toCohort}' 가 cohorts 테이블에 없습니다\n");
    exit(2);
}

$fromStart = new DateTime($fromRow['start_date']);
$toStart   = new DateTime($toRow['start_date']);
$dayOffset = (int)$fromStart->diff($toStart)->format('%r%a');

echo "[cohort 정보]\n";
echo "  {$fromCohort} 시작: {$fromRow['start_date']}\n";
echo "  {$toCohort} 시작: {$toRow['start_date']}\n";
echo "  Day shift: " . ($dayOffset >= 0 ? "+{$dayOffset}" : (string)$dayOffset) . "일\n\n";

// ── 멱등 가드: 대상 cohort 에 이미 데이터 있나? ───────────────
$countTasksStmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE cohort = ?");
$countTasksStmt->execute([$toCohort]);
$nTasks = (int)$countTasksStmt->fetchColumn();

$countCurStmt = $db->prepare("SELECT COUNT(*) FROM curriculum_items WHERE cohort = ?");
$countCurStmt->execute([$toCohort]);
$nCur = (int)$countCurStmt->fetchColumn();

if (($nTasks > 0 || $nCur > 0) && !$force) {
    fwrite(STDERR, "대상 cohort '{$toCohort}' 에 이미 task {$nTasks}건 / curriculum {$nCur}건 존재.\n");
    fwrite(STDERR, "  --force 사용하거나 수동 삭제 후 재실행하세요.\n");
    exit(3);
}

if ($nTasks > 0 || $nCur > 0) {
    echo "[--force] 대상 기존 데이터 task {$nTasks} + curriculum {$nCur} 건 삭제 예정\n\n";
}

// ── 11기 source 데이터 사전 통계 ────────────────────────────
$srcRoleStats = $db->prepare("
    SELECT role,
           COUNT(*) AS n,
           SUM(assignee_admin_id IS NOT NULL) AS admin_assigned,
           SUM(assignee_member_id IS NOT NULL) AS member_assigned,
           SUM(assignee_admin_id IS NULL AND assignee_member_id IS NULL) AS unassigned
    FROM tasks WHERE cohort = ?
    GROUP BY role ORDER BY role
");
$srcRoleStats->execute([$fromCohort]);
$srcRoles = $srcRoleStats->fetchAll(PDO::FETCH_ASSOC);

echo "[원본 task 분포 ({$fromCohort})]\n";
printf("  %-12s %6s %6s %6s %6s\n", 'role', 'n', 'admin', 'member', 'null');
foreach ($srcRoles as $r) {
    printf("  %-12s %6d %6d %6d %6d\n",
        $r['role'], $r['n'], $r['admin_assigned'], $r['member_assigned'], $r['unassigned']);
}
echo "\n";

$countCurStmt->execute([$fromCohort]);
$srcCur = (int)$countCurStmt->fetchColumn();
echo "[원본 curriculum_items ({$fromCohort})]: {$srcCur}건\n\n";

// ── 12기 후보 검증 (role 별 ≥ 1) ──────────────────────────
$srcUsedRoles = array_column($srcRoles, 'role');

$candidateCounts = [];
foreach ($srcUsedRoles as $role) {
    $candidateCounts[$role] = countCandidates($db, $role, $toCohort);
}

echo "[대상 후보 ({$toCohort})]\n";
foreach ($candidateCounts as $role => $count) {
    printf("  %-12s %6d명\n", $role, $count);
}
echo "\n";

$missing = array_filter($candidateCounts, fn($c) => $c === 0);
if ($missing && !$allowMissingRoles) {
    fwrite(STDERR, "다음 role 의 대상 후보가 0명입니다: " . implode(', ', array_keys($missing)) . "\n");
    fwrite(STDERR, "  admin_roles 등록 후 재실행하거나 --allow-missing-roles 사용\n");
    exit(4);
}

// ── Template 추출 ─────────────────────────────────────────
$templates = extractTemplates($db, $fromCohort, $fromRow['start_date']);

$tplByRole = [];
foreach ($templates as $tpl) {
    $tplByRole[$tpl['role']] = ($tplByRole[$tpl['role']] ?? 0) + 1;
}

echo "[Template 추출] " . count($templates) . " templates (dedupe 결과)\n";
foreach ($tplByRole as $role => $cnt) {
    printf("  %-12s %6d templates\n", $role, $cnt);
}
echo "\n";

/**
 * 특정 role 의 대상 cohort 후보 수.
 * - admin role (head/coach/sub_coach/subhead1/subhead2): admin_roles JOIN (admins.cohort = $toCohort)
 * - operation: cohort=NULL 인 active operation admin (binnie4/wannie 등)
 * - leader/subleader: bootcamp_members (cohort_id, member_role)
 */
function countCandidates(PDO $db, string $role, string $toCohort): int {
    if (in_array($role, ['head', 'coach', 'sub_coach', 'subhead1', 'subhead2'], true)) {
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT a.id)
            FROM admins a JOIN admin_roles ar ON ar.admin_id = a.id
            WHERE a.cohort = ? AND ar.role = ? AND a.is_active = 1
        ");
        $stmt->execute([$toCohort, $role]);
        return (int)$stmt->fetchColumn();
    }
    if ($role === 'operation') {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM admins
            WHERE cohort IS NULL AND role = 'operation' AND is_active = 1
        ");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }
    if (in_array($role, ['leader', 'subleader'], true)) {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM bootcamp_members bm
            JOIN cohorts c ON c.id = bm.cohort_id
            WHERE c.cohort = ? AND bm.member_role = ? AND bm.is_active = 1
        ");
        $stmt->execute([$toCohort, $role]);
        return (int)$stmt->fetchColumn();
    }
    return 0;
}

/**
 * 원본 cohort 의 task 를 (role, title, content_markdown, start_day_offset, end_day_offset) 로 dedupe.
 * 각 template 은 11기 assignee 정보(operation 보존용) 와 source row 수 함께 보관.
 *
 * @return array<int, array{
 *   role: string,
 *   title: string,
 *   content_markdown: ?string,
 *   start_day_offset: int,
 *   end_day_offset: int,
 *   src_assignee_admin_ids: int[],
 *   src_has_unassigned: bool,
 *   src_row_count: int
 * }>
 */
function extractTemplates(PDO $db, string $fromCohort, string $fromStart): array {
    $stmt = $db->prepare("
        SELECT role, title, content_markdown,
               DATEDIFF(start_date, ?) AS start_day_offset,
               DATEDIFF(end_date, ?)   AS end_day_offset,
               assignee_admin_id, assignee_member_id
        FROM tasks
        WHERE cohort = ?
        ORDER BY start_day_offset, role, title, id
    ");
    $stmt->execute([$fromStart, $fromStart, $fromCohort]);

    $templates = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = sha1(implode('|', [
            $r['role'],
            $r['title'],
            $r['content_markdown'] ?? '',
            (string)$r['start_day_offset'],
            (string)$r['end_day_offset'],
        ]));
        if (!isset($templates[$key])) {
            $templates[$key] = [
                'role' => $r['role'],
                'title' => $r['title'],
                'content_markdown' => $r['content_markdown'],
                'start_day_offset' => (int)$r['start_day_offset'],
                'end_day_offset'   => (int)$r['end_day_offset'],
                'src_assignee_admin_ids' => [],
                'src_has_unassigned' => false,
                'src_row_count' => 0,
            ];
        }
        $t = &$templates[$key];
        $t['src_row_count']++;
        if ($r['assignee_admin_id'] !== null) {
            $aid = (int)$r['assignee_admin_id'];
            if (!in_array($aid, $t['src_assignee_admin_ids'], true)) {
                $t['src_assignee_admin_ids'][] = $aid;
            }
        }
        if ($r['assignee_admin_id'] === null && $r['assignee_member_id'] === null) {
            $t['src_has_unassigned'] = true;
        }
        unset($t);
    }
    return array_values($templates);
}
