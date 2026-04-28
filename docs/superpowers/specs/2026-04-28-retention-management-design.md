# 리텐션 관리 탭 설계

**작성일**: 2026-04-28
**대상**: `boot.soritune.com/operation` (운영팀 페이지)
**상태**: 설계 확정, 구현 계획 수립 전

## 1. 목적

기수 간 회원 잔존을 N→N+1 페어 단위로 분석한다. 운영팀이 다음 질문에 답할 수 있게 한다.

- 직전 기수에서 다음 기수로 몇 %가 넘어왔는가?
- 다음 기수의 인원이 잔존·회귀·신규로 어떻게 구성되는가?
- 한 기수에 시작한 회원이 이후 기수에 얼마나 남는가 (코호트 잔존 곡선)?
- 조·점수·누적 참여 횟수에 따라 잔존율이 어떻게 다른가?

## 2. 핵심 정의

### 2.1 페어와 매칭

- **페어** = `(N, next)` 두 인접 기수. `cohorts.start_date` 오름차순으로 N의 다음 row를 next로 잡는다.
- **anchor** = N (전 기수). 분석의 기준 코호트.
- **매칭 키** = `bootcamp_members.user_id`.
  - `user_id` NULL/빈값인 회원은 분모·분자 모두에서 제외한다 (2026-04-28 user_id fill 작업 후 PROD 0건, DEV 8건 잔존 — 잘못된 phone 번호로 매칭 불가한 케이스).
  - 같은 user_id가 같은 기수에 중복 row인 경우 `COUNT(DISTINCT user_id)`로 1명 처리.
- **페어 노출 조건**: `anchor.total_with_user_id > 0 AND next.total_with_user_id > 0`. 양쪽 모두 분석 가능한 회원이 있어야 페어 목록에 포함. 어느 쪽이든 user_id 보유자 0이면 분모 0 / 매칭 불가이므로 제외.
- **member_status 필터링 없음**. 등록 자체로 참여 카운트 (refunded·leaving·out_of_group_management 모두 포함). 운영자가 환불자 분리를 원하면 후속 토글 추가 가능.

### 2.2 사용자 집합

```
U_N      := DISTINCT user_id where cohort_id = N            (anchor 가입자)
U_next   := DISTINCT user_id where cohort_id = next         (다음 기수 가입자)
U_past_N := DISTINCT user_id where cohorts.start_date < N's (anchor 이전 모든 기수)
```

### 2.3 상단 4카드 — 다음 기수 인원의 분류

다음 기수의 각 user_id를 한 카테고리로 배정한다.

| 카드 | 정의 | 의미 |
|------|------|------|
| **잔존** | `U_next ∩ U_N` | 직전 기수에서 다음 기수로 넘어옴 |
| **회귀** | `(U_next ∩ U_past_N) − U_N` | 과거 기수 경험 있으나 직전 기수엔 없었다가 복귀 |
| **신규** | `U_next − U_past_N − U_N` | 첫 참여 (이전 기수 row가 없는 사람) |
| **합계** | `|U_next|` | 셋의 합과 일치해야 함 (불변 검증) |

**참고**: 신규 정의는 "이전 기수 user_id 집합에 없음"이 기준. `participation_count=1`은 논리적으로 같은 의미지만 데이터 보정/누락/중복 row 등으로 어긋날 수 있으니 일치 여부는 단정하지 않고 테스트로 검증한다 (§ 6 불변 참고).

**리텐션 %** = `잔존 / |U_N| × 100` (분모는 anchor 인원)

### 2.4 코호트 잔존 곡선 (GA4 스타일, step-independent)

anchor=N 이후 모든 기수 C에 대해:

```
잔존_C = |U_N ∩ U_C| / |U_N| × 100
```

- **step-independent**: 한 기수 빠진 후 돌아와도 잔존으로 카운트 (GA4 표준 정의 동일)
- X축: 기수 (N, N+1, N+2, ...). N은 100%로 시작.
- Y축: 잔존율 (%)
- anchor=10기처럼 다음 기수가 1개뿐이면 점 1개만 표시. 그래프 자체는 그대로 그림.

