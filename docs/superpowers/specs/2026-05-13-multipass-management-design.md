# 다회권 확인 (multipass management) — 설계

날짜: 2026-05-13
범위: boot.soritune.com (boot-dev → boot-prod)
배경: 11~13기 같은 묶음 상품(다회권)을 한 번에 구매한 회원 정보를 보관·조회·쿠폰 발급 추적할 데이터 모델과 어드민 탭이 없어, 운영팀이 외부 시트로 따로 관리하던 흐름을 사이트 안으로 옮긴다.

## 배경

- 부트캠프는 기본적으로 11기, 12기처럼 기수 단위로 판매한다.
- 일부 회원은 11기~13기, 9기~12기처럼 여러 기수를 한 번에 구매한 다회권 회원이다.
- 다회권 회원이 개인 사정으로 중간 기수를 쉬는 경우, 운영팀이 LMS 에서 해당 기수 쿠폰을 발급해 나중에 다시 참여할 수 있게 한다.
- 현재 boot 안에는 이 다회권 자체에 대한 데이터 모델이 없다. 운영팀이 외부 시트로 관리하다 보니, 어드민 회원관리 화면에서 "이 회원이 다회권인지", "어느 기수까지 했는지", "쿠폰 발급은 어디까지 됐는지" 즉시 확인 불가.
- LMS 와의 자동 연동은 본 작업 범위가 아니다. 쿠폰 발급은 LMS 에서 운영팀이 수동으로 진행하고, boot 사이트는 발급 사실만 체크박스로 추적한다.

## 정책 결정

사용자 의사 결정 요약:
- **입력 경로**: CSV/Excel 일괄 임포트 + 어드민 단건 UI 둘 다 (`bulk-register.js`, `admin-cafe-bulk.js` 패턴 차용).
- **회원 식별 키**: `user_id` (소리튠 아이디) 단독. boot 전체가 user_id 중심이므로 일관 ([feedback_boot_user_id_central]). user_id 없는 행은 임포트 거부.
- **데이터 분리**: 다회권은 순수 "권리" 메타만 저장. `bootcamp_members` row 는 자동 생성하지 않으며, 해당 기수 시작 시 운영팀이 기존 회원 등록 흐름으로 별도 생성.
- **수강 판정**: 포함 기수 중 "수강했다"는 판정은 `bootcamp_members` row 존재 여부 (`is_active`/`member_status` 무관). 실시간 EXISTS 로 derive, 별도 컬럼 저장 안 함.
- **쿠폰 발급 단위**: 다회권 × 기수 조합별 체크박스. `coupon_issued_at`/`coupon_issued_by` 자동 기록.
- **다수 보유**: 한 user_id 가 여러 다회권을 동시 보유 가능. 회원 검색 결과에 카드 N개 모두 노출.
- **상품명 관리**: 자유 텍스트(`product_name VARCHAR`). 별도 product 마스터 테이블 없음. 상품별 보기는 `DISTINCT product_name` 으로 그룹.
- **권한**: `operation` only (회원관리·쿠폰 정보를 다루므로 코치/팀장 비공개).
- **노출 위치**: `/operation` 의 새 탭 1개만. 회원 상세 패널 통합은 보류 (YAGNI).
- **데이터 규모**: 수십 건. CSV 임포트 1회 + 이후 어드민 UI 단건 보강.

## 변경 범위

### 신규 코드

| 파일 | 책임 |
|---|---|
| `migrate_multipass.php` | 1회용 마이그. `multipass`, `multipass_cohorts` 테이블 `CREATE TABLE IF NOT EXISTS` |
| `public_html/includes/multipass/multipass_repo.php` | DB 액세스. `findByUserIds`, `searchMembers`, `createPass`, `updatePass`, `deletePass`, `toggleCoupon`. 상품별 그룹 집계는 클라이언트 측 처리. |
| `public_html/includes/multipass/multipass_csv_parser.php` | CSV 입력 → `[{row, user_id, product_name, cohort_labels[], error?}]` 정규화 |
| `public_html/includes/multipass/multipass_bulk.php` | 검증 + 적용 (validate / apply 두 함수) |
| `public_html/js/admin-multipass.js` | 탭 IIFE (`AdminMultipassApp`). 회원별/상품별 sub-탭 + 추가/CSV 모달 |
| `tests/multipass_csv_parser_test.php` | CSV 파서 단위 (BOM, 헤더 자동 감지, cohort 라벨 분리/숫자 추출) |
| `tests/multipass_repo_test.php` | DEV DB 통합. CRUD + 토글 + UNIQUE/CASCADE |
| `tests/multipass_api_test.php` | DEV DB 통합. 9 액션 행복경로 + 권한 거부 + bulk validate/apply |
| `tests/multipass_invariants.php` | PROD smoke. orphan/중복/coupon at-by 일관성 |

