/* ══════════════════════════════════════════════════════════════
   Group Assignment App
   조 배정 관리: 조장 관리, 조 생성, 자동 배정, 수동 이동
   ══════════════════════════════════════════════════════════════ */
const GroupAssignmentApp = (() => {
    const API = '/api/bootcamp.php?action=';
    let admin = null;
    let role = '';
    let cohorts = [];
    let selectedCohortId = 0;
    let selectedStageNo = 0;
    let currentSubTab = 'overview';

    function init(adminData, roleStr, cohortList, activeCohortId) {
        admin = adminData;
        role = roleStr;
        cohorts = cohortList;
        selectedCohortId = activeCohortId;
    }

    // ══════════════════════════════════════════════════════════
    // 메인 탭 렌더링
    // ══════════════════════════════════════════════════════════

    function renderTab(container) {
        container.innerHTML = `
            <div class="bc-toolbar mt-md">
                <span class="bc-toolbar-title">조 배정 관리</span>
            </div>
            <div class="ga-filters">
                <div class="filter-item">
                    <span class="filter-label">기수</span>
                    <select id="ga-cohort">
                        ${cohorts.map(c => `<option value="${c.id}" ${parseInt(c.id) === selectedCohortId ? 'selected' : ''}>${App.esc(c.cohort)}</option>`).join('')}
                    </select>
                </div>
                <div class="filter-item">
                    <span class="filter-label">단계</span>
                    <select id="ga-stage">
                        <option value="0">전체</option>
                        <option value="1" ${selectedStageNo === 1 ? 'selected' : ''}>1단계</option>
                        <option value="2" ${selectedStageNo === 2 ? 'selected' : ''}>2단계</option>
                    </select>
                </div>
            </div>
            <div class="ga-subtabs">
                <button class="ga-subtab active" data-sub="overview">현황</button>
                <button class="ga-subtab" data-sub="leaders">조장/부조장 관리</button>
                <button class="ga-subtab" data-sub="groups">조 생성/관리</button>
                <button class="ga-subtab" data-sub="auto">자동 배정</button>
                <button class="ga-subtab" data-sub="manual">수동 이동</button>
            </div>
            <div id="ga-body"></div>
        `;

        document.getElementById('ga-cohort').onchange = (e) => {
            selectedCohortId = parseInt(e.target.value);
            loadSubTab(currentSubTab);
        };
        document.getElementById('ga-stage').onchange = (e) => {
            selectedStageNo = parseInt(e.target.value);
            loadSubTab(currentSubTab);
        };

        container.querySelectorAll('.ga-subtab').forEach(btn => {
            btn.onclick = () => {
                container.querySelectorAll('.ga-subtab').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                loadSubTab(btn.dataset.sub);
            };
        });

        loadSubTab('overview');
    }

    function loadSubTab(sub) {
        currentSubTab = sub;
        const body = document.getElementById('ga-body');
        if (!body) return;
        body.innerHTML = '<div class="empty-state">로딩 중...</div>';

        switch (sub) {
            case 'overview': loadOverview(body); break;
            case 'leaders': loadLeaders(body); break;
            case 'groups': loadGroups(body); break;
            case 'auto': loadAutoAssign(body); break;
            case 'manual': loadManualMove(body); break;
        }
    }

    // ══════════════════════════════════════════════════════════
    // 1. 현황 (Overview)
    // ══════════════════════════════════════════════════════════

    async function loadOverview(body) {
        const r = await App.get(API + 'assignment_summary', { cohort_id: selectedCohortId });
        if (!r.success) return;

        const stageStats = r.stage_stats || [];
        const groupStats = r.group_stats || [];

        let stageHtml = '';
        for (const stage of [1, 2]) {
            const s = stageStats.find(x => parseInt(x.stage_no) === stage) || { total: 0, assigned: 0, unassigned: 0 };
            stageHtml += `
                <div class="ga-stat-card">
                    <div class="ga-stat-title">${stage}단계</div>
                    <div class="ga-stat-row"><span>전체</span><strong>${s.total}명</strong></div>
                    <div class="ga-stat-row"><span>배정</span><strong class="text-success">${s.assigned}명</strong></div>
                    <div class="ga-stat-row"><span>미배정</span><strong class="text-danger">${s.unassigned}명</strong></div>
                </div>
            `;
        }

        const stageFilter = selectedStageNo || 0;
        const filteredGroups = stageFilter ? groupStats.filter(g => parseInt(g.stage_no) === stageFilter) : groupStats;

        body.innerHTML = `
            <div class="ga-stats-grid">${stageHtml}</div>
            <h3 class="ga-section-title mt-lg">조별 인원 분포</h3>
            ${filteredGroups.length ? `
            <div class="ga-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>조</th><th>단계</th><th>조장</th><th>부조장</th><th>인원</th><th>신규</th><th>재수강</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${filteredGroups.map(g => `
                            <tr>
                                <td><strong>${App.esc(g.name)}</strong></td>
                                <td><span class="badge badge-neutral">${g.stage_no}단계</span></td>
                                <td>${App.esc(g.leader_nickname || '-')}</td>
                                <td>${App.esc(g.subleader_nickname || '-')}</td>
                                <td>${g.member_count}</td>
                                <td>${g.new_count}</td>
                                <td>${g.returning_count}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            ` : '<div class="empty-state">등록된 조가 없습니다.</div>'}
        `;
    }

    // ══════════════════════════════════════════════════════════
    // 2. 조장 관리 (Leaders)
    // ══════════════════════════════════════════════════════════

    async function loadLeaders(body) {
        const params = { cohort_id: selectedCohortId };
        if (selectedStageNo) params.stage_no = selectedStageNo;
        const r = await App.get(API + 'leader_candidates', params);
        if (!r.success) return;

        const candidates = r.candidates || [];
        const leaders = candidates.filter(c => c.member_role === 'leader');
        const subleaders = candidates.filter(c => c.member_role === 'subleader');
        const members = candidates.filter(c => c.member_role === 'member');

        body.innerHTML = `
            <h3 class="ga-section-title">현재 조장 (${leaders.length}명)</h3>
            ${leaders.length ? `
            <div class="ga-table-wrap">
                <table class="data-table">
                    <thead><tr><th>닉네임</th><th>이름</th><th>단계</th><th>배정된 조</th><th></th></tr></thead>
                    <tbody>
                        ${leaders.map(m => `
                            <tr>
                                <td><strong>${App.esc(m.nickname)}</strong></td>
                                <td>${App.esc(m.real_name || '-')}</td>
                                <td><span class="badge badge-neutral">${m.stage_no}단계</span></td>
                                <td>${App.esc(m.group_name || '미배정')}</td>
                                <td>
                                    <button class="btn btn-sm btn-danger-outline" onclick="GroupAssignmentApp._unassignLeader(${m.id}, '${App.esc(m.nickname)}')">해제</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            ` : '<div class="empty-state">지정된 조장이 없습니다.</div>'}

            <h3 class="ga-section-title mt-lg">현재 부조장 (${subleaders.length}명)</h3>
            ${subleaders.length ? `
            <div class="ga-table-wrap">
                <table class="data-table">
                    <thead><tr><th>닉네임</th><th>이름</th><th>단계</th><th>배정된 조</th><th></th></tr></thead>
                    <tbody>
                        ${subleaders.map(m => `
                            <tr>
                                <td><strong>${App.esc(m.nickname)}</strong></td>
                                <td>${App.esc(m.real_name || '-')}</td>
                                <td><span class="badge badge-neutral">${m.stage_no}단계</span></td>
                                <td>${App.esc(m.group_name || '미배정')}</td>
                                <td>
                                    <button class="btn btn-sm btn-danger-outline" onclick="GroupAssignmentApp._unassignSubleader(${m.id}, '${App.esc(m.nickname)}')">해제</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            ` : '<div class="empty-state">지정된 부조장이 없습니다.</div>'}

            <h3 class="ga-section-title mt-lg">회원 목록 (${members.length}명)</h3>
            <div class="ga-search-bar">
                <input type="text" class="form-input" id="ga-leader-search" placeholder="닉네임/이름 검색...">
            </div>
            <div id="ga-leader-list">
                ${renderMemberList(members)}
            </div>
        `;

        document.getElementById('ga-leader-search').oninput = (e) => {
            const kw = e.target.value.toLowerCase();
            const filtered = members.filter(m =>
                m.nickname.toLowerCase().includes(kw) || (m.real_name || '').toLowerCase().includes(kw)
            );
            document.getElementById('ga-leader-list').innerHTML = renderMemberList(filtered);
        };
    }

    function renderMemberList(members) {
        if (!members.length) return '<div class="empty-state">회원이 없습니다.</div>';
        return `
            <div class="ga-table-wrap">
                <table class="data-table">
                    <thead><tr><th>닉네임</th><th>이름</th><th>단계</th><th>현재 조</th><th></th></tr></thead>
                    <tbody>
                        ${members.map(m => `
                            <tr>
                                <td>${App.esc(m.nickname)}</td>
                                <td>${App.esc(m.real_name || '-')}</td>
                                <td><span class="badge badge-neutral">${m.stage_no}단계</span></td>
                                <td>${App.esc(m.group_name || '미배정')}</td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="GroupAssignmentApp._assignLeader(${m.id})">조장</button>
                                    <button class="btn btn-sm btn-secondary" onclick="GroupAssignmentApp._assignSubleader(${m.id})">부조장</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    async function _assignLeader(memberId) {
        App.showLoading();
        const r = await App.post(API + 'leader_assign', { member_id: memberId });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            loadSubTab('leaders');
        }
    }

    async function _unassignLeader(memberId, nickname) {
        if (!await App.confirm(`'${nickname}'의 조장을 해제하시겠습니까?`)) return;
        App.showLoading();
        const r = await App.post(API + 'leader_unassign', { member_id: memberId });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            loadSubTab('leaders');
        }
    }

    async function _assignSubleader(memberId) {
        App.showLoading();
        const r = await App.post(API + 'subleader_assign', { member_id: memberId });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            loadSubTab('leaders');
        }
    }

    async function _unassignSubleader(memberId, nickname) {
        if (!await App.confirm(`'${nickname}'의 부조장을 해제하시겠습니까?`)) return;
        App.showLoading();
        const r = await App.post(API + 'subleader_unassign', { member_id: memberId });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            loadSubTab('leaders');
        }
    }

    // ══════════════════════════════════════════════════════════
    // 3. 조 생성/관리 (Groups)
    // ══════════════════════════════════════════════════════════

    async function loadGroups(body) {
        const params = { cohort_id: selectedCohortId };
        if (selectedStageNo) params.stage_no = selectedStageNo;
        const r = await App.get(API + 'groups_with_stats', params);
        if (!r.success) return;

        const groups = r.groups || [];
        const unassigned = r.unassigned_count || 0;

        body.innerHTML = `
            <div class="ga-toolbar">
                <span>미배정 인원: <strong class="text-danger">${unassigned}명</strong></span>
                <button class="btn btn-primary btn-sm" id="ga-add-group">조 추가</button>
            </div>
            ${groups.length ? `
            <div class="ga-table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>조 이름</th><th>단계</th><th>조장</th><th>부조장</th><th>인원</th><th>신규</th><th>재수강</th><th></th></tr>
                    </thead>
                    <tbody>
                        ${groups.map(g => `
                            <tr>
                                <td><strong>${App.esc(g.name)}</strong></td>
                                <td><span class="badge badge-neutral">${g.stage_no}단계</span></td>
                                <td>${App.esc(g.leader_nickname || g.leader_real_name || '-')}</td>
                                <td>${App.esc(g.subleader_nickname || '-')}</td>
                                <td>${g.total_members}</td>
                                <td>${g.new_members}</td>
                                <td>${g.returning_members}</td>
                                <td class="actions">
                                    <button class="btn-icon" onclick="GroupAssignmentApp._editGroup(${g.id})">수정</button>
                                    <button class="btn-icon danger" onclick="GroupAssignmentApp._deleteGroup(${g.id}, '${App.esc(g.name)}')">삭제</button>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
            ` : '<div class="empty-state">등록된 조가 없습니다.</div>'}
        `;

        document.getElementById('ga-add-group').onclick = () => showGroupCreateForm();
    }

    function getAvailableCandidates(candidates, stageNo) {
        const leaders = candidates.filter(c => c.member_role === 'leader' && parseInt(c.leader_group_count) === 0 && parseInt(c.stage_no) === stageNo);
        const subleaders = candidates.filter(c => c.member_role === 'subleader' && !c.group_id && parseInt(c.stage_no) === stageNo);
        return { leaders, subleaders };
    }

    function renderLeaderOptions(leaders) {
        return '<option value="">선택하세요</option>' +
            leaders.map(l => `<option value="${l.id}">${App.esc(l.nickname)}${l.real_name ? ' (' + App.esc(l.real_name) + ')' : ''}</option>`).join('');
    }

    function renderSubleaderOptions(subleaders) {
        return '<option value="">없음</option>' +
            subleaders.map(l => `<option value="${l.id}">${App.esc(l.nickname)}${l.real_name ? ' (' + App.esc(l.real_name) + ')' : ''}</option>`).join('');
    }

    async function showGroupCreateForm() {
        const stageNo = selectedStageNo || 1;

        const r = await App.get(API + 'leader_candidates', { cohort_id: selectedCohortId });
        const allCandidates = r.candidates || [];
        let { leaders, subleaders } = getAvailableCandidates(allCandidates, stageNo);

        const body = `
            <div class="form-group">
                <label class="form-label">조 이름 *</label>
                <input type="text" class="form-input" id="gcf-name" placeholder="예: 루크조">
            </div>
            <div class="form-group">
                <label class="form-label">단계 *</label>
                <select class="form-select" id="gcf-stage">
                    <option value="1" ${stageNo === 1 ? 'selected' : ''}>1단계</option>
                    <option value="2" ${stageNo === 2 ? 'selected' : ''}>2단계</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">조장 *</label>
                <select class="form-select" id="gcf-leader">
                    ${renderLeaderOptions(leaders)}
                </select>
                <div class="form-help" id="gcf-leader-help">${leaders.length === 0 ? '해당 단계에 미배정 조장이 없습니다. 먼저 조장을 지정해주세요.' : ''}</div>
            </div>
            <div class="form-group">
                <label class="form-label">부조장</label>
                <select class="form-select" id="gcf-subleader">
                    ${renderSubleaderOptions(subleaders)}
                </select>
                <div class="form-help" id="gcf-subleader-help">${subleaders.length === 0 ? '해당 단계에 미배정 부조장이 없습니다.' : ''}</div>
            </div>
        `;
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">취소</button>
            <button class="btn btn-primary" id="gcf-save">생성</button>
        `;
        App.openModal('조 생성', body, footer);

        // 단계 변경 시 조장/부조장 후보 갱신
        document.getElementById('gcf-stage').onchange = (e) => {
            const st = parseInt(e.target.value);
            const avail = getAvailableCandidates(allCandidates, st);
            document.getElementById('gcf-leader').innerHTML = renderLeaderOptions(avail.leaders);
            document.getElementById('gcf-leader-help').textContent = avail.leaders.length === 0 ? '해당 단계에 미배정 조장이 없습니다.' : '';
            document.getElementById('gcf-subleader').innerHTML = renderSubleaderOptions(avail.subleaders);
            document.getElementById('gcf-subleader-help').textContent = avail.subleaders.length === 0 ? '해당 단계에 미배정 부조장이 없습니다.' : '';
        };

        document.getElementById('gcf-save').onclick = async () => {
            const name = document.getElementById('gcf-name').value.trim();
            const stage = parseInt(document.getElementById('gcf-stage').value);
            const leaderId = parseInt(document.getElementById('gcf-leader').value);
            const subleaderId = parseInt(document.getElementById('gcf-subleader').value) || null;

            if (!name) return Toast.warning('조 이름을 입력해주세요.');
            if (!leaderId) return Toast.warning('조장을 선택해주세요.');

            App.showLoading();
            const r = await App.post(API + 'group_create_ext', {
                cohort_id: selectedCohortId,
                name,
                stage_no: stage,
                leader_member_id: leaderId,
                subleader_member_id: subleaderId,
            });
            App.hideLoading();
            if (r.success) {
                App.closeModal();
                Toast.success(r.message);
                loadSubTab('groups');
            }
        };
    }

    async function _editGroup(groupId) {
        const [r, r2] = await Promise.all([
            App.get(API + 'groups_with_stats', { cohort_id: selectedCohortId }),
            App.get(API + 'leader_candidates', { cohort_id: selectedCohortId }),
        ]);
        if (!r.success) return;
        const group = (r.groups || []).find(g => parseInt(g.id) === groupId);
        if (!group) return Toast.error('조를 찾을 수 없습니다.');

        const allCandidates = r2.candidates || [];
        const stageNo = parseInt(group.stage_no);

        // 조장 후보: 미배정 조장 + 현재 이 조의 조장
        const leaders = allCandidates.filter(c =>
            c.member_role === 'leader' && parseInt(c.stage_no) === stageNo &&
            (parseInt(c.leader_group_count) === 0 || parseInt(c.id) === parseInt(group.leader_member_id))
        );

        // 부조장 후보: 미배정 부조장 + 현재 이 조의 부조장
        const currentSubleaderId = group.subleader_member_id ? parseInt(group.subleader_member_id) : null;
        const subleaders = allCandidates.filter(c =>
            c.member_role === 'subleader' && parseInt(c.stage_no) === stageNo &&
            (!c.group_id || parseInt(c.id) === currentSubleaderId)
        );

        const body = `
            <div class="form-group">
                <label class="form-label">조 이름</label>
                <input type="text" class="form-input" id="gef-name" value="${App.esc(group.name)}">
            </div>
            <div class="form-group">
                <label class="form-label">단계</label>
                <input type="text" class="form-input" value="${group.stage_no}단계" disabled>
            </div>
            <div class="form-group">
                <label class="form-label">조장</label>
                <select class="form-select" id="gef-leader">
                    ${leaders.map(l => `<option value="${l.id}" ${parseInt(l.id) === parseInt(group.leader_member_id) ? 'selected' : ''}>${App.esc(l.nickname)}${l.real_name ? ' (' + App.esc(l.real_name) + ')' : ''}</option>`).join('')}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">부조장</label>
                <select class="form-select" id="gef-subleader">
                    <option value="">없음</option>
                    ${subleaders.map(m => `<option value="${m.id}" ${parseInt(m.id) === currentSubleaderId ? 'selected' : ''}>${App.esc(m.nickname)}${m.real_name ? ' (' + App.esc(m.real_name) + ')' : ''}</option>`).join('')}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">카카오톡 링크</label>
                <input type="text" class="form-input" id="gef-kakao" value="${App.esc(group.kakao_link || '')}">
            </div>
        `;
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">취소</button>
            <button class="btn btn-primary" id="gef-save">수정</button>
        `;
        App.openModal('조 수정', body, footer);

        document.getElementById('gef-save').onclick = async () => {
            const subleaderVal = document.getElementById('gef-subleader').value;
            const payload = {
                id: groupId,
                name: document.getElementById('gef-name').value.trim(),
                leader_member_id: parseInt(document.getElementById('gef-leader').value) || null,
                subleader_member_id: subleaderVal ? parseInt(subleaderVal) : null,
                kakao_link: document.getElementById('gef-kakao').value.trim(),
            };
            App.showLoading();
            const r = await App.post(API + 'group_update_ext', payload);
            App.hideLoading();
            if (r.success) {
                App.closeModal();
                Toast.success(r.message);
                loadSubTab('groups');
            }
        };
    }

    async function _deleteGroup(groupId, name) {
        if (!await App.confirm(`'${name}' 조를 삭제하시겠습니까?\n배정된 회원은 미배정 상태가 됩니다.`)) return;
        App.showLoading();
        const r = await App.post(API + 'group_delete', { id: groupId });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            loadSubTab('groups');
        }
    }

    // ══════════════════════════════════════════════════════════
    // 4. 자동 배정 (Auto Assignment)
    // ══════════════════════════════════════════════════════════

    let previewData = null;

    async function loadAutoAssign(body) {
        previewData = null;
        const stageNo = selectedStageNo || 0;

        body.innerHTML = `
            <div class="ga-auto-header">
                <p>미배정 회원을 조에 균등하게 자동 배정합니다.</p>
                <p class="text-muted">재수강/신규 회원이 각 조에 골고루 분배됩니다.</p>
            </div>
            ${stageNo ? `
                <div class="ga-auto-actions">
                    <button class="btn btn-primary" id="ga-preview-btn">미리보기 생성</button>
                </div>
                <div id="ga-preview-body"></div>
            ` : `
                <div class="ga-stage-select">
                    <p>자동 배정할 단계를 선택하세요:</p>
                    <button class="btn btn-primary btn-lg" onclick="GroupAssignmentApp._autoForStage(1)">1단계 자동 배정</button>
                    <button class="btn btn-primary btn-lg" onclick="GroupAssignmentApp._autoForStage(2)">2단계 자동 배정</button>
                </div>
            `}
        `;

        if (stageNo) {
            document.getElementById('ga-preview-btn').onclick = () => generatePreview(stageNo);
        }
    }

    function _autoForStage(stage) {
        selectedStageNo = stage;
        document.getElementById('ga-stage').value = stage;
        loadSubTab('auto');
    }

    async function generatePreview(stageNo) {
        const previewBody = document.getElementById('ga-preview-body');
        previewBody.innerHTML = '<div class="empty-state">미리보기 생성 중...</div>';

        App.showLoading();
        const r = await App.get(API + 'assignment_preview', { cohort_id: selectedCohortId, stage_no: stageNo });
        App.hideLoading();

        if (!r.success) return;

        if (r.error) {
            previewBody.innerHTML = `<div class="ga-error">${App.esc(r.error)}</div>`;
            return;
        }

        if (r.message) {
            previewBody.innerHTML = `<div class="empty-state">${App.esc(r.message)}</div>`;
            return;
        }

        previewData = r;
        const preview = r.preview || [];

        previewBody.innerHTML = `
            <div class="ga-preview-summary">
                <div class="ga-stat-card compact">
                    <span>배정 대상</span><strong>${r.total_unassigned}명</strong>
                </div>
                <div class="ga-stat-card compact">
                    <span>신규</span><strong>${r.total_new}명</strong>
                </div>
                <div class="ga-stat-card compact">
                    <span>재수강</span><strong>${r.total_returning}명</strong>
                </div>
            </div>
            <h3 class="ga-section-title mt-md">배정 미리보기</h3>
            <div class="ga-preview-groups">
                ${preview.map(g => `
                    <div class="ga-preview-card">
                        <div class="ga-preview-card-header">
                            <strong>${App.esc(g.group_name)}</strong>
                            <span class="text-muted">조장: ${App.esc(g.leader_nickname)}</span>
                        </div>
                        <div class="ga-preview-card-stats">
                            <span>기존 ${g.existing_count}명</span>
                            <span class="text-success">+ 신규 ${g.new_count}명</span>
                            <span class="text-info">+ 재수강 ${g.returning_count}명</span>
                            <span><strong>= 총 ${g.total_after}명</strong></span>
                        </div>
                        ${(g.new_assigned.length + g.returning_assigned.length) > 0 ? `
                        <div class="ga-preview-card-members">
                            ${g.returning_assigned.map(m => `<span class="ga-member-chip returning">${App.esc(m.nickname)}</span>`).join('')}
                            ${g.new_assigned.map(m => `<span class="ga-member-chip new">${App.esc(m.nickname)}</span>`).join('')}
                        </div>
                        ` : '<div class="text-muted" style="padding:8px">새 배정 없음</div>'}
                    </div>
                `).join('')}
            </div>
            <div class="ga-preview-actions mt-md">
                <button class="btn btn-secondary" id="ga-cancel-btn">취소</button>
                <button class="btn btn-primary" id="ga-confirm-btn">확정</button>
            </div>
        `;

        document.getElementById('ga-cancel-btn').onclick = () => {
            previewData = null;
            previewBody.innerHTML = '';
        };
        document.getElementById('ga-confirm-btn').onclick = () => confirmAssignment(stageNo);
    }

    async function confirmAssignment(stageNo) {
        if (!await App.confirm('자동 배정을 확정하시겠습니까?\nDB에 저장됩니다.')) return;

        App.showLoading();
        const r = await App.post(API + 'assignment_confirm', { cohort_id: selectedCohortId, stage_no: stageNo });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            previewData = null;
            loadSubTab('auto');
        }
    }

    // ══════════════════════════════════════════════════════════
    // 5. 수동 이동 (Manual Move)
    // ══════════════════════════════════════════════════════════

    async function loadManualMove(body) {
        const params = { cohort_id: selectedCohortId };
        if (selectedStageNo) params.stage_no = selectedStageNo;

        const [rMembers, rGroups] = await Promise.all([
            App.get(API + 'group_members', params),
            App.get(API + 'groups_with_stats', params),
        ]);

        if (!rMembers.success || !rGroups.success) return;

        const members = rMembers.members || [];
        const groups = rGroups.groups || [];

        // 조별로 그룹핑
        const grouped = {};
        grouped['unassigned'] = { name: '미배정', members: [] };
        groups.forEach(g => { grouped[g.id] = { name: g.name, stage_no: g.stage_no, members: [] }; });

        members.forEach(m => {
            const key = m.group_id || 'unassigned';
            if (grouped[key]) {
                grouped[key].members.push(m);
            } else {
                grouped['unassigned'].members.push(m);
            }
        });

        // 이동 대상 조 옵션 생성
        const groupOptions = groups.map(g => `<option value="${g.id}">${App.esc(g.name)} (${g.stage_no}단계)</option>`).join('');

        let html = '<div class="ga-manual-grid">';
        // 미배정 먼저
        const unassigned = grouped['unassigned'];
        if (unassigned.members.length) {
            html += renderGroupCard('unassigned', unassigned, groupOptions, groups);
        }
        // 조별
        groups.forEach(g => {
            const gd = grouped[g.id];
            if (gd) {
                html += renderGroupCard(g.id, gd, groupOptions, groups);
            }
        });
        html += '</div>';

        body.innerHTML = html;
    }

    function renderGroupCard(groupId, groupData, groupOptions, allGroups) {
        const isUnassigned = groupId === 'unassigned';
        return `
            <div class="ga-group-card">
                <div class="ga-group-card-header">
                    <strong>${App.esc(groupData.name)}</strong>
                    <span class="badge ${isUnassigned ? 'badge-danger' : 'badge-neutral'}">${groupData.members.length}명</span>
                </div>
                <div class="ga-group-card-body">
                    ${groupData.members.length ? groupData.members.map(m => {
                        const isLeader = m.member_role === 'leader';
                        const isSubleader = m.member_role === 'subleader';
                        const isFixed = isLeader || isSubleader;
                        const pc = parseInt(m.participation_count);
                        const pcBadge = pc > 1 ? `<span class="badge badge-info badge-xs">${pc}회차</span>` : '';
                        return `
                            <div class="ga-member-row ${isLeader ? 'is-leader' : ''}">
                                <div class="ga-member-info">
                                    <span>${App.esc(m.nickname)}</span>
                                    ${isLeader ? '<span class="badge badge-primary badge-xs">조장</span>' : ''}
                                    ${isSubleader ? '<span class="badge badge-secondary badge-xs">부조장</span>' : ''}
                                    ${pcBadge}
                                </div>
                                ${!isFixed ? `
                                <select class="form-select form-select-sm ga-move-select" data-member-id="${m.id}" data-member-name="${App.esc(m.nickname)}" data-stage="${m.stage_no}">
                                    <option value="${m.group_id || ''}" selected>${isUnassigned ? '미배정' : App.esc(groupData.name)}</option>
                                    ${isUnassigned ? '' : '<option value="">미배정</option>'}
                                    ${allGroups.filter(g => parseInt(g.id) !== parseInt(m.group_id) && parseInt(g.stage_no) === parseInt(m.stage_no)).map(g =>
                                        `<option value="${g.id}">${App.esc(g.name)}</option>`
                                    ).join('')}
                                </select>
                                ` : ''}
                            </div>
                        `;
                    }).join('') : '<div class="text-muted" style="padding:8px">회원 없음</div>'}
                </div>
            </div>
        `;
    }

    // 이벤트 위임으로 이동 처리
    document.addEventListener('change', async (e) => {
        if (!e.target.classList.contains('ga-move-select')) return;
        const sel = e.target;
        const memberId = parseInt(sel.dataset.memberId);
        const memberName = sel.dataset.memberName;
        const targetGroupId = sel.value || null;
        const originalValue = sel.querySelector('option[selected]')?.value || '';

        if ((targetGroupId || '') === originalValue) return;

        const targetName = targetGroupId ? sel.options[sel.selectedIndex].text : '미배정';
        if (!await App.confirm(`'${memberName}'을(를) '${targetName}'(으)로 이동하시겠습니까?`)) {
            sel.value = originalValue;
            return;
        }

        App.showLoading();
        const r = await App.post(API + 'member_move', { member_id: memberId, target_group_id: targetGroupId });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message);
            loadSubTab('manual');
        } else {
            sel.value = originalValue;
        }
    });

    return {
        init,
        renderTab,
        _assignLeader,
        _unassignLeader,
        _assignSubleader,
        _unassignSubleader,
        _editGroup,
        _deleteGroup,
        _autoForStage,
    };
})();
