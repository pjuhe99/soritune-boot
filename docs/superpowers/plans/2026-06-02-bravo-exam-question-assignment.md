# BRAVO 시험-문제 배정 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 관리자가 각 BRAVO 시험에 문제은행의 문제를 수동 선택(벌크 set)으로 배정할 수 있게 한다.

**Architecture:** 순수 추가형. 신규 junction 테이블 `bravo_exam_questions`(exam↔question N:M). 배정 도메인은 신규 서비스 `bravo_exam_questions.php`로 분리. 저장은 트랜잭션 전체 교체(`bravoExamQuestionSet`, `inTransaction()` 가드로 중첩 방지). 시험/문제 삭제 시 junction cascade. 프론트는 기존 시험 관리 탭의 per-exam 패널(OT 패턴)로 "문제" 버튼 추가.

**Tech Stack:** PHP 8 + PDO(MariaDB), vanilla JS SPA, 커스텀 `t()` CLI 테스트 러너(DEV DB 트랜잭션 롤백). 작업·검증은 **DEV(`/root/boot-dev`, dev 브랜치, DB SORITUNECOM_DEV_BOOT)** 에서만. ⚠️ DEV DB 파괴적 op(DROP/TRUNCATE/migrate reset) 금지 — 테스트는 트랜잭션 롤백만.

**참조 스펙:** `docs/superpowers/specs/2026-06-02-bravo-exam-question-assignment-design.md`

---

## File Structure

- **Create** `migrate_bravo_exam_questions.php` — 멱등 마이그(junction 생성).
- **Create** `public_html/api/services/bravo_exam_questions.php` — AssignedIds / List / Set.
- **Modify** `public_html/api/services/bravo.php` — `bravoExamDelete` junction cascade 추가.
- **Modify** `public_html/api/services/bravo_questions.php` — `bravoQuestionDelete` junction cascade 추가.
- **Modify** `public_html/api/admin.php` — require_once + 2 case (list/save).
- **Modify** `public_html/js/admin-bravo-exams.js` — "문제" 버튼 + 배정 패널.
- **Create** `tests/bravo_exam_questions_schema_invariants.php`, `tests/bravo_exam_question_test.php`.

---

## Task 1: 마이그레이션 (bravo_exam_questions)

**Files:**
- Create: `migrate_bravo_exam_questions.php`
- Test: `tests/bravo_exam_questions_schema_invariants.php`

- [ ] **Step 1: 마이그레이션 파일 작성**

Create `migrate_bravo_exam_questions.php`:

```php
<?php
/**
 * Migration: BRAVO 5차 슬라이스 — bravo_exam_questions (시험↔문제 N:M 배정)
 * 실행: php migrate_bravo_exam_questions.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS. 추가형(기존 테이블 미수정).
 */
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: bravo_exam_questions ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_exam_questions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id       INT UNSIGNED NOT NULL COMMENT 'bravo_exams.id',
    question_id   INT UNSIGNED NOT NULL COMMENT 'bravo_questions.id',
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '제시 순서 (저장 시 제출 리스트 인덱스)',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_beq_exam_question (exam_id, question_id),
    KEY idx_beq_exam (exam_id),
    KEY idx_beq_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_exam_questions 생성 완료\n";

echo "\n=== Migration 완료 ===\n";
```

- [ ] **Step 2: DEV DB 적용**

Run: `cd /root/boot-dev && php migrate_bravo_exam_questions.php`
Expected:
```
bravo_exam_questions 생성 완료

=== Migration 완료 ===
```

- [ ] **Step 3: 스키마 불변식 테스트 작성**

Create `tests/bravo_exam_questions_schema_invariants.php`:

```php
<?php
/**
 * bravo_exam_questions 스키마 불변식. DEV DB.
 * 사용: php tests/bravo_exam_questions_schema_invariants.php
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

$exists = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'bravo_exam_questions'")->fetchColumn();
t('bravo_exam_questions 테이블 존재', (int)$exists === 1);
if ((int)$exists !== 1) { echo "\n결과: {$pass} pass, {$fail} fail\n"; exit(1); }

$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_exam_questions") as $c) $cols[$c['Field']] = $c;
foreach (['id','exam_id','question_id','display_order','created_at'] as $col) {
    t("bravo_exam_questions.{$col} 존재", isset($cols[$col]));
}
t('exam_id NOT NULL', $cols['exam_id']['Null'] === 'NO');
t('question_id NOT NULL', $cols['question_id']['Null'] === 'NO');
t('display_order 기본 0', (string)$cols['display_order']['Default'] === '0');

$idx = $db->query("SHOW INDEX FROM bravo_exam_questions WHERE Key_name='uk_beq_exam_question'")->fetchAll();
t('(exam_id,question_id) UNIQUE', count($idx) === 2 && (int)$idx[0]['Non_unique'] === 0);

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 4: 테스트 실행 → 통과**

Run: `cd /root/boot-dev && php tests/bravo_exam_questions_schema_invariants.php`
Expected: 모든 `PASS`, `결과: N pass, 0 fail`

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add migrate_bravo_exam_questions.php tests/bravo_exam_questions_schema_invariants.php
git commit -m "feat(bravo): 시험-문제 배정 마이그레이션 (bravo_exam_questions)"
```

---

## Task 2: 배정 서비스 (AssignedIds / List / Set)

**Files:**
- Create: `public_html/api/services/bravo_exam_questions.php`
- Test: `tests/bravo_exam_question_test.php`

- [ ] **Step 1: 통합 테스트 작성**

Create `tests/bravo_exam_question_test.php`:

```php
<?php
/**
 * BRAVO 시험-문제 배정 서비스 테스트. DEV DB 통합(트랜잭션 롤백).
 * 사용: php tests/bravo_exam_question_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/bravo.php';
require_once __DIR__ . '/../public_html/api/services/bravo_questions.php';
require_once __DIR__ . '/../public_html/api/services/bravo_exam_questions.php';

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
    $tag = 'TEQ_' . bin2hex(random_bytes(3));
    $examId = bravoExamCreate($db, [
        'title'=>"{$tag} 시험",'bravo_level'=>1,'exam_mode'=>'always',
        'attempt_limit'=>3,'target_type'=>'all','status'=>'preparing',
    ], 99);
    $q1 = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>1,'korean_text'=>"{$tag} q1",'english_text'=>'q1','difficulty'=>'easy','is_active'=>1], 99);
    $q2 = bravoQuestionCreate($db, ['question_type'=>2,'bravo_level'=>1,'korean_text'=>"{$tag} q2",'english_text'=>'q2','difficulty'=>'normal','is_active'=>1], 99);
    $q3 = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>1,'korean_text'=>"{$tag} q3",'english_text'=>'q3','difficulty'=>'hard','is_active'=>1], 99);
    t('셋업 id 정상', $examId>0 && $q1>0 && $q2>0 && $q3>0);

    // 배정 없음
    t('초기 배정 없음', bravoExamQuestionAssignedIds($db, $examId) === []);

    // set [q1, q3] — 순서 보존
    bravoExamQuestionSet($db, $examId, [$q1, $q3]);
    t('set 후 assignedIds 순서', bravoExamQuestionAssignedIds($db, $examId) === [$q1, $q3], json_encode(bravoExamQuestionAssignedIds($db, $examId)));
    $list = bravoExamQuestionList($db, $examId);
    t('list 2건 + 조인내용', count($list)===2 && $list[0]['english_text']==='q1' && (int)$list[0]['display_order']===0 && $list[1]['english_text']==='q3');

    // re-set [q2, q1, q3] — 멱등 교체, 중복 없음, display_order 갱신
    bravoExamQuestionSet($db, $examId, [$q2, $q1, $q3]);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM bravo_exam_questions WHERE exam_id=".(int)$examId)->fetchColumn();
    t('re-set 3건(중복 없음)', $cnt === 3, 'cnt='.$cnt);
    t('re-set 순서 [q2,q1,q3]', bravoExamQuestionAssignedIds($db, $examId) === [$q2, $q1, $q3]);

    // 미존재 id 필터 (존재하지 않는 id 는 set 에서 무시)
    $ghost = 99999999;
    bravoExamQuestionSet($db, $examId, [$q1, $ghost, $q3]);
    t('미존재 id 필터', bravoExamQuestionAssignedIds($db, $examId) === [$q1, $q3], json_encode(bravoExamQuestionAssignedIds($db, $examId)));

    // 빈 set → 전체 비움
    bravoExamQuestionSet($db, $examId, []);
    t('빈 set → 0건', bravoExamQuestionAssignedIds($db, $examId) === []);

    // 중복 입력 → 1건으로
    bravoExamQuestionSet($db, $examId, [$q1, $q1, $q2]);
    t('중복 입력 dedup', bravoExamQuestionAssignedIds($db, $examId) === [$q1, $q2]);

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage());
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_exam_question_test.php`
Expected: FAIL — `Call to undefined function bravoExamQuestionAssignedIds()` (또는 require 실패 fatal). 함수/파일 미존재 확인.

