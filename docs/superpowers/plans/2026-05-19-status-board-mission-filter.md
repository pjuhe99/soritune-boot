# 현황판 미수행 필터 (줌특강X/내맛X/말까X) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `/operation/#status` 외 coach/head/leader 4개 역할의 공용 현황판 필터 바에 「줌특강X / 내맛X / 말까X」 체크박스 3개를 추가해, 선택된 날짜 기준으로 미수행 회원만 솎아낸다 (OR 합집합).

**Architecture:**
- `public_html/js/bootcamp.js` 의 IIFE 내부에 헬퍼 4개 추가: 상수 `MISSION_FILTER_CODES`, 순수 함수 `isSelectedDateMonday`, 필터 헬퍼 `applyMissionFilter`, UI 헬퍼 `renderMissionFilterItems`, 이벤트 바인더 `bindMissionFilterEvents`.
- `filterBarHtml(opts)` 에 `missionFilter: true` 옵션을 추가해 **현황판 탭에서만** 체크박스를 노출 (다른 탭 회귀 0).
- `applyMissionFilter` 는 client-side 솎아내기. API 무변경. 줌특강X 는 `zoom_daily ∨ daily_mission` 둘 다 미체크 시 매칭, 말까X 는 월요일에만 활성.
- `loadStatusBoard()` 진입 시마다 `selectedMissingFilters` Set 을 초기화 → 탭 전환/새로고침 시 필터 해제 상태로 시작.
- `bindFilterEvents()` 의 `dateEl.onchange` 에 말까 자동 disable/해제 가드를 끼워 넣는다.

**Tech Stack:** Vanilla JS (IIFE 패턴), CSS (token 기반), PHP entry pages (수정 없음, `v()` 가 mtime 자동 캐시버스트).

**Spec:** `docs/superpowers/specs/2026-05-19-status-board-mission-filter-design.md`

---

## File Structure

**Modify:**
- `public_html/js/bootcamp.js` — 모듈 상태 1개, 함수 5개 신규/수정, `loadStatusBoard`/`renderStatusBoard` 변경
- `public_html/css/bootcamp.css` — `.bc-mission-filter*` 셋 신규 스타일 추가

**No changes:**
- `public_html/operation/*.php`, `coach/*.php`, `head/*.php`, `leader/*.php` — `v()` 가 mtime 으로 자동 캐시 버스트
- PHP API / DB — 무변경
- 기존 PHP 테스트 — 무관 (회귀 없음)

**Tests:**
- 부트캠프는 JS 자동 테스트 셋업이 없다 (`tests/` 디렉토리는 PHP 전용). 본 plan 은 spec 의 시나리오 테이블·invariant 를 **Task 9 의 수동 브라우저 검증 매트릭스**로 갈음한다.
- 단위 검증이 필요한 순수 함수 (`applyMissionFilter`, `isSelectedDateMonday`) 는 Task 9 에서 브라우저 콘솔 1회 단발 테스트로 확인.

---

### Task 1: 모듈 상태 + 상수 + `isSelectedDateMonday` 헬퍼 추가

**Files:**
- Modify: `public_html/js/bootcamp.js` (전역 변수 선언부 + 헬퍼 영역)

- [ ] **Step 1: `selectedMissingFilters` 상태 추가**

`public_html/js/bootcamp.js` 의 IIFE 안 기존 모듈 상태 (`let missionTypes = [];` 부근, 19 줄 근처) 바로 아래에 추가:

```js
    let selectedMissingFilters = new Set();   // 현황판 미수행 필터 ('zoom'/'inner33'/'speak')
```

- [ ] **Step 2: `MISSION_FILTER_CODES` 상수 + `isSelectedDateMonday` 헬퍼 추가**

`scoreClass(score)` 함수 (354~361 줄 근처) **바로 위에** 다음 블록 추가:

```js
    // ── 현황판 미수행 필터 ──
    const MISSION_FILTER_CODES = {
        zoom:    ['zoom_daily', 'daily_mission'],   // 둘 다 미체크여야 매칭 (데일리는 줌특강 보완)
        inner33: ['inner33'],
        speak:   ['speak_mission'],                 // 월요일에만 부여
    };

    function isSelectedDateMonday() {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(selectedDate || '');
        if (!m) return false;
        return new Date(+m[1], +m[2] - 1, +m[3]).getDay() === 1;   // 일=0, 월=1
    }
```

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/bootcamp.js && git commit -m "$(cat <<'EOF'
refactor(status-board): 미수행 필터 상태/상수/헬퍼 추가

