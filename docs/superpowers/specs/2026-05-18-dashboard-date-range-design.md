# 운영 대시보드 날짜 범위 셀렉터 — 설계

작성일: 2026-05-18
관련 파일:
- `public_html/api/services/dashboard.php`
- `public_html/js/bootcamp.js` (`loadDashboard` / `renderDashboard`)
- `public_html/api/admin.php` (`dashboard_stats` 라우팅)
- `public_html/css/bootcamp.css` (toolbar 영역 스타일)

## 1. 배경

현재 `/operation` 대시보드의 과제율(섹션 1·2·3)은 cohort 시작일부터 어제까지의 고정 구간을 보여준다.
운영자는 특정 구간(예: 평가 기간만, 특정 주만)의 과제율을 확인할 수 없다.

기본 노출 구간 자체도 한 가지 결함이 있다:
- 현재 디폴트: `cohort_start ~ yesterday` — 적응기간 1~3일차(감점 없음)가 포함되어 통계가 낙관적으로 보임
- 사용자 요청 디폴트: `scoring_start ~ today` — 감점 기간만, 오늘 진행분까지

## 2. 목표

1. 운영자가 대시보드 toolbar에서 **시작일/종료일**을 자유롭게 선택해 과제율을 다시 볼 수 있다.
2. 페이지 새로고침 시 항상 **디폴트 구간**(scoring_start ~ today)으로 초기화된다 — 상태를 persist하지 않는다.
3. 적응기간 중에는 scoring_start가 미래이므로 디폴트 시작이 `cohort_start`로 자동 후퇴한다 (1~3일차도 표시).
4. 점수 분포·경고 멤버 섹션(섹션 4)은 누적 점수 기반이라 날짜와 무관 — 그대로 유지.

## 3. 비목표 (out of scope)

- 점수 분포/경고 멤버를 특정 기간 기준으로 재계산하기 (누적 score 자체가 cohort 전체 누적이라 무의미)
- 코치/조장 화면, 회원 본인 화면 (영향 없음)
- 날짜 범위를 URL이나 localStorage에 persist하기 (사용자 명시: 새로고침 = 디폴트)
- preset chip("이번 주", "최근 7일" 등) — 단순함 우선

## 4. UX 설계

### Toolbar 레이아웃

```
┌────────────────────────────────────────────────────────────────────┐
│ 대시보드   [시작일 ▾ 2026-05-14] ~ [종료일 ▾ 2026-05-18] [기본값]    │
└────────────────────────────────────────────────────────────────────┘
```

- 데스크탑: 한 줄 표시
- 모바일(< 640px): toolbar-title 아래 줄로 wrap, date input은 width 130px 정도

### 인터랙션

| 액션 | 동작 |
|------|------|
| 시작/종료 date input 변경 | 350ms debounce 후 `dashboard_stats` 재호출 |
| 시작일 > 종료일 | 토스트 "시작일이 종료일보다 이후입니다" + 직전 값으로 input 복원, fetch 안 함 |
| 시작일 < cohort_start | 서버에서 cohort_start로 clamp (조용히) |
| 종료일 > today | 서버에서 today로 clamp (조용히) |
| [기본값] 클릭 | 두 input 비우고 재 fetch (서버 디폴트) |
| 새로고침 / 탭 재진입 | 디폴트 구간으로 초기화 |

### 디폴트 구간 (서버 산출)

- `today < scoring_start`: `cohort_start ~ today` (적응기간 중)
- `today >= scoring_start`: `scoring_start ~ today`
- 둘 다 `cohort_end`가 있고 `cohort_end < today`면 종료를 `cohort_end`로 clamp

## 5. 데이터 흐름

```
[User] date input 변경
   │
   ▼
[JS] debounce 350ms → validate (start ≤ end) → fetch
   │
   ▼
GET /api/admin.php?action=dashboard_stats
    &cohort_id=N&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
   │
   ▼
[PHP] handleDashboardStats() → computeDashboardStats(...)
   │  - start_date/end_date 비어있으면 디폴트 산출
   │  - clamp (cohort_start ≤ start, end ≤ today)
   │  - 검증 (start ≤ end)
   ▼
JSON 응답 { agg_start, agg_end, is_default_range, ...기존필드 }
   │
   ▼
[JS] renderDashboard()
   - 첫 응답이면 input 값을 agg_start/agg_end로 채움
   - 섹션 1 헤더 텍스트 갱신 (agg_start ~ agg_end)
   - 섹션 1/2/3 재렌더
```

