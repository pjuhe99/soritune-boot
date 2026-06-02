<?php
/**
 * BRAVO 문제은행 서비스 (4차 슬라이스).
 * 검증/정규화 순수함수 + CRUD. 기존 BRAVO 경로와 무관한 추가형.
 */

/** 유효 난이도 집합. */
function bravoQuestionDifficulties(): array {
    return ['easy', 'normal', 'hard'];
}

/**
 * 문제 입력 검증. 에러 메시지 배열 반환(빈=통과). 순수.
 */
function bravoQuestionValidate(array $d): array {
    $errors = [];

    $type = isset($d['question_type']) ? (int)$d['question_type'] : 0;
    if (!in_array($type, [1,2,3], true)) $errors[] = '문제 유형은 1/2/3 중 하나여야 합니다.';

    $level = isset($d['bravo_level']) ? (int)$d['bravo_level'] : 0;
    if (!in_array($level, [1,2,3], true)) $errors[] = 'BRAVO 등급은 1/2/3 중 하나여야 합니다.';

    $ko = isset($d['korean_text']) && is_string($d['korean_text']) ? trim($d['korean_text']) : '';
    if ($ko === '') $errors[] = '한국어 문장을 입력해주세요.';

    $en = isset($d['english_text']) && is_string($d['english_text']) ? trim($d['english_text']) : '';
    if ($en === '') $errors[] = '기준 영어 문장을 입력해주세요.';

    $diff = $d['difficulty'] ?? 'normal';
    if (!in_array($diff, bravoQuestionDifficulties(), true)) $errors[] = '난이도가 올바르지 않습니다.';

    foreach (['reference_speech_sec' => '기준 발화 시간', 'response_time_limit_sec' => '반응 속도 기준'] as $k => $label) {
        if (isset($d[$k]) && $d[$k] !== '' && $d[$k] !== null) {
            if (!is_numeric($d[$k]) || (float)$d[$k] < 0) $errors[] = "{$label}은(는) 0 이상의 숫자여야 합니다.";
        }
    }

    return $errors;
}

/**
 * 폼 입력 → bravo_questions 저장용 정규화 컬럼 배열. 순수.
 * 빈 문자열은 NULL(텍스트/숫자), difficulty 기본 normal, is_active 0/1.
 */
function bravoQuestionPersistData(array $d): array {
    $diff = in_array($d['difficulty'] ?? '', bravoQuestionDifficulties(), true) ? $d['difficulty'] : 'normal';
    $strOrNull = function ($v) {
        if (!is_string($v)) return null;
        $v = trim($v);
        return $v === '' ? null : $v;
    };
    $numOrNull = function ($v) {
        if ($v === '' || $v === null || !is_numeric($v)) return null;
        return (float)$v;
    };
    return [
        'question_type'           => (int)($d['question_type'] ?? 0),
        'bravo_level'             => (int)($d['bravo_level'] ?? 0),
        'source'                  => $strOrNull($d['source'] ?? null),
        'korean_text'             => trim((string)($d['korean_text'] ?? '')),
        'english_text'            => trim((string)($d['english_text'] ?? '')),
        'target_chunks'           => $strOrNull($d['target_chunks'] ?? null),
        'accepted_answers'        => $strOrNull($d['accepted_answers'] ?? null),
        'reference_speech_sec'    => $numOrNull($d['reference_speech_sec'] ?? null),
        'response_time_limit_sec' => $numOrNull($d['response_time_limit_sec'] ?? null),
        'difficulty'              => $diff,
        'is_active'               => !empty($d['is_active']) ? 1 : 0,
    ];
}
