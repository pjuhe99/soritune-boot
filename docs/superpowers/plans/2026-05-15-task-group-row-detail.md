# Task 묶음 미완료 상세 펼침 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Task 관리 그룹 행 클릭으로 펼쳐서 (날짜 + 담당자 + 완료여부) row 단위 확인 + 그 자리에서 toggle.

**Architecture:** 새 GET endpoint `task_group_rows` 추가 (필터 2개: only_incomplete / only_until_today). UI 는 클릭 → 펼침 → lazy fetch 패턴. Toggle 시 기존 `toggle_task` 재사용 + 진행 배지(`35/63`)는 client-side 로 ±1 직접 갱신해서 펼침 유지.

**Tech Stack:** PHP 8 (`api/admin.php`), MariaDB 10, Vanilla JS (`js/admin.js`).

**Spec:** [docs/superpowers/specs/2026-05-15-task-group-row-detail-design.md](../specs/2026-05-15-task-group-row-detail-design.md)

---

## File Structure

| 파일 | 변경 | 책임 |
|---|---|---|
| `public_html/api/admin.php` | 수정 (case 1개 추가) | `task_group_rows` GET |
| `public_html/js/admin.js` | 수정 (Task 관리 영역) | 그룹 행 클릭 + 펼침 + 필터 토글 + row 토글 + 진행 배지 갱신 |
| `tests/task_group_api_test.php` | 수정 (테스트 추가) | `task_group_rows` 4 케이스 |

---

### Task 1: API `task_group_rows` endpoint + 통합 테스트

**Files:**
- Modify: `public_html/api/admin.php` (case 1개 추가, `task_group_delete` case 다음, `// ── Task CRUD` 주석 직전)
- Modify: `tests/task_group_api_test.php` (새 테스트 4 케이스 try 블록 안에 추가)

- [ ] **Step 1.1: 실패하는 테스트 추가**

`tests/task_group_api_test.php` 의 try 블록 안, 기존 `task_group_delete` 테스트 다음 (`// ── Cleanup` 또는 `} finally {` 직전) 에 다음 추가:

```php
// ── Test: task_group_rows ──────────────────────
// 시드: __test_group__ 그룹은 task_group_delete 호출 후 completed=1 만 2 row 남음 (2099-01-04, 2099-01-05).
// 미래(2099) 날짜라 only_until_today=1 이면 0 row.

// 1) only_incomplete=0 only_until_today=0 → 2 row (남은 완료 row 모두)
$qsBase = "cohort=" . urlencode($cohort) . "&title=" . urlencode($titleA) . "&role=operation";
$r = req('GET', "$api?action=task_group_rows&{$qsBase}&only_incomplete=0&only_until_today=0", $h);
t('task_group_rows 200', $r['code'] === 200, "code={$r['code']}");
t('task_group_rows 전체 → 2 row', count($r['body']['rows'] ?? []) === 2, json_encode($r['body']));

// 2) only_incomplete=1 → 0 row (남은 row 모두 completed=1)
$r = req('GET', "$api?action=task_group_rows&{$qsBase}&only_incomplete=1&only_until_today=0", $h);
t('task_group_rows 미완료만 → 0 row', count($r['body']['rows'] ?? []) === 0);

// 3) only_until_today=1 + 미래(2099) 시드 → 0 row
$r = req('GET', "$api?action=task_group_rows&{$qsBase}&only_incomplete=0&only_until_today=1", $h);
t('task_group_rows 오늘까지 + 미래시드 → 0 row', count($r['body']['rows'] ?? []) === 0);

// 4) 응답 필드 cutoff_today 존재
$r = req('GET', "$api?action=task_group_rows&{$qsBase}&only_incomplete=0&only_until_today=0", $h);
t('task_group_rows cutoff_today 필드', !empty($r['body']['cutoff_today']),
   'cutoff_today=' . ($r['body']['cutoff_today'] ?? 'MISSING'));

// 5) 응답 row 형식 — assignee_kind ENUM
$r = req('GET', "$api?action=task_group_rows&{$qsBase}&only_incomplete=0&only_until_today=0", $h);
$rows = $r['body']['rows'] ?? [];
$validKinds = ['admin', 'member', 'unassigned'];
$allKindsValid = !empty($rows) && count(array_filter($rows, fn($r) => in_array($r['assignee_kind'] ?? '', $validKinds))) === count($rows);
t('task_group_rows assignee_kind 모두 valid', $allKindsValid);
```

