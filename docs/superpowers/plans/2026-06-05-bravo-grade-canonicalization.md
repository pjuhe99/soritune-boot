# BRAVO 등급 단일화·강등·누적 횟수 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 브라보 등급의 진실원을 `bravo_member_grades.current_level` 하나로 통일 — 자동 등급 부여 중단(freeze), grandfather backfill, 셀프 강등, 등급당 평생 3회 누적 quota(+관리자 추가 부여), 보유/이전등급/quota 게이트.

**Architecture:** 신규 standalone 서비스 `bravo_grades.php`(테이블 2: grades+log)가 등급·quota·mutex 의 단일 진실원. `bravo.php` 가 이를 require — released 전환 훅(bravoExamUpdate)·status 재구성. 게이트(bravo_attempts.php)는 released-pass 차단을 보유/이전등급/누적 quota 3종으로 교체하고, 동시성은 grades 행 FOR UPDATE(사람 단위 mutex — 행이 항상 존재해 gap-lock 한계 없음)로 직렬화. 표시 5곳은 레거시 `bravo_grade` 읽기를 신규 테이블 JOIN 으로 교체(응답 키·'Bravo N' 포맷 유지 — 프론트 표시 코드 무수정).

**Tech Stack:** PHP 8 + PDO(MariaDB), vanilla JS. 테스트 = CLI + DEV DB 트랜잭션 롤백.

**스펙:** `docs/superpowers/specs/2026-06-05-bravo-grade-canonicalization-design.md`
**작업 디렉토리:** `/root/boot-dev` (dev 브랜치). ⛔ git push 금지(컨트롤러가 마지막에), PROD 접근 금지, main 체크아웃 금지, `git add .` 금지, destructive DB op 금지(`TRUNCATE`/`DROP` — 기존 recalcAllMemberStats 의 TRUNCATE 는 기존 코드라 무수정).
**테스트 파일 메모:** 스펙 §9는 "신규 2파일"이지만 태스크 독립성을 위해 **신규 5파일로 분할** (schema_invariants / grade_core / grade_policy / grade_gates / grade_display). 회귀 = 기존 19 + 신규 5 = **24파일**.

---

### Task 1: 마이그레이션 + 서비스 코어 (`bravo_grades.php`)

**Files:**
- Create: `public_html/api/services/bravo_grades.php` (standalone — 다른 서비스 require 안 함)
- Create: `migrate_bravo_member_grades.php`
- Test: `tests/bravo_member_grades_schema_invariants.php`, `tests/bravo_grade_core_test.php`

- [ ] **Step 1: 스키마 불변식 테스트 작성 (failing)**

`tests/bravo_member_grades_schema_invariants.php`:

```php
<?php
/**
 * bravo_member_grades / bravo_grade_log 스키마 불변식. DEV DB.
 * 사용: php tests/bravo_member_grades_schema_invariants.php
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

foreach (['bravo_member_grades', 'bravo_grade_log'] as $tbl) {
    $exists = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$tbl}'")->fetchColumn();
    t("{$tbl} 테이블 존재", (int)$exists === 1);
    if ((int)$exists !== 1) { echo "\n결과: {$pass} pass, {$fail} fail\n"; exit(1); }
}

// bravo_member_grades
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_member_grades") as $c) $cols[$c['Field']] = $c;
foreach (['id','member_key','current_level','extra_attempts_1','extra_attempts_2','extra_attempts_3','updated_at'] as $col) {
    t("grades.{$col} 존재", isset($cols[$col]));
}
t('current_level 기본 0', (string)$cols['current_level']['Default'] === '0');
t('extra_attempts_1 기본 0', (string)$cols['extra_attempts_1']['Default'] === '0');
t('member_key VARCHAR(120) NOT NULL', stripos($cols['member_key']['Type'], 'varchar(120)') === 0 && $cols['member_key']['Null'] === 'NO');
$idx = $db->query("SHOW INDEX FROM bravo_member_grades WHERE Key_name='uk_bmg_member'")->fetchAll();
t('member_key UNIQUE', count($idx) === 1 && (int)$idx[0]['Non_unique'] === 0);

// bravo_grade_log
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_grade_log") as $c) $cols[$c['Field']] = $c;
foreach (['id','member_key','from_level','to_level','source','ref_id','note','created_at'] as $col) {
    t("log.{$col} 존재", isset($cols[$col]));
}
t('source ENUM 4값', stripos($cols['source']['Type'], "enum('grandfather','exam_pass','self_demotion','admin_adjust')") === 0);
t('ref_id NULL 허용', $cols['ref_id']['Null'] === 'YES');
$ix = $db->query("SHOW INDEX FROM bravo_grade_log WHERE Key_name='idx_bgl_member'")->fetchAll();
t('idx_bgl_member 2컬럼 비유니크', count($ix) === 2 && (int)$ix[0]['Non_unique'] === 1);

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 코어 테스트 작성 (failing)**

`tests/bravo_grade_core_test.php`:

```php
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

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 3: 실패 확인**

Run: `php tests/bravo_member_grades_schema_invariants.php; php tests/bravo_grade_core_test.php`
Expected: 테이블 부재 FAIL / 서비스 파일 부재 Fatal

- [ ] **Step 4: 서비스 코어 구현**

`public_html/api/services/bravo_grades.php` (신규 — **standalone, 다른 서비스 require 금지**):

```php
<?php
/**
 * BRAVO 등급 진실원 서비스 (등급 단일화 슬라이스).
 * - bravo_member_grades: 사람 단위(member_key = user_id ?: 'p:'+phone) 현재 등급 + 추가 응시 횟수.
 *   행 부재 = 무등급(0)·추가횟수 0. 이 행은 등급당 quota 동시성 mutex 로도 사용 (bravoGradeLockRow).
 * - bravo_grade_log: 모든 등급 변경 이력 (grandfather/exam_pass/self_demotion/admin_adjust).
 * 레거시 member_history_stats.bravo_grade 는 backfill 후 freeze — 이 테이블이 유일한 진실원.
 */

const BRAVO_BASE_ATTEMPTS_PER_LEVEL = 3; // 등급당 평생 기본 응시 횟수 (초과는 관리자 extra 부여 — 유료 정책 운영 수동)

/**
 * 현재 등급 (행 부재 = 0).
 */
function bravoGradeCurrentLevel(PDO $db, string $memberKey): int {
    $stmt = $db->prepare("SELECT current_level FROM bravo_member_grades WHERE member_key = ?");
    $stmt->execute([$memberKey]);
    $v = $stmt->fetchColumn();
    return $v === false ? 0 : (int)$v;
}

/**
 * 등급 행 전체 (없으면 null).
 */
function bravoGradeRow(PDO $db, string $memberKey): ?array {
    $stmt = $db->prepare("SELECT * FROM bravo_member_grades WHERE member_key = ?");
    $stmt->execute([$memberKey]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * 사람 단위 mutex: 행 보장(INSERT IGNORE) 후 FOR UPDATE 잠금. 트랜잭션 안에서만 호출(호출부 책임).
 * 행이 항상 존재하므로 빈 범위 gap-lock 한계 없이 같은 사람의 동시 작업이 직렬화된다.
 */
function bravoGradeLockRow(PDO $db, string $memberKey): array {
    $db->prepare("INSERT IGNORE INTO bravo_member_grades (member_key) VALUES (?)")->execute([$memberKey]);
    $stmt = $db->prepare("SELECT * FROM bravo_member_grades WHERE member_key = ? FOR UPDATE");
    $stmt->execute([$memberKey]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * 등급 설정 + 이력 로그. 같은 레벨이면 no-op(로그 없음). 0~3 클램프.
 */
function bravoGradeSet(PDO $db, string $memberKey, int $to, string $source, ?int $refId = null, ?string $note = null): void {
    $to = max(0, min(3, $to));
    $from = bravoGradeCurrentLevel($db, $memberKey);
    if ($from === $to) return;
    $db->prepare("
        INSERT INTO bravo_member_grades (member_key, current_level) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE current_level = VALUES(current_level)
    ")->execute([$memberKey, $to]);
    $db->prepare("INSERT INTO bravo_grade_log (member_key, from_level, to_level, source, ref_id, note) VALUES (?,?,?,?,?,?)")
       ->execute([$memberKey, $from, $to, $source, $refId, $note !== null ? mb_substr($note, 0, 255) : null]);
}

/**
 * 셀프 강등: 한 단계 하향 (B1→0 포함). 0 이면 에러. 사람 행 잠금으로 더블클릭 멱등.
 * 성공: ['from'=>n, 'to'=>n-1]
 */
function bravoGradeDemote(PDO $db, string $memberKey): array {
    $owns = !$db->inTransaction();
    if ($owns) $db->beginTransaction();
    try {
        $row = bravoGradeLockRow($db, $memberKey);
        $from = (int)$row['current_level'];
        if ($from < 1) {
            if ($owns) $db->rollBack();
            return ['error' => '내릴 등급이 없습니다.'];
        }
        $to = $from - 1;
        bravoGradeSet($db, $memberKey, $to, 'self_demotion', null, null);
        if ($owns) $db->commit();
        return ['from' => $from, 'to' => $to];
    } catch (Throwable $e) {
        if ($owns) $db->rollBack();
        throw $e;
    }
}

/**
 * released 전환 훅: 그 시험의 확정 pass 전원을 max(현재, 시험등급) 으로 승급. 변경 인원 반환.
 * 이미 같거나 높은 등급은 no-op(로그 없음) — released 재전환에도 멱등.
 */
function bravoGradeApplyExamPass(PDO $db, array $exam): int {
    $level = (int)$exam['bravo_level'];
    $stmt = $db->prepare("
        SELECT DISTINCT a.member_key
        FROM bravo_attempts a
        JOIN bravo_attempt_grades g ON g.attempt_id = a.id AND g.result = 'pass'
        WHERE a.exam_id = ?
    ");
    $stmt->execute([(int)$exam['id']]);
    $n = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
        if (bravoGradeCurrentLevel($db, (string)$key) < $level) {
            bravoGradeSet($db, (string)$key, $level, 'exam_pass', (int)$exam['id'], isset($exam['title']) ? (string)$exam['title'] : null);
            $n++;
        }
    }
    return $n;
}

/**
 * 등급당 평생 누적 quota: used = 그 등급 모든 시험의 attempt 수 합산, limit = 기본 3 + 관리자 extra.
 */
function bravoAttemptQuotaForLevel(PDO $db, string $memberKey, int $level): array {
    $cnt = $db->prepare("
        SELECT COUNT(*)
        FROM bravo_attempts a
        JOIN bravo_exams e ON e.id = a.exam_id
        WHERE a.member_key = ? AND e.bravo_level = ?
    ");
    $cnt->execute([$memberKey, $level]);
    $used = (int)$cnt->fetchColumn();
    $row = bravoGradeRow($db, $memberKey);
    $extraCol = 'extra_attempts_' . max(1, min(3, $level));
    $extra = $row ? (int)$row[$extraCol] : 0;
    $limit = BRAVO_BASE_ATTEMPTS_PER_LEVEL + $extra;
    return ['used' => $used, 'limit' => $limit, 'left' => max(0, $limit - $used)];
}

/**
 * 레거시 member_history_stats.bravo_grade → bravo_member_grades backfill (grandfather). 멱등.
 * - user_id 행 우선. phone 행은 그 phone 의 bootcamp_members 가 user_id 를 갖지 않을 때만 'p:'+phone 키로
 *   (이중 카운트 방지 — 표시 경로의 COALESCE(user행, phone행) 우선순위와 동일).
 * - 'Bravo N' 문자열 파싱. 기존 current_level 이 같거나 높으면 skip (안 내림 — GREATEST 의미).
 */
function bravoGradeBackfillFromLegacy(PDO $db): array {
    $parse = function (?string $g): int {
        return ($g !== null && preg_match('/(\d)/', $g, $m)) ? max(0, min(3, (int)$m[1])) : 0;
    };
    $byKey = [];
    foreach ($db->query("SELECT user_id, bravo_grade FROM member_history_stats WHERE user_id IS NOT NULL AND user_id != '' AND bravo_grade IS NOT NULL") as $r) {
        $lv = $parse($r['bravo_grade']);
        if ($lv >= 1) $byKey[(string)$r['user_id']] = max($byKey[(string)$r['user_id']] ?? 0, $lv);
    }
    $uidByPhone = $db->prepare("SELECT user_id FROM bootcamp_members WHERE phone = ? AND user_id IS NOT NULL AND user_id != '' LIMIT 1");
    foreach ($db->query("SELECT phone, bravo_grade FROM member_history_stats WHERE phone IS NOT NULL AND phone != '' AND bravo_grade IS NOT NULL") as $r) {
        $lv = $parse($r['bravo_grade']);
        if ($lv < 1) continue;
        $uidByPhone->execute([$r['phone']]);
        $uid = $uidByPhone->fetchColumn();
        $key = ($uid !== false && $uid !== '' && $uid !== null) ? (string)$uid : 'p:' . $r['phone'];
        $byKey[$key] = max($byKey[$key] ?? 0, $lv);
    }
    $applied = 0; $skipped = 0;
    foreach ($byKey as $key => $lv) {
        if (bravoGradeCurrentLevel($db, $key) >= $lv) { $skipped++; continue; }
        bravoGradeSet($db, $key, $lv, 'grandfather', null, 'legacy bravo_grade backfill');
        $applied++;
    }
    return ['applied' => $applied, 'skipped' => $skipped];
}
```

