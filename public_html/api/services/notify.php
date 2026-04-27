<?php
/**
 * Notify API Handlers
 * 모든 액션은 operation/head/subhead1/subhead2 중 하나의 역할 필요.
 */

require_once __DIR__ . '/../../includes/notify/dispatcher.php';

const NOTIFY_ROLES = ['operation', 'head', 'subhead1', 'subhead2'];
const NOTIFY_PREVIEW_TTL_MIN = 10;

function handleNotifyListScenarios() {
    requireAdmin(NOTIFY_ROLES);
    $db = getDB();
    $scenarios = notifyLoadScenarios();
    notifyEnsureScenarioStates($db, $scenarios);

    $stmt = $db->query("SELECT * FROM notify_scenario_state");
    $stateMap = [];
    foreach ($stmt->fetchAll() as $row) $stateMap[$row['scenario_key']] = $row;

    $now = time();
    $out = [];
    foreach ($scenarios as $key => $def) {
        $state = $stateMap[$key] ?? [];
        $out[] = [
            'key'             => $key,
            'name'            => $def['name'] ?? $key,
            'description'     => $def['description'] ?? '',
            'schedule'        => $def['schedule'] ?? '',
            'cooldown_hours'  => $def['cooldown_hours'] ?? null,
            'max_attempts'    => $def['max_attempts'] ?? null,
            'source_type'     => $def['source']['type'] ?? '',
            'template_id'     => $def['template']['templateId'] ?? '',
            'fallback_lms'    => (bool)($def['template']['fallback_lms'] ?? false),
            'is_active'       => (int)($state['is_active'] ?? 0),
            'is_running'      => (int)($state['is_running'] ?? 0),
            'last_run_at'     => $state['last_run_at'] ?? null,
            'last_run_status' => $state['last_run_status'] ?? null,
            'last_batch_id'   => $state['last_batch_id'] ?? null,
            'next_run_at'     => notifyNextRunAt((string)($def['schedule'] ?? ''), $now),
        ];
    }
    jsonSuccess(['scenarios' => $out]);
}

/** 다음 실행 예정 시각을 단순 brute-force로 계산 (최대 8일 탐색) */
function notifyNextRunAt(string $cronExpr, int $now): ?string {
    if ($cronExpr === '') return null;
    $base = $now - ($now % 60) + 60;
    for ($i = 0; $i < 60 * 24 * 8; $i++) {
        $ts = $base + ($i * 60);
        if (notifyCronMatches($cronExpr, $ts)) return date('Y-m-d H:i:s', $ts);
    }
    return null;
}

