# admin.js 부분 분리 — 카페 게시글 탭 추출 — 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** admin.js (2050 줄, churn 1위) 의 카페 게시글 탭 (라인 1912~2037) 만 별도 파일 `admin-cafe.js` 로 분리. 다른 탭은 손대지 않음.

**Architecture:** GroupAssignmentApp 패턴 따름. 신규 파일에 `AdminCafeApp` 글로벌 IIFE 작성 → admin.js 의 cafe 관련 state/함수 제거 + 호출 site 1곳 변경 → operation/index.php 에 script 태그 1줄 추가.

**Tech Stack:** vanilla JS (boot 의 글로벌 IIFE 패턴), node `--check` parse-only syntax 검증.

**Spec:** `docs/superpowers/specs/2026-05-07-admin-cafe-extraction-design.md` (commit `fd46753`)

---

## 파일 구조

**신규**
- `public_html/js/admin-cafe.js` — `AdminCafeApp` 글로벌. `renderTab(container)` 진입, `_setPage(p)` HTML onclick 용, 내부 `loadPage(p)` / `load()` 헬퍼.

**수정**
- `public_html/js/admin.js` — cafe state/함수/return key 제거 + 호출 site 교체
- `public_html/operation/index.php` — `<script src="/js/admin-cafe.js?...">` 추가

---

## Task 1: admin-cafe.js 신규 파일 작성

**Files:**
- Create: `/root/boot-dev/public_html/js/admin-cafe.js`

- [ ] **Step 1: 신규 파일 작성**

Create `/root/boot-dev/public_html/js/admin-cafe.js`:

```javascript
/* ── Admin: 카페 게시글 탭 (operation 페이지 전용) ────────── */
/* admin.js 에서 분리. 의존성: App (common.js), Toast (toast.js) */
const AdminCafeApp = (() => {
    let containerEl = null;   // renderTab 에서 받은 element 캐시 (sub-load 에서 재사용)
    let page = 1;
    let filter = {};

    async function renderTab(container) {
        if (!container) return;
        containerEl = container;
        page = 1;
        filter = {};
        await load();
    }

    async function load() {
        if (!containerEl) return;

        const params = new URLSearchParams({ action: 'cafe_posts', page, limit: 50 });
        if (filter.board_type) params.set('board_type', filter.board_type);
        if (filter.date) params.set('date', filter.date);
        if (filter.mapped !== undefined && filter.mapped !== '') params.set('mapped', filter.mapped);
        if (filter.keyword) params.set('keyword', filter.keyword);

        if (page === 1) containerEl.innerHTML = '<div class="empty-state">로딩 중...</div>';
        const r = await App.get('/api/bootcamp.php?' + params.toString());
        if (!r.success) { containerEl.innerHTML = '<div class="empty-state">불러오기 실패</div>'; return; }

        const BOARD_LABELS = {
            speak_mission: '내맛미션',
            inner33: '내맛33미션',
            daily_mission: '데일리 미션',
        };
        const totalPages = Math.ceil(r.total / r.limit) || 1;

        const statsHtml = (r.stats || []).map(s => {
            const label = BOARD_LABELS[s.board_type] || s.board_type || '기타';
            return `<span class="badge badge-secondary" style="margin-right:4px">${App.esc(label)}: ${s.cnt}건 (매핑 ${s.mapped_cnt})</span>`;
        }).join('');

        containerEl.innerHTML = `
            <div class="mgmt-toolbar mt-md" style="flex-wrap:wrap;gap:8px">
                <span style="font-weight:600">카페 게시글 (${r.total}건)</span>
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <select class="form-select form-select-sm" id="cafe-filter-board" style="width:auto">
                        <option value="">전체 게시판</option>
                        <option value="speak_mission" ${filter.board_type === 'speak_mission' ? 'selected' : ''}>내맛미션</option>
                        <option value="inner33" ${filter.board_type === 'inner33' ? 'selected' : ''}>내맛33미션</option>
                        <option value="daily_mission" ${filter.board_type === 'daily_mission' ? 'selected' : ''}>데일리 미션</option>
                    </select>
                    <input type="date" class="form-input form-input-sm" id="cafe-filter-date" value="${filter.date || ''}" style="width:auto">
                    <select class="form-select form-select-sm" id="cafe-filter-mapped" style="width:auto">
                        <option value="">전체</option>
                        <option value="1" ${filter.mapped === '1' ? 'selected' : ''}>매핑됨</option>
                        <option value="0" ${filter.mapped === '0' ? 'selected' : ''}>미매핑</option>
                    </select>
                    <input type="text" class="form-input form-input-sm" id="cafe-filter-keyword" placeholder="제목/닉네임 검색" value="${App.esc(filter.keyword || '')}" style="width:140px">
                    <button class="btn btn-primary btn-sm" id="cafe-filter-btn">검색</button>
                    <button class="btn btn-secondary btn-sm" id="cafe-filter-reset">초기화</button>
                    <button class="btn btn-sm" id="btn-cafe-remap" style="background:#f59e0b;color:#fff" title="미매핑 카페 게시글을 재매핑하고 체크리스트에 반영합니다">수동 반영</button>
                </div>
            </div>
            ${statsHtml ? `<div class="mt-sm">${statsHtml}</div>` : ''}
            <div style="overflow-x:auto">
                <table class="data-table mt-sm">
                    <thead><tr>
                        <th>게시판</th>
                        <th>제목</th>
                        <th>카페 닉네임</th>
                        <th>매핑 회원</th>
                        <th>업로드일</th>
                        <th>체크</th>
                    </tr></thead>
                    <tbody>
                        ${r.posts.length ? r.posts.map(p => {
                            const boardLabel = BOARD_LABELS[p.board_type] || p.board_type || '-';
                            const postedDate = p.posted_at ? p.posted_at.substring(0, 16) : '-';
                            const memberName = p.member_real_name ? `${App.esc(p.member_real_name)} (${App.esc(p.member_nickname || '')})` : '<span class="text-danger">미매핑</span>';
                            const checkBadge = p.mission_checked == 1 ? '<span class="badge badge-success">완료</span>' : '<span class="badge badge-secondary">-</span>';
                            return `<tr>
                                <td><span class="badge badge-primary">${App.esc(boardLabel)}</span></td>
                                <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${App.esc(p.title)}">${App.esc(p.title)}</td>
                                <td>${App.esc(p.nickname || '-')}</td>
                                <td>${memberName}</td>
                                <td>${postedDate}</td>
                                <td>${checkBadge}</td>
                            </tr>`;
                        }).join('') : '<tr><td colspan="6" class="empty-state">게시글이 없습니다.</td></tr>'}
                    </tbody>
                </table>
            </div>
            ${totalPages > 1 ? `
            <div class="pagination mt-md" style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap">
                ${page > 1 ? `<button class="btn btn-sm btn-secondary" onclick="AdminCafeApp._setPage(${page - 1})">이전</button>` : ''}
                <span class="badge" style="padding:6px 10px">${page} / ${totalPages}</span>
                ${page < totalPages ? `<button class="btn btn-sm btn-secondary" onclick="AdminCafeApp._setPage(${page + 1})">다음</button>` : ''}
            </div>` : ''}
        `;

        // 필터 이벤트
        const applyFilter = () => {
            filter = {
                board_type: document.getElementById('cafe-filter-board').value,
                date: document.getElementById('cafe-filter-date').value,
                mapped: document.getElementById('cafe-filter-mapped').value,
                keyword: document.getElementById('cafe-filter-keyword').value.trim(),
            };
            loadPage(1);
        };
        document.getElementById('cafe-filter-btn').onclick = applyFilter;
        document.getElementById('cafe-filter-keyword').onkeydown = (e) => { if (e.key === 'Enter') applyFilter(); };
        document.getElementById('cafe-filter-reset').onclick = () => {
            filter = {};
            loadPage(1);
        };
        document.getElementById('btn-cafe-remap').onclick = async () => {
            if (!await App.confirm('미매핑 카페 게시글을 재매핑하고 체크리스트에 반영합니다. 진행하시겠습니까?')) return;
            const btn = document.getElementById('btn-cafe-remap');
            btn.disabled = true;
            btn.textContent = '반영 중...';
            const r = await App.post('/api/bootcamp.php?action=cafe_remap_unmapped');
            btn.disabled = false;
            btn.textContent = '수동 반영';
            if (r.success) {
                Toast.success(r.data.message);
                loadPage(1);
            } else {
                Toast.error(r.message || '수동 반영 실패');
            }
        };
    }

    async function loadPage(p) {
        page = p;
        await load();
    }

    function _setPage(p) {
        loadPage(p);
    }

    return { renderTab, _setPage };
})();
```

- [ ] **Step 2: syntax check**

Run: `node --check /root/boot-dev/public_html/js/admin-cafe.js`
Expected: 종료 코드 0, 빈 stdout (`echo $?` → 0)

