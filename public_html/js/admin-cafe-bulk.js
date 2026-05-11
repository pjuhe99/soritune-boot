/**
 * 카페 키 일괄 등록 paste 페이지.
 * 회원 관리 탭의 [카페 키 일괄 등록] 버튼이 호출하는 별도 view.
 *
 * 외부 인터페이스:
 *   AdminCafeBulkApp.show(container) — container 안에 렌더링
 *   AdminCafeBulkApp.hide()          — 회원 관리 탭으로 복귀
 */
const AdminCafeBulkApp = (() => {
    let containerEl = null;
    let onBack = null;
    let parsedRows = []; // 파싱 결과 (각 행 + 적용 체크 + dropdown 선택)

    function show(container, backFn) {
        containerEl = container;
        onBack = backFn || (() => {});
        render();
    }

    function hide() {
        if (typeof onBack === 'function') onBack();
    }

    function render() {
        containerEl.innerHTML = `
            <div style="padding: 16px;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                    <h2 style="margin:0;">카페 키 일괄 등록</h2>
                    <button class="btn btn-secondary" onclick="AdminCafeBulkApp.hide()">← 회원 관리</button>
                </div>
                <div style="background:#f9fafb; padding:12px; border-radius:6px; margin-bottom:12px;">
                    <div style="font-size:13px; color:#374151; margin-bottom:8px;">
                        카톡방에서 받은 정보를 <b>CSV</b>로 붙여넣으세요. (헤더 행은 있어도/없어도 OK)
                    </div>
                    <div style="font-family:monospace; font-size:12px; background:#fff; padding:8px; border:1px solid #e5e7eb; border-radius:4px;">
                        조,이름,닉,링크<br>
                        리사조,김명식,그릭이,https://cafe.naver.com/...<br>
                        무이조,이서연,서연쓰,https://m.cafe.naver.com/...
                    </div>
                </div>
                <textarea id="cb-csv" rows="8"
                    style="width:100%; font-family:monospace; font-size:13px; padding:8px;"
                    placeholder="여기에 CSV 붙여넣기"></textarea>
                <div style="margin-top:8px;">
                    <button class="btn btn-primary" id="cb-parse">파싱하기</button>
                </div>
                <div id="cb-preview" style="margin-top:16px;"></div>
                <div id="cb-result" style="margin-top:16px;"></div>
            </div>
        `;
        document.getElementById('cb-parse').onclick = doParse;
    }

    async function doParse() {
        const csv = document.getElementById('cb-csv').value;
        if (!csv.trim()) return Toast.warning('CSV 를 붙여넣으세요.');

        App.showLoading();
        const r = await App.post('/api/admin.php?action=cafe_bulk_parse', { csv });
        App.hideLoading();

        if (!r.success) return Toast.error(r.message || '파싱 실패');
        parsedRows = (r.data.rows || []).map(row => ({
            ...row,
            apply: row.status === 'HIGH',  // HIGH 만 기본 체크 ON
            selectedMemberId: (row.candidates && row.candidates[0]) ? row.candidates[0].member_id : null,
        }));
        renderPreview(r.data.summary);
    }

    function renderPreview(summary) {
        const el = document.getElementById('cb-preview');
        if (parsedRows.length === 0) { el.innerHTML = '<div>결과 없음</div>'; return; }

        el.innerHTML = `
            <h3>미리보기 (${parsedRows.length}행 — HIGH ${summary.high} / MID ${summary.mid} / LOW ${summary.low} / FAIL ${summary.fail} / SKIP ${summary.skip})</h3>
            <div style="margin-bottom:8px;">
                <button class="btn btn-sm btn-secondary" id="cb-check-all">전체 체크</button>
                <button class="btn btn-sm btn-secondary" id="cb-check-high">HIGH만 체크</button>
                <button class="btn btn-sm btn-secondary" id="cb-uncheck-all">전체 해제</button>
            </div>
            <div style="overflow-x:auto;">
            <table class="table" style="font-size:13px;">
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th>조</th>
                        <th>이름</th>
                        <th>닉</th>
                        <th>카페닉</th>
                        <th>매칭 회원</th>
                        <th>상태</th>
                        <th style="width:60px;">적용</th>
                    </tr>
                </thead>
                <tbody>${parsedRows.map(renderRow).join('')}</tbody>
            </table>
            </div>
            <div style="margin-top:12px;">
                <button class="btn btn-primary" id="cb-apply">
                    <span id="cb-apply-count">0</span>개 행 적용
                </button>
            </div>
        `;
        document.getElementById('cb-check-all').onclick = () => bulkCheck(_ => true);
        document.getElementById('cb-check-high').onclick = () => bulkCheck(r => r.status === 'HIGH');
        document.getElementById('cb-uncheck-all').onclick = () => bulkCheck(_ => false);
        document.getElementById('cb-apply').onclick = doApply;
        attachRowHandlers();
        updateApplyCount();
    }

    function renderRow(row) {
        const idx = parsedRows.indexOf(row);
        const statusColor = {
            HIGH: '#bbf7d0', MID: '#fef3c7', MID_MULTI: '#fef3c7', LOW: '#fef3c7',
            NO_MATCH: '#e5e7eb', ALREADY_MAPPED_SAME: '#f3f4f6',
            ALREADY_MAPPED_DIFF: '#fed7aa',
            CAFE_FETCH_FAIL: '#fecaca', INVALID_LINK: '#fecaca', WRONG_CAFE: '#fecaca',
            CSV_ERROR: '#fecaca', DUPLICATE_IN_BATCH: '#e5e7eb',
        }[row.status] || '#fff';
        const isSkip = ['ALREADY_MAPPED_SAME', 'DUPLICATE_IN_BATCH'].includes(row.status);
        const isFail = ['INVALID_LINK', 'WRONG_CAFE', 'CAFE_FETCH_FAIL', 'CSV_ERROR'].includes(row.status);
        const canApply = !isSkip && !isFail;

        let matchCellHtml = '';
        if (row.existing_member) {
            const em = row.existing_member;
            matchCellHtml = `이미: ${App.esc(em.real_name)} (${App.esc(em.group_name || '-')}) ${row.status === 'ALREADY_MAPPED_DIFF' ? '⚠️' : ''}`;
        } else if (row.candidates && row.candidates.length === 1 && row.status === 'HIGH') {
            const c = row.candidates[0];
            matchCellHtml = `${App.esc(c.real_name)} (${App.esc(c.group_name || '-')})`;
        } else if (row.candidates && row.candidates.length > 0) {
            // dropdown
            matchCellHtml = `<select class="form-select form-select-sm" data-idx="${idx}" data-role="member-select">
                <option value="">-- 선택 --</option>
                ${row.candidates.map(c => `<option value="${c.member_id}" ${c.member_id === row.selectedMemberId ? 'selected' : ''}>${App.esc(c.real_name)} (${App.esc(c.group_name || '-')})</option>`).join('')}
            </select>`;
        } else if (row.status === 'NO_MATCH') {
            matchCellHtml = `<input type="text" class="form-input form-input-sm" data-idx="${idx}" data-role="member-search" placeholder="회원 검색..." style="width:160px;">
                <select class="form-select form-select-sm" data-idx="${idx}" data-role="member-select" style="display:none;"></select>`;
        } else {
            matchCellHtml = '-';
        }

        return `<tr style="background:${statusColor};">
            <td>${row.row}</td>
            <td>${App.esc(row.group || '')}</td>
            <td>${App.esc(row.name || '')}</td>
            <td>${App.esc(row.nick || '')}</td>
            <td>${App.esc(row.cafe_nick || '')}</td>
            <td>${matchCellHtml}</td>
            <td><b>${row.status}</b>${row.error ? ` <span style="color:#dc2626;">${App.esc(row.error)}</span>` : ''}</td>
            <td>${canApply ? `<input type="checkbox" data-idx="${idx}" data-role="apply" ${row.apply ? 'checked' : ''}>` : ''}</td>
        </tr>`;
    }

    function attachRowHandlers() {
        document.querySelectorAll('input[data-role="apply"]').forEach(el => {
            el.onchange = (e) => {
                const idx = parseInt(e.target.dataset.idx);
                parsedRows[idx].apply = e.target.checked;
                updateApplyCount();
            };
        });
        document.querySelectorAll('select[data-role="member-select"]').forEach(el => {
            el.onchange = (e) => {
                const idx = parseInt(e.target.dataset.idx);
                parsedRows[idx].selectedMemberId = e.target.value ? parseInt(e.target.value) : null;
                updateApplyCount();
            };
        });
        // NO_MATCH 행의 검색 input — 1차 release 에서는 미구현 (placeholder만)
        document.querySelectorAll('input[data-role="member-search"]').forEach(el => {
            el.placeholder = '회원 검색 (다음 릴리스)';
            el.disabled = true;
        });
    }

    function bulkCheck(predicate) {
        parsedRows.forEach((r, i) => {
            const isSkip = ['ALREADY_MAPPED_SAME', 'DUPLICATE_IN_BATCH'].includes(r.status);
            const isFail = ['INVALID_LINK', 'WRONG_CAFE', 'CAFE_FETCH_FAIL', 'CSV_ERROR'].includes(r.status);
            if (isSkip || isFail) { r.apply = false; return; }
            r.apply = !!predicate(r);
        });
        renderPreview({ high: 0, mid: 0, low: 0, fail: 0, skip: 0 }); // summary 재계산 생략 (count 갱신만)
    }

    function updateApplyCount() {
        const count = parsedRows.filter(r => r.apply && r.selectedMemberId).length;
        const el = document.getElementById('cb-apply-count');
        if (el) el.textContent = count;
    }

    async function doApply() {
        const rows = parsedRows
            .filter(r => r.apply && r.selectedMemberId && r.member_key)
            .map(r => ({
                row: r.row,
                article_id: r.article_id,
                member_key: r.member_key,
                cafe_nick: r.cafe_nick,
                target_member_id: r.selectedMemberId,
            }));
        if (rows.length === 0) return Toast.warning('적용할 행이 없습니다.');

        if (!await App.confirm(`${rows.length}개 행 적용. 계속할까요?`)) return;

        App.showLoading();
        const r = await App.post('/api/admin.php?action=cafe_bulk_apply', { rows });
        App.hideLoading();

        if (!r.success) return Toast.error(r.message || '적용 실패');
        renderResult(r.data);
    }

    function renderResult(data) {
        const el = document.getElementById('cb-result');
        const s = data.summary;
        el.innerHTML = `
            <h3>적용 결과</h3>
            <div style="font-size:14px; margin-bottom:8px;">
                ✅ 적용 ${s.applied} / ⏭ 건너뜀 ${s.skipped} / ❌ 실패 ${s.failed}
            </div>
            <div style="overflow-x:auto;">
            <table class="table" style="font-size:13px;">
                <thead><tr><th>#</th><th>상태</th><th>회원</th><th>백필</th><th>미션</th><th>비고</th></tr></thead>
                <tbody>${(data.results || []).map(r => `
                    <tr>
                        <td>${r.row}</td>
                        <td>${r.status}</td>
                        <td>${r.member_id || '-'}</td>
                        <td>${r.backfilled_posts ?? '-'}</td>
                        <td>${r.missions_saved ?? '-'}</td>
                        <td>${r.displaced ? `옛 회원 displaced: ${r.displaced}` : ''}${r.reason ? App.esc(r.reason) : ''}</td>
                    </tr>
                `).join('')}</tbody>
            </table>
            </div>
            <div style="margin-top:12px;"><button class="btn btn-secondary" onclick="AdminCafeBulkApp.hide()">완료</button></div>
        `;
        Toast.success(`${s.applied}건 적용`);
    }

    return { show, hide };
})();
