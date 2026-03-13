<?php
/**
 * member_history_stats 검증 스크립트
 *
 * 1) 테스트용 cohort + 회원 삽입
 * 2) 백필 실행
 * 3) 8가지 케이스 검증
 * 4) 테스트 데이터 삭제 (원복)
 *
 * 실행: php verify_member_stats.php
 */

require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/api/services/member_stats.php';

$db = getDB();

echo "============================================================\n";
echo " member_history_stats 검증 시작\n";
echo "============================================================\n\n";

// ── 1. 테스트 cohort 생성 ──────────────────────────────────────
echo "[1] 테스트 cohort 생성\n";

$testCohorts = [
    // 종료된 cohort 3개 (end_date < today)
    ['cohort' => 'TEST_0기', 'code' => 'TEST_0', 'start_date' => '2025-01-01', 'end_date' => '2025-01-28'],
    ['cohort' => 'TEST_A기', 'code' => 'TEST_A', 'start_date' => '2025-03-01', 'end_date' => '2025-03-28'],
    ['cohort' => 'TEST_B기', 'code' => 'TEST_B', 'start_date' => '2025-06-01', 'end_date' => '2025-06-28'],
    // 진행 중 cohort 1개
    ['cohort' => 'TEST_C기', 'code' => 'TEST_C', 'start_date' => '2026-03-01', 'end_date' => '2026-04-01'],
];

$cohortIds = [];
foreach ($testCohorts as $tc) {
    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date) VALUES (?, ?, ?, ?)")
       ->execute([$tc['cohort'], $tc['code'], $tc['start_date'], $tc['end_date']]);
    $cohortIds[$tc['cohort']] = (int)$db->lastInsertId();
    echo "  {$tc['cohort']} → id={$cohortIds[$tc['cohort']]}\n";
}

// ── 2. 테스트 회원 생성 (8가지 케이스) ──────────────────────────
echo "\n[2] 테스트 회원 생성 (8 케이스)\n";

// 실제 존재하지 않을 phone/user_id 사용
$testMembers = [
    // ① phone만 있는 회원 — TEST_0기 stage1 활성
    ['nickname' => 'TEST_case1_phone_only', 'phone' => '09900000001', 'user_id' => null, 'cohort' => 'TEST_0기', 'stage_no' => 1, 'is_active' => 1],

    // ② user_id만 있는 회원 — TEST_0기 stage1 활성
    ['nickname' => 'TEST_case2_userid_only', 'phone' => null, 'user_id' => 'test_uid_002', 'cohort' => 'TEST_0기', 'stage_no' => 1, 'is_active' => 1],

    // ③ 둘 다 있는 회원 — TEST_0기 stage1 활성
    ['nickname' => 'TEST_case3_both', 'phone' => '09900000003', 'user_id' => 'test_uid_003', 'cohort' => 'TEST_0기', 'stage_no' => 1, 'is_active' => 1],

    // ④ stage1만 참여 — TEST_A기 stage1 활성 (종료 cohort → 완주)
    ['nickname' => 'TEST_case4_stage1', 'phone' => '09900000004', 'user_id' => null, 'cohort' => 'TEST_A기', 'stage_no' => 1, 'is_active' => 1],

    // ⑤ stage2까지 간 회원 — 같은 사람이 TEST_A기에 stage1, TEST_B기에 stage2
    ['nickname' => 'TEST_case5_s1', 'phone' => '09900000005', 'user_id' => null, 'cohort' => 'TEST_A기', 'stage_no' => 1, 'is_active' => 1],
    ['nickname' => 'TEST_case5_s2', 'phone' => '09900000005', 'user_id' => null, 'cohort' => 'TEST_B기', 'stage_no' => 2, 'is_active' => 1],

    // ⑥ 종료 cohort에서 탈락 (is_active=0) — 완주 X
    ['nickname' => 'TEST_case6_dropped', 'phone' => '09900000006', 'user_id' => null, 'cohort' => 'TEST_A기', 'stage_no' => 1, 'is_active' => 0],

    // ⑦ 종료 cohort 정상 수료 — 완주 O
    ['nickname' => 'TEST_case7_completed', 'phone' => '09900000007', 'user_id' => null, 'cohort' => 'TEST_A기', 'stage_no' => 1, 'is_active' => 1],

    // ⑧ 여러 cohort 참여 — 같은 사람이 0기(s1,활성) + A기(s1,활성) + B기(s2,활성) + C기(s1,활성,진행중)
    ['nickname' => 'TEST_case8_multi_0', 'phone' => '09900000008', 'user_id' => 'test_uid_008', 'cohort' => 'TEST_0기', 'stage_no' => 1, 'is_active' => 1],
    ['nickname' => 'TEST_case8_multi_A', 'phone' => '09900000008', 'user_id' => 'test_uid_008', 'cohort' => 'TEST_A기', 'stage_no' => 1, 'is_active' => 1],
    ['nickname' => 'TEST_case8_multi_B', 'phone' => '09900000008', 'user_id' => 'test_uid_008', 'cohort' => 'TEST_B기', 'stage_no' => 2, 'is_active' => 1],
    ['nickname' => 'TEST_case8_multi_C', 'phone' => '09900000008', 'user_id' => 'test_uid_008', 'cohort' => 'TEST_C기', 'stage_no' => 1, 'is_active' => 1],
];

