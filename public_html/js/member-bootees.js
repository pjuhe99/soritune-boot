/* ══════════════════════════════════════════════════════════════
   MemberBootees — 부티즈 정보 탭
   같은 기수 멤버 목록 (코인 내림차순 → 닉네임 가나다순)
   본인 포함 (강조 표시), 필터: 전체 / 우리 조
   ══════════════════════════════════════════════════════════════ */
const MemberBootees = (() => {
    const API = '/api/bootcamp.php?action=';

    let mounted = false;
    let panel = null;
    let allMembers = [];
    let myGroupId = null;
    let myMemberId = null;
    let activeFilter = 'all';
    let searchKeyword = '';

    MemberTabs.register('members', { mount, unmount });

    // ══════════════════════════════════════════════════════════
    // Mount / Unmount
    // ══════════════════════════════════════════════════════════

    function mount(el, member) {
        if (mounted) return;
        mounted = true;
        panel = el;
        myMemberId = member.member_id;
        activeFilter = 'all';
        searchKeyword = '';

        panel.innerHTML = `
            <div class="bootees-container">
                <div class="bootees-toolbar">
                    <h3 class="bootees-title">부티즈 정보</h3>
                    <div class="bootees-filter-chips" id="bootees-filter-chips">
                        <button class="filter-chip active" data-filter="all">전체</button>
                        <button class="filter-chip" data-filter="my_group">우리 조</button>
                    </div>
                </div>
                <div class="bootees-search">
                    <input type="text" class="bootees-search-input" id="bootees-search" placeholder="닉네임 검색">
                </div>
                <div id="bootees-list"></div>
            </div>
        `;

        document.getElementById('bootees-search').oninput = App.debounce((e) => {
            searchKeyword = e.target.value.trim().toLowerCase();
            renderList();
        }, 200);

        MemberUtils.bindFilterChips('bootees-filter-chips', 'filter', (filter) => {
            activeFilter = filter;
            MemberUtils.logEvent('click_members_filter', filter);
            renderList();
        });
        MemberUtils.logEvent('open_tab_members');
        loadData();
    }

    function unmount() {
        mounted = false;
        panel = null;
        allMembers = [];
    }

    // ══════════════════════════════════════════════════════════
    // Data Loading
    // ══════════════════════════════════════════════════════════

    async function loadData() {
        const listEl = document.getElementById('bootees-list');
        if (!listEl) return;

        listEl.innerHTML = '<div class="bootees-loading">불러오는 중...</div>';

        const r = await App.get(API + 'member_bootees');
        if (!r.success) {
            listEl.innerHTML = '<div class="bootees-empty">데이터를 불러올 수 없습니다.</div>';
            return;
        }

        allMembers = r.members || [];
        myGroupId = r.my_group_id;
        myMemberId = r.my_member_id;
        renderList();
    }

    // ══════════════════════════════════════════════════════════
    // Rendering
    // ══════════════════════════════════════════════════════════

    function renderList() {
        const listEl = document.getElementById('bootees-list');
        if (!listEl) return;

        let filtered = allMembers;
        if (activeFilter === 'my_group' && myGroupId) {
            filtered = filtered.filter(m => m.group_id === myGroupId);
        }
        if (searchKeyword) {
            filtered = filtered.filter(m => (m.nickname || '').toLowerCase().includes(searchKeyword));
        }

        if (filtered.length === 0) {
            listEl.innerHTML = '<div class="bootees-empty">표시할 부티즈가 없습니다.</div>';
            return;
        }

        const rows = filtered.map((m, i) => {
            const isMe = m.id == myMemberId;
            const bravoHtml = m.bravo_grade
                ? `<span class="bootees-bravo">${App.esc(m.bravo_grade)}</span>`
                : '<span class="bootees-bravo-none">-</span>';
            const meBadge = isMe ? '<span class="bootees-me-badge">나</span>' : '';

            return `
                <div class="bootees-row${isMe ? ' bootees-row-me' : ''}">
                    <span class="bootees-rank">${i + 1}</span>
                    <div class="bootees-info">
                        <span class="bootees-nickname">${App.esc(m.nickname)}${meBadge}</span>
                        <span class="bootees-group">${App.esc(m.group_name || '')}</span>
                    </div>
                    <div class="bootees-stats">
                        <div class="bootees-stat">
                            <span class="bootees-stat-value bootees-score">${m.score ?? 0}</span>
                            <span class="bootees-stat-label">점수</span>
                        </div>
                        <div class="bootees-stat">
                            <span class="bootees-stat-value bootees-coin">${m.coin ?? 0}</span>
                            <span class="bootees-stat-label">코인</span>
                        </div>
                        <div class="bootees-stat">
                            <span class="bootees-stat-value">${m.completed_count ?? 0}</span>
                            <span class="bootees-stat-label">완주</span>
                        </div>
                        <div class="bootees-stat">
                            ${bravoHtml}
                            <span class="bootees-stat-label">브라보</span>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        listEl.innerHTML = `
            <div class="bootees-count">${filtered.length}명</div>
            <div class="bootees-list">${rows}</div>
        `;
    }

    return {};
})();