- [ ] **Step 3: 서비스 구현**

Create `public_html/api/services/bravo_exam_questions.php`:

```php
<?php
/**
 * BRAVO 시험-문제 배정 서비스 (5차 슬라이스).
 * 시험↔문제 N:M junction. 벌크 set(replace). 기존 BRAVO 경로와 무관한 추가형.
 */

/**
 * 시험에 배정된 question_id 배열 (display_order 순).
 */
function bravoExamQuestionAssignedIds(PDO $db, int $examId): array {
    $stmt = $db->prepare("SELECT question_id FROM bravo_exam_questions WHERE exam_id = ? ORDER BY display_order, id");
    $stmt->execute([$examId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 시험에 배정된 문제 전체 행 (bravo_questions 조인, display_order 순). display_order 컬럼 포함.
 */
function bravoExamQuestionList(PDO $db, int $examId): array {
    $stmt = $db->prepare("
        SELECT q.*, beq.display_order
        FROM bravo_exam_questions beq
        JOIN bravo_questions q ON beq.question_id = q.id
        WHERE beq.exam_id = ?
        ORDER BY beq.display_order, beq.id
    ");
    $stmt->execute([$examId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 시험 배정 전체 교체. 입력 순서대로 display_order(0,1,2,...) 부여. 존재하는 question_id만.
 * caller 가 이미 트랜잭션 안이면 중첩 begin/commit 생략 (PDO 중첩 트랜잭션 미지원).
 */
function bravoExamQuestionSet(PDO $db, int $examId, array $questionIds): void {
    // 정수화 + 순서보존 중복제거
    $ids = [];
    foreach ($questionIds as $qid) {
        $qid = (int)$qid;
        if ($qid > 0 && !in_array($qid, $ids, true)) $ids[] = $qid;
    }
    // 실제 존재하는 문제 id 만 남김
    if ($ids) {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id FROM bravo_questions WHERE id IN ($place)");
        $stmt->execute($ids);
        $exist = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $ids = array_values(array_filter($ids, function ($q) use ($exist) { return in_array($q, $exist, true); }));
    }

    $owns = !$db->inTransaction();
    if ($owns) $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM bravo_exam_questions WHERE exam_id = ?")->execute([$examId]);
        if ($ids) {
            $ins = $db->prepare("INSERT INTO bravo_exam_questions (exam_id, question_id, display_order) VALUES (?, ?, ?)");
            foreach ($ids as $i => $qid) $ins->execute([$examId, $qid, $i]);
        }
        if ($owns) $db->commit();
    } catch (Throwable $e) {
        if ($owns) $db->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 4: 통과 확인**

Run: `cd /root/boot-dev && php tests/bravo_exam_question_test.php`
Expected: 모든 `PASS`, `결과: N pass, 0 fail`

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/bravo_exam_questions.php tests/bravo_exam_question_test.php
git commit -m "feat(bravo): 시험-문제 배정 서비스 (AssignedIds/List/Set) + 통합 테스트"
```

