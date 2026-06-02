# BRAVO 도전 시스템 1차 슬라이스 (기반 + 관리자 골격) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** boot.soritune.com 에 소리블록 BRAVO 등급 시험 시스템의 기반(신규 테이블 2개 + 자격 자동계산)과 관리자 "BRAVO 자격" 페이지 골격을, 기존 `member_history_stats.bravo_grade` 를 일절 건드리지 않는 순수 추가형으로 구축한다.

**Architecture:** PHP+PDO. 신규 테이블 `bravo_levels`(등급설정 시드) / `bravo_member_settings`(회원별 override·수동부여·메모). 자격은 저장하지 않고 `api/services/bravo.php` 의 순수 함수로 읽을 때 계산(유효회독 = override ?? 기존 completed_bootcamp_count → bravo_levels 임계로 등급 산출 ∪ 수동부여). 관리자 API 는 admin.php 의 얇은 case 가 bravo.php 서비스로 위임. 프론트는 operation SPA 에 신규 탭 + `js/admin-bravo.js` 모듈.

**Tech Stack:** PHP 8 (PDO/MariaDB), vanilla JS SPA (App.get/App.post fetch 헬퍼, IIFE 모듈), CLI 테스트(`php tests/xxx.php`, DEV DB transaction rollback).

**작업 규칙(중요):** 모든 작업은 `/root/boot-dev` (DEV_BOOT, dev 브랜치)에서만. DB는 DEV DB(`boot-dev/.db_credentials`)에 먼저 적용. dev push 후 멈추고 사용자 확인. PROD는 사용자 명시 요청 시에만.

**스펙 원본:** `docs/superpowers/specs/2026-06-02-bravo-test-foundation-design.md`

---

## File Structure

- Create: `migrate_bravo.php` — 신규 테이블 2개 생성 + bravo_levels 시드 (멱등)
- Create: `public_html/api/services/bravo.php` — 자격계산 순수 함수 + 관리자 데이터 서비스 함수
- Create: `tests/bravo_schema_invariants.php` — 스키마/시드 검증
- Create: `tests/bravo_qualification_test.php` — 자격계산 순수 함수 단위 테스트
- Create: `tests/bravo_admin_service_test.php` — 관리자 서비스 함수 통합 테스트(DEV DB txn rollback)
- Create: `public_html/js/admin-bravo.js` — 관리자 BRAVO 자격 페이지 모듈
- Modify: `public_html/api/admin.php` — `require_once services/bravo.php` + `bravo_member_list` / `bravo_member_update` case 추가
- Modify: `public_html/js/admin.js` — operation 탭 버튼 + tab-content div + lazy-load observer
- Modify: `public_html/operation/index.php` — `<script src="/js/admin-bravo.js<?= v(...) ?>">` 추가

---

## Task 1: 마이그레이션 — 신규 테이블 + 시드

**Files:**
- Create: `migrate_bravo.php`
- Test: `tests/bravo_schema_invariants.php`

- [ ] **Step 1: 스키마 검증 테스트 작성 (먼저 실패)**

Create `tests/bravo_schema_invariants.php`:

```php
<?php
/**
 * BRAVO 스키마/시드 검증. 사용: php tests/bravo_schema_invariants.php
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

function tableExists($db, $name): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn() === 1;
}

t('bravo_levels 테이블 존재', tableExists($db, 'bravo_levels'));
t('bravo_member_settings 테이블 존재', tableExists($db, 'bravo_member_settings'));

$levels = $db->query("SELECT level, name, required_review_count, passing_score, requires_previous_level FROM bravo_levels ORDER BY level")->fetchAll(PDO::FETCH_ASSOC);
t('bravo_levels 시드 3행', count($levels) === 3, 'count=' . count($levels));
$expected = [
    ['level'=>1,'name'=>'BRAVO 1','required_review_count'=>3,'passing_score'=>50,'requires_previous_level'=>0],
    ['level'=>2,'name'=>'BRAVO 2','required_review_count'=>6,'passing_score'=>65,'requires_previous_level'=>1],
    ['level'=>3,'name'=>'BRAVO 3','required_review_count'=>10,'passing_score'=>80,'requires_previous_level'=>1],
];
foreach ($expected as $i => $e) {
    $row = $levels[$i] ?? [];
    $ok = (int)($row['level']??-1)===$e['level']
        && ($row['name']??'')===$e['name']
        && (int)($row['required_review_count']??-1)===$e['required_review_count']
        && (int)($row['passing_score']??-1)===$e['passing_score']
        && (int)($row['requires_previous_level']??-1)===$e['requires_previous_level'];
    t("bravo_levels 시드 level {$e['level']} 값", $ok, json_encode($row, JSON_UNESCAPED_UNICODE));
}

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 테스트 실행해서 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_schema_invariants.php`
Expected: FAIL (테이블 없음 — "Base table or view not found" 또는 tableExists FAIL)

