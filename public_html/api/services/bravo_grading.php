<?php
/**
 * BRAVO 채점 서비스 (7차 슬라이스). 관리자 수동채점 — 판정→자동 환산→확정.
 * 점수 스냅샷의 보호 대상은 가중치 상수 변경. N(분모) 변경은 확정 시 자동 재환산.
 */

require_once __DIR__ . '/bravo.php';
require_once __DIR__ . '/bravo_attempts.php';

// 등급별 평가요소 가중치 (기능정의서 §11 — DB화는 YAGNI)
const BRAVO_GRADE_WEIGHTS = [
    1 => ['accuracy' => 60, 'chunk' => 20, 'response' => 10, 'fluency' => 10, 'completion' => 0],
    2 => ['accuracy' => 45, 'chunk' => 20, 'response' => 15, 'fluency' => 15, 'completion' => 5],
    3 => ['accuracy' => 40, 'chunk' => 15, 'response' => 20, 'fluency' => 20, 'completion' => 5],
];
const BRAVO_GRADE_COEFF = ['correct' => 1.0, 'partial' => 0.5, 'wrong' => 0.0, 'good' => 1.0, 'normal' => 0.5, 'poor' => 0.0];

/**
 * 판정 입력 검증. 통과 시 빈 배열, 실패 시 에러 메시지 배열. 순수.
 * completion_ok 는 B2/B3(가중치>0)에서만 필수, B1은 무시.
 */
function bravoGradeValidate(int $level, array $d): array {
    $errors = [];
    if (!in_array($d['accuracy'] ?? '', ['correct', 'partial', 'wrong'], true)) $errors[] = '정답도 판정이 필요합니다.';
    if (!isset($d['chunk_ok']) || !in_array((int)$d['chunk_ok'], [0, 1], true)) $errors[] = '청크 판정이 필요합니다.';
    foreach (['response_rating' => '반응속도', 'fluency_rating' => '유창성'] as $k => $label) {
        if (!in_array($d[$k] ?? '', ['good', 'normal', 'poor'], true)) $errors[] = "{$label} 판정이 필요합니다.";
    }
    $w = BRAVO_GRADE_WEIGHTS[$level] ?? null;
    if ($w && $w['completion'] > 0 && (!isset($d['completion_ok']) || !in_array((int)$d['completion_ok'], [0, 1], true))) {
        $errors[] = '발화완성도 판정이 필요합니다.';
    }
    return $errors;
}

/**
 * 문항 환산 점수. 문항별 요소 만점 = 등급 가중치 ÷ N. DECIMAL(5,2) 반올림. 순수.
 */
function bravoGradeScore(int $level, int $n, array $j): float {
    $w = BRAVO_GRADE_WEIGHTS[$level] ?? null;
    if (!$w || $n < 1) return 0.0;
    $score  = ($w['accuracy'] / $n) * (BRAVO_GRADE_COEFF[$j['accuracy'] ?? ''] ?? 0.0);
    $score += ($w['chunk'] / $n) * (!empty($j['chunk_ok']) ? 1.0 : 0.0);
    $score += ($w['response'] / $n) * (BRAVO_GRADE_COEFF[$j['response_rating'] ?? ''] ?? 0.0);
    $score += ($w['fluency'] / $n) * (BRAVO_GRADE_COEFF[$j['fluency_rating'] ?? ''] ?? 0.0);
    if ($w['completion'] > 0) {
        $score += ($w['completion'] / $n) * (!empty($j['completion_ok']) ? 1.0 : 0.0);
    }
    return round($score, 2);
}

/**
 * HTTP Range 헤더 파싱 (단일 범위만). 성공 [start, end], 미적용/비정상 null → 200 전체 폴백. 순수.
 */
