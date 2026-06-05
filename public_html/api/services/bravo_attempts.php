<?php
/**
 * BRAVO 응시(attempt) 서비스 (6차 슬라이스).
 * OT→응시(녹음 답안)→제출. 기존 BRAVO 경로와 무관한 추가형.
 * 업로드 루트는 docroot 밖 — 직접 URL 접근 불가 (서빙은 채점 슬라이스에서 PHP 스트리밍).
 */

require_once __DIR__ . '/bravo.php';
require_once __DIR__ . '/bravo_exam_questions.php';

if (!defined('BRAVO_UPLOAD_ROOT')) {
    define('BRAVO_UPLOAD_ROOT', dirname(__DIR__, 3) . '/bravo_uploads');
}

const BRAVO_AUDIO_MAX_BYTES = 10 * 1024 * 1024; // 10MB (1분 opus/aac ≈ 0.5~1MB)
// ⚠️ php upload_max_filesize/post_max_size 가 이 값 이상이어야 함 (미만이면 $_FILES 가 비어
//    "녹음 파일이 없습니다" 로 떨어짐 — 현재 서버 2G 확인됨)

/**
 * 실측 MIME → 저장 확장자. 미지원이면 null.
 * (브라우저 webm 녹음은 finfo 가 video/webm 으로 읽는 경우가 흔함 — 둘 다 수용)
 */
function bravoAnswerAudioExt(string $mime): ?string {
    $map = [
        'audio/webm' => 'webm', 'video/webm' => 'webm',
        'audio/mp4'  => 'm4a',  'video/mp4'  => 'm4a',
        'audio/ogg'  => 'ogg',  'application/ogg' => 'ogg',
    ];
    return $map[$mime] ?? null;
}

/**
 * 업로드 파일 검증. 성공: ['mime'=>, 'ext'=>], 실패: ['error'=>msg].
 */
