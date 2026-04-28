# Retention Management Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "리텐션 관리" tab to `boot.soritune.com/operation` that shows N→N+1 cohort retention with summary cards, GA4-style cohort survival curve, and 3 breakdowns (group / score band / participation count).

**Architecture:** Backend = two new actions in the existing `api/admin.php` switch (handlers live in `api/services/retention.php`). Frontend = new `js/retention.js` (IIFE namespace) lazy-loaded by `js/admin.js`, rendering with Chart.js. No DB migration; all queries derive from existing tables (`bootcamp_members`, `cohorts`, `bootcamp_groups`, `member_scores`).

**Tech Stack:** PHP 8 + PDO (existing), Plain JS (IIFE namespaces, `App` helpers from `js/common.js`), Chart.js 4.4.x via CDN, MariaDB.

**Spec:** `docs/superpowers/specs/2026-04-28-retention-management-design.md`

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `public_html/api/services/retention.php` | Create | Two handlers (`handleRetentionPairs`, `handleRetentionSummary`) + private helpers (집합 계산, 카드 분류, 곡선, breakdown) |
| `public_html/api/admin.php` | Modify | Add `require_once` and 2 case branches |
| `public_html/js/retention.js` | Create | `RetentionApp` IIFE: init, 페어 버튼, 카드, Chart.js 곡선, breakdown 표 |
| `public_html/css/retention.css` | Create | Card / table / horizontal-bar styles, reusing notify·admin tokens |
| `public_html/js/admin.js` | Modify | Add tab button, tab-content div, MutationObserver for lazy init |
| `public_html/operation/index.php` | Modify | `<link>` for retention.css, `<script>` for retention.js + Chart.js CDN |
| `tests/retention_invariants.php` | Create | Standalone PHP script: iterate all pairs, run invariants from spec § 6, print PASS/FAIL summary |

Test infra note: this repo has no PHPUnit. We use a standalone invariant verification script per project convention. Each backend task adds an invariant when the corresponding code lands.

---

### Task 1: Service skeleton + `handleRetentionPairs`

**Files:**
- Create: `public_html/api/services/retention.php`

- [ ] **Step 1: Create the service file with one handler returning the pair list**

```php
<?php
/**
 * Retention API Handlers
 * 운영팀 리텐션 관리 탭 백엔드.
 * Spec: docs/superpowers/specs/2026-04-28-retention-management-design.md
 */

const RETENTION_ROLES = ['operation'];

/**
 * 페어 목록 반환.
 * 정렬: anchor.start_date ASC (오래된 → 최신)
 * 노출 조건: anchor.total_with_user_id > 0 AND next.total_with_user_id > 0
 */
function handleRetentionPairs(): void {
    requireAdmin(RETENTION_ROLES);
    $db = getDB();

    $stmt = $db->query("
        SELECT c.id, c.cohort, c.start_date,
               (SELECT COUNT(DISTINCT bm.user_id)
                  FROM bootcamp_members bm
                 WHERE bm.cohort_id = c.id
                   AND bm.user_id IS NOT NULL AND bm.user_id <> '') AS total_with_user_id
          FROM cohorts c
         ORDER BY c.start_date ASC, c.id ASC
    ");
    $cohorts = $stmt->fetchAll();

    $pairs = [];
    for ($i = 0; $i < count($cohorts) - 1; $i++) {
        $a = $cohorts[$i];
        $n = $cohorts[$i + 1];
        if ((int)$a['total_with_user_id'] === 0 || (int)$n['total_with_user_id'] === 0) continue;
        $pairs[] = [
            'anchor_cohort_id'           => (int)$a['id'],
            'anchor_name'                => $a['cohort'],
            'anchor_total_with_user_id'  => (int)$a['total_with_user_id'],
            'next_cohort_id'             => (int)$n['id'],
            'next_name'                  => $n['cohort'],
            'next_total_with_user_id'    => (int)$n['total_with_user_id'],
        ];
    }
    jsonSuccess(['pairs' => $pairs]);
}
```

- [ ] **Step 2: Verify file syntax**

Run: `php -l /root/boot-dev/public_html/api/services/retention.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/retention.php
git commit -m "feat(retention): service skeleton with pair list handler"
```

---

### Task 2: Wire `retention_pairs` route in `admin.php` + smoke test

**Files:**
- Modify: `public_html/api/admin.php` (require + new case)

- [ ] **Step 1: Add `require_once` near other service includes (after `member_bulk` line)**

Find this block at the top of `admin.php`:
```php
require_once __DIR__ . '/services/member_stats.php';
require_once __DIR__ . '/services/member_bulk.php';
```

Add immediately after:
```php
require_once __DIR__ . '/services/retention.php';
```

- [ ] **Step 2: Add new case before the `default:` handler at the bottom**

Find:
```php
// ── Default ─────────────────────────────────────────────────

default:
    jsonError('Unknown action', 404);
```

Insert above the `// ── Default` comment:
```php
// ── Retention (operation only) ──────────────────────────────

case 'retention_pairs':
    handleRetentionPairs();
    break;

```

- [ ] **Step 3: Smoke test the route via mysql + curl-equivalent**

