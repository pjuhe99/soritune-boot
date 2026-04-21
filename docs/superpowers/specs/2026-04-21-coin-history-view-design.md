# 회원용 코인 내역 화면 + Reward Group Cohort 단위 재편 — 설계서

- 작성일: 2026-04-21
- 대상: `boot.soritune.com` — 부트캠프 코인 시스템
- 선행 스펙: `2026-04-20-reward-groups-coins-design.md` (본 스펙에서 일부 정책을 뒤집음)

## 1. 배경 / 문제

두 개의 맞물린 문제를 한 번에 해결한다.

### 1.1 회원은 자기 코인의 "왜·언제"를 모른다

대시보드의 "코인 58" 숫자 하나만 보이고, 이게 어떤 활동에서 생겼는지, 언제 실제 적립금으로 받게 되는지는 회원 관점에서 불투명. 회원 지원 문의로 반복 유입.

### 1.2 선행 스펙의 11-12 합침 지급 모델이 실제 운영 모델과 어긋난다

2026-04-20 스펙은 "11기 cycle + 12기 cycle을 한 reward group으로 묶고 12기 cycle이 closed된 시점에 합쳐서 지급"하는 모델이다. 그러나 실제 운영 의도는:

- **각 cohort 종료 시점에 그 cohort의 cycle 코인만 적립금으로 지급** (11기 cohort 종료 시 11기 cycle 코인 지급)
- 4/20~4/26 (11기 cohort 끝자락 & 12기 cycle 시작) 기간 코인은 12기 cycle로 들어가고, **12기 cohort 종료 시 12기 cycle 코인과 함께** 지급
- 12기 cohort에 계속 참여하지 않는(하차/환불) 11기 회원의 12기 cycle 코인은 **소실** (이월되지 않음)

따라서 reward_group을 "cohort 단위"로 재편해야 한다. 11기 reward_group은 11기 cycle만, 12기 reward_group은 12기 cycle(+이후 생길 13기 cycle)만 포함.

## 2. 요구사항

### 기능

- 회원은 대시보드의 **"코인 N" stat 카드**를 탭하면 `/내코인` 상세 화면으로 진입한다.
- 상세 화면은 **현재 참여 중인(open) reward_group의 earn log**를 cycle별 카드로 분리 표시:
  - 각 cycle 카드: 제목("11기 코인"), 합계(예: `50`), 지급 시점 안내 배너, earn log 리스트.
  - 로그 항목: 날짜 · 사유 라벨 · 증감값.
- 회원이 여러 open reward_group에 코인을 갖고 있으면 (11기 cycle 1개 group + 12기 cycle 1개 group, cohort 전환기 회원의 일반적 상태) **두 group의 cycle을 모두 표시** — group 경계는 숨기고 cycle 카드만 나열.
- 이미 지급 완료된 과거 reward_group은 이 화면에 표시하지 않음 (스코프 A).

### 비기능

- 선행 스펙이 만든 `reward_groups`, `reward_group_distributions`, `coin_cycles.reward_group_id`, `syncMemberCoinBalance` 변경, `handleCoinChange` 개편은 **그대로 유지**. 본 스펙은 reward_group의 **할당 단위만 변경**한다 ("11+12 묶음" → "cohort별 분리").
- 기존 `migrate_split_cycle_11_12.php`가 만든 "11-12기 리워드" group을 **새 마이그레이션으로 재편**: 11기 cycle만 남기고, 12기 cycle은 새 12기 group으로 이동. group 이름도 "11기 리워드" / "12기 리워드" 로 분리.
- 화면 API는 회원 인증(기존 bootcamp 로그인) 하에 자기 자신 데이터만 조회.

### 권한

- 본인 코인 내역 조회: 로그인한 본인. 다른 회원 내역 조회 불가.

## 3. 정책 변경 (선행 스펙 대비)

### 3.1 Reward Group 할당 규칙

- **Before (2026-04-20 스펙)**: 하나의 reward_group에 항상 **2개 cycle** ("전 cohort 말 cycle" + "현 cohort 초 cycle"). 두 cycle 모두 closed일 때만 지급.
- **After (본 스펙)**: 하나의 reward_group = **한 cohort의 coin cycle(들)**. 대부분 1개 cycle. cohort 기간 중 cycle이 여러 개로 쪼개지면 여러 개 포함 가능하지만 상한은 정하지 않음.