function bravoAnswerValidateUpload(array $file): array {
    if (!isset($file['tmp_name']) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => '녹음 업로드에 실패했습니다. 다시 시도해주세요.'];
    }
    $size = filesize($file['tmp_name']);
    if ($size === false || $size <= 0 || $size > BRAVO_AUDIO_MAX_BYTES) {
        return ['error' => '녹음 파일 크기가 올바르지 않습니다.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $ext = bravoAnswerAudioExt($mime);
    if ($ext === null) {
        return ['error' => '지원하지 않는 녹음 형식입니다.'];
    }
    return ['mime' => $mime, 'ext' => $ext];
}

/**
 * 회원의 시험 접근 공통 검증 (intro/start/save 용 — submit 은 미사용).
 * 보유/이전등급/누적 quota 게이트 포함.
 * 성공: ['exam'=>row, 'ctx'=>bravoMemberContext, 'member_key'=>string]
 * 실패: ['error'=>msg, 'code'=>http]
 */
function bravoAttemptExamAccess(PDO $db, int $memberId, int $examId): array {
    $ctx = bravoMemberContext($db, $memberId);
    if (!$ctx) return ['error' => '회원 정보를 찾을 수 없습니다.', 'code' => 404];

    $stmt = $db->prepare("SELECT id, title, bravo_level, exam_mode, start_at, end_at, result_release_at, attempt_limit, target_type, target_cohort_id, status FROM bravo_exams WHERE id = ?");
    $stmt->execute([$examId]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam) return ['error' => '시험을 찾을 수 없습니다.', 'code' => 404];
    if ($exam['status'] !== 'open') return ['error' => '현재 응시할 수 없는 시험입니다.', 'code' => 400];
    if (!bravoAttemptSavePeriodOk($exam)) return ['error' => '응시 기간이 아닙니다.', 'code' => 400];
    if ($exam['target_type'] === 'cohort' && (int)$exam['target_cohort_id'] !== (int)$ctx['row']['cohort_id']) {
        return ['error' => '응시 대상이 아닙니다.', 'code' => 403];
    }
    if (!in_array((int)$exam['bravo_level'], $ctx['eligible'], true)) {
        return ['error' => '응시 자격이 없습니다.', 'code' => 403];
    }
    $memberKey = bravoAttemptMemberKey($ctx['row']);
    $examLevel = (int)$exam['bravo_level'];
    $cur = bravoGradeCurrentLevel($db, $memberKey);
    // ① 보유 차단 — 재응시는 강등 신청 후 (등급 진실원 기준, slice8 의 released-pass 차단 대체)
    if ($cur >= $examLevel) {
        return ['error' => "이미 BRAVO {$examLevel} 등급을 보유하고 있습니다. 재응시하려면 강등 신청을 해주세요.", 'code' => 403];
    }
    // ② 이전등급 요건 (bravo_levels.requires_previous_level — B2←B1, B3←B2)
    $reqPrev = false;
    foreach ($ctx['levels'] as $lvRow) {
        if ((int)$lvRow['level'] === $examLevel) { $reqPrev = (int)$lvRow['requires_previous_level'] === 1; break; }
    }
    if ($reqPrev && $cur < $examLevel - 1) {
        return ['error' => 'BRAVO ' . ($examLevel - 1) . ' 등급 취득 후 응시할 수 있습니다.', 'code' => 403];
    }
    // ③-pre 같은 시험 미확정 submitted → 채점 대기 차단 (quota 보다 먼저 — 더 명확한 안내)
    // (start 의 미확정 체크와 의도적 중복 — 수정 시 두 곳 동기화.)
    $subChk = $db->prepare("
        SELECT COUNT(*) FROM bravo_attempts a
        LEFT JOIN bravo_attempt_grades g ON g.attempt_id = a.id
        WHERE a.exam_id = ? AND a.member_key = ? AND a.status = 'submitted' AND g.id IS NULL
    ");
    $subChk->execute([$examId, $memberKey]);
    if ((int)$subChk->fetchColumn() > 0) {
        return ['error' => '이미 제출한 응시의 채점이 진행 중입니다. 결과 확정 후 다시 도전할 수 있습니다.', 'code' => 400];
    }
    // ③ 등급당 평생 누적 quota (기본 3 + 관리자 extra)
    $quota = bravoAttemptQuotaForLevel($db, $memberKey, $examLevel);
    if ($quota['left'] <= 0) {
        return ['error' => "응시 횟수를 모두 사용했습니다. (누적 {$quota['used']}/{$quota['limit']}) 추가 응시는 운영진에게 문의해주세요.", 'code' => 403];
    }
    return ['exam' => $exam, 'ctx' => $ctx, 'member_key' => $memberKey];
}

/**
 * 기간 게이트 (save/접근용 — submit 은 기간 무관). 순수.
 * period 모드에서 start_at 전이거나 end_at 후면 false.
 */
function bravoAttemptSavePeriodOk(array $exam): bool {
    if (($exam['exam_mode'] ?? '') !== 'period') return true;
    $now = date('Y-m-d H:i:s');
    if (!empty($exam['start_at']) && $now < $exam['start_at']) return false;
    if (!empty($exam['end_at']) && $now > $exam['end_at']) return false;
    return true;
}

function bravoAttemptGet(PDO $db, int $id): ?array {
    $stmt = $db->prepare("SELECT * FROM bravo_attempts WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * attempt 소유 검증: 세션 회원(member_id)의 member_key 와 attempt.member_key 일치 시 행 반환.
 * (기수 row 가 달라도 같은 사람이면 접근 가능)
 */
function bravoAttemptForMember(PDO $db, int $attemptId, int $memberId): ?array {
    $attempt = bravoAttemptGet($db, $attemptId);
    if (!$attempt) return null;
    $ctx = bravoMemberContext($db, $memberId);
    if (!$ctx) return null;
    return $attempt['member_key'] === bravoAttemptMemberKey($ctx['row']) ? $attempt : null;
}

/**
 * 진행 중 attempt 조회 (이어하기).
 */
function bravoAttemptFindInProgress(PDO $db, int $examId, string $memberKey): ?array {
    $stmt = $db->prepare("SELECT * FROM bravo_attempts WHERE exam_id = ? AND member_key = ? AND status = 'in_progress' ORDER BY attempt_no DESC LIMIT 1");
    $stmt->execute([$examId, $memberKey]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * 응시 시작 (이어하기 겸용).
 * - in_progress 존재 → 그대로 반환 (resumed=true, 차감 없음)
 * - 미확정 submitted 존재 → 채점 대기 차단 (확정되면 같은 시험 재응시 가능 — 실제 한도는 누적 quota)
 * - 신규: require_check 검증 → 스냅샷 → 사람 단위 mutex(bravoGradeLockRow) → 같은 시험 in_progress 재확인
 *   (더블클릭 안전망 — 락 대기 중 선행 커밋된 in_progress 를 resumed 로 반환, 이중 차감 방지)
 *   → 누적 quota 재검사 → INSERT
 *   (mutex 가 같은 등급 다른 시험의 동시 시작도 직렬화 — 빈 범위 gap-lock 한계 없음)
 * 성공: ['attempt'=>row, 'resumed'=>bool], 실패: ['error'=>msg]
 * $testHook: 테스트 전용 — mutex 획득 후·in_progress 재확인 직전 호출(race/더블클릭 재현).
 */
function bravoAttemptStart(PDO $db, array $exam, array $memberRow, string $memberKey, bool $otChecked, ?callable $testHook = null): array {
    $examId = (int)$exam['id'];

    $existing = bravoAttemptFindInProgress($db, $examId, $memberKey);
    if ($existing) return ['attempt' => $existing, 'resumed' => true];

    // 미확정 submitted → 채점 대기 차단 (pass/fail 비대칭 차단은 발표 전 정보 누설이라 불문 — 스펙 §4-2)
    // (access ③-pre 와 동일 쿼리 의도적 중복 — start 직접 호출 경로의 방어선. 수정 시 두 곳 동기화.)
    $sub = $db->prepare("
        SELECT COUNT(*) FROM bravo_attempts a
        LEFT JOIN bravo_attempt_grades g ON g.attempt_id = a.id
        WHERE a.exam_id = ? AND a.member_key = ? AND a.status = 'submitted' AND g.id IS NULL
    ");
    $sub->execute([$examId, $memberKey]);
    if ((int)$sub->fetchColumn() > 0) {
        return ['error' => '이미 제출한 응시의 채점이 진행 중입니다. 결과 확정 후 다시 도전할 수 있습니다.'];
    }

    $otCheckedAt = null;
    $ot = bravoOtGet($db, $examId);
    if ($ot && (int)$ot['require_check'] === 1) {
        if (!$otChecked) return ['error' => 'OT 안내 확인 체크가 필요합니다.'];
        $otCheckedAt = date('Y-m-d H:i:s');
    }

    $qids = bravoExamQuestionAssignedIds($db, $examId);
    if (!$qids) return ['error' => '아직 출제 준비 중인 시험입니다.'];

    $owns = !$db->inTransaction();
    if ($owns) $db->beginTransaction();
    try {
        // 사람 단위 mutex — 같은 등급 다른 시험의 동시 시작도 이 행 잠금으로 직렬화
        bravoGradeLockRow($db, $memberKey);
        if ($testHook) $testHook();
        // 더블클릭 재확인 — 락 대기 중 먼저 커밋된 같은 시험 in_progress 가 있으면 그걸 반환 (이중 차감 방지)
        $existing = bravoAttemptFindInProgress($db, $examId, $memberKey);
        if ($existing) {
            if ($owns) $db->rollBack();
            return ['attempt' => $existing, 'resumed' => true];
        }
        $quota = bravoAttemptQuotaForLevel($db, $memberKey, (int)$exam['bravo_level']);
        if ($quota['left'] <= 0) {
            if ($owns) $db->rollBack();
            return ['error' => "응시 횟수를 모두 사용했습니다. (누적 {$quota['used']}/{$quota['limit']})"];
        }
        // attempt_no = 이 시험 내 순번 (UNIQUE(exam, member, attempt_no) — 누적 used 와 분리)
        $no = $db->prepare("SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM bravo_attempts WHERE exam_id = ? AND member_key = ?");
        $no->execute([$examId, $memberKey]);
        $attemptNo = (int)$no->fetchColumn();
        $ins = $db->prepare("INSERT INTO bravo_attempts (exam_id, member_key, member_id, attempt_no, question_ids, ot_checked_at) VALUES (?,?,?,?,?,?)");
        $ins->execute([$examId, $memberKey, (int)$memberRow['id'], $attemptNo, json_encode($qids), $otCheckedAt]);
        $newId = (int)$db->lastInsertId();
        if ($owns) $db->commit();
        return ['attempt' => bravoAttemptGet($db, $newId), 'resumed' => false];
    } catch (PDOException $e) {
        if ($owns) $db->rollBack();
        if ($e->getCode() === '23000') {
            // UNIQUE 충돌 안전망 — 락 후 재확인을 통과한 극단적 경쟁(같은 커넥션 밖) 시 기존 in_progress 반환
            $existing = bravoAttemptFindInProgress($db, $examId, $memberKey);
            if ($existing) return ['attempt' => $existing, 'resumed' => true];
        }
        throw $e;
    }
}

/**
 * 스냅샷 순서대로 회원에게 보여줄 문제 페이로드. 정답 필드 미포함.
 * 은행에서 삭제된 문제는 제외(스냅샷엔 남지만 출제 불가 — submit 완비 판정도 동일 기준).
 */
function bravoAttemptQuestions(PDO $db, array $attempt): array {
    $qids = array_map('intval', json_decode((string)$attempt['question_ids'], true) ?: []);
    if (!$qids) return [];

    $place = implode(',', array_fill(0, count($qids), '?'));
    $stmt = $db->prepare("
        SELECT id, question_type, korean_text, target_chunks, reference_speech_sec, response_time_limit_sec
        FROM bravo_questions WHERE id IN ($place)
    ");
    $stmt->execute($qids);
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) $byId[(int)$q['id']] = $q;

    $out = [];
    foreach ($qids as $i => $qid) {
        if (!isset($byId[$qid])) continue;
        $q = $byId[$qid];
        $q['seq'] = $i;
        $out[] = $q;
    }
    return $out;
}

/**
 * 답변 완료된 question_id 목록.
 */
function bravoAttemptAnsweredIds(PDO $db, int $attemptId): array {
    $stmt = $db->prepare("SELECT question_id FROM bravo_answers WHERE attempt_id = ? ORDER BY seq");
    $stmt->execute([$attemptId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 녹음 답안 저장 (신규 또는 재녹음 1회 교체).
 * $srcPath: 업로드 tmp 파일. $viaUpload=true 면 move_uploaded_file, false(테스트)면 rename.
 * 성공: ['saved'=>true, 'answered_count'=>n, 'all_answered'=>bool], 실패: ['error'=>msg]
 */
function bravoAnswerStore(PDO $db, array $attempt, int $questionId, string $srcPath, string $mime, string $ext, ?int $durationMs, bool $viaUpload = true): array {
    if (($attempt['status'] ?? '') !== 'in_progress') {
        return ['error' => '이미 제출된 응시입니다.'];
    }
    $qids = array_map('intval', json_decode((string)$attempt['question_ids'], true) ?: []);
    $seq = array_search($questionId, $qids, true);
    if ($seq === false) return ['error' => '이 시험의 문제가 아닙니다.'];

    $attemptId = (int)$attempt['id'];
    $ex = $db->prepare("SELECT retake_used FROM bravo_answers WHERE attempt_id = ? AND question_id = ?");
    $ex->execute([$attemptId, $questionId]);
    $prev = $ex->fetch(PDO::FETCH_ASSOC);
    if ($prev && (int)$prev['retake_used'] === 1) {
        return ['error' => '재녹음 기회를 이미 사용했습니다.'];
    }

    $dir = BRAVO_UPLOAD_ROOT . '/answers/' . $attemptId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return ['error' => '저장 공간 오류입니다. 관리자에게 문의해주세요.'];
    }
    $dest = $dir . '/' . $questionId . '.' . $ext;
    $moved = $viaUpload ? move_uploaded_file($srcPath, $dest) : rename($srcPath, $dest);
    if (!$moved) return ['error' => '녹음 저장에 실패했습니다. 다시 시도해주세요.'];
    // 재녹음이 다른 포맷일 때 옛 확장자 파일 정리
    foreach (glob($dir . '/' . $questionId . '.*') ?: [] as $f) {
        if ($f !== $dest) @unlink($f);
    }

    $relPath = 'answers/' . $attemptId . '/' . $questionId . '.' . $ext;
    try {
        $db->prepare("
            INSERT INTO bravo_answers (attempt_id, question_id, seq, audio_path, audio_mime, duration_ms)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                audio_path = VALUES(audio_path), audio_mime = VALUES(audio_mime),
                duration_ms = VALUES(duration_ms), retake_used = 1, answered_at = CURRENT_TIMESTAMP
        ")->execute([$attemptId, $questionId, (int)$seq, $relPath, $mime, $durationMs]);
    } catch (Throwable $e) {
        @unlink($dest); // 행 실패 시 고아 파일 정리
        throw $e;
    }

    $answered = bravoAttemptAnsweredIds($db, $attemptId);
    $existingQids = array_map(fn($q) => (int)$q['id'], bravoAttemptQuestions($db, $attempt));
    return [
        'saved' => true,
        'answered_count' => count($answered),
        'all_answered' => array_diff($existingQids, $answered) === [],
    ];
}

/**
 * 최종 제출. 기간 체크 없음(의도) — 답안은 기간 내에만 저장되므로 완비 자체가 증명.
 * 완비 판정은 스냅샷 ∩ 현존 문제 기준(은행 삭제 문제로 인한 영구 미완비 방지).
 * 성공: ['submitted'=>true], 실패: ['error'=>msg(, 'missing'=>[qid...])]
 */
function bravoAttemptSubmit(PDO $db, array $attempt): array {
    if (($attempt['status'] ?? '') !== 'in_progress') {
        return ['error' => '이미 제출된 응시입니다.'];
    }
    $attemptId = (int)$attempt['id'];
    $required = array_map(fn($q) => (int)$q['id'], bravoAttemptQuestions($db, $attempt));
    if (!$required) {
        return ['error' => '출제된 문제가 없습니다. 관리자에게 문의해주세요.'];
    }
    $answered = bravoAttemptAnsweredIds($db, $attemptId);
    $missing = array_values(array_diff($required, $answered));
    if ($missing) {
        return ['error' => '아직 답변하지 않은 문제가 ' . count($missing) . '개 있습니다.', 'missing' => $missing];
    }
    $db->prepare("UPDATE bravo_attempts SET status = 'submitted', submitted_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'in_progress'")
       ->execute([$attemptId]);
    return ['submitted' => true];
}

/**
 * attempt 의 녹음 디렉토리 삭제 (시험 삭제 cascade 용). 루트 밖 경로 방어.
 */
function bravoAttemptPurgeFiles(int $attemptId): void {
    $dir = BRAVO_UPLOAD_ROOT . '/answers/' . $attemptId;
    $real = realpath($dir);
    $root = realpath(BRAVO_UPLOAD_ROOT);
    if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) return;
    foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
}