- [ ] **Step 3: 마이그레이션 작성**

Create `migrate_bravo.php`:

```php
<?php
/**
 * Migration: BRAVO 도전 시스템 1차 슬라이스 테이블
 * 실행: php migrate_bravo.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS + 시드 INSERT ... ON DUPLICATE KEY UPDATE
 * 기존 member_history_stats.bravo_grade 와 무관한 순수 추가형.
 */
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: BRAVO 도전 시스템 (기반) ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_levels (
    level                   TINYINT UNSIGNED PRIMARY KEY,
    name                    VARCHAR(20)  NOT NULL,
    required_review_count   TINYINT UNSIGNED NOT NULL,
    passing_score           TINYINT UNSIGNED NOT NULL,
    requires_previous_level TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'doc 7-2 권장 메타데이터. 1차 자동계산엔 미적용',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_levels 생성 완료\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_member_settings (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id               VARCHAR(100) NOT NULL,
    review_count_override TINYINT UNSIGNED DEFAULT NULL COMMENT 'NULL=자동(completed_bootcamp_count) 사용',
    granted_levels        SET('1','2','3') DEFAULT NULL COMMENT '수동부여 등급 (계산과 무관하게 응시 허용)',
    notes                 TEXT DEFAULT NULL,
    updated_by            INT UNSIGNED DEFAULT NULL COMMENT '마지막 수정 admin id',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bms_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_member_settings 생성 완료\n";

$seed = $db->prepare("
    INSERT INTO bravo_levels (level, name, required_review_count, passing_score, requires_previous_level)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        required_review_count = VALUES(required_review_count),
        passing_score = VALUES(passing_score),
        requires_previous_level = VALUES(requires_previous_level)
");
$seed->execute([1, 'BRAVO 1', 3, 50, 0]);
$seed->execute([2, 'BRAVO 2', 6, 65, 1]);
$seed->execute([3, 'BRAVO 3', 10, 80, 1]);
echo "bravo_levels 시드 완료 (3행)\n";

echo "\n=== Migration 완료 ===\n";
```

- [ ] **Step 4: DEV DB 에 마이그레이션 실행**

Run: `cd /root/boot-dev && php migrate_bravo.php`
Expected: "bravo_levels 생성 완료" / "bravo_member_settings 생성 완료" / "bravo_levels 시드 완료 (3행)" / "Migration 완료"

- [ ] **Step 5: 스키마 테스트 실행해서 통과 확인**

