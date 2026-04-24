/* ══════════════════════════════════════════════════════════════
   AdminNotify — /operation 알림톡 탭
   시나리오 목록/토글, 발송 전 미리보기 모달, 배치 이력/상세/재시도.
   스펙: docs/superpowers/specs/2026-04-23-notify-alimtalk-design.md
   ══════════════════════════════════════════════════════════════ */
const AdminNotify = (() => {
    const API = '/api/bootcamp.php?action=';

    let root = null;
    let scenarios = [];

    async function init(container) {
        root = container;
        await refresh();
    }

    async function refresh() {
        root.innerHTML = '<div class="loading">알림톡 시나리오 불러오는 중…</div>';
        const r = await App.get(API + 'notify_list_scenarios');
        if (!r.success) { root.innerHTML = `<div class="error">${App.esc(r.error || '오류')}</div>`; return; }
        scenarios = r.scenarios || [];
        render();
    }

    function render() {
        if (scenarios.length === 0) {
            root.innerHTML = '<div class="empty">등록된 시나리오가 없습니다.</div>';
            return;
        }
        const rows = scenarios.map(s => `
            <div class="notify-row" data-key="${App.esc(s.key)}">
                <div class="notify-row-head">
                    <strong>${App.esc(s.name)}</strong>
                    <span class="muted">${App.esc(s.schedule)}</span>
                    <label class="toggle">
                        <input type="checkbox" ${s.is_active ? 'checked' : ''} data-act="toggle">
                        <span>스케줄</span>
                    </label>
                    <button data-act="send-real">지금 발송</button>
                    <button data-act="send-dry">DRY 발송</button>
                    <button data-act="batches">이력</button>
                </div>
                <div class="notify-row-meta">
                    <span>다음 실행: ${App.esc(s.next_run_at || '-')}</span>
                    <span>마지막: ${App.esc(s.last_run_at || '-')} (${App.esc(s.last_run_status || '-')})</span>
                    <span>쿨다운 ${s.cooldown_hours}h / 최대 ${s.max_attempts}회</span>
                </div>
                <div class="notify-row-batches" data-role="batches"></div>
            </div>
        `).join('');
        root.innerHTML = `<h2>알림톡</h2>${rows}`;
        root.querySelectorAll('.notify-row').forEach(bindRow);
    }

    function bindRow(rowEl) {
        const key = rowEl.dataset.key;
        rowEl.querySelector('input[data-act="toggle"]').onchange = async (e) => {
            const r = await App.post(API + 'notify_toggle', { key, is_active: e.target.checked });
            if (!r.success) { Toast.error(r.error); refresh(); }
            else Toast.ok('변경되었습니다');
        };
        rowEl.querySelector('button[data-act="send-real"]').onclick = () => openPreview(key, false);
        rowEl.querySelector('button[data-act="send-dry"]').onclick  = () => openPreview(key, true);
        rowEl.querySelector('button[data-act="batches"]').onclick   = () => loadBatches(rowEl, key);
    }

    async function openPreview(key, dryRun) {
        App.showLoading();
        const r = await App.post(API + 'notify_preview', { key, dry_run: dryRun });
        App.hideLoading();
        if (!r.success) { Toast.error(r.error); return; }
        showPreviewModal(r);
    }

    function showPreviewModal(p) {
        const ovl = document.createElement('div');
        ovl.className = 'notify-modal-overlay';
        ovl.innerHTML = `
            <div class="notify-modal-box">
                <h3>발송 전 확인 — <span class="env env-${p.environment}">${p.environment}</span> ${p.dry_run ? '(DRY_RUN)' : '(실발송)'}</h3>
                <p>발송 대상: <strong>${p.target_count}명</strong>, 스킵: ${p.skip_count}명</p>
                <table class="notify-preview-table">
                    <thead><tr><th>이름</th><th>전화</th><th>상태</th></tr></thead>
                    <tbody>
                        ${p.candidates.map(c => `<tr><td>${App.esc(c.name)}</td><td>${App.esc(c.phone)}</td><td>발송예정</td></tr>`).join('')}
                        ${p.skips.map(s => `<tr class="skip"><td>${App.esc(s.name)}</td><td>${App.esc(s.phone)}</td><td>스킵 (${App.esc(s.reason)})</td></tr>`).join('')}
                    </tbody>
                </table>
                <div class="rendered">
                    <h4>본문 변수 미리보기 (첫 1건) — 템플릿 ${App.esc(p.template_id)}</h4>
                    <pre>${App.esc(JSON.stringify(p.rendered_first || {}, null, 2))}</pre>
                </div>
                <div class="notify-modal-actions">
                    <button data-act="cancel">취소</button>
                    <button data-act="confirm" ${p.target_count === 0 ? 'disabled' : ''}>${p.target_count}명에게 ${p.dry_run ? 'DRY 발송' : '지금 발송'}</button>
                </div>
            </div>
        `;
        document.body.appendChild(ovl);
        ovl.querySelector('button[data-act="cancel"]').onclick = () => ovl.remove();
        ovl.querySelector('button[data-act="confirm"]').onclick = async () => {
            App.showLoading();
            const r = await App.post(API + 'notify_send_now', { preview_id: p.preview_id });
            App.hideLoading();
            if (!r.success) { Toast.error(r.error); return; }
            Toast.ok(`발송 완료 (batch ${r.batch_id})`);
            ovl.remove();
            refresh();
        };
    }

    async function loadBatches(rowEl, key) {
        const target = rowEl.querySelector('[data-role="batches"]');
        target.innerHTML = '<em>불러오는 중…</em>';
        const r = await App.get(`${API}notify_list_batches&key=${encodeURIComponent(key)}&limit=15`);
        if (!r.success) { target.innerHTML = `<span class="error">${App.esc(r.error)}</span>`; return; }
        if (!r.batches || r.batches.length === 0) { target.innerHTML = '<em>이력 없음</em>'; return; }
        target.innerHTML = `
            <table class="notify-batch-table">
                <thead><tr><th>#</th><th>트리거</th><th>시작</th><th>대상</th><th>발송</th><th>실패</th><th>미확정</th><th>스킵</th><th>상태</th><th></th></tr></thead>
                <tbody>
                    ${r.batches.map(b => `
                        <tr>
                            <td>${b.id}</td>
                            <td>${App.esc(b.trigger_type)}${b.dry_run == 1 ? ' (DRY)' : ''}</td>
                            <td>${App.esc(b.started_at)}</td>
                            <td>${b.target_count}</td>
                            <td>${b.sent_count}</td>
                            <td>${b.failed_count}</td>
                            <td>${b.unknown_count}</td>
                            <td>${b.skipped_count}</td>
                            <td><span class="status status-${App.esc(b.status)}">${App.esc(b.status)}</span></td>
                            <td><button data-batch="${b.id}">상세</button></td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
            <div class="batch-detail" data-role="detail"></div>
        `;
        target.querySelectorAll('button[data-batch]').forEach(btn => {
            btn.onclick = () => showBatchDetail(target.querySelector('[data-role="detail"]'), Number(btn.dataset.batch));
        });
    }

    async function showBatchDetail(target, batchId) {
        target.innerHTML = '<em>불러오는 중…</em>';
        const r = await App.get(`${API}notify_batch_detail&batch_id=${batchId}`);
        if (!r.success) { target.innerHTML = `<span class="error">${App.esc(r.error)}</span>`; return; }
        const failedCount = (r.messages || []).filter(m => m.status === 'failed').length;
        target.innerHTML = `
            <h4>배치 #${r.batch.id} 메시지</h4>
            <table class="notify-msg-table">
                <thead><tr><th>이름</th><th>전화</th><th>채널</th><th>상태</th><th>사유</th></tr></thead>
                <tbody>
                    ${r.messages.map(m => `
                        <tr class="status-${App.esc(m.status)}">
                            <td>${App.esc(m.name || '')}</td>
                            <td>${App.esc(m.phone)}</td>
                            <td>${App.esc(m.channel_used)}</td>
                            <td>${App.esc(m.status)}${m.status === 'unknown' ? ' (조사 필요)' : ''}</td>
                            <td>${App.esc(m.skip_reason || m.fail_reason || '')}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
            ${failedCount > 0 ? `<button data-act="retry">실패자 ${failedCount}명 재시도</button>` : ''}
        `;
        const retryBtn = target.querySelector('button[data-act="retry"]');
        if (retryBtn) {
            retryBtn.onclick = async () => {
                if (!confirm(`failed 상태 ${failedCount}명에게 재발송합니다.`)) return;
                App.showLoading();
                const rr = await App.post(API + 'notify_retry_failed', { batch_id: batchId });
                App.hideLoading();
                if (!rr.success) { Toast.error(rr.error); return; }
                Toast.ok(`재시도 배치 #${rr.batch_id} 생성됨`);
            };
        }
    }

    return { init };
})();
