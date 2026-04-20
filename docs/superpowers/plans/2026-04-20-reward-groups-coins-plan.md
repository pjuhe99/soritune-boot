# 리워드 구간(Reward Group) 코인 표시 — 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 여러 coin cycle을 하나의 "reward group"으로 묶어 지급 단위로 관리하고, 회원이 자신의 리워드 구간 코인을 cycle별로 분리해 볼 수 있게 한다. 첫 적용 대상은 11기(4/19 정산 마감) + 12기(4/20~, 부트캠프 잔여 활동 흡수).

**Architecture:** MySQL 테이블 2개 신설(`reward_groups`, `reward_group_distributions`) + `coin_cycles.reward_group_id` FK. `syncMemberCoinBalance` 공식을 `SUM(earned-used)`로 변경. `handleCoinChange`를 `applyCoinChange` 위임으로 리팩토링해 수동 조정이 cycle에 귀속되게 함. 운영자 UI는 `/operation/#coins`에 Reward Groups 섹션 신설. 회원 UI는 `memberTable.js` 렌더를 cycle 브레이크다운으로 교체. 11→12기 데이터 이관은 event-date 기준(reason_type별) + `coin_logs` 기반 `member_cycle_coins` 전수 재계산으로 안전하게.

**Tech Stack:** PHP 8 (PDO, procedural), Vanilla JS, MySQL 8. 테스트 인프라는 없음 → 변경마다 SQL/UI 수동 검증.

**Design doc:** `docs/superpowers/specs/2026-04-20-reward-groups-coins-design.md`

**Deploy rule (CLAUDE.md):** 모든 작업은 `boot-dev`(dev 브랜치)에서만. dev push 후 사용자 명시적 요청 시에만 main 머지 + prod 반영.

---

## Phase A: 스키마 + 코어 코드 변경 (선결)

### Task A1: 스키마 마이그 스크립트 작성

**Files:**
- Create: `/root/boot-dev/migrate_reward_groups.php`

- [ ] **Step 1: 마이그 스크립트 작성**

기존 `migrate_coin_cycle.php`의 패턴을 그대로 따른다 (`public_html/config.php` require → `$db = getDB()` → `$db->exec(...)` DDL).

```php
<?php
/**
 * boot.soritune.com - Reward Groups Migration
 * reward_groups + reward_group_distributions 테이블 생성
 * coin_cycles.reward_group_id FK 컬럼 추가
 *
 * 실행: php migrate_reward_groups.php
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Reward Groups Migration ===\n\n";

// 1. reward_groups
echo "[1] reward_groups 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS reward_groups (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(50) NOT NULL COMMENT '예: 11-12기 리워드',
    status           ENUM('open','distributed') NOT NULL DEFAULT 'open',
    distributed_at   DATETIME     DEFAULT NULL,
    distributed_by   INT UNSIGNED DEFAULT NULL COMMENT 'admins.id',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rg_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// 2. reward_group_distributions
echo "\n[2] reward_group_distributions 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS reward_group_distributions (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reward_group_id    INT UNSIGNED NOT NULL,
    member_id          INT UNSIGNED NOT NULL,
    total_amount       INT NOT NULL COMMENT '지급 확정 코인 합',
    cycle_breakdown    JSON NOT NULL COMMENT '예: {\"11기\": 50, \"12기\": 8}',
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_rgd (reward_group_id, member_id),
    KEY idx_rgd_member (member_id),
    CONSTRAINT fk_rgd_group  FOREIGN KEY (reward_group_id) REFERENCES reward_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_rgd_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

// 3. coin_cycles.reward_group_id
echo "\n[3] coin_cycles.reward_group_id 추가...\n";
$cols = $db->query("SHOW COLUMNS FROM coin_cycles")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('reward_group_id', $cols)) {
    $db->exec("ALTER TABLE coin_cycles ADD COLUMN reward_group_id INT UNSIGNED NULL AFTER max_coin");
    $db->exec("ALTER TABLE coin_cycles ADD KEY idx_cc_rg (reward_group_id)");
    $db->exec("ALTER TABLE coin_cycles ADD CONSTRAINT fk_cc_rg FOREIGN KEY (reward_group_id) REFERENCES reward_groups(id) ON DELETE SET NULL");
    echo "  - 컬럼 + FK 추가 완료\n";
} else {
    echo "  - 이미 존재\n";
}

echo "\n=== Reward Groups Migration 완료 ===\n";
```

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add migrate_reward_groups.php && git commit -m "feat: reward_groups 스키마 마이그 스크립트"
```

---

### Task A2: Dev DB에 스키마 마이그 실행

**Files:** (DB만 변경)

- [ ] **Step 1: 스크립트 실행**

```bash
cd /root/boot-dev && php migrate_reward_groups.php
```

Expected output: 3개 섹션 모두 "완료".

- [ ] **Step 2: 검증**

```bash
cd /root/boot-dev && source .db_credentials && mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SHOW CREATE TABLE reward_groups;
SHOW CREATE TABLE reward_group_distributions;
SHOW COLUMNS FROM coin_cycles LIKE 'reward_group_id';
"
```

Expected: 두 테이블 + `reward_group_id` 컬럼 존재 확인.

---

### Task A3: `syncMemberCoinBalance` 공식 변경

**Files:**
- Modify: `/root/boot-dev/public_html/includes/coin_functions.php:125-135`

- [ ] **Step 1: SUM 식에 `used_coin` 차감 추가**

기존 코드(coin_functions.php:125-135):
```php
function syncMemberCoinBalance($db, $memberId) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(earned_coin), 0) AS total FROM member_cycle_coins WHERE member_id = ?");
    $stmt->execute([$memberId]);
    $total = (int)$stmt->fetchColumn();

    $db->prepare("
        INSERT INTO member_coin_balances (member_id, current_coin)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE current_coin = VALUES(current_coin)
    ")->execute([$memberId, $total]);
}
```

`SELECT` 한 줄을 교체:
```php
    $stmt = $db->prepare("SELECT COALESCE(SUM(earned_coin - used_coin), 0) AS total FROM member_cycle_coins WHERE member_id = ?");
```

- [ ] **Step 2: 검증 (기존 데이터 값 유지)**

```bash
cd /root/boot-dev && source .db_credentials && mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT COUNT(*) FROM member_cycle_coins WHERE used_coin > 0;
"
```

Expected: `0` (현재 used_coin=0 뿐이므로 공식 변경해도 결과 동일 — 하위 호환).

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add public_html/includes/coin_functions.php && git commit -m "feat: syncMemberCoinBalance를 SUM(earned-used) 공식으로 변경"
```

---

### Task A4: `handleCoinChange` 리팩토링 (cycle_id 필수)

**Files:**
- Modify: `/root/boot-dev/public_html/api/services/coin.php:25-62`

- [ ] **Step 1: `handleCoinChange`를 `applyCoinChange` 위임으로 교체**

기존 `handleCoinChange`(coin.php:25-62) 전체를 아래로 교체:

```php
function handleCoinChange($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();
    $memberId    = (int)($input['member_id'] ?? 0);
    $cycleId     = (int)($input['cycle_id'] ?? 0);
    $coinChange  = (int)($input['coin_change'] ?? 0);
    $reasonType  = trim($input['reason_type'] ?? '');
    $reasonDetail = trim($input['reason_detail'] ?? '') ?: null;

    if (!$memberId || !$cycleId || !$coinChange || !$reasonType) {
        jsonError('member_id, cycle_id, coin_change, reason_type 필요');
    }

    $db = getDB();

    // cycle 존재 확인
    $cStmt = $db->prepare("SELECT id FROM coin_cycles WHERE id = ?");
    $cStmt->execute([$cycleId]);
    if (!$cStmt->fetch()) jsonError('존재하지 않는 cycle');

    // 차감일 때 earned 부족 방지
    if ($coinChange < 0) {
        $mStmt = $db->prepare("SELECT earned_coin, used_coin FROM member_cycle_coins WHERE member_id = ? AND cycle_id = ?");
        $mStmt->execute([$memberId, $cycleId]);
        $row = $mStmt->fetch();
        $current = $row ? ((int)$row['earned_coin'] - (int)$row['used_coin']) : 0;
        if ($current + $coinChange < 0) {
            jsonError("해당 cycle의 잔액({$current})을 초과 차감할 수 없습니다.");
        }
    }

    $result = applyCoinChange($db, $memberId, $cycleId, $coinChange, $reasonType, $reasonDetail, $admin['admin_id']);

    // 현재 전체 잔액 조회
    $balStmt = $db->prepare("SELECT current_coin FROM member_coin_balances WHERE member_id = ?");
    $balStmt->execute([$memberId]);
    $afterCoin = (int)($balStmt->fetchColumn() ?: 0);
    $beforeCoin = $afterCoin - $result['applied'];

    jsonSuccess([
        'before_coin' => $beforeCoin,
        'after_coin'  => $afterCoin,
        'applied'     => $result['applied'],
    ], '코인이 처리되었습니다.');
}
```