### 2.5 Breakdown 3종

#### 2.5.1 조별 (anchor의 조 데이터 있을 때만)

- 표시 조건: `EXISTS bootcamp_groups WHERE cohort_id = N`
- 행 분류:
  - anchor 기수의 각 조 (정상)
  - **"미배정"**: `bm.group_id IS NULL`
  - **"조 정보 이상"**: `bm.group_id NOT NULL` 이지만 그 group이 anchor cohort에 속하지 않는 경우 (다른 기수 group을 가리키는 데이터 오류). 정상 운영에선 0건 기대, 발견 시 운영자가 인지하도록 별도 행으로 분리 표시.
- 정렬: 정상 조는 `bg.stage_no, bg.id`. "미배정" / "조 정보 이상"은 마지막에.
- 컬럼: 조 이름 / anchor 인원 / 다음기수 진출자 / 진출률(%)

#### 2.5.2 점수 범위 (anchor의 점수 데이터 충분할 때만)

- 구간: `0점` / `-1~-10` / `-11~-24` / `-25 이하`
- 점수 컬럼: `member_scores.current_score` (anchor가 종료된 시점이라 final ≈ current)
- **분모는 점수 row 보유자 기준**. 무점수 회원은 점수 카드에서 제외 (다른 카드/요약은 포함됨).
- 표시 조건:
  - `EXISTS bm JOIN ms WHERE bm.cohort_id = N`
  - **AND** anchor 기수에서 `member_scores` row가 있는 회원 수 ≥ anchor 전체 회원 수 × 50% (sparse data 차단: 6기 1명, 10기 단일값 케이스)
- 컬럼: 구간 / anchor 인원(점수 보유자) / 다음기수 진출자 / 진출률(%)
- 응답 메타: `coverage_pct` (점수 보유 비율), `scored_total` (점수 보유자 수). 카드 헤더에 "점수 보유 N명 (anchor의 X%) 기준" 표시로 무점수 제외 사실을 명시.

#### 2.5.3 누적 참여 횟수 (모든 anchor에서 활성)

- 컬럼: `bootcamp_members.participation_count` (그 기수 가입 시점의 누적 횟수)
- 구간: `1회 (신규)` / `2~3회` / `4~6회` / `7회 이상`
- 컬럼: 구간 / anchor 인원 / 다음기수 진출자 / 진출률(%)

## 3. UI 레이아웃

```
[운영팀 헤더]
[기존 탭들...] [리텐션 관리]

기수 페어 선택  (가로 스크롤)
[1→2기] [2→3기] ... [10→11기]   ← 다음 기수 등록자 ≥1명인 anchor만 표시

▎11기 → 12기 리텐션  (anchor 224명 / 12기 225명)         조회시각: 2026-04-28 14:32 [↻]

[잔존 32% 72명] [회귀 18% 40명] [신규 50% 113명] [12기 총 225명]

▎코호트 잔존 곡선 (anchor=11기 → 이후 기수)
   라인 차트: X=기수, Y=% (step-independent)
   ※ 직전·과거 모두 포함

▎상황별 리텐션 breakdown
   ┌── 조별 ─────────────────────────┐
   │ 헤어조  28명  9 진출  ████░ 32%   │   ← anchor에 조 데이터 있을 때만
   │ ...                              │
   └─────────────────────────────────┘
   ┌── 점수 범위 (anchor 종료시점) ───┐
   │ 0점   107  38  ████ 36%         │   ← 점수 row≥50%일 때만
   │ ...                             │
   └─────────────────────────────────┘
   ┌── 누적 참여 횟수 ───────────────┐
   │ 1회 신규  78  24  ███ 31%       │   ← 항상 표시
   │ ...                             │
   └─────────────────────────────────┘
```

- 데이터 없는 breakdown 카드는 영역 자체를 숨김 (자리 차지 안 함)
- 모바일: 4 요약 카드 1열 stack, 곡선/breakdown 풀 폭

**기본 선택 페어**: 진입 시 가능한 페어 중 가장 최근 anchor 자동 선택 (예: 12기에 등록자 있으면 11→12, 없으면 10→11)