### 수정

| 파일 | 변경 |
|---|---|
| `public_html/api/admin.php` | `multipass_*` 9 액션 추가 |
| `public_html/operation/index.php` | `<script src="/js/admin-multipass.js">` 추가 |
| `public_html/js/admin.js` | operation 탭 목록에 `다회권 확인` 탭 button + content div 추가, 탭 lazy load 분기에서 `AdminMultipassApp.init` 호출 |
| `public_html/css/admin.css` | 카드/배지/체크박스 스타일 (필요 최소). 가능하면 기존 `.dashboard-card`, `.tab-content` 재사용 |

## 데이터 모델

```sql
CREATE TABLE IF NOT EXISTS multipass (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       VARCHAR(100) NOT NULL  COMMENT '소리튠 아이디 (식별축, FK 안 검)',
  product_name  VARCHAR(100) NOT NULL  COMMENT '예: "11~13기 묶음권"',
  note          TEXT NULL              COMMENT '운영 메모',
  created_by    INT UNSIGNED NULL      COMMENT 'admins.id',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mp_user_id (user_id),
  KEY idx_mp_product (product_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multipass_cohorts (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pass_id           INT UNSIGNED NOT NULL,
  cohort_id         INT UNSIGNED NOT NULL,
  coupon_issued     TINYINT(1) NOT NULL DEFAULT 0,
  coupon_issued_at  DATETIME NULL,
  coupon_issued_by  INT UNSIGNED NULL,
  note              VARCHAR(255) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_pass_cohort (pass_id, cohort_id),
  KEY idx_mpc_cohort (cohort_id),
  CONSTRAINT fk_mpc_pass   FOREIGN KEY (pass_id)   REFERENCES multipass(id) ON DELETE CASCADE,
  CONSTRAINT fk_mpc_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

설계 메모:
- `multipass.user_id` 는 `bootcamp_members.user_id` 와 의미적으로 같지만 FK 는 안 검는다. 다회권은 boot 에 한 번도 등록 안 된 user_id 도 보유 가능해야 하기 때문.
- `multipass_cohorts.cohort_id` 는 `cohorts.id` FK + `ON DELETE RESTRICT`. cohort 삭제는 다회권 보호를 위해 차단(의도된 동작).
- `(pass_id, cohort_id)` UNIQUE → 동일 다회권 안 중복 기수 방지.
- "수강 여부"는 컬럼으로 저장하지 않는다. 매 조회 시 두 신호로 derive 한다 (실시간 정확성, 멤버 행 추가/삭제 시 자동 반영):
  - `has_member_row` = `EXISTS(SELECT 1 FROM bootcamp_members WHERE user_id=? AND cohort_id=?)`. **단순 행 존재 여부.**
  - `joined` = `EXISTS(... AND member_status IN ('active','leaving','out_of_group_management'))`. 환불 행은 자리를 차지하지 않은 것이라 제외. UI 의 ✅/⚪ 배지와 "남은 기수" 카운트의 기준.
  - 두 값이 다르면 (즉 row 는 있지만 모두 refunded) UI 는 회색 ⚪ 미수강 + 작은 회색 텍스트 "(환불)" 추가 표시.
  - `member_status` enum 출처: `bootcamp_members.member_status` (`active`/`out_of_group_management`/`leaving`/`refunded`).
- `coupon_issued_at`/`coupon_issued_by` 는 `coupon_issued=1` 토글 시 자동 채우고, 0 으로 되돌리면 둘 다 NULL.

## API (admin.php 신규 9 액션)

모두 `requireAdmin(['operation'])`.

| action | method | 입력 | 응답 |
|---|---|---|---|
| `multipass_list` | GET | `?user_id=`, `?product_name=`, `?cohort_id=` (모두 옵션) | `{passes: [{id, user_id, product_name, note, created_at, cohorts:[{cohort_id, cohort, start_date, is_active, coupon_issued, coupon_issued_at, coupon_issued_by_name, has_member_row, joined}]}]}` |
| `multipass_get` | GET | `?id=` | 위 단건 |
| `multipass_create` | POST | `{user_id, product_name, cohort_ids:[…], note?}` | `{id}` |
| `multipass_update` | POST | `{id, user_id?, product_name?, note?, cohort_ids?:[…]}` (cohort_ids 주면 diff INSERT/DELETE; user_id 변경은 오타/계정 이전 용도로 허용 — multipass_cohorts 그대로 묶여 쿠폰 이력 보존) | `{ok:true, removed_cohort_ids:[…], added_cohort_ids:[…]}` |
| `multipass_delete` | POST | `{id}` | `{ok:true}` |
| `multipass_toggle_coupon` | POST | `{pass_id, cohort_id, issued:bool}` | `{coupon_issued, coupon_issued_at, coupon_issued_by_name}` |
| `multipass_search_member` | GET | `?q=` (user_id/nickname/real_name/phone 부분일치) | `{members: [{user_id, profiles:[{nickname, real_name, phone, latest_cohort}], passes:[…동 list 포맷]}]}` |
| `multipass_bulk_validate` | POST | `{rows:[{user_id, product_name, cohorts:string}]}` | `{rows:[…with status: OK/WARN_NO_MEMBER/WARN_DUPLICATE_PASS/WARN_DUPLICATE_PASS_IN_BATCH/ERROR_*, existing_pass_id?, …], summary:{ok, warn, error}}` |
| `multipass_bulk_apply` | POST | `{rows:[…WARN 행은 mode 결정 포함]}` (≤200) | `{applied, failed:[{row, error}]}` |

**기존 액션 재사용** (신규 아님):
- `cohort_list` (admin.php 기존) — 추가 모달의 "포함 기수" 체크박스, CSV 검증의 cohort 라벨 매칭에서 cohorts 마스터로 사용. 신규 액션 만들지 않음.
- 상품별 보기는 클라이언트 측 `multipass_list` 응답을 `product_name` 으로 group → 카드 카운트/평균 산출. 데이터 규모(수십 건)에서 별도 `multipass_product_summary` 액션은 YAGNI.

핵심 규칙:
- `multipass_search_member` 는 `bootcamp_members` 에서 q 매칭 → distinct user_id → 그 user_id 들의 다회권 lookup. user_id 가 다회권에만 있고 멤버 행이 없는 경우도 직접 user_id 일치로 매칭 (q LIKE 가 user_id 포함하면).
- `multipass_search_member` 응답의 `profiles` 는 같은 user_id 가 여러 cohort 에 등록된 경우 cohort 별 nickname 변경 이력을 보여줄 수 있도록 array. UI 는 가장 최근(`latest_cohort`)을 헤더에 표시.
- `joined` flag 는 list/get/search 모든 응답에서 `EXISTS bootcamp_members` 로 매번 계산.
- `multipass_toggle_coupon` 은 `coupon_issued=1` 시 `coupon_issued_at=NOW()`, `coupon_issued_by=admin.id` 자동 set. `0` 으로 토글 시 둘 다 `NULL`.
- 감사 로그: 토글 액션에 한해 `multipass_cohorts.coupon_issued_at`/`coupon_issued_by` 자체 감사로 만족. 그 외 mutating 액션(`create`/`update`/`delete`/`bulk_apply`)에는 별도 로그를 남기지 않는다. 이유: `admin_action_logs` 테이블은 존재하지만 boot 코드 어디서도 INSERT 하지 않는다 (`grep -rn admin_action_logs public_html/` = 0건, helper 미존재). 다회권 액션을 위해 신규 helper 를 도입하면 작업 범위가 "감사 로그 인프라 신설"로 확장된다 → YAGNI. 운영자 1명 가정 + 데이터 수십 건 + 쿠폰 토글만 자체 감사가 있으면 충분. **만약 향후 감사 로그 인프라를 도입하면 별도 작업으로 처리**한다.

## CSV 임포트

### 템플릿

```
user_id,product_name,cohorts
3937726826@k,11~13기 묶음권,"11,12,13"
4114325139@n,5~7기 패키지,"5|6|7"
```

### 파서 규칙 (`multipass_csv_parser.php`)

- BOM/UTF-8 처리.
- 헤더 자동 감지: 첫 행이 `user_id`/`아이디`/`product_name`/`상품명`/`cohorts`/`기수` 같은 키워드 포함 시 헤더로 판정.
- `cohorts` 컬럼: 쉼표/파이프/슬래시 분리 → 각 토큰에서 `/(\d+)/` 추출 → cohorts 테이블의 `cohort='{n}기'` 로 매핑. 매칭 실패 시 row.error에 `cohort_label='{원본}' 식별 실패` 기록.
- 동일 파일 내 `(user_id, product_name)` 중복 → 자동 합치지 않는다. 두 행 모두 검증 단계로 보내고 `WARN_DUPLICATE_PASS_IN_BATCH` status + 같은 (user_id, product_name) 의 첫 행을 `target_pass_in_batch` 로 표기. 운영자가 행마다 mode 를 결정한다 (아래 적용 단계).

### 검증 단계 (`multipass_bulk_validate`)

| 조건 | status |
|---|---|
| user_id 비어있음 | `ERROR_NO_USER_ID` |
| product_name 비어있음 | `ERROR_NO_PRODUCT` |
| cohorts 토큰 0개 | `ERROR_NO_COHORTS` |
| cohort 라벨 식별 실패 (1개 이상) | `ERROR_COHORT_LABEL` (실패 라벨 목록 포함) |
| user_id 가 `bootcamp_members` 에 한 번도 없음 | `WARN_NO_MEMBER` (적용 가능, 노란 배지) |
| 같은 (user_id, product_name) 다회권이 DB 에 이미 존재 | `WARN_DUPLICATE_PASS` (운영자 결정 필요 · `existing_pass_id` 포함) |
| 같은 파일 내 동일 (user_id, product_name) 두 행 | `WARN_DUPLICATE_PASS_IN_BATCH` (운영자 결정 필요 · `target_pass_in_batch` = 같은 묶음의 첫 번째 행 번호) |
| 그 외 | `OK` |

### 적용 단계 (`multipass_bulk_apply`)

- 입력 행 ≤ 200 (`bulk-register` 기존 가드와 동일).
- 행 단위 try/catch + 행 단위 트랜잭션.
- `WARN_DUPLICATE_PASS*` 행은 운영자가 행마다 다음 세 mode 중 하나를 명시해 보낸다 (UI 에서 라디오):
  - `extend` — 기존 pass(`existing_pass_id` 또는 같은 묶음의 첫 행이 만든 pass)에 cohort 만 추가. `(pass_id, cohort_id)` UNIQUE 위반 시 그 cohort 만 무시 (이미 포함된 기수).
  - `new` — 새 multipass row 를 별도로 생성 (정말 같은 회원이 같은 상품명으로 두 건 보유하는 케이스).
  - `skip` — 이 행 적용하지 않음.
- 우선순위: DB 중복(`WARN_DUPLICATE_PASS`)이 배치 내 중복(`WARN_DUPLICATE_PASS_IN_BATCH`)보다 먼저 평가 → DB 에 이미 있으면 `existing_pass_id` 가 extend 의 기본 타깃. 둘 다 해당하면 운영자가 둘 중 하나를 명시 (`existing_pass_id` 가 채워져 있으면 그쪽 우선).
- 결과: `{applied:int, failed:[{row, error}]}`.

## UI / UX

탭: `/operation` 의 새 탭, `data-tab="#tab-multipass"`, `data-hash="multipass"`, 라벨 "다회권 확인". 위치는 "회원 관리"와 "조 배정" 사이.

탭 안 sub-탭 2개 + 액션 버튼 2개:

```
[회원별 보기] [상품별 보기]               [+ 다회권 추가] [CSV 일괄]
─────────────────────────────────────────────────────────────────
(sub-tab content)
```

### 회원별 보기 (기본)

```
검색: [user_id / 닉네임 / 실명 / phone _____________]   (300ms debounce)
─────────────────────────────────────────────────────
👤 user_id: 3937726826@k  (홍길동 / 길동이 / 010-1234-5678)
   ── 보유 다회권 (2건) ──

   ┌ 11~13기 묶음권   (2025-04-10 등록)         [수정] [삭제] ┐
   │ 포함 3기 · 수강 1 / 남은 2                                 │
   │ ✅ 11기  (수강 중)                       □ 쿠폰 발급        │
   │ ⚪ 12기  (미수강)                         ☑ 쿠폰 발급        │
   │                                            └ 2026-05-13 by 운영팀A │
   │ ⚪ 13기  (미수강)                         □ 쿠폰 발급        │
   └────────────────────────────────────────────────────────────┘
   ┌ 5~7기 묶음권   (2024-08-01 등록)          [수정] [삭제] ┐
   │ ...                                                         │
   └────────────────────────────────────────────────────────────┘
