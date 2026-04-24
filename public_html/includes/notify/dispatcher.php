<?php
/**
 * boot.soritune.com - Notify 디스패처
 * 핵심: notifyRunScenario() — 스케줄/수동/재시도 모두 같은 진입점.
 */

require_once __DIR__ . '/notify_functions.php';
require_once __DIR__ . '/scenario_registry.php';
require_once __DIR__ . '/source_google_sheet.php';
require_once __DIR__ . '/solapi_client.php';

/**
 * 디스패처 진입점: cron('* * * * *')에서 1회 실행.
 * - flock 보조 락
 * - scenarios 등록 + state UPSERT
 * - is_active=1 + cron 매칭 시나리오에 대해 runScenario 호출
 */
function notifyDispatch(int $now = null): void {
    $now = $now ?? time();
    $lockFile = '/tmp/notify_dispatch.lock';
    $fp = fopen($lockFile, 'c');
    if ($fp === false) {
        error_log('notify_dispatch: 락 파일 열기 실패');
        return;
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return;
    }

    try {
        $db = getDB();
        $scenarios = notifyLoadScenarios();
        notifyEnsureScenarioStates($db, $scenarios);

        $stmt = $db->query("SELECT scenario_key FROM notify_scenario_state WHERE is_active = 1");
        $activeKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($activeKeys as $key) {
            if (!isset($scenarios[$key])) continue;
            $def = $scenarios[$key];
            if (!notifyCronMatches((string)$def['schedule'], $now)) continue;
            try {
                notifyRunScenario($db, $def, 'schedule');
            } catch (Throwable $e) {
                error_log("notify scenario '{$key}' 예외: " . $e->getMessage());
            }
        }

        $db->exec("DELETE FROM notify_preview WHERE expires_at < NOW() - INTERVAL 1 DAY");
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * 시나리오 1회 실행. 모든 트리거(스케줄/수동/재시도)의 공통 진입.
 */
function notifyRunScenario(
    PDO $db,
    array $def,
    string $trigger,
    ?string $triggeredBy = null,
    ?bool $dryRun = null,
    ?array $rowKeysFilter = null
): ?int {
    $key = (string)$def['key'];
    $keys = solapiLoadKeys();
    if ($dryRun === null) {
        $dryRun = (bool)($keys['dry_run_default'] ?? false);
    }

    // 1) 시나리오별 락 claim
    $claim = $db->prepare("
        UPDATE notify_scenario_state
           SET is_running = 1, running_since = NOW()
         WHERE scenario_key = ?
           AND (is_running = 0 OR running_since < NOW() - INTERVAL 10 MINUTE)
    ");
    $claim->execute([$key]);
    if ($claim->rowCount() === 0) {
        return null;
    }

    $batchId = null;
    try {
        // 2) 배치 INSERT
        $ins = $db->prepare("
            INSERT INTO notify_batch
              (scenario_key, trigger_type, triggered_by, started_at, dry_run, status, target_count)
            VALUES (?, ?, ?, NOW(), ?, 'running', 0)
        ");
        $ins->execute([$key, $trigger, $triggeredBy, (int)$dryRun]);
        $batchId = (int)$db->lastInsertId();

        // 3) source 어댑터 호출
        try {
            $rows = notifyFetchRows($def);
        } catch (Throwable $e) {
            notifyFinalizeBatch($db, $batchId, [
                'status' => 'failed',
                'error_message' => 'source: ' . $e->getMessage(),
            ]);
            notifyUpdateState($db, $key, $batchId, 'failed');
            return $batchId;
        }

        if ($rowKeysFilter !== null) {
            $set = array_flip($rowKeysFilter);
            $rows = array_values(array_filter($rows, fn($r) => isset($set[$r['row_key']])));
        }

        $db->prepare("UPDATE notify_batch SET target_count = ? WHERE id = ?")
           ->execute([count($rows), $batchId]);

        if (empty($rows)) {
            notifyFinalizeBatch($db, $batchId, [
                'status' => 'no_targets',
                'sent_count' => 0, 'failed_count' => 0, 'unknown_count' => 0, 'skipped_count' => 0,
            ]);
            notifyUpdateState($db, $key, $batchId, 'no_targets');
            return $batchId;
        }

        // 4) 메시지 큐잉 + 정책 체크
        $queued  = [];
        $skipped = 0;

        $insMsg = $db->prepare("
            INSERT INTO notify_message
              (batch_id, scenario_key, row_key, phone, name, template_id, rendered_text,
               channel_used, status, skip_reason, processed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'none', ?, ?, ?)
        ");

        $tpl = $def['template'];
        $templateId = (string)$tpl['templateId'];
        $variables  = (array)$tpl['variables'];
        $pfId = (string)($tpl['pfId_override'] ?? $keys['defaultPfId'] ?? '');
        $from = (string)($keys['defaultFrom'] ?? '');

        foreach ($rows as $row) {
            $rendered = notifyRenderVariables($variables, $row['columns'] ?? []);
            $renderedText = notifyComposeRenderedText($rendered);

            $phoneNorm = notifyNormalizePhone($row['phone'] ?? '');
            if ($phoneNorm === null) {
                $insMsg->execute([
                    $batchId, $key, $row['row_key'], (string)($row['phone'] ?? ''),
                    $row['name'] ?? null, $templateId, $renderedText,
                    'skipped', 'phone_invalid', date('Y-m-d H:i:s'),
                ]);
                $skipped++;
                continue;
            }

            // 쿨다운 (sent + unknown)
            $cd = $db->prepare("
                SELECT MAX(processed_at) FROM notify_message
                 WHERE scenario_key = ? AND phone = ?
                   AND status IN ('sent','unknown')
                   AND processed_at >= NOW() - INTERVAL ? HOUR
            ");
            $cd->execute([$key, $phoneNorm, (int)$def['cooldown_hours']]);
            if ($cd->fetchColumn()) {
                $insMsg->execute([
                    $batchId, $key, $row['row_key'], $phoneNorm,
                    $row['name'] ?? null, $templateId, $renderedText,
                    'skipped', 'cooldown', date('Y-m-d H:i:s'),
                ]);
                $skipped++;
                continue;
            }

            // 최대횟수 (sent + unknown 만 카운트)
            $mx = $db->prepare("
                SELECT COUNT(*) FROM notify_message
                 WHERE scenario_key = ? AND phone = ? AND status IN ('sent','unknown')
            ");
            $mx->execute([$key, $phoneNorm]);
            if ((int)$mx->fetchColumn() >= (int)$def['max_attempts']) {
                $insMsg->execute([
                    $batchId, $key, $row['row_key'], $phoneNorm,
                    $row['name'] ?? null, $templateId, $renderedText,
                    'skipped', 'max_attempts', date('Y-m-d H:i:s'),
                ]);
                $skipped++;
                continue;
            }

            // queued 기록
            $insQ = $db->prepare("
                INSERT INTO notify_message
                  (batch_id, scenario_key, row_key, phone, name, template_id,
                   rendered_text, channel_used, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'none', 'queued')
            ");
            $insQ->execute([
                $batchId, $key, $row['row_key'], $phoneNorm,
                $row['name'] ?? null, $templateId, $renderedText,
            ]);
            $msgId = (int)$db->lastInsertId();

            $queued[] = [
                'msg_id'  => $msgId,
                'phone'   => $phoneNorm,
                'payload' => solapiBuildAlimtalkPayload($phoneNorm, $from, $pfId, $templateId, $rendered),
            ];
        }

        // 5) 발송 (DRY_RUN 분기)
        $sent = 0; $failed = 0; $unknown = 0;
        if ($dryRun) {
            $upd = $db->prepare("
                UPDATE notify_message
                   SET status='dry_run', channel_used='none', processed_at=NOW()
                 WHERE id = ?
            ");
            foreach ($queued as $q) $upd->execute([$q['msg_id']]);
        } elseif (!empty($queued)) {
            $messages = array_column($queued, 'payload');
            $resp = solapiSendMany($messages);
            $statuses = notifyMapSolapiResponse($resp, $queued);
            foreach ($statuses as $msgId => $info) {
                $db->prepare("
                    UPDATE notify_message
                       SET status = ?, channel_used = ?, sent_at = ?,
                           processed_at = NOW(),
                           solapi_message_id = ?, fail_reason = ?
                     WHERE id = ?
                ")->execute([
                    $info['status'],
                    $info['channel_used'],
                    $info['sent_at'],
                    $info['solapi_message_id'],
                    $info['fail_reason'],
                    $msgId,
                ]);
                if     ($info['status'] === 'sent')    $sent++;
                elseif ($info['status'] === 'failed')  $failed++;
                elseif ($info['status'] === 'unknown') $unknown++;
            }
        }

        // 6) 배치 finalize
        $finalStatus = notifyDecideBatchStatus(
            target: count($rows),
            sent:   $dryRun ? count($queued) : $sent,
            failed: $failed,
            unknown:$unknown
        );
        if ($dryRun) {
            $finalStatus = count($queued) > 0 ? 'completed' : 'no_targets';
            $sentCount = 0;
        } else {
            $sentCount = $sent;
        }

        notifyFinalizeBatch($db, $batchId, [
            'status' => $finalStatus,
            'sent_count' => $sentCount,
            'failed_count' => $failed,
            'unknown_count' => $unknown,
            'skipped_count' => $skipped,
        ]);
        notifyUpdateState($db, $key, $batchId, $finalStatus);

        return $batchId;

    } catch (Throwable $e) {
        if ($batchId) {
            notifyFinalizeBatch($db, $batchId, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            notifyUpdateState($db, $key, $batchId, 'failed');
        }
        throw $e;
    } finally {
        $db->prepare("UPDATE notify_scenario_state SET is_running = 0, running_since = NULL WHERE scenario_key = ?")
           ->execute([$key]);
    }
}

/** source 타입 분기 (현재는 google_sheet만). 미래에 db_query 추가. */
function notifyFetchRows(array $def): array {
    $type = $def['source']['type'] ?? '';
    return match ($type) {
        'google_sheet' => notifySourceGoogleSheet($def['source']),
        default => throw new RuntimeException("미지원 source.type: '{$type}'"),
    };
}

/** 치환된 변수만으로 본문 미리보기 문자열 합성 (감사·UI용) */
function notifyComposeRenderedText(array $rendered): string {
    return json_encode($rendered, JSON_UNESCAPED_UNICODE);
}

/**
 * 솔라피 응답을 큐 메시지에 매핑.
 * - HTTP timeout/5xx → 모든 메시지 unknown
 * - HTTP 4xx → 모든 메시지 failed (응답 본문 fail_reason)
 * - HTTP 2xx → parsed에서 messageId 매칭 시도. 매칭 실패한 건은 unknown.
 */
function notifyMapSolapiResponse(array $resp, array $queued): array {
    $result = [];
    $now = date('Y-m-d H:i:s');

    if (!$resp['ok']) {
        $isUnknown = ($resp['http_code'] === 0 || $resp['http_code'] >= 500);
        foreach ($queued as $q) {
            $result[$q['msg_id']] = [
                'status'            => $isUnknown ? 'unknown' : 'failed',
                'channel_used'      => 'none',
                'sent_at'           => null,
                'solapi_message_id' => null,
                'fail_reason'       => substr((string)$resp['body'], 0, 1000),
            ];
        }
        return $result;
    }

    $byPhone = [];
    foreach ((array)($resp['parsed']['messageList'] ?? $resp['parsed']['messages'] ?? []) as $m) {
        $to = $m['to'] ?? null;
        if ($to !== null) $byPhone[(string)$to] = $m;
    }

    foreach ($queued as $q) {
        $m = $byPhone[$q['phone']] ?? null;
        if ($m === null) {
            $result[$q['msg_id']] = [
                'status'            => 'unknown',
                'channel_used'      => 'none',
                'sent_at'           => null,
                'solapi_message_id' => null,
                'fail_reason'       => 'no_response_match',
            ];
            continue;
        }
        $statusCode = (string)($m['statusCode'] ?? $m['status'] ?? '');
        $messageId  = (string)($m['messageId'] ?? '');
        $isSuccess  = (str_starts_with($statusCode, '2') || $statusCode === '0' || $statusCode === '');
        if ($isSuccess) {
            $result[$q['msg_id']] = [
                'status'            => 'sent',
                'channel_used'      => 'alimtalk',
                'sent_at'           => $now,
                'solapi_message_id' => $messageId,
                'fail_reason'       => null,
            ];
        } else {
            $result[$q['msg_id']] = [
                'status'            => 'failed',
                'channel_used'      => 'none',
                'sent_at'           => null,
                'solapi_message_id' => $messageId ?: null,
                'fail_reason'       => substr(json_encode($m, JSON_UNESCAPED_UNICODE), 0, 1000),
            ];
        }
    }
    return $result;
}

/** 정상 종료 시 배치 status 결정 규칙 */
function notifyDecideBatchStatus(int $target, int $sent, int $failed, int $unknown): string {
    if ($target === 0) return 'no_targets';
    if ($sent > 0 && ($failed === 0 && $unknown === 0)) return 'completed';
    if ($sent > 0 && ($failed > 0 || $unknown > 0))     return 'partial';
    if ($sent === 0 && ($failed > 0 || $unknown > 0))   return 'failed';
    return 'completed'; // skipped만 있는 케이스
}

/** 배치 row 마무리 UPDATE */
function notifyFinalizeBatch(PDO $db, int $batchId, array $fields): void {
    $cols = []; $vals = [];
    foreach (['status','sent_count','failed_count','unknown_count','skipped_count','error_message'] as $f) {
        if (array_key_exists($f, $fields)) {
            $cols[] = "{$f} = ?";
            $vals[] = $fields[$f];
        }
    }
    $cols[] = 'finished_at = NOW()';
    $sql = "UPDATE notify_batch SET " . implode(', ', $cols) . " WHERE id = ?";
    $vals[] = $batchId;
    $db->prepare($sql)->execute($vals);
}

function notifyUpdateState(PDO $db, string $key, ?int $batchId, string $status): void {
    $db->prepare("
        UPDATE notify_scenario_state
           SET last_run_at = NOW(), last_run_status = ?, last_batch_id = ?
         WHERE scenario_key = ?
    ")->execute([$status, $batchId, $key]);
}
