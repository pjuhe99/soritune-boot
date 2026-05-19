# 현황판 미션 미수행 필터 — 설계

작성일: 2026-05-19
관련 파일:
- `public_html/js/bootcamp.js` (`filterBarHtml`, `loadStatusBoard`, `renderStatusBoard`)
- `public_html/css/bootcamp.css` (필터 바 컴포넌트)
- 관련 진입점 (변경 없음, 자동 적용 대상):
  - `public_html/operation/index.php`
  - `public_html/coach/index.php`
  - `public_html/head/index.php`
  - `public_html/leader/index.php`

## 1. 배경

`#bc-tab-status` 현황판 (`/operation/#status` 외 coach/head/leader 4곳에서 동일하게 노출)은 선택된 날짜 기준으로 회원 카드와 미션 체크 dot 4개(`줌`/`데`/`내`/`말`)를 표시한다. 운영자는 "오늘 줌특강 안 한 사람", "내맛 안 한 사람", "이번 주 말까 안 한 사람"을 따로 골라 챙겨야 하는데, 현재는 카드를 일일이 스캔하거나 카운트해야 한다.

## 2. 목표

1. 현황판 필터 바에 **「줌특강X / 내맛X / 말까X」** 체크박스 3개를 추가한다.
2. 다중 선택 시 **합집합(OR)** 으로 필터한다 — 「하나라도 안 한 사람」이 보임.
3. **클라이언트 사이드** 필터로 처리 — API 무변경, 토글 시 즉시 재렌더.
4. `bootcamp.js` 공용 코드 1개 수정으로 operation/coach/head/leader 4곳에 **동시 적용**한다.
5. 「줌특강X」는 `zoom_daily` 와 `daily_mission` 이 **둘 다** 미체크인 경우만 필터에 매칭한다 (데일리는 줌특강의 보완 수단).
6. 「말까X」는 선택된 날짜가 **월요일이 아닐 때 disabled** + 툴팁 "월요일에만 부여"로 표시한다 — `speak_mission` 의 부여 요일과 일치.

## 3. 비목표 (out of scope)

- 체크리스트(`#bc-tab-checklist`), 부활(`#bc-tab-revival`), 코인(`#bc-tab-coin`), 회원 목록(`#bc-tab-members`) 탭의 필터 바 — 영향 없음.
- 회원 본인 화면, 카페 키, QR, 점수/코인 로그 — 무관.
- 교집합(AND) / NOT 조합 / "OK인 사람만" 필터 모드 — v1 에서는 단순 OR 합집합만.
- 필터 상태를 URL 또는 localStorage 에 persist — 탭 전환 / 새로고침 시 모든 체크박스 해제.
- 서버 측 필터 추가 — `status_board` 응답이 이미 멤버 + checks 전체를 1회 fetch 하므로 client-side 가 충분.
- 필터 적용 후 매칭 카운트 배지 ("23/85") — v1 에서는 카드만 솎아내고, 카운트는 후속 작업.

## 4. UX 설계

### 필터 바 레이아웃 (현황판 탭에서만)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ [기수 ▾] [날짜 📅] [조 ▾] [단계 ▾] [정렬 ▾]                                  │
│ 미수행: ☐ 줌특강X   ☐ 내맛X   ☐ 말까X (월요일에만 부여)                       │
└─────────────────────────────────────────────────────────────────────────────┘
```

- 기존 5개 필터(기수/날짜/조/단계/정렬)는 그대로 첫 줄. 미수행 체크박스 3개는 같은 `.bc-filters` 컨테이너 안의 별도 `.filter-item` 으로 추가 — `flex-wrap: wrap` 으로 화면 폭에 따라 자연스럽게 다음 줄로 내려감.
- 데스크탑(≥ 1024px): 공간이 남으면 한 줄로 붙고, 부족하면 두 번째 줄로 wrap.
- 모바일(< 640px): 기존 필터들과 함께 자연 wrap.
- 라벨 "미수행:" 은 `filter-label` 스타일로 첫 체크박스 왼쪽에 1번만 표시.

### 인터랙션

| 액션 | 동작 |
|------|------|
| 체크박스 클릭 (모두 해제 → 1개 체크) | 즉시 재렌더, 매칭된 회원 카드만 표시 |
| 추가 체크박스 클릭 | OR 합집합으로 확장 |
| 모든 체크박스 해제 | 현재 동작 그대로 (전체 표시) |
| 날짜 변경 (월→화) | 「말까X」가 켜져 있었다면 자동 해제 + disabled 처리, 그 외 체크 상태 유지 → 재렌더 |
| 날짜 변경 (화→월) | 「말까X」 enabled (체크는 사용자가 직접) |
| 기수/조/단계 변경 | 새 status_board fetch → 받은 결과에 같은 미수행 필터 재적용 |
| 탭 전환 후 복귀 | 모든 미수행 체크박스 해제 상태로 새 로드 (`loadStatusBoard` 가 `selectedMissingFilters` 초기화) |

### 결과가 0건일 때

기존 "회원이 없습니다." 빈 상태 메시지 대신 **"조건에 맞는 회원이 없습니다."** 로 분기 — 운영자가 "필터 때문에 비어있구나" 를 알 수 있도록.

## 5. 데이터 흐름

```
[User] 현황판 진입
   │
   ▼
