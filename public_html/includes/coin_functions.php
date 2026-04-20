<?php
/**
 * boot.soritune.com - Coin Functions
 * 코인 핵심 비즈니스 로직
 * saveCheck()에서 호출되며, coin_cycle 기반으로 동작
 */

// 코인 정책 상수
if (!defined('COIN_STUDY_OPEN_AMOUNT'))  define('COIN_STUDY_OPEN_AMOUNT', 5);
if (!defined('COIN_STUDY_OPEN_MAX'))     define('COIN_STUDY_OPEN_MAX', 10);
if (!defined('COIN_STUDY_JOIN_AMOUNT'))  define('COIN_STUDY_JOIN_AMOUNT', 2);
if (!defined('COIN_STUDY_JOIN_MAX'))     define('COIN_STUDY_JOIN_MAX', 15);
if (!defined('COIN_LEADER'))             define('COIN_LEADER', 100);
if (!defined('COIN_SUBLEADER'))          define('COIN_SUBLEADER', 50);
if (!defined('COIN_PERFECT_ATTENDANCE')) define('COIN_PERFECT_ATTENDANCE', 15);
if (!defined('COIN_HAMEMMAL'))           define('COIN_HAMEMMAL', 10);
if (!defined('COIN_HAMEMMAL_THRESHOLD')) define('COIN_HAMEMMAL_THRESHOLD', 21);
if (!defined('COIN_CHEER_AMOUNT'))       define('COIN_CHEER_AMOUNT', 10);
if (!defined('COIN_CHEER_MAX_TARGETS'))  define('COIN_CHEER_MAX_TARGETS', 3);
if (!defined('COIN_CYCLE_MAX'))          define('COIN_CYCLE_MAX', 200);

// 복습스터디 개설 의무 횟수 (이 횟수까지는 코인 미지급)
if (!defined('COIN_STUDY_OPEN_DUTY_LEADER'))    define('COIN_STUDY_OPEN_DUTY_LEADER', 4);
if (!defined('COIN_STUDY_OPEN_DUTY_SUBLEADER')) define('COIN_STUDY_OPEN_DUTY_SUBLEADER', 2);

// ══════════════════════════════════════════════════════════════
// Cycle 조회
// ══════════════════════════════════════════════════════════════

/**
 * 날짜가 속한 coin cycle 반환 (active/closed 모두)
 * @return array|null cycle row or null
 */
