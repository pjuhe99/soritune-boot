<?php
/**
 * boot.soritune.com - Bootcamp API Router
 * 얇은 진입점: 인증/헬퍼 + 서비스 디스패치
 */

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/bootcamp_functions.php';
require_once __DIR__ . '/../includes/coin_functions.php';

// Services
require_once __DIR__ . '/services/member_create.php';
require_once __DIR__ . '/services/member.php';
require_once __DIR__ . '/services/member_stats.php';
require_once __DIR__ . '/services/check.php';
require_once __DIR__ . '/services/score.php';
require_once __DIR__ . '/services/revival.php';
require_once __DIR__ . '/services/coin.php';
require_once __DIR__ . '/services/coin_cycle.php';
require_once __DIR__ . '/services/coin_reward_group.php';
require_once __DIR__ . '/services/integration.php';
require_once __DIR__ . '/services/study.php';
require_once __DIR__ . '/services/lecture.php';
require_once __DIR__ . '/services/curriculum.php';
require_once __DIR__ . '/services/member_page.php';
require_once __DIR__ . '/services/issue_report.php';
require_once __DIR__ . '/services/dashboard.php';
require_once __DIR__ . '/services/group_assignment.php';
require_once __DIR__ . '/services/entrance.php';
require_once __DIR__ . '/services/attendance.php';

header('Content-Type: application/json; charset=utf-8');

$action = getAction();
$method = getMethod();

// ── Shared Helpers ────────────────────────────────────────────

/**
 * 리더의 조 스코핑: 리더이면 자기 bootcamp_group_id 반환, operation/coach이면 null(제한 없음)
 */
function getLeaderGroupScope($admin) {
    if (hasRole($admin, 'operation') || hasRole($admin, 'coach') || hasRole($admin, 'head') || hasRole($admin, 'subhead1') || hasRole($admin, 'subhead2')) return null;
    return $admin['bootcamp_group_id'] ?? null;
}

/**
 * 리더가 해당 member_id에 접근 가능한지 확인
 */
function verifyMemberAccess($db, $memberId, $groupId) {
    if (!$groupId) return true;
    $stmt = $db->prepare("SELECT id FROM bootcamp_members WHERE id = ? AND group_id = ?");
    $stmt->execute([$memberId, $groupId]);
    return (bool)$stmt->fetch();
}

/**
 * 외부 연동 API 키 인증
 */
function requireApiKey() {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!$key) jsonError('API key required', 401);

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM integration_api_keys WHERE api_key = ? AND is_active = 1");
    $stmt->execute([$key]);
    if (!$stmt->fetch()) jsonError('Invalid API key', 401);
}

/**
 * cafe_member_key → member_id 변환
 */
function resolveMemberByKey($db, $cafeKey) {
    if (!$cafeKey) return null;
    $stmt = $db->prepare("
        SELECT id FROM bootcamp_members
        WHERE cafe_member_key = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$cafeKey]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

/**
 * integration_logs 저장
 */
function logIntegration($db, $executionId, $results, $unmappedKeys, $errorDetails) {
    $db->prepare("
        INSERT INTO integration_logs
            (execution_id, total_received, total_success, total_skipped, total_error, total_unmapped, unmapped_keys, error_details)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $executionId,
        $results['success'] + $results['skipped'] + $results['error'] + $results['unmapped'],
        $results['success'],
        $results['skipped'],
        $results['error'],
        $results['unmapped'],
        !empty($unmappedKeys) ? json_encode($unmappedKeys, JSON_UNESCAPED_UNICODE) : null,
        !empty($errorDetails) ? json_encode($errorDetails, JSON_UNESCAPED_UNICODE) : null,
    ]);
}


// ══════════════════════════════════════════════════════════════
// API Routes
// ══════════════════════════════════════════════════════════════

