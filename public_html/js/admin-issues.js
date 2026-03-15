/* ══════════════════════════════════════════════════════════════
   AdminIssues — 운영용 오류 문의 관리 탭
   목록 + 필터 (상태/유형) + 상세 모달 + 상태 변경
   ══════════════════════════════════════════════════════════════ */
const AdminIssues = (() => {
    const API = '/api/bootcamp.php?action=';

    // ── 상태 ──
    const STATUS_MAP = {
        pending:     { label: '접수됨',    cls: 'issue-badge--pending' },
        in_progress: { label: '확인 중',   cls: 'issue-badge--progress' },
        resolved:    { label: '처리 완료', cls: 'issue-badge--resolved' },
        rejected:    { label: '반려',      cls: 'issue-badge--rejected' },
    };

    // "처리 전" = pending + in_progress
    const UNRESOLVED = ['pending', 'in_progress'];
    const RESOLVED   = ['resolved', 'rejected'];

    // ── 필터 정의 (확장 포인트) ──
    const STATUS_FILTERS = [
        { key: 'all',        label: '전체',    match: () => true },
        { key: 'unresolved', label: '처리 전', match: i => UNRESOLVED.includes(i.status) },
        { key: 'resolved',   label: '처리 완료', match: i => RESOLVED.includes(i.status) },
    ];

    const PAGE_SIZE = 30;

    let container = null;
    let admin = null;
    let allIssues = [];
    let issueTypes = {};

    // ── 필터 상태 (복수 필터 확장 가능) ──
    let filters = {
        status: 'all',
        type: 'all',    // 향후 문의 유형 필터
    };
    let currentPage = 1;

    // ══════════════════════════════════════════════════════════
    // Init
    // ══════════════════════════════════════════════════════════

    function init(tabEl, adm) {
        container = tabEl;
        admin = adm;

        container.innerHTML = `
            <div class="issue-adm">
                <div class="issue-adm-toolbar">
                    <h3 class="issue-adm-title">오류 문의</h3>
                    <button class="btn btn-secondary btn-sm" id="issue-adm-refresh">새로고침</button>
                </div>
                <div class="issue-adm-filter-bar" id="issue-adm-filter-bar">
                    <div class="issue-adm-filter-group" id="issue-adm-status-filters"></div>
                    <div class="issue-adm-filter-group" id="issue-adm-type-filters"></div>
                </div>
                <div class="issue-adm-count" id="issue-adm-count"></div>
                <div class="issue-adm-list" id="issue-adm-list"></div>
                <div class="issue-adm-pager" id="issue-adm-pager"></div>
            </div>
        `;

        document.getElementById('issue-adm-refresh').onclick = () => {
            container.dataset.loaded = '';
            loadList();
        };

        loadList();
    }

    // ══════════════════════════════════════════════════════════
    // Data
    // ══════════════════════════════════════════════════════════

    async function loadList() {
        const listEl = document.getElementById('issue-adm-list');
        if (!listEl) return;
        listEl.innerHTML = '<div class="issue-adm-loading">불러오는 중...</div>';

        const r = await App.get(API + 'issue_admin_list');
        if (!r.success) {
            listEl.innerHTML = '<div class="issue-adm-empty">목록을 불러올 수 없습니다.</div>';
            return;
        }

        allIssues = r.issues || [];
        issueTypes = r.issue_types || {};
        currentPage = 1;
        renderStatusFilters();
        renderTypeFilters();
        renderList();
    }

    // ══════════════════════════════════════════════════════════
    // Filters
    // ══════════════════════════════════════════════════════════

    function getFiltered() {
        let result = allIssues;

        // 상태 필터
        const sf = STATUS_FILTERS.find(f => f.key === filters.status);
        if (sf) result = result.filter(sf.match);

        // 유형 필터
        if (filters.type !== 'all') {
            result = result.filter(i => i.issue_type === filters.type);
        }

        return result;
    }

    function renderStatusFilters() {
        const el = document.getElementById('issue-adm-status-filters');
        if (!el) return;

        // 건수 계산
        const counts = {};
        STATUS_FILTERS.forEach(f => {
            counts[f.key] = allIssues.filter(f.match).length;
        });

        el.innerHTML = STATUS_FILTERS.map(f => {
            const active = filters.status === f.key ? ' active' : '';
            return `<button class="filter-chip${active}" data-filter="${f.key}">${f.label} <span class="chip-count">${counts[f.key]}</span></button>`;
        }).join('');

        el.querySelectorAll('.filter-chip').forEach(chip => {
            chip.onclick = () => {
                filters.status = chip.dataset.filter;
                currentPage = 1;
                renderStatusFilters();
                renderList();
            };
        });
    }

    function renderTypeFilters() {
        const el = document.getElementById('issue-adm-type-filters');
        if (!el || !issueTypes || Object.keys(issueTypes).length === 0) return;

        // 유형별 건수
        const counts = { all: allIssues.length };
        allIssues.forEach(i => {
            counts[i.issue_type] = (counts[i.issue_type] || 0) + 1;
        });

        const chips = [{ key: 'all', label: '전체 유형' }];
        Object.entries(issueTypes).forEach(([key, label]) => {
            if (counts[key]) chips.push({ key, label });
        });

        // 유형이 1개뿐이면 필터 불필요
        if (chips.length <= 2) { el.innerHTML = ''; return; }

        el.innerHTML = chips.map(c => {
            const active = filters.type === c.key ? ' active' : '';
            const cnt = c.key === 'all' ? '' : ` <span class="chip-count">${counts[c.key] || 0}</span>`;
            return `<button class="filter-chip filter-chip--sub${active}" data-filter="${c.key}">${c.label}${cnt}</button>`;
        }).join('');

        el.querySelectorAll('.filter-chip').forEach(chip => {
            chip.onclick = () => {
                filters.type = chip.dataset.filter;
                currentPage = 1;
                renderTypeFilters();
                renderList();
            };
        });
    }

    // ══════════════════════════════════════════════════════════
    // List + Pagination
    // ══════════════════════════════════════════════════════════

    function renderList() {
        const listEl = document.getElementById('issue-adm-list');
        const countEl = document.getElementById('issue-adm-count');
        const pagerEl = document.getElementById('issue-adm-pager');
        if (!listEl) return;

        const filtered = getFiltered();
        const total = filtered.length;
        const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
        if (currentPage > totalPages) currentPage = totalPages;
        const start = (currentPage - 1) * PAGE_SIZE;
        const pageItems = filtered.slice(start, start + PAGE_SIZE);

        if (countEl) {
            countEl.textContent = total > PAGE_SIZE
                ? `${total}건 중 ${start + 1}-${Math.min(start + PAGE_SIZE, total)}건`
                : `${total}건`;
        }

        if (total === 0) {
            listEl.innerHTML = '<div class="issue-adm-empty">해당 문의가 없습니다.</div>';
            if (pagerEl) pagerEl.innerHTML = '';
            return;
        }

        listEl.innerHTML = `
            <table class="issue-adm-table">
                <thead>
                    <tr>
                        <th>상태</th>
                        <th>유형</th>
                        <th>작성자</th>
                        <th>조</th>
                        <th>작성일</th>
                    </tr>
                </thead>
                <tbody>
                    ${pageItems.map(renderRow).join('')}
                </tbody>
            </table>
        `;

        listEl.querySelectorAll('.issue-adm-row').forEach(row => {
            row.onclick = () => {
                const issue = allIssues.find(i => String(i.id) === row.dataset.id);
                if (issue) openDetail(issue);
            };
        });

        // Pagination
        if (pagerEl) {
            if (totalPages <= 1) {
                pagerEl.innerHTML = '';
            } else {
                let html = '';
                if (currentPage > 1) html += `<button class="pager-btn" data-page="${currentPage - 1}">&laquo; 이전</button>`;
                html += `<span class="pager-info">${currentPage} / ${totalPages}</span>`;
                if (currentPage < totalPages) html += `<button class="pager-btn" data-page="${currentPage + 1}">다음 &raquo;</button>`;
                pagerEl.innerHTML = html;
                pagerEl.querySelectorAll('.pager-btn').forEach(btn => {
                    btn.onclick = () => {
                        currentPage = parseInt(btn.dataset.page, 10);
                        renderList();
                        container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    };
                });
            }
        }
    }

    function renderRow(issue) {
        const st = STATUS_MAP[issue.status] || STATUS_MAP.pending;
        const date = formatShort(issue.created_at);
        const name = issue.nickname || issue.member_name || '-';
        const group = issue.group_name || '-';

        return `
            <tr class="issue-adm-row" data-id="${issue.id}">
                <td><span class="issue-badge ${st.cls}">${st.label}</span></td>
                <td>${App.esc(issue.issue_type_label)}</td>
                <td>${App.esc(name)}</td>
                <td>${App.esc(group)}</td>
                <td class="issue-adm-date">${date}</td>
            </tr>
        `;
    }

    // ══════════════════════════════════════════════════════════
    // Detail Modal
    // ══════════════════════════════════════════════════════════

    function openDetail(issue) {
        const st = STATUS_MAP[issue.status] || STATUS_MAP.pending;
        const name = issue.nickname || issue.member_name || '-';

        const descHtml = issue.description
            ? `<div class="issue-adm-detail-section">
                   <div class="issue-adm-detail-label">추가 설명</div>
                   <div class="issue-adm-detail-value">${App.esc(issue.description).replace(/\n/g, '<br>')}</div>
               </div>`
            : '';

        const noteHtml = `
            <div class="issue-adm-detail-section">
                <div class="issue-adm-detail-label">운영팀 메모</div>
                <textarea class="issue-adm-note" id="adm-issue-note" rows="2" placeholder="메모 입력 (사용자에게 노출됨)">${App.esc(issue.admin_note || '')}</textarea>
                <button class="btn btn-secondary btn-sm" id="adm-note-save">메모 저장</button>
            </div>
        `;

        // 상태 변경 버튼
        const statusBtns = Object.entries(STATUS_MAP)
            .filter(([key]) => key !== issue.status)
            .map(([key, val]) =>
                `<button class="btn btn-sm issue-adm-status-btn ${val.cls}" data-status="${key}">${val.label}</button>`
            ).join('');

        const bodyHtml = `
            <div class="issue-adm-detail">
                <div class="issue-adm-detail-row">
                    <span class="issue-adm-detail-label">작성자</span>
                    <span>${App.esc(name)} · ${App.esc(issue.group_name || '-')}</span>
                </div>
                <div class="issue-adm-detail-row">
                    <span class="issue-adm-detail-label">작성일</span>
                    <span>${formatFull(issue.created_at)}</span>
                </div>
                <div class="issue-adm-detail-row">
                    <span class="issue-adm-detail-label">문의 유형</span>
                    <span>${App.esc(issue.issue_type_label)}</span>
                </div>
                <div class="issue-adm-detail-row">
                    <span class="issue-adm-detail-label">현재 상태</span>
                    <span class="issue-badge ${st.cls}" id="adm-issue-current-status">${st.label}</span>
                </div>
                ${issue.resolved_at ? `
                <div class="issue-adm-detail-row">
                    <span class="issue-adm-detail-label">처리일</span>
                    <span>${formatFull(issue.resolved_at)}</span>
                </div>` : ''}
                ${descHtml}
                ${noteHtml}
                <div class="issue-adm-detail-section">
                    <div class="issue-adm-detail-label">상태 변경</div>
                    <div class="issue-adm-status-btns" id="adm-status-btns">${statusBtns}</div>
                </div>
            </div>
        `;

        App.openModal(`문의 #${issue.id}`, bodyHtml);

        // 메모 저장
        document.getElementById('adm-note-save').onclick = async () => {
            const note = document.getElementById('adm-issue-note')?.value || '';
            const r = await App.post(API + 'issue_admin_note', { id: issue.id, admin_note: note });
            if (r.success) {
                Toast.success('메모가 저장되었습니다.');
                issue.admin_note = note;
            }
        };

        // 상태 변경 버튼
        document.querySelectorAll('#adm-status-btns .issue-adm-status-btn').forEach(btn => {
            btn.onclick = () => changeStatus(issue, btn.dataset.status);
        });
    }

    // ══════════════════════════════════════════════════════════
    // Status Change
    // ══════════════════════════════════════════════════════════

    async function changeStatus(issue, newStatus) {
        const st = STATUS_MAP[newStatus];
        if (!st) return;

        const ok = await App.confirm(`이 문의를 "${st.label}"(으)로 변경하시겠습니까?`);
        if (!ok) return;

        const r = await App.post(API + 'issue_status_update', {
            id: issue.id,
            status: newStatus,
        });

        if (r.success) {
            Toast.success(r.message || '상태가 변경되었습니다.');
            issue.status = newStatus;
            if (newStatus === 'resolved' || newStatus === 'rejected') {
                issue.resolved_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
            }
            App.closeModal();
            renderStatusFilters();
            renderList();
        }
    }

    // ══════════════════════════════════════════════════════════
    // Utils
    // ══════════════════════════════════════════════════════════

    function formatShort(dt) {
        if (!dt) return '';
        const d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d)) return dt.slice(0, 10);
        const m = d.getMonth() + 1;
        const day = d.getDate();
        const h = String(d.getHours()).padStart(2, '0');
        const min = String(d.getMinutes()).padStart(2, '0');
        return `${m}/${day} ${h}:${min}`;
    }

    function formatFull(dt) {
        if (!dt) return '';
        const d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d)) return dt;
        return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
    }

    return { init };
})();