- [ ] **Step 1.2: 테스트 실행해서 신규 케이스 fail 확인** (ADMIN_COOKIE 없으면 exit 2 — endpoint 가 없으므로 어느 쪽이든 PASS 안 나는 게 정상)

```bash
ADMIN_COOKIE='PHPSESSID_ADMIN=...' php /root/boot-dev/tests/task_group_api_test.php
```

- [ ] **Step 1.3: `task_group_rows` 구현**

`public_html/api/admin.php` 의 `task_group_delete` case **다음, `// ── Task CRUD` 주석 직전에** 삽입:

```php
case 'task_group_rows':
    $admin = requireAdmin(['operation']);
    $cohort = trim($_GET['cohort'] ?? '');
    $title  = trim($_GET['title']  ?? '');
    $role   = trim($_GET['role']   ?? '');
    if (!$cohort || !$title || !$role) jsonError('cohort/title/role 필수.');

    $onlyIncomplete = ($_GET['only_incomplete']  ?? '1') === '1';
    $onlyUntilToday = ($_GET['only_until_today'] ?? '1') === '1';

    $where  = "WHERE t.cohort = ? AND t.title = ? AND t.role = ?";
    $params = [$cohort, $title, $role];
    if ($onlyIncomplete) $where .= " AND t.completed = 0";
    if ($onlyUntilToday) $where .= " AND t.end_date <= CURDATE()";

    $db = getDB();
    $sql = "
        SELECT t.id,
               t.start_date,
               t.end_date,
               t.completed,
               COALESCE(a.name, bm.real_name) AS assignee_name,
               CASE
                 WHEN t.assignee_admin_id  IS NOT NULL THEN 'admin'
                 WHEN t.assignee_member_id IS NOT NULL THEN 'member'
                 ELSE 'unassigned'
               END AS assignee_kind
          FROM tasks t
          LEFT JOIN admins a            ON t.assignee_admin_id  = a.id
          LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
          $where
         ORDER BY t.start_date ASC, assignee_name ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $today = $db->query("SELECT CURDATE() AS d")->fetch()['d'];

    jsonSuccess([
        'rows'         => $stmt->fetchAll(),
        'cutoff_today' => $today,
        'filters'      => [
            'only_incomplete'  => $onlyIncomplete,
            'only_until_today' => $onlyUntilToday,
        ],
    ]);
    break;
```

- [ ] **Step 1.4: php -l + 테스트 다시 (cookie 없으니 exit 2 — fine)**

```bash
cd /root/boot-dev && php -l public_html/api/admin.php tests/task_group_api_test.php
```

Both: "No syntax errors detected".

- [ ] **Step 1.5: Commit**

