/* ── Admin Dashboard (Shared by all roles) ──────────────── */
/* V2: multi-role support, auto-assign tasks */
const AdminApp = (() => {
    const ROLE_LABELS = {
        leader: '팀장', coach: '코치', head: '총괄코치',
        subhead1: '부총괄1', subhead2: '부총괄2', operation: '운영팀'
    };
    const ALL_ROLES = ['leader', 'coach', 'head', 'subhead1', 'subhead2', 'operation'];
    const PAGE_ROLES = {
        head: ['head', 'subhead1', 'subhead2'],
        coach: ['coach'],
        leader: ['leader'],
        operation: ['operation'],
    };

    let role = '';       // page role (from data-role attribute)
    let admin = null;
    let currentDate = App.today();
    let overdueOpen = false;
    let root = null;

    function isOperation() {
        return admin && admin.admin_roles && admin.admin_roles.includes('operation');
    }

    // ── Init ──
    async function init() {
        root = document.getElementById('admin-root');
        role = root.dataset.role;

        App.showLoading();
        const r = await App.get('/api/admin.php?action=check_session');
        App.hideLoading();

        if (r.logged_in && r.admin.admin_roles && r.admin.admin_roles.some(ar => (PAGE_ROLES[role] || []).includes(ar))) {
            admin = r.admin;
            showDashboard();
        } else {
            showLoginForm();
        }
    }

    // ── Login ──
    function showLoginForm() {
        const pageLabel = (PAGE_ROLES[role] || [role]).map(r => ROLE_LABELS[r] || r).join(' / ');
        root.innerHTML = `
            <div class="admin-login">
                <div class="login-box">
                    <div class="login-title">소리튠 부트캠프</div>
                    <p class="login-subtitle"><span class="badge badge-primary">${App.esc(pageLabel)}</span></p>
                    <form id="login-form">
                        <div class="form-group">
                            <label class="form-label">아이디</label>
                            <input type="text" class="form-input" id="login-id" autocomplete="username" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">비밀번호</label>
                            <input type="password" class="form-input" id="login-pw" autocomplete="current-password" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block btn-lg mt-md">로그인</button>
                    </form>
                </div>
            </div>
        `;
        document.getElementById('login-form').onsubmit = async (e) => {
            e.preventDefault();
            const loginId = document.getElementById('login-id').value.trim();
            const password = document.getElementById('login-pw').value;
            if (!loginId || !password) return;

            App.showLoading();
            const r = await App.post('/api/admin.php?action=login', { login_id: loginId, password });
            App.hideLoading();

            if (r.success) {
                // Check if admin has a role compatible with this page
                const pageAllowed = (PAGE_ROLES[role] || []);
                const hasAccess = r.admin.admin_roles.some(ar => pageAllowed.includes(ar));
                if (!hasAccess) {
                    Toast.error('이 페이지에 접근 권한이 없습니다.');
                    return;
                }
                admin = r.admin;
                Toast.success(r.message);
                showDashboard();
            }
        };
    }

    // ── Dashboard ──
    async function showDashboard() {
        const displayRoles = admin.admin_roles.filter(r => (PAGE_ROLES[role] || []).includes(r));
        const roleLabel = displayRoles.map(r => ROLE_LABELS[r] || r).join(' / ');

        root.innerHTML = `
            <div class="admin-dashboard">
                <div class="admin-header">
                    <div class="admin-header-left">
                        <span class="header-title">소리튠 부트캠프</span>
                        <span class="role-label">${App.esc(roleLabel)}</span>
                    </div>
                    <div class="admin-header-right">
                        <span class="admin-name">${App.esc(admin.admin_name)}</span>
                        <button class="btn-logout" id="btn-logout">로그아웃</button>
                    </div>
                </div>
                ${isOperation() ? '<div class="cohort-bar" id="cohort-bar"></div>' : ''}
                <div class="admin-content">
                    <div class="section" id="sec-weekly"></div>
                    <div class="section" id="sec-guide-btn"></div>
                    <div class="section" id="sec-date-nav"></div>
                    <div class="section" id="sec-tasks"></div>
                    <div class="section" id="sec-overdue"></div>
                    ${isOperation() ? `
                    <div class="admin-tabs" id="sec-tabs">
                        <div class="tab_wrap">
                            <button class="tab active" data-tab="#tab-members">회원 관리</button>
                            <button class="tab" data-tab="#tab-admins">관리자 관리</button>
                            <button class="tab" data-tab="#tab-tasks-mgmt">Task 관리</button>
                            <button class="tab" data-tab="#tab-guides-mgmt">가이드 관리</button>
                            <button class="tab" data-tab="#tab-calendar-mgmt">캘린더 관리</button>
                        </div>
                        <div class="tab-content active" id="tab-members"></div>
                        <div class="tab-content" id="tab-admins"></div>
                        <div class="tab-content" id="tab-tasks-mgmt"></div>
                        <div class="tab-content" id="tab-guides-mgmt"></div>
                        <div class="tab-content" id="tab-calendar-mgmt"></div>
                    </div>
                    ` : `
                    <div class="admin-tabs" id="sec-tabs">
                        <div class="tab_wrap">
                            <button class="tab active" data-tab="#tab-placeholder">추가 기능</button>
                        </div>
                        <div class="tab-content active" id="tab-placeholder">
                            <div class="empty-state mt-lg">추후 확장 예정입니다.</div>
                        </div>
                    </div>
                    `}
                </div>
            </div>
        `;

        document.getElementById('btn-logout').onclick = async () => {
            await App.post('/api/admin.php?action=logout');
            Toast.info('로그아웃 되었습니다.');
            showLoginForm();
        };

        App.initTabs(document.getElementById('sec-tabs'));

        if (isOperation()) {
            await renderCohortBar();
        }

        await Promise.all([
            loadWeeklyGoal(),
            loadTodayTasks(),
            loadOverdueTasks(),
        ]);

        renderGuideButton();
        renderDateNav();

        if (isOperation()) {
            loadMembersMgmt();
            loadAdminsMgmt();
            loadTasksMgmt();
            loadGuidesMgmt();
            loadCalendarMgmt();
        }
    }

    // ── Cohort Bar (Operation) ──
    async function renderCohortBar() {
        const r = await App.get('/api/admin.php?action=cohort_list');
        if (!r.success) return;
        const bar = document.getElementById('cohort-bar');
        const opts = r.cohorts.map(c => `<option value="${App.esc(c)}" ${c === r.current_cohort ? 'selected' : ''}>${App.esc(c)}</option>`).join('');
        bar.innerHTML = `
            <span>현재 기수:</span>
            <select id="cohort-select">${opts}</select>
        `;
        document.getElementById('cohort-select').onchange = async (e) => {
            App.showLoading();
            const res = await App.post('/api/admin.php?action=change_cohort', { cohort: e.target.value });
            App.hideLoading();
            if (res.success) {
                Toast.success(res.message);
                showDashboard();
            }
        };
    }

    // ── Weekly Goal ──
    async function loadWeeklyGoal() {
        const sec = document.getElementById('sec-weekly');
        const r = await App.get('/api/admin.php?action=weekly_goals');
        if (r.success && r.goal) {
            sec.innerHTML = `
                <div class="section-title">이번주 목표</div>
                <div class="weekly-goal-card mt-sm">
                    <div class="weekly-goal-label">${App.esc(r.goal.week_label)} (${r.goal.start_date} ~ ${r.goal.end_date})</div>
                    <div class="weekly-goal-content">${App.esc(r.goal.content || '')}</div>
                </div>
            `;
        } else {
            sec.innerHTML = `
                <div class="section-title">이번주 목표</div>
                <div class="weekly-goal-card mt-sm">
                    <div class="weekly-goal-content text-muted">등록된 주간 목표가 없습니다.</div>
                </div>
            `;
        }
    }

    // ── Guide Button ──
    function renderGuideButton() {
        const sec = document.getElementById('sec-guide-btn');
        sec.innerHTML = `<button class="btn guide-btn btn-block" id="btn-guide">업무 가이드</button>`;
        document.getElementById('btn-guide').onclick = showGuidePopup;
    }

    // ── Guide Popup ──
    async function showGuidePopup() {
        App.showLoading();
        const r = await App.get('/api/admin.php?action=guide_list');
        App.hideLoading();
        if (!r.success) return;

        if (!r.guides.length) {
            App.openModal('업무 가이드', '<div class="empty-state">등록된 가이드가 없습니다.</div>');
            return;
        }

        const list = r.guides.map(g => `
            <a href="${App.esc(g.url)}" target="_blank" rel="noopener" class="list-item" style="display:block;text-decoration:none;color:inherit;">
                <div class="list-item-title">${App.esc(g.title)}</div>
                ${g.note ? `<div class="list-item-subtitle">${App.esc(g.note)}</div>` : ''}
            </a>
        `).join('');

        App.openModal('업무 가이드', list);
    }

    // ── Date Navigation ──
    function renderDateNav() {
        const sec = document.getElementById('sec-date-nav');
        const isToday = currentDate === App.today();
        sec.innerHTML = `
            <div class="paging">
                <button class="page-btn" id="date-prev">&lt;</button>
                <span class="page-label" id="date-label">${App.formatDateKo(currentDate)}</span>
                ${!isToday ? '<span class="page-today" id="date-today">오늘</span>' : ''}
                <button class="page-btn" id="date-next">&gt;</button>
            </div>
        `;
        document.getElementById('date-prev').onclick = () => changeDate(-1);
        document.getElementById('date-next').onclick = () => changeDate(1);
        const todayBtn = document.getElementById('date-today');
        if (todayBtn) todayBtn.onclick = () => { currentDate = App.today(); renderDateNav(); loadTodayTasks(); };
    }

    function changeDate(delta) {
        const d = new Date(currentDate + 'T00:00:00');
        d.setDate(d.getDate() + delta);
        currentDate = App.formatDate(d);
        renderDateNav();
        loadTodayTasks();
    }

    // ── Today's Tasks ──
    async function loadTodayTasks() {
        const sec = document.getElementById('sec-tasks');
        sec.innerHTML = '<div class="section-title">오늘의 Task</div><div class="empty-state">로딩 중...</div>';

        const r = await App.get('/api/admin.php?action=today_tasks', { date: currentDate });
        if (!r.success) return;

        const title = `<div class="section-title">오늘의 Task <span class="count">${r.tasks.length}개</span></div>`;

        if (!r.tasks.length) {
            sec.innerHTML = title + '<div class="empty-state">해당 날짜에 Task가 없습니다.</div>';
            return;
        }

        sec.innerHTML = title + '<div class="task-list">' + r.tasks.map(t => renderTaskCard(t)).join('') + '</div>';
        bindTaskEvents(sec);
    }

    // ── Overdue Tasks ──
    async function loadOverdueTasks() {
        const sec = document.getElementById('sec-overdue');
        const r = await App.get('/api/admin.php?action=overdue_tasks');
        if (!r.success) return;

        if (!r.tasks.length) {
            sec.innerHTML = '';
            return;
        }

        sec.innerHTML = `
            <button class="overdue-toggle ${overdueOpen ? 'open' : ''}" id="overdue-toggle">
                <span>지연된 Task <span class="badge badge-warning" style="margin-left:4px">${r.tasks.length}</span></span>
                <span class="arrow">▼</span>
            </button>
            <div class="overdue-list ${overdueOpen ? '' : 'hidden'}" id="overdue-list">
                <div class="task-list mt-sm">
                    ${r.tasks.map(t => renderTaskCard(t, true)).join('')}
                </div>
            </div>
        `;

        document.getElementById('overdue-toggle').onclick = () => {
            overdueOpen = !overdueOpen;
            document.getElementById('overdue-toggle').classList.toggle('open');
            document.getElementById('overdue-list').classList.toggle('hidden');
        };

        bindTaskEvents(document.getElementById('overdue-list'));
    }

    // ── Markdown Rendering ──
    function renderMarkdown(text) {
        if (!text) return '';
        if (typeof marked !== 'undefined') {
            return marked.parse(text, { breaks: true });
        }
        return App.esc(text).replace(/\n/g, '<br>');
    }

    // ── Task Card Rendering ──
    function renderTaskCard(task, isOverdue = false) {
        const completed = parseInt(task.completed);
        const hasContent = !!task.content_markdown;
        return `
            <div class="task-card ${completed ? 'completed' : ''} ${isOverdue && !completed ? 'overdue' : ''}" data-id="${task.id}">
                <div class="task-top">
                    <input type="checkbox" class="task-checkbox" ${completed ? 'checked' : ''} data-task-id="${task.id}">
                    <div class="task-info">
                        <div class="task-title">${App.esc(task.title)}</div>
                        <div class="task-meta">
                            <span>${task.start_date} ~ ${task.end_date}</span>
                            ${task.assignee_name ? `<span class="badge badge-primary">${App.esc(task.assignee_name)}</span>` : ''}
                            ${isOperation() ? `<span class="badge badge-primary">${App.esc(ROLE_LABELS[task.role] || task.role)}</span>` : ''}
                            ${hasContent ? `<button class="task-toggle-content" data-task-id="${task.id}">내용 보기</button>` : ''}
                        </div>
                        ${hasContent ? `<div class="task-content collapsed" id="task-content-${task.id}">${renderMarkdown(task.content_markdown)}</div>` : ''}
                    </div>
                </div>
            </div>
        `;
    }

    function bindTaskEvents(container) {
        container.querySelectorAll('.task-checkbox').forEach(cb => {
            cb.onchange = async () => {
                const taskId = parseInt(cb.dataset.taskId);
                const r = await App.post('/api/admin.php?action=toggle_task', { task_id: taskId, completed: cb.checked });
                if (r.success) {
                    Toast.success(r.message);
                    loadTodayTasks();
                    loadOverdueTasks();
                }
            };
        });

        container.querySelectorAll('.task-toggle-content').forEach(btn => {
            btn.onclick = () => {
                const el = document.getElementById(`task-content-${btn.dataset.taskId}`);
                if (el) {
                    el.classList.toggle('collapsed');
                    btn.textContent = el.classList.contains('collapsed') ? '내용 보기' : '내용 접기';
                }
            };
        });
    }

    // ══════════════════════════════════════════════════════════
    // ── Operation CRUD ──
    // ══════════════════════════════════════════════════════════

    // ── Members Management ──
    async function loadMembersMgmt() {
        const sec = document.getElementById('tab-members');
        sec.innerHTML = '<div class="empty-state">로딩 중...</div>';
        const r = await App.get('/api/admin.php?action=member_list');
        if (!r.success) return;

        sec.innerHTML = `
            <div class="mgmt-toolbar mt-md">
                <span style="font-weight:600">회원 (${r.members.length}명)</span>
                <button class="btn btn-primary btn-sm" id="btn-add-member">추가</button>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead><tr><th>이름</th><th>전화번호</th><th>포인트</th><th>상태</th><th></th></tr></thead>
                    <tbody>
                        ${r.members.map(m => `
                            <tr>
                                <td>${App.esc(m.name)}</td>
                                <td>${App.esc(m.phone)}</td>
                                <td>${m.point}</td>
                                <td>${m.is_active == 1 ? '<span class="badge badge-success">활성</span>' : '<span class="badge badge-danger">비활성</span>'}</td>
                                <td class="actions">
                                    <button class="btn-icon" onclick="AdminApp._editMember(${m.id}, '${App.esc(m.name)}', '${App.esc(m.phone)}', ${m.point}, ${m.is_active})">수정</button>
                                    <button class="btn-icon danger" onclick="AdminApp._deleteMember(${m.id}, '${App.esc(m.name)}')">삭제</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        if (!r.members.length) sec.querySelector('tbody').innerHTML = '<tr><td colspan="5" class="empty-state">등록된 회원이 없습니다.</td></tr>';
        document.getElementById('btn-add-member').onclick = () => showMemberForm();
    }

    function showMemberForm(data = {}) {
        const isEdit = !!data.id;
        const body = `
            <div class="form-group">
                <label class="form-label">이름 *</label>
                <input type="text" class="form-input" id="mf-name" value="${App.esc(data.name || '')}">
            </div>
            <div class="form-group">
                <label class="form-label">전화번호 *</label>
                <input type="tel" class="form-input" id="mf-phone" value="${App.esc(data.phone || '')}" placeholder="01012345678">
            </div>
            <div class="form-group">
                <label class="form-label">포인트</label>
                <input type="number" class="form-input" id="mf-point" value="${data.point ?? 0}">
            </div>
            ${isEdit ? `
            <div class="form-group">
                <label class="form-label">상태</label>
                <select class="form-select" id="mf-active">
                    <option value="1" ${data.is_active == 1 ? 'selected' : ''}>활성</option>
                    <option value="0" ${data.is_active == 0 ? 'selected' : ''}>비활성</option>
                </select>
            </div>` : ''}
        `;
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">취소</button>
            <button class="btn btn-primary" id="mf-save">${isEdit ? '수정' : '추가'}</button>
        `;
        App.openModal(isEdit ? '회원 수정' : '회원 추가', body, footer);
        document.getElementById('mf-save').onclick = async () => {
            const payload = {
                name: document.getElementById('mf-name').value.trim(),
                phone: document.getElementById('mf-phone').value.trim(),
                point: parseInt(document.getElementById('mf-point').value) || 0,
            };
            if (isEdit) {
                payload.id = data.id;
                payload.is_active = parseInt(document.getElementById('mf-active').value);
            }
            if (!payload.name || !payload.phone) return Toast.warning('이름과 전화번호를 입력해주세요.');

            App.showLoading();
            const r = await App.post(`/api/admin.php?action=${isEdit ? 'member_update' : 'member_create'}`, payload);
            App.hideLoading();
            if (r.success) {
                App.closeModal();
                Toast.success(r.message);
                loadMembersMgmt();
            }
        };
    }

    async function _editMember(id, name, phone, point, active) {
        showMemberForm({ id, name, phone, point, is_active: active });
    }

    async function _deleteMember(id, name) {
        if (!await App.confirm(`'${name}' 회원을 삭제하시겠습니까?`)) return;
        App.showLoading();
        const r = await App.post('/api/admin.php?action=member_delete', { id });
        App.hideLoading();
        if (r.success) { Toast.success(r.message); loadMembersMgmt(); }
    }

    // ── Admins Management ──
    async function loadAdminsMgmt() {
        const sec = document.getElementById('tab-admins');
        sec.innerHTML = '<div class="empty-state">로딩 중...</div>';
        const r = await App.get('/api/admin.php?action=admin_list');
        if (!r.success) return;

        sec.innerHTML = `
            <div class="mgmt-toolbar mt-md">
                <span style="font-weight:600">관리자 (${r.admins.length}명)</span>
                <button class="btn btn-primary btn-sm" id="btn-add-admin">추가</button>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead><tr><th>이름</th><th>아이디</th><th>역할</th><th>기수</th><th>팀/시간</th><th></th></tr></thead>
                    <tbody>
                        ${r.admins.map(a => `
                            <tr>
                                <td>${App.esc(a.name)}</td>
                                <td>${App.esc(a.login_id)}</td>
                                <td>${(a.roles || []).map(r => `<span class="badge badge-primary" style="margin:1px">${App.esc(ROLE_LABELS[r] || r)}</span>`).join(' ')}</td>
                                <td>${App.esc(a.cohort || '-')}</td>
                                <td>${App.esc(a.team || a.class_time || '-')}</td>
                                <td class="actions">
                                    <button class="btn-icon" onclick="AdminApp._editAdmin(${a.id})">수정</button>
                                    <button class="btn-icon danger" onclick="AdminApp._deleteAdmin(${a.id}, '${App.esc(a.name)}')">삭제</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        document.getElementById('btn-add-admin').onclick = () => showAdminForm();
    }

    function renderRoleCheckboxes(selectedRoles = [], idPrefix = 'af') {
        return ALL_ROLES.map(r => `
            <label style="display:inline-flex;align-items:center;gap:4px;margin-right:12px;margin-bottom:4px">
                <input type="checkbox" class="${idPrefix}-role-cb" value="${r}" ${selectedRoles.includes(r) ? 'checked' : ''}>
                ${App.esc(ROLE_LABELS[r])}
            </label>
        `).join('');
    }

    function getCheckedRoles(idPrefix = 'af') {
        return Array.from(document.querySelectorAll(`.${idPrefix}-role-cb:checked`)).map(cb => cb.value);
    }

    function showAdminForm(data = {}) {
        const isEdit = !!data.id;
        const selectedRoles = data.roles || [];
        const body = `
            <div class="form-group">
                <label class="form-label">이름 *</label>
                <input type="text" class="form-input" id="af-name" value="${App.esc(data.name || '')}">
            </div>
            <div class="form-group">
                <label class="form-label">아이디 *</label>
                <input type="text" class="form-input" id="af-loginid" value="${App.esc(data.login_id || '')}" ${isEdit ? 'readonly style="background:#f3f4f6"' : ''}>
            </div>
            <div class="form-group">
                <label class="form-label">${isEdit ? '비밀번호 (변경 시만 입력)' : '비밀번호 *'}</label>
                <input type="password" class="form-input" id="af-pw" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label class="form-label">역할 * (복수 선택 가능)</label>
                <div id="af-roles-wrap" style="display:flex;flex-wrap:wrap;padding:8px 0">
                    ${renderRoleCheckboxes(selectedRoles, 'af')}
                </div>
            </div>
            <div class="form-group" id="af-cohort-group">
                <label class="form-label">기수</label>
                <input type="text" class="form-input" id="af-cohort" value="${App.esc(data.cohort || '')}" placeholder="예: 1기">
            </div>
            <div class="form-group">
                <label class="form-label">팀 (팀장용)</label>
                <input type="text" class="form-input" id="af-team" value="${App.esc(data.team || '')}">
            </div>
            <div class="form-group">
                <label class="form-label">수업 시간 (코치용)</label>
                <input type="text" class="form-input" id="af-classtime" value="${App.esc(data.class_time || '')}">
            </div>
        `;
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">취소</button>
            <button class="btn btn-primary" id="af-save">${isEdit ? '수정' : '추가'}</button>
        `;
        App.openModal(isEdit ? '관리자 수정' : '관리자 추가', body, footer);

        document.getElementById('af-save').onclick = async () => {
            const roles = getCheckedRoles('af');
            const payload = {
                name: document.getElementById('af-name').value.trim(),
                login_id: document.getElementById('af-loginid').value.trim(),
                roles: roles,
                cohort: document.getElementById('af-cohort').value.trim(),
                team: document.getElementById('af-team').value.trim(),
                class_time: document.getElementById('af-classtime').value.trim(),
            };
            const pw = document.getElementById('af-pw').value;
            if (pw) payload.password = pw;
            if (isEdit) payload.id = data.id;

            if (!payload.name) return Toast.warning('이름을 입력해주세요.');
            if (!isEdit && !payload.login_id) return Toast.warning('아이디를 입력해주세요.');
            if (!isEdit && !pw) return Toast.warning('비밀번호를 입력해주세요.');
            if (!roles.length) return Toast.warning('역할을 하나 이상 선택해주세요.');

            App.showLoading();
            const r = await App.post(`/api/admin.php?action=${isEdit ? 'admin_update' : 'admin_create'}`, payload);
            App.hideLoading();
            if (r.success) {
                App.closeModal();
                Toast.success(r.message);
                loadAdminsMgmt();
            }
        };
    }

    async function _editAdmin(id) {
        const r = await App.get('/api/admin.php?action=admin_list');
        if (!r.success) return;
        const a = r.admins.find(x => x.id == id);
        if (a) showAdminForm(a);
    }

    async function _deleteAdmin(id, name) {
        if (!await App.confirm(`'${name}' 관리자를 삭제하시겠습니까?`)) return;
        App.showLoading();
        const r = await App.post('/api/admin.php?action=admin_delete', { id });
        App.hideLoading();
        if (r.success) { Toast.success(r.message); loadAdminsMgmt(); }
    }

    // ── Tasks Management ──
    async function loadTasksMgmt() {
        const sec = document.getElementById('tab-tasks-mgmt');
        sec.innerHTML = '<div class="empty-state">로딩 중...</div>';

        const [rToday, rOverdue] = await Promise.all([
            App.get('/api/admin.php?action=today_tasks', { date: App.today() }),
            App.get('/api/admin.php?action=overdue_tasks'),
        ]);

        const seen = new Set();
        const tasks = [];
        for (const t of [...(rOverdue.tasks || []), ...(rToday.tasks || [])]) {
            if (!seen.has(t.id)) { seen.add(t.id); tasks.push(t); }
        }

        sec.innerHTML = `
            <div class="mgmt-toolbar mt-md">
                <span style="font-weight:600">Task 관리</span>
                <button class="btn btn-primary btn-sm" id="btn-add-task">추가</button>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead><tr><th>제목</th><th>역할</th><th>담당자</th><th>기간</th><th>완료</th><th></th></tr></thead>
                    <tbody>
                        ${tasks.map(t => `
                            <tr>
                                <td>${App.esc(t.title)}</td>
                                <td><span class="badge badge-primary">${App.esc(ROLE_LABELS[t.role] || t.role)}</span></td>
                                <td>${App.esc(t.assignee_name || '-')}</td>
                                <td style="white-space:nowrap">${t.start_date} ~ ${t.end_date}</td>
                                <td>${parseInt(t.completed) ? '<span class="badge badge-success">완료</span>' : '<span class="badge badge-warning">미완료</span>'}</td>
                                <td class="actions">
                                    <button class="btn-icon" onclick="AdminApp._editTask(${t.id})">수정</button>
                                    <button class="btn-icon danger" onclick="AdminApp._deleteTask(${t.id}, '${App.esc(t.title)}')">삭제</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            <p class="text-muted mt-sm" style="font-size:0.8rem">* 현재 오늘 날짜 기준 + 지연 Task만 표시됩니다.</p>
        `;
        if (!tasks.length) sec.querySelector('tbody').innerHTML = '<tr><td colspan="6" class="empty-state">Task가 없습니다.</td></tr>';
        document.getElementById('btn-add-task').onclick = () => showTaskForm();
    }

    const WEEKDAY_LABELS = ['일', '월', '화', '수', '목', '금', '토'];

    function showTaskForm(data = {}) {
        const isEdit = !!data.id;

        let roleSection;
        if (isEdit) {
            roleSection = `
                <div class="form-group">
                    <label class="form-label">담당 역할</label>
                    <select class="form-select" id="tf-role">
                        ${ALL_ROLES.map(r => `<option value="${r}" ${data.role === r ? 'selected' : ''}>${App.esc(ROLE_LABELS[r])}</option>`).join('')}
                    </select>
                </div>
            `;
        } else {
            roleSection = `
                <div class="form-group">
                    <label class="form-label">담당 역할 * (복수 선택 가능)</label>
                    <div style="display:flex;flex-wrap:wrap;padding:8px 0">
                        ${renderRoleCheckboxes([], 'tf')}
                    </div>
                </div>
            `;
        }

        // Date mode selector (create only)
        const dateModeSection = isEdit ? '' : `
            <div class="form-group">
                <label class="form-label">날짜 설정 방식 *</label>
                <div style="display:flex;flex-wrap:wrap;gap:8px;padding:4px 0">
                    <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">
                        <input type="radio" name="tf-date-mode" value="direct" checked> 날짜 직접 선택
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">
                        <input type="radio" name="tf-date-mode" value="week"> 주차/요일 선택
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">
                        <input type="radio" name="tf-date-mode" value="daily"> 데일리 반복
                    </label>
                </div>
            </div>
        `;

        // Direct date fields
        const directDateSection = `
            <div id="tf-mode-direct" class="tf-date-section">
                <div class="form-group">
                    <label class="form-label">시작일 *</label>
                    <input type="date" class="form-input" id="tf-start" value="${data.start_date || ''}">
                </div>
                <div class="form-group">
                    <label class="form-label">종료일 *</label>
                    <input type="date" class="form-input" id="tf-end" value="${data.end_date || ''}">
                </div>
            </div>
        `;

        // Week/day fields
        const weekDaySection = isEdit ? '' : `
            <div id="tf-mode-week" class="tf-date-section" style="display:none">
                <div class="form-group">
                    <label class="form-label">주차 *</label>
                    <input type="number" class="form-input" id="tf-week-num" min="1" max="52" placeholder="예: 1">
                </div>
                <div class="form-group">
                    <label class="form-label">요일 *</label>
                    <select class="form-select" id="tf-weekday">
                        <option value="1">월요일</option>
                        <option value="2">화요일</option>
                        <option value="3">수요일</option>
                        <option value="4">목요일</option>
                        <option value="5">금요일</option>
                        <option value="6">토요일</option>
                        <option value="0">일요일</option>
                    </select>
                </div>
                <p class="text-muted" style="font-size:0.8rem;margin-top:-8px">* cohort 시작일 기준 N주차의 해당 요일로 날짜가 계산됩니다.</p>
            </div>
        `;

        // Daily repeat fields
        const dailySection = isEdit ? '' : `
            <div id="tf-mode-daily" class="tf-date-section" style="display:none">
                <div class="form-group">
                    <label class="form-label">반복 요일 * (복수 선택 가능)</label>
                    <div style="display:flex;flex-wrap:wrap;gap:6px;padding:4px 0">
                        ${WEEKDAY_LABELS.map((label, i) => `
                            <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">
                                <input type="checkbox" class="tf-daily-day" value="${i}"> ${label}
                            </label>
                        `).join('')}
                    </div>
                </div>
                <p class="text-muted" style="font-size:0.8rem;margin-top:-8px">* cohort 시작일~종료일 범위에서 선택한 요일에 해당하는 날짜마다 task가 생성됩니다.</p>
            </div>
        `;

        const body = `
            <div class="form-group">
                <label class="form-label">제목 *</label>
                <input type="text" class="form-input" id="tf-title" value="${App.esc(data.title || '')}">
            </div>
            ${roleSection}
            ${dateModeSection}
            ${directDateSection}
            ${weekDaySection}
            ${dailySection}
            <div class="form-group">
                <label class="form-label">내용</label>
                <textarea class="form-textarea" id="tf-content" rows="4" style="resize:vertical">${App.esc(data.content_markdown || '')}</textarea>
            </div>
        `;
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">취소</button>
            <button class="btn btn-primary" id="tf-save">${isEdit ? '수정' : '추가'}</button>
        `;
        App.openModal(isEdit ? 'Task 수정' : 'Task 추가', body, footer);

        // Date mode switching (create only)
        if (!isEdit) {
            const modeRadios = document.querySelectorAll('input[name="tf-date-mode"]');
            modeRadios.forEach(radio => {
                radio.addEventListener('change', () => {
                    document.querySelectorAll('.tf-date-section').forEach(s => s.style.display = 'none');
                    const target = document.getElementById('tf-mode-' + radio.value);
                    if (target) target.style.display = '';
                });
            });
        }

        document.getElementById('tf-save').onclick = async () => {
            const payload = {
                title: document.getElementById('tf-title').value.trim(),
                content_markdown: document.getElementById('tf-content').value.trim(),
            };

            if (isEdit) {
                payload.id = data.id;
                payload.role = document.getElementById('tf-role').value;
                payload.start_date = document.getElementById('tf-start').value;
                payload.end_date = document.getElementById('tf-end').value;
                if (!payload.title || !payload.start_date || !payload.end_date) return Toast.warning('필수 항목을 모두 입력해주세요.');
            } else {
                payload.roles = getCheckedRoles('tf');
                if (!payload.roles.length) return Toast.warning('역할을 하나 이상 선택해주세요.');

                const mode = document.querySelector('input[name="tf-date-mode"]:checked').value;
                payload.date_mode = mode;

                if (mode === 'direct') {
                    payload.start_date = document.getElementById('tf-start').value;
                    payload.end_date = document.getElementById('tf-end').value;
                    if (!payload.start_date || !payload.end_date) return Toast.warning('시작일과 종료일을 입력해주세요.');
                } else if (mode === 'week') {
                    payload.week_number = parseInt(document.getElementById('tf-week-num').value);
                    payload.weekday = parseInt(document.getElementById('tf-weekday').value);
                    if (!payload.week_number || isNaN(payload.weekday)) return Toast.warning('주차와 요일을 선택해주세요.');
                } else if (mode === 'daily') {
                    payload.repeat_days = Array.from(document.querySelectorAll('.tf-daily-day:checked')).map(cb => parseInt(cb.value));
                    if (!payload.repeat_days.length) return Toast.warning('반복할 요일을 하나 이상 선택해주세요.');
                }
            }

            if (!payload.title) return Toast.warning('제목을 입력해주세요.');

            App.showLoading();
            const r = await App.post(`/api/admin.php?action=${isEdit ? 'task_update' : 'task_create'}`, payload);
            App.hideLoading();
            if (r.success) {
                App.closeModal();
                Toast.success(r.message);
                loadTasksMgmt();
                loadTodayTasks();
                loadOverdueTasks();
            }
        };
    }

    async function _editTask(id) {
        const [r1, r2] = await Promise.all([
            App.get('/api/admin.php?action=today_tasks', { date: App.today() }),
            App.get('/api/admin.php?action=overdue_tasks'),
        ]);
        const all = [...(r1.tasks || []), ...(r2.tasks || [])];
        const t = all.find(x => x.id == id);
        if (t) showTaskForm(t);
        else Toast.error('Task를 찾을 수 없습니다.');
    }

    async function _deleteTask(id, title) {
        if (!await App.confirm(`'${title}' Task를 삭제하시겠습니까?`)) return;
        App.showLoading();
        const r = await App.post('/api/admin.php?action=task_delete', { id });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            loadTasksMgmt();
            loadTodayTasks();
            loadOverdueTasks();
        }
    }

    // ── Guides Management ──
    async function loadGuidesMgmt() {
        const sec = document.getElementById('tab-guides-mgmt');
        sec.innerHTML = '<div class="empty-state">로딩 중...</div>';
        const r = await App.get('/api/admin.php?action=guide_list');
        if (!r.success) return;

        sec.innerHTML = `
            <div class="mgmt-toolbar mt-md">
                <span style="font-weight:600">가이드 (${r.guides.length}개)</span>
                <button class="btn btn-primary btn-sm" id="btn-add-guide">추가</button>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead><tr><th>제목</th><th>역할</th><th>메모</th><th></th></tr></thead>
                    <tbody>
                        ${r.guides.map(g => `
                            <tr>
                                <td><a href="${App.esc(g.url)}" target="_blank" rel="noopener">${App.esc(g.title)}</a></td>
                                <td><span class="badge badge-primary">${App.esc(ROLE_LABELS[g.role] || g.role)}</span></td>
                                <td>${App.esc(g.note || '-')}</td>
                                <td class="actions">
                                    <button class="btn-icon" onclick="AdminApp._editGuide(${g.id})">수정</button>
                                    <button class="btn-icon danger" onclick="AdminApp._deleteGuide(${g.id}, '${App.esc(g.title)}')">삭제</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        if (!r.guides.length) sec.querySelector('tbody').innerHTML = '<tr><td colspan="4" class="empty-state">등록된 가이드가 없습니다.</td></tr>';
        document.getElementById('btn-add-guide').onclick = () => showGuideForm();
    }

    function showGuideForm(data = {}) {
        const isEdit = !!data.id;
        const body = `
            <div class="form-group">
                <label class="form-label">제목 *</label>
                <input type="text" class="form-input" id="gf-title" value="${App.esc(data.title || '')}">
            </div>
            <div class="form-group">
                <label class="form-label">URL *</label>
                <input type="url" class="form-input" id="gf-url" value="${App.esc(data.url || '')}" placeholder="https://...">
            </div>
            <div class="form-group">
                <label class="form-label">담당 역할 *</label>
                <select class="form-select" id="gf-role">
                    ${ALL_ROLES.map(r => `<option value="${r}" ${data.role === r ? 'selected' : ''}>${App.esc(ROLE_LABELS[r])}</option>`).join('')}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">메모</label>
                <input type="text" class="form-input" id="gf-note" value="${App.esc(data.note || '')}">
            </div>
            <div class="form-group">
                <label class="form-label">정렬 순서</label>
                <input type="number" class="form-input" id="gf-sort" value="${data.sort_order ?? 0}">
            </div>
        `;
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">취소</button>
            <button class="btn btn-primary" id="gf-save">${isEdit ? '수정' : '추가'}</button>
        `;
        App.openModal(isEdit ? '가이드 수정' : '가이드 추가', body, footer);
        document.getElementById('gf-save').onclick = async () => {
            const payload = {
                title: document.getElementById('gf-title').value.trim(),
                url: document.getElementById('gf-url').value.trim(),
                role: document.getElementById('gf-role').value,
                note: document.getElementById('gf-note').value.trim(),
                sort_order: parseInt(document.getElementById('gf-sort').value) || 0,
            };
            if (isEdit) payload.id = data.id;
            if (!payload.title || !payload.url) return Toast.warning('제목과 URL을 입력해주세요.');

            App.showLoading();
            const r = await App.post(`/api/admin.php?action=${isEdit ? 'guide_update' : 'guide_create'}`, payload);
            App.hideLoading();
            if (r.success) {
                App.closeModal();
                Toast.success(r.message);
                loadGuidesMgmt();
            }
        };
    }

    async function _editGuide(id) {
        const r = await App.get('/api/admin.php?action=guide_list');
        if (!r.success) return;
        const g = r.guides.find(x => x.id == id);
        if (g) showGuideForm(g);
    }

    async function _deleteGuide(id, title) {
        if (!await App.confirm(`'${title}' 가이드를 삭제하시겠습니까?`)) return;
        App.showLoading();
        const r = await App.post('/api/admin.php?action=guide_delete', { id });
        App.hideLoading();
        if (r.success) { Toast.success(r.message); loadGuidesMgmt(); }
    }

    // ── Calendar Management ──
    async function loadCalendarMgmt() {
        const sec = document.getElementById('tab-calendar-mgmt');
        sec.innerHTML = '<div class="empty-state">로딩 중...</div>';
        const r = await App.get('/api/admin.php?action=calendar_list');
        if (!r.success) return;

        sec.innerHTML = `
            <div class="mgmt-toolbar mt-md">
                <span style="font-weight:600">캘린더 (${r.calendar.length}개)</span>
                <button class="btn btn-primary btn-sm" id="btn-add-cal">추가</button>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead><tr><th>주차</th><th>기간</th><th>내용</th><th></th></tr></thead>
                    <tbody>
                        ${r.calendar.map(c => `
                            <tr>
                                <td>${App.esc(c.week_label)}</td>
                                <td style="white-space:nowrap">${c.start_date} ~ ${c.end_date}</td>
                                <td>${App.esc((c.content || '').substring(0, 50))}${(c.content || '').length > 50 ? '...' : ''}</td>
                                <td class="actions">
                                    <button class="btn-icon" onclick="AdminApp._editCalendar(${c.id})">수정</button>
                                    <button class="btn-icon danger" onclick="AdminApp._deleteCalendar(${c.id}, '${App.esc(c.week_label)}')">삭제</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        if (!r.calendar.length) sec.querySelector('tbody').innerHTML = '<tr><td colspan="4" class="empty-state">등록된 캘린더가 없습니다.</td></tr>';
        document.getElementById('btn-add-cal').onclick = () => showCalendarForm();
    }

    function showCalendarForm(data = {}) {
        const isEdit = !!data.id;
        const body = `
            <div class="form-group">
                <label class="form-label">주차 라벨 *</label>
                <input type="text" class="form-input" id="cf-label" value="${App.esc(data.week_label || '')}" placeholder="예: 1주차">
            </div>
            <div class="form-group">
                <label class="form-label">시작일 *</label>
                <input type="date" class="form-input" id="cf-start" value="${data.start_date || ''}">
            </div>
            <div class="form-group">
                <label class="form-label">종료일 *</label>
                <input type="date" class="form-input" id="cf-end" value="${data.end_date || ''}">
            </div>
            <div class="form-group">
                <label class="form-label">내용</label>
                <textarea class="form-textarea" id="cf-content" rows="4" style="resize:vertical">${App.esc(data.content || '')}</textarea>
            </div>
        `;
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">취소</button>
            <button class="btn btn-primary" id="cf-save">${isEdit ? '수정' : '추가'}</button>
        `;
        App.openModal(isEdit ? '캘린더 수정' : '캘린더 추가', body, footer);
        document.getElementById('cf-save').onclick = async () => {
            const payload = {
                week_label: document.getElementById('cf-label').value.trim(),
                start_date: document.getElementById('cf-start').value,
                end_date: document.getElementById('cf-end').value,
                content: document.getElementById('cf-content').value.trim(),
            };
            if (isEdit) payload.id = data.id;
            if (!payload.week_label || !payload.start_date || !payload.end_date) return Toast.warning('필수 항목을 모두 입력해주세요.');

            App.showLoading();
            const r = await App.post(`/api/admin.php?action=${isEdit ? 'calendar_update' : 'calendar_create'}`, payload);
            App.hideLoading();
            if (r.success) {
                App.closeModal();
                Toast.success(r.message);
                loadCalendarMgmt();
                loadWeeklyGoal();
            }
        };
    }

    async function _editCalendar(id) {
        const r = await App.get('/api/admin.php?action=calendar_list');
        if (!r.success) return;
        const c = r.calendar.find(x => x.id == id);
        if (c) showCalendarForm(c);
    }

    async function _deleteCalendar(id, label) {
        if (!await App.confirm(`'${label}' 캘린더를 삭제하시겠습니까?`)) return;
        App.showLoading();
        const r = await App.post('/api/admin.php?action=calendar_delete', { id });
        App.hideLoading();
        if (r.success) { Toast.success(r.message); loadCalendarMgmt(); loadWeeklyGoal(); }
    }

    // ── Public API ──
    return {
        init,
        _editMember, _deleteMember,
        _editAdmin, _deleteAdmin,
        _editTask, _deleteTask,
        _editGuide, _deleteGuide,
        _editCalendar, _deleteCalendar,
    };
})();
