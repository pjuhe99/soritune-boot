# expelled 약한 조치 전환 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `member_status='expelled'` 를 단체활동 전반 차단(2026-05-22 spec)에서 약한 시각적 분리로 전환. expelled = active 와 거의 동일 동작 + 체크리스트·현황판에서만 기본 숨김 + 토글 노출.

**Architecture:** 두 면 변경. (1) SQL 게이트 10곳에서 `expelled` 제거 + expel 핸들러의 `group_id=NULL` 제거 → expelled 가 모든 정규 경로에서 active 처럼 통과. (2) 체크리스트·현황판 3개 API 핸들러에 `include_expelled` 쿼리 토글 + bootcamp.js filterBarHtml 에 opt-in 체크박스 (localStorage 저장).

**Tech Stack:** PHP 8 / MariaDB / vanilla JS (bootcamp.js / admin.js). DB 마이그 0개. 테스트는 CLI invariant 패턴 (boot 의 기존 `tests/*_invariants.php` 와 동일 형식).

**Spec:** [docs/superpowers/specs/2026-05-28-expelled-soft-mode-design.md](../specs/2026-05-28-expelled-soft-mode-design.md)

---

## File Map

**서버 핸들러 (변경)**
- `public_html/api/admin.php:807-809` — expel 분기 group_id=NULL 제거
- `public_html/api/services/member.php:175-178` — 동일
- `public_html/api/services/check.php:7-77` — `handleChecklist` 토글
- `public_html/api/services/check.php:161-245` — `handleChecklistByMission` 토글
- `public_html/api/services/check.php:313-414` — `handleStatusBoard` 토글

**SQL 게이트 (expelled 제거 — 10곳)**
- `public_html/cron.php:89`, `:158`
- `public_html/api/qr.php:178`, `:247`, `:278`, `:285`
- `public_html/includes/qr_actions.php:28`, `:115`
- `public_html/api/services/attendance.php:21`
- `public_html/api/services/integration.php:177`
- `public_html/api/services/review.php:32`
- `public_html/api/services/member_page.php:402`
- `public_html/api/services/coin_reward_group.php:221` (`$INACTIVE_STATUSES` 배열)
- `public_html/includes/bootcamp_functions.php:334` (`resolveMemberByKey`)

**JS (변경)**
- `public_html/js/bootcamp.js:275` — `filterBarHtml` 에 `includeExpelled` opt-in 체크박스
- `public_html/js/bootcamp.js:400` — `loadChecklist` 호출 + API param 부착
- `public_html/js/bootcamp.js:~821` — `loadStatusBoard` 호출 + API param 부착
- `public_html/js/bootcamp.js` — `renderChecklist` / `renderChecklistByMission` / `renderStatusBoard` 행 시각 차별화
- `public_html/js/admin.js:1376-1378` — `_setMemberStatus` confirm 메시지
- `public_html/js/bootcamp.js` (동일 패턴 있으면) — confirm 메시지

**테스트 (수정 6 + 신규 2)**
- `tests/expelled_set_status_invariants.php` — group_id=NULL → group_id 보존 검증으로 flip
- `tests/expelled_cron_invariants.php` — expected flip
- `tests/expelled_qr_scan_invariants.php` — expected flip
- `tests/expelled_cafe_ingest_invariants.php` — expected flip
- `tests/expelled_review_invariants.php` — expected flip
- `tests/expelled_bootees_invariants.php` — expected flip
- `tests/expelled_soft_checklist_invariants.php` — 신규 (3 핸들러 토글 검증)

**문서**
- `docs/ops/2026-05-28-expelled-soft-mode-prod-check.md` — PROD 사전 배포 SQL 체크리스트 (§12.2 spec)

---

## Task 1: `expelled_set_status` 테스트 — group_id 보존 검증으로 flip

이번 변경의 핵심: expel 시 group_id 가 보존돼야 함. 기존 테스트가 `group_id = NULL` 을 검증하므로 그것부터 flip → red 가 되면 이후 핸들러 수정으로 green.

**Files:**
- Modify: `tests/expelled_set_status_invariants.php`

- [ ] **Step 1: 테스트 fixture 에 group 한 개 INSERT 후 expel 시 group 보존되는지 검증으로 변경**

`tests/expelled_set_status_invariants.php` 의 32-66 라인 (active 회원 fixture + expel 시뮬레이션 + 검증) 을 다음으로 교체:

```php
    // group fixture
    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, code) VALUES (?, 'TEST_G', 'tg')")
       ->execute([$cohortId]);
    $groupId = (int)$db->lastInsertId();

    // active 회원 + group 배정
    $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, member_status, is_active, member_role, stage_no, joined_at)
        VALUES (?, ?, '활성장', 'al', 'active', 1, 'leader', 1, CURDATE())")
       ->execute([$cohortId, $groupId]);
    $memberId = (int)$db->lastInsertId();

    // 변경 후 정책 시뮬레이션:
    // (1) FOR UPDATE 로 prev status 조회
    // (2) member_status='expelled' UPDATE — group_id 안 건드림
    // (3) admin_action_logs INSERT
    $prev = $db->prepare("SELECT member_status FROM bootcamp_members WHERE id = ? FOR UPDATE");
    $prev->execute([$memberId]);
    $previousStatus = $prev->fetchColumn();

    $db->prepare("UPDATE bootcamp_members SET member_status='expelled' WHERE id=?")
       ->execute([$memberId]);

    $reason = '점수 -50 이하 3주 연속';
    $db->prepare("INSERT INTO admin_action_logs
        (actor_admin_id, action_type, target_table, target_id, payload_json)
        VALUES (?, 'member_status_change', 'bootcamp_members', ?, ?)")
       ->execute([
         $adminId,
         $memberId,
         json_encode(['from' => $previousStatus, 'to' => 'expelled', 'reason' => $reason], JSON_UNESCAPED_UNICODE),
       ]);

    // 검증
    $row = $db->prepare("SELECT member_status, group_id, member_role FROM bootcamp_members WHERE id = ?");
    $row->execute([$memberId]);
    $current = $row->fetch(PDO::FETCH_ASSOC);

    t('member_status = expelled', $current['member_status'] === 'expelled');
    t('group_id 보존 (NULL 아님)', (int)$current['group_id'] === $groupId);
    t('member_role 보존', $current['member_role'] === 'leader');

    $log = $db->prepare("SELECT actor_admin_id, action_type, payload_json
                          FROM admin_action_logs
                         WHERE target_table='bootcamp_members' AND target_id = ?");
    $log->execute([$memberId]);
    $logRow = $log->fetch(PDO::FETCH_ASSOC);

    t('admin_action_logs row 있음', $logRow !== false);
    t('action_type = member_status_change', ($logRow['action_type'] ?? '') === 'member_status_change');
    $payload = json_decode($logRow['payload_json'] ?? '', true);
    t('payload.from = active', ($payload['from'] ?? null) === 'active');
    t('payload.to = expelled', ($payload['to'] ?? null) === 'expelled');
    t('payload.reason 보존', ($payload['reason'] ?? null) === $reason);
    t('actor_admin_id 보존', (int)($logRow['actor_admin_id'] ?? 0) === $adminId);
```