switch ($action) {

// ── Cohorts & Groups (간단한 CRUD, 인라인 유지) ──────────────

case 'cohorts':
    requireAdmin();
    $db = getDB();
    $stmt = $db->query("SELECT * FROM cohorts ORDER BY start_date DESC");
    jsonSuccess(['cohorts' => $stmt->fetchAll()]);
    break;

case 'groups':
    requireAdmin();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    if (!$cohortId) jsonError('cohort_id 필요');
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM bootcamp_groups WHERE cohort_id = ? ORDER BY name");
    $stmt->execute([$cohortId]);
    jsonSuccess(['groups' => $stmt->fetchAll()]);
    break;

case 'group_create':
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'coach']);
    $input = getJsonInput();
    $cohortId = (int)($input['cohort_id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $code = trim($input['code'] ?? '');
    if (!$cohortId || !$name || !$code) jsonError('cohort_id, name, code 필요');
    $db = getDB();
    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, code) VALUES (?, ?, ?)")->execute([$cohortId, $name, $code]);
    jsonSuccess(['id' => (int)$db->lastInsertId()], '조가 추가되었습니다.');
    break;

case 'group_update':
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'coach']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');
    $fields = []; $params = [];
    foreach (['name', 'code', 'kakao_link'] as $f) {
        if (isset($input[$f])) { $fields[] = "$f = ?"; $params[] = trim($input[$f]); }
    }
    if (!$fields) jsonError('수정할 내용 없음');
    $params[] = $id;
    $db = getDB();
    $db->prepare("UPDATE bootcamp_groups SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    jsonSuccess([], '조 정보가 수정되었습니다.');
    break;

case 'group_delete':
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'coach']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');
    $db = getDB();
    $db->prepare("DELETE FROM bootcamp_groups WHERE id = ?")->execute([$id]);
    jsonSuccess([], '조가 삭제되었습니다.');
    break;

// ── Members ──────────────────────────────────────────────────

case 'members':          handleMembers(); break;
case 'member_create':    handleMemberCreate($method); break;
case 'member_update':    handleMemberUpdate($method); break;
case 'member_delete':    handleMemberDelete($method); break;
case 'member_restore':   handleMemberRestore($method); break;
case 'member_set_status': handleMemberSetStatus($method); break;

// ── Mission Types ────────────────────────────────────────────

case 'mission_types':
    requireAdmin();
    $db = getDB();
    $stmt = $db->query("SELECT * FROM mission_types WHERE is_active = 1 ORDER BY display_order");
    jsonSuccess(['mission_types' => $stmt->fetchAll()]);
    break;

// ── Checklist & Status Board ─────────────────────────────────

case 'checklist':               handleChecklist(); break;
case 'checklist_by_mission':    handleChecklistByMission(); break;
case 'member_checklist_all':    handleMemberChecklistAll(); break;
case 'check_save':
    $admin = requireAdmin(['operation', 'leader', 'subleader', 'coach', 'head', 'subhead1', 'subhead2']);
    handleCheckSave($method, $admin);
    break;
case 'check_bulk_save':
    $admin = requireAdmin(['operation', 'leader', 'subleader', 'coach', 'head', 'subhead1', 'subhead2']);
    handleCheckBulkSave($method, $admin);
    break;
case 'status_board':     handleStatusBoard(); break;

// ── Warning Notes ────────────────────────────────────────────

case 'warning_notes':    handleWarningNotes(); break;
case 'warning_note_create':
    $admin = requireAdmin(['operation', 'leader', 'subleader', 'coach', 'head', 'subhead1', 'subhead2']);
    handleWarningNoteCreate($method, $admin);
    break;

// ── Revival ──────────────────────────────────────────────────

case 'revival_candidates': handleRevivalCandidates(); break;
case 'revival_manual':     handleManualRevival(); break;
case 'revival_logs':       handleRevivalLogs(); break;

// ── Coins ────────────────────────────────────────────────────

