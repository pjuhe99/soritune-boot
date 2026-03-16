<?php
/**
 * Curriculum Service
 * 진도 관리 — 사용자 페이지용 조회
 */

const CURRICULUM_TYPE_LABELS = [
    'progress'              => '진도',
    'event'                 => '이벤트',
    'lecture'               => '강의 듣기',
    'malkka_mission'        => '말까미션',
    'naemat33_mission'      => '내맛33미션',
    'zoom_or_daily_mission' => '줌 특강 / 데일리미션',
    'hamummal'              => '하멈말',
];

/**
 * 오늘의 진도 목록 (회원용)
 */
function handleCurriculumToday() {
    $member = requireMember();
    $cohort = $member['cohort'];
    $today  = date('Y-m-d');

    $db = getDB();
    $stmt = $db->prepare('
        SELECT id, target_date, task_type, note, sort_order
        FROM curriculum_items
        WHERE cohort = ? AND target_date = ?
        ORDER BY sort_order ASC, id ASC
    ');
    $stmt->execute([$cohort, $today]);
    $items = $stmt->fetchAll();

    foreach ($items as &$item) {
        $item['task_type_label'] = CURRICULUM_TYPE_LABELS[$item['task_type']] ?? $item['task_type'];
    }
    unset($item);

    // 주차/요일 라벨 계산
    $weekLabel = '';
    $weekdayLabels = ['일', '월', '화', '수', '목', '금', '토'];
    $stmtCohort = $db->prepare('SELECT start_date FROM cohorts WHERE cohort = ?');
    $stmtCohort->execute([$cohort]);
    $cohortRow = $stmtCohort->fetch();
    if ($cohortRow) {
        $start = new DateTime($cohortRow['start_date']);
        $todayDt = new DateTime($today);
        $diff = (int)$start->diff($todayDt)->days;
        $weekNum = (int)floor($diff / 7) + 1;
        $dow = (int)$todayDt->format('w'); // 0=Sun
        $weekLabel = $weekNum . '주차 · ' . $weekdayLabels[$dow] . '요일';
    }

    jsonSuccess([
        'date'       => $today,
        'week_label' => $weekLabel,
        'items'      => $items,
    ]);
}
