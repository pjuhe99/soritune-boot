/* ══════════════════════════════════════════════════════════════
   AdminGrowthRecords — /operation 성장기록 탭
   제출 목록(음성 재생/다운로드, 동의 여부) + 취소 + 접수/마감/가이드 설정.
   스펙: docs/superpowers/specs/2026-06-11-growth-record-submission-design.md
   ══════════════════════════════════════════════════════════════ */
const AdminGrowthRecords = (() => {
    const API = '/api/bootcamp.php?action=';

    let state = { cohorts: [], cohortId: 0, status: 'active', q: '' };

    async function init(container) {
        container.innerHTML = `
            <div class="admin-growth-page">
                <div class="admin-growth-settings" id="agr-settings">
                    <div class="agr-settings-title">접수 설정</div>
                    <div class="agr-settings-row">
                        <label class="agr-toggle">
                            <input type="checkbox" id="agr-toggle-enabled" disabled>
                            <span>성장기록 접수</span>
                        </label>
                        <label>마감: <input type="text" id="agr-deadline" placeholder="2026-06-16 23:59:59" size="20"></label>
                        <button class="btn btn-small btn-secondary" id="agr-deadline-save">마감 저장</button>
                        <button class="btn btn-small btn-secondary" id="agr-guide-edit">가이드 수정</button>
                        <span class="agr-settings-hint">마감 이후/접수 OFF 시 회원 제출이 차단됩니다.</span>
                    </div>
                </div>
                <div class="admin-growth-filters">
                    <label>기수: <select id="agr-cohort"><option value="0">전체</option></select></label>
                    <label>상태:
                        <select id="agr-status">
                            <option value="active">활성</option>
                            <option value="cancelled">취소됨</option>
                            <option value="all">전체</option>
                        </select>
                    </label>
                    <label>닉네임: <input type="text" id="agr-q" placeholder="검색"></label>
                    <button class="btn btn-secondary" id="agr-reload">조회</button>
                </div>
                <div class="admin-growth-counts" id="agr-counts"></div>
                <div class="admin-growth-list" id="agr-list"></div>
            </div>
        `;

        await loadSettings();

        const cr = await App.get(API + 'cohorts');
        state.cohorts = cr.cohorts || [];
        const sel = document.getElementById('agr-cohort');
        sel.innerHTML = '<option value="0">전체</option>' + state.cohorts.map(c =>
            `<option value="${c.id}">${App.esc(c.cohort)}</option>`).join('');

        sel.onchange = () => { state.cohortId = parseInt(sel.value); load(); };
        document.getElementById('agr-status').onchange = (e) => { state.status = e.target.value; load(); };
        document.getElementById('agr-reload').onclick = () => {
            state.q = document.getElementById('agr-q').value.trim();
            load();
        };
        document.getElementById('agr-q').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') document.getElementById('agr-reload').click();
        });

        await load();
    }

    async function loadSettings() {
        const toggle = document.getElementById('agr-toggle-enabled');
        const deadline = document.getElementById('agr-deadline');
        const r = await App.get(API + 'growth_record_settings');
        if (!r.success) { toggle.disabled = true; return; }
        toggle.checked = !!r.enabled;
        toggle.disabled = false;
        deadline.value = r.deadline || '';

        toggle.onchange = async () => {
            toggle.disabled = true;
            const res = await App.post(API + 'growth_record_settings_save',
                { key: 'growth_record_enabled', value: toggle.checked ? 'on' : 'off' });
            toggle.disabled = false;
            if (res.success) Toast.success(`접수: ${toggle.checked ? 'ON' : 'OFF'}`);
            else { toggle.checked = !toggle.checked; }
        };
        document.getElementById('agr-deadline-save').onclick = async () => {
            const res = await App.post(API + 'growth_record_settings_save',
                { key: 'growth_record_deadline', value: deadline.value.trim() });
            if (res.success) Toast.success('마감일 저장됨');
        };
        document.getElementById('agr-guide-edit').onclick = () => showGuideModal();
    }

    async function showGuideModal() {
        const r = await App.get(API + 'growth_record_settings');
        const guide = r.success ? (r.guide || '') : '';
        App.openModal('성장기록 가이드 수정', `
            <div class="agr-guide-modal">
                <textarea id="agr-guide-text" rows="16" class="form-input" maxlength="5000">${App.esc(guide)}</textarea>
                <div class="agr-guide-actions">
                    <button class="btn btn-secondary" id="agr-guide-close">닫기</button>
                    <button class="btn btn-primary" id="agr-guide-save">저장</button>
                </div>
            </div>
        `);
        document.getElementById('agr-guide-close').onclick = () => App.closeModal();
        document.getElementById('agr-guide-save').onclick = async () => {
            const value = document.getElementById('agr-guide-text').value;
            const res = await App.post(API + 'growth_record_settings_save',
                { key: 'growth_record_guide', value });
            if (res.success) { Toast.success('가이드 저장됨'); App.closeModal(); }
        };
    }

    async function load() {
        const list = document.getElementById('agr-list');
        const counts = document.getElementById('agr-counts');
        list.innerHTML = '<div class="empty-state">조회 중…</div>';

        const params = new URLSearchParams({ status: state.status });
        if (state.cohortId) params.set('cohort_id', String(state.cohortId));
        if (state.q) params.set('q', state.q);

        const r = await App.get(API + 'growth_records_list&' + params.toString());
        if (!r.success) {
            list.innerHTML = '<div class="empty-state">조회 실패</div>';
            counts.innerHTML = '';
            return;
        }

        counts.innerHTML = `전체 ${r.counts.total} · 활성 ${r.counts.active} · 취소 ${r.counts.cancelled}`;
        if (!r.items.length) {
            list.innerHTML = '<div class="empty-state">제출된 성장기록이 없습니다.</div>';
            return;
        }

        list.innerHTML = `
            <table class="admin-growth-table">
                <thead>
                    <tr>
                        <th>제출일</th><th>기수</th><th>조</th><th>닉네임</th><th>후기</th>
                        <th>동의</th><th>상태</th><th></th>
                    </tr>
                </thead>
                <tbody>${r.items.map(renderRow).join('')}</tbody>
            </table>
        `;

        list.querySelectorAll('.agr-cancel-btn[data-id]').forEach(btn => {
            btn.addEventListener('click', () => showCancelModal(btn.dataset));
        });
    }

    function audioCell(id, which) {
        const label = which === 'before' ? 'Before' : 'After';
        const src = `${API}growth_record_audio_admin&id=${id}&which=${which}`;
        return `<span class="agr-media-item">
                    <span class="agr-media-label">${label}</span>
                    <audio controls preload="none" class="agr-audio" src="${src}"></audio>
                    <a href="${src}&download=1" title="다운로드" class="agr-dl-link">⬇</a>
                </span>`;
    }

    function renderRow(item) {
        const submitted = (item.submitted_at || '').slice(5, 16);
        const cancelled = !!item.cancelled_at;
        const rowClass = cancelled ? 'agr-row-cancelled' : '';
        const statusCell = cancelled
            ? `<span class="agr-status agr-status-cancelled">취소됨</span>`
            : `<span class="agr-status agr-status-active">활성</span>`;
        const actionCell = cancelled ? '' :
            `<button class="btn btn-small btn-danger agr-cancel-btn"
                     data-id="${item.id}" data-nickname="${App.esc(item.nickname || '')}"
                     data-submitted="${App.esc(submitted)}">취소</button>`;
        const mediaRow = `<tr class="agr-media-row ${rowClass}">
                <td colspan="8">${audioCell(item.id, 'before')}${audioCell(item.id, 'after')}</td>
            </tr>`;
        const cancelRow = cancelled
            ? `<tr class="agr-cancel-meta ${rowClass}"><td colspan="8">└ 사유: "${App.esc(item.cancel_reason || '')}" · by ${App.esc(item.cancelled_by_name || '?')} · ${App.esc((item.cancelled_at || '').slice(5, 16))}</td></tr>`
            : '';
        return `
            <tr class="${rowClass}">
                <td>${App.esc(submitted)}</td>
                <td>${App.esc(item.cohort_label || '-')}</td>
                <td>${App.esc(item.group_name || '-')}</td>
                <td>${App.esc(item.nickname || '')}</td>
                <td><a href="${App.esc(item.url)}" target="_blank" rel="noopener noreferrer">↗ 링크</a></td>
                <td title="${App.esc(item.consent_agreed_at || '')}">${item.consent_agreed_at ? '✓' : '✗'}</td>
                <td>${statusCell}</td>
                <td>${actionCell}</td>
            </tr>
            ${mediaRow}
            ${cancelRow}
        `;
    }

    function showCancelModal(ds) {
        App.openModal('성장기록 취소', `
            <div class="agr-cancel-modal">
                <div>닉네임: ${App.esc(ds.nickname)} · 제출: ${App.esc(ds.submitted)}</div>
                <div class="agr-cancel-warn">※ 취소하면 회원이 다시 제출할 수 있습니다. 코인 변동은 없습니다.</div>
                <label class="agr-cancel-label">취소 사유 (필수)</label>
                <textarea id="agr-cancel-reason" maxlength="255" rows="3" class="form-input"></textarea>
                <div class="agr-cancel-actions">
                    <button class="btn btn-secondary" id="agr-cancel-close">닫기</button>
                    <button class="btn btn-danger" id="agr-cancel-confirm">취소 처리</button>
                </div>
            </div>
        `);
        document.getElementById('agr-cancel-close').onclick = () => App.closeModal();
        document.getElementById('agr-cancel-confirm').onclick = async () => {
            const reason = document.getElementById('agr-cancel-reason').value.trim();
            if (!reason) { Toast.error('취소 사유를 입력해주세요.'); return; }
            App.showLoading();
            const r = await App.post(API + 'growth_record_cancel', { id: parseInt(ds.id), cancel_reason: reason });
            App.hideLoading();
            if (r.success) { Toast.success(r.message); App.closeModal(); await load(); }
        };
    }

    return { init };
})();
