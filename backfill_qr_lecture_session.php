<?php
/**
 * qr_sessions.lecture_session_id 가 NULL 인 row 를 3-tier cascade 로 소급 매칭.
 *
 * Usage:
 *   php backfill_qr_lecture_session.php --cohort=12 --dry-run
 *   php backfill_qr_lecture_session.php --cohort=12 --apply
 *
 * spec: docs/superpowers/specs/2026-05-18-qr-lecture-auto-match-design.md
 */
declare(strict_types=1);
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/api/services/qr_match.php';

$opts = getopt('', ['cohort:', 'dry-run', 'apply']);
$cohort = isset($opts['cohort']) ? (int)$opts['cohort'] : 0;
$isDryRun = array_key_exists('dry-run', $opts);
$isApply = array_key_exists('apply', $opts);

if ($cohort <= 0 || ($isDryRun === $isApply)) {
    fwrite(STDERR, "Usage: php backfill_qr_lecture_session.php --cohort=N (--dry-run | --apply)\n");
    exit(1);
}
$mode = $isApply ? 'APPLY' : 'DRY-RUN';

$db = getDB();
$dbName = $db->query('SELECT DATABASE()')->fetchColumn();

echo "═══════════════════════════════════════════════════\n";
echo "QR ↔ lecture 자동 매칭 백필\n";
echo "mode:   {$mode}\n";
echo "cohort: {$cohort}\n";
echo "DB:     {$dbName}\n";
echo "═══════════════════════════════════════════════════\n\n";

// ── 대상 row 조회 ─────────────────────────────────────────
$stmt = $db->prepare("
    SELECT qs.id, qs.admin_id, qs.cohort_id, qs.created_at,
           DATE(qs.created_at) AS at_date,
           TIME(qs.created_at) AS at_time,
           COALESCE(a.name, '(시스템)') AS admin_name
    FROM qr_sessions qs
    LEFT JOIN admins a ON a.id = qs.admin_id
    WHERE qs.cohort_id = ?
      AND qs.session_type != 'revival'
      AND qs.lecture_session_id IS NULL
      AND NOT EXISTS (SELECT 1 FROM study_sessions ss WHERE ss.qr_session_id = qs.id)
    ORDER BY qs.created_at ASC
");
$stmt->execute([$cohort]);
$targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$targets) {
    echo "대상 row 0건. 종료.\n";
    exit(0);
}
echo "대상 row: " . count($targets) . "건\n\n";

// ── Tier 별 매칭 시뮬레이션 ───────────────────────────────
$plan = [];           // qs_id => matched lecture_id|null
$tierCount = ['A' => 0, 'B' => 0, 'C' => 0, 'null' => 0];