## 6. 백엔드 변경

### `public_html/api/services/dashboard.php`

함수 시그니처 변경:

```php
function computeDashboardStats(
    PDO $db,
    int $cohortId,
    string $cohortStart,
    ?string $cohortEnd,
    string $todayKST,
    ?string $reqStart = null,   // 신규
    ?string $reqEnd = null      // 신규
): array
```

로직 변경 부분:

```php
$adaptationEnd    = date('Y-m-d', strtotime($cohortStart . ' + ' . (SCORE_ADAPTATION_DAYS - 1) . ' days'));
$scoringStart     = date('Y-m-d', strtotime($adaptationEnd . ' +1 day'));
$adaptationActive = $todayKST < $scoringStart;

// 디폴트 산출
$defaultStart = $adaptationActive ? $cohortStart : $scoringStart;
$defaultEnd   = $todayKST;
if ($cohortEnd && $cohortEnd < $defaultEnd) $defaultEnd = $cohortEnd;

// 요청값 우선
$aggStart = $reqStart ?: $defaultStart;
$aggEnd   = $reqEnd   ?: $defaultEnd;

// clamp
if ($aggStart < $cohortStart) $aggStart = $cohortStart;
if ($aggEnd > $todayKST)       $aggEnd = $todayKST;
if ($cohortEnd && $aggEnd > $cohortEnd) $aggEnd = $cohortEnd;

// 검증
if ($aggStart > $aggEnd) jsonError('시작일이 종료일보다 이후입니다.');

$isDefaultRange = ($aggStart === $defaultStart && $aggEnd === $defaultEnd);

// (이후 기존 로직에서 $aggStart, $aggEnd 사용)
```

응답 변경:

```php
return [
    'agg_start'         => $aggStart,        // 신규 (현재 표시 구간 시작)
    'agg_end'           => $aggEnd,          // 신규 (현재 표시 구간 종료)
    'is_default_range'  => $isDefaultRange,  // 신규
    'default_start'     => $defaultStart,    // 신규 (UI 디폴트 복원용)
    'default_end'       => $defaultEnd,      // 신규
    'cohort_start'      => $cohortStart,     // 신규 (input min 제약)
    'scoring_start'     => $scoringStart,    // 기존 유지 (적응기간 안내 표시용)
    'adaptation_active' => $adaptationActive,// 기존 유지
    'total_days'        => $totalDays,
    'total_mondays'     => $totalMondays,
    'cohort_summary'    => $cohortSummary,
    'groups'            => $groupResults,
    'members'           => $memberResults,
    'score_distribution'=> $scoreDistribution,
    'score_warnings'    => [...],
    // 제거: 'display_start', 'scoring_end'  (agg_start/agg_end로 대체)
];
```

**`display_start`/`scoring_end` 제거** — 기존 응답 키지만 의미가 모호해 새 키로 통일. JS 호출처는 단일(`bootcamp.js`)이라 안전.

### `handleDashboardStats`

```php
function handleDashboardStats() {
    $admin = requireAdmin();
    $explicit = (int)($_GET['cohort_id'] ?? 0);
    $cohortId = resolveAdminCohortId($explicit ?: null, $admin, false);
    if (!$cohortId) jsonError('활성 기수를 찾을 수 없습니다.');

    $reqStart = trim($_GET['start_date'] ?? '');
    $reqEnd   = trim($_GET['end_date'] ?? '');
    foreach ([$reqStart, $reqEnd] as $d) {
        if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            jsonError('날짜 형식이 잘못되었습니다.');
        }
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT start_date, end_date FROM cohorts WHERE id = ?");
    $stmt->execute([$cohortId]);
    $cohort = $stmt->fetch();
    if (!$cohort) jsonError('기수를 찾을 수 없습니다.');

    $todayKST = date('Y-m-d');
    jsonSuccess(computeDashboardStats(
        $db, $cohortId,
        $cohort['start_date'], $cohort['end_date'] ?? null,
        $todayKST,
        $reqStart !== '' ? $reqStart : null,
        $reqEnd !== ''   ? $reqEnd   : null
    ));
}
```

## 7. 프론트엔드 변경

### `public_html/js/bootcamp.js` — `loadDashboard` / `renderDashboard`

상태:
```js
let dbDateState = { start: '', end: '', debounceTimer: null };
```