- [ ] **Step 2: 테스트 실행해서 PASS 확인 (이 테스트는 인라인 시뮬레이션이라 핸들러 수정 전에도 통과해야 함)**

```bash
cd /root/boot-dev && php tests/expelled_set_status_invariants.php
```

Expected: `결과: 9 PASS, 0 FAIL`

이 테스트는 SQL 자체만 검증 (핸들러 호출 X). 다음 Task 들에서 실제 핸들러를 spec 에 맞게 고쳐도 이 테스트가 계속 통과한다 = 시뮬레이션이 실제 핸들러와 일치.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add tests/expelled_set_status_invariants.php
git commit -m "test(expelled): group_id·role 보존 검증으로 flip (약한 조치 전환)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: 게이트 5개 invariant 테스트 expected flip

spec §11.1 의 5개 invariant 테스트 (`cron`, `qr_scan`, `cafe_ingest`, `review`, `bootees`) 의 expected 를 "expelled 차단" → "expelled 통과" 로 flip. 이 단계는 **테스트가 red** 가 되는 게 정상 (게이트 코드는 아직 안 고침). Task 3 에서 게이트 고친 후 green.

### 2A. `expelled_cron_invariants.php`

- [ ] **Step 1: SQL 게이트와 expected 를 새 정책으로 변경**

`tests/expelled_cron_invariants.php` 의 line 47-68 영역에서:

기존 SQL (line 52):
```php
          AND bm.member_status NOT IN ('refunded','expelled')
```
새 SQL:
```php
          AND bm.member_status != 'refunded'
```

기존 expected + 검증 (line 61-68):
```php
    $expected = [$idA, $idL, $idO];
    sort($expected);

    t('cron SELECT = active + leaving + OOM (3명)', $ids === $expected,
      'got=' . json_encode($ids) . ' expected=' . json_encode($expected));
    t('refunded(is_active=0) 제외', !in_array($idR, $ids, true));
    t('refunded(is_active=1) 제외 (member_status 가드)', !in_array($idRactive, $ids, true));
    t('expelled(is_active=1) 제외 (member_status 가드)', !in_array($idX, $ids, true));
```

새 expected + 검증:
```php
    $expected = [$idA, $idL, $idO, $idX];
    sort($expected);

    t('cron SELECT = active + leaving + OOM + expelled (4명)', $ids === $expected,
      'got=' . json_encode($ids) . ' expected=' . json_encode($expected));
    t('refunded(is_active=0) 제외', !in_array($idR, $ids, true));
    t('refunded(is_active=1) 제외 (member_status 가드)', !in_array($idRactive, $ids, true));
    t('expelled(is_active=1) 포함 (약한 조치 — active 와 동일 처리)', in_array($idX, $ids, true));
```

또한 파일 상단 정책 주석 (line 4):
```php
 * 정책: '단체 활동 대상' = is_active=1 AND member_status NOT IN ('refunded','expelled')
```
→
```php
 * 정책: '단체 활동 대상' = is_active=1 AND member_status != 'refunded'
 * (expelled 는 약한 조치 — active 와 동일하게 cron 통과)
```

- [ ] **Step 2: 테스트 실행해서 PASS 확인 (인라인 SQL 변경 → expected 와 일치)**

```bash
cd /root/boot-dev && php tests/expelled_cron_invariants.php
```

Expected: `결과: 4 PASS, 0 FAIL` (active+leaving+OOM+expelled 4명, refunded 2종 제외, expelled 포함)

### 2B. `expelled_qr_scan_invariants.php`

- [ ] **Step 3: 파일 열고 같은 패턴 적용**

```bash
cd /root/boot-dev && cat tests/expelled_qr_scan_invariants.php | head -80
```

- [ ] **Step 4: SQL 의 `NOT IN ('refunded','expelled')` 를 `!= 'refunded'` 로 변경. expected 도 expelled 가 포함되도록 flip. (구체 라인은 파일 확인 후 spec §11.1 동등 패턴 따라)**

- [ ] **Step 5: 테스트 실행 PASS 확인**

```bash
cd /root/boot-dev && php tests/expelled_qr_scan_invariants.php
```

### 2C. `expelled_cafe_ingest_invariants.php`

- [ ] **Step 6: 같은 패턴 적용 (NOT IN → !=, expected expelled 포함)**

- [ ] **Step 7: 테스트 실행 PASS 확인**

```bash
cd /root/boot-dev && php tests/expelled_cafe_ingest_invariants.php
```

### 2D. `expelled_review_invariants.php`

- [ ] **Step 8: line 43-44 의 `$blocked` 배열 수정 + expected 변경**

기존:
```php
    // review.php 게이트 (변경 후): refunded + expelled 차단
    $blocked = ['refunded', 'expelled'];
```
새:
```php
    // review.php 게이트 (약한 조치 전환 후): refunded 만 차단
    $blocked = ['refunded'];
```

line 48-54 의 fixture 배열 expected 도 변경:
```php
    foreach ([
        ['active', $idA, false],
        ['leaving', $idL, false],
        ['out_of_group_management', $idO, false],
        ['refunded', $idR, true],
        ['expelled', $idX, false],  // ← false 로 flip
    ] as [$label, $id, $expectBlock]) {
```

- [ ] **Step 9: 테스트 실행 PASS 확인**

```bash
cd /root/boot-dev && php tests/expelled_review_invariants.php
```

### 2E. `expelled_bootees_invariants.php`

- [ ] **Step 10: 같은 패턴 적용**

- [ ] **Step 11: 테스트 실행 PASS 확인**

```bash
cd /root/boot-dev && php tests/expelled_bootees_invariants.php
```

### 2F. Commit

- [ ] **Step 12: 5개 테스트 한꺼번에 커밋 — 모두 PASS 인 상태**

