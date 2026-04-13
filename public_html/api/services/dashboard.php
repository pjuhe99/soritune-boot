<?php
/**
 * Dashboard Service
 * 기수 전체·조별·멤버별 과제율 및 점수 현황 대시보드
 */

function handleDashboardStats() {
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    $db = getDB();

    // cohort_id가 없으면 현재 세션 기수 또는 활성 기수 사용
    if (!$cohortId) {
        $currentCohort = getSetting('current_cohort');
        if ($currentCohort) {
            $stmt = $db->prepare("SELECT id FROM cohorts WHERE cohort = ? LIMIT 1");
            $stmt->execute([$currentCohort]);
            $row = $stmt->fetch();
            if ($row) $cohortId = (int)$row['id'];
        }
        if (!$cohortId) {
            $stmt = $db->query("SELECT id FROM cohorts WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1");
            $row = $stmt->fetch();
            if ($row) $cohortId = (int)$row['id'];
        }
        if (!$cohortId) jsonError('활성 기수를 찾을 수 없습니다.');
    }

    // 1. 기수 정보
    $stmt = $db->prepare("SELECT start_date, end_date FROM cohorts WHERE id = ?");
    $stmt->execute([$cohortId]);
    $cohort = $stmt->fetch();
    if (!$cohort) jsonError('기수를 찾을 수 없습니다.');

    $adaptationEnd = date('Y-m-d', strtotime($cohort['start_date'] . ' + ' . (SCORE_ADAPTATION_DAYS - 1) . ' days'));
    $scoringStart = date('Y-m-d', strtotime($adaptationEnd . ' +1 day'));
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $scoringEnd = ($cohort['end_date'] && $cohort['end_date'] < $yesterday) ? $cohort['end_date'] : $yesterday;

    // 채점 기간이 아직 시작 안 됐으면
    if ($scoringStart > $scoringEnd) {
        jsonSuccess([
            'scoring_start' => $scoringStart,
            'scoring_end' => $scoringEnd,
            'total_days' => 0,
            'total_mondays' => 0,
            'cohort_summary' => null,
            'groups' => [],
            'members' => [],
            'score_distribution' => [],
            'score_warnings' => ['approaching' => [], 'revival_eligible' => [], 'out' => []],
        ]);
        return;
    }

    // 2. 날짜 계산
    $totalDays = 0;
    $totalMondays = 0;
    $current = $scoringStart;
    while ($current <= $scoringEnd) {
        $totalDays++;
        if ((int)date('w', strtotime($current)) === 1) $totalMondays++;
        $current = date('Y-m-d', strtotime($current . ' +1 day'));
    }

    // 3. 멤버 조회
    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
               bm.group_id, bm.member_status, bg.name AS group_name,
               COALESCE(ms.current_score, 0) AS current_score
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        WHERE bm.cohort_id = ? AND bm.is_active = 1
        ORDER BY bg.name, bm.nickname
    ");
    $stmt->execute([$cohortId]);
    $members = $stmt->fetchAll();
    $memberIds = array_column($members, 'id');

    if (empty($memberIds)) {
        jsonSuccess([
            'scoring_start' => $scoringStart,
            'scoring_end' => $scoringEnd,
            'total_days' => $totalDays,
            'total_mondays' => $totalMondays,
            'cohort_summary' => null,
            'groups' => [],
            'members' => [],
            'score_distribution' => [],
            'score_warnings' => ['approaching' => [], 'revival_eligible' => [], 'out' => []],
        ]);
        return;
    }

    // 4. 미션 타입 코드→ID 매핑
    $codeToId = getMissionCodeToIdMap($db);
    $zoomId = $codeToId['zoom_daily'] ?? null;
    $dailyId = $codeToId['daily_mission'] ?? null;
    $inner33Id = $codeToId['inner33'] ?? null;
    $speakId = $codeToId['speak_mission'] ?? null;
    $bookOpenId = $codeToId['bookclub_open'] ?? null;
    $bookJoinId = $codeToId['bookclub_join'] ?? null;
    $hamemmalId = $codeToId['hamemmal'] ?? null;

    // 5. 해당 기간의 모든 체크 데이터 조회
    $ph = implode(',', array_fill(0, count($memberIds), '?'));
    $stmt = $db->prepare("
        SELECT member_id, check_date, mission_type_id, status
        FROM member_mission_checks
        WHERE member_id IN ({$ph})
          AND check_date BETWEEN ? AND ?
    ");
    $stmt->execute(array_merge($memberIds, [$scoringStart, $scoringEnd]));

    // member_id → date → mission_type_id → status
    $checkData = [];
    foreach ($stmt->fetchAll() as $c) {
        $checkData[(int)$c['member_id']][$c['check_date']][(int)$c['mission_type_id']] = (int)$c['status'];
    }

    // 6. 멤버별 집계
    $memberResults = [];
    $groupAgg = []; // group_id => aggregated data

    foreach ($members as $m) {
        $mid = (int)$m['id'];
        $gid = $m['group_id'] ? (int)$m['group_id'] : 0;
        $byDate = $checkData[$mid] ?? [];

        // 필수 과제 집계
        $zoomDone = 0;
        $inner33Done = 0;
        $speakDone = 0;

        // 선택 참여 집계
        $bookOpenCount = 0;
        $bookJoinCount = 0;
        $hamemmalCount = 0;

        $cur = $scoringStart;
        while ($cur <= $scoringEnd) {
            $missions = $byDate[$cur] ?? [];
            $dow = (int)date('w', strtotime($cur));

            // zoom_daily OR daily_mission
            $zoomPass = false;
            if ($zoomId && ($missions[$zoomId] ?? 0) === 1) $zoomPass = true;
            if ($dailyId && ($missions[$dailyId] ?? 0) === 1) $zoomPass = true;
            if ($zoomPass) $zoomDone++;

            // inner33
            if ($inner33Id && ($missions[$inner33Id] ?? 0) === 1) $inner33Done++;

            // speak_mission (월요일만)
            if ($dow === 1 && $speakId && ($missions[$speakId] ?? 0) === 1) $speakDone++;

            // 선택 참여 (모든 날짜)
            if ($bookOpenId && ($missions[$bookOpenId] ?? 0) === 1) $bookOpenCount++;
            if ($bookJoinId && ($missions[$bookJoinId] ?? 0) === 1) $bookJoinCount++;
            if ($hamemmalId && ($missions[$hamemmalId] ?? 0) === 1) $hamemmalCount++;

            $cur = date('Y-m-d', strtotime($cur . ' +1 day'));
        }

        $zoomRate = $totalDays > 0 ? round($zoomDone / $totalDays * 100, 1) : 0;
        $inner33Rate = $totalDays > 0 ? round($inner33Done / $totalDays * 100, 1) : 0;
        $speakRate = $totalMondays > 0 ? round($speakDone / $totalMondays * 100, 1) : 0;
        $avgRate = round(($zoomRate + $inner33Rate + $speakRate) / 3, 1);

        $memberResult = [
            'id' => $mid,
            'nickname' => $m['nickname'],
            'real_name' => $m['real_name'],
            'group_id' => $gid,
            'group_name' => $m['group_name'] ?? '',
            'member_role' => $m['member_role'],
            'current_score' => (int)$m['current_score'],
            'member_status' => $m['member_status'],
            'required' => [
                'zoom_daily' => ['done' => $zoomDone, 'total' => $totalDays, 'rate' => $zoomRate],
                'inner33' => ['done' => $inner33Done, 'total' => $totalDays, 'rate' => $inner33Rate],
                'speak_mission' => ['done' => $speakDone, 'total' => $totalMondays, 'rate' => $speakRate],
                'avg_rate' => $avgRate,
            ],
            'optional' => [
                'bookclub_open' => $bookOpenCount,
                'bookclub_join' => $bookJoinCount,
                'hamemmal' => $hamemmalCount,
            ],
        ];
        $memberResults[] = $memberResult;

        // 조별 집계 누적
        if (!isset($groupAgg[$gid])) {
            $groupAgg[$gid] = [
                'id' => $gid,
                'name' => $m['group_name'] ?? '미배정',
                'member_count' => 0,
                'zoom_sum' => 0, 'inner33_sum' => 0, 'speak_sum' => 0,
                'book_open_sum' => 0, 'book_join_sum' => 0, 'hamemmal_sum' => 0,
            ];
        }
        $groupAgg[$gid]['member_count']++;
        $groupAgg[$gid]['zoom_sum'] += $zoomDone;
        $groupAgg[$gid]['inner33_sum'] += $inner33Done;
        $groupAgg[$gid]['speak_sum'] += $speakDone;
        $groupAgg[$gid]['book_open_sum'] += $bookOpenCount;
        $groupAgg[$gid]['book_join_sum'] += $bookJoinCount;
        $groupAgg[$gid]['hamemmal_sum'] += $hamemmalCount;
    }

    // 6.5. 조별 코치 조회
    $groupIds = array_keys($groupAgg);
    $coachMap = []; // group_id => "코치1, 코치2"
    if ($groupIds) {
        $gph = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $db->prepare("
            SELECT cga.group_id, a.name
            FROM coach_group_assignments cga
            JOIN admins a ON cga.admin_id = a.id AND a.is_active = 1
            WHERE cga.group_id IN ({$gph})
            ORDER BY a.name
        ");
        $stmt->execute($groupIds);
        foreach ($stmt->fetchAll() as $row) {
            $gid = (int)$row['group_id'];
            $coachMap[$gid] = isset($coachMap[$gid]) ? $coachMap[$gid] . ', ' . $row['name'] : $row['name'];
        }
    }

    // 7. 조별 비율 계산
    $groupResults = [];
    foreach ($groupAgg as $g) {
        $mc = $g['member_count'];
        $groupResults[] = [
            'id' => $g['id'],
            'name' => $g['name'],
            'coach' => $coachMap[$g['id']] ?? '',
            'member_count' => $mc,
            'zoom_daily_rate' => $zdr = ($mc * $totalDays > 0) ? round($g['zoom_sum'] / ($mc * $totalDays) * 100, 1) : 0,
            'inner33_rate' => $i3r = ($mc * $totalDays > 0) ? round($g['inner33_sum'] / ($mc * $totalDays) * 100, 1) : 0,
            'speak_rate' => $spr = ($mc * $totalMondays > 0) ? round($g['speak_sum'] / ($mc * $totalMondays) * 100, 1) : 0,
            'avg_rate' => round(($zdr + $i3r + $spr) / 3, 1),
            'optional_avg' => [
                'bookclub_open' => $mc > 0 ? round($g['book_open_sum'] / $mc, 1) : 0,
                'bookclub_join' => $mc > 0 ? round($g['book_join_sum'] / $mc, 1) : 0,
                'hamemmal' => $mc > 0 ? round($g['hamemmal_sum'] / $mc, 1) : 0,
            ],
        ];
    }
    // 조 이름 순 정렬
    usort($groupResults, fn($a, $b) => strcmp($a['name'], $b['name']));

    // 8. 기수 전체 요약
    $totalMembers = count($members);
    $cohortZoomSum = array_sum(array_column($groupAgg, 'zoom_sum'));
    $cohortInner33Sum = array_sum(array_column($groupAgg, 'inner33_sum'));
    $cohortSpeakSum = array_sum(array_column($groupAgg, 'speak_sum'));
    $cohortBookOpenSum = array_sum(array_column($groupAgg, 'book_open_sum'));
    $cohortBookJoinSum = array_sum(array_column($groupAgg, 'book_join_sum'));
    $cohortHamemmalSum = array_sum(array_column($groupAgg, 'hamemmal_sum'));

    $cohortSummary = [
        'member_count' => $totalMembers,
        'zoom_daily_rate' => $csZdr = ($totalMembers * $totalDays > 0) ? round($cohortZoomSum / ($totalMembers * $totalDays) * 100, 1) : 0,
        'inner33_rate' => $csI3r = ($totalMembers * $totalDays > 0) ? round($cohortInner33Sum / ($totalMembers * $totalDays) * 100, 1) : 0,
        'speak_rate' => $csSpr = ($totalMembers * $totalMondays > 0) ? round($cohortSpeakSum / ($totalMembers * $totalMondays) * 100, 1) : 0,
        'avg_rate' => round(($csZdr + $csI3r + $csSpr) / 3, 1),
        'optional_avg' => [
            'bookclub_open' => $totalMembers > 0 ? round($cohortBookOpenSum / $totalMembers, 1) : 0,
            'bookclub_join' => $totalMembers > 0 ? round($cohortBookJoinSum / $totalMembers, 1) : 0,
            'hamemmal' => $totalMembers > 0 ? round($cohortHamemmalSum / $totalMembers, 1) : 0,
        ],
    ];

    // 9. 점수 분포
    $scoreBuckets = [
        ['range' => '0 ~ -4', 'min' => -4, 'max' => 0, 'count' => 0],
        ['range' => '-5 ~ -9', 'min' => -9, 'max' => -5, 'count' => 0],
        ['range' => '-10 ~ -14', 'min' => -14, 'max' => -10, 'count' => 0],
        ['range' => '-15 ~ -19', 'min' => -19, 'max' => -15, 'count' => 0],
        ['range' => '-20 ~ -24', 'min' => -24, 'max' => -20, 'count' => 0],
        ['range' => '-25 이하', 'min' => -9999, 'max' => -25, 'count' => 0],
    ];
    foreach ($members as $m) {
        $score = (int)$m['current_score'];
        foreach ($scoreBuckets as &$bucket) {
            if ($score >= $bucket['min'] && $score <= $bucket['max']) {
                $bucket['count']++;
                break;
            }
        }
        unset($bucket);
    }
    $scoreDistribution = array_map(fn($b) => ['range' => $b['range'], 'count' => $b['count']], $scoreBuckets);

    // 10. 경고 멤버
    $approaching = [];
    $revivalEligible = [];
    $out = [];
    foreach ($memberResults as $mr) {
        $score = $mr['current_score'];
        $info = ['id' => $mr['id'], 'nickname' => $mr['nickname'], 'group_name' => $mr['group_name'], 'current_score' => $score];
        if ($score <= SCORE_OUT_THRESHOLD) {
            $out[] = $info;
        } elseif ($score <= SCORE_REVIVAL_ELIGIBLE) {
            $revivalEligible[] = $info;
        } elseif ($score <= SCORE_REVIVAL_CANDIDATE) {
            $approaching[] = $info;
        }
    }

    jsonSuccess([
        'scoring_start' => $scoringStart,
        'scoring_end' => $scoringEnd,
        'total_days' => $totalDays,
        'total_mondays' => $totalMondays,
        'cohort_summary' => $cohortSummary,
        'groups' => $groupResults,
        'members' => $memberResults,
        'score_distribution' => $scoreDistribution,
        'score_warnings' => [
            'approaching' => $approaching,
            'revival_eligible' => $revivalEligible,
            'out' => $out,
        ],
    ]);
}
