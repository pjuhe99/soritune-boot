/* ── Multipass (다회권 확인) ────────────────────────────────── */
const AdminMultipassApp = (() => {
    let container = null;
    let admin = null;
    let cohorts = [];        // cohort_list 응답 cohort_details
    let activeSubTab = 'members';
    let searchTimer = null;
    let searchSeq = 0;

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
        const mySeq = ++searchSeq;
        const results = document.getElementById('mp-results');
        q = q.trim();
        if (!q) { results.innerHTML = ''; return; }
        results.innerHTML = '<p>검색 중...</p>';
        try {
            const r = await App.get('/api/admin.php?action=multipass_search_member&q=' + encodeURIComponent(q));
            if (mySeq !== searchSeq) return;  // stale response, newer search in flight
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
            if (mySeq !== searchSeq) return;  // stale
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
                    // 토글 성공 후 전체 재검색으로 최신 발급 상태 반영
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

    function openAddModal() {
        openPassModal({ mode: 'create' });
    }

    async function openEditModal(passId) {
        try {
            const r = await App.get('/api/admin.php?action=multipass_get&id=' + passId);
            openPassModal({ mode: 'edit', pass: r.pass });
        } catch (e) {
            Toast.error('불러오기 실패: ' + (e.message || e));
        }
    }

    function openPassModal({ mode, pass }) {
        const isEdit = mode === 'edit';
        const userId      = isEdit ? pass.user_id : '';
        const productName = isEdit ? pass.product_name : '';
        const note        = isEdit ? (pass.note || '') : '';
        const selectedIds = isEdit ? new Set(pass.cohorts.map(c => c.cohort_id)) : new Set();

        const cohortChecks = cohorts.map(c => {
            const checked = selectedIds.has(c.id);
            const inactive = !c.is_active ? 'mp-cohort-inactive' : '';
            return `<label class="mp-cohort-check ${inactive}">
                <input type="checkbox" value="${c.id}" ${checked ? 'checked' : ''}> ${App.esc(c.cohort)}
            </label>`;
        }).join('');

        const title = isEdit ? '다회권 수정' : '다회권 추가';
        const bodyHtml = `
            <div class="mp-modal-body">
                <div class="mp-form-row">
                    <label>구매자 user_id</label>
                    <input type="text" id="mp-f-userid" value="${App.esc(userId)}">
                    <button class="btn btn-secondary btn-xs" id="mp-f-lookup">회원 조회</button>
                    <span id="mp-f-lookup-result"></span>
                </div>
                <div class="mp-form-row">
                    <label>상품명</label>
                    <input type="text" id="mp-f-product" value="${App.esc(productName)}">
                </div>
                <div class="mp-form-row">
                    <label>포함 기수</label>
                    <div class="mp-cohort-checks">${cohortChecks}</div>
                </div>
                <div class="mp-form-row">
                    <label>메모(선택)</label>
                    <textarea id="mp-f-note" rows="2">${App.esc(note)}</textarea>
                </div>
                <div class="mp-form-actions">
                    <button class="btn btn-secondary" id="mp-f-cancel">취소</button>
                    <button class="btn btn-primary" id="mp-f-save">${isEdit ? '저장' : '추가'}</button>
                </div>
            </div>
        `;

        App.openModal(title, bodyHtml);

        document.querySelector('#mp-f-cancel').onclick = () => App.closeModal();
        document.querySelector('#mp-f-lookup').onclick = async () => {
            const uid = document.querySelector('#mp-f-userid').value.trim();
            if (!uid) return;
            try {
                const r = await App.get('/api/admin.php?action=multipass_search_member&q=' + encodeURIComponent(uid));
                const m = (r.members || []).find(x => x.user_id === uid);
                const out = document.querySelector('#mp-f-lookup-result');
                if (m && m.profiles && m.profiles.length) {
                    const p = m.profiles[0];
                    out.innerHTML = `<span class="mp-profile-ok">${App.esc(p.real_name || '')} / ${App.esc(p.nickname || '')}</span>`;
                } else {
                    out.innerHTML = `<span class="mp-profile-warn">⚠ boot 에 등록된 적 없는 user_id</span>`;
                }
            } catch (e) { /* swallow */ }
        };
        document.querySelector('#mp-f-save').onclick = async () => {
            const newUid     = document.querySelector('#mp-f-userid').value.trim();
            const newProduct = document.querySelector('#mp-f-product').value.trim();
            const newNote    = document.querySelector('#mp-f-note').value.trim() || null;
            const checkedIds = Array.from(document.querySelectorAll('.mp-cohort-checks input:checked')).map(cb => parseInt(cb.value));
            if (!newUid)          { Toast.error('user_id 필수'); return; }
            if (!newProduct)      { Toast.error('상품명 필수'); return; }
            if (!checkedIds.length) { Toast.error('포함 기수 1개 이상 선택'); return; }
            try {
                if (isEdit) {
                    await App.post('/api/admin.php?action=multipass_update', {
                        id: pass.id, user_id: newUid, product_name: newProduct, note: newNote, cohort_ids: checkedIds,
                    });
                    Toast.success('수정되었습니다.');
                } else {
                    await App.post('/api/admin.php?action=multipass_create', {
                        user_id: newUid, product_name: newProduct, note: newNote, cohort_ids: checkedIds,
                    });
                    Toast.success('다회권이 추가되었습니다.');
                }
                App.closeModal();
                // 검색 결과 갱신
                const cur = document.getElementById('mp-search-input');
                if (cur && cur.value) doSearch(cur.value);
            } catch (e) {
                Toast.error('저장 실패: ' + (e.message || e));
            }
        };
    }

    function openBulkModal() {
        let parsedRows = null;

        const bodyHtml = `
            <div class="mp-modal-body mp-bulk">
                <div class="mp-bulk-section">
                    <div class="mp-bulk-step">1. 템플릿</div>
                    <pre class="mp-bulk-template">user_id,product_name,cohorts
3937726826@k,11~13기 묶음권,"11,12,13"
4114325139@n,5~7기 패키지,"5|6|7"</pre>
                    <p class="mp-bulk-note">cohorts 컬럼: 쉼표·파이프·슬래시 분리. "11"·"11기" 모두 인식.</p>
                </div>

                <div class="mp-bulk-section">
                    <div class="mp-bulk-step">2. CSV 붙여넣기 또는 Excel 업로드</div>
                    <textarea id="mp-bulk-csv" rows="6" placeholder="여기에 CSV 붙여넣기"></textarea>
                    <div class="mp-bulk-upload">
                        <input type="file" id="mp-bulk-file" accept=".xlsx,.xls,.csv" style="display:none">
                        <button class="btn btn-secondary btn-sm" id="mp-bulk-file-btn">파일 선택 (Excel/CSV)</button>
                    </div>
                    <button class="btn btn-primary" id="mp-bulk-validate">검증</button>
                </div>

                <div class="mp-bulk-section" id="mp-bulk-result" style="display:none">
                    <div class="mp-bulk-step">3. 검증 결과</div>
                    <div id="mp-bulk-summary"></div>
                    <div id="mp-bulk-table"></div>
                    <button class="btn btn-primary" id="mp-bulk-apply">적용</button>
                </div>

                <div class="mp-form-actions">
                    <button class="btn btn-secondary" id="mp-bulk-close">닫기</button>
                </div>
            </div>
        `;

        App.openModal('다회권 CSV 일괄 등록', bodyHtml);

        document.querySelector('#mp-bulk-close').onclick = () => App.closeModal();

        document.querySelector('#mp-bulk-file-btn').onclick = () => document.querySelector('#mp-bulk-file').click();
        document.querySelector('#mp-bulk-file').onchange = async (ev) => {
            const file = ev.target.files[0];
            if (!file) return;
            const ext = file.name.split('.').pop().toLowerCase();
            if (ext === 'csv') {
                const text = await file.text();
                document.querySelector('#mp-bulk-csv').value = text;
            } else {
                // Excel — XLSX 사용 (operation/index.php 에서 이미 로드됨)
                const buf = await file.arrayBuffer();
                const wb = XLSX.read(buf);
                const ws = wb.Sheets[wb.SheetNames[0]];
                const csv = XLSX.utils.sheet_to_csv(ws);
                document.querySelector('#mp-bulk-csv').value = csv;
            }
        };

        document.querySelector('#mp-bulk-validate').onclick = async () => {
            const csv = document.querySelector('#mp-bulk-csv').value;
            if (!csv.trim()) { Toast.error('CSV 가 비어있습니다.'); return; }
            try {
                const r = await App.post('/api/admin.php?action=multipass_bulk_validate', { csv });
                parsedRows = r.rows || [];
                const summary = r.summary || { ok: 0, warn: 0, error: 0 };
                document.querySelector('#mp-bulk-summary').innerHTML = `
                    정상 <strong>${summary.ok}</strong>건 ·
                    WARN <strong style="color:#f59e0b">${summary.warn}</strong>건 ·
                    ERROR <strong style="color:#dc2626">${summary.error}</strong>건
                `;
                document.querySelector('#mp-bulk-table').innerHTML = renderBulkTable(parsedRows);
                document.querySelector('#mp-bulk-result').style.display = '';
            } catch (e) {
                Toast.error('검증 실패: ' + (e.message || e));
            }
        };

        document.querySelector('#mp-bulk-apply').onclick = async () => {
            if (!parsedRows) return;
            // 운영자 결정 (mode) 수집
            const applyRows = parsedRows
                .filter(r => !String(r.status || '').startsWith('ERROR_'))
                .map((r) => {
                    const modeSelect = document.querySelector(`select[data-row="${r.row}"]`);
                    const mode = modeSelect ? modeSelect.value : null;
                    return mode ? { ...r, mode } : r;
                });
            if (!applyRows.length) { Toast.error('적용할 행이 없습니다.'); return; }
            try {
                const r = await App.post('/api/admin.php?action=multipass_bulk_apply', { rows: applyRows });
                const failedMsg = r.failed && r.failed.length ?
                    `\n실패 ${r.failed.length}건:\n` + r.failed.map(f => `  행 ${f.row}: ${f.error}`).join('\n') : '';
                Toast.success(`적용 완료: ${r.applied}건${failedMsg}`);
                App.closeModal();
                const cur = document.getElementById('mp-search-input');
                if (cur && cur.value) doSearch(cur.value);
            } catch (e) {
                Toast.error('적용 실패: ' + (e.message || e));
            }
        };
    }

    function renderBulkTable(rows) {
        return `
            <table class="data-table mp-bulk-table">
                <thead>
                    <tr><th>#</th><th>user_id</th><th>상품명</th><th>기수</th><th>상태</th><th>처리</th></tr>
                </thead>
                <tbody>
                    ${rows.map(r => {
                        const status = r.status || '';
                        const statusClass = status.startsWith('ERROR_') ? 'mp-status-err'
                                          : status.startsWith('WARN_')  ? 'mp-status-warn' : 'mp-status-ok';
                        let modeCtrl = '';
                        if (status === 'WARN_DUPLICATE_PASS' || status === 'WARN_DUPLICATE_PASS_IN_BATCH') {
                            const target = status === 'WARN_DUPLICATE_PASS'
                                ? `기존 pass#${r.existing_pass_id}`
                                : `같은 파일 행 ${r.target_pass_in_batch}`;
                            modeCtrl = `
                                <select data-row="${r.row}">
                                    <option value="extend">extend (${target} 에 cohort 추가)</option>
                                    <option value="new">new (별도 다회권)</option>
                                    <option value="skip">skip (적용 안 함)</option>
                                </select>
                            `;
                        }
                        return `<tr>
                            <td>${r.row || ''}</td>
                            <td>${App.esc(r.user_id || '')}</td>
                            <td>${App.esc(r.product_name || '')}</td>
                            <td>${(r.cohort_labels || []).map(l => App.esc(l)).join(', ')}</td>
                            <td class="${statusClass}">${App.esc(status)}${r.unmatched_labels ? ' [' + r.unmatched_labels.map(l => App.esc(l)).join(',') + ']' : ''}</td>
                            <td>${modeCtrl}</td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>
        `;
    }

    function renderProductsView() { document.getElementById('mp-body').innerHTML = '<p>Task 12 에서 구현</p>'; }

    return { init };
})();
