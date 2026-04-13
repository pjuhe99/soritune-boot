<?php
/**
 * boot.soritune.com - Bootcamp Shared Functions
 * saveCheck, recalculateMemberScore, etc.
 * Used by bootcamp.php and qr.php
 */

// 점수 시스템 상수
if (!defined('SCORE_START'))              define('SCORE_START', 0);
if (!defined('SCORE_ADAPTATION_DAYS'))    define('SCORE_ADAPTATION_DAYS', 3);
if (!defined('SCORE_OUT_THRESHOLD'))      define('SCORE_OUT_THRESHOLD', -25);
if (!defined('SCORE_REVIVAL_CANDIDATE'))  define('SCORE_REVIVAL_CANDIDATE', -8);
if (!defined('SCORE_REVIVAL_ELIGIBLE'))   define('SCORE_REVIVAL_ELIGIBLE', -10);
if (!defined('SCORE_REVIVAL_AFTER'))      define('SCORE_REVIVAL_AFTER', -5);
if (!defined('SCORE_REVIVAL_BONUS'))     define('SCORE_REVIVAL_BONUS', 7);

/**
 * 감점 규칙 (하루 단위)
 */
if (!function_exists('getPenaltyRules')) {
function getPenaltyRules() {
    return [
        ['codes' => ['zoom_daily', 'daily_mission'], 'penalty' => -1, 'weekday' => null],
        ['codes' => ['inner33'], 'penalty' => -1, 'weekday' => null],
        ['codes' => ['speak_mission'], 'penalty' => -2, 'weekday' => 1],
    ];
}
}

/**
 * 미션 타입 코드→ID 매핑 (캐시)
 */
if (!function_exists('getMissionCodeToIdMap')) {
function getMissionCodeToIdMap($db) {
    static $map = null;
    if ($map !== null) return $map;
    $stmt = $db->query("SELECT id, code FROM mission_types WHERE is_active = 1");
    $map = [];
    foreach ($stmt->fetchAll() as $mt) {
        $map[$mt['code']] = (int)$mt['id'];
    }
    return $map;
}
}

/**
 * 연속 과제 미수행 일수 계산
 */
if (!function_exists('calcConsecutiveMissDays')) {
function calcConsecutiveMissDays($byDate, $codeToId) {
    $scoredCodes = ['zoom_daily', 'daily_mission', 'inner33', 'speak_mission'];
    $dates = array_keys($byDate);
    rsort($dates);

    $consecutive = 0;
    foreach ($dates as $date) {
        $missions = $byDate[$date];
        $anyDone = false;
        foreach ($scoredCodes as $code) {
            $typeId = $codeToId[$code] ?? null;
            if ($typeId && ($missions[$typeId] ?? 0) === 1) {
                $anyDone = true;
                break;
            }
        }
        if (!$anyDone) {
            $consecutive++;
        } else {
            break;
        }
    }
    return $consecutive;
}
}

/**
 * Stale 점수 일괄 갱신
 * last_calculated_at < 오늘인 멤버만 재계산하여 점수를 최신화한다.
 * 점수를 표시하는 API 진입부에서 호출.
 */