## 4. 아키텍처

### 4.1 파일 구성

| 파일 | 종류 | 역할 |
|------|------|------|
| `api/services/retention.php` | 신규 | 5종 SQL 실행, 한 응답에 묶어 반환 |
| `js/retention.js` | 신규 | 탭 init, 페어 버튼, 카드/곡선/breakdown 렌더 |
| `css/retention.css` | 신규 | 카드/표/막대 스타일 (admin.css·notify.css 토큰 재사용) |
| `tests/retention_test.php` | 신규 | 픽스처 기반 불변 검증 |
| `api/admin.php` | 수정 | retention 액션 라우팅 추가 |
| `js/admin.js` | 수정 | 탭 1개·DOM 1개 추가, lazy-load 분기 |
| `operation/index.php` | 수정 | `<script src=js/retention.js>` 태그 추가 |

### 4.2 API 엔드포인트

```
GET /api/admin.php?action=retention_pairs
→ { pairs: [
    { anchor_cohort_id, anchor_name, anchor_total_with_user_id,
      next_cohort_id,   next_name,   next_total_with_user_id },
    ...
  ] }
  // 정렬: anchor.start_date ASC (오래된 → 최신, 화면 좌→우)
  // 노출 조건: anchor.total_with_user_id > 0 AND next.total_with_user_id > 0

GET /api/admin.php?action=retention_summary&anchor_cohort_id=N
→ {
    anchor: { id, name, total, total_with_user_id },
    next:   { id, name, total, total_with_user_id },
    cards:  {
      stay, returning, brand_new,
      retention_pct,                   // 잔존 / anchor.total_with_user_id × 100
      next_total_with_user_id,         // = stay + returning + brand_new (불변)
      excluded_null_user_id,           // user_id 미입력 회원 수 (anchor + next 합산)
    },
    curve:  [{ step, cohort_id, cohort_name, count, pct }, ...],
                                        // step=0은 anchor 자체 (count=anchor.total_with_user_id, pct=100)
                                        // step=1은 next 기수 (count=stay)
    breakdown: {
      group:         null | {
                       rows: [{ name, total, transitioned, pct, kind }, ...],
                                        // kind: "group" | "unassigned" | "anomaly"
                     },
      score:         null | {
                       coverage_pct,    // 점수 보유 비율 (anchor 기준)
                       scored_total,    // 점수 보유자 수
                       rows: [{ band, total, transitioned, pct }, ...],
                     },
      participation: { rows: [{ bucket, total, transitioned, pct }, ...] }
    },
    generated_at: "2026-04-28T14:32:00+09:00"
  }
```

`breakdown.group` / `breakdown.score`가 `null`이면 프론트는 해당 카드를 숨김. `breakdown.participation`은 항상 활성.


### 4.3 권한

- `requireAdmin(['operation'])` — 기존 운영팀 액션과 동일 패턴.

### 4.4 캐싱

- 미적용. SQL 5개 전체 실행이 ms 단위, 호출자가 운영자 1~2명 수준.

## 5. 에지 케이스 정책

| 케이스 | 처리 |
|--------|------|
| anchor 또는 next의 `total_with_user_id`가 0 | 페어 목록에서 자동 제외 (분모 0 / 매칭 불가) |
| 페어 목록 비어 있음 | "분석 가능한 페어가 없습니다" 안내 |
| user_id NULL/빈값 회원 | 분모/분자 제외, 화면 하단에 `excluded_null_user_id` 작은 안내 표시 |
| 같은 user_id 동일 기수 중복 row | `COUNT(DISTINCT)`로 1명 처리 |
| 점수 데이터 sparse (anchor 보유 비율 < 50%) | 점수 카드 비활성화 + 안내 |
| 조 미배정 회원 (group_id NULL) | 조별 breakdown에 "미배정" 행으로 표시 |
| 조 정보 이상 (group_id가 다른 기수의 group을 가리킴) | "조 정보 이상" 행으로 별도 표시 (운영자 인지용) |
| 다음 기수가 진행 중 | "조회 시각" 표시 + 새로고침 버튼 |
| 잔존 곡선 1점뿐 | 점 + 라벨 그대로 표시 |
| 환불자 (`member_status='refunded'`) | 등록자로 카운트 (분리 토글은 향후 요청 시) |

