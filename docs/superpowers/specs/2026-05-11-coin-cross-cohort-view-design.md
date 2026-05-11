# 코인 cross-cohort 회원 view (Option A — 쿼리 시점 합산)

- 작성일: 2026-05-11
- 대상: `boot.soritune.com` (boot)
- 변경 범위: **회원 화면만** (코치/팀장/어드민 view 미수정, reward distribution 로직 미수정)

## 배경 / 문제

11기→12기 dual-enrollment 회원이 12기 chip 으로 로그인하면, 11기 활동 중에 받은 `cycle_12`(=12기 리워드) 코인이 안 보인다.

- `member_coin_balances.current_coin` 은 단일 `member_id` 기준 캐시 → 12기 row 만 read 되고 11기 row 에 쌓인 잔액은 빠짐.
- PROD 2026-05-08 기준 dual-enrollment 회원 146건, 그 중 **11기 row 에만 cycle_12 잔액(>0)** 보유 71건 → 12기 chip 에서 코인 사라진 것처럼 보임.
- 회원이 "12기 가입했더니 코인이 0이 되었다" 로 인식.

## 목표

- 회원 view 에서 cohort 가 바뀌어도 "표시 group" 안의 코인이 끊김 없이 합산되어 보이게.
- 11기 chip 의 현행 동작은 그대로 유지 (rg 3, rg 4 모두 보임).
- 12기 chip 일 때 `cycle_12` 가 11기 row 에 있던 잔액까지 포함해서 노출.
- 데이터 마이그·schema 변경 없음. 코드 revert 만으로 즉시 롤백 가능.

## Out of scope

- 코치 / 팀장 / 어드민 화면.
- reward distribution / coach.php 의 reward 처리 로직.
- `member_coin_balances` 캐시의 reward distribution 기록 경로 (read 만 끊고, write 는 그대로 유지).
- chip swap 자체의 동작 (`auth.swapMemberCohort`).
- 동명이인 sibling 매칭 (phone fallback 안 함).

## 채택된 접근법 — Option A (쿼리 시점 합산)

회원 view 의 모든 코인 집계 쿼리에서 "현재 member_id" 를 "현재 + 같은 user_id 의 earlier-cohort sibling member_id 들" 로 확장한다. 데이터/스키마는 그대로.

### Architecture

```
chip swap (auth.swapMemberCohort)  →  세션 active member_id = X기 row
        ↓
findCoinSiblingMemberIds(db, currentMemberId)
   - user_id 기준 cohort_id < 현재 row 조회 → sibling 목록
        ↓
3개 진입점이 sibling 목록을 함께 사용:
   ① 대시보드 stat   (member.php login/check_session/dashboard)
   ② cycle 카드 본문 (coin_functions.getMemberCoinHistory)
   ③ 현재 reward group (coin_functions.getCurrentRewardGroupForMember)
        ↓
SQL: ... WHERE mcc.member_id IN (?, ?, ...) ...
응답 JSON 의 logs 에 cohort_label 부착
        ↓
member-coin-history.js renderCycleCard 가 cohort 별 sub-section 분기 렌더
```

`mcc`/`coin_logs`/`member_coin_balances` row ownership 은 그대로. **합산은 view 시점에만** 일어남.

## Section 2 — 표시 group / sibling 결정 규칙

### 표시 group (chip = 현재 cohort X 기준)

```
rg.status = 'open'
AND (
  현재 member_id 가 rg 안의 어느 cycle 에든 mcc 보유   -- 기존 룰
  OR
  rg 안에 cc.name = '<X label>' 인 cycle 이 존재         -- 신규 룰
)
```

- 12기 chip: rg 4("12기 리워드", cycle_5="12기") → 신규 룰로 표시. rg 3("11기 리워드", cycle_2="11기") 은 12기 row 에 mcc 없고 cycle name "12기" 도 없음 → 제외.
- 11기 chip: 11기 row 가 rg 3·rg 4 양쪽에 mcc 보유 → 둘 다 표시. **현행 동작 유지.**