### 3.2 지급 사전조건

- **Before**: cycle 정확히 2개 AND 두 cycle 모두 closed.
- **After**: group에 소속된 **모든** cycle이 `status='closed'` (cycle 개수 제약 제거).

### 3.3 하차자 처리

- 지급 실행 시 회원 필터: `bootcamp_members.is_active = 1 AND member_status NOT IN ('refunded','leaving','out_of_group_management')`.
- 해당 필터에서 탈락한 회원의 해당 group 코인은 **소실** — `reward_group_distributions`에 INSERT하지 않고, `member_cycle_coins.used_coin = earned_coin`으로만 닫아버림 (회계 보존). `coin_logs`에는 group의 **각 cycle별로** `reason_type='reward_forfeited'`, `coin_change = -(해당 cycle의 earned - used_before)`, `reason_detail = "하차자 코인 소실 ({group.name})"` 기록. `cycle_id`는 각 해당 cycle의 id.
- `reason_type` 값은 VARCHAR(50)이므로 ENUM 제약 없음 — 신규 값 추가만으로 작동.

### 3.4 선행 스펙의 불변식 수정

- 섹션 3.4 "group당 cycle 최대 2개" 제약 **제거**.
- 섹션 3.4 "지급 사전조건: cycle 정확히 2개" **→ "모든 cycle이 closed"로 변경**.
- 선행 스펙 섹션 4.1의 `current_reward_group` 응답 필드는 지금까지 **한 group**만 반환했음. 본 스펙에서는 회원이 여러 open group에 코인을 가질 수 있는 게 정상 케이스가 되므로, **복수 반환 가능한 신규 API**를 추가 (`my_coin_history`). 기존 `current_reward_group` 필드는 legacy 호환을 위해 유지하되, 운영자 memberTable 화면에서만 사용한다.

## 4. 데이터 모델

### 4.1 스키마 변경

선행 스펙의 테이블을 그대로 유지. 필드 추가/삭제 없음.

### 4.2 데이터 마이그레이션

`migrate_split_11_12_groups.php` (1회 실행, `--dry-run` 지원, 트랜잭션):

1. 기존 "11-12기 리워드" group을 찾아 `UPDATE name = '11기 리워드'`.
2. 새 "12기 리워드" group INSERT (`status='open'`).
3. 12기 cycle의 `reward_group_id`를 새 group으로 `UPDATE`.
4. 검증:
   - 11기 group 소속 cycle = 1개 (11기 cycle만).
   - 12기 group 소속 cycle = 1개 (12기 cycle만).
   - 기존 `member_cycle_coins` 데이터 불변.
   - 기존 `coin_logs` 데이터 불변.
5. 실패 시 rollback.

## 5. API

### 5.1 신규 엔드포인트: `my_coin_history`

- Path: `GET /api/bootcamp.php?action=my_coin_history`
- 인증: bootcamp 로그인 세션의 `member_id` 사용.
- 응답:

```json
{
  "success": true,
  "groups": [
    {
      "group_id": 1,
      "group_name": "11기 리워드",
      "cycles": [
        {
          "cycle_id": 2,
          "cycle_name": "11기",
          "cycle_status": "closed",
          "earned": 50,
          "payout_message": "11기 마감 후 곧 적립금으로 지급됩니다",
          "logs": [
            {"date": "2026-04-18", "reason_type": "leader_coin", "label": "리더 코인", "change": 40},
            {"date": "2026-04-16", "reason_type": "study_join",  "label": "복습스터디 참여", "change": 2},
            {"date": "2026-04-15", "reason_type": "study_open",  "label": "복습스터디 개설", "change": 5}
          ]
        }
      ]
    },
    {
      "group_id": 2,
      "group_name": "12기 리워드",
      "cycles": [
        {
          "cycle_id": 3,
          "cycle_name": "12기",
          "cycle_status": "active",
          "earned": 8,
          "payout_message": "12기 마감 시 적립금으로 지급됩니다 (다음 기수에 함께 정산)",
          "logs": [
            {"date": "2026-04-21", "reason_type": "cheer_award", "label": "응원상", "change": 1},
            {"date": "2026-04-21", "reason_type": "study_open",  "label": "복습스터디 개설", "change": 5},
            {"date": "2026-04-20", "reason_type": "study_join",  "label": "복습스터디 참여", "change": 2}
          ]
        }
      ]
    }
  ]
}
```

