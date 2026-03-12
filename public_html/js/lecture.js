/* ══════════════════════════════════════════════════════════════
   LectureApp — 코치 강의 달력 & 관리
   CalendarUI 공통 컴포넌트 사용
   ══════════════════════════════════════════════════════════════ */
const LectureApp = (() => {
    const API = '/api/bootcamp.php?action=';

    let cal = null;
    let admin = null;
    let role = null;
    let containerId = null;
    let coachesCache = null;
    let cohortsCache = null;

    const HOST_LABELS = { coach1: 'C1', coach2: 'C2' };
    const STAGE_LABELS = { 1: '1단계', 2: '2단계' };
    const WEEKDAY_NAMES = ['', '월', '화', '수', '목', '금', '토', '일'];

    // ══════════════════════════════════════════════════════════
    // Init
    // ══════════════════════════════════════════════════════════

    function initForAdmin(adminData, adminRole, elId) {
        admin = adminData;
        role = adminRole;
        containerId = elId;

        const container = document.getElementById(elId);
        if (!container) return;

        container.innerHTML = '<div class="lecture-container" id="lec-cal-wrap"></div>';

        const canCreate = ['operation', 'head', 'subhead1', 'subhead2'].includes(role);

        cal = CalendarUI.create(document.getElementById('lec-cal-wrap'), {
            onMonthChange: () => loadSessions(),
            chipSelector: '.lec-chip',
            onChipClick: (e, chip) => openDetail(parseInt(chip.dataset.id)),
            headerHtml: canCreate
                ? '<button class="btn btn-primary btn-sm" id="btn-lec-create">강의 스케줄 추가</button>'
                : '',
            renderChips(events) {
                return events.map(s => {
                    const stageClass = `stage-${s.stage}`;
                    const zoomClass = s.zoom_status === 'failed' ? 'zoom-failed' : '';
                    const timeLabel = (s.start_time || '').substring(0, 5);
                    const hostBadge = `<span class="host-badge ${s.host_account}">${HOST_LABELS[s.host_account] || ''}</span>`;
                    const label = `${timeLabel} ${App.esc(s.coach_name || '')}`;
                    return `<div class="lec-chip ${stageClass} ${zoomClass}" data-id="${s.id}" title="${App.esc(s.title)}">${hostBadge}<span>${label}</span></div>`;
                }).join('');
            },
        }).mount();

        // Bind create button after mount
        if (canCreate) {
            const createBtn = document.getElementById('btn-lec-create');
            if (createBtn) createBtn.onclick = openCreateModal;
        }

        loadSessions();
    }

    // ══════════════════════════════════════════════════════════
    // Data Loading
    // ══════════════════════════════════════════════════════════

    async function loadSessions() {
        const { year, month } = cal.getMonth();
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;
        const r = await App.get(API + 'lecture_sessions', { month: monthStr });
        const sessions = r.success ? (r.sessions || []) : [];
        cal.setEvents(sessions.map(s => ({ ...s, date: s.lecture_date }))).render();
    }

    async function loadCoaches() {
        if (coachesCache) return coachesCache;
        const r = await App.get(API + 'lecture_coaches');
        coachesCache = r.success ? (r.coaches || []) : [];
        return coachesCache;
    }

    async function loadCohorts() {
        if (cohortsCache) return cohortsCache;
        const r = await App.get(API + 'cohorts');
        cohortsCache = r.success ? (r.cohorts || []) : [];
        return cohortsCache;
    }

    // ══════════════════════════════════════════════════════════
    // Detail Modal
    // ══════════════════════════════════════════════════════════

    async function openDetail(sessionId) {
        App.showLoading();
        const r = await App.get(API + 'lecture_session_detail', { session_id: sessionId });
        App.hideLoading();
        if (!r.success) return;

        const s = r.session;
        const dateKo = App.formatDateKo(s.lecture_date);
        const timeLabel = (s.start_time || '').substring(0, 5) + ' ~ ' + (s.end_time || '').substring(0, 5);
        const hostLabel = s.host_account === 'coach1' ? 'Coach 1' : 'Coach 2';
        const stageLabel = STAGE_LABELS[s.stage] || s.stage;

        let body = `
            <div class="lec-detail-info">
                <div class="lec-detail-row"><span class="lec-detail-label">날짜</span><span class="lec-detail-value">${dateKo}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">시간</span><span class="lec-detail-value">${App.esc(timeLabel)}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">코치</span><span class="lec-detail-value">${App.esc(s.coach_name)}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">단계</span><span class="lec-detail-value">${App.esc(stageLabel)}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">호스트</span><span class="lec-detail-value"><span class="host-badge ${s.host_account}" style="font-size:11px;padding:1px 6px;">${App.esc(hostLabel)}</span></span></div>
            </div>
        `;

        // Zoom section
        if (s.zoom_status === 'ready' && s.zoom_join_url) {
            body += `
                <div class="lec-detail-actions">
                    <a href="${App.esc(s.zoom_join_url)}" target="_blank" class="lec-btn-zoom">Zoom 입장</a>
                    <button class="lec-btn-copy" onclick="LectureApp._copyZoom('${App.esc(s.zoom_join_url)}')">Zoom 링크 복사</button>
            `;
            if (s.zoom_start_url) {
                body += `<a href="${App.esc(s.zoom_start_url)}" target="_blank" class="lec-btn-zoom-host">호스트로 입장 (시작)</a>`;
            }
            if (s.zoom_password) {
                body += `<div class="lec-host-guide">Zoom 비밀번호: <strong>${App.esc(s.zoom_password)}</strong></div>`;
            }
            body += `<div class="lec-host-guide">호스트 계정: <strong>${App.esc(hostLabel)}</strong> 계정으로 Zoom이 생성되었습니다.</div>`;
            body += `</div>`;
        } else if (s.zoom_status === 'failed') {
            body += `<div class="lec-notice warning">Zoom 생성에 실패했습니다.${s.zoom_error_message ? ' (' + App.esc(s.zoom_error_message) + ')' : ''}</div>`;
            if (['operation', 'head', 'subhead1', 'subhead2'].includes(role)) {
                body += `<button class="btn btn-primary btn-sm mt-sm" id="btn-zoom-retry" data-schedule="${s.schedule_id}">Zoom 재생성</button>`;
            }
        } else if (s.zoom_status === 'pending') {
            body += `<div class="lec-notice muted">Zoom 생성 대기 중입니다.</div>`;
        }

        // Cancel button (admin only)
        const canCancel = ['operation', 'head', 'subhead1', 'subhead2'].includes(role);
        if (canCancel) {
            body += `
                <div class="lec-detail-cancel-area">
                    <button class="btn btn-danger btn-sm" id="btn-lec-cancel" data-schedule="${s.schedule_id}">이 스케줄 취소 (미래 세션 전체)</button>
                </div>
            `;
        }

        App.openModal(s.title || '강의 상세', body);

        // Bind events
        const retryBtn = document.getElementById('btn-zoom-retry');
        if (retryBtn) retryBtn.onclick = () => retryZoom(parseInt(retryBtn.dataset.schedule));

        const cancelBtn = document.getElementById('btn-lec-cancel');
        if (cancelBtn) cancelBtn.onclick = () => cancelSchedule(parseInt(cancelBtn.dataset.schedule));
    }

    function _copyZoom(url) {
        navigator.clipboard.writeText(url).then(() => {
            Toast.success('Zoom 링크가 복사되었습니다.');
        }).catch(() => {
            // Fallback
            const ta = document.createElement('textarea');
            ta.value = url;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            Toast.success('Zoom 링크가 복사되었습니다.');
        });
    }

    async function retryZoom(scheduleId) {
        App.showLoading();
        const r = await App.post(API + 'lecture_zoom_retry', { schedule_id: scheduleId });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message || 'Zoom이 생성되었습니다.');
            App.closeModal();
            loadSessions();
        }
    }

    async function cancelSchedule(scheduleId) {
        const ok = await App.confirm('이 스케줄의 미래 세션을 모두 취소하시겠습니까?\n이 작업은 되돌릴 수 없습니다.');
        if (!ok) return;

        App.showLoading();
        const r = await App.post(API + 'lecture_schedule_cancel', { schedule_id: scheduleId });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message || '취소되었습니다.');
            App.closeModal();
            loadSessions();
        }
    }

    // ══════════════════════════════════════════════════════════
    // Create Modal
    // ══════════════════════════════════════════════════════════

    async function openCreateModal() {
        App.showLoading();
        const [coaches, cohorts] = await Promise.all([loadCoaches(), loadCohorts()]);
        App.hideLoading();

        const coachOpts = coaches.map(c => `<option value="${c.id}">${App.esc(c.name)}</option>`).join('');
        const cohortOpts = cohorts.map(c => `<option value="${c.id}">${App.esc(c.name || c.id)}</option>`).join('');

        const body = `
            <form id="lec-create-form">
                <div class="form-group">
                    <label class="form-label">기수</label>
                    <select class="form-input" id="lec-cohort" required>${cohortOpts}</select>
                </div>
                <div class="form-group">
                    <label class="form-label">담당 코치</label>
                    <select class="form-input" id="lec-coach" required>
                        <option value="">선택</option>${coachOpts}
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">단계</label>
                    <select class="form-input" id="lec-stage" required>
                        <option value="1">1단계</option>
                        <option value="2">2단계</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">요일 선택</label>
                    <div class="lec-weekday-group" id="lec-weekdays">
                        <button type="button" class="lec-weekday-btn" data-wd="1">월</button>
                        <button type="button" class="lec-weekday-btn" data-wd="2">화</button>
                        <button type="button" class="lec-weekday-btn" data-wd="3">수</button>
                        <button type="button" class="lec-weekday-btn" data-wd="4">목</button>
                        <button type="button" class="lec-weekday-btn" data-wd="5">금</button>
                        <button type="button" class="lec-weekday-btn" data-wd="6">토</button>
                        <button type="button" class="lec-weekday-btn" data-wd="7">일</button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">시작 시간</label>
                    <input type="time" class="form-input" id="lec-time" required>
                </div>
                <div class="form-group">
                    <label class="form-label">호스트 계정</label>
                    <select class="form-input" id="lec-host" required>
                        <option value="coach1">Coach 1</option>
                        <option value="coach2">Coach 2</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-block mt-md">스케줄 생성</button>
            </form>
        `;

        App.openModal('강의 스케줄 추가', body);

        // Weekday toggle
        document.querySelectorAll('#lec-weekdays .lec-weekday-btn').forEach(btn => {
            btn.onclick = () => btn.classList.toggle('selected');
        });

        document.getElementById('lec-create-form').onsubmit = async (e) => {
            e.preventDefault();
            const weekdays = [];
            document.querySelectorAll('#lec-weekdays .lec-weekday-btn.selected').forEach(btn => {
                weekdays.push(parseInt(btn.dataset.wd));
            });
            if (weekdays.length === 0) {
                Toast.warning('요일을 1개 이상 선택해주세요.');
                return;
            }

            const payload = {
                cohort_id: parseInt(document.getElementById('lec-cohort').value),
                coach_admin_id: parseInt(document.getElementById('lec-coach').value),
                stage: parseInt(document.getElementById('lec-stage').value),
                weekdays,
                start_time: document.getElementById('lec-time').value,
                host_account: document.getElementById('lec-host').value,
            };

            if (!payload.coach_admin_id) {
                Toast.warning('담당 코치를 선택해주세요.');
                return;
            }
            if (!payload.start_time) {
                Toast.warning('시작 시간을 입력해주세요.');
                return;
            }

            App.showLoading();
            const r = await App.post(API + 'lecture_schedule_create', payload);
            App.hideLoading();

            if (r.success) {
                Toast.success(r.message || '강의 스케줄이 생성되었습니다.');
                App.closeModal();
                loadSessions();
            }
        };
    }

    // ══════════════════════════════════════════════════════════
    // Public API
    // ══════════════════════════════════════════════════════════

    return {
        initForAdmin,
        _copyZoom,
    };
})();