selectedMissingFilters Set 과 MISSION_FILTER_CODES, isSelectedDateMonday
헬퍼를 추가. 후속 task 에서 filterBarHtml/applyMissionFilter 가 사용.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `applyMissionFilter` 함수 추가

**Files:**
- Modify: `public_html/js/bootcamp.js` (`isSelectedDateMonday` 바로 아래)

- [ ] **Step 1: `applyMissionFilter` 함수 추가**

Task 1 에서 추가한 `isSelectedDateMonday` 함수 바로 아래에 추가:

```js
    function applyMissionFilter(members, checks, filters) {
        if (filters.size === 0) return members;

        const idOf = code => missionTypes.find(m => m.code === code)?.id;
        const activeFilters = new Set(filters);
        const ids = {};

        // mission code → id 매핑 + 누락 가드
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

        // 말까는 월요일이 아니면 평가 스킵 (UI 에서도 disabled 지만 방어적으로)
        if (activeFilters.has('speak') && !isSelectedDateMonday()) activeFilters.delete('speak');
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

**왜 `!== 1` 인가**: API 응답에서 `0` (명시적 fail) 과 `undefined` (미체크) 둘 다 "안 한 사람" 으로 본다. 현 dot 렌더 로직 `v === undefined ? 'none' : (v ? 'pass' : 'fail')` 와 일치 — none/fail 둘 다 "미수행".

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/bootcamp.js && git commit -m "$(cat <<'EOF'
feat(status-board): applyMissionFilter (OR 합집합 client-side)

zoom 필터는 zoom_daily∨daily_mission 둘 다 미체크일 때만 매칭.
mission_type id 누락 시 console.warn + 해당 키 무시 (방어).
말까 키는 비-월요일이면 평가 스킵.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `renderMissionFilterItems` 헬퍼 + `filterBarHtml` 옵션 확장

**Files:**
- Modify: `public_html/js/bootcamp.js` (`filterBarHtml` 274 줄 부근)

- [ ] **Step 1: `renderMissionFilterItems` 함수 추가**

Task 2 에서 추가한 `applyMissionFilter` 바로 아래에 추가:

```js
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
```

- [ ] **Step 2: `filterBarHtml` 에 `missionFilter` 옵션 추가**

`filterBarHtml(opts = {})` 함수 (274~325 줄) 의 본문을 다음과 같이 수정:

기존:
```js
    function filterBarHtml(opts = {}) {
        const showDate = opts.date !== false;
        const showGroup = opts.group !== false && !leaderMode;
        const showStage = opts.stage !== false;
        const showCohort = !leaderMode;
        return `
            <div class="bc-filters">
                ${showCohort ? ` ... ` : ` ... `}
                ${showDate ? ` ... ` : ''}
                ${showGroup ? ` ... ` : ''}
                ${showStage ? ` ... ` : ''}
                <div class="filter-item">
                    <span class="filter-label">정렬</span>
                    <select id="fl-sort">...</select>
                </div>
            </div>
        `;
    }
