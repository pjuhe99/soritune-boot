# BRAVO 문제은행 + OT 관리 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 관리자가 BRAVO 문제은행에 문제를 단건 CRUD 하고, 각 시험에 OT(오리엔테이션) 콘텐츠를 1:1로 작성할 수 있게 한다.

**Architecture:** 순수 추가형. 신규 테이블 2개(`bravo_questions` standalone 문제은행, `bravo_exam_ot` 시험 1:1). 문제은행 도메인은 신규 서비스 파일 `bravo_questions.php`로 분리, OT는 시험과 강결합이라 기존 `bravo.php`에 추가. `admin.php` 얇은 case → 서비스 위임. 프론트는 기존 BRAVO 서브탭 셸 확장(문제은행=신규 서브탭, OT=시험관리 탭 내 per-exam 편집).

**Tech Stack:** PHP 8 + PDO(MariaDB), vanilla JS SPA, 커스텀 `t()` 테스트 러너(CLI, DEV DB 트랜잭션 롤백). 작업/검증은 **DEV(`/root/boot-dev`, dev 브랜치, DB SORITUNECOM_DEV_BOOT)** 에서만.

**참조 스펙:** `docs/superpowers/specs/2026-06-02-bravo-question-bank-ot-design.md`

---

## File Structure

- **Create** `migrate_bravo_questions.php` — 멱등 마이그(두 테이블 생성).
- **Create** `public_html/api/services/bravo_questions.php` — 문제은행 검증 순수함수 + CRUD.
- **Modify** `public_html/api/services/bravo.php` — OT 순수함수/CRUD 추가, `bravoExamDelete` OT 정리 보강.
- **Modify** `public_html/api/admin.php` — bravo_questions require_once + 신규 case 5개.
- **Create** `public_html/js/admin-bravo-questions.js` — 문제은행 서브탭 앱.
- **Modify** `public_html/js/admin-bravo.js` — 셸에 `문제은행` 서브탭 추가.
- **Modify** `public_html/js/admin-bravo-exams.js` — 시험 행에 OT 버튼 + OT 편집 폼.
- **Modify** `public_html/operation/index.php` — `admin-bravo-questions.js` include 추가(`v()` 가 mtime 기반이라 cache-buster 자동).
- **Create** `tests/bravo_questions_schema_invariants.php`, `tests/bravo_question_test.php`, `tests/bravo_ot_test.php`.

---

## Task 1: 마이그레이션 (bravo_questions + bravo_exam_ot)

**Files:**
- Create: `migrate_bravo_questions.php`
- Test: `tests/bravo_questions_schema_invariants.php`

- [ ] **Step 1: 마이그레이션 파일 작성**

Create `migrate_bravo_questions.php`:

```php
<?php
/**
 * Migration: BRAVO 4차 슬라이스 — bravo_questions(문제은행) + bravo_exam_ot(시험별 OT)
 * 실행: php migrate_bravo_questions.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS. 추가형(기존 테이블 미수정).
 */
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: bravo_questions + bravo_exam_ot ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_questions (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_type           TINYINT UNSIGNED NOT NULL COMMENT '유형 1/2/3',
    bravo_level             TINYINT UNSIGNED NOT NULL COMMENT '1/2/3 (bravo_levels.level)',
    source                  VARCHAR(60) DEFAULT NULL COMMENT '출제 원천 (자유텍스트)',
    korean_text             TEXT NOT NULL COMMENT '한국어 제시 문장',
    english_text            TEXT NOT NULL COMMENT '기준 영어 정답 문장',
    target_chunks           VARCHAR(255) DEFAULT NULL COMMENT '타겟 청크',
    accepted_answers        TEXT DEFAULT NULL COMMENT '허용 정답 (1줄 1개)',
    reference_speech_sec    DECIMAL(4,1) DEFAULT NULL COMMENT '기준 발화 시간(초)',
    response_time_limit_sec DECIMAL(4,1) DEFAULT NULL COMMENT '반응 속도 기준(초)',
    difficulty              ENUM('easy','normal','hard') NOT NULL DEFAULT 'normal' COMMENT '쉬움/보통/어려움',
    is_active               TINYINT(1) NOT NULL DEFAULT 1 COMMENT '활성/비활성',
    created_by              INT UNSIGNED DEFAULT NULL COMMENT '생성 admin id',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_bq_type_level (question_type, bravo_level),
    KEY idx_bq_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_questions 생성 완료\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_exam_ot (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id       INT UNSIGNED NOT NULL COMMENT 'bravo_exams.id (1:1)',
    title         VARCHAR(120) DEFAULT NULL COMMENT 'OT 제목',
    intro_text    TEXT DEFAULT NULL COMMENT '전체 시험 안내문',
    video_url     VARCHAR(500) DEFAULT NULL COMMENT 'OT 영상 URL (선택)',
    type1_text    TEXT DEFAULT NULL COMMENT '유형 1 안내문',
    type2_text    TEXT DEFAULT NULL COMMENT '유형 2 안내문',
    type3_text    TEXT DEFAULT NULL COMMENT '유형 3 안내문',
    require_check TINYINT(1) NOT NULL DEFAULT 1 COMMENT '필수 확인 체크 ON/OFF',
    updated_by    INT UNSIGNED DEFAULT NULL COMMENT '마지막 수정 admin id',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bravo_exam_ot_exam (exam_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_exam_ot 생성 완료\n";

echo "\n=== Migration 완료 ===\n";
```

- [ ] **Step 2: DEV DB에 마이그 적용**

Run: `cd /root/boot-dev && php migrate_bravo_questions.php`
Expected 출력:
```
bravo_questions 생성 완료
bravo_exam_ot 생성 완료

=== Migration 완료 ===
```

- [ ] **Step 3: 스키마 불변식 테스트 작성**

Create `tests/bravo_questions_schema_invariants.php`:

```php
<?php
/**
 * bravo_questions + bravo_exam_ot 스키마 불변식. DEV DB.
 * 사용: php tests/bravo_questions_schema_invariants.php
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

$qCols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_questions") as $c) $qCols[$c['Field']] = $c;
foreach (['id','question_type','bravo_level','source','korean_text','english_text','target_chunks',
          'accepted_answers','reference_speech_sec','response_time_limit_sec','difficulty','is_active','created_by'] as $col) {
    t("bravo_questions.{$col} 존재", isset($qCols[$col]));
}
t('difficulty ENUM', strpos($qCols['difficulty']['Type'], "enum('easy','normal','hard')") !== false, $qCols['difficulty']['Type']);
t('korean_text NOT NULL', $qCols['korean_text']['Null'] === 'NO');
t('english_text NOT NULL', $qCols['english_text']['Null'] === 'NO');
t('is_active 기본 1', (string)$qCols['is_active']['Default'] === '1');

$oCols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_exam_ot") as $c) $oCols[$c['Field']] = $c;
foreach (['id','exam_id','title','intro_text','video_url','type1_text','type2_text','type3_text','require_check'] as $col) {
    t("bravo_exam_ot.{$col} 존재", isset($oCols[$col]));
}
$idx = $db->query("SHOW INDEX FROM bravo_exam_ot WHERE Key_name='uk_bravo_exam_ot_exam'")->fetchAll();
t('exam_id UNIQUE 인덱스', count($idx) === 1 && (int)$idx[0]['Non_unique'] === 0);

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 4: 테스트 실행 → 통과 확인**

Run: `cd /root/boot-dev && php tests/bravo_questions_schema_invariants.php`
Expected: 모든 라인 `PASS`, 마지막 `결과: N pass, 0 fail`

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add migrate_bravo_questions.php tests/bravo_questions_schema_invariants.php
git commit -m "feat(bravo): 문제은행+OT 마이그레이션 (bravo_questions, bravo_exam_ot)"
```

---

## Task 2: 문제은행 검증/정규화 순수함수

**Files:**
- Create: `public_html/api/services/bravo_questions.php`
- Test: `tests/bravo_question_test.php` (이 태스크에선 순수함수 블록만)

- [ ] **Step 1: 순수함수 테스트 작성**

Create `tests/bravo_question_test.php`:

```php
<?php
/**
 * BRAVO 문제은행 서비스 테스트. 순수함수 + DEV DB 통합(트랜잭션 롤백).
 * 사용: php tests/bravo_question_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/bravo_questions.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// ── 순수: bravoQuestionValidate ──
$valid = ['question_type'=>1,'bravo_level'=>1,'korean_text'=>'안녕','english_text'=>'hi','difficulty'=>'easy'];
t('유효 입력 통과', bravoQuestionValidate($valid) === []);
t('type 범위 밖', in_array('문제 유형은 1/2/3 중 하나여야 합니다.', bravoQuestionValidate(['question_type'=>4]+$valid), true));
t('level 범위 밖', in_array('BRAVO 등급은 1/2/3 중 하나여야 합니다.', bravoQuestionValidate(['bravo_level'=>9]+$valid), true));
t('korean 빈값', in_array('한국어 문장을 입력해주세요.', bravoQuestionValidate(['korean_text'=>'  ']+$valid), true));
t('english 빈값', in_array('기준 영어 문장을 입력해주세요.', bravoQuestionValidate(['english_text'=>'']+$valid), true));
t('difficulty 무효', in_array('난이도가 올바르지 않습니다.', bravoQuestionValidate(['difficulty'=>'x']+$valid), true));
t('reference_speech_sec 음수', count(bravoQuestionValidate(['reference_speech_sec'=>-1]+$valid)) === 1);
t('reference_speech_sec 빈값 허용', bravoQuestionValidate(['reference_speech_sec'=>'']+$valid) === []);

// ── 순수: bravoQuestionPersistData ──
$p = bravoQuestionPersistData([
    'question_type'=>'2','bravo_level'=>'3','source'=>'  줌특강 PPT ','korean_text'=>'  한 ','english_text'=>' en ',
    'target_chunks'=>'','accepted_answers'=>" a\nb ",'reference_speech_sec'=>'4.5','response_time_limit_sec'=>'',
    'difficulty'=>'hard','is_active'=>'1',
]);
t('persist type 캐스팅', $p['question_type'] === 2);
t('persist source trim', $p['source'] === '줌특강 PPT');
t('persist korean trim', $p['korean_text'] === '한');
t('persist 빈 target_chunks → null', $p['target_chunks'] === null);
t('persist accepted_answers trim 보존', $p['accepted_answers'] === "a\nb");
t('persist ref_sec 숫자', $p['reference_speech_sec'] === 4.5);
t('persist 빈 resp_sec → null', $p['response_time_limit_sec'] === null);
t('persist difficulty', $p['difficulty'] === 'hard');
t('persist is_active 1', $p['is_active'] === 1);
$p2 = bravoQuestionPersistData(['difficulty'=>'bogus']);
t('persist difficulty 기본 normal', $p2['difficulty'] === 'normal');
t('persist is_active 미전달 → 0', $p2['is_active'] === 0);

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 테스트 실행 → 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_question_test.php`
Expected: FAIL — `Call to undefined function bravoQuestionValidate()` (파일/함수 미존재)

- [ ] **Step 3: 서비스 파일에 순수함수 구현**

Create `public_html/api/services/bravo_questions.php`:

```php
<?php
/**
 * BRAVO 문제은행 서비스 (4차 슬라이스).
 * 검증/정규화 순수함수 + CRUD. 기존 BRAVO 경로와 무관한 추가형.
 */

/** 유효 난이도 집합. */
function bravoQuestionDifficulties(): array {
    return ['easy', 'normal', 'hard'];
}

/**
 * 문제 입력 검증. 에러 메시지 배열 반환(빈=통과). 순수.
 */
function bravoQuestionValidate(array $d): array {
    $errors = [];

    $type = isset($d['question_type']) ? (int)$d['question_type'] : 0;
    if (!in_array($type, [1,2,3], true)) $errors[] = '문제 유형은 1/2/3 중 하나여야 합니다.';

    $level = isset($d['bravo_level']) ? (int)$d['bravo_level'] : 0;
    if (!in_array($level, [1,2,3], true)) $errors[] = 'BRAVO 등급은 1/2/3 중 하나여야 합니다.';

    $ko = isset($d['korean_text']) && is_string($d['korean_text']) ? trim($d['korean_text']) : '';
    if ($ko === '') $errors[] = '한국어 문장을 입력해주세요.';

    $en = isset($d['english_text']) && is_string($d['english_text']) ? trim($d['english_text']) : '';
    if ($en === '') $errors[] = '기준 영어 문장을 입력해주세요.';

    $diff = $d['difficulty'] ?? 'normal';
    if (!in_array($diff, bravoQuestionDifficulties(), true)) $errors[] = '난이도가 올바르지 않습니다.';

    foreach (['reference_speech_sec' => '기준 발화 시간', 'response_time_limit_sec' => '반응 속도 기준'] as $k => $label) {
        if (isset($d[$k]) && $d[$k] !== '' && $d[$k] !== null) {
            if (!is_numeric($d[$k]) || (float)$d[$k] < 0) $errors[] = "{$label}은(는) 0 이상의 숫자여야 합니다.";
        }
    }

    return $errors;
}

/**
 * 폼 입력 → bravo_questions 저장용 정규화 컬럼 배열. 순수.
 * 빈 문자열은 NULL(텍스트/숫자), difficulty 기본 normal, is_active 0/1.
 */
function bravoQuestionPersistData(array $d): array {
    $diff = in_array($d['difficulty'] ?? '', bravoQuestionDifficulties(), true) ? $d['difficulty'] : 'normal';
    $strOrNull = function ($v) {
        if (!is_string($v)) return null;
        $v = trim($v);
        return $v === '' ? null : $v;
    };
    $numOrNull = function ($v) {
        if ($v === '' || $v === null || !is_numeric($v)) return null;
        return (float)$v;
    };
    return [
        'question_type'           => (int)($d['question_type'] ?? 0),
        'bravo_level'             => (int)($d['bravo_level'] ?? 0),
        'source'                  => $strOrNull($d['source'] ?? null),
        'korean_text'             => trim((string)($d['korean_text'] ?? '')),
        'english_text'            => trim((string)($d['english_text'] ?? '')),
        'target_chunks'           => $strOrNull($d['target_chunks'] ?? null),
        'accepted_answers'        => $strOrNull($d['accepted_answers'] ?? null),
        'reference_speech_sec'    => $numOrNull($d['reference_speech_sec'] ?? null),
        'response_time_limit_sec' => $numOrNull($d['response_time_limit_sec'] ?? null),
        'difficulty'              => $diff,
        'is_active'               => !empty($d['is_active']) ? 1 : 0,
    ];
}
```

- [ ] **Step 4: 테스트 실행 → 순수함수 블록 통과 확인**

Run: `cd /root/boot-dev && php tests/bravo_question_test.php`
Expected: 순수함수 테스트 전부 `PASS`, 마지막 `결과: N pass, 0 fail`

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/bravo_questions.php tests/bravo_question_test.php
git commit -m "feat(bravo): 문제은행 검증/정규화 순수함수"
```

---

## Task 3: 문제은행 CRUD 서비스

**Files:**
- Modify: `public_html/api/services/bravo_questions.php` (append)
- Test: `tests/bravo_question_test.php` (통합 블록 추가)

- [ ] **Step 1: 통합 테스트 추가**

`tests/bravo_question_test.php` 의 `echo "\n결과: ..."` 줄 **바로 앞**에 아래 블록 삽입:

```php
// ── 통합: CRUD (DEV DB, 트랜잭션 롤백) ──
$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TQ_' . bin2hex(random_bytes(3));
    $id1 = bravoQuestionCreate($db, [
        'question_type'=>1,'bravo_level'=>1,'source'=>'VOD','korean_text'=>"{$tag} 안녕",'english_text'=>'hello',
        'difficulty'=>'easy','is_active'=>1,'reference_speech_sec'=>'3.0',
    ], 99);
    $id2 = bravoQuestionCreate($db, [
        'question_type'=>2,'bravo_level'=>3,'korean_text'=>"{$tag} 비활성",'english_text'=>'inactive',
        'difficulty'=>'hard','is_active'=>0,
    ], 99);
    t('create id 반환', $id1 > 0 && $id2 > 0 && $id1 !== $id2);

    $kw = bravoQuestionList($db, ['keyword'=>$tag]);
    t('keyword 필터 2건', count($kw) === 2, 'count=' . count($kw));
    t('정렬 id DESC', (int)$kw[0]['id'] === $id2);

    $byLevel = bravoQuestionList($db, ['keyword'=>$tag, 'bravo_level'=>1]);
    t('level 필터 1건', count($byLevel) === 1 && (int)$byLevel[0]['id'] === $id1);

    $active = bravoQuestionList($db, ['keyword'=>$tag, 'is_active'=>0]);
    t('is_active=0 필터 1건', count($active) === 1 && (int)$active[0]['id'] === $id2);

    $byType = bravoQuestionList($db, ['keyword'=>$tag, 'question_type'=>2]);
    t('type 필터 1건', count($byType) === 1 && (int)$byType[0]['id'] === $id2);

    bravoQuestionUpdate($db, $id1, [
        'question_type'=>3,'bravo_level'=>2,'korean_text'=>"{$tag} 수정",'english_text'=>'edited',
        'difficulty'=>'normal','is_active'=>0,
    ]);
    $one = bravoQuestionList($db, ['keyword'=>$tag, 'question_type'=>3]);
    t('update 반영', count($one) === 1 && $one[0]['english_text'] === 'edited' && (int)$one[0]['bravo_level'] === 2);

    bravoQuestionDelete($db, $id1);
    bravoQuestionDelete($db, $id2);
    t('delete 후 0건', count(bravoQuestionList($db, ['keyword'=>$tag])) === 0);

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage());
}
```

- [ ] **Step 2: 테스트 실행 → 통합 블록 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_question_test.php`
Expected: FAIL — `Call to undefined function bravoQuestionCreate()`

- [ ] **Step 3: CRUD 함수 구현**

`public_html/api/services/bravo_questions.php` 끝에 append:

```php
/**
 * 문제 목록. 선택 필터: question_type/bravo_level/difficulty/is_active/keyword.
 */
function bravoQuestionList(PDO $db, array $filters = []): array {
    $where = []; $params = [];
    if (!empty($filters['question_type'])) { $where[] = 'question_type = ?'; $params[] = (int)$filters['question_type']; }
    if (!empty($filters['bravo_level']))   { $where[] = 'bravo_level = ?';   $params[] = (int)$filters['bravo_level']; }
    if (!empty($filters['difficulty']) && in_array($filters['difficulty'], bravoQuestionDifficulties(), true)) {
        $where[] = 'difficulty = ?'; $params[] = $filters['difficulty'];
    }
    if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
        $where[] = 'is_active = ?'; $params[] = ((int)$filters['is_active'] ? 1 : 0);
    }
    if (!empty($filters['keyword'])) {
        $where[] = '(korean_text LIKE ? OR english_text LIKE ?)';
        $kw = '%' . $filters['keyword'] . '%';
        $params[] = $kw; $params[] = $kw;
    }
    $sql = "SELECT * FROM bravo_questions";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY id DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 문제 생성. 정규화 후 INSERT, 신규 id 반환.
 */
function bravoQuestionCreate(PDO $db, array $d, int $adminId): int {
    $c = bravoQuestionPersistData($d);
    $db->prepare("
        INSERT INTO bravo_questions
            (question_type, bravo_level, source, korean_text, english_text, target_chunks,
             accepted_answers, reference_speech_sec, response_time_limit_sec, difficulty, is_active, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $c['question_type'], $c['bravo_level'], $c['source'], $c['korean_text'], $c['english_text'], $c['target_chunks'],
        $c['accepted_answers'], $c['reference_speech_sec'], $c['response_time_limit_sec'], $c['difficulty'], $c['is_active'], $adminId,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * 문제 수정 (전체 필드).
 */
function bravoQuestionUpdate(PDO $db, int $id, array $d): void {
    $c = bravoQuestionPersistData($d);
    $db->prepare("
        UPDATE bravo_questions SET
            question_type=?, bravo_level=?, source=?, korean_text=?, english_text=?, target_chunks=?,
            accepted_answers=?, reference_speech_sec=?, response_time_limit_sec=?, difficulty=?, is_active=?
        WHERE id=?
    ")->execute([
        $c['question_type'], $c['bravo_level'], $c['source'], $c['korean_text'], $c['english_text'], $c['target_chunks'],
        $c['accepted_answers'], $c['reference_speech_sec'], $c['response_time_limit_sec'], $c['difficulty'], $c['is_active'], $id,
    ]);
}

/**
 * 문제 삭제 (하드).
 */
function bravoQuestionDelete(PDO $db, int $id): void {
    $db->prepare("DELETE FROM bravo_questions WHERE id = ?")->execute([$id]);
}
```

- [ ] **Step 4: 테스트 실행 → 전부 통과**

Run: `cd /root/boot-dev && php tests/bravo_question_test.php`
Expected: 모든 라인 `PASS`, `결과: N pass, 0 fail`

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/bravo_questions.php tests/bravo_question_test.php
git commit -m "feat(bravo): 문제은행 CRUD 서비스 + 통합 테스트"
```

---

## Task 4: OT 순수함수 + CRUD + 시험삭제 정리

**Files:**
- Modify: `public_html/api/services/bravo.php` (append OT 함수, `bravoExamDelete` 보강)
- Test: `tests/bravo_ot_test.php`

- [ ] **Step 1: OT 테스트 작성**

Create `tests/bravo_ot_test.php`:

```php
<?php
/**
 * BRAVO 시험별 OT 서비스 테스트. 순수 + DEV DB 통합(트랜잭션 롤백).
 * 사용: php tests/bravo_ot_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/bravo.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// ── 순수: bravoOtValidate ──
t('exam_id 없으면 에러', in_array('시험을 지정해주세요.', bravoOtValidate([]), true));
t('exam_id 0 에러', in_array('시험을 지정해주세요.', bravoOtValidate(['exam_id'=>0]), true));
t('exam_id 유효 통과', bravoOtValidate(['exam_id'=>5]) === []);

// ── 순수: bravoOtPersistData ──
$p = bravoOtPersistData(['title'=>'  OT ','intro_text'=>'','video_url'=>' http://v ','type1_text'=>'유형1','require_check'=>'1']);
t('persist title trim', $p['title'] === 'OT');
t('persist 빈 intro → null', $p['intro_text'] === null);
t('persist video trim', $p['video_url'] === 'http://v');
t('persist require_check 1', $p['require_check'] === 1);
$p2 = bravoOtPersistData([]);
t('persist require_check 미전달 → 0', $p2['require_check'] === 0);

// ── 통합: upsert/get + 시험삭제 정리 (DEV DB, 롤백) ──
$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TOT_' . bin2hex(random_bytes(3));
    $examId = bravoExamCreate($db, [
        'title'=>"{$tag} 시험",'bravo_level'=>1,'exam_mode'=>'always',
        'attempt_limit'=>3,'target_type'=>'all','status'=>'preparing',
    ], 99);
    t('시험 생성', $examId > 0);
    t('OT 최초 없음', bravoOtGet($db, $examId) === null);

    bravoOtUpsert($db, $examId, ['exam_id'=>$examId,'title'=>'OT 제목','intro_text'=>'안내','require_check'=>1], 99);
    $ot1 = bravoOtGet($db, $examId);
    t('OT upsert 생성', $ot1 !== null && $ot1['title'] === 'OT 제목');
    t('require_check 1', (int)$ot1['require_check'] === 1);

    bravoOtUpsert($db, $examId, ['exam_id'=>$examId,'title'=>'OT 수정','type1_text'=>'유형1 안내','require_check'=>0], 99);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM bravo_exam_ot WHERE exam_id=" . (int)$examId)->fetchColumn();
    t('upsert 중복 안생김(1행)', $cnt === 1, 'cnt=' . $cnt);
    $ot2 = bravoOtGet($db, $examId);
    t('OT 갱신 반영', $ot2['title'] === 'OT 수정' && $ot2['type1_text'] === '유형1 안내');
    t('require_check 0 갱신', (int)$ot2['require_check'] === 0);

    bravoExamDelete($db, $examId);
    t('시험 삭제 시 OT 동반 삭제', bravoOtGet($db, $examId) === null);
    t('시험 행도 삭제', (int)$db->query("SELECT COUNT(*) FROM bravo_exams WHERE id=" . (int)$examId)->fetchColumn() === 0);

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage());
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 테스트 실행 → 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_ot_test.php`
Expected: FAIL — `Call to undefined function bravoOtValidate()`

- [ ] **Step 3: OT 함수 구현 + bravoExamDelete 보강**

`public_html/api/services/bravo.php` 의 기존 `bravoExamDelete` 함수를 아래로 교체:

```php
/**
 * 시험 삭제 (하드). 연결된 OT(bravo_exam_ot) 도 함께 삭제.
 */
function bravoExamDelete(PDO $db, int $id): void {
    $db->prepare("DELETE FROM bravo_exam_ot WHERE exam_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM bravo_exams WHERE id = ?")->execute([$id]);
}
```

그리고 같은 파일 **맨 끝**에 OT 함수 append:

```php
/**
 * OT 입력 검증. exam_id 필수, 나머지 필드는 모두 선택. 순수.
 */
