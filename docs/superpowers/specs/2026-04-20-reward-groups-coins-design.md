# 리워드 구간(Reward Group) 코인 표시 — 설계서

- 작성일: 2026-04-20
- 대상: `boot.soritune.com` — 부트캠프 코인 시스템

## 1. 배경 / 문제

부트캠프는 한 기수의 종료일과 코인 정산일이 어긋납니다.

- 11기 부트캠프 종료: 2026-04-26
- 11기 코인 정산 마감: 2026-04-19 (일요일)
- 4/20 ~ 4/26 기간에도 회원 활동은 계속되지만, 해당 기간 코인은 다음 기수(12기)로 적립해야 함
- 리워드는 **11기 + 12기 코인을 합쳐** 12기 리워드 시점에 지급

이 "두 기수를 한 리워드 단위로 묶는" 패턴은 **매 기수 반복**됩니다. (Q3=A: 항상 2기수씩)

### 현재 상태의 한계

- `coin_cycles`는 이미 기수별로 분리되어 저장되지만, **여러 cycle을 한 리워드 단위로 묶는 개념이 없음**.
- 회원 화면은 `member_coin_balances.current_coin` 하나만 보여줌 → 전체 누적 합계만 노출, 기수별 구분 불가능.
- 지급(distribution) 이벤트를 기록할 테이블이 없음 → 누가 언제 지급했는지 추적 안 됨.

## 2. 요구사항

### 기능

- 운영자는 **2개 cycle을 하나의 "reward group"** 으로 묶을 수 있다.
- 운영자는 reward group 단위로 **[지급]** 액션을 실행할 수 있다. 지급 시 해당 그룹 내 회원들의 누적 코인이 "지급됨(used)" 상태로 이동하고, 회원별 확정 금액이 별도 스냅샷 테이블에 저장된다.
- 회원은 본인의 **현재 열린 리워드 구간** 코인을 cycle별로 분리해서 확인할 수 있다 (예: "11-12기 리워드: 58코인 = 11기 50 + 12기 8").
- 지급이 완료되면 해당 구간 코인은 회원 화면에서 사라지고, 다음 구간(열린 상태)만 표시된다.
- **수동 코인 조정(`handleCoinChange`)은 반드시 특정 cycle에 귀속**되어 `member_cycle_coins`에 반영된다. `syncMemberCoinBalance` 이후 유실되지 않는다.

### 비기능

- 기존 1기 cycle과 같은 legacy 데이터는 `reward_group_id = NULL`로 두고 별도 처리 (회원이 보유한 현재 잔액에는 영향 없음).
- `syncMemberCoinBalance`가 `SUM(earned - used)`로 바뀌어도 기존 데이터(`used_coin = 0`)는 동일한 값을 유지 → **하위 호환**.
- 지급 액션은 트랜잭션으로 원자성 보장.
- 지급된 reward group의 회원별 확정 금액은 `coin_logs`나 `member_cycle_coins`가 바뀌어도 불변으로 조회 가능해야 한다 → 전용 스냅샷 테이블.

### 권한

- 조회: 모든 admin
- reward group 생성/수정/삭제, cycle attach/detach, 지급 실행: `operation` 롤

## 3. 데이터 모델

### 3.1 신규 테이블: `reward_groups`

```sql
CREATE TABLE reward_groups (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(50) NOT NULL COMMENT '예: 11-12기 리워드',
    status           ENUM('open','distributed') NOT NULL DEFAULT 'open',
    distributed_at   DATETIME     DEFAULT NULL,
    distributed_by   INT UNSIGNED DEFAULT NULL COMMENT 'admins.id',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rg_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2 `coin_cycles` 변경

```sql
ALTER TABLE coin_cycles
  ADD COLUMN reward_group_id INT UNSIGNED NULL AFTER max_coin,
  ADD KEY idx_cc_rg (reward_group_id),
  ADD CONSTRAINT fk_cc_rg FOREIGN KEY (reward_group_id)
      REFERENCES reward_groups(id) ON DELETE SET NULL;
