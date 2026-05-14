<?php
/**
 * Issue Report Service
 * 오류 문의 등록/조회/상태변경 — DB 처리 전용
 * UI 렌더링 로직 없음
 */

// ── 문의 유형 상수 ──────────────────────────────────────────
const ISSUE_TYPES = [
    'naemat33'       => '내맛33미션',
    'zoom'           => '줌 특강',
    'daily'          => '데일리 미션',
    'malkka'         => '말까미션',
    'study_create'   => '복습스터디 개설',
    'study_join'     => '복습스터디 참여',
];

// ── 상태 상수 ────────────────────────────────────────────────
const ISSUE_STATUSES = [
    'pending'     => '접수됨',
    'in_progress' => '확인 중',
    'resolved'    => '처리 완료',
    'rejected'    => '반려',
];

const ISSUE_DESC_MAX_LENGTH = 1000;

// ══════════════════════════════════════════════════════════════
// 사용자용: 오류 문의 등록
// ══════════════════════════════════════════════════════════════

function handleIssueCreate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $member = requireMember();
    $input = getJsonInput();

    // ── Validation ──
    $issueType = trim($input['issue_type'] ?? '');
    if (!$issueType || !isset(ISSUE_TYPES[$issueType])) {
        jsonError('문의 유형을 선택해주세요.');
    }

    $description = trim($input['description'] ?? '');
    if (mb_strlen($description) > ISSUE_DESC_MAX_LENGTH) {
        jsonError('추가 설명은 ' . ISSUE_DESC_MAX_LENGTH . '자 이내로 입력해주세요.');
    }

    $db = getDB();
    $memberId = $member['member_id'];

    // ── 회원 snapshot 정보 조회 ──
    $snap = $db->prepare("
        SELECT bm.cohort_id, bm.group_id, bm.nickname, bg.name AS group_name
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        WHERE bm.id = ?
    ");
    $snap->execute([$memberId]);
    $info = $snap->fetch(PDO::FETCH_ASSOC);
    if (!$info) jsonError('회원 정보를 찾을 수 없습니다.');

    // ── 저장 ──
    $stmt = $db->prepare("
        INSERT INTO issue_reports
            (member_id, cohort_id, group_id, nickname, group_name, issue_type, description, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->execute([
        $memberId,
        (int)$info['cohort_id'],
        $info['group_id'] ? (int)$info['group_id'] : null,
        $info['nickname'],
        $info['group_name'],
        $issueType,
        $description ?: null,
    ]);
    $issueId = (int)$db->lastInsertId();

    // ── 상태 변경 로그 ──
    logIssueStatusChange($db, $issueId, null, 'pending', 'system', null, '사용자 등록');

    // ── 이벤트 로그 (사용자 행동 추적) ──
    logMemberEvent($memberId, 'submit_issue_report', (string)$issueId, [
        'issue_type' => $issueType,
    ]);

    jsonSuccess(['id' => $issueId], '오류 문의가 등록되었습니다.');
}

// ══════════════════════════════════════════════════════════════
// 사용자용: 내 문의 목록
// ══════════════════════════════════════════════════════════════

function handleIssueList() {
    $member = requireMember();
    $db = getDB();

    $stmt = $db->prepare("
        SELECT id, issue_type, description, status, admin_note, created_at, resolved_at
        FROM issue_reports
        WHERE member_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$member['member_id']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 라벨 매핑
    foreach ($rows as &$r) {
        $r['issue_type_label'] = ISSUE_TYPES[$r['issue_type']] ?? $r['issue_type'];
        $r['status_label'] = ISSUE_STATUSES[$r['status']] ?? $r['status'];
    }
    unset($r);

    jsonSuccess(['issues' => $rows]);
}

// ══════════════════════════════════════════════════════════════
// 공통: 단건 상세
// ══════════════════════════════════════════════════════════════

function handleIssueDetail() {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM issue_reports WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonError('문의를 찾을 수 없습니다.', 404);

    $row['issue_type_label'] = ISSUE_TYPES[$row['issue_type']] ?? $row['issue_type'];
    $row['status_label'] = ISSUE_STATUSES[$row['status']] ?? $row['status'];

    jsonSuccess(['issue' => $row]);
}

// ══════════════════════════════════════════════════════════════
// 운영용: 전체 문의 목록 (필터)
// ══════════════════════════════════════════════════════════════

function handleIssueAdminList() {
    requireAdmin(['operation']);
    $db = getDB();

    $where = ['1=1'];
    $params = [];

    if (!empty($_GET['cohort_id'])) {
        $where[] = 'ir.cohort_id = ?';
        $params[] = (int)$_GET['cohort_id'];
    }
    if (!empty($_GET['status'])) {
        $where[] = 'ir.status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['issue_type'])) {
        $where[] = 'ir.issue_type = ?';
        $params[] = $_GET['issue_type'];
    }

    $stmt = $db->prepare("
        SELECT ir.*, bm.real_name AS member_name
        FROM issue_reports ir
        LEFT JOIN bootcamp_members bm ON ir.member_id = bm.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ir.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['issue_type_label'] = ISSUE_TYPES[$r['issue_type']] ?? $r['issue_type'];
        $r['status_label'] = ISSUE_STATUSES[$r['status']] ?? $r['status'];
    }
    unset($r);

    jsonSuccess([
        'issues' => $rows,
        'issue_types' => ISSUE_TYPES,
        'statuses' => ISSUE_STATUSES,
    ]);
}

// ══════════════════════════════════════════════════════════════
// 운영용: 상태 변경
// ══════════════════════════════════════════════════════════════

function handleIssueStatusUpdate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();

    $id = (int)($input['id'] ?? 0);
    $newStatus = trim($input['status'] ?? '');
    if (!$id) jsonError('id 필요');
    if (!isset(ISSUE_STATUSES[$newStatus])) jsonError('유효하지 않은 상태값');

    $db = getDB();
    $current = $db->prepare("SELECT status FROM issue_reports WHERE id = ?");
    $current->execute([$id]);
    $row = $current->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonError('문의를 찾을 수 없습니다.', 404);

    $oldStatus = $row['status'];
    if ($oldStatus === $newStatus) jsonError('이미 같은 상태입니다.');

    $resolvedAt = in_array($newStatus, ['resolved', 'rejected']) ? date('Y-m-d H:i:s') : null;
    $resolvedBy = in_array($newStatus, ['resolved', 'rejected']) ? $admin['admin_id'] : null;

    $db->prepare("
        UPDATE issue_reports
        SET status = ?, resolved_by = ?, resolved_at = ?
        WHERE id = ?
    ")->execute([$newStatus, $resolvedBy, $resolvedAt, $id]);

    $note = trim($input['note'] ?? '') ?: null;
    logIssueStatusChange($db, $id, $oldStatus, $newStatus, 'admin', $admin['admin_id'], $note);

    jsonSuccess([], ISSUE_STATUSES[$newStatus] . ' 처리되었습니다.');
}

// ══════════════════════════════════════════════════════════════
// 운영용: 메모 저장
// ══════════════════════════════════════════════════════════════

function handleIssueAdminNote($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();

    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $note = trim($input['admin_note'] ?? '');
    if (mb_strlen($note) > ISSUE_DESC_MAX_LENGTH) {
        jsonError('메모는 ' . ISSUE_DESC_MAX_LENGTH . '자 이내로 입력해주세요.');
    }

    $db = getDB();
    $db->prepare("UPDATE issue_reports SET admin_note = ? WHERE id = ?")->execute([$note ?: null, $id]);
    jsonSuccess([], '메모가 저장되었습니다.');
}

// ══════════════════════════════════════════════════════════════
// 내부: 상태 변경 로그 저장 (공통)
// ══════════════════════════════════════════════════════════════

function logIssueStatusChange($db, $issueId, $oldStatus, $newStatus, $changedByType, $changedById, $note = null) {
    try {
        $db->prepare("
            INSERT INTO issue_report_logs (issue_id, old_status, new_status, changed_by_type, changed_by_id, note)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$issueId, $oldStatus, $newStatus, $changedByType, $changedById, $note]);
    } catch (\Throwable $e) {
        error_log("logIssueStatusChange failed: " . $e->getMessage());
    }
}

// ══════════════════════════════════════════════════════════════
// Auto resolve: 미션 체크 상태 검사 (read-only)
// ══════════════════════════════════════════════════════════════

const ISSUE_MISSION_MAP = [
    'naemat33' => 'inner33',
    'malkka'   => 'speak_mission',
    'zoom'     => 'zoom_daily',
    'daily'    => 'daily_mission',
];
const ISSUE_INSPECT_RANGE_DAYS = 7;

/**
 * 한 issue 의 미션 체크 상태를 검사한다 (read-only).
 *
 * 반환:
 *   mission_status    'all_checked' | 'has_unchecked' | 'no_data' | 'unsupported'
 *   unchecked_dates   ['YYYY-MM-DD', ...]
 *   checked_dates     ['YYYY-MM-DD', ...]
 *   inspected_range   ['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD']  ※ unsupported 시 null
 */
function inspectIssueMission(PDO $db, int $issueId): array {
    $unsupported = [
        'mission_status' => 'unsupported',
        'unchecked_dates' => [],
        'checked_dates' => [],
        'inspected_range' => null,
    ];

    $stmt = $db->prepare("SELECT member_id, cohort_id, issue_type, created_at FROM issue_reports WHERE id = ?");
    $stmt->execute([$issueId]);
    $issue = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$issue) return $unsupported;

    if (!isset(ISSUE_MISSION_MAP[$issue['issue_type']])) return $unsupported;

    $missionCode = ISSUE_MISSION_MAP[$issue['issue_type']];

    // 검사 범위 (KST). DB time_zone = +09:00 이므로 created_at, NOW() 모두 KST.
    $createdDate = substr($issue['created_at'], 0, 10);
    $rangeFrom = date('Y-m-d', strtotime($createdDate . ' -' . ISSUE_INSPECT_RANGE_DAYS . ' days'));

    // cohort.start_date 하한 적용
    $cs = $db->prepare("SELECT start_date FROM cohorts WHERE id = ?");
    $cs->execute([(int)$issue['cohort_id']]);
    $cohortStart = $cs->fetchColumn();
    if ($cohortStart && $cohortStart > $rangeFrom) {
        $rangeFrom = $cohortStart;
    }
    $rangeTo = $createdDate;

    $checkStmt = $db->prepare("
        SELECT mmc.check_date, mmc.status
        FROM member_mission_checks mmc
        JOIN mission_types mt ON mmc.mission_type_id = mt.id
        WHERE mmc.member_id = ?
          AND mmc.cohort_id = ?
          AND mt.code = ?
          AND mmc.check_date BETWEEN ? AND ?
        ORDER BY mmc.check_date ASC
    ");
    $checkStmt->execute([
        (int)$issue['member_id'],
        (int)$issue['cohort_id'],
        $missionCode,
        $rangeFrom,
        $rangeTo,
    ]);
    $rows = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

    $checked = [];
    $unchecked = [];
    foreach ($rows as $r) {
        if ((int)$r['status'] === 1) $checked[] = $r['check_date'];
        else $unchecked[] = $r['check_date'];
    }

    if (empty($rows)) {
        $status = 'no_data';
    } elseif (empty($unchecked)) {
        $status = 'all_checked';
    } else {
        $status = 'has_unchecked';
    }

    return [
        'mission_status' => $status,
        'unchecked_dates' => $unchecked,
        'checked_dates' => $checked,
        'inspected_range' => ['from' => $rangeFrom, 'to' => $rangeTo],
    ];
}

/**
 * 한 issue 를 자동 해결 처리한다 (write).
 *
 * 조건:
 *  - issue_reports.status='pending'
 *  - inspectIssueMission()의 mission_status === 'all_checked'
 *
 * 반환:
 *  - ok=true 시  ['ok'=>true, 'inspection'=>array]
 *  - ok=false 시 ['ok'=>false, 'reason'=>'not_pending'|'not_eligible'|'not_found', 'inspection'=>array|null]
 */
function attemptAutoResolveIssue(PDO $db, int $issueId, int $adminId): array {
    $row = $db->prepare("SELECT id, status FROM issue_reports WHERE id = ? FOR UPDATE");
    $inTx = $db->inTransaction();
    if (!$inTx) $db->beginTransaction();
    try {
        $row->execute([$issueId]);
        $issue = $row->fetch(PDO::FETCH_ASSOC);
        if (!$issue) {
            if (!$inTx) $db->rollBack();
            return ['ok' => false, 'reason' => 'not_found', 'inspection' => null];
        }
        if ($issue['status'] !== 'pending') {
            if (!$inTx) $db->rollBack();
            return ['ok' => false, 'reason' => 'not_pending', 'inspection' => null];
        }

        $inspection = inspectIssueMission($db, $issueId);
        if ($inspection['mission_status'] !== 'all_checked') {
            if (!$inTx) $db->rollBack();
            return ['ok' => false, 'reason' => 'not_eligible', 'inspection' => $inspection];
        }

        $rangeLabel = $inspection['inspected_range']['from'] . '~' . $inspection['inspected_range']['to'];
        $autoNote = 'auto: 폴링 후 모두 체크 완료 확인 (' . $rangeLabel . ')';

        $db->prepare("
            UPDATE issue_reports
            SET status = 'resolved',
                admin_note = CASE
                    WHEN admin_note IS NULL OR admin_note = '' THEN ?
                    ELSE CONCAT(admin_note, ' / ', ?)
                END,
                resolved_by = ?,
                resolved_at = NOW()
            WHERE id = ? AND status = 'pending'
        ")->execute([$autoNote, $autoNote, $adminId, $issueId]);

        logIssueStatusChange($db, $issueId, 'pending', 'resolved', 'admin', $adminId, $autoNote);

        if (!$inTx) $db->commit();
        return ['ok' => true, 'inspection' => $inspection];
    } catch (\Throwable $e) {
        if (!$inTx && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// ══════════════════════════════════════════════════════════════
// 운영용: 단건 자동 해결
// ══════════════════════════════════════════════════════════════

function handleIssueAdminResolveAuto($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $db = getDB();
    $r = attemptAutoResolveIssue($db, $id, (int)$admin['admin_id']);
    if (!$r['ok']) {
        $msg = match ($r['reason']) {
            'not_found'    => '문의를 찾을 수 없습니다.',
            'not_pending'  => '이미 처리된 문의입니다.',
            'not_eligible' => '자동 해결 조건을 충족하지 않습니다 (미체크 일자 존재).',
            default        => '자동 해결 실패',
        };
        jsonError($msg, 409);
    }
    jsonSuccess(['inspection' => $r['inspection']], '자동 해결되었습니다.');
}