loadStatusBoard()
   - selectedMissingFilters = new Set()   ← 진입 시마다 초기화
   - filterBarHtml({ missionFilter: true }) 렌더
   - bindFilterEvents(renderStatusBoard, sec)
   - bindMissionFilterEvents(renderStatusBoard, sec)   ← 신규
   - renderStatusBoard()
   │
   ▼
renderStatusBoard()
   - GET /api/bootcamp.php?action=status_board (cohort, date, group, stage, sort)
   - 응답: { members, checks, mission_types, miss_days, warning_notes, thresholds }
   - applyMissionFilter(members, checks, selectedMissingFilters, selectedDate)
        ← 신규 헬퍼. members 를 client-side 로 솎아냄
   - 솎아낸 members 로 기존 카드 렌더
   │
   ▼
[User] 체크박스 토글
   - selectedMissingFilters 갱신
   - renderStatusBoard()  ← API 재호출 없이 즉시 재실행 (캐시된 응답 X — 단순화 위해 매번 fetch)
```

**API 캐시는 도입하지 않는다.** 체크박스 토글마다 `status_board` fetch 가 1회 발생. 코호트 50~100명 규모에서 응답 1~3KB·~50ms 라 체감 차이 무시 가능, 코드 단순함이 더 가치 있다. (날짜/조 변경 시 stale 캐시 무효화 로직이 불필요해짐.)

## 6. 프론트엔드 변경

### `public_html/js/bootcamp.js`

#### 6.1 모듈 레벨 상태

```js
// 기존 selectedCohortId, selectedDate, ... 옆에 추가
let selectedMissingFilters = new Set();   // {'zoom', 'inner33', 'speak'} 부분집합
```

#### 6.2 `filterBarHtml(opts)` 확장

기존 시그니처에 `missionFilter` 옵션 추가:

```js
function filterBarHtml(opts = {}) {
    const showDate = opts.date !== false;
    const showGroup = opts.group !== false && !leaderMode;
    const showStage = opts.stage !== false;
    const showCohort = !leaderMode;
    const showMissionFilter = opts.missionFilter === true;   // 신규, 기본 false

    return `
        <div class="bc-filters">
            ${/* 기존 5개 필터 그대로 */ ''}
            ${showMissionFilter ? renderMissionFilterItems() : ''}
        </div>
    `;
}

function renderMissionFilterItems() {
    const isMonday = isSelectedDateMonday();
    const opts = [
        { key: 'zoom',    label: '줌특강X', disabled: false },
        { key: 'inner33', label: '내맛X',   disabled: false },
        { key: 'speak',   label: '말까X',   disabled: !isMonday, hint: isMonday ? '' : '월요일에만 부여' },
    ];
    return `
        <div class="filter-item bc-mission-filter">
            <span class="filter-label">미수행</span>
            <div class="bc-mission-filter-checks">
                ${opts.map(o => `
                    <label class="bc-mission-filter-check ${o.disabled ? 'is-disabled' : ''}"
                           ${o.hint ? `title="${App.esc(o.hint)}"` : ''}>
                        <input type="checkbox"
                               data-mission-key="${o.key}"
                               ${selectedMissingFilters.has(o.key) ? 'checked' : ''}
                               ${o.disabled ? 'disabled' : ''}>
                        ${App.esc(o.label)}
                    </label>
                `).join('')}
            </div>
        </div>
    `;
}