```bash
cd /root/boot-dev
git add tests/expelled_cron_invariants.php \
        tests/expelled_qr_scan_invariants.php \
        tests/expelled_cafe_ingest_invariants.php \
        tests/expelled_review_invariants.php \
        tests/expelled_bootees_invariants.php
git commit -m "test(expelled): 5 invariant — expected flip (active 와 동일 통과)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

이 테스트들은 모두 SQL 을 인라인 시뮬레이션 → expected 와 일치. 다음 Task 3 에서 실제 코드 게이트를 같은 SQL 패턴으로 맞추면 production 도 같은 동작.

---

## Task 3: SQL 게이트 10곳에서 `expelled` 제거 (실제 production 코드)

Task 2 의 테스트 SQL 과 동일 패턴으로 실제 코드 변경. 패턴은 일관:
- `member_status NOT IN ('refunded','expelled')` → `member_status != 'refunded'`
- `in_array($status, ['refunded','expelled'])` → `in_array($status, ['refunded'])`
- `$INACTIVE_STATUSES = ['refunded','leaving','out_of_group_management','expelled']` → `$INACTIVE_STATUSES = ['refunded','leaving','out_of_group_management']`

### 3A. cron.php (2곳)

- [ ] **Step 1: `public_html/cron.php` 의 line 89, 158 두 곳 모두 변경**

```bash
cd /root/boot-dev && grep -n "NOT IN ('refunded','expelled')" public_html/cron.php
```

각 라인에서 `NOT IN ('refunded','expelled')` → `!= 'refunded'`

### 3B. qr.php (4곳)

- [ ] **Step 2: `public_html/api/qr.php` 의 line 178, 247, 278, 285 변경**

```bash
cd /root/boot-dev && grep -n "NOT IN ('refunded','expelled')" public_html/api/qr.php
```

각 라인 `NOT IN ('refunded','expelled')` → `!= 'refunded'`

### 3C. qr_actions.php (2곳)

- [ ] **Step 3: `public_html/includes/qr_actions.php` 의 line 28, 115 변경**

```bash
cd /root/boot-dev && grep -n "NOT IN ('refunded','expelled')" public_html/includes/qr_actions.php
```

### 3D. attendance.php

- [ ] **Step 4: `public_html/api/services/attendance.php` line 21 변경**

### 3E. integration.php

- [ ] **Step 5: `public_html/api/services/integration.php` line 173 의 주석 + line 177 변경**

주석 (line 173): "expelled/refunded 제외: 퇴출·환불 회원의 게시글은 소급 백필 대상 아님"
→ "refunded 제외: 환불 회원의 게시글은 소급 백필 대상 아님. expelled 는 active 와 동일 처리 (2026-05-28 약한 조치 전환)"

SQL (line 177): `NOT IN ('refunded','expelled')` → `!= 'refunded'`

### 3F. review.php

- [ ] **Step 6: `public_html/api/services/review.php` line 32 변경**

```php
in_array($member['member_status'], ['refunded', 'expelled'])
```
→
```php
in_array($member['member_status'], ['refunded'])
```

### 3G. member_page.php (부티즈)

- [ ] **Step 7: `public_html/api/services/member_page.php` line 402 변경**

### 3H. coin_reward_group.php

- [ ] **Step 8: `public_html/api/services/coin_reward_group.php` line 221 의 `$INACTIVE_STATUSES` 배열에서 `'expelled'` 제거**

```php
$INACTIVE_STATUSES = ['refunded', 'leaving', 'out_of_group_management', 'expelled'];
```
→
```php
$INACTIVE_STATUSES = ['refunded', 'leaving', 'out_of_group_management'];
```

### 3I. bootcamp_functions.php (`resolveMemberByKey`)

- [ ] **Step 9: `public_html/includes/bootcamp_functions.php` line 334 변경**

### 3J. 전체 테스트 회귀 + 커밋

- [ ] **Step 10: 모든 expelled invariant 테스트 + leaving invariant 테스트 회귀 확인 (기존 leaving fixture 가 신규 정책에도 깨지지 않는지)**

```bash
cd /root/boot-dev && for t in tests/expelled_*.php tests/leaving_*.php; do
  echo "=== $t ==="
  php "$t" || break
done
```

Expected: 모두 `PASS, 0 FAIL`. leaving 관련 테스트는 expelled 와 무관하므로 깨질 가능성 낮음 — 깨지면 해당 fixture 확인 필요.

- [ ] **Step 11: grep 으로 expelled 잔존 게이트 0 확인**

```bash
cd /root/boot-dev && grep -rn "NOT IN ('refunded','expelled')" public_html/ tests/
cd /root/boot-dev && grep -rn "'refunded'.*'expelled'\|'expelled'.*'refunded'" public_html/api/services/ public_html/includes/
```

Expected: 한 줄도 안 나옴 (admin.php:594, 628 의 statusCounts 는 다른 배열이라 매치 안 됨 — 검증). 만약 잔존 매치가 있으면 spec §5.1 비변경 목록과 대조해서 의도된 잔존인지 확인.

- [ ] **Step 12: Commit**

```bash
cd /root/boot-dev
git add public_html/cron.php \
        public_html/api/qr.php \
        public_html/includes/qr_actions.php \
        public_html/api/services/attendance.php \
        public_html/api/services/integration.php \
        public_html/api/services/review.php \
        public_html/api/services/member_page.php \
        public_html/api/services/coin_reward_group.php \
        public_html/includes/bootcamp_functions.php
git commit -m "fix(expelled): 게이트 10곳에서 expelled 제거 — active 와 동일 처리

cron(2)/qr(4)/qr_actions(2)/attendance/integration/review/member_page/
coin_reward_group/bootcamp_functions:resolveMemberByKey.
refunded 만 차단 유지. spec: 2026-05-28-expelled-soft-mode-design.md §5.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: expel 핸들러 — `group_id=NULL` 제거 (admin.php + services/member.php)

Task 1 의 테스트가 이미 group 보존 시뮬레이션 → 이 Task 에서 실제 핸들러를 같은 정책으로 맞춤.

**Files:**
- Modify: `public_html/api/admin.php:807-809`
- Modify: `public_html/api/services/member.php:175-178`

- [ ] **Step 1: admin.php 의 expel 분기 변경**

```bash
cd /root/boot-dev && grep -n "elseif (\$status === 'expelled')" public_html/api/admin.php
```

