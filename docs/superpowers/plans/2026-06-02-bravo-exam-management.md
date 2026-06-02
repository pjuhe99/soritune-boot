# BRAVO 2차 슬라이스 (관리자 시험 관리) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 관리자가 BRAVO 시험(도전)을 생성·수정·삭제하고 운영 기간/상태를 관리하는 코어 CRUD를, 기존 테이블을 건드리지 않는 추가형으로 구축한다.

**Architecture:** PHP+PDO. 신규 테이블 `bravo_exams`. 검증·CRUD 로직은 `api/services/bravo.php` 에 함수로 추가(순수 검증 함수 + DB CRUD), admin.php 얇은 case 가 위임. 프론트는 슬라이스1의 BRAVO 탭을 서브탭(회원 자격 / 시험 관리) 구조로 확장하고 신규 `js/admin-bravo-exams.js` 모듈 추가. 상태 전환은 관리자 수동, 응시 대상은 전체/특정 기수.

**Tech Stack:** PHP 8 (PDO/MariaDB), vanilla JS SPA (App.get/App.post/App.esc, Toast.success/error, IIFE 모듈), CLI 테스트(`php tests/xxx.php`, DEV DB transaction rollback).

**작업 규칙:** 모든 작업 `/root/boot-dev`(DEV_BOOT, dev 브랜치). DB는 DEV 먼저. **이 프로젝트는 슬라이스별 운영배포 안 함 — dev 에서 BRAVO 전체 완성 후 1회 일괄 반영.** dev push 까지만.

**스펙:** `docs/superpowers/specs/2026-06-02-bravo-exam-management-design.md`
**선행:** 슬라이스1 완료(`api/services/bravo.php`에 bravoLoadLevels/bravoMemberList 등, `js/admin-bravo.js` AdminBravoApp, operation 탭 `tab-bravo`, 테이블 bravo_levels/bravo_member_settings).

---

## File Structure

- Create: `migrate_bravo_exams.php` — bravo_exams 테이블 생성
- Create: `tests/bravo_exams_schema_invariants.php` — 스키마 검증
- Modify: `public_html/api/services/bravo.php` — 검증 순수함수 + 시험 CRUD 함수 추가 (기존 함수 미수정)
- Create: `tests/bravo_exam_validate_test.php` — 검증 순수함수 단위 테스트
- Create: `tests/bravo_exam_service_test.php` — CRUD 통합 테스트(DEV DB txn rollback)
- Modify: `public_html/api/admin.php` — bravo_exam_list/save/delete case 추가
- Create: `public_html/js/admin-bravo-exams.js` — AdminBravoExamApp (시험 목록/폼/CRUD)
- Modify: `public_html/operation/index.php` — admin-bravo-exams.js include
- Modify: `public_html/js/admin-bravo.js` — 서브탭 셸로 리팩터(회원 자격 뷰 보존 + 시험 관리 마운트)
- Modify: `public_html/js/admin.js` — operation 탭 라벨 `BRAVO 자격` → `BRAVO`

---

## Task 1: 마이그레이션 — bravo_exams 테이블

**Files:**
- Create: `migrate_bravo_exams.php`
- Test: `tests/bravo_exams_schema_invariants.php`

- [ ] **Step 1: 스키마 검증 테스트 작성 (먼저 실패)**

Create `tests/bravo_exams_schema_invariants.php`:

```php
<?php
/**
 * bravo_exams 스키마 검증. 사용: php tests/bravo_exams_schema_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$db = getDB();
$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

function colExists(PDO $db, string $table, string $col): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() === 1;
}

$tblStmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'bravo_exams'");
$tblStmt->execute();
t('bravo_exams 테이블 존재', (int)$tblStmt->fetchColumn() === 1);

foreach (['id','title','bravo_level','exam_mode','start_at','end_at','result_release_at','attempt_limit','target_type','target_cohort_id','status','created_by','created_at','updated_at'] as $col) {
    t("컬럼 {$col} 존재", colExists($db, 'bravo_exams', $col));
}

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_exams_schema_invariants.php`
Expected: FAIL (테이블/컬럼 없음).

- [ ] **Step 3: 마이그레이션 작성**

Create `migrate_bravo_exams.php`:

```php
<?php
/**
 * Migration: BRAVO 2차 슬라이스 — bravo_exams 테이블
 * 실행: php migrate_bravo_exams.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS. 추가형(기존 테이블 미수정).
 */
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: bravo_exams ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_exams (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title             VARCHAR(120) NOT NULL,
    bravo_level       TINYINT UNSIGNED NOT NULL COMMENT '1/2/3 (bravo_levels.level)',
    exam_mode         ENUM('period','always') NOT NULL DEFAULT 'period' COMMENT '기간제/상시',
    start_at          DATETIME DEFAULT NULL COMMENT '응시 시작 (period 필수)',
    end_at            DATETIME DEFAULT NULL COMMENT '응시 종료 (period 필수)',
    result_release_at DATETIME DEFAULT NULL COMMENT '결과 발표일 (period 필수)',
    attempt_limit     TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '응시 횟수',
    target_type       ENUM('all','cohort') NOT NULL DEFAULT 'all',
    target_cohort_id  INT UNSIGNED DEFAULT NULL COMMENT 'cohort 일 때 cohorts.id',
    status            ENUM('preparing','open','closed','released') NOT NULL DEFAULT 'preparing' COMMENT '준비중/오픈/종료/결과발표 (수동)',
    created_by        INT UNSIGNED DEFAULT NULL COMMENT '생성 admin id',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_bravo_exams_status (status),
    KEY idx_bravo_exams_cohort (target_cohort_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_exams 생성 완료\n";

echo "\n=== Migration 완료 ===\n";
```

