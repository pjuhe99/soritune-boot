<?php
/**
 * Retention API Handlers
 * 운영팀 리텐션 관리 탭 백엔드.
 * Spec: docs/superpowers/specs/2026-04-28-retention-management-design.md
 */

const RETENTION_ROLES = ['operation'];

/**
 * Anchor 기수의 user_id 보유 회원 집합을 반환.
 * @return string[] DISTINCT user_id list
 */
function retentionUserIdsInCohort(\PDO $db, int $cohortId): array {
    $stmt = $db->prepare("
        SELECT DISTINCT user_id
          FROM bootcamp_members
         WHERE cohort_id = ?
           AND user_id IS NOT NULL AND user_id <> ''
    ");
    $stmt->execute([$cohortId]);
    return array_column($stmt->fetchAll(), 'user_id');
}

/**
 * 주어진 anchor cohort 이전(start_date 기준)의 모든 기수에 등장한 user_id 집합.
 * @return array<string, true> set
 */
function retentionPastUserIdSet(\PDO $db, int $anchorCohortId): array {
    $stmt = $db->prepare("
        SELECT DISTINCT bm.user_id
          FROM bootcamp_members bm
          JOIN cohorts c ON c.id = bm.cohort_id
          JOIN cohorts a ON a.id = ?
         WHERE c.start_date < a.start_date
           AND bm.user_id IS NOT NULL AND bm.user_id <> ''
    ");
    $stmt->execute([$anchorCohortId]);
    $set = [];
    foreach ($stmt->fetchAll() as $r) $set[$r['user_id']] = true;
    return $set;
}

/**
 * cohort row와 해당 기수의 next cohort row를 함께 반환. next가 없으면 [row, null].
 * @return array{0: array<string,mixed>|null, 1: array<string,mixed>|null}
 */
function retentionAnchorAndNext(\PDO $db, int $anchorCohortId): array {
    $stmt = $db->query("SELECT id, cohort, start_date, end_date FROM cohorts ORDER BY start_date ASC, id ASC");
    $rows = $stmt->fetchAll();
    $anchor = null; $next = null;
    foreach ($rows as $i => $r) {
        if ((int)$r['id'] === $anchorCohortId) {
            $anchor = $r;
            $next   = $rows[$i + 1] ?? null;
            break;
        }
    }
    return [$anchor, $next];
}

/**
 * Next 기수의 user_id 각각을 잔존/회귀/신규로 분류.
 *
 * @param string[]            $uNext   next 기수 user_id 리스트
 * @param string[]            $uAnchor anchor 기수 user_id 리스트
 * @param array<string, true> $uPast   anchor 이전 모든 기수 user_id set
 * @return array{stay:int, returning:int, brand_new:int}
 */
function retentionClassifyNext(array $uNext, array $uAnchor, array $uPast): array {
    $anchorSet = array_flip($uAnchor);
    $stay = 0; $returning = 0; $brandNew = 0;
    foreach ($uNext as $uid) {
        if (isset($anchorSet[$uid]))      $stay++;
        elseif (isset($uPast[$uid]))      $returning++;
        else                              $brandNew++;
    }
    return ['stay' => $stay, 'returning' => $returning, 'brand_new' => $brandNew];
}

/**
 * Anchor 기수 이후 모든 기수에 대한 step-independent 잔존 곡선.
 * step 0 은 anchor 자체 (count=|U_N|, pct=100).
 *
 * @return array<int, array{step:int, cohort_id:int, cohort_name:string, count:int, pct:float}>
 */
function retentionComputeCurve(\PDO $db, array $anchor, array $uAnchor): array {
    $anchorSetSize = count($uAnchor);
    $points = [[
        'step'        => 0,
        'cohort_id'   => (int)$anchor['id'],
        'cohort_name' => $anchor['cohort'],
        'count'       => $anchorSetSize,
        'pct'         => $anchorSetSize > 0 ? 100.0 : 0.0,
    ]];
    if ($anchorSetSize === 0) return $points;

    // anchor 이후 cohorts 모두
    $stmt = $db->prepare("
        SELECT id, cohort
          FROM cohorts
         WHERE start_date > (SELECT start_date FROM cohorts WHERE id = ?)
         ORDER BY start_date ASC, id ASC
    ");
    $stmt->execute([(int)$anchor['id']]);
    $futures = $stmt->fetchAll();

    if (!$futures) return $points;

    // |U_N ∩ U_C| per future cohort C
    $placeholders = implode(',', array_fill(0, count($uAnchor), '?'));
    $futureIds    = array_column($futures, 'id');
    $futurePlace  = implode(',', array_fill(0, count($futureIds), '?'));
    $sql = "
        SELECT cohort_id, COUNT(DISTINCT user_id) AS cnt
          FROM bootcamp_members
         WHERE cohort_id IN ($futurePlace)
           AND user_id IN ($placeholders)
         GROUP BY cohort_id
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($futureIds, $uAnchor));
    $countsById = [];
    foreach ($stmt->fetchAll() as $r) $countsById[(int)$r['cohort_id']] = (int)$r['cnt'];

    $step = 1;
    foreach ($futures as $f) {
        $cnt = $countsById[(int)$f['id']] ?? 0;
        $points[] = [
            'step'        => $step++,
            'cohort_id'   => (int)$f['id'],
            'cohort_name' => $f['cohort'],
            'count'       => $cnt,
            'pct'         => round($cnt / $anchorSetSize * 100, 2),
        ];
    }
    return $points;
}

/**
 * Anchor 기수의 누적 참여 횟수 4구간 breakdown.
 * 항상 활성. 분모: anchor user_id 보유 회원 전체.
 *
 * @return array{rows: array<int, array{bucket:string, total:int, transitioned:int, pct:float}>}
 */
function retentionBreakdownParticipation(\PDO $db, int $anchorId, array $uNext): array {
    $nextSet = array_flip($uNext);

    // GROUP BY user_id: 한 user_id가 anchor 기수 내 중복 row를 가질 수 있으므로 (legacy migration)
    // MIN(participation_count) 으로 결정적 tie-break (가장 작은 값 채택).
    // ELSE '이상치': 0/NULL 같은 비정상 값은 invariant 테스트에서 잡히도록 별도 버킷.
    $stmt = $db->prepare("
        SELECT user_id,
          CASE
            WHEN MIN(participation_count) = 1             THEN '1회 (신규)'
            WHEN MIN(participation_count) BETWEEN 2 AND 3 THEN '2~3회'
            WHEN MIN(participation_count) BETWEEN 4 AND 6 THEN '4~6회'
            WHEN MIN(participation_count) >= 7            THEN '7회 이상'
            ELSE '이상치'
          END AS bucket
        FROM bootcamp_members
        WHERE cohort_id = ?
          AND user_id IS NOT NULL AND user_id <> ''
        GROUP BY user_id
    ");
    $stmt->execute([$anchorId]);

    $agg = [
        '1회 (신규)' => ['total' => 0, 'transitioned' => 0],
        '2~3회'      => ['total' => 0, 'transitioned' => 0],
        '4~6회'      => ['total' => 0, 'transitioned' => 0],
        '7회 이상'   => ['total' => 0, 'transitioned' => 0],
    ];
    foreach ($stmt->fetchAll() as $row) {
        $bucket = $row['bucket'];
        if (!isset($agg[$bucket])) {
            // '이상치' 는 발견 시 동적으로 추가 (정상 운영에선 발생 X).
            $agg[$bucket] = ['total' => 0, 'transitioned' => 0];
        }
        $agg[$bucket]['total']++;
        if (isset($nextSet[$row['user_id']])) $agg[$bucket]['transitioned']++;
    }

    $rows = [];
    foreach ($agg as $bucket => $v) {
        $rows[] = [
            'bucket'       => $bucket,
            'total'        => $v['total'],
            'transitioned' => $v['transitioned'],
            'pct'          => $v['total'] > 0 ? round($v['transitioned'] / $v['total'] * 100, 2) : 0.0,
        ];
    }
    return ['rows' => $rows];
}

/**
 * Anchor 기수의 조별 breakdown. anchor에 그룹 데이터 없으면 null.
 *
 * @return null|array{rows: array<int, array{name:string, kind:string, total:int, transitioned:int, pct:float}>}
 */
function retentionBreakdownGroup(\PDO $db, int $anchorId, array $uNext): ?array {
    // 조 데이터 존재 확인
    $stmt = $db->prepare("SELECT COUNT(*) FROM bootcamp_groups WHERE cohort_id = ?");
    $stmt->execute([$anchorId]);
    if ((int)$stmt->fetchColumn() === 0) return null;

    $nextSet = array_flip($uNext);

    // SQL-side dedup: 한 user_id가 anchor 기수 내 중복 row를 가질 경우 MIN(id) 가진 row만 채택 (Task 6와 동일 정책).
    $stmt = $db->prepare("
        SELECT bm.user_id, bm.group_id, bg.name AS group_name, bg.cohort_id AS group_cohort_id
          FROM bootcamp_members bm
          JOIN (
                SELECT user_id, MIN(id) AS keep_id
                  FROM bootcamp_members
                 WHERE cohort_id = ?
                   AND user_id IS NOT NULL AND user_id <> ''
                 GROUP BY user_id
               ) k ON k.keep_id = bm.id
          LEFT JOIN bootcamp_groups bg ON bg.id = bm.group_id
    ");
    $stmt->execute([$anchorId]);

    // anchor 기수의 조 row 순서 (정상 조는 stage_no, id 정렬)
    $stmt2 = $db->prepare("SELECT id, name, stage_no FROM bootcamp_groups WHERE cohort_id = ? ORDER BY stage_no, id");
    $stmt2->execute([$anchorId]);
    $orderedGroups = $stmt2->fetchAll();

    $rowsByKey = []; // groupId|"unassigned"|"anomaly" => row
    foreach ($orderedGroups as $g) {
        $rowsByKey[(int)$g['id']] = [
            'name' => $g['name'], 'kind' => 'group',
            'total' => 0, 'transitioned' => 0, 'pct' => 0.0,
        ];
    }
    $rowsByKey['unassigned'] = ['name' => '미배정',       'kind' => 'unassigned', 'total' => 0, 'transitioned' => 0, 'pct' => 0.0];
    $rowsByKey['anomaly']    = ['name' => '조 정보 이상', 'kind' => 'anomaly',    'total' => 0, 'transitioned' => 0, 'pct' => 0.0];

    foreach ($stmt->fetchAll() as $row) {
        $uid = $row['user_id'];

        if ($row['group_id'] === null) {
            $key = 'unassigned';
        } elseif ((int)($row['group_cohort_id'] ?? 0) !== $anchorId) {
            $key = 'anomaly';
        } else {
            $key = (int)$row['group_id'];
        }

        // anchor 기수의 정상 group_id 라면 pre-seeded $rowsByKey 에 항상 존재.
        // 만약 누락되어 있으면 데이터 불변 위반 (FK 깨짐 등) — 침묵하지 말고 anomaly 로 보낸다.
        if (!isset($rowsByKey[$key])) {
            $key = 'anomaly';
        }
        $rowsByKey[$key]['total']++;
        if (isset($nextSet[$uid])) $rowsByKey[$key]['transitioned']++;
    }

    $rows = [];
    foreach ($rowsByKey as $r) {
        $r['pct'] = $r['total'] > 0 ? round($r['transitioned'] / $r['total'] * 100, 2) : 0.0;
        // unassigned/anomaly 가 0이면 표시 제외
        if (in_array($r['kind'], ['unassigned', 'anomaly'], true) && $r['total'] === 0) continue;
        $rows[] = $r;
    }
    return ['rows' => $rows];
}

/**
 * Anchor 기수의 점수 범위 4구간 breakdown.
 * 점수 보유자가 anchor 인원의 50% 미만이면 null (sparse 차단).
 * 분모: 점수 row 보유자.
 *
 * @return null|array{coverage_pct:float, scored_total:int, rows: array<int, array{band:string, total:int, transitioned:int, pct:float}>}
 */
function retentionBreakdownScore(\PDO $db, int $anchorId, int $anchorTotalWithUserId, array $uNext): ?array {
    // Deduped 멤버에 대해 점수 row 보유자만 집계.
    $stmt = $db->prepare("
        SELECT bm.user_id, ms.current_score
          FROM bootcamp_members bm
          JOIN (SELECT user_id, MIN(id) AS keep_id
                  FROM bootcamp_members
                 WHERE cohort_id = ?
                   AND user_id IS NOT NULL AND user_id <> ''
                 GROUP BY user_id) k ON k.keep_id = bm.id
          JOIN member_scores ms ON ms.member_id = bm.id
    ");
    $stmt->execute([$anchorId]);
    $rowsRaw = $stmt->fetchAll();

    $scoredTotal = count($rowsRaw);
    if ($scoredTotal === 0) return null;

    $coverage = $anchorTotalWithUserId > 0 ? $scoredTotal / $anchorTotalWithUserId : 0;
    if ($coverage < 0.5) return null;

    $nextSet = array_flip($uNext);

    $agg = [
        '0점'      => ['total' => 0, 'transitioned' => 0],
        '-1~-10'   => ['total' => 0, 'transitioned' => 0],
        '-11~-24'  => ['total' => 0, 'transitioned' => 0],
        '-25 이하' => ['total' => 0, 'transitioned' => 0],
    ];
    foreach ($rowsRaw as $row) {
        $score = (int)$row['current_score'];
        if ($score >= 0)             $band = '0점';
        elseif ($score >= -10)        $band = '-1~-10';
        elseif ($score >= -24)        $band = '-11~-24';
        else                          $band = '-25 이하';

        $agg[$band]['total']++;
        if (isset($nextSet[$row['user_id']])) $agg[$band]['transitioned']++;
    }

    $rows = [];
    foreach ($agg as $band => $v) {
        $rows[] = [
            'band'         => $band,
            'total'        => $v['total'],
            'transitioned' => $v['transitioned'],
            'pct'          => $v['total'] > 0 ? round($v['transitioned'] / $v['total'] * 100, 2) : 0.0,
        ];
    }
    return [
        'coverage_pct' => round($coverage * 100, 2),
        'scored_total' => $scoredTotal,
        'rows'         => $rows,
    ];
}

/**
 * 페어 목록 반환.
 * 정렬: anchor.start_date ASC (오래된 → 최신)
 * 노출 조건: anchor.total_with_user_id > 0 AND next.total_with_user_id > 0
 */
function handleRetentionPairs(): void {
    requireAdmin(RETENTION_ROLES);
    $db = getDB();

    $stmt = $db->query("
        SELECT c.id, c.cohort, c.start_date,
               (SELECT COUNT(DISTINCT bm.user_id)
                  FROM bootcamp_members bm
                 WHERE bm.cohort_id = c.id
                   AND bm.user_id IS NOT NULL AND bm.user_id <> '') AS total_with_user_id
          FROM cohorts c
         ORDER BY c.start_date ASC, c.id ASC
    ");
    $cohorts = $stmt->fetchAll();

    $pairs = [];
    for ($i = 0; $i < count($cohorts) - 1; $i++) {
        $a = $cohorts[$i];
        $n = $cohorts[$i + 1];
        if ((int)$a['total_with_user_id'] === 0 || (int)$n['total_with_user_id'] === 0) continue;
        $pairs[] = [
            'anchor_cohort_id'           => (int)$a['id'],
            'anchor_name'                => $a['cohort'],
            'anchor_total_with_user_id'  => (int)$a['total_with_user_id'],
            'next_cohort_id'             => (int)$n['id'],
            'next_name'                  => $n['cohort'],
            'next_total_with_user_id'    => (int)$n['total_with_user_id'],
        ];
    }
    jsonSuccess(['pairs' => $pairs]);
}
