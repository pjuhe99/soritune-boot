# 어드민 대시보드 — 적응기간(1~3일차) 노출

**Date:** 2026-05-14
**Scope:** boot.soritune.com (DEV_BOOT → BOOT)
**Files in scope:** `public_html/api/services/dashboard.php`, `public_html/js/bootcamp.js`

## 1. 배경 / 문제

- 기수 시작 후 `SCORE_ADAPTATION_DAYS = 3`일은 "적응기간"으로 감점이 적용되지 않는다.
- 현재 어드민 대시보드(`기수 전체 과제율 / 조별 비교 / 점수 분포 / 경고 멤버`)는 `scoring_start = cohort.start_date + 3일` 이후 어제까지의 범위에서만 데이터를 집계한다.
- 결과적으로 1~3일차에는 `dashboard_stats` 응답이 `cohort_summary: null`로 떨어지고, 프론트는 "아직 채점 기간이 시작되지 않았습니다" 빈 화면을 보여준다.
- 운영진이 1~3일차의 회원별 미션 수행 현황을 미리 보고 케어할 수 없는 문제. 감점은 4일차부터지만, **현황 가시성**은 1일차부터 필요하다.

## 2. 목표 / 범위

### 한다

- 어드민 대시보드가 `cohort.start_date`부터 어제까지의 데이터를 집계해서 적응기간(1~3일차)에도 동일한 UI로 표시되도록 한다.
- 적응기간에는 상단에 `⏳ 적응기간 중 — 감점은 YYYY-MM-DD부터 적용` 안내 배지를 표시한다.
- 점수 분포/경고 멤버 섹션은 그대로 노출한다(적응기간엔 자연스레 모두 0점 버킷에 몰림).

### 안 한다

- `bootcamp_functions.php::recalculateMemberScore` 감점 시작일 변경 X. 감점 로직 자체는 그대로 4일차부터.
- `coin_functions.php`의 찐완주 판정 X (적응기간 이후로 유지).
- `check.php`의 코치 체크리스트 "적응기간 날짜" 경고 X (date-level 경고는 그대로 의미 있음).
- 회원 본인 화면 / 코치 대시보드 X (이미 오늘까지 표시 중이거나 별도 범위).
- DB 스키마 변경 / 마이그레이션 X.

## 3. 동작 명세

### 3.1 집계 범위

- **모든 날짜는 KST(서버 PHP `date()` 기본)** 기준.
- `display_start = cohort.start_date`
- `scoring_start = cohort.start_date + 3일` (= 기존 의미, 감점 시작일)
- `adaptation_end = cohort.start_date + 2일` (= 적응기간 마지막 날, 기존 의미)
- `scoring_end = min(cohort.end_date, 어제)` (기존 그대로)
- `adaptation_active = (오늘 < scoring_start)` — 즉 1~3일차 당일에만 true. 4일차(== scoring_start) 당일부터 false.
- **집계 루프 / total_days / total_mondays / SELECT WHERE check_date BETWEEN ?**: 모두 `display_start`부터 `scoring_end`까지로 변경

### 3.2 시점별 동작

| 오늘 시점 | display_start | scoring_end | cohort_summary | adaptation_active |
|-----------|---------------|-------------|----------------|--------------------|
| 오늘 < cohort.start_date (시작 전) | 시작일 | 어제 (< display_start) | null | true |
| 오늘 == cohort.start_date (1일차 당일) | 시작일 | 어제 (= start_date-1, < display_start) | null | true |
| start_date < 오늘 ≤ start_date+3 (2~4일차) | 시작일 | 어제 (≥ display_start) | 정상 집계 | 오늘 < scoring_start 이면 true |
| 오늘 > scoring_start (5일차+) | 시작일 | 어제 또는 cohort.end_date | 정상 집계 | false |
| 오늘 > cohort.end_date+1 (종료 후) | 시작일 | cohort.end_date | 정상 집계 | false |

- `cohort_summary: null` 케이스에서도 `scoring_start`, `adaptation_active`, `display_start` 키는 응답에 채워 보낸다 → 프론트가 "곧 시작합니다 — N일 남음" 같은 안내를 줄 여지를 남김(이번 작업에선 기본 안내만).

### 3.3 응답 스키마

```jsonc
{
  "success": true,
  "display_start":     "YYYY-MM-DD",  // NEW: cohort.start_date
  "scoring_start":     "YYYY-MM-DD",  // 기존 의미: 감점 시작일 = start_date+3
  "scoring_end":       "YYYY-MM-DD",  // 기존
  "adaptation_active": true,          // NEW: 오늘 < scoring_start ? true : false
  "total_days":        N,             // display_start ~ scoring_end 기준
  "total_mondays":     N,             // 동일
  "cohort_summary":    { ... } | null,
  "groups":            [...],
  "members":           [...],
  "score_distribution":[...],
  "score_warnings":    { "approaching": [], "revival_eligible": [], "out": [] }
}
```

### 3.4 프론트 (`bootcamp.js::renderDashboard`)