$testMemberIds = [];
foreach ($testMembers as $tm) {
    $cid = $cohortIds[$tm['cohort']];
    $db->prepare("INSERT INTO bootcamp_members (nickname, real_name, phone, user_id, cohort_id, stage_no, is_active, participation_count) VALUES (?, ?, ?, ?, ?, ?, ?, 1)")
       ->execute([$tm['nickname'], $tm['nickname'], $tm['phone'], $tm['user_id'], $cid, $tm['stage_no'], $tm['is_active']]);
    $id = (int)$db->lastInsertId();
    $testMemberIds[] = $id;
    echo "  {$tm['nickname']} → id={$id} (cohort={$tm['cohort']}, stage={$tm['stage_no']}, active={$tm['is_active']})\n";
}

// ── 3. 백필 실행 ──────────────────────────────────────────────
echo "\n[3] 전체 백필 실행\n";
$count = recalcAllMemberStats($db);
echo "  {$count}건 생성\n";

// ── 4. 검증 ────────────────────────────────────────────────────
echo "\n[4] 케이스별 검증\n";
echo str_repeat('─', 80) . "\n";

$cases = [
    ['phone' => '09900000001', 'user_id' => null,           'label' => '① phone만 있는 회원 (TEST_0기 s1 활성, 종료됨)',
     'expect' => ['s1' => 1, 's2' => 0, 'comp' => 1, 'bravo' => null]],

    ['phone' => null,          'user_id' => 'test_uid_002', 'label' => '② user_id만 있는 회원 (TEST_0기 s1 활성, 종료됨)',
     'expect' => ['s1' => 1, 's2' => 0, 'comp' => 1, 'bravo' => null]],

    ['phone' => '09900000003', 'user_id' => 'test_uid_003', 'label' => '③ phone+user_id 둘 다 (TEST_0기 s1 활성, 종료됨)',
     'expect' => ['s1' => 1, 's2' => 0, 'comp' => 1, 'bravo' => null]],

    ['phone' => '09900000004', 'user_id' => null,           'label' => '④ stage1만 참여 (TEST_A기 s1 활성, 종료됨)',
     'expect' => ['s1' => 1, 's2' => 0, 'comp' => 1, 'bravo' => null]],

    ['phone' => '09900000005', 'user_id' => null,           'label' => '⑤ stage2까지 간 회원 (A기 s1 + B기 s2, 모두 종료+활성)',
     'expect' => ['s1' => 1, 's2' => 1, 'comp' => 2, 'bravo' => null]],

    ['phone' => '09900000006', 'user_id' => null,           'label' => '⑥ 종료 cohort에서 탈락 (A기 s1 is_active=0)',
     'expect' => ['s1' => 1, 's2' => 0, 'comp' => 0, 'bravo' => null]],

    ['phone' => '09900000007', 'user_id' => null,           'label' => '⑦ 종료 cohort 정상 수료 (A기 s1 is_active=1)',
     'expect' => ['s1' => 1, 's2' => 0, 'comp' => 1, 'bravo' => null]],

    ['phone' => '09900000008', 'user_id' => 'test_uid_008', 'label' => '⑧ 여러 cohort 참여 (0기s1 + A기s1 + B기s2 + C기s1진행중)',
     'expect' => ['s1' => 3, 's2' => 1, 'comp' => 3, 'bravo' => 'Bravo 1']],
];

$allPass = true;
foreach ($cases as $c) {
    echo "\n{$c['label']}\n";

    // member_history_stats에서 조회
    $actual = null;
    if ($c['phone']) {
        $stmt = $db->prepare("SELECT * FROM member_history_stats WHERE phone = ?");
        $stmt->execute([$c['phone']]);
        $actual = $stmt->fetch();
    }
    if (!$actual && $c['user_id']) {
        $stmt = $db->prepare("SELECT * FROM member_history_stats WHERE user_id = ?");
        $stmt->execute([$c['user_id']]);
        $actual = $stmt->fetch();
    }

    if (!$actual) {
        echo "  ❌ FAIL: member_history_stats에 row 없음\n";
        $allPass = false;
        continue;
    }

    $s1    = (int)$actual['stage1_participation_count'];
    $s2    = (int)$actual['stage2_participation_count'];
    $comp  = (int)$actual['completed_bootcamp_count'];
    $bravo = $actual['bravo_grade'];
    $e     = $c['expect'];

    $pass = ($s1 === $e['s1'] && $s2 === $e['s2'] && $comp === $e['comp'] && $bravo === $e['bravo']);

    echo "  기대: s1={$e['s1']} s2={$e['s2']} comp={$e['comp']} bravo=" . ($e['bravo'] ?? 'null') . "\n";
    echo "  실제: s1={$s1} s2={$s2} comp={$comp} bravo=" . ($bravo ?? 'null') . "\n";
    echo "  " . ($pass ? '✅ PASS' : '❌ FAIL') . "\n";

    if (!$pass) $allPass = false;
}