```

규칙:
- 검색은 `multipass_search_member?q=` 호출 → 매칭 user_id 별로 카드 묶음 렌더.
- 카드 헤더: `상품명 (created_at YYYY-MM-DD 등록)` + 우측 [수정] [삭제].
- "포함 N기 · 수강 X / 남은 Y" 요약 (Y = 포함 - 수강).
- 기수 row: 좌측 수강 배지 + cohort 이름 + 상태 텍스트 + 우측 쿠폰 체크박스.
  - 상태 텍스트: `joined=true` → "수강함" (✅ 녹색). `joined=false && has_member_row=true` → "환불" (⚪ 회색 + 작은 회색 "(환불)"). `joined=false && has_member_row=false` → "미수강" (⚪ 회색).
  - cohort 활성 여부는 cohort 이름 옆에 작은 회색 텍스트로 "(종료)" 표시 (`cohorts.is_active=0`).
  - "남은 기수" 카운트는 `joined=false` 인 기수 합 (환불/미수강 둘 다 포함 — 운영자가 쿠폰 발급 결정할 후보).
- 쿠폰 체크박스 클릭 = `multipass_toggle_coupon` 즉시 호출, 옵티미스틱 UI. 발급 시 그 row 아래에 `└ 2026-05-13 by 운영팀A` 한 줄 표시.
- 검색 결과 0건이면 "이 검색어로 매칭되는 다회권이 없습니다." + [+ 다회권 추가] 바로가기.

### 상품별 보기

```
상품 카드 그리드 (DISTINCT product_name)
┌ 11~13기 묶음권 ┐  ┌ 5~7기 묶음권 ┐  ┌ 9~12기 패키지 ┐
│ 구매자 12명     │  │ 구매자 8명     │  │ 구매자 5명       │
│ 평균 수강 1.4기 │  │ 평균 수강 2.8기│  │ 평균 수강 0.6기  │
└─────────────┘  └────────────┘  └─────────────┘

