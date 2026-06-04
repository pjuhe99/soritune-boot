# BRAVO 관리자 채점 (수동채점·확정) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 관리자가 제출된 BRAVO 응시의 녹음을 들으며 문항별 간단 판정을 입력하면 등급별 배점표로 자동 환산하고, 합불 자동 판정(+오버라이드)을 거쳐 확정한다.

**Architecture:** 순수 추가형. 신규 테이블 2개(`bravo_answer_grades` 판정+점수 스냅샷+n_denominator / `bravo_attempt_grades` 확정+passing_score 스냅샷, 행 존재=확정). 환산은 코드 상수 가중치 기반 순수함수. 확정 시 N 불일치 grade 자동 재환산 + 유령 grade(삭제 문항) 합산 제외. 오디오는 admin.php에서 Range 단일 범위 지원 스트리밍. 프론트는 BRAVO 셸 4번째 서브탭.

**Tech Stack:** PHP 8 + PDO(MariaDB), vanilla JS SPA, 커스텀 `t()` CLI 테스트(트랜잭션 롤백). 작업·검증은 **DEV(`/root/boot-dev`, dev 브랜치, DB SORITUNECOM_DEV_BOOT)** 에서만. ⚠️ DDL은 마이그의 CREATE TABLE IF NOT EXISTS만 — DROP/TRUNCATE/ALTER 금지. PROD(`/root/boot-prod`) 접근·git push 금지(컨트롤러가 최종 수행).

**참조 스펙:** `docs/superpowers/specs/2026-06-04-bravo-grading-design.md`

---

## File Structure

- **Create** `migrate_bravo_grades.php` — 멱등 마이그(2테이블).
- **Create** `public_html/api/services/bravo_grading.php` — 가중치 상수·환산/검증 순수함수·save/confirm/cancel/목록/detail/Range 파싱.
- **Modify** `public_html/api/services/bravo.php` — `bravoExamDelete`에 grades 2테이블 cascade 추가.
- **Modify** `public_html/api/admin.php` — require_once + case 6개(목록2/detail/save/confirm/audio).
- **Create** `public_html/js/admin-bravo-grading.js` — 채점 서브탭 모듈(AdminBravoGradingApp).
- **Modify** `public_html/js/admin-bravo.js` — 셸에 4번째 서브탭 `grading` 등록.
- **Modify** `public_html/operation/index.php` — script include 1줄.
- **Create** `tests/bravo_grades_schema_invariants.php`, `tests/bravo_grading_test.php`.

---

## Task 1: 마이그레이션 (bravo_answer_grades / bravo_attempt_grades)

**Files:**
- Create: `migrate_bravo_grades.php`
- Test: `tests/bravo_grades_schema_invariants.php`

- [ ] **Step 1: 마이그레이션 파일 작성**

Create `migrate_bravo_grades.php`:

```php
<?php
/**
 * Migration: BRAVO 7차 슬라이스 — bravo_answer_grades / bravo_attempt_grades (관리자 채점)
 * 실행: php migrate_bravo_grades.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS. 추가형(기존 테이블 미수정).
 */
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: bravo_answer_grades / bravo_attempt_grades ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_answer_grades (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    answer_id       INT UNSIGNED NOT NULL COMMENT 'bravo_answers.id',
    attempt_id      INT UNSIGNED NOT NULL COMMENT '비정규화 — 목록 집계용',
    accuracy        ENUM('correct','partial','wrong') NOT NULL COMMENT '정답도 (1/0.5/0)',
    chunk_ok        TINYINT(1) NOT NULL COMMENT '핵심청크 포함 (1/0)',
    response_rating ENUM('good','normal','poor') NOT NULL COMMENT '반응속도 (1/0.5/0)',
    fluency_rating  ENUM('good','normal','poor') NOT NULL COMMENT '유창성 (1/0.5/0)',
    completion_ok   TINYINT(1) NULL COMMENT '발화완성도 — B2/B3만, B1은 NULL',
    score           DECIMAL(5,2) NOT NULL COMMENT '판정 시점 환산 점수 스냅샷',
    n_denominator   SMALLINT UNSIGNED NOT NULL COMMENT '환산에 사용한 분모 N (확정 시 일관성 검증/재환산용)',
    memo            VARCHAR(255) NULL COMMENT '문항 메모',
    graded_by       INT UNSIGNED NOT NULL,
    graded_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bag_answer (answer_id),
    KEY idx_bag_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_answer_grades 생성 완료\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_attempt_grades (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id        INT UNSIGNED NOT NULL COMMENT 'bravo_attempts.id — 행 존재 = 확정',
    total_score       DECIMAL(5,2) NOT NULL COMMENT '확정 시점 합산 스냅샷',
    passing_score     DECIMAL(5,2) NOT NULL COMMENT '확정 시점 합격선 스냅샷',
    result            ENUM('pass','fail') NOT NULL,
    result_overridden TINYINT(1) NOT NULL DEFAULT 0,
    override_reason   VARCHAR(255) NULL COMMENT '오버라이드 시 필수',
    memo              TEXT NULL COMMENT '전체 채점 메모',
    confirmed_by      INT UNSIGNED NOT NULL,
    confirmed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_batg_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_attempt_grades 생성 완료\n";

echo "\n=== Migration 완료 ===\n";
```

- [ ] **Step 2: DEV DB 적용**

Run: `cd /root/boot-dev && php migrate_bravo_grades.php`
Expected:
```
bravo_answer_grades 생성 완료
bravo_attempt_grades 생성 완료

=== Migration 완료 ===
```

- [ ] **Step 3: 스키마 불변식 테스트 작성**

Create `tests/bravo_grades_schema_invariants.php`:

```php
<?php
/**
 * bravo_answer_grades / bravo_attempt_grades 스키마 불변식. DEV DB.
 * 사용: php tests/bravo_grades_schema_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();

foreach (['bravo_answer_grades', 'bravo_attempt_grades'] as $tbl) {
    $exists = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$tbl}'")->fetchColumn();
    t("{$tbl} 테이블 존재", (int)$exists === 1);
    if ((int)$exists !== 1) { echo "\n결과: {$pass} pass, {$fail} fail\n"; exit(1); }
}

// bravo_answer_grades
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_answer_grades") as $c) $cols[$c['Field']] = $c;
foreach (['id','answer_id','attempt_id','accuracy','chunk_ok','response_rating','fluency_rating','completion_ok','score','n_denominator','memo','graded_by','graded_at'] as $col) {
    t("answer_grades.{$col} 존재", isset($cols[$col]));
}
t('accuracy ENUM 3값', stripos($cols['accuracy']['Type'], "enum('correct','partial','wrong')") === 0);
t('response_rating ENUM 3값', stripos($cols['response_rating']['Type'], "enum('good','normal','poor')") === 0);
t('completion_ok NULL 허용', $cols['completion_ok']['Null'] === 'YES');
t('score NOT NULL', $cols['score']['Null'] === 'NO');
t('n_denominator NOT NULL', $cols['n_denominator']['Null'] === 'NO');
$idx = $db->query("SHOW INDEX FROM bravo_answer_grades WHERE Key_name='uk_bag_answer'")->fetchAll();
t('answer_id UNIQUE', count($idx) === 1 && (int)$idx[0]['Non_unique'] === 0);
$ix = $db->query("SHOW INDEX FROM bravo_answer_grades WHERE Key_name='idx_bag_attempt'")->fetchAll();
t('idx_bag_attempt 존재', count($ix) === 1);

// bravo_attempt_grades
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_attempt_grades") as $c) $cols[$c['Field']] = $c;
foreach (['id','attempt_id','total_score','passing_score','result','result_overridden','override_reason','memo','confirmed_by','confirmed_at'] as $col) {
    t("attempt_grades.{$col} 존재", isset($cols[$col]));
}
t('result ENUM 2값', stripos($cols['result']['Type'], "enum('pass','fail')") === 0);
t('passing_score NOT NULL', $cols['passing_score']['Null'] === 'NO');
t('result_overridden 기본 0', (string)$cols['result_overridden']['Default'] === '0');
$idx = $db->query("SHOW INDEX FROM bravo_attempt_grades WHERE Key_name='uk_batg_attempt'")->fetchAll();
t('attempt_id UNIQUE', count($idx) === 1 && (int)$idx[0]['Non_unique'] === 0);

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 4: 테스트 실행 → 통과**

Run: `cd /root/boot-dev && php tests/bravo_grades_schema_invariants.php`
Expected: 모든 `PASS`, `결과: N pass, 0 fail`

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add migrate_bravo_grades.php tests/bravo_grades_schema_invariants.php
git commit -m "feat(bravo): 채점 마이그레이션 (bravo_answer_grades/bravo_attempt_grades)"
```

---

## Task 2: 환산 순수함수 + 판정 저장 서비스

**Files:**
- Create: `public_html/api/services/bravo_grading.php`
- Test: `tests/bravo_grading_test.php`

- [ ] **Step 1: 테스트 작성 (TDD — 순수함수 + save 부분)**

Create `tests/bravo_grading_test.php`:

```php
<?php
/**
 * BRAVO 채점 서비스 테스트. DEV DB 통합(트랜잭션 롤백).
 * 사용: php tests/bravo_grading_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
define('BRAVO_UPLOAD_ROOT', sys_get_temp_dir() . '/bravo_grading_test_' . getmypid());
require_once __DIR__ . '/../public_html/api/services/bravo.php';
require_once __DIR__ . '/../public_html/api/services/bravo_questions.php';
require_once __DIR__ . '/../public_html/api/services/bravo_exam_questions.php';
require_once __DIR__ . '/../public_html/api/services/bravo_attempts.php';
require_once __DIR__ . '/../public_html/api/services/bravo_grading.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// ── 환산 순수함수 (DB 불필요) ──
$J_MAX  = ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good','completion_ok'=>1];
$J_ZERO = ['accuracy'=>'wrong','chunk_ok'=>0,'response_rating'=>'poor','fluency_rating'=>'poor','completion_ok'=>0];
$J_MIX  = ['accuracy'=>'partial','chunk_ok'=>1,'response_rating'=>'normal','fluency_rating'=>'poor','completion_ok'=>1];

t('B1 만점 문항 (N=20)', bravoGradeScore(1, 20, $J_MAX) === 5.0);                  // 100/20
t('B1 0점 문항', bravoGradeScore(1, 20, $J_ZERO) === 0.0);
t('B1 completion 무시', bravoGradeScore(1, 20, array_merge($J_MAX, ['completion_ok'=>0])) === 5.0); // 가중치 0
t('B2 만점 문항 (N=20)', bravoGradeScore(2, 20, $J_MAX) === 5.0);
t('B3 만점 문항 (N=20)', bravoGradeScore(3, 20, $J_MAX) === 5.0);
// B1 혼합: (60*0.5 + 20*1 + 10*0.5 + 10*0)/20 = (30+20+5)/20 = 2.75
t('B1 혼합 판정', bravoGradeScore(1, 20, $J_MIX) === 2.75);
// B2 혼합: (45*0.5 + 20*1 + 15*0.5 + 15*0 + 5*1)/20 = (22.5+20+7.5+5)/20 = 2.75
t('B2 혼합 판정', bravoGradeScore(2, 20, $J_MIX) === 2.75);
// N=3 반올림: B1 만점 = 100/3 = 33.333... → 33.33
t('N=3 반올림', bravoGradeScore(1, 3, $J_MAX) === 33.33);
t('N=0 방어', bravoGradeScore(1, 0, $J_MAX) === 0.0);
t('미정의 등급 방어', bravoGradeScore(9, 20, $J_MAX) === 0.0);

// 판정 검증
t('B1 검증 통과 (completion 없이)', bravoGradeValidate(1, ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good']) === []);
t('B2 completion 누락 거부', bravoGradeValidate(2, ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good']) !== []);
t('잘못된 accuracy 거부', bravoGradeValidate(1, ['accuracy'=>'great','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good']) !== []);
t('chunk_ok 누락 거부', bravoGradeValidate(1, ['accuracy'=>'correct','response_rating'=>'good','fluency_rating'=>'good']) !== []);

// Range 파싱
t('Range 정상 0-99', bravoAudioRangeParse('bytes=0-99', 1000) === [0, 99]);
t('Range 중간', bravoAudioRangeParse('bytes=500-', 1000) === [500, 999]);
t('Range suffix', bravoAudioRangeParse('bytes=-100', 1000) === [900, 999]);
t('Range end 초과 클램프', bravoAudioRangeParse('bytes=0-5000', 1000) === [0, 999]);
t('Range 비정상 → null', bravoAudioRangeParse('bytes=abc', 1000) === null);
t('Range 멀티 → null', bravoAudioRangeParse('bytes=0-1,5-9', 1000) === null);
t('Range start 초과 → null', bravoAudioRangeParse('bytes=1000-', 1000) === null);
t('Range null 헤더', bravoAudioRangeParse(null, 1000) === null);

// ── DB 통합 ──
$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TGR_' . bin2hex(random_bytes(3));

    // 셋업: 기수+회원(자격)+시험(B2, open)+문제 3개+배정+응시+답안 3건+submit
    // ⚠️ cohorts/bootcamp_members INSERT 컬럼은 tests/bravo_attempt_test.php 의 셋업을 그대로 본뜰 것
    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1)")
       ->execute(["{$tag}기", $tag]);
    $cohortId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
       ->execute([$cohortId, "{$tag}응시자", "{$tag}닉", '01000000099', "{$tag}_uid"]);
    $memberId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute(["{$tag}_uid"]);

    $examId = bravoExamCreate($db, ['title'=>"{$tag} 시험",'bravo_level'=>2,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    $qids = [];
    foreach ([1,2,3] as $i) {
        $qids[] = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>2,'korean_text'=>"{$tag} q{$i}",'english_text'=>"answer {$i}",'accepted_answers'=>"alt {$i}",'target_chunks'=>"chunk {$i}",'difficulty'=>'easy','is_active'=>1], 99);
    }
    bravoExamQuestionSet($db, $examId, $qids);

    $acc = bravoAttemptExamAccess($db, $memberId, $examId);
    $r = bravoAttemptStart($db, $acc['exam'], $acc['ctx']['row'], $acc['member_key'], false);
    $attempt = $r['attempt'];
    $answerIds = [];
    foreach ($qids as $q) {
        $f = tempnam(sys_get_temp_dir(), 'tgr_'); file_put_contents($f, 'audio');
        bravoAnswerStore($db, $attempt, $q, $f, 'audio/webm', 'webm', 3000, false);
        $aid = $db->query("SELECT id FROM bravo_answers WHERE attempt_id=" . (int)$attempt['id'] . " AND question_id=" . (int)$q)->fetchColumn();
        $answerIds[$q] = (int)$aid;
    }
    bravoAttemptSubmit($db, $attempt);
    $attempt = bravoAttemptGet($db, (int)$attempt['id']);
    $exam = $acc['exam'];
    t('셋업 정상', $attempt['status'] === 'submitted' && count($answerIds) === 3);

    // save: in_progress attempt 거부 검증용 별도 응시
    $examId2 = bravoExamCreate($db, ['title'=>"{$tag} 진행중",'bravo_level'=>2,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    bravoExamQuestionSet($db, $examId2, [$qids[0]]);
    $acc2 = bravoAttemptExamAccess($db, $memberId, $examId2);
    $r2 = bravoAttemptStart($db, $acc2['exam'], $acc2['ctx']['row'], $acc2['member_key'], false);
    $ipAttempt = $r2['attempt'];
    $f = tempnam(sys_get_temp_dir(), 'tgr_'); file_put_contents($f, 'audio');
    bravoAnswerStore($db, $ipAttempt, $qids[0], $f, 'audio/webm', 'webm', 1000, false);
    $ipAnswerId = (int)$db->query("SELECT id FROM bravo_answers WHERE attempt_id=" . (int)$ipAttempt['id'])->fetchColumn();
    $g = bravoGradeSave($db, $ipAttempt, $acc2['exam'], $ipAnswerId, ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good','completion_ok'=>1], 99);
    t('비submitted 채점 거부', isset($g['error']));

    // save 정상 (B2, N=3): 만점 문항 = 100/3 = 33.33
    $J = ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good','completion_ok'=>1];
    $g = bravoGradeSave($db, $attempt, $exam, $answerIds[$qids[0]], $J + ['memo'=>'좋음'], 99);
    t('save 정상', !isset($g['error']) && $g['score'] === 33.33 && $g['graded_count'] === 1 && $g['total_count'] === 3, json_encode($g));
    $row = $db->query("SELECT * FROM bravo_answer_grades WHERE answer_id=" . $answerIds[$qids[0]])->fetch(PDO::FETCH_ASSOC);
    t('grade 행 (n_denominator/메모/graded_by)', (int)$row['n_denominator'] === 3 && $row['memo'] === '좋음' && (int)$row['graded_by'] === 99);

    // 재판정 갱신 (upsert)
    $g = bravoGradeSave($db, $attempt, $exam, $answerIds[$qids[0]], ['accuracy'=>'wrong','chunk_ok'=>0,'response_rating'=>'poor','fluency_rating'=>'poor','completion_ok'=>0], 99);
    t('재판정 갱신', !isset($g['error']) && $g['score'] === 0.0 && $g['graded_count'] === 1);

    // B2 completion 누락 거부 / 스냅샷 밖 answer 거부
    $g = bravoGradeSave($db, $attempt, $exam, $answerIds[$qids[1]], ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good'], 99);
    t('B2 completion 누락 거부', isset($g['error']));
    $g = bravoGradeSave($db, $attempt, $exam, 99999999, $J, 99);
    t('미존재 answer 거부', isset($g['error']));

    // summary (1문항만 판정 → auto_result null)
    $sum = bravoGradingSummary($db, $attempt, 2);
    t('summary 진행중', $sum['graded_count'] === 1 && $sum['total_count'] === 3 && $sum['auto_result'] === null);

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

// tmp 정리
if (is_dir(BRAVO_UPLOAD_ROOT)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BRAVO_UPLOAD_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname()); }
    @rmdir(BRAVO_UPLOAD_ROOT);
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

⚠️ `bravoQuestionCreate` 입력 키(`accepted_answers`/`target_chunks`)는 실제 `bravo_questions.php`의 PersistData에서 확인 — 미지원 키면 제거(테스트 의도는 유지). 셋업 INSERT는 `tests/bravo_attempt_test.php` 패턴 준수.

- [ ] **Step 2: 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_grading_test.php`
Expected: FAIL — `Failed opening required ... bravo_grading.php`.

- [ ] **Step 3: 서비스 구현 (1부 — 상수·순수함수·save·summary·Range)**