---

## Task 3: 삭제 cascade (시험·문제 삭제 시 junction 정리)

**Files:**
- Modify: `public_html/api/services/bravo.php` (`bravoExamDelete`)
- Modify: `public_html/api/services/bravo_questions.php` (`bravoQuestionDelete`)
- Test: `tests/bravo_exam_question_test.php` (cascade 블록 추가)

- [ ] **Step 1: cascade 테스트 추가**

`tests/bravo_exam_question_test.php` 의 `$db->rollBack();` (정상 경로, try 블록 끝) **바로 앞**에 아래 블록 삽입:

```php
    // ── cascade: 시험 삭제 시 junction 제거 ──
    bravoExamQuestionSet($db, $examId, [$q1, $q2, $q3]);
    t('cascade 전 3건', count(bravoExamQuestionAssignedIds($db, $examId)) === 3);
    bravoExamDelete($db, $examId);
    t('시험 삭제 → junction 0건', (int)$db->query("SELECT COUNT(*) FROM bravo_exam_questions WHERE exam_id=".(int)$examId)->fetchColumn() === 0);

    // ── cascade: 문제 삭제 시 모든 배정에서 제거 ──
    $examId2 = bravoExamCreate($db, ['title'=>"{$tag} 시험2",'bravo_level'=>1,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'preparing'], 99);
    bravoExamQuestionSet($db, $examId2, [$q1, $q2]);
    bravoQuestionDelete($db, $q1);
    t('문제 삭제 → 해당 배정 제거', bravoExamQuestionAssignedIds($db, $examId2) === [$q2], json_encode(bravoExamQuestionAssignedIds($db, $examId2)));
```

- [ ] **Step 2: 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_exam_question_test.php`
Expected: cascade 관련 FAIL (시험/문제 삭제 후에도 junction 행 잔존) — 예: `FAIL 시험 삭제 → junction 0건`.

- [ ] **Step 3a: `bravoExamDelete` 보강 (bravo.php)**

`public_html/api/services/bravo.php` 의 현재 `bravoExamDelete` 를 아래로 교체 (junction 삭제 줄을 OT 삭제 앞에 추가):

```php
/**
 * 시험 삭제 (하드). 연결된 문제 배정(bravo_exam_questions)·OT(bravo_exam_ot) 도 함께 삭제.
 */
function bravoExamDelete(PDO $db, int $id): void {
    $db->prepare("DELETE FROM bravo_exam_questions WHERE exam_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM bravo_exam_ot WHERE exam_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM bravo_exams WHERE id = ?")->execute([$id]);
}
```

- [ ] **Step 3b: `bravoQuestionDelete` 보강 (bravo_questions.php)**

`public_html/api/services/bravo_questions.php` 의 현재 `bravoQuestionDelete` 를 아래로 교체:

```php
/**
 * 문제 삭제 (하드). 모든 시험 배정(bravo_exam_questions) 에서도 제거.
 */
function bravoQuestionDelete(PDO $db, int $id): void {
    $db->prepare("DELETE FROM bravo_exam_questions WHERE question_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM bravo_questions WHERE id = ?")->execute([$id]);
}
```

- [ ] **Step 4: 통과 + 회귀 확인**

Run:
```bash
cd /root/boot-dev && php tests/bravo_exam_question_test.php && php tests/bravo_ot_test.php && php tests/bravo_exam_service_test.php && php tests/bravo_question_test.php
```
Expected: 4개 모두 `결과: N pass, 0 fail` (cascade 추가가 기존 OT/exam/question 테스트 회귀 없음).

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/bravo.php public_html/api/services/bravo_questions.php tests/bravo_exam_question_test.php
git commit -m "feat(bravo): 시험·문제 삭제 시 배정(junction) cascade 정리"
```

