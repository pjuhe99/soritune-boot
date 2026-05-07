# 점수·코인 hot path 트랜잭션화 — 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 6개 hot path (saveCheck/applyCoinChange/recalculateMemberScore/handleScoreAdjust/qrRecordRevival/qrRecordAttendance) 에 nested-tx-guard 추가 + applyCoinChange UPDATE 를 atomic 으로 변환.

**Architecture:** 각 함수는 진입 시 `!$db->inTransaction()` 가드로 outer tx 존재 여부 판별. 자체 BEGIN/COMMIT 는 outer tx 없을 때만. exception 은 항상 rethrow. applyCoinChange 의 read-modify-write 패턴을 `LEAST(earned_coin + ?, ?)` atomic UPDATE 로 교체 + 적용 후 SELECT 로 실제 값 회수.

**Tech Stack:** PHP 8.5 (PDO), MariaDB 10.5 (InnoDB REPEATABLE READ), boot CLI 테스트 패턴 (`tests/` + `t()` 헬퍼).

**Spec:** `docs/superpowers/specs/2026-05-07-score-coin-transactions-design.md` (commit `1536c4d`)

---

## 파일 구조

**신규**
- `tests/transaction_invariants.php` — CLI 테스트 (8 인보리언트)

**수정**
- `public_html/includes/coin_functions.php` — `applyCoinChange` 본문 변경 (atomic UPDATE + tx-guard)
- `public_html/includes/bootcamp_functions.php` — `saveCheck`, `recalculateMemberScore` 에 tx-guard 래핑 + 신규 `adjustMemberScore` 헬퍼 추출
- `public_html/api/services/score.php` — `handleScoreAdjust` 본문을 `adjustMemberScore` 호출로 교체
- `public_html/includes/qr_actions.php` — `qrRecordAttendance`, `qrRecordRevival` 에 tx-guard 래핑

---

## Task 1: 테스트 스캐폴드 작성 (failing baseline)

**Files:**
- Create: `/root/boot-dev/tests/transaction_invariants.php`

테스트 스캐폴드 — 함수가 아직 tx-guard 가 없으므로 일부 인보리언트는 실패 (의도). 후속 task 에서 함수 수정으로 PASS 하게 만든다.

- [ ] **Step 1: 테스트 파일 생성**

Create `/root/boot-dev/tests/transaction_invariants.php`:

```php
<?php
/**
 * 점수/코인 트랜잭션화 인보리언트 테스트
 * 사용: php tests/transaction_invariants.php
 *
 * 각 테스트는 outer transaction 으로 감싸지고 마지막에 rollback —
 * tx-guard 가 작동하면 outer rollback 만으로 모든 상태가 깨끗해져야 함.
 * tx-guard 가 없으면 내부 BEGIN 충돌(PDOException) 또는 내부 COMMIT 으로
 * outer rollback 이 무력화되어 잔존물이 남음.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/bootcamp_functions.php';
require_once __DIR__ . '/../public_html/includes/coin_functions.php';
require_once __DIR__ . '/../public_html/includes/qr_actions.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; }
    else { $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n"; }
}

$db = getDB();

// ── Setup ──
$db->beginTransaction();

try {
    $cohort = $db->query("SELECT id FROM cohorts WHERE cohort = '12기' LIMIT 1")->fetch();
    $cohortId = (int)$cohort['id'];

    $members = $db->prepare("
        SELECT id FROM bootcamp_members
        WHERE cohort_id = ? AND is_active = 1 AND member_status != 'refunded'
        LIMIT 1
    ");
    $members->execute([$cohortId]);
    $memberRow = $members->fetch();
    if (!$memberRow) {
        echo "SKIP  no active 12기 members in DEV\n";
        $db->rollBack();
        exit(0);
    }
    $memberId = (int)$memberRow['id'];

    // 활성 코인 사이클
    $cycle = $db->query("SELECT id, max_coin FROM coin_cycles WHERE status = 'active' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$cycle) {
        echo "SKIP  no active coin cycle in DEV\n";
        $db->rollBack();
        exit(0);
    }
    $cycleId = (int)$cycle['id'];
    $maxCoin = (int)$cycle['max_coin'];

    // member_cycle_coins 초기화
    $db->prepare("
        INSERT INTO member_cycle_coins (member_id, cycle_id, earned_coin, used_coin)
        VALUES (?, ?, 0, 0)
        ON DUPLICATE KEY UPDATE earned_coin = 0, used_coin = 0
    ")->execute([$memberId, $cycleId]);

    // ── Test 1: applyCoinChange 양수 정상 적립 ──
    $r1 = applyCoinChange($db, $memberId, $cycleId, 50, 'leader_coin', 'test', null);
    t('applyCoinChange: +50 정상 적립', $r1['after'] === 50 && $r1['applied'] === 50);

    $cnt = $db->prepare("SELECT COUNT(*) FROM coin_logs WHERE member_id = ? AND cycle_id = ? AND reason_detail = 'test'");
    $cnt->execute([$memberId, $cycleId]);
    t('applyCoinChange: coin_logs 1행', (int)$cnt->fetchColumn() === 1);

    // ── Test 2: applyCoinChange cap (atomic LEAST) ──
    // 잔액을 max-20 으로 조정
    $db->prepare("UPDATE member_cycle_coins SET earned_coin = ? WHERE member_id = ? AND cycle_id = ?")
       ->execute([$maxCoin - 20, $memberId, $cycleId]);

    $r2 = applyCoinChange($db, $memberId, $cycleId, 50, 'leader_coin', 'cap-test', null);
    t('applyCoinChange: cap 적용 시 max 까지만', $r2['after'] === $maxCoin && $r2['applied'] === 20);

    // ── Test 3: applyCoinChange 음수 (cap 무관) ──
    $db->prepare("UPDATE member_cycle_coins SET earned_coin = 100 WHERE member_id = ? AND cycle_id = ?")
       ->execute([$memberId, $cycleId]);

    $r3 = applyCoinChange($db, $memberId, $cycleId, -30, 'leader_coin', 'neg-test', null);
    t('applyCoinChange: 음수 정상 차감', $r3['after'] === 70 && $r3['applied'] === -30);

    // ── Test 4: applyCoinChange 0 (no-op) ──
    $beforeZero = $db->query("SELECT earned_coin FROM member_cycle_coins WHERE member_id = $memberId AND cycle_id = $cycleId")->fetchColumn();
    $r4 = applyCoinChange($db, $memberId, $cycleId, 0, 'leader_coin', 'zero-test', null);
    $afterZero = $db->query("SELECT earned_coin FROM member_cycle_coins WHERE member_id = $memberId AND cycle_id = $cycleId")->fetchColumn();
    t('applyCoinChange: 0 입력 no-op', $r4['applied'] === 0 && $beforeZero === $afterZero);

    $cntZero = $db->prepare("SELECT COUNT(*) FROM coin_logs WHERE member_id = ? AND cycle_id = ? AND reason_detail = 'zero-test'");
    $cntZero->execute([$memberId, $cycleId]);
    t('applyCoinChange: 0 입력 시 coin_logs 미생성', (int)$cntZero->fetchColumn() === 0);

    // ── Test 5: saveCheck nested tx (outer tx 가 모든 writes 컨트롤) ──
    // outer tx 안에서 saveCheck 호출. saveCheck 가 자체 BEGIN 하면 PDOException 발생 → 실패.
    // saveCheck 가 가드 적용되었으면 정상 실행되고 outer tx 안에 모든 writes 누적.
    $missionTypeId = (int)$db->query("SELECT id FROM mission_types WHERE code = 'zoom_daily' AND is_active = 1 LIMIT 1")->fetchColumn();
    if ($missionTypeId === 0) {
        t('saveCheck: nested tx (skipped, no zoom_daily mission_type)', true);
    } else {
        $today = date('Y-m-d');
        $sourceRef = 'tx-test-' . uniqid();
        $caughtException = null;
        try {
            saveCheck($db, $memberId, $today, $missionTypeId, 1, 'manual', $sourceRef, null);
        } catch (\Throwable $e) {
            $caughtException = $e;
        }
        t('saveCheck: nested tx 안에서 예외 없이 실행', $caughtException === null,
            $caughtException ? 'exception: ' . $caughtException->getMessage() : '');

        $mmcCnt = $db->prepare("SELECT COUNT(*) FROM member_mission_checks WHERE member_id = ? AND check_date = ? AND mission_type_id = ?");
        $mmcCnt->execute([$memberId, $today, $missionTypeId]);
        t('saveCheck: nested tx 안에서 mmc 1행 적용', (int)$mmcCnt->fetchColumn() === 1);
    }

    // ── Test 6: handleScoreAdjust 동등 함수 (adjustMemberScore) ──
    // adjustMemberScore 는 이번 작업에서 추출되는 헬퍼 — bootcamp_functions.php
    if (function_exists('adjustMemberScore')) {
        $beforeAdj = (int)($db->query("SELECT current_score FROM member_scores WHERE member_id = $memberId")->fetchColumn() ?: 0);
        $rAdj = adjustMemberScore($db, $memberId, -3, 'tx-test-adjust', null);
        t('adjustMemberScore: -3 정상 조정', $rAdj['after_score'] === $beforeAdj - 3);

        $logCnt = $db->prepare("SELECT COUNT(*) FROM score_logs WHERE member_id = ? AND reason_detail = 'tx-test-adjust'");
        $logCnt->execute([$memberId]);
        t('adjustMemberScore: score_logs 1행', (int)$logCnt->fetchColumn() === 1);
    } else {
        t('adjustMemberScore: 함수 정의 (Task 5 에서 추가)', false, 'function not yet defined');
        t('adjustMemberScore: score_logs (Task 5 에서 추가)', false, 'function not yet defined');
    }

    // ── Test 7: qrRecordAttendance nested tx ──
    // QR 세션 만들고 호출. tx-guard 적용되었는지만 확인 (#2 의 reserve-first 동작은 별 spec).
    $db->prepare("
        INSERT INTO qr_sessions (session_code, session_type, admin_id, cohort_id, status, expires_at, created_at)
        VALUES (?, 'attendance', NULL, ?, 'active', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
    ")->execute(['txtest-' . uniqid(), $cohortId]);
    $attSessionId = (int)$db->lastInsertId();
    $attSession = $db->query("SELECT * FROM qr_sessions WHERE id = $attSessionId")->fetch();

    $caughtAtt = null;
    try {
        $rAtt = qrRecordAttendance($db, $attSession, $memberId, $memberId, '127.0.0.1', 'test');
    } catch (\Throwable $e) {
        $caughtAtt = $e;
    }
    t('qrRecordAttendance: nested tx 안에서 예외 없이 실행', $caughtAtt === null,
        $caughtAtt ? 'exception: ' . $caughtAtt->getMessage() : '');

    // ── Test 8: qrRecordRevival nested tx ──
    $db->prepare("
        INSERT INTO qr_sessions (session_code, session_type, admin_id, cohort_id, status, expires_at, created_at)
        VALUES (?, 'revival', NULL, ?, 'active', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
    ")->execute(['txtest-rev-' . uniqid(), $cohortId]);
    $revSessionId = (int)$db->lastInsertId();
    $revSession = $db->query("SELECT * FROM qr_sessions WHERE id = $revSessionId")->fetch();

    $db->prepare("INSERT INTO member_scores (member_id, current_score, last_calculated_at) VALUES (?, -10, NOW()) ON DUPLICATE KEY UPDATE current_score = -10, last_calculated_at = NOW()")
       ->execute([$memberId]);

    $caughtRev = null;
    try {
        $rRev = qrRecordRevival($db, $revSession, $memberId, $memberId, '127.0.0.1', 'test');
    } catch (\Throwable $e) {
        $caughtRev = $e;
    }
    t('qrRecordRevival: nested tx 안에서 예외 없이 실행', $caughtRev === null,
        $caughtRev ? 'exception: ' . $caughtRev->getMessage() : '');

} finally {
    $db->rollBack();
}

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 실행 — baseline 실패 확인**

Run: `cd /root/boot-dev && php tests/transaction_invariants.php`

Expected baseline: **Test 6 의 두 항목만 FAIL** (`adjustMemberScore: 함수 정의 (Task 5 에서 추가)`, `score_logs (Task 5 에서 추가)`). 나머지 항목은 baseline 에서 이미 PASS:
- Tests 1~4 (applyCoinChange): 현재 read-modify-write 도 단일 호출 시 정확함 (race 없는 단일 thread CLI 라 atomic 무관) → PASS
- Test 5 (saveCheck nested): 현재 saveCheck 는 자체 BEGIN 안 함 → 외부 tx 안에서 호출 무탈 → PASS
- Test 7/8 (qrRecord*): 현재도 자체 BEGIN 안 함 → PASS

즉 **TDD 의 진짜 transition 은 Test 6 만** 발생. 나머지는 회귀 가드 (Task 2~6 후에도 PASS 유지). 만약 baseline 에서 Test 6 이외에 FAIL 이 있으면 plan 의 가정이 틀린 것 — 보고 후 plan 재검토.

베이스라인 출력 예시:
```
PASS  applyCoinChange: +50 정상 적립
PASS  applyCoinChange: coin_logs 1행
PASS  applyCoinChange: cap 적용 시 max 까지만
PASS  applyCoinChange: 음수 정상 차감
PASS  applyCoinChange: 0 입력 no-op
PASS  applyCoinChange: 0 입력 시 coin_logs 미생성
PASS  saveCheck: nested tx 안에서 예외 없이 실행
PASS  saveCheck: nested tx 안에서 mmc 1행 적용
FAIL  adjustMemberScore: 함수 정의 (Task 5 에서 추가)  (function not yet defined)
FAIL  adjustMemberScore: score_logs (Task 5 에서 추가)  (function not yet defined)
PASS  qrRecordAttendance: nested tx 안에서 예외 없이 실행
PASS  qrRecordRevival: nested tx 안에서 예외 없이 실행