function bravoAudioRangeParse(?string $header, int $size): ?array {
    if ($header === null || $size <= 0) return null;
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) return null;
    if ($m[1] === '' && $m[2] === '') return null;
    if ($m[1] === '') {
        $len = (int)$m[2];
        if ($len <= 0) return null;
        $start = max(0, $size - $len);
        $end = $size - 1;
    } else {
        $start = (int)$m[1];
        $end = ($m[2] === '') ? $size - 1 : min((int)$m[2], $size - 1);
    }
    if ($start > $end || $start >= $size) return null;
    return [$start, $end];
}

/**
 * 채점 대상 문항 id 목록 = 스냅샷 ∩ 현존 (slice6 submit 완비 판정과 동일 기준). 순서 보존.
 */
function bravoGradingQuestionIds(PDO $db, array $attempt): array {
    return array_map(fn($q) => (int)$q['id'], bravoAttemptQuestions($db, $attempt));
}

/**
 * attempt 확정 행 조회 (없으면 null).
 */
function bravoAttemptGradeGet(PDO $db, int $attemptId): ?array {
    $stmt = $db->prepare("SELECT * FROM bravo_attempt_grades WHERE attempt_id = ?");
    $stmt->execute([$attemptId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * attempt 의 유효 grade 행들 (현존 문항 대응만 — 유령 grade 제외). [question_id => grade row]
 */
function bravoGradingValidGrades(PDO $db, array $attempt): array {
    $validQids = bravoGradingQuestionIds($db, $attempt);
    if (!$validQids) return [];
    $stmt = $db->prepare("
        SELECT g.*, a.question_id
        FROM bravo_answer_grades g
        JOIN bravo_answers a ON g.answer_id = a.id
        WHERE g.attempt_id = ?
    ");
    $stmt->execute([(int)$attempt['id']]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $qid = (int)$g['question_id'];
        if (in_array($qid, $validQids, true)) $out[$qid] = $g;
    }
    return $out;
}

/**
 * 채점 진행 요약: graded_count / total_count / total_so_far(유효 grade 합) / auto_result(전 문항 판정 시에만, 아니면 null).
 */
function bravoGradingSummary(PDO $db, array $attempt, int $level): array {
    $totalCount = count(bravoGradingQuestionIds($db, $attempt));
    $grades = bravoGradingValidGrades($db, $attempt);
    $total = round(array_sum(array_map(fn($g) => (float)$g['score'], $grades)), 2);
    $auto = null;
    if ($totalCount > 0 && count($grades) === $totalCount) {
        $passing = bravoGradingPassingScore($db, $level);
        $auto = $total >= $passing ? 'pass' : 'fail';
    }
    return ['graded_count' => count($grades), 'total_count' => $totalCount, 'total_so_far' => $total, 'auto_result' => $auto];
}

/**
 * 등급의 현재 합격선 (bravo_levels).
 */
function bravoGradingPassingScore(PDO $db, int $level): float {
    $stmt = $db->prepare("SELECT passing_score FROM bravo_levels WHERE level = ?");
    $stmt->execute([$level]);
    return (float)$stmt->fetchColumn();
}

/**
 * 문항 판정 저장 (upsert). 성공: ['score'=>..]+summary, 실패: ['error'=>msg].
 */
function bravoGradeSave(PDO $db, array $attempt, array $exam, int $answerId, array $input, int $adminId): array {
    if (($attempt['status'] ?? '') !== 'submitted') return ['error' => '제출된 응시만 채점할 수 있습니다.'];
    if (bravoAttemptGradeGet($db, (int)$attempt['id'])) return ['error' => '확정된 채점입니다. 확정 취소 후 수정하세요.'];

    $stmt = $db->prepare("SELECT id, question_id FROM bravo_answers WHERE id = ? AND attempt_id = ?");
    $stmt->execute([$answerId, (int)$attempt['id']]);
    $ans = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ans) return ['error' => '답안을 찾을 수 없습니다.'];

    $validQids = bravoGradingQuestionIds($db, $attempt);
    if (!in_array((int)$ans['question_id'], $validQids, true)) return ['error' => '채점 대상 문항이 아닙니다.'];

    $level = (int)$exam['bravo_level'];
    $errors = bravoGradeValidate($level, $input);
    if ($errors) return ['error' => implode(' ', $errors)];

    $n = count($validQids);
    $score = bravoGradeScore($level, $n, $input);
    $w = BRAVO_GRADE_WEIGHTS[$level];
    $completion = $w['completion'] > 0 ? (int)$input['completion_ok'] : null;
    $memo = isset($input['memo']) && is_string($input['memo']) && trim($input['memo']) !== '' ? mb_substr(trim($input['memo']), 0, 255) : null;

    $db->prepare("
        INSERT INTO bravo_answer_grades
            (answer_id, attempt_id, accuracy, chunk_ok, response_rating, fluency_rating, completion_ok, score, n_denominator, memo, graded_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            accuracy=VALUES(accuracy), chunk_ok=VALUES(chunk_ok), response_rating=VALUES(response_rating),
            fluency_rating=VALUES(fluency_rating), completion_ok=VALUES(completion_ok),
            score=VALUES(score), n_denominator=VALUES(n_denominator), memo=VALUES(memo), graded_by=VALUES(graded_by)
    ")->execute([
        $answerId, (int)$attempt['id'], $input['accuracy'], (int)$input['chunk_ok'],
        $input['response_rating'], $input['fluency_rating'], $completion, $score, $n, $memo, $adminId,
    ]);

    return ['score' => $score] + bravoGradingSummary($db, $attempt, $level);
}

/**
 * 채점 확정. 전 문항(현존 기준) 판정 필수 → N 불일치 grade 자동 재환산 → 유효 grade 합산
 * → passing_score 스냅샷 → 오버라이드 검증 → INSERT.
 * 성공: ['total_score'=>, 'result'=>], 실패: ['error'=>(, 'missing_count'=>)]
 */
function bravoAttemptConfirm(PDO $db, array $attempt, array $exam, array $input, int $adminId): array {
    if (($attempt['status'] ?? '') !== 'submitted') return ['error' => '제출된 응시만 확정할 수 있습니다.'];
    $attemptId = (int)$attempt['id'];
    if (bravoAttemptGradeGet($db, $attemptId)) return ['error' => '이미 확정된 채점입니다.'];

    $level = (int)$exam['bravo_level'];
    $validQids = bravoGradingQuestionIds($db, $attempt);
    $n = count($validQids);
    if ($n === 0) return ['error' => '채점 대상 문항이 없습니다.'];

    $grades = bravoGradingValidGrades($db, $attempt);
    $missing = $n - count($grades);
    if ($missing > 0) return ['error' => "판정되지 않은 문항이 {$missing}개 있습니다.", 'missing_count' => $missing];

    // N 불일치 grade 자동 재환산 (저장된 판정값 + 현재 가중치·N — 한 attempt 안에서 단일 N 보장)
    $upd = $db->prepare("UPDATE bravo_answer_grades SET score = ?, n_denominator = ? WHERE id = ?");
    foreach ($grades as $qid => $g) {
        if ((int)$g['n_denominator'] !== $n) {
            $j = [
                'accuracy' => $g['accuracy'], 'chunk_ok' => (int)$g['chunk_ok'],
                'response_rating' => $g['response_rating'], 'fluency_rating' => $g['fluency_rating'],
                'completion_ok' => $g['completion_ok'] !== null ? (int)$g['completion_ok'] : 0,
            ];
            $score = bravoGradeScore($level, $n, $j);
            $upd->execute([$score, $n, (int)$g['id']]);
            $grades[$qid]['score'] = $score;
        }
    }

    $total = round(array_sum(array_map(fn($g) => (float)$g['score'], $grades)), 2);
    $passing = bravoGradingPassingScore($db, $level);
    $auto = $total >= $passing ? 'pass' : 'fail';

    $result = $input['result'] ?? '';
    if (!in_array($result, ['pass', 'fail'], true)) return ['error' => '합불 판정을 선택해주세요.'];
    $overridden = $result !== $auto;
    $reason = isset($input['override_reason']) && is_string($input['override_reason']) ? trim($input['override_reason']) : '';
    if ($overridden && $reason === '') return ['error' => '자동 판정과 다르게 확정하려면 사유가 필요합니다.'];
    $memo = isset($input['memo']) && is_string($input['memo']) && trim($input['memo']) !== '' ? trim($input['memo']) : null;

    try {
        $db->prepare("
            INSERT INTO bravo_attempt_grades
                (attempt_id, total_score, passing_score, result, result_overridden, override_reason, memo, confirmed_by)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([
            $attemptId, $total, $passing, $result,
            $overridden ? 1 : 0, $overridden ? mb_substr($reason, 0, 255) : null, $memo, $adminId,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') return ['error' => '이미 확정된 채점입니다.'];
        throw $e;
    }

    return ['total_score' => $total, 'result' => $result];
}

/**
 * 확정 취소 (released 전만). 판정(answer_grades)은 보존.
 */
function bravoAttemptConfirmCancel(PDO $db, array $attempt, array $exam): array {
    $attemptId = (int)$attempt['id'];
    if (!bravoAttemptGradeGet($db, $attemptId)) return ['error' => '확정된 채점이 없습니다.'];
    if (($exam['status'] ?? '') === 'released') return ['error' => '결과가 발표된 시험은 확정을 취소할 수 없습니다.'];
    $db->prepare("DELETE FROM bravo_attempt_grades WHERE attempt_id = ?")->execute([$attemptId]);
    return ['cancelled' => true];
}

/**
 * 채점 대상 시험 목록 (submitted attempt ≥1) + 카운트.
 */
function bravoGradingExamList(PDO $db): array {
    $rows = $db->query("
        SELECT e.id, e.title, e.bravo_level, e.status,
               COUNT(*) AS total,
               SUM(ag.id IS NOT NULL) AS confirmed_cnt,
               SUM(ag.id IS NULL AND COALESCE(g.c, 0) = 0) AS ungraded_cnt,
               SUM(ag.id IS NULL AND COALESCE(g.c, 0) > 0) AS grading_cnt
        FROM bravo_attempts a
        JOIN bravo_exams e ON a.exam_id = e.id
        LEFT JOIN bravo_attempt_grades ag ON ag.attempt_id = a.id
        LEFT JOIN (SELECT attempt_id, COUNT(*) c FROM bravo_answer_grades GROUP BY attempt_id) g ON g.attempt_id = a.id
        WHERE a.status = 'submitted'
        GROUP BY e.id, e.title, e.bravo_level, e.status
        ORDER BY e.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'title' => $r['title'], 'bravo_level' => (int)$r['bravo_level'], 'status' => $r['status'],
        'counts' => ['total' => (int)$r['total'], 'ungraded' => (int)$r['ungraded_cnt'], 'grading' => (int)$r['grading_cnt'], 'confirmed' => (int)$r['confirmed_cnt']],
    ], $rows);
}

/**
 * 시험의 채점 대상 응시 목록 (submitted). 진행도는 attempt 별 현존 N 기준.
 */
function bravoGradingAttemptList(PDO $db, int $examId): array {
    $stmt = $db->prepare("
        SELECT a.id, a.attempt_no, a.submitted_at, a.question_ids, a.member_id,
               bm.real_name, c.cohort,
               ag.total_score, ag.result
        FROM bravo_attempts a
        JOIN bootcamp_members bm ON a.member_id = bm.id
        JOIN cohorts c ON bm.cohort_id = c.id
        LEFT JOIN bravo_attempt_grades ag ON ag.attempt_id = a.id
        WHERE a.exam_id = ? AND a.status = 'submitted'
        ORDER BY a.submitted_at, a.id
    ");
    $stmt->execute([$examId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $attemptRow = ['id' => (int)$r['id'], 'question_ids' => $r['question_ids']];
        $validQids = bravoGradingQuestionIds($db, $attemptRow);
        $grades = bravoGradingValidGrades($db, $attemptRow);
        $out[] = [
            'attempt_id' => (int)$r['id'],
            'member_name' => $r['real_name'],
            'cohort' => $r['cohort'],
            'attempt_no' => (int)$r['attempt_no'],
            'submitted_at' => $r['submitted_at'],
            'graded_count' => count($grades),
            'total_count' => count($validQids),
            'confirmed' => $r['total_score'] !== null ? ['total_score' => (float)$r['total_score'], 'result' => $r['result']] : null,
        ];
    }
    return $out;
}

/**
 * 채점 상세: 문항 카드 데이터 (정답 포함 — 관리자 전용) + 기존 판정 + 확정 정보.
 */
function bravoGradingDetail(PDO $db, array $attempt, array $exam): array {
    $attemptId = (int)$attempt['id'];
    $level = (int)$exam['bravo_level'];
    $validQids = bravoGradingQuestionIds($db, $attempt);
    $grades = bravoGradingValidGrades($db, $attempt);

    $items = [];
    if ($validQids) {
        $place = implode(',', array_fill(0, count($validQids), '?'));
        $qStmt = $db->prepare("
            SELECT id, question_type, korean_text, english_text, accepted_answers, target_chunks,
                   reference_speech_sec, response_time_limit_sec
            FROM bravo_questions WHERE id IN ($place)
        ");
        $qStmt->execute($validQids);
        $qById = [];
        foreach ($qStmt->fetchAll(PDO::FETCH_ASSOC) as $q) $qById[(int)$q['id']] = $q;

        $aStmt = $db->prepare("SELECT id, question_id, audio_mime, duration_ms, retake_used, answered_at FROM bravo_answers WHERE attempt_id = ?");
        $aStmt->execute([$attemptId]);
        $aByQid = [];
        foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) $aByQid[(int)$a['question_id']] = $a;

        foreach ($validQids as $seq => $qid) {
            if (!isset($qById[$qid]) || !isset($aByQid[$qid])) continue;
            $g = $grades[$qid] ?? null;
            $items[] = [
                'answer_id' => (int)$aByQid[$qid]['id'],
                'seq' => $seq,
                'question' => $qById[$qid],
                'answer' => [
                    'audio_mime' => $aByQid[$qid]['audio_mime'],
                    'duration_ms' => $aByQid[$qid]['duration_ms'] !== null ? (int)$aByQid[$qid]['duration_ms'] : null,
                    'retake_used' => (int)$aByQid[$qid]['retake_used'],
                    'answered_at' => $aByQid[$qid]['answered_at'],
                ],
                'grade' => $g ? [
                    'accuracy' => $g['accuracy'], 'chunk_ok' => (int)$g['chunk_ok'],
                    'response_rating' => $g['response_rating'], 'fluency_rating' => $g['fluency_rating'],
                    'completion_ok' => $g['completion_ok'] !== null ? (int)$g['completion_ok'] : null,
                    'score' => (float)$g['score'], 'memo' => $g['memo'],
                ] : null,
            ];
        }
    }

    $confirmed = bravoAttemptGradeGet($db, $attemptId);
    return [
        'attempt' => ['id' => $attemptId, 'attempt_no' => (int)$attempt['attempt_no'], 'submitted_at' => $attempt['submitted_at']],
        'exam' => ['id' => (int)$exam['id'], 'title' => $exam['title'], 'bravo_level' => $level, 'status' => $exam['status']],
        'passing_score' => bravoGradingPassingScore($db, $level),
        'weights' => BRAVO_GRADE_WEIGHTS[$level] ?? null,
        'items' => $items,
        'summary' => bravoGradingSummary($db, $attempt, $level),
        'confirmed' => $confirmed ? [
            'total_score' => (float)$confirmed['total_score'], 'passing_score' => (float)$confirmed['passing_score'],
            'result' => $confirmed['result'], 'result_overridden' => (int)$confirmed['result_overridden'],
            'override_reason' => $confirmed['override_reason'], 'memo' => $confirmed['memo'],
            'confirmed_at' => $confirmed['confirmed_at'],
        ] : null,
    ];
}