case 'coin_balance':     handleCoinBalance(); break;
case 'coin_change':      handleCoinChange($method); break;
case 'coin_logs':        handleCoinLogs(); break;

// ── Coin Cycles ─────────────────────────────────────────────

case 'coin_cycles':              handleCoinCycles(); break;
case 'coin_cycle_create':        handleCoinCycleCreate($method); break;
case 'coin_cycle_update':        handleCoinCycleUpdate($method); break;
case 'coin_cycle_close':         handleCoinCycleClose($method); break;
case 'coin_cycle_members':       handleCoinCycleMembers(); break;
case 'coin_leader_grant':        handleCoinLeaderGrant($method); break;
case 'coin_settlement_preview':  handleCoinSettlementPreview(); break;
case 'coin_settlement_execute':  handleCoinSettlementExecute($method); break;
case 'coin_cheer_award':         handleCoinCheerAward($method); break;
case 'coin_cheer_status':        handleCoinCheerStatus(); break;
case 'coin_cheer_groups':        handleCoinCheerGroups(); break;

// ── Coin Reward Groups ──────────────────────────────────────
case 'coin_reward_groups':                    handleCoinRewardGroups(); break;
case 'coin_reward_group_create':              handleCoinRewardGroupCreate($method); break;
case 'coin_reward_group_update':              handleCoinRewardGroupUpdate($method); break;
case 'coin_reward_group_delete':              handleCoinRewardGroupDelete($method); break;
case 'coin_reward_group_attach_cycle':        handleCoinRewardGroupAttach($method); break;
case 'coin_reward_group_detach_cycle':        handleCoinRewardGroupDetach($method); break;
case 'coin_reward_group_preview':             handleCoinRewardGroupPreview(); break;
case 'coin_reward_group_distribute':          handleCoinRewardGroupDistribute($method); break;
case 'coin_reward_group_distribution_detail': handleCoinRewardGroupDistributionDetail(); break;

// ── Scores ───────────────────────────────────────────────────

case 'score_logs':       handleScoreLogs(); break;
case 'score_adjust':     handleScoreAdjust($method); break;
case 'score_recalculate': handleScoreRecalculate($method); break;

// ── Integration (n8n) ────────────────────────────────────────

case 'integration_check':      handleIntegrationCheck($method); break;
case 'integration_check_bulk': handleIntegrationCheckBulk($method); break;
case 'integration_member_map': handleIntegrationMemberMap(); break;
case 'integration_logs':       handleIntegrationLogs(); break;
case 'integration_cafe_posts': handleIntegrationCafePosts($method); break;
case 'cafe_posts':             handleCafePosts(); break;
case 'cafe_remap_unmapped':    handleCafeRemapUnmapped($method); break;

// ── Study (복습스터디) ──────────────────────────────────────

case 'study_groups':            handleStudyGroups(); break;
case 'study_members':           handleStudyMembers(); break;
case 'study_sessions':          handleStudySessions(); break;
case 'study_session_detail':    handleStudySessionDetail(); break;
case 'study_session_create':    handleStudySessionCreate($method); break;
case 'study_session_cancel':    handleStudySessionCancel($method); break;
case 'study_session_qr':        handleStudySessionQr($method); break;
case 'study_session_retry_zoom': handleStudySessionRetryZoom($method); break;
case 'study_zoom_failed':        handleStudyZoomFailed(); break;

// ── Admin Study (어드민 복습스터디 관리) ────────────────────

case 'admin_study_groups':      handleAdminStudyGroups(); break;
case 'admin_study_members':     handleAdminStudyMembers(); break;
case 'admin_study_sessions':    handleAdminStudySessions(); break;
case 'admin_study_detail':      handleAdminStudyDetail(); break;
case 'admin_study_create':
    $admin = requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    handleAdminStudyCreate($method, $admin);
    break;
case 'admin_study_cancel':
    $admin = requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    handleAdminStudyCancel($method, $admin);
    break;