변경점 요약:
- `cycle_id` 필수 파라미터 추가
- 직접 `INSERT coin_logs` + `UPDATE member_coin_balances` 대신 `applyCoinChange` 호출 → `member_cycle_coins`에 반영
- 차감 시 해당 cycle 잔액 초과 검증
- 응답의 `before/after_coin`은 전체 잔액

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/coin.php && git commit -m "feat: handleCoinChange를 applyCoinChange 위임으로 리팩토링 (cycle_id 필수)"
```

---

## Phase B: 운영자 수동 코인 조정 UI 업데이트

### Task B1: `_coinAction`에 cycle 선택기 추가

**Files:**
- Modify: `/root/boot-dev/public_html/js/bootcamp.js:1285-` (`_coinAction` 함수)

- [ ] **Step 1: cycle 선택기 + fetch 로직 추가**

`_coinAction` 함수 상단에서 현재 active cycle을 API로 조회해 select에 채우고, 제출 시 `cycle_id`를 포함한다.

```javascript
function _coinAction(memberId, nickname, currentCoin) {
    // 먼저 active cycle 목록 로드
    fetch('/api/bootcamp.php?action=coin_cycles', { credentials: 'include' })
        .then(r => r.json())
        .then(r => {
            if (!r.success) { Toast.error(r.error || '사이클 조회 실패'); return; }
            const cycles = (r.cycles || []).filter(c => c.status === 'active');
            if (!cycles.length) { Toast.error('active cycle이 없습니다'); return; }

            const cycleOptions = cycles.map(c =>
                `<option value="${c.id}">${App.esc(c.name)} (${c.start_date}~${c.end_date})</option>`
            ).join('');

            const body = `
                <div class="bc-coin-card">
                    <div class="coin-label">${App.esc(nickname)} 현재 코인</div>
                    <div class="coin-value">${currentCoin}</div>
                </div>
                <div class="form-group">
                    <label class="form-label">귀속 Cycle</label>
                    <select class="form-input" id="coin-cycle-id">${cycleOptions}</select>
                </div>
                <div class="form-group">
                    <label class="form-label">변동량 (양수=적립, 음수=차감)</label>
                    <input type="number" class="form-input" id="coin-amount" placeholder="예: 10 또는 -5">
                </div>
                <div class="form-group">
                    <label class="form-label">사유</label>
                    <input type="text" class="form-input" id="coin-reason" placeholder="예: 이벤트 보상">
                </div>
            `;

            App.modal('코인 조정', body, async () => {
                const cycleId = parseInt(document.getElementById('coin-cycle-id').value);
                const amount = parseInt(document.getElementById('coin-amount').value);
                const reason = document.getElementById('coin-reason').value.trim();
                if (!cycleId || !amount || !reason) { Toast.error('모든 항목을 입력하세요'); return false; }

                const r = await apiPost('coin_change', {
                    member_id: memberId,
                    cycle_id: cycleId,
                    coin_change: amount,
                    reason_type: 'manual_adjustment',
                    reason_detail: reason,
                });
                if (!r.success) { Toast.error(r.error || r.message); return false; }
                Toast.success(r.message || '코인이 처리되었습니다');
            });
        });
}
```

기존 `_coinAction` 함수 전체를 위 코드로 대체. 기존 `apiPost` 헬퍼가 없으면 `App.api` 혹은 fetch로 교체(파일 내 기존 호출 스타일 확인).

- [ ] **Step 2: 브라우저 테스트**

운영자 계정으로 `/bootcamp` 접속 → 회원 리스트에서 [적립/차감] 버튼 클릭 → cycle 선택 + 양/음수 입력 → 저장 → 토스트 확인.

```bash
# dev DB 직접 확인:
cd /root/boot-dev && source .db_credentials && mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT * FROM coin_logs WHERE reason_type='manual_adjustment' ORDER BY id DESC LIMIT 3;
SELECT member_id, cycle_id, earned_coin, used_coin FROM member_cycle_coins ORDER BY updated_at DESC LIMIT 3;
SELECT member_id, current_coin FROM member_coin_balances ORDER BY updated_at DESC LIMIT 3;
"
```

Expected: `coin_logs`에 `cycle_id` 포함된 로그, `member_cycle_coins.earned_coin` 변동, `member_coin_balances.current_coin` 동기화됨.

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/bootcamp.js && git commit -m "feat: 수동 코인 조정 UI에 cycle 선택기 추가"
```

---

## Phase C: Reward Group 백엔드

### Task C1: 헬퍼 함수 추가

**Files:**
- Modify: `/root/boot-dev/public_html/includes/coin_functions.php` (파일 하단 append)

- [ ] **Step 1: 헬퍼 함수 추가**

`coin_functions.php` 파일 **끝에** append:

```php

// ══════════════════════════════════════════════════════════════
// Reward Groups (리워드 구간)
// ══════════════════════════════════════════════════════════════

/**
 * 회원의 현재 open reward group 반환 (해당 member가 member_cycle_coins row를 가진 cycle 중).
 * 여러 open group에 걸쳐있으면 cycle end_date 최신 기준으로 하나 선택.
 */
function getCurrentRewardGroupForMember($db, $memberId) {
    $stmt = $db->prepare("
        SELECT rg.id, rg.name, rg.status
        FROM reward_groups rg
        JOIN coin_cycles cc ON cc.reward_group_id = rg.id
        JOIN member_cycle_coins mcc ON mcc.cycle_id = cc.id AND mcc.member_id = ?
        WHERE rg.status = 'open'
        ORDER BY cc.end_date DESC
        LIMIT 1
    ");
    $stmt->execute([$memberId]);
    $group = $stmt->fetch();
    if (!$group) return null;

    // 소속 cycle들 + 해당 회원의 earned, cycle status
    $cStmt = $db->prepare("
        SELECT cc.id, cc.name, cc.status,
               COALESCE(mcc.earned_coin, 0) AS earned,
               COALESCE(mcc.used_coin, 0)   AS used
        FROM coin_cycles cc
        LEFT JOIN member_cycle_coins mcc ON mcc.cycle_id = cc.id AND mcc.member_id = ?
        WHERE cc.reward_group_id = ?
        ORDER BY cc.start_date
    ");
    $cStmt->execute([$memberId, $group['id']]);
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

/**
 * reward group 조회 (속한 cycle 목록 포함)
 */
function getRewardGroupWithCycles($db, $groupId) {
    $stmt = $db->prepare("SELECT * FROM reward_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $group = $stmt->fetch();
    if (!$group) return null;

    $cStmt = $db->prepare("
        SELECT id, name, start_date, end_date, status
        FROM coin_cycles WHERE reward_group_id = ?
        ORDER BY start_date
    ");
    $cStmt->execute([$groupId]);
    $group['cycles'] = $cStmt->fetchAll();

    return $group;
}

/**
 * 지급 사전조건 검사.
 * @return array ['can_distribute' => bool, 'blockers' => [string, ...]]
 */
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

- [ ] **Step 2: PHP 신택스 체크**

```bash
php -l /root/boot-dev/public_html/includes/coin_functions.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add public_html/includes/coin_functions.php && git commit -m "feat: reward group 헬퍼 함수 추가"
```

---

### Task C2: Reward Group 서비스 파일 작성

**Files:**
- Create: `/root/boot-dev/public_html/api/services/coin_reward_group.php`

- [ ] **Step 1: 서비스 파일 작성**

기존 `coin_cycle.php`의 패턴을 따른다 (`handleXxx` 함수들 + `requireAdmin` + `jsonError`/`jsonSuccess`).

```php
<?php
/**
 * Coin Reward Group Service
 * 리워드 구간 CRUD, 지급(distribute), 지급 내역 조회
 */

