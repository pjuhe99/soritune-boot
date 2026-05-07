# 점수·코인 hot path 트랜잭션화 — 설계

날짜: 2026-05-07
범위: boot.soritune.com (boot-dev → boot-prod)
배경 audit: 2026-05-07 외부 코드 리뷰 — 12기 오픈 전 보완 항목 #3 (정합성)
선행: `2026-05-07-qr-self-verification-design.md` (#2). 이번 spec 은 #2 의 `qrRecordAttendance`/`qrRecordRevival` 트랜잭션 가드를 포함.

## 배경

`saveCheck`, `applyCoinChange`, `recalculateMemberScore`, `handleScoreAdjust`, `qrRecordRevival`, `qrRecordAttendance` 가 모두 다중 write 를 순차 실행하면서 BEGIN/COMMIT 이 없다. 부분 실패 시 데이터 분기 (예: `member_mission_checks` 는 들어갔는데 `member_scores` 가 안 변함, `coin_logs` 와 `member_cycle_coins` 잔액 불일치). `applyCoinChange` 의 `SET earned_coin = ?` 패턴은 read-modify-write 라 동시 호출 시 cap 우회/이중 적용 가능.

12기 오픈(2026-05-11) 전에 다중 write 경로를 트랜잭션으로 묶고, `applyCoinChange` UPDATE 를 atomic 으로 변환한다. SELECT FOR UPDATE 까지는 가지 않음 (QPS 가 낮아 락 부담 대비 가치 작음, 사용자 결정).

## 정책 결정

사용자 승인 요약:
- BEGIN/COMMIT + 원자 UPDATE 까지 (Recommended option)
- SELECT FOR UPDATE 는 도입 안 함
- coin_logs 의 동시-race-induced inflated delta 는 알려진 trade-off 로 수용 (balance 자체는 atomic UPDATE 로 정확)

## 변경 범위

수정 (총 6개 함수, 파일 5개):
- `public_html/includes/bootcamp_functions.php` — `saveCheck`, `recalculateMemberScore` 에 nested-tx-guard 추가
- `public_html/includes/coin_functions.php` — `applyCoinChange` 에 nested-tx-guard + `SET earned_coin = ?` → `SET earned_coin = LEAST(earned_coin + ?, ?)` (양수) / `SET earned_coin = earned_coin + ?` (음수) 변경 + 적용 후 SELECT 로 실제 값 회수
- `public_html/api/services/score.php` — `handleScoreAdjust` 에 BEGIN/COMMIT 추가
- `public_html/includes/qr_actions.php` — `qrRecordAttendance`, `qrRecordRevival` 에 nested-tx-guard 추가 (#2 에서 deferred 된 부분)

신규:
- `tests/transaction_invariants.php` — 6~8개 인보리언트 CLI 테스트

영향 없음:
- 기존 호출자 (`study.php`, `check.php`, `coin.php`, 보너스 함수들) 는 변경 없음. 자체적으로 BEGIN 안 해도 helper 가 자기 진입 시 BEGIN.
- 마이그 없음 (스키마 변경 없음).

## 트랜잭션 가드 패턴

```php
function saveCheck($db, ...) {
    $startedHere = !$db->inTransaction();
    if ($startedHere) $db->beginTransaction();
    try {
        // ... existing logic ...
        if ($startedHere) $db->commit();
        return $result;
    } catch (\Throwable $e) {
        if ($startedHere) $db->rollBack();
        throw $e;
    }
}
```

이 패턴을 6개 함수 전부에 적용. 핵심 보장:
- 함수 진입 시 outer tx 가 있으면 (`$db->inTransaction()===true`) 자체 BEGIN 안 함, 자체 COMMIT/ROLLBACK 도 안 함 → outer tx 가 결정.
- outer tx 없으면 자체 BEGIN/COMMIT/ROLLBACK 수행.
- 어떤 깊이로 호출되든 정확히 한 번의 BEGIN ↔ COMMIT/ROLLBACK 쌍.
- exception 은 항상 rethrow → 호출 체인의 가장 바깥 try/catch 가 처리 (변경 없음).

### 호출 트리 예시

```
qrRecordAttendance (top-level)
  → BEGIN (startedHere=true)
  → INSERT IGNORE qr_attendance
  → saveCheck
    → startedHere=false (이미 outer tx)
    → INSERT/UPDATE member_mission_checks
    → recalculateMemberScore
      → startedHere=false
      → SELECT/UPSERT member_scores 등
    → processCoinForCheck → applyCoinChange
      → startedHere=false
      → atomic UPDATE earned_coin
      → INSERT coin_logs
      → syncMemberCoinBalance
  → COMMIT (qrRecordAttendance 에서, startedHere=true 였음)
```

```
study.php → saveCheck (top-level here, qrRecord 와 무관)
  → BEGIN (startedHere=true)
  → ...
  → COMMIT
```

## 원자 UPDATE — `applyCoinChange`

기존 (race vulnerable):

```php
$beforeEarned = (int)$mcc['earned_coin'];
$newEarned = $beforeEarned + $coinChange;
if ($coinChange > 0 && $newEarned > $maxCoin) {
    $coinChange = max(0, $maxCoin - $beforeEarned);
    $newEarned = $beforeEarned + $coinChange;
}
UPDATE member_cycle_coins SET earned_coin = ? WHERE member_id = ? AND cycle_id = ?
```

새 패턴:

```php
$beforeEarned = (int)$mcc['earned_coin'];   // 로깅용 snapshot

if ($coinChange > 0) {
    $db->prepare("
        UPDATE member_cycle_coins
        SET earned_coin = LEAST(earned_coin + ?, ?)
        WHERE member_id = ? AND cycle_id = ?
    ")->execute([$coinChange, $maxCoin, $memberId, $cycleId]);
} elseif ($coinChange < 0) {
    $db->prepare("
        UPDATE member_cycle_coins
        SET earned_coin = earned_coin + ?
        WHERE member_id = ? AND cycle_id = ?
    ")->execute([$coinChange, $memberId, $cycleId]);
} else {
    return ['before' => $beforeEarned, 'after' => $beforeEarned, 'applied' => 0];
}

// 적용 후 실제 값 재조회
$stmt = $db->prepare("SELECT earned_coin FROM member_cycle_coins WHERE member_id = ? AND cycle_id = ?");
$stmt->execute([$memberId, $cycleId]);
$newEarned = (int)$stmt->fetchColumn();
$applied = $newEarned - $beforeEarned;

// coin_logs INSERT
$db->prepare("
    INSERT INTO coin_logs (member_id, cycle_id, coin_change, before_coin, after_coin, reason_type, reason_detail, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
")->execute([$memberId, $cycleId, $applied, $beforeEarned, $newEarned, $reasonType, $reasonDetail, $adminId]);

syncMemberCoinBalance($db, $memberId);

return ['before' => $beforeEarned, 'after' => $newEarned, 'applied' => $applied];
```

핵심 보장:
- atomic UPDATE → cap 절대 초과하지 않음 (DB 가 LEAST 로 enforce)
- 0 입력은 short-circuit (기존 동작 유지)
- 음수는 cap 무시 (기존 동작 — coin 차감 시 음수 가능)

알려진 trade-off (race-induced):
- 동시 +50 두 호출이 100→150→200 진행 시: T2 의 `coin_logs` 는 before=100, after=200, applied=100 으로 기록될 수 있음. 실제 T2 만의 기여는 50.
- balance 는 정확 (UPDATE 가 atomic). audit log 만 약간 inflated.
- 12기 QPS 가 매우 낮아 (~0.02 평균, 5-10/sec 피크) 실질 발생 빈도 vanishingly small.

## 데이터 플로우 — 트랜잭션 경계

```
[top-level entrypoint]
  ├── handleScoreAdjust         → BEGIN → SELECT score → INSERT score_logs → UPSERT member_scores → COMMIT
  ├── qrRecordRevival           → BEGIN → INSERT IGNORE qr_attendance → ensureMemberScoreFresh →
  │                                       INSERT revival_logs → INSERT score_logs →
  │                                       UPSERT member_scores → UPDATE bootcamp_members → COMMIT
  ├── qrRecordAttendance        → BEGIN → INSERT IGNORE qr_attendance → saveCheck → COMMIT
  └── study.php / check.php / coin.php / 보너스 콜
        → saveCheck or applyCoinChange called directly (no outer tx)
        → those self-BEGIN/COMMIT
```

각 함수는 자기 진입 시점에 outer tx 가 있는지 확인하고, 없을 때만 자체 BEGIN. 6개 함수 모두 동일 패턴.

## 에러 핸들링

- 부분 실패 (helper 안의 SQL exception): outer 함수의 catch 가 잡아서 rollback. 모든 writes 무효화.
- nested 호출에서 rollback 발생: PDO rollback 은 "현재 transaction 전체" 를 rollback. nested-tx-guard 덕분에 가장 바깥 BEGIN 의 catch 만이 rollback 호출 → 안전.
- exception 은 항상 rethrow → API 라우터 (jsonError 호출) 또는 cron 의 try/catch 가 잡음. 사용자에게 500 에러 노출 가능성 있음 — 기존 동작과 동일.
- 코너 케이스: caller (예: study.php) 가 자체 try/catch 후 다른 saveCheck 호출. 첫 saveCheck rollback 후 두 번째 saveCheck 는 새 BEGIN 가능 — PDO state 가 깨끗해짐. 정상.

## 테스트

신규 `tests/transaction_invariants.php` (transaction + finally rollback 로 PROD 데이터 영향 0):

1. **applyCoinChange 양수**: +50 호출 → earned_coin += 50, coin_logs 1행, applied=50
2. **applyCoinChange cap**: earned_coin=180, max=200 인 상태에서 +50 → earned_coin=200 (LEAST 적용), applied=20
3. **applyCoinChange 음수**: -10 호출 → earned_coin -= 10, cap 무관, applied=-10
4. **applyCoinChange 0**: 0 입력 → no change, no log
5. **saveCheck 정상**: 첫 mmc 생성 → mmc 1행 + score_logs (변동 시) + coin 변동 모두 적용 (rollback 안 됨)
6. **saveCheck rollback**: 강제 fail 주입 (예: 잘못된 missionTypeId 또는 일부러 invalid SQL) → mmc 0행, score_logs 0행, coin_logs 0행 (모두 rollback)
7. **handleScoreAdjust 동등 path**: BEGIN/COMMIT 정상 / 강제 fail 시 score_logs + member_scores 둘 다 rollback
8. **nested tx**: 외부에서 BEGIN 후 saveCheck 호출 → saveCheck 가 startedHere=false 로 자체 BEGIN 안 함, 외부 COMMIT 으로 통합 적용

강제 fail 주입 방법:
- 가장 단순: 트랜잭션 가드 함수 안의 가장 마지막 SQL 직전에 invalid 데이터 (예: NULL into NOT NULL 컬럼) 를 사용하도록 테스트 헬퍼 함수로 추출.
- 또는 mock 으로 PDO 대신 wrapper 를 쓰지만 boot 패턴에 없음 → 실제 invalid SQL 주입 방식.
- 구체 방법은 plan 단계에서 결정.

회귀: Task 7 (#2 의 인보리언트 13개) 가 그대로 통과해야 함. saveCheck/applyCoinChange 동작 변경이 없으므로 기대 통과.

## 롤아웃

```
1. boot-dev: spec/plan 검토 → 6개 함수 수정
2. boot-dev: tests/transaction_invariants.php 작성
3. boot-dev: php tests/transaction_invariants.php → 모두 PASS
4. boot-dev: php tests/qr_auth_invariants.php → 13/13 회귀 PASS
5. boot-dev: dev push
6. ⛔ 사용자 검증
7. main 머지 + boot-prod pull (사용자 명시 요청 시)
```

마이그 없음. PROD 적용 시 lock 영향 없음.

## 안 함

- SELECT FOR UPDATE 도입 (사용자 결정)
- coin_logs race-induced inflated delta 정밀 보정 (FOR UPDATE 없으면 어쩔 수 없음)
- 동시성 stress 테스트 (PHP CLI 단일 thread, 시뮬레이션만 가능)
- 다른 hot path 트랜잭션화 (cron, notify_dispatcher 등 — 별건)
- helper 함수 시그니처 변경 (인터페이스 호환 유지)
- 호출자(study.php 등) 변경

## 인접 spec 과의 관계

- **#2 QR 본인 확인** (이미 dev 에 push 됨, commit `a39bfd1`): qrRecordAttendance/qrRecordRevival 의 트랜잭션 가드 추가가 이번 spec 의 일부. 두 spec 묶어서 PROD 반영하면 자연스럽게 일관성 보존.
- **#5 admin.js 부분 분리**: 이번 spec 의 가드 패턴이 도입되면 향후 admin.php 분리 시 같은 패턴 사용. 기반.