Create `public_html/api/services/bravo_grading.php`:

```php
<?php
/**
 * BRAVO 채점 서비스 (7차 슬라이스). 관리자 수동채점 — 판정→자동 환산→확정.
 * 점수 스냅샷의 보호 대상은 가중치 상수 변경. N(분모) 변경은 확정 시 자동 재환산.
 */

require_once __DIR__ . '/bravo.php';
require_once __DIR__ . '/bravo_attempts.php';

// 등급별 평가요소 가중치 (기능정의서 §11 — DB화는 YAGNI)
const BRAVO_GRADE_WEIGHTS = [
    1 => ['accuracy' => 60, 'chunk' => 20, 'response' => 10, 'fluency' => 10, 'completion' => 0],
    2 => ['accuracy' => 45, 'chunk' => 20, 'response' => 15, 'fluency' => 15, 'completion' => 5],
    3 => ['accuracy' => 40, 'chunk' => 15, 'response' => 20, 'fluency' => 20, 'completion' => 5],
];
const BRAVO_GRADE_COEFF = ['correct' => 1.0, 'partial' => 0.5, 'wrong' => 0.0, 'good' => 1.0, 'normal' => 0.5, 'poor' => 0.0];

/**
 * 판정 입력 검증. 통과 시 빈 배열, 실패 시 에러 메시지 배열. 순수.
 * completion_ok 는 B2/B3(가중치>0)에서만 필수, B1은 무시.
 */
function bravoGradeValidate(int $level, array $d): array {
    $errors = [];
    if (!in_array($d['accuracy'] ?? '', ['correct', 'partial', 'wrong'], true)) $errors[] = '정답도 판정이 필요합니다.';
    if (!isset($d['chunk_ok']) || !in_array((int)$d['chunk_ok'], [0, 1], true)) $errors[] = '청크 판정이 필요합니다.';
    foreach (['response_rating' => '반응속도', 'fluency_rating' => '유창성'] as $k => $label) {
        if (!in_array($d[$k] ?? '', ['good', 'normal', 'poor'], true)) $errors[] = "{$label} 판정이 필요합니다.";
    }
    $w = BRAVO_GRADE_WEIGHTS[$level] ?? null;
    if ($w && $w['completion'] > 0 && (!isset($d['completion_ok']) || !in_array((int)$d['completion_ok'], [0, 1], true))) {
        $errors[] = '발화완성도 판정이 필요합니다.';
    }
    return $errors;
}

/**
 * 문항 환산 점수. 문항별 요소 만점 = 등급 가중치 ÷ N. DECIMAL(5,2) 반올림. 순수.
 */
function bravoGradeScore(int $level, int $n, array $j): float {
    $w = BRAVO_GRADE_WEIGHTS[$level] ?? null;
    if (!$w || $n < 1) return 0.0;
    $score  = ($w['accuracy'] / $n) * (BRAVO_GRADE_COEFF[$j['accuracy'] ?? ''] ?? 0.0);
    $score += ($w['chunk'] / $n) * (!empty($j['chunk_ok']) ? 1.0 : 0.0);
    $score += ($w['response'] / $n) * (BRAVO_GRADE_COEFF[$j['response_rating'] ?? ''] ?? 0.0);
    $score += ($w['fluency'] / $n) * (BRAVO_GRADE_COEFF[$j['fluency_rating'] ?? ''] ?? 0.0);
    if ($w['completion'] > 0) {
        $score += ($w['completion'] / $n) * (!empty($j['completion_ok']) ? 1.0 : 0.0);
    }
    return round($score, 2);
}

/**
 * HTTP Range 헤더 파싱 (단일 범위만). 성공 [start, end], 미적용/비정상 null → 200 전체 폴백. 순수.
 */
function bravoAudioRangeParse(?string $header, int $size): ?array {
    if ($header === null || $size <= 0) return null;
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) return null;
    if ($m[1] === '' && $m[2] === '') return null;
    if ($m[1] === '') {
        $len = (int)$m[2];
        if ($len <= 0) return null;
        $start = max(0, $size - $len);
        $end = $size - 1;
    } else {
        $start = (int)$m[1];
        $end = ($m[2] === '') ? $size - 1 : min((int)$m[2], $size - 1);
    }
    if ($start > $end || $start >= $size) return null;
    return [$start, $end];
}

/**
 * 채점 대상 문항 id 목록 = 스냅샷 ∩ 현존 (slice6 submit 완비 판정과 동일 기준). 순서 보존.
 */
function bravoGradingQuestionIds(PDO $db, array $attempt): array {
    return array_map(fn($q) => (int)$q['id'], bravoAttemptQuestions($db, $attempt));
}

/**
 * attempt 확정 행 조회 (없으면 null).
 */
function bravoAttemptGradeGet(PDO $db, int $attemptId): ?array {
    $stmt = $db->prepare("SELECT * FROM bravo_attempt_grades WHERE attempt_id = ?");
    $stmt->execute([$attemptId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * attempt 의 유효 grade 행들 (현존 문항 대응만 — 유령 grade 제외). [question_id => grade row]
 */
function bravoGradingValidGrades(PDO $db, array $attempt): array {
    $validQids = bravoGradingQuestionIds($db, $attempt);
    if (!$validQids) return [];
    $stmt = $db->prepare("
        SELECT g.*, a.question_id
        FROM bravo_answer_grades g
        JOIN bravo_answers a ON g.answer_id = a.id
        WHERE g.attempt_id = ?
    ");
    $stmt->execute([(int)$attempt['id']]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $qid = (int)$g['question_id'];
        if (in_array($qid, $validQids, true)) $out[$qid] = $g;
    }
    return $out;
}

/**
 * 채점 진행 요약: graded_count / total_count / total_so_far(유효 grade 합) / auto_result(전 문항 판정 시에만, 아니면 null).
 */
function bravoGradingSummary(PDO $db, array $attempt, int $level): array {
    $totalCount = count(bravoGradingQuestionIds($db, $attempt));
    $grades = bravoGradingValidGrades($db, $attempt);
    $total = round(array_sum(array_map(fn($g) => (float)$g['score'], $grades)), 2);
    $auto = null;
    if ($totalCount > 0 && count($grades) === $totalCount) {
        $passing = bravoGradingPassingScore($db, $level);
        $auto = $total >= $passing ? 'pass' : 'fail';
    }
    return ['graded_count' => count($grades), 'total_count' => $totalCount, 'total_so_far' => $total, 'auto_result' => $auto];
}

/**
 * 등급의 현재 합격선 (bravo_levels).
 */
function bravoGradingPassingScore(PDO $db, int $level): float {
    $stmt = $db->prepare("SELECT passing_score FROM bravo_levels WHERE level = ?");
    $stmt->execute([$level]);
    return (float)$stmt->fetchColumn();
}

/**
 * 문항 판정 저장 (upsert). 성공: ['score'=>..]+summary, 실패: ['error'=>msg].
 */
function bravoGradeSave(PDO $db, array $attempt, array $exam, int $answerId, array $input, int $adminId): array {
    if (($attempt['status'] ?? '') !== 'submitted') return ['error' => '제출된 응시만 채점할 수 있습니다.'];
    if (bravoAttemptGradeGet($db, (int)$attempt['id'])) return ['error' => '확정된 채점입니다. 확정 취소 후 수정하세요.'];

    $stmt = $db->prepare("SELECT id, question_id FROM bravo_answers WHERE id = ? AND attempt_id = ?");
    $stmt->execute([$answerId, (int)$attempt['id']]);
    $ans = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ans) return ['error' => '답안을 찾을 수 없습니다.'];

    $validQids = bravoGradingQuestionIds($db, $attempt);
    if (!in_array((int)$ans['question_id'], $validQids, true)) return ['error' => '채점 대상 문항이 아닙니다.'];

    $level = (int)$exam['bravo_level'];
    $errors = bravoGradeValidate($level, $input);
    if ($errors) return ['error' => implode(' ', $errors)];

    $n = count($validQids);
    $score = bravoGradeScore($level, $n, $input);
    $w = BRAVO_GRADE_WEIGHTS[$level];
    $completion = $w['completion'] > 0 ? (int)$input['completion_ok'] : null;
    $memo = isset($input['memo']) && is_string($input['memo']) && trim($input['memo']) !== '' ? mb_substr(trim($input['memo']), 0, 255) : null;

    $db->prepare("
        INSERT INTO bravo_answer_grades
            (answer_id, attempt_id, accuracy, chunk_ok, response_rating, fluency_rating, completion_ok, score, n_denominator, memo, graded_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            accuracy=VALUES(accuracy), chunk_ok=VALUES(chunk_ok), response_rating=VALUES(response_rating),
            fluency_rating=VALUES(fluency_rating), completion_ok=VALUES(completion_ok),
            score=VALUES(score), n_denominator=VALUES(n_denominator), memo=VALUES(memo), graded_by=VALUES(graded_by)
    ")->execute([
        $answerId, (int)$attempt['id'], $input['accuracy'], (int)$input['chunk_ok'],
        $input['response_rating'], $input['fluency_rating'], $completion, $score, $n, $memo, $adminId,
    ]);

    return ['score' => $score] + bravoGradingSummary($db, $attempt, $level);
}
```

- [ ] **Step 4: 통과 확인**

