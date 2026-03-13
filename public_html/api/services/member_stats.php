<?php
/**
 * Member Stats Service
 * 회원별 단계별 참여 횟수, 완주 횟수, Bravo 등급 계산
 */

/**
 * 회원 목록에 stats(stage1_count, stage2_count, completion_count, bravo_grade)를 일괄 추가
 * @param PDO $db
 * @param array $members - 각 원소에 phone, user_id 필드 필요
 * @return array - stats가 추가된 members
 */
function enrichMembersWithStats($db, $members) {
    if (empty($members)) return $members;

    // 1) phone, user_id 수집
    $phones = [];
    $userIds = [];
    foreach ($members as $m) {
        if (!empty($m['phone']))   $phones[]  = $m['phone'];
        if (!empty($m['user_id'])) $userIds[] = $m['user_id'];
    }

    if (empty($phones) && empty($userIds)) {
        // 매칭 불가 — 기본값 세팅
        foreach ($members as &$m) {
            $m['stage1_count']      = ($m['stage_no'] == 1) ? 1 : 0;
            $m['stage2_count']      = ($m['stage_no'] == 2) ? 1 : 0;
            $m['completion_count']  = 0;
            $m['bravo_grade']       = null;
        }
        unset($m);
        return $members;
    }

    // 2) 매칭되는 모든 bootcamp_members 레코드를 한 번에 조회
    $conds = [];
    $params = [];
    if (!empty($phones)) {
        $ph = implode(',', array_fill(0, count($phones), '?'));
        $conds[] = "(bm.phone IN ({$ph}) AND bm.phone != '')";
        $params = array_merge($params, $phones);
    }
    if (!empty($userIds)) {
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $conds[] = "(bm.user_id IN ({$ph}) AND bm.user_id != '')";
        $params = array_merge($params, $userIds);
    }

    $today = date('Y-m-d');
    $stmt = $db->prepare("
        SELECT bm.phone, bm.user_id, bm.cohort_id, bm.stage_no, bm.is_active,
               c.end_date
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE " . implode(' OR ', $conds) . "
    ");
    $stmt->execute($params);
    $allRecords = $stmt->fetchAll();

    // 3) phone별, user_id별 레코드 인덱스 구축
    $byPhone = [];
    $byUserId = [];
    foreach ($allRecords as $r) {
        if (!empty($r['phone']))   $byPhone[$r['phone']][]     = $r;
        if (!empty($r['user_id'])) $byUserId[$r['user_id']][]  = $r;
    }

    // 4) 각 멤버의 이력 레코드를 모아서 계산
    foreach ($members as &$m) {
        $related = [];
        $seen = []; // cohort_id+stage_no 중복 방지

        // phone 기준 매칭
        if (!empty($m['phone']) && isset($byPhone[$m['phone']])) {
            foreach ($byPhone[$m['phone']] as $r) {
                $key = $r['cohort_id'] . '_' . $r['stage_no'] . '_' . $r['is_active'];
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $related[] = $r;
                }
            }
        }
        // user_id 기준 매칭 (추가)
        if (!empty($m['user_id']) && isset($byUserId[$m['user_id']])) {
            foreach ($byUserId[$m['user_id']] as $r) {
                $key = $r['cohort_id'] . '_' . $r['stage_no'] . '_' . $r['is_active'];
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $related[] = $r;
                }
            }
        }

        $m = array_merge($m, calcStatsFromRecords($related, $today));
    }
    unset($m);

    return $members;
}

/**
 * 관련 레코드 배열에서 stats를 계산
 */
function calcStatsFromRecords($records, $today) {
    $stage1Cohorts      = [];
    $stage2Cohorts      = [];
    $completionCohorts  = [];

    foreach ($records as $r) {
        $cid = (int)$r['cohort_id'];

        // 1단계 참여 cohort (현재 포함)
        if ((int)$r['stage_no'] === 1) {
            $stage1Cohorts[$cid] = true;
        }

        // 2단계 참여 cohort (현재 포함)
        if ((int)$r['stage_no'] === 2) {
            $stage2Cohorts[$cid] = true;
        }

        // 완주: 종료된 cohort + is_active=1
        if ($r['end_date'] < $today && (int)$r['is_active'] === 1) {
            $completionCohorts[$cid] = true;
        }
    }

    $completionCount = count($completionCohorts);

    return [
        'stage1_count'      => count($stage1Cohorts),
        'stage2_count'      => count($stage2Cohorts),
        'completion_count'  => $completionCount,
        'bravo_grade'       => calcBravoGrade($completionCount),
    ];
}

/**
 * 완주 횟수 기반 Bravo 등급 산정
 */
function calcBravoGrade($completionCount) {
    if ($completionCount >= 10) return 'Bravo 3';
    if ($completionCount >= 6)  return 'Bravo 2';
    if ($completionCount >= 3)  return 'Bravo 1';
    return null;
}
