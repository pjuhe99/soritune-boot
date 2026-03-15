/* ══════════════════════════════════════════════════════════════
   MemberProgress — 진도 달력 탭
   CalendarUI 재사용, curriculum_items를 월별 달력으로 표시
   ══════════════════════════════════════════════════════════════ */
const MemberProgress = (() => {
    const API = '/api/bootcamp.php?action=';

    // ── 카테고리 컬러 매핑 (한 곳에서 관리) ──
    const TYPE_COLORS = {
        progress:              { bg: '#dbeafe', color: '#1e40af', label: '진도' },
        event:                 { bg: '#fef3c7', color: '#92400e', label: '이벤트' },
        lecture:               { bg: '#dcfce7', color: '#15803d', label: '강의 듣기' },
        malkka_mission:        { bg: '#fce7f3', color: '#9d174d', label: '말까미션' },
        naemat33_mission:      { bg: '#ede9fe', color: '#5b21b6', label: '내맛33미션' },
        zoom_or_daily_mission: { bg: '#e0f2fe', color: '#0369a1', label: '줌/데일리미션' },
        hamummal:              { bg: '#fef9c3', color: '#854d0e', label: '하멈말' },
    };

    const DEFAULT_COLOR = { bg: '#f3f4f6', color: '#374151', label: '기타' };

    function getTypeStyle(taskType) {
        return TYPE_COLORS[taskType] || DEFAULT_COLOR;
    }

    let cal = null;
    let mounted = false;

    MemberTabs.register('curriculum', { mount, unmount });

    // ══════════════════════════════════════════════════════════
    // Mount / Unmount
    // ══════════════════════════════════════════════════════════

    function mount(panel, member) {
        if (mounted) return;
        mounted = true;

        panel.innerHTML = `
            <div class="progress-cal-section">
                <div class="progress-legend" id="progress-legend"></div>
                <div id="progress-cal-container"></div>
            </div>
        `;

        renderLegend();

        cal = CalendarUI.create(document.getElementById('progress-cal-container'), {
            onMonthChange: () => loadData(),
            chipSelector: '.progress-chip',
            onChipClick: (e, chip) => { const id = parseInt(chip.dataset.id); if (!isNaN(id)) openDetail(id); },
            renderChips: (events) => events.map(ev => renderChip(ev)).join(''),
        }).mount();

        MemberUtils.logEvent('open_tab_curriculum');
        loadData();
    }

    function unmount() {
        mounted = false;
        cal = null;
    }

    // ══════════════════════════════════════════════════════════
    // Legend
    // ══════════════════════════════════════════════════════════

    function renderLegend() {
        const el = document.getElementById('progress-legend');
        if (!el) return;

        // 주요 카테고리만 범례에 표시
        const mainTypes = ['progress', 'event', 'lecture', 'malkka_mission', 'naemat33_mission', 'zoom_or_daily_mission', 'hamummal'];
        el.innerHTML = mainTypes.map(t => {
            const s = getTypeStyle(t);
            return `<span class="progress-legend-item"><span class="progress-legend-dot" style="background:${s.bg};border:1px solid ${s.color}"></span>${s.label}</span>`;
        }).join('');
    }

    // ══════════════════════════════════════════════════════════
    // Data Loading
    // ══════════════════════════════════════════════════════════

    async function loadData() {
        if (!cal) return;
        const { year, month } = cal.getMonth();
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;

        const r = await App.get(API + 'member_curriculum', { month: monthStr });

        if (!r.success) {
            cal.setEvents([]).render();
            return;
        }

        // CalendarUI 이벤트 포맷으로 변환 (date 필드 필수)
        const events = (r.items || []).map(item => ({
            ...item,
            date: item.target_date,
        }));

        cal.setEvents(events).render();
    }

    // ══════════════════════════════════════════════════════════
    // Chip Rendering
    // ══════════════════════════════════════════════════════════

    function renderChip(item) {
        const style = getTypeStyle(item.task_type);
        const label = style.label;
        const note = item.note ? ': ' + truncate(item.note, 8) : '';

        return `<div class="progress-chip" data-id="${item.id}" style="background:${style.bg};color:${style.color}" title="${App.esc(label + (item.note ? ' - ' + item.note : ''))}">${App.esc(label)}${App.esc(note)}</div>`;
    }

    function truncate(str, len) {
        if (!str) return '';
        return str.length > len ? str.substring(0, len) + '…' : str;
    }

    // ══════════════════════════════════════════════════════════
    // Detail Modal
    // ══════════════════════════════════════════════════════════

    async function openDetail(itemId) {
        App.showLoading();
        const r = await App.get(API + 'member_curriculum_detail', { item_id: itemId });
        App.hideLoading();
        if (!r.success) return;

        const item = r.item;
        const style = getTypeStyle(item.task_type);
        const dateKo = App.formatDateKo(item.target_date);
        const noteHtml = item.note
            ? App.esc(item.note).replace(/\n/g, '<br>')
            : '<span style="color:var(--color-text-sub)">내용 없음</span>';

        const body = `
            <div class="progress-detail">
                <div class="progress-detail-badge" style="background:${style.bg};color:${style.color}">${App.esc(style.label)}</div>
                <div class="lec-detail-info">
                    <div class="lec-detail-row"><span class="lec-detail-label">날짜</span><span class="lec-detail-value">${dateKo}</span></div>
                    <div class="lec-detail-row"><span class="lec-detail-label">카테고리</span><span class="lec-detail-value">${App.esc(item.task_type_label)}</span></div>
                </div>
                <div class="progress-detail-note">${noteHtml}</div>
            </div>
        `;

        App.openModal(style.label, body);
    }

    return {};
})();