```

변경: `const showMissionFilter = opts.missionFilter === true;` 줄을 추가하고, `</div>` 닫기 직전에 `${showMissionFilter ? renderMissionFilterItems() : ''}` 를 추가.

수정 후 (생략 없이):

```js
    function filterBarHtml(opts = {}) {
        const showDate = opts.date !== false;
        const showGroup = opts.group !== false && !leaderMode;
        const showStage = opts.stage !== false;
        const showCohort = !leaderMode;
        const showMissionFilter = opts.missionFilter === true;
        return `
            <div class="bc-filters">
                ${showCohort ? `
                <div class="filter-item">
                    <span class="filter-label">기수</span>
                    <select id="fl-cohort">
                        ${cohorts.map(c => `<option value="${c.id}" ${parseInt(c.id) === selectedCohortId ? 'selected' : ''}>${App.esc(c.cohort)}</option>`).join('')}
                    </select>
                </div>` : `
                <div class="filter-item">
                    <span class="filter-label">조</span>
                    <span style="padding:6px 0;font-weight:700;font-size:var(--sm-font-size)">${App.esc(leaderGroupName || '-')}</span>
                </div>`}
                ${showDate ? `
                <div class="filter-item">
                    <span class="filter-label">날짜</span>
                    <input type="date" id="fl-date" value="${selectedDate}">
                </div>` : ''}
                ${showGroup ? `
                <div class="filter-item">
                    <span class="filter-label">조</span>
                    <select id="fl-group">
                        <option value="0">전체</option>
                        ${groups.map(g => `<option value="${g.id}" ${parseInt(g.id) === selectedGroupId ? 'selected' : ''}>${App.esc(g.name)}</option>`).join('')}
                    </select>
                </div>` : ''}
                ${showStage ? `
                <div class="filter-item">
                    <span class="filter-label">단계</span>
                    <select id="fl-stage">
                        <option value="0">전체</option>
                        <option value="1" ${selectedStageNo === 1 ? 'selected' : ''}>1단계</option>
                        <option value="2" ${selectedStageNo === 2 ? 'selected' : ''}>2단계</option>
                    </select>
                </div>` : ''}
                <div class="filter-item">
                    <span class="filter-label">정렬</span>
                    <select id="fl-sort">
                        <option value="">기본(조→닉네임)</option>
                        <option value="name_asc" ${selectedSort === 'name_asc' ? 'selected' : ''}>이름순</option>
                        <option value="nickname_asc" ${selectedSort === 'nickname_asc' ? 'selected' : ''}>닉네임순</option>
                        <option value="score_asc" ${selectedSort === 'score_asc' ? 'selected' : ''}>점수 낮은 순</option>
                    </select>
                </div>
                ${showMissionFilter ? renderMissionFilterItems() : ''}
            </div>
        `;
    }
```

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/bootcamp.js && git commit -m "$(cat <<'EOF'
feat(status-board): filterBarHtml missionFilter 옵션 + renderMissionFilterItems

opts.missionFilter === true 일 때만 미수행 체크박스 3개 노출.
체크리스트/부활/코인/회원 탭은 옵션 미전달이라 영향 0.
말까X 는 비-월요일이면 disabled + 'is-disabled' 클래스 + 툴팁.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: `bindMissionFilterEvents` 함수 추가

**Files:**
- Modify: `public_html/js/bootcamp.js` (`bindFilterEvents` 327~352 줄 부근)

- [ ] **Step 1: `bindMissionFilterEvents` 함수 추가**

`bindFilterEvents(onFilter, container)` 함수 (327~352 줄) 의 닫는 `}` 바로 아래에 추가:

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

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/bootcamp.js && git commit -m "$(cat <<'EOF'
feat(status-board): bindMissionFilterEvents 체크박스 토글 핸들러

scope.querySelectorAll 로 스코프해 다른 탭 중복 id 충돌 회피
(기존 bindFilterEvents 와 동일 패턴).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: `bindFilterEvents.dateEl.onchange` 에 말까 자동 disable 가드 끼우기

**Files:**
- Modify: `public_html/js/bootcamp.js` (`bindFilterEvents` 347 줄)

- [ ] **Step 1: 기존 `dateEl.onchange` 1줄을 다중행 로직으로 교체**

`public_html/js/bootcamp.js:347` 의 다음 줄:

```js
        if (dateEl) dateEl.onchange = () => { selectedDate = dateEl.value; onFilter(); };
```

을 다음으로 교체:

```js
        if (dateEl) dateEl.onchange = () => {
            selectedDate = dateEl.value;
            // 말까는 월요일에만 부여 → 날짜가 비-월요일이 되면 자동 해제 + disable
            const speakCb = scope.querySelector('.bc-mission-filter input[data-mission-key="speak"]');
            if (speakCb) {
                const monday = isSelectedDateMonday();
                if (!monday) selectedMissingFilters.delete('speak');
                speakCb.checked = monday ? speakCb.checked : false;
                speakCb.disabled = !monday;
                const label = speakCb.closest('.bc-mission-filter-check');
                if (label) {
                    label.classList.toggle('is-disabled', !monday);
                    if (!monday) label.title = '월요일에만 부여';
                    else label.removeAttribute('title');
                }
            }
            onFilter();
        };
