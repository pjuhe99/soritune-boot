<?php
/**
 * cafe_posts.assignment_date 를 새 컷오프(매일 07:00 KST)로 재계산.
 *
 *   00:00:00 ~ 07:00:00 → 전날
 *   07:00:01 ~ 23:59:59 → 당일
 *
 * 추가로 mission_checks 에서 source='automation' AND source_ref='cafe:{id}' 인
 * row 의 check_date 를 새 assignment_date 로 정정.
 *
 * 정정 정책:
 *   - 옛 (회원, 옛 날짜, 미션type) row 가 source='automation' && source_ref='cafe:{이 글 id}' 일 때만 이동
 *   - 새 (회원, 새 날짜, 미션type) row 가 이미 있으면:
 *       · 거기가 manual(status=1)이면 → 옛 row 만 status=0 으로 비움 (manual 우선)
 *       · 거기가 다른 cafe 글의 automation(status=1)이면 → 옛 row 삭제 (이미 체크돼 있음)
 *       · 거기가 status=0(미체크)이면 → 옛 row 의 status/source/source_ref 를 새 row 로 이전 후 옛 row 삭제
 *   - 없으면 → 옛 row 의 check_date 를 새 날짜로 UPDATE
 *
 * 점수/코인 재계산은 마이그 끝에서 영향 멤버 전체 recalculateMemberScore 호출.
 * (코인은 멱등성이 보장되지 않으므로 추가 보정 필요 시 별도 처리 — 본 마이그는 손대지 않음)
 *
 * Usage:
 *   php migrate_cafe_assignment_date_7am_cutoff.php --dry-run
 *   php migrate_cafe_assignment_date_7am_cutoff.php --apply
 */

declare(strict_types=1);

require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/includes/cafe/cafe_ingest.php';
require_once __DIR__ . '/public_html/includes/bootcamp_functions.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['--dry-run', '--apply'], true)) {
    fwrite(STDERR, "Usage: php migrate_cafe_assignment_date_7am_cutoff.php --dry-run | --apply\n");
    exit(1);
}
$dryRun = ($mode === '--dry-run');

$db = getDB();

echo "═══════════════════════════════════════════════════\n";
echo "cafe assignment_date 7am cutoff 백필\n";
echo "mode: " . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n";
echo "DB:   " . ($db->query('SELECT DATABASE()')->fetchColumn()) . "\n";
echo "═══════════════════════════════════════════════════\n\n";

