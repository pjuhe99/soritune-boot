<?php
/**
 * Retention API Handlers
 * 운영팀 리텐션 관리 탭 백엔드.
 * Spec: docs/superpowers/specs/2026-04-28-retention-management-design.md
 */

const RETENTION_ROLES = ['operation'];

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