- 대상 group 선택 쿼리: `reward_groups.status = 'open'` AND 회원이 해당 group의 어느 cycle에든 `member_cycle_coins.earned_coin > 0` row를 가짐.
- 정렬: group은 `cycles[0].start_date ASC` (과거 cohort 먼저). 11기가 위, 12기가 아래.
- cycle 내 `logs`: `coin_logs.created_at DESC` (최신 먼저). 페이징 없음 — 한 cycle당 로그 수는 ≤ 30 내외로 예상.
- `earned` = `member_cycle_coins.earned_coin - member_cycle_coins.used_coin` (지급 전 = `used_coin`이 0).
- `logs`는 **해당 cycle의 모든 coin_logs** (양수·음수 포함). 음수 로그(체크 해제 등)도 그대로 보이게 하여 합계 정합성 유지.
- `payout_message`는 서버에서 생성:
  - cycle `status='closed'` AND group `status='open'`: "{cycle_name} 마감 후 곧 적립금으로 지급됩니다"
  - cycle `status='active'`: "{cycle_name} 마감 시 적립금으로 지급됩니다 (다음 기수에 함께 정산)"
- 회원에게 보유 코인이 있는 open group이 **0개**이면 `groups: []` 반환. 프론트는 "아직 받은 코인이 없습니다" 표시.
- `legacy cycle` (reward_group_id=NULL) 코인은 이 응답에 포함 **안 됨** — 회원 불만 여지가 있지만 현재 운영 상황에서는 해당 케이스 없음 (이번 마이그 후 기존 cycle은 모두 group에 할당).

### 5.2 기존 엔드포인트 영향

- `coin_reward_group_distribute` (운영자 지급 실행): 섹션 3.3의 하차자 필터 + cycle 개수 제약 제거 반영.
- `coin_reward_group_preview`: 마찬가지로 cycle 수 제약 제거. `blockers` 문구에서 "cycle이 정확히 2개여야 함" 라인 삭제.
- `coin_reward_groups` (운영자 리스트): 변경 없음.

### 5.3 Reason Type 한글 라벨

프론트/백엔드 공통 테이블 (PHP는 `api/services/coin.php` 근방 상수, JS는 `member-home.js` 또는 신설 파일에):

| reason_type | 라벨 |
|---|---|
| `study_open` | 복습스터디 개설 |
| `study_join` | 복습스터디 참여 |
| `leader_coin` | 리더 코인 (조장/부조장) |
| `perfect_attendance` | 찐완주 보너스 |
| `hamemmal_bonus` | 하멈말 보너스 |
| `cheer_award` | 응원상 |
| `manual_adjustment` | 운영자 조정 |
| `reward_distribution` | 적립금 지급 |
| `reward_forfeited` | 하차로 인한 소실 |

서버 응답의 `label` 필드에 서버가 직접 넣어 보냄 — 클라이언트는 그대로 렌더. `reason_detail`은 회원에게 노출하지 않음 (내부 메타).

음수 로그(`coin_change < 0`)의 경우 라벨 뒤에 "(취소)"를 붙여 구분 — 예: `study_open` + `-5` → "복습스터디 개설 (취소)". 서버에서 처리.

## 6. UI

### 6.1 접근 경로

`member-home.js`의 `stat-coin` 카드 전체를 클릭 가능한 요소로 만든다 (`?` 버튼은 기존대로 유지, 별도 이벤트 stopPropagation). 카드 클릭 → `/내코인` 라우트로 이동.

### 6.2 `/내코인` 화면

모바일 우선 레이아웃. 목업 기준(2026-04-21 브레인스토밍 옵션 A):

```
┌─ 헤더 ──────────────────────────┐
│ ← 뒤로     내 코인 내역          │
└──────────────────────────────────┘

┌─ 11기 코인 카드 ────────────────┐
│ 11기 코인                   50  │
│ ┌────────────────────────────┐ │
│ │ 11기 마감 후 곧 적립금으로  │ │
│ │ 지급됩니다                  │ │
│ └────────────────────────────┘ │
│ 4/18  리더 코인            +40 │
│ 4/16  복습스터디 참여       +2 │
│ 4/15  복습스터디 개설       +5 │
│ …                               │
└──────────────────────────────────┘

┌─ 12기 코인 카드 ────────────────┐
│ 12기 코인  [적립 중]         8  │
│ ┌────────────────────────────┐ │
│ │ 12기 마감 시 적립금으로 지급 │ │
│ │ 됩니다 (다음 기수에 함께)    │ │
│ └────────────────────────────┘ │
│ 4/21  응원상                +1 │
│ 4/21  복습스터디 개설       +5 │
│ 4/20  복습스터디 참여       +2 │
└──────────────────────────────────┘
```

