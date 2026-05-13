<?php
/**
 * 다회권 CSV 일괄 검증 + 적용.
 *
 * 입력 row 형태 (parser 출력):
 *   ['row' => int, 'user_id' => string, 'product_name' => string,
 *    'cohort_labels' => string[], 'cohort_raw' => string[]]
 *
 * 검증 결과 row 추가 필드:
 *   ['status' => 'OK'|'WARN_*'|'ERROR_*', 'cohort_ids' => int[]?, 'unmatched_labels' => string[]?,
 *    'existing_pass_id' => int?, 'target_pass_in_batch' => int?]
 *
 * 적용 입력 row 추가 필드 (운영자 결정):
 *   ['mode' => 'extend'|'new'|'skip']  // WARN_DUPLICATE_PASS* 행만 필요
 */
declare(strict_types=1);

require_once __DIR__ . '/multipass_repo.php';

const MULTIPASS_BULK_MAX_ROWS = 200;

/**
 * 행 단위 검증.
 *
 * @return array{rows: array, summary: array{ok:int, warn:int, error:int}}
 */
function validateMultipassBulk(PDO $db, array $rows): array {
    // cohorts master — "11기" → cohort_id 매핑
    $cohortMap = [];  // "11" => cohort_id
    foreach ($db->query("SELECT id, cohort FROM cohorts")->fetchAll() as $c) {
        if (preg_match('/^(\d+)/', $c['cohort'], $m)) {
            $cohortMap[$m[1]] = (int)$c['id'];
        }
    }

    // boot 에 등록된 user_id set
    $knownUserIds = array_flip(
        $db->query("SELECT DISTINCT user_id FROM bootcamp_members WHERE user_id IS NOT NULL AND user_id <> ''")
           ->fetchAll(PDO::FETCH_COLUMN)
    );

    // 기존 multipass — (user_id, product_name) → pass_id
    $existingMap = [];
    foreach ($db->query("SELECT id, user_id, product_name FROM multipass")->fetchAll() as $p) {
        $existingMap[$p['user_id'] . '|' . $p['product_name']] = (int)$p['id'];
    }

    // 배치 내 첫 등장 추적
    $firstInBatch = []; // "user_id|product_name" => row_num

    $out = [];
    $summary = ['ok' => 0, 'warn' => 0, 'error' => 0];

    foreach ($rows as $r) {
        $r['user_id']      = trim($r['user_id'] ?? '');
        $r['product_name'] = trim($r['product_name'] ?? '');
        $r['cohort_labels'] = $r['cohort_labels'] ?? [];

        if ($r['user_id'] === '') {
            $r['status'] = 'ERROR_NO_USER_ID';
            $summary['error']++;
            $out[] = $r;
            continue;
        }
        if ($r['product_name'] === '') {
            $r['status'] = 'ERROR_NO_PRODUCT';
            $summary['error']++;
            $out[] = $r;
            continue;
        }
        if (empty($r['cohort_labels'])) {
            $r['status'] = 'ERROR_NO_COHORTS';
            $summary['error']++;
            $out[] = $r;
            continue;
        }

        // cohort 라벨 → cohort_id
        $cohortIds = [];
        $unmatched = [];
        foreach ($r['cohort_labels'] as $label) {
            // 라벨이 "11" 같이 숫자만이면 매칭, 아니면 unmatched
            if (preg_match('/^(\d+)$/', $label, $m) && isset($cohortMap[$m[1]])) {
                $cohortIds[] = $cohortMap[$m[1]];
            } else {
                $unmatched[] = $label;
            }
        }
        if ($unmatched) {
            $r['status'] = 'ERROR_COHORT_LABEL';
            $r['unmatched_labels'] = $unmatched;
            $summary['error']++;
            $out[] = $r;
            continue;
        }
        $r['cohort_ids'] = array_values(array_unique($cohortIds));

        // user_id 미존재 → WARN
        $hasMember = isset($knownUserIds[$r['user_id']]);

        // 중복 평가 (DB 우선)
        $key = $r['user_id'] . '|' . $r['product_name'];
        if (isset($existingMap[$key])) {
            $r['status'] = 'WARN_DUPLICATE_PASS';
            $r['existing_pass_id'] = $existingMap[$key];
            $summary['warn']++;
        } elseif (isset($firstInBatch[$key])) {
            $r['status'] = 'WARN_DUPLICATE_PASS_IN_BATCH';
            $r['target_pass_in_batch'] = $firstInBatch[$key];
            $summary['warn']++;
        } elseif (!$hasMember) {
            $r['status'] = 'WARN_NO_MEMBER';
            $summary['warn']++;
            $firstInBatch[$key] = $r['row'];
        } else {
            $r['status'] = 'OK';
            $summary['ok']++;
            $firstInBatch[$key] = $r['row'];
        }
        $out[] = $r;
    }

    return ['rows' => $out, 'summary' => $summary];
}