// ── 5. JOIN 검증 (bootcamp_members + member_history_stats) ────
echo "\n" . str_repeat('─', 80) . "\n";
echo "\n[5] JOIN 검증 (프론트에서 보이는 것과 동일한 쿼리)\n\n";

$stmt = $db->prepare("
    SELECT bm.nickname, bm.phone, bm.user_id, bm.stage_no, bm.is_active,
           c.cohort, c.end_date,
           COALESCE(mhs_p.stage1_participation_count, mhs_u.stage1_participation_count, 0) AS s1,
           COALESCE(mhs_p.stage2_participation_count, mhs_u.stage2_participation_count, 0) AS s2,
           COALESCE(mhs_p.completed_bootcamp_count, mhs_u.completed_bootcamp_count, 0) AS comp,
           COALESCE(mhs_p.bravo_grade, mhs_u.bravo_grade) AS bravo
    FROM bootcamp_members bm
    JOIN cohorts c ON bm.cohort_id = c.id
    LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
    LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
    WHERE bm.nickname LIKE 'TEST_case%'
    ORDER BY bm.nickname
");
$stmt->execute();
$rows = $stmt->fetchAll();

printf("  %-28s %-10s %-8s %-6s %-4s %-4s %-4s %-4s %s\n",
    'nickname', 'cohort', 'end_date', 'stage', 's1', 's2', 'comp', 'bravo', 'active');
printf("  %s\n", str_repeat('─', 90));
foreach ($rows as $r) {
    printf("  %-28s %-10s %-8s %-6s %-4s %-4s %-4s %-4s %s\n",
        $r['nickname'], $r['cohort'], $r['end_date'] < date('Y-m-d') ? '종료' : '진행중',
        $r['stage_no'].'단계', $r['s1'], $r['s2'], $r['comp'],
        $r['bravo'] ?? '-', $r['is_active'] ? 'Y' : 'N');
}

// ── 6. 기존 1기 실데이터 영향 확인 ───────────────────────────
echo "\n" . str_repeat('─', 80) . "\n";
echo "\n[6] 기존 1기 실데이터 영향 없음 확인 (샘플 5명)\n\n";

$stmt = $db->prepare("
    SELECT bm.nickname, bm.phone,
           COALESCE(mhs_p.stage1_participation_count, mhs_u.stage1_participation_count, 0) AS s1,
           COALESCE(mhs_p.stage2_participation_count, mhs_u.stage2_participation_count, 0) AS s2,
           COALESCE(mhs_p.completed_bootcamp_count, mhs_u.completed_bootcamp_count, 0) AS comp,
           COALESCE(mhs_p.bravo_grade, mhs_u.bravo_grade) AS bravo
    FROM bootcamp_members bm
    JOIN cohorts c ON bm.cohort_id = c.id
    LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
    LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
    WHERE c.cohort = '1기'
    LIMIT 5
");
$stmt->execute();
foreach ($stmt->fetchAll() as $r) {
    printf("  %-20s s1=%-3s s2=%-3s comp=%-3s bravo=%s\n",
        $r['nickname'], $r['s1'], $r['s2'], $r['comp'], $r['bravo'] ?? 'null');
}
echo "  → 1기는 아직 진행중(end_date=2026-03-20)이므로 comp=0, bravo=null이 정상\n";

// ── 7. 테스트 데이터 정리 ──────────────────────────────────────
echo "\n" . str_repeat('─', 80) . "\n";
echo "\n[7] 테스트 데이터 삭제 (원복)\n";

// 테스트 회원 삭제
$placeholders = implode(',', array_fill(0, count($testMemberIds), '?'));
$db->prepare("DELETE FROM bootcamp_members WHERE id IN ({$placeholders})")->execute($testMemberIds);
echo "  테스트 회원 " . count($testMemberIds) . "명 삭제\n";

// 테스트 cohort 삭제
foreach ($cohortIds as $name => $cid) {
    $db->prepare("DELETE FROM cohorts WHERE id = ?")->execute([$cid]);
}
echo "  테스트 cohort " . count($cohortIds) . "개 삭제\n";

// 백필 재실행 (테스트 데이터 제거 후 정상 상태 복구)
echo "\n[8] 테스트 데이터 제거 후 백필 재실행 (정상 복구)\n";
$count = recalcAllMemberStats($db);
echo "  {$count}건 생성 (실데이터만)\n";

// ── 결과 ────────────────────────────────────────────────────────
echo "\n" . str_repeat('═', 60) . "\n";
echo $allPass ? "  ✅ 전체 검증 PASS\n" : "  ❌ 일부 검증 FAIL — 위 결과 확인 필요\n";
echo str_repeat('═', 60) . "\n";