### sibling 정의 (`findCoinSiblingMemberIds`)

```sql
SELECT id AS member_id, cohort_id
FROM bootcamp_members
WHERE user_id = :curUserId
  AND cohort_id < :curCohortId
  AND id <> :curMemberId
  AND user_id IS NOT NULL AND user_id <> ''
```

- earlier cohort 만 (cohort_id < 현재).
- `is_active`/`member_status` 필터 안 함 (코인은 historical fact, refund/withdraw 와 무관).
- `user_id` 비어 있으면 lookup skip — phone fallback 안 함 (동명이인 위험).
- 같은 요청 안에서 여러 진입점이 부르므로 request-scoped static cache (함수 안의 `static $cache`).
- helper 실패(예: 예외) 시 `[currentMemberId]` 하나만 담은 배열 반환 → 최악의 경우 현행 동작.

### Edge cases

- sibling 0건 → 배열에 currentMemberId 만, 현행과 동일.
- sibling 다수 (13기 chip 에서 11·12 둘 다) → cohort_id 오름차순 정렬.
- 같은 user_id 가 같은 cohort 에 2 row (이론상 없음) → DISTINCT.

## Section 3 — cycle 안 합산 + 응답 JSON

### `getMemberCoinHistory` 변경

기존 `WHERE mcc.member_id = ?` → `WHERE mcc.member_id IN (?, ?, ...)` 로 확장. cycle row 자체는 cohort 무관(reward_groups + coin_cycles 메타데이터)이므로 그대로.

**반환 cycle 의 범위**: `getDisplayedRewardGroupIds(currentMemberId, siblings)` 결과의 rg 들 안에 있는 cycle 만 반환 (= "표시 group" 룰 일관). 12기 chip 에선 rg 3 의 cycle_11 은 반환 안 됨. 11기 chip 에선 rg 3·rg 4 모두 반환.

```sql
-- cycle 별 earned/used 집계
SELECT
  cc.id AS cycle_id, cc.name AS cycle_name,
  SUM(CASE WHEN cl.delta > 0 THEN cl.delta ELSE 0 END) AS earned,
  SUM(CASE WHEN cl.delta < 0 THEN -cl.delta ELSE 0 END) AS used
FROM coin_cycles cc
JOIN member_cycle_coins mcc
  ON mcc.cycle_id = cc.id AND mcc.member_id IN (:ids)
LEFT JOIN coin_logs cl
  ON cl.cycle_id = cc.id AND cl.member_id IN (:ids)
WHERE cc.reward_group_id IN (:displayed_group_ids)
GROUP BY cc.id
```

### coin_logs 에 cohort 라벨 부착

```sql
SELECT cl.*, bm.cohort_id, c.name AS source_cohort_label
FROM coin_logs cl
JOIN bootcamp_members bm ON bm.id = cl.member_id
JOIN cohorts c ON c.id = bm.cohort_id
WHERE cl.cycle_id = :cycle_id AND cl.member_id IN (:ids)
ORDER BY cl.created_at DESC
```

### 응답 JSON (cycle 한 개)

```json
{
  "cycle_id": 5,
  "cycle_name": "12기",
  "earned": 60,
  "used": 0,
  "balance": 60,
  "logs_by_cohort": [
    { "cohort_id": 11, "cohort_label": "11기", "is_other_cohort": true, "logs": [ { "delta": 50, "...": "..." } ] },
    { "cohort_id": 12, "cohort_label": "12기", "is_other_cohort": false, "logs": [] }
  ]
}
```

- `is_other_cohort` = "현재 chip 의 cohort 와 다른가" 플래그. JS 가 라벨 분기에 사용.
- 단일 cohort (sibling 0 또는 한 쪽만 logs 있음) → JS 가 flat 렌더 + 라벨 생략.
- earned/used/balance 는 합산값 (카드 상단에 그대로).

