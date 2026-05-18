<?php
/**
 * findMatchingLectureSession() 단위 테스트.
 * DEV DB transaction rollback 으로 격리.
 * 사용: php tests/qr_lecture_match_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/qr_match.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();
$db->beginTransaction();

try {
    // ── 공통 fixture: 테스트용 cohort 1개 ──────────────────────
    $cohortLabel = 'QRM_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    // 공통 헬퍼: admin 만들기 (login_id=UNIQUE NOT NULL, role enum 에 'member' 없음)
    $insAdmin = $db->prepare("INSERT INTO admins (login_id, password_hash, name, role, is_active)
                              VALUES (?, '\$2y\$10\$dummy', ?, ?, 1)");

    // 공통 헬퍼: lecture_schedule (schedule_id 를 lecture_sessions 에 넣어야 함)
    $insSchedule = $db->prepare("INSERT INTO lecture_schedules
        (cohort_id, coach_admin_id, stage, weekdays, start_time, host_account, created_by)
        VALUES (?, ?, ?, 'mon', ?, 'coach1', ?)");

    // 공통 헬퍼: lecture_session (schedule_id + host_account NOT NULL)
    $insLecture = $db->prepare("INSERT INTO lecture_sessions
        (schedule_id, cohort_id, coach_admin_id, lecture_date, start_time, end_time, stage, host_account, title, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'coach1', ?, ?)");

    // ───────────────────────────────────────────────────────────
    // 시나리오 1: Tier A 정확 매칭
    //   admin Kel(role=coach), 같은 admin_id로 등록된 오늘 강의 1건
    // ───────────────────────────────────────────────────────────
    $insAdmin->execute(['kel_t1_' . bin2hex(random_bytes(2)), 'Kel_T1', 'coach']);
    $kelId = (int)$db->lastInsertId();
    $insSchedule->execute([$cohortId, $kelId, 1, '06:00:00', $kelId]);
    $scheduleId1 = (int)$db->lastInsertId();
    $insLecture->execute([$scheduleId1, $cohortId, $kelId, date('Y-m-d'), '06:00:00', '07:00:00', 1, '[06:00] Kel 1단계', 'active']);
    $lectureId1 = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, $kelId, $cohortId);
    t('T1: Tier A 동일 admin_id 매칭', $matched === $lectureId1, "expected={$lectureId1}, got=" . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 T1b: atDate/atTime half-null 가드
    // ───────────────────────────────────────────────────────────
    $caught = false;
    try {
        findMatchingLectureSession($db, $kelId, $cohortId, '2026-05-12', null);
    } catch (InvalidArgumentException $e) {
        $caught = true;
    }
    t('T1b: atDate/atTime half-null throws', $caught);

    // ───────────────────────────────────────────────────────────
    // 시나리오 2: Tier B 동일 이름 매칭
    //   Darren 옛 admin (role=sub_coach) 발급 QR
    //   → 새 Darren (role=sub_coach) 으로 등록된 강의에 매칭
    // ───────────────────────────────────────────────────────────
    $insAdmin->execute(['darren_old_' . bin2hex(random_bytes(2)), 'Darren_T2', 'sub_coach']);
    $darrenOldId = (int)$db->lastInsertId();
    $insAdmin->execute(['darren_new_' . bin2hex(random_bytes(2)), 'Darren_T2', 'sub_coach']);
    $darrenNewId = (int)$db->lastInsertId();
    $insSchedule->execute([$cohortId, $darrenNewId, 2, '06:00:00', $darrenNewId]);
    $scheduleId2 = (int)$db->lastInsertId();
    $insLecture->execute([$scheduleId2, $cohortId, $darrenNewId, date('Y-m-d'), '06:00:00', '07:00:00', 2, '[06:00] Darren 2단계', 'active']);
    $lectureId2 = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, $darrenOldId, $cohortId);
    t('T2: Tier B 동일 이름 admin 매칭', $matched === $lectureId2, "expected={$lectureId2}, got=" . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 3: Tier C 단일 후보 매칭
    //   Ella(role=coach) admin, 그날 Ella 강의 없음, ±60분 내 강의 정확히 1건
    // ───────────────────────────────────────────────────────────
    $insAdmin->execute(['ella_t3_' . bin2hex(random_bytes(2)), 'Ella_T3', 'coach']);
    $ellaId = (int)$db->lastInsertId();
    // 같은 cohort, Ella 가 아닌 다른 코치 강의 1건 — 시각은 현재 시각 기준 ±60분 안
    $insAdmin->execute(['kel_t3_' . bin2hex(random_bytes(2)), 'Kel_T3', 'coach']);
    $kelT3 = (int)$db->lastInsertId();
    $nowTime = date('H:i:s');
    $insSchedule->execute([$cohortId, $kelT3, 1, $nowTime, $kelT3]);
    $scheduleId3 = (int)$db->lastInsertId();
    $insLecture->execute([$scheduleId3, $cohortId, $kelT3, date('Y-m-d'), $nowTime, $nowTime, 1, '[T3] Kel', 'active']);
    $lectureId3 = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, $ellaId, $cohortId);
    t('T3: Tier C 단일 후보 매칭', $matched === $lectureId3, "expected={$lectureId3}, got=" . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 4: Tier C 후보 2건이면 매칭 X (보수성)
    //   Ella 발급, ±60분 내 강의 2건 → NULL
    // ───────────────────────────────────────────────────────────
    $insAdmin->execute(['lulu_t4_' . bin2hex(random_bytes(2)), 'Lulu_T4', 'coach']);
    $luluT4 = (int)$db->lastInsertId();
    $insSchedule->execute([$cohortId, $luluT4, 2, $nowTime, $luluT4]);
    $scheduleId4 = (int)$db->lastInsertId();
    $insLecture->execute([$scheduleId4, $cohortId, $luluT4, date('Y-m-d'), $nowTime, $nowTime, 2, '[T4] Lulu', 'active']);

    $matched = findMatchingLectureSession($db, $ellaId, $cohortId);
    t('T4: Tier C 후보 2건이면 NULL (보수성)', $matched === null, 'got=' . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 5: Tier C 후보 0건이면 NULL
    //   별도 cohort (강의 없음) 에서 Ella 발급
    // ───────────────────────────────────────────────────────────
    $cohortLabel2 = 'QRM2_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel2, $cohortLabel2]);
    $cohortId2 = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, $ellaId, $cohortId2);
    t('T5: Tier C 후보 0건이면 NULL', $matched === null, 'got=' . var_export($matched, true));

} finally {
    $db->rollBack();
}

echo "\n── 결과: PASS {$pass}, FAIL {$fail} ──\n";
exit($fail > 0 ? 1 : 0);