The dev site root is `dev-boot.soritune.com`. Run a local PHP one-liner that mimics the request (so we don't need HTTP):

```bash
cd /root/boot-dev/public_html && php -r '
$_GET["action"] = "retention_pairs";
$_SERVER["REQUEST_METHOD"] = "GET";
$_SERVER["HTTP_HOST"] = "dev-boot.soritune.com";
session_start();
// simulate operation admin login
$_SESSION["admin"] = ["admin_id"=>0, "admin_name"=>"smoke", "admin_roles"=>["operation"]];
require __DIR__ . "/api/admin.php";
'
```

Expected: JSON like `{"success":true,"pairs":[{"anchor_cohort_id":1,...},...]}`. 첫 페어가 `1기→2기` 또는 가장 오래된 페어여야 함. PROD/DEV에 따라 페어 수는 달라짐 (DEV 10페어 예상).

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev
git add public_html/api/admin.php
git commit -m "feat(retention): route retention_pairs action"
```

---

### Task 3: Helper for user-set queries (U_N, U_next, U_past)

**Files:**
- Modify: `public_html/api/services/retention.php`

- [ ] **Step 1: Add three helpers above `handleRetentionPairs`**

```php
/**
 * Anchor 기수의 user_id 보유 회원 집합을 반환.
 * @return string[] DISTINCT user_id list
 */
function retentionUserIdsInCohort(\PDO $db, int $cohortId): array {
    $stmt = $db->prepare("
        SELECT DISTINCT user_id
          FROM bootcamp_members
         WHERE cohort_id = ?
           AND user_id IS NOT NULL AND user_id <> ''
    ");
    $stmt->execute([$cohortId]);
    return array_column($stmt->fetchAll(), 'user_id');
}

/**
 * 주어진 anchor cohort 이전(start_date 기준)의 모든 기수에 등장한 user_id 집합.
 * @return array<string, true> set
 */
function retentionPastUserIdSet(\PDO $db, int $anchorCohortId): array {
    $stmt = $db->prepare("
        SELECT DISTINCT bm.user_id
          FROM bootcamp_members bm
          JOIN cohorts c ON c.id = bm.cohort_id
          JOIN cohorts a ON a.id = ?
         WHERE c.start_date < a.start_date
           AND bm.user_id IS NOT NULL AND bm.user_id <> ''
    ");
    $stmt->execute([$anchorCohortId]);
    $set = [];
    foreach ($stmt->fetchAll() as $r) $set[$r['user_id']] = true;
    return $set;
}

/**
 * cohort row와 해당 기수의 next cohort row를 함께 반환. next가 없으면 [row, null].
 * @return array{0: array<string,mixed>|null, 1: array<string,mixed>|null}
 */
function retentionAnchorAndNext(\PDO $db, int $anchorCohortId): array {
    $stmt = $db->query("SELECT id, cohort, start_date, end_date FROM cohorts ORDER BY start_date ASC, id ASC");
    $rows = $stmt->fetchAll();
    $anchor = null; $next = null;
    foreach ($rows as $i => $r) {
        if ((int)$r['id'] === $anchorCohortId) {
            $anchor = $r;
            $next   = $rows[$i + 1] ?? null;
            break;
        }
    }
    return [$anchor, $next];
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l /root/boot-dev/public_html/api/services/retention.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/retention.php
git commit -m "feat(retention): user-set helpers (U_N, U_next, U_past, anchor+next)"
```

---

### Task 4: Cards calculation — 잔존 / 회귀 / 신규

**Files:**
- Modify: `public_html/api/services/retention.php`

- [ ] **Step 1: Add `retentionComputeCards`**

```php
/**
 * Next 기수의 user_id 각각을 잔존/회귀/신규로 분류.
 *
 * @param string[]            $uNext   next 기수 user_id 리스트
 * @param string[]            $uAnchor anchor 기수 user_id 리스트
 * @param array<string, true> $uPast   anchor 이전 모든 기수 user_id set
 * @return array{stay:int, returning:int, brand_new:int}
 */
function retentionClassifyNext(array $uNext, array $uAnchor, array $uPast): array {
    $anchorSet = array_flip($uAnchor);
    $stay = 0; $returning = 0; $brandNew = 0;
    foreach ($uNext as $uid) {
        if (isset($anchorSet[$uid]))      $stay++;
        elseif (isset($uPast[$uid]))      $returning++;
        else                              $brandNew++;
    }
    return ['stay' => $stay, 'returning' => $returning, 'brand_new' => $brandNew];
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l /root/boot-dev/public_html/api/services/retention.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/retention.php
git commit -m "feat(retention): classify next-cohort user_ids into stay/returning/brand_new"
```

---

### Task 5: Cohort survival curve (step-independent)

**Files:**
- Modify: `public_html/api/services/retention.php`

- [ ] **Step 1: Add `retentionComputeCurve`**

```php
/**
 * Anchor 기수 이후 모든 기수에 대한 step-independent 잔존 곡선.
 * step 0 은 anchor 자체 (count=|U_N|, pct=100).
 *
 * @return array<int, array{step:int, cohort_id:int, cohort_name:string, count:int, pct:float}>
 */
function retentionComputeCurve(\PDO $db, array $anchor, array $uAnchor): array {
    $anchorSetSize = count($uAnchor);
    $points = [[
        'step'        => 0,
        'cohort_id'   => (int)$anchor['id'],
        'cohort_name' => $anchor['cohort'],
        'count'       => $anchorSetSize,
        'pct'         => $anchorSetSize > 0 ? 100.0 : 0.0,
    ]];
    if ($anchorSetSize === 0) return $points;

    // anchor 이후 cohorts 모두
    $stmt = $db->prepare("
        SELECT id, cohort
          FROM cohorts
         WHERE start_date > (SELECT start_date FROM cohorts WHERE id = ?)
         ORDER BY start_date ASC, id ASC
    ");
    $stmt->execute([(int)$anchor['id']]);
    $futures = $stmt->fetchAll();

    if (!$futures) return $points;

    // |U_N ∩ U_C| per future cohort C
    $placeholders = implode(',', array_fill(0, count($uAnchor), '?'));
    $futureIds    = array_column($futures, 'id');
    $futurePlace  = implode(',', array_fill(0, count($futureIds), '?'));
    $sql = "
        SELECT cohort_id, COUNT(DISTINCT user_id) AS cnt
          FROM bootcamp_members
         WHERE cohort_id IN ($futurePlace)
           AND user_id IN ($placeholders)
         GROUP BY cohort_id
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($futureIds, $uAnchor));
    $countsById = [];
    foreach ($stmt->fetchAll() as $r) $countsById[(int)$r['cohort_id']] = (int)$r['cnt'];

    $step = 1;
    foreach ($futures as $f) {
        $cnt = $countsById[(int)$f['id']] ?? 0;
        $points[] = [
            'step'        => $step++,
            'cohort_id'   => (int)$f['id'],
            'cohort_name' => $f['cohort'],
            'count'       => $cnt,
            'pct'         => round($cnt / $anchorSetSize * 100, 2),
        ];
    }
    return $points;
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l /root/boot-dev/public_html/api/services/retention.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/retention.php
git commit -m "feat(retention): step-independent cohort survival curve"
```

---

### Task 6: Breakdown — 누적 참여 횟수 (always active)

**Files:**
- Modify: `public_html/api/services/retention.php`

- [ ] **Step 1: Add `retentionBreakdownParticipation`**

```php
/**
 * Anchor 기수의 누적 참여 횟수 4구간 breakdown.
 * 항상 활성. 분모: anchor user_id 보유 회원 전체.
 *
 * @return array{rows: array<int, array{bucket:string, total:int, transitioned:int, pct:float}>}
 */
function retentionBreakdownParticipation(\PDO $db, int $anchorId, array $uNext): array {
    $nextSet = array_flip($uNext);

    $stmt = $db->prepare("
        SELECT
          CASE
            WHEN participation_count = 1            THEN '1회 (신규)'
            WHEN participation_count BETWEEN 2 AND 3 THEN '2~3회'
            WHEN participation_count BETWEEN 4 AND 6 THEN '4~6회'
            ELSE '7회 이상'
          END AS bucket,
          user_id
        FROM bootcamp_members
        WHERE cohort_id = ?
          AND user_id IS NOT NULL AND user_id <> ''
    ");
    $stmt->execute([$anchorId]);

    $agg = [
        '1회 (신규)' => ['total'=>0, 'transitioned'=>0],
        '2~3회'      => ['total'=>0, 'transitioned'=>0],
        '4~6회'      => ['total'=>0, 'transitioned'=>0],
        '7회 이상'   => ['total'=>0, 'transitioned'=>0],
    ];
    $seen = [];
    foreach ($stmt->fetchAll() as $row) {
        $uid = $row['user_id'];
        $bucket = $row['bucket'];
        if (isset($seen[$uid])) continue;  // DISTINCT user_id 보장
        $seen[$uid] = true;
        $agg[$bucket]['total']++;
        if (isset($nextSet[$uid])) $agg[$bucket]['transitioned']++;
    }

    $rows = [];
    foreach ($agg as $bucket => $v) {
        $rows[] = [
            'bucket'       => $bucket,
            'total'        => $v['total'],
            'transitioned' => $v['transitioned'],
            'pct'          => $v['total'] > 0 ? round($v['transitioned'] / $v['total'] * 100, 2) : 0.0,
        ];
    }
    return ['rows' => $rows];
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l /root/boot-dev/public_html/api/services/retention.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/retention.php
git commit -m "feat(retention): participation_count breakdown (4 buckets)"
```

---

### Task 7: Breakdown — 조별 (with 미배정 / 조 정보 이상)

**Files:**
- Modify: `public_html/api/services/retention.php`

- [ ] **Step 1: Add `retentionBreakdownGroup`**

```php
/**
 * Anchor 기수의 조별 breakdown. anchor에 그룹 데이터 없으면 null.
 *
 * @return null|array{rows: array<int, array{name:string, kind:string, total:int, transitioned:int, pct:float}>}
 */
function retentionBreakdownGroup(\PDO $db, int $anchorId, array $uNext): ?array {
    // 조 데이터 존재 확인
    $stmt = $db->prepare("SELECT COUNT(*) FROM bootcamp_groups WHERE cohort_id = ?");
    $stmt->execute([$anchorId]);
    if ((int)$stmt->fetchColumn() === 0) return null;

    $nextSet = array_flip($uNext);

    // anchor 기수의 모든 회원 (group_id, user_id, group_name, group_cohort_id LEFT JOIN)
    $stmt = $db->prepare("
        SELECT bm.user_id, bm.group_id, bg.name AS group_name, bg.cohort_id AS group_cohort_id
          FROM bootcamp_members bm
          LEFT JOIN bootcamp_groups bg ON bg.id = bm.group_id
         WHERE bm.cohort_id = ?
           AND bm.user_id IS NOT NULL AND bm.user_id <> ''
    ");
    $stmt->execute([$anchorId]);

    // anchor 조 row 순서를 위해 별도 조회
    $stmt2 = $db->prepare("SELECT id, name, stage_no FROM bootcamp_groups WHERE cohort_id = ? ORDER BY stage_no, id");
    $stmt2->execute([$anchorId]);
    $orderedGroups = $stmt2->fetchAll();

    $rowsByKey = []; // groupId|"unassigned"|"anomaly" => row
    foreach ($orderedGroups as $g) {
        $rowsByKey[(int)$g['id']] = [
            'name' => $g['name'], 'kind' => 'group',
            'total' => 0, 'transitioned' => 0, 'pct' => 0.0,
        ];
    }
    $rowsByKey['unassigned'] = ['name' => '미배정',         'kind' => 'unassigned', 'total' => 0, 'transitioned' => 0, 'pct' => 0.0];
    $rowsByKey['anomaly']    = ['name' => '조 정보 이상',   'kind' => 'anomaly',    'total' => 0, 'transitioned' => 0, 'pct' => 0.0];

    $seen = [];
    foreach ($stmt->fetchAll() as $row) {
        $uid = $row['user_id'];
        if (isset($seen[$uid])) continue;
        $seen[$uid] = true;

        if ($row['group_id'] === null) {
            $key = 'unassigned';
        } elseif ((int)($row['group_cohort_id'] ?? 0) !== $anchorId) {
            $key = 'anomaly';
        } else {
            $key = (int)$row['group_id'];
        }
        if (!isset($rowsByKey[$key])) {
            $rowsByKey[$key] = [
                'name' => $row['group_name'] ?? '?', 'kind' => 'group',
                'total' => 0, 'transitioned' => 0, 'pct' => 0.0,
            ];
        }
        $rowsByKey[$key]['total']++;
        if (isset($nextSet[$uid])) $rowsByKey[$key]['transitioned']++;
    }

    $rows = [];
    foreach ($rowsByKey as $r) {
        $r['pct'] = $r['total'] > 0 ? round($r['transitioned'] / $r['total'] * 100, 2) : 0.0;
        // unassigned/anomaly 가 0이면 표시 제외
        if (in_array($r['kind'], ['unassigned', 'anomaly'], true) && $r['total'] === 0) continue;
        $rows[] = $r;
    }
    return ['rows' => $rows];
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l /root/boot-dev/public_html/api/services/retention.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/retention.php
git commit -m "feat(retention): group breakdown with unassigned/anomaly rows"
```

---

### Task 8: Breakdown — 점수 범위 + sparse 임계

**Files:**
- Modify: `public_html/api/services/retention.php`

- [ ] **Step 1: Add `retentionBreakdownScore`**

```php
/**
 * Anchor 기수의 점수 범위 4구간 breakdown.
 * 점수 보유자가 anchor 인원의 50% 미만이면 null (sparse 차단).
 * 분모: 점수 row 보유자.
 *
 * @return null|array{coverage_pct:float, scored_total:int, rows: array<int, array{band:string, total:int, transitioned:int, pct:float}>}
 */
function retentionBreakdownScore(\PDO $db, int $anchorId, int $anchorTotalWithUserId, array $uNext): ?array {
    // 1) 점수 row 보유자 카운트
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT bm.user_id)
          FROM bootcamp_members bm
          JOIN member_scores ms ON ms.member_id = bm.id
         WHERE bm.cohort_id = ?
           AND bm.user_id IS NOT NULL AND bm.user_id <> ''
    ");
    $stmt->execute([$anchorId]);
    $scoredTotal = (int)$stmt->fetchColumn();
    if ($scoredTotal === 0) return null;

    $coverage = $anchorTotalWithUserId > 0 ? $scoredTotal / $anchorTotalWithUserId : 0;
    if ($coverage < 0.5) return null;

    // 2) 구간 집계
    $nextSet = array_flip($uNext);
    $stmt = $db->prepare("
        SELECT
          CASE
            WHEN ms.current_score >= 0                 THEN '0점'
            WHEN ms.current_score BETWEEN -10 AND -1   THEN '-1~-10'
            WHEN ms.current_score BETWEEN -24 AND -11  THEN '-11~-24'
            ELSE '-25 이하'
          END AS band,
          bm.user_id
        FROM bootcamp_members bm
        JOIN member_scores ms ON ms.member_id = bm.id
        WHERE bm.cohort_id = ?
          AND bm.user_id IS NOT NULL AND bm.user_id <> ''
    ");
    $stmt->execute([$anchorId]);

    $agg = [
        '0점'      => ['total'=>0, 'transitioned'=>0],
        '-1~-10'   => ['total'=>0, 'transitioned'=>0],
        '-11~-24'  => ['total'=>0, 'transitioned'=>0],
        '-25 이하' => ['total'=>0, 'transitioned'=>0],
    ];
    $seen = [];
    foreach ($stmt->fetchAll() as $row) {
        $uid = $row['user_id'];
        if (isset($seen[$uid])) continue;
        $seen[$uid] = true;
        $agg[$row['band']]['total']++;
        if (isset($nextSet[$uid])) $agg[$row['band']]['transitioned']++;
    }

    $rows = [];
    foreach ($agg as $band => $v) {
        $rows[] = [
            'band'         => $band,
            'total'        => $v['total'],
            'transitioned' => $v['transitioned'],
            'pct'          => $v['total'] > 0 ? round($v['transitioned'] / $v['total'] * 100, 2) : 0.0,
        ];
    }
    return [
        'coverage_pct' => round($coverage * 100, 2),
        'scored_total' => $scoredTotal,
        'rows'         => $rows,
    ];
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l /root/boot-dev/public_html/api/services/retention.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/retention.php
git commit -m "feat(retention): score-band breakdown with 50% sparse threshold"
```

---

### Task 9: `handleRetentionSummary` — assemble + route + smoke

**Files:**
- Modify: `public_html/api/services/retention.php`, `public_html/api/admin.php`

- [ ] **Step 1: Add `handleRetentionSummary` to `retention.php`**

```php
/**
 * 한 페어의 모든 데이터를 한 응답에 묶어 반환.
 */
function handleRetentionSummary(): void {
    requireAdmin(RETENTION_ROLES);
    $anchorId = (int)($_GET['anchor_cohort_id'] ?? 0);
    if ($anchorId <= 0) jsonError('anchor_cohort_id 필요');

    $db = getDB();
    [$anchor, $next] = retentionAnchorAndNext($db, $anchorId);
    if (!$anchor) jsonError('anchor cohort를 찾을 수 없습니다.', 404);
    if (!$next)   jsonError('이 anchor 다음 기수가 존재하지 않습니다.');

    $uAnchor = retentionUserIdsInCohort($db, (int)$anchor['id']);
    $uNext   = retentionUserIdsInCohort($db, (int)$next['id']);
    $uPast   = retentionPastUserIdSet($db, (int)$anchor['id']);

    $anchorTotalWithUid = count($uAnchor);
    $nextTotalWithUid   = count($uNext);

    $cards = retentionClassifyNext($uNext, $uAnchor, $uPast);

    $stayPct = $anchorTotalWithUid > 0 ? round($cards['stay'] / $anchorTotalWithUid * 100, 2) : 0.0;

    // user_id NULL/빈값 row 수 (anchor + next 합산, 안내 문구용)
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM bootcamp_members
         WHERE cohort_id IN (?, ?)
           AND (user_id IS NULL OR user_id = '')
    ");
    $stmt->execute([(int)$anchor['id'], (int)$next['id']]);
    $excludedNullUserId = (int)$stmt->fetchColumn();

    $curve         = retentionComputeCurve($db, $anchor, $uAnchor);
    $breakdownPart = retentionBreakdownParticipation($db, (int)$anchor['id'], $uNext);
    $breakdownGrp  = retentionBreakdownGroup($db, (int)$anchor['id'], $uNext);
    $breakdownScr  = retentionBreakdownScore($db, (int)$anchor['id'], $anchorTotalWithUid, $uNext);

    // anchor / next의 전체 row 수 (참조용)
    $stmt = $db->prepare("SELECT cohort_id, COUNT(*) c FROM bootcamp_members WHERE cohort_id IN (?, ?) GROUP BY cohort_id");
    $stmt->execute([(int)$anchor['id'], (int)$next['id']]);
    $totalsById = [];
    foreach ($stmt->fetchAll() as $r) $totalsById[(int)$r['cohort_id']] = (int)$r['c'];

    jsonSuccess([
        'anchor' => [
            'id' => (int)$anchor['id'],
            'name' => $anchor['cohort'],
            'total' => $totalsById[(int)$anchor['id']] ?? 0,
            'total_with_user_id' => $anchorTotalWithUid,
        ],
        'next' => [
            'id' => (int)$next['id'],
            'name' => $next['cohort'],
            'total' => $totalsById[(int)$next['id']] ?? 0,
            'total_with_user_id' => $nextTotalWithUid,
        ],
        'cards' => [
            'stay' => $cards['stay'],
            'returning' => $cards['returning'],
            'brand_new' => $cards['brand_new'],
            'retention_pct' => $stayPct,
            'next_total_with_user_id' => $nextTotalWithUid,
            'excluded_null_user_id' => $excludedNullUserId,
        ],
        'curve' => $curve,
        'breakdown' => [
            'group'         => $breakdownGrp,
            'score'         => $breakdownScr,
            'participation' => $breakdownPart,
        ],
        'generated_at' => date(\DateTime::ATOM),
    ]);
}
```

- [ ] **Step 2: Add `case 'retention_summary'` to `admin.php`**

In `public_html/api/admin.php`, find the `case 'retention_pairs':` block added in Task 2, and add immediately after its `break;`:

```php
case 'retention_summary':
    handleRetentionSummary();
    break;
```

- [ ] **Step 3: Smoke — call summary for 11기 anchor (id=11)**

```bash
cd /root/boot-dev/public_html && php -r '
$_GET["action"] = "retention_summary";
$_GET["anchor_cohort_id"] = "11";
$_SERVER["REQUEST_METHOD"] = "GET";
session_start();
$_SESSION["admin"] = ["admin_id"=>0, "admin_name"=>"smoke", "admin_roles"=>["operation"]];
require __DIR__ . "/api/admin.php";
' | head -c 600
```

Expected: JSON starting with `{"success":true,"anchor":{"id":11,...},"next":...,"cards":{"stay":...}` etc. The `cards.stay + returning + brand_new` should equal `next.total_with_user_id`. (Will be verified more rigorously by Task 10's invariant script.)

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/retention.php public_html/api/admin.php
git commit -m "feat(retention): summary handler with cards, curve, breakdowns + route"
```

---

### Task 10: Invariant verification script (all pairs)

**Files:**
- Create: `tests/retention_invariants.php`

- [ ] **Step 1: Create test directory if needed**

```bash
mkdir -p /root/boot-dev/tests
```

- [ ] **Step 2: Write the invariant script**

```php
<?php
/**
 * Retention invariants verification (spec § 6).
 * Runs against the DB credentials in /root/boot-dev/.db_credentials.
 * Iterates all pairs and checks every invariant. Prints PASS/FAIL summary.
 */

require_once __DIR__ . '/../public_html/auth.php';
require_once __DIR__ . '/../public_html/api/services/retention.php';

// stub the auth/json helpers used by handlers — we want to call internal helpers directly
function _approx_eq(float $a, float $b, float $eps = 0.01): bool { return abs($a - $b) <= $eps; }

$db = getDB();

// load all cohorts in order
$rows = $db->query("SELECT id, cohort, start_date FROM cohorts ORDER BY start_date, id")->fetchAll();

$total = 0; $pass = 0; $fail = 0;
$failures = [];

for ($i = 0; $i < count($rows) - 1; $i++) {
    $anchor = $rows[$i];
    $next   = $rows[$i + 1];
    $anchorId = (int)$anchor['id'];
    $nextId   = (int)$next['id'];

    $uAnchor = retentionUserIdsInCohort($db, $anchorId);
    $uNext   = retentionUserIdsInCohort($db, $nextId);
    $uPast   = retentionPastUserIdSet($db, $anchorId);
    if (count($uAnchor) === 0 || count($uNext) === 0) continue;

    $cards = retentionClassifyNext($uNext, $uAnchor, $uPast);
    $stay = $cards['stay']; $ret = $cards['returning']; $brand = $cards['brand_new'];

    $errors = [];

    // Invariant: stay + returning + brand_new = |U_next|
    if ($stay + $ret + $brand !== count($uNext)) {
        $errors[] = "cards sum != |U_next| ($stay+$ret+$brand vs " . count($uNext) . ")";
    }

    // Invariant: brand_new == count(next user_ids with participation_count=1)
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT bm.user_id)
          FROM bootcamp_members bm
         WHERE bm.cohort_id = ?
           AND bm.participation_count = 1
           AND bm.user_id IS NOT NULL AND bm.user_id <> ''
    ");
    $stmt->execute([$nextId]);
    $pcOne = (int)$stmt->fetchColumn();
    if ($pcOne !== $brand) {
        $errors[] = "brand_new($brand) != participation_count=1 in next($pcOne) — data drift, soft warn";
    }

    // Invariant: curve[0].pct = 100, curve[1].count = stay
    $curve = retentionComputeCurve($db, $anchor, $uAnchor);
    if (!_approx_eq($curve[0]['pct'], 100.0)) $errors[] = "curve[0].pct != 100 ({$curve[0]['pct']})";
    if ($curve[0]['count'] !== count($uAnchor)) $errors[] = "curve[0].count != |U_anchor|";
    if (!isset($curve[1]) || $curve[1]['count'] !== $stay) {
        $errors[] = "curve[1].count != stay (" . ($curve[1]['count'] ?? 'NA') . " vs $stay)";
    }

    // Invariant: participation breakdown total = |U_anchor|, transitioned = stay
    $bp = retentionBreakdownParticipation($db, $anchorId, $uNext);
    $bpTotal = array_sum(array_column($bp['rows'], 'total'));
    $bpTrans = array_sum(array_column($bp['rows'], 'transitioned'));
    if ($bpTotal !== count($uAnchor)) $errors[] = "participation total($bpTotal) != |U_anchor|(" . count($uAnchor) . ")";
    if ($bpTrans !== $stay)           $errors[] = "participation trans($bpTrans) != stay($stay)";

    // Invariant: group breakdown (when active) total = |U_anchor|, trans = stay
    $bg = retentionBreakdownGroup($db, $anchorId, $uNext);
    if ($bg !== null) {
        $bgTotal = array_sum(array_column($bg['rows'], 'total'));
        $bgTrans = array_sum(array_column($bg['rows'], 'transitioned'));
        if ($bgTotal !== count($uAnchor)) $errors[] = "group total($bgTotal) != |U_anchor|";
        if ($bgTrans !== $stay)           $errors[] = "group trans($bgTrans) != stay";
    }

    // Invariant: score breakdown (when active) total ≤ |U_anchor|, trans ≤ stay
    $bs = retentionBreakdownScore($db, $anchorId, count($uAnchor), $uNext);
    if ($bs !== null) {
        $bsTotal = array_sum(array_column($bs['rows'], 'total'));
        $bsTrans = array_sum(array_column($bs['rows'], 'transitioned'));
        if ($bsTotal > count($uAnchor)) $errors[] = "score total($bsTotal) > |U_anchor|";
        if ($bsTrans > $stay)           $errors[] = "score trans($bsTrans) > stay";
        if ($bsTotal !== $bs['scored_total']) $errors[] = "score scored_total mismatch";
    }

    $total++;
    if ($errors) {
        $fail++;
        $failures[] = "pair {$anchor['cohort']}→{$next['cohort']}: " . implode('; ', $errors);
    } else {
        $pass++;
    }
}

echo "RETENTION INVARIANTS\n";
echo "====================\n";
echo "Pairs checked: $total\n";
echo "Passed:        $pass\n";
echo "Failed:        $fail\n";
if ($failures) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "\nAll invariants OK.\n";
exit(0);
```

- [ ] **Step 3: Run it against DEV**

```bash
cd /root/boot-dev && php tests/retention_invariants.php
```

Expected: `Pairs checked: 10`, `Passed: 10`, `Failed: 0`, exit code 0. (DEV has cohorts 1~11.)

If `brand_new vs participation_count=1` soft-warns appear, that's data drift, not a code bug — investigate but don't block.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev
git add tests/retention_invariants.php
git commit -m "test(retention): invariant verification script for all pairs"
```

---

### Task 11: Wire frontend assets in `operation/index.php`

**Files:**
- Modify: `public_html/operation/index.php`

- [ ] **Step 1: Add CSS link after `notify.css`**

Find:
```html
<link rel="stylesheet" href="/css/notify.css<?= v('/css/notify.css') ?>">
```

Add immediately after:
```html
<link rel="stylesheet" href="/css/retention.css<?= v('/css/retention.css') ?>">
```

- [ ] **Step 2: Add Chart.js CDN + retention.js after `notify.js`**

Find:
```html
<script src="/js/notify.js<?= v('/js/notify.js') ?>"></script>
```

Add immediately after:
```html
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="/js/retention.js<?= v('/js/retention.js') ?>"></script>
```

- [ ] **Step 3: Verify the page still loads**

The css/js files don't exist yet, so a 404 is expected for those two. The page itself should still render normally — just open `https://dev-boot.soritune.com/operation/` in a browser to confirm no JS errors break the existing tabs.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev
git add public_html/operation/index.php
git commit -m "feat(retention): include retention.css/.js + Chart.js CDN on operation page"
```

---

### Task 12: Add tab button + tab-content + lazy load to `admin.js`

**Files:**
- Modify: `public_html/js/admin.js`

- [ ] **Step 1: Add the tab button**

Find (around line 195, in the `operation` role tab block):
```javascript
                            <button class="tab" data-tab="#tab-notify" data-hash="notify">알림톡</button>
```

Add immediately after:
```javascript
                            <button class="tab" data-tab="#tab-retention" data-hash="retention">리텐션 관리</button>
```

- [ ] **Step 2: Add the tab content div**

Find (around line 217):
```javascript
                        <div class="tab-content" id="tab-notify"></div>
```

Add immediately after:
```javascript
                        <div class="tab-content" id="tab-retention"></div>
```

- [ ] **Step 3: Add lazy load block**

Find (around line 455, the notify lazy-load block ending with `}`):
```javascript
            // Notify 탭 lazy load
            if (typeof AdminNotify !== 'undefined') {
                const notifyTab = document.getElementById('tab-notify');
                if (notifyTab) {
                    const notifyObserver = new MutationObserver(() => {
                        if (notifyTab.classList.contains('active') && !notifyTab.dataset.loaded) {
                            notifyTab.dataset.loaded = '1';
                            AdminNotify.init(notifyTab);
                        }
                    });
                    notifyObserver.observe(notifyTab, { attributes: true, attributeFilter: ['class'] });
                }
            }
```

Add immediately after (closing `}` of the if-block):
```javascript

            // Retention 탭 lazy load
            if (typeof RetentionApp !== 'undefined') {
                const retTab = document.getElementById('tab-retention');
                if (retTab) {
                    const retObserver = new MutationObserver(() => {
                        if (retTab.classList.contains('active') && !retTab.dataset.loaded) {
                            retTab.dataset.loaded = '1';
                            RetentionApp.init(retTab);
                        }
                    });
                    retObserver.observe(retTab, { attributes: true, attributeFilter: ['class'] });
                }
            }
```

- [ ] **Step 4: Verify in browser**

Reload `https://dev-boot.soritune.com/operation/` (logged in as operation admin). The "리텐션 관리" tab button should appear at the end of the operation tab strip. Clicking it shows an empty area (RetentionApp not yet defined → silently no init, just the empty tab-content div).

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev
git add public_html/js/admin.js
git commit -m "feat(retention): add operation tab button, content div, lazy-load hook"
```

---

### Task 13: `js/retention.js` skeleton + pair button render

**Files:**
- Create: `public_html/js/retention.js`

- [ ] **Step 1: Create the file**

```javascript
/* ══════════════════════════════════════════════════════════════
   RetentionApp — /operation 리텐션 관리 탭
   페어 선택 → 카드, GA4 잔존 곡선, breakdown 3종 표시.
   Spec: docs/superpowers/specs/2026-04-28-retention-management-design.md
   ══════════════════════════════════════════════════════════════ */
const RetentionApp = (() => {
    const API = '/api/admin.php?action=';
    let root = null;
    let pairs = [];
    let currentAnchorId = null;
    let chart = null;

    async function init(container) {
        root = container;
        await loadPairs();
    }

    async function loadPairs() {
        root.innerHTML = '<div class="loading">페어 목록 로드 중…</div>';
        const r = await App.get(API + 'retention_pairs');
        if (!r.success) {
            root.innerHTML = `<div class="error">${App.esc(r.error || '오류')}</div>`;
            return;
        }
        pairs = r.pairs || [];
        if (pairs.length === 0) {
            root.innerHTML = '<div class="empty">분석 가능한 페어가 없습니다. 다음 기수에 등록자가 1명 이상 있어야 합니다.</div>';
            return;
        }
        renderShell();
        // default: 가장 최근 anchor (페어 목록의 마지막)
        selectPair(pairs[pairs.length - 1].anchor_cohort_id);
    }

    function renderShell() {
        const buttons = pairs.map(p => `
            <button class="ret-pair-btn" data-anchor="${p.anchor_cohort_id}">
                ${App.esc(p.anchor_name)} → ${App.esc(p.next_name)}
            </button>
        `).join('');
        root.innerHTML = `
            <div class="ret-header">
                <h2>리텐션 관리</h2>
                <button class="btn btn-secondary btn-sm" id="ret-refresh">새로고침</button>
            </div>
            <div class="ret-pair-strip">${buttons}</div>
            <div class="ret-pair-title" id="ret-pair-title"></div>
            <div class="ret-cards" id="ret-cards"></div>
            <div class="ret-section">
                <h3>코호트 잔존 곡선 (anchor 이후 기수, step-independent)</h3>
                <div class="ret-curve-wrap"><canvas id="ret-curve"></canvas></div>
                <div class="muted ret-curve-note">직전·과거 모두 포함, 한 기수 빠진 후 돌아와도 잔존으로 카운트.</div>
            </div>
            <div class="ret-section">
                <h3>상황별 리텐션 breakdown</h3>
                <div class="ret-breakdowns" id="ret-breakdowns"></div>
            </div>
            <div class="ret-footnote" id="ret-footnote"></div>
        `;
        root.querySelector('.ret-pair-strip').addEventListener('click', e => {
            const btn = e.target.closest('.ret-pair-btn');
            if (!btn) return;
            selectPair(parseInt(btn.dataset.anchor, 10));
        });
        root.querySelector('#ret-refresh').addEventListener('click', () => {
            if (currentAnchorId) selectPair(currentAnchorId);
        });
    }

    async function selectPair(anchorId) {
        currentAnchorId = anchorId;
        root.querySelectorAll('.ret-pair-btn').forEach(b => {
            b.classList.toggle('active', parseInt(b.dataset.anchor, 10) === anchorId);
        });
        document.getElementById('ret-cards').innerHTML = '<div class="loading">로드 중…</div>';
        document.getElementById('ret-breakdowns').innerHTML = '';

        const r = await App.get(API + 'retention_summary&anchor_cohort_id=' + anchorId);
        if (!r.success) {
            document.getElementById('ret-cards').innerHTML = `<div class="error">${App.esc(r.error || '오류')}</div>`;
            return;
        }
        renderSummary(r);
    }

    function renderSummary(d) {
        // Tasks 14, 15, 16 implement these.
        renderTitle(d);
        renderCards(d);
        renderCurve(d);
        renderBreakdowns(d);
        renderFootnote(d);
    }

    function renderTitle(d)      { /* Task 14 */ }
    function renderCards(d)      { /* Task 14 */ }
    function renderCurve(d)      { /* Task 15 */ }
    function renderBreakdowns(d) { /* Task 16 */ }
    function renderFootnote(d)   { /* Task 14 */ }

    return { init };
})();
```

- [ ] **Step 2: Browser verify**

Reload the page, click "리텐션 관리" tab. Should see:
- 헤더 "리텐션 관리" + 새로고침 버튼
- 페어 버튼 strip (10개 버튼 in DEV)
- 마지막 버튼이 active 상태
- "로드 중…" 텍스트 이후 (renderCards stub 비어있음) 빈 영역들

콘솔 에러 없어야 함.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/js/retention.js
git commit -m "feat(retention): RetentionApp skeleton with pair strip and shell"
```

---

### Task 14: Render title, cards, footnote

**Files:**
- Modify: `public_html/js/retention.js`

- [ ] **Step 1: Replace the three stubs**

Find the three empty stub functions (`renderTitle`, `renderCards`, `renderFootnote`) and replace:

```javascript
    function renderTitle(d) {
        const t = document.getElementById('ret-pair-title');
        const ts = (d.generated_at || '').replace('T', ' ').slice(0, 19);
        t.innerHTML = `
            <div>▎<strong>${App.esc(d.anchor.name)} → ${App.esc(d.next.name)}</strong>
                 리텐션 (anchor ${d.anchor.total_with_user_id}명 · 다음 ${d.next.total_with_user_id}명)</div>
            <div class="muted ret-pair-meta">조회 시각: ${App.esc(ts)}</div>
        `;
    }

    function renderCards(d) {
        const c = d.cards;
        const totalNext = c.next_total_with_user_id;
        const pct = (n) => totalNext > 0 ? Math.round(n / totalNext * 100) : 0;
        document.getElementById('ret-cards').innerHTML = `
            <div class="ret-card">
                <div class="ret-card-label">잔존 (직전 → 다음)</div>
                <div class="ret-card-num">${pct(c.stay)}% · ${c.stay}명</div>
                <div class="muted">리텐션 ${c.retention_pct}%</div>
            </div>
            <div class="ret-card">
                <div class="ret-card-label">회귀 (과거 → 다음)</div>
                <div class="ret-card-num">${pct(c.returning)}% · ${c.returning}명</div>
            </div>
            <div class="ret-card">
                <div class="ret-card-label">신규 (첫 참여)</div>
                <div class="ret-card-num">${pct(c.brand_new)}% · ${c.brand_new}명</div>
            </div>
            <div class="ret-card ret-card-sum">
                <div class="ret-card-label">${App.esc(d.next.name)} 총 (user_id 보유)</div>
                <div class="ret-card-num">${totalNext}명</div>
                <div class="muted">전체 row ${d.next.total}건</div>
            </div>
        `;
    }

    function renderFootnote(d) {
        const f = document.getElementById('ret-footnote');
        const lines = [];
        if (d.cards.excluded_null_user_id > 0) {
            lines.push(`user_id 미입력 회원 ${d.cards.excluded_null_user_id}명은 분석에서 제외됨 (anchor + next 합산).`);
        }
        f.innerHTML = lines.length
            ? `<div class="muted">※ ${lines.map(App.esc).join(' · ')}</div>`
            : '';
    }
```

- [ ] **Step 2: Browser verify**

Reload, click "리텐션 관리" → 가장 최근 페어 자동 선택됨. 4개 카드와 헤더 타이틀이 보여야 함. 다른 페어 버튼 클릭 시 카드 값이 바뀌어야 함.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/js/retention.js
git commit -m "feat(retention): render pair title, 4 summary cards, footnote"
```

---

### Task 15: Render survival curve (Chart.js)

**Files:**
- Modify: `public_html/js/retention.js`

- [ ] **Step 1: Replace `renderCurve` stub**

```javascript
    function renderCurve(d) {
        const ctx = document.getElementById('ret-curve');
        if (chart) { chart.destroy(); chart = null; }
        const labels = d.curve.map(p => p.cohort_name);
        const data   = d.curve.map(p => p.pct);
        const counts = d.curve.map(p => p.count);
        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Retention %',
                    data,
                    fill: false,
                    tension: 0.15,
                    borderColor: '#2563EB',
                    backgroundColor: '#2563EB',
                    pointRadius: 5,
                    pointHoverRadius: 7,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } },
                    x: { title: { display: true, text: 'Cohort' } },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const i = ctx.dataIndex;
                                return `${data[i]}%  (${counts[i]}명)`;
                            }
                        }
                    },
                },
            },
        });
    }
```

- [ ] **Step 2: Browser verify**

Reload, click 페어. 11기 anchor (마지막 페어)면 점이 2개 (11기=100%, 12기=...%). 1기 anchor면 점 11개. 곡선이 부드럽게 그려지고 hover에 "%·인원" 툴팁이 떠야 함.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/js/retention.js
git commit -m "feat(retention): cohort survival curve with Chart.js"
```

---

### Task 16: Render 3 breakdowns

**Files:**
- Modify: `public_html/js/retention.js`

- [ ] **Step 1: Replace `renderBreakdowns` stub**

```javascript
    function renderBreakdowns(d) {
        const cards = [];

        // 조별
        if (d.breakdown.group) {
            cards.push(buildBreakdownCard(
                '조별',
                d.breakdown.group.rows.map(r => ({
                    label: r.name + (r.kind === 'unassigned' ? ' (미배정)' : r.kind === 'anomaly' ? ' (조 정보 이상)' : ''),
                    total: r.total, transitioned: r.transitioned, pct: r.pct,
                })),
                ''
            ));
        } else {
            cards.push(disabledCard('조별', '이 anchor 기수에는 조 데이터가 없습니다.'));
        }

        // 점수
        if (d.breakdown.score) {
            const meta = `점수 보유 ${d.breakdown.score.scored_total}명 (anchor의 ${d.breakdown.score.coverage_pct}%) 기준`;
            cards.push(buildBreakdownCard(
                '점수 범위',
                d.breakdown.score.rows.map(r => ({
                    label: r.band, total: r.total, transitioned: r.transitioned, pct: r.pct,
                })),
                meta
            ));
        } else {
            cards.push(disabledCard('점수 범위', '점수 데이터가 충분하지 않거나 없습니다 (anchor의 50% 미만).'));
        }

        // 누적 참여 횟수
        cards.push(buildBreakdownCard(
            '누적 참여 횟수',
            d.breakdown.participation.rows.map(r => ({
                label: r.bucket, total: r.total, transitioned: r.transitioned, pct: r.pct,
            })),
            ''
        ));

        document.getElementById('ret-breakdowns').innerHTML = cards.join('');
    }

    function buildBreakdownCard(title, rows, meta) {
        const trs = rows.map(r => `
            <tr>
                <td class="ret-bd-label">${App.esc(r.label)}</td>
                <td class="ret-bd-num">${r.total}</td>
                <td class="ret-bd-num">${r.transitioned}</td>
                <td class="ret-bd-bar">
                    <div class="ret-bar"><div class="ret-bar-fill" style="width:${r.pct}%"></div></div>
                </td>
                <td class="ret-bd-pct">${r.pct}%</td>
            </tr>
        `).join('');
        return `
            <div class="ret-bd-card">
                <h4>${App.esc(title)}</h4>
                ${meta ? `<div class="muted ret-bd-meta">${App.esc(meta)}</div>` : ''}
                <table class="ret-bd-table">
                    <thead><tr><th>구간</th><th>인원</th><th>진출</th><th>진출률</th><th></th></tr></thead>
                    <tbody>${trs}</tbody>
                </table>
            </div>`;
    }

    function disabledCard(title, msg) {
        return `
            <div class="ret-bd-card ret-bd-card-disabled">
                <h4>${App.esc(title)}</h4>
                <div class="muted">${App.esc(msg)}</div>
            </div>`;
    }
```

- [ ] **Step 2: Browser verify**

11기 anchor → 3개 카드 모두 표시. 1~10기 anchor → 조/점수 카드 disabled 상태(회색 톤·안내 문구), 누적참여횟수 카드만 활성. 각 행에 막대와 % 보임.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/js/retention.js
git commit -m "feat(retention): render group/score/participation breakdowns with bars"
```

---

### Task 17: CSS

**Files:**
- Create: `public_html/css/retention.css`

- [ ] **Step 1: Create the stylesheet**

```css
/* Retention 탭 — boot operation
 * Spec: docs/superpowers/specs/2026-04-28-retention-management-design.md
 */

.ret-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 0 4px;
}
.ret-header h2 { margin: 0; font-size: 18px; }