- [ ] **Step 5: 마이그레이션 작성**

`migrate_bravo_member_grades.php`:

```php
<?php
/**
 * Migration: BRAVO 등급 단일화 — bravo_member_grades / bravo_grade_log + grandfather backfill
 * 실행: php migrate_bravo_member_grades.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS + backfill 은 기존 등급이 같거나 높으면 skip.
 * ⚠️ 운영 반영 시 git pull 보다 먼저 실행 (스펙 §11 — 새 코드는 이 테이블을 즉시 조회).
 */
require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/api/services/bravo_grades.php';

$db = getDB();

echo "=== Migration: bravo_member_grades / bravo_grade_log ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_member_grades (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_key       VARCHAR(120) NOT NULL COMMENT 'user_id ?: p:<phone> — bravoAttemptMemberKey 와 동일 규약',
    current_level    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=무등급, 1~3',
    extra_attempts_1 TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'B1 추가 응시 횟수 (관리자 부여 — 유료 정책 운영 수동)',
    extra_attempts_2 TINYINT UNSIGNED NOT NULL DEFAULT 0,
    extra_attempts_3 TINYINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bmg_member (member_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_member_grades 생성 완료\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_grade_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_key  VARCHAR(120) NOT NULL,
    from_level  TINYINT UNSIGNED NOT NULL,
    to_level    TINYINT UNSIGNED NOT NULL,
    source      ENUM('grandfather','exam_pass','self_demotion','admin_adjust') NOT NULL,
    ref_id      INT UNSIGNED NULL COMMENT 'exam_pass=exam_id, admin_adjust=admin_id, 그 외 NULL',
    note        VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bgl_member (member_key, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_grade_log 생성 완료\n";

$r = bravoGradeBackfillFromLegacy($db);
echo "grandfather backfill: applied {$r['applied']}, skipped {$r['skipped']}\n";

echo "\n=== Migration 완료 ===\n";
```

- [ ] **Step 6: DEV 적용 + 테스트 통과 + 멱등 확인**

```bash
cd /root/boot-dev
php migrate_bravo_member_grades.php
php migrate_bravo_member_grades.php   # 멱등 — applied 0
php tests/bravo_member_grades_schema_invariants.php
php tests/bravo_grade_core_test.php
```
Expected: 1차 backfill applied ≈ 230+ (DEV 분포 B1 157/B2 76/B3 5 의 사람 단위 dedupe 결과), 2차 applied 0. 테스트 전체 PASS.

- [ ] **Step 7: Commit**

```bash
git add public_html/api/services/bravo_grades.php migrate_bravo_member_grades.php tests/bravo_member_grades_schema_invariants.php tests/bravo_grade_core_test.php
git commit -m "feat(bravo): 등급 진실원 서비스 + 마이그 (grandfather backfill, 사람단위 mutex 행)"
```

---

### Task 2: released 전환 훅 + quota 함수 검증

**Files:**
- Modify: `public_html/api/services/bravo.php` — 상단 require 추가 + `bravoExamUpdate`(현재 266-277행) 교체
- Test: `tests/bravo_grade_policy_test.php`

- [ ] **Step 1: 테스트 작성 (failing)**

`tests/bravo_grade_policy_test.php`:

```php
<?php
/**
 * BRAVO released 훅 + 누적 quota 테스트. DEV DB 트랜잭션 롤백.
 * 사용: php tests/bravo_grade_policy_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
define('BRAVO_UPLOAD_ROOT', sys_get_temp_dir() . '/bravo_policy_test_' . getmypid());
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

$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TGP_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1)")
       ->execute(["{$tag}기", $tag]);
    $cohortId = (int)$db->lastInsertId();
    $mkMember = function (int $i) use ($db, $cohortId, $tag): int {
        $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
           ->execute([$cohortId, "{$tag}회원{$i}", "{$tag}닉{$i}", '0100000030' . $i, "{$tag}_uid{$i}"]);
        $mid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute(["{$tag}_uid{$i}"]);
        return $mid;
    };
    $J_MAX  = ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good','completion_ok'=>1];
    $J_ZERO = ['accuracy'=>'wrong','chunk_ok'=>0,'response_rating'=>'poor','fluency_rating'=>'poor','completion_ok'=>0];
    $gradeAttempt = function (int $memberId, int $examId, array $qids, array $judge, string $result) use ($db): array {
        $acc = bravoAttemptExamAccess($db, $memberId, $examId);
        if (isset($acc['error'])) throw new RuntimeException('access: ' . $acc['error']);
        $r = bravoAttemptStart($db, $acc['exam'], $acc['ctx']['row'], $acc['member_key'], false);
        if (isset($r['error'])) throw new RuntimeException('start: ' . $r['error']);
        $attempt = $r['attempt'];
        foreach ($qids as $q) {
            $f = tempnam(sys_get_temp_dir(), 'tgp_'); file_put_contents($f, 'audio');
            bravoAnswerStore($db, $attempt, $q, $f, 'audio/webm', 'webm', 3000, false);
        }
        bravoAttemptSubmit($db, $attempt);
        $attempt = bravoAttemptGet($db, (int)$attempt['id']);
        foreach ($qids as $q) {
            $aid = (int)$db->query("SELECT id FROM bravo_answers WHERE attempt_id=" . (int)$attempt['id'] . " AND question_id=" . (int)$q)->fetchColumn();
            bravoGradeSave($db, $attempt, $acc['exam'], $aid, $judge, 99);
        }
        $c = bravoAttemptConfirm($db, $attempt, $acc['exam'], ['result' => $result], 99);
        if (isset($c['error'])) throw new RuntimeException('confirm: ' . $c['error']);
        return ['attempt' => $attempt, 'member_key' => $acc['member_key']];
    };
    $mkExam = function (int $level, string $suffix) use ($db, $tag): array {
        $id = bravoExamCreate($db, ['title'=>"{$tag} {$suffix}",'bravo_level'=>$level,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
        $q = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>$level,'korean_text'=>"{$tag} q{$suffix}",'english_text'=>'a','accepted_answers'=>'','target_chunks'=>'c','difficulty'=>'easy','is_active'=>1], 99);
        bravoExamQuestionSet($db, $id, [$q]);
        return [$id, [$q]];
    };

    // ── released 훅: pass 승급 / fail 제외 ──
    [$examA, $qA] = $mkExam(1, 'A');
    $m1 = $mkMember(1); $m2 = $mkMember(2);
    $r1 = $gradeAttempt($m1, $examA, $qA, $J_MAX, 'pass');
    $r2 = $gradeAttempt($m2, $examA, $qA, $J_ZERO, 'fail');
    $key1 = $r1['member_key']; $key2 = $r2['member_key'];
    t('released 전: 등급 미변동', bravoGradeCurrentLevel($db, $key1) === 0);

    // bravoExamUpdate 경유 released 전환 (관리자 저장 경로와 동일)
    $examRow = $db->query("SELECT * FROM bravo_exams WHERE id=" . $examA)->fetch(PDO::FETCH_ASSOC);
    $upd = array_merge($examRow, ['status' => 'released']);
    bravoExamUpdate($db, $examA, $upd);
    t('released 훅: pass 승급', bravoGradeCurrentLevel($db, $key1) === 1);
    t('released 훅: fail 제외', bravoGradeCurrentLevel($db, $key2) === 0);
    $passLog = $db->prepare("SELECT * FROM bravo_grade_log WHERE member_key = ? AND source = 'exam_pass'");
    $passLog->execute([$key1]);
    $pl = $passLog->fetch(PDO::FETCH_ASSOC);
    t('exam_pass 로그 (ref=exam_id)', $pl && (int)$pl['ref_id'] === $examA);

    // 재전환 멱등 (released → closed → released)
    bravoExamUpdate($db, $examA, array_merge($upd, ['status' => 'closed']));
    $logsBefore = (int)$db->query("SELECT COUNT(*) FROM bravo_grade_log WHERE source='exam_pass'")->fetchColumn();
    bravoExamUpdate($db, $examA, $upd);
    $logsAfter = (int)$db->query("SELECT COUNT(*) FROM bravo_grade_log WHERE source='exam_pass'")->fetchColumn();
    t('released 재전환 멱등 (승급 로그 미증가)', $logsBefore === $logsAfter && bravoGradeCurrentLevel($db, $key1) === 1);

    // 이미 상위 등급 보유자 no-op
    bravoGradeSet($db, $key2, 3, 'admin_adjust', 99, null);
    bravoExamUpdate($db, $examA, array_merge($upd, ['status' => 'closed']));
    bravoExamUpdate($db, $examA, $upd);
    t('상위 등급 보유자 no-op', bravoGradeCurrentLevel($db, $key2) === 3);

    // ── 누적 quota ──
    $m3 = $mkMember(3);
    $key3 = "{$tag}_uid3";
    $q0 = bravoAttemptQuotaForLevel($db, $key3, 1);
    t('quota 초기 (0/3)', $q0['used'] === 0 && $q0['limit'] === 3 && $q0['left'] === 3);
    // 서로 다른 B1 시험 2개 합산
    [$examB, $qB] = $mkExam(1, 'B');
    [$examC, $qC] = $mkExam(1, 'C');
    $gradeAttempt($m3, $examB, $qB, $J_ZERO, 'fail');
    $gradeAttempt($m3, $examC, $qC, $J_ZERO, 'fail');
    $q2 = bravoAttemptQuotaForLevel($db, $key3, 1);
    t('quota 두 시험 합산 (2/3)', $q2['used'] === 2 && $q2['left'] === 1);
    // extra 부여 시 한도 증가
    $db->prepare("UPDATE bravo_member_grades SET extra_attempts_1 = 2 WHERE member_key = ?")->execute([$key3]);
    $q3 = bravoAttemptQuotaForLevel($db, $key3, 1);
    t('extra 부여 (limit 5)', $q3['limit'] === 5 && $q3['left'] === 3);
    // 다른 등급은 독립
    $qLv2 = bravoAttemptQuotaForLevel($db, $key3, 2);
    t('등급별 독립 (B2 0/3)', $qLv2['used'] === 0 && $qLv2['limit'] === 3);

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

if (is_dir(BRAVO_UPLOAD_ROOT)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BRAVO_UPLOAD_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname()); }
    @rmdir(BRAVO_UPLOAD_ROOT);
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

⚠️ 이 시점(Task 2)에는 게이트가 아직 옛 코드(released-pass 차단)라 위 테스트의 quota 시나리오는 **시험별 attempt_limit 안에서** 동작 — gradeAttempt 가 시험당 1회씩만 쓰므로 충돌 없음. 테스트는 Task 3 이후에도 그대로 통과해야 함.

- [ ] **Step 2: 실패 확인**

Run: `php tests/bravo_grade_policy_test.php`
Expected: `released 훅: pass 승급` FAIL (훅 미구현 — 등급 0 유지)

- [ ] **Step 3: bravo.php 수정**

상단(파일 첫 require 위치 — 현재 bravo.php 에 require 가 없으므로 docblock 아래)에 추가:

```php
require_once __DIR__ . '/bravo_grades.php';
```

`bravoExamUpdate`(현재 266-277행) 전체 교체:

```php
/**
 * 시험 수정 (status 포함 전체 필드).
 * status 가 released 로 '전환'되는 시점에 확정 pass 전원 승급 (bravoGradeApplyExamPass).
 * 재전환(released→closed→released)은 ApplyExamPass 내부 no-op 으로 멱등.
 */