function isSelectedDateMonday() {
    // selectedDate 는 'YYYY-MM-DD' KST. UTC 변환 우회 위해 수동 파싱.
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(selectedDate || '');
    if (!m) return false;
    const d = new Date(parseInt(m[1]), parseInt(m[2]) - 1, parseInt(m[3]));
    return d.getDay() === 1;   // 일=0, 월=1
}
```

#### 6.3 `bindMissionFilterEvents(onFilter, scope)` 신규

```js
function bindMissionFilterEvents(onFilter, scope) {
    scope.querySelectorAll('.bc-mission-filter input[type="checkbox"]').forEach(cb => {
        cb.onchange = () => {
            const key = cb.dataset.missionKey;
            if (cb.checked) selectedMissingFilters.add(key);
            else selectedMissingFilters.delete(key);
            onFilter();
        };
    });
}
```

#### 6.4 날짜 변경 시 「말까X」 자동 해제

`bindFilterEvents` 의 `dateEl.onchange` 에서, 새 날짜가 월요일이 아니면 `selectedMissingFilters.delete('speak')` 후 필터 바 영역만 재렌더 (또는 disabled/checked 상태만 직접 갱신). 가장 단순한 구현은:

```js
if (dateEl) dateEl.onchange = () => {
    selectedDate = dateEl.value;
    if (!isSelectedDateMonday()) selectedMissingFilters.delete('speak');
    // 필터 바의 말까X 체크박스/disabled 상태를 갱신
    const speakCb = scope.querySelector('.bc-mission-filter input[data-mission-key="speak"]');
    if (speakCb) {
        speakCb.checked = false;
        speakCb.disabled = !isSelectedDateMonday();
        const label = speakCb.closest('.bc-mission-filter-check');
        if (label) {
            label.classList.toggle('is-disabled', speakCb.disabled);
            if (speakCb.disabled) label.title = '월요일에만 부여';
            else label.removeAttribute('title');
        }
    }
    onFilter();
};
```

#### 6.5 `applyMissionFilter(members, checks, filters, dateStr)` 신규

```js
const MISSION_FILTER_CODES = {
    zoom:    ['zoom_daily', 'daily_mission'],  // 둘 다 안 한 경우 매칭
    inner33: ['inner33'],
    speak:   ['speak_mission'],
};

function applyMissionFilter(members, checks, filters, dateStr) {
    if (filters.size === 0) return members;

    const idOf = code => missionTypes.find(m => m.code === code)?.id;
    const isMonday = (() => {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateStr || '');
        if (!m) return false;
        return new Date(+m[1], +m[2] - 1, +m[3]).getDay() === 1;
    })();

    // mission code → id 매핑 + 누락 가드 (DB 운영 실수로 mission_type 사라진 경우)
    const ids = {};
    const activeFilters = new Set(filters);
    for (const [key, codes] of Object.entries(MISSION_FILTER_CODES)) {
        if (!activeFilters.has(key)) continue;
        const mapped = codes.map(idOf);
        if (mapped.some(x => x == null)) {
            console.warn(`[status-board] mission code 누락: ${codes.join(',')} — '${key}' 필터 무시`);
            activeFilters.delete(key);
            continue;
        }
        ids[key] = mapped;
    }
    // 말까는 월요일이 아니면 평가 자체를 스킵 (UI 에서도 disabled 지만 방어적으로)
    if (activeFilters.has('speak') && !isMonday) activeFilters.delete('speak');
    if (activeFilters.size === 0) return members;

    return members.filter(mem => {
        const c = checks[mem.id] || {};
        // OR 합집합: 켜진 필터 중 하나라도 매칭하면 표시
        if (activeFilters.has('zoom')) {
            const [zoomId, dailyId] = ids.zoom;
            if (c[zoomId] !== 1 && c[dailyId] !== 1) return true;
        }
        if (activeFilters.has('inner33')) {
            const [innerId] = ids.inner33;
            if (c[innerId] !== 1) return true;
        }
        if (activeFilters.has('speak')) {
            const [speakId] = ids.speak;
            if (c[speakId] !== 1) return true;
        }
        return false;
    });
}
```

**`v === 1` 매칭 정책**: API 응답에서 `0` (명시적 fail) 과 `undefined` (미체크) 둘 다 "안 한 사람" 으로 본다 → `!== 1` 로 판정. (현 dot 렌더 로직 `v === undefined ? 'none' : (v ? 'pass' : 'fail')` 와 일치 — none/fail 둘 다 "미수행" 으로 묶임.)

**누락 가드**: `mission_types` 응답에 `zoom_daily/daily_mission/inner33/speak_mission` 중 누락이 있으면 `console.warn` + 해당 키 무시. 원본 `filters` Set 은 건드리지 않고 로컬 `activeFilters` 복사본으로 처리해서 UI 체크 상태와 분리.

#### 6.6 `loadStatusBoard` 변경

```js
async function loadStatusBoard() {
    selectedMissingFilters = new Set();   // 진입 시마다 리셋

    const sec = document.getElementById('bc-tab-status');
    await loadGroups();

    sec.innerHTML = `
        <div class="bc-toolbar mt-md">
            <span class="bc-toolbar-title">현황판</span>
        </div>
        ${filterBarHtml({ missionFilter: true })}    ← 옵션 신규
        <div id="bc-status-body"><div class="empty-state">로딩 중...</div></div>
    `;

    bindFilterEvents(renderStatusBoard, sec);
    bindMissionFilterEvents(renderStatusBoard, sec);   ← 신규
    renderStatusBoard();
}
```

#### 6.7 `renderStatusBoard` 변경

```js
const r = await App.get(API + 'status_board', params);
if (!r.success) return;

