<?php
/**
 * BRAVO 등급 진실원 서비스 (등급 단일화 슬라이스).
 * - bravo_member_grades: 사람 단위(member_key = user_id ?: 'p:'+phone) 현재 등급 + 추가 응시 횟수.
 *   행 부재 = 무등급(0)·추가횟수 0. 이 행은 등급당 quota 동시성 mutex 로도 사용 (bravoGradeLockRow).
 * - bravo_grade_log: 모든 등급 변경 이력 (grandfather/exam_pass/self_demotion/admin_adjust).
 * 레거시 member_history_stats.bravo_grade 는 backfill 후 freeze — 이 테이블이 유일한 진실원.
 */

const BRAVO_BASE_ATTEMPTS_PER_LEVEL = 3; // 등급당 평생 기본 응시 횟수 (초과는 관리자 extra 부여 — 유료 정책 운영 수동)

/**
 * 현재 등급 (행 부재 = 0).
 */
function bravoGradeCurrentLevel(PDO $db, string $memberKey): int {
    $stmt = $db->prepare("SELECT current_level FROM bravo_member_grades WHERE member_key = ?");
    $stmt->execute([$memberKey]);
    $v = $stmt->fetchColumn();
    return $v === false ? 0 : (int)$v;
}

/**
 * 등급 행 전체 (없으면 null).
 */
