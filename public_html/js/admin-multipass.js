/* ── Multipass (다회권 확인) ────────────────────────────────── */
const AdminMultipassApp = (() => {
    let container = null;
    let admin = null;
    let cohorts = [];        // cohort_list 응답 cohort_details
    let activeSubTab = 'members';
    let searchTimer = null;

    async function init(adminSession, containerId) {
        admin = adminSession;
        container = document.getElementById(containerId);
        if (!container) return;
        await loadCohorts();
        renderShell();
        renderMembersView('');
    }

    async function loadCohorts() {
        try {
            const r = await App.get('/api/admin.php?action=cohort_list');
            cohorts = (r.cohort_details || []).slice().sort((a, b) =>
                new Date(b.start_date) - new Date(a.start_date));
        } catch (e) {
            cohorts = [];
        }
    }

    function renderShell() {
        container.innerHTML = `
            <div class="multipass">
                <div class="multipass-toolbar">
                    <div class="multipass-subtabs">
                        <button class="multipass-subtab active" data-sub="members">회원별 보기</button>
                        <button class="multipass-subtab" data-sub="products">상품별 보기</button>
                    </div>
                    <div class="multipass-actions">
                        <button class="btn btn-primary btn-sm" id="mp-add">+ 다회권 추가</button>
                        <button class="btn btn-secondary btn-sm" id="mp-bulk">CSV 일괄</button>
                    </div>
                </div>
                <div class="multipass-body" id="mp-body"></div>
            </div>
        `;
        container.querySelectorAll('.multipass-subtab').forEach(btn => {
            btn.addEventListener('click', () => {
                container.querySelectorAll('.multipass-subtab').forEach(b => b.classList.toggle('active', b === btn));
                activeSubTab = btn.dataset.sub;
                if (activeSubTab === 'members') renderMembersView('');
                else renderProductsView();
            });
        });
        document.getElementById('mp-add').addEventListener('click', openAddModal);
        document.getElementById('mp-bulk').addEventListener('click', openBulkModal);
    }

    function renderMembersView(initialQ) {
        const body = document.getElementById('mp-body');
        body.innerHTML = `
            <div class="mp-search">
                <input type="text" id="mp-search-input" placeholder="user_id / 닉네임 / 실명 / 전화번호" value="${App.esc(initialQ || '')}">
            </div>
            <div id="mp-results"></div>
        `;
        const input = document.getElementById('mp-search-input');
        input.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => doSearch(input.value), 300);
        });
        if (initialQ) doSearch(initialQ);
    }

    async function doSearch(q) {
        const results = document.getElementById('mp-results');
        q = q.trim();
        if (!q) { results.innerHTML = ''; return; }
        results.innerHTML = '<p>검색 중...</p>';
        try {
            const r = await App.get('/api/admin.php?action=multipass_search_member&q=' + encodeURIComponent(q));
            const members = r.members || [];
            if (!members.length) {
                results.innerHTML = `
                    <p>이 검색어로 매칭되는 다회권이 없습니다.</p>
                    <button class="btn btn-primary btn-sm" onclick="document.getElementById('mp-add').click()">+ 다회권 추가</button>
                `;
                return;
            }
            results.innerHTML = members.map(m => renderMemberCard(m)).join('');
            attachCardHandlers();
        } catch (e) {
            results.innerHTML = `<p class="text-danger">검색 실패: ${App.esc(e.message || e)}</p>`;
        }
    }

    function renderMemberCard(m) {
        const profile = (m.profiles && m.profiles[0]) || {};
        const profStr = [profile.real_name, profile.nickname, profile.phone].filter(Boolean).join(' / ');
        const passes = m.passes || [];
        return `
            <div class="mp-member-card">
                <div class="mp-member-header">
                    👤 user_id: <strong>${App.esc(m.user_id)}</strong>
                    ${profStr ? `<span class="mp-profile">(${App.esc(profStr)})</span>` : ''}
                </div>
                <div class="mp-passes">
                    <div class="mp-passes-label">── 보유 다회권 (${passes.length}건) ──</div>
                    ${passes.map(p => renderPassCard(p)).join('')}
                </div>
            </div>
        `;
    }

    function renderPassCard(p) {
        const total = p.cohorts.length;
        const joined = p.cohorts.filter(c => c.joined).length;
        const remain = total - joined;
        return `
            <div class="mp-pass-card" data-pass-id="${p.id}">
                <div class="mp-pass-header">
                    <strong>${App.esc(p.product_name)}</strong>
                    <span class="mp-pass-meta">(${(p.created_at || '').slice(0, 10)} 등록)</span>
                    <span class="mp-pass-actions">
                        <button class="btn btn-secondary btn-xs mp-edit" data-id="${p.id}">수정</button>
                        <button class="btn btn-danger btn-xs mp-delete" data-id="${p.id}">삭제</button>
                    </span>
                </div>
                <div class="mp-pass-summary">포함 ${total}기 · 수강 ${joined} / 남은 ${remain}</div>
                <div class="mp-pass-cohorts">
                    ${p.cohorts.map(c => renderCohortRow(p.id, c)).join('')}
                </div>
            </div>
        `;
    }

    function renderCohortRow(passId, c) {
        const badge = c.joined ? '✅' : '⚪';
        let label;
        if (c.joined) label = '수강함';
        else if (c.has_member_row) label = '미수강 (환불)';
        else label = '미수강';
        const inactive = !c.is_active ? '<span class="mp-inactive">(종료)</span>' : '';
        const issuedMeta = c.coupon_issued ? `
            <div class="mp-coupon-meta">└ ${(c.coupon_issued_at || '').slice(0, 10)} by ${App.esc(c.coupon_issued_by_name || '?')}</div>
        ` : '';
        return `
            <div class="mp-cohort-row" data-cohort-id="${c.cohort_id}">
                <span class="mp-cohort-badge">${badge}</span>
                <span class="mp-cohort-name">${App.esc(c.cohort)} ${inactive}</span>
                <span class="mp-cohort-status">${label}</span>
                <label class="mp-coupon-toggle">
                    <input type="checkbox" class="mp-coupon-cb" data-pass-id="${passId}" data-cohort-id="${c.cohort_id}" ${c.coupon_issued ? 'checked' : ''}>
                    쿠폰 발급
                </label>
                ${issuedMeta}
            </div>
        `;
    }

    function attachCardHandlers() {
        container.querySelectorAll('.mp-coupon-cb').forEach(cb => {
            cb.addEventListener('change', async () => {
                const passId   = parseInt(cb.dataset.passId);
                const cohortId = parseInt(cb.dataset.cohortId);
                const issued   = cb.checked;
                try {
                    await App.post('/api/admin.php?action=multipass_toggle_coupon', { pass_id: passId, cohort_id: cohortId, issued });
                    // 옵티미스틱 — 결과 재로드 대신 카드의 메타 부분만 갱신
                    doSearch(document.getElementById('mp-search-input').value);
                } catch (e) {
                    cb.checked = !issued;
                    Toast.error('쿠폰 토글 실패: ' + (e.message || e));
                }
            });
        });
        container.querySelectorAll('.mp-edit').forEach(btn => {
            btn.addEventListener('click', () => openEditModal(parseInt(btn.dataset.id)));
        });
        container.querySelectorAll('.mp-delete').forEach(btn => {
            btn.addEventListener('click', () => deletePass(parseInt(btn.dataset.id)));
        });
    }

    async function deletePass(id) {
        if (!confirm('이 다회권을 삭제할까요?\n(쿠폰 발급 이력도 함께 삭제됩니다)')) return;
        try {
            await App.post('/api/admin.php?action=multipass_delete', { id });
            Toast.success('삭제 완료');
            doSearch(document.getElementById('mp-search-input').value);
        } catch (e) {
            Toast.error('삭제 실패: ' + (e.message || e));
        }
    }

    // 후속 task 에서 채움
    function openAddModal() { Toast.info('Task 10 에서 구현'); }
    function openEditModal(id) { Toast.info('Task 10 에서 구현'); }
    function openBulkModal() { Toast.info('Task 11 에서 구현'); }
    function renderProductsView() { document.getElementById('mp-body').innerHTML = '<p>Task 12 에서 구현</p>'; }

    return { init };
})();
