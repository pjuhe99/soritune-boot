# 회원용 코인 내역 화면 + Reward Group Cohort 단위 재편 — 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 회원이 대시보드의 코인 stat 카드를 탭하면 자기 적립 내역(cycle별 분리 + 지급 시점 배너 + earn log)을 볼 수 있게 하고, 동시에 reward_group 단위를 "11-12 묶음"에서 "cohort별 단일 group"으로 재편한다.

**Architecture:** 11기 cycle은 현행 "11-12기 리워드" group을 "11기 리워드"로 이름 변경 후 계속 귀속. 12기 cycle은 새 "12기 리워드" group으로 이동(신규 마이그 1회). `checkDistributePrerequisites`에서 "cycle 정확히 2개" 제약 제거. `handleCoinRewardGroupDistribute`에서 비활성 회원(`refunded/leaving/out_of_group_management`)을 지급에서 제외하고 `reward_forfeited` 로그로 소각. 신규 엔드포인트 `my_coin_history`가 회원의 모든 open reward_group + cycle + earn log를 반환. 신규 JS 모듈 `member-coin-history.js`가 대시보드 내 섹션 토글로 상세 화면을 렌더.

**Tech Stack:** PHP 8 (PDO, procedural), Vanilla JS, MySQL 8. 테스트 인프라 없음 → SQL/curl/UI 수동 검증.

**Design doc:** `docs/superpowers/specs/2026-04-21-coin-history-view-design.md`

**Deploy rule (CLAUDE.md):** 모든 작업은 `boot-dev`(dev 브랜치)에서만. dev push 후 사용자 명시적 요청 시에만 main 머지 + prod 반영.

---

## Phase A: 데이터 마이그 (선결)

### Task A1: Group 재편 마이그 스크립트 작성

**Files:**
- Create: `/root/boot-dev/migrate_split_11_12_groups.php`

- [ ] **Step 1: 스크립트 작성**

기존 `migrate_split_cycle_11_12.php`의 argv/dry-run 패턴을 따른다.

```php
<?php
/**
 * boot.soritune.com - 11-12 묶음 group을 cohort별 단독 group으로 재편
 *
 * Before:
 *   reward_groups: [id=X, name="11-12기 리워드", status=open]
 *     소속 cycles: 11기(id=N1), 12기(id=N2)
 *
 * After:
 *   reward_groups: [id=X, name="11기 리워드", status=open]      ← 이름만 변경
 *                  [id=Y, name="12기 리워드", status=open]      ← 신규
 *   11기 cycle: reward_group_id = X
 *   12기 cycle: reward_group_id = Y
 *
 * 실행:
 *   php migrate_split_11_12_groups.php --dry-run --old-group-id=X
 *   php migrate_split_11_12_groups.php --execute --old-group-id=X
 */

require_once __DIR__ . '/public_html/config.php';

$opts = getopt('', ['dry-run', 'execute', 'old-group-id:']);
$dryRun  = isset($opts['dry-run']);
$execute = isset($opts['execute']);
$oldId   = (int)($opts['old-group-id'] ?? 0);

if ((!$dryRun && !$execute) || !$oldId) {
    fwrite(STDERR, "Usage: php migrate_split_11_12_groups.php --dry-run|--execute --old-group-id=ID\n");
    exit(2);
}

$db = getDB();

echo "=== 11-12 묶음 group 재편 ===\n";
echo "  모드: " . ($dryRun ? 'DRY-RUN' : 'EXECUTE') . "\n";
echo "  대상 group_id: $oldId\n\n";

// 기존 group 확인
$stmt = $db->prepare("SELECT * FROM reward_groups WHERE id = ?");
$stmt->execute([$oldId]);
$oldGroup = $stmt->fetch();
if (!$oldGroup) { fwrite(STDERR, "group id=$oldId 없음\n"); exit(1); }
if ($oldGroup['status'] !== 'open') { fwrite(STDERR, "이미 distributed된 group은 재편 불가\n"); exit(1); }
echo "  [확인] 기존 group: {$oldGroup['name']} (status={$oldGroup['status']})\n";

// 소속 cycles 확인
$cStmt = $db->prepare("SELECT id, name, start_date, end_date, status FROM coin_cycles WHERE reward_group_id = ? ORDER BY start_date");
$cStmt->execute([$oldId]);
$cycles = $cStmt->fetchAll();
echo "  [확인] 소속 cycles: " . count($cycles) . "개\n";
foreach ($cycles as $c) {
    echo "    - id={$c['id']} name={$c['name']} ({$c['start_date']}~{$c['end_date']}) status={$c['status']}\n";
}

if (count($cycles) !== 2) { fwrite(STDERR, "cycle 개수가 2가 아님 — 수동 확인 필요\n"); exit(1); }

// start_date 오름차순: 첫 cycle = 11기(유지), 두 번째 cycle = 12기(이동 대상)
$keepCycle = $cycles[0];
$moveCycle = $cycles[1];

echo "\n  [계획]\n";
echo "    1) group id=$oldId 이름: \"{$oldGroup['name']}\" → \"{$keepCycle['name']} 리워드\"\n";
echo "    2) 새 group INSERT: name=\"{$moveCycle['name']} 리워드\", status=open\n";
echo "    3) cycle id={$moveCycle['id']}의 reward_group_id → 새 group id\n";

if ($dryRun) {
    echo "\n=== DRY-RUN 종료 ===\n";
    exit(0);
}

$db->beginTransaction();
try {
    // 1) 기존 group 이름 변경
    $db->prepare("UPDATE reward_groups SET name = ? WHERE id = ?")
       ->execute(["{$keepCycle['name']} 리워드", $oldId]);

    // 2) 새 group 생성
    $db->prepare("INSERT INTO reward_groups (name, status) VALUES (?, 'open')")
       ->execute(["{$moveCycle['name']} 리워드"]);
    $newId = (int)$db->lastInsertId();
    echo "    → 새 group id=$newId\n";

    // 3) 12기 cycle 이동
    $db->prepare("UPDATE coin_cycles SET reward_group_id = ? WHERE id = ?")
       ->execute([$newId, $moveCycle['id']]);

    // 검증: 각 group에 cycle 1개씩
    $verifyStmt = $db->prepare("SELECT reward_group_id, COUNT(*) AS cnt FROM coin_cycles WHERE reward_group_id IN (?, ?) GROUP BY reward_group_id");
    $verifyStmt->execute([$oldId, $newId]);
    $counts = [];
    foreach ($verifyStmt->fetchAll() as $r) $counts[(int)$r['reward_group_id']] = (int)$r['cnt'];
    if (($counts[$oldId] ?? 0) !== 1 || ($counts[$newId] ?? 0) !== 1) {
        throw new Exception("검증 실패: 각 group에 cycle 1개씩이어야 함. 실제: " . json_encode($counts));
    }

    $db->commit();
    echo "\n=== EXECUTE 완료 ===\n";
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "실패, 롤백됨: " . $e->getMessage() . "\n");
    exit(1);
}
```