10 passed, 2 failed.
```

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev
git add tests/transaction_invariants.php
git commit -m "$(cat <<'EOF'
test(score-coin): #3 트랜잭션 인보리언트 베이스라인

8개 인보리언트 테스트 — Task 5/6 의 nested-tx-guard 와 adjustMemberScore
헬퍼 추출 전 baseline. saveCheck/adjustMemberScore 관련 항목은 의도적으로 FAIL.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: applyCoinChange — atomic UPDATE + nested-tx-guard

**Files:**
- Modify: `/root/boot-dev/public_html/includes/coin_functions.php` (`applyCoinChange` 함수, 라인 84~120)

- [ ] **Step 1: applyCoinChange 본문 교체**

Edit `/root/boot-dev/public_html/includes/coin_functions.php`. 라인 84 의 `function applyCoinChange(...)` 본문 전체 (function body 닫는 `}` 까지) 를 다음으로 교체:

```php
function applyCoinChange($db, $memberId, $cycleId, $coinChange, $reasonType, $reasonDetail = null, $adminId = null) {
    $startedHere = !$db->inTransaction();
    if ($startedHere) $db->beginTransaction();
    try {
        $mcc = getOrCreateMemberCycleCoins($db, $memberId, $cycleId);
        $cycleStmt = $db->prepare("SELECT max_coin FROM coin_cycles WHERE id = ?");
        $cycleStmt->execute([$cycleId]);
        $cycle = $cycleStmt->fetch();
        $maxCoin = $cycle ? (int)$cycle['max_coin'] : COIN_CYCLE_MAX;

        $beforeEarned = (int)$mcc['earned_coin'];

        if ($coinChange === 0) {
            if ($startedHere) $db->commit();
            return ['before' => $beforeEarned, 'after' => $beforeEarned, 'applied' => 0];
        }

        // Atomic UPDATE: 양수는 cap 적용, 음수는 cap 무관
        if ($coinChange > 0) {
            $db->prepare("
                UPDATE member_cycle_coins
                SET earned_coin = LEAST(earned_coin + ?, ?)
                WHERE member_id = ? AND cycle_id = ?
            ")->execute([$coinChange, $maxCoin, $memberId, $cycleId]);
        } else {
            $db->prepare("
                UPDATE member_cycle_coins
                SET earned_coin = earned_coin + ?
                WHERE member_id = ? AND cycle_id = ?
            ")->execute([$coinChange, $memberId, $cycleId]);
        }

        // 적용 후 실제 값 재조회 (race 시 약간 부정확할 수 있으나 balance 자체는 atomic)
        $stmt = $db->prepare("SELECT earned_coin FROM member_cycle_coins WHERE member_id = ? AND cycle_id = ?");
        $stmt->execute([$memberId, $cycleId]);
        $newEarned = (int)$stmt->fetchColumn();
        $applied = $newEarned - $beforeEarned;

        // applied=0 이면 (cap 가득찬 상태에서 양수 시도) coin_logs 도 skip
        if ($applied === 0) {
            if ($startedHere) $db->commit();
            return ['before' => $beforeEarned, 'after' => $beforeEarned, 'applied' => 0];
        }

        $db->prepare("
            INSERT INTO coin_logs (member_id, cycle_id, coin_change, before_coin, after_coin, reason_type, reason_detail, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$memberId, $cycleId, $applied, $beforeEarned, $newEarned, $reasonType, $reasonDetail, $adminId]);

        syncMemberCoinBalance($db, $memberId);

        if ($startedHere) $db->commit();
        return ['before' => $beforeEarned, 'after' => $newEarned, 'applied' => $applied];

    } catch (\Throwable $e) {
        if ($startedHere) $db->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 2: PHP syntax check**

Run: `php -l /root/boot-dev/public_html/includes/coin_functions.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: 테스트 재실행 — Test 1~4 PASS 확인**

Run: `cd /root/boot-dev && php tests/transaction_invariants.php`
Expected: Test 1, 2, 3, 4 (applyCoinChange 관련) 모두 PASS. Test 5/6 은 여전히 FAIL (다음 task).

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev
git add public_html/includes/coin_functions.php
git commit -m "$(cat <<'EOF'
feat(coin): #3 applyCoinChange atomic UPDATE + nested-tx-guard

SET earned_coin = ? (read-modify-write) → LEAST(earned_coin + ?, ?) atomic.
양수는 cap, 음수는 cap 무관. 0 입력은 short-circuit (기존 동작 유지).
nested-tx-guard 로 outer tx 안에서 호출 시 자체 BEGIN/COMMIT 안 함.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: saveCheck — nested-tx-guard

**Files:**
- Modify: `/root/boot-dev/public_html/includes/bootcamp_functions.php` (`saveCheck` 함수, 라인 224~276)

- [ ] **Step 1: saveCheck 본문을 가드로 래핑**

Edit `/root/boot-dev/public_html/includes/bootcamp_functions.php`. 라인 224 의 `function saveCheck(...)` 본문 전체를 다음으로 교체:

```php
function saveCheck($db, $memberId, $checkDate, $missionTypeId, $status, $source, $sourceRef, $adminId, $skipRecalc = false) {
    $startedHere = !$db->inTransaction();
    if ($startedHere) $db->beginTransaction();
    try {
        $existing = $db->prepare("
            SELECT id, status, source FROM member_mission_checks
            WHERE member_id = ? AND check_date = ? AND mission_type_id = ?
        ");
        $existing->execute([$memberId, $checkDate, $missionTypeId]);
        $existingRow = $existing->fetch();

        if ($existingRow && $existingRow['source'] === 'manual' && $source === 'automation' && (int)$existingRow['status'] === 1) {
            if ($startedHere) $db->commit();
            return ['action' => 'skipped', 'reason' => 'manual check already completed'];
        }

        $member = $db->prepare("SELECT cohort_id, group_id FROM bootcamp_members WHERE id = ?");
        $member->execute([$memberId]);
        $memberRow = $member->fetch();
        if (!$memberRow) {
            if ($startedHere) $db->commit();
            return ['action' => 'error', 'reason' => 'member not found'];
        }

        $statusVal = $status ? 1 : 0;
        $prevStatus = $existingRow ? (int)$existingRow['status'] : null;

        if ($existingRow) {
            $db->prepare("
                UPDATE member_mission_checks
                SET status = ?, source = ?, source_ref = ?, updated_by = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([$statusVal, $source, $sourceRef, $adminId, $existingRow['id']]);
            $action = ((int)$existingRow['status'] !== $statusVal) ? 'updated' : 'unchanged';
        } else {
            $db->prepare("
                INSERT INTO member_mission_checks
                    (member_id, cohort_id, group_id, check_date, mission_type_id, status, source, source_ref, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$memberId, $memberRow['cohort_id'], $memberRow['group_id'], $checkDate, $missionTypeId, $statusVal, $source, $sourceRef, $adminId]);
            $action = 'created';
        }

        if ($action !== 'unchanged' && !$skipRecalc) {
            recalculateMemberScore($db, $memberId, $adminId);
        }

        if ($action !== 'unchanged' && function_exists('processCoinForCheck')) {
            $codeMap = array_flip(getMissionCodeToIdMap($db));
            $mCode = $codeMap[$missionTypeId] ?? null;
            if ($mCode) {
                processCoinForCheck($db, $memberId, $checkDate, $mCode, $statusVal, $prevStatus, $adminId);
            }
        }

        if ($startedHere) $db->commit();
        return ['action' => $action, 'prev_status' => $prevStatus];

    } catch (\Throwable $e) {
        if ($startedHere) $db->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 2: PHP syntax check**

Run: `php -l /root/boot-dev/public_html/includes/bootcamp_functions.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: 테스트 재실행 — Test 5 PASS 확인**

Run: `cd /root/boot-dev && php tests/transaction_invariants.php`
Expected: Test 5 (saveCheck nested tx) PASS. Test 6 은 여전히 FAIL (Task 5 다음).

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev
git add public_html/includes/bootcamp_functions.php
git commit -m "$(cat <<'EOF'
feat(score): #3 saveCheck nested-tx-guard

outer tx 안에서 호출되면 자체 BEGIN/COMMIT 안 함, 직접 호출되면 자체 처리.
recalculateMemberScore + applyCoinChange 가 saveCheck 와 같은 tx 안에 묶여
부분 실패 시 모두 rollback.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: recalculateMemberScore — nested-tx-guard

**Files:**
- Modify: `/root/boot-dev/public_html/includes/bootcamp_functions.php` (`recalculateMemberScore` 함수, 라인 117~219)

- [ ] **Step 1: recalculateMemberScore 본문을 가드로 래핑**

Edit `/root/boot-dev/public_html/includes/bootcamp_functions.php`. 라인 117 의 `function recalculateMemberScore(...)` 본문 전체를 다음으로 교체:

```php
function recalculateMemberScore($db, $memberId, $adminId = null) {
    $startedHere = !$db->inTransaction();
    if ($startedHere) $db->beginTransaction();
    try {
        $member = $db->prepare("SELECT id, cohort_id, stage_no FROM bootcamp_members WHERE id = ?");
        $member->execute([$memberId]);
        $member = $member->fetch();
        if (!$member) {
            if ($startedHere) $db->commit();
            return null;
        }

        $cohort = $db->prepare("SELECT start_date, end_date FROM cohorts WHERE id = ?");
        $cohort->execute([$member['cohort_id']]);
        $cohortRow = $cohort->fetch();
        $adaptationEnd = $cohortRow
            ? date('Y-m-d', strtotime($cohortRow['start_date'] . ' + ' . (SCORE_ADAPTATION_DAYS - 1) . ' days'))
            : null;
        $cohortEndDate = $cohortRow['end_date'] ?? null;

        $codeToId = getMissionCodeToIdMap($db);

        $checks = $db->prepare("
            SELECT check_date, mission_type_id, status FROM member_mission_checks
            WHERE member_id = ? AND check_date > ?
            ORDER BY check_date
        ");
        $checks->execute([$memberId, $adaptationEnd ?? '1900-01-01']);
        $byDate = [];
        foreach ($checks->fetchAll() as $c) {
            $byDate[$c['check_date']][(int)$c['mission_type_id']] = (int)$c['status'];
        }

        $scoringStart = date('Y-m-d', strtotime($adaptationEnd . ' +1 day'));
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $scoringEnd = ($cohortEndDate && $cohortEndDate < $yesterday) ? $cohortEndDate : $yesterday;
        $rules = getPenaltyRules();
        $penaltySum = 0;

        $current = $scoringStart;
        while ($current <= $scoringEnd) {
            $missions = $byDate[$current] ?? [];
            $dow = (int)date('w', strtotime($current));

            foreach ($rules as $rule) {
                if ($rule['weekday'] !== null && $dow !== $rule['weekday']) continue;

                $passed = false;
                foreach ($rule['codes'] as $code) {
                    $typeId = $codeToId[$code] ?? null;
                    if ($typeId && ($missions[$typeId] ?? 0) === 1) {
                        $passed = true;
                        break;
                    }
                }
                if (!$passed) {
                    $penaltySum += $rule['penalty'];
                }
            }

            $current = date('Y-m-d', strtotime($current . ' +1 day'));
        }

        $revivals = $db->prepare("
            SELECT SUM(after_score - before_score) AS revival_delta
            FROM revival_logs WHERE member_id = ?
        ");
        $revivals->execute([$memberId]);
        $revivalDelta = (int)($revivals->fetch()['revival_delta'] ?? 0);

        $manuals = $db->prepare("
            SELECT SUM(score_change) AS manual_delta
            FROM score_logs WHERE member_id = ? AND reason_type = 'manual_adjustment'
        ");
        $manuals->execute([$memberId]);
        $manualDelta = (int)($manuals->fetch()['manual_delta'] ?? 0);

        $finalScore = SCORE_START + $penaltySum + $revivalDelta + $manualDelta;

        $current = $db->prepare("SELECT current_score FROM member_scores WHERE member_id = ?");
        $current->execute([$memberId]);
        $currentRow = $current->fetch();
        $beforeScore = $currentRow ? (int)$currentRow['current_score'] : 0;

        $db->prepare("
            INSERT INTO member_scores (member_id, current_score, last_calculated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE current_score = VALUES(current_score), last_calculated_at = NOW()
        ")->execute([$memberId, $finalScore]);

        if ($finalScore <= SCORE_OUT_THRESHOLD) {
            $db->prepare("UPDATE bootcamp_members SET member_status = 'out_of_group_management' WHERE id = ? AND member_status = 'active'")
               ->execute([$memberId]);
        } else {
            $db->prepare("UPDATE bootcamp_members SET member_status = 'active' WHERE id = ? AND member_status = 'out_of_group_management'")
               ->execute([$memberId]);
        }

        if ($finalScore !== $beforeScore) {
            $db->prepare("
                INSERT INTO score_logs (member_id, score_change, before_score, after_score, reason_type, reason_detail, created_by)
                VALUES (?, ?, ?, ?, 'recalculation', '전체 재계산', ?)
            ")->execute([$memberId, $finalScore - $beforeScore, $beforeScore, $finalScore, $adminId]);
        }

        if ($startedHere) $db->commit();
        return $finalScore;

    } catch (\Throwable $e) {
        if ($startedHere) $db->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 2: PHP syntax check**

Run: `php -l /root/boot-dev/public_html/includes/bootcamp_functions.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: 테스트 재실행 — 회귀 확인**

Run: `cd /root/boot-dev && php tests/transaction_invariants.php`
Expected: 기존 PASS (1~5) 유지. Test 6 은 여전히 FAIL.

또한 #2 의 인보리언트 회귀:
Run: `cd /root/boot-dev && php tests/qr_auth_invariants.php`
Expected: 13/13 PASS

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev
git add public_html/includes/bootcamp_functions.php
git commit -m "$(cat <<'EOF'
feat(score): #3 recalculateMemberScore nested-tx-guard

UPSERT member_scores + UPDATE bootcamp_members + INSERT score_logs 가 한 tx.
saveCheck 안에서 호출 시 outer tx 사용, 직접 호출 시 자체 BEGIN/COMMIT.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: adjustMemberScore 헬퍼 추출 + handleScoreAdjust 교체

**Files:**
- Modify: `/root/boot-dev/public_html/includes/bootcamp_functions.php` (`adjustMemberScore` 헬퍼 추가)
- Modify: `/root/boot-dev/public_html/api/services/score.php` (`handleScoreAdjust` 본문 교체)

- [ ] **Step 1: adjustMemberScore 헬퍼 추가**

Edit `/root/boot-dev/public_html/includes/bootcamp_functions.php`. 파일 끝(가장 마지막 `?>` 가 없으니 마지막 함수 정의 다음) 에 추가:

```php
/**
 * 수동 점수 조정 — score_logs INSERT + member_scores UPSERT 를 tx 로 묶음.
 *
 * @return array { before_score: int, after_score: int }
 */
if (!function_exists('adjustMemberScore')) {
function adjustMemberScore($db, int $memberId, int $scoreChange, ?string $reasonDetail, ?int $adminId): array {
    $startedHere = !$db->inTransaction();
    if ($startedHere) $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT current_score FROM member_scores WHERE member_id = ?");
        $stmt->execute([$memberId]);
        $row = $stmt->fetch();
        $beforeScore = $row ? (int)$row['current_score'] : 0;
        $afterScore = $beforeScore + $scoreChange;

        $db->prepare("
            INSERT INTO score_logs (member_id, score_change, before_score, after_score, reason_type, reason_detail, created_by)
            VALUES (?, ?, ?, ?, 'manual_adjustment', ?, ?)
        ")->execute([$memberId, $scoreChange, $beforeScore, $afterScore, $reasonDetail, $adminId]);

        $db->prepare("
            INSERT INTO member_scores (member_id, current_score, last_calculated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE current_score = VALUES(current_score), last_calculated_at = NOW()
        ")->execute([$memberId, $afterScore]);

        if ($startedHere) $db->commit();
        return ['before_score' => $beforeScore, 'after_score' => $afterScore];

    } catch (\Throwable $e) {
        if ($startedHere) $db->rollBack();
        throw $e;
    }
}
}
```

- [ ] **Step 2: handleScoreAdjust 본문을 헬퍼 호출로 교체**

Edit `/root/boot-dev/public_html/api/services/score.php`. 라인 25 의 `function handleScoreAdjust($method) { ... }` 함수 본문 전체를 다음으로 교체:

```php
function handleScoreAdjust($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $memberId = (int)($input['member_id'] ?? 0);
    $scoreChange = (int)($input['score_change'] ?? 0);
    $reasonDetail = trim($input['reason_detail'] ?? '') ?: null;

    if (!$memberId || !$scoreChange) jsonError('member_id, score_change 필요');

    $db = getDB();
    $result = adjustMemberScore($db, $memberId, $scoreChange, $reasonDetail, (int)$admin['admin_id']);

    jsonSuccess([
        'before_score' => $result['before_score'],
        'after_score' => $result['after_score'],
    ], '점수가 조정되었습니다.');
}
```

- [ ] **Step 3: PHP syntax check**

Run: `php -l /root/boot-dev/public_html/includes/bootcamp_functions.php && php -l /root/boot-dev/public_html/api/services/score.php`
Expected: 둘 다 `No syntax errors detected`

- [ ] **Step 4: 테스트 재실행 — Test 6 PASS 확인**

Run: `cd /root/boot-dev && php tests/transaction_invariants.php`
Expected: Test 6 (adjustMemberScore) 의 두 인보리언트 PASS.

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev
git add public_html/includes/bootcamp_functions.php public_html/api/services/score.php
git commit -m "$(cat <<'EOF'
feat(score): #3 adjustMemberScore 헬퍼 추출 + nested-tx-guard

handleScoreAdjust 의 핵심 로직(score_logs INSERT + member_scores UPSERT)을
adjustMemberScore() 헬퍼로 추출해서 테스트 가능하게.
HTTP 라우터는 thin wrapper.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: qr_actions.php — qrRecordAttendance / qrRecordRevival 가드

**Files:**
- Modify: `/root/boot-dev/public_html/includes/qr_actions.php` (두 함수 모두 가드 래핑)

- [ ] **Step 1: qrRecordAttendance 가드 래핑**

Edit `/root/boot-dev/public_html/includes/qr_actions.php`. `function qrRecordAttendance(...)` 의 본문 시작과 끝을 가드 패턴으로 감싼다. 기존 본문 (멤버 검증 → INSERT IGNORE → rowCount 분기 → saveCheck 호출 → return) 을 try 블록 안에 그대로 넣고 outer 가드 추가:

기존 함수 시작:
```php
function qrRecordAttendance(PDO $db, array $session, int $memberId, ?int $actorMemberId, string $clientIp, string $userAgent): array {
    // 멤버 검증
    $memberStmt = $db->prepare("...");
    ...
}
```

새 함수 시작 (본문은 그대로 try 안에):
```php
function qrRecordAttendance(PDO $db, array $session, int $memberId, ?int $actorMemberId, string $clientIp, string $userAgent): array {
    $startedHere = !$db->inTransaction();
    if ($startedHere) $db->beginTransaction();
    try {
        // 멤버 검증
        $memberStmt = $db->prepare("
            SELECT id, nickname, group_id, cohort_id FROM bootcamp_members
            WHERE id = ? AND cohort_id = ? AND is_active = 1 AND member_status != 'refunded'
        ");
        $memberStmt->execute([$memberId, $session['cohort_id']]);
        $member = $memberStmt->fetch();
        if (!$member) {
            if ($startedHere) $db->commit();
            return ['ok' => false, 'error' => '유효하지 않은 회원입니다.', 'http_status' => 400];
        }

        // Reserve-first: UNIQUE 가드 가장 먼저
        $insert = $db->prepare("
            INSERT IGNORE INTO qr_attendance (qr_session_id, member_id, actor_member_id, group_id, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$session['id'], $memberId, $actorMemberId, $member['group_id'], $clientIp, $userAgent]);

        if ($insert->rowCount() === 0) {
            if ($startedHere) $db->commit();
            return ['ok' => true, 'already' => true, 'member_name' => $member['nickname']];
        }

        // 첫 처리: saveCheck 부수 효과
        $studyLink = $db->prepare("SELECT id, study_date FROM study_sessions WHERE qr_session_id = ?");
        $studyLink->execute([$session['id']]);
        $studyRow = $studyLink->fetch();

        if ($studyRow) {
            $missionCode = 'bookclub_join';
            $checkDate = $studyRow['study_date'];
            $sourceRef = 'study_qr:' . $studyRow['id'];
        } else {
            $missionCode = 'zoom_daily';
            $checkDate = date('Y-m-d');
            $sourceRef = 'qr_session:' . $session['session_code'];
        }

        $missionTypeId = getMissionTypeId($db, $missionCode);
        if ($missionTypeId) {
            saveCheck(
                $db,
                $memberId,
                $checkDate,
                $missionTypeId,
                1,
                'manual',
                $sourceRef,
                $session['admin_id'] ? (int)$session['admin_id'] : null
            );
        }

        if ($startedHere) $db->commit();
        return ['ok' => true, 'already' => false, 'member_name' => $member['nickname']];

    } catch (\Throwable $e) {
        if ($startedHere) $db->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 2: qrRecordRevival 가드 래핑**

Edit 같은 파일. `function qrRecordRevival(...)` 본문도 동일 패턴으로 감싼다:

```php
function qrRecordRevival(PDO $db, array $session, int $memberId, ?int $actorMemberId, string $clientIp, string $userAgent): array {
    $startedHere = !$db->inTransaction();
    if ($startedHere) $db->beginTransaction();
    try {
        if (($session['session_type'] ?? '') !== 'revival') {
            if ($startedHere) $db->commit();
            return ['ok' => false, 'error' => '패자부활 세션이 아닙니다.', 'http_status' => 400];
        }

        // 멤버 검증
        $memberStmt = $db->prepare("
            SELECT id, nickname, group_id, cohort_id FROM bootcamp_members
            WHERE id = ? AND cohort_id = ? AND is_active = 1 AND member_status != 'refunded'
        ");
        $memberStmt->execute([$memberId, $session['cohort_id']]);
        $member = $memberStmt->fetch();
        if (!$member) {
            if ($startedHere) $db->commit();
            return ['ok' => false, 'error' => '유효하지 않은 회원입니다.', 'http_status' => 400];
        }

        // Reserve-first
        $insert = $db->prepare("
            INSERT IGNORE INTO qr_attendance (qr_session_id, member_id, actor_member_id, group_id, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$session['id'], $memberId, $actorMemberId, $member['group_id'], $clientIp, $userAgent]);

        if ($insert->rowCount() === 0) {
            if ($startedHere) $db->commit();
            return ['ok' => true, 'already' => true, 'member_name' => $member['nickname']];
        }

        ensureMemberScoreFresh($db, $memberId);
        $scoreStmt = $db->prepare("SELECT current_score FROM member_scores WHERE member_id = ?");
        $scoreStmt->execute([$memberId]);
        $scoreRow = $scoreStmt->fetch();
        $beforeScore = $scoreRow ? (int)$scoreRow['current_score'] : 0;

        if ($beforeScore > SCORE_REVIVAL_ELIGIBLE) {
            if ($startedHere) $db->commit();
            return [
                'ok' => true,
                'not_eligible' => true,
                'member_name' => $member['nickname'],
                'current_score' => $beforeScore,
            ];
        }

        $afterScore = $beforeScore + SCORE_REVIVAL_BONUS;
        $change = SCORE_REVIVAL_BONUS;
        $sessionAdminId = $session['admin_id'] ? (int)$session['admin_id'] : null;
        $note = 'QR 패자부활 (세션: ' . $session['session_code'] . ')';

        $db->prepare("
            INSERT INTO revival_logs (member_id, actor_member_id, qr_session_id, before_score, after_score, note, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$memberId, $actorMemberId, $session['id'], $beforeScore, $afterScore, $note, $sessionAdminId]);

        $db->prepare("
            INSERT INTO score_logs (member_id, score_change, before_score, after_score, reason_type, reason_detail, created_by)
            VALUES (?, ?, ?, ?, 'revival_adjustment', ?, ?)
        ")->execute([$memberId, $change, $beforeScore, $afterScore, $note, $sessionAdminId]);

        $db->prepare("
            INSERT INTO member_scores (member_id, current_score, last_calculated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE current_score = VALUES(current_score), last_calculated_at = NOW()
        ")->execute([$memberId, $afterScore]);

        if ($afterScore > SCORE_OUT_THRESHOLD) {
            $db->prepare("UPDATE bootcamp_members SET member_status = 'active' WHERE id = ? AND member_status = 'out_of_group_management'")
               ->execute([$memberId]);
        }

        if ($startedHere) $db->commit();
        return [
            'ok' => true,
            'already' => false,
            'not_eligible' => false,
            'member_name' => $member['nickname'],
            'before_score' => $beforeScore,
            'after_score' => $afterScore,
            'bonus' => $change,
        ];

    } catch (\Throwable $e) {
        if ($startedHere) $db->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 3: PHP syntax check**

Run: `php -l /root/boot-dev/public_html/includes/qr_actions.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: 테스트 재실행 — Test 7/8 PASS 확인 (이미 PASS 였으나 회귀 확인)**

Run: `cd /root/boot-dev && php tests/transaction_invariants.php`
Expected: 모든 인보리언트 PASS (10 passed, 0 failed — Test 1-8 의 인보리언트 항목별 카운트).

회귀:
Run: `cd /root/boot-dev && php tests/qr_auth_invariants.php`
Expected: 13/13 PASS

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev
git add public_html/includes/qr_actions.php
git commit -m "$(cat <<'EOF'
feat(qr): #3 qrRecordAttendance/qrRecordRevival nested-tx-guard

#2 산물에 BEGIN/COMMIT 가드 추가. saveCheck/recalculateMemberScore 가
같은 tx 안에 묶이고 부분 실패 시 모두 rollback.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: 통합 smoke + dev push

**Files:**
- 변경 없음 (검증 + push 만)

- [ ] **Step 1: 전체 테스트 한 번 더**

Run: `cd /root/boot-dev && php tests/transaction_invariants.php`
Expected: `12 passed, 0 failed.` (Task 1 의 t() 호출 12개 모두 PASS)

- [ ] **Step 2: #2 회귀**

Run: `cd /root/boot-dev && php tests/qr_auth_invariants.php`
Expected: `13 passed, 0 failed.`

- [ ] **Step 3: HTTP smoke (간접 확인)**

```bash
# verify 비로그인 가능 (회귀)
curl -sk "https://dev-boot.soritune.com/api/qr.php?action=verify&code=anything" | head -c 200
echo ""
# groups 비로그인 차단 (회귀)
curl -sk "https://dev-boot.soritune.com/api/qr.php?action=groups&code=anycode" | head -c 200
echo ""
```

Expected: 첫 번째는 200 + valid:false, 두 번째는 401 + 로그인 필요.

- [ ] **Step 4: dev push**

```bash
cd /root/boot-dev
git status   # working tree clean
git log --oneline origin/dev..HEAD
git push origin dev
git rev-parse origin/dev   # 새 origin/dev SHA
```

- [ ] **Step 5: 사용자 보고**

다음 정보 정리:
1. 트랜잭션 인보리언트 테스트 PASS 카운트
2. #2 회귀 13/13
3. 비로그인 차단 회귀 OK
4. dev push 완료 (origin/dev 새 SHA)
5. PROD 반영 전 사용자 검증 필요 항목:
   - 운영 페이지에서 점수/코인 직접 조정 동작 확인
   - 부트캠프 일상 흐름 (출석/패자부활) 회귀 검토
   - 마이그 없음 → PROD 반영은 main 머지 + boot-prod git pull 만

---

## Self-Review

### Spec coverage 체크

- [x] saveCheck nested-tx-guard — Task 3
- [x] applyCoinChange nested-tx-guard + atomic UPDATE — Task 2
- [x] recalculateMemberScore nested-tx-guard — Task 4
- [x] handleScoreAdjust → adjustMemberScore 헬퍼 추출 + tx-guard — Task 5
- [x] qrRecordAttendance nested-tx-guard — Task 6
- [x] qrRecordRevival nested-tx-guard — Task 6
- [x] 0 입력 short-circuit (applyCoinChange) — Task 2
- [x] 양수 cap LEAST atomic — Task 2
- [x] 음수 cap 무관 — Task 2
- [x] 마이그 없음 — 모든 task 가 코드만 변경

### 인보리언트 8개 매핑

1. applyCoinChange +50 → Task 1 Test 1 (Task 2 후 PASS)
2. applyCoinChange cap LEAST → Task 1 Test 2 (Task 2 후 PASS)
3. applyCoinChange 음수 → Task 1 Test 3 (Task 2 후 PASS)
4. applyCoinChange 0 → Task 1 Test 4 (Task 2 후 PASS)
5. saveCheck nested → Task 1 Test 5 (Task 3 후 PASS)
6. adjustMemberScore → Task 1 Test 6 (Task 5 후 PASS)
7. qrRecordAttendance nested → Task 1 Test 7 (Task 6 후 회귀 OK)
8. qrRecordRevival nested → Task 1 Test 8 (Task 6 후 회귀 OK)

### Type/시그니처 일관성

- `applyCoinChange` 시그니처 변경 없음 (return shape `before/after/applied` 유지)
- `saveCheck` / `recalculateMemberScore` / `qrRecordAttendance` / `qrRecordRevival` 시그니처 변경 없음
- 신규 `adjustMemberScore($db, int $memberId, int $scoreChange, ?string $reasonDetail, ?int $adminId): array` — Task 5 정의, 같은 task 의 handleScoreAdjust 호출 일치

### 변경 안 된 부분 (의도)

- `study.php`, `check.php`, `coin.php` 등 caller 변경 없음 — helper 자체 가드라 caller 변경 불필요
- `processCoinForCheck` 변경 없음 — 내부에서 applyCoinChange 호출하니 자동 보호
- `getOrCreateMemberCycleCoins`, `syncMemberCoinBalance` 변경 없음 — 단일 write 라 race 위험 작음

---

## 안 함

- PROD 반영 (별도 작업; 사용자 명시 요청 후 main 머지 + boot-prod git pull)
- SELECT FOR UPDATE 도입 (사용자 결정)
- coin_logs race-induced inflated delta 정밀 보정
- 스트레스/병렬 테스트 (PHP CLI 단일 thread 한계)
- helper 함수 시그니처 변경
- 마이그