function bravoGradeRow(PDO $db, string $memberKey): ?array {
    $stmt = $db->prepare("SELECT * FROM bravo_member_grades WHERE member_key = ?");
    $stmt->execute([$memberKey]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * 사람 단위 mutex: 행 보장(INSERT IGNORE) 후 FOR UPDATE 잠금. 트랜잭션 안에서만 호출(호출부 책임).
 * 행이 항상 존재하므로 빈 범위 gap-lock 한계 없이 같은 사람의 동시 작업이 직렬화된다.
 * ⚠️ autocommit(트랜잭션 밖)에서 호출 금지 — INSERT IGNORE 가 즉시 커밋되어 미사용 행이 영구 잔류함.
 */
function bravoGradeLockRow(PDO $db, string $memberKey): array {
    $db->prepare("INSERT IGNORE INTO bravo_member_grades (member_key) VALUES (?)")->execute([$memberKey]);
    $stmt = $db->prepare("SELECT * FROM bravo_member_grades WHERE member_key = ? FOR UPDATE");
    $stmt->execute([$memberKey]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * 등급 설정 + 이력 로그. 같은 레벨이면 no-op(로그 없음). 0~3 클램프.
 * 잠금 없는 read-then-write — 동시 호출 시 로그 from_level 이 stale 할 수 있으나 current_level 은 last-write 로 정합. 직렬화가 필요하면 호출부가 bravoGradeLockRow 선행 (Demote/Start 패턴).
 */
function bravoGradeSet(PDO $db, string $memberKey, int $to, string $source, ?int $refId = null, ?string $note = null): void {
    $to = max(0, min(3, $to));
    $from = bravoGradeCurrentLevel($db, $memberKey);
    if ($from === $to) return;
    $db->prepare("
        INSERT INTO bravo_member_grades (member_key, current_level) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE current_level = VALUES(current_level)
    ")->execute([$memberKey, $to]);
    $db->prepare("INSERT INTO bravo_grade_log (member_key, from_level, to_level, source, ref_id, note) VALUES (?,?,?,?,?,?)")
       ->execute([$memberKey, $from, $to, $source, $refId, $note !== null ? mb_substr($note, 0, 255) : null]);
}

/**
 * 셀프 강등: 한 단계 하향 (B1→0 포함). 0 이면 에러. 사람 행 잠금으로 더블클릭 멱등.
 * 성공: ['from'=>n, 'to'=>n-1]
 */
function bravoGradeDemote(PDO $db, string $memberKey): array {
    $owns = !$db->inTransaction();
    if ($owns) $db->beginTransaction();
    try {
        $row = bravoGradeLockRow($db, $memberKey);
        $from = (int)$row['current_level'];
        if ($from < 1) {
            if ($owns) $db->rollBack();
            return ['error' => '내릴 등급이 없습니다.'];
        }
        $to = $from - 1;
        bravoGradeSet($db, $memberKey, $to, 'self_demotion', null, null);
        if ($owns) $db->commit();
        return ['from' => $from, 'to' => $to];
    } catch (Throwable $e) {
        if ($owns) $db->rollBack();
        throw $e;
    }
}

/**
 * released 전환 훅: 그 시험의 확정 pass 전원을 max(현재, 시험등급) 으로 승급. 변경 인원 반환.
 * 이미 같거나 높은 등급은 no-op(로그 없음) — released 재전환에도 멱등.
 */
function bravoGradeApplyExamPass(PDO $db, array $exam): int {
    $level = (int)$exam['bravo_level'];
    $stmt = $db->prepare("
        SELECT DISTINCT a.member_key
        FROM bravo_attempts a
        JOIN bravo_attempt_grades g ON g.attempt_id = a.id AND g.result = 'pass'
        WHERE a.exam_id = ?
    ");
    $stmt->execute([(int)$exam['id']]);
    $n = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
        if (bravoGradeCurrentLevel($db, (string)$key) < $level) {
            bravoGradeSet($db, (string)$key, $level, 'exam_pass', (int)$exam['id'], isset($exam['title']) ? (string)$exam['title'] : null);
            $n++;
        }
    }
    return $n;
}

/**
 * 등급당 평생 누적 quota: used = 그 등급 모든 시험의 attempt 수 합산, limit = 기본 3 + 관리자 extra.
 */
function bravoAttemptQuotaForLevel(PDO $db, string $memberKey, int $level): array {
    $cnt = $db->prepare("
        SELECT COUNT(*)
        FROM bravo_attempts a
        JOIN bravo_exams e ON e.id = a.exam_id
        WHERE a.member_key = ? AND e.bravo_level = ?
    ");
    $cnt->execute([$memberKey, $level]);
    $used = (int)$cnt->fetchColumn();
    $row = bravoGradeRow($db, $memberKey);
    $extraCol = 'extra_attempts_' . max(1, min(3, $level));
    $extra = $row ? (int)$row[$extraCol] : 0;
    $limit = BRAVO_BASE_ATTEMPTS_PER_LEVEL + $extra;
    return ['used' => $used, 'limit' => $limit, 'left' => max(0, $limit - $used)];
}

/**
 * 레거시 member_history_stats.bravo_grade → bravo_member_grades backfill (grandfather). 멱등.
 * - user_id 행 우선. phone 행은 그 phone 의 bootcamp_members 가 user_id 를 갖지 않을 때만 'p:'+phone 키로
 *   (이중 카운트 방지 — 표시 경로의 COALESCE(user행, phone행) 우선순위와 동일).
 * - 'Bravo N' 문자열 파싱. 기존 current_level 이 같거나 높으면 skip (안 내림 — GREATEST 의미).
 */
function bravoGradeBackfillFromLegacy(PDO $db): array {
    $parse = function (?string $g): int {
        return ($g !== null && preg_match('/(\d)/', $g, $m)) ? max(0, min(3, (int)$m[1])) : 0;
    };
    $byKey = [];
    foreach ($db->query("SELECT user_id, bravo_grade FROM member_history_stats WHERE user_id IS NOT NULL AND user_id != '' AND bravo_grade IS NOT NULL") as $r) {
        $lv = $parse($r['bravo_grade']);
        if ($lv >= 1) $byKey[(string)$r['user_id']] = max($byKey[(string)$r['user_id']] ?? 0, $lv);
    }
    $uidByPhone = $db->prepare("SELECT user_id FROM bootcamp_members WHERE phone = ? AND user_id IS NOT NULL AND user_id != '' LIMIT 1");
    foreach ($db->query("SELECT phone, bravo_grade FROM member_history_stats WHERE phone IS NOT NULL AND phone != '' AND bravo_grade IS NOT NULL") as $r) {
        $lv = $parse($r['bravo_grade']);
        if ($lv < 1) continue;
        $uidByPhone->execute([$r['phone']]);
        $uid = $uidByPhone->fetchColumn();
        $key = ($uid !== false && $uid !== '' && $uid !== null) ? (string)$uid : 'p:' . $r['phone'];
        $byKey[$key] = max($byKey[$key] ?? 0, $lv);
    }
    $applied = 0; $skipped = 0;
    foreach ($byKey as $key => $lv) {
        if (bravoGradeCurrentLevel($db, $key) >= $lv) { $skipped++; continue; }
        bravoGradeSet($db, $key, $lv, 'grandfather', null, 'legacy bravo_grade backfill');
        $applied++;
    }
    return ['applied' => $applied, 'skipped' => $skipped];
}