.ret-pair-strip {
    display: flex; gap: 8px; overflow-x: auto;
    padding: 8px 0; margin-bottom: 12px;
    border-bottom: 1px solid var(--border, #e5e7eb);
}
.ret-pair-btn {
    flex-shrink: 0;
    padding: 8px 14px; border-radius: 999px;
    border: 1px solid var(--border, #d1d5db);
    background: #fff; cursor: pointer; font-size: 14px;
    white-space: nowrap;
}
.ret-pair-btn:hover { background: #f3f4f6; }
.ret-pair-btn.active {
    background: #2563EB; color: #fff; border-color: #2563EB;
}

.ret-pair-title { font-size: 16px; padding: 8px 0; }
.ret-pair-meta { font-size: 12px; }

.ret-cards {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 12px; margin: 12px 0;
}
.ret-card {
    border: 1px solid var(--border, #e5e7eb); border-radius: 8px;
    padding: 14px; background: #fff;
}
.ret-card-label { font-size: 12px; color: #6b7280; }
.ret-card-num { font-size: 22px; font-weight: 600; margin-top: 4px; }
.ret-card-sum { background: #f9fafb; }

.ret-section { margin: 18px 0; }
.ret-section h3 { font-size: 15px; margin: 0 0 8px; }
.ret-curve-wrap { height: 280px; }
.ret-curve-note { font-size: 12px; }

.ret-breakdowns {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 14px;
}
.ret-bd-card {
    border: 1px solid var(--border, #e5e7eb); border-radius: 8px;
    padding: 14px; background: #fff;
}
.ret-bd-card-disabled { background: #f9fafb; }
.ret-bd-card h4 { margin: 0 0 6px; font-size: 14px; }
.ret-bd-meta { font-size: 12px; margin-bottom: 6px; }
.ret-bd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ret-bd-table th, .ret-bd-table td {
    padding: 6px 4px; border-bottom: 1px solid #f3f4f6; text-align: left;
}
.ret-bd-num, .ret-bd-pct { text-align: right; white-space: nowrap; }
.ret-bar {
    display: block; width: 100%; height: 8px;
    background: #f3f4f6; border-radius: 4px; overflow: hidden;
}
.ret-bar-fill {
    height: 100%; background: #2563EB;
}

.ret-footnote { padding: 8px 0; font-size: 12px; }

@media (max-width: 720px) {
    .ret-cards { grid-template-columns: 1fr; }
}
```

- [ ] **Step 2: Browser verify**

Reload. 카드/표/막대가 깔끔하게 정렬되어야 함. 모바일 폭(< 720px)에서 카드가 1열 stack.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/css/retention.css
git commit -m "style(retention): card/curve/breakdown layout"
```

---

### Task 18: DEV smoke + invariant re-run + final cleanup

**Files:**
- (None — verification only)

- [ ] **Step 1: Re-run invariants**

```bash
cd /root/boot-dev && php tests/retention_invariants.php
```

Expected: `Failed: 0`. If any fail, return to the affected backend task.

- [ ] **Step 2: Manual smoke — click every pair on dev**

Visit `https://dev-boot.soritune.com/operation/`, login as operation admin, click 리텐션 관리 tab. For each pair button:
- 4 카드 값이 합리적
- 곡선 점 수 = anchor 이후 기수 수 + 1 (anchor 자체 포함)
- 11기 anchor: 조·점수·누적 3개 카드 모두 활성
- 1~10기 anchor: 조·점수 카드 disabled, 누적만 활성
- 콘솔 에러 없음
- 새로고침 버튼 작동

- [ ] **Step 3: Push to dev**

```bash
cd /root/boot-dev
git push origin dev
```

- [ ] **Step 4: ⛔ STOP — request user verification**

CLAUDE.md 정책: dev push 후 사용자에게 dev 페이지 확인 요청. 운영 반영은 사용자가 명시적으로 요청한 경우에만.

Report to user with:
- dev URL: `https://dev-boot.soritune.com/operation/` → 리텐션 관리 탭
- invariant 검증 결과 (`Pairs checked: N · Failed: 0`)
- 다음 단계: 사용자 승인 시 main 머지 + BOOT pull

---

## Self-Review Checklist (run before handoff)

- [ ] **Spec coverage**: Every section of `docs/superpowers/specs/2026-04-28-retention-management-design.md` has a task implementing it (cards § 2.3 → Task 4, curve § 2.4 → Task 5, breakdowns § 2.5 → Tasks 6-8, API § 4.2 → Tasks 1+9, UI § 3 → Tasks 11-17, edges § 5 → distributed across tasks, invariants § 6 → Task 10).
- [ ] **No placeholders** in any task (all code blocks complete, no "TBD" / "implement later").
- [ ] **Type consistency**: function names match across tasks (`retentionUserIdsInCohort`, `retentionPastUserIdSet`, `retentionAnchorAndNext`, `retentionClassifyNext`, `retentionComputeCurve`, `retentionBreakdownParticipation`, `retentionBreakdownGroup`, `retentionBreakdownScore`, `handleRetentionPairs`, `handleRetentionSummary`).
- [ ] **API shape consistency**: response keys (`anchor.total_with_user_id`, `cards.next_total_with_user_id`, `breakdown.group.rows[].kind`, `breakdown.score.coverage_pct/scored_total`) match between Task 9 implementation and Tasks 14-16 frontend usage.