Run: `cd /root/boot-dev && php tests/bravo_schema_invariants.php`
Expected: 모든 PASS, "5 pass, 0 fail" (2 테이블 + 시드 3행 카운트 + 3개 값 = 6 PASS; 정확히는 6 pass)

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev
git add migrate_bravo.php tests/bravo_schema_invariants.php
git commit -m "feat(bravo): 기반 테이블 bravo_levels/bravo_member_settings 마이그레이션 + 스키마 검증"
```

---

## Task 2: 자격계산 순수 함수 (`api/services/bravo.php`)

**Files:**
- Create: `public_html/api/services/bravo.php`
- Test: `tests/bravo_qualification_test.php`

- [ ] **Step 1: 단위 테스트 작성 (먼저 실패)**

Create `tests/bravo_qualification_test.php`:

```php
<?php
/**
 * BRAVO 자격계산 순수 함수 단위 테스트.
 * 사용: php tests/bravo_qualification_test.php
 * DB 불필요 (순수 함수). bravo_levels 임계는 픽스처로 주입.
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

// 픽스처: bravo_levels 임계 (실제 시드와 동일 형태)
$levels = [
    ['level'=>1,'required_review_count'=>3],
    ['level'=>2,'required_review_count'=>6],
    ['level'=>3,'required_review_count'=>10],
];

// bravoEffectiveReviewCount: override 우선, NULL이면 completed
t('유효회독 override 우선', bravoEffectiveReviewCount(8, 2) === 8);
t('유효회독 override NULL이면 completed', bravoEffectiveReviewCount(null, 5) === 5);
t('유효회독 override 0도 유효(자동 아님)', bravoEffectiveReviewCount(0, 9) === 0);

// bravoAutoEligibleLevels: 회독수 임계 (경계)
t('자동 0회독 → 없음', bravoAutoEligibleLevels(0, $levels) === []);
t('자동 2회독 → 없음', bravoAutoEligibleLevels(2, $levels) === []);
t('자동 3회독 → [1]', bravoAutoEligibleLevels(3, $levels) === [1]);
t('자동 5회독 → [1]', bravoAutoEligibleLevels(5, $levels) === [1]);
t('자동 6회독 → [1,2]', bravoAutoEligibleLevels(6, $levels) === [1,2]);
t('자동 9회독 → [1,2]', bravoAutoEligibleLevels(9, $levels) === [1,2]);
t('자동 10회독 → [1,2,3]', bravoAutoEligibleLevels(10, $levels) === [1,2,3]);
t('자동 15회독 → [1,2,3]', bravoAutoEligibleLevels(15, $levels) === [1,2,3]);

// bravoEligibleLevels: 자동 ∪ 수동부여, 정렬·중복제거
t('최종 자동만 (override NULL, completed 6, grant 없음)',
    bravoEligibleLevels(null, 6, [], $levels) === [1,2]);
t('최종 수동부여 합집합 (자동 [1] + grant [3])',
    bravoEligibleLevels(null, 3, [3], $levels) === [1,3]);
t('최종 수동부여 중복제거 (자동 [1,2] + grant [1])',
    bravoEligibleLevels(null, 6, [1], $levels) === [1,2]);
t('최종 override가 자동 좌우 (override 10, completed 1)',
    bravoEligibleLevels(10, 1, [], $levels) === [1,2,3]);
t('최종 grant만 (자동 없음 + grant [2])',
    bravoEligibleLevels(null, 0, [2], $levels) === [2]);

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 테스트 실행해서 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_qualification_test.php`
Expected: FATAL — `require`로 부르는 `bravo.php` 가 없어 파일 not found 에러

- [ ] **Step 3: 서비스 파일 + 순수 함수 작성**

Create `public_html/api/services/bravo.php`:

```php
<?php
/**
 * BRAVO 도전 시스템 서비스.
 * 1차 슬라이스: 자격 자동계산 순수 함수 + 관리자 데이터 서비스.
 * 기존 member_history_stats.bravo_grade 와 무관한 추가형.
 */

/**
 * 유효 회독수 = override 가 있으면 그 값, 없으면(NULL) 자동 completed_bootcamp_count.
 * override 0 은 명시적 0 으로 자동값을 덮는다 (NULL 만 자동).
 */
function bravoEffectiveReviewCount(?int $override, int $completedCount): int {
    return $override !== null ? $override : $completedCount;
}

/**
 * 회독수 임계 기준 자동 응시가능 등급 목록. (doc 15-3, 회독수만)
 * $levels: [['level'=>int,'required_review_count'=>int], ...]
 * 반환: 오름차순 level 배열.
 */
function bravoAutoEligibleLevels(int $reviewCount, array $levels): array {
    $out = [];
    foreach ($levels as $lv) {
        if ($reviewCount >= (int)$lv['required_review_count']) {
            $out[] = (int)$lv['level'];
        }
    }
    sort($out);
    return $out;
}

/**
 * 최종 응시가능 등급 = 자동 ∪ 수동부여. 중복제거·오름차순.
 * $grantedLevels: int 배열 (예: [1,3]).
 */
function bravoEligibleLevels(?int $override, int $completedCount, array $grantedLevels, array $levels): array {
    $review = bravoEffectiveReviewCount($override, $completedCount);
    $auto   = bravoAutoEligibleLevels($review, $levels);
    $union  = array_values(array_unique(array_merge($auto, array_map('intval', $grantedLevels))));
    sort($union);
    return $union;
}

/**
 * granted_levels SET 컬럼 문자열("1,3")을 int 배열로 파싱.
 */
