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
    'study_create'   => '복습클래스 개설',
    'study_join'     => '복습클래스 참여',
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