서버에서 cohort 별 grouping 하는 이유: 클라가 member_id→cohort_label 별도 fetch 없이 일관 렌더, 합산값과 정합 보장.

## Section 4 — 대시보드 stat 카드 재계산

### 현재

`member.php:54` (login), `:129` (check_session), `:180` (dashboard) 응답에서 `current_coin` 으로 `member_coin_balances.current_coin` 직접 read. 단일 member_id 기준이라 12기 chip 시 11기 row 의 cycle_12 portion 누락.

### 변경 후

`member_coin_balances` 캐시 직접 read 중단. "표시 group 들의 cycle 합산" 으로 재계산:

```sql
SELECT GREATEST(COALESCE(SUM(cl.delta), 0), 0) AS current_coin
FROM coin_logs cl
JOIN coin_cycles cc      ON cc.id = cl.cycle_id
JOIN reward_groups rg    ON rg.id = cc.reward_group_id
WHERE rg.status = 'open'
  AND rg.id IN (:displayed_group_ids)
  AND cl.member_id IN (:sibling_ids)
```

- "표시 group" 룰 재사용 → 12기 chip 에선 rg 4 만 sum → 11기 row 의 cycle_11(rg 3) portion **자동 제외**.
- 11기 chip 에선 rg 3 + rg 4 → 현행과 동일.
- `GREATEST(..., 0)` 클램프 → 음수 노출 방지.

### 왜 캐시를 안 쓰는가

- `member_coin_balances` 는 member_id 1:1 row → cross-cohort 합산을 표현 못 함.
- 새 cache row 추가 시 reward distribution 모든 경로에 sync 책임 증가 + chip 별로 값이 달라야 하므로 캐시 키 복잡.
- 쿼리 시점 계산은 dashboard/login 당 1회로 비용 미미. 인덱스 활용 (rg.id, cl.cycle_id, cl.member_id).

### Helper 함수 (신규)

```php
// coin_functions.php (신규)
function findCoinSiblingMemberIds(PDO $db, int $currentMemberId): array {
  // request-scoped static cache; user_id < currentCohortId rows
  // 반환: [['member_id'=>..., 'cohort_id'=>..., 'cohort_label'=>...], ...]
  // 항상 currentMemberId 의 정보를 첫 번째로 포함
}

function getDisplayedRewardGroupIds(PDO $db, int $currentMemberId, array $siblingMemberIds): array {
  // Section 2 의 표시 group 룰 적용
  // 반환: [rg_id, ...]
}

function getMemberDisplayedCoinTotal(PDO $db, int $currentMemberId): int {
  // Section 4 의 SQL 캡슐화
}
```

`member.php` 의 3개 호출지점은 `current_coin` 만 `getMemberDisplayedCoinTotal()` 결과로 교체 (응답 키 이름 유지).

### `getCurrentRewardGroupForMember` 변경

기존: 현재 member_id 가 mcc 보유한 open rg 중 가장 최근(또는 단일) 반환. 12기 chip dual-enrollment 회원은 mcc 없으니 rg 4 를 못 찾아 `null` 반환 가능 → "내가 어느 리워드 진행 중인지" 가 안 보일 위험.

변경: Section 2 의 "표시 group" 룰을 그대로 적용한 후 단일 reward group 선택 로직(기존 정렬: rg.id DESC 또는 cycle.created_at DESC, 현행 동작 유지) 으로 최종 1개 선택. 사실상 `getDisplayedRewardGroupIds()` 결과의 첫 항목을 반환하는 구조로 단순화 가능. 단, 기존 호출지가 기대하는 row 형태(rg + cycle 메타) 그대로 유지.

### Edge cases

- sibling 0 + displayed group 0 (오래된 회원, 모든 rg closed) → 0 반환.
- 정확히 0 코인 → 0 반환 (UI 정상).
- 무한 lookup 방지: helper 안에서 sibling 의 sibling 은 안 따라감 (1-hop only).