- [ ] **Step 4: DEV DB 적용**

Run: `cd /root/boot-dev && php migrate_bravo_exams.php`
Expected: "bravo_exams 생성 완료" / "Migration 완료".

- [ ] **Step 5: 스키마 테스트 통과 확인**

Run: `cd /root/boot-dev && php tests/bravo_exams_schema_invariants.php`
Expected: 모든 PASS, "15 pass, 0 fail" (1 테이블 + 14 컬럼).

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev
git add migrate_bravo_exams.php tests/bravo_exams_schema_invariants.php
git commit -m "feat(bravo): bravo_exams 테이블 마이그레이션 + 스키마 검증"
```

---

## Task 2: 시험 검증 순수 함수 (`api/services/bravo.php`)

**Files:**
- Modify: `public_html/api/services/bravo.php` (APPEND — 기존 함수 미수정)
- Test: `tests/bravo_exam_validate_test.php`

- [ ] **Step 1: 단위 테스트 작성 (먼저 실패)**

Create `tests/bravo_exam_validate_test.php`:

```php
<?php
/**
 * BRAVO 시험 검증 순수 함수 단위 테스트. DB 불필요.
 * 사용: php tests/bravo_exam_validate_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/api/services/bravo.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

function valid(): array {
    return [
        'title' => '6월 BRAVO 1',
        'bravo_level' => 1,
        'exam_mode' => 'period',
        'start_at' => '2026-06-01 10:00:00',
        'end_at' => '2026-06-02 10:00:00',
        'result_release_at' => '2026-06-12 10:00:00',
        'attempt_limit' => 3,
        'target_type' => 'all',
        'target_cohort_id' => null,
        'status' => 'preparing',
    ];
}

t('정상 period → 에러 없음', bravoValidateExam(valid()) === []);

$d = valid(); $d['title'] = '   ';
t('빈 제목 에러', in_array('시험명을 입력해주세요.', bravoValidateExam($d), true));

$d = valid(); $d['bravo_level'] = 4;
t('level 범위 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['exam_mode'] = 'weird';
t('mode 범위 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['status'] = 'nope';
t('status 범위 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['attempt_limit'] = 0;
t('attempt_limit<1 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['start_at'] = null;
t('period 시작일 누락 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['start_at'] = '2026-06-03 10:00:00'; // start > end
t('start > end 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['result_release_at'] = '2026-06-01 09:00:00'; // release < end
t('release < end 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['exam_mode'] = 'always'; $d['start_at'] = null; $d['end_at'] = null; $d['result_release_at'] = null;
t('always 모드 날짜 없어도 통과', bravoValidateExam($d) === []);

$d = valid(); $d['target_type'] = 'cohort'; $d['target_cohort_id'] = null;
t('cohort 타겟 cohort_id 누락 에러', count(bravoValidateExam($d)) >= 1);

$d = valid(); $d['target_type'] = 'cohort'; $d['target_cohort_id'] = 5;
t('cohort 타겟 cohort_id 있으면 통과', bravoValidateExam($d) === []);

$d = valid(); $d['target_type'] = 'bogus';
t('target_type 범위 에러', count(bravoValidateExam($d)) >= 1);

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_exam_validate_test.php`
Expected: FATAL (bravoValidateExam 미정의).

- [ ] **Step 3: 검증 함수 구현 (bravo.php 끝에 APPEND)**

Append to `public_html/api/services/bravo.php`:

```php

/**
 * 날짜 문자열 → unix timestamp (빈/무효는 null). 검증/비교용 순수 헬퍼.
 */
function bravoTs(?string $v): ?int {
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;
    $ts = strtotime($v);
    return $ts === false ? null : $ts;
}

/**
 * 시험 입력 검증. 에러 메시지 배열 반환 (빈 배열 = 통과). 순수 함수.
 */
function bravoValidateExam(array $d): array {
    $errors = [];

    $title = isset($d['title']) && is_string($d['title']) ? trim($d['title']) : '';
    if ($title === '') $errors[] = '시험명을 입력해주세요.';

    $level = isset($d['bravo_level']) ? (int)$d['bravo_level'] : 0;
    if (!in_array($level, [1,2,3], true)) $errors[] = 'BRAVO 등급은 1/2/3 중 하나여야 합니다.';

    $mode = $d['exam_mode'] ?? '';
    if (!in_array($mode, ['period','always'], true)) $errors[] = '응시 방식이 올바르지 않습니다.';

    $status = $d['status'] ?? 'preparing';
    if (!in_array($status, ['preparing','open','closed','released'], true)) $errors[] = '시험 상태가 올바르지 않습니다.';

    $limit = isset($d['attempt_limit']) ? (int)$d['attempt_limit'] : 0;
    if ($limit < 1) $errors[] = '응시 횟수는 1 이상이어야 합니다.';

    $target = $d['target_type'] ?? '';
    if (!in_array($target, ['all','cohort'], true)) {
        $errors[] = '대상 유형이 올바르지 않습니다.';
    } elseif ($target === 'cohort') {
        $cid = isset($d['target_cohort_id']) ? (int)$d['target_cohort_id'] : 0;
        if ($cid < 1) $errors[] = '특정 기수 대상일 때 기수를 선택해주세요.';
    }

    if ($mode === 'period') {
        $s = bravoTs($d['start_at'] ?? null);
        $e = bravoTs($d['end_at'] ?? null);
        $r = bravoTs($d['result_release_at'] ?? null);
        if ($s === null) $errors[] = '응시 시작일이 올바르지 않습니다.';
        if ($e === null) $errors[] = '응시 종료일이 올바르지 않습니다.';
        if ($r === null) $errors[] = '결과 발표일이 올바르지 않습니다.';
        if ($s !== null && $e !== null && $s > $e) $errors[] = '응시 시작일은 종료일보다 앞서야 합니다.';
        if ($e !== null && $r !== null && $r < $e) $errors[] = '결과 발표일은 응시 종료일 이후여야 합니다.';
    }

    return $errors;
}
```

- [ ] **Step 4: 통과 확인**

Run: `cd /root/boot-dev && php tests/bravo_exam_validate_test.php`
Expected: 모든 PASS, "14 pass, 0 fail".

- [ ] **Step 5: 기존 BRAVO 테스트 회귀 확인**

Run: `cd /root/boot-dev && php tests/bravo_qualification_test.php | tail -1`
Expected: "25 pass, 0 fail" (기존 순수함수 미변경).

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/services/bravo.php tests/bravo_exam_validate_test.php
git commit -m "feat(bravo): 시험 입력 검증 순수 함수 + 단위 테스트"
```