// ── Lecture (코치 강의) ────────────────────────────────────

case 'lecture_coaches':           handleLectureCoaches(); break;
case 'lecture_sessions':          handleLectureSessions(); break;
case 'lecture_session_detail':    handleLectureSessionDetail(); break;
case 'lecture_schedule_create':   handleLectureScheduleCreate($method); break;
case 'lecture_schedule_cancel':   handleLectureScheduleCancel($method); break;
case 'lecture_zoom_retry':        handleLectureZoomRetry($method); break;

// ── Lecture Events (1회성 이벤트) ────────────────────────

case 'lecture_event_create':      handleLectureEventCreate($method); break;
case 'lecture_event_cancel':      handleLectureEventCancel($method); break;
case 'lecture_event_detail':      handleLectureEventDetail(); break;
case 'lecture_event_zoom_retry':  handleLectureEventZoomRetry($method); break;
case 'lecture_events':            handleLectureEvents(); break;

// ── Curriculum (진도) ──────────────────────────────────────────
case 'curriculum_today':    handleCurriculumToday(); break;

// ── System Contents (시스템 콘텐츠) ────────────────────────────
case 'system_content':
    requireMember();
    $key = trim($_GET['key'] ?? '');
    if (!$key) jsonError('key 필요');
    $db = getDB();
    $stmt = $db->prepare("SELECT content_markdown FROM system_contents WHERE content_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    jsonSuccess(['content' => $row ? $row['content_markdown'] : null]);
    break;

// ── Member Page (사용자 페이지 전용) ──────────────────────────
case 'member_checks':            handleMemberChecks(); break;
case 'member_checks_summary':    handleMemberChecksSummary(); break;
case 'member_curriculum':        handleMemberCurriculum(); break;
case 'member_curriculum_detail': handleMemberCurriculumDetail(); break;
case 'member_bootees':           handleMemberBootees(); break;
case 'member_event_log':         handleMemberEventLog($method); break;

// ── Dashboard ────────────────────────────────────────────────
case 'dashboard_stats':  handleDashboardStats(); break;

// ── Issue Reports (오류 문의) ──────────────────────────────
case 'issue_create':        handleIssueCreate($method); break;
case 'issue_list':          handleIssueList(); break;
case 'issue_detail':        handleIssueDetail(); break;
case 'issue_admin_list':    handleIssueAdminList(); break;
case 'issue_status_update': handleIssueStatusUpdate($method); break;
case 'issue_admin_note':    handleIssueAdminNote($method); break;

// ── Entrance (입장 체크) ─────────────────────────────────────

case 'entrance_list':    handleEntranceList(); break;
case 'entrance_save':    handleEntranceSave($method); break;

// ── Group Assignment (조 배정) ──────────────────────────────

case 'leader_candidates':    handleLeaderCandidates(); break;
case 'leader_assign':        handleLeaderAssign($method); break;
case 'leader_unassign':      handleLeaderUnassign($method); break;
case 'subleader_assign':     handleSubleaderAssign($method); break;
case 'subleader_unassign':   handleSubleaderUnassign($method); break;
case 'groups_with_stats':    handleGroupsWithStats(); break;
case 'group_create_ext':     handleGroupCreateExtended($method); break;
case 'group_update_ext':     handleGroupUpdateExtended($method); break;
case 'assignment_preview':   handleAssignmentPreview(); break;
case 'assignment_confirm':   handleAssignmentConfirm($method); break;
case 'assignment_reset':     handleAssignmentReset($method); break;
case 'member_move':          handleMemberMove($method); break;
case 'group_members':        handleGroupMembers(); break;
case 'assignment_summary':   handleAssignmentSummary(); break;

// ── Attendance ──
case 'attendance_stats':     handleAttendanceStats(); break;

// ──────────────────────────────────────────────────────────────

default:
    jsonError('Unknown action', 404);
}
