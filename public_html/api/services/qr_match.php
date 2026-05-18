<?php
/**
 * QR ↔ 강의 자동 매칭 헬퍼 (3-tier cascade).
 * spec: docs/superpowers/specs/2026-05-18-qr-lecture-auto-match-design.md
 *
 * @param PDO $db
 * @param int $adminId  QR 발급 admin id. 0 이면 Tier A·B skip, Tier C 만 시도.
 * @param int $cohortId
 * @param ?string $atDate 'YYYY-MM-DD'. null 이면 KST 오늘.
 * @param ?string $atTime 'HH:MM:SS'. null 이면 KST 현재 시각.
 * @return ?int 매칭된 lecture_sessions.id, 없으면 null.
 */
function findMatchingLectureSession(
    PDO $db, int $adminId, int $cohortId,
    ?string $atDate = null, ?string $atTime = null
): ?int {
    if (($atDate === null) !== ($atTime === null)) {
        throw new InvalidArgumentException('atDate and atTime must both be null or both be set');
    }
    $atDate = $atDate ?? date('Y-m-d');
    $atTime = $atTime ?? date('H:i:s');

    // ── Tier A: 정확 매칭 (admin_id 일치) ─────────────────────
    if ($adminId > 0) {
        $stmt = $db->prepare("
            SELECT id FROM lecture_sessions
            WHERE coach_admin_id = ?
              AND lecture_date = ?
              AND cohort_id = ?
              AND status = 'active'
            ORDER BY ABS(TIME_TO_SEC(TIMEDIFF(start_time, ?))) ASC
            LIMIT 1
        ");
        $stmt->execute([$adminId, $atDate, $cohortId, $atTime]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int)$id;
    }

    // ── Tier B: 동일 이름 admin 그룹 매칭 ────────────────────
    if ($adminId > 0) {
        $stmt = $db->prepare("
            SELECT id FROM lecture_sessions
            WHERE coach_admin_id IN (
                SELECT id FROM admins
                WHERE name = (SELECT name FROM admins WHERE id = ?)
                  AND role IN ('coach','sub_coach','head','subhead1','subhead2')
              )
              AND lecture_date = ?
              AND cohort_id = ?
              AND status = 'active'
            ORDER BY ABS(TIME_TO_SEC(TIMEDIFF(start_time, ?))) ASC
            LIMIT 1
        ");
        $stmt->execute([$adminId, $atDate, $cohortId, $atTime]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int)$id;
    }

    // ── Tier C: 시간대 단일 후보 매칭 (보수적) ────────────────
    // Note: TIMESTAMPDIFF does not work with TIME columns (returns NULL);
    // use TIME_TO_SEC(TIMEDIFF(...)) for correct ±60-minute filtering.
    $stmt = $db->prepare("
        SELECT id FROM lecture_sessions
        WHERE lecture_date = ?
          AND cohort_id = ?
          AND status = 'active'
          AND ABS(TIME_TO_SEC(TIMEDIFF(start_time, ?))) / 60 <= 60
    ");
    $stmt->execute([$atDate, $cohortId, $atTime]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($rows) === 1) return (int)$rows[0];

    return null;
}