// ── 목록 ────────────────────────────────────────────────────
function handleCoinRewardGroups() {
    requireAdmin();
    $db = getDB();
    $stmt = $db->query("
        SELECT rg.*,
               (SELECT COUNT(*) FROM coin_cycles cc WHERE cc.reward_group_id = rg.id) AS cycle_count,
               (SELECT COALESCE(SUM(mcc.earned_coin - mcc.used_coin), 0)
                  FROM coin_cycles cc
                  JOIN member_cycle_coins mcc ON mcc.cycle_id = cc.id
                 WHERE cc.reward_group_id = rg.id) AS active_total
        FROM reward_groups rg
        ORDER BY rg.created_at DESC
    ");
    $groups = $stmt->fetchAll();

    // 각 group의 cycle 이름 붙여주기
    foreach ($groups as &$g) {
        $cStmt = $db->prepare("SELECT id, name, start_date, end_date, status FROM coin_cycles WHERE reward_group_id = ? ORDER BY start_date");
        $cStmt->execute([$g['id']]);
        $g['cycles'] = $cStmt->fetchAll();
    }
    jsonSuccess(['groups' => $groups]);
}

// ── CRUD ────────────────────────────────────────────────────
function handleCoinRewardGroupCreate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $name = trim($input['name'] ?? '');
    if (!$name) jsonError('name 필요');

    $db = getDB();
    $db->prepare("INSERT INTO reward_groups (name) VALUES (?)")->execute([$name]);
    jsonSuccess(['id' => (int)$db->lastInsertId()], 'Reward group이 생성되었습니다.');
}

function handleCoinRewardGroupUpdate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    if (!$id || !$name) jsonError('id, name 필요');

    $db = getDB();
    $gStmt = $db->prepare("SELECT status FROM reward_groups WHERE id = ?");
    $gStmt->execute([$id]);
    $row = $gStmt->fetch();
    if (!$row) jsonError('group을 찾을 수 없습니다');
    if ($row['status'] !== 'open') jsonError('이미 지급된 group은 수정 불가');

    $db->prepare("UPDATE reward_groups SET name = ? WHERE id = ?")->execute([$name, $id]);
    jsonSuccess([], '수정되었습니다.');
}

function handleCoinRewardGroupDelete($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $db = getDB();
    $gStmt = $db->prepare("
        SELECT rg.status, (SELECT COUNT(*) FROM coin_cycles cc WHERE cc.reward_group_id = rg.id) AS cc
        FROM reward_groups rg WHERE rg.id = ?
    ");
    $gStmt->execute([$id]);
    $row = $gStmt->fetch();
    if (!$row) jsonError('group을 찾을 수 없습니다');
    if ($row['status'] !== 'open') jsonError('지급된 group은 삭제 불가');
    if ((int)$row['cc'] !== 0) jsonError('소속 cycle을 먼저 떼세요');

    $db->prepare("DELETE FROM reward_groups WHERE id = ?")->execute([$id]);
    jsonSuccess([], '삭제되었습니다.');
}

// ── Cycle attach/detach ────────────────────────────────────
function handleCoinRewardGroupAttach($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $groupId = (int)($input['group_id'] ?? 0);
    $cycleId = (int)($input['cycle_id'] ?? 0);
    if (!$groupId || !$cycleId) jsonError('group_id, cycle_id 필요');

    $db = getDB();

    // group status=open
    $gStmt = $db->prepare("SELECT status FROM reward_groups WHERE id = ?");
    $gStmt->execute([$groupId]);
    $g = $gStmt->fetch();
    if (!$g) jsonError('group을 찾을 수 없습니다');
    if ($g['status'] !== 'open') jsonError('지급된 group에는 cycle 추가 불가');

    // cycle 자체가 다른 group에 속해있지 않은지
    $cStmt = $db->prepare("SELECT reward_group_id FROM coin_cycles WHERE id = ?");
    $cStmt->execute([$cycleId]);
    $c = $cStmt->fetch();
    if (!$c) jsonError('cycle을 찾을 수 없습니다');
    if ($c['reward_group_id']) jsonError('이미 다른 group에 속한 cycle');

    // group의 현재 cycle 개수 < 2
    $countStmt = $db->prepare("SELECT COUNT(*) FROM coin_cycles WHERE reward_group_id = ?");
    $countStmt->execute([$groupId]);
    if ((int)$countStmt->fetchColumn() >= 2) jsonError('reward group당 cycle은 최대 2개');

    $db->prepare("UPDATE coin_cycles SET reward_group_id = ? WHERE id = ?")->execute([$groupId, $cycleId]);
    jsonSuccess([], 'Cycle이 group에 추가되었습니다.');
}

function handleCoinRewardGroupDetach($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $groupId = (int)($input['group_id'] ?? 0);
    $cycleId = (int)($input['cycle_id'] ?? 0);
    if (!$groupId || !$cycleId) jsonError('group_id, cycle_id 필요');

    $db = getDB();
    $gStmt = $db->prepare("SELECT status FROM reward_groups WHERE id = ?");
    $gStmt->execute([$groupId]);
    $g = $gStmt->fetch();
    if (!$g) jsonError('group을 찾을 수 없습니다');
    if ($g['status'] !== 'open') jsonError('지급된 group은 detach 불가');

    $db->prepare("UPDATE coin_cycles SET reward_group_id = NULL WHERE id = ? AND reward_group_id = ?")
       ->execute([$cycleId, $groupId]);
    jsonSuccess([], 'Cycle이 group에서 제외되었습니다.');
}

// ── Preview / Distribute ───────────────────────────────────
function handleCoinRewardGroupPreview() {
    requireAdmin(['operation']);
    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$groupId) jsonError('group_id 필요');

    $db = getDB();
    $group = getRewardGroupWithCycles($db, $groupId);
    if (!$group) jsonError('group을 찾을 수 없습니다');

    $prereq = checkDistributePrerequisites($group);

    // 회원별 cycle 합계
    $cycleIds = array_map(fn($c) => (int)$c['id'], $group['cycles']);
    $members = [];
    if ($cycleIds) {
        $ph = implode(',', array_fill(0, count($cycleIds), '?'));
        $stmt = $db->prepare("
            SELECT bm.id AS member_id, bm.nickname, bm.real_name,
                   cc.name AS cycle_name,
                   (mcc.earned_coin - mcc.used_coin) AS amount
            FROM member_cycle_coins mcc
            JOIN coin_cycles cc ON cc.id = mcc.cycle_id
            JOIN bootcamp_members bm ON bm.id = mcc.member_id
            WHERE mcc.cycle_id IN ($ph)
              AND (mcc.earned_coin - mcc.used_coin) > 0
            ORDER BY bm.nickname
        ");
        $stmt->execute($cycleIds);
        foreach ($stmt->fetchAll() as $r) {
            $mid = (int)$r['member_id'];
            if (!isset($members[$mid])) {
                $members[$mid] = [
                    'member_id' => $mid,
                    'nickname'  => $r['nickname'],
                    'real_name' => $r['real_name'],
                    'per_cycle' => [],
                    'total'     => 0,
                ];
            }
            $members[$mid]['per_cycle'][$r['cycle_name']] = (int)$r['amount'];
            $members[$mid]['total'] += (int)$r['amount'];
        }
    }

    jsonSuccess([
        'group'          => ['id' => (int)$group['id'], 'name' => $group['name'], 'status' => $group['status']],
        'cycles'         => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'status' => $c['status']], $group['cycles']),
        'can_distribute' => $prereq['can_distribute'],
        'blockers'       => $prereq['blockers'],
        'members'        => array_values($members),
    ]);
}

