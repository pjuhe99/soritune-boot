/* ══════════════════════════════════════════════════════════════
   MemberAssignments — 과제 이력 탭 (읽기 전용)
   본인의 체크리스트 이력을 날짜별 최신순으로 표시
   ══════════════════════════════════════════════════════════════ */
const MemberAssignments = (() => {
    const API = '/api/bootcamp.php?action=';

    let mounted = false;
    let panel = null;
    let currentPage = 1;
    let totalPages = 1;

    MemberTabs.register('assignments', { mount, unmount });

    function mount(el, member) {
        if (mounted) return;
        mounted = true;
        panel = el;
        currentPage = 1;

        panel.innerHTML = `
            <div class="assignments-container">
                <div class="assignments-header">
                    <h3 class="assignments-title">과제 이력</h3>
                </div>
                <div id="assignments-notice"></div>
                <div id="assignments-summary"></div>
                <div id="assignments-list"></div>
                <div id="assignments-pager"></div>
            </div>
        `;

        MemberIssue.renderNotice(document.getElementById('assignments-notice'));
        MemberUtils.logEvent('open_tab_assignments');
        loadSummary();
        loadPage(1);
    }

    function unmount() {
        mounted = false;
        panel = null;
    }

    // ══════════════════════════════════════════════════════════
    // Summary Card
    // ══════════════════════════════════════════════════════════

    async function loadSummary() {
        const el = document.getElementById('assignments-summary');
        if (!el) return;

        const r = await App.get(API + 'member_checks_summary');
        if (!r.success || r.total_days === 0) { el.innerHTML = ''; return; }

        el.innerHTML = `
            <div class="assignments-summary-card">
                <div class="summary-stat">
                    <span class="summary-value">${r.completion_rate}<small>%</small></span>
                    <span class="summary-label">완료율</span>
                </div>
                <div class="summary-stat">
                    <span class="summary-value">${r.current_streak}<small>일</small></span>
                    <span class="summary-label">연속 올클</span>
                </div>
                <div class="summary-stat">
                    <span class="summary-value">${r.perfect_days}<small>/${r.total_days}</small></span>
                    <span class="summary-label">올클 일수</span>
                </div>
            </div>
        `;
    }

    // ══════════════════════════════════════════════════════════
    // Data Loading
    // ══════════════════════════════════════════════════════════

    async function loadPage(page) {
        const listEl = document.getElementById('assignments-list');
        const pagerEl = document.getElementById('assignments-pager');
        if (!listEl) return;

        listEl.innerHTML = '<div class="assignments-loading">불러오는 중...</div>';

        const r = await App.get(API + 'member_checks', { page });
        if (!r.success) {
            listEl.innerHTML = '<div class="assignments-empty">데이터를 불러올 수 없습니다.</div>';
            return;
        }

        currentPage = r.page;
        totalPages = r.total_pages;

        if (!r.dates || r.dates.length === 0) {
            listEl.innerHTML = '<div class="assignments-empty">아직 과제 이력이 없습니다.</div>';
            pagerEl.innerHTML = '';
            return;
        }

        listEl.innerHTML = r.dates.map(d => renderDateGroup(d)).join('');
        pagerEl.innerHTML = renderPager();
        bindPager(pagerEl);
    }

    // ══════════════════════════════════════════════════════════
    // Rendering
    // ══════════════════════════════════════════════════════════

    function renderDateGroup(dateData) {
        const dateKo = App.formatDateKo(dateData.date);
        const checks = dateData.checks || [];

        // 전체 완료 여부
        const totalDone = checks.filter(c => c.status === 1).length;
        const totalAll = checks.length;
        const allDone = totalDone === totalAll && totalAll > 0;

        const summaryClass = allDone ? 'assignments-summary-done' : 'assignments-summary-partial';
        const summaryText = `${totalDone}/${totalAll}`;

        const rows = checks.map(c => {
            const statusClass = c.status === 1 ? 'check-done' : 'check-miss';
            const statusLabel = c.status === 1 ? '완료' : '미완료';
            const sourceLabel = getSourceLabel(c.source);

            return `
                <div class="assignment-row ${statusClass}">
                    <span class="assignment-icon">${c.status === 1 ? '&#10003;' : '&#10007;'}</span>
                    <span class="assignment-name">${App.esc(c.mission_name)}</span>
                    <span class="assignment-source">${App.esc(sourceLabel)}</span>
                    <span class="assignment-status">${statusLabel}</span>
                </div>
            `;
        }).join('');

        return `
            <div class="assignments-date-group">
                <div class="assignments-date-header">
                    <span class="assignments-date">${dateKo}</span>
                    <span class="assignments-summary ${summaryClass}">${summaryText}</span>
                </div>
                <div class="assignments-date-body">${rows}</div>
            </div>
        `;
    }

    function getSourceLabel(source) {
        switch (source) {
            case 'manual':      return '수동';
            case 'automation':  return '자동';
            case 'cafe_api':    return '카페';
            default:            return '';
        }
    }

    // ══════════════════════════════════════════════════════════
    // Pagination
    // ══════════════════════════════════════════════════════════

    function renderPager() {
        if (totalPages <= 1) return '';

        let html = '<div class="assignments-pager">';
        if (currentPage > 1) {
            html += `<button class="pager-btn" data-page="${currentPage - 1}">&laquo; 이전</button>`;
        }
        html += `<span class="pager-info">${currentPage} / ${totalPages}</span>`;
        if (currentPage < totalPages) {
            html += `<button class="pager-btn" data-page="${currentPage + 1}">다음 &raquo;</button>`;
        }
        html += '</div>';
        return html;
    }

    function bindPager(pagerEl) {
        pagerEl.querySelectorAll('.pager-btn').forEach(btn => {
            btn.onclick = () => {
                const page = parseInt(btn.dataset.page);
                if (page >= 1 && page <= totalPages) {
                    loadPage(page);
                    // 스크롤 상단 이동
                    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            };
        });
    }

    return {};
})();