- [ ] **Step 2: DEV DB에서 기존 group id 확인**

```bash
cd /root/boot-dev
mysql -u root -p$(grep DB_PASS .db_credentials | cut -d= -f2) SORITUNECOM_DEV_BOOT -e "SELECT id, name, status FROM reward_groups"
```

결과의 "11-12기 리워드" 행의 id를 Step 3에 사용.

- [ ] **Step 3: dry-run 실행**

```bash
cd /root/boot-dev
php migrate_split_11_12_groups.php --dry-run --old-group-id=<ID>
```

Expected 출력 예:
```
  [확인] 기존 group: 11-12기 리워드 (status=open)
  [확인] 소속 cycles: 2개
    - id=2 name=11기 (...) status=closed
    - id=3 name=12기 (...) status=active
  [계획]
    1) group id=N 이름: "11-12기 리워드" → "11기 리워드"
    2) 새 group INSERT: name="12기 리워드", status=open
    3) cycle id=3의 reward_group_id → 새 group id
```

- [ ] **Step 4: execute 실행**

```bash
php migrate_split_11_12_groups.php --execute --old-group-id=<ID>
```

- [ ] **Step 5: 결과 검증 SQL**

```sql
SELECT rg.id, rg.name, rg.status, cc.id AS cycle_id, cc.name AS cycle_name
FROM reward_groups rg
LEFT JOIN coin_cycles cc ON cc.reward_group_id = rg.id
ORDER BY rg.id;
```

Expected: 두 행 — "11기 리워드" ← 11기 cycle 1개, "12기 리워드" ← 12기 cycle 1개.

- [ ] **Step 6: 회원 데이터 불변 확인**

```sql
-- 회원 코인 합계가 마이그 전후 동일해야 함
SELECT member_id, SUM(earned_coin) AS total FROM member_cycle_coins GROUP BY member_id ORDER BY member_id LIMIT 10;
-- coin_logs 건수 불변
SELECT COUNT(*) FROM coin_logs;
```

Step 3의 pre-migration 스냅샷과 비교. 차이 있으면 롤백 필요 (트랜잭션은 이미 커밋됐으니 수동 복구).

- [ ] **Step 7: 커밋**

```bash
cd /root/boot-dev
git add migrate_split_11_12_groups.php
git commit -m "feat: 11-12 묶음 group을 cohort별 단독 group으로 재편하는 마이그 스크립트"
```

---

## Phase B: 백엔드 코어 (지급 로직 + 라벨 맵)

### Task B1: `checkDistributePrerequisites`에서 cycle 개수 제약 제거

**Files:**
- Modify: `/root/boot-dev/public_html/includes/coin_functions.php:665-683`

- [ ] **Step 1: 함수 수정**

기존 코드:

```php
function checkDistributePrerequisites($group) {
    $blockers = [];
    if ($group['status'] !== 'open') {
        $blockers[] = "이미 지급 완료된 group";
    }
    $cycles = $group['cycles'] ?? [];
    if (count($cycles) !== 2) {
        $blockers[] = "cycle이 정확히 2개여야 함 (현재 " . count($cycles) . "개)";
    }
    foreach ($cycles as $c) {
        if ($c['status'] !== 'closed') {
            $blockers[] = "{$c['name']} cycle이 아직 closed 아님";
        }
    }
    return [
        'can_distribute' => empty($blockers),
        'blockers'       => $blockers,
    ];
}
```

다음으로 교체:

```php
function checkDistributePrerequisites($group) {
    $blockers = [];
    if ($group['status'] !== 'open') {
        $blockers[] = "이미 지급 완료된 group";
    }
    $cycles = $group['cycles'] ?? [];
    if (count($cycles) < 1) {
        $blockers[] = "group에 소속된 cycle이 없음";
    }
    foreach ($cycles as $c) {
        if ($c['status'] !== 'closed') {
            $blockers[] = "{$c['name']} cycle이 아직 closed 아님";
        }
    }
    return [
        'can_distribute' => empty($blockers),
        'blockers'       => $blockers,
    ];
}
```

