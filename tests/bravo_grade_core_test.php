<?php
/**
 * BRAVO 등급 서비스 코어 테스트 (Set/CurrentLevel/로그/Demote/LockRow/Backfill). DEV DB 트랜잭션 롤백.
 * 사용: php tests/bravo_grade_core_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/bravo_grades.php';

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
    $tag = 'TGC_' . bin2hex(random_bytes(3));
    $k1 = "{$tag}_uid1";
    $kp = "p:0109999{$tag}"; // phone-only 키

    // ── CurrentLevel: 행 부재 = 0 ──
    t('행 부재 = 무등급 0', bravoGradeCurrentLevel($db, $k1) === 0);

    // ── Set + 로그 ──
    bravoGradeSet($db, $k1, 2, 'admin_adjust', 99, '테스트 부여');
    t('Set 후 레벨', bravoGradeCurrentLevel($db, $k1) === 2);
    $log = $db->prepare("SELECT * FROM bravo_grade_log WHERE member_key = ? ORDER BY id DESC LIMIT 1");
    $log->execute([$k1]);
    $l = $log->fetch(PDO::FETCH_ASSOC);
    t('로그 기록 (from 0 → to 2, source, ref)', $l && (int)$l['from_level'] === 0 && (int)$l['to_level'] === 2 && $l['source'] === 'admin_adjust' && (int)$l['ref_id'] === 99);

    // no-op: 같은 레벨 재설정 → 로그 없음
    $cntBefore = (int)$db->query("SELECT COUNT(*) FROM bravo_grade_log WHERE member_key = '{$k1}'")->fetchColumn();
    bravoGradeSet($db, $k1, 2, 'admin_adjust', 99, null);
    $cntAfter = (int)$db->query("SELECT COUNT(*) FROM bravo_grade_log WHERE member_key = '{$k1}'")->fetchColumn();
    t('같은 레벨 no-op (로그 미증가)', $cntBefore === $cntAfter);

    // 범위 클램프
    bravoGradeSet($db, $k1, 9, 'admin_adjust', 99, null);
    t('상한 클램프 3', bravoGradeCurrentLevel($db, $k1) === 3);

    // ── Demote ──
    $r = bravoGradeDemote($db, $k1);
    t('강등 3→2', !isset($r['error']) && $r['from'] === 3 && $r['to'] === 2 && bravoGradeCurrentLevel($db, $k1) === 2);
    bravoGradeDemote($db, $k1);
    $r = bravoGradeDemote($db, $k1);
    t('강등 1→0 (무등급 포함)', !isset($r['error']) && $r['to'] === 0 && bravoGradeCurrentLevel($db, $k1) === 0);
    $r = bravoGradeDemote($db, $k1);
    t('0 에서 강등 거부', isset($r['error']));
    $demoteLogs = (int)$db->query("SELECT COUNT(*) FROM bravo_grade_log WHERE member_key = '{$k1}' AND source = 'self_demotion'")->fetchColumn();
    t('강등 로그 3건', $demoteLogs === 3);

    // ── phone-only 키 동작 ──
    bravoGradeSet($db, $kp, 1, 'grandfather', null, null);
    t('phone-only 키 등급', bravoGradeCurrentLevel($db, $kp) === 1);
    $r = bravoGradeDemote($db, $kp);
    t('phone-only 강등', !isset($r['error']) && $r['to'] === 0);

    // ── LockRow: lazy 생성 + 행 반환 ──
    $k2 = "{$tag}_uid2";
    $row = bravoGradeLockRow($db, $k2);
    t('LockRow lazy 생성 (level 0, extra 0)', $row && (int)$row['current_level'] === 0 && (int)$row['extra_attempts_1'] === 0);
    $row2 = bravoGradeLockRow($db, $k2);
    t('LockRow 재호출 동일 행', (int)$row2['id'] === (int)$row['id']);

    // ── Backfill ──
    // 레거시 행 셋업: user_id 행 'Bravo 2' + 같은 사람 phone 행 'Bravo 1'(user 행이 커버 — skip 대상)
    $phone = '0108888' . substr($tag, 4);
    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1)")
       ->execute(["{$tag}기", $tag]);
    $cohortId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
       ->execute([$cohortId, "{$tag}회원", "{$tag}닉", $phone, "{$tag}_legacy"]);
    $db->prepare("INSERT INTO member_history_stats (user_id, completed_bootcamp_count, bravo_grade, last_calculated_at) VALUES (?, 6, 'Bravo 2', NOW())
                  ON DUPLICATE KEY UPDATE bravo_grade = 'Bravo 2'")->execute(["{$tag}_legacy"]);
    $db->prepare("INSERT INTO member_history_stats (phone, completed_bootcamp_count, bravo_grade, last_calculated_at) VALUES (?, 6, 'Bravo 1', NOW())
                  ON DUPLICATE KEY UPDATE bravo_grade = 'Bravo 1'")->execute([$phone]);
    // phone-only 레거시: bootcamp_members 에 user_id 없는 회원 + phone 행 'Bravo 3'
    $phone2 = '0107777' . substr($tag, 4);
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,NULL,1)")
       ->execute([$cohortId, "{$tag}폰온리", "{$tag}닉2", $phone2]);
    $db->prepare("INSERT INTO member_history_stats (phone, completed_bootcamp_count, bravo_grade, last_calculated_at) VALUES (?, 10, 'Bravo 3', NOW())
                  ON DUPLICATE KEY UPDATE bravo_grade = 'Bravo 3'")->execute([$phone2]);

    $r1 = bravoGradeBackfillFromLegacy($db);
    t('backfill: user 키 Bravo 2', bravoGradeCurrentLevel($db, "{$tag}_legacy") === 2);
    t('backfill: 같은 사람 phone 행은 user 키로 흡수 (p:키 미생성)', bravoGradeCurrentLevel($db, 'p:' . $phone) === 0);
    t('backfill: phone-only → p: 키 Bravo 3', bravoGradeCurrentLevel($db, 'p:' . $phone2) === 3);
    $gfLogs = (int)$db->query("SELECT COUNT(*) FROM bravo_grade_log WHERE source = 'grandfather' AND member_key IN (" . $db->quote("{$tag}_legacy") . "," . $db->quote('p:' . $phone2) . ")")->fetchColumn();
    t('backfill 로그 grandfather', $gfLogs === 2);

    // 멱등: 재실행 시 변화·로그 증가 없음
    $logsBefore = (int)$db->query("SELECT COUNT(*) FROM bravo_grade_log")->fetchColumn();
    $r2 = bravoGradeBackfillFromLegacy($db);
    $logsAfter = (int)$db->query("SELECT COUNT(*) FROM bravo_grade_log")->fetchColumn();
    t('backfill 멱등 (applied 0, 로그 동일)', $r2['applied'] === 0 && $logsBefore === $logsAfter);

    // 이미 더 높은 등급 보유자는 강등시키지 않음 (GREATEST 의미)
    bravoGradeSet($db, "{$tag}_legacy", 3, 'exam_pass', null, null);
    bravoGradeBackfillFromLegacy($db);
    t('backfill 이 기존 상위 등급 안 내림', bravoGradeCurrentLevel($db, "{$tag}_legacy") === 3);

    // 강등 후 마이그 재실행이 grandfather 등급을 복원하면 안 됨 (외부 리뷰 Critical)
    bravoGradeSet($db, 'p:' . $phone2, 1, 'self_demotion', null, null); // B3 grandfather → B1 로 강등 시뮬
    bravoGradeBackfillFromLegacy($db);
    t('강등 후 backfill 재실행이 복원 안 함', bravoGradeCurrentLevel($db, 'p:' . $phone2) === 1);

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