(카드 클릭 시 해당 상품 구매자 표 펼침)
─────────────────────────────────────────────
상품: 11~13기 묶음권 · 12명
┌─ user_id ──── 닉네임 ──── 11기 ── 12기 ── 13기 ── 남은 ─┐
│  3937...@k    길동이      ✅      ⚪🎟    ⚪      2     │
│  4114...@n    Bella       ✅      ✅      ⚪      1     │
│  ...                                                       │
└────────────────────────────────────────────────────────────┘
```

규칙:
- 상품 카드: `multipass_list` 응답을 `product_name` 으로 group, 평균 수강 = `AVG(joined_count_per_pass)`.
- 표 헤더의 "11기/12기/13기"는 그 상품에 포함된 cohorts 의 합집합. 각 셀에 ✅(joined) / ⚪(미수강) / 🎟(쿠폰 발급) 조합 배지.
- 행 클릭 시 회원별 보기로 점프 (해당 user_id 검색 prefil).

### [+ 다회권 추가] 모달

```
구매자 user_id: [_______________] [회원 조회]
                                  └ 매칭 시 닉네임/실명 표시. 미매칭 시 노란 배지 "boot 에 등록된 적 없는 user_id"
상품명:        [_____________________________]
포함 기수:     ☐ 9기  ☐ 10기  ☑ 11기  ☑ 12기  ☑ 13기
              (cohorts.start_date DESC, is_active=0 회색)