---

## Task 4: admin.php API case (list / save)

**Files:**
- Modify: `public_html/api/admin.php`

- [ ] **Step 1: require_once 추가**

`public_html/api/admin.php` 에서 기존 `require_once __DIR__ . '/services/bravo_questions.php';` 줄 **바로 다음**에 추가:

```php
require_once __DIR__ . '/services/bravo_exam_questions.php';
```

- [ ] **Step 2: 신규 case 추가**

기존 `case 'bravo_ot_save':` 블록(`break;`로 끝남) **바로 다음**에 2개 case 삽입:

```php
case 'bravo_exam_question_list':
    requireAdmin(['operation']);
    $examId = (isset($_GET['exam_id']) && is_numeric($_GET['exam_id'])) ? (int)$_GET['exam_id'] : 0;
    if ($examId < 1) jsonError('exam_id가 필요합니다.');
    $db = getDB();
    $stmt = $db->prepare("SELECT id, title, bravo_level FROM bravo_exams WHERE id = ?");
    $stmt->execute([$examId]);
    $examRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$examRow) jsonError('시험을 찾을 수 없습니다.', 404);

    $assignedRows = bravoExamQuestionList($db, $examId);
    $assignedIds = array_map(function ($r) { return (int)$r['id']; }, $assignedRows);

    $showAll = !empty($_GET['show_all']);
    $filters = $showAll ? [] : ['bravo_level' => (int)$examRow['bravo_level'], 'is_active' => 1];
    $candidates = bravoQuestionList($db, $filters);

    // 후보 = 필터결과 ∪ 현재 배정 (배정된 문제가 필터 밖이어도 항상 패널에 보이도록)
    $byId = [];
    foreach ($candidates as $c) $byId[(int)$c['id']] = $c;
    foreach ($assignedRows as $r) {
        $rid = (int)$r['id'];
        if (!isset($byId[$rid])) { unset($r['display_order']); $byId[$rid] = $r; }
    }
    $merged = array_values($byId);
    usort($merged, function ($a, $b) {
        return [(int)$a['question_type'], (int)$a['id']] <=> [(int)$b['question_type'], (int)$b['id']];
    });

    jsonSuccess(['exam' => $examRow, 'assigned_ids' => $assignedIds, 'candidates' => $merged]);
    break;

case 'bravo_exam_question_save':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $examId = (isset($input['exam_id']) && is_numeric($input['exam_id'])) ? (int)$input['exam_id'] : 0;
    if ($examId < 1) jsonError('exam_id가 필요합니다.');
    $qids = (isset($input['question_ids']) && is_array($input['question_ids'])) ? $input['question_ids'] : [];
    $db = getDB();
    bravoExamQuestionSet($db, $examId, $qids);
    jsonSuccess(['count' => count(bravoExamQuestionAssignedIds($db, $examId))], '저장되었습니다.');
    break;
```

- [ ] **Step 3: 문법 검사**

Run: `cd /root/boot-dev && php -l public_html/api/admin.php && php -l public_html/api/services/bravo_exam_questions.php`
Expected: 양쪽 `No syntax errors detected ...`

- [ ] **Step 4: 전체 BRAVO 테스트 회귀**

Run:
```bash
cd /root/boot-dev && for f in bravo_schema_invariants bravo_qualification_test bravo_admin_service_test bravo_exam_validate_test bravo_exam_service_test bravo_exams_schema_invariants bravo_member_status_test bravo_questions_schema_invariants bravo_question_test bravo_ot_test bravo_exam_questions_schema_invariants bravo_exam_question_test; do echo "== $f =="; php tests/$f.php | tail -1; done
```
Expected: 각 줄 `결과: N pass, 0 fail`

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/admin.php
git commit -m "feat(bravo): admin API case (시험-문제 배정 list/save)"
```

---

## Task 5: 프론트 — 시험 행 "문제" 배정 패널

**Files:**
- Modify: `public_html/js/admin-bravo-exams.js`

먼저 파일 전체를 읽어 구조 확인: 모듈 변수 `container`, `editingId`, 시험 행 액션 버튼(`.bravo-exam-del`/`.bravo-exam-ot`, line ~48-49), `render()` 의 버튼 바인딩(line ~72), 폼 host `#bravo-exam-form`, 모듈 끝 `return { init };`. `App.get/App.post/App.esc`, `Toast` 전역.