function bravoParseGrantedLevels(?string $raw): array {
    if ($raw === null || $raw === '') return [];
    $out = [];
    foreach (explode(',', $raw) as $p) {
        $p = trim($p);
        if ($p !== '' && in_array($p, ['1','2','3'], true)) $out[] = (int)$p;
    }
    sort($out);
    return $out;
}

/**
 * int 배열을 granted_levels SET 저장용 문자열로. 빈 배열이면 '' (NULL 처리는 호출부).
 */
function bravoFormatGrantedLevels(array $levels): string {
    $valid = [];
    foreach ($levels as $l) {
        $l = (int)$l;
        if (in_array($l, [1,2,3], true)) $valid[$l] = true;
    }
    $keys = array_keys($valid);
    sort($keys);
    return implode(',', $keys);
}
```

- [ ] **Step 4: 테스트 실행해서 통과 확인**

Run: `cd /root/boot-dev && php tests/bravo_qualification_test.php`
Expected: 모든 PASS, "18 pass, 0 fail"

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/services/bravo.php tests/bravo_qualification_test.php
git commit -m "feat(bravo): 자격 자동계산 순수 함수 + 단위 테스트"
```

---

## Task 3: 관리자 데이터 서비스 함수 (bravo.php 확장)

**Files:**
- Modify: `public_html/api/services/bravo.php` (함수 추가)
- Test: `tests/bravo_admin_service_test.php`

- [ ] **Step 1: 통합 테스트 작성 (먼저 실패)**

Create `tests/bravo_admin_service_test.php`:

```php
<?php
/**
 * BRAVO 관리자 서비스 통합 테스트. DEV DB transaction rollback.
 * 사용: php tests/bravo_admin_service_test.php
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
    // 테스트 cohort + 회원 2명 (user_id 보유)
    $label = 'TEST_BRV_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$label, $label]);
    $cohortId = (int)$db->lastInsertId();

    $uidA = 'brv_a_' . bin2hex(random_bytes(3));
    $uidB = 'brv_b_' . bin2hex(random_bytes(3));
    $ins = $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, member_status, is_active, stage_no, joined_at) VALUES (?, ?, ?, ?, ?, 'active', 1, 1, CURDATE())");
    $ins->execute([$cohortId, '김알파', '알파', '01000000001', $uidA]);
    $ins->execute([$cohortId, '이베타', '베타', '01000000002', $uidB]);

    // member_history_stats: A는 user_id-row 로 completed 7, B는 phone-row 로 completed 2
    $db->prepare("INSERT INTO member_history_stats (user_id, stage1_participation_count, stage2_participation_count, completed_bootcamp_count, last_calculated_at) VALUES (?, 0, 0, 7, NOW())")
       ->execute([$uidA]);
    $db->prepare("INSERT INTO member_history_stats (phone, stage1_participation_count, stage2_participation_count, completed_bootcamp_count, last_calculated_at) VALUES (?, 0, 0, 2, NOW())")
       ->execute(['01000000002']);

    // --- bravoMemberList: 기수 회원 + 자동 completed + 계산 등급 ---
    $list = bravoMemberList($db, $label);
    $byUid = [];
    foreach ($list as $r) $byUid[$r['user_id']] = $r;

    t('list 2명 반환', count($list) === 2, 'count=' . count($list));
    t('A completed 7 (user_id-row)', (int)$byUid[$uidA]['completed_bootcamp_count'] === 7);
    t('A override 없음 → 유효회독 7 → 등급 [1,2]', $byUid[$uidA]['eligible_levels'] === [1,2], json_encode($byUid[$uidA]['eligible_levels']));
    t('B completed 2 (phone-row 폴백)', (int)$byUid[$uidB]['completed_bootcamp_count'] === 2);
    t('B 등급 없음', $byUid[$uidB]['eligible_levels'] === []);

    // --- bravoMemberUpsert: A에 override 10 + grant [3] + notes ---
    bravoMemberUpsert($db, $uidA, 10, [3], '예외 승인', 99);
    $list2 = bravoMemberList($db, $label);
    $a2 = null; foreach ($list2 as $r) if ($r['user_id'] === $uidA) $a2 = $r;
    t('A override 10 반영', (int)$a2['review_count_override'] === 10);
    t('A granted_levels [3]', $a2['granted_levels'] === [3], json_encode($a2['granted_levels']));
    t('A notes 반영', $a2['notes'] === '예외 승인');
    t('A 등급 override10 ∪ grant3 → [1,2,3]', $a2['eligible_levels'] === [1,2,3], json_encode($a2['eligible_levels']));

    // --- upsert 멱등: 같은 user_id 재호출 시 update (중복 row 없음) ---
    bravoMemberUpsert($db, $uidA, null, [], '메모수정', 99);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM bravo_member_settings WHERE user_id = " . $db->quote($uidA))->fetchColumn();
    t('upsert 멱등 (row 1개)', $cnt === 1, 'cnt=' . $cnt);
    $list3 = bravoMemberList($db, $label);
    $a3 = null; foreach ($list3 as $r) if ($r['user_id'] === $uidA) $a3 = $r;
    t('A override NULL 복귀 → 자동 7 → [1,2]', $a3['eligible_levels'] === [1,2], json_encode($a3['eligible_levels']));
    t('A granted 비움', $a3['granted_levels'] === []);

    $db->rollBack();
} catch (\Throwable $e) {
    $db->rollBack();
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 테스트 실행해서 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_admin_service_test.php`
Expected: FATAL — `bravoMemberList`/`bravoMemberUpsert` 미정의 (Call to undefined function)