메모(선택):     [_______________________________]
                                                [취소] [저장]
```

### [CSV 일괄] 모달

`bulk-register.js` / `admin-cafe-bulk.js` 패턴 차용:

1. 템플릿 안내 + CSV/Excel 다운로드 버튼.
2. CSV paste (textarea) 또는 Excel 업로드 (xlsx.full.min.js 이미 로드되어 있음).
3. `multipass_bulk_validate` 호출 → 결과 표 (status 색상별, WARN 행은 운영자 결정 컨트롤).
4. "정상 + 운영자 확정 행" 적용 → `multipass_bulk_apply` → 성공/실패 요약.

### 색상 토큰

| 의미 | 색 |
|---|---|
| 수강함 | `#16a34a` (녹색 체크) |
| 미수강 | `#9ca3af` (회색) |
| 쿠폰 발급됨 | `#FF5E00` Soritune Orange (🎟) |
| 비활성 cohort | opacity 0.5 |
| WARN | `#f59e0b` (노란 배지) |
| ERROR | `#dc2626` (빨간 배지) |

기존 css 토큰과 일치 여부는 admin.css/common.css 에서 확인 후 같은 변수 재사용.

## 마이그레이션 / 배포

### 마이그 스크립트

```
$ cd /root/boot-dev && php migrate_multipass.php
- 두 테이블 CREATE TABLE IF NOT EXISTS
- SHOW CREATE TABLE 출력으로 확인
- 멱등 (이미 존재하면 변경 없음)
```

