/* ══════════════════════════════════════════════════════════════
   MemberCalendar — 캘린더 탭 (복습클래스 + 코치 강의 통합 달력)
   역할: 필터 칩 UI + 데이터 로드 + normalize + filter + 칩 렌더링
   상세 모달은 member-calendar-detail.js에 위임
   ══════════════════════════════════════════════════════════════ */
const MemberCalendar = (() => {
    const API = '/api/bootcamp.php?action=';

    let cal = null;
    let mounted = false;
    let allEvents = [];       // normalize된 전체 이벤트
    let activeFilter = 'all'; // 'all' | '1' | '2'

    // 탭 핸들러 등록
    MemberTabs.register('calendar', { mount, unmount });

    // ══════════════════════════════════════════════════════════
    // Mount / Unmount
    // ══════════════════════════════════════════════════════════

    function mount(panel, member) {
        if (mounted) return;
        mounted = true;
        activeFilter = 'all'; // 새로고침 시 기본값

        panel.innerHTML = `
            <div class="member-cal-section">
                <div class="member-cal-toolbar">
                    <div class="member-legend">
                        <span class="member-legend-item"><span class="member-legend-dot member-legend-study"></span>복습클래스</span>
                        <span class="member-legend-item"><span class="member-legend-dot member-legend-lecture"></span>코치 강의</span>
                    </div>
                    <div class="member-filter-chips" id="member-filter-chips">
                        <button class="filter-chip active" data-stage="all">전체</button>
                        <button class="filter-chip" data-stage="1">1단계</button>
                        <button class="filter-chip" data-stage="2">2단계</button>
                    </div>
                </div>
                <div id="member-cal-container"></div>
            </div>
        `;

        // 필터 칩 이벤트 바인딩
        bindFilterChips();
        MemberUtils.logEvent('open_tab_calendar');

        // 캘린더 초기화
        cal = CalendarUI.create(document.getElementById('member-cal-container'), {
            onMonthChange: () => loadData(),
            chipSelector: '.member-chip',
            onChipClick: (e, chip) => MemberCalendarDetail.open(chip.dataset.type, parseInt(chip.dataset.id)),
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
    // Filter Chips
    // ══════════════════════════════════════════════════════════

    function bindFilterChips() {
        const container = document.getElementById('member-filter-chips');
        if (!container) return;

        container.addEventListener('click', (e) => {
            const chip = e.target.closest('.filter-chip');
            if (!chip) return;

            const stage = chip.dataset.stage;
            if (stage === activeFilter) return; // 동일 필터 재클릭 무시

            // UI 상태 전환
            container.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
            chip.classList.add('active');

            activeFilter = stage;
            MemberUtils.logEvent('click_calendar_stage_filter', stage);
            applyFilterAndRender();
        });
    }

    // ══════════════════════════════════════════════════════════
    // Data: Load → Normalize → Filter → Render
    // ══════════════════════════════════════════════════════════

    async function loadData() {
        if (!cal) return;
        const { year, month } = cal.getMonth();
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;

        const [studyRes, lectureRes] = await Promise.all([
            App.get(API + 'study_sessions', { month: monthStr }),
            App.get(API + 'lecture_sessions', { month: monthStr }),
        ]);

        allEvents = [];

        // normalize: 공통 필드 _type, date, stage
        if (studyRes.success) {
            (studyRes.sessions || []).forEach(s => {
                allEvents.push(normalizeStudy(s));
            });
        }

        if (lectureRes.success) {
            (lectureRes.sessions || []).forEach(s => {
                allEvents.push(normalizeLecture(s));
            });
        }

        applyFilterAndRender();
    }

    /** 스터디 → 공통 이벤트 포맷 */
    function normalizeStudy(s) {
        return {
            ...s,
            _type: 'study',
            date: s.study_date,
            stage: parseInt(s.level) || null, // level → stage 통일
        };
    }

    /** 강의 → 공통 이벤트 포맷 */
    function normalizeLecture(s) {
        return {
            ...s,
            _type: 'lecture',
            date: s.lecture_date,
            stage: parseInt(s.stage) || null, // 이미 stage 필드
        };
    }

    /** 필터 적용 후 캘린더 렌더링 */
    function applyFilterAndRender() {
        if (!cal) return;
        const filtered = filterByStage(allEvents, activeFilter);
        cal.setEvents(filtered).render();
    }

    /** 단계 필터링 */
    function filterByStage(events, stage) {
        if (stage === 'all') return events;
        const stageNum = parseInt(stage);
        return events.filter(ev => ev.stage === stageNum);
    }

    // ══════════════════════════════════════════════════════════
    // Chip Rendering
    // ══════════════════════════════════════════════════════════

    function renderChip(ev) {
        return ev._type === 'study' ? renderStudyChip(ev) : renderLectureChip(ev);
    }

    function renderStudyChip(s) {
        const timeLabel = (s.start_time || '').substring(0, 5);
        const levelLabel = s.stage ? s.stage + '단계' : '';
        const host = s.host_nickname || '';
        const firstLine = timeLabel + (levelLabel ? ' ' + levelLabel : '');
        return `<div class="member-chip member-chip-study" data-type="study" data-id="${s.id}" title="${App.esc(s.title)}"><span class="chip-line1">${App.esc(firstLine)}</span><span class="chip-line2">${App.esc(host)}</span></div>`;
    }

    function renderLectureChip(s) {
        const timeLabel = (s.start_time || '').substring(0, 5);
        const stageLabel = s.stage ? s.stage + '단계' : '';
        const coach = s.coach_name || '';
        const firstLine = timeLabel + (stageLabel ? ' ' + stageLabel : '');
        return `<div class="member-chip member-chip-lecture" data-type="lecture" data-id="${s.id}" title="${App.esc(s.title)}"><span class="chip-line1">${App.esc(firstLine)}</span><span class="chip-line2">${App.esc(coach)}</span></div>`;
    }

    return { loadData };
})();
