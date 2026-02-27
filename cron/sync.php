<?php
/**
 * 소리튠 부트캠프(BOOT) - 구글시트 → DB 동기화 크론
 *
 * CLI: php /var/www/html/_______site_SORITUNECOM_BOOT/cron/sync.php
 * 웹:  https://boot.soritune.com/api/system.php?action=trigger_sync&key=boot_sync_2026
 * 크론: 매 10분마다 실행
 */

$isCli = (php_sapi_name() === 'cli');

// 웹 호출 시 보안키 확인
if (!$isCli) {
    $key = $_GET['key'] ?? '';
    if ($key !== 'boot_sync_2026') {
        http_response_code(403);
        die('Unauthorized');
    }
}

// 동시 실행 방지
$lockFile = sys_get_temp_dir() . '/boot-gsheet-sync.lock';
$lockFp = fopen($lockFile, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    syncLog('이미 동기화 실행 중 - 종료');
    exit(0);
}

require_once __DIR__ . '/GoogleSheets.php';

// DB 연결
$credFile = dirname(__DIR__) . '/.db_credentials';
$lines = file($credFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$creds = [];
foreach ($lines as $line) {
    if (str_contains($line, '=')) {
        [$k, $v] = explode('=', $line, 2);
        $creds[trim($k)] = trim($v);
    }
}

$db = new PDO(
    "mysql:host={$creds['DB_HOST']};dbname={$creds['DB_NAME']};charset=utf8mb4",
    $creds['DB_USER'], $creds['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$db->exec("SET time_zone = '+09:00'");

date_default_timezone_set('Asia/Seoul');

function syncLog(string $msg, string $level = 'INFO'): void {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] [{$level}] {$msg}\n";
}

function toBool($v): int {
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_string($v)) {
        return in_array(strtolower(trim($v)), ['true','1','yes','y','o','예','네','완료','참여']) ? 1 : 0;
    }
    return $v ? 1 : 0;
}

function toInt($v): int {
    if ($v === null || $v === '') return 0;
    return intval(preg_replace('/[^0-9\-]/', '', $v));
}

function toDate($v): ?string {
    if (empty($v)) return null;
    $v = trim($v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
}

syncLog('=== 구글시트 동기화 시작 ===');

try {
    $gs = new GoogleSheets();
    syncLog('Google Sheets API 연결 성공');

    // 활성 기수 조회
    $cohorts = $db->query("SELECT * FROM boot_cohorts WHERE stage = 'active' AND is_active = 1 AND google_sheet_id IS NOT NULL ORDER BY id")->fetchAll();

    if (empty($cohorts)) {
        syncLog('동기화할 활성 기수 없음', 'WARN');
        goto cleanup;
    }

    foreach ($cohorts as $cohort) {
        $cid = $cohort['id'];
        $label = "{$cohort['name']} (ID:{$cid})";
        $sheetId = $cohort['google_sheet_id'];

        syncLog("--- [{$label}] 시작 ---");

        // 시트 탭 목록 확인
        try {
            $tabs = $gs->getSheets($sheetId);
            syncLog("[{$label}] 시트 탭: " . implode(', ', $tabs));
        } catch (Exception $e) {
            syncLog("[{$label}] 시트 접근 실패: " . $e->getMessage(), 'ERROR');
            continue;
        }

        // ── 1. 회원 동기화 (탭: "회원 목록") ──
        if (in_array('회원 목록', $tabs)) {
            try {
                $data = $gs->getSheetData($sheetId, '회원 목록');
                if (count($data) > 1) {
                    $headers = array_shift($data);
                    $hmap = array_flip($headers);
                    $gv = function($row, $key, $def = '') use ($hmap) {
                        $i = $hmap[$key] ?? null;
                        return ($i !== null && isset($row[$i])) ? trim($row[$i]) : $def;
                    };

                    $synced = 0;
                    foreach ($data as $row) {
                        $sid = $gv($row, '아이디');
                        if (empty($sid)) continue;

                        $name = $gv($row, '주문자명') ?: $gv($row, '이름_입학원서') ?: $gv($row, '이름');
                        if (empty($name)) continue;

                        $phone = $gv($row, '주문자 휴대전화') ?: $gv($row, '전화번호');
                        $phoneClean = preg_replace('/[^0-9]/', '', $phone);
                        $phoneLast4 = $phoneClean ? substr($phoneClean, -4) : null;
                        $teamName = $gv($row, '조편성') ?: $gv($row, '조');

                        // team_id 찾기
                        $teamId = null;
                        if ($teamName) {
                            $stmt = $db->prepare('SELECT id FROM boot_teams WHERE cohort_id = ? AND team_name = ?');
                            $stmt->execute([$cid, $teamName]);
                            $t = $stmt->fetch();
                            if ($t) $teamId = $t['id'];
                        }

                        $db->prepare('
                            INSERT INTO boot_members (cohort_id, soritune_id, payment_id, name, email, phone, phone_last4,
                                zoom_nickname, cafe_nickname, birth_date, team_id, current_score)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE
                                name = VALUES(name), email = COALESCE(VALUES(email), email),
                                phone = COALESCE(VALUES(phone), phone), phone_last4 = COALESCE(VALUES(phone_last4), phone_last4),
                                zoom_nickname = COALESCE(VALUES(zoom_nickname), zoom_nickname),
                                cafe_nickname = COALESCE(VALUES(cafe_nickname), cafe_nickname),
                                team_id = COALESCE(VALUES(team_id), team_id)
                        ')->execute([
                            $cid, $sid, $sid, $name,
                            $gv($row, '주문자 이메일') ?: $gv($row, '이메일') ?: null,
                            $phone ?: null, $phoneLast4,
                            $gv($row, '닉네임') ?: $gv($row, 'Zoom닉네임') ?: null,
                            $gv($row, '카페별명_입학원서') ?: $gv($row, '카페닉네임') ?: null,
                            $gv($row, '생년월일') ?: null,
                            $teamId,
                            $cohort['initial_score'] ?? 100,
                        ]);
                        $synced++;
                    }
                    syncLog("[{$label}] 회원 동기화: {$synced}명");
                }
            } catch (Exception $e) {
                syncLog("[{$label}] 회원 동기화 실패: " . $e->getMessage(), 'ERROR');
            }
        }

        // ── 2. 조 동기화 (탭: "조 목록") ──
        if (in_array('조 목록', $tabs)) {
            try {
                $data = $gs->getSheetData($sheetId, '조 목록');
                if (count($data) > 1) {
                    $headers = array_shift($data);
                    $hmap = array_flip($headers);
                    $gv = function($row, $key, $def = '') use ($hmap) {
                        $i = $hmap[$key] ?? null;
                        return ($i !== null && isset($row[$i])) ? trim($row[$i]) : $def;
                    };

                    $synced = 0;
                    foreach ($data as $row) {
                        $teamName = $gv($row, '조 이름') ?: $gv($row, '조이름');
                        if (empty($teamName)) continue;

                        // 조장 ID 찾기
                        $leaderSid = $gv($row, '대장 아이디') ?: $gv($row, '조장 아이디');
                        $leaderId = null;
                        if ($leaderSid) {
                            $stmt = $db->prepare('SELECT id FROM boot_members WHERE cohort_id = ? AND soritune_id = ?');
                            $stmt->execute([$cid, $leaderSid]);
                            $f = $stmt->fetch();
                            if ($f) $leaderId = $f['id'];
                        }
                        if (!$leaderId) {
                            $leaderName = $gv($row, '대장 이름') ?: $gv($row, '조장 이름');
                            if ($leaderName) {
                                $stmt = $db->prepare('SELECT id FROM boot_members WHERE cohort_id = ? AND name = ? LIMIT 1');
                                $stmt->execute([$cid, $leaderName]);
                                $f = $stmt->fetch();
                                if ($f) $leaderId = $f['id'];
                            }
                        }

                        // 부조장
                        $subSid = $gv($row, '부대장 아이디') ?: $gv($row, '부조장 아이디');
                        $subLeaderId = null;
                        if ($subSid) {
                            $stmt = $db->prepare('SELECT id FROM boot_members WHERE cohort_id = ? AND soritune_id = ?');
                            $stmt->execute([$cid, $subSid]);
                            $f = $stmt->fetch();
                            if ($f) $subLeaderId = $f['id'];
                        }

                        $stmt = $db->prepare('SELECT id FROM boot_teams WHERE cohort_id = ? AND team_name = ?');
                        $stmt->execute([$cid, $teamName]);
                        $existing = $stmt->fetch();

                        if ($existing) {
                            $db->prepare('UPDATE boot_teams SET leader_id = COALESCE(?, leader_id), sub_leader_id = COALESCE(?, sub_leader_id) WHERE id = ?')
                                ->execute([$leaderId, $subLeaderId, $existing['id']]);
                        } else {
                            $db->prepare('INSERT INTO boot_teams (cohort_id, team_name, leader_id, sub_leader_id) VALUES (?, ?, ?, ?)')
                                ->execute([$cid, $teamName, $leaderId, $subLeaderId]);
                        }
                        $synced++;
                    }
                    syncLog("[{$label}] 조 동기화: {$synced}개");
                }
            } catch (Exception $e) {
                syncLog("[{$label}] 조 동기화 실패: " . $e->getMessage(), 'ERROR');
            }
        }

        // ── 3. 과제 동기화 (탭: "과제 체크리스트") ──
        if (in_array('과제 체크리스트', $tabs)) {
            try {
                $data = $gs->getSheetData($sheetId, '과제 체크리스트');
                if (count($data) > 1) {
                    $headers = array_shift($data);
                    $hmap = array_flip($headers);
                    $gv = function($row, $key, $def = '') use ($hmap) {
                        $i = $hmap[$key] ?? null;
                        return ($i !== null && isset($row[$i])) ? trim($row[$i]) : $def;
                    };

                    // 회원 soritune_id → member_id 캐시
                    $stmt = $db->prepare('SELECT id, soritune_id FROM boot_members WHERE cohort_id = ?');
                    $stmt->execute([$cid]);
                    $memberCache = [];
                    foreach ($stmt->fetchAll() as $m) {
                        $memberCache[$m['soritune_id']] = $m['id'];
                    }

                    $synced = 0;
                    $batch = [];
                    foreach ($data as $row) {
                        $sid = $gv($row, '아이디');
                        $dateStr = $gv($row, '날짜');
                        if (empty($sid)) continue;
                        $taskDate = toDate($dateStr);
                        if (!$taskDate) continue;

                        $memberId = $memberCache[$sid] ?? null;
                        if (!$memberId) continue;

                        $batch[] = [
                            $memberId, $taskDate,
                            toBool($gv($row, '줌 강의 or 데일리 미션', false)),
                            toBool($gv($row, '내맛33미션', false)),
                            toBool($gv($row, '말까미션', false)),
                            toBool($gv($row, '하멈말', false)),
                            0, // recording
                            toBool($gv($row, '복습 스터디 참여', false)),
                            toInt($gv($row, '일별 차감 점수', 0)),
                            toInt($gv($row, '합산 점수', 0)),
                            1, // processed
                        ];

                        if (count($batch) >= 500) {
                            flushAssignments($db, $batch);
                            $synced += count($batch);
                            $batch = [];
                        }
                    }
                    if (!empty($batch)) {
                        flushAssignments($db, $batch);
                        $synced += count($batch);
                    }
                    syncLog("[{$label}] 과제 동기화: {$synced}건");
                }
            } catch (Exception $e) {
                syncLog("[{$label}] 과제 동기화 실패: " . $e->getMessage(), 'ERROR');
            }
        }

        // ── 4. 팀 통계 재계산 ──
        $db->prepare("
            UPDATE boot_teams t SET
                member_count = (SELECT COUNT(*) FROM boot_members m WHERE m.team_id = t.id AND m.is_active = 1),
                average_score = COALESCE((SELECT AVG(m.current_score) FROM boot_members m WHERE m.team_id = t.id AND m.is_active = 1 AND m.status = 'active'), 0)
            WHERE t.cohort_id = ?
        ")->execute([$cid]);

        // ── 5. 회원 점수 업데이트 (과제 합산 점수에서 최신 값 반영) ──
        $db->prepare("
            UPDATE boot_members m SET
                current_score = COALESCE(
                    (SELECT dt.cumulative_score FROM boot_daily_tasks dt
                     WHERE dt.member_id = m.id ORDER BY dt.task_date DESC LIMIT 1),
                    m.current_score
                )
            WHERE m.cohort_id = ? AND m.is_active = 1
        ")->execute([$cid]);

        syncLog("[{$label}] --- 완료 ---");
    }

} catch (Exception $e) {
    syncLog('치명적 오류: ' . $e->getMessage(), 'FATAL');
}

cleanup:
flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink($lockFile);
syncLog('=== 구글시트 동기화 완료 ===');

// 웹 호출 시 JSON 응답
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'message' => '동기화 완료', 'time' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
}

function flushAssignments(PDO $db, array $batch): void {
    $ph = [];
    $vals = [];
    foreach ($batch as $row) {
        $ph[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        foreach ($row as $v) $vals[] = $v;
    }
    $sql = 'INSERT INTO boot_daily_tasks
        (member_id, task_date, zoom_completed, cafe_completed, malkka_completed, hamummal_completed,
         recording_completed, study_participated, deduction_amount, cumulative_score, processed)
        VALUES ' . implode(',', $ph) . '
        ON DUPLICATE KEY UPDATE
            zoom_completed = VALUES(zoom_completed),
            cafe_completed = VALUES(cafe_completed),
            malkka_completed = VALUES(malkka_completed),
            hamummal_completed = VALUES(hamummal_completed),
            study_participated = VALUES(study_participated),
            deduction_amount = VALUES(deduction_amount),
            cumulative_score = VALUES(cumulative_score)';
    $db->prepare($sql)->execute($vals);
}