```

### 3.3 신규 테이블: `reward_group_distributions`

지급 시점의 회원별 확정 금액 스냅샷. 이후 `coin_logs`/`member_cycle_coins`가 변해도 불변으로 유지.

```sql
CREATE TABLE reward_group_distributions (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reward_group_id    INT UNSIGNED NOT NULL,
    member_id          INT UNSIGNED NOT NULL,
    total_amount       INT NOT NULL COMMENT '지급 확정 코인 합',
    cycle_breakdown    JSON NOT NULL COMMENT '예: {"11기": 50, "12기": 8}',
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_rgd (reward_group_id, member_id),
    KEY idx_rgd_member (member_id),
    CONSTRAINT fk_rgd_group  FOREIGN KEY (reward_group_id) REFERENCES reward_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_rgd_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `total_amount = 0`인 회원도 기록할지? → **0은 INSERT 생략**. "지급 내역"은 실제로 받은 회원만.
- `cycle_breakdown` JSON 포맷: cycle `name`을 key로, 해당 cycle의 earned(지급 시점)를 값으로.

### 3.4 불변식 (앱 레이어 검증)

- 하나의 `reward_group`에 속한 cycle은 **최대 2개**.
- **지급 사전조건**: `reward_groups.status='open'` AND cycle 정확히 2개 AND **두 cycle 모두 `coin_cycles.status='closed'`**. (활동 중 실수 지급 방지)
- `reward_groups.status='distributed'` 이후에는 해당 group의 cycle attach/detach 불가.
- 이미 `distributed` 상태인 group에는 재지급 불가.

### 3.5 `syncMemberCoinBalance` 의미 변경

- Before: `current_coin = SUM(member_cycle_coins.earned_coin)` (회원 기준 전체 합)
- After: `current_coin = SUM(member_cycle_coins.earned_coin - member_cycle_coins.used_coin)`

과거 데이터는 `used_coin = 0`이므로 값이 동일하게 유지됨.

### 3.6 수동 코인 조정 개편 (`handleCoinChange`)

**현재 버그**: `coin.php handleCoinChange`(25-62행)는 `coin_logs` + `member_coin_balances`만 업데이트하고 `member_cycle_coins`를 건드리지 않음. 이후 어떤 경로로든 `syncMemberCoinBalance`가 호출되면 `SUM(member_cycle_coins.earned_coin)`으로 재계산되어 수동 조정분이 통째로 유실됨.

**변경**:
- `handleCoinChange` 시그니처에 `cycle_id` 필수 파라미터 추가. UI에서 대상 cycle 선택. 기본값: 현재 open reward group의 `coin_cycles.status='active'` cycle 중 `start_date`가 가장 최근인 것.
- 내부 구현을 `applyCoinChange($db, $memberId, $cycleId, $coinChange, 'manual_adjustment', $reasonDetail, $adminId)` 호출로 교체.
- 차감(음수)인데 `earned_coin` 부족 시: 같은 reward group의 다른 cycle에서 차감하는 fallback은 하지 않음. 운영자가 cycle을 옳게 고르게 강제. (초과 차감은 jsonError로 막음.)
- 결과: 수동 조정이 `member_cycle_coins.earned_coin`(혹은 `used_coin`)에 반영되어 sync 공식과 일관.

## 4. API

### 4.1 회원용 응답 확장

기존 회원 프로필 응답에 `current_reward_group` 필드 추가.

```json
{
  "coin": 58,
  "current_reward_group": {
    "name": "11-12기 리워드",
    "cycles": [
      {"name": "11기", "earned": 50, "settled": true},
      {"name": "12기", "earned":  8, "settled": false}
    ]
  }
}
```

- `coin` = `SUM(earned - used)` — 지금까지와 동일한 필드명, 의미만 "활성 잔액"으로 변경.
- `current_reward_group` = 회원의 `member_cycle_coins`가 존재하는 cycle 중 `status='open'`인 reward group. **없으면 필드 누락**.
- `settled` = cycle의 `status='closed'`면 true.

#### Edge cases

- **여러 open group에 코인이 있는 회원**: 드물지만 가능 (cohort 간 이동). 정책: `current_reward_group`은 "cycle `end_date`가 가장 늦은 open group" 하나만 반환. `coin` 필드는 전체 합. → 이 경우 breakdown 합 ≠ `coin` 가능.
- **legacy cycle(`reward_group_id=NULL`) 잔액이 있는 회원**: `coin`에는 포함되지만 `current_reward_group.cycles`에는 포함 안 됨. 화면은 "코인: N" + "현재 리워드 구간: ..." 두 줄로 병기하거나, "코인" 전체 값을 생략하고 reward group 합만 보여줄지 UI 결정 필요.
- **정책**: 화면 표시는 `coin`과 `current_reward_group.cycles` 합이 일치하지 않을 수 있음을 전제로, 회원 UI에서는 **`current_reward_group.cycles`의 합계만** 크게 보여준다. `coin` 전체값은 회원 UI에서 노출하지 않는다. (운영자 화면에서만 전체 합 참조.)

### 4.2 운영자용 신규 엔드포인트

| action | method | 설명 |
|---|---|---|
| `coin_reward_groups` | GET | 전체 group 리스트 + 소속 cycle + 그룹별 총 earned 합 |
| `coin_reward_group_create` | POST | `{name}` 받아 생성 (status=open) |
| `coin_reward_group_update` | POST | `{id, name}` (open일 때만) |
| `coin_reward_group_delete` | POST | `{id}` (소속 cycle 0개, status=open일 때만) |
| `coin_reward_group_attach_cycle` | POST | `{group_id, cycle_id}` (group당 cycle < 2, status=open, 해당 cycle의 기존 group_id=NULL) |
| `coin_reward_group_detach_cycle` | POST | `{group_id, cycle_id}` (status=open) |
| `coin_reward_group_preview` | GET | `?group_id=X` — 회원별 (cycle1_earned, cycle2_earned, total) |
| `coin_reward_group_distribute` | POST | `{group_id}` — 지급 실행 (사전조건 충족 시에만) |
| `coin_reward_group_distribution_detail` | GET | `?group_id=X` — `reward_group_distributions`에서 회원별 확정 금액 조회 (지급완료 group의 [내역] 용) |

#### 수동 조정 API 변경 (기존 `coin_change`)

`handleCoinChange` (action: `coin_change`)의 POST body 스키마:

Before:
```json
{"member_id": N, "coin_change": ±N, "reason_type": "...", "reason_detail": "..."}
```

After:
```json
{"member_id": N, "cycle_id": N, "coin_change": ±N, "reason_type": "...", "reason_detail": "..."}
```

- `cycle_id` 필수. 클라이언트(운영자 UI)는 회원 프로필에서 현재 open reward group의 active cycle을 기본 선택.
- 서버는 `applyCoinChange`로 위임. `member_cycle_coins`와 `coin_logs` 모두 자동 업데이트.

#### `coin_reward_group_distribute` 동작

트랜잭션 내에서 실행:

1. **검증**: group 존재 + `status='open'` + cycle 정확히 2개 + **두 cycle 모두 `coin_cycles.status='closed'`**. 어느 하나라도 실패면 jsonError.
2. 해당 group의 cycle 2개에 소속된 모든 회원의 `member_cycle_coins`를 조회. 회원별로:
   - 각 cycle의 `earned_coin - used_coin` 값을 구함 (cycle_name → amount).
   - `total = SUM`.
   - `total > 0`이면 `reward_group_distributions`에 INSERT: `(reward_group_id, member_id, total_amount=total, cycle_breakdown={cycle_name: earned_before_used, ...})`.
   - 각 `member_cycle_coins` row: `used_coin = earned_coin`으로 업데이트.
   - `coin_logs`에 cycle별로 `reason_type='reward_distribution'`, `coin_change = -(earned - used_before)`, `reason_detail = "리워드 지급 ({group.name})"` 기록.
3. `reward_groups` 업데이트: `status='distributed'`, `distributed_at = NOW()`, `distributed_by = admin_id`.
4. 영향받은 모든 member_id에 대해 `syncMemberCoinBalance` 호출.
5. 커밋.

#### `coin_reward_group_preview` 응답

```json
{
  "group": {"id": 1, "name": "11-12기 리워드", "status": "open"},
  "cycles": [{"id": 2, "name": "11기", "status": "closed"}, {"id": 3, "name": "12기", "status": "active"}],
  "can_distribute": false,
  "blockers": ["12기 cycle이 아직 closed 아님"],
  "members": [
    {"member_id": 2059, "nickname": "stephen", "per_cycle": {"11기": 110, "12기": 50}, "total": 160},
    ...
  ]
}
```

`can_distribute`가 false면 운영자 UI의 [지급] 버튼을 비활성화하고 `blockers` 문구 표시.

## 5. UI

### 5.1 회원 화면 (`js/memberTable.js`)

`memberTable.js:130-135`의 "점수 / 코인" 섹션 교체:

```
점수 / 코인
점수: 42
코인 (11-12기 리워드): 58
  └ 11기 50 (정산 완료) · 12기 8 (적립 중)
```

렌더링 규칙:
- `current_reward_group`이 있으면 `current_reward_group.cycles`의 `earned` 합을 "코인" 숫자로 표시하고, 아래에 cycle 브레이크다운 노출.
- `current_reward_group`이 없으면:
  - `coin > 0`이면 기존 포맷 (`코인: {coin}`) — legacy-only 회원
  - `coin == 0`이면 `코인: 0`
- cycle 배지: `settled=true` → "정산 완료", `settled=false` → "적립 중".
- `coin` 전체값은 회원 UI에 표시하지 않음 (breakdown 합과 불일치 가능성 때문).

### 5.2 운영자 화면 (`/operation/#coins`)

기존 Cycle 테이블 위에 **Reward Groups 섹션**을 추가.

#### Reward Groups 섹션 (신규)

| 컬럼 | 내용 |
|---|---|
| 이름 | `reward_groups.name` |
| 소속 Cycle | 2개 cycle 이름 (미완성이면 "1/2") |
| 상태 | 열림 / 지급완료 |
| 합계 | 전 회원 earned 총합 (open일 때) / 지급 총액 (distributed일 때) |
| 액션 | open: [cycle 추가] / [지급] / [수정] / [삭제], distributed: [내역] |

- **[새 Reward Group]** 버튼: 이름만 받아 생성 (cycle 연결은 이후 [cycle 추가]로).
- **[cycle 추가]**: 현재 `reward_group_id=NULL`인 cycle만 선택 가능한 드롭다운.
- **[지급]**: preview 모달 → 회원별 earned 표 + `can_distribute` + `blockers` 표시. `can_distribute=false`면 실행 버튼 비활성. true일 때만 확인 → distribute 실행.
- **[내역]**: distributed 그룹에 대해 `reward_group_distributions` 조회 → 회원별 확정 금액(`total_amount` + `cycle_breakdown`) 표 + 지급 시점/담당자 표시.

#### Coin Cycles 섹션 (기존 + 컬럼 추가)

- 기존 컬럼 유지, 맨 우측에 "리워드 구간" 컬럼 추가 (해당 cycle의 `reward_group.name` 또는 "-").
- 기존 `[정산]`/`[리더코인]`/`[마감]` 버튼은 그대로. 리워드 지급과 독립된 단위 작업으로 유지.

## 6. 마이그 경로 (11기 → 12기 분리)

### 6.1 선행: 스키마 + 코드 마이그

`migrate_reward_groups.php` (1회 실행):

1. `CREATE TABLE reward_groups`
2. `CREATE TABLE reward_group_distributions`
3. `ALTER TABLE coin_cycles ADD COLUMN reward_group_id`
4. 코드 배포:
   - `syncMemberCoinBalance` → `SUM(earned - used)` 공식
   - `handleCoinChange` → `cycle_id` 필수화 + `applyCoinChange` 위임 (섹션 3.6)

### 6.2 데이터 마이그: `migrate_split_cycle_11_12.php`

트랜잭션 + `--dry-run` 플래그 지원. 입력: 기존 11기 cycle id, 신규 12기 start/end 날짜, group name.

**원칙**: 이관 기준은 `coin_logs.created_at`이 아니라 **event 발생 날짜**. `member_cycle_coins`는 증분 연산이 아니라 **truth source(`member_mission_checks` + 이동 처리된 `coin_logs`)에서 재계산**으로 덮어쓴다.

실행 단계:

1. `reward_groups` 생성 (`name='11-12기 리워드'`, `status='open'`).
2. 11기: `end_date = '2026-04-19'`, `reward_group_id = new_group_id`.
3. 12기 cycle INSERT (`start_date='2026-04-20'`, `end_date=<입력 일요일>`, `reward_group_id = new_group_id`).
4. **`coin_logs` 이관 (event-date 기준)** — 11기로 기록된 로그를 reason_type별로 처리:
   - `reason_type='leader_coin'` (4/20 일괄 지급분): 모두 12기로 이동. 이유: "현 cycle 리더 보상" 성격이고, 11기 end_date가 4/19로 바뀐 상황에선 4/20에 준 게 12기 보상이 되어야 함.
   - `reason_type='study_join'` / `study_open`: `reason_detail`에서 `YYYY-MM-DD` 파싱 (형식: `"{code} check {date}"` 또는 `"{code} uncheck {date}"`). 파싱된 date >= `2026-04-20`이면 12기로 이동, 그 외는 11기에 남김.
   - 그 외 reason_type (`perfect_attendance`, `hamemmal_bonus`, `cheer_award`, `manual_adjustment`, ...): 발견 시 스크립트 **에러로 중단** (운영자 수동 확인 후 재실행). 이번 건에선 prod에 해당 로그 없음을 사전 확인.
   - `cycle_id IS NULL`인 로그는 건드리지 않음 (legacy 수동 조정 등).
   - 실제 UPDATE:
     ```sql
     UPDATE coin_logs SET cycle_id = :cycle12 WHERE id IN (<이동 대상 id 목록>);
     ```
5. **`member_cycle_coins` 재계산 (coin_logs 기반)** — 영향 member_id 집합에 대해 각 cycle별로:
   - **`earned_coin`**: `SELECT COALESCE(SUM(coin_change), 0) FROM coin_logs WHERE member_id=? AND cycle_id=?` (양수·음수 포함 총합. uncheck 차감 로그도 반영되어 `processCoinForCheck` 증감 로직과 일치).
   - **`study_open_count`**: `SELECT COUNT(*) FROM coin_logs WHERE member_id=? AND cycle_id=? AND reason_type='study_open' AND coin_change > 0`.
     - 의무 횟수(duty) 범위 체크는 coin_log 자체가 생성되지 않으므로 자연 제외 — 기존 코드 의도와 일치.
   - **`study_join_count`**: 동일 방식 (`reason_type='study_join'`).
   - **`leader_coin_granted`**: `EXISTS (coin_logs WHERE member_id=? AND cycle_id=? AND reason_type='leader_coin' AND coin_change > 0)` → 0 또는 1.
   - **`perfect_attendance_granted` / `hamemmal_granted`**: 각 `reason_type='perfect_attendance'` / `'hamemmal_bonus'`에 대해 동일 방식. 이번 migration 시점엔 두 값 모두 0이어야 정상.
   - **`used_coin`**: 현재 값 유지 (migration은 지급 이벤트 아님, 기본 0).
   - 계산된 값으로 11기/12기의 `member_cycle_coins` 행을 UPSERT (없으면 INSERT).
6. 영향 member들에 대해 `syncMemberCoinBalance` 호출.
7. **검증 (commit 전)**:
   - 영향 회원별 `SUM(earned) across all cycles` = 마이그 전과 동일 (±0).
   - 11기/12기 `earned_coin >= 0`, 모든 `*_count >= 0`.
   - 이동된 coin_logs 건수 = (leader_coin on 4/20) + (study_* with parsed date >= 4/20).
   - 파싱 실패한 reason_detail 0건.
   - 에러 reason_type 0건.
   - 문제 있으면 rollback 후 리포트 출력.

### 6.3 실행 순서

```
[DEV]
1. 스키마 마이그 (migrate_reward_groups.php)
2. 코드 배포 (syncMemberCoinBalance 교체)
3. 데이터 마이그 dry-run → 결과 리뷰
4. 데이터 마이그 실제 실행
5. UI 확인 (회원 화면 + /operation/#coins)

→ 사용자 확인 및 "운영 반영" 명시적 요청 후에만 아래 진행

[PROD]
1. mysqldump 백업
2. 스키마 마이그
3. 코드 pull
4. 데이터 마이그 dry-run
5. 데이터 마이그 실제 실행
6. 운영자 UI에서 reward_group "11-12기 리워드" 확인
```

### 6.4 롤백

- 데이터 마이그는 트랜잭션이므로 실패 시 자동 rollback.
- 실행 후 문제 발견 시 역방향 스크립트는 제공하지 않음. **프로덕션 실행 직전 `mysqldump` 백업 필수**.

## 7. 스코프 외 (별도 기획 필요)

- 회원에게 리워드 지급 알림 (이메일/푸시/앱 내 알림).
- 과거 지급 이력을 회원 본인이 조회하는 뷰.
- 리워드 금액 → 상품/혜택 매핑 로직.
- reward group당 cycle 수가 2가 아닌 경우 (N 유연화).

## 8. 리스크 / 주의

- `coin` 필드 의미 변경(`SUM(earned) → SUM(earned-used)`)은 이 스펙 내에서는 하위 호환이지만, 향후 `used_coin`을 리워드 지급 외 용도로 쓰게 되면 의미가 꼬일 수 있음. `used_coin`의 유일한 set 경로를 "reward distribution"으로 강제할 것.
- 운영자가 실수로 잘못된 cycle을 group에 붙이면 회원 화면 표시가 틀어짐. `status='open'`일 때만 detach 가능하게 막았으므로 지급 전까지는 수정 가능.
- 지급 후에 특정 회원에게 추가 코인을 주고 싶은 상황(예: 누락된 응원상 등)은 새 reward group의 새 cycle에서 처리해야 함. distributed group은 불변.
- `handleCoinChange` 시그니처 변경은 **운영자 UI의 수동 조정 폼** 변경을 수반. 구현 계획에서 호출부 전수 조사 필요 (`api/bootcamp.php` 라우팅 + 운영자 화면 JS).
- 마이그 이후 `reason_detail` 파싱에 의존하는 코드가 생기면 안 됨 — 이번 마이그 스크립트에서만 쓰고, 일반 런타임에서는 `coin_logs.cycle_id`와 `member_mission_checks.check_date`를 신뢰.