function bravoOtValidate(array $d): array {
    $errors = [];
    $examId = isset($d['exam_id']) ? (int)$d['exam_id'] : 0;
    if ($examId < 1) $errors[] = '시험을 지정해주세요.';
    return $errors;
}

/**
 * 폼 입력 → bravo_exam_ot 저장용 정규화. 빈 문자열은 NULL, require_check 0/1. 순수.
 */
function bravoOtPersistData(array $d): array {
    $strOrNull = function ($v) {
        if (!is_string($v)) return null;
        $v = trim($v);
        return $v === '' ? null : $v;
    };
    return [
        'title'         => $strOrNull($d['title'] ?? null),
        'intro_text'    => $strOrNull($d['intro_text'] ?? null),
        'video_url'     => $strOrNull($d['video_url'] ?? null),
        'type1_text'    => $strOrNull($d['type1_text'] ?? null),
        'type2_text'    => $strOrNull($d['type2_text'] ?? null),
        'type3_text'    => $strOrNull($d['type3_text'] ?? null),
        'require_check' => !empty($d['require_check']) ? 1 : 0,
    ];
}

/**
 * 시험 OT 조회 (없으면 null).
 */
function bravoOtGet(PDO $db, int $examId): ?array {
    $stmt = $db->prepare("SELECT * FROM bravo_exam_ot WHERE exam_id = ?");
    $stmt->execute([$examId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * 시험 OT upsert (exam_id UNIQUE 기준).
 */
function bravoOtUpsert(PDO $db, int $examId, array $d, int $adminId): void {
    $c = bravoOtPersistData($d);
    $db->prepare("
        INSERT INTO bravo_exam_ot
            (exam_id, title, intro_text, video_url, type1_text, type2_text, type3_text, require_check, updated_by)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            title=VALUES(title), intro_text=VALUES(intro_text), video_url=VALUES(video_url),
            type1_text=VALUES(type1_text), type2_text=VALUES(type2_text), type3_text=VALUES(type3_text),
            require_check=VALUES(require_check), updated_by=VALUES(updated_by)
    ")->execute([
        $examId, $c['title'], $c['intro_text'], $c['video_url'],
        $c['type1_text'], $c['type2_text'], $c['type3_text'], $c['require_check'], $adminId,
    ]);
}
```

- [ ] **Step 4: OT 테스트 + 기존 exam 테스트 실행 → 전부 통과**

Run: `cd /root/boot-dev && php tests/bravo_ot_test.php && php tests/bravo_exam_service_test.php`
Expected: 양쪽 모두 `결과: N pass, 0 fail` (bravoExamDelete 보강이 기존 exam 테스트 회귀 없음)

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/bravo.php tests/bravo_ot_test.php
git commit -m "feat(bravo): 시험별 OT 순수함수+CRUD, 시험삭제 시 OT 정리"
```

---

## Task 5: admin.php API case (문제은행 5 + OT 2)

**Files:**
- Modify: `public_html/api/admin.php` (require_once + 신규 case)

- [ ] **Step 1: require_once 추가**

`public_html/api/admin.php:18` 의 `require_once __DIR__ . '/services/bravo.php';` **바로 다음 줄**에 추가:

```php
require_once __DIR__ . '/services/bravo_questions.php';
```

- [ ] **Step 2: 신규 case 추가**

`public_html/api/admin.php` 의 `case 'bravo_exam_delete':` 블록(`break;`로 끝남) **바로 다음**에 아래 5개 case 삽입:

```php
case 'bravo_question_list':
    requireAdmin(['operation']);
    $db = getDB();
    $filters = [];
    if (!empty($_GET['question_type'])) $filters['question_type'] = (int)$_GET['question_type'];
    if (!empty($_GET['bravo_level']))   $filters['bravo_level']   = (int)$_GET['bravo_level'];
    if (!empty($_GET['difficulty']) && is_string($_GET['difficulty'])) $filters['difficulty'] = $_GET['difficulty'];
    if (isset($_GET['is_active']) && $_GET['is_active'] !== '') $filters['is_active'] = (int)$_GET['is_active'];
    if (!empty($_GET['keyword']) && is_string($_GET['keyword'])) $filters['keyword'] = $_GET['keyword'];
    jsonSuccess(['questions' => bravoQuestionList($db, $filters)]);
    break;

case 'bravo_question_save':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $errors = bravoQuestionValidate($input);
    if ($errors) jsonError($errors[0]);
    $db = getDB();
    $id = (isset($input['id']) && is_numeric($input['id']) && (int)$input['id'] > 0) ? (int)$input['id'] : 0;
    if ($id > 0) {
        bravoQuestionUpdate($db, $id, $input);
        jsonSuccess(['id' => $id], '저장되었습니다.');
    } else {
        $newId = bravoQuestionCreate($db, $input, (int)$admin['admin_id']);
        jsonSuccess(['id' => $newId], '저장되었습니다.');
    }
    break;

case 'bravo_question_delete':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (isset($input['id']) && is_numeric($input['id'])) ? (int)$input['id'] : 0;
    if ($id < 1) jsonError('id가 필요합니다.');
    $db = getDB();
    bravoQuestionDelete($db, $id);
    jsonSuccess([], '삭제되었습니다.');
    break;

case 'bravo_ot_get':
    requireAdmin(['operation']);
    $examId = (isset($_GET['exam_id']) && is_numeric($_GET['exam_id'])) ? (int)$_GET['exam_id'] : 0;
    if ($examId < 1) jsonError('exam_id가 필요합니다.');
    $db = getDB();
    jsonSuccess(['ot' => bravoOtGet($db, $examId)]);
    break;

case 'bravo_ot_save':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $errors = bravoOtValidate($input);
    if ($errors) jsonError($errors[0]);
    $db = getDB();
    bravoOtUpsert($db, (int)$input['exam_id'], $input, (int)$admin['admin_id']);
    jsonSuccess([], '저장되었습니다.');
    break;
```

- [ ] **Step 3: PHP 문법 검사**

Run: `cd /root/boot-dev && php -l public_html/api/admin.php && php -l public_html/api/services/bravo_questions.php`
Expected: 양쪽 `No syntax errors detected ...`

- [ ] **Step 4: 회귀 — 전체 BRAVO 테스트 재실행**

Run:
```bash
cd /root/boot-dev && for f in bravo_schema_invariants bravo_qualification_test bravo_admin_service_test bravo_exam_validate_test bravo_exam_service_test bravo_exams_schema_invariants bravo_member_status_test bravo_questions_schema_invariants bravo_question_test bravo_ot_test; do echo "== $f =="; php tests/$f.php | tail -1; done
```
Expected: 각 줄 `결과: N pass, 0 fail`

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/admin.php
git commit -m "feat(bravo): admin API case (문제은행 5 + OT 2)"
```

---

## Task 6: 프론트 — 문제은행 서브탭

**Files:**
- Create: `public_html/js/admin-bravo-questions.js`
- Modify: `public_html/js/admin-bravo.js` (셸에 서브탭 추가)
- Modify: `public_html/operation/index.php` (script include)

- [ ] **Step 1: 문제은행 앱 작성**

Create `public_html/js/admin-bravo-questions.js`:

```javascript
/* ── BRAVO 문제은행 (operation) ── */
const AdminBravoQuestionApp = (() => {
    let admin = null;
    let root = null;
    let questions = [];
    let editingId = null;

    const DIFF = { easy: '쉬움', normal: '보통', hard: '어려움' };
    const SOURCES = ['소리블록 훈련VOD문장', '줌특강 PPT', '부트캠프 훈련영상'];

    async function init(adminSession, containerId) {
        admin = adminSession;
        root = document.getElementById(containerId);
        if (!root) return;
        editingId = null;
        root.innerHTML = `
            <div class="bravo-q">
                <div class="bravo-q-filters">
                    <select id="bq-f-type"><option value="">유형 전체</option><option value="1">유형 1</option><option value="2">유형 2</option><option value="3">유형 3</option></select>
                    <select id="bq-f-level"><option value="">등급 전체</option><option value="1">BRAVO 1</option><option value="2">BRAVO 2</option><option value="3">BRAVO 3</option></select>
                    <select id="bq-f-diff"><option value="">난이도 전체</option><option value="easy">쉬움</option><option value="normal">보통</option><option value="hard">어려움</option></select>
                    <select id="bq-f-active"><option value="">활성 전체</option><option value="1">활성</option><option value="0">비활성</option></select>
                    <input type="text" id="bq-f-kw" placeholder="문장 검색">
                    <button class="btn btn-sm" id="bq-search">검색</button>
                    <button class="btn btn-primary btn-sm" id="bq-new">+ 문제 추가</button>
                </div>
                <div id="bq-form"></div>
                <table class="data-table bravo-q-table">
                    <thead><tr><th>유형</th><th>등급</th><th>한국어</th><th>영어</th><th>난이도</th><th>활성</th><th></th></tr></thead>
                    <tbody id="bq-tbody"></tbody>
                </table>
            </div>`;
        root.querySelector('#bq-search').addEventListener('click', load);
        root.querySelector('#bq-new').addEventListener('click', () => openForm(null));
        await load();
    }

    function filterQuery() {
        const q = new URLSearchParams();
        const t = root.querySelector('#bq-f-type').value; if (t) q.set('question_type', t);
        const l = root.querySelector('#bq-f-level').value; if (l) q.set('bravo_level', l);
        const d = root.querySelector('#bq-f-diff').value; if (d) q.set('difficulty', d);
        const a = root.querySelector('#bq-f-active').value; if (a !== '') q.set('is_active', a);
        const k = root.querySelector('#bq-f-kw').value.trim(); if (k) q.set('keyword', k);
        return q.toString();
    }

    async function load() {
        const qs = filterQuery();
        const r = await App.get('/api/admin.php?action=bravo_question_list' + (qs ? '&' + qs : ''));
        questions = (r && r.success !== false) ? (r.questions || []) : [];
        renderRows();
    }

    function renderRows() {
        const tb = root.querySelector('#bq-tbody');
        if (!questions.length) { tb.innerHTML = '<tr><td colspan="7">문제가 없습니다.</td></tr>'; return; }
        tb.innerHTML = questions.map(q => `
            <tr data-id="${q.id}">
                <td>유형 ${q.question_type}</td>
                <td>BRAVO ${q.bravo_level}</td>
                <td>${App.esc((q.korean_text || '').slice(0, 30))}</td>
                <td>${App.esc((q.english_text || '').slice(0, 30))}</td>
                <td>${DIFF[q.difficulty] || q.difficulty}</td>
                <td>${parseInt(q.is_active, 10) ? '활성' : '비활성'}</td>
                <td>
                    <button class="btn btn-sm bq-edit" data-id="${q.id}">수정</button>
                    <button class="btn btn-sm btn-danger bq-del" data-id="${q.id}">삭제</button>
                </td>
            </tr>`).join('');
        tb.querySelectorAll('.bq-edit').forEach(b => b.addEventListener('click', () => openForm(parseInt(b.dataset.id, 10))));
        tb.querySelectorAll('.bq-del').forEach(b => b.addEventListener('click', () => onDelete(parseInt(b.dataset.id, 10))));
    }

    function openForm(id) {
        editingId = id;
        const q = id ? questions.find(x => x.id === id) : null;
        const sel = (v, opts) => opts.map(o => `<option value="${o.v}" ${String(v) === String(o.v) ? 'selected' : ''}>${o.t}</option>`).join('');
        const typeOpts = sel(q && q.question_type, [{v:1,t:'유형 1'},{v:2,t:'유형 2'},{v:3,t:'유형 3'}]);
        const levelOpts = sel(q && q.bravo_level, [{v:1,t:'BRAVO 1'},{v:2,t:'BRAVO 2'},{v:3,t:'BRAVO 3'}]);
        const diffOpts = sel(q ? q.difficulty : 'normal', [{v:'easy',t:'쉬움'},{v:'normal',t:'보통'},{v:'hard',t:'어려움'}]);
        const f = root.querySelector('#bq-form');
        f.innerHTML = `
            <div class="bravo-q-form">
                <h4>${id ? '문제 수정' : '문제 추가'}</h4>
                <label>유형 <select id="bq-type">${typeOpts}</select></label>
                <label>등급 <select id="bq-level">${levelOpts}</select></label>
                <label>난이도 <select id="bq-diff">${diffOpts}</select></label>
                <label>출제원천 <input type="text" id="bq-source" list="bq-source-list" value="${q ? App.esc(q.source || '') : ''}"></label>
                <datalist id="bq-source-list">${SOURCES.map(s => `<option value="${App.esc(s)}">`).join('')}</datalist>
                <label class="bq-wide">한국어 문장 <textarea id="bq-ko" rows="2">${q ? App.esc(q.korean_text || '') : ''}</textarea></label>
                <label class="bq-wide">기준 영어 문장 <textarea id="bq-en" rows="2">${q ? App.esc(q.english_text || '') : ''}</textarea></label>
                <label class="bq-wide">타겟 청크 <input type="text" id="bq-chunks" value="${q ? App.esc(q.target_chunks || '') : ''}"></label>
                <label class="bq-wide">허용 정답 (1줄 1개) <textarea id="bq-accepted" rows="2">${q ? App.esc(q.accepted_answers || '') : ''}</textarea></label>
                <label>기준 발화(초) <input type="number" step="0.1" min="0" id="bq-ref" value="${q && q.reference_speech_sec !== null ? q.reference_speech_sec : ''}" style="width:6em"></label>
                <label>반응속도(초) <input type="number" step="0.1" min="0" id="bq-resp" value="${q && q.response_time_limit_sec !== null ? q.response_time_limit_sec : ''}" style="width:6em"></label>
                <label>활성 <input type="checkbox" id="bq-active" ${!q || parseInt(q.is_active, 10) ? 'checked' : ''}></label>
                <div><button class="btn btn-primary btn-sm" id="bq-save">저장</button>
                <button class="btn btn-sm" id="bq-cancel">취소</button></div>
            </div>`;
        f.querySelector('#bq-save').addEventListener('click', onSave);
        f.querySelector('#bq-cancel').addEventListener('click', () => { f.innerHTML = ''; editingId = null; });
    }

    async function onSave() {
        const f = root.querySelector('#bq-form');
        const payload = {
            id: editingId || 0,
            question_type: parseInt(f.querySelector('#bq-type').value, 10),
            bravo_level: parseInt(f.querySelector('#bq-level').value, 10),
            difficulty: f.querySelector('#bq-diff').value,
            source: f.querySelector('#bq-source').value,
            korean_text: f.querySelector('#bq-ko').value,
            english_text: f.querySelector('#bq-en').value,
            target_chunks: f.querySelector('#bq-chunks').value,
            accepted_answers: f.querySelector('#bq-accepted').value,
            reference_speech_sec: f.querySelector('#bq-ref').value,
            response_time_limit_sec: f.querySelector('#bq-resp').value,
            is_active: f.querySelector('#bq-active').checked ? 1 : 0,
        };
        const r = await App.post('/api/admin.php?action=bravo_question_save', payload);
        if (r && r.success !== false) {
            Toast.success('저장되었습니다.');
            f.innerHTML = ''; editingId = null;
            await load();
        } else {
            Toast.error((r && r.error) || '저장 실패');
        }
    }

    async function onDelete(id) {
        if (!confirm('이 문제를 삭제할까요?')) return;
        const r = await App.post('/api/admin.php?action=bravo_question_delete', { id });
        if (r && r.success !== false) { Toast.success('삭제되었습니다.'); await load(); }
        else Toast.error((r && r.error) || '삭제 실패');
    }

    return { init };
})();
```

- [ ] **Step 2: 셸에 서브탭 추가 — 서브탭 버튼/컨테이너**

`public_html/js/admin-bravo.js` 의 `init` 안 `root.innerHTML = ` 템플릿을 아래로 교체(문제은행 버튼 + sub div 추가):

```javascript
        root.innerHTML = `
            <div class="bravo-subtabs">
                <button class="bravo-subtab active" data-sub="qual">회원 자격</button>
                <button class="bravo-subtab" data-sub="exams">시험 관리</button>
                <button class="bravo-subtab" data-sub="questions">문제은행</button>
            </div>
            <div class="bravo-sub" id="bravo-sub-qual"></div>
            <div class="bravo-sub" id="bravo-sub-exams" style="display:none"></div>
            <div class="bravo-sub" id="bravo-sub-questions" style="display:none"></div>`;
```

- [ ] **Step 3: 셸 마운트 상태 + switchSub 확장**

`public_html/js/admin-bravo.js` 상단 `let examsMounted = false;` **다음 줄**에 추가:

```javascript
    let questionsMounted = false;
```

`init` 안 `examsMounted = false;` **다음 줄**에 추가:

```javascript
        questionsMounted = false;
```

`switchSub` 함수의 display 토글 3줄을 아래로 교체:

```javascript
        root.querySelector('#bravo-sub-qual').style.display = sub === 'qual' ? '' : 'none';
        root.querySelector('#bravo-sub-exams').style.display = sub === 'exams' ? '' : 'none';
        root.querySelector('#bravo-sub-questions').style.display = sub === 'questions' ? '' : 'none';
```

`switchSub` 함수 안 exams 마운트 `if` 블록 **다음**에 추가:

```javascript
        if (sub === 'questions' && !questionsMounted && typeof AdminBravoQuestionApp !== 'undefined') {
            questionsMounted = true;
            AdminBravoQuestionApp.init(admin, 'bravo-sub-questions');
        }
```

- [ ] **Step 4: operation/index.php 에 script include 추가**

`public_html/operation/index.php:53` 의 `<script src="/js/admin-bravo-exams.js...></script>` 줄 **다음**에 추가(`v()` 가 mtime 기반이라 캐시버스터 자동):

```php
    <script src="/js/admin-bravo-questions.js<?= v('/js/admin-bravo-questions.js') ?>"></script>
```

- [ ] **Step 5: 문법 검사 + 커밋**

Run: `cd /root/boot-dev && php -l public_html/operation/index.php && node --check public_html/js/admin-bravo-questions.js && node --check public_html/js/admin-bravo.js`
Expected: `No syntax errors detected ...` (php), node 는 출력 없으면 통과. (node 미설치 시 이 단계는 건너뛰고 브라우저 검증으로 대체)

```bash
cd /root/boot-dev && git add public_html/js/admin-bravo-questions.js public_html/js/admin-bravo.js public_html/operation/index.php
git commit -m "feat(bravo): 문제은행 관리자 서브탭 프론트"
```

---

## Task 7: 프론트 — 시험관리 탭 내 per-exam OT 편집

**Files:**
- Modify: `public_html/js/admin-bravo-exams.js` (OT 버튼 + 폼 + 저장)

- [ ] **Step 1: 시험 행에 OT 버튼 추가**

`public_html/js/admin-bravo-exams.js` 의 시험 행 액션 버튼 부분(`bravo-exam-edit`/`bravo-exam-del` 버튼이 있는 `<td>`)에서, 삭제 버튼 줄 **다음**에 OT 버튼 추가:

```javascript
                    <button class="btn btn-sm bravo-exam-ot" data-id="${e.id}">OT</button>
```

(즉 해당 `<td>` 안이 `수정 / 삭제 / OT` 3버튼이 되도록.)

- [ ] **Step 2: OT 버튼 이벤트 바인딩**

`admin-bravo-exams.js` 의 `render()` 함수 끝, edit/del 버튼에 `addEventListener` 를 거는 부분(`container.querySelectorAll('.bravo-exam-del')...` 바인딩, line 69-70) **바로 다음**에 추가:

```javascript
        container.querySelectorAll('.bravo-exam-ot').forEach(b =>
            b.addEventListener('click', () => openOt(parseInt(b.dataset.id, 10))));
```

- [ ] **Step 3: OT 편집 함수 추가**

`admin-bravo-exams.js` 의 즉시실행 모듈 `return { init };` **바로 앞**에 함수 추가(`AdminBravoExamApp` 내부 스코프). 시험 편집 폼과 동일하게 기존 `#bravo-exam-form` div(모듈 스코프 `container` 안)를 host 로 재사용한다 — OT 열기와 시험 편집은 상호배타적 동작이라 충돌 없음. `App`/`Toast` 전역 헬퍼 사용:

```javascript
    async function openOt(examId) {
        const r = await App.get('/api/admin.php?action=bravo_ot_get&exam_id=' + examId);
        const ot = (r && r.success !== false) ? (r.ot || {}) : {};
        const v = k => ot && ot[k] != null ? App.esc(ot[k]) : '';
        editingId = null; // 시험 편집 모드 해제(같은 host 공유)
        const host = container.querySelector('#bravo-exam-form');
        if (!host) return;
        host.innerHTML = `
            <div class="bravo-ot-form">
                <h4>시험 #${examId} OT 안내</h4>
                <label>OT 제목 <input type="text" id="ot-title" value="${v('title')}"></label>
                <label class="ot-wide">전체 안내문 <textarea id="ot-intro" rows="3">${v('intro_text')}</textarea></label>
                <label class="ot-wide">영상 URL <input type="text" id="ot-video" value="${v('video_url')}"></label>
                <label class="ot-wide">유형 1 안내문 <textarea id="ot-t1" rows="2">${v('type1_text')}</textarea></label>
                <label class="ot-wide">유형 2 안내문 <textarea id="ot-t2" rows="2">${v('type2_text')}</textarea></label>
                <label class="ot-wide">유형 3 안내문 <textarea id="ot-t3" rows="2">${v('type3_text')}</textarea></label>
                <label>필수 확인 체크 <input type="checkbox" id="ot-require" ${ot.require_check == null || parseInt(ot.require_check, 10) ? 'checked' : ''}></label>
                <div><button class="btn btn-primary btn-sm" id="ot-save">OT 저장</button>
                <button class="btn btn-sm" id="ot-cancel">닫기</button></div>
            </div>`;
        host.querySelector('#ot-save').addEventListener('click', () => saveOt(examId, host));
        host.querySelector('#ot-cancel').addEventListener('click', () => { host.innerHTML = ''; });
    }

    async function saveOt(examId, host) {
        const payload = {
            exam_id: examId,
            title: host.querySelector('#ot-title').value,
            intro_text: host.querySelector('#ot-intro').value,
            video_url: host.querySelector('#ot-video').value,
            type1_text: host.querySelector('#ot-t1').value,
            type2_text: host.querySelector('#ot-t2').value,
            type3_text: host.querySelector('#ot-t3').value,
            require_check: host.querySelector('#ot-require').checked ? 1 : 0,
        };
        const r = await App.post('/api/admin.php?action=bravo_ot_save', payload);
        if (r && r.success !== false) { Toast.success('OT가 저장되었습니다.'); host.innerHTML = ''; }
        else Toast.error((r && r.error) || 'OT 저장 실패');
    }
```

- [ ] **Step 4: 문법 검사**

Run: `cd /root/boot-dev && node --check public_html/js/admin-bravo-exams.js`
Expected: 출력 없으면 통과. (node 미설치 시 브라우저 콘솔 검증으로 대체)

- [ ] **Step 5: 브라우저 통합 검증**

https://dev-boot.soritune.com 운영 SPA 로그인 → `BRAVO` 탭 →
1. `문제은행` 서브탭: 문제 추가/수정/삭제 + 필터 동작.
2. `시험 관리` 서브탭: 시험 행 `OT` 버튼 → 안내문 입력 → 저장 → 다시 열어 값 유지 확인.
콘솔 에러 없음 확인.

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/admin-bravo-exams.js
git commit -m "feat(bravo): 시험관리 탭 per-exam OT 편집 프론트"
```

---

## 최종 통합 검증 (전 태스크 완료 후)

- [ ] **전체 BRAVO 테스트 재실행 (회귀 0)**

Run:
```bash
cd /root/boot-dev && for f in bravo_schema_invariants bravo_qualification_test bravo_admin_service_test bravo_exam_validate_test bravo_exam_service_test bravo_exams_schema_invariants bravo_member_status_test bravo_questions_schema_invariants bravo_question_test bravo_ot_test; do echo "== $f =="; php tests/$f.php | tail -1; done
```
Expected: 모든 파일 `결과: N pass, 0 fail`

- [ ] **dev push (운영 미반영)**

```bash
cd /root/boot-dev && git push origin dev
```

⛔ **여기서 멈춤.** 운영(main) 반영은 BRAVO 전체 완성 시 1회 일괄 — 사용자 명시 요청 시에만. dev 검증 대기.

---

## 미적용 (다음 슬라이스)

시험-문제 배정/자동출제, 회원 OT 노출·마이크 테스트·확인 체크 흐름, 실제 응시·녹음·채점·인증서, CSV 일괄 입력, §15-6 출제/채점 매트릭스, 전체 BRAVO UI 스타일링(slice1~4 신규 클래스 CSS 미작성 누적 — `.bravo-q*`, `.bravo-ot-form` 포함).