const { members, checks, mission_types: mt, miss_days: missDays, warning_notes: warnNotes, thresholds } = r;

const filtered = applyMissionFilter(members, checks, selectedMissingFilters, selectedDate);
if (!filtered.length) {
    const msg = members.length === 0
        ? '회원이 없습니다.'
        : '조건에 맞는 회원이 없습니다.';
    body.innerHTML = `<div class="empty-state">${msg}</div>`;
    return;
}

body.innerHTML = filtered.map(m => { /* 기존 카드 렌더 그대로 */ }).join('');
```

### `public_html/css/bootcamp.css`

기존 `.bc-filters .filter-item` 스타일을 재사용하되, 체크박스 그룹용 보조 스타일 추가:

```css
.bc-mission-filter .bc-mission-filter-checks {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-3);
    align-items: center;
    padding: 6px 0;
}
.bc-mission-filter-check {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: var(--text-sm);
    cursor: pointer;
    user-select: none;
}
.bc-mission-filter-check input[type="checkbox"] {
    margin: 0;
    cursor: pointer;
}
.bc-mission-filter-check.is-disabled {
    color: var(--color-text-muted);
    cursor: not-allowed;
}
.bc-mission-filter-check.is-disabled input[type="checkbox"] {
    cursor: not-allowed;
}
```

캐시 무효화: `public_html/operation/index.php` / `coach/index.php` / `head/index.php` / `leader/index.php` 의 `bootcamp.js` / `bootcamp.css` 참조에 사용 중인 `?v=` 쿼리 스트링을 일괄 갱신 (현 코드 컨벤션 동일).

## 7. 에러 처리

| 상황 | 처리 |
|------|------|
| `mission_types` API 응답에 `zoom_daily`/`daily_mission`/`inner33`/`speak_mission` 중 하나라도 없음 | `console.warn` + 해당 필터 키 무시 (다른 필터는 정상 작동). 체크박스는 그대로 렌더되어 사용자가 토글해도 무효 — 운영자가 알아채면 보고 받음. (이 시나리오는 DB 운영 실수 — alert/toast 까지는 과함) |
| `selectedDate` 가 invalid (수동 비움 등) | `isSelectedDateMonday()` `false` → 「말까X」 disabled, 다른 필터는 정상 |
| `checks[mem.id]` 가 undefined (신규 입과 회원, 체크 없음) | `c = {}` 으로 폴백 → 모든 미션 `!== 1` 매칭 → 어떤 필터든 켜져 있으면 표시됨 (정확함: "체크 안 한 사람") |
| 필터 적용 결과 0건 | "조건에 맞는 회원이 없습니다." 빈 상태 |

## 8. 테스트 계획

### 단위 (`applyMissionFilter` — vitest 또는 jest 가 없으면 수동 console 검증)

부트캠프 JS 측은 현재 자동 단위 테스트 셋업이 없다 (`tests/` 디렉토리는 PHP 위주). 따라서 `applyMissionFilter` 검증은 **수동 시나리오 테이블**로 갈음:

| 케이스 | filters | checks[123] | 기대 |
|--------|---------|-------------|------|
| 필터 없음 | ∅ | { zoom:1 } | 표시 |
| 줌X / 줌 했음 | {zoom} | { zoom:1, daily:0 } | 숨김 |
| 줌X / 데일리만 했음 | {zoom} | { zoom:0, daily:1 } | 숨김 (보완) |
| 줌X / 둘 다 안 함 | {zoom} | { zoom:0, daily:0 } | 표시 |
| 줌X / 둘 다 undefined | {zoom} | {} | 표시 |
| 내맛X / 내맛 했음 | {inner33} | { inner33:1 } | 숨김 |
| 내맛X / 내맛 fail | {inner33} | { inner33:0 } | 표시 |
| 줌X + 내맛X (OR) / 줌만 안 함 | {zoom, inner33} | { zoom:0, daily:0, inner33:1 } | 표시 (줌 조건 매칭) |
| 줌X + 내맛X (OR) / 둘 다 OK | {zoom, inner33} | { zoom:1, inner33:1 } | 숨김 |
| 말까X / 월요일 / 안 함 | {speak} (월) | { speak:0 } | 표시 |
| 말까X / 화요일 / 안 함 | {speak} (화) | { speak:0 } | 숨김 (말까 조건 무효) |
| 말까X + 줌X / 화요일 / 줌만 안 함 | {speak, zoom} (화) | { zoom:0, daily:0, speak:0 } | 표시 (줌 조건만 살아있음) |

### 회귀 (수동)

**4역할 × 핵심 시나리오** — 운영/코치/조장/헤드 각각 로그인 후:

1. 현황판 진입 → 미수행 체크박스 3개 노출, 모두 미체크, 카드 전체 표시 (기존 동작)
2. 「줌특강X」체크 → 줌/데일리 둘 다 안 한 사람만 표시
3. 「내맛X」추가 체크 → 줌+데일리 둘 다 안 함 OR 내맛 안 함 (합집합)
4. 모두 해제 → 전체 복귀
5. 날짜를 월요일로 변경 → 「말까X」enabled, 화요일로 변경 → 자동 해제 + disabled
6. 조/단계 필터 동시 사용 → 서버 필터 + 클라이언트 미수행 필터 양쪽 모두 적용
7. 탭 전환 → 다른 탭 → 다시 현황판 → 미수행 필터 모두 해제 상태로 시작
8. **체크리스트/부활/코인/회원 탭** — 필터 바에 「미수행」 체크박스가 나타나면 안 됨 (`opts.missionFilter` 미전달이 정상 동작하는지 확인)
9. 회원 카드 클릭 → 상세 모달 / 비고 버튼 등 기존 인터랙션 동일 동작
10. 매우 작은 화면 (375px) — 필터 바 자연 wrap, 체크박스 라벨 잘림 없음

### 인보리언트

- `INV-MF-1`: `selectedMissingFilters.size === 0` → `applyMissionFilter` 결과는 입력 `members` 와 같은 원소·순서를 그대로 보존 (필터 전 동작과 1:1).
- `INV-MF-2`: 필터 결과 `filtered.length <= members.length` 항상 성립.
- `INV-MF-3`: 같은 입력(checks, filters, dateStr) → 결정적 동일 결과.
- `INV-MF-4`: 「줌특강X」켜진 상태에서 `zoom_daily` 또는 `daily_mission` 둘 중 **하나라도** `=== 1` 이면 해당 회원은 매칭에서 제외됨.
- `INV-MF-5`: 날짜가 월요일이 아닐 때 「말까X」 필터 키는 결과에 영향을 주지 않음 (다른 필터만 평가).
- `INV-MF-6`: 체크리스트/부활/코인/회원 탭의 `filterBarHtml(...)` 호출에 `missionFilter: true` 옵션이 전달되지 않음 (해당 탭에는 미수행 체크박스가 렌더되지 않음).

## 9. 마이그레이션

DB 마이그 0건. PHP 변경 0건. JS 단일 파일 + CSS 단일 파일 + 4 진입점의 `?v=` 캐시 버스터만 갱신.

## 10. 롤백

`bootcamp.js`, `bootcamp.css` 의 commit revert + `?v=` 한 번 더 갱신 → 즉시 원복. 데이터 변경 없음.

## 11. 후속 검토 (out of scope, 백로그)

- **매칭 카운트 배지** — "23/85명" 같은 결과 카운트를 toolbar 또는 필터 바에 노출.
- **AND 모드 토글** — "둘 다 안 한 위험군" 한정 필터.
- **OK 토글** — "X 한 사람만" 정반대 모드.
- **회원 카드의 미체크 미션 강조** — 체크 dot 의 fail/none 에 작은 펄스/아웃라인.
- **체크리스트 탭에도 동일 필터 도입** — 운영 요청 시점에 별도 spec.