---

## Task 3: 시험 CRUD 서비스 함수 (`api/services/bravo.php`)

**Files:**
- Modify: `public_html/api/services/bravo.php` (APPEND)
- Test: `tests/bravo_exam_service_test.php`

- [ ] **Step 1: 통합 테스트 작성 (먼저 실패)**

Create `tests/bravo_exam_service_test.php`:

```php
<?php
/**
 * BRAVO 시험 CRUD 통합 테스트. DEV DB transaction rollback.
 * 사용: php tests/bravo_exam_service_test.php
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

$db = getDB();
$db->beginTransaction();
try {
    // 테스트 cohort
    $label = 'TEST_EXAM_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$label, $label]);
    $cohortId = (int)$db->lastInsertId();

    // --- create (period, 특정 기수) ---
    $id = bravoExamCreate($db, [
        'title' => '통합 BRAVO 2',
        'bravo_level' => 2,
        'exam_mode' => 'period',
        'start_at' => '2026-06-01 10:00:00',
        'end_at' => '2026-06-02 10:00:00',
        'result_release_at' => '2026-06-12 10:00:00',
        'attempt_limit' => 3,
        'target_type' => 'cohort',
        'target_cohort_id' => $cohortId,
        'status' => 'preparing',
    ], 99);
    t('create 반환 id > 0', $id > 0);

    $list = bravoExamList($db, ['target_cohort_id' => $cohortId]);
    $row = null; foreach ($list as $r) if ((int)$r['id'] === $id) $row = $r;
    t('list 에 생성된 시험 존재', $row !== null);
    t('level_name 조인', ($row['level_name'] ?? '') === 'BRAVO 2', $row['level_name'] ?? '(null)');
    t('cohort 라벨 조인', ($row['target_cohort_label'] ?? '') === $label);
    t('status preparing', $row['status'] === 'preparing');
    t('start_at 저장', strpos((string)$row['start_at'], '2026-06-01 10:00:00') === 0);

    // --- update (status 변경 + always 전환 → 날짜 NULL 정규화) ---
    bravoExamUpdate($db, $id, [
        'title' => '통합 BRAVO 2 (수정)',
        'bravo_level' => 2,
        'exam_mode' => 'always',
        'start_at' => '2026-06-01 10:00:00', // always 라 무시되어야
        'end_at' => '2026-06-02 10:00:00',
        'result_release_at' => '2026-06-12 10:00:00',
        'attempt_limit' => 1,
        'target_type' => 'all',
        'target_cohort_id' => null,
        'status' => 'open',
    ]);
    $list2 = bravoExamList($db);
    $row2 = null; foreach ($list2 as $r) if ((int)$r['id'] === $id) $row2 = $r;
    t('update 제목 반영', $row2['title'] === '통합 BRAVO 2 (수정)');
    t('update status open', $row2['status'] === 'open');
    t('always 모드 start_at NULL 정규화', $row2['start_at'] === null);
    t('always 모드 end_at NULL 정규화', $row2['end_at'] === null);
    t('update target all → cohort_id NULL', $row2['target_cohort_id'] === null);
    t('attempt_limit 1 반영', (int)$row2['attempt_limit'] === 1);

    // --- delete ---
    bravoExamDelete($db, $id);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM bravo_exams WHERE id = " . (int)$id)->fetchColumn();
    t('delete 후 row 0', $cnt === 0, 'cnt=' . $cnt);

    $db->rollBack();
} catch (\Throwable $e) {
    $db->rollBack();
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_exam_service_test.php`
Expected: FATAL (bravoExamCreate/List/Update/Delete 미정의).

- [ ] **Step 3: CRUD 함수 구현 (bravo.php 끝에 APPEND)**

Append to `public_html/api/services/bravo.php`:

```php

/**
 * 시험 날짜 문자열 → 'Y-m-d H:i:s' 정규화 (빈/무효는 null).
 */
function bravoFmtDt(?string $v): ?string {
    $ts = bravoTs($v);
    return $ts === null ? null : date('Y-m-d H:i:s', $ts);
}

/**
 * 폼 입력 → bravo_exams 저장용 정규화 컬럼 배열.
 * always 모드면 날짜 NULL, all 타겟이면 cohort_id NULL.
 */
function bravoExamPersistData(array $d): array {
    $mode   = in_array($d['exam_mode'] ?? '', ['period','always'], true) ? $d['exam_mode'] : 'period';
    $target = in_array($d['target_type'] ?? '', ['all','cohort'], true) ? $d['target_type'] : 'all';
    $status = in_array($d['status'] ?? '', ['preparing','open','closed','released'], true) ? $d['status'] : 'preparing';
    $isPeriod = $mode === 'period';
    $cid = ($target === 'cohort') ? ((int)($d['target_cohort_id'] ?? 0) ?: null) : null;
    return [
        'title'             => trim((string)($d['title'] ?? '')),
        'bravo_level'       => (int)($d['bravo_level'] ?? 0),
        'exam_mode'         => $mode,
        'start_at'          => $isPeriod ? bravoFmtDt($d['start_at'] ?? null) : null,
        'end_at'            => $isPeriod ? bravoFmtDt($d['end_at'] ?? null) : null,
        'result_release_at' => $isPeriod ? bravoFmtDt($d['result_release_at'] ?? null) : null,
        'attempt_limit'     => max(1, (int)($d['attempt_limit'] ?? 3)),
        'target_type'       => $target,
        'target_cohort_id'  => $cid,
        'status'            => $status,
    ];
}

/**
 * 시험 목록. level명/cohort라벨 조인. 선택 필터: status / bravo_level / target_cohort_id.
 */
function bravoExamList(PDO $db, array $filters = []): array {
    $where = []; $params = [];
    if (!empty($filters['status']))           { $where[] = 'e.status = ?';           $params[] = $filters['status']; }
    if (!empty($filters['bravo_level']))      { $where[] = 'e.bravo_level = ?';      $params[] = (int)$filters['bravo_level']; }
    if (!empty($filters['target_cohort_id'])) { $where[] = 'e.target_cohort_id = ?'; $params[] = (int)$filters['target_cohort_id']; }
    $sql = "SELECT e.*, bl.name AS level_name, c.cohort AS target_cohort_label
            FROM bravo_exams e
            LEFT JOIN bravo_levels bl ON e.bravo_level = bl.level
            LEFT JOIN cohorts c ON e.target_cohort_id = c.id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY e.id DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 시험 생성. 정규화 후 INSERT, 신규 id 반환.
 */
function bravoExamCreate(PDO $db, array $d, int $adminId): int {
    $c = bravoExamPersistData($d);
    $db->prepare("
        INSERT INTO bravo_exams
            (title, bravo_level, exam_mode, start_at, end_at, result_release_at, attempt_limit, target_type, target_cohort_id, status, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $c['title'], $c['bravo_level'], $c['exam_mode'], $c['start_at'], $c['end_at'], $c['result_release_at'],
        $c['attempt_limit'], $c['target_type'], $c['target_cohort_id'], $c['status'], $adminId,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * 시험 수정 (status 포함 전체 필드).
 */
function bravoExamUpdate(PDO $db, int $id, array $d): void {
    $c = bravoExamPersistData($d);
    $db->prepare("
        UPDATE bravo_exams SET
            title=?, bravo_level=?, exam_mode=?, start_at=?, end_at=?, result_release_at=?,
            attempt_limit=?, target_type=?, target_cohort_id=?, status=?
        WHERE id=?
    ")->execute([
        $c['title'], $c['bravo_level'], $c['exam_mode'], $c['start_at'], $c['end_at'], $c['result_release_at'],
        $c['attempt_limit'], $c['target_type'], $c['target_cohort_id'], $c['status'], $id,
    ]);
}

/**
 * 시험 삭제 (하드, 참조 테이블 없음).
 */
function bravoExamDelete(PDO $db, int $id): void {
    $db->prepare("DELETE FROM bravo_exams WHERE id = ?")->execute([$id]);
}
```

- [ ] **Step 4: 통과 확인**

Run: `cd /root/boot-dev && php tests/bravo_exam_service_test.php`
Expected: 모든 PASS, "14 pass, 0 fail".

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/services/bravo.php tests/bravo_exam_service_test.php
git commit -m "feat(bravo): 시험 CRUD 서비스 함수 + 통합 테스트"
```

---

## Task 4: 관리자 API case (admin.php)

**Files:**
- Modify: `public_html/api/admin.php` (switch 에 3 case 추가)

- [ ] **Step 1: case 추가** — `public_html/api/admin.php` 의 `switch ($action)` 안, 기존 `case 'bravo_member_update':` 블록 다음(또는 `bravo_member_list` 근처)에 추가:

```php
case 'bravo_exam_list':
    requireAdmin(['operation']);
    $db = getDB();
    $filters = [];
    if (!empty($_GET['status']) && is_string($_GET['status'])) $filters['status'] = $_GET['status'];
    if (!empty($_GET['bravo_level'])) $filters['bravo_level'] = (int)$_GET['bravo_level'];
    if (!empty($_GET['target_cohort_id'])) $filters['target_cohort_id'] = (int)$_GET['target_cohort_id'];
    $cohorts = $db->query("SELECT id, cohort FROM cohorts ORDER BY cohort")->fetchAll(PDO::FETCH_ASSOC);
    jsonSuccess([
        'exams'   => bravoExamList($db, $filters),
        'levels'  => bravoLoadLevels($db),
        'cohorts' => $cohorts,
    ]);
    break;