## Section 5 — UI 렌더 + 라벨

진입점: `public_html/js/member-coin-history.js` 의 `renderCycleCard(cycle)` (~line 48).

```js
function renderCycleCard(cycle) {
  const groups = cycle.logs_by_cohort || [];
  const nonEmpty = groups.filter(g => g.logs.length > 0);
  const hasMultipleSources = nonEmpty.length > 1;

  // 헤더 (현행 유지): cycle.cycle_name + balance(=earned-used)
  if (!hasMultipleSources) {
    // sibling 없거나 한 쪽만 logs 있음 → flat 렌더, 라벨 생략
    renderFlatLogs(nonEmpty.flatMap(g => g.logs));
  } else {
    // 멀티 cohort: cohort 별 sub-section
    nonEmpty.forEach(g => {
      const label = g.is_other_cohort ? `⤷ ${g.cohort_label} 때 받은 코인` : `⤷ ${g.cohort_label}`;
      renderSectionHeader(label);
      renderFlatLogs(g.logs);
    });
  }
}
```

### 라벨 정책

- 현재 chip cohort 와 같은 그룹 → `"⤷ 12기"` (간결)
- sibling 출신 → `"⤷ 11기 때 받은 코인"` (출처 명시)
- 정렬: cohort_id 오름차순 → 옛 기수가 먼저, 현재 chip 의 그룹이 마지막.

### 카드 상단 합계

- `balance = earned - used` (합산값) → 카드 상단의 큰 숫자.
- sub-section 안의 합계는 표시 안 함 (사용자 결정: "카드 상단 = 합계, sub-section 은 logs 만").

### 서브섹션 헤더 스타일

- `<div class="coin-section-label">`, 본문 라벨보다 살짝 작고 muted color (다크 테마 `var(--text-muted)`).
- `margin: 12px 0 6px`. 첫 그룹은 `margin-top: 0`.
- `⤷` 글자로 들여쓰기 신호 — preview 채택본 일치.

### Empty cycle 처리

- earned/used 모두 0 + logs 비어 있으면 카드 자체 미렌더 (현행과 동일).
- 71건 케이스의 12기 chip → 11기 그룹만 logs 있음 → multipleSources=false → flat + 라벨 생략 (사용자 결정 "단일 섹션이면 라벨 생략" 일치).

### Accessibility / 모바일

- 서브섹션 헤더는 시각적 분리 목적, 별도 ARIA role 안 함.
- 모바일 라벨 자연 줄바꿈, max-width 카드 폭.

## Section 6 — 검증/테스트 전략

### Invariants (`tests/invariants/coin_cross_cohort.php` 신규)

PROD 데이터에 대해 read-only 회귀 가드:

```
INV-1  자기 cohort chip 시, 새 합산 잔액 ≥ old member_coin_balances.current_coin
INV-2  sibling 0건 회원(non-dual)의 새 잔액 = old 잔액 (영향 없어야)
INV-3  11기 chip 회원의 새 잔액 = old 잔액 (earlier sibling 없음)
INV-4  12기 chip dual-enrollment 71건: 새 잔액 = 12기 row balance + 11기 row 의 cycle_12(rg 4) portion
       (검증 샘플 5건 — oh_nakazawa 60, 3321876906@k 50, 3641551876@k 50 등 메모리 기록)
INV-5  cycle_11(rg 3) portion 은 12기 chip 합산에 절대 포함 안 됨
INV-6  findCoinSiblingMemberIds 가 cohort_id < 현재 만 반환
```

### Smoke 시나리오 (DEV, 수동)

1. 11기 단일 회원 로그인 → 기존과 동일 (flat, 라벨 없음).
2. 12기 단일 회원 로그인 → cycle_12 카드만, flat.
3. dual-enrollment 11기 chip → rg 3 + rg 4 카드 (현행 유지).
4. dual-enrollment 12기 chip → cycle_12 카드 하나, `⤷ 11기 때 받은 코인` + `⤷ 12기` 두 섹션 + 카드 상단 합계.
5. dual-enrollment 12기 chip + 11기 row 잔액 0, 12기 row 잔액 30 → multipleSources=false → flat + 라벨 생략, 헤더=30.
6. chip 토글 ↔ swap 후 즉시 반영 (reload 기준).