Run: `cd /root/boot-dev && php tests/bravo_grading_test.php`
Expected: 모든 `PASS`, `결과: N pass, 0 fail`

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/bravo_grading.php tests/bravo_grading_test.php
git commit -m "feat(bravo): 채점 환산 순수함수 + 판정 저장 서비스"
```

---

## Task 3: 확정/취소 + 목록/상세 + cascade

**Files:**
- Modify: `public_html/api/services/bravo_grading.php` (함수 추가)
- Modify: `public_html/api/services/bravo.php` (`bravoExamDelete` cascade)
- Test: `tests/bravo_grading_test.php` (블록 추가)

- [ ] **Step 1: 테스트 추가 (TDD)**

`tests/bravo_grading_test.php`의 `$db->rollBack();` (정상 경로 try 블록 끝) **바로 앞**에 삽입:

```php
    // ── confirm: 미완 거부 → 완료 → 자동 합불·passing_score 스냅샷 ──
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'pass'], 99);
    t('미완 confirm 거부', isset($r['error']) && $r['missing_count'] === 2);

    // 나머지 2문항 판정: q1=0점(이미 재판정으로 wrong), q2/q3 만점 → 총점 0 + 33.33 + 33.33 = 66.66 ≥ 65 → pass
    foreach ([$qids[1], $qids[2]] as $q) {
        bravoGradeSave($db, $attempt, $exam, $answerIds[$q], $J, 99);
    }
    $sum = bravoGradingSummary($db, $attempt, 2);
    t('전 문항 판정 후 auto_result', $sum['auto_result'] === 'pass' && $sum['total_so_far'] === 66.66, json_encode($sum));

    // 오버라이드 사유 누락 거부 (auto=pass 인데 fail 요청)
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'fail'], 99);
    t('오버라이드 사유 누락 거부', isset($r['error']));

    // 정상 확정 (auto 그대로)
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'pass', 'memo' => '전체 메모'], 99);
    t('confirm 정상', !isset($r['error']) && $r['total_score'] === 66.66 && $r['result'] === 'pass', json_encode($r));
    $cg = bravoAttemptGradeGet($db, (int)$attempt['id']);
    t('확정 행 (passing_score 스냅샷·비오버라이드)', $cg && (float)$cg['passing_score'] === 65.0 && (int)$cg['result_overridden'] === 0 && $cg['memo'] === '전체 메모');

    // 확정 후 판정 save 거부 / 중복 확정 거부
    $g = bravoGradeSave($db, $attempt, $exam, $answerIds[$qids[0]], $J, 99);
    t('확정 후 save 거부', isset($g['error']));
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'pass'], 99);
    t('중복 확정 거부', isset($r['error']));

    // cancel → 재확정 (오버라이드: auto=pass 를 fail 로 + 사유)
    $r = bravoAttemptConfirmCancel($db, $attempt, $exam);
    t('cancel 정상 (판정 보존)', !isset($r['error']) && bravoAttemptGradeGet($db, (int)$attempt['id']) === null
        && (int)$db->query("SELECT COUNT(*) FROM bravo_answer_grades WHERE attempt_id=" . (int)$attempt['id'])->fetchColumn() === 3);
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'fail', 'override_reason' => '발음 불명확 재검토'], 99);
    t('오버라이드 확정', !isset($r['error']) && $r['result'] === 'fail');
    $cg = bravoAttemptGradeGet($db, (int)$attempt['id']);
    t('오버라이드 기록', (int)$cg['result_overridden'] === 1 && $cg['override_reason'] === '발음 불명확 재검토');

    // released 후 cancel 거부
    $db->prepare("UPDATE bravo_exams SET status='released' WHERE id=?")->execute([$examId]);
    $examReleased = $db->query("SELECT * FROM bravo_exams WHERE id=" . (int)$examId)->fetch(PDO::FETCH_ASSOC);
    $r = bravoAttemptConfirmCancel($db, $attempt, $examReleased);
    t('released 후 cancel 거부', isset($r['error']));
    $db->prepare("UPDATE bravo_exams SET status='closed' WHERE id=?")->execute([$examId]);

    // ── 문항 삭제 엣지: 유령 grade 제외 + 자동 재환산 ──
    bravoAttemptConfirmCancel($db, $attempt, $db->query("SELECT * FROM bravo_exams WHERE id=" . (int)$examId)->fetch(PDO::FETCH_ASSOC));
    bravoQuestionDelete($db, $qids[2]); // q3 삭제 → N: 3→2, q3 grade 는 유령
    $sum = bravoGradingSummary($db, $attempt, 2);
    t('유령 grade 제외 집계', $sum['total_count'] === 2 && $sum['graded_count'] === 2);
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'pass'], 99);
    // 재환산: q1=wrong(0점, N=2 여도 0), q2=만점 → 100/2=50. 총점 50 ≥ 65? 아니오 → auto=fail. pass 요청은 오버라이드 사유 필요 → 거부
    t('재환산 후 auto 변동 → 오버라이드 사유 요구', isset($r['error']));
    $r = bravoAttemptConfirm($db, $attempt, $exam, ['result' => 'fail'], 99);
    t('재환산 확정 (단일 N)', !isset($r['error']) && $r['total_score'] === 50.0, json_encode($r));
    $regraded = $db->query("SELECT n_denominator FROM bravo_answer_grades g JOIN bravo_answers a ON g.answer_id=a.id WHERE g.attempt_id=" . (int)$attempt['id'] . " AND a.question_id=" . (int)$qids[1])->fetchColumn();
    t('재환산 n_denominator 갱신', (int)$regraded === 2);

    // ── 목록 ──
    $exams = bravoGradingExamList($db);
    $mine = null;
    foreach ($exams as $e) { if ((int)$e['id'] === $examId) $mine = $e; }
    t('exam_list 포함 + 카운트', $mine !== null && (int)$mine['counts']['total'] === 1 && (int)$mine['counts']['confirmed'] === 1);

    $list = bravoGradingAttemptList($db, $examId);
    t('attempt_list', count($list) === 1 && $list[0]['member_name'] === "{$tag}응시자"
        && (int)$list[0]['graded_count'] === 2 && (int)$list[0]['total_count'] === 2
        && $list[0]['confirmed'] !== null && $list[0]['confirmed']['result'] === 'fail');

    // ── detail ──
    $d = bravoGradingDetail($db, $attempt, $exam);
    t('detail items (현존 2문항 + 정답 포함)', count($d['items']) === 2
        && $d['items'][0]['question']['english_text'] === 'answer 1'
        && $d['items'][0]['grade'] !== null && $d['items'][1]['grade'] !== null);
    t('detail 메타', $d['passing_score'] === 65.0 && isset($d['weights']['accuracy']) && $d['confirmed'] !== null);

    // ── cascade: 시험 삭제 → grades 정리 ──
    bravoExamDelete($db, $examId);
    t('시험 삭제 → answer_grades 0건', (int)$db->query("SELECT COUNT(*) FROM bravo_answer_grades WHERE attempt_id=" . (int)$attempt['id'])->fetchColumn() === 0);
    t('시험 삭제 → attempt_grades 0건', (int)$db->query("SELECT COUNT(*) FROM bravo_attempt_grades WHERE attempt_id=" . (int)$attempt['id'])->fetchColumn() === 0);
```

⚠️ 위 confirm 시그니처는 `bravoAttemptConfirm($db, $attempt, $exam, $input, $adminId)` / `bravoAttemptConfirmCancel($db, $attempt, $exam)` — Step 3 구현과 일치시킬 것. 유령 grade 블록의 첫 줄(cancel)은 직전 오버라이드 확정 상태를 풀기 위함.

- [ ] **Step 2: 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_grading_test.php`
Expected: FAIL — `Call to undefined function bravoAttemptConfirm()`.

- [ ] **Step 3: 서비스 함수 추가**

`public_html/api/services/bravo_grading.php` 끝에 추가:

```php
/**
 * 채점 확정. 전 문항(현존 기준) 판정 필수 → N 불일치 grade 자동 재환산 → 유효 grade 합산
 * → passing_score 스냅샷 → 오버라이드 검증 → INSERT.
 * 성공: ['total_score'=>, 'result'=>], 실패: ['error'=>(, 'missing_count'=>)]
 */
function bravoAttemptConfirm(PDO $db, array $attempt, array $exam, array $input, int $adminId): array {
    if (($attempt['status'] ?? '') !== 'submitted') return ['error' => '제출된 응시만 확정할 수 있습니다.'];
    $attemptId = (int)$attempt['id'];
    if (bravoAttemptGradeGet($db, $attemptId)) return ['error' => '이미 확정된 채점입니다.'];

    $level = (int)$exam['bravo_level'];
    $validQids = bravoGradingQuestionIds($db, $attempt);
    $n = count($validQids);
    if ($n === 0) return ['error' => '채점 대상 문항이 없습니다.'];

    $grades = bravoGradingValidGrades($db, $attempt);
    $missing = $n - count($grades);
    if ($missing > 0) return ['error' => "판정되지 않은 문항이 {$missing}개 있습니다.", 'missing_count' => $missing];

    // N 불일치 grade 자동 재환산 (저장된 판정값 + 현재 가중치·N — 한 attempt 안에서 단일 N 보장)
    $upd = $db->prepare("UPDATE bravo_answer_grades SET score = ?, n_denominator = ? WHERE id = ?");
    foreach ($grades as $qid => $g) {
        if ((int)$g['n_denominator'] !== $n) {
            $j = [
                'accuracy' => $g['accuracy'], 'chunk_ok' => (int)$g['chunk_ok'],
                'response_rating' => $g['response_rating'], 'fluency_rating' => $g['fluency_rating'],
                'completion_ok' => $g['completion_ok'] !== null ? (int)$g['completion_ok'] : 0,
            ];
            $score = bravoGradeScore($level, $n, $j);
            $upd->execute([$score, $n, (int)$g['id']]);
            $grades[$qid]['score'] = $score;
        }
    }

    $total = round(array_sum(array_map(fn($g) => (float)$g['score'], $grades)), 2);
    $passing = bravoGradingPassingScore($db, $level);
    $auto = $total >= $passing ? 'pass' : 'fail';

    $result = $input['result'] ?? '';
    if (!in_array($result, ['pass', 'fail'], true)) return ['error' => '합불 판정을 선택해주세요.'];
    $overridden = $result !== $auto;
    $reason = isset($input['override_reason']) && is_string($input['override_reason']) ? trim($input['override_reason']) : '';
    if ($overridden && $reason === '') return ['error' => '자동 판정과 다르게 확정하려면 사유가 필요합니다.'];
    $memo = isset($input['memo']) && is_string($input['memo']) && trim($input['memo']) !== '' ? trim($input['memo']) : null;

    $db->prepare("
        INSERT INTO bravo_attempt_grades
            (attempt_id, total_score, passing_score, result, result_overridden, override_reason, memo, confirmed_by)
        VALUES (?,?,?,?,?,?,?,?)
    ")->execute([
        $attemptId, $total, $passing, $result,
        $overridden ? 1 : 0, $overridden ? mb_substr($reason, 0, 255) : null, $memo, $adminId,
    ]);

    return ['total_score' => $total, 'result' => $result];
}

/**
 * 확정 취소 (released 전만). 판정(answer_grades)은 보존.
 */
function bravoAttemptConfirmCancel(PDO $db, array $attempt, array $exam): array {
    $attemptId = (int)$attempt['id'];
    if (!bravoAttemptGradeGet($db, $attemptId)) return ['error' => '확정된 채점이 없습니다.'];
    if (($exam['status'] ?? '') === 'released') return ['error' => '결과가 발표된 시험은 확정을 취소할 수 없습니다.'];
    $db->prepare("DELETE FROM bravo_attempt_grades WHERE attempt_id = ?")->execute([$attemptId]);
    return ['cancelled' => true];
}

/**
 * 채점 대상 시험 목록 (submitted attempt ≥1) + 카운트.
 */
function bravoGradingExamList(PDO $db): array {
    $rows = $db->query("
        SELECT e.id, e.title, e.bravo_level, e.status,
               COUNT(*) AS total,
               SUM(ag.id IS NOT NULL) AS confirmed_cnt,
               SUM(ag.id IS NULL AND COALESCE(g.c, 0) = 0) AS ungraded_cnt,
               SUM(ag.id IS NULL AND COALESCE(g.c, 0) > 0) AS grading_cnt
        FROM bravo_attempts a
        JOIN bravo_exams e ON a.exam_id = e.id
        LEFT JOIN bravo_attempt_grades ag ON ag.attempt_id = a.id
        LEFT JOIN (SELECT attempt_id, COUNT(*) c FROM bravo_answer_grades GROUP BY attempt_id) g ON g.attempt_id = a.id
        WHERE a.status = 'submitted'
        GROUP BY e.id, e.title, e.bravo_level, e.status
        ORDER BY e.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'title' => $r['title'], 'bravo_level' => (int)$r['bravo_level'], 'status' => $r['status'],
        'counts' => ['total' => (int)$r['total'], 'ungraded' => (int)$r['ungraded_cnt'], 'grading' => (int)$r['grading_cnt'], 'confirmed' => (int)$r['confirmed_cnt']],
    ], $rows);
}

/**
 * 시험의 채점 대상 응시 목록 (submitted). 진행도는 attempt 별 현존 N 기준.
 */
function bravoGradingAttemptList(PDO $db, int $examId): array {
    $stmt = $db->prepare("
        SELECT a.id, a.attempt_no, a.submitted_at, a.question_ids, a.member_id,
               bm.real_name, c.cohort,
               ag.total_score, ag.result
        FROM bravo_attempts a
        JOIN bootcamp_members bm ON a.member_id = bm.id
        JOIN cohorts c ON bm.cohort_id = c.id
        LEFT JOIN bravo_attempt_grades ag ON ag.attempt_id = a.id
        WHERE a.exam_id = ? AND a.status = 'submitted'
        ORDER BY a.submitted_at, a.id
    ");
    $stmt->execute([$examId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $attemptRow = ['id' => (int)$r['id'], 'question_ids' => $r['question_ids']];
        $validQids = bravoGradingQuestionIds($db, $attemptRow);
        $grades = bravoGradingValidGrades($db, $attemptRow);
        $out[] = [
            'attempt_id' => (int)$r['id'],
            'member_name' => $r['real_name'],
            'cohort' => $r['cohort'],
            'attempt_no' => (int)$r['attempt_no'],
            'submitted_at' => $r['submitted_at'],
            'graded_count' => count($grades),
            'total_count' => count($validQids),
            'confirmed' => $r['total_score'] !== null ? ['total_score' => (float)$r['total_score'], 'result' => $r['result']] : null,
        ];
    }
    return $out;
}

/**
 * 채점 상세: 문항 카드 데이터 (정답 포함 — 관리자 전용) + 기존 판정 + 확정 정보.
 */
function bravoGradingDetail(PDO $db, array $attempt, array $exam): array {
    $attemptId = (int)$attempt['id'];
    $level = (int)$exam['bravo_level'];
    $validQids = bravoGradingQuestionIds($db, $attempt);
    $grades = bravoGradingValidGrades($db, $attempt);

    $items = [];
    if ($validQids) {
        $place = implode(',', array_fill(0, count($validQids), '?'));
        $qStmt = $db->prepare("
            SELECT id, question_type, korean_text, english_text, accepted_answers, target_chunks,
                   reference_speech_sec, response_time_limit_sec
            FROM bravo_questions WHERE id IN ($place)
        ");
        $qStmt->execute($validQids);
        $qById = [];
        foreach ($qStmt->fetchAll(PDO::FETCH_ASSOC) as $q) $qById[(int)$q['id']] = $q;

        $aStmt = $db->prepare("SELECT id, question_id, audio_mime, duration_ms, retake_used, answered_at FROM bravo_answers WHERE attempt_id = ?");
        $aStmt->execute([$attemptId]);
        $aByQid = [];
        foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $a) $aByQid[(int)$a['question_id']] = $a;

        foreach ($validQids as $seq => $qid) {
            if (!isset($qById[$qid]) || !isset($aByQid[$qid])) continue;
            $g = $grades[$qid] ?? null;
            $items[] = [
                'answer_id' => (int)$aByQid[$qid]['id'],
                'seq' => $seq,
                'question' => $qById[$qid],
                'answer' => [
                    'audio_mime' => $aByQid[$qid]['audio_mime'],
                    'duration_ms' => $aByQid[$qid]['duration_ms'] !== null ? (int)$aByQid[$qid]['duration_ms'] : null,
                    'retake_used' => (int)$aByQid[$qid]['retake_used'],
                    'answered_at' => $aByQid[$qid]['answered_at'],
                ],
                'grade' => $g ? [
                    'accuracy' => $g['accuracy'], 'chunk_ok' => (int)$g['chunk_ok'],
                    'response_rating' => $g['response_rating'], 'fluency_rating' => $g['fluency_rating'],
                    'completion_ok' => $g['completion_ok'] !== null ? (int)$g['completion_ok'] : null,
                    'score' => (float)$g['score'], 'memo' => $g['memo'],
                ] : null,
            ];
        }
    }

    $confirmed = bravoAttemptGradeGet($db, $attemptId);
    return [
        'attempt' => ['id' => $attemptId, 'attempt_no' => (int)$attempt['attempt_no'], 'submitted_at' => $attempt['submitted_at']],
        'exam' => ['id' => (int)$exam['id'], 'title' => $exam['title'], 'bravo_level' => $level, 'status' => $exam['status']],
        'passing_score' => bravoGradingPassingScore($db, $level),
        'weights' => BRAVO_GRADE_WEIGHTS[$level] ?? null,
        'items' => $items,
        'summary' => bravoGradingSummary($db, $attempt, $level),
        'confirmed' => $confirmed ? [
            'total_score' => (float)$confirmed['total_score'], 'passing_score' => (float)$confirmed['passing_score'],
            'result' => $confirmed['result'], 'result_overridden' => (int)$confirmed['result_overridden'],
            'override_reason' => $confirmed['override_reason'], 'memo' => $confirmed['memo'],
            'confirmed_at' => $confirmed['confirmed_at'],
        ] : null,
    ];
}
```

detail은 회원 이름이 필요 — admin.php case에서 attempt_list와 동일 join으로 1회 조회해 응답에 합치거나, 간단히 case에서 `SELECT bm.real_name, c.cohort FROM bravo_attempts a JOIN bootcamp_members bm ON a.member_id=bm.id JOIN cohorts c ON bm.cohort_id=c.id WHERE a.id=?` 추가 (Task 4 코드에 포함됨).

- [ ] **Step 4: bravoExamDelete cascade 보강**

`public_html/api/services/bravo.php`의 `bravoExamDelete` try 블록 안, `DELETE FROM bravo_answers ...` 줄 **바로 앞**에 2줄 추가 (현재 함수를 읽고 정확한 위치에):