```bash
cd /root/boot-dev && git add public_html/api/admin.php tests/task_group_api_test.php \
  && git commit -m "feat(api): task_group_rows — 묶음 안 row 목록 (필터 2개)

(cohort, title, role) 묶음 안의 row 목록 + 담당자 이름 + completed 반환.
필터: only_incomplete (default ON), only_until_today (default ON).
응답에 cutoff_today (서버 CURDATE) 포함 — UI 보조 텍스트용.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: UI — 그룹 행 클릭 가능 + 화살표 + stopPropagation

**Files:**
- Modify: `public_html/js/admin.js` (`loadTasksMgmt` 안의 `${groups.map(g => ...)}` 템플릿 + 행 click 핸들러 attach)

- [ ] **Step 2.1: 그룹 row 템플릿 수정**

`public_html/js/admin.js` 의 line 1471-1488 (현재 `${groups.map(g => {` 블록) 을 다음으로 교체:

```javascript
                        ${groups.map(g => {
                            const cohortAttr = encodeURIComponent(g.cohort);
                            const titleAttr  = encodeURIComponent(g.title);
                            const roleAttr   = encodeURIComponent(g.role);
                            return `
                            <tr class="group-row" data-cohort="${cohortAttr}" data-title="${titleAttr}" data-role="${roleAttr}" style="cursor:pointer">
                                <td><span class="expand-arrow" style="display:inline-block;width:14px;color:var(--gray-500,#888)">▶</span> ${App.esc(g.title)}</td>
                                <td><span class="badge badge-primary">${App.esc(ROLE_LABELS[g.role] || g.role)}</span></td>
                                <td>${assigneeLabel(g.assignee_count)}</td>
                                <td style="white-space:nowrap">${periodLabel(g.min_start_date, g.max_end_date)}</td>
                                <td>${progressBadge(g.done_count, g.total_count)}</td>
                                <td class="actions">
                                    <button class="btn-icon" onclick="event.stopPropagation();AdminApp._editTaskGroup('${cohortAttr}','${titleAttr}','${roleAttr}')">수정</button>
                                    <button class="btn-icon danger" onclick="event.stopPropagation();AdminApp._deleteTaskGroup('${cohortAttr}','${titleAttr}','${roleAttr}',${parseInt(g.total_count)||0},${parseInt(g.done_count)||0})">삭제</button>
                                </td>
                            </tr>
                        `;}).join('')}
```

핵심 변경:
- `<tr>` → `<tr class="group-row" data-cohort=... style="cursor:pointer">`
- 첫 `<td>` 의 title 앞에 `<span class="expand-arrow">▶</span>` 추가
- 수정·삭제 버튼 onclick 앞에 `event.stopPropagation();` prefix

- [ ] **Step 2.2: 그룹 row 에 click 핸들러 attach**

기존 `loadTasksMgmt` 함수 끝 부분의 chip onclick 등록 코드 **다음에** 다음 코드 추가 (`document.getElementById('task-mgmt-filter').querySelectorAll('.chip').forEach(...)` 블록 직후):

```javascript
        // 그룹 row 클릭 → 펼침 토글
        sec.querySelectorAll('tr.group-row').forEach(tr => {
            tr.addEventListener('click', () => AdminApp._toggleGroupExpand(tr));
        });
```

- [ ] **Step 2.3: 시각 검증 (수동)**

dev-boot.soritune.com 접속 후 새로고침 (Ctrl+Shift+R):
- 그룹 row 좌측에 ▶ 화살표 표시 확인
- 행 hover 시 cursor:pointer
- 행 클릭 시 콘솔 에러 (`AdminApp._toggleGroupExpand is not a function`) — Task 3 에서 와이어업 예정
- 수정/삭제 버튼 클릭 시 펼침 안 발생 (이건 Task 3 후에 진짜 검증 가능, 지금은 콘솔 에러만 안 나면 OK)

- [ ] **Step 2.4: Commit**

```bash
cd /root/boot-dev && git add public_html/js/admin.js \
  && git commit -m "feat(ui): Task 관리 그룹 행 click 가능 + 화살표 prefix

행 좌측 ▶ 화살표 + cursor:pointer. 수정/삭제 버튼은
event.stopPropagation 으로 행 클릭과 분리. 펼침 핸들러는 다음 commit.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: UI — 펼침 토글 + lazy fetch + 필터 토글

**Files:**
- Modify: `public_html/js/admin.js` (`_deleteTaskGroup` 정의 다음에 신규 함수 2개 + export 객체)

- [ ] **Step 3.1: 신규 함수 `_toggleGroupExpand` + `_renderGroupExpand` 추가**

`public_html/js/admin.js` 의 `_deleteTaskGroup` 정의 (현재 line 1717 부근) **다음에** 같은 IIFE 스코프 안에 다음 두 함수 추가:

```javascript
    async function _toggleGroupExpand(groupRow) {
        const tbody = groupRow.parentElement;

        // 다른 펼침 닫기
        tbody.querySelectorAll('tr.group-expand').forEach(tr => {
            if (tr.previousElementSibling !== groupRow) {
                const prev = tr.previousElementSibling;
                if (prev) {
                    const arrow = prev.querySelector('.expand-arrow');
                    if (arrow) arrow.textContent = '▶';
                }
                tr.remove();
            }
        });

        // 자기 자신 토글
        const next = groupRow.nextElementSibling;
        if (next && next.classList.contains('group-expand')) {
            next.remove();
            const arrow = groupRow.querySelector('.expand-arrow');
            if (arrow) arrow.textContent = '▶';
            return;
        }

        // 새 펼침 행 삽입
        const expandRow = document.createElement('tr');
        expandRow.className = 'group-expand';
        expandRow.innerHTML = `<td colspan="6" style="background:var(--gray-50,#fafafa);padding:0">
            <div class="group-expand-body"
                 data-cohort="${groupRow.dataset.cohort}"
                 data-title="${groupRow.dataset.title}"
                 data-role="${groupRow.dataset.role}"
                 data-only-incomplete="1"
                 data-only-until-today="1">
                <div class="empty-state" style="padding:16px">로딩 중...</div>
            </div>
        </td>`;
        groupRow.after(expandRow);
        const arrow = groupRow.querySelector('.expand-arrow');
        if (arrow) arrow.textContent = '▼';

        // 펼침 자체 클릭은 그룹 row click 으로 전파되지 않게
        expandRow.addEventListener('click', (e) => e.stopPropagation());

        await _renderGroupExpand(expandRow.querySelector('.group-expand-body'));
    }

    async function _renderGroupExpand(body) {
        const cohort         = decodeURIComponent(body.dataset.cohort);
        const title          = decodeURIComponent(body.dataset.title);
        const role           = decodeURIComponent(body.dataset.role);
        const onlyIncomplete = body.dataset.onlyIncomplete === '1';
        const onlyUntilToday = body.dataset.onlyUntilToday === '1';

        body.innerHTML = '<div class="empty-state" style="padding:16px">로딩 중...</div>';
        const r = await App.get('/api/admin.php?action=task_group_rows', {
            cohort, title, role,
            only_incomplete:  onlyIncomplete  ? '1' : '0',
            only_until_today: onlyUntilToday  ? '1' : '0',
        });
        if (!r.success) return;

        const rows   = r.rows || [];
        const cutoff = r.cutoff_today || '';
        const WD     = ['일','월','화','수','목','금','토'];

        function rowLine(row) {
            const d = new Date(row.start_date + 'T00:00:00');
            const dateLabel = `${d.getMonth()+1}/${d.getDate()}(${WD[d.getDay()]})`;
            const assignee  = row.assignee_kind === 'unassigned'
                ? '<span class="text-muted">미배정</span>'
                : App.esc(row.assignee_name || '?');
            const completed = parseInt(row.completed) === 1;
            const btnLabel  = completed ? '☑ 완료'      : '☐ 완료하기';
            const btnClass  = completed ? 'btn btn-success btn-sm' : 'btn btn-secondary btn-sm';
            return `<div class="group-row-line" style="display:grid;grid-template-columns:90px 1fr auto;gap:12px;align-items:center;padding:6px 12px;border-bottom:1px solid var(--gray-100,#eee)">
                <span style="font-family:monospace">${dateLabel}</span>
                <span>${assignee}</span>
                <button class="${btnClass}" data-task-id="${row.id}" data-completed="${completed?1:0}">${btnLabel}</button>
            </div>`;
        }

        const empty = onlyIncomplete
            ? '이 묶음은 오늘까지 미완료가 없습니다.'
            : '이 묶음에 row 가 없습니다.';

        body.innerHTML = `
            <div style="padding:8px 12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-bottom:1px solid var(--gray-200,#ddd)">
                <button class="chip ${onlyIncomplete  ? 'active' : ''}" data-toggle="incomplete">${onlyIncomplete  ? '●' : '○'} 미완료만</button>
                <button class="chip ${!onlyIncomplete ? 'active' : ''}" data-toggle="all">${!onlyIncomplete ? '●' : '○'} 전체</button>
                ${onlyUntilToday ? `<span class="text-muted" style="font-size:0.85rem;margin-left:auto">오늘까지: end_date ≤ ${App.esc(cutoff)}</span>` : ''}
            </div>
            ${rows.length
                ? rows.map(rowLine).join('')
                : `<div class="empty-state" style="padding:16px;text-align:center">${empty}</div>`}
        `;

        // 필터 토글
        body.querySelector('[data-toggle="incomplete"]').onclick = (e) => {
            e.stopPropagation();
            body.dataset.onlyIncomplete = '1';
            _renderGroupExpand(body);
        };
        body.querySelector('[data-toggle="all"]').onclick = (e) => {
            e.stopPropagation();
            body.dataset.onlyIncomplete = '0';
            _renderGroupExpand(body);
        };

        // row 토글 버튼 (Task 4 의 _toggleRowComplete 호출)
        body.querySelectorAll('button[data-task-id]').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                _toggleRowComplete(parseInt(btn.dataset.taskId, 10), btn);
            };
        });
    }
```

- [ ] **Step 3.2: Export 객체에 두 함수 등록**

`public_html/js/admin.js:2078` (현재 `_editTaskGroup, _deleteTaskGroup,` 라인) 을:

```javascript
        _editTaskGroup, _deleteTaskGroup, _toggleGroupExpand,
```

로 교체. (`_renderGroupExpand` 와 `_toggleRowComplete` 는 export 안 함 — 내부 호출만.)

NOTE: `_toggleRowComplete` 는 Task 4 에서 정의되므로 지금 호출 시 ReferenceError. Task 3 verification 단계에서는 row 토글 버튼 클릭만 안 하면 됨.

- [ ] **Step 3.3: 시각 검증 (수동)**

dev-boot.soritune.com 새로고침:
- 그룹 행 클릭 → 그 아래 펼침 등장 (▶ → ▼)
- 다른 그룹 클릭 → 이전 펼침 자동 닫힘
- 같은 그룹 다시 클릭 → 펼침 닫힘 (▼ → ▶)
- 펼침 안 [○ 미완료만 | ● 전체] 토글 → fetch 다시 + 리스트 갱신
- 보조 텍스트 "오늘까지: end_date ≤ 2026-05-15" 미완료만 모드에서만 보임
- DEV 데이터 없는 묶음이면 "이 묶음은 오늘까지 미완료가 없습니다." 빈 상태
- row 토글 버튼 클릭은 `_toggleRowComplete is not defined` 에러 — Task 4 후 와이어업

- [ ] **Step 3.4: php -l + node --check**

```bash
cd /root/boot-dev && node --check public_html/js/admin.js
```

- [ ] **Step 3.5: Commit**

```bash
cd /root/boot-dev && git add public_html/js/admin.js \
  && git commit -m "feat(ui): Task 묶음 행 펼침 + 미완료/전체 필터 토글

행 클릭으로 그 아래 펼침 row 동적 삽입 + lazy fetch task_group_rows.
한 번에 한 그룹만 펼침. 펼침 안 [○미완료만|●전체] 칩 토글 시 재fetch.
미완료만 모드일 때 'end_date ≤ 오늘' 보조 텍스트. row 토글 버튼은
다음 commit (Task 4) 에서 와이어업.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: UI — `_toggleRowComplete` + 진행 배지 client-side 갱신

**Files:**
- Modify: `public_html/js/admin.js` (`_renderGroupExpand` 다음에 신규 함수)

- [ ] **Step 4.1: `_toggleRowComplete` 함수 추가**

`public_html/js/admin.js` 의 `_renderGroupExpand` 함수 정의 **다음에** 추가:

```javascript
    async function _toggleRowComplete(taskId, btn) {
        const wasCompleted = parseInt(btn.dataset.completed, 10) === 1;
        const newCompleted = !wasCompleted;

        btn.disabled = true;
        const r = await App.post('/api/admin.php?action=toggle_task', {
            task_id: taskId,
            completed: newCompleted,
        });
        btn.disabled = false;

        if (!r.success) return; // App.post 가 실패 토스트 자동 emit

        // row 버튼 상태 갱신
        btn.dataset.completed = newCompleted ? '1' : '0';
        btn.textContent = newCompleted ? '☑ 완료' : '☐ 완료하기';
        btn.className   = newCompleted
            ? 'btn btn-success btn-sm'
            : 'btn btn-secondary btn-sm';

        // 그룹 row 진행 배지 client-side ±1
        const expandRow = btn.closest('tr.group-expand');
        const groupRow  = expandRow ? expandRow.previousElementSibling : null;
        if (!groupRow) return;
        // 컬럼 순서: 0:title 1:role 2:assignee 3:period 4:progress 5:actions
        const progressCell = groupRow.children[4];
        if (!progressCell) return;
        const badge = progressCell.querySelector('.badge');
        if (!badge) return;

        const match = badge.textContent.match(/(\d+)\s*\/\s*(\d+)/);
        if (!match) return;
        let done = parseInt(match[1], 10);
        const total = parseInt(match[2], 10);
        done += newCompleted ? 1 : -1;
        if (done < 0)     done = 0;
        if (done > total) done = total;

        if (done === 0) {
            badge.className = 'badge badge-warning';
            badge.textContent = `미완료 0/${total}`;
        } else if (done === total) {
            badge.className = 'badge badge-success';
            badge.textContent = `완료 ${done}/${total}`;
        } else {
            badge.className = 'badge badge-primary';
            badge.textContent = `진행 ${done}/${total}`;
        }
    }
```

- [ ] **Step 4.2: 시각 검증 (수동)**

dev-boot.soritune.com 새로고침:
- 그룹 펼침 → row [☐ 완료하기] 클릭 → 즉시 [☑ 완료] 로 변경 (success 클래스)
- 그룹 row 진행 배지 `35/63` → `36/63` 즉시 갱신, 완료 시 색상 변경
- 다시 클릭 → [☐ 완료하기] 원상복귀, 배지 -1
- 모두 완료 (`N/N`) → 초록 "완료 N/N", 모두 미완료 (`0/N`) → 노랑 "미완료 0/N"
- 펼침은 닫지 않고 유지

- [ ] **Step 4.3: node --check**

```bash
cd /root/boot-dev && node --check public_html/js/admin.js
```

Expected: clean.

- [ ] **Step 4.4: Commit**

```bash
cd /root/boot-dev && git add public_html/js/admin.js \
  && git commit -m "feat(ui): 펼침 row 토글 + 진행 배지 client-side ±1

기존 toggle_task API 재사용. 응답 후 그 row 의 버튼 라벨/클래스 갱신 +
상위 그룹 row 의 진행 배지 (35/63) 도 직접 ±1 + 색상 (success/primary/
warning) 재계산. 펼침 유지로 연속 토글 가능.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: 회귀 검증 + DEV push

**Files:** 변경 없음. 검증만.

- [ ] **Step 5.1: 인보리언트 회귀**

```bash
php /root/boot-dev/tests/task_group_invariants.php
```

Expected: `PASS=6  FAIL=0` (이전 spec 의 invariants 영향 없음 확인).

- [ ] **Step 5.2: cohort_switch 회귀**

```bash
php /root/boot-dev/tests/cohort_switch_invariants.php
```

Expected: 이전 PASS 상태 유지 (9 passed).

- [ ] **Step 5.3: HTTP 통합 테스트는 deferred 표시**

```bash
ADMIN_COOKIE='PHPSESSID_ADMIN=...' php /root/boot-dev/tests/task_group_api_test.php
```

ADMIN_COOKIE 가 없으니 exit 2. 사용자가 배포 후 cookie 와 함께 실행.

- [ ] **Step 5.4: 코드 회귀 검사**

회원·코치·조장 화면용 row 단위 endpoint 변경 없음 확인:

```bash
cd /root/boot-dev && git diff origin/dev..HEAD -- public_html/api/admin.php | grep -E "^[+-].*today_tasks|^[+-].*overdue_tasks|^[+-].*toggle_task"
```

Expected: zero 매치 (이번 작업이 today_tasks/overdue_tasks/toggle_task 코드 영역 무수정).

- [ ] **Step 5.5: DEV push**

```bash
cd /root/boot-dev && git push origin dev
```

⛔ **Push 후 멈춤. 사용자에게 dev 검증 요청. 운영 반영은 별도 명시 필요.**

---

## 검증 시나리오 (사용자 DEV 검증)

DEV 데이터가 부족할 수 있으니 시드 1 회 권장:

```bash
mysql -u root SORITUNECOM_DEV_BOOT <<'SQL'
SET @cohort := (SELECT cohort FROM cohorts ORDER BY start_date DESC LIMIT 1);
INSERT INTO tasks (title, role, assignee_admin_id, completed, start_date, end_date, content_markdown, cohort)
VALUES
  ('[DEV] 펼침 테스트', 'head', NULL, 0, '2026-05-13','2026-05-13','c', @cohort),
  ('[DEV] 펼침 테스트', 'head', NULL, 0, '2026-05-14','2026-05-14','c', @cohort),
  ('[DEV] 펼침 테스트', 'head', NULL, 0, '2026-05-15','2026-05-15','c', @cohort),
  ('[DEV] 펼침 테스트', 'head', NULL, 1, '2026-05-12','2026-05-12','c', @cohort),
  ('[DEV] 펼침 테스트', 'head', NULL, 0, '2026-05-20','2026-05-20','c', @cohort);
SQL
```

dev-boot.soritune.com/operation → "Task 관리" 탭에서:

1. **펼침 동작**: `[DEV] 펼침 테스트` 그룹 행 클릭 → 그 아래 펼침 등장 (5/12 완료, 5/13~5/15 미완료, 5/20 미래는 안 보임)
2. **다른 행 펼치면 이전 닫힘**
3. **미완료/전체 토글**:
   - 미완료만 → 5/13, 5/14, 5/15 (3개)
   - 전체 → 5/12 (완료) + 5/13~15 (미완료) + 5/20 (미래 미완료) — 5개
4. **보조 텍스트**: 미완료만 모드에서 `오늘까지: end_date ≤ 2026-05-15` 표시
5. **row 토글**: 5/13 [☐ 완료하기] 클릭 → [☑ 완료] + 그룹 진행 배지 +1 즉시 변경
6. **수정/삭제 버튼 분리**: 수정 클릭 시 펼침 안 발생
7. **회원·코치·조장 화면**: today_tasks/overdue_tasks 정상

검증 후 시드 정리:

```bash
mysql -u root SORITUNECOM_DEV_BOOT -e "DELETE FROM tasks WHERE title='[DEV] 펼침 테스트'"
```

---

## 운영 반영

⛔ 사용자가 "운영 반영해줘" 명시한 경우에만:

```bash
cd /root/boot-dev && git checkout main && git merge dev && git push origin main && git checkout dev
cd /root/boot-prod && git pull origin main
# DB 마이그 없음
```
