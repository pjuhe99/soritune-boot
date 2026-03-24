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
    let highlightAdminId = null; // 본인 강의 하이라이트용 admin id

    const HOST_LABELS = { coach1: 'C1', coach2: 'C2' };
    const STAGE_LABELS = { 1: '1단계', 2: '2단계' };
    const WEEKDAY_NAMES = ['', '월', '화', '수', '목', '금', '토', '일'];

    // 이벤트 컬러 팔레트
    const EVENT_COLORS = {
        coral:  { bg: '#fee2e2', fg: '#dc2626', label: '코랄' },
        amber:  { bg: '#fef3c7', fg: '#d97706', label: '앰버' },
        violet: { bg: '#ede9fe', fg: '#7c3aed', label: '바이올렛' },
        teal:   { bg: '#ccfbf1', fg: '#0d9488', label: '틸' },
        slate:  { bg: '#f1f5f9', fg: '#475569', label: '슬레이트' },
    };

    // ══════════════════════════════════════════════════════════
    // Init
    // ══════════════════════════════════════════════════════════

    /**
     * initForAdmin(adminData, adminRole, elId, options?)
     *
     * options.highlightAdminId — 이 admin_id에 해당하는 강의 칩을 하이라이트
     *   /head, /coach 에서 본인 담당 수업 강조 시 사용
     */
    function initForAdmin(adminData, adminRole, elId, options) {
        admin = adminData;
        role = adminRole;
        containerId = elId;
        highlightAdminId = (options && options.highlightAdminId) || null;

        const container = document.getElementById(elId);
        if (!container) return;

        container.innerHTML = '<div class="lecture-container" id="lec-cal-wrap"></div>';

        const canCreate = ['operation', 'head', 'subhead1', 'subhead2'].includes(role);

        cal = CalendarUI.create(document.getElementById('lec-cal-wrap'), {
            onMonthChange: () => loadAllData(),
            chipSelector: '.lec-chip, .lec-event-chip',
            onChipClick: (e, chip) => {
                if (chip.classList.contains('lec-event-chip')) {
                    openEventDetail(parseInt(chip.dataset.id));
                } else {
                    openDetail(parseInt(chip.dataset.id));
                }
            },
            headerHtml: canCreate
                ? '<button class="btn btn-primary btn-sm" id="btn-lec-create">특강 스케줄 추가</button> <button class="btn btn-sm" id="btn-evt-create" style="margin-left:6px;background:#7c3aed;color:#fff;border:none;">이벤트 추가</button>'
                : '',
            renderChips(events) {
                return events.map(s => {
                    // 이벤트 칩
                    if (s._type === 'event') {
                        const c = EVENT_COLORS[s.color] || EVENT_COLORS.coral;
                        const timeLabel = (s.start_time || '').substring(0, 5);
                        const hostBadge = `<span class="host-badge ${s.host_account}">${HOST_LABELS[s.host_account] || ''}</span>`;
                        return `<div class="lec-event-chip" data-id="${s.id}" title="${App.esc(s.title)}" style="background:${c.bg};color:${c.fg};">${hostBadge}<span class="chip-line1">${timeLabel}</span><span class="chip-line2">${App.esc(s.title)}</span></div>`;
                    }
                    // 기존 특강 칩
                    const stageClass = `stage-${s.stage}`;
                    const zoomClass = s.zoom_status === 'failed' ? 'zoom-failed' : '';
                    const mineClass = highlightAdminId && parseInt(s.coach_admin_id) === highlightAdminId ? 'lec-chip-mine' : '';
                    const timeLabel = (s.start_time || '').substring(0, 5);
                    const stageLabel = STAGE_LABELS[s.stage] || '';
                    const hostBadge = `<span class="host-badge ${s.host_account}">${HOST_LABELS[s.host_account] || ''}</span>`;
                    const firstLine = `${timeLabel} ${stageLabel}`;
                    const coachName = App.esc(s.coach_name || '');
                    return `<div class="lec-chip ${stageClass} ${zoomClass} ${mineClass}" data-id="${s.id}" title="${App.esc(s.title)}">${hostBadge}<span class="chip-line1">${firstLine}</span><span class="chip-line2">${coachName}</span></div>`;
                }).join('');
            },
        }).mount();

        // Bind create buttons after mount
        if (canCreate) {
            const createBtn = document.getElementById('btn-lec-create');
            if (createBtn) createBtn.onclick = openCreateModal;
            const evtBtn = document.getElementById('btn-evt-create');
            if (evtBtn) evtBtn.onclick = openEventCreateModal;
        }

        loadAllData();
    }

    // ══════════════════════════════════════════════════════════
    // Data Loading
    // ══════════════════════════════════════════════════════════

    async function loadAllData() {
        const { year, month } = cal.getMonth();
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;

        const [rSess, rEvt] = await Promise.all([
            App.get(API + 'lecture_sessions', { month: monthStr }),
            App.get(API + 'lecture_events', { month: monthStr }),
        ]);

        const sessions = (rSess.success ? (rSess.sessions || []) : [])
            .map(s => ({ ...s, date: s.lecture_date, _type: 'lecture' }));
        const events = (rEvt.success ? (rEvt.events || []) : [])
            .map(e => ({ ...e, date: e.event_date, _type: 'event' }));

        cal.setEvents([...sessions, ...events]).render();
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
                    <button class="btn btn-danger btn-sm" id="btn-lec-cancel" data-schedule="${s.schedule_id}" data-date="${s.lecture_date}">이 스케줄 취소 (이 날짜 이후 세션 전체)</button>
                </div>
            `;
        }

        App.openModal(s.title || '강의 상세', body);

        // Bind events
        const retryBtn = document.getElementById('btn-zoom-retry');
        if (retryBtn) retryBtn.onclick = () => retryZoom(parseInt(retryBtn.dataset.schedule));

        const cancelBtn = document.getElementById('btn-lec-cancel');
        if (cancelBtn) cancelBtn.onclick = () => cancelSchedule(parseInt(cancelBtn.dataset.schedule), cancelBtn.dataset.date);
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
            loadAllData();
        }
    }

    async function cancelSchedule(scheduleId, fromDate) {
        const ok = await App.confirm(`이 스케줄의 ${fromDate} 이후 세션을 모두 취소하시겠습니까?\n이 작업은 되돌릴 수 없습니다.`);
        if (!ok) return;

        App.showLoading();
        const r = await App.post(API + 'lecture_schedule_cancel', { schedule_id: scheduleId, from_date: fromDate });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message || '취소되었습니다.');
            App.closeModal();
            loadAllData();
        }
    }

    // ══════════════════════════════════════════════════════════
    // Create Modal — 공용 컴포넌트 (operation / head 공유)
    // ══════════════════════════════════════════════════════════

    /**
     * openCreateModal()
     * 특강 스케줄 추가 팝업을 열고,
     * 폼 렌더링 → 상태 관리 → 유효성 검사 → submit 을 처리한다.
     * onSuccess 시 달력을 자동 갱신한다.
     */
    async function openCreateModal() {
        // ── 1) 데이터 로드 ──
        App.showLoading();
        const [coaches, cohorts] = await Promise.all([loadCoaches(), loadCohorts()]);
        App.hideLoading();

        if (!coaches.length) { Toast.warning('등록된 코치가 없습니다.'); return; }
        if (!cohorts.length) { Toast.warning('등록된 기수가 없습니다.'); return; }

        // ── 2) 폼 HTML 렌더 ──
        const body = buildCreateFormHtml(coaches, cohorts);
        App.openModal('특강 스케줄 추가', body);

        // ── 3) 이벤트 바인딩 ──
        bindCreateFormEvents(cohorts);
    }

    // ────────────────────────────────────────────────────────
    // Create Form — UI 렌더링
    // ────────────────────────────────────────────────────────

    function buildCreateFormHtml(coaches, cohorts) {
        const coachOpts = coaches
            .map(c => `<option value="${c.id}">${App.esc(c.name)}</option>`)
            .join('');

        const cohortOpts = cohorts.map(c => {
            const label = c.cohort || c.name || `기수 #${c.id}`;
            const period = c.start_date && c.end_date
                ? ` (${c.start_date} ~ ${c.end_date})`
                : '';
            return `<option value="${c.id}" data-start="${c.start_date || ''}" data-end="${c.end_date || ''}">${App.esc(label + period)}</option>`;
        }).join('');

        return `
            <form id="lec-create-form" class="lec-create-form">

                <!-- ▸ 기수 & 코치 -->
                <fieldset class="lec-form-section">
                    <legend class="lec-form-section-title">기본 정보</legend>

                    <div class="form-group">
                        <label class="form-label">수업 기수 <span class="text-danger">*</span></label>
                        <select class="form-input" id="lec-cohort" required>
                            ${cohortOpts}
                        </select>
                        <p class="lec-form-hint" id="lec-cohort-hint"></p>
                    </div>

                    <div class="form-group">
                        <label class="form-label">담당 코치 <span class="text-danger">*</span></label>
                        <select class="form-input" id="lec-coach" required>
                            <option value="">선택해주세요</option>
                            ${coachOpts}
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">단계 <span class="text-danger">*</span></label>
                        <select class="form-input" id="lec-stage" required>
                            <option value="1">1단계</option>
                            <option value="2">2단계</option>
                        </select>
                    </div>
                </fieldset>

                <!-- ▸ 스케줄 설정 -->
                <fieldset class="lec-form-section">
                    <legend class="lec-form-section-title">스케줄 설정</legend>

                    <div class="form-group">
                        <label class="form-label">반복 요일 <span class="text-danger">*</span></label>
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
                        <label class="form-label">시작 시간 <span class="text-danger">*</span></label>
                        <input type="time" class="form-input" id="lec-time" required>
                        <p class="lec-form-hint">수업 시간: 60분 (자동)</p>
                    </div>
                </fieldset>

                <!-- ▸ Zoom 설정 -->
                <fieldset class="lec-form-section">
                    <legend class="lec-form-section-title">Zoom 설정</legend>

                    <div class="form-group">
                        <label class="form-label">호스트 계정 <span class="text-danger">*</span></label>
                        <div class="lec-host-radio-group" id="lec-host-group">
                            <label class="lec-host-radio">
                                <input type="radio" name="lec-host" value="coach1" checked>
                                <span class="lec-host-radio-card">
                                    <span class="host-badge coach1" style="font-size:11px;padding:1px 6px;">Coach 1</span>
                                </span>
                            </label>
                            <label class="lec-host-radio">
                                <input type="radio" name="lec-host" value="coach2">
                                <span class="lec-host-radio-card">
                                    <span class="host-badge coach2" style="font-size:11px;padding:1px 6px;">Coach 2</span>
                                </span>
                            </label>
                        </div>
                        <p class="lec-form-hint">같은 시간·같은 호스트 계정에 기존 강의가 있으면 생성이 거부됩니다.</p>
                    </div>
                </fieldset>

                <!-- ▸ 미리보기 요약 -->
                <div class="lec-preview" id="lec-preview" style="display:none;"></div>

                <!-- ▸ 제출 -->
                <button type="submit" class="btn btn-primary btn-block btn-lg mt-md" id="lec-submit-btn">
                    스케줄 생성
                </button>
            </form>
        `;
    }

    // ────────────────────────────────────────────────────────
    // Create Form — 상태 관리 & 이벤트 바인딩
    // ────────────────────────────────────────────────────────

    function bindCreateFormEvents(cohorts) {
        // 요일 토글
        document.querySelectorAll('#lec-weekdays .lec-weekday-btn').forEach(btn => {
            btn.onclick = () => { btn.classList.toggle('selected'); updatePreview(cohorts); };
        });

        // 기수 선택 시 힌트 갱신
        const cohortSel = document.getElementById('lec-cohort');
        cohortSel.onchange = () => updateCohortHint(cohorts);
        updateCohortHint(cohorts); // 초기 표시

        // 시간/코치/단계 변경 시 미리보기 갱신
        ['lec-coach', 'lec-stage', 'lec-time'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.onchange = () => updatePreview(cohorts);
        });
        document.querySelectorAll('input[name="lec-host"]').forEach(r => {
            r.onchange = () => updatePreview(cohorts);
        });

        // 폼 제출
        document.getElementById('lec-create-form').onsubmit = (e) => {
            e.preventDefault();
            handleCreateSubmit(cohorts);
        };
    }

    /** 기수 선택 시 기간 힌트 */
    function updateCohortHint(cohorts) {
        const sel = document.getElementById('lec-cohort');
        const hint = document.getElementById('lec-cohort-hint');
        if (!sel || !hint) return;

        const opt = sel.options[sel.selectedIndex];
        const start = opt?.dataset?.start;
        const end = opt?.dataset?.end;

        if (start && end) {
            hint.textContent = `기간: ${start} ~ ${end}`;
            hint.style.display = '';
        } else {
            hint.textContent = '기수에 시작/종료일이 설정되지 않았습니다.';
            hint.className = 'lec-form-hint lec-form-hint--warn';
            hint.style.display = '';
        }

        updatePreview(cohorts);
    }

    /** 미리보기 요약 갱신 */
    function updatePreview(cohorts) {
        const preview = document.getElementById('lec-preview');
        if (!preview) return;

        const coachEl = document.getElementById('lec-coach');
        const coachName = coachEl?.options[coachEl.selectedIndex]?.text || '';
        const time = document.getElementById('lec-time')?.value || '';
        const stage = document.getElementById('lec-stage')?.value || '1';
        const host = document.querySelector('input[name="lec-host"]:checked')?.value || 'coach1';

        const weekdays = [];
        document.querySelectorAll('#lec-weekdays .lec-weekday-btn.selected').forEach(btn => {
            weekdays.push(parseInt(btn.dataset.wd));
        });

        if (!coachEl?.value || !time || !weekdays.length) {
            preview.style.display = 'none';
            return;
        }

        const wdLabels = weekdays.map(w => WEEKDAY_NAMES[w]).join(', ');
        const hostLabel = host === 'coach1' ? 'Coach 1' : 'Coach 2';

        // 세션 수 계산
        const cohortSel = document.getElementById('lec-cohort');
        const opt = cohortSel?.options[cohortSel.selectedIndex];
        const startDate = opt?.dataset?.start;
        const endDate = opt?.dataset?.end;
        let sessionCount = '';
        if (startDate && endDate) {
            const count = countSessionDates(startDate, endDate, weekdays);
            sessionCount = ` / 총 <strong>${count}회</strong> 생성 예정`;
        }

        preview.innerHTML = `
            <div class="lec-preview-title">생성 미리보기</div>
            <div class="lec-preview-body">
                <span>[${App.esc(time)}]</span>
                <span>${App.esc(coachName)}</span>
                <span>${App.esc(stage)}단계 강의</span>
                <span class="host-badge ${host}" style="font-size:10px;padding:0 4px;">${App.esc(hostLabel)}</span>
            </div>
            <div class="lec-preview-sub">매주 ${App.esc(wdLabels)}${sessionCount}</div>
        `;
        preview.style.display = '';
    }

    /** 클라이언트 세션 수 계산 (미리보기용) */
    function countSessionDates(startStr, endStr, weekdays) {
        const start = new Date(startStr + 'T00:00:00');
        const end = new Date(endStr + 'T00:00:00');
        let count = 0;
        const cur = new Date(start);
        while (cur <= end) {
            // JS getDay: 0=Sun, ISO: 1=Mon..7=Sun
            const iso = cur.getDay() === 0 ? 7 : cur.getDay();
            if (weekdays.includes(iso)) count++;
            cur.setDate(cur.getDate() + 1);
        }
        return count;
    }

    // ────────────────────────────────────────────────────────
    // Create Form — 유효성 검사
    // ────────────────────────────────────────────────────────

    function validateCreateForm() {
        const cohortId = parseInt(document.getElementById('lec-cohort')?.value || '0');
        const coachId = parseInt(document.getElementById('lec-coach')?.value || '0');
        const stage = parseInt(document.getElementById('lec-stage')?.value || '0');
        const time = document.getElementById('lec-time')?.value || '';
        const host = document.querySelector('input[name="lec-host"]:checked')?.value || '';

        const weekdays = [];
        document.querySelectorAll('#lec-weekdays .lec-weekday-btn.selected').forEach(btn => {
            weekdays.push(parseInt(btn.dataset.wd));
        });

        if (!cohortId) return { ok: false, msg: '수업 기수를 선택해주세요.' };
        if (!coachId)  return { ok: false, msg: '담당 코치를 선택해주세요.' };
        if (!stage)    return { ok: false, msg: '단계를 선택해주세요.' };
        if (!weekdays.length) return { ok: false, msg: '반복 요일을 1개 이상 선택해주세요.' };
        if (!time)     return { ok: false, msg: '시작 시간을 입력해주세요.' };
        if (!host)     return { ok: false, msg: '호스트 계정을 선택해주세요.' };

        // cohort 기간 확인
        const cohortSel = document.getElementById('lec-cohort');
        const opt = cohortSel?.options[cohortSel.selectedIndex];
        if (!opt?.dataset?.start || !opt?.dataset?.end) {
            return { ok: false, msg: '선택한 기수에 시작/종료일이 설정되어 있지 않습니다. 기수 관리에서 먼저 기간을 설정해주세요.' };
        }

        const count = countSessionDates(opt.dataset.start, opt.dataset.end, weekdays);
        if (count === 0) {
            return { ok: false, msg: '선택한 요일에 해당하는 날짜가 기수 기간 내에 없습니다.' };
        }

        return {
            ok: true,
            payload: {
                cohort_id: cohortId,
                coach_admin_id: coachId,
                stage,
                weekdays,
                start_time: time,
                host_account: host,
            },
        };
    }

    // ────────────────────────────────────────────────────────
    // Create Form — 제출 처리
    // ────────────────────────────────────────────────────────

    async function handleCreateSubmit() {
        const { ok, msg, payload } = validateCreateForm();
        if (!ok) { Toast.warning(msg); return; }

        const btn = document.getElementById('lec-submit-btn');
        if (!btn || btn.disabled) return;

        // 버튼 로딩 상태
        btn.disabled = true;
        const origText = btn.textContent;
        btn.innerHTML = '<span class="spinner-inline"></span> 생성 중…';

        const r = await App.post(API + 'lecture_schedule_create', payload);

        btn.disabled = false;
        btn.textContent = origText;

        if (r.success) {
            const sessionCount = r.session_count || '';
            const extra = sessionCount ? ` (${sessionCount}개 세션)` : '';
            Toast.success((r.message || '강의 스케줄이 생성되었습니다.') + extra);
            App.closeModal();
            coachesCache = null; // 다음 생성 시 최신 목록
            loadAllData();
        }
    }

    // ══════════════════════════════════════════════════════════
    // Event Create Modal
    // ══════════════════════════════════════════════════════════

    async function openEventCreateModal() {
        App.showLoading();
        const [coaches, cohorts] = await Promise.all([loadCoaches(), loadCohorts()]);
        App.hideLoading();

        if (!coaches.length) { Toast.warning('등록된 코치가 없습니다.'); return; }
        if (!cohorts.length) { Toast.warning('등록된 기수가 없습니다.'); return; }

        const body = buildEventCreateFormHtml(coaches, cohorts);
        App.openModal('이벤트 추가', body);
        bindEventCreateFormEvents();
    }

    function buildEventCreateFormHtml(coaches, cohorts) {
        const coachOpts = coaches
            .map(c => `<option value="${c.id}">${App.esc(c.name)}</option>`)
            .join('');

        const cohortOpts = cohorts.map(c => {
            const label = c.cohort || c.name || `기수 #${c.id}`;
            const period = c.start_date && c.end_date
                ? ` (${c.start_date} ~ ${c.end_date})`
                : '';
            return `<option value="${c.id}">${App.esc(label + period)}</option>`;
        }).join('');

        const colorChips = Object.entries(EVENT_COLORS).map(([key, c], i) =>
            `<label class="lec-color-radio">
                <input type="radio" name="evt-color" value="${key}" ${i === 0 ? 'checked' : ''}>
                <span class="lec-color-chip" style="background:${c.bg};color:${c.fg};border-color:${c.fg};">${App.esc(c.label)}</span>
            </label>`
        ).join('');

        return `
            <form id="evt-create-form" class="lec-create-form">

                <!-- ▸ 기본 정보 -->
                <fieldset class="lec-form-section">
                    <legend class="lec-form-section-title">기본 정보</legend>

                    <div class="form-group">
                        <label class="form-label">제목 <span class="text-danger">*</span></label>
                        <input type="text" class="form-input" id="evt-title" maxlength="200" placeholder="이벤트 제목을 입력하세요" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">수업 기수 <span class="text-danger">*</span></label>
                        <select class="form-input" id="evt-cohort" required>
                            ${cohortOpts}
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">담당 코치 <span class="text-danger">*</span></label>
                        <select class="form-input" id="evt-coach" required>
                            <option value="">선택해주세요</option>
                            ${coachOpts}
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">단계 <span class="text-danger">*</span></label>
                        <select class="form-input" id="evt-stage" required>
                            <option value="1">1단계</option>
                            <option value="2">2단계</option>
                        </select>
                    </div>
                </fieldset>

                <!-- ▸ 스케줄 설정 -->
                <fieldset class="lec-form-section">
                    <legend class="lec-form-section-title">스케줄 설정</legend>

                    <div class="form-group">
                        <label class="form-label">날짜 <span class="text-danger">*</span></label>
                        <input type="date" class="form-input" id="evt-date" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">시작 시간 <span class="text-danger">*</span></label>
                        <input type="time" class="form-input" id="evt-time" required>
                        <p class="lec-form-hint">수업 시간: 60분 (자동)</p>
                    </div>
                </fieldset>

                <!-- ▸ 컬러 선택 -->
                <fieldset class="lec-form-section">
                    <legend class="lec-form-section-title">캘린더 색상</legend>
                    <div class="lec-color-group">
                        ${colorChips}
                    </div>
                </fieldset>

                <!-- ▸ Zoom 설정 -->
                <fieldset class="lec-form-section">
                    <legend class="lec-form-section-title">Zoom 설정</legend>

                    <div class="form-group">
                        <label class="form-label">호스트 계정 <span class="text-danger">*</span></label>
                        <div class="lec-host-radio-group" id="evt-host-group">
                            <label class="lec-host-radio">
                                <input type="radio" name="evt-host" value="coach1" checked>
                                <span class="lec-host-radio-card">
                                    <span class="host-badge coach1" style="font-size:11px;padding:1px 6px;">Coach 1</span>
                                </span>
                            </label>
                            <label class="lec-host-radio">
                                <input type="radio" name="evt-host" value="coach2">
                                <span class="lec-host-radio-card">
                                    <span class="host-badge coach2" style="font-size:11px;padding:1px 6px;">Coach 2</span>
                                </span>
                            </label>
                        </div>
                        <p class="lec-form-hint">선택한 호스트 계정으로 Zoom 미팅이 새로 생성됩니다.</p>
                    </div>
                </fieldset>

                <!-- ▸ 미리보기 -->
                <div class="lec-preview" id="evt-preview" style="display:none;"></div>

                <!-- ▸ 제출 -->
                <button type="submit" class="btn btn-primary btn-block btn-lg mt-md" id="evt-submit-btn">
                    이벤트 생성
                </button>
            </form>
        `;
    }

    function bindEventCreateFormEvents() {
        ['evt-title', 'evt-coach', 'evt-stage', 'evt-date', 'evt-time'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', updateEventPreview);
            if (el) el.addEventListener('change', updateEventPreview);
        });
        document.querySelectorAll('input[name="evt-color"]').forEach(r => {
            r.onchange = updateEventPreview;
        });
        document.querySelectorAll('input[name="evt-host"]').forEach(r => {
            r.onchange = updateEventPreview;
        });

        document.getElementById('evt-create-form').onsubmit = (e) => {
            e.preventDefault();
            handleEventCreateSubmit();
        };
    }

    function updateEventPreview() {
        const preview = document.getElementById('evt-preview');
        if (!preview) return;

        const title = document.getElementById('evt-title')?.value?.trim() || '';
        const date = document.getElementById('evt-date')?.value || '';
        const time = document.getElementById('evt-time')?.value || '';
        const coachEl = document.getElementById('evt-coach');
        const coachName = coachEl?.options[coachEl.selectedIndex]?.text || '';
        const stage = document.getElementById('evt-stage')?.value || '1';
        const color = document.querySelector('input[name="evt-color"]:checked')?.value || 'coral';
        const host = document.querySelector('input[name="evt-host"]:checked')?.value || 'coach1';

        if (!title || !date || !time || !coachEl?.value) {
            preview.style.display = 'none';
            return;
        }

        const c = EVENT_COLORS[color] || EVENT_COLORS.coral;
        const hostLabel = host === 'coach1' ? 'Coach 1' : 'Coach 2';

        preview.innerHTML = `
            <div class="lec-preview-title">생성 미리보기</div>
            <div class="lec-preview-body">
                <span class="lec-event-chip-preview" style="background:${c.bg};color:${c.fg};padding:2px 8px;border-radius:3px;font-weight:600;">${App.esc(title)}</span>
                <span class="host-badge ${host}" style="font-size:10px;padding:0 4px;">${App.esc(hostLabel)}</span>
            </div>
            <div class="lec-preview-sub">${App.esc(date)} ${App.esc(time)} / ${App.esc(coachName)} / ${App.esc(stage)}단계</div>
        `;
        preview.style.display = '';
    }

    function validateEventCreateForm() {
        const title    = document.getElementById('evt-title')?.value?.trim() || '';
        const cohortId = parseInt(document.getElementById('evt-cohort')?.value || '0');
        const coachId  = parseInt(document.getElementById('evt-coach')?.value || '0');
        const stage    = parseInt(document.getElementById('evt-stage')?.value || '0');
        const date     = document.getElementById('evt-date')?.value || '';
        const time     = document.getElementById('evt-time')?.value || '';
        const color    = document.querySelector('input[name="evt-color"]:checked')?.value || '';
        const host     = document.querySelector('input[name="evt-host"]:checked')?.value || '';

        if (!title)    return { ok: false, msg: '제목을 입력해주세요.' };
        if (!cohortId) return { ok: false, msg: '수업 기수를 선택해주세요.' };
        if (!coachId)  return { ok: false, msg: '담당 코치를 선택해주세요.' };
        if (!stage)    return { ok: false, msg: '단계를 선택해주세요.' };
        if (!date)     return { ok: false, msg: '날짜를 선택해주세요.' };
        if (!time)     return { ok: false, msg: '시작 시간을 입력해주세요.' };
        if (!color)    return { ok: false, msg: '색상을 선택해주세요.' };
        if (!host)     return { ok: false, msg: '호스트 계정을 선택해주세요.' };

        return {
            ok: true,
            payload: {
                title,
                cohort_id: cohortId,
                coach_admin_id: coachId,
                stage,
                event_date: date,
                start_time: time,
                color,
                host_account: host,
            },
        };
    }

    async function handleEventCreateSubmit() {
        const { ok, msg, payload } = validateEventCreateForm();
        if (!ok) { Toast.warning(msg); return; }

        const btn = document.getElementById('evt-submit-btn');
        if (!btn || btn.disabled) return;

        btn.disabled = true;
        const origText = btn.textContent;
        btn.innerHTML = '<span class="spinner-inline"></span> 생성 중…';

        const r = await App.post(API + 'lecture_event_create', payload);

        btn.disabled = false;
        btn.textContent = origText;

        if (r.success) {
            Toast.success(r.message || '이벤트가 생성되었습니다.');
            App.closeModal();
            loadAllData();
        }
    }

    // ══════════════════════════════════════════════════════════
    // Event Detail Modal
    // ══════════════════════════════════════════════════════════

    async function openEventDetail(eventId) {
        App.showLoading();
        const r = await App.get(API + 'lecture_event_detail', { event_id: eventId });
        App.hideLoading();
        if (!r.success) return;

        const ev = r.event;
        const dateKo = App.formatDateKo(ev.event_date);
        const timeLabel = (ev.start_time || '').substring(0, 5) + ' ~ ' + (ev.end_time || '').substring(0, 5);
        const hostLabel = ev.host_account === 'coach1' ? 'Coach 1' : 'Coach 2';
        const stageLabel = STAGE_LABELS[ev.stage] || ev.stage;
        const c = EVENT_COLORS[ev.color] || EVENT_COLORS.coral;

        let body = `
            <div style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;background:${c.bg};color:${c.fg};margin-bottom:12px;">${App.esc(c.label)}</div>
            <div class="lec-detail-info">
                <div class="lec-detail-row"><span class="lec-detail-label">날짜</span><span class="lec-detail-value">${dateKo}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">시간</span><span class="lec-detail-value">${App.esc(timeLabel)}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">코치</span><span class="lec-detail-value">${App.esc(ev.coach_name)}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">단계</span><span class="lec-detail-value">${App.esc(stageLabel)}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">호스트</span><span class="lec-detail-value"><span class="host-badge ${ev.host_account}" style="font-size:11px;padding:1px 6px;">${App.esc(hostLabel)}</span></span></div>
            </div>
        `;

        // Zoom section
        if (ev.zoom_status === 'ready' && ev.zoom_join_url) {
            body += `
                <div class="lec-detail-actions">
                    <a href="${App.esc(ev.zoom_join_url)}" target="_blank" class="lec-btn-zoom">Zoom 입장</a>
                    <button class="lec-btn-copy" onclick="LectureApp._copyZoom('${App.esc(ev.zoom_join_url)}')">Zoom 링크 복사</button>
            `;
            if (ev.zoom_password) {
                body += `<div class="lec-host-guide">Zoom 비밀번호: <strong>${App.esc(ev.zoom_password)}</strong></div>`;
            }
            body += `<div class="lec-host-guide">호스트 계정: <strong>${App.esc(hostLabel)}</strong> 계정으로 Zoom이 생성되었습니다.</div>`;
            body += `</div>`;
        } else if (ev.zoom_status === 'failed') {
            body += `<div class="lec-notice warning">Zoom 생성에 실패했습니다.${ev.zoom_error_message ? ' (' + App.esc(ev.zoom_error_message) + ')' : ''}</div>`;
            if (['operation', 'head', 'subhead1', 'subhead2'].includes(role)) {
                body += `<button class="btn btn-primary btn-sm mt-sm" id="btn-evt-zoom-retry" data-event="${ev.id}">Zoom 재생성</button>`;
            }
        } else if (ev.zoom_status === 'pending') {
            body += `<div class="lec-notice muted">Zoom 생성 대기 중입니다.</div>`;
            if (['operation', 'head', 'subhead1', 'subhead2'].includes(role)) {
                body += `<button class="btn btn-primary btn-sm mt-sm" id="btn-evt-zoom-retry" data-event="${ev.id}">Zoom 재시도</button>`;
            }
        }

        // Cancel button
        const canCancel = ['operation', 'head', 'subhead1', 'subhead2'].includes(role);
        if (canCancel) {
            body += `
                <div class="lec-detail-cancel-area">
                    <button class="btn btn-danger btn-sm" id="btn-evt-cancel" data-event="${ev.id}">이벤트 취소</button>
                </div>
            `;
        }

        App.openModal(ev.title || '이벤트 상세', body);

        const retryBtn = document.getElementById('btn-evt-zoom-retry');
        if (retryBtn) retryBtn.onclick = () => retryEventZoom(parseInt(retryBtn.dataset.event));

        const cancelBtn = document.getElementById('btn-evt-cancel');
        if (cancelBtn) cancelBtn.onclick = () => cancelEvent(parseInt(cancelBtn.dataset.event));
    }

    async function retryEventZoom(eventId) {
        App.showLoading();
        const r = await App.post(API + 'lecture_event_zoom_retry', { event_id: eventId });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message || 'Zoom이 생성되었습니다.');
            App.closeModal();
            loadAllData();
        }
    }

    async function cancelEvent(eventId) {
        const ok = await App.confirm('이 이벤트를 취소하시겠습니까?\n이 작업은 되돌릴 수 없습니다.');
        if (!ok) return;

        App.showLoading();
        const r = await App.post(API + 'lecture_event_cancel', { event_id: eventId });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message || '이벤트가 취소되었습니다.');
            App.closeModal();
            loadAllData();
        }
    }

    // ══════════════════════════════════════════════════════════
    // Public API
    // ══════════════════════════════════════════════════════════

    return {
        initForAdmin,
        _copyZoom,
    };
})();
