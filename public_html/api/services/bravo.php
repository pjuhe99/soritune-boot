<?php
/**
 * BRAVO 도전 시스템 서비스.
 * 1차 슬라이스: 자격 자동계산 순수 함수 + 관리자 데이터 서비스.
 * 기존 member_history_stats.bravo_grade 와 무관한 추가형.
 */
require_once __DIR__ . '/bravo_grades.php';

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

/**
 * 날짜 문자열 → unix timestamp (빈/무효는 null). 검증/비교용 순수 헬퍼.
 */
function bravoTs(?string $v): ?int {
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;
    // 관리자 입력: 포맷 강제 없이 strtotime 으로 관대하게 파싱 (date-picker 입력 전제)
    $ts = strtotime($v);
    return $ts === false ? null : $ts;
}

/**
 * 시험 입력 검증. 에러 메시지 배열 반환 (빈 배열 = 통과). 순수 함수.
 */
function bravoValidateExam(array $d): array {
    $errors = [];

    $title = isset($d['title']) && is_string($d['title']) ? trim($d['title']) : '';
    if ($title === '') $errors[] = '시험명을 입력해주세요.';

    $level = isset($d['bravo_level']) ? (int)$d['bravo_level'] : 0;
    if (!in_array($level, [1,2,3], true)) $errors[] = 'BRAVO 등급은 1/2/3 중 하나여야 합니다.';

    $mode = $d['exam_mode'] ?? '';
    if (!in_array($mode, ['period','always'], true)) $errors[] = '응시 방식이 올바르지 않습니다.';

    // status 누락은 의도적으로 'preparing' 으로 허용 (DB 컬럼 기본값 + bravoExamPersistData 기본값과 일관). title/level 과 달리 안전한 기본값이 있음.
    $status = $d['status'] ?? 'preparing';
    if (!in_array($status, ['preparing','open','closed','released'], true)) $errors[] = '시험 상태가 올바르지 않습니다.';

    $limit = isset($d['attempt_limit']) ? (int)$d['attempt_limit'] : 0;
    if ($limit < 1) $errors[] = '응시 횟수는 1 이상이어야 합니다.';

    $target = $d['target_type'] ?? '';
    if (!in_array($target, ['all','cohort'], true)) {
        $errors[] = '대상 유형이 올바르지 않습니다.';
    } elseif ($target === 'cohort') {
        $cid = isset($d['target_cohort_id']) ? (int)$d['target_cohort_id'] : 0;
        if ($cid < 1) $errors[] = '특정 기수 대상일 때 기수를 선택해주세요.';
    }

    if ($mode === 'period') {
        $s = bravoTs($d['start_at'] ?? null);
        $e = bravoTs($d['end_at'] ?? null);
        $r = bravoTs($d['result_release_at'] ?? null);
        if ($s === null) $errors[] = '응시 시작일이 올바르지 않습니다.';
        if ($e === null) $errors[] = '응시 종료일이 올바르지 않습니다.';
        if ($r === null) $errors[] = '결과 발표일이 올바르지 않습니다.';
        if ($s !== null && $e !== null && $s > $e) $errors[] = '응시 시작일은 종료일보다 앞서야 합니다.';
        if ($e !== null && $r !== null && $r < $e) $errors[] = '결과 발표일은 응시 종료일 이후여야 합니다.';
    }

    return $errors;
}

/**
 * 시험 날짜 문자열 → 'Y-m-d H:i:s' 정규화 (빈/무효는 null).
 */
function bravoFmtDt(?string $v): ?string {
    $ts = bravoTs($v);
    return $ts === null ? null : date('Y-m-d H:i:s', $ts);
}

/**
 * 폼 입력 → bravo_exams 저장용 정규화 컬럼 배열.
 * always 모드면 날짜 NULL, all 타겟이면 cohort_id NULL.
 */