```php
            $db->prepare("DELETE FROM bravo_answer_grades WHERE attempt_id IN ($place)")->execute($attemptIds);
            $db->prepare("DELETE FROM bravo_attempt_grades WHERE attempt_id IN ($place)")->execute($attemptIds);
```

(둘 다 `if ($attemptIds)` 블록 안 — `$place`가 이미 정의된 위치. doc comment의 삭제 대상 나열에 grades 2테이블 추가.)

- [ ] **Step 5: 통과 + 회귀**

Run:
```bash
cd /root/boot-dev && php tests/bravo_grading_test.php && php tests/bravo_attempt_test.php && php tests/bravo_exam_service_test.php && php tests/bravo_member_status_test.php && php tests/bravo_question_test.php
```
Expected: 전부 `결과: N pass, 0 fail`

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/bravo_grading.php public_html/api/services/bravo.php tests/bravo_grading_test.php
git commit -m "feat(bravo): 채점 확정/취소(재환산·passing 스냅샷) + 목록/상세 + grades cascade"
```

---

## Task 4: admin.php API case 6개

**Files:**
- Modify: `public_html/api/admin.php`

- [ ] **Step 1: require_once 추가**

기존 `require_once __DIR__ . '/services/bravo_attempts.php';` 줄 **바로 다음**에:

```php
require_once __DIR__ . '/services/bravo_grading.php';
```

- [ ] **Step 2: case 6개 삽입**

기존 `case 'bravo_exam_question_save':` 블록(`break;`로 끝남) **바로 다음**에 삽입. (공통 헬퍼: attempt 로드는 `bravoAttemptGet`, exam 로드는 명시 컬럼 — `bravoAttemptExamAccess`는 회원용 검증이라 사용하지 않음.)

```php
case 'bravo_grading_exam_list':
    requireAdmin(['operation']);
    $db = getDB();
    jsonSuccess(['exams' => bravoGradingExamList($db)]);
    break;

case 'bravo_grading_attempt_list':
    requireAdmin(['operation']);
    $examId = (isset($_GET['exam_id']) && is_numeric($_GET['exam_id'])) ? (int)$_GET['exam_id'] : 0;
    if ($examId < 1) jsonError('exam_id가 필요합니다.');
    $db = getDB();
    jsonSuccess(['attempts' => bravoGradingAttemptList($db, $examId)]);
    break;

case 'bravo_grading_detail':
    requireAdmin(['operation']);
    $attemptId = (isset($_GET['attempt_id']) && is_numeric($_GET['attempt_id'])) ? (int)$_GET['attempt_id'] : 0;
    if ($attemptId < 1) jsonError('attempt_id가 필요합니다.');
    $db = getDB();
    $attempt = bravoAttemptGet($db, $attemptId);
    if (!$attempt || $attempt['status'] !== 'submitted') jsonError('채점 대상 응시를 찾을 수 없습니다.', 404);
    $exStmt = $db->prepare("SELECT id, title, bravo_level, status FROM bravo_exams WHERE id = ?");
    $exStmt->execute([(int)$attempt['exam_id']]);
    $exam = $exStmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam) jsonError('시험을 찾을 수 없습니다.', 404);
    $mStmt = $db->prepare("
        SELECT bm.real_name, c.cohort FROM bravo_attempts a
        JOIN bootcamp_members bm ON a.member_id = bm.id
        JOIN cohorts c ON bm.cohort_id = c.id WHERE a.id = ?
    ");
    $mStmt->execute([$attemptId]);
    $member = $mStmt->fetch(PDO::FETCH_ASSOC) ?: ['real_name' => null, 'cohort' => null];
    jsonSuccess(bravoGradingDetail($db, $attempt, $exam) + ['member' => ['name' => $member['real_name'], 'cohort' => $member['cohort']]]);
    break;

case 'bravo_answer_grade_save':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $answerId = (isset($input['answer_id']) && is_numeric($input['answer_id'])) ? (int)$input['answer_id'] : 0;
    if ($answerId < 1) jsonError('answer_id가 필요합니다.');
    $db = getDB();
    $aStmt = $db->prepare("SELECT attempt_id FROM bravo_answers WHERE id = ?");
    $aStmt->execute([$answerId]);
    $attemptId = (int)$aStmt->fetchColumn();
    if ($attemptId < 1) jsonError('답안을 찾을 수 없습니다.', 404);
    $attempt = bravoAttemptGet($db, $attemptId);
    $exStmt = $db->prepare("SELECT id, bravo_level FROM bravo_exams WHERE id = ?");
    $exStmt->execute([(int)$attempt['exam_id']]);
    $exam = $exStmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam) jsonError('시험을 찾을 수 없습니다.', 404);
    $r = bravoGradeSave($db, $attempt, $exam, $answerId, $input, (int)$admin['admin_id']);
    if (isset($r['error'])) jsonError($r['error']);
    jsonSuccess($r, '저장되었습니다.');
    break;

case 'bravo_attempt_confirm':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $attemptId = (isset($input['attempt_id']) && is_numeric($input['attempt_id'])) ? (int)$input['attempt_id'] : 0;
    if ($attemptId < 1) jsonError('attempt_id가 필요합니다.');
    $db = getDB();
    $attempt = bravoAttemptGet($db, $attemptId);
    if (!$attempt) jsonError('응시를 찾을 수 없습니다.', 404);
    $exStmt = $db->prepare("SELECT id, title, bravo_level, status FROM bravo_exams WHERE id = ?");
    $exStmt->execute([(int)$attempt['exam_id']]);
    $exam = $exStmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam) jsonError('시험을 찾을 수 없습니다.', 404);
    if (($input['action'] ?? '') === 'cancel') {
        $r = bravoAttemptConfirmCancel($db, $attempt, $exam);
        if (isset($r['error'])) jsonError($r['error']);
        jsonSuccess($r, '확정이 취소되었습니다.');
    }
    $r = bravoAttemptConfirm($db, $attempt, $exam, $input, (int)$admin['admin_id']);
    if (isset($r['error'])) jsonError($r['error']);
    jsonSuccess($r, '확정되었습니다.');
    break;

case 'bravo_answer_audio':
    requireAdmin(['operation']);
    $answerId = (isset($_GET['answer_id']) && is_numeric($_GET['answer_id'])) ? (int)$_GET['answer_id'] : 0;
    if ($answerId < 1) jsonError('answer_id가 필요합니다.');
    $db = getDB();
    $aStmt = $db->prepare("SELECT audio_path, audio_mime FROM bravo_answers WHERE id = ?");
    $aStmt->execute([$answerId]);
    $row = $aStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jsonError('답안을 찾을 수 없습니다.', 404);
    $path = BRAVO_UPLOAD_ROOT . '/' . $row['audio_path'];
    $real = realpath($path);
    $root = realpath(BRAVO_UPLOAD_ROOT);
    if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        jsonError('녹음 파일이 없습니다.', 404);
    }
    $size = (int)filesize($real);
    // JSON 아님 — 바이너리 스트리밍 (admin.php 상단의 JSON Content-Type 을 덮어씀)
    header('Content-Type: ' . $row['audio_mime']);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600');
    $range = bravoAudioRangeParse($_SERVER['HTTP_RANGE'] ?? null, $size);
    if ($range !== null) {
        [$start, $end] = $range;
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
        header('Content-Length: ' . ($end - $start + 1));
        $fp = fopen($real, 'rb');
        fseek($fp, $start);
        echo fread($fp, $end - $start + 1);
        fclose($fp);
    } else {
        header('Content-Length: ' . $size);
        readfile($real);
    }
    exit;
```

⚠️ admin.php 상단의 기존 `header('Content-Type: application/json...')` 존재 여부 확인 — 동일 헤더는 후속 `header()`가 교체하므로 audio case의 header가 이김. `$admin['admin_id']` 키는 기존 case(예: `bravo_exam_save`)의 실제 사용과 일치시킬 것.

- [ ] **Step 3: 문법 검사 + 전체 회귀**

Run:
```bash
cd /root/boot-dev && php -l public_html/api/admin.php && php -l public_html/api/services/bravo_grading.php
cd /root/boot-dev && for f in bravo_schema_invariants bravo_qualification_test bravo_admin_service_test bravo_exam_validate_test bravo_exam_service_test bravo_exams_schema_invariants bravo_member_status_test bravo_questions_schema_invariants bravo_question_test bravo_ot_test bravo_exam_questions_schema_invariants bravo_exam_question_test bravo_attempts_schema_invariants bravo_attempt_test bravo_grades_schema_invariants bravo_grading_test; do echo "== $f =="; php tests/$f.php | tail -1; done
```
Expected: 16파일 전부 `결과: N pass, 0 fail`

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/admin.php
git commit -m "feat(bravo): 채점 admin API case (목록/상세/판정저장/확정/오디오 Range 스트리밍)"
```

---

## Task 5: 프론트 — 채점 서브탭

**Files:**
- Create: `public_html/js/admin-bravo-grading.js`
- Modify: `public_html/js/admin-bravo.js`
- Modify: `public_html/operation/index.php`

먼저 `public_html/js/admin-bravo.js` 전체(135줄 — 셸 구조)와 `admin-bravo-exams.js`의 App/Toast 사용 패턴 확인.

- [ ] **Step 1: admin-bravo.js 셸에 4번째 서브탭 추가**