- [ ] **Step 3: 서비스 함수 구현 (bravo.php 끝에 추가)**

Append to `public_html/api/services/bravo.php`:

```php

/**
 * bravo_levels 설정 로드 (자격계산 임계의 단일 진실원).
 */
function bravoLoadLevels(PDO $db): array {
    return $db->query("SELECT level, name, required_review_count, passing_score, requires_previous_level FROM bravo_levels ORDER BY level")
              ->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 특정 기수 회원 목록 + 자동 completed_bootcamp_count + 계산된 응시가능 등급.
 * 기존 member_list 조인 패턴 재사용 (user_id-row 우선, phone-row 폴백).
 */
function bravoMemberList(PDO $db, string $cohort): array {
    $levels = bravoLoadLevels($db);
    $stmt = $db->prepare("
        SELECT bm.user_id, bm.real_name, bm.nickname, bm.phone,
               COALESCE(mhs_u.completed_bootcamp_count, mhs_p.completed_bootcamp_count, 0) AS completed_bootcamp_count,
               bms.review_count_override, bms.granted_levels, bms.notes
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
        LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
        LEFT JOIN bravo_member_settings bms ON bm.user_id = bms.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
        WHERE c.cohort = ? AND bm.member_status NOT IN ('refunded','expelled')
        ORDER BY bm.real_name
    ");
    $stmt->execute([$cohort]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $override = $r['review_count_override'] !== null ? (int)$r['review_count_override'] : null;
        $granted  = bravoParseGrantedLevels($r['granted_levels']);
        $completed = (int)$r['completed_bootcamp_count'];
        $out[] = [
            'user_id'                  => $r['user_id'],
            'real_name'                => $r['real_name'],
            'nickname'                 => $r['nickname'],
            'phone'                    => $r['phone'],
            'completed_bootcamp_count' => $completed,
            'review_count_override'    => $override,
            'effective_review_count'   => bravoEffectiveReviewCount($override, $completed),
            'granted_levels'           => $granted,
            'notes'                    => $r['notes'],
            'eligible_levels'          => bravoEligibleLevels($override, $completed, $granted, $levels),
        ];
    }
    return $out;
}

/**
 * 회원 BRAVO 설정 upsert (user_id 기준). override NULL = 자동복귀, grant [] = 비움.
 */
function bravoMemberUpsert(PDO $db, string $userId, ?int $override, array $grantedLevels, ?string $notes, ?int $adminId): void {
    $grantedStr = bravoFormatGrantedLevels($grantedLevels);
    $grantedVal = $grantedStr === '' ? null : $grantedStr;
    $notesVal   = ($notes !== null && trim($notes) !== '') ? $notes : null;
    $db->prepare("
        INSERT INTO bravo_member_settings (user_id, review_count_override, granted_levels, notes, updated_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            review_count_override = VALUES(review_count_override),
            granted_levels        = VALUES(granted_levels),
            notes                 = VALUES(notes),
            updated_by            = VALUES(updated_by)
    ")->execute([$userId, $override, $grantedVal, $notesVal, $adminId]);
}
```