- [ ] **Step 2: 커밋 (아직 테스트 없음, Task B2 완료 후 함께 검증)**

```bash
cd /root/boot-dev
git add public_html/includes/coin_functions.php
git commit -m "refactor: reward_group 지급 사전조건에서 cycle 개수=2 제약 제거"
```

### Task B2: 지급 시 비활성 회원 소각(reward_forfeited) 처리

**Files:**
- Modify: `/root/boot-dev/public_html/api/services/coin_reward_group.php:208-268` (`handleCoinRewardGroupDistribute`의 트랜잭션 블록)

- [ ] **Step 1: 지급 핸들러의 회원 조회 쿼리에 활성 필터 추가**

현재 `handleCoinRewardGroupDistribute`는 `member_cycle_coins`에서 모든 회원을 조회한다. 활성 회원과 비활성 회원을 JOIN으로 분리해서 처리한다.

기존 (211-217행):

```php
$ph = implode(',', array_fill(0, count($cycleIds), '?'));
$stmt = $db->prepare("
    SELECT mcc.member_id, mcc.cycle_id, mcc.earned_coin, mcc.used_coin
    FROM member_cycle_coins mcc
    WHERE mcc.cycle_id IN ($ph)
");
$stmt->execute($cycleIds);
$rows = $stmt->fetchAll();
```

다음으로 교체:

```php
$ph = implode(',', array_fill(0, count($cycleIds), '?'));
$stmt = $db->prepare("
    SELECT mcc.member_id, mcc.cycle_id, mcc.earned_coin, mcc.used_coin,
           bm.is_active, bm.member_status
    FROM member_cycle_coins mcc
    JOIN bootcamp_members bm ON bm.id = mcc.member_id
    WHERE mcc.cycle_id IN ($ph)
");
$stmt->execute($cycleIds);
$allRows = $stmt->fetchAll();

$INACTIVE_STATUSES = ['refunded', 'leaving', 'out_of_group_management'];
$activeRows   = [];
$forfeitRows  = [];
foreach ($allRows as $r) {
    $isInactive = ((int)$r['is_active'] === 0) || in_array($r['member_status'], $INACTIVE_STATUSES, true);
    if ($isInactive) {
        $forfeitRows[] = $r;
    } else {
        $activeRows[] = $r;
    }
}
$rows = $activeRows;
```

- [ ] **Step 2: 소각 처리 블록 추가**

`$db->commit()` 직전, `syncMemberCoinBalance` 루프 뒤에 소각 블록을 추가. 즉 기존 259-261행:

```php
foreach (array_keys($perMember) as $mid) {
    syncMemberCoinBalance($db, $mid);
}
```

다음으로 교체:

```php
foreach (array_keys($perMember) as $mid) {
    syncMemberCoinBalance($db, $mid);
}

// 비활성 회원 소각 (reward_forfeited)
$forfeitedMemberIds = [];
foreach ($forfeitRows as $r) {
    $mid = (int)$r['member_id'];
    $cid = (int)$r['cycle_id'];
    $earnedBefore = (int)$r['earned_coin'];
    $usedBefore   = (int)$r['used_coin'];
    $active = $earnedBefore - $usedBefore;
    if ($active <= 0) continue;

    $db->prepare("UPDATE member_cycle_coins SET used_coin = earned_coin WHERE member_id = ? AND cycle_id = ?")
       ->execute([$mid, $cid]);

    $db->prepare("
        INSERT INTO coin_logs (member_id, cycle_id, coin_change, before_coin, after_coin, reason_type, reason_detail, created_by)
        VALUES (?, ?, ?, ?, ?, 'reward_forfeited', ?, ?)
    ")->execute([$mid, $cid, -$active, $active, 0, "하차자 코인 소실 ({$group['name']})", $admin['admin_id']]);

    $forfeitedMemberIds[$mid] = true;
}

foreach (array_keys($forfeitedMemberIds) as $mid) {
    syncMemberCoinBalance($db, $mid);
}
```

- [ ] **Step 3: 응답 메시지에 소각 건수 포함**

기존 264행:

```php
jsonSuccess(['granted' => $grantedCount], "리워드 지급 완료: {$grantedCount}명");
```

다음으로 교체:

```php
$forfeitedCount = count($forfeitedMemberIds);
jsonSuccess(
    ['granted' => $grantedCount, 'forfeited' => $forfeitedCount],
    "리워드 지급 완료: 지급 {$grantedCount}명 / 소각 {$forfeitedCount}명"
);
```

- [ ] **Step 4: 검증 (수동, DEV DB에 임시 데이터)**

```bash
# 1. 임시 group 생성 (또는 기존 12기 group 활용)
# 2. 소속 cycle status=closed로 바꿔놓음
# 3. 그 cycle에 member_cycle_coins 행 하나 추가하되 해당 member의 is_active=0 으로 설정
# 4. curl로 distribute 호출
curl -s -b /tmp/admin_session -X POST \
  'https://dev-boot.soritune.com/api/bootcamp.php?action=coin_reward_group_distribute' \
  -H 'Content-Type: application/json' \
  -d '{"group_id":<테스트 group id>}'
# 5. 응답에 granted/forfeited 둘 다 포함 확인
# 6. coin_logs에서 reward_forfeited 행 생성 확인
mysql ... -e "SELECT reason_type, COUNT(*) FROM coin_logs WHERE reason_type IN ('reward_distribution','reward_forfeited') GROUP BY reason_type"
```