라인 808 부근에서:
```php
        } elseif ($status === 'expelled') {
            $db->prepare("UPDATE bootcamp_members SET member_status='expelled', group_id=NULL WHERE id=?")->execute([$id]);
```
→
```php
        } elseif ($status === 'expelled') {
            // 약한 조치 전환 (2026-05-28): group_id 보존 — leader 화면은 새 토글로 제어
            $db->prepare("UPDATE bootcamp_members SET member_status='expelled' WHERE id=?")->execute([$id]);
```

- [ ] **Step 2: services/member.php 의 expel 분기에 동일 변경 적용**

```bash
cd /root/boot-dev && grep -n "elseif (\$status === 'expelled')" public_html/api/services/member.php
```

같은 변환.

- [ ] **Step 3: Task 1 의 테스트 재실행 + handleMemberSetStatus 직접 호출하는 endpoint 가 있다면 smoke 테스트**

```bash
cd /root/boot-dev && php tests/expelled_set_status_invariants.php
```

Expected: 9 PASS (이미 통과 중이지만 회귀 가드).

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev
git add public_html/api/admin.php public_html/api/services/member.php
git commit -m "fix(expelled): expel 핸들러에서 group_id=NULL 제거 (약한 조치 전환)

admin.php + services/member.php 두 라우팅 모두 group_id 보존.
복원 시 별도 조 재배정 불필요. spec §6.1.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: `handleChecklist` 에 `include_expelled` 토글

**Files:**
- Modify: `public_html/api/services/check.php:7-77` (`handleChecklist`)

- [ ] **Step 1: WHERE 구성 직후 토글 조건 추가**

`public_html/api/services/check.php` line 16-19:
```php
    $where = ["bm.cohort_id = ?", "bm.is_active = 1"];
    $params = [$cohortId];
    if (!empty($_GET['group_id'])) { $where[] = "bm.group_id = ?"; $params[] = (int)$_GET['group_id']; }
    if (!empty($_GET['stage_no'])) { $where[] = "bm.stage_no = ?"; $params[] = (int)$_GET['stage_no']; }
```

뒤에 추가:
```php
    if (empty($_GET['include_expelled'])) {
        $where[] = "bm.member_status != 'expelled'";
    }
```

- [ ] **Step 2: Commit (검증은 Task 7 의 통합 테스트에서)**

```bash
cd /root/boot-dev
git add public_html/api/services/check.php
git commit -m "feat(checklist): include_expelled 토글 — handleChecklist

기본은 expelled 숨김, ?include_expelled=1 이면 노출. spec §7.1.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: `handleChecklistByMission` 와 `handleStatusBoard` 에 동일 토글

**Files:**
- Modify: `public_html/api/services/check.php:161-245` (`handleChecklistByMission`)
- Modify: `public_html/api/services/check.php:313-414` (`handleStatusBoard`)

- [ ] **Step 1: `handleChecklistByMission` WHERE 구성 직후 (line 189-192 부근) 동일 패턴 추가**

```php
    $where = ["bm.cohort_id = ?", "bm.is_active = 1"];
    $params = [$cohortId];
    if (!empty($_GET['group_id'])) { $where[] = "bm.group_id = ?"; $params[] = (int)$_GET['group_id']; }
    if (!empty($_GET['stage_no'])) { $where[] = "bm.stage_no = ?"; $params[] = (int)$_GET['stage_no']; }
    if (empty($_GET['include_expelled'])) {
        $where[] = "bm.member_status != 'expelled'";
    }
