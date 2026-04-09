/* ══════════════════════════════════════════════════════════════
   AdminStudyApp — 어드민용 복습스터디 관리
   coach / head / operation 탭에서 사용
   ══════════════════════════════════════════════════════════════ */
const AdminStudyApp = (() => {
    const API = '/api/bootcamp.php?action=';

    let admin = null;
    let cohortId = 0;
    let cal = null;
    let sessions = [];
    let groups = [];
    let tabEl = null;

    function initTab(container, adminData, cid) {
        admin = adminData;
        cohortId = cid;
        tabEl = container;

        tabEl.innerHTML = `
            <div class="bc-toolbar mt-md">
                <span class="bc-toolbar-title">복습스터디</span>
                <button class="btn btn-primary btn-sm" id="btn-admin-study-create">+ 예약</button>
            </div>
            <div id="admin-study-cal"></div>
        `;

        document.getElementById('btn-admin-study-create').onclick = openCreateModal;

        cal = CalendarUI.create(document.getElementById('admin-study-cal'), {
            onMonthChange: () => loadSessions(),
            chipSelector: '.study-chip',
            onChipClick: (e, chip) => openDetail(parseInt(chip.dataset.id)),
            renderChips(events) {
                return events.map(s => {
                    const time = (s.start_time || '').substring(0, 5);
                    const nick = extractHost(s.title);
                    return `<div class="study-chip status-${s.status}" data-id="${s.id}" title="${App.esc(s.title)}">
                        <span class="chip-line1">${App.esc(time)} ${s.level}단계</span>
                        <span class="chip-line2">${App.esc(nick)}</span>
                    </div>`;
                }).join('');
            },
        }).mount();

        loadSessions();
    }

    function extractHost(title) {
        const m = title.match(/단계\s+(.+?)님의/) || title.match(/\]\s*(.+?)님의/);
        return m ? m[1] : '';
    }

    // ── 데이터 로드 ──

    async function loadSessions() {
        const { year, month } = cal.getMonth();
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;
        const r = await App.get(API + 'admin_study_sessions', { cohort_id: cohortId, month: monthStr });
        sessions = r.success ? (r.sessions || []) : [];
        cal.setEvents(sessions.map(s => ({ ...s, date: s.study_date }))).render();
    }

    async function loadGroups() {
        if (groups.length) return;
        const r = await App.get(API + 'admin_study_groups', { cohort_id: cohortId });
        groups = r.success ? (r.groups || []) : [];
    }

    // ── 생성 모달 ──

    async function openCreateModal() {
        await loadGroups();

        const groupOpts = groups.map(g => `<option value="${g.id}">${App.esc(g.name)}</option>`).join('');
        const hourOpts = Array.from({ length: 18 }, (_, i) => i + 6)
            .map(h => `<option value="${String(h).padStart(2, '0')}">${String(h).padStart(2, '0')}시</option>`).join('');

        const body = `
            <div class="form-group">
                <label class="form-label">단계</label>
                <div style="display:flex;gap:12px">
                    <label><input type="radio" name="as-level" value="1" checked> 1단계</label>
                    <label><input type="radio" name="as-level" value="2"> 2단계</label>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">날짜</label>
                <input type="date" class="form-input" id="as-date" value="${App.today()}">
            </div>
            <div class="form-group">
                <label class="form-label">시간</label>
                <div style="display:flex;gap:8px">
                    <select class="form-input" id="as-hour">${hourOpts}</select>
                    <select class="form-input" id="as-minute">
                        <option value="00">00분</option>
                        <option value="30">30분</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">조 선택</label>
                <select class="form-input" id="as-group">
                    <option value="">전체</option>
                    ${groupOpts}
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">회원 선택</label>
                <select class="form-input" id="as-member">
                    <option value="">조를 먼저 선택하세요</option>
                </select>
            </div>
        `;

        App.modal('복습스터디 예약', body, async () => {
            const level = parseInt(document.querySelector('input[name="as-level"]:checked')?.value || 1);
            const studyDate = document.getElementById('as-date').value;
            const hour = document.getElementById('as-hour').value;
            const minute = document.getElementById('as-minute').value;
            const memberId = parseInt(document.getElementById('as-member').value);

            if (!studyDate) { Toast.warning('날짜를 선택해주세요.'); return false; }
            if (!memberId) { Toast.warning('회원을 선택해주세요.'); return false; }

            const startTime = `${hour}:${minute}`;

            App.showLoading();
            const r = await App.post(API + 'admin_study_create', {
                host_member_id: memberId,
                study_date: studyDate,
                start_time: startTime,
                level,
            });
            App.hideLoading();

            if (r.success) {
                Toast.success(r.message);
                loadSessions();
                return true;
            }
            return false;
        });

        // 조 변경 시 회원 목록 갱신
        const groupSel = document.getElementById('as-group');
        const memberSel = document.getElementById('as-member');

        async function refreshMembers() {
            const gid = groupSel.value;
            const params = { cohort_id: cohortId };
            if (gid) params.group_id = gid;
            const r = await App.get(API + 'admin_study_members', params);
            const members = r.success ? (r.members || []) : [];
            memberSel.innerHTML = '<option value="">회원을 선택하세요</option>' +
                members.map(m => `<option value="${m.id}">${App.esc(m.nickname)}${m.real_name ? ` (${App.esc(m.real_name)})` : ''} — ${App.esc(m.group_name || '')}</option>`).join('');
        }

        groupSel.onchange = refreshMembers;
        // 초기 로드 (전체)
        refreshMembers();
    }

    // ── 상세 모달 ──

    async function openDetail(sessionId) {
        App.showLoading();
        const r = await App.get(API + 'admin_study_detail', { session_id: sessionId });
        App.hideLoading();
        if (!r.success) return;

        const s = r.session;
        const participants = r.participants || [];
        const canCancel = r.can_cancel;

        const startTime = (s.start_time || '').substring(0, 5);
        const endTime = (s.end_time || '').substring(0, 5);

        const statusLabels = { pending: '대기', active: '진행', cancelled: '취소됨' };

        let body = `
            <div class="form-group">
                <div style="font-size:var(--text-sm);color:var(--color-text-sub);margin-bottom:8px">
                    <strong>${App.esc(s.study_date)}</strong> ${startTime} ~ ${endTime}
                    · ${s.level}단계 · ${App.esc(statusLabels[s.status] || s.status)}
                </div>
                <div style="font-size:var(--text-sm)">
                    개설자: <strong>${App.esc(s.host_nickname)}</strong>${s.host_real_name ? ` (${App.esc(s.host_real_name)})` : ''}
                </div>
            </div>
        `;

        if (s.zoom_join_url) {
            body += `<div class="form-group"><label class="form-label">Zoom 링크</label>
                <a href="${App.esc(s.zoom_join_url)}" target="_blank" style="word-break:break-all">${App.esc(s.zoom_join_url)}</a></div>`;
        }

        if (participants.length) {
            body += `<div class="form-group"><label class="form-label">참여자 (${participants.length}명)</label>
                <div style="max-height:150px;overflow-y:auto">
                    ${participants.map(p => `<div style="font-size:var(--text-sm);padding:2px 0">${App.esc(p.nickname)}${p.real_name ? ` (${App.esc(p.real_name)})` : ''} — ${App.esc(p.group_name || '')} <span style="color:#888">${(p.scanned_at || '').substring(11, 16)}</span></div>`).join('')}
                </div></div>`;
        }

        if (canCancel) {
            body += `<div style="margin-top:12px"><button class="btn btn-danger btn-sm" id="btn-admin-study-cancel">복습스터디 취소</button></div>`;
        }

        App.modal(App.esc(s.title), body);

        if (canCancel) {
            document.getElementById('btn-admin-study-cancel').onclick = async () => {
                if (!confirm('이 복습스터디를 취소하시겠습니까?')) return;
                App.showLoading();
                const cr = await App.post(API + 'admin_study_cancel', { session_id: sessionId });
                App.hideLoading();
                if (cr.success) {
                    Toast.success(cr.message);
                    App.closeModal();
                    loadSessions();
                }
            };
        }
    }

    return { initTab };
})();
