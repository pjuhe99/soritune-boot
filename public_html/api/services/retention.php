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