if (!function_exists('ensureScoresFresh')) {
function ensureScoresFresh($db, $cohortId) {
    $stmt = $db->prepare("
        SELECT bm.id
        FROM bootcamp_members bm
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        WHERE bm.cohort_id = ? AND (bm.is_active = 1 OR bm.member_status = 'leaving')
          AND (ms.last_calculated_at IS NULL OR ms.last_calculated_at < CURDATE())
    ");
    $stmt->execute([$cohortId]);
    $staleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($staleIds as $mid) {
        recalculateMemberScore($db, (int)$mid);
    }
}
}

/**
 * 단일 멤버 점수 최신화 (멤버 ID만 아는 경우)
 */
if (!function_exists('ensureMemberScoreFresh')) {
function ensureMemberScoreFresh($db, $memberId) {
    $stmt = $db->prepare("
        SELECT 1 FROM member_scores
        WHERE member_id = ? AND last_calculated_at >= CURDATE()
    ");
    $stmt->execute([$memberId]);
    if (!$stmt->fetch()) {
        recalculateMemberScore($db, $memberId);
    }
}
}

/**
 * 회원 점수 전체 재계산 (v2 - 감점 기반)
 */
if (!function_exists('recalculateMemberScore')) {
function recalculateMemberScore($db, $memberId, $adminId = null) {
    $member = $db->prepare("SELECT id, cohort_id, stage_no FROM bootcamp_members WHERE id = ?");
    $member->execute([$memberId]);
    $member = $member->fetch();
    if (!$member) return null;

    $cohort = $db->prepare("SELECT start_date, end_date FROM cohorts WHERE id = ?");
    $cohort->execute([$member['cohort_id']]);
    $cohortRow = $cohort->fetch();
    $adaptationEnd = $cohortRow
        ? date('Y-m-d', strtotime($cohortRow['start_date'] . ' + ' . (SCORE_ADAPTATION_DAYS - 1) . ' days'))
        : null;
    $cohortEndDate = $cohortRow['end_date'] ?? null;

    $codeToId = getMissionCodeToIdMap($db);

    $checks = $db->prepare("
        SELECT check_date, mission_type_id, status FROM member_mission_checks
        WHERE member_id = ? AND check_date > ?
        ORDER BY check_date
    ");
    $checks->execute([$memberId, $adaptationEnd ?? '1900-01-01']);
    $byDate = [];
    foreach ($checks->fetchAll() as $c) {
        $byDate[$c['check_date']][(int)$c['mission_type_id']] = (int)$c['status'];
    }

    $scoringStart = date('Y-m-d', strtotime($adaptationEnd . ' +1 day'));
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $scoringEnd = ($cohortEndDate && $cohortEndDate < $yesterday) ? $cohortEndDate : $yesterday;
    $rules = getPenaltyRules();
    $penaltySum = 0;

    $current = $scoringStart;
    while ($current <= $scoringEnd) {
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

    $revivals = $db->prepare("
        SELECT SUM(after_score - before_score) AS revival_delta
        FROM revival_logs WHERE member_id = ?
    ");
    $revivals->execute([$memberId]);
    $revivalDelta = (int)($revivals->fetch()['revival_delta'] ?? 0);

    $manuals = $db->prepare("
        SELECT SUM(score_change) AS manual_delta
        FROM score_logs WHERE member_id = ? AND reason_type = 'manual_adjustment'
    ");
    $manuals->execute([$memberId]);
    $manualDelta = (int)($manuals->fetch()['manual_delta'] ?? 0);

    $finalScore = SCORE_START + $penaltySum + $revivalDelta + $manualDelta;

    $current = $db->prepare("SELECT current_score FROM member_scores WHERE member_id = ?");
    $current->execute([$memberId]);
    $currentRow = $current->fetch();
    $beforeScore = $currentRow ? (int)$currentRow['current_score'] : 0;

    $db->prepare("
        INSERT INTO member_scores (member_id, current_score, last_calculated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE current_score = VALUES(current_score), last_calculated_at = NOW()
    ")->execute([$memberId, $finalScore]);

    if ($finalScore <= SCORE_OUT_THRESHOLD) {
        $db->prepare("UPDATE bootcamp_members SET member_status = 'out_of_group_management' WHERE id = ? AND member_status = 'active'")
           ->execute([$memberId]);
    } else {
        $db->prepare("UPDATE bootcamp_members SET member_status = 'active' WHERE id = ? AND member_status = 'out_of_group_management'")
           ->execute([$memberId]);
    }

    if ($finalScore !== $beforeScore) {
        $db->prepare("
            INSERT INTO score_logs (member_id, score_change, before_score, after_score, reason_type, reason_detail, created_by)
            VALUES (?, ?, ?, ?, 'recalculation', '전체 재계산', ?)
        ")->execute([$memberId, $finalScore - $beforeScore, $beforeScore, $finalScore, $adminId]);
    }

    return $finalScore;
}
}

/**
 * 체크 저장 (upsert) + 점수 반영
 */
if (!function_exists('saveCheck')) {
function saveCheck($db, $memberId, $checkDate, $missionTypeId, $status, $source, $sourceRef, $adminId, $skipRecalc = false) {
    $existing = $db->prepare("
        SELECT id, status, source FROM member_mission_checks
        WHERE member_id = ? AND check_date = ? AND mission_type_id = ?
    ");
    $existing->execute([$memberId, $checkDate, $missionTypeId]);
    $existingRow = $existing->fetch();

    if ($existingRow && $existingRow['source'] === 'manual' && $source === 'automation' && (int)$existingRow['status'] === 1) {
        return ['action' => 'skipped', 'reason' => 'manual check already completed'];
    }

    $member = $db->prepare("SELECT cohort_id, group_id FROM bootcamp_members WHERE id = ?");
    $member->execute([$memberId]);
    $memberRow = $member->fetch();
    if (!$memberRow) return ['action' => 'error', 'reason' => 'member not found'];

    $statusVal = $status ? 1 : 0;
    $prevStatus = $existingRow ? (int)$existingRow['status'] : null;

    if ($existingRow) {
        $db->prepare("
            UPDATE member_mission_checks
            SET status = ?, source = ?, source_ref = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$statusVal, $source, $sourceRef, $adminId, $existingRow['id']]);
        $action = ((int)$existingRow['status'] !== $statusVal) ? 'updated' : 'unchanged';
    } else {
        $db->prepare("
            INSERT INTO member_mission_checks
                (member_id, cohort_id, group_id, check_date, mission_type_id, status, source, source_ref, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$memberId, $memberRow['cohort_id'], $memberRow['group_id'], $checkDate, $missionTypeId, $statusVal, $source, $sourceRef, $adminId]);
        $action = 'created';
    }

    if ($action !== 'unchanged' && !$skipRecalc) {
        recalculateMemberScore($db, $memberId, $adminId);
    }

    // 코인 처리 (score와 독립, skipRecalc 무관하게 항상 실행)
    if ($action !== 'unchanged' && function_exists('processCoinForCheck')) {
        $codeMap = array_flip(getMissionCodeToIdMap($db));
        $mCode = $codeMap[$missionTypeId] ?? null;
        if ($mCode) {
            processCoinForCheck($db, $memberId, $checkDate, $mCode, $statusVal, $prevStatus, $adminId);
        }
    }

    return ['action' => $action, 'prev_status' => $prevStatus];
}
}

/**
 * mission_type_code → id 변환
 */
if (!function_exists('getMissionTypeId')) {
function getMissionTypeId($db, $code) {
    $stmt = $db->prepare("SELECT id FROM mission_types WHERE code = ? AND is_active = 1");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}
}
