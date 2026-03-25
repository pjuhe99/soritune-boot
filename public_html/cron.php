<?php
/**
 * boot.soritune.com - Cron Tasks
 * Usage: php cron.php <command>
 *
 * Commands:
 *   init_daily_checks  - 매일 06:00 실행. 활성 멤버에게 필수 미션 미완료(status=0) 레코드 생성
 *   run_all            - 기존 일괄 크론 (향후 확장용)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/config.php';

$command = $argv[1] ?? '';

switch ($command) {
    case 'init_daily_checks':
        initDailyChecks();
        break;
    case 'backfill_checks':
        backfillChecks();
        break;
    case 'run_all':
        // 향후 확장용
        cronLog('run_all: no tasks configured yet');
        break;
    default:
        echo "Usage: php cron.php <command>\n";
        echo "  init_daily_checks  매일 06:00 필수 미션 미완료 레코드 생성\n";
        echo "  backfill_checks    과거 날짜 미션 레코드 소급 생성 (1회성)\n";
        echo "  run_all            일괄 크론\n";
        exit(1);
}

// ══════════════════════════════════════════════════════════════
// init_daily_checks: 활성 멤버 전원에게 오늘의 필수 미션 status=0 생성
// ══════════════════════════════════════════════════════════════

function initDailyChecks() {
    $db = getDB();
    $today = date('Y-m-d');
    $dow = (int)date('N'); // 1=Mon ... 7=Sun

    cronLog("init_daily_checks START: date={$today}, dow={$dow}");

    // 필수 미션 코드 (매일 + 요일별)
    $dailyCodes = ['zoom_daily', 'daily_mission', 'inner33'];
    $mondayCodes = ['speak_mission'];

    $requiredCodes = $dailyCodes;
    if ($dow === 1) {
        $requiredCodes = array_merge($requiredCodes, $mondayCodes);
    }

    // 미션 코드 → ID 매핑
    $ph = implode(',', array_fill(0, count($requiredCodes), '?'));
    $stmt = $db->prepare("SELECT id, code FROM mission_types WHERE code IN ({$ph}) AND is_active = 1");
    $stmt->execute($requiredCodes);
    $missionMap = [];
    foreach ($stmt->fetchAll() as $mt) {
        $missionMap[$mt['code']] = (int)$mt['id'];
    }

    if (empty($missionMap)) {
        cronLog("ERROR: no mission_types found for codes: " . implode(',', $requiredCodes));
        return;
    }

    // 활성 멤버 목록 (진행 중인 코호트 소속)
    $members = $db->query("
        SELECT bm.id AS member_id, bm.cohort_id, bm.group_id
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.is_active = 1
          AND bm.member_status = 'active'
          AND c.start_date <= '{$today}'
          AND c.end_date >= '{$today}'
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($members)) {
        cronLog("No active members in current cohorts. Skipping.");
        return;
    }

    cronLog("Active members: " . count($members) . ", Missions: " . implode(',', array_keys($missionMap)));

    // INSERT IGNORE: 이미 레코드가 있으면 무시 (기존 완료 상태 보존)
    $insertStmt = $db->prepare("
        INSERT IGNORE INTO member_mission_checks
            (member_id, cohort_id, group_id, check_date, mission_type_id, status, source, source_ref)
        VALUES (?, ?, ?, ?, ?, 0, 'automation', 'cron:init_daily')
    ");

    $created = 0;
    foreach ($members as $m) {
        foreach ($missionMap as $code => $typeId) {
            $insertStmt->execute([
                $m['member_id'],
                $m['cohort_id'],
                $m['group_id'],
                $today,
                $typeId,
            ]);
            if ($insertStmt->rowCount() > 0) {
                $created++;
            }
        }
    }

    $total = count($members) * count($missionMap);
    cronLog("init_daily_checks DONE: {$created}/{$total} records created (rest already existed)");
}

// ══════════════════════════════════════════════════════════════
// backfill_checks: cohort 시작일~어제까지 과거 날짜 미션 레코드 소급 생성 (1회성)
// ══════════════════════════════════════════════════════════════

function backfillChecks() {
    $db = getDB();
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    cronLog("backfill_checks START: backfill up to {$yesterday}");

    // 필수 미션 코드 → ID 매핑
    $allCodes = ['zoom_daily', 'daily_mission', 'inner33', 'speak_mission'];
    $ph = implode(',', array_fill(0, count($allCodes), '?'));
    $stmt = $db->prepare("SELECT id, code FROM mission_types WHERE code IN ({$ph}) AND is_active = 1");
    $stmt->execute($allCodes);
    $missionMap = [];
    foreach ($stmt->fetchAll() as $mt) {
        $missionMap[$mt['code']] = (int)$mt['id'];
    }

    $dailyCodes = ['zoom_daily', 'daily_mission', 'inner33'];
    $mondayCodes = ['speak_mission'];

    // 활성 멤버 + cohort 기간 조회
    $members = $db->query("
        SELECT bm.id AS member_id, bm.cohort_id, bm.group_id,
               c.start_date, c.end_date
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.is_active = 1
          AND bm.member_status = 'active'
          AND c.end_date >= '{$yesterday}'
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($members)) {
        cronLog("No active members found. Skipping.");
        return;
    }

    cronLog("Active members: " . count($members));

    $insertStmt = $db->prepare("
        INSERT IGNORE INTO member_mission_checks
            (member_id, cohort_id, group_id, check_date, mission_type_id, status, source, source_ref)
        VALUES (?, ?, ?, ?, ?, 0, 'automation', 'cron:backfill')
    ");

    $created = 0;
    $total = 0;

    foreach ($members as $m) {
        $start = $m['start_date'];
        $end = min($m['end_date'], $yesterday);

        $current = $start;
        while ($current <= $end) {
            $dow = (int)date('N', strtotime($current)); // 1=Mon ... 7=Sun

            // 매일 미션
            foreach ($dailyCodes as $code) {
                if (isset($missionMap[$code])) {
                    $insertStmt->execute([$m['member_id'], $m['cohort_id'], $m['group_id'], $current, $missionMap[$code]]);
                    if ($insertStmt->rowCount() > 0) $created++;
                    $total++;
                }
            }

            // 월요일 미션
            if ($dow === 1) {
                foreach ($mondayCodes as $code) {
                    if (isset($missionMap[$code])) {
                        $insertStmt->execute([$m['member_id'], $m['cohort_id'], $m['group_id'], $current, $missionMap[$code]]);
                        if ($insertStmt->rowCount() > 0) $created++;
                        $total++;
                    }
                }
            }

            $current = date('Y-m-d', strtotime($current . ' +1 day'));
        }
    }

    cronLog("backfill_checks DONE: {$created}/{$total} records created (rest already existed)");
}

// ══════════════════════════════════════════════════════════════
// Logging
// ══════════════════════════════════════════════════════════════

function cronLog($msg) {
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents($logDir . '/cron.log', $line, FILE_APPEND);
    echo $line; // CLI 출력
}