case 'bravo_exam_save':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $errors = bravoValidateExam($input);
    if ($errors) jsonError($errors[0]);
    $db = getDB();
    $id = (isset($input['id']) && (int)$input['id'] > 0) ? (int)$input['id'] : 0;
    if ($id > 0) {
        bravoExamUpdate($db, $id, $input);
        jsonSuccess(['id' => $id], '저장되었습니다.');
    } else {
        $newId = bravoExamCreate($db, $input, (int)$admin['admin_id']);
        jsonSuccess(['id' => $newId], '저장되었습니다.');
    }
    break;

case 'bravo_exam_delete':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    if ($id < 1) jsonError('id가 필요합니다.');
    $db = getDB();
    bravoExamDelete($db, $id);
    jsonSuccess([], '삭제되었습니다.');
    break;
```

> 참고: `bravo.php` 는 슬라이스1에서 이미 `require_once __DIR__ . '/services/bravo.php';` 로 admin.php 상단에 포함됨 — 추가 require 불필요. `$admin['admin_id']` 는 기존 updated_by 패턴 키(슬라이스1에서 검증됨).

- [ ] **Step 2: PHP 문법 검사**

Run: `cd /root/boot-dev && php -l public_html/api/admin.php`
Expected: "No syntax errors detected".

- [ ] **Step 3: require 확인 (이미 포함됨)**

Run: `cd /root/boot-dev && grep -n "services/bravo.php" public_html/api/admin.php`
Expected: 슬라이스1의 `require_once __DIR__ . '/services/bravo.php';` 한 줄이 보임. 없으면 상단 require 블록(notice.php 다음)에 추가.

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/admin.php
git commit -m "feat(bravo): 관리자 API case bravo_exam_list/save/delete"
```

---

## Task 5: 시험 관리 프론트 모듈 (`admin-bravo-exams.js`)

**Files:**
- Create: `public_html/js/admin-bravo-exams.js`
- Modify: `public_html/operation/index.php` (include)

- [ ] **Step 1: AdminBravoExamApp 모듈 작성**

Create `public_html/js/admin-bravo-exams.js`:

```javascript
/* ── BRAVO 시험 관리 (operation) ───────────────────────────── */
const AdminBravoExamApp = (() => {
    let container = null;
    let exams = [], levels = [], cohorts = [];
    let editingId = null;

    const STATUS = { preparing:'준비중', open:'오픈', closed:'종료', released:'결과발표' };
    const STATUS_KEYS = ['preparing','open','closed','released'];

    async function init(adminSession, containerId) {
        container = document.getElementById(containerId);
        if (!container) return;
        await load();
        render();
    }

    async function load() {
        const r = await App.get('/api/admin.php?action=bravo_exam_list');
        if (!r || r.success === false) { exams = []; levels = []; cohorts = []; return; }
        exams = r.exams || []; levels = r.levels || []; cohorts = r.cohorts || [];
    }

    function periodText(e) {
        if (e.exam_mode === 'always') return '상시';
        const s = (e.start_at || '').slice(0, 16);
        const en = (e.end_at || '').slice(0, 16);
        return `${s} ~ ${en}`;
    }

    function targetText(e) {
        return e.target_type === 'cohort'
            ? `${App.esc(e.target_cohort_label || '?')} 기수`
            : '전체';
    }

    function render() {
        if (!container) return;
        const rows = exams.map(e => `
            <tr>
                <td>${App.esc(e.title)}</td>
                <td>${App.esc(e.level_name || ('BRAVO ' + e.bravo_level))}</td>
                <td>${App.esc(periodText(e))}</td>
                <td>${targetText(e)}</td>
                <td class="num">${e.attempt_limit}회</td>
                <td>${STATUS[e.status] || App.esc(e.status)}</td>
                <td>
                    <button class="btn btn-sm bravo-exam-edit" data-id="${e.id}">수정</button>
                    <button class="btn btn-sm btn-danger bravo-exam-del" data-id="${e.id}">삭제</button>
                </td>
            </tr>`).join('');

        container.innerHTML = `
            <div class="bravo-exam-admin">
                <div class="bravo-exam-toolbar">
                    <button class="btn btn-primary btn-sm" id="bravo-exam-new">+ 시험 추가</button>
                </div>
                <div id="bravo-exam-form"></div>
                <table class="data-table">
                    <thead><tr>
                        <th>시험명</th><th>등급</th><th>기간</th><th>대상</th><th>응시횟수</th><th>상태</th><th></th>
                    </tr></thead>
                    <tbody>${rows || '<tr><td colspan="7">등록된 시험이 없습니다.</td></tr>'}</tbody>
                </table>
            </div>`;

        container.querySelector('#bravo-exam-new').addEventListener('click', () => openForm(null));
        container.querySelectorAll('.bravo-exam-edit').forEach(b =>
            b.addEventListener('click', () => openForm(parseInt(b.dataset.id, 10))));
        container.querySelectorAll('.bravo-exam-del').forEach(b =>
            b.addEventListener('click', () => onDelete(parseInt(b.dataset.id, 10))));
    }

    function toLocal(v) { return v ? v.slice(0, 16).replace(' ', 'T') : ''; }

    function openForm(id) {
        editingId = id;
        const e = id ? exams.find(x => parseInt(x.id, 10) === id) : null;
        const levelOpts = levels.map(l =>
            `<option value="${l.level}" ${e && parseInt(e.bravo_level, 10) === parseInt(l.level, 10) ? 'selected' : ''}>${App.esc(l.name)}</option>`).join('');
        const cohortOpts = cohorts.map(c =>
            `<option value="${c.id}" ${e && parseInt(e.target_cohort_id, 10) === parseInt(c.id, 10) ? 'selected' : ''}>${App.esc(c.cohort)}</option>`).join('');
        const mode = e ? e.exam_mode : 'period';
        const tgt = e ? e.target_type : 'all';
        const status = e ? e.status : 'preparing';
        const statusOpts = STATUS_KEYS.map(s =>
            `<option value="${s}" ${status === s ? 'selected' : ''}>${STATUS[s]}</option>`).join('');

        const formEl = container.querySelector('#bravo-exam-form');
        formEl.innerHTML = `
            <div class="bravo-exam-fields">
                <label>시험명 <input type="text" id="bx-title" value="${e ? App.esc(e.title) : ''}"></label>
                <label>등급 <select id="bx-level">${levelOpts}</select></label>
                <label>응시방식 <select id="bx-mode">
                    <option value="period" ${mode === 'period' ? 'selected' : ''}>기간제</option>
                    <option value="always" ${mode === 'always' ? 'selected' : ''}>상시</option>
                </select></label>
                <span class="bx-dates">
                    <label>시작 <input type="datetime-local" id="bx-start" value="${toLocal(e && e.start_at)}"></label>
                    <label>종료 <input type="datetime-local" id="bx-end" value="${toLocal(e && e.end_at)}"></label>
                    <label>발표 <input type="datetime-local" id="bx-release" value="${toLocal(e && e.result_release_at)}"></label>
                </span>
                <label>응시횟수 <input type="number" id="bx-limit" min="1" value="${e ? e.attempt_limit : 3}" style="width:4em"></label>
                <label>대상 <select id="bx-target">
                    <option value="all" ${tgt === 'all' ? 'selected' : ''}>전체</option>
                    <option value="cohort" ${tgt === 'cohort' ? 'selected' : ''}>특정 기수</option>
                </select></label>
                <select id="bx-cohort" ${tgt === 'cohort' ? '' : 'disabled'}>${cohortOpts}</select>
                <label>상태 <select id="bx-status">${statusOpts}</select></label>
                <button class="btn btn-primary btn-sm" id="bx-save">저장</button>
                <button class="btn btn-sm" id="bx-cancel">취소</button>
            </div>`;

        const modeSel = formEl.querySelector('#bx-mode');
        const datesEl = formEl.querySelector('.bx-dates');
        const toggleDates = () => { datesEl.style.display = modeSel.value === 'always' ? 'none' : ''; };
        modeSel.addEventListener('change', toggleDates); toggleDates();

        const tgtSel = formEl.querySelector('#bx-target');
        const cohortSel = formEl.querySelector('#bx-cohort');
        tgtSel.addEventListener('change', () => { cohortSel.disabled = tgtSel.value !== 'cohort'; });

        formEl.querySelector('#bx-save').addEventListener('click', onSave);
        formEl.querySelector('#bx-cancel').addEventListener('click', () => { formEl.innerHTML = ''; editingId = null; });
    }

    function localToDt(v) { return v ? v.replace('T', ' ') + ':00' : null; }

    async function onSave() {
        const f = container.querySelector('#bravo-exam-form');
        const mode = f.querySelector('#bx-mode').value;
        const target = f.querySelector('#bx-target').value;
        const payload = {
            title: f.querySelector('#bx-title').value,
            bravo_level: parseInt(f.querySelector('#bx-level').value, 10),
            exam_mode: mode,
            start_at: mode === 'period' ? localToDt(f.querySelector('#bx-start').value) : null,
            end_at: mode === 'period' ? localToDt(f.querySelector('#bx-end').value) : null,
            result_release_at: mode === 'period' ? localToDt(f.querySelector('#bx-release').value) : null,
            attempt_limit: parseInt(f.querySelector('#bx-limit').value, 10) || 1,
            target_type: target,
            target_cohort_id: target === 'cohort' ? parseInt(f.querySelector('#bx-cohort').value, 10) : null,
            status: f.querySelector('#bx-status').value,
        };
        if (editingId) payload.id = editingId;
        const r = await App.post('/api/admin.php?action=bravo_exam_save', payload);
        if (r && r.success !== false) {
            Toast.success('저장되었습니다.');
            await load();
            render();
        } else {
            Toast.error((r && r.error) || '저장 실패');
        }
    }

    async function onDelete(id) {
        if (!confirm('이 시험을 삭제할까요?')) return;
        const r = await App.post('/api/admin.php?action=bravo_exam_delete', { id });
        if (r && r.success !== false) {
            Toast.success('삭제되었습니다.');
            await load();
            render();
        } else {
            Toast.error((r && r.error) || '삭제 실패');
        }
    }

    return { init };
})();
```

> Toast API 는 슬라이스1에서 확인된 실제 시그니처 `Toast.success(msg)`/`Toast.error(msg)`. App.get/post/esc 는 common.js 제공. 확인용으로 `public_html/js/toast.js` 와 `public_html/js/admin-multipass.js` 호출 관행을 한 번 대조하고, 다르면 맞춘다.

- [ ] **Step 2: operation/index.php 에 include 추가** — `public_html/operation/index.php` 의 script 목록에서 `admin-bravo.js` 줄 **앞**(또는 근처)에, 그리고 `AdminApp.init()` 보다 앞에 추가:

```php
    <script src="/js/admin-bravo-exams.js<?= v('/js/admin-bravo-exams.js') ?>"></script>
```

- [ ] **Step 3: JS 문법 검사**