function handleCoinRewardGroupDistribute($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $groupId = (int)($input['group_id'] ?? 0);
    if (!$groupId) jsonError('group_id 필요');

    $db = getDB();
    $group = getRewardGroupWithCycles($db, $groupId);
    if (!$group) jsonError('group을 찾을 수 없습니다');

    $prereq = checkDistributePrerequisites($group);
    if (!$prereq['can_distribute']) {
        jsonError('지급 불가: ' . implode(', ', $prereq['blockers']));
    }

    $cycleIds = array_map(fn($c) => (int)$c['id'], $group['cycles']);
    $cycleNames = []; // cycle_id => name
    foreach ($group['cycles'] as $c) $cycleNames[(int)$c['id']] = $c['name'];

    $db->beginTransaction();
    try {
        $ph = implode(',', array_fill(0, count($cycleIds), '?'));
        $stmt = $db->prepare("
            SELECT mcc.member_id, mcc.cycle_id, mcc.earned_coin, mcc.used_coin
            FROM member_cycle_coins mcc
            WHERE mcc.cycle_id IN ($ph)
        ");
        $stmt->execute($cycleIds);
        $rows = $stmt->fetchAll();

        // member_id별로 cycle 합산
        $perMember = []; // member_id => ['breakdown' => [cycle_name => amount], 'total' => N]
        foreach ($rows as $r) {
            $mid = (int)$r['member_id'];
            $active = (int)$r['earned_coin'] - (int)$r['used_coin'];
            if ($active <= 0) continue;
            $cname = $cycleNames[(int)$r['cycle_id']];
            if (!isset($perMember[$mid])) $perMember[$mid] = ['breakdown' => [], 'total' => 0];
            $perMember[$mid]['breakdown'][$cname] = $active;
            $perMember[$mid]['total'] += $active;
        }

        $grantedCount = 0;
        foreach ($perMember as $mid => $info) {
            // reward_group_distributions INSERT
            $db->prepare("
                INSERT INTO reward_group_distributions (reward_group_id, member_id, total_amount, cycle_breakdown)
                VALUES (?, ?, ?, ?)
            ")->execute([$groupId, $mid, $info['total'], json_encode($info['breakdown'], JSON_UNESCAPED_UNICODE)]);
            $grantedCount++;
        }

        // 각 cycle × member 의 used_coin = earned_coin + coin_logs INSERT
        foreach ($rows as $r) {
            $mid = (int)$r['member_id'];
            $cid = (int)$r['cycle_id'];
            $active = (int)$r['earned_coin'] - (int)$r['used_coin'];
            if ($active <= 0) continue;

            $db->prepare("UPDATE member_cycle_coins SET used_coin = earned_coin WHERE member_id = ? AND cycle_id = ?")
               ->execute([$mid, $cid]);

            $db->prepare("
                INSERT INTO coin_logs (member_id, cycle_id, coin_change, before_coin, after_coin, reason_type, reason_detail, created_by)
                VALUES (?, ?, ?, ?, ?, 'reward_distribution', ?, ?)
            ")->execute([$mid, $cid, -$active, (int)$r['earned_coin'] - (int)$r['used_coin'], 0, "리워드 지급 ({$group['name']})", $admin['admin_id']]);
        }

        // group status 업데이트
        $db->prepare("UPDATE reward_groups SET status='distributed', distributed_at=NOW(), distributed_by=? WHERE id = ?")
           ->execute([$admin['admin_id'], $groupId]);

        // 영향 member 전원 sync
        foreach (array_keys($perMember) as $mid) {
            syncMemberCoinBalance($db, $mid);
        }

        $db->commit();
        jsonSuccess(['granted' => $grantedCount], "리워드 지급 완료: {$grantedCount}명");
    } catch (Throwable $e) {
        $db->rollBack();
        jsonError('지급 실패: ' . $e->getMessage());
    }
}

// ── 지급 내역 ────────────────────────────────────────────────
function handleCoinRewardGroupDistributionDetail() {
    requireAdmin();
    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$groupId) jsonError('group_id 필요');

    $db = getDB();
    $gStmt = $db->prepare("
        SELECT rg.*, a.name AS distributor_name
        FROM reward_groups rg
        LEFT JOIN admins a ON rg.distributed_by = a.id
        WHERE rg.id = ?
    ");
    $gStmt->execute([$groupId]);
    $group = $gStmt->fetch();
    if (!$group) jsonError('group을 찾을 수 없습니다');

    $dStmt = $db->prepare("
        SELECT rgd.*, bm.nickname, bm.real_name
        FROM reward_group_distributions rgd
        JOIN bootcamp_members bm ON bm.id = rgd.member_id
        WHERE rgd.reward_group_id = ?
        ORDER BY bm.nickname
    ");
    $dStmt->execute([$groupId]);
    $distributions = $dStmt->fetchAll();
    foreach ($distributions as &$d) {
        $d['cycle_breakdown'] = json_decode($d['cycle_breakdown'], true);
    }

    jsonSuccess(['group' => $group, 'distributions' => $distributions]);
}
```

- [ ] **Step 2: PHP 신택스 체크**

```bash
php -l /root/boot-dev/public_html/api/services/coin_reward_group.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/coin_reward_group.php && git commit -m "feat: coin reward group API 서비스 추가"
```

---

### Task C3: 라우팅 등록

**Files:**
- Modify: `/root/boot-dev/public_html/api/bootcamp.php:10-22` (require_once 블록), `:224-235` (coin cycles 라우팅 아래)

- [ ] **Step 1: require_once 추가**

`bootcamp.php:22` (`require_once __DIR__ . '/services/coin_cycle.php';` 다음 줄)에 추가:

```php
require_once __DIR__ . '/services/coin_reward_group.php';
```

- [ ] **Step 2: 라우팅 case 추가**

`bootcamp.php:235`의 `case 'coin_cheer_status':` 뒤(`// ── Scores ───` 구분선 앞)에 블록 추가:

```php

// ── Coin Reward Groups ──────────────────────────────────────
case 'coin_reward_groups':                    handleCoinRewardGroups(); break;
case 'coin_reward_group_create':              handleCoinRewardGroupCreate($method); break;
case 'coin_reward_group_update':              handleCoinRewardGroupUpdate($method); break;
case 'coin_reward_group_delete':              handleCoinRewardGroupDelete($method); break;
case 'coin_reward_group_attach_cycle':        handleCoinRewardGroupAttach($method); break;
case 'coin_reward_group_detach_cycle':        handleCoinRewardGroupDetach($method); break;
case 'coin_reward_group_preview':             handleCoinRewardGroupPreview(); break;
case 'coin_reward_group_distribute':          handleCoinRewardGroupDistribute($method); break;
case 'coin_reward_group_distribution_detail': handleCoinRewardGroupDistributionDetail(); break;
```

- [ ] **Step 3: 브라우저/curl로 smoke test**

```bash
# 운영자 세션 쿠키 필요. 일단 인증 에러라도 응답 구조 확인 가능:
curl -s 'https://dev-boot.soritune.com/api/bootcamp.php?action=coin_reward_groups' | head
```

Expected: JSON (인증 에러 가능). 500 에러 안 나면 라우팅 OK.

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/bootcamp.php && git commit -m "feat: reward group 엔드포인트 라우팅 등록"
```

---

## Phase D: 회원 API + UI

### Task D1: 회원 API 응답에 `current_reward_group` 추가

**Files:**
- Modify: `/root/boot-dev/public_html/api/member.php` (login:61-76, check_session:109-125, dashboard:136-161)

- [ ] **Step 1: login case 업데이트**

member.php:46-76 `login` case의 coin 조회 이후에 다음 줄 추가(라인 48 아래):

```php
    require_once __DIR__ . '/../includes/coin_functions.php';
    $currentRewardGroup = getCurrentRewardGroupForMember($db, $member['id']);
```

그리고 jsonSuccess의 `'member'` 배열 맨 끝에 추가:
```php
            'current_reward_group' => $currentRewardGroup,
```

- [ ] **Step 2: check_session case 업데이트**

member.php:105-125 `check_session` case의 coin 조회 아래 동일하게:

```php
            require_once __DIR__ . '/../includes/coin_functions.php';
            $currentRewardGroup = getCurrentRewardGroupForMember($db, $member['id']);
```

jsonSuccess 'member' 배열 맨 끝에 추가:
```php
                    'current_reward_group' => $currentRewardGroup,
```

- [ ] **Step 3: dashboard case 업데이트**

member.php:154-161의 dashboard 응답 `$member` 배열에 coin 다음 줄 추가:

```php
    require_once __DIR__ . '/../includes/coin_functions.php';
    $member['current_reward_group'] = getCurrentRewardGroupForMember($db, $s['member_id']);
```

- [ ] **Step 4: 수동 테스트**

```bash
# 회원 세션 쿠키로 check_session 호출:
curl -s 'https://dev-boot.soritune.com/api/member.php?action=check_session' -b 'SORITUNE_MEMBER_SESSION=...' | python3 -m json.tool
```

Expected: `"current_reward_group"` 필드 존재 (null 혹은 객체).

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/member.php && git commit -m "feat: 회원 API 응답에 current_reward_group 추가"
```

---

### Task D2: memberTable.js 코인 섹션 교체

**Files:**
- Modify: `/root/boot-dev/public_html/js/memberTable.js:129-135`

- [ ] **Step 1: 코인 렌더링 로직 교체**

기존(memberTable.js:129-135):
```javascript
                        <div class="mt-detail-section">
                            <div class="mt-detail-label">점수 / 코인</div>
                            <div class="mt-detail-items">
                                <span>점수: ${scoreHtml(m.current_score)}</span>
                                <span>코인: ${m.current_coin || 0}</span>
                            </div>
                        </div>
```

교체:
```javascript
                        <div class="mt-detail-section">
                            <div class="mt-detail-label">점수 / 코인</div>
                            <div class="mt-detail-items">
                                <span>점수: ${scoreHtml(m.current_score)}</span>
                                ${renderCoinSection(m)}
                            </div>
                        </div>
```

파일 내 어딘가에 함수 추가 (파일 상단 헬퍼 영역):
```javascript
function renderCoinSection(m) {
    const rg = m.current_reward_group;
    if (rg && Array.isArray(rg.cycles) && rg.cycles.length) {
        const total = rg.cycles.reduce((s, c) => s + (parseInt(c.earned) || 0), 0);
        const breakdown = rg.cycles.map(c =>
            `${App.esc(c.name)} ${c.earned} (${c.settled ? '정산 완료' : '적립 중'})`
        ).join(' · ');
        return `<span>코인 (${App.esc(rg.name)}): ${total}<br><small style="opacity:0.7">└ ${breakdown}</small></span>`;
    }
    return `<span>코인: ${parseInt(m.current_coin) || 0}</span>`;
}
```

**주의**: `memberTable.js`가 운영자 화면에서도 쓰임. 관리자 조회용 API(`member_page.php`)도 `current_reward_group`을 반환하는지 확인 — Task D3 참조.

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/memberTable.js && git commit -m "feat: 회원 카드에 reward group 브레이크다운 표시"
```

---

### Task D3: 관리자 회원 조회 API도 `current_reward_group` 포함

**Files:**
- Modify: `/root/boot-dev/public_html/api/services/member_page.php:385-410` (코인 조회 블록)

- [ ] **Step 1: member_page.php 응답에 필드 추가**

member_page.php:385 근처 SELECT에 필드 추가하거나, 반환 직전에 회원별 `getCurrentRewardGroupForMember` 호출. 성능 문제가 없으면 후자가 간단:

member_page.php에서 회원 리스트를 반환하는 루프가 있으면 각 회원에 대해:
```php
$m['current_reward_group'] = getCurrentRewardGroupForMember($db, (int)$m['id']);
```

(파일 정확한 위치는 member_page.php 내부 코드 읽고 적합한 루프 지점에서 추가. 리스트가 매우 길면 batch 조회 최적화 고려.)

- [ ] **Step 2: bootcamp.js 내 회원 리스트 렌더 부분의 `m.current_coin`을 `renderCoinSection(m)`로 교체**

bootcamp.js:488, :772, :1272의 `${m.current_coin || 0}` 3곳을 `memberTable.js`에 정의한 `renderCoinSection(m)`을 호출하도록 수정. 혹은 해당 3곳만 기존 표시(총합) 유지하고 카드 상세에서만 브레이크다운 — **요구사항상 회원 본인이 보는 화면만 중요**하므로, 리스트 요약은 `current_coin` 유지해도 됨. 이 단계는 요구사항 재확인 후 결정.

**결정**: 회원 본인용 뷰는 `memberTable.js` 한 곳만 브레이크다운 필요. 관리자 리스트는 총합 유지. member_page.php에만 필드 추가, bootcamp.js의 총합 표시는 건드리지 않음.

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/member_page.php && git commit -m "feat: 회원 페이지 API에 current_reward_group 포함"
```

---

## Phase E: 운영자 Reward Group UI

### Task E1: js/coin.js에 Reward Groups 섹션 추가

**Files:**
- Modify: `/root/boot-dev/public_html/js/coin.js` (기존 `showCycles` 위에 `showRewardGroups` 섹션 추가)

- [ ] **Step 1: `showRewardGroups` 함수 작성**

`js/coin.js` 상단 `showCycles` 함수 위에 삽입:

```javascript
async function showRewardGroups(container) {
    const r = await api('coin_reward_groups');
    if (!r.success) { container.innerHTML = `<p class="text-danger">${r.error || r.message}</p>`; return; }

    const groupsHtml = r.groups.length ? r.groups.map(g => {
        const cycleBadges = (g.cycles || []).map(c =>
            `<span class="badge">${esc(c.name)}</span>`
        ).join(' ');
        const statusBadge = g.status === 'open'
            ? '<span class="badge badge-success">열림</span>'
            : '<span class="badge badge-secondary">지급완료</span>';
        const actions = g.status === 'open'
            ? `<button class="btn-icon" onclick="CoinApp.rgAttachCycle(${g.id})">cycle 추가</button>
               <button class="btn-icon" onclick="CoinApp.rgPreview(${g.id})">지급</button>
               <button class="btn-icon" onclick="CoinApp.rgEdit(${g.id}, '${esc(g.name)}')">수정</button>
               <button class="btn-icon danger" onclick="CoinApp.rgDelete(${g.id})">삭제</button>`
            : `<button class="btn-icon" onclick="CoinApp.rgDetail(${g.id})">내역</button>`;
        return `
            <tr>
                <td><strong>${esc(g.name)}</strong></td>
                <td>${cycleBadges} (${g.cycle_count}/2)</td>
                <td>${statusBadge}</td>
                <td>${g.active_total || 0}</td>
                <td class="actions">${actions}</td>
            </tr>
        `;
    }).join('') : '<tr><td colspan="5" class="empty-state">등록된 reward group이 없습니다.</td></tr>';

    container.innerHTML = `
        <div class="mgmt-toolbar mt-md">
            <span style="font-weight:600">Reward Groups</span>
            <button class="btn btn-primary btn-sm" id="btn-add-rg">새 Reward Group</button>
        </div>
        <div style="overflow-x:auto">
            <table class="data-table">
                <thead><tr><th>이름</th><th>소속 Cycle</th><th>상태</th><th>활성 합계</th><th></th></tr></thead>
                <tbody>${groupsHtml}</tbody>
            </table>
        </div>
    `;
    document.getElementById('btn-add-rg').onclick = async () => {
        const name = prompt('Reward Group 이름 (예: 11-12기 리워드)');
        if (!name) return;
        const r2 = await api('coin_reward_group_create', { body: { name } });
        if (!r2.success) { App.toast(r2.error || r2.message, 'error'); return; }
        App.toast(r2.message);
        showRewardGroups(container);
    };
}
```

- [ ] **Step 2: 액션 함수들 추가**

`CoinApp` 모듈의 return 객체에 아래 함수들을 export하고 구현 추가:

```javascript
async function rgEdit(id, currentName) {
    const name = prompt('새 이름', currentName);
    if (!name || name === currentName) return;
    const r = await api('coin_reward_group_update', { body: { id, name } });
    if (!r.success) { App.toast(r.error || r.message, 'error'); return; }
    App.toast(r.message);
    showRewardGroups(document.querySelector('.coins-container')); // 또는 현재 container 참조
}

async function rgDelete(id) {
    if (!confirm('이 reward group을 삭제합니다. 계속하시겠습니까?')) return;
    const r = await api('coin_reward_group_delete', { body: { id } });
    if (!r.success) { App.toast(r.error || r.message, 'error'); return; }
    App.toast(r.message);
    showRewardGroups(document.querySelector('.coins-container'));
}

async function rgAttachCycle(groupId) {
    // 현재 reward_group_id=NULL인 cycle 목록 가져옴
    const cr = await api('coin_cycles');
    if (!cr.success) { App.toast('cycle 조회 실패', 'error'); return; }
    const freeCycles = cr.cycles.filter(c => !c.reward_group_id);
    if (!freeCycles.length) { App.toast('붙일 수 있는 cycle이 없습니다.', 'error'); return; }
    const options = freeCycles.map(c => `<option value="${c.id}">${esc(c.name)} (${c.start_date}~${c.end_date})</option>`).join('');
    App.modal('Cycle 추가', `
        <div class="form-group"><label>Cycle</label><select id="rg-attach-cycle">${options}</select></div>
    `, async () => {
        const cycleId = parseInt(document.getElementById('rg-attach-cycle').value);
        const r = await api('coin_reward_group_attach_cycle', { body: { group_id: groupId, cycle_id: cycleId } });
        if (!r.success) { App.toast(r.error || r.message, 'error'); return false; }
        App.toast(r.message);
        showRewardGroups(document.querySelector('.coins-container'));
    });
}

async function rgPreview(groupId) {
    const r = await api('coin_reward_group_preview', { qs: `&group_id=${groupId}` });
    if (!r.success) { App.toast(r.error || r.message, 'error'); return; }
    const membersHtml = r.members.length ? r.members.map(m => {
        const perCycleStr = Object.entries(m.per_cycle).map(([k,v]) => `${esc(k)}: ${v}`).join(', ');
        return `<tr><td>${esc(m.nickname)}</td><td>${perCycleStr}</td><td style="font-weight:700">${m.total}</td></tr>`;
    }).join('') : '<tr><td colspan="3" class="empty-state">지급 대상이 없습니다.</td></tr>';

    const blockerHtml = r.can_distribute ? '' :
        `<div style="margin-bottom:12px;padding:10px;background:#fee;border-left:4px solid #c00">
            <strong>지급 불가:</strong> ${r.blockers.map(esc).join(', ')}
         </div>`;

    const title = `리워드 지급 미리보기 — ${esc(r.group.name)}`;
    App.modal(title, `
        ${blockerHtml}
        <div style="overflow-x:auto;max-height:50vh">
            <table class="data-table" style="font-size:13px">
                <thead><tr><th>회원</th><th>Cycle별</th><th>합계</th></tr></thead>
                <tbody>${membersHtml}</tbody>
            </table>
        </div>
    `, r.can_distribute && r.members.length ? async () => {
        if (!confirm(`${r.members.length}명에게 지급합니다. 확정하시겠습니까?`)) return false;
        const er = await api('coin_reward_group_distribute', { body: { group_id: groupId } });
        if (!er.success) { App.toast(er.error || er.message, 'error'); return false; }
        App.toast(er.message);
        App.closeModal();
        showRewardGroups(document.querySelector('.coins-container'));
    } : null, { wide: true });
}

async function rgDetail(groupId) {
    const r = await api('coin_reward_group_distribution_detail', { qs: `&group_id=${groupId}` });
    if (!r.success) { App.toast(r.error || r.message, 'error'); return; }
    const rowsHtml = r.distributions.map(d => {
        const bd = Object.entries(d.cycle_breakdown).map(([k,v]) => `${esc(k)}: ${v}`).join(', ');
        return `<tr><td>${esc(d.nickname)}</td><td>${bd}</td><td style="font-weight:700">${d.total_amount}</td></tr>`;
    }).join('');
    App.modal(`지급 내역 — ${esc(r.group.name)}`, `
        <p>지급 시점: ${r.group.distributed_at || '-'} / 담당자: ${esc(r.group.distributor_name || '-')}</p>
        <div style="overflow-x:auto;max-height:60vh">
            <table class="data-table" style="font-size:13px">
                <thead><tr><th>회원</th><th>Cycle별</th><th>합계</th></tr></thead>
                <tbody>${rowsHtml}</tbody>
            </table>
        </div>
    `, null, { wide: true });
}
```

- [ ] **Step 3: CoinApp return 객체에 추가**

파일 끝 `return { ... }` 블록에 새 함수들 등록:
```javascript
return {
    // 기존 ...
    showRewardGroups,
    rgEdit,
    rgDelete,
    rgAttachCycle,
    rgPreview,
    rgDetail,
};
```

- [ ] **Step 4: `/operation/#coins` 진입 시 섹션 렌더링**

`js/admin.js:401`의 `CoinApp.showCycles(coinTab)` 호출을 두 섹션 렌더로 교체:

```javascript
// admin.js:395-406 내부
if (typeof CoinApp !== 'undefined') {
    const coinTab = document.getElementById('tab-coin-cycles');
    if (coinTab) {
        const observer = new MutationObserver(() => {
            if (coinTab.classList.contains('active') && !coinTab.dataset.loaded) {
                coinTab.dataset.loaded = '1';
                // Reward Groups + Cycles 두 섹션을 각자의 컨테이너에 렌더
                coinTab.innerHTML = '<div id="rg-section"></div><div id="cycles-section"></div>';
                CoinApp.showRewardGroups(document.getElementById('rg-section'));
                CoinApp.showCycles(document.getElementById('cycles-section'));
            }
        });
        observer.observe(coinTab, { attributes: true, attributeFilter: ['class'] });
    }
}
```

`coin.js` 내 `rgEdit`/`rgDelete`/`rgAttachCycle`/`rgPreview` 함수에서 `document.querySelector('.coins-container')` 대신 `document.getElementById('rg-section')` 사용하도록 수정.

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/coin.js && git commit -m "feat: 운영자 화면에 Reward Groups 섹션 추가"
```

---

### Task E2: Coin Cycles 테이블에 "리워드 구간" 컬럼 추가

**Files:**
- Modify: `/root/boot-dev/public_html/js/coin.js:36-58` (showCycles의 thead/tbody)
- Modify: `/root/boot-dev/public_html/api/services/coin_cycle.php:12-19` (handleCoinCycles SELECT)

- [ ] **Step 1: 서버 쿼리에 reward_group 정보 조인**

`coin_cycle.php:12-19` `handleCoinCycles`의 SELECT 교체:
```php
    $stmt = $db->query("
        SELECT cc.*,
               rg.name AS reward_group_name,
               (SELECT COUNT(*) FROM member_cycle_coins mcc WHERE mcc.cycle_id = cc.id) AS member_count,
               (SELECT COALESCE(SUM(mcc2.earned_coin), 0) FROM member_cycle_coins mcc2 WHERE mcc2.cycle_id = cc.id) AS total_earned
        FROM coin_cycles cc
        LEFT JOIN reward_groups rg ON cc.reward_group_id = rg.id
        ORDER BY cc.start_date DESC
    ");
```

- [ ] **Step 2: UI 테이블 헤더/로우에 컬럼 추가**

`coin.js:36`의 thead:
```javascript
<thead><tr><th>이름</th><th>기간</th><th>상태</th><th>리워드 구간</th><th>참여자</th><th>총 코인</th><th></th></tr></thead>
```

`coin.js:39-53` tbody 각 행에 `<td>${esc(c.reward_group_name || '-')}</td>` 추가 (상태 다음, 참여자 앞).

빈 상태 메시지 colspan도 `5` → `7`로 조정.

- [ ] **Step 3: 브라우저 확인**

`/operation/#coins` 재방문 → Reward Groups 섹션 + Coin Cycles 컬럼 확인.

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/coin.js public_html/api/services/coin_cycle.php && git commit -m "feat: Cycle 테이블에 리워드 구간 컬럼 추가"
```

---

## Phase F: 11기 → 12기 데이터 마이그

### Task F1: 데이터 마이그 스크립트 작성

**Files:**
- Create: `/root/boot-dev/migrate_split_cycle_11_12.php`

- [ ] **Step 1: 스크립트 작성**

```php
<?php
/**
 * boot.soritune.com - 11기 → 12기 Cycle 분리 + Reward Group 설정 마이그
 *
 * 실행:
 *   php migrate_split_cycle_11_12.php --dry-run
 *   php migrate_split_cycle_11_12.php --execute --cycle11=2 --cycle12-end=2026-05-17 --group-name="11-12기 리워드"
 */

require_once __DIR__ . '/public_html/config.php';

$opts = getopt('', ['dry-run', 'execute', 'cycle11:', 'cycle12-end:', 'group-name:']);
$dryRun   = isset($opts['dry-run']);
$execute  = isset($opts['execute']);
$cycle11  = (int)($opts['cycle11']  ?? 0);
$cycle12End = $opts['cycle12-end'] ?? '';
$groupName = $opts['group-name'] ?? '11-12기 리워드';

if ((!$dryRun && !$execute) || !$cycle11 || !$cycle12End) {
    fwrite(STDERR, "Usage: php migrate_split_cycle_11_12.php --dry-run|--execute --cycle11=ID --cycle12-end=YYYY-MM-DD [--group-name=NAME]\n");
    exit(2);
}

$cycle11End = '2026-04-19';
$cycle12Start = '2026-04-20';

$db = getDB();

echo "=== 11기 → 12기 분리 마이그 ===\n";
echo "  모드: " . ($dryRun ? 'DRY-RUN' : 'EXECUTE') . "\n";
echo "  11기 cycle_id: $cycle11\n";
echo "  12기 기간: $cycle12Start ~ $cycle12End\n";
echo "  Reward Group: $groupName\n\n";

// cycle11 존재 확인
$stmt = $db->prepare("SELECT * FROM coin_cycles WHERE id = ?");
$stmt->execute([$cycle11]);
$c11 = $stmt->fetch();
if (!$c11) { fwrite(STDERR, "cycle11 ($cycle11)을 찾을 수 없습니다.\n"); exit(1); }
echo "  [확인] 11기: {$c11['name']} ({$c11['start_date']}~{$c11['end_date']})\n";

// 12기 end_date 일요일 검증
if ((int)date('w', strtotime($cycle12End)) !== 0) {
    fwrite(STDERR, "cycle12 end_date는 일요일이어야 합니다.\n"); exit(1);
}

// 4/20 이후 coin_logs 분류
$logStmt = $db->prepare("
    SELECT id, reason_type, reason_detail, coin_change, DATE(created_at) AS d
    FROM coin_logs
    WHERE cycle_id = ?
    ORDER BY id
");
$logStmt->execute([$cycle11]);
$allLogs = $logStmt->fetchAll();

$movingLogIds = [];
$ambiguous = [];
$errorsByType = [];

foreach ($allLogs as $log) {
    $rtype = $log['reason_type'];
    $moveIt = false;

    if ($rtype === 'leader_coin') {
        // 4/20 날짜에 기록된 것만 이동 (batch 실행 시점 기준)
        if ($log['d'] >= $cycle12Start) $moveIt = true;
    } elseif (in_array($rtype, ['study_open', 'study_join'])) {
        // reason_detail에서 YYYY-MM-DD 파싱
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $log['reason_detail'] ?? '', $m)) {
            if ($m[1] >= $cycle12Start) $moveIt = true;
        } else {
            $ambiguous[] = $log;
        }
    } elseif (in_array($rtype, ['perfect_attendance', 'hamemmal_bonus', 'cheer_award', 'manual_adjustment', 'reward_distribution'])) {
        // 이번 migration 범위 밖. 4/20 이후에 있으면 운영자 판단 필요
        if ($log['d'] >= $cycle12Start) {
            $errorsByType[$rtype] = ($errorsByType[$rtype] ?? 0) + 1;
        }
    }
    if ($moveIt) $movingLogIds[] = (int)$log['id'];
}

echo "\n[분석]\n";
echo "  총 11기 logs: " . count($allLogs) . "\n";
echo "  12기로 이동 대상: " . count($movingLogIds) . "\n";
echo "  파싱 모호: " . count($ambiguous) . "\n";
echo "  에러 reason_type: " . json_encode($errorsByType, JSON_UNESCAPED_UNICODE) . "\n";

if (count($ambiguous) > 0) {
    echo "\n[에러] reason_detail에서 날짜 파싱 실패한 logs 있음. 중단.\n";
    foreach ($ambiguous as $l) { echo "  - log_id={$l['id']} type={$l['reason_type']} detail={$l['reason_detail']}\n"; }
    exit(1);
}
if (count($errorsByType) > 0) {
    echo "\n[에러] migration 범위 외 reason_type이 4/20 이후에 있음. 운영자 수동 확인 필요.\n";
    exit(1);
}

if ($dryRun) {
    echo "\n=== DRY-RUN 종료 (변경 없음) ===\n";
    exit(0);
}

// EXECUTE
$db->beginTransaction();
try {
    // 1. reward_groups 생성
    $db->prepare("INSERT INTO reward_groups (name) VALUES (?)")->execute([$groupName]);
    $groupId = (int)$db->lastInsertId();
    echo "\n[1] reward_groups id=$groupId 생성\n";

    // 2. 11기 업데이트
    $db->prepare("UPDATE coin_cycles SET end_date=?, reward_group_id=? WHERE id=?")
       ->execute([$cycle11End, $groupId, $cycle11]);
    echo "[2] 11기 end_date=$cycle11End, reward_group_id=$groupId\n";

    // 3. 12기 INSERT
    $db->prepare("INSERT INTO coin_cycles (name, start_date, end_date, reward_group_id) VALUES (?, ?, ?, ?)")
       ->execute(['12기', $cycle12Start, $cycle12End, $groupId]);
    $cycle12 = (int)$db->lastInsertId();
    echo "[3] 12기 생성 id=$cycle12\n";

    // 4. 로그 이관
    if ($movingLogIds) {
        $ph = implode(',', array_fill(0, count($movingLogIds), '?'));
        $params = array_merge([$cycle12], $movingLogIds);
        $db->prepare("UPDATE coin_logs SET cycle_id=? WHERE id IN ($ph)")->execute($params);
        echo "[4] " . count($movingLogIds) . "건 로그 이관 완료\n";
    }

    // 5. 영향 member_id 수집
    $affected = [];
    foreach ($allLogs as $l) $affected[(int)$l['member_id']] = true;
    $affectedIds = array_keys($affected);
    echo "[5] 영향 회원 " . count($affectedIds) . "명\n";

    // 6. 재계산: 각 (member, cycle)의 member_cycle_coins를 coin_logs에서 재계산
    foreach ([$cycle11, $cycle12] as $cid) {
        foreach ($affectedIds as $mid) {
            $r = recalcMemberCycleCoins($db, $mid, $cid);
            if ($r['upserted']) {
                // echo "   m=$mid c=$cid earned={$r['earned']} open={$r['open']} join={$r['join']} leader={$r['leader']}\n";
            }
        }
    }
    echo "[6] member_cycle_coins 재계산 완료\n";

    // 7. sync balance
    foreach ($affectedIds as $mid) syncMemberCoinBalance($db, $mid);
    echo "[7] member_coin_balances 동기화 완료\n";

    $db->commit();
    echo "\n=== 마이그 완료 ===\n";
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "[에러] 롤백: " . $e->getMessage() . "\n");
    exit(1);
}

// ── helper ──
function recalcMemberCycleCoins($db, $memberId, $cycleId) {
    $s1 = $db->prepare("SELECT COALESCE(SUM(coin_change), 0) FROM coin_logs WHERE member_id=? AND cycle_id=?");
    $s1->execute([$memberId, $cycleId]);
    $earned = max(0, (int)$s1->fetchColumn());

    $s2 = $db->prepare("SELECT COUNT(*) FROM coin_logs WHERE member_id=? AND cycle_id=? AND reason_type='study_open' AND coin_change > 0");
    $s2->execute([$memberId, $cycleId]);
    $openCount = (int)$s2->fetchColumn();

    $s3 = $db->prepare("SELECT COUNT(*) FROM coin_logs WHERE member_id=? AND cycle_id=? AND reason_type='study_join' AND coin_change > 0");
    $s3->execute([$memberId, $cycleId]);
    $joinCount = (int)$s3->fetchColumn();

    $s4 = $db->prepare("SELECT COUNT(*) FROM coin_logs WHERE member_id=? AND cycle_id=? AND reason_type='leader_coin' AND coin_change > 0");
    $s4->execute([$memberId, $cycleId]);
    $leaderGranted = (int)$s4->fetchColumn() > 0 ? 1 : 0;

    $s5 = $db->prepare("SELECT COUNT(*) FROM coin_logs WHERE member_id=? AND cycle_id=? AND reason_type='perfect_attendance' AND coin_change > 0");
    $s5->execute([$memberId, $cycleId]);
    $paGranted = (int)$s5->fetchColumn() > 0 ? 1 : 0;

    $s6 = $db->prepare("SELECT COUNT(*) FROM coin_logs WHERE member_id=? AND cycle_id=? AND reason_type='hamemmal_bonus' AND coin_change > 0");
    $s6->execute([$memberId, $cycleId]);
    $hmGranted = (int)$s6->fetchColumn() > 0 ? 1 : 0;

    // used_coin 기존 값 유지
    $s7 = $db->prepare("SELECT used_coin FROM member_cycle_coins WHERE member_id=? AND cycle_id=?");
    $s7->execute([$memberId, $cycleId]);
    $usedExisting = (int)($s7->fetchColumn() ?: 0);

    if ($earned === 0 && $openCount === 0 && $joinCount === 0 && !$leaderGranted && !$paGranted && !$hmGranted && $usedExisting === 0) {
        // 아무것도 없으면 row 생략(삭제는 안 함 — 기존 row 유지)
        return ['upserted' => false];
    }

    $db->prepare("
        INSERT INTO member_cycle_coins
            (member_id, cycle_id, earned_coin, used_coin, study_open_count, study_join_count, leader_coin_granted, perfect_attendance_granted, hamemmal_granted)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            earned_coin = VALUES(earned_coin),
            study_open_count = VALUES(study_open_count),
            study_join_count = VALUES(study_join_count),
            leader_coin_granted = VALUES(leader_coin_granted),
            perfect_attendance_granted = VALUES(perfect_attendance_granted),
            hamemmal_granted = VALUES(hamemmal_granted)
    ")->execute([$memberId, $cycleId, $earned, $usedExisting, $openCount, $joinCount, $leaderGranted, $paGranted, $hmGranted]);

    return ['upserted' => true, 'earned' => $earned, 'open' => $openCount, 'join' => $joinCount, 'leader' => $leaderGranted];
}
```

- [ ] **Step 2: 스크립트 syntax 체크**

```bash
php -l /root/boot-dev/migrate_split_cycle_11_12.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add migrate_split_cycle_11_12.php && git commit -m "feat: 11→12기 분리 마이그 스크립트"
```

---

### Task F2: Dry-run 실행 + 결과 리뷰

- [ ] **Step 1: 11기 cycle id 확인**

```bash
cd /root/boot-dev && source .db_credentials && mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT id, name, start_date, end_date, status FROM coin_cycles ORDER BY start_date;
"
```

결과에서 `11기` row의 `id` 메모. (현재 dev에선 2일 가능성 높음. 실행 시점에 재확인.)

- [ ] **Step 2: 12기 end_date 결정**

운영자에게 확인: 12기를 며칠까지(일요일)로 잡을지. 예: `2026-05-17`.

- [ ] **Step 3: Dry-run 실행**

```bash
cd /root/boot-dev && php migrate_split_cycle_11_12.php --dry-run --cycle11=<id> --cycle12-end=2026-05-17
```

Expected output: 분석 표(총 logs, 이동 대상 수, 에러 0건). 파싱 실패/에러 reason_type이 있으면 중단 메시지.

- [ ] **Step 4: 결과를 사용자에게 보고**

이동 대상 건수와 reason_type 분포를 사용자에게 공유하고 실제 실행 승인 받기.

---

### Task F3: Dev에서 실제 마이그 실행

- [ ] **Step 1: 실제 실행**

```bash
cd /root/boot-dev && php migrate_split_cycle_11_12.php --execute --cycle11=<id> --cycle12-end=2026-05-17
```

Expected: `=== 마이그 완료 ===`.

- [ ] **Step 2: DB 직접 검증**

```bash
cd /root/boot-dev && source .db_credentials && mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
-- 1. 11기/12기 + reward_group 상태
SELECT cc.id, cc.name, cc.start_date, cc.end_date, cc.status, cc.reward_group_id, rg.name AS group_name
  FROM coin_cycles cc
  LEFT JOIN reward_groups rg ON cc.reward_group_id = rg.id
  ORDER BY cc.start_date;

-- 2. 12기로 이관된 로그
SELECT reason_type, COUNT(*), SUM(coin_change) FROM coin_logs
  WHERE cycle_id = (SELECT id FROM coin_cycles WHERE name='12기') GROUP BY reason_type;

-- 3. 회원 총 코인 sanity: member_coin_balances ↔ SUM(member_cycle_coins.earned-used)
SELECT bm.nickname, mcb.current_coin AS bal,
       (SELECT COALESCE(SUM(earned_coin - used_coin), 0) FROM member_cycle_coins WHERE member_id = bm.id) AS sum_mcc
  FROM bootcamp_members bm
  JOIN member_coin_balances mcb ON mcb.member_id = bm.id
  HAVING bal <> sum_mcc;
"
```

Expected:
- 11기 end_date=2026-04-19, reward_group_id 설정됨.
- 12기 row 존재, 같은 reward_group_id.
- 12기에 leader_coin/study_join 로그 존재.
- 3번 쿼리 결과 `0 rows` (mcb와 mcc 합이 일치).

- [ ] **Step 3: UI 확인 (브라우저)**

1. `/operation/#coins` 방문 → Reward Groups 섹션에 "11-12기 리워드" 보임 + 11기/12기 모두 소속.
2. 회원 계정 로그인 → 본인 카드에 `current_reward_group` 브레이크다운 보임 (11기: N, 12기: M).
3. 조장 계정 → 같은 카드 확인.
4. 4/20 체크 다시 해보기 (uncheck → check) → 12기 cycle의 study_join_count 증가 확인.

- [ ] **Step 4: 문제 있으면 `mysqldump` 복구 후 재시도, OK면 다음 단계**

---

### Task F4: dev 커밋/푸시

- [ ] **Step 1: dev 푸시**

```bash
cd /root/boot-dev && git push origin dev
```

- [ ] **Step 2: ⛔ 여기서 멈추고 사용자에게 확인 요청**

사용자에게 알림: "dev 적용 완료. 운영에 반영할지 명시적으로 요청해주세요."

---

## Phase G: Prod 배포 (사용자 명시적 요청 이후)

### Task G1: Prod DB 백업

- [ ] **Step 1: mysqldump**

```bash
cd /root/boot-prod && source .db_credentials && \
  mysqldump -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" > /root/boot-prod/logs/backup_pre_reward_groups_$(date +%Y%m%d_%H%M%S).sql
```

Expected: 백업 파일 생성.

---

### Task G2: main 머지 + prod 코드 pull

- [ ] **Step 1: main 머지 & push**

```bash
cd /root/boot-dev && git checkout main && git merge dev && git push origin main && git checkout dev
```

- [ ] **Step 2: prod pull**

```bash
cd /root/boot-prod && git pull origin main
```

---

### Task G3: Prod 스키마 마이그

- [ ] **Step 1: 실행**

```bash
cd /root/boot-prod && php migrate_reward_groups.php
```

Expected: 3 섹션 모두 완료.

---

### Task G4: Prod 데이터 마이그 dry-run

- [ ] **Step 1: 11기 cycle id 확인**

```bash
cd /root/boot-prod && source .db_credentials && mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id, name FROM coin_cycles WHERE name='11기';"
```

- [ ] **Step 2: Dry-run**

```bash
cd /root/boot-prod && php migrate_split_cycle_11_12.php --dry-run --cycle11=<prod_id> --cycle12-end=<YYYY-MM-DD>
```

사용자에게 결과 보고.

---

### Task G5: Prod 실제 마이그 실행

- [ ] **Step 1: 실행**

```bash
cd /root/boot-prod && php migrate_split_cycle_11_12.php --execute --cycle11=<prod_id> --cycle12-end=<YYYY-MM-DD>
```

Expected: `=== 마이그 완료 ===`.

- [ ] **Step 2: 검증**

Task F3 Step 2의 SELECT 쿼리들을 prod DB에서 실행. 결과가 dev와 동일한 구조여야 함.

---

### Task G6: Prod UI 스모크 테스트

- [ ] **Step 1: 운영자 계정으로 `https://boot.soritune.com/operation/#coins` 확인**

Reward Groups 섹션 노출, 11-12기 리워드 group이 cycle 2개 소속으로 표시되는지.

- [ ] **Step 2: 회원 계정으로 자기 카드 확인**

브레이크다운 노출(11기 N + 12기 M).

- [ ] **Step 3: 운영자에게 알림**

"완료. 회원들에게 '11기 코인 정산은 4/19까지, 이후 활동은 12기 코인으로 적립됨' 안내 필요." 알림 전달.

---

## 스코프 외 (별도 구현 필요 시 별도 plan)

- 회원에게 리워드 지급 알림 (이메일/푸시)
- 회원용 과거 지급 이력 뷰
- 상품/혜택 매핑 (지급된 코인 → 실제 보상)
- reward group N 유연화 (2 외 케이스)

---

## 검증 체크리스트 (완료 전 확인)

- [ ] `migrate_reward_groups.php` 실행 후 두 테이블 + FK 컬럼 존재
- [ ] `syncMemberCoinBalance` 공식 변경 후 기존 회원 잔액 유지
- [ ] `handleCoinChange`가 `cycle_id` 없으면 400 반환, 있으면 `member_cycle_coins` 업데이트
- [ ] Reward Group CRUD/attach/detach 전부 동작
- [ ] Preview에 `can_distribute=false` + `blockers` 채워짐 (cycle 미-closed 시)
- [ ] Distribute가 두 cycle 모두 closed일 때만 성공
- [ ] Distribute 후 `reward_group_distributions`에 회원별 row, `member_cycle_coins.used_coin = earned_coin`, `coin_logs`에 reward_distribution 로그
- [ ] 회원 API 응답에 `current_reward_group` 포함
- [ ] `memberTable.js` 회원 카드에 cycle별 브레이크다운 표시
- [ ] 11→12기 마이그 후 총 잔액(회원별 `member_coin_balances.current_coin`)이 마이그 전과 동일