## 6. 불변 검증 (자동 테스트)

```
# 카드/요약
cards.stay + cards.returning + cards.brand_new = next.total_with_user_id
cards.retention_pct = cards.stay / anchor.total_with_user_id × 100
cards.brand_new   = COUNT(next 회원 중 participation_count=1)   (검증용)
                    # 데이터 보정 등으로 어긋나면 경고 로그, 본 화면은 집합 정의를 따름

# 곡선
curve[0] = { step:0, cohort_id:anchor.id, count:anchor.total_with_user_id, pct:100 }
curve[1].count = cards.stay
curve[1].pct   = cards.retention_pct
curve[k].count ≤ anchor.total_with_user_id  (모든 k≥1)

# breakdown
누적참여횟수 total 합        = anchor.total_with_user_id        (항상 활성, 100% 커버)
누적참여횟수 transitioned 합 = cards.stay
조별 카드 활성 시:  total 합 = anchor.total_with_user_id, transitioned 합 = cards.stay
점수별 카드 활성 시: total 합 = scored_total (≤ anchor.total_with_user_id, sparse 허용)
                   transitioned 합 ≤ cards.stay (점수 보유자 중 잔존자만 포함)
```

## 7. 테스트 전략

- **단위**: `tests/retention_test.php` — 픽스처 데이터로 위 6개 불변을 검증.
- **통합 스모크**: 모든 anchor에 대해 5종 SQL이 에러 없이 실행되는지.
- **수동 검증**: PROD 11기 anchor 결과를 운영자 직관과 비교.

## 8. 배포

CLAUDE.md 정책 준수:

1. DEV(boot-dev)에서 구현 → 동작 검증
2. dev push 후 사용자에게 `dev-boot.soritune.com/operation` 확인 요청 → ⛔ 멈춤
3. 승인 후 main 머지 + BOOT pull
4. **DB 마이그레이션 없음** — 신규 테이블/컬럼 0개

## 9. 결정 이력 (브레인스토밍 요약)

| # | 결정 | 채택안 |
|---|------|--------|
| Q1 | "넘어왔다" 정의 | (A) 단순 등록 매칭 |
| Q2 | user_id NULL 처리 | 매칭 키는 `user_id` 단독. 사전 작업으로 phone 매칭 기반 fill 스크립트를 1회 돌려 DEV 80 row 보정 완료 (잘못된 phone 패턴 8건은 NULL 잔존, 분석에서 제외). PROD는 적용 시점 NULL 0건이라 미실행. 본 화면 매칭 로직에는 phone fallback이 들어가지 않는다. |
| Q3 | GA4 유지율 시간축 | (A) 다단계 코호트 잔존 (anchor=N → N+k) |
| Q4 | 점수 데이터 / 구간 | `member_scores.current_score`, 4구간 그대로 (`0` / `-1~-10` / `-11~-24` / `-25 이하`) |
| Q5 | 조별 breakdown 가능 anchor | anchor에 조 데이터 있는 모든 기수 (현재 11기, 미래 12기+) |
| Q6 | 누적 참여 횟수 구간 | 4구간 (`1회 신규` / `2~3회` / `4~6회` / `7회 이상`) |
| Q7 | 신규 정의 | (C) 3분할 (잔존/회귀/신규) |
| Q8 | 잔존 곡선 단계 매칭 | (I) Step-independent (GA4 표준) |

## 10. 비목표 (Out of scope)

- 점수가 +로 가는 상황의 별도 처리 (현재 데이터에 없음)
- 환불자 별도 분리 토글
- 캐시 / 비동기 사전 계산
- 잔존 곡선의 in-cohort 일별 활동 그래프 (옛 기수 활동 데이터 부정확)
- 회원 단위 drill-down (조별 클릭 → 회원 목록 등)
- Excel/CSV 내보내기
