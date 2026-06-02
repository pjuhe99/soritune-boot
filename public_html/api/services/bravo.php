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
