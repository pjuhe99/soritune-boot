/* ══════════════════════════════════════════════════════════════
   MemberCalendar — 캘린더 탭 (복습클래스 + 코치 강의 통합 달력)
   기존 member.js에서 캘린더 관련 로직 분리
   ══════════════════════════════════════════════════════════════ */
const MemberCalendar = (() => {
    const API = '/api/bootcamp.php?action=';

    let cal = null;
    let mounted = false;

    // 탭 핸들러 등록
    MemberTabs.register('calendar', { mount, unmount });

    function mount(panel, member) {
        if (mounted) return; // 이미 마운트된 경우 재렌더링 방지
        mounted = true;

        panel.innerHTML = `
            <div class="member-cal-section">
                <div class="member-legend">
                    <span class="member-legend-item"><span class="member-legend-dot member-legend-study"></span>복습클래스</span>
                    <span class="member-legend-item"><span class="member-legend-dot member-legend-lecture"></span>코치 강의</span>
                </div>
                <div id="member-cal-container"></div>
            </div>
        `;

        cal = CalendarUI.create(document.getElementById('member-cal-container'), {
            onMonthChange: () => loadData(),
            chipSelector: '.member-chip',
            onChipClick: (e, chip) => MemberCalendarDetail.open(chip.dataset.type, parseInt(chip.dataset.id)),
            renderChips(events) {
                return events.map(ev => ev._type === 'study' ? renderStudyChip(ev) : renderLectureChip(ev)).join('');
            },
        }).mount();

        loadData();
    }

    function unmount() {
        mounted = false;
        cal = null;
    }

    // ── 데이터 로드 ──

    async function loadData() {
        if (!cal) return;
        const { year, month } = cal.getMonth();
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;

        const [studyRes, lectureRes] = await Promise.all([
            App.get(API + 'study_sessions', { month: monthStr }),
            App.get(API + 'lecture_sessions', { month: monthStr }),
        ]);

        const events = [];

        if (studyRes.success) {
            (studyRes.sessions || []).forEach(s => {
                events.push({ ...s, _type: 'study', date: s.study_date });
            });
        }

        if (lectureRes.success) {
            (lectureRes.sessions || []).forEach(s => {
                events.push({ ...s, _type: 'lecture', date: s.lecture_date });
            });
        }

        cal.setEvents(events).render();
    }

    // ── 칩 렌더링 ──

    function renderStudyChip(s) {
        const timeLabel = (s.start_time || '').substring(0, 5);
        const levelLabel = s.level ? s.level + '단계' : '';
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
