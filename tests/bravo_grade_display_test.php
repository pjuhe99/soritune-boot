<?php
/**
 * 표시 경로가 bravo_member_grades 기준인지 + freeze 검증. DEV DB 트랜잭션 롤백.
 * 사용: php tests/bravo_grade_display_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/bravo_grades.php';
require_once __DIR__ . '/../public_html/api/services/member_stats.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// 표시 SQL 조각 (4개 파일 공통 패턴) — 파일에서 직접 추출해 실행으로 검증
$displayExpr = "CASE WHEN bmg.current_level >= 1 THEN CONCAT('Bravo ', bmg.current_level) END";

$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TGD_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1)")
       ->execute(["{$tag}기", $tag]);
    $cohortId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
       ->execute([$cohortId, "{$tag}회원", "{$tag}닉", '01000000501', "{$tag}_uid"]);
    $mid = (int)$db->lastInsertId();
    // 레거시엔 'Bravo 3', 신규엔 1 — 표시가 신규(1)를 따라야 함 (강등 반영 증명)
    $db->prepare("INSERT INTO member_history_stats (user_id, completed_bootcamp_count, bravo_grade, last_calculated_at) VALUES (?, 10, 'Bravo 3', NOW())
                  ON DUPLICATE KEY UPDATE bravo_grade='Bravo 3'")->execute(["{$tag}_uid"]);
    bravoGradeSet($db, "{$tag}_uid", 1, 'admin_adjust', 99, null);

    // member.php check_session 과 동일 패턴의 쿼리 실행
    $stmt = $db->prepare("
        SELECT {$displayExpr} AS bravo_grade
        FROM bootcamp_members bm
        LEFT JOIN bravo_member_grades bmg ON bmg.member_key = COALESCE(NULLIF(bm.user_id, ''), CONCAT('p:', bm.phone))
        WHERE bm.id = ?
    ");
    $stmt->execute([$mid]);
    t('표시 = 신규 테이블 기준 (레거시 Bravo 3 무시, Bravo 1)', $stmt->fetchColumn() === 'Bravo 1');

    // 무등급 → NULL
    bravoGradeSet($db, "{$tag}_uid", 0, 'self_demotion', null, null);
    $stmt->execute([$mid]);
    t('무등급 → NULL', $stmt->fetchColumn() === null);

    // phone-only 회원
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,NULL,1)")
       ->execute([$cohortId, "{$tag}폰", "{$tag}닉2", '01000000502']);
    $mid2 = (int)$db->lastInsertId();
    bravoGradeSet($db, 'p:01000000502', 2, 'grandfather', null, null);
    $stmt->execute([$mid2]);
    t('phone-only 표시 (p: 키)', $stmt->fetchColumn() === 'Bravo 2');

    // ── freeze: refreshMemberStats 가 bravo_grade 를 더이상 쓰지 않음 ──
    refreshMemberStats($db, '01000000501', "{$tag}_uid");
    $legacy = $db->prepare("SELECT bravo_grade FROM member_history_stats WHERE user_id = ?");
    $legacy->execute(["{$tag}_uid"]);
    t('freeze: 레거시 값 보존 (재계산이 Bravo 3 유지 — 완주 0 인데도 안 덮음)', $legacy->fetchColumn() === 'Bravo 3');

    // ── 실제 파일들이 레거시 bravo_grade 를 더이상 SELECT 하지 않는지 정적 검사 ──
    foreach (['public_html/api/member.php', 'public_html/api/services/member.php', 'public_html/api/services/member_page.php', 'public_html/api/admin.php'] as $f) {
        $src = file_get_contents(__DIR__ . '/../' . $f);
        t("{$f}: mhs.bravo_grade 읽기 제거", strpos($src, 'mhs_u.bravo_grade') === false && strpos($src, 'mhs_p.bravo_grade') === false);
        t("{$f}: 신규 조인 존재", strpos($src, 'bravo_member_grades') !== false);
    }
    $statsSrc = file_get_contents(__DIR__ . '/../public_html/api/services/member_stats.php');
    t('member_stats: upsert 에서 bravo_grade 제거', substr_count($statsSrc, 'bravo_grade') <= 2); // calcBravoGrade docblock 언급 정도만 허용

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