1. `!r.success || !r.cohort_summary` 가드: 기존 "아직 채점 기간이 시작되지 않았습니다" 문구 그대로 유지 (오늘 ≤ cohort.start_date 케이스).
2. 본문 렌더링 시작 시, `d.adaptation_active === true`이면 본문 최상단에 안내 1줄:
   ```html
   <div class="db-adaptation-notice">
     ⏳ 적응기간 중 — 감점은 {scoring_start}부터 적용
   </div>
   ```
   스타일: 기존 muted/info 톤(노란 배경, 좁은 padding, 좌측 정렬). CSS는 `bootcamp.css`(또는 admin-cafe.css 옆 동일 위치)에 1 클래스 추가.
3. 섹션 1 헤더 라벨: `(${d.scoring_start} ~ ${d.scoring_end}, ${cs.member_count}명)` → `(${d.display_start} ~ ${d.scoring_end}, ${cs.member_count}명)` 로 변경.
4. 점수 분포 / 경고 멤버 / 조별 비교 / 멤버별 — 표시 로직 동일.

### 3.5 적응기간 중의 점수/경고 동작

- DB의 `member_scores.current_score`는 `recalculateMemberScore`가 `adaptation_end+1`(= scoring_start)부터의 체크만 본다. 적응기간에는 그 범위가 비어 있으므로 모두 `SCORE_START`(보통 0)에 머문다.
- `dashboard.php`의 점수 분포는 `current_score` 그대로 사용 → 적응기간엔 전원 `0 ~ -4` 버킷, 경고 배열 셋 다 빈 배열. UI에는 그대로 노출되며, 안내 배지가 "감점 미반영" 사실을 설명한다.

## 4. 변경 파일

| 파일 | 변경 |
|------|------|
| `public_html/api/services/dashboard.php` | `$displayStart = $cohort['start_date']`, 집계/루프/SELECT를 `displayStart` 기준, `adaptation_active`/`display_start` 응답에 추가, 빈 응답 분기에도 동일 키 채움 |
| `public_html/js/bootcamp.js` | 헤더 라벨 변수 교체, 본문 상단 `db-adaptation-notice` 1줄 삽입 |
| `public_html/css/bootcamp.css` | `.db-section-title` 근처(L484 이후)에 `.db-adaptation-notice` 1 클래스 추가 |
| `public_html/index.php` 또는 cache-buster | `bootcamp.js?v=`, `bootcamp.css?v=` 갱신 |

## 5. 테스트 / 검증

### 5.1 자동 (PHPUnit)

- `tests/Boot/Dashboard/DashboardServiceTest.php` 신규
  - 1일차(today == start_date): `cohort_summary: null`, `adaptation_active: true`, `display_start == start_date`
  - 2일차(today == start_date+1): cohort_summary 정상, total_days=1, `adaptation_active: true`, scoring_start은 변경 없음
  - 4일차(today == start_date+3): cohort_summary 정상, total_days=3 (display_start ~ yesterday), `adaptation_active: false` (오늘 == scoring_start, 경계 명시: `adaptation_active = (today < scoring_start)`)
  - 5일차(today == start_date+4): total_days=4, `adaptation_active: false`
  - cohort 종료 후: total_days = cohort 길이, `adaptation_active: false`
- 시간 모킹은 기존 테스트 유틸의 `TestClock` 또는 `setMockedTodayKST` 패턴 사용 (없으면 함수 시그니처에 `?string $todayKST = null` 옵셔널 추가).

### 5.2 DEV 수동

- 12기(현재 5일차+): regression — 표시·라벨·통계 동일, 배지 없음.
- DEV에 `start_date = today` 임시 cohort 만들고 회원 1~2명 옮겨 1~4일차 시뮬레이션 (또는 `start_date`를 today-1/today-2/today-3로 옮기며 확인).

### 5.3 PROD 인보리언트

- 11기/12기 dashboard_stats 비교: 변경 전/후 cohort_summary·groups·members·score_distribution·warnings 동일 (key/값).
- 임시로 `scoring_start = display_start` 시점 셋업 시 적응기간 배지 표시 + 라벨 변경 확인.

## 6. 엣지케이스 / 정책

- `cohort.end_date IS NULL` (운영 중): `scoring_end = 어제`, 변경 없음.
- `cohort.end_date < cohort.start_date` (불량 데이터): 기존과 동일하게 `scoring_start > scoring_end`로 떨어져 null 응답. `adaptation_active = (today < scoring_start)`로 채워 보냄.
- `cohort.start_date == today`: `scoring_end = yesterday < display_start` → 집계 0건. 응답은 `cohort_summary: null`, 프론트는 기존 빈 메시지. 배지 안 보임(본문 자체가 없으므로).
- chip(`admin_view_cohort_id`)로 다른 기수 선택 시: `resolveAdminCohortId` 그대로 사용. 기수가 적응기간이면 자동으로 배지 표시.

## 7. 배포 절차

1. DEV_BOOT(dev)에서 구현 + 테스트
2. `pnpm`/`phpunit` (해당 프로젝트 패턴), `?v=` cache-buster 갱신
3. push origin dev
4. ⛔ 사용자 DEV 확인 (`dev-boot.soritune.com/operation` 어드민 → 시스템 → 대시보드)
5. 사용자 명시 시 main 머지 + boot-prod pull

## 8. 비-목표(다음 작업 거리)

- 적응기간 중 회원별 "감점 시뮬레이션" 표시 (현재 점수 옆에 `(만약 감점 적용 중이라면 -N점)` 같은) — 별도 요청 시.
- 적응기간을 cohort별로 다르게 설정 (지금은 전역 상수) — 별도 요청 시.