// ─── Phase 1: cafe_posts 영향 row 수집 ────────────────────────
$posts = $db->query("
    SELECT id, cafe_article_id, member_id, board_type, posted_at, assignment_date
    FROM cafe_posts
    WHERE posted_at IS NOT NULL
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$changedPosts = [];
foreach ($posts as $p) {
    $newDate = cafeAssignmentDateForPostedAt($p['posted_at']);
    if ($newDate !== $p['assignment_date']) {
        $p['new_assignment_date'] = $newDate;
        $changedPosts[] = $p;
    }
}

echo "cafe_posts 전체: " . count($posts) . "\n";
echo "  assignment_date 바뀜: " . count($changedPosts) . "\n\n";

// ─── Phase 2: mission_checks 영향 분류 ────────────────────────
$missionTypeCache = [];
function missionTypeIdCached(PDO $db, string $code, array &$cache): ?int {
    if (array_key_exists($code, $cache)) return $cache[$code];
    $st = $db->prepare("SELECT id FROM mission_types WHERE code = ?");
    $st->execute([$code]);
    $v = $st->fetchColumn();
    $cache[$code] = $v ? (int)$v : null;
    return $cache[$code];
}

$buckets = [
    'no_member_or_type'  => 0,        // saveCheck 호출 안 된 글 (회원 미매칭 등)
    'no_old_row'         => 0,        // 옛 row 가 없거나 source_ref 다름 (이미 다른 글이 덮음)
    'move'               => 0,        // 옛 row check_date UPDATE 로 이동
    'delete_old_keep_new'=> 0,        // 새 날짜에 manual/다른 cafe 가 이미 status=1 → 옛 row status=0
    'merge_into_new'     => 0,        // 새 날짜에 status=0 row 있음 → 옛 row 이전
];
$plan = []; // 실행할 액션 리스트
$affectedMembers = [];

foreach ($changedPosts as $p) {
    if (!$p['member_id'] || !$p['board_type']) {
        $buckets['no_member_or_type']++;
        continue;
    }
    $mtId = missionTypeIdCached($db, $p['board_type'], $missionTypeCache);
    if (!$mtId) {
        $buckets['no_member_or_type']++;
        continue;
    }
    $sourceRef = "cafe:{$p['cafe_article_id']}";

    // 옛 날짜 row (이 글이 만든 row 인지 확인)
    $st = $db->prepare("
        SELECT id, status, source, source_ref
        FROM member_mission_checks
        WHERE member_id = ? AND check_date = ? AND mission_type_id = ?
    ");
    $st->execute([$p['member_id'], $p['assignment_date'], $mtId]);
    $oldRow = $st->fetch(PDO::FETCH_ASSOC);

    if (!$oldRow || $oldRow['source'] !== 'automation' || $oldRow['source_ref'] !== $sourceRef) {
        $buckets['no_old_row']++;
        continue;
    }

    // 새 날짜 row
    $st = $db->prepare("
        SELECT id, status, source, source_ref
        FROM member_mission_checks
        WHERE member_id = ? AND check_date = ? AND mission_type_id = ?
    ");
    $st->execute([$p['member_id'], $p['new_assignment_date'], $mtId]);
    $newRow = $st->fetch(PDO::FETCH_ASSOC);

    if (!$newRow) {
        $plan[] = ['action' => 'move_row', 'old_row_id' => $oldRow['id'], 'new_date' => $p['new_assignment_date'], 'post' => $p];
        $buckets['move']++;
    } elseif ((int)$newRow['status'] === 1) {
        $plan[] = ['action' => 'delete_old', 'old_row_id' => $oldRow['id'], 'post' => $p];
        $buckets['delete_old_keep_new']++;
    } else {
        // 새 row status=0 → 거기에 이 글 정보를 이식
        $plan[] = ['action' => 'merge_into_new', 'old_row_id' => $oldRow['id'], 'new_row_id' => $newRow['id'], 'post' => $p];
        $buckets['merge_into_new']++;
    }
    $affectedMembers[(int)$p['member_id']] = true;
}

echo "─── mission_checks 영향 분류 ───\n";
foreach ($buckets as $k => $v) {
    printf("  %-22s %d\n", $k, $v);
}
echo "  영향 회원 수:           " . count($affectedMembers) . "\n\n";

// ─── Sample 출력 ────────────────────────────────────────
$sampleLimit = 5;
foreach (['move', 'delete_old_keep_new', 'merge_into_new'] as $action) {
    $samples = array_slice(array_values(array_filter($plan, fn($x) => $x['action'] === ($action === 'move' ? 'move_row' : ($action === 'delete_old_keep_new' ? 'delete_old' : 'merge_into_new')))), 0, $sampleLimit);
    if (empty($samples)) continue;
    echo "─── Sample: {$action} ───\n";
    foreach ($samples as $s) {
        $p = $s['post'];
        printf("  member_id=%d board=%s posted=%s  %s → %s\n",
            $p['member_id'], $p['board_type'], $p['posted_at'],
            $p['assignment_date'], $p['new_assignment_date']
        );
    }
    echo "\n";
}

if ($dryRun) {
    echo "═══ DRY-RUN END (실제 변경 없음) ═══\n";
    echo "실행: php " . basename(__FILE__) . " --apply\n";
    exit(0);
}

// ─── APPLY 모드 ───────────────────────────────────────────
echo "─── APPLY START ───\n";

// 스냅샷 백업
$ts = date('Ymd_His');
$snapshotDir = __DIR__ . '/tmp';
if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0755, true);
$snapPosts  = "{$snapshotDir}/snap_cafe_posts_{$ts}.sql";
$snapChecks = "{$snapshotDir}/snap_mission_checks_{$ts}.sql";

$creds = parse_ini_file(__DIR__ . '/.db_credentials');
$dbName = $creds['DB_NAME'] ?? null;
$dbUser = $creds['DB_USER'] ?? null;
$dbPass = $creds['DB_PASS'] ?? null;
if (!$dbName) {
    fwrite(STDERR, "ERROR: DB credentials not found\n");
    exit(2);
}
$mysqldump = '/usr/bin/mysqldump';
$cmdPosts = sprintf('%s --single-transaction -u%s -p%s %s cafe_posts > %s 2>/dev/null',
    escapeshellcmd($mysqldump), escapeshellarg($dbUser), escapeshellarg($dbPass),
    escapeshellarg($dbName), escapeshellarg($snapPosts));
$cmdChecks = sprintf('%s --single-transaction -u%s -p%s %s member_mission_checks > %s 2>/dev/null',
    escapeshellcmd($mysqldump), escapeshellarg($dbUser), escapeshellarg($dbPass),
    escapeshellarg($dbName), escapeshellarg($snapChecks));
echo "  스냅샷: cafe_posts → {$snapPosts}\n";
system($cmdPosts);
echo "  스냅샷: member_mission_checks → {$snapChecks}\n";
system($cmdChecks);

$db->beginTransaction();
try {
    $upd = $db->prepare("UPDATE cafe_posts SET assignment_date = ? WHERE id = ?");
    foreach ($changedPosts as $p) {
        $upd->execute([$p['new_assignment_date'], $p['id']]);
    }
    echo "  cafe_posts.assignment_date 업데이트: " . count($changedPosts) . " rows\n";

    $moveStmt   = $db->prepare("UPDATE member_mission_checks SET check_date = ?, updated_at = NOW() WHERE id = ?");
    $deleteStmt = $db->prepare("DELETE FROM member_mission_checks WHERE id = ?");
    $mergeStmt  = $db->prepare("
        UPDATE member_mission_checks
        SET status = 1, source = 'automation', source_ref = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $cMove = $cDel = $cMerge = 0;
    foreach ($plan as $act) {
        if ($act['action'] === 'move_row') {
            $moveStmt->execute([$act['new_date'], $act['old_row_id']]);
            $cMove++;
        } elseif ($act['action'] === 'delete_old') {
            $deleteStmt->execute([$act['old_row_id']]);
            $cDel++;
        } elseif ($act['action'] === 'merge_into_new') {
            $sourceRef = "cafe:{$act['post']['cafe_article_id']}";
            $mergeStmt->execute([$sourceRef, $act['new_row_id']]);
            $deleteStmt->execute([$act['old_row_id']]);
            $cMerge++;
        }
    }
    echo "  mission_checks: move={$cMove}, delete_old={$cDel}, merge={$cMerge}\n";

    $db->commit();
    echo "  COMMIT OK\n";
} catch (\Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "ERROR (rolled back): " . $e->getMessage() . "\n");
    exit(3);
}

// 영향 회원 점수 재계산
echo "  영향 회원 " . count($affectedMembers) . "명 점수 재계산...\n";
foreach (array_keys($affectedMembers) as $mid) {
    try { recalculateMemberScore($db, (int)$mid, null); } catch (\Throwable $e) { /* skip */ }
}
echo "  점수 재계산 DONE\n";

echo "═══ APPLY END ═══\n";