만약 syntax error 라면 BLOCKED 으로 보고. 단순 retry 금지.

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/admin-cafe.js
git commit -m "$(cat <<'EOF'
feat(admin): #5 admin-cafe.js 분리 — AdminCafeApp 모듈

admin.js 의 카페 게시글 탭 (loadCafePosts + state) 을 별도 파일로.
GroupAssignmentApp 패턴: renderTab(container) 진입, containerEl 캐시.
loadPage(p) 헬퍼로 page 전이 통일 (필터/초기화/수동반영/페이지네이션).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: operation/index.php script 태그 추가

**Files:**
- Modify: `/root/boot-dev/public_html/operation/index.php` (admin.js 다음 줄)

- [ ] **Step 1: script 태그 추가**

Edit `/root/boot-dev/public_html/operation/index.php`. 라인 34 의 admin.js script 직후에 admin-cafe.js 추가:

기존 라인 34~35:
```php
    <script src="/js/admin.js<?= v('/js/admin.js') ?>"></script>
    <script src="/js/coin.js<?= v('/js/coin.js') ?>"></script>
```

다음으로 변경:
```php
    <script src="/js/admin.js<?= v('/js/admin.js') ?>"></script>
    <script src="/js/admin-cafe.js<?= v('/js/admin-cafe.js') ?>"></script>
    <script src="/js/coin.js<?= v('/js/coin.js') ?>"></script>
```

- [ ] **Step 2: PHP syntax check**

Run: `php -l /root/boot-dev/public_html/operation/index.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: 다른 admin 페이지 (leader/head/coach) 는 변경 없음 확인**

Run:
```bash
grep -l "admin-cafe.js" /root/boot-dev/public_html/operation/index.php /root/boot-dev/public_html/leader/index.php /root/boot-dev/public_html/head/index.php /root/boot-dev/public_html/coach/index.php
```
Expected: `operation/index.php` 만 출력. 다른 3개 파일은 admin-cafe.js 미참조 (의도된 동작 — cafe 탭은 isOperation() 가드라 다른 페이지에서 안 보임).

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev
git add public_html/operation/index.php
git commit -m "$(cat <<'EOF'
feat(operation): #5 admin-cafe.js script 태그 추가

operation 페이지에서만 로드 (cafe 탭은 isOperation() 가드라
leader/head/coach 페이지에서는 안 보임).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: admin.js cleanup — cafe state/함수/호출 제거

**Files:**
- Modify: `/root/boot-dev/public_html/js/admin.js` — 4 군데 변경

- [ ] **Step 1: 호출 site 교체 (라인 405)**

Edit `/root/boot-dev/public_html/js/admin.js`. 라인 405 의 `loadCafePosts();` 를 다음으로 교체:

기존:
```javascript
            loadCafePosts();
```

새:
```javascript
            if (typeof AdminCafeApp !== 'undefined') {
                const cafeTab = document.getElementById('tab-cafe-posts');
                if (cafeTab) AdminCafeApp.renderTab(cafeTab);
            }
```

`typeof AdminCafeApp !== 'undefined'` 가드는 belt-and-suspenders — `if (isOperation())` 블록 (라인 380~) 안에 있고 operation 페이지에만 admin-cafe.js 가 로드되므로 실제로는 항상 통과. 하지만 admin-cafe.js 로드 실패 (네트워크 오류 등) 시 admin.js 의 나머지 흐름을 깨지 않게 방어.

- [ ] **Step 2: state 변수 제거 (라인 1912~1913)**

Edit `/root/boot-dev/public_html/js/admin.js`. 라인 1912~1913 (cafe 섹션 시작 직전) 의 두 줄 제거:

기존:
```javascript
    let cafePostPage = 1;
    let cafePostFilter = {};