function getCycleForDate($db, $date) {
    $stmt = $db->prepare("
        SELECT * FROM coin_cycles
        WHERE start_date <= ? AND end_date >= ?
        LIMIT 1
    ");
    $stmt->execute([$date, $date]);
    return $stmt->fetch() ?: null;
}

/**
 * 현재 active cycle 반환
 */
function getActiveCycle($db) {
    $stmt = $db->query("SELECT * FROM coin_cycles WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
    return $stmt->fetch() ?: null;
}

// ══════════════════════════════════════════════════════════════
// Member Cycle Coins 조회/생성
// ══════════════════════════════════════════════════════════════

/**
 * member_cycle_coins row 반환 (없으면 자동 생성)
 */
function getOrCreateMemberCycleCoins($db, $memberId, $cycleId) {
    $stmt = $db->prepare("SELECT * FROM member_cycle_coins WHERE member_id = ? AND cycle_id = ?");
    $stmt->execute([$memberId, $cycleId]);
    $row = $stmt->fetch();

    if (!$row) {
        $db->prepare("
            INSERT INTO member_cycle_coins (member_id, cycle_id) VALUES (?, ?)
        ")->execute([$memberId, $cycleId]);
        $stmt->execute([$memberId, $cycleId]);
        $row = $stmt->fetch();
    }

    return $row;
}

// ══════════════════════════════════════════════════════════════
// 코인 변동 공통
// ══════════════════════════════════════════════════════════════

/**
 * 코인 변동 적용 (cycle 기준 캡 적용, 음수 허용)
 *
 * @return array ['before' => int, 'after' => int, 'applied' => int]
 */
function applyCoinChange($db, $memberId, $cycleId, $coinChange, $reasonType, $reasonDetail = null, $adminId = null) {
    $mcc = getOrCreateMemberCycleCoins($db, $memberId, $cycleId);
    $cycleStmt = $db->prepare("SELECT max_coin FROM coin_cycles WHERE id = ?");
    $cycleStmt->execute([$cycleId]);
    $cycle = $cycleStmt->fetch();
    $maxCoin = $cycle ? (int)$cycle['max_coin'] : COIN_CYCLE_MAX;

    $beforeEarned = (int)$mcc['earned_coin'];
    $newEarned = $beforeEarned + $coinChange;

    // 양수 방향(적립)일 때만 캡 적용
    if ($coinChange > 0 && $newEarned > $maxCoin) {
        $coinChange = max(0, $maxCoin - $beforeEarned);
        $newEarned = $beforeEarned + $coinChange;
    }

    // 변동이 0이면 skip
    if ($coinChange === 0) {
        return ['before' => $beforeEarned, 'after' => $beforeEarned, 'applied' => 0];
    }

    // member_cycle_coins 업데이트
    $db->prepare("
        UPDATE member_cycle_coins SET earned_coin = ? WHERE member_id = ? AND cycle_id = ?
    ")->execute([$newEarned, $memberId, $cycleId]);

    // coin_logs 기록
    $db->prepare("
        INSERT INTO coin_logs (member_id, cycle_id, coin_change, before_coin, after_coin, reason_type, reason_detail, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$memberId, $cycleId, $coinChange, $beforeEarned, $newEarned, $reasonType, $reasonDetail, $adminId]);

    // member_coin_balances 동기화 (전체 cycle 합산)
    syncMemberCoinBalance($db, $memberId);

    return ['before' => $beforeEarned, 'after' => $newEarned, 'applied' => $coinChange];
}

/**
 * member_coin_balances를 전체 cycle 합산으로 동기화
 */
function syncMemberCoinBalance($db, $memberId) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(earned_coin - used_coin), 0) AS total FROM member_cycle_coins WHERE member_id = ?");
    $stmt->execute([$memberId]);
    $total = (int)$stmt->fetchColumn();

    $db->prepare("
        INSERT INTO member_coin_balances (member_id, current_coin)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE current_coin = VALUES(current_coin)
    ")->execute([$memberId, $total]);
}

// ══════════════════════════════════════════════════════════════
// 체크리스트 연동
// ══════════════════════════════════════════════════════════════

/**
 * 체크리스트 저장 후 코인 자동 지급/차감
 * saveCheck()에서 호출됨
 *
 * @param int $newStatus  새 상태 (1 or 0)
 * @param int|null $prevStatus  이전 상태 (null이면 신규)
 */
function processCoinForCheck($db, $memberId, $checkDate, $missionCode, $newStatus, $prevStatus, $adminId) {
    // bookclub_open / bookclub_join만 코인 대상
    $coinMap = [
        'bookclub_open' => ['amount' => COIN_STUDY_OPEN_AMOUNT, 'counter' => 'study_open_count', 'max' => COIN_STUDY_OPEN_MAX, 'reason' => 'study_open'],
        'bookclub_join' => ['amount' => COIN_STUDY_JOIN_AMOUNT, 'counter' => 'study_join_count', 'max' => COIN_STUDY_JOIN_MAX, 'reason' => 'study_join'],
    ];

    if (!isset($coinMap[$missionCode])) return;

    $config = $coinMap[$missionCode];

    // 변동 방향 판별
    $wasChecked = ($prevStatus !== null && (int)$prevStatus === 1);
    $isChecked  = ((int)$newStatus === 1);

    if ($wasChecked === $isChecked) return; // 실제 변경 없음

    // cycle 조회
    $cycle = getCycleForDate($db, $checkDate);
    if (!$cycle) return; // 해당 날짜에 cycle 없으면 skip

    $cycleId = (int)$cycle['id'];
    $mcc = getOrCreateMemberCycleCoins($db, $memberId, $cycleId);

    // bookclub_open 의무 횟수 체크 (조장/부조장)
    $dutyCount = 0;
    if ($missionCode === 'bookclub_open') {
        $dutyCount = getStudyOpenDutyCount($db, $memberId);
    }

    if ($isChecked) {
        // 체크: 지급
        $currentCount = (int)$mcc[$config['counter']];
        if ($currentCount >= $config['max']) return; // 캡 도달

        // 의무 횟수 이내이면 코인 미지급
        if ($dutyCount > 0) {
            $totalChecks = countMissionChecksInCycle($db, $memberId, $missionCode, $cycle['start_date'], $cycle['end_date']);
            if ($totalChecks <= $dutyCount) return; // 의무 범위 내 → 스킵
        }

        $result = applyCoinChange($db, $memberId, $cycleId, $config['amount'], $config['reason'],
            "{$missionCode} check {$checkDate}", $adminId);

        if ($result['applied'] > 0) {
            // 카운터 증가
            $db->prepare("
                UPDATE member_cycle_coins SET {$config['counter']} = {$config['counter']} + 1
                WHERE member_id = ? AND cycle_id = ?
            ")->execute([$memberId, $cycleId]);
        }
    } else {
        // 체크 해제: 차감
        $currentCount = (int)$mcc[$config['counter']];
        if ($currentCount <= 0) return; // 차감할 것 없음

        // 해제 후 남은 체크 수가 의무 이상일 때만 차감 (의무 범위로 돌아가면 차감 불필요)
        if ($dutyCount > 0) {
            $totalChecks = countMissionChecksInCycle($db, $memberId, $missionCode, $cycle['start_date'], $cycle['end_date']);
            if ($totalChecks < $dutyCount) return; // 이미 의무 범위 내 → 차감 불필요
        }

        applyCoinChange($db, $memberId, $cycleId, -$config['amount'], $config['reason'],
            "{$missionCode} uncheck {$checkDate}", $adminId);

        // 카운터 감소
        $db->prepare("
            UPDATE member_cycle_coins SET {$config['counter']} = GREATEST({$config['counter']} - 1, 0)
            WHERE member_id = ? AND cycle_id = ?
        ")->execute([$memberId, $cycleId]);
    }
}

/**
 * 멤버의 역할에 따른 복습스터디 개설 의무 횟수 반환
 */
function getStudyOpenDutyCount($db, $memberId) {
    $stmt = $db->prepare("SELECT member_role FROM bootcamp_members WHERE id = ?");
    $stmt->execute([$memberId]);
    $role = $stmt->fetchColumn();

    if ($role === 'leader')    return COIN_STUDY_OPEN_DUTY_LEADER;
    if ($role === 'subleader') return COIN_STUDY_OPEN_DUTY_SUBLEADER;
    return 0;
}

/**
 * 사이클 기간 내 특정 미션의 체크(status=1) 횟수
 */
function countMissionChecksInCycle($db, $memberId, $missionCode, $startDate, $endDate) {
    $codeToId = getMissionCodeToIdMap($db);
    $typeId = $codeToId[$missionCode] ?? null;
    if (!$typeId) return 0;

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM member_mission_checks
        WHERE member_id = ? AND mission_type_id = ? AND status = 1
          AND check_date >= ? AND check_date <= ?
    ");
    $stmt->execute([$memberId, $typeId, $startDate, $endDate]);
    return (int)$stmt->fetchColumn();
}

// ══════════════════════════════════════════════════════════════
// 리더 코인
// ══════════════════════════════════════════════════════════════

/**
 * 리더/부조장 코인 지급
 * @return array ['applied' => int] or ['skipped' => true]
 */
function grantLeaderCoin($db, $memberId, $cycleId, $role, $adminId) {
    $amount = ($role === 'leader') ? COIN_LEADER : (($role === 'subleader') ? COIN_SUBLEADER : 0);
    if ($amount === 0) return ['skipped' => true, 'reason' => 'not a leader role'];

    $mcc = getOrCreateMemberCycleCoins($db, $memberId, $cycleId);
    if ((int)$mcc['leader_coin_granted'] === 1) {
        return ['skipped' => true, 'reason' => 'already granted'];
    }

    $result = applyCoinChange($db, $memberId, $cycleId, $amount, 'leader_coin',
        "{$role} coin", $adminId);

    $db->prepare("
        UPDATE member_cycle_coins SET leader_coin_granted = 1 WHERE member_id = ? AND cycle_id = ?
    ")->execute([$memberId, $cycleId]);

    return $result;
}

/**
 * 리더 코인 회수 (role 변경 시)
 */
function revokeLeaderCoin($db, $memberId, $cycleId, $prevRole, $adminId) {
    $amount = ($prevRole === 'leader') ? COIN_LEADER : (($prevRole === 'subleader') ? COIN_SUBLEADER : 0);
    if ($amount === 0) return ['skipped' => true];

    $mcc = getOrCreateMemberCycleCoins($db, $memberId, $cycleId);
    if ((int)$mcc['leader_coin_granted'] === 0) {
        return ['skipped' => true, 'reason' => 'not granted'];
    }

    $result = applyCoinChange($db, $memberId, $cycleId, -$amount, 'leader_coin',
        "{$prevRole} coin revoked", $adminId);

    $db->prepare("
        UPDATE member_cycle_coins SET leader_coin_granted = 0 WHERE member_id = ? AND cycle_id = ?
    ")->execute([$memberId, $cycleId]);

    return $result;
}

/**
 * role 변경 시 코인 처리 (member_update에서 호출)
 */
function handleRoleChangeCoin($db, $memberId, $beforeRole, $afterRole, $adminId) {
    $today = date('Y-m-d');
    $cycle = getCycleForDate($db, $today);
    if (!$cycle) return; // cycle 없으면 skip

    $cycleId = (int)$cycle['id'];
    $leaderRoles = ['leader', 'subleader'];

    $wasCoinRole = in_array($beforeRole, $leaderRoles);
    $isCoinRole  = in_array($afterRole, $leaderRoles);

    if ($wasCoinRole && !$isCoinRole) {
        // 리더 → 일반: 회수
        revokeLeaderCoin($db, $memberId, $cycleId, $beforeRole, $adminId);
    } elseif (!$wasCoinRole && $isCoinRole) {
        // 일반 → 리더: 지급
        grantLeaderCoin($db, $memberId, $cycleId, $afterRole, $adminId);
    } elseif ($wasCoinRole && $isCoinRole && $beforeRole !== $afterRole) {
        // 조장 ↔ 부조장: 회수 후 지급
        revokeLeaderCoin($db, $memberId, $cycleId, $beforeRole, $adminId);
        // leader_coin_granted가 0으로 리셋되었으므로 바로 지급 가능
        grantLeaderCoin($db, $memberId, $cycleId, $afterRole, $adminId);
    }
}

// ══════════════════════════════════════════════════════════════
// 찐완주 판정 (penalty 계산 — recalculateMemberScore 로직 복제)
// ══════════════════════════════════════════════════════════════

/**
 * 기간 내 penalty 합산 (score 업데이트 없이 판정만)
 * recalculateMemberScore()의 L112-134 로직을 복제
 *
 * @return int penaltySum (0이면 감점 없음 = 찐완주)
 */
function calcPenaltyForPeriod($db, $memberId, $startDate, $endDate) {
    $codeToId = getMissionCodeToIdMap($db);

    $stmt = $db->prepare("
        SELECT check_date, mission_type_id, status FROM member_mission_checks
        WHERE member_id = ? AND check_date >= ? AND check_date <= ?
        ORDER BY check_date
    ");
    $stmt->execute([$memberId, $startDate, $endDate]);
    $byDate = [];
    foreach ($stmt->fetchAll() as $c) {
        $byDate[$c['check_date']][(int)$c['mission_type_id']] = (int)$c['status'];
    }

    $rules = getPenaltyRules();
    $penaltySum = 0;

    $current = $startDate;
    while ($current <= $endDate) {
        $missions = $byDate[$current] ?? [];
        $dow = (int)date('w', strtotime($current));

        foreach ($rules as $rule) {
            if ($rule['weekday'] !== null && $dow !== $rule['weekday']) continue;

            $passed = false;
            foreach ($rule['codes'] as $code) {
                $typeId = $codeToId[$code] ?? null;
                if ($typeId && ($missions[$typeId] ?? 0) === 1) {
                    $passed = true;
                    break;
                }
            }
            if (!$passed) {
                $penaltySum += $rule['penalty'];
            }
        }

        $current = date('Y-m-d', strtotime($current . ' +1 day'));
    }

    return $penaltySum;
}

// ══════════════════════════════════════════════════════════════
// 배치 정산 (찐완주 + 하멈말)
// ══════════════════════════════════════════════════════════════

/**
 * 정산 미리보기 (실제 지급 없음)
 * @return array ['members' => [...], 'summary' => [...]]
 */
function previewSettlement($db, $cycleId) {
    $cycleStmt = $db->prepare("SELECT * FROM coin_cycles WHERE id = ?");
    $cycleStmt->execute([$cycleId]);
    $cycle = $cycleStmt->fetch();
    if (!$cycle) return null;

    // cycle 기간에 활동 중인 회원 (어떤 cohort든)
    $members = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.cohort_id, bm.member_role,
               bg.name AS group_name,
               COALESCE(mcc.earned_coin, 0) AS earned_coin,
               COALESCE(mcc.perfect_attendance_granted, 0) AS pa_granted,
               COALESCE(mcc.hamemmal_granted, 0) AS hm_granted
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_cycle_coins mcc ON bm.id = mcc.member_id AND mcc.cycle_id = ?
        WHERE bm.is_active = 1 AND bm.member_status != 'refunded'
        ORDER BY bg.name, bm.nickname
    ");
    $members->execute([$cycleId]);
    $members = $members->fetchAll();

    // 하멈말 mission_type_id
    $hamemmalTypeId = getMissionTypeId($db, 'hamemmal');

    // cohort의 적응기간 고려
    $cohortAdaptationEnds = [];

    $result = [];
    foreach ($members as $m) {
        $mid = (int)$m['id'];

        // 적응기간 종료일 계산 (cohort별 캐시)
        $cid = (int)$m['cohort_id'];
        if (!isset($cohortAdaptationEnds[$cid])) {
            $cStmt = $db->prepare("SELECT start_date FROM cohorts WHERE id = ?");
            $cStmt->execute([$cid]);
            $cRow = $cStmt->fetch();
            $cohortAdaptationEnds[$cid] = $cRow
                ? date('Y-m-d', strtotime($cRow['start_date'] . ' + ' . SCORE_ADAPTATION_DAYS . ' days'))
                : $cycle['start_date'];
        }

        // 찐완주: cycle 기간 내 penalty (적응기간 이후부터)
        $penaltyStart = max($cycle['start_date'], $cohortAdaptationEnds[$cid]);
        $penalty = calcPenaltyForPeriod($db, $mid, $penaltyStart, $cycle['end_date']);
        $perfectAttendance = ($penalty === 0) && !(int)$m['pa_granted'];

        // 하멈말: cycle 기간 내 hamemmal 체크 횟수
        $hmCount = 0;
        if ($hamemmalTypeId) {
            $hmStmt = $db->prepare("
                SELECT COUNT(*) FROM member_mission_checks
                WHERE member_id = ? AND mission_type_id = ? AND status = 1
                  AND check_date >= ? AND check_date <= ?
            ");
            $hmStmt->execute([$mid, $hamemmalTypeId, $cycle['start_date'], $cycle['end_date']]);
            $hmCount = (int)$hmStmt->fetchColumn();
        }
        $hamemmalEligible = ($hmCount >= COIN_HAMEMMAL_THRESHOLD) && !(int)$m['hm_granted'];

        $entry = [
            'member_id' => $mid,
            'nickname' => $m['nickname'],
            'real_name' => $m['real_name'],
            'group_name' => $m['group_name'],
            'earned_coin' => (int)$m['earned_coin'],
            'penalty' => $penalty,
            'perfect_attendance' => $perfectAttendance,
            'perfect_attendance_coin' => $perfectAttendance ? COIN_PERFECT_ATTENDANCE : 0,
            'hamemmal_count' => $hmCount,
            'hamemmal_eligible' => $hamemmalEligible,
            'hamemmal_coin' => $hamemmalEligible ? COIN_HAMEMMAL : 0,
        ];
        $result[] = $entry;
    }

    $paCount = count(array_filter($result, fn($r) => $r['perfect_attendance']));
    $hmEligibleCount = count(array_filter($result, fn($r) => $r['hamemmal_eligible']));

    return [
        'cycle' => $cycle,
        'members' => $result,
        'summary' => [
            'total_members' => count($result),
            'perfect_attendance_count' => $paCount,
            'perfect_attendance_total_coin' => $paCount * COIN_PERFECT_ATTENDANCE,
            'hamemmal_count' => $hmEligibleCount,
            'hamemmal_total_coin' => $hmEligibleCount * COIN_HAMEMMAL,
        ],
    ];
}

/**
 * 정산 실행 (찐완주 + 하멈말)
 * @return array 처리 결과
 */
function executeSettlement($db, $cycleId, $adminId) {
    $preview = previewSettlement($db, $cycleId);
    if (!$preview) return ['error' => 'cycle not found'];

    $results = ['perfect_attendance' => 0, 'hamemmal' => 0, 'skipped' => 0];

    foreach ($preview['members'] as $m) {
        $mid = $m['member_id'];

        if ($m['perfect_attendance']) {
            applyCoinChange($db, $mid, $cycleId, COIN_PERFECT_ATTENDANCE, 'perfect_attendance',
                "찐완주 (penalty={$m['penalty']})", $adminId);
            $db->prepare("
                UPDATE member_cycle_coins SET perfect_attendance_granted = 1
                WHERE member_id = ? AND cycle_id = ?
            ")->execute([$mid, $cycleId]);
            $results['perfect_attendance']++;
        }

        if ($m['hamemmal_eligible']) {
            applyCoinChange($db, $mid, $cycleId, COIN_HAMEMMAL, 'hamemmal_bonus',
                "하멈말 {$m['hamemmal_count']}회", $adminId);
            $db->prepare("
                UPDATE member_cycle_coins SET hamemmal_granted = 1
                WHERE member_id = ? AND cycle_id = ?
            ")->execute([$mid, $cycleId]);
            $results['hamemmal']++;
        }

        if (!$m['perfect_attendance'] && !$m['hamemmal_eligible']) {
            $results['skipped']++;
        }
    }

    return $results;
}

// ══════════════════════════════════════════════════════════════
// 응원상
// ══════════════════════════════════════════════════════════════

/**
 * 응원상 지급
 * @param array $targetMemberIds 대상 회원 ID 배열 (최대 3명)
 * @return array 결과
 */
function grantCheerAward($db, $cycleId, $leaderMemberId, $targetMemberIds, $adminId) {
    // 조장의 group_id 확인
    $leaderStmt = $db->prepare("SELECT group_id, member_role FROM bootcamp_members WHERE id = ? AND is_active = 1");
    $leaderStmt->execute([$leaderMemberId]);
    $leader = $leaderStmt->fetch();
    if (!$leader) return ['error' => '조장 정보를 찾을 수 없습니다.'];
    if (!in_array($leader['member_role'], ['leader', 'subleader'])) return ['error' => '조장/부조장만 응원상을 줄 수 있습니다.'];
    if (!$leader['group_id']) return ['error' => '조가 배정되지 않았습니다.'];

    // 이미 선택한 수 확인
    $existingStmt = $db->prepare("
        SELECT COUNT(*) FROM leader_cheer_awards WHERE cycle_id = ? AND leader_member_id = ?
    ");
    $existingStmt->execute([$cycleId, $leaderMemberId]);
    $existingCount = (int)$existingStmt->fetchColumn();
    $remaining = COIN_CHEER_MAX_TARGETS - $existingCount;

    if (count($targetMemberIds) > $remaining) {
        return ['error' => "응원상은 최대 " . COIN_CHEER_MAX_TARGETS . "명까지입니다. 남은 선택 가능: {$remaining}명"];
    }

    $results = ['granted' => 0, 'errors' => []];
    foreach ($targetMemberIds as $targetId) {
        $targetId = (int)$targetId;
        if ($targetId === $leaderMemberId) {
            $results['errors'][] = ['member_id' => $targetId, 'reason' => '본인에게는 줄 수 없습니다.'];
            continue;
        }

        // 같은 조 확인
        $tStmt = $db->prepare("SELECT id, group_id, nickname FROM bootcamp_members WHERE id = ? AND is_active = 1");
        $tStmt->execute([$targetId]);
        $target = $tStmt->fetch();
        if (!$target || (int)$target['group_id'] !== (int)$leader['group_id']) {
            $results['errors'][] = ['member_id' => $targetId, 'reason' => '같은 조의 회원만 선택할 수 있습니다.'];
            continue;
        }

        // 중복 체크
        $dupStmt = $db->prepare("
            SELECT id FROM leader_cheer_awards WHERE cycle_id = ? AND leader_member_id = ? AND target_member_id = ?
        ");
        $dupStmt->execute([$cycleId, $leaderMemberId, $targetId]);
        if ($dupStmt->fetch()) {
            $results['errors'][] = ['member_id' => $targetId, 'reason' => '이미 선택된 회원입니다.'];
            continue;
        }

        // 기록 + 코인 지급
        $db->prepare("
            INSERT INTO leader_cheer_awards (cycle_id, leader_member_id, target_member_id, coin_amount)
            VALUES (?, ?, ?, ?)
        ")->execute([$cycleId, $leaderMemberId, $targetId, COIN_CHEER_AMOUNT]);

        applyCoinChange($db, $targetId, $cycleId, COIN_CHEER_AMOUNT, 'cheer_award',
            "응원상 from member:{$leaderMemberId}", $adminId);

        $results['granted']++;
    }

    return $results;
}

// ══════════════════════════════════════════════════════════════
// Reward Groups (리워드 구간)
// ══════════════════════════════════════════════════════════════

/**
 * 회원의 현재 open reward group 반환 (해당 member가 member_cycle_coins row를 가진 cycle 중).
 * 여러 open group에 걸쳐있으면 cycle end_date 최신 기준으로 하나 선택.
 */
function getCurrentRewardGroupForMember($db, $memberId) {
    $stmt = $db->prepare("
        SELECT rg.id, rg.name, rg.status
        FROM reward_groups rg
        JOIN coin_cycles cc ON cc.reward_group_id = rg.id
        JOIN member_cycle_coins mcc ON mcc.cycle_id = cc.id AND mcc.member_id = ?
        WHERE rg.status = 'open'
        ORDER BY cc.end_date DESC
        LIMIT 1
    ");
    $stmt->execute([$memberId]);
    $group = $stmt->fetch();
    if (!$group) return null;

    // 소속 cycle들 + 해당 회원의 earned, cycle status
    $cStmt = $db->prepare("
        SELECT cc.id, cc.name, cc.status,
               COALESCE(mcc.earned_coin, 0) AS earned,
               COALESCE(mcc.used_coin, 0)   AS used
        FROM coin_cycles cc
        LEFT JOIN member_cycle_coins mcc ON mcc.cycle_id = cc.id AND mcc.member_id = ?
        WHERE cc.reward_group_id = ?
        ORDER BY cc.start_date
    ");
    $cStmt->execute([$memberId, $group['id']]);
    $cycles = [];
    foreach ($cStmt->fetchAll() as $c) {
        $cycles[] = [
            'name'    => $c['name'],
            'earned'  => (int)$c['earned'] - (int)$c['used'],
            'settled' => $c['status'] === 'closed',
        ];
    }

    return [
        'name'   => $group['name'],
        'cycles' => $cycles,
    ];
}

/**
 * reward group 조회 (속한 cycle 목록 포함)
 */
function getRewardGroupWithCycles($db, $groupId) {
    $stmt = $db->prepare("SELECT * FROM reward_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();
    if (!$group) return null;

    $cStmt = $db->prepare("
        SELECT id, name, start_date, end_date, status
        FROM coin_cycles WHERE reward_group_id = ?
        ORDER BY start_date
    ");
    $cStmt->execute([$groupId]);
    $group['cycles'] = $cStmt->fetchAll();

    return $group;
}

/**
 * 지급 사전조건 검사.
 * @return array ['can_distribute' => bool, 'blockers' => [string, ...]]
 */
function checkDistributePrerequisites($group) {
    $blockers = [];
    if ($group['status'] !== 'open') {
        $blockers[] = "이미 지급 완료된 group";
    }
    $cycles = $group['cycles'] ?? [];
    if (count($cycles) !== 2) {
        $blockers[] = "cycle이 정확히 2개여야 함 (현재 " . count($cycles) . "개)";
    }
    foreach ($cycles as $c) {
        if ($c['status'] !== 'closed') {
            $blockers[] = "{$c['name']} cycle이 아직 closed 아님";
        }
    }
    return [
        'can_distribute' => empty($blockers),
        'blockers'       => $blockers,
    ];
}