- [ ] **Step 4: 테스트 실행해서 통과 확인**

Run: `cd /root/boot-dev && php tests/bravo_admin_service_test.php`
Expected: 모든 PASS, "13 pass, 0 fail"

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/services/bravo.php tests/bravo_admin_service_test.php
git commit -m "feat(bravo): 관리자 데이터 서비스(member_list/upsert) + 통합 테스트"
```

---

## Task 4: 관리자 API case (admin.php)

**Files:**
- Modify: `public_html/api/admin.php` (상단 require + switch에 2 case 추가)

- [ ] **Step 1: require_once 추가**

`public_html/api/admin.php` 상단 require 블록(다른 `require_once __DIR__ . '/services/...';` 들 근처, `require_once __DIR__ . '/services/notice.php';` 다음 줄)에 추가:

```php
require_once __DIR__ . '/services/bravo.php';
```

- [ ] **Step 2: bravo_member_list / bravo_member_update case 추가**

`public_html/api/admin.php` 의 `switch ($action) { ... }` 안, 기존 `case 'member_list':` 블록 바로 앞(또는 뒤)에 추가:

```php
case 'bravo_member_list':
    $admin = requireAdmin(['operation']);
    $cohort = getEffectiveCohort($admin);
    $db = getDB();
    jsonSuccess([
        'members' => bravoMemberList($db, $cohort),
        'levels'  => bravoLoadLevels($db),
    ]);
    break;

case 'bravo_member_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $userId = trim($input['user_id'] ?? '');
    if ($userId === '') jsonError('user_id가 필요합니다.');

    // override: 빈 문자열/미전달 → NULL(자동), 숫자 → 0~99 정수
    $override = null;
    if (isset($input['review_count_override']) && $input['review_count_override'] !== '' && $input['review_count_override'] !== null) {
        $override = (int)$input['review_count_override'];
        if ($override < 0)  $override = 0;
        if ($override > 99) $override = 99;
    }
    // granted_levels: 배열(또는 미전달 → [])
    $granted = [];
    if (isset($input['granted_levels']) && is_array($input['granted_levels'])) {
        foreach ($input['granted_levels'] as $g) {
            $gi = (int)$g;
            if (in_array($gi, [1,2,3], true)) $granted[] = $gi;
        }
    }
    $notes = isset($input['notes']) ? (string)$input['notes'] : null;

    $db = getDB();
    bravoMemberUpsert($db, $userId, $override, $granted, $notes, (int)$admin['admin_id']);
    jsonSuccess([], '저장되었습니다.');
    break;
```

> 참고: `$admin['admin_id']` 키는 로그인 응답/세션의 admin 식별자. 실제 세션 배열 키가 다르면(`requireAdmin()` 반환 구조 확인) 맞춰 사용한다. (예: `$admin['id']`)

- [ ] **Step 3: requireAdmin 반환 구조 확인 (admin_id 키 검증)**

Run: `cd /root/boot-dev && grep -n "function requireAdmin\|function getAdminSession\|'admin_id'\|admin_id =>" public_html/auth.php public_html/api/admin.php | head`
Expected: requireAdmin 이 반환하는 배열의 admin 식별 키를 확인. Step 2 의 `$admin['admin_id']` 가 실제 키와 일치하는지 맞추고, 다르면 그 키로 수정.

- [ ] **Step 4: PHP 문법 검사**

Run: `cd /root/boot-dev && php -l public_html/api/admin.php`
Expected: "No syntax errors detected in public_html/api/admin.php"

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/admin.php
git commit -m "feat(bravo): 관리자 API case bravo_member_list/bravo_member_update"
```

---

## Task 5: 관리자 프론트 — BRAVO 자격 페이지 모듈

**Files:**
- Create: `public_html/js/admin-bravo.js`
- Modify: `public_html/js/admin.js` (탭 버튼 + tab-content + lazy-load observer)
- Modify: `public_html/operation/index.php` (script include)

- [ ] **Step 1: admin-bravo.js 모듈 작성**

Create `public_html/js/admin-bravo.js`:

```javascript
/* ── BRAVO 자격 관리 (operation) ───────────────────────────── */
const AdminBravoApp = (() => {
    let container = null;
    let levels = [];
    let members = [];

    async function init(adminSession, containerId) {
        container = document.getElementById(containerId);
        if (!container) return;
        await load();
        render();
    }

    async function load() {
        const r = await App.get('/api/admin.php?action=bravo_member_list');
        if (!r || r.success === false) { members = []; levels = []; return; }
        members = r.members || [];
        levels = r.levels || [];
    }

    function levelChip(eligible) {
        if (!eligible || eligible.length === 0) return '<span class="bravo-chip none">없음</span>';
        return eligible.map(l => `<span class="bravo-chip lv${l}">BRAVO ${l}</span>`).join(' ');
    }

    function grantCheckboxes(userId, granted) {
        return [1,2,3].map(l => {
            const checked = granted.includes(l) ? 'checked' : '';
            return `<label class="bravo-grant"><input type="checkbox" data-grant="${l}" ${checked}> ${l}</label>`;
        }).join(' ');
    }

    function render() {
        if (!container) return;
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
                <td>${grantCheckboxes(m.user_id, m.granted_levels)}</td>
                <td>${levelChip(m.eligible_levels)}</td>
                <td><input type="text" class="bravo-notes" value="${App.esc(m.notes || '')}" placeholder="메모"></td>
                <td><button class="btn btn-primary btn-sm bravo-save">저장</button></td>
            </tr>`;
        }).join('');

        container.innerHTML = `
            <div class="bravo-admin">
                <p class="bravo-help">응시 자격 임계 — ${App.esc(thresholdInfo)}. 회독수 override 비우면 자동(완주횟수) 사용. 수동부여는 계산과 무관하게 응시 허용.</p>
                <table class="data-table bravo-table">
                    <thead><tr>
                        <th>회원</th><th>전화번호</th><th>완주(자동)</th><th>override</th><th>유효회독</th><th>수동부여</th><th>응시가능</th><th>메모</th><th></th>
                    </tr></thead>
                    <tbody>${rows || '<tr><td colspan="9">회원이 없습니다.</td></tr>'}</tbody>
                </table>
            </div>`;

        container.querySelectorAll('.bravo-save').forEach(btn => {
            btn.addEventListener('click', onSave);
        });
    }

    async function onSave(e) {
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
            if (typeof Toast !== 'undefined') Toast.show('저장되었습니다.');
            await load();
            render();
        } else {
            if (typeof Toast !== 'undefined') Toast.show((r && r.error) || '저장 실패', 'error');
        }
    }

    return { init };
})();
```

> 참고: `Toast.show` 시그니처가 다르면(예: `Toast.error`) 기존 모듈(`js/admin-multipass.js` 등) 호출 관행에 맞춘다. `App.esc`/`App.get`/`App.post` 는 common.js 제공.

- [ ] **Step 2: admin.js 에 탭 버튼 추가**

`public_html/js/admin.js` 의 operation 탭 목록(`data-tab` 버튼들, 대략 line 203 `리텐션 관리` 버튼 뒤)에 추가:

```html
                            <button class="tab" data-tab="#tab-bravo" data-hash="bravo">BRAVO 자격</button>
```

그리고 같은 영역의 tab-content div 목록(line 205~ 의 `<div class="tab-content" ...>` 들)에 대응 div 추가:

```html
                        <div class="tab-content" id="tab-bravo"></div>
```

- [ ] **Step 3: admin.js 에 lazy-load observer 추가**

`public_html/js/admin.js` 의 Multipass lazy-load 블록(대략 line 499~510) 패턴을 따라, 같은 init 영역에 추가:

```javascript
            // BRAVO 자격 탭 lazy load (operation)
            if (typeof AdminBravoApp !== 'undefined') {
                const bravoTab = document.getElementById('tab-bravo');
                if (bravoTab) {
                    const bravoObserver = new MutationObserver(() => {
                        if (bravoTab.classList.contains('active') && !bravoTab.dataset.loaded) {
                            bravoTab.dataset.loaded = '1';
                            AdminBravoApp.init(admin, 'tab-bravo');
                        }
                    });
                    bravoObserver.observe(bravoTab, { attributes: true, attributeFilter: ['class'] });
                }
            }
```

- [ ] **Step 4: operation/index.php 에 script include 추가**

`public_html/operation/index.php` 의 script 목록(예: `admin-multipass.js` 줄 근처)에 추가:

```php
    <script src="/js/admin-bravo.js<?= v('/js/admin-bravo.js') ?>"></script>
