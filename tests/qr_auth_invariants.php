<?php
/**
 * QR 본인 확인 인보리언트 테스트
 * 사용: php tests/qr_auth_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/qr_actions.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; }
    else { $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n"; }
}

$db = getDB();

// ── Setup: 가짜 attendance QR session + 테스트 회원 (12기 활성 그룹) ──
// PROD 데이터 영향 없도록 transaction 사용
$db->beginTransaction();

try {
    // 12기 활성 회원 2명 가져오기 (group_id 무관 — DEV DB 에서 groups 미편성일 수 있음)
    $cohort = $db->query("SELECT id FROM cohorts WHERE cohort = '12기' LIMIT 1")->fetch();
    $cohortId = (int)$cohort['id'];

    $members = $db->prepare("
        SELECT id FROM bootcamp_members
        WHERE cohort_id = ? AND is_active = 1 AND member_status != 'refunded'
        LIMIT 2
    ");
    $members->execute([$cohortId]);
    $rows = $members->fetchAll();
    if (count($rows) < 2) {
        echo "SKIP  insufficient test data (need ≥2 active members in 12기)\n";
        $db->rollBack();
        exit(0);
    }
    $memberA = (int)$rows[0]['id'];
    $memberB = (int)$rows[1]['id'];

    // 테스트용 admin_id: 첫 번째 admins 레코드 사용 (NOT NULL FK 충족)
    $testAdminId = (int)$db->query("SELECT id FROM admins ORDER BY id LIMIT 1")->fetchColumn();

    // 테스트용 attendance QR session
    $db->prepare("
        INSERT INTO qr_sessions (session_code, session_type, admin_id, cohort_id, status, expires_at, created_at)
        VALUES ('test_att_xxxxx', 'attendance', ?, ?, 'active', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
    ")->execute([$testAdminId, $cohortId]);
    $attSessionId = (int)$db->lastInsertId();
    $attSession = $db->query("SELECT * FROM qr_sessions WHERE id = $attSessionId")->fetch();

    // ── 1. 본인 출석 ──
    $r = qrRecordAttendance($db, $attSession, $memberA, $memberA, '127.0.0.1', 'test-ua');
    t('record: 본인 첫 출석', $r['ok'] === true && $r['already'] === false);

    $row = $db->query("SELECT actor_member_id FROM qr_attendance WHERE qr_session_id = $attSessionId AND member_id = $memberA")->fetch();
    t('record: actor_member_id == member_id 기록', (int)$row['actor_member_id'] === $memberA);

    // ── 2. 동일 회원 중복 호출 (race 시뮬) ──
    $r2 = qrRecordAttendance($db, $attSession, $memberA, $memberA, '127.0.0.1', 'test-ua');
    t('record: 중복 호출 already=true', $r2['ok'] === true && $r2['already'] === true);

    // saveCheck 부수 효과는 1회만 적용됐는지 — 첫 호출에서만 record 가 만들어졌어야 함
    $cnt = $db->prepare("SELECT COUNT(*) FROM qr_attendance WHERE qr_session_id = ? AND member_id = ?");
    $cnt->execute([$attSessionId, $memberA]);
    t('record: qr_attendance 중복 행 없음', (int)$cnt->fetchColumn() === 1);

    // ── 3. 대리 출석 (memberA 가 memberB 의 출석을 찍음) ──
    $r3 = qrRecordAttendance($db, $attSession, $memberB, $memberA, '127.0.0.1', 'test-ua');
    t('record: 대리 출석 정상', $r3['ok'] === true && $r3['already'] === false);

    $proxyRow = $db->query("SELECT actor_member_id FROM qr_attendance WHERE qr_session_id = $attSessionId AND member_id = $memberB")->fetch();
    t('record: 대리 시 actor_member_id != member_id', (int)$proxyRow['actor_member_id'] === $memberA);

    // ── 4. revival 세션 셋업 ──
    $db->prepare("
        INSERT INTO qr_sessions (session_code, session_type, admin_id, cohort_id, status, expires_at, created_at)
        VALUES ('test_rev_xxxxx', 'revival', ?, ?, 'active', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
    ")->execute([$testAdminId, $cohortId]);
    $revSessionId = (int)$db->lastInsertId();
    $revSession = $db->query("SELECT * FROM qr_sessions WHERE id = $revSessionId")->fetch();

    // 대상 회원 점수를 -10 으로 강제 (테스트용)
    $db->prepare("INSERT INTO member_scores (member_id, current_score, last_calculated_at) VALUES (?, -10, NOW()) ON DUPLICATE KEY UPDATE current_score = -10")->execute([$memberA]);

    $r4 = qrRecordRevival($db, $revSession, $memberA, $memberA, '127.0.0.1', 'test-ua');
    t('revival: 적격 회원 첫 부활', $r4['ok'] === true && empty($r4['already']) && empty($r4['not_eligible']) && $r4['after_score'] === -3);

    $r5 = qrRecordRevival($db, $revSession, $memberA, $memberA, '127.0.0.1', 'test-ua');
    t('revival: 같은 세션 중복 호출 already=true', $r5['ok'] === true && $r5['already'] === true);

    // revival_logs 가 1건만 있어야 함
    $rcnt = $db->prepare("SELECT COUNT(*) FROM revival_logs WHERE qr_session_id = ? AND member_id = ?");
    $rcnt->execute([$revSessionId, $memberA]);
    t('revival: revival_logs 중복 없음', (int)$rcnt->fetchColumn() === 1);

    // ── 5. 부적격 회원 — 점수 회복 후 재진입 차단 ──
    // memberB 점수 +5 로 강제 (부적격)
    $db->prepare("INSERT INTO member_scores (member_id, current_score, last_calculated_at) VALUES (?, 5, NOW()) ON DUPLICATE KEY UPDATE current_score = 5")->execute([$memberB]);

    $r6 = qrRecordRevival($db, $revSession, $memberB, $memberB, '127.0.0.1', 'test-ua');
    t('revival: 부적격 not_eligible=true', $r6['ok'] === true && !empty($r6['not_eligible']));

    // 가드 row 는 만들어졌는지
    $guardCnt = $db->prepare("SELECT COUNT(*) FROM qr_attendance WHERE qr_session_id = ? AND member_id = ?");
    $guardCnt->execute([$revSessionId, $memberB]);
    t('revival: 부적격이어도 가드 row 생성', (int)$guardCnt->fetchColumn() === 1);

    // 점수 조작 시뮬: memberB 점수를 -10 으로 내리고 재호출 → 같은 세션 차단되는지
    $db->prepare("UPDATE member_scores SET current_score = -10 WHERE member_id = ?")->execute([$memberB]);
    $r7 = qrRecordRevival($db, $revSession, $memberB, $memberB, '127.0.0.1', 'test-ua');
    t('revival: 부적격 후 점수 조작 재진입 차단', $r7['ok'] === true && $r7['already'] === true);

    // revival_logs 는 memberB 에 대해 0건이어야 함
    $rcntB = $db->prepare("SELECT COUNT(*) FROM revival_logs WHERE qr_session_id = ? AND member_id = ?");
    $rcntB->execute([$revSessionId, $memberB]);
    t('revival: 부적격 회원은 revival_logs 미생성', (int)$rcntB->fetchColumn() === 0);

} finally {
    // 모든 테스트 데이터 롤백
    $db->rollBack();
}

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
