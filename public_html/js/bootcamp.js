/* ══════════════════════════════════════════════════════════════
   Bootcamp Management App
   체크리스트 / 현황판 / 패자부활전 / 코인 관리
   ══════════════════════════════════════════════════════════════ */
const BootcampApp = (() => {
    const API = '/api/bootcamp.php?action=';
    const ROLE_LABELS = { member: '회원', leader: '조장', subleader: '부조장' };
    const MISSION_SHORT = { '데일리미션': '데일리', '복클 참여': '복클참여', '복클 개설': '복클개설' };
    function missionShort(name) { return MISSION_SHORT[name] || name.substring(0, 4); }

    let admin = null;
    let root = null;
    let leaderMode = false;
    let leaderGroupName = '';

    // 공유 필터 상태
    let cohorts = [];
    let groups = [];
    let missionTypes = [];
    let selectedCohortId = 0;
    let selectedGroupId = 0;
    let selectedStageNo = 0;
    let selectedDate = App.today();
    let checklistInitialState = {};

    // ── Init ──
    async function init() {
        root = document.getElementById('bootcamp-root');
        App.showLoading();
        const r = await App.get('/api/admin.php?action=check_session');
        App.hideLoading();

        if (!r.logged_in || !r.admin.admin_roles || !r.admin.admin_roles.includes('operation')) {
            showLogin();
            return;
        }
        admin = r.admin;
        await loadMasterData();
        showMain();
    }

    function showLogin() {
        root.innerHTML = `
            <div class="admin-login">
                <div class="login-box">
                    <div class="login-title">소리튠 부트캠프</div>
                    <div class="login-subtitle">부트캠프 관리</div>
                    <form id="login-form">
                        <div class="form-group"><input type="text" class="form-input" id="login-id" placeholder="아이디" required></div>
                        <div class="form-group"><input type="password" class="form-input" id="login-pw" placeholder="비밀번호" required></div>
                        <button type="submit" class="btn btn-primary btn-block btn-lg mt-md">로그인</button>
                    </form>
                </div>
            </div>
        `;
        document.getElementById('login-form').onsubmit = async (e) => {
            e.preventDefault();
            App.showLoading();
            const r = await App.post('/api/admin.php?action=login', {
                login_id: document.getElementById('login-id').value.trim(),
                password: document.getElementById('login-pw').value,
            });
            App.hideLoading();
            if (r.success && r.admin.admin_roles.includes('operation')) {
                admin = r.admin;
                await loadMasterData();
                showMain();
            } else if (r.success) {
                Toast.error('운영팀 권한이 필요합니다.');
            }
        };
    }

    // ── Coach Mode Init (called from admin.js) ──
    async function initForCoach(coachAdmin) {
        admin = coachAdmin;
        leaderMode = false;

        await loadMasterData();

        // 활성 기수의 조 목록 로드
        if (selectedCohortId) {
            await loadGroups();
            if (groups.length) selectedGroupId = parseInt(groups[0].id);
        }

        // 탭 이벤트 바인딩
        const coachTabLoaders = {
            '#bc-tab-dashboard': () => loadDashboard(),
            '#bc-tab-checklist': loadChecklist,
            '#bc-tab-status': loadStatusBoard,
            '#bc-tab-qr': loadQR,
            '#bc-tab-attendance': () => { if (typeof AttendanceApp !== 'undefined') AttendanceApp.init(document.getElementById('bc-tab-attendance'), admin, 'coach'); },
            '#bc-tab-revival': loadRevival,
            '#bc-tab-coins': loadCoins,
            '#bc-tab-members': loadMembersMgmt,
            '#bc-tab-groups': loadGroupsMgmt,
        };
        const tabs = document.getElementById('sec-tabs');
        if (tabs) {
            tabs.querySelectorAll('.tab').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const tab = btn.dataset.tab;
                    // 패자부활 QR 세션 활성 중 다른 탭으로 이동 시 종료 확인
                    if (tab !== '#bc-tab-revival' && revivalQrSessionCode) {
                        if (!await App.confirm('패자부활 QR 세션이 활성 중입니다.\n탭을 이동하면 세션이 종료됩니다.\n이동하시겠습니까?')) return;
                        await App.post(QR_API + 'close_session', { session_code: revivalQrSessionCode });
                        if (revivalQrTimer) { clearInterval(revivalQrTimer); revivalQrTimer = null; }
                        revivalQrSessionCode = null;
                    }
                    const loader = coachTabLoaders[tab];
                    if (loader) loader();
                });
            });
        }

        // 창 닫기/새로고침 시 패자부활 QR 세션 종료
        window.addEventListener('beforeunload', () => {
            if (revivalQrSessionCode) {
                const blob = new Blob([JSON.stringify({ session_code: revivalQrSessionCode })], { type: 'application/json' });
                navigator.sendBeacon(QR_API + 'close_session', blob);
            }
        });

        // hash에 해당하는 탭 데이터 로드, 없으면 기본 체크리스트
        const activeBtn = tabs && tabs.querySelector('.tab.active');
        const activeTab = activeBtn && activeBtn.dataset.tab;
        const hashLoader = activeTab && coachTabLoaders[activeTab];
        if (hashLoader) {
            hashLoader();
        } else if (!activeTab || activeTab === '#bc-tab-checklist') {
            loadChecklist();
        }
    }

    // ── Leader Mode Init (called from admin.js) ──
    async function initForLeader(leaderAdmin) {
        admin = leaderAdmin;
        leaderMode = true;

        await loadMasterData();

        // 리더의 bootcamp_group_id로 조 고정
        const gid = admin.bootcamp_group_id;
        if (gid) {
            selectedGroupId = gid;
            // 조의 cohort_id 파악
            for (const c of cohorts) {
                const r = await App.get(API + 'groups', { cohort_id: c.id });
                const g = (r.groups || []).find(x => parseInt(x.id) === gid);
                if (g) {
                    selectedCohortId = parseInt(c.id);
                    leaderGroupName = g.name;
                    groups = r.groups;
                    break;
                }
            }
        }

        // 탭 이벤트 바인딩
        const leaderTabLoaders = {
            '#bc-tab-dashboard': () => loadDashboard(),
            '#bc-tab-checklist': loadChecklist,
            '#bc-tab-status': loadStatusBoard,
            '#bc-tab-entrance': loadEntrance,
        };
        const tabs = document.getElementById('sec-tabs');
        if (tabs) {
            tabs.querySelectorAll('.tab').forEach(btn => {
                btn.addEventListener('click', () => {
                    const loader = leaderTabLoaders[btn.dataset.tab];
                    if (loader) loader();
                });
            });
        }

        // hash에 해당하는 탭 데이터 로드, 없으면 기본 체크리스트
        const activeBtn = tabs && tabs.querySelector('.tab.active');
        const activeTab = activeBtn && activeBtn.dataset.tab;
        const hashLoader = activeTab && leaderTabLoaders[activeTab];
        if (hashLoader && activeTab !== '#bc-tab-checklist') {
            hashLoader();
        } else {
            loadChecklist();
        }
    }

    async function loadMasterData() {
        const [rCohorts, rMissions] = await Promise.all([
            App.get(API + 'cohorts'),
            App.get(API + 'mission_types'),
        ]);
        cohorts = rCohorts.cohorts || [];
        missionTypes = rMissions.mission_types || [];
        if (cohorts.length && !selectedCohortId) {
            // 활성 기수 우선, 없으면 첫 번째
            const active = cohorts.find(c => c.is_active);
            selectedCohortId = active ? parseInt(active.id) : parseInt(cohorts[0].id);
        }
    }

    async function loadGroups() {
        if (!selectedCohortId) { groups = []; return; }
        const r = await App.get(API + 'groups', { cohort_id: selectedCohortId });
        groups = r.groups || [];
    }

    // ── Main Layout ──
    function showMain() {
        root.innerHTML = `
            <div class="admin-dashboard">
                <div class="admin-header">
                    <div class="admin-header-left">
                        <span class="header-title">부트캠프 관리</span>
                        <a href="/operation" class="bc-back">← 운영 대시보드</a>
                    </div>
                    <div class="admin-header-right">
                        <span class="admin-name">${App.esc(admin.admin_name)}</span>
                    </div>
                </div>
                <div class="admin-content">
                    <div class="admin-tabs" id="bc-tabs">
                        <div class="tab-wrap">
                            <button class="tab active" data-tab="#bc-tab-checklist" data-hash="checklist">체크리스트</button>
                            <button class="tab" data-tab="#bc-tab-status" data-hash="status">현황판</button>
                            <button class="tab" data-tab="#bc-tab-revival" data-hash="revival">패자부활전</button>
                            <button class="tab" data-tab="#bc-tab-coins" data-hash="coins">코인 관리</button>
                            <button class="tab" data-tab="#bc-tab-members" data-hash="members">회원 관리</button>
                            <button class="tab" data-tab="#bc-tab-groups" data-hash="groups">조 관리</button>
                        </div>
                        <div class="tab-content active" id="bc-tab-checklist"></div>
                        <div class="tab-content" id="bc-tab-status"></div>
                        <div class="tab-content" id="bc-tab-revival"></div>
                        <div class="tab-content" id="bc-tab-coins"></div>
                        <div class="tab-content" id="bc-tab-members"></div>
                        <div class="tab-content" id="bc-tab-groups"></div>
                    </div>
                </div>
            </div>
        `;
        const tabCtrl = App.initTabs(document.getElementById('bc-tabs'));

        const bcTabLoaders = {
            '#bc-tab-checklist': loadChecklist,
            '#bc-tab-status': loadStatusBoard,
            '#bc-tab-revival': loadRevival,
            '#bc-tab-coins': loadCoins,
            '#bc-tab-members': loadMembersMgmt,
            '#bc-tab-groups': loadGroupsMgmt,
        };

        // 탭 전환 시 데이터 로드
        document.querySelectorAll('#bc-tabs .tab').forEach(btn => {
            btn.addEventListener('click', () => {
                const loader = bcTabLoaders[btn.dataset.tab];
                if (loader) loader();
            });
        });

        // hash가 있으면 해당 탭 활성화 + 데이터 로드, 없으면 기본 체크리스트
        if (tabCtrl.activateFromHash()) {
            const activeBtn = document.querySelector('#bc-tabs .tab.active');
            if (activeBtn) {
                const loader = bcTabLoaders[activeBtn.dataset.tab];
                if (loader) loader();
            }
        } else {
            loadChecklist();
        }
    }

    // ── Filter Bar HTML ──
    function filterBarHtml(opts = {}) {
        const showDate = opts.date !== false;
        const showGroup = opts.group !== false && !leaderMode;
        const showStage = opts.stage !== false;
        const showCohort = !leaderMode;
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
            </div>
        `;
    }

    function bindFilterEvents(onFilter) {
        const cohortEl = document.getElementById('fl-cohort');
        const dateEl = document.getElementById('fl-date');
        const groupEl = document.getElementById('fl-group');
        const stageEl = document.getElementById('fl-stage');

        if (cohortEl) cohortEl.onchange = async () => {
            selectedCohortId = parseInt(cohortEl.value);
            selectedGroupId = 0;
            await loadGroups();
            if (groupEl) {
                groupEl.innerHTML = '<option value="0">전체</option>' +
                    groups.map(g => `<option value="${g.id}">${App.esc(g.name)}</option>`).join('');
            }
            onFilter();
        };
        if (dateEl) dateEl.onchange = () => { selectedDate = dateEl.value; onFilter(); };
        if (groupEl) groupEl.onchange = () => { selectedGroupId = parseInt(groupEl.value); onFilter(); };
        if (stageEl) stageEl.onchange = () => { selectedStageNo = parseInt(stageEl.value); onFilter(); };
    }

    function scoreClass(score) {
        if (score <= -25) return 'out';
        if (score <= -15) return 'danger';
        if (score <= -13) return 'revival-warning';
        if (score < 0) return 'negative';
        if (score > 0) return 'positive';
        return '';
    }

    // ══════════════════════════════════════════════════════════
    // ── 체크리스트 ──
    // ══════════════════════════════════════════════════════════
    async function loadChecklist() {
        const sec = document.getElementById('bc-tab-checklist');
        await loadGroups();

        sec.innerHTML = `
            <div class="bc-toolbar mt-md">
                <span class="bc-toolbar-title">체크리스트</span>
                <button class="btn btn-primary btn-sm" id="bc-checklist-save">저장</button>
            </div>
            ${filterBarHtml()}
            <div id="bc-checklist-body"><div class="empty-state">로딩 중...</div></div>
        `;

        bindFilterEvents(renderChecklist);
        document.getElementById('bc-checklist-save').onclick = saveChecklist;
        renderChecklist();
    }

    async function renderChecklist() {
        const body = document.getElementById('bc-checklist-body');
        body.innerHTML = '<div class="empty-state">로딩 중...</div>';

        const params = { cohort_id: selectedCohortId, date: selectedDate };
        if (selectedGroupId) params.group_id = selectedGroupId;
        if (selectedStageNo) params.stage_no = selectedStageNo;

        const r = await App.get(API + 'checklist', params);
        if (!r.success) return;

        const { members, checks, mission_types: mt, scoring_start, scoring_end } = r;
        if (!members.length) {
            body.innerHTML = '<div class="empty-state">회원이 없습니다.</div>';
            return;
        }

        // 감점 기간 안내
        const isOutOfScoring = (scoring_start && selectedDate < scoring_start) || (scoring_end && selectedDate > scoring_end);
        let scoringNotice = '';
        if (isOutOfScoring) {
            const reason = selectedDate < scoring_start
                ? `적응기간(~${scoring_start} 전)에 해당합니다.`
                : `기수 종료일(${scoring_end}) 이후입니다.`;
            scoringNotice = `<div class="bc-scoring-notice">이 날짜는 점수에 반영되지 않습니다. ${reason}</div>`;
        }

        // 초기 체크 상태 저장 (변경 감지용)
        checklistInitialState = {};
        members.forEach(m => {
            const mc = checks[m.id] || {};
            mt.forEach(mi => {
                const key = `${m.id}_${mi.code}`;
                checklistInitialState[key] = !!(mc[mi.id] && mc[mi.id].status);
            });
        });

        body.innerHTML = `
            ${scoringNotice}
            <div style="overflow-x:auto">
                <table class="bc-checklist-table">
                    <thead>
                        <tr>
                            <th>회원</th>
                            <th>점수</th>
                            <th>코인</th>
                            ${mt.map(m => `<th title="${App.esc(m.name)}">${App.esc(missionShort(m.name))}</th>`).join('')}
                        </tr>
                    </thead>
                    <tbody>
                        ${members.map(m => {
                            const mc = checks[m.id] || {};
                            const sc = scoreClass(parseInt(m.current_score));
                            return `
                            <tr>
                                <td>
                                    <div class="member-name">${App.esc(m.nickname)}${m.real_name ? ` <span style="color:#888;font-size:12px">(${App.esc(m.real_name)})</span>` : ''}${parseInt(m.participation_count) > 1 ? ` <span class="badge badge-info" style="font-size:10px">${m.participation_count}회차</span>` : ''}</div>
                                    <div class="member-sub">${App.esc(m.group_name || '-')} · ${m.stage_no}단계</div>
                                </td>
                                <td class="score-cell ${sc}">${m.current_score}</td>
                                <td>${m.current_coin || 0}</td>
                                ${mt.map(mi => {
                                    const cv = mc[mi.id];
                                    const checked = cv && cv.status ? 'checked' : '';
                                    return `<td><input type="checkbox" class="bc-check" data-member="${m.id}" data-mission="${mi.code}" ${checked}></td>`;
                                }).join('')}
                            </tr>`;
                        }).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    async function saveChecklist() {
        const checkboxes = document.querySelectorAll('.bc-check');
        const items = [];
        let hasChanges = false;
        checkboxes.forEach(cb => {
            const key = `${cb.dataset.member}_${cb.dataset.mission}`;
            const initial = checklistInitialState[key] || false;
            if (cb.checked !== initial) hasChanges = true;
            items.push({
                member_id: parseInt(cb.dataset.member),
                mission_type_code: cb.dataset.mission,
                status: cb.checked,
            });
        });

        if (!hasChanges) {
            Toast.info('변경된 항목이 없습니다.');
            return;
        }

        App.showLoading();
        const r = await App.post(API + 'check_bulk_save', {
            check_date: selectedDate,
            items,
        });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            renderChecklist();
        }
    }

    // ══════════════════════════════════════════════════════════
    // ── 현황판 ──
    // ══════════════════════════════════════════════════════════
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

        bindFilterEvents(renderStatusBoard);
        renderStatusBoard();
    }

    async function renderStatusBoard() {
        const body = document.getElementById('bc-status-body');
        body.innerHTML = '<div class="empty-state">로딩 중...</div>';

        const params = { cohort_id: selectedCohortId, date: selectedDate };
        if (selectedGroupId) params.group_id = selectedGroupId;
        if (selectedStageNo) params.stage_no = selectedStageNo;

        const r = await App.get(API + 'status_board', params);
        if (!r.success) return;

        const { members, checks, mission_types: mt, miss_days: missDays, warning_notes: warnNotes, thresholds } = r;
        if (!members.length) {
            body.innerHTML = '<div class="empty-state">회원이 없습니다.</div>';
            return;
        }

        body.innerHTML = members.map(m => {
            const mc = checks[m.id] || {};
            const score = parseInt(m.current_score);
            const missCount = missDays[m.id] || 0;
            const hasNote = !!warnNotes[m.id];
            const isOut = m.member_status === 'out_of_group_management';
            const isRevivalCandidate = score <= (thresholds?.revival_candidate ?? -13);
            const isRevivalEligible = score <= (thresholds?.revival_eligible ?? -15);
            const sc = scoreClass(score);

            // 경고 레벨: black > red > yellow
            let warningClass = '';
            let warningBadge = '';
            if (isOut) {
                warningClass = 'warning-out';
                warningBadge = '<span class="badge badge-out">OUT</span>';
            } else if (isRevivalCandidate) {
                warningClass = 'warning-black';
                warningBadge = '<span class="badge badge-black">부활대상</span>';
            }
            if (missCount >= 5) {
                warningClass = warningClass || 'warning-red';
                warningBadge += `<span class="badge badge-red">${missCount}일 미수행</span>`;
            } else if (missCount >= 3 && !hasNote) {
                warningClass = warningClass || 'warning-yellow';
                warningBadge += `<span class="badge badge-yellow">${missCount}일 미수행</span>`;
            }

            return `
                <div class="bc-status-card ${warningClass}" data-member-id="${m.id}">
                    <div class="bc-status-info">
                        <div class="bc-status-name">
                            ${App.esc(m.nickname)}${m.real_name ? ` <span style="color:#888;font-size:12px">(${App.esc(m.real_name)})</span>` : ''}
                            ${parseInt(m.participation_count) > 1 ? `<span class="badge badge-info" style="font-size:10px">${m.participation_count}회차</span>` : ''}
                            ${warningBadge}
                        </div>
                        <div class="bc-status-meta">
                            <span>${App.esc(m.group_name || '-')}</span>
                            <span>${m.stage_no}단계</span>
                            <span>${App.esc(ROLE_LABELS[m.member_role] || m.member_role)}</span>
                            <span>코인: ${m.current_coin || 0}</span>
                        </div>
                        <div class="bc-status-checks mt-sm">
                            ${mt.map(mi => {
                                const v = mc[mi.id];
                                const cls = v === undefined ? 'none' : (v ? 'pass' : 'fail');
                                const label = mi.name.substring(0, 2);
                                return `<span class="bc-check-dot ${cls}" title="${App.esc(mi.name)}">${label}</span>`;
                            }).join('')}
                        </div>
                        ${(missCount >= 3 && !hasNote && (leaderMode || !leaderMode)) ? `
                        <button class="btn btn-sm btn-warning mt-sm bc-warn-note" data-member-id="${m.id}" data-nickname="${App.esc(m.nickname)}" onclick="event.stopPropagation()">비고 입력</button>` : ''}
                    </div>
                    <div class="bc-status-score ${sc}">${score}</div>
                </div>
            `;
        }).join('');

        // 비고 입력 버튼
        body.querySelectorAll('.bc-warn-note').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                showWarningNoteForm(parseInt(btn.dataset.memberId), btn.dataset.nickname);
            };
        });

        // 카드 클릭 시 상세(점수로그/코인로그) 모달
        body.querySelectorAll('.bc-status-card').forEach(card => {
            card.style.cursor = 'pointer';
            card.onclick = () => showMemberDetail(parseInt(card.dataset.memberId));
        });
    }

    function showWarningNoteForm(memberId, nickname) {
        const body = `
            <div class="form-group">
                <label class="form-label">${App.esc(nickname)}에게 개별 카톡 후 비고를 입력하세요</label>
                <textarea class="form-input" id="warn-note" rows="3" placeholder="카톡 내용 요약 등"></textarea>
            </div>
        `;
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">취소</button>
            <button class="btn btn-primary" id="warn-save">저장</button>
        `;
        App.openModal('비고 입력', body, footer);

        document.getElementById('warn-save').onclick = async () => {
            const note = document.getElementById('warn-note').value.trim();
            if (!note) return Toast.warning('비고를 입력해주세요.');

            App.showLoading();
            const r = await App.post(API + 'warning_note_create', { member_id: memberId, note });
            App.hideLoading();
            if (r.success) {
                App.closeModal();
                Toast.success(r.message);
                renderStatusBoard();
            }
        };
    }

    async function showMemberDetail(memberId) {
        App.showLoading();
        const [rScore, rCoin] = await Promise.all([
            App.get(API + 'score_logs', { member_id: memberId }),
            App.get(API + 'coin_logs', { member_id: memberId }),
        ]);
        App.hideLoading();

        const scoreLogs = rScore.logs || [];
        const coinLogs = rCoin.logs || [];

        const body = `
            <div class="admin-tabs" id="detail-tabs" style="margin:0">
                <div class="tab-wrap">
                    <button class="tab active" data-tab="#detail-score">점수 이력</button>
                    <button class="tab" data-tab="#detail-coin">코인 이력</button>
                </div>
                <div class="tab-content active" id="detail-score">
                    ${scoreLogs.length ? `
                    <div style="overflow-x:auto;max-height:300px;overflow-y:auto">
                        <table class="bc-log-table">
                            <thead><tr><th>일시</th><th>변동</th><th>전</th><th>후</th><th>사유</th></tr></thead>
                            <tbody>
                                ${scoreLogs.map(l => `
                                    <tr>
                                        <td style="white-space:nowrap">${(l.created_at || '').substring(0, 16)}</td>
                                        <td class="${parseInt(l.score_change) >= 0 ? 'log-positive' : 'log-negative'}">${parseInt(l.score_change) > 0 ? '+' : ''}${l.score_change}</td>
                                        <td>${l.before_score}</td>
                                        <td>${l.after_score}</td>
                                        <td>${App.esc(l.reason_detail || l.reason_type)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>` : '<div class="empty-state">이력이 없습니다.</div>'}
                </div>
                <div class="tab-content" id="detail-coin">
                    ${coinLogs.length ? `
                    <div style="overflow-x:auto;max-height:300px;overflow-y:auto">
                        <table class="bc-log-table">
                            <thead><tr><th>일시</th><th>변동</th><th>전</th><th>후</th><th>사유</th></tr></thead>
                            <tbody>
                                ${coinLogs.map(l => `
                                    <tr>
                                        <td style="white-space:nowrap">${(l.created_at || '').substring(0, 16)}</td>
                                        <td class="${parseInt(l.coin_change) >= 0 ? 'log-positive' : 'log-negative'}">${parseInt(l.coin_change) > 0 ? '+' : ''}${l.coin_change}</td>
                                        <td>${l.before_coin}</td>
                                        <td>${l.after_coin}</td>
                                        <td>${App.esc(l.reason_detail || l.reason_type)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>` : '<div class="empty-state">이력이 없습니다.</div>'}
                </div>
            </div>
        `;
        App.openModal('회원 상세', body);
        App.initTabs(document.getElementById('detail-tabs'));
    }

    // ══════════════════════════════════════════════════════════
    // ── 패자부활전 ──
    // ══════════════════════════════════════════════════════════
    let revivalQrTimer = null;
    let revivalQrSessionCode = null;

    async function loadRevival() {
        const sec = document.getElementById('bc-tab-revival');
        selectedGroupId = 0;
        await loadGroups();

        // 이전 타이머 정리
        if (revivalQrTimer) { clearInterval(revivalQrTimer); revivalQrTimer = null; }

        sec.innerHTML = `
            <div class="bc-toolbar mt-md">
                <span class="bc-toolbar-title">패자부활전</span>
            </div>
            <div class="admin-tabs" id="revival-tabs" style="margin:0">
                <div class="tab-wrap">
                    <button class="tab active" data-tab="#revival-candidates">대상자</button>
                    <button class="tab" data-tab="#revival-qr-manage">QR 관리</button>
                    <button class="tab" data-tab="#revival-history">처리 이력</button>
                </div>
                <div class="tab-content active" id="revival-candidates"></div>
                <div class="tab-content" id="revival-qr-manage"></div>
                <div class="tab-content" id="revival-history"></div>
            </div>
        `;

        App.initTabs(document.getElementById('revival-tabs'));
        document.querySelectorAll('#revival-tabs .tab').forEach(btn => {
            btn.addEventListener('click', () => {
                const tab = btn.dataset.tab;
                if (tab === '#revival-candidates') renderRevivalCandidates();
                else if (tab === '#revival-qr-manage') loadRevivalQR();
                else if (tab === '#revival-history') renderRevivalHistory();
            });
        });

        renderRevivalCandidates();
    }

    async function renderRevivalCandidates() {
        const sec = document.getElementById('revival-candidates');
        sec.innerHTML = `
            ${filterBarHtml({ date: false })}
            <div id="revival-list"><div class="empty-state">로딩 중...</div></div>
        `;
        bindFilterEvents(fetchRevivalCandidates);
        fetchRevivalCandidates();
    }

    async function fetchRevivalCandidates() {
        const list = document.getElementById('revival-list');
        list.innerHTML = '<div class="empty-state">로딩 중...</div>';

        const params = { cohort_id: selectedCohortId };
        if (selectedGroupId) params.group_id = selectedGroupId;
        if (selectedStageNo) params.stage_no = selectedStageNo;

        const r = await App.get(API + 'revival_candidates', params);
        if (!r.success) return;

        const candidates = r.candidates || [];
        if (!candidates.length) {
            list.innerHTML = `<div class="empty-state">패자부활 대상자가 없습니다. (기준: -15점 이하)</div>`;
            return;
        }

        list.innerHTML = `
            <div class="revival-summary" style="margin-bottom:12px;padding:8px 12px;background:var(--bg-light,#f5f5f5);border-radius:8px;font-size:var(--sm-font-size)">
                대상자 <strong>${candidates.length}</strong>명 (기준: -15점 이하)
            </div>
            ${candidates.map(c => `
                <div class="bc-revival-row">
                    <div class="revival-info">
                        <div class="revival-name">${App.esc(c.nickname)}</div>
                        <div class="revival-detail">${App.esc(c.group_name || '-')} · ${c.stage_no}단계 · ${App.esc(ROLE_LABELS[c.member_role] || '')}</div>
                    </div>
                    <div class="revival-score">${c.current_score}</div>
                </div>
            `).join('')}
        `;
    }

    // ── 패자부활 QR 관리 ──
    async function loadRevivalQR() {
        const container = document.getElementById('revival-qr-manage');
        if (!container) return;

        if (revivalQrTimer) { clearInterval(revivalQrTimer); revivalQrTimer = null; }
        container.innerHTML = '<div class="empty-state mt-lg">로딩 중...</div>';

        const r = await App.get(QR_API + 'session_status', { session_type: 'revival' });
        if (!r.success) return;

        if (r.has_session) {
            revivalQrSessionCode = r.session_code;
            renderRevivalQRActive(container, r);
        } else {
            revivalQrSessionCode = null;
            renderRevivalQRIdle(container);
        }
    }

    function renderRevivalQRIdle(container) {
        container.innerHTML = `
            <div class="qr-idle">
                <div class="qr-idle-icon" style="font-size:48px">&#x1F3C6;</div>
                <div class="qr-idle-title">패자부활전 QR</div>
                <div class="qr-idle-desc">QR 세션을 시작하면 대상자들이<br>QR 코드를 스캔하여 부활 처리할 수 있습니다</div>
                <button class="btn btn-primary btn-lg" id="btn-revival-qr-start">패자부활전 체크 QR 생성</button>
            </div>
        `;
        document.getElementById('btn-revival-qr-start').onclick = createRevivalQRSession;
    }

    async function createRevivalQRSession() {
        App.showLoading();
        const r = await App.post(QR_API + 'create_session', { session_type: 'revival' });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            loadRevivalQR();
        }
    }

    function renderRevivalQRActive(container, data) {
        const expiresAt = new Date(data.expires_at.replace(' ', 'T'));
        const attendeeCount = data.attendees ? data.attendees.length : 0;

        container.innerHTML = `
            <div class="qr-active">
                <div class="qr-active-header">
                    <div class="qr-timer" id="revival-qr-timer"></div>
                    <button class="btn btn-secondary btn-sm" id="btn-revival-qr-close">세션 종료</button>
                </div>
                <div class="qr-code-wrap">
                    <div id="revival-qr-code-canvas"></div>
                </div>
                <div class="qr-url-wrap">
                    <input type="text" class="form-input" id="revival-qr-url" value="${App.esc(data.scan_url)}" readonly>
                    <button class="btn btn-secondary btn-sm" id="btn-revival-qr-copy">복사</button>
                </div>
                <div class="qr-attendees-header">
                    <span class="qr-attendees-title">부활 처리 현황</span>
                    <span class="revival-qr-attendees-count"><strong>${attendeeCount}</strong>명 스캔</span>
                </div>
                <div id="revival-qr-attendee-list"></div>
            </div>
        `;

        // QR 코드 생성
        renderRevivalQRCode(data.scan_url);

        // 스캔 목록 렌더
        renderRevivalAttendeeList(data.attendees);

        // 타이머 + 자동 새로고침
        updateRevivalQRTimer(expiresAt);
        revivalQrTimer = setInterval(() => {
            updateRevivalQRTimer(expiresAt);
            refreshRevivalAttendees();
        }, 5000);

        // 이벤트
        document.getElementById('btn-revival-qr-close').onclick = closeRevivalQRSession;
        document.getElementById('btn-revival-qr-copy').onclick = () => {
            const input = document.getElementById('revival-qr-url');
            input.select();
            navigator.clipboard.writeText(input.value).then(() => Toast.success('링크가 복사되었습니다'));
        };
    }

    function renderRevivalQRCode(url) {
        const canvas = document.getElementById('revival-qr-code-canvas');
        if (!canvas) return;

        if (typeof QRCode !== 'undefined') {
            new QRCode(canvas, {
                text: url, width: 240, height: 240,
                colorDark: '#2563EB', colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M,
            });
        } else {
            canvas.innerHTML = '<div style="padding:20px;text-align:center;color:#999;">QR 라이브러리 로딩 중...</div>';
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';
            script.onload = () => {
                canvas.innerHTML = '';
                new QRCode(canvas, {
                    text: url, width: 240, height: 240,
                    colorDark: '#2563EB', colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M,
                });
            };
            document.head.appendChild(script);
        }
    }

    function renderRevivalAttendeeList(attendees) {
        const list = document.getElementById('revival-qr-attendee-list');
        if (!list) return;

        if (!attendees || attendees.length === 0) {
            list.innerHTML = '<div class="empty-state mt-sm">아직 스캔한 대상자가 없습니다</div>';
            return;
        }

        list.innerHTML = attendees.map((a, i) => {
            const time = a.scanned_at ? a.scanned_at.substring(11, 16) : '';
            return `
                <div class="qr-attendee-item">
                    <span class="qr-attendee-num">${attendees.length - i}</span>
                    <span class="qr-attendee-name">${App.esc(a.nickname)}</span>
                    <span class="qr-attendee-group">${App.esc(a.group_name)}</span>
                    <span class="qr-attendee-time">${time}</span>
                </div>`;
        }).join('');
    }

    function updateRevivalQRTimer(expiresAt) {
        const timerEl = document.getElementById('revival-qr-timer');
        if (!timerEl) return;

        const now = new Date();
        const diff = expiresAt - now;

        if (diff <= 0) {
            timerEl.textContent = '만료됨';
            timerEl.classList.add('expired');
            if (revivalQrTimer) { clearInterval(revivalQrTimer); revivalQrTimer = null; }
            setTimeout(() => loadRevivalQR(), 1000);
            return;
        }

        const mins = Math.floor(diff / 60000);
        const secs = Math.floor((diff % 60000) / 1000);
        timerEl.textContent = `${mins}분 ${String(secs).padStart(2, '0')}초 남음`;
    }

    async function refreshRevivalAttendees() {
        const r = await App.api(QR_API + 'session_status&session_type=revival', { showError: false });
        if (!r || !r.success) return;

        if (!r.has_session) {
            if (revivalQrTimer) { clearInterval(revivalQrTimer); revivalQrTimer = null; }
            loadRevivalQR();
            return;
        }

        const countEl = document.querySelector('.revival-qr-attendees-count');
        if (countEl) {
            const cnt = r.attendees ? r.attendees.length : 0;
            countEl.innerHTML = `<strong>${cnt}</strong>명 스캔`;
        }

        renderRevivalAttendeeList(r.attendees);
    }

    async function closeRevivalQRSession() {
        if (!revivalQrSessionCode) return;
        if (!await App.confirm('닫으면 현재 패자부활 QR 세션이 종료됩니다.\n종료하시겠습니까?')) return;

        App.showLoading();
        const r = await App.post(QR_API + 'close_session', { session_code: revivalQrSessionCode });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            if (revivalQrTimer) { clearInterval(revivalQrTimer); revivalQrTimer = null; }
            revivalQrSessionCode = null;
            loadRevivalQR();
        }
    }

    async function renderRevivalHistory() {
        const sec = document.getElementById('revival-history');
        sec.innerHTML = '<div class="empty-state">로딩 중...</div>';

        const r = await App.get(API + 'revival_logs', { cohort_id: selectedCohortId });
        if (!r.success) return;

        const logs = r.logs || [];
        if (!logs.length) {
            sec.innerHTML = '<div class="empty-state mt-md">이력이 없습니다.</div>';
            return;
        }

        sec.innerHTML = `
            <div style="overflow-x:auto" class="mt-md">
                <table class="bc-log-table">
                    <thead><tr><th>일시</th><th>회원</th><th>조</th><th>전</th><th>후</th><th>처리자</th><th>메모</th></tr></thead>
                    <tbody>
                        ${logs.map(l => `
                            <tr>
                                <td style="white-space:nowrap">${(l.created_at || '').substring(0, 16)}</td>
                                <td>${App.esc(l.nickname)}</td>
                                <td>${App.esc(l.group_name || '-')}</td>
                                <td class="log-negative">${l.before_score}</td>
                                <td>${l.after_score}</td>
                                <td>${App.esc(l.operator_name || '-')}</td>
                                <td>${App.esc(l.note || '-')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    // ══════════════════════════════════════════════════════════
    // ── 코인 관리 ──
    // ══════════════════════════════════════════════════════════
    async function loadCoins() {
        const sec = document.getElementById('bc-tab-coins');
        await loadGroups();

        sec.innerHTML = `
            <div class="bc-toolbar mt-md">
                <span class="bc-toolbar-title">코인 관리</span>
            </div>
            ${filterBarHtml({ date: false })}
            <div id="bc-coin-body"><div class="empty-state">로딩 중...</div></div>
        `;

        bindFilterEvents(renderCoinList);
        renderCoinList();
    }

    async function renderCoinList() {
        const body = document.getElementById('bc-coin-body');
        body.innerHTML = '<div class="empty-state">로딩 중...</div>';

        const params = { cohort_id: selectedCohortId };
        if (selectedGroupId) params.group_id = selectedGroupId;
        if (selectedStageNo) params.stage_no = selectedStageNo;

        const r = await App.get(API + 'members', params);
        if (!r.success) return;

        const members = r.members || [];
        if (!members.length) {
            body.innerHTML = '<div class="empty-state">회원이 없습니다.</div>';
            return;
        }

        body.innerHTML = `
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead><tr><th>회원</th><th>조</th><th>단계</th><th>코인</th><th></th></tr></thead>
                    <tbody>
                        ${members.map(m => `
                            <tr>
                                <td>${App.esc(m.nickname)}</td>
                                <td>${App.esc(m.group_name || '-')}</td>
                                <td>${m.stage_no}단계</td>
                                <td style="font-weight:700;color:var(--main-color)">${m.current_coin || 0}</td>
                                <td class="actions">
                                    <button class="btn-icon" onclick="BootcampApp._coinAction(${m.id}, '${App.esc(m.nickname)}', ${m.current_coin || 0})">적립/차감</button>
                                    <button class="btn-icon" onclick="BootcampApp._coinLogs(${m.id}, '${App.esc(m.nickname)}')">이력</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    function _coinAction(memberId, nickname, currentCoin) {
        const body = `
            <div class="bc-coin-card">
                <div class="coin-label">${App.esc(nickname)} 현재 코인</div>
                <div class="coin-value">${currentCoin}</div>
            </div>
            <div class="form-group">
                <label class="form-label">변동량 (양수=적립, 음수=차감)</label>
                <input type="number" class="form-input" id="coin-amount" placeholder="예: 10 또는 -5">
            </div>
            <div class="form-group">
                <label class="form-label">사유 유형</label>
                <select class="form-select" id="coin-reason-type">
                    <option value="manual_adjustment">수동 조정</option>
                    <option value="leader_coin">조장 코인</option>
                    <option value="study_open">스터디 개설</option>
                    <option value="study_join">스터디 참여</option>
                    <option value="completion_bonus">수료 보너스</option>
                    <option value="event_reward">이벤트 보상</option>
                    <option value="redemption">사용(차감)</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">사유 상세 (선택)</label>
                <input type="text" class="form-input" id="coin-reason-detail">
            </div>
        `;
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">취소</button>
            <button class="btn btn-primary" id="coin-submit">처리</button>
        `;
        App.openModal('코인 적립/차감', body, footer);

        document.getElementById('coin-submit').onclick = async () => {
            const amount = parseInt(document.getElementById('coin-amount').value);
            if (!amount || isNaN(amount)) return Toast.warning('변동량을 입력해주세요.');

            App.showLoading();
            const r = await App.post(API + 'coin_change', {
                member_id: memberId,
                coin_change: amount,
                reason_type: document.getElementById('coin-reason-type').value,
                reason_detail: document.getElementById('coin-reason-detail').value.trim(),
            });
            App.hideLoading();
            if (r.success) {
                App.closeModal();
                Toast.success(r.message);
                renderCoinList();
            }
        };
    }

    async function _coinLogs(memberId, nickname) {
        App.showLoading();
        const r = await App.get(API + 'coin_logs', { member_id: memberId });
        App.hideLoading();

        const logs = r.logs || [];
        const body = logs.length ? `
            <div style="overflow-x:auto;max-height:400px;overflow-y:auto">
                <table class="bc-log-table">
                    <thead><tr><th>일시</th><th>변동</th><th>전</th><th>후</th><th>사유</th><th>처리자</th></tr></thead>
                    <tbody>
                        ${logs.map(l => `
                            <tr>
                                <td style="white-space:nowrap">${(l.created_at || '').substring(0, 16)}</td>
                                <td class="${parseInt(l.coin_change) >= 0 ? 'log-positive' : 'log-negative'}">${parseInt(l.coin_change) > 0 ? '+' : ''}${l.coin_change}</td>
                                <td>${l.before_coin}</td>
                                <td>${l.after_coin}</td>
                                <td>${App.esc(l.reason_detail || l.reason_type)}</td>
                                <td>${App.esc(l.operator_name || '-')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        ` : '<div class="empty-state">이력이 없습니다.</div>';

        App.openModal(`${nickname} 코인 이력`, body);
    }

    // ══════════════════════════════════════════════════════════
    // ── 회원 관리 ──
    // ══════════════════════════════════════════════════════════
    async function loadMembersMgmt() {
        const sec = document.getElementById('bc-tab-members');
        await loadGroups();

        sec.innerHTML = `
            <div class="bc-toolbar mt-md">
                <span class="bc-toolbar-title">부트캠프 회원</span>
                <button class="btn btn-primary btn-sm" id="bc-add-member">추가</button>
            </div>
            ${filterBarHtml({ date: false })}
            <div id="bc-members-body"><div class="empty-state">로딩 중...</div></div>
        `;

        bindFilterEvents(renderMembersList);
        document.getElementById('bc-add-member').onclick = () => showMemberForm();
        renderMembersList();
    }

    async function renderMembersList() {
        const body = document.getElementById('bc-members-body');
        body.innerHTML = '<div class="empty-state">로딩 중...</div>';

        const params = { cohort_id: selectedCohortId };
        if (selectedGroupId) params.group_id = selectedGroupId;
        if (selectedStageNo) params.stage_no = selectedStageNo;

        const r = await App.get(API + 'members', params);
        if (!r.success) return;

        const members = r.members || [];
        if (!members.length) {
            body.innerHTML = '<div class="empty-state">회원이 없습니다.</div>';
            return;
        }

        body.innerHTML = MemberTable.searchBarHtml(members.length, members) + MemberTable.render(members, {
            mode: 'bootcamp',
            editFn: 'BootcampApp._editMember',
            deleteFn: 'BootcampApp._deleteMember',
        });
        MemberTable.bindToggle(body);
        MemberTable.bindSearch(body);
        MemberTable.bindEntranceFilter(body);
    }

    function showMemberForm(data = {}) {
        const isEdit = !!data.id;
        const body = `
            <div class="form-group">
                <label class="form-label">닉네임 *</label>
                <input type="text" class="form-input" id="mf-nickname" value="${App.esc(data.nickname || '')}">
            </div>
            <div class="form-group">
                <label class="form-label">이름</label>
                <input type="text" class="form-input" id="mf-realname" value="${App.esc(data.real_name || '')}">
            </div>
            ${isEdit ? `
            <div class="form-group">
                <label class="form-label">조</label>
                <select class="form-select" id="mf-group">
                    <option value="">미배정</option>
                    ${groups.map(g => `<option value="${g.id}" ${parseInt(data.group_id) === parseInt(g.id) ? 'selected' : ''}>${App.esc(g.name)}</option>`).join('')}
                </select>
            </div>
            ` : `
            <div class="form-group">
                <label class="form-label">조</label>
                <div class="form-help">조 배정은 [조 배정 관리] 탭에서 진행합니다.</div>
            </div>
            `}
            <div class="form-group">
                <label class="form-label">단계</label>
                <select class="form-select" id="mf-stage">
                    <option value="1" ${parseInt(data.stage_no) === 1 || !data.stage_no ? 'selected' : ''}>1단계</option>
                    <option value="2" ${parseInt(data.stage_no) === 2 ? 'selected' : ''}>2단계</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">역할</label>
                <select class="form-select" id="mf-role">
                    <option value="member" ${data.member_role === 'member' || !data.member_role ? 'selected' : ''}>회원</option>
                    <option value="leader" ${data.member_role === 'leader' ? 'selected' : ''}>조장</option>
                    <option value="subleader" ${data.member_role === 'subleader' ? 'selected' : ''}>부조장</option>
                </select>
            </div>
        `;
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">취소</button>
            <button class="btn btn-primary" id="mf-save">${isEdit ? '수정' : '추가'}</button>
        `;
        App.openModal(isEdit ? '회원 수정' : '회원 추가', body, footer);

        document.getElementById('mf-save').onclick = async () => {
            const nickname = document.getElementById('mf-nickname').value.trim();
            if (!nickname) return Toast.warning('닉네임을 입력해주세요.');

            const payload = {
                nickname,
                real_name: document.getElementById('mf-realname').value.trim(),
                stage_no: parseInt(document.getElementById('mf-stage').value),
                member_role: document.getElementById('mf-role').value,
            };

            // 수정 모드에서만 group_id 포함
            const groupEl = document.getElementById('mf-group');
            if (groupEl) {
                payload.group_id = parseInt(groupEl.value) || null;
            }

            if (isEdit) {
                payload.id = data.id;
            } else {
                payload.cohort_id = selectedCohortId;
            }

            App.showLoading();
            const r = await App.post(API + (isEdit ? 'member_update' : 'member_create'), payload);
            App.hideLoading();
            if (r.success) {
                App.closeModal();
                Toast.success(r.message);
                renderMembersList();
            }
        };
    }

    async function _editMember(id) {
        const r = await App.get(API + 'members', { cohort_id: selectedCohortId });
        if (!r.success) return;
        const m = (r.members || []).find(x => parseInt(x.id) === id);
        if (m) showMemberForm(m);
        else Toast.error('회원을 찾을 수 없습니다.');
    }

    async function _deleteMember(id, nickname) {
        if (!await App.confirm(`'${nickname}' 회원을 삭제하시겠습니까?\n관련 체크/점수/코인 데이터도 모두 삭제됩니다.`)) return;
        App.showLoading();
        const r = await App.post(API + 'member_delete', { id });
        App.hideLoading();
        if (r.success) { Toast.success(r.message); renderMembersList(); }
    }

    // ══════════════════════════════════════════════════════════
    // ── 조 관리 ──
    // ══════════════════════════════════════════════════════════
    async function loadGroupsMgmt() {
        const sec = document.getElementById('bc-tab-groups');
        await loadGroups();

        sec.innerHTML = `
            <div class="bc-toolbar mt-md">
                <span class="bc-toolbar-title">조 관리</span>
                <button class="btn btn-primary btn-sm" id="bc-add-group">추가</button>
            </div>
            <div class="bc-filters">
                <div class="filter-item">
                    <span class="filter-label">기수</span>
                    <select id="fl-cohort-grp">
                        ${cohorts.map(c => `<option value="${c.id}" ${parseInt(c.id) === selectedCohortId ? 'selected' : ''}>${App.esc(c.cohort)}</option>`).join('')}
                    </select>
                </div>
            </div>
            <div id="bc-groups-body"></div>
        `;

        document.getElementById('fl-cohort-grp').onchange = async (e) => {
            selectedCohortId = parseInt(e.target.value);
            await loadGroups();
            renderGroupsList();
        };
        document.getElementById('bc-add-group').onclick = () => showGroupForm();
        renderGroupsList();
    }

    function renderGroupsList() {
        const body = document.getElementById('bc-groups-body');
        if (!groups.length) {
            body.innerHTML = '<div class="empty-state">등록된 조가 없습니다.</div>';
            return;
        }
        body.innerHTML = `
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead><tr><th>조 이름</th><th>코드</th><th></th></tr></thead>
                    <tbody>
                        ${groups.map(g => `
                            <tr>
                                <td>${App.esc(g.name)}</td>
                                <td><code>${App.esc(g.code)}</code></td>
                                <td class="actions">
                                    <button class="btn-icon" onclick="BootcampApp._editGroup(${g.id}, '${App.esc(g.name)}', '${App.esc(g.code)}')">수정</button>
                                    <button class="btn-icon danger" onclick="BootcampApp._deleteGroup(${g.id}, '${App.esc(g.name)}')">삭제</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    function showGroupForm(data = {}) {
        const isEdit = !!data.id;
        const body = `
            <div class="form-group">
                <label class="form-label">조 이름 *</label>
                <input type="text" class="form-input" id="gf-name" value="${App.esc(data.name || '')}" placeholder="예: 루크조">
            </div>
            <div class="form-group">
                <label class="form-label">코드 *</label>
                <input type="text" class="form-input" id="gf-code" value="${App.esc(data.code || '')}" placeholder="예: luke">
            </div>
        `;
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">취소</button>
            <button class="btn btn-primary" id="gf-save">${isEdit ? '수정' : '추가'}</button>
        `;
        App.openModal(isEdit ? '조 수정' : '조 추가', body, footer);

        document.getElementById('gf-save').onclick = async () => {
            const payload = {
                name: document.getElementById('gf-name').value.trim(),
                code: document.getElementById('gf-code').value.trim(),
            };
            if (!payload.name || !payload.code) return Toast.warning('이름과 코드를 입력해주세요.');

            if (isEdit) payload.id = data.id;
            else payload.cohort_id = selectedCohortId;

            App.showLoading();
            const r = await App.post(API + (isEdit ? 'group_update' : 'group_create'), payload);
            App.hideLoading();
            if (r.success) {
                App.closeModal();
                Toast.success(r.message);
                await loadGroups();
                renderGroupsList();
            }
        };
    }

    function _editGroup(id, name, code) {
        showGroupForm({ id, name, code });
    }

    async function _deleteGroup(id, name) {
        if (!await App.confirm(`'${name}' 조를 삭제하시겠습니까?`)) return;
        App.showLoading();
        const r = await App.post(API + 'group_delete', { id });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            await loadGroups();
            renderGroupsList();
        }
    }

    // ══════════════════════════════════════════════════════════════
    // QR 출석
    // ══════════════════════════════════════════════════════════════

    const QR_API = '/api/qr.php?action=';
    let qrRefreshTimer = null;
    let qrSessionCode = null;

    async function loadQR() {
        const container = document.getElementById('bc-tab-qr');
        if (!container) return;

        // 이전 타이머 정리
        if (qrRefreshTimer) { clearInterval(qrRefreshTimer); qrRefreshTimer = null; }

        container.innerHTML = '<div class="empty-state mt-lg">로딩 중...</div>';

        const r = await App.get(QR_API + 'session_status');
        if (!r.success) return;

        if (r.has_session) {
            qrSessionCode = r.session_code;
            renderQRActive(container, r);
        } else {
            qrSessionCode = null;
            renderQRIdle(container);
        }
    }

    function renderQRIdle(container) {
        container.innerHTML = `
            <div class="qr-idle">
                <div class="qr-idle-icon">&#x1F4F1;</div>
                <div class="qr-idle-title">QR 출석체크</div>
                <div class="qr-idle-desc">QR 세션을 시작하면 학생들이<br>QR 코드를 스캔하여 출석할 수 있습니다</div>
                <button class="btn btn-primary btn-lg" id="btn-qr-start">QR 출석 시작</button>
            </div>
        `;
        document.getElementById('btn-qr-start').onclick = createQRSession;
    }

    async function createQRSession() {
        App.showLoading();
        const r = await App.post(QR_API + 'create_session');
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            loadQR();
        }
    }

    function renderQRActive(container, data) {
        const expiresAt = new Date(data.expires_at.replace(' ', 'T'));
        const attendeeCount = data.attendees ? data.attendees.length : 0;

        container.innerHTML = `
            <div class="qr-active">
                <div class="qr-active-header">
                    <div class="qr-timer" id="qr-timer"></div>
                    <button class="btn btn-secondary btn-sm" id="btn-qr-close">세션 종료</button>
                </div>
                <div class="qr-code-wrap">
                    <div id="qr-code-canvas"></div>
                </div>
                <div class="qr-url-wrap">
                    <input type="text" class="form-input" id="qr-url-input" value="${App.esc(data.scan_url)}" readonly>
                    <button class="btn btn-secondary btn-sm" id="btn-qr-copy">복사</button>
                </div>
                <div class="qr-attendees-header">
                    <span class="qr-attendees-title">출석 현황</span>
                    <span class="qr-attendees-count"><strong>${attendeeCount}</strong> / ${data.total_members}명</span>
                </div>
                <div id="qr-attendee-list"></div>
            </div>
        `;

        // QR 코드 생성 (qrcode.js CDN)
        renderQRCode(data.scan_url);

        // 출석자 목록 렌더
        renderAttendeeList(data.attendees);

        // 타이머
        updateQRTimer(expiresAt);
        qrRefreshTimer = setInterval(() => {
            updateQRTimer(expiresAt);
            refreshAttendees();
        }, 5000);

        // 이벤트
        document.getElementById('btn-qr-close').onclick = closeQRSession;
        document.getElementById('btn-qr-copy').onclick = () => {
            const input = document.getElementById('qr-url-input');
            input.select();
            navigator.clipboard.writeText(input.value).then(() => Toast.success('링크가 복사되었습니다'));
        };
    }

    function renderQRCode(url) {
        const canvas = document.getElementById('qr-code-canvas');
        if (!canvas) return;

        // qrcode.js가 로드되었는지 확인
        if (typeof QRCode !== 'undefined') {
            new QRCode(canvas, {
                text: url,
                width: 240,
                height: 240,
                colorDark: '#1D4ED8',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M,
            });
        } else {
            // fallback: 라이브러리 없으면 URL만 표시
            canvas.innerHTML = `<div style="padding:20px;text-align:center;color:#999;">QR 라이브러리 로딩 중...</div>`;
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';
            script.onload = () => {
                canvas.innerHTML = '';
                new QRCode(canvas, {
                    text: url,
                    width: 240,
                    height: 240,
                    colorDark: '#1D4ED8',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.M,
                });
            };
            document.head.appendChild(script);
        }
    }

    function renderAttendeeList(attendees) {
        const list = document.getElementById('qr-attendee-list');
        if (!list) return;

        if (!attendees || attendees.length === 0) {
            list.innerHTML = '<div class="empty-state mt-sm">아직 출석한 학생이 없습니다</div>';
            return;
        }

        list.innerHTML = attendees.map((a, i) => {
            const time = a.scanned_at ? a.scanned_at.substring(11, 16) : '';
            return `
                <div class="qr-attendee-item">
                    <span class="qr-attendee-num">${attendees.length - i}</span>
                    <span class="qr-attendee-name">${App.esc(a.nickname)}</span>
                    <span class="qr-attendee-group">${App.esc(a.group_name)}</span>
                    <span class="qr-attendee-time">${time}</span>
                </div>`;
        }).join('');
    }

    function updateQRTimer(expiresAt) {
        const timerEl = document.getElementById('qr-timer');
        if (!timerEl) return;

        const now = new Date();
        const diff = expiresAt - now;

        if (diff <= 0) {
            timerEl.textContent = '만료됨';
            timerEl.classList.add('expired');
            if (qrRefreshTimer) { clearInterval(qrRefreshTimer); qrRefreshTimer = null; }
            // 자동으로 idle 화면으로 전환
            setTimeout(() => loadQR(), 1000);
            return;
        }

        const mins = Math.floor(diff / 60000);
        const secs = Math.floor((diff % 60000) / 1000);
        timerEl.textContent = `${mins}분 ${String(secs).padStart(2, '0')}초 남음`;
    }

    async function refreshAttendees() {
        const r = await App.api(QR_API + 'session_status', { showError: false });
        if (!r || !r.success) return;

        if (!r.has_session) {
            if (qrRefreshTimer) { clearInterval(qrRefreshTimer); qrRefreshTimer = null; }
            loadQR();
            return;
        }

        // 출석 수 업데이트
        const countEl = document.querySelector('.qr-attendees-count');
        if (countEl) {
            const cnt = r.attendees ? r.attendees.length : 0;
            countEl.innerHTML = `<strong>${cnt}</strong> / ${r.total_members}명`;
        }

        renderAttendeeList(r.attendees);
    }

    async function closeQRSession() {
        if (!qrSessionCode) return;
        if (!await App.confirm('QR 세션을 종료하시겠습니까?')) return;

        App.showLoading();
        const r = await App.post(QR_API + 'close_session', { session_code: qrSessionCode });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            if (qrRefreshTimer) { clearInterval(qrRefreshTimer); qrRefreshTimer = null; }
            loadQR();
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Dashboard (대시보드)
    // ══════════════════════════════════════════════════════════════

    async function loadDashboard(container) {
        const sec = container || document.getElementById('bc-tab-dashboard');
        if (!sec) return;

        sec.innerHTML = `
            <div class="bc-toolbar mt-md">
                <span class="bc-toolbar-title">대시보드</span>
            </div>
            <div id="db-body"><div class="empty-state">로딩 중...</div></div>
        `;

        await renderDashboard(sec);
    }

    async function renderDashboard(sec) {
        const body = sec.querySelector('#db-body') || sec;
        App.showLoading();
        const params = selectedCohortId ? { cohort_id: selectedCohortId } : {};
        const r = await App.get(API + 'dashboard_stats', params);
        App.hideLoading();

        if (!r.success || !r.cohort_summary) {
            body.innerHTML = '<div class="empty-state">아직 채점 기간이 시작되지 않았습니다.</div>';
            return;
        }

        const d = r;
        const cs = d.cohort_summary;

        // 색상 클래스 결정
        function rateColor(rate) {
            if (rate >= 80) return 'high';
            if (rate >= 60) return 'mid';
            return 'low';
        }
        function optLevel(count) {
            if (count === 0) return '';
            if (count <= 3) return 'active-1';
            if (count <= 7) return 'active-2';
            return 'active-3';
        }
        function scoreColor(score) {
            if (score > -5) return '';
            if (score > -10) return 'negative';
            if (score > -15) return 'negative';
            if (score <= -25) return 'danger';
            return 'danger';
        }

        let html = '';

        // ── 섹션 1: 기수 전체 요약
        html += `
            <div class="db-section-title">기수 전체 과제율 <span style="font-weight:normal;font-size:var(--text-xs);color:var(--color-text-muted)">(${d.scoring_start} ~ ${d.scoring_end}, ${cs.member_count}명)</span></div>
            <div class="db-metrics">
                <div class="db-metric-card">
                    <div class="db-metric-label">줌/데일리미션</div>
                    <div class="db-metric-value">${cs.zoom_daily_rate}%</div>
                    <div class="db-metric-bar"><div class="db-metric-bar-fill" style="width:${cs.zoom_daily_rate}%"></div></div>
                    <div class="db-metric-sub">총 ${d.total_days}일</div>
                </div>
                <div class="db-metric-card">
                    <div class="db-metric-label">내맛33</div>
                    <div class="db-metric-value">${cs.inner33_rate}%</div>
                    <div class="db-metric-bar"><div class="db-metric-bar-fill" style="width:${cs.inner33_rate}%"></div></div>
                    <div class="db-metric-sub">총 ${d.total_days}일</div>
                </div>
                <div class="db-metric-card">
                    <div class="db-metric-label">말까미션</div>
                    <div class="db-metric-value">${cs.speak_rate}%</div>
                    <div class="db-metric-bar"><div class="db-metric-bar-fill" style="width:${cs.speak_rate}%"></div></div>
                    <div class="db-metric-sub">총 ${d.total_mondays}주 (월요일)</div>
                </div>
            </div>
        `;

        // ── 섹션 2: 필수 과제율 - 조별 비교
        html += `<div class="db-section-title">필수 과제율 - 조별 비교</div>`;
        html += `<div style="overflow-x:auto"><table class="db-group-table">
            <thead><tr>
                <th>조</th><th>인원</th><th>줌/데일리</th><th>내맛33</th><th>말까미션</th>
            </tr></thead><tbody>`;

        const myGroupIds = (admin && admin.assigned_group_ids) || [];
        for (const g of d.groups) {
            const isMine = myGroupIds.includes(g.id);
            html += `
                <tr class="db-group-row${isMine ? ' db-my-group' : ''}" data-group-id="${g.id}">
                    <td><span class="db-chevron" id="chev-req-${g.id}">&#9654;</span> ${App.esc(g.name)}${isMine ? ' <span class="badge badge-primary badge-xs">MY</span>' : ''}</td>
                    <td>${g.member_count}</td>
                    <td>${g.zoom_daily_rate}%</td>
                    <td>${g.inner33_rate}%</td>
                    <td>${g.speak_rate}%</td>
                </tr>
                <tr class="db-member-panel" id="panel-req-${g.id}"><td colspan="5">
                    <div class="db-member-list">${renderGroupMembers(d.members.filter(m => m.group_id === g.id), 'required')}</div>
                </td></tr>
            `;
        }
        html += `</tbody></table></div>`;

        // ── 섹션 3: 선택 참여 현황
        html += `<div class="db-section-title">선택 참여 현황 <span style="font-weight:normal;font-size:var(--text-xs);color:var(--color-text-muted)">(1인 평균 횟수)</span></div>`;
        html += `<div style="overflow-x:auto"><table class="db-group-table">
            <thead><tr>
                <th>조</th><th>인원</th><th>복클 개설</th><th>복클 참여</th><th>하멈말</th>
            </tr></thead><tbody>`;

        // 전체 행
        html += `<tr style="font-weight:var(--font-bold);background:var(--color-gray-50)">
            <td>전체</td><td>${cs.member_count}</td>
            <td>${cs.optional_avg.bookclub_open}회</td>
            <td>${cs.optional_avg.bookclub_join}회</td>
            <td>${cs.optional_avg.hamemmal}회</td>
        </tr>`;

        for (const g of d.groups) {
            const oa = g.optional_avg;
            const isMine2 = myGroupIds.includes(g.id);
            html += `
                <tr class="db-group-row${isMine2 ? ' db-my-group' : ''}" data-group-id="opt-${g.id}">
                    <td><span class="db-chevron" id="chev-opt-${g.id}">&#9654;</span> ${App.esc(g.name)}${isMine2 ? ' <span class="badge badge-primary badge-xs">MY</span>' : ''}</td>
                    <td>${g.member_count}</td>
                    <td>${oa.bookclub_open}회</td>
                    <td>${oa.bookclub_join}회</td>
                    <td>${oa.hamemmal}회</td>
                </tr>
                <tr class="db-member-panel" id="panel-opt-${g.id}"><td colspan="5">
                    <div class="db-member-list">${renderGroupMembers(d.members.filter(m => m.group_id === g.id), 'optional')}</div>
                </td></tr>
            `;
        }
        html += `</tbody></table></div>`;

        // ── 섹션 4: 점수 현황
        html += `<div class="db-section-title">점수 현황</div>`;

        // 4a: 점수 분포
        const scoreColors = ['score-green', 'score-lime', 'score-yellow', 'score-orange', 'score-red', 'score-black'];
        const maxCount = Math.max(...d.score_distribution.map(s => s.count), 1);
        html += `<div class="db-score-chart">`;
        d.score_distribution.forEach((s, i) => {
            const pct = Math.max(s.count / maxCount * 100, s.count > 0 ? 8 : 0);
            html += `<div class="db-score-row">
                <span class="db-score-label">${s.range}</span>
                <div class="db-score-bar-wrap">
                    <div class="db-score-bar-fill ${scoreColors[i]}" style="width:${pct}%">${s.count > 0 ? s.count + '명' : ''}</div>
                </div>
            </div>`;
        });
        html += `</div>`;

        // 4b: 경고 멤버
        const sw = d.score_warnings;
        if (sw.approaching.length || sw.revival_eligible.length || sw.out.length) {
            html += `<div class="db-section-title">주의 필요 멤버</div>`;

            if (sw.approaching.length) {
                html += `<div class="db-warning-group">
                    <div class="db-warning-title"><span class="badge-yellow">부활 후보</span> (${SCORE_REVIVAL_CANDIDATE} ~ ${SCORE_REVIVAL_CANDIDATE - 1}점)</div>`;
                sw.approaching.forEach(m => {
                    html += `<div class="db-warning-item">${App.esc(m.nickname)} <span style="color:var(--color-text-muted)">(${App.esc(m.group_name)})</span> <span class="score">${m.current_score}점</span></div>`;
                });
                html += `</div>`;
            }
            if (sw.revival_eligible.length) {
                html += `<div class="db-warning-group">
                    <div class="db-warning-title"><span class="badge-black">부활 대상</span> (${SCORE_REVIVAL_ELIGIBLE} ~ ${SCORE_OUT_THRESHOLD + 1}점)</div>`;
                sw.revival_eligible.forEach(m => {
                    html += `<div class="db-warning-item">${App.esc(m.nickname)} <span style="color:var(--color-text-muted)">(${App.esc(m.group_name)})</span> <span class="score">${m.current_score}점</span></div>`;
                });
                html += `</div>`;
            }
            if (sw.out.length) {
                html += `<div class="db-warning-group">
                    <div class="db-warning-title"><span class="badge-out">OUT</span> (${SCORE_OUT_THRESHOLD}점 이하)</div>`;
                sw.out.forEach(m => {
                    html += `<div class="db-warning-item">${App.esc(m.nickname)} <span style="color:var(--color-text-muted)">(${App.esc(m.group_name)})</span> <span class="score">${m.current_score}점</span></div>`;
                });
                html += `</div>`;
            }
        }

        body.innerHTML = html;

        // 아코디언 이벤트 바인딩
        body.querySelectorAll('.db-group-row').forEach(row => {
            row.addEventListener('click', () => {
                const gid = row.dataset.groupId;
                const panel = body.querySelector(`#panel-${gid.startsWith('opt-') ? 'opt-' + gid.replace('opt-', '') : 'req-' + gid}`);
                const chevron = body.querySelector(`#chev-${gid.startsWith('opt-') ? 'opt-' + gid.replace('opt-', '') : 'req-' + gid}`);
                if (panel) {
                    panel.classList.toggle('open');
                    if (chevron) chevron.classList.toggle('open');
                }
            });
        });
    }

    // 점수 임계값 상수 (경고 표시용)
    const SCORE_REVIVAL_CANDIDATE = -13;
    const SCORE_REVIVAL_ELIGIBLE = -15;
    const SCORE_OUT_THRESHOLD = -25;

    function renderGroupMembers(members, mode) {
        if (!members.length) return '<div class="empty-state" style="padding:var(--space-3)">멤버가 없습니다.</div>';

        return members.map(m => {
            const scoreClass = m.current_score <= -25 ? 'danger' : m.current_score <= -10 ? 'negative' : '';

            if (mode === 'required') {
                const rq = m.required;
                return `<div class="db-member-item">
                    <span class="db-member-name">${App.esc(m.nickname)}${m.real_name ? ` <span style="color:#888;font-size:12px">(${App.esc(m.real_name)})</span>` : ''}</span>
                    <span class="db-member-score score-cell ${scoreClass}">${m.current_score}</span>
                    <div class="db-member-bars">
                        ${miniBar('줌', rq.zoom_daily.rate)}
                        ${miniBar('내맛', rq.inner33.rate)}
                        ${miniBar('말까', rq.speak_mission.rate)}
                    </div>
                </div>`;
            } else {
                const op = m.optional;
                return `<div class="db-member-item">
                    <span class="db-member-name">${App.esc(m.nickname)}${m.real_name ? ` <span style="color:#888;font-size:12px">(${App.esc(m.real_name)})</span>` : ''}</span>
                    <div class="db-member-optional">
                        <span class="db-opt-badge ${optLevel(op.bookclub_open)}">개설 ${op.bookclub_open}</span>
                        <span class="db-opt-badge ${optLevel(op.bookclub_join)}">참여 ${op.bookclub_join}</span>
                        <span class="db-opt-badge ${optLevel(op.hamemmal)}">하멈말 ${op.hamemmal}</span>
                    </div>
                </div>`;
            }
        }).join('');

        function miniBar(label, rate) {
            const cls = rate >= 80 ? 'high' : rate >= 60 ? 'mid' : 'low';
            return `<div class="db-mini-bar-wrap">
                <div class="db-mini-bar"><div class="db-mini-bar-fill ${cls}" style="width:${rate}%"></div></div>
                <span class="db-mini-rate">${rate}%</span>
            </div>`;
        }

        function optLevel(count) {
            if (count === 0) return '';
            if (count <= 3) return 'active-1';
            if (count <= 7) return 'active-2';
            return 'active-3';
        }
    }

    // ── Entrance Check (입장 체크) ──

    async function loadEntrance() {
        const sec = document.getElementById('bc-tab-entrance');
        if (!sec) return;

        if (!selectedGroupId) {
            sec.innerHTML = '<div class="empty-state">배정된 조가 없습니다.</div>';
            return;
        }

        sec.innerHTML = '<div class="empty-state">로딩 중...</div>';

        const r = await App.get(API + 'entrance_list', { group_id: selectedGroupId });
        if (!r.success) {
            sec.innerHTML = '<div class="empty-state">데이터를 불러올 수 없습니다.</div>';
            return;
        }

        const members = r.members || [];
        if (!members.length) {
            sec.innerHTML = '<div class="empty-state">조원이 없습니다.</div>';
            return;
        }

        const enteredCount = members.filter(m => parseInt(m.entered)).length;
        const total = members.length;

        sec.innerHTML = `
            <div class="bc-toolbar mt-md">
                <span class="bc-toolbar-title">${App.esc(leaderGroupName)} 입장 체크</span>
                <button class="btn btn-primary btn-sm" id="bc-entrance-save">저장</button>
            </div>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:var(--space-3)">
                <div class="task-filter-chips" id="bc-entrance-filters">
                    <button class="chip active" data-filter="all">전체</button>
                    <button class="chip" data-filter="entered">입장 완료</button>
                    <button class="chip" data-filter="not_entered">미입장</button>
                </div>
                <span id="bc-entrance-stats" style="font-size:var(--text-sm);color:var(--color-text-sub)">${enteredCount} / ${total}명 입장 완료</span>
            </div>
            <div class="mt-container">
                <table class="data-table" id="bc-entrance-table">
                    <thead>
                        <tr>
                            <th>이름</th>
                            <th>닉네임</th>
                            <th style="text-align:center">입장</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${members.map(m => `
                        <tr data-entered="${parseInt(m.entered) ? '1' : '0'}">
                            <td>${App.esc(m.real_name || '-')}</td>
                            <td>${App.esc(m.nickname || '-')}${m.member_role !== 'member' ? ` <span class="badge badge-primary" style="font-size:10px">${App.esc(ROLE_LABELS[m.member_role] || m.member_role)}</span>` : ''}</td>
                            <td style="text-align:center">
                                <input type="checkbox" class="bc-entrance-check" data-member-id="${m.id}" ${parseInt(m.entered) ? 'checked' : ''}>
                            </td>
                        </tr>`).join('')}
                    </tbody>
                </table>
            </div>
        `;

        // 필터 칩 이벤트
        const filterWrap = document.getElementById('bc-entrance-filters');
        filterWrap.querySelectorAll('.chip').forEach(btn => {
            btn.onclick = () => {
                filterWrap.querySelectorAll('.chip').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                filterEntranceRows(btn.dataset.filter);
            };
        });

        // 체크박스 변경 시 카운트 & data-entered 실시간 업데이트
        sec.querySelectorAll('.bc-entrance-check').forEach(cb => {
            cb.addEventListener('change', () => {
                cb.closest('tr').dataset.entered = cb.checked ? '1' : '0';
                updateEntranceStats();
                // 현재 필터 다시 적용
                const activeFilter = filterWrap.querySelector('.chip.active')?.dataset.filter || 'all';
                filterEntranceRows(activeFilter);
            });
        });

        document.getElementById('bc-entrance-save').onclick = saveEntrance;
    }

    async function saveEntrance() {
        const checkboxes = document.querySelectorAll('.bc-entrance-check');
        const entries = [];
        checkboxes.forEach(cb => {
            entries.push({
                member_id: parseInt(cb.dataset.memberId),
                entered: cb.checked ? 1 : 0,
            });
        });

        if (!entries.length) return;

        App.showLoading();
        const r = await App.post(API + 'entrance_save', {
            group_id: selectedGroupId,
            entries,
        });
        App.hideLoading();

        if (r.success) {
            Toast.success(r.message);
        }
    }

    function filterEntranceRows(filter) {
        const table = document.getElementById('bc-entrance-table');
        if (!table) return;
        table.querySelectorAll('tbody tr').forEach(row => {
            if (filter === 'all') { row.style.display = ''; return; }
            const entered = row.dataset.entered === '1';
            row.style.display = (filter === 'entered' && entered) || (filter === 'not_entered' && !entered) ? '' : 'none';
        });
    }

    function updateEntranceStats() {
        const statsEl = document.getElementById('bc-entrance-stats');
        if (!statsEl) return;
        const rows = document.querySelectorAll('#bc-entrance-table tbody tr');
        let total = 0, entered = 0;
        rows.forEach(row => { total++; if (row.dataset.entered === '1') entered++; });
        statsEl.textContent = `${entered} / ${total}명 입장 완료`;
    }

    // ── Public API ──
    function _getCohorts() { return cohorts; }
    function _getSelectedCohortId() { return selectedCohortId; }

    return {
        init,
        initForCoach,
        initForLeader,
        loadDashboard,
        _editMember, _deleteMember,
        _coinAction, _coinLogs,
        _editGroup, _deleteGroup,
        showWarningNoteForm,
        _getCohorts,
        _getSelectedCohortId,
    };
})();
