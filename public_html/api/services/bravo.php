<?php
/**
 * BRAVO 도전 시스템 서비스.
 * 1차 슬라이스: 자격 자동계산 순수 함수 + 관리자 데이터 서비스.
 * 기존 member_history_stats.bravo_grade 와 무관한 추가형.
 */

/**
 * 유효 회독수 = override 가 있으면 그 값, 없으면(NULL) 자동 completed_bootcamp_count.
 * override 0 은 명시적 0 으로 자동값을 덮는다 (NULL 만 자동).
 */
function bravoEffectiveReviewCount(?int $override, int $completedCount): int {
    return $override !== null ? $override : $completedCount;
}

/**
 * 회독수 임계 기준 자동 응시가능 등급 목록. (doc 15-3, 회독수만)
 * $levels: [['level'=>int,'required_review_count'=>int], ...]
 * 반환: 오름차순 level 배열.
 */
function bravoAutoEligibleLevels(int $reviewCount, array $levels): array {
    $out = [];
    foreach ($levels as $lv) {
        if ($reviewCount >= (int)$lv['required_review_count']) {
            $out[] = (int)$lv['level'];
        }
    }
    sort($out);
    return $out;
}

/**
 * 최종 응시가능 등급 = 자동 ∪ 수동부여. 중복제거·오름차순.
 * $grantedLevels: int 배열 (예: [1,3]).
 */
function bravoEligibleLevels(?int $override, int $completedCount, array $grantedLevels, array $levels): array {
    $review = bravoEffectiveReviewCount($override, $completedCount);
    $auto   = bravoAutoEligibleLevels($review, $levels);
    $union  = array_values(array_unique(array_merge($auto, array_map('intval', $grantedLevels))));
    sort($union);
    return $union;
}

/**
 * granted_levels SET 컬럼 문자열("1,3")을 int 배열로 파싱.
 */
function bravoParseGrantedLevels(?string $raw): array {
    if ($raw === null || $raw === '') return [];
    $out = [];
    foreach (explode(',', $raw) as $p) {
        $p = trim($p);
        if ($p !== '' && in_array($p, ['1','2','3'], true)) $out[] = (int)$p;
    }
    sort($out);
    return $out;
}

/**
 * int 배열을 granted_levels SET 저장용 문자열로. 빈 배열이면 '' (NULL 처리는 호출부).
 */
function bravoFormatGrantedLevels(array $levels): string {
    $valid = [];
    foreach ($levels as $l) {
        $l = (int)$l;
        if (in_array($l, [1,2,3], true)) $valid[$l] = true;
    }
    $keys = array_keys($valid);
    sort($keys);
    return implode(',', $keys);
}

/**
 * bravo_levels 설정 로드 (자격계산 임계의 단일 진실원).
 */
function bravoLoadLevels(PDO $db): array {
    return $db->query("SELECT level, name, required_review_count, passing_score, requires_previous_level FROM bravo_levels ORDER BY level")
              ->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 특정 기수 회원 목록 + 자동 completed_bootcamp_count + 계산된 응시가능 등급.
 * 기존 member_list 조인 패턴 재사용 (user_id-row 우선, phone-row 폴백).
 */
function bravoMemberList(PDO $db, string $cohort): array {
    $levels = bravoLoadLevels($db);
    $stmt = $db->prepare("
        SELECT bm.user_id, bm.real_name, bm.nickname, bm.phone,
               COALESCE(mhs_u.completed_bootcamp_count, mhs_p.completed_bootcamp_count, 0) AS completed_bootcamp_count,
               bms.review_count_override, bms.granted_levels, bms.notes
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
        LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
        LEFT JOIN bravo_member_settings bms ON bm.user_id = bms.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
        WHERE c.cohort = ? AND bm.member_status NOT IN ('refunded','expelled')
        ORDER BY bm.real_name
    ");
    $stmt->execute([$cohort]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $override = $r['review_count_override'] !== null ? (int)$r['review_count_override'] : null;
        $granted  = bravoParseGrantedLevels($r['granted_levels']);
        $completed = (int)$r['completed_bootcamp_count'];
        $out[] = [
            'user_id'                  => $r['user_id'],
            'real_name'                => $r['real_name'],
            'nickname'                 => $r['nickname'],
            'phone'                    => $r['phone'],
            'completed_bootcamp_count' => $completed,
            'review_count_override'    => $override,
            'effective_review_count'   => bravoEffectiveReviewCount($override, $completed),
            'granted_levels'           => $granted,
            'notes'                    => $r['notes'],
            'eligible_levels'          => bravoEligibleLevels($override, $completed, $granted, $levels),
        ];
    }
    return $out;
}

/**
 * 회원 BRAVO 설정 upsert (user_id 기준). override NULL = 자동복귀, grant [] = 비움.
 */
function bravoMemberUpsert(PDO $db, string $userId, ?int $override, array $grantedLevels, ?string $notes, ?int $adminId): void {
    $grantedStr = bravoFormatGrantedLevels($grantedLevels);
    $grantedVal = $grantedStr === '' ? null : $grantedStr;
    $notesVal   = ($notes !== null && trim($notes) !== '') ? $notes : null;
    $db->prepare("
        INSERT INTO bravo_member_settings (user_id, review_count_override, granted_levels, notes, updated_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            review_count_override = VALUES(review_count_override),
            granted_levels        = VALUES(granted_levels),
            notes                 = VALUES(notes),
            updated_by            = VALUES(updated_by)
    ")->execute([$userId, $override, $grantedVal, $notesVal, $adminId]);
}