function bravoExamUpdate(PDO $db, int $id, array $d): void {
    $c = bravoExamPersistData($d);
    $prevStmt = $db->prepare("SELECT status FROM bravo_exams WHERE id = ?");
    $prevStmt->execute([$id]);
    $prevStatus = $prevStmt->fetchColumn();

    $owns = !$db->inTransaction();
    if ($owns) $db->beginTransaction();
    try {
        $db->prepare("
            UPDATE bravo_exams SET
                title=?, bravo_level=?, exam_mode=?, start_at=?, end_at=?, result_release_at=?,
                attempt_limit=?, target_type=?, target_cohort_id=?, status=?
            WHERE id=?
        ")->execute([
            $c['title'], $c['bravo_level'], $c['exam_mode'], $c['start_at'], $c['end_at'], $c['result_release_at'],
            $c['attempt_limit'], $c['target_type'], $c['target_cohort_id'], $c['status'], $id,
        ]);
        if ($prevStatus !== false && $prevStatus !== 'released' && $c['status'] === 'released') {
            bravoGradeApplyExamPass($db, ['id' => $id, 'bravo_level' => $c['bravo_level'], 'title' => $c['title']]);
        }
        if ($owns) $db->commit();
    } catch (Throwable $e) {
        if ($owns) $db->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 4: 테스트 통과 + 기존 회귀 스모크**

```bash
php tests/bravo_grade_policy_test.php
php tests/bravo_exam_service_test.php && php tests/bravo_release_test.php
```
Expected: policy 전체 PASS. ⚠️ `bravo_release_test.php` 는 이 시점부터 **released 전환 시 합격자 등급이 올라** 같은-등급 차단(`released pass 차단`)이 여전히 동작하므로 통과 예상 — 만약 FAIL 이면 보고만 하고 진행 (Task 3 에서 단언 교체).

- [ ] **Step 5: Commit**

```bash
git add public_html/api/services/bravo.php tests/bravo_grade_policy_test.php
git commit -m "feat(bravo): released 전환 훅 — 확정 pass 전원 등급 승급 (재전환 멱등)"
```

---

### Task 3: 응시 게이트 교체 (보유/이전등급/누적 quota + 같은시험 재응시 + race)

**Files:**
- Modify: `public_html/api/services/bravo_attempts.php` — `bravoAttemptExamAccess`(56-79행 부근) + `bravoAttemptStart`(122-173행 부근)
- Modify: `public_html/api/services/bravo.php` — `bravoHasReleasedPass` 함수 **삭제**
- Modify: `tests/bravo_attempt_test.php`, `tests/bravo_release_test.php` — 단언 교체
- Test: `tests/bravo_grade_gates_test.php` (신규)

- [ ] **Step 1: 게이트 테스트 작성 (failing)**

`tests/bravo_grade_gates_test.php`:

```php
<?php
/**
 * BRAVO 응시 게이트 테스트 — 보유 차단·이전등급 요건·누적 quota·같은시험 재응시·동시 시작 race·phone-only.
 * DEV DB 트랜잭션 롤백. 사용: php tests/bravo_grade_gates_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
define('BRAVO_UPLOAD_ROOT', sys_get_temp_dir() . '/bravo_gates_test_' . getmypid());
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

$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TGG_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1)")
       ->execute(["{$tag}기", $tag]);
    $cohortId = (int)$db->lastInsertId();
    // m1: user_id 회원 / mp: phone-only 회원
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
       ->execute([$cohortId, "{$tag}회원1", "{$tag}닉1", '01000000401', "{$tag}_uid1"]);
    $m1 = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute(["{$tag}_uid1"]);
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,NULL,1)")
       ->execute([$cohortId, "{$tag}폰온리", "{$tag}닉P", '01000000402']);
    $mp = (int)$db->lastInsertId();
    $key1 = "{$tag}_uid1"; $keyP = 'p:01000000402';

    $mkExam = function (int $level, string $suffix, int $limit = 3) use ($db, $tag): array {
        $id = bravoExamCreate($db, ['title'=>"{$tag} {$suffix}",'bravo_level'=>$level,'exam_mode'=>'always','attempt_limit'=>$limit,'target_type'=>'all','status'=>'open'], 99);
        $q = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>$level,'korean_text'=>"{$tag} q{$suffix}",'english_text'=>'a','accepted_answers'=>'','target_chunks'=>'c','difficulty'=>'easy','is_active'=>1], 99);
        bravoExamQuestionSet($db, $id, [$q]);
        return [$id, [$q]];
    };
    $J_MAX  = ['accuracy'=>'correct','chunk_ok'=>1,'response_rating'=>'good','fluency_rating'=>'good','completion_ok'=>1];
    $J_ZERO = ['accuracy'=>'wrong','chunk_ok'=>0,'response_rating'=>'poor','fluency_rating'=>'poor','completion_ok'=>0];
    // submit 까지 (확정은 호출부가)
    $submitAttempt = function (int $memberId, int $examId, array $qids) use ($db): array {
        $acc = bravoAttemptExamAccess($db, $memberId, $examId);
        if (isset($acc['error'])) return $acc;
        $r = bravoAttemptStart($db, $acc['exam'], $acc['ctx']['row'], $acc['member_key'], false);
        if (isset($r['error'])) return $r;
        $attempt = $r['attempt'];
        foreach ($qids as $q) {
            $f = tempnam(sys_get_temp_dir(), 'tgg_'); file_put_contents($f, 'audio');
            bravoAnswerStore($db, $attempt, $q, $f, 'audio/webm', 'webm', 3000, false);
        }
        bravoAttemptSubmit($db, $attempt);
        return ['attempt' => bravoAttemptGet($db, (int)$attempt['id']), 'member_key' => $acc['member_key'], 'exam' => $acc['exam']];
    };
    $confirmAs = function (array $sub, array $judge, string $result) use ($db): void {
        $attempt = $sub['attempt'];
        foreach (json_decode((string)$attempt['question_ids'], true) as $q) {
            $aid = (int)$db->query("SELECT id FROM bravo_answers WHERE attempt_id=" . (int)$attempt['id'] . " AND question_id=" . (int)$q)->fetchColumn();
            bravoGradeSave($db, $attempt, $sub['exam'], $aid, $judge, 99);
        }
        $c = bravoAttemptConfirm($db, $attempt, $sub['exam'], ['result' => $result], 99);
        if (isset($c['error'])) throw new RuntimeException('confirm: ' . $c['error']);
    };

    // ── ① 보유 차단 + 강등 해제 ──
    [$examA, $qA] = $mkExam(1, 'A');
    bravoGradeSet($db, $key1, 1, 'admin_adjust', 99, null); // B1 보유자
    $acc = bravoAttemptExamAccess($db, $m1, $examA);
    t('보유 차단 (403, 강등 안내)', isset($acc['error']) && ($acc['code'] ?? 0) === 403 && str_contains($acc['error'], '강등'), json_encode($acc));
    bravoGradeDemote($db, $key1); // B1 → 0
    $acc = bravoAttemptExamAccess($db, $m1, $examA);
    t('강등 후 차단 해제', !isset($acc['error']), $acc['error'] ?? '');

    // ── ② 이전등급 요건 ──
    [$examB2, $qB2] = $mkExam(2, 'B2');
    $acc = bravoAttemptExamAccess($db, $m1, $examB2);
    t('B2: B1 미보유 거부', isset($acc['error']) && str_contains($acc['error'], 'BRAVO 1'), json_encode($acc));
    bravoGradeSet($db, $key1, 1, 'admin_adjust', 99, null); // grandfather/관리자 부여도 인정
    $acc = bravoAttemptExamAccess($db, $m1, $examB2);
    t('B2: B1 보유 시 통과', !isset($acc['error']), $acc['error'] ?? '');
    // B1 은 요건 면제 (위 ① 에서 무등급 접근 이미 검증됨 — requires_previous_level=0)

    // ── ③ 누적 quota: 서로 다른 같은-등급 시험 합산 ──
    // m1 은 B1 보유 상태 → B1 시험은 보유 차단. quota 는 phone-only 회원으로 검증.
    [$examC, $qC] = $mkExam(1, 'C');
    [$examD, $qD] = $mkExam(1, 'D');
    $s1 = $submitAttempt($mp, $examC, $qC);
    t('phone-only 응시 시작/제출', isset($s1['attempt']), json_encode($s1));
    $confirmAs($s1, $J_ZERO, 'fail');
    $s2 = $submitAttempt($mp, $examD, $qD);
    $confirmAs($s2, $J_ZERO, 'fail');
    // 같은 시험 재응시 (확정 후 — 새 attempt_no)
    $s3 = $submitAttempt($mp, $examC, $qC);
    t('같은 시험 재응시 (확정 후, attempt_no 2)', isset($s3['attempt']) && (int)$s3['attempt']['attempt_no'] === 2, json_encode($s3['attempt'] ?? $s3));
    // 미확정 submitted 상태에선 같은 시험 재시작 차단
    $s4 = $submitAttempt($mp, $examC, $qC);
    t('미확정 submitted 재시작 차단', isset($s4['error']) && str_contains($s4['error'], '채점'), json_encode($s4));
    // 누적 3회 소진 → 다른 같은-등급 시험도 차단 (access 에서)
    $acc = bravoAttemptExamAccess($db, $mp, $examD);
    t('누적 3회 소진 차단 (시험 D 1회 + C 2회)', isset($acc['error']) && str_contains($acc['error'], '횟수'), json_encode($acc));
    // extra 부여 → 통과
    $db->prepare("UPDATE bravo_member_grades SET extra_attempts_1 = 1 WHERE member_key = ?")->execute([$keyP]);
    $acc = bravoAttemptExamAccess($db, $mp, $examD);
    t('extra 부여 후 통과', !isset($acc['error']), $acc['error'] ?? '');

    // ── ④ 동시 시작 race: 서로 다른 같은-등급 시험 2개 — mutex 직렬화 ──
    // testHook 으로 INSERT 직전에 같은 사람의 다른 시험 attempt 를 끼워넣어 quota 초과를 재현 시도.
    // mutex(bravoGradeLockRow)가 같은 트랜잭션 안이라 직렬화됨 — quota 재검사로 한쪽이 거부되어야 함.
    $db->prepare("UPDATE bravo_member_grades SET extra_attempts_1 = 0 WHERE member_key = ?")->execute([$keyP]);
    // 현재 keyP 의 B1 누적 = 3 (C2 + D1) → 이미 소진. extra 1 부여해 잔여 1 로 만들고 race 검증.
    $db->prepare("UPDATE bravo_member_grades SET extra_attempts_1 = 1 WHERE member_key = ?")->execute([$keyP]);
    $hooked = false;
    $accD = bravoAttemptExamAccess($db, $mp, $examD);
    $hook = function () use ($db, &$hooked, $examC, $keyP, $mp, $qC) {
        if ($hooked) return;
        $hooked = true;
        // 같은 트랜잭션(동일 커넥션) 안 — mutex 잠금 이후 시점이라 이 INSERT 는 직렬화 안쪽.
        // 다른 커넥션 시뮬은 CLI 단일 커넥션 한계로 불가 — 대신 'mutex 이후 카운트가 INSERT 직전 기준' 임을 검증:
        $db->prepare("INSERT INTO bravo_attempts (exam_id, member_key, member_id, attempt_no, question_ids) VALUES (?,?,?,?,?)")
           ->execute([$examC, $keyP, $mp, 3, json_encode($qC)]);
    };
    $r = bravoAttemptStart($db, $accD['exam'], $accD['ctx']['row'], $accD['member_key'], false, $hook);
    // hook 이 잔여 1 을 선점 → start 의 quota 재검사(잠금 후 카운트)가 이를 보고 거부해야 함
    t('race: mutex 후 quota 재검사로 거부', isset($r['error']) && str_contains($r['error'], '횟수'), json_encode($r));

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

if (is_dir(BRAVO_UPLOAD_ROOT)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BRAVO_UPLOAD_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname()); }
    @rmdir(BRAVO_UPLOAD_ROOT);
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

⚠️ **race 테스트의 한계와 의도**: CLI 단일 커넥션이라 진짜 2-커넥션 동시성은 재현 불가. 위 테스트는 "quota 검사가 **mutex 잠금 이후의 카운트** 기준"임을 검증 — testHook(INSERT 직전 호출)이 끼워넣은 attempt 를 start 의 재검사가 보고 거부하면, 실제 2-커넥션 시나리오에서도 잠금 직렬화로 같은 결과가 보장됨 (hook 위치는 잠금 획득 후·카운트 전이어야 함 — 구현의 hook 호출 지점을 카운트 직전에 둘 것).

- [ ] **Step 2: 실패 확인**

Run: `php tests/bravo_grade_gates_test.php`
Expected: `보유 차단` FAIL (옛 게이트는 released-pass 기준 — admin_adjust 등급은 안 막음)

- [ ] **Step 3: bravoAttemptExamAccess 게이트 교체**

`bravo_attempts.php` 의 eligible 검사부터 return 까지(현재 69-79행)를 교체:

```php
    if (!in_array((int)$exam['bravo_level'], $ctx['eligible'], true)) {
        return ['error' => '응시 자격이 없습니다.', 'code' => 403];
    }
    $memberKey = bravoAttemptMemberKey($ctx['row']);
    $examLevel = (int)$exam['bravo_level'];
    $cur = bravoGradeCurrentLevel($db, $memberKey);
    // ① 보유 차단 — 재응시는 강등 신청 후 (등급 진실원 기준, slice8 의 released-pass 차단 대체)
    if ($cur >= $examLevel) {
        return ['error' => "이미 BRAVO {$examLevel} 등급을 보유하고 있습니다. 재응시하려면 강등 신청을 해주세요.", 'code' => 403];
    }
    // ② 이전등급 요건 (bravo_levels.requires_previous_level — B2←B1, B3←B2)
    $reqPrev = false;
    foreach ($ctx['levels'] as $lvRow) {
        if ((int)$lvRow['level'] === $examLevel) { $reqPrev = (int)$lvRow['requires_previous_level'] === 1; break; }
    }
    if ($reqPrev && $cur < $examLevel - 1) {
        return ['error' => 'BRAVO ' . ($examLevel - 1) . ' 등급 취득 후 응시할 수 있습니다.', 'code' => 403];
    }
    // ③ 등급당 평생 누적 quota (기본 3 + 관리자 extra)
    $quota = bravoAttemptQuotaForLevel($db, $memberKey, $examLevel);
    if ($quota['left'] <= 0) {
        return ['error' => "응시 횟수를 모두 사용했습니다. (누적 {$quota['used']}/{$quota['limit']}) 추가 응시는 운영진에게 문의해주세요.", 'code' => 403];
    }
    return ['exam' => $exam, 'ctx' => $ctx, 'member_key' => $memberKey];
```

(함수 docblock 의 "intro/start/save 용" 설명에 "보유/이전등급/누적 quota 게이트 포함" 한 줄 추가.)

- [ ] **Step 4: bravoAttemptStart 재작성**

함수 전체(현재 115-173행 부근) 교체:

```php
/**
 * 응시 시작 (이어하기 겸용).
 * - in_progress 존재 → 그대로 반환 (resumed=true, 차감 없음)
 * - 미확정 submitted 존재 → 채점 대기 차단 (확정되면 같은 시험 재응시 가능 — 실제 한도는 누적 quota)
 * - 신규: require_check 검증 → 스냅샷 → 사람 단위 mutex(bravoGradeLockRow) → 누적 quota 재검사 → INSERT
 *   (mutex 가 같은 등급 다른 시험의 동시 시작도 직렬화 — 빈 범위 gap-lock 한계 없음)
 * 성공: ['attempt'=>row, 'resumed'=>bool], 실패: ['error'=>msg]
 * $testHook: 테스트 전용 — mutex 획득 후·quota 카운트 직전 호출(race 재현).
 */
function bravoAttemptStart(PDO $db, array $exam, array $memberRow, string $memberKey, bool $otChecked, ?callable $testHook = null): array {
    $examId = (int)$exam['id'];

    $existing = bravoAttemptFindInProgress($db, $examId, $memberKey);
    if ($existing) return ['attempt' => $existing, 'resumed' => true];

    // 미확정 submitted → 채점 대기 차단 (pass/fail 비대칭 차단은 발표 전 정보 누설이라 불문 — 스펙 §4-2)
    $sub = $db->prepare("
        SELECT COUNT(*) FROM bravo_attempts a
        LEFT JOIN bravo_attempt_grades g ON g.attempt_id = a.id
        WHERE a.exam_id = ? AND a.member_key = ? AND a.status = 'submitted' AND g.id IS NULL
    ");
    $sub->execute([$examId, $memberKey]);
    if ((int)$sub->fetchColumn() > 0) {
        return ['error' => '이미 제출한 응시의 채점이 진행 중입니다. 결과 확정 후 다시 도전할 수 있습니다.'];
    }

    $otCheckedAt = null;
    $ot = bravoOtGet($db, $examId);
    if ($ot && (int)$ot['require_check'] === 1) {
        if (!$otChecked) return ['error' => 'OT 안내 확인 체크가 필요합니다.'];
        $otCheckedAt = date('Y-m-d H:i:s');
    }

    $qids = bravoExamQuestionAssignedIds($db, $examId);
    if (!$qids) return ['error' => '아직 출제 준비 중인 시험입니다.'];

    $owns = !$db->inTransaction();
    if ($owns) $db->beginTransaction();
    try {
        // 사람 단위 mutex — 같은 등급 다른 시험의 동시 시작도 이 행 잠금으로 직렬화
        bravoGradeLockRow($db, $memberKey);
        if ($testHook) $testHook();
        $quota = bravoAttemptQuotaForLevel($db, $memberKey, (int)$exam['bravo_level']);
        if ($quota['left'] <= 0) {
            if ($owns) $db->rollBack();
            return ['error' => "응시 횟수를 모두 사용했습니다. (누적 {$quota['used']}/{$quota['limit']})"];
        }
        // attempt_no = 이 시험 내 순번 (UNIQUE(exam, member, attempt_no) — 누적 used 와 분리)
        $no = $db->prepare("SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM bravo_attempts WHERE exam_id = ? AND member_key = ?");
        $no->execute([$examId, $memberKey]);
        $attemptNo = (int)$no->fetchColumn();
        $ins = $db->prepare("INSERT INTO bravo_attempts (exam_id, member_key, member_id, attempt_no, question_ids, ot_checked_at) VALUES (?,?,?,?,?,?)");
        $ins->execute([$examId, $memberKey, (int)$memberRow['id'], $attemptNo, json_encode($qids), $otCheckedAt]);
        $newId = (int)$db->lastInsertId();
        if ($owns) $db->commit();
        return ['attempt' => bravoAttemptGet($db, $newId), 'resumed' => false];
    } catch (PDOException $e) {
        if ($owns) $db->rollBack();
        if ($e->getCode() === '23000') {
            // 같은 시험 더블클릭 안전망 — 기존 in_progress 반환
            $existing = bravoAttemptFindInProgress($db, $examId, $memberKey);
            if ($existing) return ['attempt' => $existing, 'resumed' => true];
        }
        throw $e;
    }
}
```

- [ ] **Step 5: bravoHasReleasedPass 삭제**

`bravo.php` 의 `bravoHasReleasedPass` 함수(docblock 포함) 전체 삭제. 호출부 전수 확인:

```bash
grep -rn 'bravoHasReleasedPass' public_html/ tests/
```
Expected: `bravo.php` 의 `bravoMemberStatus`(passed_level — Task 4 에서 교체 예정이므로 **이 시점엔 임시로** `'passed_level' => bravoGradeCurrentLevel($db, $memberKey) >= $L,` 로 치환해 깨지지 않게 함) + `tests/bravo_release_test.php` 의 직접 단언 3건 (Step 6 에서 수정).

- [ ] **Step 6: 기존 테스트 단언 교체**

`tests/bravo_release_test.php`:
- `bravoHasReleasedPass` 직접 단언 3건 → `bravoGradeCurrentLevel($db, $key1, ...)` 기반으로 교체:
  ```php
  t('released 후 등급 취득 (m1 B2)', bravoGradeCurrentLevel($db, $key1) === 2);
  t('불합격자 등급 없음 (m2)', bravoGradeCurrentLevel($db, $key2) === 0);
  ```
- `'합격자 같은 등급 차단 (403)'` 단언의 메시지 검사를 `str_contains($acc['error'], '보유')` 로 교체 (새 메시지 "이미 BRAVO 2 등급을 보유하고...").
- `'released 전 pass: 차단 안 함'` 단언은 **그대로 유지** (released 전엔 current_level 미상승이라 여전히 통과해야 함 — 발표 전 비공개 보장의 새 형태).
- `'불합격자 통과'`/`'다른 등급 차단 안 함'` 유지. 단 examB/examC 가 **B2/B1** 인데 이전등급 요건 활성화로: m2(불합격, 등급 0)는 examB(B2) 접근이 이제 **이전등급 요건으로 거부**됨 — 단언을 `str_contains($acc['error'], 'BRAVO 1')` (요건 거부) 로 교체하거나, m2 에게 `bravoGradeSet($db, $key2, 1, 'admin_adjust', 99, null)` 부여 후 통과 단언 유지. **후자 권장** (원래 의도 = 불합격자 재응시 허용 검증).
- m5 블록(released+미확정)은 무관 — 유지.

`tests/bravo_attempt_test.php`:
- "이미 제출한 시험입니다" 단언 → 새 메시지 `'채점이 진행 중'` 포함 여부로 교체.
- attempt_limit 소진 단언(시험 단위 3회)이 있으면: 같은 시험 3회는 이제 누적 quota(등급당 3)와 수치가 같아 메시지만 다름 — 메시지 단언을 `'횟수'` 포함으로 완화. 횟수 차감/이어하기/스냅샷 등 나머지 단언 유지. **단언의 의미를 바꾸지 말고 메시지·기준만 새 정책에 맞출 것.**

- [ ] **Step 7: 전체 확인**

```bash
php tests/bravo_grade_gates_test.php
php tests/bravo_attempt_test.php && php tests/bravo_release_test.php && php tests/bravo_grade_policy_test.php && php tests/bravo_grading_test.php && php tests/bravo_certificates_test.php
grep -rn 'bravoHasReleasedPass' public_html/ tests/ ; echo "grep done (히트 0 이어야)"
```
Expected: 전부 PASS, grep 0건.

- [ ] **Step 8: Commit**

```bash
git add public_html/api/services/bravo.php public_html/api/services/bravo_attempts.php tests/bravo_grade_gates_test.php tests/bravo_attempt_test.php tests/bravo_release_test.php
git commit -m "feat(bravo): 응시 게이트 교체 — 보유/이전등급/누적quota + 같은시험 재응시(확정 후) + 사람단위 mutex"
```

---

### Task 4: bravoMemberStatus 재구성 (current_level/held/quota/prev_required)

**Files:**
- Modify: `public_html/api/services/bravo.php` — `bravoStatusAttempts` + `bravoMemberStatus`
- Modify: `tests/bravo_member_status_test.php`, `tests/bravo_release_test.php` — used/limit·passed_level 단언

- [ ] **Step 1: bravoStatusAttempts 의 used/limit 을 누적 quota 로 교체**

함수 앞부분(used 계산부)을 다음으로 교체 — in_progress/submitted/released result 로직은 **무변경**:

```php
function bravoStatusAttempts(PDO $db, array $exam, string $memberKey): array {
    $examId = (int)$exam['id'];
    // used/limit 은 등급당 평생 누적 quota (시험 단위 아님 — 정책 슬라이스에서 교체)
    $quota = bravoAttemptQuotaForLevel($db, $memberKey, (int)$exam['bravo_level']);

    $stmt = $db->prepare("SELECT id, status, question_ids FROM bravo_attempts WHERE exam_id = ? AND member_key = ? ORDER BY attempt_no");
    $stmt->execute([$examId, $memberKey]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $submitted = false; $inProgress = null;
    $cntStmt = $db->prepare("SELECT COUNT(*) FROM bravo_answers WHERE attempt_id = ?");
    foreach ($rows as $a) {
        if ($a['status'] === 'submitted') {
            $submitted = true;
        } elseif ($a['status'] === 'in_progress') {
            $total = count(json_decode((string)$a['question_ids'], true) ?: []);
            $cntStmt->execute([(int)$a['id']]);
            $inProgress = ['attempt_id' => (int)$a['id'], 'answered' => (int)$cntStmt->fetchColumn(), 'total' => $total];
        }
    }
    $out = ['exam_id' => $examId, 'used' => $quota['used'], 'limit' => $quota['limit'], 'in_progress' => $inProgress, 'submitted' => $submitted];
    // (이하 released result 블록 기존 그대로)
```

- [ ] **Step 2: bravoMemberStatus 재구성**

levels 루프와 member 반환부 교체 (현재 levels[] 배열 구성부):

```php
    $cur = bravoGradeCurrentLevel($db, $memberKey);

    $out = [];
    foreach ($ctx['levels'] as $lv) {
        $L = (int)$lv['level'];
        $isElig = in_array($L, $ctx['eligible'], true);
        $exStmt->execute([$L, (int)$m['cohort_id']]);
        $exam = $exStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $attempts = $exam ? bravoStatusAttempts($db, $exam, $memberKey) : null;
        $quota = bravoAttemptQuotaForLevel($db, $memberKey, $L);
        $out[] = [
            'level'                 => $L,
            'name'                  => $lv['name'],
            'required_review_count' => (int)$lv['required_review_count'],
            'eligible'              => $isElig,
            'status'                => $isElig ? 'eligible' : 'ineligible',
            'held'                  => $cur >= $L,
            'prev_required'         => ((int)$lv['requires_previous_level'] === 1) && $cur < $L - 1,
            'quota'                 => $quota,
            'exam'                  => $exam,
            'attempts'              => $attempts,
        ];
    }

    return [
        'member' => [
            'real_name'              => $m['real_name'],
            'nickname'               => $m['nickname'],
            'cohort'                 => $m['cohort'],
            'effective_review_count' => bravoEffectiveReviewCount($ctx['override'], $ctx['completed']),
            'current_level'          => $cur,
        ],
        'levels' => $out,
    ];
```

(`passed_level` 키는 **제거** — `held` 로 대체. 함수 docblock 의 반환 설명도 갱신: held/prev_required/quota/current_level.)

- [ ] **Step 3: 기존 테스트 단언 수정**

- `tests/bravo_member_status_test.php`: `passed_level` 참조가 있으면 `held` 로, attempts `used`/`limit` 단언은 누적 의미(시험 1개 시나리오에선 수치 동일 — limit 만 3 고정)로 확인 후 필요한 것만 수정.
- `tests/bravo_release_test.php`: `passed_level` 단언 2건 → `held` 로 교체 (`'released: 합격자 passed_level true'` → `held === true` — m1 은 released 훅으로 B2 취득). `미released: passed_level false` → `held === false`. `'미released: 기존 필드 유지'` 의 `limit === 3` 은 누적 한도라 그대로 3 — 유지.

- [ ] **Step 4: 확인 + Commit**

```bash
php tests/bravo_member_status_test.php && php tests/bravo_release_test.php && php tests/bravo_grade_gates_test.php
git add public_html/api/services/bravo.php tests/bravo_member_status_test.php tests/bravo_release_test.php
git commit -m "feat(bravo): status 재구성 — current_level/held/prev_required/누적 quota 축"
```

---

### Task 5: 표시 경로 5곳 전환 + freeze

**Files:**
- Modify: `public_html/api/member.php` — login(58-67행)·check_session(107-118행) 쿼리
- Modify: `public_html/api/services/member.php` — 25-44행 쿼리
- Modify: `public_html/api/services/member_page.php` — 388-400행 쿼리
- Modify: `public_html/api/admin.php` — 909-930행 쿼리
- Modify: `public_html/api/services/member_stats.php` — freeze
- Test: `tests/bravo_grade_display_test.php` (신규)

- [ ] **Step 1: 표시 테스트 작성 (failing)**

`tests/bravo_grade_display_test.php`:

```php
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
```

- [ ] **Step 2: 실패 확인** — Run: `php tests/bravo_grade_display_test.php` → freeze/정적 검사 FAIL

- [ ] **Step 3: 4개 쿼리 교체 (공통 패턴)**

4개 파일 모두 동일 치환:
- 셀렉트의 `COALESCE(mhs_u.bravo_grade, mhs_p.bravo_grade) AS bravo_grade` →
  ```sql
  CASE WHEN bmg.current_level >= 1 THEN CONCAT('Bravo ', bmg.current_level) END AS bravo_grade
  ```
- FROM 절의 `mhs_u` JOIN 줄 **다음에** 추가:
  ```sql
  LEFT JOIN bravo_member_grades bmg ON bmg.member_key = COALESCE(NULLIF(bm.user_id, ''), CONCAT('p:', bm.phone))
  ```
- 적용 위치: `member.php` login(58-67행)·check_session(107-118행) 2곳, `services/member.php` 25-44행, `services/member_page.php` 388-400행, `admin.php` 909-930행. (mhs 조인 자체는 completed_bootcamp_count 용으로 **유지** — bravo_grade 컬럼 참조만 제거.)
- `member.php` 에 `require_once __DIR__ . '/services/bravo_grades.php';` 는 **불필요** (SQL 조인만) — 단 이미 bravo.php require 로 로드됨.

- [ ] **Step 4: member_stats.php freeze**

- 17행 docblock 에 한 줄 추가: `* bravo_grade 는 freeze — 등급 진실원은 bravo_member_grades (등급 단일화 슬라이스), 이 파일은 더이상 쓰지 않음.`
- 55행 `$bravo = calcBravoGrade($comp);` 삭제, 59·64행 호출에서 `$bravo` 인자 삭제.
- `upsertStatsByPhone`/`upsertStatsByUserId`: 시그니처에서 `$bravo` 제거 + SQL 에서 `bravo_grade` 컬럼/`VALUES`/`ON DUPLICATE` 줄 제거:
  ```php
  function upsertStatsByPhone($db, $phone, $s1, $s2, $comp) {
      $db->prepare("
          INSERT INTO member_history_stats
              (phone, stage1_participation_count, stage2_participation_count, completed_bootcamp_count, last_calculated_at)
          VALUES (?, ?, ?, ?, NOW())
          ON DUPLICATE KEY UPDATE
              stage1_participation_count = VALUES(stage1_participation_count),
              stage2_participation_count = VALUES(stage2_participation_count),
              completed_bootcamp_count   = VALUES(completed_bootcamp_count),
              last_calculated_at         = NOW()
      ")->execute([$phone, $s1, $s2, $comp]);
  }
  ```
  (`upsertStatsByUserId` 동일 — user_id 버전.)
- `recalcAllMemberStats` 의 3개 호출(180·185·197행)에서 `$stats['bravo']` 인자 삭제.
- `calcStatsFromRecords`(222-227행): `'bravo' => calcBravoGrade($comp),` 줄 삭제.
- `calcBravoGrade`(233행) docblock 을 교체: `* 완주 횟수 기반 Bravo 등급 산정 — freeze 후 미사용. 등급 진실원은 bravo_member_grades (마이그 backfill 의 역사적 기준 참조용으로만 잔존).`

- [ ] **Step 5: 확인 + Commit**

```bash
php -l public_html/api/member.php && php -l public_html/api/services/member.php && php -l public_html/api/services/member_page.php && php -l public_html/api/admin.php && php -l public_html/api/services/member_stats.php
php tests/bravo_grade_display_test.php
curl -s 'https://dev-boot.soritune.com/api/member.php?action=check_session' | head -c 120   # JSON 정상 (logged_in false)
git add public_html/api/member.php public_html/api/services/member.php public_html/api/services/member_page.php public_html/api/admin.php public_html/api/services/member_stats.php tests/bravo_grade_display_test.php
git commit -m "feat(bravo): 표시 경로 5곳 신규 등급 테이블 전환 + 레거시 bravo_grade freeze"
```

---

### Task 6: 강등 신청 (member.php case + member-bravo.js 모달·분기 재정렬 + CSS)

**Files:**
- Modify: `public_html/api/member.php` — case `bravo_demote` (`bravo_certificate` case 다음)
- Modify: `public_html/js/member-bravo.js` — 헤더 등급/강등 버튼 + 모달 + actionHtml 재정렬
- Modify: `public_html/css/bravo.css` — 강등 영역 소폭 추가

- [ ] **Step 1: member.php case 추가**

`bravo_certificate` case 다음, `default:` 앞에:

```php
case 'bravo_demote':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $s = requireMember();
    $db = getDB();
    $ctx = bravoMemberContext($db, (int)$s['member_id']);
    if (!$ctx) jsonError('회원 정보를 찾을 수 없습니다.', 404);
    $r = bravoGradeDemote($db, bravoAttemptMemberKey($ctx['row']));
    if (isset($r['error'])) jsonError($r['error']);
    jsonSuccess(
        ['from' => $r['from'], 'to' => $r['to']],
        'BRAVO ' . $r['from'] . ' → ' . ($r['to'] >= 1 ? 'BRAVO ' . $r['to'] : '무등급') . ' 로 강등되었습니다.'
    );
    break;
```

- [ ] **Step 2: member-bravo.js — 헤더 + 모달 + 분기 재정렬**

`render()` 의 sub/카드 마크업 사이에 등급 헤더 추가 + dialog + 핸들러, `actionHtml` 재정렬. 파일에서 해당 부분 교체:

```js
    // 카드 액션 — 분기 순서: ① 진행 중 응시(가리면 안 됨) ② released 결과 ③ 보유 ④ 이전등급 요건 ⑤ quota ⑥ 도전 (스펙 §6)
    function actionHtml(lv) {
        const ex = lv.exam, at = lv.attempts;
        if (lv.held && (!at || !at.in_progress)) {
            // 보유 등급: 결과 카드(인증서)가 있으면 그쪽 우선
            if (at && at.result && at.result.result === 'pass') {
                const r = at.result;
                const certLabel = r.cert_issued ? '인증서 다시 받기' : '인증서 다운로드';
                return `<p class="bravo-state bravo-result-pass">🎉 합격! 총점 ${parseFloat(r.total_score)} / 합격선 ${parseFloat(r.passing_score)}</p>
                    <button class="btn btn-primary bravo-cert" data-attempt-id="${r.attempt_id}">${certLabel}</button>`;
            }
            return `<p class="bravo-state bravo-result-pass">✅ BRAVO ${parseInt(lv.level, 10)} 보유</p>`;
        }
        if (!lv.eligible || !ex || !at) return '';
        if (at.in_progress) {
            const ip = at.in_progress;
            if (ip.answered >= ip.total && ip.total > 0) {
                return `<button class="btn btn-primary bravo-finalize" data-attempt-id="${ip.attempt_id}">제출 마무리</button>`;
            }
            if (ex.status === 'open') {
                return `<button class="btn btn-primary bravo-challenge" data-exam-id="${at.exam_id}">이어하기 (${ip.answered}/${ip.total})</button>`;
            }
            return '<p class="bravo-state">응시 기간 종료 (미제출)</p>';
        }
        if (at.result) {
            const r = at.result;
            if (r.result === 'pass') {
                const certLabel = r.cert_issued ? '인증서 다시 받기' : '인증서 다운로드';
                return `<p class="bravo-state bravo-result-pass">🎉 합격! 총점 ${parseFloat(r.total_score)} / 합격선 ${parseFloat(r.passing_score)}</p>
                    <button class="btn btn-primary bravo-cert" data-attempt-id="${r.attempt_id}">${certLabel}</button>`;
            }
            const retry = (ex.status === 'open' && lv.quota && lv.quota.left > 0 && !lv.held && !lv.prev_required)
                ? `<button class="btn btn-primary bravo-challenge" data-exam-id="${at.exam_id}">다시 도전하기 (누적 ${at.used}/${at.limit}회 사용)</button>` : '';
            return `<p class="bravo-state bravo-result-fail">아쉽게 불합격 — 총점 ${parseFloat(r.total_score)} / 합격선 ${parseFloat(r.passing_score)}.</p>${retry}`;
        }
        if (lv.prev_required) {
            return `<p class="bravo-state">BRAVO ${parseInt(lv.level, 10) - 1} 등급 취득 후 도전할 수 있어요.</p>`;
        }
        if (at.submitted) {
            return '<p class="bravo-state">제출완료 — 결과 발표 대기</p>';
        }
        if (ex.status === 'open') {
            if (lv.quota && lv.quota.left <= 0) {
                return `<p class="bravo-state">누적 응시 ${at.used}/${at.limit}회 소진 — 추가 응시는 운영진에게 문의해주세요.</p>`;
            }
            return `<button class="btn btn-primary bravo-challenge" data-exam-id="${at.exam_id}">도전하기 (누적 ${at.used}/${at.limit}회 사용)</button>`;
        }
        return '';
    }
```

`render()` 함수의 `sub` 계산 다음에 등급 헤더/모달 추가 — `el.innerHTML` 템플릿의 `member-bravo-head` 안 `<p class="member-bravo-sub">` 다음에 삽입할 마크업과 핸들러:

```js
        const cur = member ? parseInt(member.current_level, 10) || 0 : 0;
        const gradeHtml = cur >= 1 ? `
                    <div class="member-bravo-grade">
                        <span>내 등급: <strong>BRAVO ${cur}</strong></span>
                        <button class="btn btn-sm bravo-demote-btn" type="button">강등 신청</button>
                    </div>` : '';
```

(템플릿의 `<p class="member-bravo-sub">${sub}</p>` 바로 다음 줄에 `${gradeHtml}` 삽입.)

템플릿 마지막(`member-bravo-note` 다음)에 dialog 추가:

```js
                <dialog class="bravo-demote-dialog" id="bravo-demote-dialog">
                    <h4>등급 강등 신청</h4>
                    <p>BRAVO ${cur} → ${cur - 1 >= 1 ? 'BRAVO ' + (cur - 1) : '무등급'} 으로 내려갑니다.</p>
                    <p class="bravo-demote-warn">⚠️ 되돌리려면 시험에 다시 합격해야 합니다.<br>누적 응시 횟수는 환불되지 않습니다.</p>
                    <div class="bravo-demote-actions">
                        <button class="btn btn-danger" id="bravo-demote-confirm" type="button">강등 확인</button>
                        <button class="btn" id="bravo-demote-cancel" type="button">취소</button>
                    </div>
                </dialog>
```

(⚠️ dialog 안 버튼은 전부 `type="button"` — `<form method="dialog">` 미사용으로 required-검증 footgun 자체를 회피.)

핸들러 등록 (기존 `.bravo-cert` 등록 다음):

```js
        const demoteBtn = el.querySelector('.bravo-demote-btn');
        if (demoteBtn) {
            const dlg = el.querySelector('#bravo-demote-dialog');
            demoteBtn.addEventListener('click', () => dlg.showModal());
            el.querySelector('#bravo-demote-cancel').addEventListener('click', () => dlg.close());
            el.querySelector('#bravo-demote-confirm').addEventListener('click', async (e) => {
                e.currentTarget.disabled = true;
                const r = await App.post('/api/member.php?action=bravo_demote', {});
                dlg.close();
                if (r && r.success !== false) {
                    Toast.success(r.message || '강등되었습니다.');
                    mount(el, member);
                } else {
                    e.currentTarget.disabled = false;
                }
            });
        }
```

- [ ] **Step 3: bravo.css 추가** (기존 "카드 상태/결과" 섹션 끝, `@media` **앞**에):

```css
/* ── 등급 헤더 + 강등 모달 ── */
.member-bravo-grade { display: flex; align-items: center; gap: var(--space-3); margin-top: var(--space-2); font-size: var(--text-base); color: var(--color-text); }
.bravo-demote-btn { color: var(--color-text-sub); }
.bravo-demote-dialog { border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: var(--space-5); max-width: 420px; box-shadow: var(--shadow-lg); }
.bravo-demote-dialog::backdrop { background: rgba(15, 23, 42, 0.5); }
.bravo-demote-dialog h4 { margin: 0 0 var(--space-3); }
.bravo-demote-warn { padding: var(--space-2) var(--space-3); background: var(--color-warning-50); border-left: 3px solid var(--color-warning-500); border-radius: var(--radius-sm); font-size: var(--text-sm); line-height: var(--leading-normal); }
.bravo-demote-actions { display: flex; gap: var(--space-2); margin-top: var(--space-4); }
```

- [ ] **Step 4: 검증 + Commit**

```bash
php -l public_html/api/member.php
node --check public_html/js/member-bravo.js
php -r '$c=file_get_contents("public_html/css/bravo.css"); exit(substr_count($c,"{")===substr_count($c,"}")?0:1);' && echo "braces OK"
php tests/bravo_grade_core_test.php   # demote 서비스 회귀
git add public_html/api/member.php public_html/js/member-bravo.js public_html/css/bravo.css
git commit -m "feat(bravo): 강등 신청 — 경고 모달 + 카드 분기 재정렬 (진행중 우선/재도전/요건/quota)"
```

---

### Task 7: 관리자 — 등급 조정·추가횟수 + attempt_limit 숨김

**Files:**
- Modify: `public_html/api/services/bravo.php` — `bravoMemberList` 확장
- Modify: `public_html/api/admin.php` — case `bravo_grade_update` 추가 (`bravo_member_update` 다음)
- Modify: `public_html/js/admin-bravo.js` — 자격 테이블 컬럼·저장
- Modify: `public_html/js/admin-bravo-exams.js` — attempt_limit 입력 숨김
- Modify: `public_html/css/admin-bravo.css` — 소폭

- [ ] **Step 1: bravoMemberList 확장**

쿼리 SELECT 에 추가(기존 `bms.notes` 다음): `,bmg.current_level, bmg.extra_attempts_1, bmg.extra_attempts_2, bmg.extra_attempts_3`
FROM 에 조인 추가(기존 bms 조인 다음): `LEFT JOIN bravo_member_grades bmg ON bmg.member_key = COALESCE(NULLIF(bm.user_id, ''), CONCAT('p:', bm.phone))`

루프 뒤에 누적 used 일괄 조회 + 항목 확장 — 루프 앞에:

```php
    // 등급별 누적 used (사람×등급 단위 일괄)
    $usedMap = [];
    foreach ($db->query("
        SELECT a.member_key, e.bravo_level, COUNT(*) AS c
        FROM bravo_attempts a JOIN bravo_exams e ON e.id = a.exam_id
        GROUP BY a.member_key, e.bravo_level
    ") as $u) {
        $usedMap[$u['member_key']][(int)$u['bravo_level']] = (int)$u['c'];
    }
```

각 항목 배열에 추가:

```php
            'member_key'             => $r['user_id'] !== null && $r['user_id'] !== '' ? $r['user_id'] : 'p:' . $r['phone'],
            'current_level'          => $r['current_level'] !== null ? (int)$r['current_level'] : 0,
            'extra_attempts'         => [
                1 => (int)($r['extra_attempts_1'] ?? 0),
                2 => (int)($r['extra_attempts_2'] ?? 0),
                3 => (int)($r['extra_attempts_3'] ?? 0),
            ],
            'used_attempts'          => [
                1 => $usedMap[$r['user_id'] ?: 'p:' . $r['phone']][1] ?? 0,
                2 => $usedMap[$r['user_id'] ?: 'p:' . $r['phone']][2] ?? 0,
                3 => $usedMap[$r['user_id'] ?: 'p:' . $r['phone']][3] ?? 0,
            ],
```

(used_attempts 키 계산은 member_key 변수로 빼서 한 번만 — 구현 시 `$mk = ...` 지역변수 사용.)

- [ ] **Step 2: admin.php case 추가** (`bravo_member_update` case 다음):

```php
case 'bravo_grade_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $memberKey = is_string($input['member_key'] ?? null) ? trim($input['member_key']) : '';
    if ($memberKey === '') jsonError('member_key가 필요합니다.');
    $level = (isset($input['current_level']) && is_numeric($input['current_level'])) ? max(0, min(3, (int)$input['current_level'])) : null;
    $extras = [];
    foreach ([1, 2, 3] as $l) {
        $k = 'extra_attempts_' . $l;
        $extras[$l] = (isset($input[$k]) && is_numeric($input[$k])) ? max(0, min(99, (int)$input[$k])) : 0;
    }
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) $db->beginTransaction();
    try {
        bravoGradeLockRow($db, $memberKey);
        if ($level !== null) {
            bravoGradeSet($db, $memberKey, $level, 'admin_adjust', (int)$admin['admin_id'], '관리자 수동 조정');
        }
        $db->prepare("UPDATE bravo_member_grades SET extra_attempts_1 = ?, extra_attempts_2 = ?, extra_attempts_3 = ? WHERE member_key = ?")
           ->execute([$extras[1], $extras[2], $extras[3], $memberKey]);
        if ($owns) $db->commit();
    } catch (Throwable $e) {
        if ($owns) $db->rollBack();
        throw $e;
    }
    jsonSuccess([], '저장되었습니다.');
    break;
```

(admin.php 는 이미 `services/bravo.php` require → `bravo_grades.php` 함수 사용 가능.)

- [ ] **Step 3: admin-bravo.js 자격 테이블 확장**

`renderQual` 의 행 템플릿에 컬럼 추가 — 헤더 `<th>` 추가: `응시가능` 다음에 `<th>현재 등급</th><th>누적 응시</th><th>추가횟수 B1/B2/B3</th>`. 행에:

```js
                <td>
                    <select class="bravo-cur-level">
                        ${[0,1,2,3].map(l => `<option value="${l}" ${ (m.current_level || 0) === l ? 'selected' : ''}>${l === 0 ? '무등급' : 'BRAVO ' + l}</option>`).join('')}
                    </select>
                </td>
                <td class="num"><small>${m.used_attempts ? `${m.used_attempts[1]}·${m.used_attempts[2]}·${m.used_attempts[3]}` : '-'}</small></td>
                <td class="bravo-extra-cell">
                    ${[1,2,3].map(l => `<input type="number" class="bravo-extra" data-lv="${l}" min="0" max="99" value="${m.extra_attempts ? m.extra_attempts[l] : 0}" style="width:3.5em">`).join(' ')}
                </td>
```

행의 `<tr data-user=...>` 에 `data-key="${App.esc(m.member_key)}"` 추가. `onSaveQual` 끝부분 교체 — 기존 `bravo_member_update` 호출 뒤에 등급/추가횟수 저장 추가 (phone-only 는 user_id 저장 skip):

```js
    async function onSaveQual(e) {
        const tr = e.target.closest('tr');
        if (!tr) return;
        const userId = tr.dataset.user;
        const memberKey = tr.dataset.key;
        let ok = true;
        if (userId) { // user_id 있는 회원만 기존 설정 저장 (phone-only 는 override/granted 미지원 — 기존 한계)
            const ovRaw = tr.querySelector('.bravo-override').value.trim();
            const granted = Array.from(tr.querySelectorAll('input[data-grant]:checked')).map(c => parseInt(c.dataset.grant, 10));
            const notes = tr.querySelector('.bravo-notes').value;
            const r1 = await App.post('/api/admin.php?action=bravo_member_update', {
                user_id: userId,
                review_count_override: ovRaw === '' ? null : parseInt(ovRaw, 10),
                granted_levels: granted,
                notes: notes,
            });
            ok = ok && r1 && r1.success !== false;
        }
        const payload = { member_key: memberKey, current_level: parseInt(tr.querySelector('.bravo-cur-level').value, 10) };
        tr.querySelectorAll('.bravo-extra').forEach(inp => { payload['extra_attempts_' + inp.dataset.lv] = parseInt(inp.value, 10) || 0; });
        const r2 = await App.post('/api/admin.php?action=bravo_grade_update', payload);
        ok = ok && r2 && r2.success !== false;
        if (ok) {
            Toast.success('저장되었습니다.');
            await loadQual();
            renderQual();
        } else {
            Toast.error('저장 실패 — 일부 항목을 확인해주세요.');
        }
    }
```

- [ ] **Step 4: admin-bravo-exams.js attempt_limit 숨김**

`openForm` 템플릿에서 다음 줄 **삭제**:
```js
                <label>응시횟수 <input type="number" id="bx-limit" min="1" value="${e ? e.attempt_limit : 3}" style="width:4em"></label>
```
`onSave` 의 payload 에서:
```js
            attempt_limit: parseInt(f.querySelector('#bx-limit').value, 10) || 1,
```
→
```js
            attempt_limit: e2 ? parseInt(e2.attempt_limit, 10) || 3 : 3, // 사용 중단 — 누적 quota 가 한도 (컬럼 잔존, 기존값 유지)
```
단 `onSave` 엔 `e` 가 없으므로 함수 첫머리에 `const e2 = editingId ? exams.find(x => parseInt(x.id, 10) === editingId) : null;` 추가.

- [ ] **Step 5: admin-bravo.css 소폭** (자격 섹션 끝, 채점 섹션 앞):

```css
.bravo-extra-cell { white-space: nowrap; }
.bravo-cur-level { padding: 2px 4px; font-size: var(--text-sm); }
```

- [ ] **Step 6: 검증 + Commit**

```bash
php -l public_html/api/admin.php
node --check public_html/js/admin-bravo.js && node --check public_html/js/admin-bravo-exams.js
php tests/bravo_admin_service_test.php && php tests/bravo_grade_core_test.php
git add public_html/api/services/bravo.php public_html/api/admin.php public_html/js/admin-bravo.js public_html/js/admin-bravo-exams.js public_html/css/admin-bravo.css
git commit -m "feat(bravo): 관리자 등급 수동조정·등급별 추가횟수 (member_key 경로 — phone-only 지원) + attempt_limit 숨김"
```

---

### Task 8: 전체 회귀 (컨트롤러)

- [ ] **Step 1: 24파일 전체**

```bash
cd /root/boot-dev
for f in tests/bravo_*.php; do php "$f" >/dev/null 2>&1 || echo "FAIL $f"; done; echo done
ls tests/bravo_*.php | wc -l   # 24 기대
```

- [ ] **Step 2: 페이지 스모크**

```bash
for u in / /operation/ ; do curl -s -o /dev/null -w "$u %{http_code}\n" "https://dev-boot.soritune.com$u"; done
curl -s 'https://dev-boot.soritune.com/api/member.php?action=bravo_demote' | head -c 120   # POST 요구 JSON 에러
```

- [ ] **Step 3: 잔여 변경 확인** — `git status --short` 의도 외 변경 0.

---

## 완료 후 (컨트롤러)

1. 최종 통합 리뷰 (opus, base=구현 시작 전 HEAD): ① `bravoHasReleasedPass`/`passed_level` 잔존 0 grep, ② 레거시 `mhs_*.bravo_grade` 읽기 잔존 0 grep (표시 5곳 외 다른 곳 — `js/memberTable.js`/`member-home.js`/`member-bootees.js` 는 응답 키 `bravo_grade` 소비라 무수정 확인), ③ 모든 등급 쓰기 경로가 `bravoGradeSet` 경유(로그 보장) — `UPDATE bravo_member_grades` 직접 호출은 extra_attempts 만, ④ 트랜잭션 가드 일관성($owns 패턴), ⑤ member-bravo-exam.js 의 intro attempts.used/limit 표시가 누적 의미로 자연 전환됐는지, ⑥ slice8 인증서·발표전 비공개 회귀 0
2. dev push → ⛔ 게이트: 사용자 브라우저 검증 (강등 모달 풀사이클·누적 횟수 표시·관리자 등급 조정·추가횟수·홈 카드 등급 반영·재도전 흐름)
3. 메모리 업데이트
4. 운영(main) 반영 금지 — 반영 시 **마이그 선실행** 절차(스펙 §11) 필수

## Self-Review 결과

- **스펙 커버리지**: §2 정책 6항(T1 freeze 는 T5/backfill T1/합격취득 T2/강등 T6/요건·quota T3) / §3 데이터모델(T1) / §4 서비스·훅·게이트(T1-T3) / §4-3 status(T4) / §5 표시(T5) / §6 회원 UI(T6) / §7 관리자(T7) / §8 엣지(T3 race·재응시, T2 멱등, T6 더블클릭 disable) / §9 테스트(신규 5파일 — 스펙의 2파일에서 태스크 독립성 위해 분할, 회귀 24) / §11 배포(완료 후 4) — 누락 없음
- **의도적 결정**: 테스트 5파일 분할, gates race 테스트는 단일 커넥션 한계로 "mutex 후 재검사" 검증으로 대체(한계 주석 포함), bravoStatusAttempts 시그니처 유지(내부만 교체 — 호출부 무변경)
- **타입 일관성**: `bravoGradeCurrentLevel/Set/Demote/LockRow/ApplyExamPass/QuotaForLevel/BackfillFromLegacy` — T1 정의 = T2-T7 사용 일치. status 응답 축 `held/prev_required/quota/current_level` — T4 서버 = T6 JS 사용 일치. `bravo_grade_update` payload (member_key/current_level/extra_attempts_N) — T7 서버 = JS 일치