function bravoExamPersistData(array $d): array {
    $mode   = in_array($d['exam_mode'] ?? '', ['period','always'], true) ? $d['exam_mode'] : 'period';
    $target = in_array($d['target_type'] ?? '', ['all','cohort'], true) ? $d['target_type'] : 'all';
    $status = in_array($d['status'] ?? '', ['preparing','open','closed','released'], true) ? $d['status'] : 'preparing';
    $isPeriod = $mode === 'period';
    $cid = ($target === 'cohort') ? ((int)($d['target_cohort_id'] ?? 0) ?: null) : null;
    return [
        'title'             => trim((string)($d['title'] ?? '')),
        'bravo_level'       => (int)($d['bravo_level'] ?? 0),
        'exam_mode'         => $mode,
        'start_at'          => $isPeriod ? bravoFmtDt($d['start_at'] ?? null) : null,
        'end_at'            => $isPeriod ? bravoFmtDt($d['end_at'] ?? null) : null,
        'result_release_at' => $isPeriod ? bravoFmtDt($d['result_release_at'] ?? null) : null,
        'attempt_limit'     => max(1, (int)($d['attempt_limit'] ?? 3)),
        'target_type'       => $target,
        'target_cohort_id'  => $cid,
        'status'            => $status,
    ];
}

/**
 * 시험 목록. level명/cohort라벨 조인. 선택 필터: status / bravo_level / target_cohort_id.
 */