toolbar HTML:
```html
<div class="bc-toolbar mt-md">
    <span class="bc-toolbar-title">대시보드</span>
    <div class="db-daterange">
        <input type="date" id="db-date-start" class="db-date-input">
        <span class="db-date-sep">~</span>
        <input type="date" id="db-date-end" class="db-date-input">
        <button type="button" class="btn btn-sm btn-secondary" id="db-date-reset">기본값</button>
    </div>
</div>
```

핸들러:
- input change/input 이벤트 → debounce 350ms → validate → fetch
- [기본값] 클릭 → 두 input value 비움 → fetch (서버 디폴트 응답 후 input에 채움)
- 응답 받으면 `agg_start`/`agg_end`로 input value 동기화 (서버 clamp 결과 반영)

`renderDashboard(sec, opts={})`:
- 최초 호출 시 toolbar 한 번만 빌드 (이미 있으면 input 값만 갱신)
- date input 값은 응답 후 동기화
- 섹션 1 헤더 텍스트: `(${d.agg_start} ~ ${d.agg_end}, ${cs.member_count}명)`
- 적응기간 안내(`d.adaptation_active`): `${d.scoring_start}부터 적용됩니다.` (기존 그대로)

### CSS — `public_html/css/bootcamp.css`

```css
.db-daterange { display: flex; align-items: center; gap: var(--space-2); margin-left: auto; }
.db-date-input { padding: 4px 8px; font-size: var(--text-sm); border: 1px solid var(--color-border); border-radius: 4px; }
.db-date-sep { color: var(--color-text-muted); }
@media (max-width: 640px) {
    .bc-toolbar { flex-wrap: wrap; }
    .db-daterange { margin-left: 0; width: 100%; }
    .db-date-input { flex: 1 1 0; min-width: 0; }
}
```

## 8. 에러 처리

| 상황 | 처리 |
|------|------|
| start_date 형식 잘못 | 서버 400 "날짜 형식이 잘못되었습니다." → 토스트 |
| start > end (JS 가드) | 토스트 + input 직전 값 복원, fetch 안 함 |
| start > end (서버 가드, 동시성) | 서버 400 → 토스트 |
| 활성 기수 없음 | 기존 동작 ("활성 기수를 찾을 수 없습니다.") |
| 응답 cohort_summary null | 기존 빈 상태 표시 |

## 9. 테스트 계획

### 단위 테스트 (`computeDashboardStats`)

| 케이스 | 입력 | 기대 |
|--------|------|------|
| 디폴트 (적응기간 후) | reqStart=null, reqEnd=null, today=5/18 | agg=(5/14, 5/18), is_default_range=true |
| 디폴트 (적응기간 중) | today=5/12 (scoring_start=5/14) | agg=(5/11, 5/12), is_default_range=true |
| 사용자 지정 정상 | reqStart=5/14, reqEnd=5/16 | agg=(5/14, 5/16), is_default_range=false |
| 시작 < cohort_start clamp | reqStart=5/01, reqEnd=5/16, cohort_start=5/11 | agg=(5/11, 5/16) |
| 종료 > today clamp | reqEnd=2099-12-31, today=5/18 | agg_end=5/18 |
| start > end | reqStart=5/16, reqEnd=5/14 | jsonError throw |
| cohort_end < today | cohort_end=5/15, today=5/18 | default_end=5/15 |

### 회귀 (수동 검증)

1. 새로고침 → 디폴트 = scoring_start ~ today
2. 시작일 5/14 → 5/15 변경 → 350ms 후 fetch, 과제율 갱신
3. 종료일 5/18 → 5/17 변경 → fetch 갱신
4. 시작 > 종료 입력 시 토스트, 값 복원
5. [기본값] 클릭 → 디폴트 복귀
6. 점수 분포·경고 멤버는 변경 무관 (날짜 바꿔도 동일)
7. 회원 본인 화면 / 코치 체크 페이지 영향 없음

### 인보리언트

- `INV-DR-1`: agg_start ≤ agg_end (서버 가드)
- `INV-DR-2`: agg_start ≥ cohort_start (서버 clamp)
- `INV-DR-3`: agg_end ≤ today (서버 clamp)
- `INV-DR-4`: 동일 요청 두 번 (같은 cohort_id, start_date, end_date) → 결정적 동일 응답
- `INV-DR-5`: score_distribution/score_warnings 결과는 reqStart/reqEnd와 무관 (cumulative)

## 10. 마이그레이션

DB 마이그 0건. API 응답 키만 변경 (`display_start`/`scoring_end` → `agg_start`/`agg_end` 외 5개 신규 키). 호출처 단일이라 안전.

## 11. 롤백

코드 revert로 즉시. 데이터 변경 없음.
