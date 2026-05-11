# 코인 cross-cohort 회원 view — 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 회원 view 의 코인 집계가 같은 user_id 의 earlier-cohort sibling 행까지 합산해서, 12기 chip 에서 11기 때 받은 cycle_12 코인이 정상 노출되게.

**Architecture:** 데이터 마이그·schema 변경 없이, 회원 view 의 3개 진입점(대시보드 stat / cycle 카드 / 현재 reward group)이 공용 helper `findCoinSiblingMemberIds` 로 sibling member_id 목록을 받고, `getDisplayedRewardGroupIds` 로 표시 대상 rg 를 결정한 뒤, mcc 합산을 IN-list 로 확장한다. coin_logs 는 표시 전용으로만 별도 쿼리에 cohort 라벨을 부착한다.

**Tech Stack:** PHP 8.5 (PDO), MariaDB 10.5, boot CLI 테스트 패턴 (`tests/<name>_invariants.php` + `t()` 헬퍼), 회원 화면용 vanilla JS.

**Spec:** `docs/superpowers/specs/2026-05-11-coin-cross-cohort-view-design.md` (commit `b106ed6`)

---

## 파일 구조

**신규**
- `tests/coin_cross_cohort_invariants.php` — CLI 인보리언트 (INV-1~6 + 응답 구조 검증)

**수정**
- `public_html/includes/coin_functions.php`
  - 신규 함수: `findCoinSiblingMemberIds`, `getDisplayedRewardGroupIds`, `getMemberDisplayedCoinTotal`
  - 수정: `getCurrentRewardGroupForMember` (L623~661) — sibling-aware
  - 수정: `getMemberCoinHistory` (L757~831) — sibling-aware + 응답에 `logs_by_cohort` 추가
- `public_html/api/member.php`
  - L54 (login), L129 (check_session), L180 (dashboard) 의 `member_coin_balances` 직접 read 블록을 `getMemberDisplayedCoinTotal` 호출로 교체
- `public_html/js/member-coin-history.js`
  - `renderCycleCard` (L48) 가 `logs_by_cohort` 분기 렌더 + sub-section 라벨 / CSS 추가

**검증만 (수정 없음)**
- `public_html/api/services/member_page.php:451` — `handleMyCoinHistory` 가 `getMemberCoinHistory` 의 응답 구조를 그대로 통과시키는지 read-only 확인.

---

## Task 1: 신규 helper `findCoinSiblingMemberIds`

**Files:**
- Modify: `/root/boot-dev/public_html/includes/coin_functions.php` (파일 끝에 추가)
- Test: `/root/boot-dev/tests/coin_cross_cohort_invariants.php` (신규)

같은 user_id 의 earlier cohort row 들의 member_id + cohort_id + cohort_label 반환. 첫 원소는 항상 currentMemberId 자신. user_id 비어 있으면 자기만 반환. request-scoped static cache.

- [ ] **Step 1: 인보리언트 스캐폴드 작성 (failing baseline)**

Create `/root/boot-dev/tests/coin_cross_cohort_invariants.php`:

```php
<?php
/**
 * 코인 cross-cohort view 인보리언트
 * 사용: php tests/coin_cross_cohort_invariants.php
 *
 * read-only. PROD/DEV 어디서 돌려도 데이터 변경 없음.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/coin_functions.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; }
    else { $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n"; }
}

$db = getDB();

// ══════════════════════════════════════════════════════════════
// INV-6: findCoinSiblingMemberIds 가 cohort_id < 현재 만 반환
// ══════════════════════════════════════════════════════════════

// 12기 dual-enrollment 회원 1명 표본 (user_id 기준)
$sample = $db->query("
    SELECT user_id, cohort_id, id AS member_id
    FROM bootcamp_members
    WHERE user_id IS NOT NULL AND user_id != ''
      AND cohort_id = (SELECT id FROM cohorts WHERE cohort = '12기' LIMIT 1)
      AND user_id IN (
        SELECT user_id FROM bootcamp_members
        WHERE user_id IS NOT NULL AND user_id != ''
        GROUP BY user_id HAVING COUNT(*) >= 2
      )
    LIMIT 1
")->fetch();

if ($sample) {
    $siblings = findCoinSiblingMemberIds($db, (int)$sample['member_id']);

    t('INV-6 첫 원소가 currentMemberId',
        !empty($siblings) && (int)$siblings[0]['member_id'] === (int)$sample['member_id']);

    $earlierOnly = true;
    foreach ($siblings as $s) {
        if ((int)$s['member_id'] === (int)$sample['member_id']) continue;
        if ((int)$s['cohort_id'] >= (int)$sample['cohort_id']) { $earlierOnly = false; break; }
    }
    t('INV-6 siblings 는 cohort_id < 현재 만', $earlierOnly);

    t('INV-6 dual-enrollment 회원은 sibling ≥ 1건 (자기 외)',
        count($siblings) >= 2);
} else {
    echo "SKIP  INV-6 (dual-enrollment 12기 회원 없음)\n";
}

// user_id 없는 회원 → 자기만 반환
$noUser = $db->query("
    SELECT id FROM bootcamp_members
    WHERE user_id IS NULL OR user_id = ''
    LIMIT 1
")->fetch();
if ($noUser) {
    $siblings = findCoinSiblingMemberIds($db, (int)$noUser['id']);
    t('INV-6 user_id 비어 있으면 자기만', count($siblings) === 1);
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 인보리언트 실행 — fatal 확인**

```bash
cd /root/boot-dev && php tests/coin_cross_cohort_invariants.php
```

Expected: PHP Fatal error "Call to undefined function findCoinSiblingMemberIds()" (의도된 fail — 다음 step 에서 구현).

- [ ] **Step 3: `findCoinSiblingMemberIds` 함수 구현**

Append to `/root/boot-dev/public_html/includes/coin_functions.php` (파일 끝):

```php

// ══════════════════════════════════════════════════════════════
// Cross-cohort 회원 view (Option A — 쿼리 시점 user_id 합산)
// 스펙: docs/superpowers/specs/2026-05-11-coin-cross-cohort-view-design.md
// ══════════════════════════════════════════════════════════════