```

`scope.querySelector('.bc-mission-filter ...')` 가 null 일 수 있음 (미수행 필터가 노출되지 않은 탭). null 가드 (`if (speakCb)`) 로 안전 처리 — 체크리스트/부활/코인/회원 탭의 날짜 변경은 기존 동작 그대로.

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/bootcamp.js && git commit -m "$(cat <<'EOF'
feat(status-board): 날짜 변경 시 말까X 자동 해제/disable

비-월요일로 바뀌면 selectedMissingFilters 에서 speak 제거 +
체크박스 unchecked + disabled + 'is-disabled' 라벨 + 툴팁.
미수행 필터 미노출 탭은 null 가드로 무영향.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: `loadStatusBoard` 에서 필터 활성화 + 상태 리셋

**Files:**
- Modify: `public_html/js/bootcamp.js` (`loadStatusBoard` 699~713 줄)

- [ ] **Step 1: 함수 본문 교체**

기존 `loadStatusBoard` (699~713 줄):

```js
    async function loadStatusBoard() {
        const sec = document.getElementById('bc-tab-status');
        await loadGroups();

        sec.innerHTML = `
            <div class="bc-toolbar mt-md">
                <span class="bc-toolbar-title">현황판</span>
            </div>
            ${filterBarHtml()}
            <div id="bc-status-body"><div class="empty-state">로딩 중...</div></div>
        `;

        bindFilterEvents(renderStatusBoard, sec);
        renderStatusBoard();
    }
```

다음으로 교체 (3줄 변경):

```js
    async function loadStatusBoard() {
        selectedMissingFilters = new Set();   // 탭 진입 시마다 미수행 필터 리셋
        const sec = document.getElementById('bc-tab-status');
        await loadGroups();

        sec.innerHTML = `
            <div class="bc-toolbar mt-md">
                <span class="bc-toolbar-title">현황판</span>
            </div>
            ${filterBarHtml({ missionFilter: true })}
            <div id="bc-status-body"><div class="empty-state">로딩 중...</div></div>
        `;

        bindFilterEvents(renderStatusBoard, sec);
        bindMissionFilterEvents(renderStatusBoard, sec);
        renderStatusBoard();
    }
