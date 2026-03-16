/* ── Bulk Member Registration ──────────────────────────────── */
const BulkRegisterApp = (() => {
    let container = null;
    let parsedRows = [];       // 파싱된 원본 데이터
    let validationResult = null; // 서버 검증 결과
    let templateColumns = [];

    const EXPECTED_HEADERS = ['이름', '닉네임', '아이디', '전화번호', '단계'];
    const HEADER_KEY_MAP = {
        '이름': 'real_name', 'real_name': 'real_name', 'name': 'real_name',
        '닉네임': 'nickname', 'nickname': 'nickname',
        '아이디': 'user_id', 'user_id': 'user_id', 'id': 'user_id',
        '전화번호': 'phone', 'phone': 'phone', '연락처': 'phone', '휴대폰': 'phone',
        '단계': 'stage_no', 'stage_no': 'stage_no', 'stage': 'stage_no',
    };

    function init(el) {
        container = el;
        render();
    }

    function render() {
        container.innerHTML = `
            <div class="bulk-register">
                <h3 class="section-title">회원 일괄 등록</h3>

                <!-- Step 1: 템플릿 다운로드 -->
                <div class="bulk-section">
                    <div class="bulk-section-header">
                        <span class="bulk-step-badge">1</span>
                        <span>템플릿 다운로드</span>
                    </div>
                    <div class="bulk-section-body">
                        <p class="bulk-desc">아래 템플릿을 다운로드하여 데이터를 입력한 후 업로드해주세요.</p>
                        <div class="bulk-template-info">
                            <table class="data-table bulk-template-table">
                                <thead>
                                    <tr>
                                        <th>이름 <span class="required">*</span></th>
                                        <th>닉네임 <span class="required">*</span></th>
                                        <th>아이디</th>
                                        <th>전화번호</th>
                                        <th>단계</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="example-row">
                                        <td>홍길동</td>
                                        <td>길동이</td>
                                        <td>4114325139@n</td>
                                        <td>010-1234-5678</td>
                                        <td>1</td>
                                    </tr>
                                    <tr class="example-row">
                                        <td>김철수</td>
                                        <td>Bella</td>
                                        <td></td>
                                        <td>01098765432</td>
                                        <td>1</td>
                                    </tr>
                                </tbody>
                            </table>
                            <ul class="bulk-notes">
                                <li><span class="required">*</span> <strong>이름, 닉네임</strong>은 필수입니다.</li>
                                <li><strong>아이디</strong>: 카페/외부 서비스 구분용 (없으면 비워두세요)</li>
                                <li><strong>전화번호</strong>: 하이픈 포함/미포함 모두 가능 (자동 정규화). 로그인에 사용됩니다.</li>
                                <li><strong>단계</strong>: 1 또는 2 (미입력 시 1단계)</li>
                                <li>조 배정은 등록 후 별도로 진행합니다.</li>
                            </ul>
                        </div>
                        <div class="bulk-btn-row">
                            <button class="btn btn-secondary btn-sm" id="bulk-dl-csv">CSV 다운로드</button>
                            <button class="btn btn-secondary btn-sm" id="bulk-dl-xlsx">Excel 다운로드</button>
                        </div>
                    </div>
                </div>

                <!-- Step 2: 파일 업로드 -->
                <div class="bulk-section">
                    <div class="bulk-section-header">
                        <span class="bulk-step-badge">2</span>
                        <span>데이터 업로드</span>
                    </div>
                    <div class="bulk-section-body">
                        <div class="bulk-upload-tabs">
                            <button class="bulk-upload-tab active" data-mode="file">파일 업로드</button>
                            <button class="bulk-upload-tab" data-mode="paste">복사/붙여넣기</button>
                        </div>
                        <div class="bulk-upload-area" id="bulk-upload-file">
                            <div class="bulk-dropzone" id="bulk-dropzone">
                                <div class="dropzone-icon">&#128196;</div>
                                <p>파일을 여기에 끌어다 놓거나</p>
                                <button class="btn btn-primary btn-sm" id="bulk-file-btn">파일 선택</button>
                                <input type="file" id="bulk-file-input" accept=".xlsx,.xls,.csv" style="display:none">
                                <p class="bulk-file-hint">xlsx, xls, csv 지원</p>
                            </div>
                        </div>
                        <div class="bulk-upload-area" id="bulk-upload-paste" style="display:none">
                            <p class="bulk-desc">구글시트나 엑셀에서 데이터를 복사한 뒤 아래에 붙여넣기 하세요.</p>
                            <p class="bulk-desc" style="color:var(--text-muted)">첫 행은 헤더(이름, 닉네임, 아이디, 전화번호, 단계)로 인식됩니다.</p>
                            <textarea id="bulk-paste-area" class="form-input bulk-paste-textarea" placeholder="이름&#9;닉네임&#9;아이디&#9;전화번호&#9;단계&#10;홍길동&#9;길동이&#9;4114325139@n&#9;010-1234-5678&#9;1" rows="8"></textarea>
                            <button class="btn btn-primary btn-sm mt-sm" id="bulk-paste-btn">데이터 적용</button>
                        </div>
                        <div id="bulk-file-status" class="bulk-file-status" style="display:none"></div>
                    </div>
                </div>

                <!-- Step 3: 검증 결과 -->
                <div class="bulk-section" id="bulk-validation-section" style="display:none">
                    <div class="bulk-section-header">
                        <span class="bulk-step-badge">3</span>
                        <span>검증 결과</span>
                    </div>
                    <div class="bulk-section-body" id="bulk-validation-body"></div>
                </div>

                <!-- Step 4: 미리보기 -->
                <div class="bulk-section" id="bulk-preview-section" style="display:none">
                    <div class="bulk-section-header">
                        <span class="bulk-step-badge">4</span>
                        <span>미리보기</span>
                    </div>
                    <div class="bulk-section-body" id="bulk-preview-body"></div>
                </div>

                <!-- Step 5: 최종 등록 -->
                <div class="bulk-section" id="bulk-register-section" style="display:none">
                    <div class="bulk-section-body bulk-register-actions">
                        <button class="btn btn-primary btn-lg" id="bulk-register-btn" disabled>등록하기</button>
                        <button class="btn btn-secondary btn-lg" id="bulk-reset-btn">초기화</button>
                    </div>
                </div>
            </div>
        `;
        bindEvents();
    }

    function bindEvents() {
        // 템플릿 다운로드
        document.getElementById('bulk-dl-csv').onclick = downloadCsvTemplate;
        document.getElementById('bulk-dl-xlsx').onclick = downloadXlsxTemplate;

        // 업로드 탭 전환
        container.querySelectorAll('.bulk-upload-tab').forEach(tab => {
            tab.onclick = () => {
                container.querySelectorAll('.bulk-upload-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                const mode = tab.dataset.mode;
                document.getElementById('bulk-upload-file').style.display = mode === 'file' ? '' : 'none';
                document.getElementById('bulk-upload-paste').style.display = mode === 'paste' ? '' : 'none';
            };
        });

        // 파일 선택
        const fileInput = document.getElementById('bulk-file-input');
        document.getElementById('bulk-file-btn').onclick = () => fileInput.click();
        fileInput.onchange = (e) => { if (e.target.files[0]) handleFile(e.target.files[0]); };

        // 드래그 앤 드롭
        const dz = document.getElementById('bulk-dropzone');
        dz.ondragover = (e) => { e.preventDefault(); dz.classList.add('dragover'); };
        dz.ondragleave = () => dz.classList.remove('dragover');
        dz.ondrop = (e) => {
            e.preventDefault();
            dz.classList.remove('dragover');
            if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]);
        };

        // 복붙
        document.getElementById('bulk-paste-btn').onclick = handlePaste;

        // 등록
        document.getElementById('bulk-register-btn').onclick = handleRegister;
        document.getElementById('bulk-reset-btn').onclick = () => {
            parsedRows = [];
            validationResult = null;
            render();
        };
    }

    // ── 템플릿 다운로드 ──

    function downloadCsvTemplate() {
        const bom = '\uFEFF';
        const csv = bom + '이름,닉네임,아이디,전화번호,단계\n홍길동,길동이,4114325139@n,010-1234-5678,1\n김철수,Bella,,01098765432,1\n';
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
        downloadBlob(blob, '회원등록_템플릿.csv');
    }

    function downloadXlsxTemplate() {
        if (typeof XLSX === 'undefined') {
            Toast.warning('Excel 라이브러리 로딩 중... CSV로 대체합니다.');
            downloadCsvTemplate();
            return;
        }
        const ws = XLSX.utils.aoa_to_sheet([
            ['이름', '닉네임', '아이디', '전화번호', '단계'],
            ['홍길동', '길동이', '4114325139@n', '010-1234-5678', 1],
            ['김철수', 'Bella', '', '010-9876-5432', 1],
        ]);
        ws['!cols'] = [{ wch: 15 }, { wch: 15 }, { wch: 18 }, { wch: 18 }, { wch: 8 }];
        // 전화번호 컬럼을 텍스트 서식으로 설정 (엑셀에서 앞의 0이 잘리는 것 방지)
        const phoneCol = 3; // D열 (0-indexed)
        for (let r = 1; r <= 2; r++) {
            const addr = XLSX.utils.encode_cell({ r, c: phoneCol });
            if (ws[addr]) { ws[addr].t = 's'; ws[addr].z = '@'; }
        }
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, '회원등록');
        XLSX.writeFile(wb, '회원등록_템플릿.xlsx');
    }

    function downloadBlob(blob, filename) {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        a.click();
        URL.revokeObjectURL(a.href);
    }

    // ── 파일 파싱 ──

    async function handleFile(file) {
        const status = document.getElementById('bulk-file-status');
        status.style.display = '';
        status.innerHTML = `<span class="text-muted">파일 읽는 중: ${App.esc(file.name)}...</span>`;

        try {
            const ext = file.name.split('.').pop().toLowerCase();
            let rows;

            if (ext === 'csv') {
                rows = await parseCsv(file);
            } else if (ext === 'xlsx' || ext === 'xls') {
                if (typeof XLSX === 'undefined') {
                    status.innerHTML = '<span class="text-danger">Excel 라이브러리가 로드되지 않았습니다.</span>';
                    return;
                }
                rows = await parseXlsx(file);
            } else {
                status.innerHTML = '<span class="text-danger">지원하지 않는 파일 형식입니다.</span>';
                return;
            }

            if (!rows || rows.length === 0) {
                status.innerHTML = '<span class="text-danger">데이터가 없습니다.</span>';
                return;
            }

            status.innerHTML = `<span class="text-success">${App.esc(file.name)} - ${rows.length}행 파싱 완료</span>`;
            parsedRows = rows;
            await validateRows();
        } catch (e) {
            status.innerHTML = `<span class="text-danger">파일 읽기 오류: ${App.esc(e.message)}</span>`;
        }
    }

    function parseCsv(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                try {
                    const text = e.target.result;
                    const lines = text.split(/\r?\n/).filter(l => l.trim());
                    if (lines.length < 2) { resolve([]); return; }

                    const headerLine = lines[0];
                    // CSV 구분자 감지 (탭 or 쉼표)
                    const sep = headerLine.includes('\t') ? '\t' : ',';
                    const headers = headerLine.split(sep).map(h => h.trim().replace(/^["']|["']$/g, ''));
                    const keyMap = mapHeaders(headers);

                    const rows = [];
                    for (let i = 1; i < lines.length; i++) {
                        const vals = parseCsvLine(lines[i], sep);
                        if (vals.every(v => v.trim() === '')) continue;
                        const row = {};
                        headers.forEach((h, idx) => {
                            const key = keyMap[h];
                            if (key) row[key] = (vals[idx] || '').trim();
                        });
                        if (row.real_name || row.nickname) rows.push(row);
                    }
                    resolve(rows);
                } catch (err) { reject(err); }
            };
            reader.onerror = reject;
            reader.readAsText(file, 'UTF-8');
        });
    }

    function parseCsvLine(line, sep) {
        if (sep === '\t') return line.split('\t');
        // Handle quoted CSV fields
        const result = [];
        let current = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (inQuotes) {
                if (ch === '"' && line[i + 1] === '"') { current += '"'; i++; }
                else if (ch === '"') { inQuotes = false; }
                else { current += ch; }
            } else {
                if (ch === '"') { inQuotes = true; }
                else if (ch === sep) { result.push(current); current = ''; }
                else { current += ch; }
            }
        }
        result.push(current);
        return result;
    }

    function parseXlsx(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                try {
                    const wb = XLSX.read(e.target.result, { type: 'array' });
                    const ws = wb.Sheets[wb.SheetNames[0]];
                    const data = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
                    if (data.length < 2) { resolve([]); return; }

                    const headers = data[0].map(h => String(h).trim());
                    const keyMap = mapHeaders(headers);

                    const rows = [];
                    for (let i = 1; i < data.length; i++) {
                        const vals = data[i];
                        if (vals.every(v => String(v).trim() === '')) continue;
                        const row = {};
                        headers.forEach((h, idx) => {
                            const key = keyMap[h];
                            if (key) row[key] = String(vals[idx] || '').trim();
                        });
                        if (row.real_name || row.nickname) rows.push(row);
                    }
                    resolve(rows);
                } catch (err) { reject(err); }
            };
            reader.onerror = reject;
            reader.readAsArrayBuffer(file);
        });
    }

    function mapHeaders(headers) {
        const map = {};
        headers.forEach(h => {
            const lower = h.toLowerCase().trim();
            const key = HEADER_KEY_MAP[h] || HEADER_KEY_MAP[lower];
            if (key) map[h] = key;
        });
        return map;
    }

    // ── 복붙 처리 ──

    async function handlePaste() {
        const text = document.getElementById('bulk-paste-area').value.trim();
        if (!text) { Toast.warning('데이터를 붙여넣기 해주세요.'); return; }

        const lines = text.split(/\r?\n/).filter(l => l.trim());
        if (lines.length < 2) { Toast.warning('헤더 + 최소 1행의 데이터가 필요합니다.'); return; }

        // 탭 우선, 쉼표 폴백
        const sep = lines[0].includes('\t') ? '\t' : ',';
        const headers = lines[0].split(sep).map(h => h.trim());
        const keyMap = mapHeaders(headers);

        if (!Object.values(keyMap).includes('real_name') && !Object.values(keyMap).includes('nickname')) {
            Toast.error('헤더에 "이름" 또는 "닉네임" 컬럼이 필요합니다.');
            return;
        }

        const rows = [];
        for (let i = 1; i < lines.length; i++) {
            const vals = lines[i].split(sep);
            if (vals.every(v => v.trim() === '')) continue;
            const row = {};
            headers.forEach((h, idx) => {
                const key = keyMap[h];
                if (key) row[key] = (vals[idx] || '').trim();
            });
            if (row.real_name || row.nickname) rows.push(row);
        }

        if (rows.length === 0) { Toast.warning('유효한 데이터가 없습니다.'); return; }

        const status = document.getElementById('bulk-file-status');
        status.style.display = '';
        status.innerHTML = `<span class="text-success">붙여넣기 - ${rows.length}행 파싱 완료</span>`;
        parsedRows = rows;
        await validateRows();
    }

    // ── 서버 검증 ──

    async function validateRows() {
        if (parsedRows.length === 0) return;

        App.showLoading();
        const r = await App.post('/api/admin.php?action=member_bulk_validate', { rows: parsedRows });
        App.hideLoading();

        if (!r.success) {
            Toast.error(r.error || '검증 실패');
            return;
        }

        validationResult = r;
        renderValidation();
        renderPreview();
    }

    // ── 검증 결과 렌더 ──

    function renderValidation() {
        const sec = document.getElementById('bulk-validation-section');
        const body = document.getElementById('bulk-validation-body');
        sec.style.display = '';

        const s = validationResult.summary;
        const hasErrors = s.error > 0;
        const hasWarnings = s.warnings > 0;

        body.innerHTML = `
            <div class="bulk-summary ${hasErrors ? 'has-error' : 'all-ok'}">
                <div class="bulk-summary-item">
                    <span class="summary-num">${s.total}</span>
                    <span class="summary-label">전체</span>
                </div>
                <div class="bulk-summary-item ok">
                    <span class="summary-num">${s.valid}</span>
                    <span class="summary-label">등록 가능</span>
                </div>
                ${s.error > 0 ? `
                <div class="bulk-summary-item error">
                    <span class="summary-num">${s.error}</span>
                    <span class="summary-label">오류</span>
                </div>` : ''}
                ${hasWarnings ? `
                <div class="bulk-summary-item warning">
                    <span class="summary-num">${s.warnings}</span>
                    <span class="summary-label">경고</span>
                </div>` : ''}
            </div>
            ${validationResult.errors.length > 0 ? `
            <div class="bulk-error-list">
                <h4>오류 목록</h4>
                <ul>
                    ${validationResult.errors.map(e =>
                        `<li><strong>${e.row_num}행</strong> ${App.esc(e.real_name || '(이름없음)')} - ${e.errors.map(App.esc).join(', ')}</li>`
                    ).join('')}
                </ul>
            </div>` : ''}
            ${validationResult.warnings.length > 0 ? `
            <div class="bulk-warning-list">
                <h4>경고 목록</h4>
                <ul>
                    ${validationResult.warnings.map(w => `<li>${App.esc(w)}</li>`).join('')}
                </ul>
            </div>` : ''}
        `;
    }

    // ── 미리보기 렌더 ──

    function renderPreview() {
        const sec = document.getElementById('bulk-preview-section');
        const body = document.getElementById('bulk-preview-body');
        sec.style.display = '';

        const all = [...validationResult.valid, ...validationResult.errors]
            .sort((a, b) => a.row_num - b.row_num);

        body.innerHTML = `
            <div class="bulk-preview-scroll">
                <table class="data-table bulk-preview-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>상태</th>
                            <th>이름</th>
                            <th>닉네임</th>
                            <th>아이디</th>
                            <th>전화번호</th>
                            <th>단계</th>
                            <th>비고</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${all.map(row => `
                        <tr class="bulk-row-${row.status}">
                            <td>${row.row_num}</td>
                            <td>${statusBadge(row.status)}</td>
                            <td>${App.esc(row.real_name || '')}</td>
                            <td>${App.esc(row.nickname || '')}</td>
                            <td>${App.esc(row.user_id || '-')}</td>
                            <td>${App.esc(row.phone || '-')}</td>
                            <td>${row.stage_no || '-'}</td>
                            <td class="bulk-note-cell">${
                                row.errors && row.errors.length > 0
                                    ? row.errors.map(e => `<span class="text-danger">${App.esc(e)}</span>`).join('<br>')
                                    : row.warnings && row.warnings.length > 0
                                        ? row.warnings.map(w => `<span class="text-warning">${App.esc(w)}</span>`).join('<br>')
                                        : ''
                            }</td>
                        </tr>`).join('')}
                    </tbody>
                </table>
            </div>
        `;

        // 등록 버튼 활성화
        const regSec = document.getElementById('bulk-register-section');
        const regBtn = document.getElementById('bulk-register-btn');
        regSec.style.display = '';

        const validCount = validationResult.valid.length;
        if (validCount > 0) {
            regBtn.disabled = false;
            regBtn.textContent = `${validCount}명 등록하기`;
        } else {
            regBtn.disabled = true;
            regBtn.textContent = '등록 가능한 데이터가 없습니다';
        }
    }

    function statusBadge(status) {
        if (status === 'ok') return '<span class="badge badge-success">OK</span>';
        if (status === 'warning') return '<span class="badge badge-warning">경고</span>';
        return '<span class="badge badge-danger">오류</span>';
    }

    // ── 최종 등록 ──

    async function handleRegister() {
        const validMembers = validationResult.valid;
        if (validMembers.length === 0) return;

        const confirmed = await App.confirm(`${validMembers.length}명을 등록하시겠습니까?\n\n(조 배정은 등록 후 별도로 진행됩니다)`);
        if (!confirmed) return;

        App.showLoading();
        const members = validMembers.map(m => ({
            real_name: m.real_name,
            nickname: m.nickname,
            user_id: m.user_id || '',
            phone: m.phone,
            stage_no: m.stage_no,
        }));

        const r = await App.post('/api/admin.php?action=member_bulk_register', { members });
        App.hideLoading();

        if (r.success) {
            Toast.success(r.message);
            renderSuccess(r.inserted);
        } else {
            Toast.error(r.error || '등록 실패');
        }
    }

    function renderSuccess(count) {
        container.innerHTML = `
            <div class="bulk-register">
                <div class="bulk-success">
                    <div class="bulk-success-icon">&#10003;</div>
                    <h3>${count}명이 등록되었습니다</h3>
                    <p>조 배정은 "조 배정" 탭에서 진행해주세요.</p>
                    <div class="bulk-btn-row mt-md">
                        <button class="btn btn-primary" id="bulk-done-btn">확인</button>
                        <button class="btn btn-secondary" id="bulk-more-btn">추가 등록</button>
                    </div>
                </div>
            </div>
        `;
        document.getElementById('bulk-done-btn').onclick = () => {
            // 회원 관리 탭으로 이동
            const memberTab = document.querySelector('[data-hash="members"]');
            if (memberTab) memberTab.click();
        };
        document.getElementById('bulk-more-btn').onclick = () => {
            parsedRows = [];
            validationResult = null;
            render();
        };
    }

    return { init };
})();