Run: `cd /root/boot-dev && node --check public_html/js/admin-bravo-exams.js`
Expected: 출력 없음(문법 OK).

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/admin-bravo-exams.js public_html/operation/index.php
git commit -m "feat(bravo): 시험 관리 프론트 모듈 AdminBravoExamApp"
```

---

## Task 6: BRAVO 탭 서브탭화 (`admin-bravo.js` 리팩터 + 탭 라벨)

**Files:**
- Modify: `public_html/js/admin-bravo.js` (서브탭 셸로 리팩터, 회원 자격 뷰 보존)
- Modify: `public_html/js/admin.js` (탭 라벨 `BRAVO 자격` → `BRAVO`)

- [ ] **Step 1: admin-bravo.js 를 서브탭 셸로 리팩터** — `public_html/js/admin-bravo.js` 전체를 아래로 교체 (회원 자격 로직은 보존하되 컨테이너를 서브탭 하위 div 로, 시험 서브탭은 AdminBravoExamApp 마운트):

```javascript
/* ── BRAVO 관리 (operation) — 서브탭 셸: 회원 자격 / 시험 관리 ── */
const AdminBravoApp = (() => {
    let admin = null;
    let root = null;
    let active = 'qual';
    let examsMounted = false;

    // 회원 자격 뷰 상태
    let qualContainer = null;
    let levels = [];
    let members = [];

    async function init(adminSession, containerId) {
        admin = adminSession;
        root = document.getElementById(containerId);
        if (!root) return;
        active = 'qual';
        examsMounted = false;
        root.innerHTML = `
            <div class="bravo-subtabs">
                <button class="bravo-subtab active" data-sub="qual">회원 자격</button>
                <button class="bravo-subtab" data-sub="exams">시험 관리</button>
            </div>
            <div class="bravo-sub" id="bravo-sub-qual"></div>
            <div class="bravo-sub" id="bravo-sub-exams" style="display:none"></div>`;
        root.querySelectorAll('.bravo-subtab').forEach(b =>
            b.addEventListener('click', () => switchSub(b.dataset.sub)));
        qualContainer = root.querySelector('#bravo-sub-qual');
        await loadQual();
        renderQual();
    }

    function switchSub(sub) {
        if (sub === active) return;
        active = sub;
        root.querySelectorAll('.bravo-subtab').forEach(b =>
            b.classList.toggle('active', b.dataset.sub === sub));
        root.querySelector('#bravo-sub-qual').style.display = sub === 'qual' ? '' : 'none';
        root.querySelector('#bravo-sub-exams').style.display = sub === 'exams' ? '' : 'none';
        if (sub === 'exams' && !examsMounted && typeof AdminBravoExamApp !== 'undefined') {
            examsMounted = true;
            AdminBravoExamApp.init(admin, 'bravo-sub-exams');
        }
    }

    // ── 회원 자격 뷰 (슬라이스1 로직 보존) ──
    async function loadQual() {
        const r = await App.get('/api/admin.php?action=bravo_member_list');
        if (!r || r.success === false) { members = []; levels = []; return; }
        members = r.members || [];
        levels = r.levels || [];
    }

    function levelChip(eligible) {
        if (!eligible || eligible.length === 0) return '<span class="bravo-chip none">없음</span>';
        return eligible.map(l => `<span class="bravo-chip lv${l}">BRAVO ${l}</span>`).join(' ');
    }

    function grantCheckboxes(granted) {
        return [1,2,3].map(l => {
            const checked = granted.includes(l) ? 'checked' : '';
            return `<label class="bravo-grant"><input type="checkbox" data-grant="${l}" ${checked}> ${l}</label>`;
        }).join(' ');
    }

    function renderQual() {
        if (!qualContainer) return;
        const thresholdInfo = levels.map(l => `BRAVO ${l.level}: ${l.required_review_count}회독·${l.passing_score}점`).join(' / ');
        const rows = members.map(m => {
            const ov = m.review_count_override === null ? '' : m.review_count_override;
            return `
            <tr data-user="${App.esc(m.user_id)}">
                <td>${App.esc(m.real_name || '')}<br><small>${App.esc(m.nickname || '')}</small></td>
                <td>${App.esc(m.phone || '')}</td>
                <td class="num">${m.completed_bootcamp_count}</td>
                <td><input type="number" class="bravo-override" min="0" max="99" value="${ov}" placeholder="자동(${m.completed_bootcamp_count})" style="width:5em"></td>
                <td class="num">${m.effective_review_count}</td>
                <td>${grantCheckboxes(m.granted_levels)}</td>
                <td>${levelChip(m.eligible_levels)}</td>
                <td><input type="text" class="bravo-notes" value="${App.esc(m.notes || '')}" placeholder="메모"></td>
                <td><button class="btn btn-primary btn-sm bravo-save">저장</button></td>
            </tr>`;
        }).join('');

        qualContainer.innerHTML = `
            <div class="bravo-admin">
                <p class="bravo-help">응시 자격 임계 — ${App.esc(thresholdInfo)}. 회독수 override 비우면 자동(완주횟수) 사용. 수동부여는 계산과 무관하게 응시 허용.</p>
                <table class="data-table bravo-table">
                    <thead><tr>
                        <th>회원</th><th>전화번호</th><th>완주(자동)</th><th>override</th><th>유효회독</th><th>수동부여</th><th>응시가능</th><th>메모</th><th></th>
                    </tr></thead>
                    <tbody>${rows || '<tr><td colspan="9">회원이 없습니다.</td></tr>'}</tbody>
                </table>
            </div>`;

        qualContainer.querySelectorAll('.bravo-save').forEach(btn => {
            btn.addEventListener('click', onSaveQual);
        });
    }

    async function onSaveQual(e) {
        const tr = e.target.closest('tr');
        if (!tr) return;
        const userId = tr.dataset.user;
        const ovRaw = tr.querySelector('.bravo-override').value.trim();
        const granted = Array.from(tr.querySelectorAll('input[data-grant]:checked')).map(c => parseInt(c.dataset.grant, 10));
        const notes = tr.querySelector('.bravo-notes').value;
        const payload = {
            user_id: userId,
            review_count_override: ovRaw === '' ? null : parseInt(ovRaw, 10),
            granted_levels: granted,
            notes: notes,
        };
        const r = await App.post('/api/admin.php?action=bravo_member_update', payload);
        if (r && r.success !== false) {
            Toast.success('저장되었습니다.');
            await loadQual();
            renderQual();
        } else {
            Toast.error((r && r.error) || '저장 실패');
        }
    }

    return { init };
})();
```

- [ ] **Step 2: admin.js 탭 라벨 변경** — `public_html/js/admin.js` 에서 operation 레이아웃의 BRAVO 탭 버튼 라벨을 변경:

기존:
```html
                            <button class="tab" data-tab="#tab-bravo" data-hash="bravo">BRAVO 자격</button>