### Regression 가드 (자동)

- `tests/invariants/` 의 다른 코인 invariant 가 있다면 함께 실행 (`coin_cycle_lifecycle.php` 등). 새 잔액이 기존 합계 invariant 와 충돌 없음 확인.
- `member_coin_balances` 테이블 자체에 write/read 없음 (캐시 read 폐기). reward distribution 경로 미수정.

### Performance

- 12기 chip 합산 SQL EXPLAIN → rg/cc/cl 인덱스 사용 확인 (`coin_logs(member_id, cycle_id)`, `member_cycle_coins(member_id)` 활용).
- dashboard 응답 시간 +50ms 이내 (DEV 부하 테스트 1~2회).

### Rollout 안전망

- 마이그·데이터 변경 없음 → 코드 revert 만으로 즉시 롤백.
- helper 실패 시 `[currentMemberId]` 하나로 fallback → 최악 = 현행 동작.

### 테스트할 수 없는 것 (명시)

- chip swap race (사용자가 빠르게 chip 토글) — 현행도 동일 수준 race, 별도 가드 안 함.
- 새 cohort(예: 13기) 활성 시 11기 chip 에서 12기 sibling 노출 여부 — Section 2 정의상 earlier cohort 만이라 안 보임. 의도 일치.
- **NOTE**: 새 cohort 가 생기면 이 spec 재검토 한 줄 추가.

## 영향 받는 파일

| 파일 | 변경 |
|------|------|
| `public_html/includes/coin_functions.php` | `findCoinSiblingMemberIds`, `getDisplayedRewardGroupIds`, `getMemberDisplayedCoinTotal` 신규. `getCurrentRewardGroupForMember`(L623), `getMemberCoinHistory`(L757) sibling-aware 로 변경 |
| `public_html/api/member.php` | login(L54) / check_session(L129) / dashboard(L180) 의 `current_coin` 계산을 `getMemberDisplayedCoinTotal` 호출로 교체 |
| `public_html/js/member-coin-history.js` | `renderCycleCard`(~L48) 가 `logs_by_cohort` 분기 렌더 + 라벨. `coin-section-label` CSS 추가 |
| `tests/invariants/coin_cross_cohort.php` | 신규 invariants 스크립트 (INV-1~6) |

마이그 없음. 새 컬럼/테이블 없음.

## 검증 데이터 (PROD 2026-05-08 기준)

- cohorts: id=11(11기, active, 2026-03-23~), id=12(12기, active, 2026-05-11~)
- coin_cycles: id=2(11기, closed, rg=3), id=5(12기, active, rg=4)
- reward_groups: id=3="11기 리워드"(open), id=4="12기 리워드"(open)
- dual-enrollment user_id 기준: 146건, phone 기준: 139건
- 11기 row 에만 cycle_12 잔액(>0) 있는 dual-enrollment: **71건** ← 핵심 영향
- cycle_5(rg 4) mcc 회원 분포: 11기 cohort row 123명(net 771), 12기 cohort row 0명
- 샘플 검증 대상: `oh_nakazawa` 11기 row=60 / 12기 row=0, `3321876906@k` 50/0, `3641551876@k` 50/0

## 미결 / 사용자 재확인 가능

- 라벨 정확한 표기는 `"⤷ 11기 때 받은 코인"` / `"⤷ 12기"` 로 채택. 변경 시 spec 업데이트.
- 단일 section(sibling 없음 또는 한 쪽만 logs) → 라벨 생략 (= 현재 UI 유지).
- siblings 2명 이상 시 라벨 정렬 = cohort 오름차순.
