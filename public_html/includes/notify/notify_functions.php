<?php
/**
 * boot.soritune.com - Notify 공통 유틸
 * 전화번호 정규화 / 템플릿 변수 치환 / cron 매처
 */

/**
 * 한국 휴대/지역 번호를 010xxxxxxxx 형태로 정규화.
 * +82 / 공백 / 하이픈 제거. 길이가 9~11이고 숫자만 남으면 OK.
 * 형식 부적합 시 null.
 */
function notifyNormalizePhone(?string $raw): ?string {
    if ($raw === null) return null;
    $s = trim($raw);
    if ($s === '') return null;
    // 모든 비숫자 제거
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits === '' || $digits === null) return null;
    // +82 / 82 prefix → 0
    if (str_starts_with($digits, '82')) {
        $digits = '0' . substr($digits, 2);
    }
    // 길이 검증 (한국 번호는 보통 10~11자리)
    $len = strlen($digits);
    if ($len < 10 || $len > 11) return null;
    if (!str_starts_with($digits, '0')) return null;
    return $digits;
}

/**
 * 시나리오의 variables 매핑(`'#{x}' => 'col:헤더'` / `'const:문자열'`)을
 * 행 데이터로 치환해 [`'#{x}' => '실제값'`] 반환.
 */
function notifyRenderVariables(array $variables, array $row): array {
    $out = [];
    foreach ($variables as $key => $spec) {
        if (str_starts_with($spec, 'col:')) {
            $col = substr($spec, 4);
            $out[$key] = (string)($row[$col] ?? '');
        } elseif (str_starts_with($spec, 'const:')) {
            $out[$key] = substr($spec, 6);
        } else {
            // 알 수 없는 prefix는 빈 문자열로 안전 처리
            $out[$key] = '';
        }
    }
    return $out;
}

/**
 * 5필드 cron 식이 주어진 timestamp(초)와 매칭되는지 검사.
 * 지원: '*', '숫자', 'A,B,C', 'A-B', '* / N' (스텝).
 * 필드: 분(0-59) 시(0-23) 일(1-31) 월(1-12) 요일(0-7, 0과 7은 일요일).
 * PHP date('w'): 0=Sun..6=Sat — cron 표준과 동일.
 */
function notifyCronMatches(string $expr, int $timestamp): bool {
    $parts = preg_split('/\s+/', trim($expr));
    if (count($parts) !== 5) return false;
    [$min, $hour, $day, $mon, $dow] = $parts;

    $now = [
        'min'  => (int)date('i', $timestamp),
        'hour' => (int)date('G', $timestamp),
        'day'  => (int)date('j', $timestamp),
        'mon'  => (int)date('n', $timestamp),
        'dow'  => (int)date('w', $timestamp),
    ];

    return notifyCronFieldMatches($min,  $now['min'],  0, 59)
        && notifyCronFieldMatches($hour, $now['hour'], 0, 23)
        && notifyCronFieldMatches($day,  $now['day'],  1, 31)
        && notifyCronFieldMatches($mon,  $now['mon'],  1, 12)
        && notifyCronDowMatches($dow,    $now['dow']);
}

function notifyCronFieldMatches(string $field, int $value, int $min, int $max): bool {
    foreach (explode(',', $field) as $part) {
        if ($part === '*') return true;
        if (preg_match('#^\*/(\d+)$#', $part, $m)) {
            $step = (int)$m[1];
            if ($step > 0 && $value >= $min && ($value - $min) % $step === 0) return true;
            continue;
        }
        if (preg_match('#^(\d+)-(\d+)$#', $part, $m)) {
            if ($value >= (int)$m[1] && $value <= (int)$m[2]) return true;
            continue;
        }
        if (ctype_digit($part) && (int)$part === $value) return true;
    }
    return false;
}

/** 요일 필드는 7=일요일도 0과 동등 처리 */
function notifyCronDowMatches(string $field, int $value): bool {
    if (notifyCronFieldMatches($field, $value, 0, 6)) return true;
    if ($value === 0 && notifyCronFieldMatches($field, 7, 0, 7)) return true;
    return false;
}