function handleNotifyToggle($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(NOTIFY_ROLES);
    $input = getJsonInput();
    $key   = trim($input['key'] ?? '');
    $on    = (int)!!($input['is_active'] ?? false);
    if ($key === '') jsonError('key 필요');

    $scenarios = notifyLoadScenarios();
    if (!isset($scenarios[$key])) jsonError('알 수 없는 시나리오');

    $db = getDB();
    $db->prepare("
        UPDATE notify_scenario_state
           SET is_active = ?, updated_by = ?
         WHERE scenario_key = ?
    ")->execute([$on, (string)$admin['admin_id'], $key]);

    jsonSuccess(['key' => $key, 'is_active' => $on]);
}

function handleNotifyPreview($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(NOTIFY_ROLES);
    $input = getJsonInput();
    $key   = trim($input['key'] ?? '');
    if ($key === '') jsonError('key 필요');

    $scenarios = notifyLoadScenarios();
    if (!isset($scenarios[$key])) jsonError('알 수 없는 시나리오');
    $def = $scenarios[$key];

    $keys = solapiLoadKeys();
    $dryRun            = isset($input['dry_run']) ? (bool)$input['dry_run'] : (bool)($keys['dry_run_default'] ?? false);
    $bypassCooldown    = (bool)($input['bypass_cooldown']     ?? false);
    $bypassMaxAttempts = (bool)($input['bypass_max_attempts'] ?? false);

    try {
        $rows = notifyFetchRows($def);
    } catch (Throwable $e) {
        jsonError('source 호출 실패: ' . $e->getMessage(), 500);
    }

    $db = getDB();
    $candidates = [];
    $skips      = [];
    $cd = $db->prepare("
        SELECT MAX(processed_at) FROM notify_message
         WHERE scenario_key = ? AND phone = ?
           AND status IN ('sent','unknown')
           AND processed_at >= NOW() - INTERVAL ? HOUR
    ");
    $mx = $db->prepare("
        SELECT COUNT(*) FROM notify_message
         WHERE scenario_key = ? AND phone = ? AND status IN ('sent','unknown')
    ");

    $cooldownHours = (int)($def['cooldown_hours'] ?? 0);
    $maxAttempts   = (int)($def['max_attempts']   ?? 0);
    $checkCd       = notifyShouldCheckCooldown($cooldownHours, $bypassCooldown);
    $checkMx       = notifyShouldCheckMaxAttempts($maxAttempts, $bypassMaxAttempts);

    foreach ($rows as $row) {
        $phoneNorm = notifyNormalizePhone($row['phone'] ?? '');
        if ($phoneNorm === null) {
            $skips[] = $row + ['_skip' => 'phone_invalid'];
            continue;
        }
        if ($checkCd) {
            $cd->execute([$key, $phoneNorm, $cooldownHours]);
            if ($cd->fetchColumn()) { $skips[] = $row + ['_skip' => 'cooldown']; continue; }
        }
        if ($checkMx) {
            $mx->execute([$key, $phoneNorm]);
            if ((int)$mx->fetchColumn() >= $maxAttempts) {
                $skips[] = $row + ['_skip' => 'max_attempts']; continue;
            }
        }
        $candidates[] = $row + ['phone_norm' => $phoneNorm];
    }

    $preview = null;
    if (!empty($candidates)) {
        $first = $candidates[0];
        $preview = notifyRenderVariables(
            (array)$def['template']['variables'],
            $first['columns'] ?? []
        );
    }

    $previewId = bin2hex(random_bytes(16));
    $rowKeys = array_map(fn($c) => $c['row_key'], $candidates);
    $db->prepare("
        INSERT INTO notify_preview
          (id, scenario_key, dry_run, bypass_cooldown, bypass_max_attempts,
           row_keys, target_count, created_by, created_at, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL ? MINUTE)
    ")->execute([
        $previewId, $key, (int)$dryRun,
        (int)$bypassCooldown, (int)$bypassMaxAttempts,
        json_encode($rowKeys, JSON_UNESCAPED_UNICODE),
        count($rowKeys), (string)$admin['admin_id'],
        NOTIFY_PREVIEW_TTL_MIN,
    ]);

    jsonSuccess([
        'preview_id'           => $previewId,
        'expires_in_min'       => NOTIFY_PREVIEW_TTL_MIN,
        'dry_run'              => (int)$dryRun,
        'bypass_cooldown'      => (int)$bypassCooldown,
        'bypass_max_attempts'  => (int)$bypassMaxAttempts,
        'environment'          => notifyEnvironmentLabel(),
        'target_count'   => count($candidates),
        'skip_count'     => count($skips),
        'candidates'     => array_map(fn($c) => [
            'row_key' => $c['row_key'],
            'name'    => $c['name'] ?? '',
            'phone'   => $c['phone_norm'],
        ], $candidates),
        'skips'          => array_map(fn($s) => [
            'row_key' => $s['row_key'],
            'name'    => $s['name'] ?? '',
            'phone'   => $s['phone'] ?? '',
            'reason'  => $s['_skip'],
        ], $skips),
        'rendered_first' => $preview,
        'template_id'    => $def['template']['templateId'] ?? '',
    ]);
}

function notifyEnvironmentLabel(): string {
    $creds = loadDbCredentials();
    $name = $creds['DB_NAME'] ?? '';
    if (str_contains($name, 'DEV')) return 'DEV';
    return 'PROD';
}

function handleNotifySendNow($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(NOTIFY_ROLES);
    $input = getJsonInput();
    $previewId = trim($input['preview_id'] ?? '');
    if ($previewId === '') jsonError('preview_id 필요');

    $db = getDB();
    $db->beginTransaction();
    try {
        $sel = $db->prepare("SELECT * FROM notify_preview WHERE id = ? FOR UPDATE");
        $sel->execute([$previewId]);
        $preview = $sel->fetch();
        if (!$preview)                         { $db->rollBack(); jsonError('만료되었거나 알 수 없는 preview'); }
        if ($preview['used_at'])               { $db->rollBack(); jsonError('이미 사용된 preview'); }
        if (strtotime($preview['expires_at']) < time()) { $db->rollBack(); jsonError('preview 만료'); }
        if ((string)$preview['created_by'] !== (string)$admin['admin_id']) {
            $db->rollBack(); jsonError('preview 권한 없음', 403);
        }
        $db->prepare("UPDATE notify_preview SET used_at = NOW() WHERE id = ?")->execute([$previewId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $scenarios = notifyLoadScenarios();
    if (!isset($scenarios[$preview['scenario_key']])) jsonError('알 수 없는 시나리오');

    $rowKeys = json_decode((string)$preview['row_keys'], true);
    if (!is_array($rowKeys)) $rowKeys = [];

    // preview.used_at이 이미 커밋됐으므로 이 시점에서 throw 나면 사용자는 새 preview를 만들어야 함.
    // blank 500 대신 JSON 에러로 일관된 응답 보장.
    try {
        $batchId = notifyRunScenario(
            $db,
            $scenarios[$preview['scenario_key']],
            'manual',
            (string)$admin['admin_id'],
            (bool)$preview['dry_run'],
            $rowKeys,
            (bool)($preview['bypass_cooldown']     ?? false),
            (bool)($preview['bypass_max_attempts'] ?? false)
        );
    } catch (Throwable $e) {
        jsonError('발송 중 오류: ' . $e->getMessage(), 500);
    }

    if ($batchId === null) jsonError('이미 실행 중인 시나리오입니다. 잠시 후 다시 시도하세요.');
    jsonSuccess(['batch_id' => $batchId]);
}

function handleNotifyListBatches() {
    requireAdmin(NOTIFY_ROLES);
    $key   = trim($_GET['key'] ?? '');
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
    if ($key === '') jsonError('key 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, scenario_key, trigger_type, triggered_by, started_at, finished_at,
               target_count, sent_count, failed_count, unknown_count, skipped_count,
               dry_run, bypass_cooldown, bypass_max_attempts, status, error_message
          FROM notify_batch
         WHERE scenario_key = ?
         ORDER BY started_at DESC
         LIMIT {$limit}
    ");
    $stmt->execute([$key]);
    jsonSuccess(['batches' => $stmt->fetchAll()]);
}

function handleNotifyBatchDetail() {
    requireAdmin(NOTIFY_ROLES);
    $batchId = (int)($_GET['batch_id'] ?? 0);
    if (!$batchId) jsonError('batch_id 필요');

    $db = getDB();
    $batch = $db->prepare("SELECT * FROM notify_batch WHERE id = ?");
    $batch->execute([$batchId]);
    $b = $batch->fetch();
    if (!$b) jsonError('배치 없음', 404);

    $msgs = $db->prepare("
        SELECT id, row_key, phone, name, channel_used, status,
               skip_reason, fail_reason, solapi_message_id, sent_at, processed_at
          FROM notify_message
         WHERE batch_id = ?
         ORDER BY id
    ");
    $msgs->execute([$batchId]);
    jsonSuccess(['batch' => $b, 'messages' => $msgs->fetchAll()]);
}

function handleNotifyRetryFailed($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(NOTIFY_ROLES);
    $input = getJsonInput();
    $batchId = (int)($input['batch_id'] ?? 0);
    if (!$batchId) jsonError('batch_id 필요');

    $db = getDB();
    $batchRow = $db->prepare("SELECT scenario_key, dry_run FROM notify_batch WHERE id = ?");
    $batchRow->execute([$batchId]);
    $batch = $batchRow->fetch();
    if (!$batch) jsonError('배치 없음', 404);

    $rk = $db->prepare("
        SELECT DISTINCT row_key FROM notify_message
         WHERE batch_id = ? AND status = 'failed'
    ");
    $rk->execute([$batchId]);
    $rowKeys = $rk->fetchAll(PDO::FETCH_COLUMN);
    if (empty($rowKeys)) jsonError('재시도할 failed 메시지가 없습니다');

    $scenarios = notifyLoadScenarios();
    $key = (string)$batch['scenario_key'];
    if (!isset($scenarios[$key])) jsonError('알 수 없는 시나리오');

    $newBatchId = notifyRunScenario(
        $db,
        $scenarios[$key],
        'retry',
        (string)$admin['admin_id'],
        (bool)$batch['dry_run'],
        $rowKeys
    );
    if ($newBatchId === null) jsonError('이미 실행 중인 시나리오입니다. 잠시 후 다시 시도하세요.');
    jsonSuccess(['batch_id' => $newBatchId]);
}