### 배포 순서 (CLAUDE.md 룰 준수)

1. boot-dev 에서 코드 작성 + `php migrate_multipass.php` (DEV DB).
2. 단위 테스트 + DEV smoke (CSV 임포트 1건, 검색, 쿠폰 토글).
3. dev push → ⛔ 사용자 확인 요청.
4. (사용자 명시 요청 시) main merge → push.
5. **boot-prod 에서 `php migrate_multipass.php` 먼저 실행 (PROD DB)** — 마이그는 멱등이고 테이블만 만들어 기존 코드에 무해. 새 코드가 의존하기 전에 스키마를 미리 준비.
6. boot-prod `git pull origin main` (코드 반영).
7. PROD smoke (다회권 1건 등록 → 검색 → 쿠폰 토글 → 상품별 보기).
8. `tests/multipass_invariants.php` PROD 실행.

순서 고정 이유: 5→6 가 아니라 6→5 가 되면 새 admin.js 가 `multipass_list` 호출 시 테이블 없음 → 503. 마이그를 먼저 적용하는 게 단일 안전 절차.

## 테스트 전략

### 단위/통합 (DEV DB)

| # | 시나리오 | 위치 |
|---|---|---|
| T1 | CSV 파서: BOM, 헤더 자동 감지, cohort 분리(쉼표/파이프), `/\d+/` 추출 | `multipass_csv_parser_test.php` |
| T2 | repo: createPass + multipass_cohorts 3행 트랜잭션 | `multipass_repo_test.php` |
| T3 | repo: 동일 (pass_id, cohort_id) INSERT → UNIQUE 위반 | 위 |
| T4 | repo: deletePass → multipass_cohorts CASCADE | 위 |
| T5 | repo: toggleCoupon issued=1 → at/by 채움, 0 → NULL | 위 |
| T6 | api: search_member q=user_id 부분일치 / 닉네임 / 실명 / phone | `multipass_api_test.php` |
| T7 | api: search 응답의 has_member_row / joined 분리 — refunded row 만 있는 cohort 는 has_member_row=true / joined=false 여야 함 | 위 |
| T8 | api: bulk_validate — user_id 미존재 = WARN, cohort 미식별 = ERROR | 위 |
| T9 | api: bulk_apply — 트랜잭션 부분 실패 시 정상 행만 적용, failed 사유 반환 | 위 |
| T10 | api: 권한 — operation 외 role(coach 등)로 모든 액션 호출 → 403 | 위 |