```

→ 두 줄 모두 삭제 (그 자리에 빈 줄도 남기지 않고).

- [ ] **Step 3: 함수 정의 제거 (라인 1915~2037)**

Edit `/root/boot-dev/public_html/js/admin.js`. `async function loadCafePosts(page = 1) {` 부터 시작해서 `function _cafePostPage(page) { loadCafePosts(page); }` 의 닫는 `}` 까지 (그리고 직후 빈 줄까지) 모두 제거.

제거 대상 라인 범위 (현재 admin.js 기준):
- 1915: `async function loadCafePosts(page = 1) {`
- ... ~2033: 닫는 `}`
- 2035: `function _cafePostPage(page) {`
- 2036: `        loadCafePosts(page);`
- 2037: `}`
- 그 다음 빈 줄

총 ~123 줄 삭제.

- [ ] **Step 4: return 객체에서 _cafePostPage 제거**

Edit `/root/boot-dev/public_html/js/admin.js`. 마지막 `return { ... };` 객체 안의 `_cafePostPage` 항목 제거.

기존 (라인 2048 부근):
```javascript
    return {
        init,
        _editMember, _deleteMember, _restoreMember, _setMemberStatus,
        _editAdmin, _deleteAdmin,
        _editTask, _deleteTask,
        _editGuide, _deleteGuide,
        _editCalendar, _deleteCalendar,
        _editCohort, _deactivateCohort, _activateCohort,
        _cafePostPage,
    };
```

새:
```javascript
    return {
        init,
        _editMember, _deleteMember, _restoreMember, _setMemberStatus,
        _editAdmin, _deleteAdmin,
        _editTask, _deleteTask,
        _editGuide, _deleteGuide,
        _editCalendar, _deleteCalendar,
        _editCohort, _deactivateCohort, _activateCohort,
    };
