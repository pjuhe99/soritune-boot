<?php
/**
 * Member Stats Service
 * member_history_stats 집계 테이블 관리
 *
 * - phone/user_id 기준으로 동일인의 크로스 cohort 통계를 계산하여 저장
 * - 원본은 항상 bootcamp_members + cohorts 에 있으므로 언제든 재계산 가능
 * - 갱신 시점: 회원 CREATE / UPDATE(stage_no, is_active, phone, user_id 변경) / DELETE
 */

/**
 * 특정 인물의 stats를 재계산하여 member_history_stats에 저장
 * @param PDO    $db
 * @param string|null $phone
 * @param string|null $userId
 */
function refreshMemberStats($db, $phone, $userId) {
    // phone, user_id 모두 없으면 집계 불가
    if (empty($phone) && empty($userId)) return;

    $today = date('Y-m-d');

    // 이 인물의 모든 bootcamp_members 레코드 조회
    $conds = [];
    $params = [];
    if (!empty($phone))   { $conds[] = "(bm.phone = ? AND bm.phone != '')";     $params[] = $phone; }
    if (!empty($userId))  { $conds[] = "(bm.user_id = ? AND bm.user_id != '')"; $params[] = $userId; }

    $stmt = $db->prepare("
        SELECT bm.cohort_id, bm.stage_no, bm.is_active, c.end_date
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE " . implode(' OR ', $conds) . "
    ");
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    // 계산
    $stage1Cohorts     = [];
    $stage2Cohorts     = [];
    $completionCohorts = [];

    foreach ($records as $r) {
        $cid = (int)$r['cohort_id'];
        if ((int)$r['stage_no'] === 1) $stage1Cohorts[$cid] = true;
        if ((int)$r['stage_no'] === 2) $stage2Cohorts[$cid] = true;
        if ($r['end_date'] < $today && (int)$r['is_active'] === 1) {
            $completionCohorts[$cid] = true;
        }
    }

    $s1    = count($stage1Cohorts);
    $s2    = count($stage2Cohorts);
    $comp  = count($completionCohorts);
    $bravo = calcBravoGrade($comp);

    // phone 기준 upsert
    if (!empty($phone)) {
        upsertStatsByPhone($db, $phone, $s1, $s2, $comp, $bravo);
    }

    // user_id 기준 upsert (phone과 별도 row일 수 있음)
    if (!empty($userId)) {
        upsertStatsByUserId($db, $userId, $s1, $s2, $comp, $bravo);
    }
}

/**
 * phone 기준 upsert
 */
function upsertStatsByPhone($db, $phone, $s1, $s2, $comp, $bravo) {
    $db->prepare("
        INSERT INTO member_history_stats
            (phone, stage1_participation_count, stage2_participation_count, completed_bootcamp_count, bravo_grade, last_calculated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            stage1_participation_count = VALUES(stage1_participation_count),
            stage2_participation_count = VALUES(stage2_participation_count),
            completed_bootcamp_count   = VALUES(completed_bootcamp_count),
            bravo_grade                = VALUES(bravo_grade),
            last_calculated_at         = NOW()
    ")->execute([$phone, $s1, $s2, $comp, $bravo]);
}

/**
 * user_id 기준 upsert
 */
function upsertStatsByUserId($db, $userId, $s1, $s2, $comp, $bravo) {
    $db->prepare("
        INSERT INTO member_history_stats
            (user_id, stage1_participation_count, stage2_participation_count, completed_bootcamp_count, bravo_grade, last_calculated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            stage1_participation_count = VALUES(stage1_participation_count),
            stage2_participation_count = VALUES(stage2_participation_count),
            completed_bootcamp_count   = VALUES(completed_bootcamp_count),
            bravo_grade                = VALUES(bravo_grade),
            last_calculated_at         = NOW()
    ")->execute([$userId, $s1, $s2, $comp, $bravo]);
}

/**
 * bootcamp_member ID로 해당 인물의 stats를 갱신
 * CREATE/UPDATE/DELETE 시 호출
 */
function refreshMemberStatsById($db, $memberId) {
    $stmt = $db->prepare("SELECT phone, user_id FROM bootcamp_members WHERE id = ?");
    $stmt->execute([$memberId]);
    $row = $stmt->fetch();
    if ($row) {
        refreshMemberStats($db, $row['phone'], $row['user_id']);
    }
}

/**
 * 삭제 전 phone/user_id를 미리 가져온 뒤 갱신 (DELETE 시 사용)
 * 삭제 후에는 bootcamp_members에서 조회 불가하므로 삭제 전에 호출해야 함
 */
function getMemberIdentifiers($db, $memberId) {
    $stmt = $db->prepare("SELECT phone, user_id FROM bootcamp_members WHERE id = ?");
    $stmt->execute([$memberId]);
    return $stmt->fetch() ?: ['phone' => null, 'user_id' => null];
}

/**
 * 전체 member_history_stats 재계산 (백필/운영 재계산 용)
 * @return int 생성된 row 수
 */
function recalcAllMemberStats($db) {
    $today = date('Y-m-d');

    // 1) 기존 데이터 클리어
    $db->exec("TRUNCATE TABLE member_history_stats");

    // 2) 모든 bootcamp_members에서 고유 phone/user_id 수집
    $stmt = $db->query("
        SELECT bm.phone, bm.user_id, bm.cohort_id, bm.stage_no, bm.is_active, c.end_date
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
    ");
    $allRecords = $stmt->fetchAll();

    // 3) phone별, user_id별 레코드 그룹핑
    $byPhone  = [];
    $byUserId = [];
    foreach ($allRecords as $r) {
        if (!empty($r['phone']))   $byPhone[$r['phone']][]     = $r;
        if (!empty($r['user_id'])) $byUserId[$r['user_id']][]  = $r;
    }

    // 4) 동일인 클러스터링: phone과 user_id가 같은 레코드에서 나오면 하나의 인물
    //    간단한 접근: phone 기준으로 먼저 처리, 그 phone에 연결된 user_id도 함께 처리
    $processedPhones  = [];
    $processedUserIds = [];
    $count = 0;

    // phone이 있는 인물 처리
    foreach ($byPhone as $phone => $records) {
        if (isset($processedPhones[$phone])) continue;
        $processedPhones[$phone] = true;

        // 이 phone에 연결된 user_id들도 수집
        $relatedRecords = $records;
        $relatedUserIds = [];
        foreach ($records as $r) {
            if (!empty($r['user_id']) && !isset($processedUserIds[$r['user_id']])) {
                $relatedUserIds[] = $r['user_id'];
                $processedUserIds[$r['user_id']] = true;
                // user_id로만 연결된 추가 레코드 병합
                if (isset($byUserId[$r['user_id']])) {
                    foreach ($byUserId[$r['user_id']] as $ur) {
                        $relatedRecords[] = $ur;
                    }
                }
            }
        }

        $stats = calcStatsFromRecords($relatedRecords, $today);

        upsertStatsByPhone($db, $phone, $stats['s1'], $stats['s2'], $stats['comp'], $stats['bravo']);
        $count++;

        // 연결된 user_id에도 동일한 값 저장
        foreach ($relatedUserIds as $uid) {
            upsertStatsByUserId($db, $uid, $stats['s1'], $stats['s2'], $stats['comp'], $stats['bravo']);
            $count++;
        }
    }

    // user_id만 있고 phone이 없는 인물 처리
    foreach ($byUserId as $userId => $records) {
        if (isset($processedUserIds[$userId])) continue;
        $processedUserIds[$userId] = true;

        $stats = calcStatsFromRecords($records, $today);

        upsertStatsByUserId($db, $userId, $stats['s1'], $stats['s2'], $stats['comp'], $stats['bravo']);
        $count++;
    }

    return $count;
}

/**
 * 레코드 배열에서 stats 계산 (내부용)
 */
function calcStatsFromRecords($records, $today) {
    $stage1  = [];
    $stage2  = [];
    $compl   = [];

    foreach ($records as $r) {
        $cid = (int)$r['cohort_id'];
        if ((int)$r['stage_no'] === 1) $stage1[$cid] = true;
        if ((int)$r['stage_no'] === 2) $stage2[$cid] = true;
        if ($r['end_date'] < $today && (int)$r['is_active'] === 1) {
            $compl[$cid] = true;
        }
    }

    $comp = count($compl);
    return [
        's1'    => count($stage1),
        's2'    => count($stage2),
        'comp'  => $comp,
        'bravo' => calcBravoGrade($comp),
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