(수동 확인 후 테스트 데이터는 rollback 또는 DELETE로 정리.)

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/services/coin_reward_group.php
git commit -m "feat: reward_group 지급 시 비활성 회원 코인 소각(reward_forfeited) 처리"
```

---

## Phase C: 회원 API (my_coin_history)

### Task C1: 사유 라벨 매핑 헬퍼 + 내역 조회 함수 추가

**Files:**
- Modify: `/root/boot-dev/public_html/includes/coin_functions.php` (파일 끝에 함수 추가)

- [ ] **Step 1: 헬퍼 함수 추가**

`coin_functions.php` 파일 끝(마지막 `}` 다음)에 아래 블록을 추가:

```php

// ══════════════════════════════════════════════════════════════
// 회원 코인 내역 (my_coin_history)
// ══════════════════════════════════════════════════════════════

/**
 * reason_type → 회원 노출용 한글 라벨.
 * 음수 변동은 라벨 뒤에 "(취소)"를 붙여 구분.
 */
function coinReasonLabel($reasonType, $coinChange) {
    $map = [
        'study_open'          => '복습스터디 개설',
        'study_join'          => '복습스터디 참여',
        'leader_coin'         => '리더 코인',
        'perfect_attendance'  => '찐완주 보너스',
        'hamemmal_bonus'      => '하멈말 보너스',
        'cheer_award'         => '응원상',
        'manual_adjustment'   => '운영자 조정',
        'reward_distribution' => '적립금 지급',
        'reward_forfeited'    => '하차로 인한 소실',
    ];
    $label = $map[$reasonType] ?? $reasonType;
    if ((int)$coinChange < 0 && $reasonType !== 'reward_distribution' && $reasonType !== 'reward_forfeited') {
        $label .= ' (취소)';
    }
    return $label;
}

/**
 * 특정 cycle 상태에 따른 회원용 지급 시점 안내 문구.
 */
function coinPayoutMessage($cycleName, $cycleStatus) {
    if ($cycleStatus === 'closed') {
        return "{$cycleName} 마감 후 곧 적립금으로 지급됩니다";
    }
    return "{$cycleName} 마감 시 적립금으로 지급됩니다 (다음 기수에 함께 정산)";
}

/**
 * 회원의 open reward_group + 각 group의 cycle + 각 cycle의 earn log 반환.
 * 스펙 5.1 참조.
 */