```

(`_cafePostPage,` 한 줄만 삭제. 콤마 처리 주의 — 위 라인의 `_activateCohort` 다음에 trailing comma 가 있으므로 자연스럽게 삭제 가능)

- [ ] **Step 5: cafe 관련 심볼이 admin.js 에 남아있지 않은지 확인**

Run:
```bash
grep -nE "cafePostPage|cafePostFilter|loadCafePosts|_cafePostPage" /root/boot-dev/public_html/js/admin.js
```
Expected: **empty output** (제거 완료). 만약 출력이 있으면 missed reference — BLOCKED 보고.

추가로 cafe 관련 잔존이 있는지 (의도된 잔존 — members management 의 fetch_cafe_info / cafe_member_key 는 회원 form 의 일부, 손대지 않음):
```bash
grep -nE "cafe" /root/boot-dev/public_html/js/admin.js | head -10
```
Expected: members form 의 `mf-cafe-article`, `mf-cafe-key`, `fetch_cafe_info`, `mf-cafe-warning`, `mf-cafe-nick` 등만 남음 (회원 form 의 카페 닉네임 매칭 UI). 카페 게시글 탭 관련 `loadCafePosts`, `cafePostPage`, `cafePostFilter` 는 0 건이어야.

- [ ] **Step 6: PHP syntax check (admin.js 는 JS, JS 도 syntax check)**

```bash
node --check /root/boot-dev/public_html/js/admin.js
```
Expected: 종료 코드 0, 빈 stdout

- [ ] **Step 7: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/admin.js
git commit -m "$(cat <<'EOF'
refactor(admin): #5 admin.js 에서 카페 게시글 탭 코드 제거

state (cafePostPage, cafePostFilter) + 함수 (loadCafePosts, _cafePostPage)
+ return key (_cafePostPage) 모두 제거. 호출 site (line 405) 는
AdminCafeApp.renderTab(cafeTab) 으로 교체 (typeof 가드 포함).

회원 form 의 카페 닉네임 매칭 UI (fetch_cafe_info / mf-cafe-* IDs) 는
별개 영역이라 손대지 않음.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: 통합 검증 + dev push

**Files:** 변경 없음 (검증 + push 만)

- [ ] **Step 1: JS syntax check (전체)**

```bash
node --check /root/boot-dev/public_html/js/admin-cafe.js
node --check /root/boot-dev/public_html/js/admin.js
```
Expected: 둘 다 종료 코드 0

- [ ] **Step 2: 백엔드 회귀 테스트**

```bash
cd /root/boot-dev && php tests/qr_auth_invariants.php
cd /root/boot-dev && php tests/transaction_invariants.php
```
Expected:
- `13 passed, 0 failed.`
- `12 passed, 0 failed.`

(백엔드 영향 없는 변경이라 회귀 없어야 함)

- [ ] **Step 3: 호출 그래프 sanity check**

```bash
grep -nE "AdminCafeApp" /root/boot-dev/public_html/js/admin.js /root/boot-dev/public_html/js/admin-cafe.js
```
Expected:
- `admin.js` 1군데 (호출 site, line ~405): `if (typeof AdminCafeApp !== 'undefined')` 블록
- `admin-cafe.js` 2군데 (정의 + 페이지네이션 onclick HTML 안의 `AdminCafeApp._setPage(...)`)

- [ ] **Step 4: HTTP smoke**

```bash
# admin-cafe.js 가 DEV 에 서빙되는지 (200 OK)
curl -sk -o /dev/null -w "admin-cafe.js: %{http_code}\n" "https://dev-boot.soritune.com/js/admin-cafe.js"
# operation 페이지가 정상 로드되는지
curl -sk -o /dev/null -w "operation page: %{http_code}\n" "https://dev-boot.soritune.com/operation/"
```
Expected: `admin-cafe.js: 200`, `operation page: 200`

- [ ] **Step 5: dev push**

```bash
cd /root/boot-dev
git status   # working tree clean (untracked OK)
git log --oneline origin/dev..HEAD   # push 할 commits 확인 (4개 정도)
git push origin dev
git rev-parse origin/dev   # 새 origin/dev SHA
```

- [ ] **Step 6: 사용자 검증 가이드 정리**

다음 정보 정리해서 보고:

1. JS syntax check: admin-cafe.js + admin.js 모두 OK
2. 백엔드 회귀: qr_auth 13/13 + transaction 12/12 PASS
3. AdminCafeApp 호출 graph: admin.js 1곳 + admin-cafe.js 2곳
4. HTTP smoke: admin-cafe.js 200 + operation page 200
5. dev push 완료 (origin/dev 새 SHA)

PROD 반영 전 사용자 수동 검증 필요 항목 (frontend UI 라 자동 어려움):
- DEV operation 로그인 → **카페 게시글 탭** 진입 → 데이터 로드, 통계 배지, 테이블 렌더 정상
- 게시판 / 날짜 / 매핑 / 키워드 필터 → 검색 / 초기화 → 결과 정확
- 페이지네이션 (다음 / 이전) → 페이지 전환
- 수동 반영 버튼 → confirm → POST → 성공 메시지 + 1페이지 복귀
- 다른 탭 (members / admins / tasks / guides / calendar / cohorts) 정상 동작 (회귀 확인)
- leader / head / coach 페이지 → 카페 탭 자체가 안 보임 (회귀)

마이그/스키마 변경 없음 → PROD 반영은 main 머지 + boot-prod git pull 만

---

## Self-Review

### Spec coverage 체크

- [x] AdminCafeApp 신규 모듈 — Task 1
- [x] renderTab(container) 인자 사용 + containerEl 캐시 — Task 1 Step 1
- [x] loadPage(p) 헬퍼 + 모든 page 전이 통일 — Task 1 Step 1
- [x] _setPage HTML onclick — Task 1 Step 1 (페이지네이션 HTML 안)
- [x] admin.js 호출 site 교체 (line 405) — Task 3 Step 1
- [x] admin.js state/함수/return key 제거 — Task 3 Step 2~4
- [x] operation/index.php script 태그 추가 — Task 2
- [x] node --check syntax 검증 — Task 1 Step 2 + Task 3 Step 6 + Task 4 Step 1
- [x] 백엔드 회귀 — Task 4 Step 2
- [x] 다른 admin 페이지 (leader/head/coach) 는 admin-cafe.js 미로드 — Task 2 Step 3
- [x] 회원 form 의 cafe 닉네임 매칭 UI 손대지 않음 — Task 3 Step 5

### Type/시그니처 일관성

- `AdminCafeApp.renderTab(container)` — Task 1 정의, Task 3 호출 일치
- `AdminCafeApp._setPage(p)` — Task 1 정의 (페이지네이션 HTML 안에서만 호출), 외부 직접 호출 없음
- 내부 `loadPage(p)` / `load()` — Task 1 안에서 일관
- `containerEl` / `page` / `filter` — 모듈 closure 변수, Task 1 안에서 일관

### Placeholder scan

- 코드 블록은 모두 완전한 구현 텍스트 (TBD/TODO 없음)
- 각 step 의 expected 결과 명시
- BLOCKED 분기 명시 (단순 retry 금지)

---

## 안 함

- PROD 반영 (별도 작업; 사용자 명시 요청 후 main 머지 + boot-prod git pull)
- 다른 management 탭 분리 (members/admins/tasks/guides/calendar/cohorts) — 12기 오픈 후
- dashboard render core 분리 (143~650 줄) — 별도 spec
- bootcamp.js (2292 줄) / api/admin.php (1628 줄) 분리 — 12기 오픈 후
- AdminCafeApp 의 ES6 모듈화 — boot 의 글로벌 IIFE 패턴 유지
- 회원 form 의 cafe 닉네임 매칭 UI 이전 — 별개 영역
