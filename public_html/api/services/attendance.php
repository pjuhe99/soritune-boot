<?php
/**
 * Attendance Stats Service
 * QR 출석 세션별 통계 — 강의/복습스터디/기타 분류
 */

function handleAttendanceStats() {
    requireAdmin();
    $db = getDB();

    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo   = $_GET['date_to']   ?? date('Y-m-d');

    // cohort_id 미지정 시 활성 기수 사용
    if (!$cohortId) {
        $stmt = $db->query("SELECT id FROM cohorts WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1");
        $row = $stmt->fetch();
        if ($row) $cohortId = (int)$row['id'];
        if (!$cohortId) jsonError('활성 기수를 찾을 수 없습니다.');
    }

    // 기수 활성 멤버 수 (출석률 분모)
    $totalStmt = $db->prepare("
        SELECT COUNT(*) FROM bootcamp_members
        WHERE cohort_id = ? AND is_active = 1
          AND member_status NOT IN ('withdrawn','out_of_group_management')
    ");
    $totalStmt->execute([$cohortId]);
    $totalMembers = (int)$totalStmt->fetchColumn();

    // QR 세션별 출석 데이터 (강의 + 복습스터디 + 기타)
    $stmt = $db->prepare("
        SELECT
            qs.id AS qr_session_id,
            qs.session_type,
            qs.admin_id,
            a.name AS admin_name,
            qs.created_at,
            qs.closed_at,
            qs.lecture_session_id,
            ls.title AS lecture_title,
            ls.lecture_date,
            ls.start_time AS lecture_start_time,
            ls.stage,
            ss.id AS study_session_id,
            ss.title AS study_title,
            (SELECT COUNT(*) FROM qr_attendance qa WHERE qa.qr_session_id = qs.id) AS attendee_count
        FROM qr_sessions qs
        LEFT JOIN admins a ON qs.admin_id = a.id
        LEFT JOIN lecture_sessions ls ON qs.lecture_session_id = ls.id
        LEFT JOIN study_sessions ss ON ss.qr_session_id = qs.id
        WHERE qs.cohort_id = ?
          AND DATE(qs.created_at) BETWEEN ? AND ?
        ORDER BY qs.created_at DESC
    ");
    $stmt->execute([$cohortId, $dateFrom, $dateTo]);
    $sessions = $stmt->fetchAll();

    $stats = [];
    $coachMap = [];       // admin_id → { name, count, total_attendees }
    $stageMap = [];       // stage → { count, total_attendees }

    foreach ($sessions as $s) {
        // 카테고리 분류
        if ($s['session_type'] === 'revival') {
            $category = 'revival';
        } elseif ($s['lecture_session_id']) {
            $category = 'lecture';
        } elseif ($s['study_session_id']) {
            $category = 'study';
        } else {
            $category = 'etc';
        }

        $attendeeCount = (int)$s['attendee_count'];
        $rate = $totalMembers > 0 ? round($attendeeCount / $totalMembers * 100, 1) : 0;

        $stats[] = [
            'qr_session_id'    => (int)$s['qr_session_id'],
            'session_type'     => $s['session_type'],
            'category'         => $category,
            'created_at'       => $s['created_at'],
            'closed_at'        => $s['closed_at'],
            'admin_name'       => $s['admin_name'] ?: '(시스템)',
            'lecture_title'    => $s['lecture_title'],
            'study_title'      => $s['study_title'],
            'lecture_date'     => $s['lecture_date'] ?: substr($s['created_at'], 0, 10),
            'lecture_start_time' => $s['lecture_start_time'],
            'stage'            => $s['stage'] ? (int)$s['stage'] : null,
            'attendee_count'   => $attendeeCount,
            'total_members'    => $totalMembers,
            'rate'             => $rate,
        ];

        // 코치별 집계 (강의 카테고리만)
        if ($category === 'lecture' && $s['admin_id'] > 0) {
            $aid = (int)$s['admin_id'];
            if (!isset($coachMap[$aid])) {
                $coachMap[$aid] = ['admin_name' => $s['admin_name'], 'session_count' => 0, 'total_attendees' => 0];
            }
            $coachMap[$aid]['session_count']++;
            $coachMap[$aid]['total_attendees'] += $attendeeCount;
        }

        // 단계별 집계 (강의 카테고리만)
        if ($category === 'lecture' && $s['stage']) {
            $stg = (int)$s['stage'];
            if (!isset($stageMap[$stg])) {
                $stageMap[$stg] = ['stage' => $stg, 'session_count' => 0, 'total_attendees' => 0];
            }
            $stageMap[$stg]['session_count']++;
            $stageMap[$stg]['total_attendees'] += $attendeeCount;
        }
    }

    // 요약 계산
    $byCoach = [];
    foreach ($coachMap as $c) {
        $avgRate = ($c['session_count'] > 0 && $totalMembers > 0)
            ? round($c['total_attendees'] / $c['session_count'] / $totalMembers * 100, 1)
            : 0;
        $byCoach[] = [
            'admin_name'    => $c['admin_name'],
            'session_count' => $c['session_count'],
            'avg_attendees' => $c['session_count'] > 0 ? round($c['total_attendees'] / $c['session_count'], 1) : 0,
            'avg_rate'      => $avgRate,
        ];
    }

    $byStage = [];
    foreach ($stageMap as $st) {
        $avgRate = ($st['session_count'] > 0 && $totalMembers > 0)
            ? round($st['total_attendees'] / $st['session_count'] / $totalMembers * 100, 1)
            : 0;
        $byStage[] = [
            'stage'         => $st['stage'],
            'session_count' => $st['session_count'],
            'avg_attendees' => $st['session_count'] > 0 ? round($st['total_attendees'] / $st['session_count'], 1) : 0,
            'avg_rate'      => $avgRate,
        ];
    }

    jsonSuccess([
        'total_members' => $totalMembers,
        'stats'   => $stats,
        'summary' => [
            'by_coach' => $byCoach,
            'by_stage' => $byStage,
        ],
    ]);
}