```

> `v()` 가 mtime 기반 자동 캐시버스팅이므로 수동 `?v=` 갱신 불필요.

- [ ] **Step 5: JS 문법 검사**

Run: `cd /root/boot-dev && node --check public_html/js/admin-bravo.js`
Expected: 출력 없음(문법 OK). node 없으면 생략하고 브라우저 콘솔로 확인.

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/admin-bravo.js public_html/js/admin.js public_html/operation/index.php
git commit -m "feat(bravo): 관리자 BRAVO 자격 페이지(operation 탭) 프론트"
```

---

## Task 6: HTTP 스모크 + 회귀 확인 + dev push

**Files:** (검증 전용, 코드 변경 없음 — 발견 시 수정)

- [ ] **Step 1: 전체 BRAVO 테스트 재실행 (회귀 없음 확인)**

Run:
```bash
cd /root/boot-dev
php tests/bravo_schema_invariants.php && php tests/bravo_qualification_test.php && php tests/bravo_admin_service_test.php
```
Expected: 세 테스트 모두 "0 fail".

- [ ] **Step 2: 기존 member_list/bravo_grade 표시 회귀 스모크**

기존 자동등급 표시가 그대로인지(추가형이라 미변경이지만 확인): DEV 사이트에서 운영 SPA `회원 관리` 탭 로드 → bravo_grade 컬럼 기존대로 표시되는지 육안 확인. 또는:

Run: `cd /root/boot-dev && grep -rn "bravo_grade" public_html/api/admin.php | head`
Expected: 기존 `member_list` 의 `COALESCE(mhs_u.bravo_grade, mhs_p.bravo_grade)` 가 그대로 존재(미수정).

- [ ] **Step 3: 관리자 API HTTP 스모크 (DEV)**

DEV 운영자 계정으로 로그인된 세션 쿠키로 (브라우저 개발자도구 또는 로그인 후 curl):
- `GET https://dev-boot.soritune.com/api/admin.php?action=bravo_member_list` → `{success:true, members:[...], levels:[3행]}` 형태 확인
- 운영 SPA 에서 `BRAVO 자격` 탭 클릭 → 회원 목록 로드, override 입력·수동부여 체크·메모 입력 후 `저장` → 토스트 + 값 유지(재로드 후 반영) 확인
- override 비우고 저장 → 자동(완주횟수)로 복귀, 응시가능 등급 재계산 확인

Expected: 위 동작 정상. (회원 식별/자격계산이 의도대로)

- [ ] **Step 4: dev 브랜치 push**

```bash
cd /root/boot-dev
git push origin dev
```

- [ ] **Step 5: ⛔ 멈춤 — 사용자에게 DEV 확인 요청**

dev push 후 **반드시 멈추고** 사용자에게 `https://dev-boot.soritune.com` 운영 SPA `BRAVO 자격` 탭 확인을 요청한다. **사용자가 "운영 반영해줘" 등 명시적으로 요청한 경우에만** main 머지 + prod pull + PROD DB 마이그(`migrate_bravo.php`) 진행. (배포 플로우/DB 규칙 준수)

---

## Self-Review (작성자 체크 완료)

- **Spec coverage:** 신규 테이블 2개(Task1) ✓ / 자격 자동계산 하이브리드(Task2) ✓ / 관리자 자격 페이지 골격(Task3-5) ✓ / RBAC operation 게이팅(Task4) ✓ / 기존 bravo_grade 미수정·추가형(Task6 회귀확인) ✓ / 제외범위(응시UI·인증서·grandfather) 미포함 ✓.
- **Placeholder scan:** 모든 코드 스텝에 실제 코드 포함. Task4 `admin_id` 키, Task5 `Toast` 시그니처는 "확인 후 맞춤" 명시 스텝/주석으로 처리(가정 노출).
- **Type consistency:** `bravoEffectiveReviewCount`/`bravoAutoEligibleLevels`/`bravoEligibleLevels`/`bravoParseGrantedLevels`/`bravoFormatGrantedLevels`/`bravoLoadLevels`/`bravoMemberList`/`bravoMemberUpsert` 시그니처가 Task2/3/4/5 전체에서 일치. `eligible_levels`/`granted_levels`/`review_count_override`/`effective_review_count` 응답 키가 서비스·테스트·프론트에서 동일.
