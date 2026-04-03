/* ══════════════════════════════════════════════════════════════
   MemberCalendar — 캘린더 탭 (복습스터디 + 코치 강의 통합 달력)
   역할: 필터 칩 UI + 데이터 로드 + normalize + filter + 칩 렌더링
   상세 모달은 member-calendar-detail.js에 위임
   ══════════════════════════════════════════════════════════════ */
const MemberCalendar = (() => {
    const API = '/api/bootcamp.php?action=';

    let cal = null;
    let mounted = false;
    let allEvents = [];
    let activeFilter = 'all';

    MemberTabs.register('calendar', { mount, unmount });

    // ══════════════════════════════════════════════════════════
    // Mount / Unmount
    // ══════════════════════════════════════════════════════════

    function mount(panel, member) {
        if (mounted) return;
        mounted = true;
        activeFilter = 'all';

        panel.innerHTML = `
            <div class="member-cal-section">
                <div class="member-cal-toolbar">
                    <div class="member-legend">
                        <span class="member-legend-item"><span class="member-legend-dot member-legend-study"></span>복습스터디</span>
                        <span class="member-legend-item"><span class="member-legend-dot member-legend-lecture"></span>코치 강의</span>
                        <span class="member-legend-item"><span class="member-legend-dot member-legend-event"></span>이벤트</span>
                    </div>
                    <div class="member-filter-chips" id="member-filter-chips">
                        <button class="filter-chip active" data-stage="all">전체</button>
                        <button class="filter-chip" data-stage="1">1단계</button>
                        <button class="filter-chip" data-stage="2">2단계</button>
                    </div>
                    <button class="btn btn-primary btn-sm" id="btn-cal-create-study" style="white-space:nowrap">복습스터디 예약</button>
                </div>
                <div id="member-cal-container"></div>
            </div>
        `;

        MemberUtils.bindFilterChips('member-filter-chips', 'stage', (stage) => {
            activeFilter = stage;
            MemberUtils.logEvent('click_calendar_stage_filter', stage);
            applyFilterAndRender();
        });
        MemberUtils.logEvent('open_tab_calendar');

        document.getElementById('btn-cal-create-study').onclick = () => {
            StudyCreate.open({
                onCreated() { loadData(); },
            });
        };

        cal = CalendarUI.create(document.getElementById('member-cal-container'), {
            onMonthChange: () => loadData(),
            chipSelector: '.member-chip, .member-chip-event',
            onChipClick: (e, chip) => {
                const id = parseInt(chip.dataset.id);
                if (!isNaN(id)) MemberCalendarDetail.open(chip.dataset.type, id);
            },
            renderChips: (events) => events.map(ev => renderChip(ev)).join(''),
        }).mount();

        loadData();
    }

    function unmount() {
        mounted = false;
        cal = null;
        allEvents = [];
    }

    // ══════════════════════════════════════════════════════════
    // Data: Load → Normalize → Filter → Render
    // ══════════════════════════════════════════════════════════

    async function loadData() {
        if (!cal) return;
        const { year, month } = cal.getMonth();
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;

        const [studyRes, lectureRes, eventRes] = await Promise.all([
            App.get(API + 'study_sessions', { month: monthStr }),
            App.get(API + 'lecture_sessions', { month: monthStr }),
            App.get(API + 'lecture_events', { month: monthStr }),
        ]);

        allEvents = [];

        if (studyRes.success) {
            (studyRes.sessions || []).forEach(s => {
                allEvents.push({ ...s, _type: 'study', date: s.study_date, stage: parseInt(s.level) || null });
            });
        }
        if (lectureRes.success) {
            (lectureRes.sessions || []).forEach(s => {
                allEvents.push({ ...s, _type: 'lecture', date: s.lecture_date, stage: parseInt(s.stage) || null });
            });
        }
        if (eventRes.success) {
            (eventRes.events || []).forEach(e => {
                allEvents.push({ ...e, _type: 'event', date: e.event_date, stage: parseInt(e.stage) || null });
            });
        }

        applyFilterAndRender();
    }

    function applyFilterAndRender() {
        if (!cal) return;
        const filtered = activeFilter === 'all'
            ? allEvents
            : allEvents.filter(ev => ev.stage === null || ev.stage === parseInt(activeFilter));
        cal.setEvents(filtered).render();
    }

    // ══════════════════════════════════════════════════════════
    // Chip Rendering — study/lecture 공통 함수
    // ══════════════════════════════════════════════════════════

    // 이벤트 컬러 팔레트 (lecture.js와 동일)
    const EVENT_COLORS = {
        coral:  { bg: '#fee2e2', fg: '#dc2626' },
        amber:  { bg: '#fef3c7', fg: '#d97706' },
        violet: { bg: '#ede9fe', fg: '#7c3aed' },
        teal:   { bg: '#ccfbf1', fg: '#0d9488' },
        slate:  { bg: '#f1f5f9', fg: '#475569' },
    };

    function renderChip(ev) {
        const timeLabel = (ev.start_time || '').substring(0, 5);
        const todayClass = ev.date === App.today() ? ' member-chip-today' : '';

        // 이벤트 칩 (별도 스타일)
        if (ev._type === 'event') {
            const c = EVENT_COLORS[ev.color] || EVENT_COLORS.coral;
            return `<div class="member-chip-event${todayClass}" data-type="event" data-id="${ev.id}" title="${App.esc(ev.title)}" style="background:${c.bg};color:${c.fg};"><span class="chip-line1">${App.esc(timeLabel)}</span><span class="chip-line2">${App.esc(ev.title)}</span></div>`;
        }

        const stageLabel = ev.stage ? ev.stage + '단계' : '';
        const firstLine = timeLabel + (stageLabel ? ' ' + stageLabel : '');
        const secondLine = ev._type === 'study' ? (ev.host_nickname || '') : (ev.coach_name || '');
        const typeClass = ev._type === 'study' ? 'member-chip-study' : 'member-chip-lecture';

        return `<div class="member-chip ${typeClass}${todayClass}" data-type="${ev._type}" data-id="${ev.id}" title="${App.esc(ev.title)}"><span class="chip-line1">${App.esc(firstLine)}</span><span class="chip-line2">${App.esc(secondLine)}</span></div>`;
    }

    return { loadData };
})();