/**
 * 같은 user_id 의 earlier-cohort sibling member_id 목록 반환.
 * 첫 원소는 항상 currentMemberId.
 * user_id 가 비어 있으면 자기만 반환 (phone fallback 안 함 — 동명이인 위험).
 * request-scoped static cache.
 *
 * @return array<int, array{member_id:int, cohort_id:int, cohort_label:string}>
 */
function findCoinSiblingMemberIds($db, $memberId): array {
    static $cache = [];
    $key = (int)$memberId;
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $db->prepare("
        SELECT bm.id AS member_id, bm.cohort_id, c.cohort AS cohort_label,
               bm.user_id
        FROM bootcamp_members bm
        JOIN cohorts c ON c.id = bm.cohort_id
        WHERE bm.id = ?
    ");
    $stmt->execute([$key]);
    $cur = $stmt->fetch();
    if (!$cur) return $cache[$key] = [];

    $self = [
        'member_id'    => (int)$cur['member_id'],
        'cohort_id'    => (int)$cur['cohort_id'],
        'cohort_label' => (string)$cur['cohort_label'],
    ];

    if (empty($cur['user_id'])) {
        return $cache[$key] = [$self];
    }

    $sStmt = $db->prepare("
        SELECT bm.id AS member_id, bm.cohort_id, c.cohort AS cohort_label
        FROM bootcamp_members bm
        JOIN cohorts c ON c.id = bm.cohort_id
        WHERE bm.user_id = ?
          AND bm.cohort_id < ?
          AND bm.id <> ?
        ORDER BY bm.cohort_id ASC
    ");
    $sStmt->execute([$cur['user_id'], $cur['cohort_id'], $key]);

    $result = [$self];
    foreach ($sStmt->fetchAll() as $r) {
        $result[] = [
            'member_id'    => (int)$r['member_id'],
            'cohort_id'    => (int)$r['cohort_id'],
            'cohort_label' => (string)$r['cohort_label'],
        ];
    }
    return $cache[$key] = $result;
}
```

- [ ] **Step 4: 인보리언트 재실행 — INV-6 PASS 확인**

```bash
cd /root/boot-dev && php tests/coin_cross_cohort_invariants.php
```

Expected: 모든 t() PASS (3 또는 4건). 실패 시 함수 구현 다시 점검.

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/coin_functions.php tests/coin_cross_cohort_invariants.php
git commit -m "feat(coin): findCoinSiblingMemberIds helper + invariant"
```

---

## Task 2: 신규 helper `getDisplayedRewardGroupIds`

**Files:**
- Modify: `/root/boot-dev/public_html/includes/coin_functions.php`
- Test: `/root/boot-dev/tests/coin_cross_cohort_invariants.php`

표시 group 룰: rg.status='open' AND (현재+sibling 어느 row 든 mcc 보유  OR  rg 안에 cc.name = "<현재 chip cohort label>" 인 cycle 존재).

- [ ] **Step 1: 인보리언트 케이스 추가**

Append to `/root/boot-dev/tests/coin_cross_cohort_invariants.php` (`exit($fail === 0 ? 0 : 1);` 위에):

```php

// ══════════════════════════════════════════════════════════════
// INV-5: 12기 chip 시 cycle_11(rg 3) 미포함
// ══════════════════════════════════════════════════════════════

if ($sample) {
    $siblings = findCoinSiblingMemberIds($db, (int)$sample['member_id']);
    $groupIds = getDisplayedRewardGroupIds($db, (int)$sample['member_id'], $siblings);

    // 11기 rg 의 cycle 만 가진 rg 가 displayed 에 들어 있으면 안 됨
    $rg11Only = $db->query("
        SELECT rg.id
        FROM reward_groups rg
        WHERE rg.status = 'open'
          AND NOT EXISTS (
            SELECT 1 FROM coin_cycles cc
            WHERE cc.reward_group_id = rg.id AND cc.name = '12기'
          )
          AND EXISTS (
            SELECT 1 FROM coin_cycles cc
            WHERE cc.reward_group_id = rg.id AND cc.name = '11기'
          )
    ")->fetchAll(PDO::FETCH_COLUMN);

    $intersect = array_intersect($groupIds, $rg11Only);
    t('INV-5 12기 chip displayed_groups 에 rg(11기 only) 미포함',
        empty($intersect),
        '겹치는 rg_id: ' . json_encode(array_values($intersect)));

    // 12기 rg (cycle name='12기' 보유) 는 반드시 displayed 에 있어야
    $rg12 = $db->query("
        SELECT DISTINCT rg.id
        FROM reward_groups rg
        JOIN coin_cycles cc ON cc.reward_group_id = rg.id AND cc.name = '12기'
        WHERE rg.status = 'open'
    ")->fetchAll(PDO::FETCH_COLUMN);
    $missing12 = array_diff($rg12, $groupIds);
    t('INV 12기 chip displayed_groups 에 cycle_12 보유 rg 모두 포함',
        empty($missing12),
        '빠진 rg_id: ' . json_encode(array_values($missing12)));
}

// 11기 chip 회원 — rg 3 + rg 4 모두 displayed
$s11 = $db->query("
    SELECT id FROM bootcamp_members bm
    JOIN cohorts c ON c.id = bm.cohort_id
    JOIN member_cycle_coins mcc ON mcc.member_id = bm.id
    JOIN coin_cycles cc ON cc.id = mcc.cycle_id
    WHERE c.cohort = '11기'
    GROUP BY bm.id
    HAVING COUNT(DISTINCT cc.reward_group_id) >= 2
    LIMIT 1
")->fetch();

if ($s11) {
    $sib11 = findCoinSiblingMemberIds($db, (int)$s11['id']);
    $g11 = getDisplayedRewardGroupIds($db, (int)$s11['id'], $sib11);
    t('INV-3 11기 chip 회원 displayed 개수 ≥ 2 (mcc 보유 rg 모두)',
        count($g11) >= 2);
}
```

- [ ] **Step 2: 인보리언트 실행 — fatal 확인**

```bash
cd /root/boot-dev && php tests/coin_cross_cohort_invariants.php
```

Expected: PHP Fatal "Call to undefined function getDisplayedRewardGroupIds()".

- [ ] **Step 3: `getDisplayedRewardGroupIds` 구현**

Append to `coin_functions.php` (Task 1 의 함수 바로 뒤):

```php

/**
 * 회원 view 에 표시할 reward_group id 목록.
 * 룰: rg.status='open' AND (
 *   현재+sibling member 중 어느 row 든 mcc 보유
 *   OR rg 안에 cc.name = '<현재 chip cohort label>' 인 cycle 존재
 * )
 *
 * @param array<int, array{member_id:int, cohort_id:int, cohort_label:string}> $siblings findCoinSiblingMemberIds 결과
 * @return array<int> rg id 목록 (정렬: 첫 cycle start_date ASC — 현행 순서 유지)
 */
function getDisplayedRewardGroupIds($db, $memberId, array $siblings): array {
    if (empty($siblings)) return [];
    $curLabel = $siblings[0]['cohort_label']; // 첫 원소 = 현재
    $ids = array_column($siblings, 'member_id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql = "
        SELECT DISTINCT rg.id
        FROM reward_groups rg
        WHERE rg.status = 'open'
          AND (
            EXISTS (
              SELECT 1 FROM coin_cycles cc
              JOIN member_cycle_coins mcc
                ON mcc.cycle_id = cc.id AND mcc.member_id IN ($placeholders)
              WHERE cc.reward_group_id = rg.id
            )
            OR EXISTS (
              SELECT 1 FROM coin_cycles cc
              WHERE cc.reward_group_id = rg.id AND cc.name = ?
            )
          )
        ORDER BY (SELECT MIN(start_date) FROM coin_cycles WHERE reward_group_id = rg.id) ASC
    ";
    $stmt = $db->prepare($sql);
    $params = array_merge($ids, [$curLabel]);
    $stmt->execute($params);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rgId) {
        $result[] = (int)$rgId;
    }
    return $result;
}
```

- [ ] **Step 4: 인보리언트 재실행 — INV-5/INV-3/INV(rg12 포함) PASS**

```bash
cd /root/boot-dev && php tests/coin_cross_cohort_invariants.php
```

Expected: 모든 PASS. fail 시 EXISTS 절 SQL 디버깅.

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/coin_functions.php tests/coin_cross_cohort_invariants.php
git commit -m "feat(coin): getDisplayedRewardGroupIds with sibling-aware union rule"
```

---

## Task 3: 신규 helper `getMemberDisplayedCoinTotal`

**Files:**
- Modify: `/root/boot-dev/public_html/includes/coin_functions.php`
- Test: `/root/boot-dev/tests/coin_cross_cohort_invariants.php`

대시보드 stat 용. displayed_group_ids 안의 cycle 들에 대해 sibling 까지 포함한 mcc 합산. coin_logs 안 씀 (race-safe).

- [ ] **Step 1: 인보리언트 추가 (INV-1~4)**

Append to `/root/boot-dev/tests/coin_cross_cohort_invariants.php` (`exit(...)` 위에):

```php

// ══════════════════════════════════════════════════════════════
// INV-1~4: getMemberDisplayedCoinTotal 합산 검증
// ══════════════════════════════════════════════════════════════

// helper: old-equivalent (displayed_groups 한정 + currentMemberId 만) 의 mcc 합산
function oldEquivalentTotal(PDO $db, int $memberId, array $groupIds): int {
    if (empty($groupIds)) return 0;
    $ph = implode(',', array_fill(0, count($groupIds), '?'));
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(mcc.earned_coin - mcc.used_coin), 0)
        FROM member_cycle_coins mcc
        JOIN coin_cycles cc ON cc.id = mcc.cycle_id
        WHERE cc.reward_group_id IN ($ph) AND mcc.member_id = ?
    ");
    $stmt->execute(array_merge($groupIds, [$memberId]));
    return max(0, (int)$stmt->fetchColumn());
}

// INV-2: sibling 0건 회원 (non-dual) → 새 잔액 = old-equivalent
$nonDual = $db->query("
    SELECT bm.id, bm.user_id, bm.cohort_id
    FROM bootcamp_members bm
    WHERE bm.user_id IS NOT NULL AND bm.user_id != ''
      AND NOT EXISTS (
        SELECT 1 FROM bootcamp_members bm2
        WHERE bm2.user_id = bm.user_id AND bm2.id <> bm.id
      )
    LIMIT 1
")->fetch();
if ($nonDual) {
    $sib = findCoinSiblingMemberIds($db, (int)$nonDual['id']);
    $gids = getDisplayedRewardGroupIds($db, (int)$nonDual['id'], $sib);
    $newTotal = getMemberDisplayedCoinTotal($db, (int)$nonDual['id']);
    $oldEq = oldEquivalentTotal($db, (int)$nonDual['id'], $gids);
    t('INV-2 sibling 0 회원 새 잔액 = old-equivalent',
        $newTotal === $oldEq,
        "new={$newTotal} old={$oldEq}");
}

// INV-3: 11기 chip 회원 새 잔액 = old-equivalent
if (isset($s11) && $s11) {
    $newTotal = getMemberDisplayedCoinTotal($db, (int)$s11['id']);
    $sib = findCoinSiblingMemberIds($db, (int)$s11['id']);
    $gids = getDisplayedRewardGroupIds($db, (int)$s11['id'], $sib);
    $oldEq = oldEquivalentTotal($db, (int)$s11['id'], $gids);
    t('INV-3 11기 chip 회원 새 잔액 = old-equivalent',
        $newTotal === $oldEq,
        "new={$newTotal} old={$oldEq}");
}

// INV-1 + INV-4: 12기 chip dual-enrollment
if ($sample) {
    $cur = (int)$sample['member_id'];
    $newTotal = getMemberDisplayedCoinTotal($db, $cur);
    $sib = findCoinSiblingMemberIds($db, $cur);
    $gids = getDisplayedRewardGroupIds($db, $cur, $sib);
    $oldEq = oldEquivalentTotal($db, $cur, $gids);
    t('INV-1 12기 chip 새 잔액 ≥ old-equivalent', $newTotal >= $oldEq,
        "new={$newTotal} old={$oldEq}");

    // INV-4: 새 잔액 = 자기 mcc 합산(displayed 안) + sibling mcc 합산(displayed 안)
    $sibTotals = 0;
    foreach ($sib as $s) {
        if ((int)$s['member_id'] === $cur) continue;
        $sibTotals += oldEquivalentTotal($db, (int)$s['member_id'], $gids);
    }
    $expected = max(0, $oldEq + $sibTotals);
    t('INV-4 12기 chip dual 새 잔액 = 자기 displayed mcc + sibling displayed mcc',
        $newTotal === $expected,
        "new={$newTotal} expected={$expected} (self={$oldEq} sib={$sibTotals})");
}
```

- [ ] **Step 2: 실행 — fatal 확인**

```bash
cd /root/boot-dev && php tests/coin_cross_cohort_invariants.php
```

Expected: Fatal "Call to undefined function getMemberDisplayedCoinTotal()".

- [ ] **Step 3: `getMemberDisplayedCoinTotal` 구현**

Append to `coin_functions.php`:

```php

/**
 * 대시보드 stat 카드용. 회원 view 에 표시되는 코인 합계.
 * displayed reward groups 안의 sibling-포함 mcc 합산. coin_logs 미사용.
 */
function getMemberDisplayedCoinTotal($db, $memberId): int {
    $siblings = findCoinSiblingMemberIds($db, (int)$memberId);
    if (empty($siblings)) return 0;
    $groupIds = getDisplayedRewardGroupIds($db, (int)$memberId, $siblings);
    if (empty($groupIds)) return 0;

    $memberIds = array_column($siblings, 'member_id');
    $ph1 = implode(',', array_fill(0, count($groupIds), '?'));
    $ph2 = implode(',', array_fill(0, count($memberIds), '?'));

    $stmt = $db->prepare("
        SELECT COALESCE(SUM(mcc.earned_coin - mcc.used_coin), 0) AS total
        FROM member_cycle_coins mcc
        JOIN coin_cycles cc   ON cc.id = mcc.cycle_id
        JOIN reward_groups rg ON rg.id = cc.reward_group_id
        WHERE rg.status = 'open'
          AND rg.id IN ($ph1)
          AND mcc.member_id IN ($ph2)
    ");
    $stmt->execute(array_merge($groupIds, $memberIds));
    $sum = (int)$stmt->fetchColumn();
    return max(0, $sum);
}
```

- [ ] **Step 4: 인보리언트 재실행 — INV-1~4 PASS**

```bash
cd /root/boot-dev && php tests/coin_cross_cohort_invariants.php
```

Expected: 모든 PASS. 12기 dual-enrollment 표본의 new 가 self+sibling 합과 정확히 일치.

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/coin_functions.php tests/coin_cross_cohort_invariants.php
git commit -m "feat(coin): getMemberDisplayedCoinTotal helper + INV-1~4"
```

---

## Task 4: `getMemberCoinHistory` sibling-aware 로 수정

**Files:**
- Modify: `/root/boot-dev/public_html/includes/coin_functions.php` (L757~831)
- Test: `/root/boot-dev/tests/coin_cross_cohort_invariants.php`

응답 구조에 cycle 객체 안 `logs_by_cohort` 추가. 기존 `logs` 키는 제거 (단일 호출지 JS 하나만 수정). `cycles[].earned` 는 sibling 포함 mcc 합산. `groups` 는 `getDisplayedRewardGroupIds` 결과로 결정.

- [ ] **Step 1: 인보리언트 추가 (응답 구조)**

Append to `/root/boot-dev/tests/coin_cross_cohort_invariants.php` (`exit(...)` 위에):

```php

// ══════════════════════════════════════════════════════════════
// getMemberCoinHistory 응답 구조 검증
// ══════════════════════════════════════════════════════════════

if ($sample) {
    $cur = (int)$sample['member_id'];
    $hist = getMemberCoinHistory($db, $cur);
    t('history 응답이 array',  is_array($hist));

    // 12기 chip dual 회원: 12기 cycle 카드가 반드시 1개 이상
    $has12 = false;
    foreach ($hist as $g) {
        foreach (($g['cycles'] ?? []) as $c) {
            if ($c['cycle_name'] === '12기') $has12 = true;
            t("cycle.logs_by_cohort 존재 ({$c['cycle_name']})", isset($c['logs_by_cohort']) && is_array($c['logs_by_cohort']));
        }
    }
    t('12기 cycle 카드 존재 (12기 chip)', $has12);

    // 11기 rg only (cycle_11) 카드는 12기 chip 에서 안 나와야
    $has11Only = false;
    foreach ($hist as $g) {
        foreach (($g['cycles'] ?? []) as $c) {
            if ($c['cycle_name'] === '11기') $has11Only = true;
        }
    }
    t('11기 cycle 카드 미포함 (12기 chip, rg_11only)', !$has11Only);
}

// 11기 chip dual 회원 — 두 cycle 모두 있어야
if (isset($s11) && $s11) {
    $hist11 = getMemberCoinHistory($db, (int)$s11['id']);
    $names = [];
    foreach ($hist11 as $g) {
        foreach (($g['cycles'] ?? []) as $c) $names[] = $c['cycle_name'];
    }
    t('11기 chip 회원 history 에 11기·12기 cycle 둘 다',
        in_array('11기', $names) && in_array('12기', $names),
        'names=' . json_encode($names));
}
```

- [ ] **Step 2: 실행 — 기존 응답이 logs_by_cohort 없어서 FAIL 확인**

```bash
cd /root/boot-dev && php tests/coin_cross_cohort_invariants.php
```

Expected: "cycle.logs_by_cohort 존재" 와 12기 cycle 관련 FAIL 다수. (의도된 baseline)

- [ ] **Step 3: `getMemberCoinHistory` 수정 — 전체 함수 본문 교체**

Edit `/root/boot-dev/public_html/includes/coin_functions.php`. 기존 함수 본문 (L757~831, `function getMemberCoinHistory(...)` 부터 닫는 `}` 까지) 을 아래로 교체:

```php
function getMemberCoinHistory($db, $memberId) {
    $siblings = findCoinSiblingMemberIds($db, (int)$memberId);
    if (empty($siblings)) return [];

    $groupIds = getDisplayedRewardGroupIds($db, (int)$memberId, $siblings);
    if (empty($groupIds)) return [];

    $memberIds = array_column($siblings, 'member_id');
    $curCohortId = (int)$siblings[0]['cohort_id'];

    // sibling cohort_label 빠른 조회용 map (member_id → cohort 메타)
    $cohortMap = [];
    foreach ($siblings as $s) {
        $cohortMap[(int)$s['member_id']] = [
            'cohort_id'    => (int)$s['cohort_id'],
            'cohort_label' => (string)$s['cohort_label'],
        ];
    }

    // 1. displayed group 메타
    $ph = implode(',', array_fill(0, count($groupIds), '?'));
    $gStmt = $db->prepare("
        SELECT id, name FROM reward_groups WHERE id IN ($ph)
        ORDER BY (SELECT MIN(start_date) FROM coin_cycles WHERE reward_group_id = reward_groups.id) ASC
    ");
    $gStmt->execute($groupIds);
    $groups = $gStmt->fetchAll();

    $result = [];
    foreach ($groups as $idx => $g) {
        $gid = (int)$g['id'];
        $isFutureGroup = ($idx > 0);

        // 2. cycles + sibling-포함 earned/used 합산
        $ph2 = implode(',', array_fill(0, count($memberIds), '?'));
        $cStmt = $db->prepare("
            SELECT cc.id, cc.name, cc.status,
                   COALESCE(SUM(mcc.earned_coin), 0) AS earned_coin,
                   COALESCE(SUM(mcc.used_coin),   0) AS used_coin
            FROM coin_cycles cc
            LEFT JOIN member_cycle_coins mcc
              ON mcc.cycle_id = cc.id
             AND mcc.member_id IN ($ph2)
            WHERE cc.reward_group_id = ?
            GROUP BY cc.id
            ORDER BY cc.start_date ASC
        ");
        $cStmt->execute(array_merge($memberIds, [$gid]));
        $cycles = $cStmt->fetchAll();

        $cycleList = [];
        foreach ($cycles as $c) {
            $cid = (int)$c['id'];
            $earned = (int)$c['earned_coin'] - (int)$c['used_coin'];

            // 3. cycle 의 logs (sibling 포함) + cohort 라벨 부착
            $lStmt = $db->prepare("
                SELECT DATE(cl.created_at) AS d, cl.reason_type, cl.coin_change, cl.member_id
                FROM coin_logs cl
                WHERE cl.cycle_id = ? AND cl.member_id IN ($ph2)
                ORDER BY cl.created_at DESC, cl.id DESC
            ");
            $lStmt->execute(array_merge([$cid], $memberIds));
            $logRows = $lStmt->fetchAll();

            // cohort 별로 grouping
            $byCohort = [];
            foreach ($logRows as $lr) {
                $mid = (int)$lr['member_id'];
                $meta = $cohortMap[$mid] ?? null;
                if (!$meta) continue;
                $cohortId = $meta['cohort_id'];
                if (!isset($byCohort[$cohortId])) {
                    $byCohort[$cohortId] = [
                        'cohort_id'       => $cohortId,
                        'cohort_label'    => $meta['cohort_label'],
                        'is_other_cohort' => $cohortId !== $curCohortId,
                        'logs'            => [],
                    ];
                }
                $byCohort[$cohortId]['logs'][] = [
                    'date'        => $lr['d'],
                    'reason_type' => $lr['reason_type'],
                    'label'       => coinReasonLabel($lr['reason_type'], (int)$lr['coin_change']),
                    'change'      => (int)$lr['coin_change'],
                ];
            }
            ksort($byCohort); // cohort_id ASC
            $logsByCohort = array_values($byCohort);

            $cycleList[] = [
                'cycle_id'       => $cid,
                'cycle_name'     => $c['name'],
                'cycle_status'   => $c['status'],
                'earned'         => $earned,
                'payout_message' => coinPayoutMessage($c['name'], $c['status'], $isFutureGroup),
                'logs_by_cohort' => $logsByCohort,
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

- [ ] **Step 4: 인보리언트 재실행 — 모든 PASS 확인**

```bash
cd /root/boot-dev && php tests/coin_cross_cohort_invariants.php
```

Expected: 모든 t() PASS. logs_by_cohort 키 존재, 12기 cycle 노출, 11기 cycle 미노출(12기 chip), 11기 chip 회원 양쪽 보유.

- [ ] **Step 5: 71건 영향 회원 sanity check (수동)**

다음 SQL 로 12기 chip 영향 회원 표본 점검 (DEV/PROD 동일 가능):

```bash
cd /root/boot-dev && php -r '
require "public_html/config.php";
require "public_html/includes/coin_functions.php";
$db = getDB();
$rows = $db->query("
    SELECT bm.id AS member_id, bm.user_id, bm.nickname
    FROM bootcamp_members bm
    JOIN cohorts c ON c.id = bm.cohort_id
    WHERE c.cohort = \"12기\" AND bm.user_id IS NOT NULL AND bm.user_id != \"\"
      AND EXISTS (
        SELECT 1 FROM bootcamp_members bm2
        JOIN coin_cycles cc ON cc.name = \"12기\"
        JOIN member_cycle_coins mcc ON mcc.cycle_id = cc.id AND mcc.member_id = bm2.id
        WHERE bm2.user_id = bm.user_id AND bm2.cohort_id < bm.cohort_id
          AND (mcc.earned_coin - mcc.used_coin) > 0
      )
    LIMIT 5
")->fetchAll();
foreach ($rows as $r) {
    $hist = getMemberCoinHistory($db, (int)$r["member_id"]);
    $cycle12 = null;
    foreach ($hist as $g) foreach (($g["cycles"]??[]) as $c) if ($c["cycle_name"]==="12기") $cycle12 = $c;
    echo "{$r["user_id"]} ({$r["nickname"]}) cycle_12 earned={$cycle12["earned"]} groups=" . count($cycle12["logs_by_cohort"]??[]) . "\n";
}
'
```

Expected: 5건 모두 `earned > 0` (메모리 검증 데이터의 oh_nakazawa=60, 3321876906@k=50 등 일치).

- [ ] **Step 6: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/coin_functions.php tests/coin_cross_cohort_invariants.php
git commit -m "feat(coin): getMemberCoinHistory cross-cohort aggregation + logs_by_cohort"
```

---

## Task 5: `getCurrentRewardGroupForMember` sibling-aware 로 수정

**Files:**
- Modify: `/root/boot-dev/public_html/includes/coin_functions.php` (L623~661)
- Test: `/root/boot-dev/tests/coin_cross_cohort_invariants.php`

12기 chip dual 회원이 mcc 없어 `null` 받던 문제 fix. displayed_group_ids 의 첫 group + sibling 포함 cycle earned 반환.

- [ ] **Step 1: 인보리언트 추가**

Append to `/root/boot-dev/tests/coin_cross_cohort_invariants.php` (`exit(...)` 위에):

```php

// ══════════════════════════════════════════════════════════════
// getCurrentRewardGroupForMember 검증
// ══════════════════════════════════════════════════════════════

if ($sample) {
    $cur = (int)$sample['member_id'];
    $rg = getCurrentRewardGroupForMember($db, $cur);
    t('12기 chip dual 회원 current reward group != null', $rg !== null);
    if ($rg) {
        $names = array_column($rg['cycles'], 'name');
        t('current reward group 안에 12기 cycle 존재', in_array('12기', $names));
    }
}
```

- [ ] **Step 2: 실행 — 71건 영향 표본 회원이라면 FAIL 가능**

```bash
cd /root/boot-dev && php tests/coin_cross_cohort_invariants.php
```

Expected: 표본이 12기 row mcc 없는 케이스면 `null` 반환 → FAIL. (mcc 있는 표본이면 이미 PASS — 그래도 sibling earned 합산은 미반영 상태)

- [ ] **Step 3: 함수 본문 교체**

Edit `coin_functions.php`. 기존 `getCurrentRewardGroupForMember` (L623~661) 본문을 교체:

```php
function getCurrentRewardGroupForMember($db, $memberId) {
    $siblings = findCoinSiblingMemberIds($db, (int)$memberId);
    if (empty($siblings)) return null;
    $groupIds = getDisplayedRewardGroupIds($db, (int)$memberId, $siblings);
    if (empty($groupIds)) return null;

    // 첫 group (= 이번 기수의 group) 선택. getDisplayedRewardGroupIds 가 cycle start_date ASC 정렬.
    $gid = $groupIds[0];
    $gStmt = $db->prepare("SELECT id, name, status FROM reward_groups WHERE id = ?");
    $gStmt->execute([$gid]);
    $group = $gStmt->fetch();
    if (!$group) return null;

    $memberIds = array_column($siblings, 'member_id');
    $ph = implode(',', array_fill(0, count($memberIds), '?'));

    $cStmt = $db->prepare("
        SELECT cc.id, cc.name, cc.status,
               COALESCE(SUM(mcc.earned_coin), 0) AS earned,
               COALESCE(SUM(mcc.used_coin), 0)   AS used
        FROM coin_cycles cc
        LEFT JOIN member_cycle_coins mcc
          ON mcc.cycle_id = cc.id AND mcc.member_id IN ($ph)
        WHERE cc.reward_group_id = ?
        GROUP BY cc.id
        ORDER BY cc.start_date ASC
    ");
    $cStmt->execute(array_merge($memberIds, [$gid]));
    $cycles = [];
    foreach ($cStmt->fetchAll() as $c) {
        $cycles[] = [
            'name'    => $c['name'],
            'earned'  => (int)$c['earned'] - (int)$c['used'],
            'settled' => $c['status'] === 'closed',
        ];
    }

    return [
        'name'   => $group['name'],
        'cycles' => $cycles,
    ];
}
```

- [ ] **Step 4: 재실행 — PASS 확인**

```bash
cd /root/boot-dev && php tests/coin_cross_cohort_invariants.php
```

Expected: 모든 t() PASS. current_reward_group 이 12기 chip 영향 회원에게도 항상 반환.

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/coin_functions.php tests/coin_cross_cohort_invariants.php
git commit -m "feat(coin): getCurrentRewardGroupForMember sibling-aware + displayed rule"
```

---

## Task 6: `api/member.php` 3개 호출지 교체

**Files:**
- Modify: `/root/boot-dev/public_html/api/member.php` (L54, L129, L180 근처)

`member_coin_balances` 직접 SELECT → `getMemberDisplayedCoinTotal()` 호출로 교체. 응답 키 이름 유지.

- [ ] **Step 1: login 액션 (L54) 교체**

Edit `public_html/api/member.php`, 다음 블록 (대략 L54~56):

```php
    $coinStmt = $db->prepare('SELECT current_coin FROM member_coin_balances WHERE member_id = ?');
    $coinStmt->execute([$member['id']]);
    $coin = (int)($coinStmt->fetchColumn() ?: 0);
```

을 아래로 교체:

```php
    $coin = getMemberDisplayedCoinTotal($db, (int)$member['id']);
```

- [ ] **Step 2: check_session 액션 (L129 근처) 교체**

같은 파일에서 동일 패턴 (L129~131 대략) 한 번 더 등장:

```php
            $coinStmt = $db->prepare('SELECT current_coin FROM member_coin_balances WHERE member_id = ?');
            $coinStmt->execute([$member['id']]);
            $coin = (int)($coinStmt->fetchColumn() ?: 0);
```

→

```php
            $coin = getMemberDisplayedCoinTotal($db, (int)$member['id']);
```

- [ ] **Step 3: dashboard 액션 (L180 근처) 교체**

세 번째 등장지점 (L180~182 대략):

```php
    $coinStmt = $db->prepare('SELECT current_coin FROM member_coin_balances WHERE member_id = ?');
    $coinStmt->execute([$s['member_id']]);
    $coin = (int)($coinStmt->fetchColumn() ?: 0);
```

→

```php
    $coin = getMemberDisplayedCoinTotal($db, (int)$s['member_id']);
```

- [ ] **Step 4: grep 으로 남은 패턴 확인**

```bash
cd /root/boot-dev && grep -n "member_coin_balances" public_html/api/member.php
```

Expected: 출력 없음 (3건 모두 교체됨).

- [ ] **Step 5: PHP syntax 검사**

```bash
cd /root/boot-dev && php -l public_html/api/member.php
```

Expected: "No syntax errors detected".

- [ ] **Step 6: DEV 로컬 호출 확인 (curl)**

DEV 회원 1명 로그인해서 dashboard 응답의 `coin` 필드가 양수로 오는지 확인. (chip swap 후에도 정상.) — 수동 또는 별도 smoke task 에서 처리.

- [ ] **Step 7: Commit**

```bash
cd /root/boot-dev && git add public_html/api/member.php
git commit -m "feat(coin): member.php uses cross-cohort displayed total"
```

---

## Task 7: `member-coin-history.js` 분기 렌더 + CSS

**Files:**
- Modify: `/root/boot-dev/public_html/js/member-coin-history.js` (L48~68)
- Modify: `/root/boot-dev/public_html/css/` 의 코인 관련 CSS 파일 (정확한 파일은 step 1 에서 확인)

`renderCycleCard` 가 `logs_by_cohort` 분기 렌더. 라벨: 다른 cohort 면 `⤷ X기 때 받은 코인`, 같은 cohort 면 `⤷ X기`. 단일 비어있지 않은 그룹이면 flat + 라벨 생략.

- [ ] **Step 1: 코인 CSS 파일 위치 확인**

```bash
cd /root/boot-dev && grep -rn "coin-cycle-card\|coin-cycle-logs" public_html/css/ public_html/style.css 2>/dev/null | head -5
```

Expected: CSS 파일 경로 1개. 이 파일에 `.coin-section-label` 추가할 예정.

- [ ] **Step 2: `renderCycleCard` 본문 교체**

Edit `/root/boot-dev/public_html/js/member-coin-history.js`. 기존 함수 (L48~68) 본문을 아래로 교체:

```js
    function renderCycleCard(cycle) {
        const statusBadge = cycle.cycle_status === 'active'
            ? '<span class="coin-cycle-badge coin-cycle-active">적립 중</span>'
            : '';
        const bannerClass = cycle.cycle_status === 'closed'
            ? 'coin-cycle-banner-closed'
            : 'coin-cycle-banner-active';

        const groups = cycle.logs_by_cohort || [];
        const nonEmpty = groups.filter(g => (g.logs || []).length > 0);
        const hasMultipleSources = nonEmpty.length > 1;

        let body = '';
        if (nonEmpty.length === 0) {
            body = '<div class="coin-history-empty-logs">이 cycle에 기록이 없습니다.</div>';
        } else if (!hasMultipleSources) {
            body = nonEmpty[0].logs.map(renderLog).join('');
        } else {
            // 양쪽에 logs 보유 — cohort 별 sub-section 분리 렌더
            body = nonEmpty.map(g => {
                const label = g.is_other_cohort
                    ? `⤷ ${App.esc(g.cohort_label)} 때 받은 코인`
                    : `⤷ ${App.esc(g.cohort_label)}`;
                const rows = g.logs.map(renderLog).join('');
                return `<div class="coin-section-label">${label}</div>${rows}`;
            }).join('');
        }

        return `
            <div class="coin-cycle-card">
                <div class="coin-cycle-head">
                    <div class="coin-cycle-name">${App.esc(cycle.cycle_name)} 코인 ${statusBadge}</div>
                    <div class="coin-cycle-total">${parseInt(cycle.earned) || 0}</div>
                </div>
                <div class="coin-cycle-banner ${bannerClass}">${App.esc(cycle.payout_message)}</div>
                <div class="coin-cycle-logs">${body}</div>
            </div>
        `;
    }
```

- [ ] **Step 3: CSS 추가 — `.coin-section-label`**

Step 1 에서 찾은 CSS 파일 (예: `public_html/css/member-coin-history.css` 또는 `public_html/style.css`) 끝에 추가:

```css
.coin-section-label {
    margin: 12px 0 6px;
    font-size: 0.85em;
    color: var(--text-muted, #888);
    font-weight: 500;
}
.coin-cycle-logs .coin-section-label:first-child {
    margin-top: 0;
}
```

- [ ] **Step 4: DEV 브라우저에서 시각 확인 (수동 — Task 9 에서 통합)**

이 task 에서는 syntax 만 확인:

```bash
cd /root/boot-dev && node -e "new Function(require('fs').readFileSync('public_html/js/member-coin-history.js', 'utf-8'))" && echo "JS syntax OK"
```

Expected: "JS syntax OK".

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/js/member-coin-history.js public_html/css/
git commit -m "feat(coin): member-coin-history.js renders logs_by_cohort with cohort labels"
```

---

## Task 8: `member_page.php` passthrough 검증 (수정 없음)

**Files:**
- Read-only: `/root/boot-dev/public_html/api/services/member_page.php:451`

`handleMyCoinHistory` 가 `getMemberCoinHistory` 의 응답 구조 (`groups[].cycles[].logs_by_cohort`) 를 그대로 통과시키는지 확인. 자체 변환·키 필터링 없으면 코드 수정 불필요.

- [ ] **Step 1: handler 본문 확인**

```bash
cd /root/boot-dev && sed -n '440,475p' public_html/api/services/member_page.php
```

`getMemberCoinHistory($db, $memberId)` 의 결과가 `jsonSuccess(['groups' => $groups])` 형태로 바로 전달되는지 확인. 중간에 키 필터링/변환이 있다면 그 부분 제거 필요.

- [ ] **Step 2: 수정 필요 시 인라인 수정**

만약 핸들러가 `cycle['logs']` 만 골라내는 패턴이 있으면 그 부분 제거하고 cycle 전체를 그대로 통과시킨다. 없으면 skip.

- [ ] **Step 3 (수정한 경우만): Commit**

```bash
cd /root/boot-dev && git add public_html/api/services/member_page.php
git commit -m "fix(coin): pass through logs_by_cohort in my_coin_history handler"
```

수정 없으면 task 자체를 commit 없이 종료.

---

## Task 9: DEV smoke 시나리오 1~7 수동 검증

**Files:**
- 코드 수정 없음 (브라우저 + 인보리언트 실행)

Spec Section 6 의 7개 smoke 시나리오 직접 확인.

- [ ] **Step 1: 인보리언트 전체 재실행 (회귀 가드)**

```bash
cd /root/boot-dev && php tests/coin_cross_cohort_invariants.php
```

Expected: 모든 PASS. 1건이라도 FAIL 이면 해당 task 로 돌아가서 fix.

- [ ] **Step 2: DEV 브라우저에서 dual-enrollment 회원 로그인**

`https://dev-boot.soritune.com` 접속. 12기 chip dual-enrollment 회원 1명 (예: oh_nakazawa) 로그인.

확인 항목:
- 대시보드 stat 카드: 코인 = 합산값 (mcc 11기 row의 cycle_12 portion 포함).
- 내코인 페이지 진입: cycle_12 카드 존재. `cycle.earned` = 합산값.
- 11기 cycle 카드는 12기 chip 에선 안 나옴.
- 본문은 단일 섹션 (11기 row 만 logs) → flat 라벨 없음.

- [ ] **Step 3: chip 토글 → 11기 chip 으로 변경**

- 대시보드 stat 카드: 코인 = 현행 동작 (11기 row 의 합산 = rg3 + rg4).
- 내코인: rg 3 ("11기 리워드") + rg 4 ("12기 리워드") 두 카드 모두 노출.

- [ ] **Step 4: 양쪽 모두 logs 보유 시나리오 (smoke 4)**

DEV 에서 12기 chip dual 회원의 12기 row 에 직접 SQL 로 임시 coin_logs row 1건 삽입 후 view 확인 (테스트 후 삭제):

```sql
-- 임시 (테스트 끝나면 DELETE)
INSERT INTO coin_logs (member_id, cycle_id, coin_change, before_coin, after_coin, reason_type, reason_detail, created_by)
SELECT bm.id, cc.id, 10, 0, 10, 'manual_adjustment', 'smoke test', 'system'
FROM bootcamp_members bm
JOIN cohorts c ON c.id = bm.cohort_id
CROSS JOIN coin_cycles cc
WHERE c.cohort = '12기' AND bm.user_id = 'oh_nakazawa' AND cc.name = '12기'
LIMIT 1;
```

확인: 내코인 페이지 → cycle_12 카드 안에 `⤷ 11기 때 받은 코인` + `⤷ 12기` 두 sub-section 라벨 시각 확인.

```sql
-- 검증 끝나면 삭제
DELETE FROM coin_logs WHERE reason_detail = 'smoke test' AND reason_type = 'manual_adjustment';
```

- [ ] **Step 5: non-dual 회원 회귀 확인**

DEV 의 sibling 0건 회원 1명 로그인 → 코인 stat / 내코인 페이지가 기존과 동일하게 보이는지 (라벨 없음, flat, 잔액 변동 없음).

- [ ] **Step 6: smoke 결과 기록 (commit 없음)**

이 task 는 수동 검증 결과만 콘솔/메모로 남기고 코드 commit 안 함. 다음 task 에서 push.

---

## Task 10: DEV push + 사용자 확인 대기

**Files:**
- Git 원격 (origin/dev)

코드 변경 4~5 commit 을 `dev` 브랜치로 push. 운영 반영은 사용자가 명시 요청할 때만.

- [ ] **Step 1: 최종 상태 점검**

```bash
cd /root/boot-dev && git log --oneline origin/dev..HEAD
```

Expected: Task 1~7 의 commit 들이 보임 (대략 5~7개).

- [ ] **Step 2: 인보리언트 한 번 더**

```bash
cd /root/boot-dev && php tests/coin_cross_cohort_invariants.php
```

Expected: 모든 PASS.

- [ ] **Step 3: dev push**

```bash
cd /root/boot-dev && git push origin dev
```

- [ ] **Step 4: 사용자에게 DEV 확인 요청**

다음 메시지로 사용자에게 보고:

```
boot 코인 cross-cohort view DEV 반영 완료.
- dev push: <commit-range>
- 인보리언트: INV-1~6 + 응답 구조 + history 검증 모두 PASS
- DEV smoke 시나리오 1~7 (수동) 결과: <요약>
- 영향 회원: 12기 chip dual-enrollment 71건 (11기 row 의 cycle_12 잔액이 이제 12기 chip 에서 합산 노출)

dev-boot.soritune.com 에서 확인 부탁드립니다.
운영 반영 원하시면 알려주세요 — main 머지 + prod pull 진행하겠습니다.
```

- [ ] **Step 5: 운영 반영 (사용자 명시 요청 시에만)**

사용자가 "운영 반영해줘" 또는 동등한 요청을 했을 때만 아래 진행. 자동 진행 금지.

```bash
cd /root/boot-dev && git checkout main && git merge dev --no-ff && git push origin main && git checkout dev
cd /root/boot-prod && git pull origin main
cd /root/boot-prod && php tests/coin_cross_cohort_invariants.php
```

Expected: PROD 인보리언트도 모두 PASS. PROD 표본 회원 1~2명 로그인해서 합산 잔액 정상 확인.

---

## 자기 검토 체크

- [x] **Spec coverage**: Section 2(표시 group/sibling) → Task 1+2, Section 3(cycle 합산/응답 JSON) → Task 4, Section 4(대시보드 stat) → Task 3+6, Section 5(UI 렌더) → Task 7, Section 6(invariants/smoke) → Task 1~5 의 invariants step + Task 9 smoke.
- [x] **No placeholders**: 모든 step 에 실제 코드/SQL/명령어 포함.
- [x] **Type 일관성**: helper 시그니처 `findCoinSiblingMemberIds($db, $memberId): array`, `getDisplayedRewardGroupIds($db, $memberId, array $siblings): array<int>`, `getMemberDisplayedCoinTotal($db, $memberId): int` — Task 1~3 정의 후 Task 4~6 에서 동일하게 호출.
- [x] **member_page.php**: Task 8 에 별도 검증 단계 명시.
- [x] **단일 섹션 정책**: Task 7 의 JS 로직이 `nonEmpty.length === 0 / 1 / >1` 3분기.