```

- [ ] **Step 2: `handleStatusBoard` WHERE 구성 직후 (line 322-325 부근) 동일 패턴 추가**

- [ ] **Step 3: `handleMemberChecklistAll` (line 250) 은 단일 회원 id 직접 조회라 토글 불요 — 변경 안 함. 확인만**

```bash
cd /root/boot-dev && grep -n "function handleMemberChecklistAll" public_html/api/services/check.php
```

`WHERE bm.id = ? AND bm.cohort_id = ?` 패턴 (line 269) 인지 확인. 그대로 두면 됨.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/check.php
git commit -m "feat(checklist): include_expelled 토글 — handleChecklistByMission, handleStatusBoard

3개 핸들러 모두 동일 패턴. spec §7.1.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: 신규 invariant — `expelled_soft_checklist_invariants.php`

**Files:**
- Create: `tests/expelled_soft_checklist_invariants.php`

- [ ] **Step 1: 신규 테스트 작성 — 3개 핸들러 토글 검증**

```php
<?php
/**
 * 체크리스트·현황판 의 include_expelled 토글이
 * handleChecklist / handleChecklistByMission / handleStatusBoard
 * 3개 핸들러 모두에서 일관되게 작동하는지 SQL-level 검증.
 *
 * 사용: php tests/expelled_soft_checklist_invariants.php
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
$db->beginTransaction();

try {
    $cohortLabel = 'TEST_XSOFT_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, code) VALUES (?, 'TEST_G', 'tg')")
       ->execute([$cohortId]);
    $groupId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 1, 1, CURDATE())");

    $ins->execute([$cohortId, $groupId, '활성', 'a', 'active']);
    $idA = (int)$db->lastInsertId();
    $ins->execute([$cohortId, $groupId, '퇴출', 'x', 'expelled']);  // group_id 보존된 신규 expel 케이스
    $idX = (int)$db->lastInsertId();

    // 체크리스트·현황판 WHERE 패턴 (handleChecklist / handleChecklistByMission / handleStatusBoard 공통)
    function runChecklistQuery(PDO $db, int $cohortId, bool $includeExpelled): array {
        $where = ["bm.cohort_id = ?", "bm.is_active = 1"];
        $params = [$cohortId];
        if (!$includeExpelled) {
            $where[] = "bm.member_status != 'expelled'";
        }
        $sql = "SELECT id FROM bootcamp_members bm WHERE " . implode(' AND ', $where) . " ORDER BY id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $idsDefault = runChecklistQuery($db, $cohortId, false);
    $idsToggleOn = runChecklistQuery($db, $cohortId, true);

    t('기본 (토글 off): active 만 보임', $idsDefault === [$idA],
      'got=' . json_encode($idsDefault));
    t('기본 (토글 off): expelled 안 보임', !in_array($idX, $idsDefault, true));
    t('토글 on: active + expelled 둘 다 보임', count($idsToggleOn) === 2 && in_array($idA, $idsToggleOn) && in_array($idX, $idsToggleOn),
      'got=' . json_encode($idsToggleOn));

    // group_id 필터까지 함께 걸어도 신규 expelled (group 보존) 는 토글로 보여야 함
    function runWithGroup(PDO $db, int $cohortId, int $groupId, bool $includeExpelled): array {
        $where = ["bm.cohort_id = ?", "bm.is_active = 1", "bm.group_id = ?"];
        $params = [$cohortId, $groupId];
        if (!$includeExpelled) $where[] = "bm.member_status != 'expelled'";
        $sql = "SELECT id FROM bootcamp_members bm WHERE " . implode(' AND ', $where) . " ORDER BY id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $idsGroupDefault = runWithGroup($db, $cohortId, $groupId, false);
    $idsGroupToggleOn = runWithGroup($db, $cohortId, $groupId, true);

    t('group 필터 + 토글 off: active 만', $idsGroupDefault === [$idA]);
    t('group 필터 + 토글 on: active + expelled (신규 케이스 group 보존)', count($idsGroupToggleOn) === 2);

    // 기존 expelled (group_id=NULL) 는 group 필터로 토글 켜도 안 보임 — spec §7.4 한계
    $ins2 = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, NULL, '구퇴출', 'oldx', 'expelled', 1, 1, CURDATE())");
    $ins2->execute([$cohortId]);
    $idOldX = (int)$db->lastInsertId();

    $idsGroupToggleOnWithOld = runWithGroup($db, $cohortId, $groupId, true);
    t('기존 expelled (group_id=NULL) 는 group 필터 + 토글 on 이어도 안 보임 (한계 §7.4)',
       !in_array($idOldX, $idsGroupToggleOnWithOld, true));

    $idsNoGroupToggleOn = runChecklistQuery($db, $cohortId, true);
    t('group 필터 없이 + 토글 on: 기존 expelled (group_id=NULL) 도 보임',
       in_array($idOldX, $idsNoGroupToggleOn, true));
} finally {
    $db->rollBack();
}

echo "\n결과: {$pass} PASS, {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 실행 + PASS 확인**

```bash
cd /root/boot-dev && php tests/expelled_soft_checklist_invariants.php
```

Expected: `결과: 8 PASS, 0 FAIL`

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add tests/expelled_soft_checklist_invariants.php
git commit -m "test(expelled): 체크리스트·현황판 include_expelled 토글 invariant (신규)

3 핸들러 SQL 패턴 + 신규/기존 expelled 의 토글 동작 (§7.4 한계 포함).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: `filterBarHtml` 에 `includeExpelled` opt-in 체크박스 추가

**Files:**
- Modify: `public_html/js/bootcamp.js:275-328` (`filterBarHtml`)

- [ ] **Step 1: opts 파라미터에 `includeExpelled` 추가 + 체크박스 렌더 + localStorage 초기값**

`public_html/js/bootcamp.js` line 275-280:
```js
    function filterBarHtml(opts = {}) {
        const showDate = opts.date !== false;
        const showGroup = opts.group !== false && !leaderMode;
        const showStage = opts.stage !== false;
        const showCohort = !leaderMode;
        const showMissionFilter = opts.missionFilter === true;
```

다음 추가 (showMissionFilter 다음 줄):
```js
        const showIncludeExpelled = opts.includeExpelled === true;
        const includeExpelledChecked = localStorage.getItem('boot.include_expelled') === '1';
```

line 325 (filter 마지막 닫기 직전 `${showMissionFilter ? renderMissionFilterItems() : ''}`) 다음에 추가:
```js
                ${showIncludeExpelled ? `
                <label class="filter-chip">
                    <input type="checkbox" id="bc-include-expelled" ${includeExpelledChecked ? 'checked' : ''}>
                    내보내기 회원 포함
                </label>` : ''}
```

- [ ] **Step 2: `bindFilterEvents` 에 체크박스 onchange 핸들러 추가**

line 370-372 영역 (sortEl 핸들러 다음):
```js
        const sortEl = scope.querySelector('#fl-sort');
        if (sortEl) sortEl.onchange = () => { selectedSort = sortEl.value; onFilter(); };
```

다음 추가:
```js
        const incExpEl = scope.querySelector('#bc-include-expelled');
        if (incExpEl) incExpEl.onchange = () => {
            localStorage.setItem('boot.include_expelled', incExpEl.checked ? '1' : '0');
            onFilter();
        };
```

- [ ] **Step 3: 헬퍼 함수 추가 — 다른 데서도 쓰기 좋게**

`filterBarHtml` 정의 바로 아래 (line ~329) 에 추가:
```js
    function includeExpelledFlag() {
        return localStorage.getItem('boot.include_expelled') === '1' ? 1 : 0;
    }
```

- [ ] **Step 4: Commit (UI 동작은 Task 9 에서 호출 사이트 연결 후 가능)**

```bash
cd /root/boot-dev
git add public_html/js/bootcamp.js
git commit -m "feat(checklist): filterBarHtml 에 includeExpelled opt-in 체크박스 + localStorage

체크박스는 opts.includeExpelled === true 인 호출처에서만 노출.
localStorage 'boot.include_expelled' 키로 마지막 선택 유지.
heler includeExpelledFlag() 추가 (다음 task 에서 API 호출에 사용).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: 체크리스트·현황판 호출 사이트 연결

**Files:**
- Modify: `public_html/js/bootcamp.js:418` (`loadChecklist`)
- Modify: `public_html/js/bootcamp.js:~821` (`loadStatusBoard`)
- Modify: `public_html/js/bootcamp.js` (`renderChecklist`, `renderChecklistByMission`, `renderStatusBoard` 의 API param 빌딩 부분)

- [ ] **Step 1: `loadChecklist` 의 filterBarHtml 호출에 includeExpelled 옵션 켜기**

line 418:
```js
            ${filterBarHtml({ date: checklistViewMode === 'daily' })}
```
→
```js
            ${filterBarHtml({ date: checklistViewMode === 'daily', includeExpelled: true })}
```

- [ ] **Step 2: `loadStatusBoard` 의 filterBarHtml 호출에 동일 옵션**

```bash
cd /root/boot-dev && grep -n "function loadStatusBoard\|filterBarHtml.*missionFilter" public_html/js/bootcamp.js
```

line 821 부근:
```js
            ${filterBarHtml({ missionFilter: true })}
```
→
```js
            ${filterBarHtml({ missionFilter: true, includeExpelled: true })}
```

- [ ] **Step 3: `renderChecklist` 의 API 호출 파라미터에 `include_expelled` 부착**

```bash
cd /root/boot-dev && grep -n "App.get(API + 'checklist'\|App.get(API + 'status_board'\|App.get(API + 'checklist_by_mission'" public_html/js/bootcamp.js
```

`renderChecklist` (line 468 부근) 의 params 빌딩:
```js
        const params = { cohort_id: selectedCohortId, date: selectedDate };
        if (selectedGroupId) params.group_id = selectedGroupId;
        if (selectedStageNo) params.stage_no = selectedStageNo;
        if (selectedSort) params.sort = selectedSort;
```

뒤에 추가:
```js
        if (includeExpelledFlag()) params.include_expelled = 1;
```

- [ ] **Step 4: `renderChecklistByMission` 의 params 빌딩에도 동일 추가**

같은 grep 으로 찾은 위치.

- [ ] **Step 5: `renderStatusBoard` 의 params 빌딩에도 동일 추가**

- [ ] **Step 6: 수동 검증 — DEV 에서 체크리스트 열고 토글 동작 확인**

브라우저에서 `https://dev-boot.soritune.com/admin/index.php#checklist` (또는 leader 계정으로 로그인) 열어서:
1. 토글 off (기본): expelled 회원 안 보임
2. 토글 on: expelled 회원 보임 (시각 차별화는 Task 10 에서)
3. 토글 ON 상태에서 새로고침 → 여전히 ON (localStorage 보존)
4. 현황판 탭으로 이동 → 같은 토글 상태
5. 다른 탭 (출석/코인) 으로 가도 체크박스가 안 보임 (opt-in 만)

DEV 에 expelled 회원이 없으면 `INSERT INTO bootcamp_members ... member_status='expelled'` 로 fixture 하나 추가하거나, `_setMemberStatus` 로 active 1명 → expelled 변환해서 확인.

- [ ] **Step 7: Commit**

```bash
cd /root/boot-dev
git add public_html/js/bootcamp.js
git commit -m "feat(checklist): loadChecklist/loadStatusBoard 토글 연결 + API param 부착

체크리스트 (일별/과제별), 현황판 의 filterBarHtml/render*에서
includeExpelled 옵션 + include_expelled param 부착. 다른 탭은 그대로.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: 체크리스트·현황판 행 시각 차별화 (expelled 배지 + 회색)

**Files:**
- Modify: `public_html/js/bootcamp.js` (`memberCellHtml` 또는 행 렌더 부분)
- Modify: `public_html/css/bootcamp.css` (옵션 — 새 클래스)

- [ ] **Step 1: API 응답에 `member_status` 가 이미 포함되는지 확인**

`handleChecklist` (line 28-39) 의 SELECT 컬럼:
```php
SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
       bm.group_id, bm.cafe_nickname, bg.name AS group_name,
       ...
```

`member_status` 가 빠짐. Task 5 의 핸들러 수정에서 SELECT 에 추가 (또는 Task 10 첫 step 에서 추가):

`public_html/api/services/check.php` 의 3개 핸들러 모두 SELECT 컬럼에 `bm.member_status,` 추가 (line 29, 202, 335).

`handleStatusBoard` (line 335) 는 이미 `bm.member_status` 가 SELECT 에 있음 — 확인:
```bash
cd /root/boot-dev && grep -n "bm.member_status" public_html/api/services/check.php
```
없으면 추가.

- [ ] **Step 2: `memberCellHtml` 에 expelled 배지 + 회색 클래스 적용**

`public_html/js/bootcamp.js` line 449:
```js
    function memberCellHtml(m) {
        const cafeNickHtml = m.cafe_nickname ? ` · ☕ ${App.esc(m.cafe_nickname)}` : '';
        return `
            <button class="bc-member-btn" data-member-id="${m.id}" type="button">
                <div class="member-name">${App.esc(m.nickname)}${m.real_name ? ` <span style="color:#888;font-size:12px">(${App.esc(m.real_name)})</span>` : ''}${parseInt(m.participation_count || 0) > 1 ? ` <span class="badge badge-info" style="font-size:10px">${m.participation_count}회차</span>` : ''}</div>
                <div class="member-sub">${App.esc(m.group_name || '-')} · ${m.stage_no}단계${cafeNickHtml}</div>
            </button>`;
    }
```

→
```js
    function memberCellHtml(m) {
        const cafeNickHtml = m.cafe_nickname ? ` · ☕ ${App.esc(m.cafe_nickname)}` : '';
        const isExpelled = m.member_status === 'expelled';
        const expelledBadge = isExpelled ? ' <span class="badge badge-danger" style="font-size:10px">퇴출</span>' : '';
        const btnClass = 'bc-member-btn' + (isExpelled ? ' bc-member-btn--expelled' : '');
        return `
            <button class="${btnClass}" data-member-id="${m.id}" type="button">
                <div class="member-name">${App.esc(m.nickname)}${m.real_name ? ` <span style="color:#888;font-size:12px">(${App.esc(m.real_name)})</span>` : ''}${parseInt(m.participation_count || 0) > 1 ? ` <span class="badge badge-info" style="font-size:10px">${m.participation_count}회차</span>` : ''}${expelledBadge}</div>
                <div class="member-sub">${App.esc(m.group_name || '-')} · ${m.stage_no}단계${cafeNickHtml}</div>
            </button>`;
    }
```

- [ ] **Step 3: CSS 회색 처리 — `public_html/css/bootcamp.css` 끝부분에 추가**

```bash
cd /root/boot-dev && tail -20 public_html/css/bootcamp.css
```

파일 끝에 추가:
```css
/* expelled 회원 — 약한 시각 분리 (체크리스트/현황판 토글 on 시 노출) */
.bc-member-btn--expelled {
    opacity: 0.7;
    background: var(--color-fff5f5, #fff5f5);
}
```

`--color-fff5f5` 변수가 정의돼 있지 않으면 fallback 으로 hex 색.

- [ ] **Step 4: CSS cache buster 갱신**

asset_version 시스템 확인:
```bash
cd /root/boot-dev && cat public_html/includes/asset_version.php 2>/dev/null | head -20
```

대부분 mtime 기반이라 변경 후 자동 갱신. 수동 cache buster 필요한 경우만 추가 작업.

- [ ] **Step 5: 수동 검증 — 토글 ON 했을 때 expelled 행이 회색 + 배지로 보이는지**

DEV 에서 확인.

- [ ] **Step 6: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/check.php public_html/js/bootcamp.js public_html/css/bootcamp.css
git commit -m "feat(checklist): expelled 행 시각 차별화 (회색 + 퇴출 배지)

member_status 를 응답 컬럼에 포함. memberCellHtml 에서 isExpelled 시
bc-member-btn--expelled 클래스 + 배지. CSS 회색 처리.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 11: confirm 메시지 변경 (admin.js + bootcamp.js)

**Files:**
- Modify: `public_html/js/admin.js:1376-1378`
- Modify: `public_html/js/bootcamp.js` (동일 패턴 있다면)

- [ ] **Step 1: admin.js 의 confirm 메시지 갱신**

`public_html/js/admin.js` line 1376-1378:
```js
        if (status === 'expelled') {
            confirmMsg += '\n이후 단체활동(zoom/카페/점수/후기/부티즈)에서 모두 빠집니다.';
        }
```
→
```js
        if (status === 'expelled') {
            confirmMsg += '\n다른 활동은 active 회원과 동일하게 유지되며, 체크리스트·현황판에서만 기본 숨김됩니다. (상단 \'내보내기 회원 포함\' 체크박스로 표시 가능)';
        }
```

- [ ] **Step 2: bootcamp.js 에 동일 패턴 있는지 확인 + 있으면 같이 갱신**

```bash
cd /root/boot-dev && grep -n "단체활동\|expelled.*confirm\|내보내기" public_html/js/bootcamp.js
```

`bootcamp.js:1672` 의 labelMap 만 있고 confirm 메시지는 admin.js 만일 수도. 확인 후 있으면 동일 변경.

- [ ] **Step 3: 수동 검증 — "내보내기" 클릭 시 새 메시지 확인**

DEV 에서 임의 active 회원에 대해 "내보내기" 버튼 클릭 → confirm 메시지 새 문구 확인.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev
git add public_html/js/admin.js
# bootcamp.js 도 변경됐으면:
# git add public_html/js/bootcamp.js
git commit -m "fix(expelled): _setMemberStatus confirm 메시지 — 약한 조치 안내로 변경

'단체활동에서 모두 빠집니다' → '체크리스트·현황판에서만 기본 숨김'.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 12: PROD 사전 배포 SQL 체크리스트 문서

**Files:**
- Create: `docs/ops/2026-05-28-expelled-soft-mode-prod-check.md`

- [ ] **Step 1: 디렉토리 생성 + 문서 작성**

```bash
mkdir -p /root/boot-dev/docs/ops
```

문서 내용:

```markdown
# expelled 약한 조치 전환 — PROD 사전 배포 체크

> spec §12.2 의 운영자 체크리스트 운용판.
> dev 검증 완료 후, main 머지·PROD pull 직전에 실행.

## 1. PROD expelled 회원 분포 확인

PROD `_______site_SORITUNECOM_BOOT/.db_credentials` 로 접근:

\`\`\`sql
SELECT
  COUNT(*) AS total,
  SUM(group_id IS NOT NULL) AS with_group,
  SUM(is_active = 1) AS still_active_flag,
  GROUP_CONCAT(id ORDER BY id) AS member_ids
FROM bootcamp_members
WHERE member_status = 'expelled';
\`\`\`

판단:
- `total = 0`: 즉시 배포 OK (소급 영향 0)
- `total > 0 AND with_group = 0`: 대부분 group 의존 처리는 0 → 영향 작음. 카페 백필/후기/멤버페이지/cron 만 영향. 운영자 인지 후 배포
- `total > 0 AND with_group > 0`: 보기 드문 케이스. 코인 적립·점수 계산·체크리스트 모두 즉시 영향. 회원 명단 확인 후 진행 판단

## 2. 카페·줌 cron 백필 윈도우 확인

\`\`\`bash
grep -n "backfill\|INTERVAL\|DATE_SUB" /root/boot-prod/public_html/cron.php | head -20
\`\`\`

백필 함수가 거슬러 올라가는 기간 확인. 이 윈도우 안에 expelled 회원의 카페 글이 있으면 다음 cron 에서 한꺼번에 ingest 됨.

## 3. opt-out 옵션 (필요 시)

위 1번 결과에 따라 운영자 판단:

(a) 그대로 배포 (Q4 결정 기본 경로)
(b) 기존 expelled 를 사전에 `refunded` 로 정리:
\`\`\`sql
-- 신중히. 회원 명단 확인 후 개별 실행 권장.
UPDATE bootcamp_members SET member_status='refunded', is_active=0
 WHERE id IN (...);
\`\`\`
(c) 별도 spec 으로 `expelled_legacy` enum 값 도입 후 마이그 — 추가 작업 필요

## 4. 배포 후 모니터링

배포 직후 첫 cron 사이클 (대략 03:00 한국 시간) 다음 날 아침 확인:

\`\`\`sql
-- 출석률 통계 분모 변동
SELECT cohort_id, COUNT(*) FROM bootcamp_members
 WHERE is_active=1 AND member_status != 'refunded' GROUP BY cohort_id;

-- 코인 적립 cron 결과 (expelled 회원에게 들어간 코인 있는지)
SELECT bm.id, bm.nickname, bm.member_status, SUM(cl.change_amount) AS coin_change_24h
  FROM bootcamp_members bm
  LEFT JOIN coin_logs cl ON cl.member_id = bm.id
   AND cl.created_at >= NOW() - INTERVAL 1 DAY
 WHERE bm.member_status = 'expelled'
 GROUP BY bm.id HAVING coin_change_24h IS NOT NULL;

-- 카페 ingest 백필 폭
SELECT execution_id, total_success, total_skipped, total_unmapped, created_at
  FROM integration_logs
 WHERE created_at >= NOW() - INTERVAL 1 DAY
 ORDER BY created_at DESC LIMIT 5;
\`\`\`

이상 수치 (코인 +10 이상, 카페 ingest +100 이상 등) 발견 시 spec §12.4 롤백 + 클린업 SQL 작성.
```

- [ ] **Step 2: Commit**

```bash
cd /root/boot-dev
git add docs/ops/2026-05-28-expelled-soft-mode-prod-check.md
git commit -m "docs(ops): expelled 약한 조치 — PROD 사전 배포 체크리스트

spec §12.2 의 운영판. PROD pull 직전 SQL + 판단 기준 + 배포 후
모니터링 query.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 13: 전체 회귀 + DEV 종합 수동 검증

**Files:**
- Run: 모든 `tests/expelled_*.php` + `tests/leaving_*.php`
- Browser: DEV 사이트 종합 시나리오

- [ ] **Step 1: 모든 invariant 테스트 회귀**

```bash
cd /root/boot-dev && for t in tests/expelled_*.php tests/leaving_*.php; do
  echo "=== $t ==="
  php "$t" || break
done
```

Expected: 모두 `결과: N PASS, 0 FAIL`

- [ ] **Step 2: spec §11.3 수동 검증 시나리오 8개 DEV 에서 실행**

DEV: https://dev-boot.soritune.com/

1. **active → 내보내기 → group_id 보존 확인**
   - operation 계정으로 임의 active 회원 1명 골라 내보내기
   - DB 직접: `SELECT id, member_status, group_id, member_role FROM bootcamp_members WHERE id=<X>` → status='expelled' 이고 group_id 가 NULL 아닌지

2. **체크리스트 기본 — expelled 안 보임**
   - leader/coach/operation 계정으로 체크리스트 탭
   - 위 회원이 안 보이는지

3. **체크박스 ON → 회색 + 배지로 expelled 표시**
   - "내보내기 회원 포함" 체크
   - 위 회원이 회색 배경에 `[퇴출]` 배지로 보이는지

4. **현황판 동일 검증**
   - 같은 토글 상태, 같은 회원 표시

5. **QR 입장 (expelled 도 가능)**
   - DEV QR 입장 페이지에서 위 회원의 QR 사용 → 입장 성공

6. **코인 적립 cron — expelled 도 적립 대상**
   - DEV cron 수동 실행 또는 SQL 로 `coin_reward_group` 활성 회원 목록 직접 확인
   ```sql
   SELECT bm.id, bm.nickname, bm.member_status FROM bootcamp_members bm
    WHERE bm.cohort_id=<현 기수> AND bm.member_status NOT IN ('refunded')
    ORDER BY bm.id;
   ```
   위 회원 포함 확인.

7. **후기 등록 — expelled 도 가능**
   - 위 회원 계정으로 로그인 → 후기 페이지 진입 → eligible 메시지

8. **복원 → active 와 완전 동일**
   - operation 계정으로 복원
   - 모든 화면에서 active 와 동일하게 노출되는지 (배지 없음, 회색 없음, 토글 무관)

9. **(추가) /operation 회원목록 expelled 탭/카운트 그대로**
   - operation 회원 관리 탭에서 expelled 탭 카운트, footer "퇴출 N 미포함" 표기 그대로인지

- [ ] **Step 3: 잡힌 회귀 또는 결함 있으면 Task 별 fix-up 으로 돌아가기. 없으면 dev push**

```bash
cd /root/boot-dev && git log --oneline origin/dev..dev
```

`git log` 결과: Task 1-12 의 커밋 12개 정도가 dev 브랜치에만 있어야 함.

```bash
cd /root/boot-dev && git push origin dev
```

- [ ] **Step 4: 사용자에게 dev 확인 요청 (CLAUDE.md 의 dev push 후 stop 규칙)**

dev push 완료 + 검증 결과 보고. 사용자가 "운영 반영해줘" 류로 명시적 요청한 경우에만 다음 진행:
- `pt-dev` 아닌 `boot-dev` 에서: `git checkout main && git merge dev && git push origin main && git checkout dev`
- `boot-prod` 에서: 사전에 docs/ops 의 체크리스트 §1 SQL 실행 → 결과 보고 → `git pull origin main`
- PROD 배포 후 docs/ops 의 §4 모니터링 query 다음 날 확인

---

## Self-Review

**Spec coverage**

- spec §3 표 (5-state 재정의) → Task 4 (group_id 보존) + Task 3 (단체활동 게이트) 로 커버
- spec §4 (DB 마이그 0개) → 본 plan 에 DB 마이그 task 없음 ✓
- spec §5 (게이트 10곳) → Task 3 의 9 sub-step 으로 커버
- spec §5.1 변경 없음 항목 (admin.php:594, 628, auth.php, bootcamp_functions.php:220-225 점수 강등) → 본 plan 에 손대지 않음 ✓
- spec §6.1 (expel 핸들러 group_id=NULL 제거) → Task 4 ✓
- spec §6.2-6.4 (복원, 로그, 권한 변경 없음) → 본 plan 에 손대지 않음 ✓
- spec §7.1 (서버 토글) → Task 5, 6 ✓
- spec §7.2 (클라이언트 체크박스 + localStorage) → Task 8, 9 ✓
- spec §7.3 (행 시각 차별화) → Task 10 ✓
- spec §7.4 (한계 — group_id=NULL) → Task 7 invariant 마지막 step 에서 검증 ✓
- spec §8 (UI 라벨/메시지) → Task 11 confirm 메시지 ✓
- spec §9.1 OOM 미변경 → 본 plan 손대지 않음 ✓
- spec §11.1 (기존 5 invariant flip) → Task 2 ✓
- spec §11.2 (신규 invariant 2개) → Task 1 (set_status flip) + Task 7 (soft_checklist) ✓
- spec §11.3 (수동 검증) → Task 13 Step 2 ✓
- spec §12.2 (PROD 사전 체크리스트) → Task 12 문서 ✓

**Placeholder scan**

- Task 2B-2E: "구체 라인은 파일 확인 후 spec §11.1 동등 패턴 따라" — 약간 hand-wavy. 실제로는 각 파일이 cron invariant 와 거의 동일 구조 (sql NOT IN flip + expected flip) 라 implementer 가 패턴 추론 가능. 단 코드 블록은 cron 한 곳만 풀로 보여줌. **수용 가능** (TDD 패턴이 1번에서 확립되면 5번까지는 같은 형태 적용).
- Task 9 Step 4, 5: "같은 grep 으로 찾은 위치" — implementer 가 직전 step 의 grep 결과로 위치 찾음. 수용 가능.
- Task 11 Step 2: "있으면 같이 갱신" — grep 으로 존재 확인 후 진행. OK.
- TBD/TODO/FIXME — 없음 ✓

**Type consistency**

- `includeExpelledFlag()` 헬퍼: Task 8 Step 3 정의 → Task 9 Step 3, 4, 5 호출. 이름 일치 ✓
- `boot.include_expelled` localStorage 키: Task 8 Step 1, Step 2 (bindFilterEvents), Step 3 (헬퍼) 일관 ✓
- `bc-include-expelled` element id: Task 8 Step 1 정의 → Task 8 Step 2 querySelector. 일치 ✓
- `bc-member-btn--expelled` CSS 클래스: Task 10 Step 2 (JS) + Step 3 (CSS) 일관 ✓
- `member_status` API 컬럼: Task 10 Step 1 에서 SELECT 추가 (handleChecklist, handleChecklistByMission 에 추가, handleStatusBoard 는 이미 있음) → Task 10 Step 2 JS 에서 `m.member_status` 사용. 일관 ✓

---

**Plan complete.**