```
변경:
```html
                            <button class="tab" data-tab="#tab-bravo" data-hash="bravo">BRAVO</button>
```
(data-tab/data-hash/`tab-bravo` div/lazy-load observer 는 그대로 — 셸 init 이 서브탭을 그림.)

- [ ] **Step 3: JS 문법 검사**

Run: `cd /root/boot-dev && node --check public_html/js/admin-bravo.js && node --check public_html/js/admin.js`
Expected: 둘 다 출력 없음.

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/admin-bravo.js public_html/js/admin.js
git commit -m "feat(bravo): BRAVO 탭 서브탭화(회원 자격/시험 관리)"
```

---

## Task 7: 전체 검증 + dev push (운영 반영 안 함)

**Files:** (검증 전용)

- [ ] **Step 1: 전체 BRAVO 테스트 재실행**

Run:
```bash
cd /root/boot-dev
for f in bravo_schema_invariants bravo_qualification_test bravo_admin_service_test bravo_exams_schema_invariants bravo_exam_validate_test bravo_exam_service_test; do
  echo "== $f =="; php tests/$f.php | tail -1
done
```
Expected: 모든 파일 "0 fail".

- [ ] **Step 2: 회귀 — 기존 자격 뷰/엔드포인트 정상**

Run: `cd /root/boot-dev && grep -n "action=bravo_member_list\|action=bravo_member_update" public_html/js/admin-bravo.js`
Expected: 서브탭 셸 안에 회원 자격 엔드포인트 호출이 보존됨(2개).

- [ ] **Step 3: HTTP 스모크 (DEV)**

DEV 운영자 세션으로 `https://dev-boot.soritune.com` 운영 SPA:
- `BRAVO` 탭 → 서브탭 `회원 자격`(슬라이스1 그대로 동작) / `시험 관리` 전환 확인
- `시험 관리`: `+ 시험 추가` → period 시험 입력(시작/종료/발표, 기수 선택) 저장 → 목록 등장
- 상시(always) 선택 시 날짜 입력 숨김 → 저장 후 목록 "상시" 표기
- 수정 → 상태 `오픈` 변경 저장 → 목록 반영
- 삭제 → 목록에서 제거
- 잘못된 입력(발표일 < 종료일) 저장 → 에러 토스트

Expected: 위 동작 정상.

- [ ] **Step 4: dev push**

```bash
cd /root/boot-dev
git push origin dev
```

- [ ] **Step 5: 운영 반영 안 함 — 멈춤**

이 프로젝트는 슬라이스별 운영 배포를 하지 않는다. dev push 까지만 하고 멈춘다. (BRAVO 전체 완성 시 일괄 운영 반영)

---

## Self-Review (작성자 체크 완료)

- **Spec coverage:** bravo_exams 테이블(T1) ✓ / 검증 순수함수 period·always·target·범위(T2) ✓ / CRUD + always날짜NULL·cohort라벨조인(T3) ✓ / API list·save·delete + 입력가드(T4) ✓ / 시험 프론트 모듈(T5) ✓ / 서브탭화·자격뷰보존·탭리네이밍(T6) ✓ / 코어만(15-6·OT·개별회원·자동전환 제외) ✓ / dev-only(T7) ✓.
- **Placeholder scan:** 모든 코드 스텝 실제 코드 포함. Toast/App 헬퍼는 "확인 후 맞춤" 주석으로 가정 노출(슬라이스1에서 이미 검증된 시그니처).
- **Type consistency:** 서비스 함수명 `bravoTs`/`bravoFmtDt`/`bravoExamPersistData`/`bravoValidateExam`/`bravoExamList`/`bravoExamCreate`/`bravoExamUpdate`/`bravoExamDelete` T2/T3/T4 일치. API 응답키 `exams`/`levels`/`cohorts` 와 프론트 소비(T5) 일치. 저장 payload 키(title/bravo_level/exam_mode/start_at/end_at/result_release_at/attempt_limit/target_type/target_cohort_id/status/id) 가 프론트(T5)·API(T4)·검증/CRUD(T2/T3) 전반 일치. cohort 응답 `{id, cohort}` 와 프론트 옵션 렌더 일치.