function bravoExamList(PDO $db, array $filters = []): array {
    $where = []; $params = [];
    if (!empty($filters['status']))           { $where[] = 'e.status = ?';           $params[] = $filters['status']; }
    if (!empty($filters['bravo_level']))      { $where[] = 'e.bravo_level = ?';      $params[] = (int)$filters['bravo_level']; }
    if (!empty($filters['target_cohort_id'])) { $where[] = 'e.target_cohort_id = ?'; $params[] = (int)$filters['target_cohort_id']; }
    $sql = "SELECT e.*, bl.name AS level_name, c.cohort AS target_cohort_label
            FROM bravo_exams e
            LEFT JOIN bravo_levels bl ON e.bravo_level = bl.level
            LEFT JOIN cohorts c ON e.target_cohort_id = c.id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY e.id DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 시험 생성. 정규화 후 INSERT, 신규 id 반환.
 */
function bravoExamCreate(PDO $db, array $d, int $adminId): int {
    $c = bravoExamPersistData($d);
    $db->prepare("
        INSERT INTO bravo_exams
            (title, bravo_level, exam_mode, start_at, end_at, result_release_at, attempt_limit, target_type, target_cohort_id, status, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $c['title'], $c['bravo_level'], $c['exam_mode'], $c['start_at'], $c['end_at'], $c['result_release_at'],
        $c['attempt_limit'], $c['target_type'], $c['target_cohort_id'], $c['status'], $adminId,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * 시험 수정 (status 포함 전체 필드).
 * status 가 released 로 '전환'되는 시점에 확정 pass 전원 승급 (bravoGradeApplyExamPass).
 * 재전환(released→closed→released)은 ApplyExamPass 내부 no-op 으로 멱등.
 * prevStatus 조회는 트랜잭션 밖 — 동시 released 저장 시 훅 2회 가능하나 ApplyExamPass no-op 멱등으로 등급 오염 없음(최악 로그 1행 중복 — 허용). 훅 예외 시 status 변경까지 롤백(발표 실패가 등급 누락보다 수복 명확 — 의도).
 */
function bravoExamUpdate(PDO $db, int $id, array $d): void {
    $c = bravoExamPersistData($d);
    $prevStmt = $db->prepare("SELECT status FROM bravo_exams WHERE id = ?");
    $prevStmt->execute([$id]);
    $prevStatus = $prevStmt->fetchColumn();

    $owns = !$db->inTransaction();
    if ($owns) $db->beginTransaction();
    try {
        $db->prepare("
            UPDATE bravo_exams SET
                title=?, bravo_level=?, exam_mode=?, start_at=?, end_at=?, result_release_at=?,
                attempt_limit=?, target_type=?, target_cohort_id=?, status=?
            WHERE id=?
        ")->execute([
            $c['title'], $c['bravo_level'], $c['exam_mode'], $c['start_at'], $c['end_at'], $c['result_release_at'],
            $c['attempt_limit'], $c['target_type'], $c['target_cohort_id'], $c['status'], $id,
        ]);
        if ($prevStatus !== false && $prevStatus !== 'released' && $c['status'] === 'released') {
            bravoGradeApplyExamPass($db, ['id' => $id, 'bravo_level' => $c['bravo_level'], 'title' => $c['title']]);
        }
        if ($owns) $db->commit();
    } catch (Throwable $e) {
        if ($owns) $db->rollBack();
        throw $e;
    }
}

/**
 * 횟수 집계 키: user_id 우선, 없으면 p:<전화> 폴백. 순수.
 */
function bravoAttemptMemberKey(array $memberRow): string {
    $uid = trim((string)($memberRow['user_id'] ?? ''));
    if ($uid !== '') return $uid;
    // phone 도 빈 회원은 로그인 경계에서 차단됨 — 'p:' 단독 키는 도달 불가
    return 'p:' . trim((string)($memberRow['phone'] ?? ''));
}

/**
 * 회원 BRAVO 컨텍스트: 회원행 + 유효회독 + 수동부여 + 자격 레벨. 회원 없으면 null.
 * row: id, user_id, phone, cohort_id, real_name, nickname, cohort
 */
function bravoMemberContext(PDO $db, int $memberId): ?array {
    $mStmt = $db->prepare("
        SELECT bm.id, bm.user_id, bm.phone, bm.cohort_id, bm.real_name, bm.nickname, c.cohort
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.id = ?
    ");
    $mStmt->execute([$memberId]);
    $m = $mStmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) return null;

    $cStmt = $db->prepare("
        SELECT COALESCE(mhs_u.completed_bootcamp_count, mhs_p.completed_bootcamp_count, 0) AS completed
        FROM bootcamp_members bm
        LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
        LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
        WHERE bm.id = ?
    ");
    $cStmt->execute([$memberId]);
    $completed = (int)$cStmt->fetchColumn();

    $override = null; $granted = [];
    if (!empty($m['user_id'])) {
        $sStmt = $db->prepare("SELECT review_count_override, granted_levels FROM bravo_member_settings WHERE user_id = ?");
        $sStmt->execute([$m['user_id']]);
        $set = $sStmt->fetch(PDO::FETCH_ASSOC);
        if ($set) {
            $override = $set['review_count_override'] !== null ? (int)$set['review_count_override'] : null;
            $granted  = bravoParseGrantedLevels($set['granted_levels']);
        }
    }

    $levels   = bravoLoadLevels($db);
    $eligible = bravoEligibleLevels($override, $completed, $granted, $levels);

    return [
        'row'       => $m,
        'completed' => $completed,
        'override'  => $override,
        'granted'   => $granted,
        'levels'    => $levels,
        'eligible'  => $eligible,
    ];
}

/**
 * 한 시험에 대한 이 사람(member_key)의 응시 현황 (status 카드용).
 * exam.status='released' 일 때만 확정(bravo_attempt_grades) 결과를 result 필드로 노출 —
 * 발표 전 비공개가 쿼리 조건으로 보장됨 (미released 시 result 키 자체가 없음).
 * used/limit 은 등급당 평생 누적 quota (시험 단위 아님 — 정책 슬라이스에서 교체).
 */
function bravoStatusAttempts(PDO $db, array $exam, string $memberKey): array {
    $examId = (int)$exam['id'];
    // used/limit 은 등급당 평생 누적 quota (시험 단위 아님 — 정책 슬라이스에서 교체)
    $quota = bravoAttemptQuotaForLevel($db, $memberKey, (int)$exam['bravo_level']);

    $stmt = $db->prepare("SELECT id, status, question_ids FROM bravo_attempts WHERE exam_id = ? AND member_key = ? ORDER BY attempt_no");
    $stmt->execute([$examId, $memberKey]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $submitted = false; $inProgress = null;
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM bravo_answers WHERE attempt_id = ?");
    foreach ($rows as $a) {
        if ($a['status'] === 'submitted') {
            $submitted = true;
        } elseif ($a['status'] === 'in_progress') {
            $total = count(json_decode((string)$a['question_ids'], true) ?: []);
            $cntStmt->execute([(int)$a['id']]);
            // in_progress 가 이론상 복수면 마지막(최대 attempt_no) 채택 — bravoAttemptFindInProgress 의 ORDER BY attempt_no DESC 와 일치
            $inProgress = ['attempt_id' => (int)$a['id'], 'answered' => (int)$cntStmt->fetchColumn(), 'total' => $total];
        }
    }
    $out = ['exam_id' => $examId, 'used' => $quota['used'], 'limit' => $quota['limit'], 'in_progress' => $inProgress, 'submitted' => $submitted];

    if (($exam['status'] ?? '') === 'released') {
        $gStmt = $db->prepare("
            SELECT a.id AS attempt_id, g.result, g.total_score, g.passing_score
            FROM bravo_attempts a
            JOIN bravo_attempt_grades g ON g.attempt_id = a.id
            WHERE a.exam_id = ? AND a.member_key = ? AND a.status = 'submitted'
            ORDER BY a.attempt_no DESC
            LIMIT 1
        ");
        $gStmt->execute([$examId, $memberKey]);
        $g = $gStmt->fetch(PDO::FETCH_ASSOC);
        if ($g) {
            $cStmt = $db->prepare("SELECT COUNT(*) FROM bravo_certificates WHERE attempt_id = ?");
            $cStmt->execute([(int)$g['attempt_id']]);
            $out['result'] = [
                'attempt_id'    => (int)$g['attempt_id'],
                'result'        => $g['result'],
                'total_score'   => (float)$g['total_score'],
                'passing_score' => (float)$g['passing_score'],
                'cert_issued'   => (int)$cStmt->fetchColumn() > 0,
            ];
        }
    }
    return $out;
}

/**
 * 시험 삭제 (하드). 연결된 문제 배정(bravo_exam_questions)·OT(bravo_exam_ot)·
 * 응시 기록(bravo_attempts/bravo_answers/bravo_answer_grades/bravo_attempt_grades + 녹음 파일) 도 함께 삭제.
 */
function bravoExamDelete(PDO $db, int $id): void {
    $aStmt = $db->prepare("SELECT id FROM bravo_attempts WHERE exam_id = ?");
    $aStmt->execute([$id]);
    $attemptIds = array_map('intval', $aStmt->fetchAll(PDO::FETCH_COLUMN));

    $owns = !$db->inTransaction();
    if ($owns) $db->beginTransaction();
    try {
        if ($attemptIds) {
            $place = implode(',', array_fill(0, count($attemptIds), '?'));
            $db->prepare("DELETE FROM bravo_answer_grades WHERE attempt_id IN ($place)")->execute($attemptIds);
            $db->prepare("DELETE FROM bravo_attempt_grades WHERE attempt_id IN ($place)")->execute($attemptIds);
            $db->prepare("DELETE FROM bravo_answers WHERE attempt_id IN ($place)")->execute($attemptIds);
            $db->prepare("DELETE FROM bravo_attempts WHERE exam_id = ?")->execute([$id]);
        }
        $db->prepare("DELETE FROM bravo_exam_questions WHERE exam_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM bravo_exam_ot WHERE exam_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM bravo_exams WHERE id = ?")->execute([$id]);
        if ($owns) $db->commit();
    } catch (Throwable $e) {
        if ($owns) $db->rollBack();
        throw $e;
    }
    // 녹음 파일 정리는 DB 확정 후 (bravo_attempts.php 로드 시에만)
    if ($attemptIds && function_exists('bravoAttemptPurgeFiles')) {
        foreach ($attemptIds as $aid) bravoAttemptPurgeFiles($aid);
    }
}

/**
 * 회원(bootcamp_members.id) 의 BRAVO 도전 상태.
 * 슬라이스1 자격 순수함수 재사용 + 등급별 매칭 시험(open 우선, preparing 비공개) 조회.
 * 반환: ['member'=>{real_name,nickname,cohort,effective_review_count,current_level}|null,
 *        'levels'=>[{level,name,required_review_count,eligible,status,held,prev_required,quota,exam|null,attempts|null}]]
 * held: 현재 등급 보유 여부 (current_level >= L). prev_required: 이전 등급 미보유로 응시 불가.
 * quota: 등급당 평생 누적 응시 횟수 {used,limit,left}. exam 에 id/attempt_limit 포함(slice6).
 */
function bravoMemberStatus(PDO $db, int $memberId): array {
    $ctx = bravoMemberContext($db, $memberId);
    if (!$ctx) {
        return ['member' => null, 'levels' => []];
    }
    $m = $ctx['row'];
    $memberKey = bravoAttemptMemberKey($m);

    $exStmt = $db->prepare("
        SELECT id, title, bravo_level, exam_mode, start_at, end_at, result_release_at, status, attempt_limit
        FROM bravo_exams
        WHERE bravo_level = ?
          AND (target_type = 'all' OR target_cohort_id = ?)
          AND status IN ('open','closed','released')
        ORDER BY (status = 'open') DESC, id DESC
        LIMIT 1
    ");

    $cur = bravoGradeCurrentLevel($db, $memberKey);

    $out = [];
    foreach ($ctx['levels'] as $lv) {
        $L = (int)$lv['level'];
        $isElig = in_array($L, $ctx['eligible'], true);
        $exStmt->execute([$L, (int)$m['cohort_id']]);
        $exam = $exStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $attempts = $exam ? bravoStatusAttempts($db, $exam, $memberKey) : null;
        $quota = bravoAttemptQuotaForLevel($db, $memberKey, $L);
        $out[] = [
            'level'                 => $L,
            'name'                  => $lv['name'],
            'required_review_count' => (int)$lv['required_review_count'],
            'eligible'              => $isElig,
            'status'                => $isElig ? 'eligible' : 'ineligible',
            'held'                  => $cur >= $L,
            'prev_required'         => ((int)$lv['requires_previous_level'] === 1) && $cur < $L - 1,
            'quota'                 => $quota,
            'exam'                  => $exam,
            'attempts'              => $attempts,
        ];
    }

    return [
        'member' => [
            'real_name'              => $m['real_name'],
            'nickname'               => $m['nickname'],
            'cohort'                 => $m['cohort'],
            'effective_review_count' => bravoEffectiveReviewCount($ctx['override'], $ctx['completed']),
            'current_level'          => $cur,
        ],
        'levels' => $out,
    ];
}

/**
 * OT 입력 검증. exam_id 필수, 나머지 필드는 모두 선택. 순수.
 */
function bravoOtValidate(array $d): array {
    $errors = [];
    $examId = isset($d['exam_id']) ? (int)$d['exam_id'] : 0;
    if ($examId < 1) $errors[] = '시험을 지정해주세요.';
    return $errors;
}

/**
 * 폼 입력 → bravo_exam_ot 저장용 정규화. 빈 문자열은 NULL, require_check 0/1. 순수.
 */
function bravoOtPersistData(array $d): array {
    $strOrNull = function ($v) {
        if (!is_string($v)) return null;
        $v = trim($v);
        return $v === '' ? null : $v;
    };
    return [
        'title'         => $strOrNull($d['title'] ?? null),
        'intro_text'    => $strOrNull($d['intro_text'] ?? null),
        'video_url'     => $strOrNull($d['video_url'] ?? null),
        'type1_text'    => $strOrNull($d['type1_text'] ?? null),
        'type2_text'    => $strOrNull($d['type2_text'] ?? null),
        'type3_text'    => $strOrNull($d['type3_text'] ?? null),
        'require_check' => !empty($d['require_check']) ? 1 : 0,
    ];
}

/**
 * 시험 OT 조회 (없으면 null).
 */
function bravoOtGet(PDO $db, int $examId): ?array {
    $stmt = $db->prepare("SELECT * FROM bravo_exam_ot WHERE exam_id = ?");
    $stmt->execute([$examId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * 시험 OT upsert (exam_id UNIQUE 기준).
 */
function bravoOtUpsert(PDO $db, int $examId, array $d, int $adminId): void {
    $c = bravoOtPersistData($d);
    $db->prepare("
        INSERT INTO bravo_exam_ot
            (exam_id, title, intro_text, video_url, type1_text, type2_text, type3_text, require_check, updated_by)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            title=VALUES(title), intro_text=VALUES(intro_text), video_url=VALUES(video_url),
            type1_text=VALUES(type1_text), type2_text=VALUES(type2_text), type3_text=VALUES(type3_text),
            require_check=VALUES(require_check), updated_by=VALUES(updated_by)
    ")->execute([
        $examId, $c['title'], $c['intro_text'], $c['video_url'],
        $c['type1_text'], $c['type2_text'], $c['type3_text'], $c['require_check'], $adminId,
    ]);
}
