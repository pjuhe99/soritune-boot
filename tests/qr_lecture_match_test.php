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
    //   → 새 Darren (role=sub_coach) 으로 등록된 강의에 매칭.
    //   명시적 atTime '06:30:00' 사용 (강의 06:00 기준 30분 내 → 가드 통과).
    // ───────────────────────────────────────────────────────────
    $insAdmin->execute(['darren_old_' . bin2hex(random_bytes(2)), 'Darren_T2', 'sub_coach']);
    $darrenOldId = (int)$db->lastInsertId();
    $insAdmin->execute(['darren_new_' . bin2hex(random_bytes(2)), 'Darren_T2', 'sub_coach']);
    $darrenNewId = (int)$db->lastInsertId();
    $insSchedule->execute([$cohortId, $darrenNewId, 2, '06:00:00', $darrenNewId]);
    $scheduleId2 = (int)$db->lastInsertId();
    $insLecture->execute([$scheduleId2, $cohortId, $darrenNewId, date('Y-m-d'), '06:00:00', '07:00:00', 2, '[06:00] Darren 2단계', 'active']);
    $lectureId2 = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, $darrenOldId, $cohortId, date('Y-m-d'), '06:30:00');
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

    // ───────────────────────────────────────────────────────────
    // 시나리오 T2b: Tier A ORDER BY 시각 근접도 (TIMEDIFF 가드)
    //   같은 코치 같은 날 강의 2건 (06:00, 12:00). 헬퍼를 11:30 시각으로 호출.
    //   → 12:00 강의가 더 가까우므로 그것이 매칭되어야 함 (NULL ordering이면 임의 row가 잡힘).
    // ───────────────────────────────────────────────────────────
    $insAdmin->execute(['hyun_t2b_' . bin2hex(random_bytes(2)), 'Hyun_T2b', 'sub_coach']);
    $hyunId = (int)$db->lastInsertId();
    $insSchedule->execute([$cohortId, $hyunId, 1, '06:00:00', $hyunId]);
    $schA = (int)$db->lastInsertId();
    $insSchedule->execute([$cohortId, $hyunId, 1, '12:00:00', $hyunId]);
    $schB = (int)$db->lastInsertId();
    $insLecture->execute([$schA, $cohortId, $hyunId, date('Y-m-d'), '06:00:00', '07:00:00', 1, '[T2b] 06:00', 'active']);
    $lecEarly = (int)$db->lastInsertId();
    $insLecture->execute([$schB, $cohortId, $hyunId, date('Y-m-d'), '12:00:00', '13:00:00', 1, '[T2b] 12:00', 'active']);
    $lecLate = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, $hyunId, $cohortId, date('Y-m-d'), '11:30:00');
    t('T2b: Tier A ORDER BY 시각 근접 (12:00 선택)', $matched === $lecLate, "expected={$lecLate}(12:00), got=" . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 6: admin_id=0 + Tier C 단일 후보 → 매칭
    // ───────────────────────────────────────────────────────────
    $cohortLabel3 = 'QRM3_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel3, $cohortLabel3]);
    $cohortId3 = (int)$db->lastInsertId();
    $insAdmin->execute(['hyun_t6_' . bin2hex(random_bytes(2)), 'Hyun_T6', 'sub_coach']);
    $hyunT6 = (int)$db->lastInsertId();
    $nowTime6 = date('H:i:s');
    $insSchedule->execute([$cohortId3, $hyunT6, 1, $nowTime6, $hyunT6]);
    $schT6 = (int)$db->lastInsertId();
    $insLecture->execute([$schT6, $cohortId3, $hyunT6, date('Y-m-d'), $nowTime6, $nowTime6, 1, '[T6] Hyun', 'active']);
    $lectureId6 = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, 0, $cohortId3);
    t('T6: admin_id=0 + Tier C 단일 후보 매칭', $matched === $lectureId6, "expected={$lectureId6}, got=" . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 7: admin_id=0 + Tier C 후보 0건 → NULL
    // ───────────────────────────────────────────────────────────
    $cohortLabel4 = 'QRM4_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel4, $cohortLabel4]);
    $cohortId4 = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, 0, $cohortId4);
    t('T7: admin_id=0 후보 없음 → NULL', $matched === null, 'got=' . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 8: Tier B 동명 admin 중 한쪽이 role='operation' (Tier B 가드로 제외)
    //   같은 이름 admin 둘, 하나는 coach 본인 (그날 강의 없음),
    //   하나는 role='operation' 동명 (강의 등록).
    //   → Tier B role 가드 'operation' 제외 → Tier C 단일 후보 fallback 매칭
    //   (plan 원본은 role='member' 였으나 실제 ENUM 에 'member' 없어 'operation' 사용)
    // ───────────────────────────────────────────────────────────
    $cohortLabel5 = 'QRM5_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel5, $cohortLabel5]);
    $cohortId5 = (int)$db->lastInsertId();

    $insAdmin->execute(['tina_coach_' . bin2hex(random_bytes(2)), 'Tina_T8', 'coach']);
    $tinaCoachId = (int)$db->lastInsertId();
    $insAdmin->execute(['tina_oper_' . bin2hex(random_bytes(2)), 'Tina_T8', 'operation']);
    $tinaOperId = (int)$db->lastInsertId();
    // operation 동명이인으로 강의 등록 (가드 검증용 가짜 데이터)
    $nowTime8 = date('H:i:s');
    $insSchedule->execute([$cohortId5, $tinaOperId, 1, $nowTime8, $tinaOperId]);
    $schT8 = (int)$db->lastInsertId();
    $insLecture->execute([$schT8, $cohortId5, $tinaOperId, date('Y-m-d'), $nowTime8, $nowTime8, 1, '[T8] fake', 'active']);
    $fakeLectureId = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, $tinaCoachId, $cohortId5);
    // Tier A 0건 (coach 본인 강의 없음), Tier B 0건 (operation 가드), Tier C 1건 → 매칭
    t('T8: role 가드 + Tier C fallback', $matched === $fakeLectureId, "expected={$fakeLectureId}, got=" . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 9: Tier B 후보가 모두 cancelled → 어디서도 매칭 안 됨
    //   강의가 status='cancelled' 이면 Tier A/B/C 모두 status='active' 가드로 제외
    // ───────────────────────────────────────────────────────────
    $cohortLabel6 = 'QRM6_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel6, $cohortLabel6]);
    $cohortId6 = (int)$db->lastInsertId();
    $insAdmin->execute(['nick_t9_' . bin2hex(random_bytes(2)), 'Nick_T9', 'coach']);
    $nickId = (int)$db->lastInsertId();
    // 강의는 cancelled 상태
    $nowTime9 = date('H:i:s');
    $insSchedule->execute([$cohortId6, $nickId, 1, $nowTime9, $nickId]);
    $schT9 = (int)$db->lastInsertId();
    $insLecture->execute([$schT9, $cohortId6, $nickId, date('Y-m-d'), $nowTime9, $nowTime9, 1, '[T9] cancelled', 'cancelled']);

    $matched = findMatchingLectureSession($db, $nickId, $cohortId6);
    t('T9: cancelled 강의는 매칭 X', $matched === null, 'got=' . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 T2c: Tier B 시각 가드 (같은 코치 그날 강의 1건뿐이지만 >60분 → NULL)
    //   Ace 옛 admin 발급, Ace 새 admin 강의 06:00 등록.
    //   헬퍼를 12:00 시각으로 호출 → 시각차 360분 → Tier B 가드 차단.
    //   Tier C 후보도 0건 (그 강의가 ±60분 밖) → NULL.
    // ───────────────────────────────────────────────────────────
    $cohortLabel7 = 'QRM7_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel7, $cohortLabel7]);
    $cohortId7 = (int)$db->lastInsertId();

    $insAdmin->execute(['ace_old_' . bin2hex(random_bytes(2)), 'Ace_T2c', 'sub_coach']);
    $aceOldId = (int)$db->lastInsertId();
    $insAdmin->execute(['ace_new_' . bin2hex(random_bytes(2)), 'Ace_T2c', 'sub_coach']);
    $aceNewId = (int)$db->lastInsertId();
    $insSchedule->execute([$cohortId7, $aceNewId, 2, '06:00:00', $aceNewId]);
    $schT2c = (int)$db->lastInsertId();
    $insLecture->execute([$schT2c, $cohortId7, $aceNewId, date('Y-m-d'), '06:00:00', '07:00:00', 2, '[T2c]', 'active']);

    $matched = findMatchingLectureSession($db, $aceOldId, $cohortId7, date('Y-m-d'), '12:00:00');
    t('T2c: Tier B 시각 >60분이면 NULL', $matched === null, 'got=' . var_export($matched, true));

} finally {
    $db->rollBack();
}

echo "\n── 결과: PASS {$pass}, FAIL {$fail} ──\n";
exit($fail > 0 ? 1 : 0);