- [ ] **Step 1: 추천 구성 상수 추가**

`admin-bravo-exams.js` 모듈 상단, 기존 `const STATUS_KEYS = ...` 줄 **다음**에 추가(§12 등급별 추천 문항 구성):

```javascript
    const EQ_RECOMMENDED = { 1: {1:15,2:5,3:0}, 2: {1:8,2:7,3:5}, 3: {1:8,2:7,3:5} };
```

- [ ] **Step 2: 시험 행에 "문제" 버튼 추가**

`render()` 의 행 템플릿에서 OT 버튼(`<button class="btn btn-sm bravo-exam-ot" data-id="${e.id}">OT</button>`) **다음**에 추가:

```javascript
                    <button class="btn btn-sm bravo-exam-eq" data-id="${e.id}">문제</button>
```

- [ ] **Step 3: 버튼 바인딩 추가**

`render()` 에서 `.bravo-exam-ot` 바인딩(`container.querySelectorAll('.bravo-exam-ot')...`) **바로 다음**에 추가:

```javascript
        container.querySelectorAll('.bravo-exam-eq').forEach(b =>
            b.addEventListener('click', () => openExamQuestions(parseInt(b.dataset.id, 10), false)));
```

- [ ] **Step 4: 배정 패널 함수 추가**

모듈의 `return { init };` **바로 앞**에 3개 함수 추가:

```javascript
    async function openExamQuestions(examId, showAll) {
        const url = '/api/admin.php?action=bravo_exam_question_list&exam_id=' + examId + (showAll ? '&show_all=1' : '');
        const r = await App.get(url);
        if (!r || r.success === false) { Toast.error((r && r.error) || '불러오기 실패'); return; }
        const assigned = new Set((r.assigned_ids || []).map(Number));
        const cands = r.candidates || [];
        const level = r.exam ? parseInt(r.exam.bravo_level, 10) : 0;
        const host = container.querySelector('#bravo-exam-form');
        if (!host) return;
        editingId = null; // 시험 편집 모드 해제(같은 host 공유)
        const rows = cands.map(q => `
            <label class="bravo-eq-row">
                <input type="checkbox" class="eq-chk" data-qid="${q.id}" data-type="${q.question_type}" ${assigned.has(parseInt(q.id, 10)) ? 'checked' : ''}>
                유형 ${q.question_type} · BRAVO ${q.bravo_level} · ${App.esc((q.korean_text || '').slice(0, 30))} <small>(${App.esc(q.difficulty)})</small>
            </label>`).join('');
        host.innerHTML = `
            <div class="bravo-eq-panel">
                <h4>시험 #${examId} 문제 배정</h4>
                <label><input type="checkbox" id="eq-showall" ${showAll ? 'checked' : ''}> 전체 등급·비활성 포함 보기</label>
                <small class="eq-note">※ 보기 전환 시 저장 안 한 체크 변경은 초기화됩니다.</small>
                <div class="eq-summary" id="eq-summary"></div>
                <div class="eq-list">${rows || '<p>후보 문제가 없습니다.</p>'}</div>
                <div><button class="btn btn-primary btn-sm" id="eq-save">배정 저장</button>
                <button class="btn btn-sm" id="eq-cancel">닫기</button></div>
            </div>`;
        host.querySelector('#eq-showall').addEventListener('change', e => openExamQuestions(examId, e.target.checked));
        host.querySelectorAll('.eq-chk').forEach(c => c.addEventListener('change', () => renderEqSummary(host, level)));
        host.querySelector('#eq-save').addEventListener('click', () => saveExamQuestions(examId, host));
        host.querySelector('#eq-cancel').addEventListener('click', () => { host.innerHTML = ''; });
        renderEqSummary(host, level);
    }

    function renderEqSummary(host, level) {
        const counts = { 1: 0, 2: 0, 3: 0 };
        host.querySelectorAll('.eq-chk:checked').forEach(c => {
            const t = parseInt(c.dataset.type, 10);
            if (counts[t] !== undefined) counts[t]++;
        });
        const rec = EQ_RECOMMENDED[level] || { 1: 0, 2: 0, 3: 0 };
        const total = counts[1] + counts[2] + counts[3];
        const el = host.querySelector('#eq-summary');
        if (el) el.textContent = `유형1 ${counts[1]}/${rec[1]} · 유형2 ${counts[2]}/${rec[2]} · 유형3 ${counts[3]}/${rec[3]} · 총 ${total}문항`;
    }

    async function saveExamQuestions(examId, host) {
        const ids = Array.from(host.querySelectorAll('.eq-chk:checked')).map(c => parseInt(c.dataset.qid, 10));
        const r = await App.post('/api/admin.php?action=bravo_exam_question_save', { exam_id: examId, question_ids: ids });
        if (r && r.success !== false) {
            Toast.success('배정되었습니다 (' + (r.count != null ? r.count : ids.length) + '문항).');
        } else {
            Toast.error((r && r.error) || '저장 실패');
        }
    }
```