렌더링 규칙:
- 배너 색상: `cycle_status='closed'` → warm/amber, `active` → cool/blue.
- 합계 숫자: 음수 로그 포함 net 값. API `earned` 필드.
- 로그: 날짜는 `MM/DD` 포맷. 한 항목은 한 줄 (mobile readable).
- `groups: []`이면 "아직 받은 코인이 없습니다. 복습스터디에 참여해 보세요." 빈 상태.

### 6.3 라우팅

- 새 JS 모듈 `public_html/js/member-coin-history.js` 신설.
- 기존 member.js의 화면 전환 방식(탭/섹션 토글)을 따름. 정확한 라우팅 방식(hash vs 섹션 토글)은 구현 단계에서 member.js의 실제 패턴을 확인하여 맞춘다.
- 뒤로가기: 브라우저 뒤로가기 또는 화면 내 "← 뒤로" 버튼. 어느 쪽이든 대시보드 홈으로 복귀.

### 6.4 기존 `coin_guide` 버튼

`stat-coin` 카드 우측의 `?` 버튼은 **변경 없음** — 기존대로 `coin_guide` 마크다운 도움말 모달을 띄운다. 새 상세 화면과는 별개 기능.

## 7. 마이그 경로

### 7.1 DEV

1. 스펙 구현: `my_coin_history` API + 신규 JS 모듈 + 마이그 스크립트.
2. `migrate_split_11_12_groups.php` dry-run → 검증 → 실제 실행.
3. `coin_reward_group_distribute` / preview의 cycle 수 제약 제거 반영.
4. 하차자 필터 반영.
5. 회원 화면 / 운영자 화면 회귀 테스트.

### 7.2 PROD

사용자의 "운영 반영" 명시적 요청 후에만 실행. `mysqldump` 백업 → 코드 pull → 마이그 dry-run → 실행.

### 7.3 롤백

- 마이그는 트랜잭션. 실패 시 자동 rollback.
- 코드는 git revert로 복구 가능.

## 8. 스코프 외

- **과거 지급 이력(적립금 수령 이력) 회원 조회**: 본 스펙의 "scope A" 결정에 따라 이번 범위에서 제외. 필요 시 별도 기획.
- **코인 알림 (이메일/푸시)**: 별도 기획.
- **여러 open group의 cycle 중 legacy(reward_group_id=NULL) 잔액 표시**: 현재 운영 데이터상 해당 없음. 필요 시 별도.
- **reward_forfeited 로그를 회원 본인이 볼 수 있는 뷰**: 이번 scope 외 (회원이 이미 하차한 상태).

## 9. 리스크 / 주의

- **선행 스펙의 "11-12 합침 지급" 모델이 이미 일부 구현/마이그된 상태**. 본 스펙은 이를 뒤집으므로, 구현 순서: (1) 신규 마이그 먼저 실행하여 group 재편, (2) cycle 수 제약/하차자 필터 코드 반영, (3) UI/API 추가. 순서가 틀어지면 "11-12기 리워드" group이 남은 채 cycle 수가 1로 바뀌어 기존 preview에 blocker 표시 이상 동작 가능.
- 하차자 정책("코인 소실")은 회원 분쟁 여지. 운영 FAQ/약관에 명시 필요.
- 회원이 여러 open group에 코인 분산된 상태에서 운영자 memberTable은 기존 `current_reward_group` 필드만 보므로 **한 group만 노출**. 이 제한은 운영자가 어차피 /operation/#coins 화면에서 전체 group을 볼 수 있어 실무적으로는 문제 없음.
- `payout_message` 카피: "다음 기수에 함께 정산"이라는 문구는 회원이 **12기 cohort에 계속 참여할 예정이라는 전제**. 하차하면 소실된다는 안내는 이 화면 바깥(부트캠프 약관/가이드)에서 별도 커뮤니케이션 필요. 본 화면에는 간결함 유지를 위해 넣지 않음.
