<?php
/**
 * BRAVO 시험-문제 배정 서비스 (5차 슬라이스).
 * 시험↔문제 N:M junction. 벌크 set(replace). 기존 BRAVO 경로와 무관한 추가형.
 */

/**
 * 시험에 배정된 question_id 배열 (display_order 순).
 */
function bravoExamQuestionAssignedIds(PDO $db, int $examId): array {
    $stmt = $db->prepare("SELECT question_id FROM bravo_exam_questions WHERE exam_id = ? ORDER BY display_order, id");
    $stmt->execute([$examId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 시험에 배정된 문제 전체 행 (bravo_questions 조인, display_order 순). display_order 컬럼 포함.
 */
function bravoExamQuestionList(PDO $db, int $examId): array {
    $stmt = $db->prepare("
        SELECT q.*, beq.display_order
        FROM bravo_exam_questions beq
        JOIN bravo_questions q ON beq.question_id = q.id
        WHERE beq.exam_id = ?
        ORDER BY beq.display_order, beq.id
    ");
    $stmt->execute([$examId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 시험 배정 전체 교체. 입력 순서대로 display_order(0,1,2,...) 부여. 존재하는 question_id만.
 * caller 가 이미 트랜잭션 안이면 중첩 begin/commit 생략 (PDO 중첩 트랜잭션 미지원).
 */
function bravoExamQuestionSet(PDO $db, int $examId, array $questionIds): void {
    // 정수화 + 순서보존 중복제거
    $ids = [];
    foreach ($questionIds as $qid) {
        $qid = (int)$qid;
        if ($qid > 0 && !in_array($qid, $ids, true)) $ids[] = $qid;
    }
    // 실제 존재하는 문제 id 만 남김
    if ($ids) {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id FROM bravo_questions WHERE id IN ($place)");
        $stmt->execute($ids);
        $exist = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_values(array_filter($ids, function ($q) use ($exist) { return in_array($q, $exist, true); }));
    }

    $owns = !$db->inTransaction();
    if ($owns) $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM bravo_exam_questions WHERE exam_id = ?")->execute([$examId]);
        if ($ids) {
            $ins = $db->prepare("INSERT INTO bravo_exam_questions (exam_id, question_id, display_order) VALUES (?, ?, ?)");
            foreach ($ids as $i => $qid) $ins->execute([$examId, $qid, $i]);
        }
        if ($owns) $db->commit();
    } catch (Throwable $e) {
        if ($owns) $db->rollBack();
        throw $e;
    }
}