`init()`의 `root.innerHTML` 템플릿에서 `<button ... data-sub="questions">문제은행</button>` 다음에:

```javascript
                <button class="bravo-subtab" data-sub="grading">채점</button>
```

`<div class="bravo-sub" id="bravo-sub-questions" ...></div>` 다음에:

```javascript
            <div class="bravo-sub" id="bravo-sub-grading" style="display:none"></div>
```

모듈 상단 `let questionsMounted = false;` 다음에 `let gradingMounted = false;` 추가, `init()`의 `questionsMounted = false;` 다음에 `gradingMounted = false;` 추가.

`switchSub()`에 display 토글 1줄과 lazy-mount 블록 추가 (기존 questions 패턴 그대로):

```javascript
        root.querySelector('#bravo-sub-grading').style.display = sub === 'grading' ? '' : 'none';
```
```javascript
        if (sub === 'grading' && !gradingMounted && typeof AdminBravoGradingApp !== 'undefined') {
            gradingMounted = true;
            AdminBravoGradingApp.init(admin, 'bravo-sub-grading');
        }
```

- [ ] **Step 2: admin-bravo-grading.js 작성**

Create `public_html/js/admin-bravo-grading.js`:

```javascript
/* ── BRAVO 채점 (operation) — 응시 목록 → 녹음 듣고 문항별 판정 → 자동 환산 → 확정 ── */
const AdminBravoGradingApp = (() => {
    let admin = null;
    let root = null;
    let exams = [];
    let currentExamId = 0;
    let attempts = [];
    let detail = null;           // bravo_grading_detail 응답
    let currentAttemptId = 0;
    let pending = {};            // answer_id → 진행 중 판정 선택값 (저장 전)

    const ACCURACY = [['correct', '정답'], ['partial', '부분'], ['wrong', '오답']];
    const RATING = [['good', '좋음'], ['normal', '보통'], ['poor', '미흡']];

    async function init(adminSession, containerId) {
        admin = adminSession;
        root = document.getElementById(containerId);
        if (!root) return;
        await loadExams();
        renderList();
    }

    async function loadExams() {
        const r = await App.get('/api/admin.php?action=bravo_grading_exam_list');
        exams = (r && r.success !== false) ? (r.exams || []) : [];
    }

    async function loadAttempts(examId) {
        const r = await App.get('/api/admin.php?action=bravo_grading_attempt_list&exam_id=' + examId);
        attempts = (r && r.success !== false) ? (r.attempts || []) : [];
    }

    function statusChip(a) {
        if (a.confirmed) return `<span class="grading-chip done">확정 ${a.confirmed.total_score}점 · ${a.confirmed.result === 'pass' ? '합격' : '불합격'}</span>`;
        if (a.graded_count > 0) return `<span class="grading-chip ing">채점중 ${a.graded_count}/${a.total_count}</span>`;
        return '<span class="grading-chip none">미채점</span>';
    }

    function renderList() {
        const opts = exams.map(e =>
            `<option value="${e.id}" ${e.id === currentExamId ? 'selected' : ''}>[BRAVO ${e.bravo_level}] ${App.esc(e.title)} (${e.status}) — 응시 ${e.counts.total} · 미채점 ${e.counts.ungraded} · 채점중 ${e.counts.grading} · 확정 ${e.counts.confirmed}</option>`).join('');
        const rows = attempts.map(a => `
            <tr>
                <td>${App.esc(a.member_name || '')}<br><small>${App.esc(a.cohort || '')}</small></td>
                <td class="num">${a.attempt_no}회차</td>
                <td>${App.esc((a.submitted_at || '').slice(0, 16))}</td>
                <td>${statusChip(a)}</td>
                <td><button class="btn btn-primary btn-sm grading-open" data-id="${a.attempt_id}">채점</button></td>
            </tr>`).join('');

        root.innerHTML = `
            <div class="bravo-grading">
                <div class="grading-toolbar">
                    <select id="grading-exam">
                        <option value="0">시험 선택 (제출된 응시가 있는 시험만)</option>${opts}
                    </select>
                </div>
                <table class="data-table grading-table">
                    <thead><tr><th>회원</th><th>회차</th><th>제출일시</th><th>상태</th><th></th></tr></thead>
                    <tbody>${rows || '<tr><td colspan="5">시험을 선택하세요.</td></tr>'}</tbody>
                </table>
                <div id="grading-detail"></div>
            </div>`;

        root.querySelector('#grading-exam').addEventListener('change', async (e) => {
            currentExamId = parseInt(e.target.value, 10) || 0;
            detail = null; currentAttemptId = 0;
            if (currentExamId) await loadAttempts(currentExamId); else attempts = [];
            renderList();
        });
        root.querySelectorAll('.grading-open').forEach(b =>
            b.addEventListener('click', () => openDetail(parseInt(b.dataset.id, 10))));
    }

    async function openDetail(attemptId) {
        const r = await App.get('/api/admin.php?action=bravo_grading_detail&attempt_id=' + attemptId);
        if (!r || r.success === false) return;
        detail = r;
        currentAttemptId = attemptId;
        pending = {};
        renderDetail();
    }

    function judgeBtns(answerId, field, options, selected) {
        return options.map(([val, label]) =>
            `<button class="btn btn-sm grading-judge ${selected === val ? 'on btn-primary' : ''}" data-answer="${answerId}" data-field="${field}" data-val="${val}">${label}</button>`).join('');
    }

    function toggleBtns(answerId, field, selected) {
        return [[1, '예'], [0, '아니오']].map(([val, label]) =>
            `<button class="btn btn-sm grading-judge ${selected === val ? 'on btn-primary' : ''}" data-answer="${answerId}" data-field="${field}" data-val="${val}">${label}</button>`).join('');
    }

    function itemCard(item, level, confirmed) {
        const q = item.question, g = item.grade;
        const sel = pending[item.answer_id] || (g ? {
            accuracy: g.accuracy, chunk_ok: g.chunk_ok,
            response_rating: g.response_rating, fluency_rating: g.fluency_rating,
            completion_ok: g.completion_ok,
        } : {});
        const dur = item.answer.duration_ms != null ? (item.answer.duration_ms / 1000).toFixed(1) + 's' : '';
        const ro = confirmed ? 'disabled' : '';
        return `
            <div class="grading-card" data-answer="${item.answer_id}">
                <div class="grading-q">
                    <strong>#${item.seq + 1} 유형${q.question_type}</strong> ${App.esc(q.korean_text || '')}
                    <div class="grading-answer-key">
                        <span>기준: ${App.esc(q.english_text || '')}</span>
                        ${q.accepted_answers ? `<span>허용: ${App.esc(q.accepted_answers)}</span>` : ''}
                        ${q.target_chunks ? `<span>청크: ${App.esc(q.target_chunks)}</span>` : ''}
                    </div>
                </div>
                <div class="grading-audio">
                    <audio controls preload="none" src="/api/admin.php?action=bravo_answer_audio&answer_id=${item.answer_id}"></audio>
                    <small>${dur}${item.answer.retake_used ? ' · 재녹음' : ''}</small>
                </div>
                <fieldset class="grading-judges" ${ro}>
                    <div>정답도: ${judgeBtns(item.answer_id, 'accuracy', ACCURACY, sel.accuracy)}</div>
                    <div>청크: ${toggleBtns(item.answer_id, 'chunk_ok', sel.chunk_ok)}</div>
                    <div>반응: ${judgeBtns(item.answer_id, 'response_rating', RATING, sel.response_rating)}</div>
                    <div>유창: ${judgeBtns(item.answer_id, 'fluency_rating', RATING, sel.fluency_rating)}</div>
                    ${level >= 2 ? `<div>완성: ${toggleBtns(item.answer_id, 'completion_ok', sel.completion_ok)}</div>` : ''}
                </fieldset>
                <div class="grading-score">${g ? `점수 <strong>${g.score}</strong>` : '<em>미판정</em>'}</div>
                <input type="text" class="grading-memo" data-answer="${item.answer_id}" value="${App.esc((g && g.memo) || '')}" placeholder="문항 메모" maxlength="255" ${confirmed ? 'disabled' : ''}>
            </div>`;
    }

    function renderDetail() {
        const host = root.querySelector('#grading-detail');
        if (!host || !detail) return;
        const level = detail.exam.bravo_level;
        const confirmed = detail.confirmed;
        const s = detail.summary;
        const autoText = s.auto_result === null
            ? `판정 ${s.graded_count}/${s.total_count} — 전 문항 판정 후 확정 가능`
            : `총점 ${s.total_so_far} / 합격선 ${detail.passing_score} → 자동 판정: ${s.auto_result === 'pass' ? '합격' : '불합격'}`;
        const cards = detail.items.map(it => itemCard(it, level, !!confirmed)).join('');

        host.innerHTML = `
            <div class="grading-panel">
                <h4>${App.esc(detail.member.name || '')} (${App.esc(detail.member.cohort || '')}) — ${App.esc(detail.exam.title)} ${detail.attempt.attempt_no}회차</h4>
                <p class="grading-progress" id="grading-progress">${App.esc(autoText)}</p>
                ${cards || '<p>채점 대상 문항이 없습니다.</p>'}
                <div class="grading-confirm">
                    ${confirmed ? `
                        <p>✅ 확정: <strong>${confirmed.total_score}점</strong> / 합격선 ${confirmed.passing_score} → <strong>${confirmed.result === 'pass' ? '합격' : '불합격'}</strong>
                        ${confirmed.result_overridden ? ` (오버라이드: ${App.esc(confirmed.override_reason || '')})` : ''}
                        · ${App.esc((confirmed.confirmed_at || '').slice(0, 16))}</p>
                        ${confirmed.memo ? `<p>메모: ${App.esc(confirmed.memo)}</p>` : ''}
                        ${detail.exam.status !== 'released' ? '<button class="btn" id="grading-cancel">확정 취소</button>' : '<p><small>발표된 시험 — 취소 불가</small></p>'}
                    ` : `
                        <label>합불: <select id="grading-result">
                            <option value="">자동 판정 따름</option>
                            <option value="pass">합격</option>
                            <option value="fail">불합격</option>
                        </select></label>
                        <input type="text" id="grading-reason" placeholder="자동 판정과 다르면 사유 필수" maxlength="255" style="display:none">
                        <input type="text" id="grading-memo-all" placeholder="전체 메모 (선택)" maxlength="500">
                        <button class="btn btn-primary" id="grading-confirm-btn" ${s.auto_result === null ? 'disabled' : ''}>확정</button>
                    `}
                    <button class="btn" id="grading-close">목록으로</button>
                </div>
            </div>`;

        // 파일 유실(404) 시 카드에 안내 — 판정은 가능 (스펙 §7)
        host.querySelectorAll('.grading-audio audio').forEach(a =>
            a.addEventListener('error', () => {
                if (!a.nextElementSibling || !a.nextElementSibling.classList.contains('grading-audio-missing')) {
                    a.insertAdjacentHTML('afterend', '<small class="grading-audio-missing">⚠️ 녹음 파일 없음 — 판정은 가능</small>');
                }
            }));

        if (!confirmed) {
            host.querySelectorAll('.grading-judge').forEach(b => b.addEventListener('click', onJudge));
            host.querySelectorAll('.grading-memo').forEach(inp => inp.addEventListener('blur', onMemoBlur));
            const resultSel = host.querySelector('#grading-result');
            resultSel.addEventListener('change', () => {
                const auto = detail.summary.auto_result;
                const v = resultSel.value;
                host.querySelector('#grading-reason').style.display = (v && v !== auto) ? '' : 'none';
            });
            host.querySelector('#grading-confirm-btn').addEventListener('click', onConfirm);
        } else {
            const cancelBtn = host.querySelector('#grading-cancel');
            if (cancelBtn) cancelBtn.addEventListener('click', onCancelConfirm);
        }
        host.querySelector('#grading-close').addEventListener('click', async () => {
            detail = null; currentAttemptId = 0;
            await loadExams();
            if (currentExamId) await loadAttempts(currentExamId);
            renderList();
        });
    }

    function judgmentComplete(sel, level) {
        return ['accuracy', 'chunk_ok', 'response_rating', 'fluency_rating']
            .every(k => sel[k] !== undefined && sel[k] !== null)
            && (level < 2 || (sel.completion_ok !== undefined && sel.completion_ok !== null));
    }

    async function onJudge(e) {
        const b = e.currentTarget;
        const answerId = parseInt(b.dataset.answer, 10);
        const field = b.dataset.field;
        const val = (field === 'chunk_ok' || field === 'completion_ok') ? parseInt(b.dataset.val, 10) : b.dataset.val;
        const item = detail.items.find(i => i.answer_id === answerId);
        if (!item) return;
        const level = detail.exam.bravo_level;
        const base = item.grade ? {
            accuracy: item.grade.accuracy, chunk_ok: item.grade.chunk_ok,
            response_rating: item.grade.response_rating, fluency_rating: item.grade.fluency_rating,
            completion_ok: item.grade.completion_ok,
        } : {};
        pending[answerId] = Object.assign({}, base, pending[answerId] || {}, { [field]: val });

        if (!judgmentComplete(pending[answerId], level)) { renderDetail(); return; }

        const memoEl = root.querySelector(`.grading-memo[data-answer="${answerId}"]`);
        const payload = Object.assign({ answer_id: answerId, memo: memoEl ? memoEl.value : '' }, pending[answerId]);
        const r = await App.post('/api/admin.php?action=bravo_answer_grade_save', payload);
        if (!r || r.success === false) { renderDetail(); return; }
        item.grade = Object.assign({}, pending[answerId], { score: r.score, memo: payload.memo || null });
        delete pending[answerId];
        detail.summary = { graded_count: r.graded_count, total_count: r.total_count, total_so_far: r.total_so_far, auto_result: r.auto_result };
        renderDetail();
    }

    async function onMemoBlur(e) {
        const inp = e.currentTarget;
        const answerId = parseInt(inp.dataset.answer, 10);
        const item = detail.items.find(i => i.answer_id === answerId);
        if (!item || !item.grade) return; // 판정 전 메모는 판정 저장 시 함께 전송됨
        if ((item.grade.memo || '') === inp.value) return;
        const payload = Object.assign({ answer_id: answerId, memo: inp.value }, {
            accuracy: item.grade.accuracy, chunk_ok: item.grade.chunk_ok,
            response_rating: item.grade.response_rating, fluency_rating: item.grade.fluency_rating,
            completion_ok: item.grade.completion_ok,
        });
        const r = await App.post('/api/admin.php?action=bravo_answer_grade_save', payload);
        if (r && r.success !== false) item.grade.memo = inp.value || null;
    }

    async function onConfirm() {
        const host = root.querySelector('#grading-detail');
        const btn = host.querySelector('#grading-confirm-btn');
        btn.disabled = true;
        const auto = detail.summary.auto_result;
        const chosen = host.querySelector('#grading-result').value || auto;
        const payload = {
            attempt_id: currentAttemptId,
            result: chosen,
            override_reason: host.querySelector('#grading-reason').value,
            memo: host.querySelector('#grading-memo-all').value,
        };
        const r = await App.post('/api/admin.php?action=bravo_attempt_confirm', payload);
        if (!r || r.success === false) { btn.disabled = false; return; }
        Toast.success(`확정되었습니다 (${r.total_score}점 · ${r.result === 'pass' ? '합격' : '불합격'}).`);
        await openDetail(currentAttemptId);
    }

    async function onCancelConfirm() {
        const r = await App.post('/api/admin.php?action=bravo_attempt_confirm', { attempt_id: currentAttemptId, action: 'cancel' });
        if (!r || r.success === false) return;
        Toast.success('확정이 취소되었습니다.');
        await openDetail(currentAttemptId);
    }

    return { init };
})();
```