```

3곳 변경:
- 1줄째 `selectedMissingFilters = new Set();` 추가
- `filterBarHtml()` → `filterBarHtml({ missionFilter: true })`
- `bindMissionFilterEvents(renderStatusBoard, sec);` 추가

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/bootcamp.js && git commit -m "$(cat <<'EOF'
feat(status-board): loadStatusBoard 미수행 필터 활성화 + 상태 리셋

탭 진입마다 selectedMissingFilters 리셋 → 새로고침/탭 전환 후
미수행 필터 모두 해제 상태로 시작. filterBarHtml 에 missionFilter
옵션 전달, bindMissionFilterEvents 호출.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: `renderStatusBoard` 에서 필터 적용 + 빈 상태 메시지 분기

**Files:**
- Modify: `public_html/js/bootcamp.js` (`renderStatusBoard` 715~732 줄)

- [ ] **Step 1: 필터 적용 + 빈 상태 분기**

`renderStatusBoard` 함수 본문에서 다음 블록 (715~732 줄 근처):

```js
        const r = await App.get(API + 'status_board', params);
        if (!r.success) return;

        const { members, checks, mission_types: mt, miss_days: missDays, warning_notes: warnNotes, thresholds } = r;
        if (!members.length) {
            body.innerHTML = '<div class="empty-state">회원이 없습니다.</div>';
            return;
        }

        body.innerHTML = members.map(m => {
```

을 다음으로 교체 (5줄 변경, `members.map` → `filtered.map`):

```js
        const r = await App.get(API + 'status_board', params);
        if (!r.success) return;

        const { members, checks, mission_types: mt, miss_days: missDays, warning_notes: warnNotes, thresholds } = r;
        const filtered = applyMissionFilter(members, checks, selectedMissingFilters);
        if (!filtered.length) {
            const msg = members.length === 0
                ? '회원이 없습니다.'
                : '조건에 맞는 회원이 없습니다.';
            body.innerHTML = `<div class="empty-state">${msg}</div>`;
            return;
        }

        body.innerHTML = filtered.map(m => {
```

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/bootcamp.js && git commit -m "$(cat <<'EOF'
feat(status-board): renderStatusBoard 미수행 필터 적용 + 빈상태 분기

applyMissionFilter 결과로 카드 솎아내기. 결과 0건일 때
원본도 0이면 '회원이 없습니다.', 필터로 0이면
'조건에 맞는 회원이 없습니다.' 로 운영자가 원인 식별 가능.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: CSS — `.bc-mission-filter*` 스타일

**Files:**
- Modify: `public_html/css/bootcamp.css` (Filter Bar 섹션 끝, 33 줄 근처)

- [ ] **Step 1: 스타일 추가**

`public_html/css/bootcamp.css:33` 의 다음 블록 끝 (Filter Bar 섹션):

```css
.bc-filters select,
.bc-filters input[type="date"] {
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 6px 10px;
    font-size: var(--text-sm);
    font-family: inherit;
    background: var(--color-bg);
    min-width: 100px;
}
```

바로 아래 (다음 섹션 `Checklist Grid` 주석 앞)에 추가:

```css
/* ── Mission Filter (현황판 미수행 체크박스) ───────────────── */
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

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/css/bootcamp.css && git commit -m "$(cat <<'EOF'
style(status-board): 미수행 필터 체크박스 스타일

기존 .bc-filters .filter-item 컨테이너 안에서 가로 wrap +
disabled 시 muted color + not-allowed 커서.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: 수동 브라우저 검증 매트릭스 (4역할 × 시나리오) + 단위 console 테스트

**Files:** 변경 없음. 검증만.

본 task 는 실 dev 환경에서 동작 확인. `v()` 헬퍼가 자동 캐시 버스트하므로 hard reload 불필요하지만, 안전을 위해 Cmd/Ctrl+Shift+R 권장.

- [ ] **Step 1: dev 서버 접근 확인**

```bash
curl -sI https://dev-boot.soritune.com/operation/ | head -3
```

기대: `HTTP/1.1 200 OK` 또는 `302` (로그인 리다이렉트). 503/500 이면 보고 후 중단.

- [ ] **Step 2: console 에러 사전 점검**

`https://dev-boot.soritune.com/operation/#status` 진입 후 F12 콘솔에서 `BootcampApp` 관련 에러 / `[status-board] mission code 누락` 경고가 없는지 확인. 정상이면 본 step PASS.

(IIFE 패턴이라 `applyMissionFilter` 가 외부 노출되지 않음 → 직접 단위 호출 불가. Step 3 의 수동 토글 매트릭스로 등가 검증. 단위 테스트가 꼭 필요해지는 시점에 후속 task 로 `window.__BootcampDebug = { applyMissionFilter, isSelectedDateMonday }` 디버그 훅 추가.)

- [ ] **Step 3: operation 역할 — 핵심 시나리오 10개 (시각 검증)**

`https://dev-boot.soritune.com/operation/#status` 접속 후 다음 표를 따라 확인. 각 단계 후 화면 카드 수 또는 표시 멤버를 메모.

| # | 액션 | 기대 |
|---|------|------|
| S1 | 진입 직후 | 미수행 체크박스 3개 노출, 모두 미체크 + 카드 전체 표시 (= 기존 동작) |
| S2 | 「줌특강X」체크 | 줌·데일리 둘 다 안 한 사람만 표시 (한 명이라도 줌 또는 데일리 ✓ 있는 사람은 사라짐) |
| S3 | 「내맛X」 추가 체크 | S2 결과 ∪ (내맛 미체크) — 카드 수 증가 또는 유지 |
| S4 | 「줌특강X」 해제 | 내맛 미체크 멤버만 |
| S5 | 모든 체크박스 해제 | 전체 카드 복귀 |
| S6 | 날짜를 가까운 월요일로 변경 (예: 2026-05-18) | 새 데이터로 재 fetch, 「말까X」 enabled |
| S7 | 월요일 상태에서 「말까X」 체크 | 말까 안 한 사람만 |
| S8 | 날짜를 비-월요일로 변경 (예: 2026-05-19 화) | 「말까X」 unchecked + disabled + 툴팁 "월요일에만 부여" / 카드 전체 복귀 |
| S9 | 비-월요일에서 「말까X」 클릭 시도 | 클릭 안 됨 (disabled), `selectedMissingFilters` 변화 없음 |
| S10 | 카드 클릭 (필터 적용 상태) | 상세 모달 정상 열림, [비고 입력] 버튼도 기존대로 동작 |

각 시나리오 PASS/FAIL 메모. FAIL 1건이면 보고 후 fix task 추가.

- [ ] **Step 4: coach 역할 — 회귀 (4개 핵심)**

`https://dev-boot.soritune.com/coach/#status` 진입 후:

| # | 액션 | 기대 |
|---|------|------|
| C1 | 진입 직후 | 미수행 체크박스 3개 노출 (operation 과 동일) |
| C2 | 「줌특강X」체크 | operation 과 동일 동작 |
| C3 | 체크리스트(`#checklist`) 탭 이동 | 미수행 체크박스 **노출 안 됨** (`opts.missionFilter` 미전달) |
| C4 | 현황판 재진입 | 미수행 모두 해제 상태로 시작 |

- [ ] **Step 5: head 역할 — 회귀 (2개 핵심)**

`https://dev-boot.soritune.com/head/#status` 진입 후:

| # | 액션 | 기대 |
|---|------|------|
| H1 | 진입 직후 | 미수행 체크박스 노출 + 동작 정상 |
| H2 | 부활(`#revival`) 탭 이동 | 미수행 체크박스 노출 안 됨 |

- [ ] **Step 6: leader 역할 — 회귀 (2개 핵심)**

`https://dev-boot.soritune.com/leader/#status` 진입 후:

| # | 액션 | 기대 |
|---|------|------|
| L1 | 진입 직후 | 미수행 체크박스 3개 노출 + 동작 정상 (leaderMode 에서도 작동) |
| L2 | 「줌특강X」체크 → 본인 조 내에서 줌·데일리 미체크자만 표시 | server-side group 필터 + client-side 미수행 필터 모두 적용 |

- [ ] **Step 7: 결과 보고**

위 시나리오 결과를 PASS/FAIL 형식으로 정리해 보고. 형식 예:

```
S1: PASS, S2: PASS, ..., S10: PASS
C1~C4: PASS
H1~H2: PASS
L1~L2: PASS
```

전체 PASS 시 사용자에게 dev 검증 완료 보고 + push 승인 요청. FAIL 시 어떤 케이스에서 어떻게 실패했는지 + 화면 캡처 또는 콘솔 출력 첨부.

- [ ] **Step 8: 모든 step PASS 시 dev push (사용자 명시 후)**

본 push 는 사용자 명시적 승인 후에만 실행. 본 plan 의 task 자체로 자동 push 하지 않음.

```bash
cd /root/boot-dev && git push origin dev
```

push 후 main 머지 / PROD 반영은 **별도 사용자 요청 대기**. (CLAUDE.md 룰: dev push 후 멈춤)

---

## 검증 체크리스트 (Task 9 끝낸 후 1회 점검)

- [ ] operation/coach/head/leader 4역할 모두에서 현황판 미수행 체크박스 3개 노출
- [ ] 모두 미체크 시 기존 동작 100% (회귀 0)
- [ ] 줌특강X 가 zoom_daily ∨ daily_mission 둘 다 미체크일 때만 매칭 (한쪽이라도 ✓ 면 숨김)
- [ ] 다중 선택 시 OR 합집합 (S3 매칭 ⊇ S2 매칭)
- [ ] 비-월요일에 말까X disabled + 자동 해제, 월요일에 enabled
- [ ] 체크리스트/부활/코인/회원 탭에 미수행 체크박스 미노출
- [ ] 결과 0건일 때 "조건에 맞는 회원이 없습니다." 메시지 (필터로 인한 0건)
- [ ] mission_types 응답 정상 시 console.warn 미발생
- [ ] 새로고침 / 탭 전환 후 현황판 재진입 시 미수행 모두 해제 상태로 시작
