/* ══════════════════════════════════════════════════════════════
   CalendarUI — 공통 달력 컴포넌트
   /study, /operation(강의), /member(통합) 에서 재사용
   ══════════════════════════════════════════════════════════════ */
const CalendarUI = (() => {
    const WEEKDAYS = ['일', '월', '화', '수', '목', '금', '토'];

    /**
     * create(container, options) → CalendarInstance
     *
     * options:
     *   onMonthChange(year, month0)   // 월 변경 시 콜백 (month: 0-indexed)
     *   renderChips(events, dateStr)  // 해당 날짜의 이벤트 → chips HTML
     *   onChipClick(e, chipEl)        // 칩 클릭
     *   chipSelector: '.cal-chip'     // 클릭 바인딩할 칩 CSS selector
     *   headerHtml: ''               // 달력 위에 표시할 HTML (버튼 등)
     *   emptyText: ''                // 이벤트 없을 때 달력 아래 표시
     */
    function create(container, options = {}) {
        const opts = Object.assign({
            onMonthChange: null,
            renderChips: () => '',
            onChipClick: null,
            chipSelector: '.cal-chip',
            headerHtml: '',
            emptyText: '',
        }, options);

        let year = 0;
        let month = 0; // 0-indexed
        let events = [];

        // ── Unique IDs ──
        const uid = 'cal-' + Math.random().toString(36).slice(2, 8);
        const ids = {
            nav: uid + '-nav',
            label: uid + '-label',
            grid: uid + '-grid',
            prev: uid + '-prev',
            next: uid + '-next',
            today: uid + '-today',
            header: uid + '-header',
        };

        // ── Render skeleton ──
        function mount() {
            container.innerHTML = `
                <div class="cal-calendar">
                    ${opts.headerHtml ? `<div class="cal-header" id="${ids.header}">${opts.headerHtml}</div>` : ''}
                    <div class="cal-nav" id="${ids.nav}">
                        <button class="page-btn" id="${ids.prev}">&lt;</button>
                        <span class="cal-month-label" id="${ids.label}"></span>
                        <button class="page-btn" id="${ids.next}">&gt;</button>
                        <button class="page-today" id="${ids.today}">오늘</button>
                    </div>
                    <div class="cal-grid" id="${ids.grid}"></div>
                </div>
            `;
            document.getElementById(ids.prev).onclick = () => changeMonth(-1);
            document.getElementById(ids.next).onclick = () => changeMonth(1);
            document.getElementById(ids.today).onclick = goToday;
        }

        function changeMonth(delta) {
            month += delta;
            if (month < 0) { month = 11; year--; }
            if (month > 11) { month = 0; year++; }
            updateLabel();
            if (opts.onMonthChange) opts.onMonthChange(year, month);
        }

        function goToday() {
            const now = new Date();
            year = now.getFullYear();
            month = now.getMonth();
            updateLabel();
            if (opts.onMonthChange) opts.onMonthChange(year, month);
        }

        function updateLabel() {
            const el = document.getElementById(ids.label);
            if (el) el.textContent = `${year}년 ${month + 1}월`;
        }

        // ── Render grid ──
        function render() {
            updateLabel();
            const grid = document.getElementById(ids.grid);
            if (!grid) return;

            // Group events by date
            const byDate = {};
            events.forEach(ev => {
                const d = ev.date || ev.study_date || ev.lecture_date;
                if (!d) return;
                if (!byDate[d]) byDate[d] = [];
                byDate[d].push(ev);
            });

            const todayStr = App.today();
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const startDow = firstDay.getDay(); // 0=Sun

            const prevMonthLast = new Date(year, month, 0);
            const cells = [];

            // Header row
            WEEKDAYS.forEach(w => {
                cells.push(`<div class="cal-head">${w}</div>`);
            });

            // Previous month
            for (let i = startDow - 1; i >= 0; i--) {
                const d = prevMonthLast.getDate() - i;
                const dateStr = App.formatDate(new Date(year, month - 1, d));
                cells.push(buildCell(d, dateStr, true, todayStr, byDate));
            }

            // Current month
            for (let d = 1; d <= lastDay.getDate(); d++) {
                const dateStr = App.formatDate(new Date(year, month, d));
                cells.push(buildCell(d, dateStr, false, todayStr, byDate));
            }

            // Next month fill
            const totalCells = cells.length - 7; // minus header
            const remaining = 7 - (totalCells % 7);
            if (remaining < 7) {
                for (let d = 1; d <= remaining; d++) {
                    const dateStr = App.formatDate(new Date(year, month + 1, d));
                    cells.push(buildCell(d, dateStr, true, todayStr, byDate));
                }
            }

            grid.innerHTML = cells.join('');

            // Bind chip clicks
            if (opts.onChipClick) {
                grid.querySelectorAll(opts.chipSelector).forEach(chip => {
                    chip.onclick = (e) => opts.onChipClick(e, chip);
                });
            }
        }

        function buildCell(day, dateStr, isOther, todayStr, byDate) {
            const isToday = dateStr === todayStr;
            const cls = ['cal-cell'];
            if (isOther) cls.push('other-month');
            if (isToday) cls.push('today');

            const dayEvents = byDate[dateStr] || [];
            const chipsHtml = opts.renderChips(dayEvents, dateStr);

            return `<div class="${cls.join(' ')}">
                <span class="cal-day">${day}</span>
                ${chipsHtml}
            </div>`;
        }

        // ── Public API ──
        const instance = {
            mount() {
                const now = new Date();
                year = now.getFullYear();
                month = now.getMonth();
                mount();
                return instance;
            },
            setMonth(y, m) { year = y; month = m; updateLabel(); return instance; },
            getMonth() { return { year, month }; },
            setEvents(ev) { events = ev || []; return instance; },
            render() { render(); return instance; },
            getHeaderEl() { return document.getElementById(ids.header); },
        };

        return instance;
    }

    return { create };
})();