function getMemberCoinHistory($db, $memberId) {
    // 1. 회원이 코인 행을 가진 open group들
    $gStmt = $db->prepare("
        SELECT DISTINCT rg.id, rg.name
        FROM reward_groups rg
        JOIN coin_cycles cc ON cc.reward_group_id = rg.id
        JOIN member_cycle_coins mcc ON mcc.cycle_id = cc.id AND mcc.member_id = ?
        WHERE rg.status = 'open'
        ORDER BY (SELECT MIN(start_date) FROM coin_cycles WHERE reward_group_id = rg.id) ASC
    ");
    $gStmt->execute([$memberId]);
    $groups = $gStmt->fetchAll();

    $result = [];
    foreach ($groups as $g) {
        $gid = (int)$g['id'];

        // 2. 해당 group의 cycles + 회원의 earned/used
        $cStmt = $db->prepare("
            SELECT cc.id, cc.name, cc.status,
                   COALESCE(mcc.earned_coin, 0) AS earned_coin,
                   COALESCE(mcc.used_coin, 0)   AS used_coin
            FROM coin_cycles cc
            LEFT JOIN member_cycle_coins mcc ON mcc.cycle_id = cc.id AND mcc.member_id = ?
            WHERE cc.reward_group_id = ?
            ORDER BY cc.start_date ASC
        ");
        $cStmt->execute([$memberId, $gid]);
        $cycles = $cStmt->fetchAll();

        $cycleList = [];
        foreach ($cycles as $c) {
            $cid = (int)$c['id'];
            $earned = (int)$c['earned_coin'] - (int)$c['used_coin'];

            // 3. 해당 cycle의 해당 회원 coin_logs
            $lStmt = $db->prepare("
                SELECT DATE(created_at) AS d, reason_type, reason_detail, coin_change
                FROM coin_logs
                WHERE member_id = ? AND cycle_id = ?
                ORDER BY created_at DESC, id DESC
            ");
            $lStmt->execute([$memberId, $cid]);
            $logRows = $lStmt->fetchAll();

            $logs = [];
            foreach ($logRows as $lr) {
                $logs[] = [
                    'date'        => $lr['d'],
                    'reason_type' => $lr['reason_type'],
                    'label'       => coinReasonLabel($lr['reason_type'], (int)$lr['coin_change']),
                    'change'      => (int)$lr['coin_change'],
                ];
            }

            $cycleList[] = [
                'cycle_id'        => $cid,
                'cycle_name'      => $c['name'],
                'cycle_status'    => $c['status'],
                'earned'          => $earned,
                'payout_message'  => coinPayoutMessage($c['name'], $c['status']),
                'logs'            => $logs,
            ];
        }

        $result[] = [
            'group_id'   => $gid,
            'group_name' => $g['name'],
            'cycles'     => $cycleList,
        ];
    }

    return $result;
}
```

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev
git add public_html/includes/coin_functions.php
git commit -m "feat: 회원 코인 내역 조회 헬퍼 + 사유 라벨 매핑"
```

### Task C2: `my_coin_history` 엔드포인트 핸들러 + 라우팅

**Files:**
- Modify: `/root/boot-dev/public_html/api/services/member_page.php` (새 핸들러 함수 추가)
- Modify: `/root/boot-dev/public_html/api/bootcamp.php` (switch case 추가)

- [ ] **Step 1: 핸들러 함수 추가**

`member_page.php` 파일 끝에 (마지막 함수 닫는 `}` 뒤에) 추가:

```php

/**
 * 회원 본인의 코인 내역 조회 (open reward_group 기준, scope A)
 * 응답 스펙: 2026-04-21-coin-history-view-design.md §5.1
 */
function handleMyCoinHistory() {
    $member = requireMember();
    $memberId = (int)$member['member_id'];
    $db = getDB();
    $groups = getMemberCoinHistory($db, $memberId);
    jsonSuccess(['groups' => $groups]);
}
```

- [ ] **Step 2: 라우터에 case 추가**

`public_html/api/bootcamp.php`의 `// ── Coin Reward Groups ──` 블록(239-248행) 바로 아래, `// ── Scores ──` 블록 위에 추가:

```php
// ── Member-facing coin history ──────────────────────────────
case 'my_coin_history':                       handleMyCoinHistory(); break;
```

- [ ] **Step 3: curl로 확인**

회원으로 로그인 세션 쿠키를 확보한 뒤:

```bash
# 1. 회원 로그인
curl -s -c /tmp/member_session -X POST \
  'https://dev-boot.soritune.com/api/member.php?action=login' \
  -H 'Content-Type: application/json' \
  -d '{"phone":"01012345678"}'
# 2. my_coin_history 호출
curl -s -b /tmp/member_session \
  'https://dev-boot.soritune.com/api/bootcamp.php?action=my_coin_history' | python3 -m json.tool
```

Expected 응답 구조(회원이 11기/12기 모두에 코인 보유한 경우):

```json
{
  "success": true,
  "groups": [
    {
      "group_id": <N>, "group_name": "11기 리워드",
      "cycles": [{"cycle_id": ..., "cycle_name": "11기", "cycle_status": "closed",
                  "earned": 50, "payout_message": "11기 마감 후 곧 적립금으로 지급됩니다",
                  "logs": [{"date": "2026-04-18", "reason_type": "leader_coin",
                            "label": "리더 코인", "change": 40}, ...]}]
    },
    {
      "group_id": <M>, "group_name": "12기 리워드",
      "cycles": [{"cycle_id": ..., "cycle_name": "12기", "cycle_status": "active",
                  "earned": 8, "payout_message": "12기 마감 시 적립금으로 지급됩니다 (다음 기수에 함께 정산)",
                  "logs": [...]}]
    }
  ]
}
```

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/services/member_page.php public_html/api/bootcamp.php
git commit -m "feat: 회원용 my_coin_history 엔드포인트 추가"
```

---

## Phase D: 회원 UI (신규 화면 + 진입)

### Task D1: `member-coin-history.js` 모듈 작성

**Files:**
- Create: `/root/boot-dev/public_html/js/member-coin-history.js`

- [ ] **Step 1: 모듈 작성**

```javascript
/* ══════════════════════════════════════════════════════════════
   MemberCoinHistory — /내코인 상세 화면
   대시보드의 코인 stat 카드를 탭하면 진입, "뒤로" 버튼으로 복귀.
   스펙: docs/superpowers/specs/2026-04-21-coin-history-view-design.md
   ══════════════════════════════════════════════════════════════ */
const MemberCoinHistory = (() => {
    const API = '/api/bootcamp.php?action=';

    /**
     * 화면을 root 요소에 렌더. onBack 콜백은 "뒤로" 시 호출.
     */
    async function render(root, onBack) {
        root.innerHTML = `
            <div class="coin-history-page">
                <div class="coin-history-header">
                    <button class="coin-history-back" id="coin-history-back-btn">← 뒤로</button>
                    <div class="coin-history-title">내 코인 내역</div>
                </div>
                <div class="coin-history-body" id="coin-history-body">
                    <div class="coin-history-loading">불러오는 중…</div>
                </div>
            </div>
        `;
        document.getElementById('coin-history-back-btn').onclick = onBack;

        const r = await App.get(API + 'my_coin_history');
        const body = document.getElementById('coin-history-body');
        if (!r.success) {
            body.innerHTML = '<div class="coin-history-empty">내역을 불러오지 못했습니다.</div>';
            return;
        }
        body.innerHTML = renderGroups(r.groups || []);
    }

    function renderGroups(groups) {
        if (!groups.length) {
            return '<div class="coin-history-empty">아직 받은 코인이 없습니다.<br>복습스터디에 참여해 보세요.</div>';
        }
        const cycleCards = [];
        for (const g of groups) {
            for (const c of (g.cycles || [])) {
                cycleCards.push(renderCycleCard(c));
            }
        }
        return cycleCards.join('');
    }

    function renderCycleCard(cycle) {
        const statusBadge = cycle.cycle_status === 'active'
            ? '<span class="coin-cycle-badge coin-cycle-active">적립 중</span>'
            : '';
        const bannerClass = cycle.cycle_status === 'closed'
            ? 'coin-cycle-banner-closed'
            : 'coin-cycle-banner-active';
        const logs = (cycle.logs || []).map(renderLog).join('');
        const emptyLogs = !(cycle.logs || []).length
            ? '<div class="coin-history-empty-logs">이 cycle에 기록이 없습니다.</div>' : '';
        return `
            <div class="coin-cycle-card">
                <div class="coin-cycle-head">
                    <div class="coin-cycle-name">${App.esc(cycle.cycle_name)} 코인 ${statusBadge}</div>
                    <div class="coin-cycle-total">${parseInt(cycle.earned) || 0}</div>
                </div>
                <div class="coin-cycle-banner ${bannerClass}">${App.esc(cycle.payout_message)}</div>
                <div class="coin-cycle-logs">${logs}${emptyLogs}</div>
            </div>
        `;
    }

    function renderLog(log) {
        const change = parseInt(log.change) || 0;
        const sign = change >= 0 ? '+' : '';
        const changeClass = change >= 0 ? 'coin-log-plus' : 'coin-log-minus';
        return `
            <div class="coin-log-row">
                <span class="coin-log-date">${App.esc(formatDate(log.date))}</span>
                <span class="coin-log-label">${App.esc(log.label)}</span>
                <span class="coin-log-change ${changeClass}">${sign}${change}</span>
            </div>
        `;
    }

    function formatDate(yyyymmdd) {
        // "2026-04-18" → "4/18"
        const m = /^\d{4}-(\d{2})-(\d{2})$/.exec(yyyymmdd || '');
        if (!m) return yyyymmdd || '';
        return `${parseInt(m[1])}/${parseInt(m[2])}`;
    }

    return { render };
})();
```

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/member-coin-history.js
git commit -m "feat: 회원 코인 내역 화면 모듈 추가"
```

### Task D2: CSS 스타일

**Files:**
- Modify: `/root/boot-dev/public_html/css/member.css` (파일 끝에 섹션 추가)

- [ ] **Step 1: 스타일 추가**

`member.css` 끝에 추가:

```css

/* ── Coin History Page ──────────────────────────────────────── */
.coin-history-page {
    min-height: 100vh;
    min-height: 100dvh;
    background: var(--color-bg-page);
    padding: var(--space-5);
    padding-top: calc(var(--safe-top) + var(--space-5));
    max-width: 720px;
    margin: 0 auto;
}
.coin-history-header {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    margin-bottom: var(--space-4);
}
.coin-history-back {
    background: transparent;
    border: none;
    color: var(--color-text-sub);
    font-size: var(--text-md);
    cursor: pointer;
    padding: var(--space-2);
}
.coin-history-title {
    font-weight: var(--font-extrabold);
    font-size: var(--text-lg);
    color: var(--color-text);
}
.coin-history-loading,
.coin-history-empty {
    padding: var(--space-8) var(--space-4);
    text-align: center;
    color: var(--color-text-sub);
    font-size: var(--text-sm);
}

.coin-cycle-card {
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    padding: var(--space-4);
    margin-bottom: var(--space-4);
}
.coin-cycle-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: var(--space-3);
}
.coin-cycle-name {
    font-weight: var(--font-bold);
    font-size: var(--text-md);
    color: var(--color-text);
}
.coin-cycle-badge {
    font-size: var(--text-xs);
    padding: 2px 6px;
    border-radius: var(--radius-sm);
    margin-left: var(--space-2);
    font-weight: var(--font-semibold);
}
.coin-cycle-active {
    background: #e3f2fd;
    color: #1565c0;
}
.coin-cycle-total {
    font-weight: var(--font-extrabold);
    font-size: var(--text-xl);
    color: var(--color-primary);
}
.coin-cycle-banner {
    font-size: var(--text-xs);
    padding: var(--space-2) var(--space-3);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-3);
}
.coin-cycle-banner-closed {
    background: #fff8e1;
    color: #a37200;
}
.coin-cycle-banner-active {
    background: #e3f2fd;
    color: #0d4a8f;
}
.coin-cycle-logs {
    font-size: var(--text-sm);
}
.coin-log-row {
    display: grid;
    grid-template-columns: 44px 1fr auto;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) 0;
    border-bottom: 1px dashed var(--color-border);
}
.coin-log-row:last-child {
    border-bottom: none;
}
.coin-log-date {
    color: var(--color-text-sub);
    font-size: var(--text-xs);
}
.coin-log-label {
    color: var(--color-text);
}
.coin-log-change {
    font-weight: var(--font-semibold);
}
.coin-log-plus  { color: #2a7a3a; }
.coin-log-minus { color: #b03030; }
.coin-history-empty-logs {
    padding: var(--space-3);
    text-align: center;
    color: var(--color-text-sub);
    font-size: var(--text-xs);
}

.stat-coin { cursor: pointer; }
.stat-coin:hover,
.stat-coin:active {
    background: var(--color-bg-soft, #f8f8fc);
}
```

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev
git add public_html/css/member.css
git commit -m "style: 회원 코인 내역 화면 스타일"
```

### Task D3: `index.php`에 JS 포함

**Files:**
- Modify: `/root/boot-dev/public_html/index.php`

기존 include는 cache-busting 래퍼 `v()`를 사용:
```html
<script src="/js/member-home.js<?= v('/js/member-home.js') ?>"></script>
<script src="/js/member-shortcuts.js<?= v('/js/member-shortcuts.js') ?>"></script>
```

- [ ] **Step 1: member-home.js 줄 바로 아래에 추가**

```html
<script src="/js/member-coin-history.js<?= v('/js/member-coin-history.js') ?>"></script>
```

member-shortcuts.js 위에 삽입하여 DOM 렌더 순서 유지.

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev
git add public_html/index.php
git commit -m "chore: member-coin-history.js 번들에 포함"
```

### Task D4: `member-home.js`의 코인 stat 카드 클릭 핸들러

**Files:**
- Modify: `/root/boot-dev/public_html/js/member-home.js` (stat-coin 카드 영역 + render 함수)

- [ ] **Step 1: 코인 카드에 `data-action="open-coin-history"` 추가 + `?` 버튼에 `event.stopPropagation()` 보장**

`member-home.js`의 기존 stat-coin 블록(약 39-47행):

```javascript
<div class="stat-card stat-coin">
    <div class="stat-card-icon">
        <svg ...>...</svg>
    </div>
    <div class="stat-card-body">
        <div class="stat-card-value">${member.coin ?? 0}</div>
        <div class="stat-card-label">코인 <button class="cur-help-btn" data-guide="coin_guide">?</button></div>
    </div>
</div>
```

다음으로 교체 (카드 자체를 클릭 가능하게 + `?` 버튼은 버블링 차단):

```javascript
<div class="stat-card stat-coin" data-action="open-coin-history" role="button" tabindex="0">
    <div class="stat-card-icon">
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.8" fill="none"/><text x="10" y="14" text-anchor="middle" font-size="10" font-weight="700" fill="currentColor">C</text></svg>
    </div>
    <div class="stat-card-body">
        <div class="stat-card-value">${member.coin ?? 0}</div>
        <div class="stat-card-label">코인 <button class="cur-help-btn" data-guide="coin_guide" onclick="event.stopPropagation()">?</button></div>
    </div>
</div>
```

(주의: 기존 `<svg ...>...</svg>` 자리에는 현재 파일의 실제 SVG 마크업을 그대로 유지. 위 스니펫은 편의상 축약.)

- [ ] **Step 2: `render` 함수 내 이벤트 바인딩 추가**

`member-home.js`의 기존 `render` 함수 안, `.cur-help-btn` 바인딩 뒤(약 72-74행 주변)에 추가:

```javascript
const coinCard = headerEl.querySelector('.stat-coin[data-action="open-coin-history"]');
if (coinCard) {
    const openHistory = () => MemberApp.openCoinHistory();
    coinCard.addEventListener('click', openHistory);
    coinCard.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openHistory(); }
    });
}
```

- [ ] **Step 3: 커밋 (Task D5에서 MemberApp.openCoinHistory 정의까지 완료 후 통합 검증)**

```bash
cd /root/boot-dev
git add public_html/js/member-home.js
git commit -m "feat: 코인 stat 카드 클릭 시 코인 내역 화면 진입"
```

### Task D5: `member.js`에 화면 전환 API 추가

**Files:**
- Modify: `/root/boot-dev/public_html/js/member.js`

- [ ] **Step 1: showDashboard의 root.innerHTML에 `coin-history` 섹션 추가**

`member.js`의 `showDashboard` 함수(약 155-173행)의 `<div class="member-content">` 바로 아래에 `<div id="member-coin-history-area" style="display:none"></div>`를 추가. 기존:

```javascript
<div class="member-content">
    <div id="member-home-area"></div>
    <div id="member-tabs-area"></div>
    ...
</div>
```

다음으로 교체:

```javascript
<div class="member-content">
    <div id="member-home-area"></div>
    <div id="member-tabs-area"></div>
    ...
</div>
<div id="member-coin-history-area" style="display:none"></div>
```

`member-logout-wrap` 블록은 기존 위치(`member-content` 안)에 그대로 두어 대시보드 복귀 시 표시되게 한다.

- [ ] **Step 2: MemberApp에 `openCoinHistory`/`closeCoinHistory` 추가**

`MemberApp` IIFE 안, `return { init };` 바로 위에 추가:

```javascript
function openCoinHistory() {
    const dashboardContent = root.querySelector('.member-content');
    const historyArea = document.getElementById('member-coin-history-area');
    if (!historyArea) return;
    dashboardContent.style.display = 'none';
    historyArea.style.display = '';
    window.scrollTo({ top: 0, behavior: 'instant' });
    MemberCoinHistory.render(historyArea, closeCoinHistory);
}

function closeCoinHistory() {
    const dashboardContent = root.querySelector('.member-content');
    const historyArea = document.getElementById('member-coin-history-area');
    if (!historyArea) return;
    historyArea.style.display = 'none';
    historyArea.innerHTML = '';
    dashboardContent.style.display = '';
    window.scrollTo({ top: 0, behavior: 'instant' });
}
```

그리고 `return { init };`을 다음으로 교체:

```javascript
return { init, openCoinHistory, closeCoinHistory };
```

- [ ] **Step 3: 브라우저 수동 검증**

DEV URL `https://dev-boot.soritune.com/`에서 회원으로 로그인:

1. 대시보드에 "코인 N" 카드가 보이는지.
2. 카드의 `?` 버튼 클릭 → 기존 `coin_guide` 모달만 뜨고 상세 화면은 안 뜨는지 (stopPropagation 확인).
3. 카드 바디(숫자/아이콘) 클릭 → `/내코인` 화면 전환.
4. 11기 코인 카드가 위에, 12기 코인 카드가 아래 (ASC 정렬).
5. 각 카드의 배너 문구가 스펙대로 표시.
6. 로그 항목의 날짜/라벨/증감이 올바른지 (특히 음수 로그에 "(취소)" 라벨).
7. "← 뒤로" 버튼 → 대시보드 복귀, 스크롤 리셋.
8. 회원이 코인 전혀 없으면 "아직 받은 코인이 없습니다" 빈 상태.

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/member.js
git commit -m "feat: MemberApp에 코인 내역 화면 진입/복귀 API"
```

---

## Phase E: 운영자 UI 미세 조정

### Task E1: 운영자 preview 문구 확인

**Files:**
- (no code change 예상, 확인만)

- [ ] **Step 1: preview 응답 확인**

운영자 로그인 후 `/operation/#coins`의 Reward Groups 섹션에서 "12기 리워드" group의 [지급] 버튼 클릭 → preview 모달.

- `blockers` 목록에 "cycle이 정확히 2개여야 함" 문구가 **없어야** 함 (Task B1 반영).
- 12기 cycle이 아직 active이므로 `{cycle_name} cycle이 아직 closed 아님` 하나만 뜨는 게 정상.
- `can_distribute: false`, [지급 실행] 버튼 비활성 상태.

문구에 문제 있으면 해당 JS(bootcamp.js 또는 admin-coins 파트)에서 인라인 수정.

- [ ] **Step 2: 문제 발견 시 수정 + 커밋** (기본 가정: 수정 없음)

```bash
# (해당 시에만)
git add public_html/js/...
git commit -m "fix: reward_group preview 문구에서 cycle 개수 제약 참조 제거"
```

---

## Phase F: 통합 검증 + dev push

### Task F1: 통합 시나리오 수동 테스트

- [ ] **Step 1: 활성 회원 시나리오**

DEV DB의 활성 회원 1명으로 로그인해 다음 확인:

- 11기 cycle 코인 / 12기 cycle 코인이 각각 보이는지.
- 화면의 합계 = API 응답 각 cycle의 `earned` 합과 일치하는지.
- 음수 로그(체크 해제) 있는 경우 "(취소)" 라벨로 보이는지.

- [ ] **Step 2: 비활성 회원 소각 플로우 (선택, 시간 여유 시)**

임시로 DEV DB 한 행을 `is_active=0` 로 바꿔놓고, 12기 cycle에 해당 회원 `member_cycle_coins` 행 생성 (수동 INSERT로) 후 12기 group을 테스트로 `status='closed'` 상태로 임시 변경 → distribute 실행 → `reward_forfeited` 로그 생성 확인 → 원복(rollback 또는 수동 DELETE + status 복구).

기록된 로그 쿼리:
```sql
SELECT * FROM coin_logs WHERE reason_type = 'reward_forfeited' ORDER BY id DESC LIMIT 5;
```

- [ ] **Step 3: 운영자 memberTable 회귀**

`/operation/#members` 에서 기존 `current_reward_group` 렌더가 여전히 동작하는지 확인 (한 group만 노출되어도 OK — 스펙 9.리스크에 명시된 수용 사항).

### Task F2: dev 브랜치 push

- [ ] **Step 1: push**

```bash
cd /root/boot-dev
git push origin dev
```

- [ ] **Step 2: ⛔ 멈춤. 사용자에게 dev 확인 요청**

여기서 사용자에게 보고 후 명시적 "운영 반영 요청"을 기다린다. 요청 받기 전까지 main 머지/prod pull 금지 (CLAUDE.md).

### Task F3: (사용자 운영 반영 요청 시에만) main 머지 + prod 적용

- [ ] **Step 1: main 머지**

```bash
cd /root/boot-dev
git checkout main
git merge dev
git push origin main
git checkout dev
```

- [ ] **Step 2: PROD DB 백업 + 마이그**

```bash
cd /root/boot-prod

# DB 백업
mkdir -p /root/backups
mysqldump -u root -p$(grep DB_PASS .db_credentials | cut -d= -f2) SORITUNECOM_BOOT > /root/backups/boot_before_split_groups_$(date +%Y%m%d_%H%M).sql

# 코드 pull
git pull origin main

# PROD의 "11-12기 리워드" group id 확인
mysql -u root -p$(grep DB_PASS .db_credentials | cut -d= -f2) SORITUNECOM_BOOT -e "SELECT id, name, status FROM reward_groups"

# 마이그 dry-run (위 id 사용)
php migrate_split_11_12_groups.php --dry-run --old-group-id=<PROD ID>

# 실제 실행
php migrate_split_11_12_groups.php --execute --old-group-id=<PROD ID>
```

- [ ] **Step 3: PROD 검증**

회원 1명으로 로그인해 Task F1 Step 1 시나리오 반복. 운영자 `/operation/#coins`에서 group 이름/cycle 분리 확인.

---

## Self-Review

이 계획이 스펙 `2026-04-21-coin-history-view-design.md`의 모든 요구를 다루는지 체크:

| 스펙 섹션 | 담당 Task |
|---|---|
| §2 접근 경로 (코인 카드 탭 → /내코인) | D4, D5 |
| §2 기능: cycle별 분리 카드 + 지급 배너 + earn log | D1 |
| §2 기능: 여러 open group의 cycle 모두 표시 | C1, D1 |
| §3.1 Group cohort별 재편 | A1 |
| §3.2 지급 사전조건 (cycle 개수 제약 제거) | B1 |
| §3.3 하차자 필터 + reward_forfeited | B2 |
| §3.4 기존 스펙 불변식 수정 | B1 |
| §4.2 migrate_split_11_12_groups.php | A1 |
| §5.1 `my_coin_history` API | C1, C2 |
| §5.2 preview의 cycle 수 제약 제거 반영 | B1, E1 |
| §5.3 reason_type 한글 라벨 | C1 |
| §6.1 코인 stat 카드 클릭 진입 | D4 |
| §6.2 레이아웃 (배너 색상, 날짜 포맷 등) | D1, D2 |
| §6.3 라우팅 (섹션 토글 방식) | D5 |
| §6.4 `?` 버튼 기존대로 유지 | D4 Step 1 (stopPropagation) |
| §7.1 DEV 마이그 순서 | A1 → B/C/D → F |
| §7.2 PROD 적용 | F3 |