IMPORTANT: 실제 셸/패턴과 어긋나면 실제 구조에 맞추고 보고. 기존 자격/시험/문제은행 서브탭 동작 보존.

- [ ] **Step 3: operation/index.php include 추가**

`<script src="/js/admin-bravo.js...` 줄 **바로 앞**에:

```php
    <script src="/js/admin-bravo-grading.js<?= v('/js/admin-bravo-grading.js') ?>"></script>
```

- [ ] **Step 4: 문법 검사 + 커밋**

Run:
```bash
cd /root/boot-dev && node --check public_html/js/admin-bravo-grading.js && node --check public_html/js/admin-bravo.js && php -l public_html/operation/index.php
```
Expected: JS 출력 없음, PHP `No syntax errors detected`.

```bash
cd /root/boot-dev && git add public_html/js/admin-bravo-grading.js public_html/js/admin-bravo.js public_html/operation/index.php
git commit -m "feat(bravo): 채점 서브탭 프론트 (녹음 재생·문항 판정·자동 환산·확정)"
```

- [ ] **Step 5: 브라우저 통합 검증 (controller/사용자)**

https://dev-boot.soritune.com 운영 SPA → BRAVO → 채점: 시험 선택 → 응시 행 [채점] → 오디오 재생(scrub 포함) → 판정 클릭(완성 시 자동 저장·점수 표시) → 실시간 총점 → [확정] → 재진입 읽기 전용 → [확정 취소] → 재확정. (slice6 검증에서 만든 제출 데이터 사용. 구현 subagent는 브라우저 불필요.)

---

## 최종 통합 검증 (전 태스크 완료 후)

- [ ] **전체 BRAVO 테스트 재실행 (16파일, 회귀 0)**

```bash
cd /root/boot-dev && for f in bravo_schema_invariants bravo_qualification_test bravo_admin_service_test bravo_exam_validate_test bravo_exam_service_test bravo_exams_schema_invariants bravo_member_status_test bravo_questions_schema_invariants bravo_question_test bravo_ot_test bravo_exam_questions_schema_invariants bravo_exam_question_test bravo_attempts_schema_invariants bravo_attempt_test bravo_grades_schema_invariants bravo_grading_test; do echo "== $f =="; php tests/$f.php | tail -1; done
```
Expected: 모든 파일 `결과: N pass, 0 fail`

- [ ] **dev push (운영 미반영)**

```bash
cd /root/boot-dev && git push origin dev
```

⛔ **여기서 멈춤.** 운영(main) 반영은 BRAVO 전체 완성 시 1회 일괄 — 사용자 명시 요청 시에만.

---

## 미적용 (다음 슬라이스)

결과 발표(released 시 회원 공개·불합격 재응시 해제·bravo_grade 전환/grandfather 정책), 인증서, STT·자동평가·재채점(2차), 응시횟수 복구·오류신고(2차), 자동출제, 전체 BRAVO UI 스타일링(slice1~7 누적 — `.grading-*` 포함).