/**
 * 검증 통과 행을 일괄 적용. 행 단위 try/catch + 행 단위 트랜잭션.
 *
 * 입력 row 추가 필드:
 *   ['mode' => 'extend'|'new'|'skip']  // WARN_DUPLICATE_PASS* 일 때 필수
 *   ['existing_pass_id' => int?]       // extend 의 기본 타깃
 *
 * @return array{applied:int, failed:array<int, array{row:int, error:string}>}
 */
function applyMultipassBulk(PDO $db, array $rows, int $createdBy): array {
    if (count($rows) > MULTIPASS_BULK_MAX_ROWS) {
        throw new InvalidArgumentException('한 번에 ' . MULTIPASS_BULK_MAX_ROWS . '행 까지만 적용 가능합니다.');
    }

    $applied = 0;
    $failed = [];

    // 같은 batch 내 (user_id, product_name) → 첫 행이 만든 pass_id 누적 (extend 의 in-batch 타깃)
    $batchPassMap = [];

    foreach ($rows as $r) {
        $rowNum = (int)($r['row'] ?? 0);
        $mode = $r['mode'] ?? null;
        $status = $r['status'] ?? '';

        if ($status === 'skip' || $mode === 'skip') continue;
        if (str_starts_with($status, 'ERROR_')) continue;

        // WARN_DUPLICATE_PASS* 행은 mode 명시 필수 (extend|new|skip)
        if (($status === 'WARN_DUPLICATE_PASS' || $status === 'WARN_DUPLICATE_PASS_IN_BATCH') && $mode === null) {
            $failed[] = ['row' => $rowNum, 'error' => "WARN 행은 mode (extend/new/skip) 를 명시해야 합니다."];
            continue;
        }

        try {
            if ($status === 'WARN_DUPLICATE_PASS' && $mode === 'extend') {
                $passId = (int)($r['existing_pass_id'] ?? 0);
                if (!$passId) throw new RuntimeException('existing_pass_id 누락');
                _addCohortsToPass($db, $passId, $r['cohort_ids'] ?? []);
                $applied++;
                continue;
            }
            if ($status === 'WARN_DUPLICATE_PASS_IN_BATCH' && $mode === 'extend') {
                $key = $r['user_id'] . '|' . $r['product_name'];
                $passId = $batchPassMap[$key] ?? null;
                if (!$passId) throw new RuntimeException('배치 내 첫 행이 적용되지 않아 extend 불가');
                _addCohortsToPass($db, $passId, $r['cohort_ids'] ?? []);
                $applied++;
                continue;
            }
            // 'new' 또는 OK / WARN_NO_MEMBER → 새 pass 생성
            $passId = createPass($db, $r['user_id'], $r['product_name'], $r['cohort_ids'] ?? [], $r['note'] ?? null, $createdBy);
            $key = $r['user_id'] . '|' . $r['product_name'];
            if (!isset($batchPassMap[$key])) $batchPassMap[$key] = $passId;
            $applied++;
        } catch (Throwable $e) {
            $failed[] = ['row' => $rowNum, 'error' => $e->getMessage()];
        }
    }

    return ['applied' => $applied, 'failed' => $failed];
}

/** UNIQUE 위반 cohort 는 무시하고 나머지 INSERT. */
function _addCohortsToPass(PDO $db, int $passId, array $cohortIds): void {
    $stmt = $db->prepare("INSERT IGNORE INTO multipass_cohorts (pass_id, cohort_id) VALUES (?, ?)");
    foreach ($cohortIds as $cid) {
        $stmt->execute([$passId, (int)$cid]);
    }
}