foreach ($targets as $r) {
    $qsId = (int)$r['id'];
    $adminId = (int)$r['admin_id'];
    $adminName = $r['admin_name'];

    // 헬퍼는 cascade 통합 결과만 반환. Tier 식별 위해 각 Tier 를 개별 쿼리로 다시 시도.
    $tierA = null; $tierB = null; $tierC = null;

    if ($adminId > 0) {
        $sa = $db->prepare("
            SELECT id FROM lecture_sessions
            WHERE coach_admin_id = ? AND lecture_date = ? AND cohort_id = ? AND status='active'
            ORDER BY ABS(TIME_TO_SEC(TIMEDIFF(start_time, ?))) ASC LIMIT 1
        ");
        $sa->execute([$adminId, $r['at_date'], $r['cohort_id'], $r['at_time']]);
        $tierA = $sa->fetchColumn() ?: null;

        if (!$tierA) {
            $sb = $db->prepare("
                SELECT id FROM lecture_sessions
                WHERE coach_admin_id IN (
                    SELECT id FROM admins
                    WHERE name = (SELECT name FROM admins WHERE id = ?)
                      AND role IN ('coach','sub_coach','head','subhead1','subhead2')
                  )
                  AND lecture_date = ? AND cohort_id = ? AND status='active'
                ORDER BY ABS(TIME_TO_SEC(TIMEDIFF(start_time, ?))) ASC LIMIT 1
            ");
            $sb->execute([$adminId, $r['at_date'], $r['cohort_id'], $r['at_time']]);
            $tierB = $sb->fetchColumn() ?: null;
        }
    }

    if (!$tierA && !$tierB) {
        $sc = $db->prepare("
            SELECT id FROM lecture_sessions
            WHERE lecture_date = ? AND cohort_id = ? AND status='active'
              AND ABS(TIME_TO_SEC(TIMEDIFF(start_time, ?))) / 60 <= 60
        ");
        $sc->execute([$r['at_date'], $r['cohort_id'], $r['at_time']]);
        $candidates = $sc->fetchAll(PDO::FETCH_COLUMN);
        if (count($candidates) === 1) {
            $tierC = (int)$candidates[0];
        }
    }

    $matched = $tierA ?: $tierB ?: $tierC ?: null;
    $plan[$qsId] = $matched;

    $tag = $tierA ? 'Tier A' : ($tierB ? 'Tier B' : ($tierC ? 'Tier C' : '없음'));
    if ($tierA) $tierCount['A']++;
    elseif ($tierB) $tierCount['B']++;
    elseif ($tierC) $tierCount['C']++;
    else $tierCount['null']++;

    $matchedStr = $matched ? "lecture #{$matched}" : 'NULL 유지';
    printf("QR #%d  %s  %s(%d)  → %s: %s\n",
        $qsId, $r['created_at'], $adminName, $adminId, $tag, $matchedStr);
}

echo "\n";
echo "요약: " . count($targets) . "건 검사 → "
    . ($tierCount['A'] + $tierCount['B'] + $tierCount['C']) . "건 매칭, "
    . $tierCount['null'] . "건 NULL 유지\n";
echo "  Tier A 매칭: {$tierCount['A']}건\n";
echo "  Tier B 매칭: {$tierCount['B']}건\n";
echo "  Tier C 매칭: {$tierCount['C']}건\n";

if ($isDryRun) {
    echo "\n[DRY-RUN] 변경 없음. apply 하려면 --apply 로 재실행.\n";
    exit(0);
}

// ── APPLY 모드 ────────────────────────────────────────────
$toUpdate = array_filter($plan, fn($v) => $v !== null);
if (!$toUpdate) {
    echo "\n[APPLY] 매칭된 row 없음. 변경 없음.\n";
    exit(0);
}

// 1) 백업 (.db_credentials 직접 파싱, parse_ini_file 의 quote 해석 피하기)
$ts = date('Ymd_His');
$backupPath = "/tmp/qr_sessions_backup_{$ts}.sql";
$creds = [];
foreach (file(__DIR__ . '/.db_credentials', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_contains($line, '=')) {
        [$k, $v] = explode('=', $line, 2);
        $creds[trim($k)] = trim($v);
    }
}
$dumpCmd = sprintf(
    "mysqldump -h %s -u %s -p%s %s qr_sessions > %s 2>/tmp/qr_dump_err_{$ts}.log",
    escapeshellarg($creds['DB_HOST']),
    escapeshellarg($creds['DB_USER']),
    escapeshellarg($creds['DB_PASS']),
    escapeshellarg($creds['DB_NAME']),
    escapeshellarg($backupPath)
);
exec($dumpCmd, $out, $rc);
if ($rc !== 0 || !file_exists($backupPath) || filesize($backupPath) < 100) {
    fwrite(STDERR, "ERROR: 백업 실패 (rc={$rc}). /tmp/qr_dump_err_{$ts}.log 확인.\n");
    exit(2);
}
echo "\n백업: {$backupPath} (" . filesize($backupPath) . " bytes)\n";

// 2) 트랜잭션 UPDATE
$db->beginTransaction();
$updated = 0;
try {
    $upd = $db->prepare("UPDATE qr_sessions SET lecture_session_id = ?
                         WHERE id = ? AND lecture_session_id IS NULL");
    foreach ($toUpdate as $qsId => $lectureId) {
        $upd->execute([$lectureId, $qsId]);
        if ($upd->rowCount() > 0) $updated++;
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "ERROR: UPDATE 실패, 롤백됨: " . $e->getMessage() . "\n");
    exit(3);
}

// 3) 백필된 id 목록 저장 (롤백용)
$logPath = "/tmp/qr_sessions_backfilled_{$ts}.txt";
file_put_contents($logPath, implode("\n", array_keys($toUpdate)));
echo "\nAPPLY 완료: {$updated}건 UPDATE.\n";
echo "백필 id 목록: {$logPath}\n";
echo "\n롤백 SQL 예시:\n";
echo "  UPDATE qr_sessions SET lecture_session_id = NULL\n";
echo "  WHERE id IN (" . implode(',', array_keys($toUpdate)) . ");\n";
exit(0);
