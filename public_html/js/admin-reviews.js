/* ══════════════════════════════════════════════════════════════
   AdminReviews — /operation 후기 관리 탭
   회원 후기 제출 목록 조회 + 취소(사유 입력) 처리.
   스펙: docs/superpowers/specs/2026-04-23-review-submission-design.md
   ══════════════════════════════════════════════════════════════ */
const AdminReviews = (() => {
    const API = '/api/bootcamp.php?action=';

    let state = {
        cycles: [],
        cycleId: 0,
        type: 'all',
        status: 'active',
        q: '',
    };

    async function init(container) {
        container.innerHTML = `
            <div class="admin-reviews-page">
                <div class="admin-reviews-filters">
                    <label>Cycle:
                        <select id="ar-cycle"></select>
                    </label>
                    <label>타입:
                        <select id="ar-type">
                            <option value="all">전체</option>
                            <option value="cafe">카페</option>
                            <option value="blog">블로그</option>
                        </select>
                    </label>
                    <label>상태:
                        <select id="ar-status">
                            <option value="active">활성</option>
                            <option value="cancelled">취소됨</option>
                            <option value="all">전체</option>
                        </select>
                    </label>
                    <label>닉네임: <input type="text" id="ar-q" placeholder="검색"></label>
                    <button class="btn btn-secondary" id="ar-reload">조회</button>
                </div>
                <div class="admin-reviews-counts" id="ar-counts"></div>
                <div class="admin-reviews-list" id="ar-list"></div>
            </div>
        `;

        // cycles 로드
        const cr = await App.get(API + 'coin_cycles');
        state.cycles = (cr.cycles || []).slice(0, 10);
        const active = state.cycles.find(c => c.status === 'active') || state.cycles[0];
        state.cycleId = active ? parseInt(active.id) : 0;

        const sel = document.getElementById('ar-cycle');
        sel.innerHTML = state.cycles.map(c =>
            `<option value="${c.id}" ${parseInt(c.id) === state.cycleId ? 'selected' : ''}>${App.esc(c.name)}${c.status === 'active' ? ' (active)' : ''}</option>`
        ).join('');

        // 이벤트
        sel.onchange = () => { state.cycleId = parseInt(sel.value); load(); };
        document.getElementById('ar-type').onchange = (e) => { state.type = e.target.value; load(); };
        document.getElementById('ar-status').onchange = (e) => { state.status = e.target.value; load(); };
        document.getElementById('ar-reload').onclick = () => {
            state.q = document.getElementById('ar-q').value.trim();
            load();
        };

        await load();
    }

    async function load() {
        const list = document.getElementById('ar-list');
        const counts = document.getElementById('ar-counts');
        list.innerHTML = '<div class="empty-state">조회 중…</div>';

        const params = new URLSearchParams({
            cycle_id: String(state.cycleId),
            type: state.type,
            status: state.status,
        });
        if (state.q) params.set('q', state.q);

        const r = await App.get(API + 'reviews_list&' + params.toString());
        if (!r.success) {
            list.innerHTML = '<div class="empty-state">조회 실패</div>';
            counts.innerHTML = '';
            return;
        }

        counts.innerHTML = `전체 ${r.counts.total} · 활성 ${r.counts.active} · 취소 ${r.counts.cancelled}`;

        if (!r.items.length) {
            list.innerHTML = '<div class="empty-state">제출된 후기가 없습니다.</div>';
            return;
        }

        list.innerHTML = `
            <table class="admin-reviews-table">
                <thead>
                    <tr>
                        <th>제출일</th><th>조</th><th>닉네임</th><th>타입</th>
                        <th>URL</th><th>코인</th><th>상태</th><th></th>
                    </tr>
                </thead>
                <tbody>${r.items.map(renderRow).join('')}</tbody>
            </table>
        `;

        list.querySelectorAll('.ar-cancel-btn[data-id]').forEach(btn => {
            btn.addEventListener('click', () => showCancelModal(btn.dataset));
        });
    }

    function renderRow(item) {
        const typeLabel = item.type === 'cafe' ? '카페' : '블로그';
        const submitted = (item.submitted_at || '').slice(5, 16).replace('T', ' '); // MM-DD HH:MM
        const cancelled = !!item.cancelled_at;
        const statusCell = cancelled
            ? `<span class="ar-status ar-status-cancelled">취소됨</span>`
            : `<span class="ar-status ar-status-active">활성</span>`;
        const actionCell = cancelled
            ? ''
            : `<button class="btn btn-small btn-danger ar-cancel-btn"
                       data-id="${item.id}"
                       data-nickname="${App.esc(item.nickname || '')}"
                       data-type="${item.type}"
                       data-url="${App.esc(item.url || '')}"
                       data-submitted="${App.esc(submitted)}"
                       data-amount="${item.coin_amount}">취소</button>`;
        const cancelRow = cancelled
            ? `<tr class="ar-cancel-meta"><td colspan="8">└ 사유: "${App.esc(item.cancel_reason || '')}" · by ${App.esc(item.cancelled_by_name || '?')} · ${App.esc((item.cancelled_at || '').slice(5,16))}</td></tr>`
            : '';

        return `
            <tr class="${cancelled ? 'ar-row-cancelled' : ''}">
                <td>${App.esc(submitted)}</td>
                <td>${App.esc(item.group_name || '-')}</td>
                <td>${App.esc(item.nickname || '')}</td>
                <td>${typeLabel}</td>
                <td><a href="${App.esc(item.url)}" target="_blank" rel="noopener noreferrer">↗ 링크</a></td>
                <td>${item.coin_amount}</td>
                <td>${statusCell}</td>
                <td>${actionCell}</td>
            </tr>
            ${cancelRow}
        `;
    }

    function showCancelModal(ds) {
        App.openModal('후기 취소', `
            <div class="ar-cancel-modal">
                <div class="ar-cancel-meta-lines">
                    <div>닉네임: ${App.esc(ds.nickname)} · ${ds.type === 'cafe' ? '카페 후기' : '블로그 후기'}</div>
                    <div>제출: ${App.esc(ds.submitted)}</div>
                    <div>URL: <a href="${App.esc(ds.url)}" target="_blank" rel="noopener noreferrer">${App.esc(ds.url)}</a></div>
                    <div class="ar-cancel-warn">※ 회원의 해당 cycle에서 ${ds.amount}코인이 차감됩니다.</div>
                </div>
                <label class="ar-cancel-label">취소 사유 (필수)</label>
                <textarea id="ar-cancel-reason" maxlength="255" rows="3" class="form-input"></textarea>
                <div class="ar-cancel-actions">
                    <button class="btn btn-secondary" id="ar-cancel-close">닫기</button>
                    <button class="btn btn-danger" id="ar-cancel-confirm">취소 처리</button>
                </div>
            </div>
        `);

        document.getElementById('ar-cancel-close').onclick = () => App.closeModal();
        document.getElementById('ar-cancel-confirm').onclick = async () => {
            const reason = document.getElementById('ar-cancel-reason').value.trim();
            if (!reason) { Toast.error('취소 사유를 입력해주세요.'); return; }
            App.showLoading();
            const r = await App.post(API + 'review_cancel', { id: parseInt(ds.id), cancel_reason: reason });
            App.hideLoading();
            if (r.success) {
                Toast.success(r.message);
                App.closeModal();
                await load();
            } else {
                Toast.error(r.error || r.message || '취소 실패');
            }
        };
    }

    return { init };
})();