IMPORTANT: 실제 파일 구조에 맞춰 삽입 위치를 조정. `container`/`editingId`/`#bravo-exam-form`/버튼 클래스명은 파일의 실제 코드와 일치시킬 것. 기존 시험 CRUD·OT 동작 보존.

- [ ] **Step 5: 문법 검사 + 커밋**

Run: `cd /root/boot-dev && node --check public_html/js/admin-bravo-exams.js`
Expected: 출력 없으면 통과.

```bash
cd /root/boot-dev && git add public_html/js/admin-bravo-exams.js
git commit -m "feat(bravo): 시험 관리 탭 문제 배정 패널 프론트"
```

- [ ] **Step 6: 브라우저 통합 검증 (controller/사용자)**

https://dev-boot.soritune.com 운영 SPA → `BRAVO` 탭 → `시험 관리` → 시험 행 `문제` 버튼 → 후보 체크/저장 → 다시 열어 유지 확인, 전체보기 토글, 출제 구성 요약 갱신. (구현 subagent 는 브라우저 실행 불필요.)

---

## 최종 통합 검증 (전 태스크 완료 후)

- [ ] **전체 BRAVO 테스트 재실행 (회귀 0)**

Run:
```bash
cd /root/boot-dev && for f in bravo_schema_invariants bravo_qualification_test bravo_admin_service_test bravo_exam_validate_test bravo_exam_service_test bravo_exams_schema_invariants bravo_member_status_test bravo_questions_schema_invariants bravo_question_test bravo_ot_test bravo_exam_questions_schema_invariants bravo_exam_question_test; do echo "== $f =="; php tests/$f.php | tail -1; done
```
Expected: 모든 파일 `결과: N pass, 0 fail`

- [ ] **dev push (운영 미반영)**

```bash
cd /root/boot-dev && git push origin dev
```

⛔ **여기서 멈춤.** 운영(main) 반영은 BRAVO 전체 완성 시 1회 일괄 — 사용자 명시 요청 시에만.

---

## 미적용 (다음 슬라이스)

자동출제(유형별 문항수 랜덤), 회원 응시·OT 노출·녹음·STT·채점·인증서, §15-6 출제/제출 방식 매트릭스, 문제 순서 드래그 재정렬, 전체 BRAVO UI 스타일링(slice1~5 신규 클래스 CSS 미작성 누적 — `.bravo-eq-*`, `.eq-summary` 포함).
