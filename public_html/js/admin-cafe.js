/* ── Admin: 카페 게시글 탭 (operation 페이지 전용) ────────── */
/* admin.js 에서 분리. 의존성: App (common.js), Toast (toast.js) */
const AdminCafeApp = (() => {
    let containerEl = null;   // renderTab 에서 받은 element 캐시 (sub-load 에서 재사용)
    let page = 1;
    let filter = {};

    async function renderTab(container) {
        if (!container) return;
        containerEl = container;
        page = 1;
        filter = {};
        await load();
    }

    async function load() {
        if (!containerEl) return;

        const params = new URLSearchParams({ action: 'cafe_posts', page, limit: 50 });
        if (filter.board_type) params.set('board_type', filter.board_type);
        if (filter.date) params.set('date', filter.date);
        if (filter.mapped !== undefined && filter.mapped !== '') params.set('mapped', filter.mapped);
        if (filter.keyword) params.set('keyword', filter.keyword);

        if (page === 1) containerEl.innerHTML = '<div class="empty-state">로딩 중...</div>';
        const r = await App.get('/api/bootcamp.php?' + params.toString());
        if (!r.success) { containerEl.innerHTML = '<div class="empty-state">불러오기 실패</div>'; return; }

        const BOARD_LABELS = {
            speak_mission: '내맛미션',
            inner33: '내맛33미션',
            daily_mission: '데일리 미션',
        };
        const totalPages = Math.ceil(r.total / r.limit) || 1;

        const statsHtml = (r.stats || []).map(s => {
            const label = BOARD_LABELS[s.board_type] || s.board_type || '기타';
            return `<span class="badge badge-secondary" style="margin-right:4px">${App.esc(label)}: ${s.cnt}건 (매핑 ${s.mapped_cnt})</span>`;
        }).join('');

        containerEl.innerHTML = `
            <div class="mgmt-toolbar mt-md" style="flex-wrap:wrap;gap:8px">
                <span style="font-weight:600">카페 게시글 (${r.total}건)</span>
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                    <select class="form-select form-select-sm" id="cafe-filter-board" style="width:auto">
                        <option value="">전체 게시판</option>
                        <option value="speak_mission" ${filter.board_type === 'speak_mission' ? 'selected' : ''}>내맛미션</option>
                        <option value="inner33" ${filter.board_type === 'inner33' ? 'selected' : ''}>내맛33미션</option>
                        <option value="daily_mission" ${filter.board_type === 'daily_mission' ? 'selected' : ''}>데일리 미션</option>
                    </select>
                    <input type="date" class="form-input form-input-sm" id="cafe-filter-date" value="${filter.date || ''}" style="width:auto">
                    <select class="form-select form-select-sm" id="cafe-filter-mapped" style="width:auto">
                        <option value="">전체</option>
                        <option value="1" ${filter.mapped === '1' ? 'selected' : ''}>매핑됨</option>
                        <option value="0" ${filter.mapped === '0' ? 'selected' : ''}>미매핑</option>
                    </select>
                    <input type="text" class="form-input form-input-sm" id="cafe-filter-keyword" placeholder="제목/닉네임 검색" value="${App.esc(filter.keyword || '')}" style="width:140px">
                    <button class="btn btn-primary btn-sm" id="cafe-filter-btn">검색</button>
                    <button class="btn btn-secondary btn-sm" id="cafe-filter-reset">초기화</button>
                    <button class="btn btn-sm" id="btn-cafe-remap" style="background:#f59e0b;color:#fff" title="미매핑 카페 게시글을 재매핑하고 체크리스트에 반영합니다">수동 반영</button>
                </div>
            </div>
            ${statsHtml ? `<div class="mt-sm">${statsHtml}</div>` : ''}
            <div style="overflow-x:auto">
                <table class="data-table mt-sm">
                    <thead><tr>
                        <th>게시판</th>
                        <th>제목</th>
                        <th>카페 닉네임</th>
                        <th>매핑 회원</th>
                        <th>업로드일</th>
                        <th>체크</th>
                    </tr></thead>
                    <tbody>
                        ${r.posts.length ? r.posts.map(p => {
                            const boardLabel = BOARD_LABELS[p.board_type] || p.board_type || '-';
                            const postedDate = p.posted_at ? p.posted_at.substring(0, 16) : '-';
                            const memberName = p.member_real_name ? `${App.esc(p.member_real_name)} (${App.esc(p.member_nickname || '')})` : '<span class="text-danger">미매핑</span>';
                            const checkBadge = p.mission_checked == 1 ? '<span class="badge badge-success">완료</span>' : '<span class="badge badge-secondary">-</span>';
                            return `<tr>
                                <td><span class="badge badge-primary">${App.esc(boardLabel)}</span></td>
                                <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${App.esc(p.title)}">${App.esc(p.title)}</td>
                                <td>${App.esc(p.nickname || '-')}</td>
                                <td>${memberName}</td>
                                <td>${postedDate}</td>
                                <td>${checkBadge}</td>
                            </tr>`;
                        }).join('') : '<tr><td colspan="6" class="empty-state">게시글이 없습니다.</td></tr>'}
                    </tbody>
                </table>
            </div>
            ${totalPages > 1 ? `
            <div class="pagination mt-md" style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap">
                ${page > 1 ? `<button class="btn btn-sm btn-secondary" onclick="AdminCafeApp._setPage(${page - 1})">이전</button>` : ''}
                <span class="badge" style="padding:6px 10px">${page} / ${totalPages}</span>
                ${page < totalPages ? `<button class="btn btn-sm btn-secondary" onclick="AdminCafeApp._setPage(${page + 1})">다음</button>` : ''}
            </div>` : ''}
        `;

        // 필터 이벤트
        const applyFilter = () => {
            filter = {
                board_type: document.getElementById('cafe-filter-board').value,
                date: document.getElementById('cafe-filter-date').value,
                mapped: document.getElementById('cafe-filter-mapped').value,
                keyword: document.getElementById('cafe-filter-keyword').value.trim(),
            };
            loadPage(1);
        };
        document.getElementById('cafe-filter-btn').onclick = applyFilter;
        document.getElementById('cafe-filter-keyword').onkeydown = (e) => { if (e.key === 'Enter') applyFilter(); };
        document.getElementById('cafe-filter-reset').onclick = () => {
            filter = {};
            loadPage(1);
        };
        document.getElementById('btn-cafe-remap').onclick = async () => {
            if (!await App.confirm('미매핑 카페 게시글을 재매핑하고 체크리스트에 반영합니다. 진행하시겠습니까?')) return;
            const btn = document.getElementById('btn-cafe-remap');
            btn.disabled = true;
            btn.textContent = '반영 중...';
            const r = await App.post('/api/bootcamp.php?action=cafe_remap_unmapped');
            btn.disabled = false;
            btn.textContent = '수동 반영';
            if (r.success) {
                Toast.success(r.data.message);
                loadPage(1);
            } else {
                Toast.error(r.message || '수동 반영 실패');
            }
        };
    }

    async function loadPage(p) {
        page = p;
        await load();
    }

    function _setPage(p) {
        loadPage(p);
    }

    return { renderTab, _setPage };
})();