### PROD 인보리언트 (`multipass_invariants.php`)

- INV-1: orphan 0 — `SELECT COUNT(*) FROM multipass_cohorts mc LEFT JOIN cohorts c ON mc.cohort_id=c.id WHERE c.id IS NULL` = 0
- INV-2: 중복 0 — `SELECT pass_id, cohort_id, COUNT(*) FROM multipass_cohorts GROUP BY 1,2 HAVING COUNT(*)>1` = 0행
- INV-3: coupon 일관성 — `SELECT COUNT(*) FROM multipass_cohorts WHERE coupon_issued=1 AND coupon_issued_at IS NULL` = 0

### DEV 수동 smoke (배포 전)

- CSV 일괄 임포트: 5행 (정상 3 + WARN 1 + ERROR 1) → 검증 표 상태별 색상 OK → 적용 → 결과 요약 → DB 확인.
- 회원별 검색 → 카드 노출 → 쿠폰 체크 ON/OFF → 새로고침 후 상태 유지.
- 상품별 보기 → 상품 카드 → 표 펼침 → 행 클릭 → 회원별 보기 점프.

## 권한 / 감사

- 모든 액션 `requireAdmin(['operation'])`.
- 감사: 쿠폰 토글은 `multipass_cohorts.coupon_issued_at`/`coupon_issued_by` 자체 감사로 충분. 그 외 mutating 액션(create/update/delete/bulk_apply)에는 별도 로그를 남기지 않는다 (이유는 API 핵심 규칙 섹션 참고 — boot 에 감사 로그 인프라가 없고, 신설은 YAGNI).
- `multipass.created_by` + `created_at`/`updated_at` 으로 다회권 row 자체의 생성/최근 수정자/시각은 추적 가능. delete 후 흔적은 남지 않으므로 운영자가 신중히 사용 (UI 의 빨간 컨펌 모달이 안전망).

## Out of Scope

- LMS 연동(쿠폰 자동 발급/회수). boot 는 발급 사실만 추적.
- 회원 페이지(member/* 경로)에서 본인 다회권 보기. operation 전용.
- user_id 변경 시 다회권 일괄 마이그 도구. 발생 시 추가.
- 다회권 결제/주문 정보(가격, 결제 채널, 주문번호). 자유 텍스트 `note` 로만 저장 가능.
- 자동 알림(쿠폰 발급 시 알림톡). 별도 작업.
- 통계 대시보드(다회권 매출 추이 등). 상품별 카드의 카운트 정도만.

## 위험 / 모니터링

- **R1: cohorts 행 삭제 차단**. `multipass_cohorts` FK RESTRICT 때문에 운영자가 cohort 삭제 시도 시 차단된다. 의도된 동작이지만 cohort 관리 탭에서 친절한 에러 메시지가 필요할 수 있음. 영향 미미라 별도 처리는 발생 시 추가.
- **R2: 마이그/배포 순서 어긋남**. PROD 코드 pull 이 마이그보다 먼저 일어나면 첫 `multipass_list` 호출이 "테이블 없음" 503. 배포 순서 룰을 PROD 마이그 → 코드 pull 로 명시.
- **R3: 토글 race**. 동일 (pass_id, cohort_id) 동시 클릭은 운영자 1명 사용 가정상 무시. UPDATE 라 마지막 클릭이 승리.
- **R4: user_id 자유 텍스트**. 오탈자로 잘못된 user_id 가 들어가면 "검색해도 안 나옴". 추가/임포트 시 "boot 에 등록된 적 있는 user_id 인지" 사전 확인 + 미매칭 노란 배지로 운영자 인지 유도. 발견 시 `multipass_update` 의 `user_id` 변경으로 수정 (multipass_cohorts 가 pass_id 로 묶여 있어 쿠폰 이력은 보존).
