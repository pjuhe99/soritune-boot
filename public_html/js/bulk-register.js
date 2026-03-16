/* ── Bulk Member Registration ──────────────────────────────── */
const BulkRegisterApp = (() => {
    let container = null;
    let parsedRows = [];
    let validationResult = null;

    const REQUIRED_KEYS = ['real_name', 'nickname'];
    const HEADER_KEY_MAP = {
        '이름': 'real_name', 'real_name': 'real_name', 'name': 'real_name', '성명': 'real_name',
        '닉네임': 'nickname', 'nickname': 'nickname', '별명': 'nickname',
        '아이디': 'user_id', 'user_id': 'user_id',
        '전화번호': 'phone', 'phone': 'phone', '연락처': 'phone', '휴대폰': 'phone', '핸드폰': 'phone', '휴대전화': 'phone',
        '단계': 'stage_no', 'stage_no': 'stage_no', 'stage': 'stage_no',
    };
    const KEY_LABELS = { real_name: '이름', nickname: '닉네임', user_id: '아이디', phone: '전화번호', stage_no: '단계' };
    const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
    const MAX_ROWS = 500;

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
                                <p class="bulk-file-hint">xlsx, xls, csv 지원 (최대 ${MAX_ROWS}명, 5MB)</p>
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
        document.getElementById('bulk-dl-csv').onclick = downloadCsvTemplate;
        document.getElementById('bulk-dl-xlsx').onclick = downloadXlsxTemplate;

        container.querySelectorAll('.bulk-upload-tab').forEach(tab => {
            tab.onclick = () => {
                container.querySelectorAll('.bulk-upload-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                const mode = tab.dataset.mode;
                document.getElementById('bulk-upload-file').style.display = mode === 'file' ? '' : 'none';
                document.getElementById('bulk-upload-paste').style.display = mode === 'paste' ? '' : 'none';
            };
        });

        const fileInput = document.getElementById('bulk-file-input');
        document.getElementById('bulk-file-btn').onclick = () => fileInput.click();
        fileInput.onchange = (e) => { if (e.target.files[0]) handleFile(e.target.files[0]); };

        const dz = document.getElementById('bulk-dropzone');
        dz.ondragover = (e) => { e.preventDefault(); dz.classList.add('dragover'); };
        dz.ondragleave = () => dz.classList.remove('dragover');
        dz.ondrop = (e) => {
            e.preventDefault();
            dz.classList.remove('dragover');
            if (e.dataTransfer.files[0]) handleFile(e.dataTransfer.files[0]);
        };

        document.getElementById('bulk-paste-btn').onclick = handlePaste;
        document.getElementById('bulk-register-btn').onclick = handleRegister;
        document.getElementById('bulk-reset-btn').onclick = resetState;
    }

    function resetState() {
        parsedRows = [];
        validationResult = null;
        render();
    }

    // ── 상태 표시 ──

    function showStatus(html) {
        const el = document.getElementById('bulk-file-status');
        el.style.display = '';
        el.innerHTML = html;
    }

    function hideDownstreamSections() {
        ['bulk-validation-section', 'bulk-preview-section', 'bulk-register-section'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
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
        for (let r = 1; r <= 2; r++) {
            const addr = XLSX.utils.encode_cell({ r, c: 3 });
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

    // ── 헤더 매핑 ──

    function mapHeaders(rawHeaders) {
        const mapped = {};   // rawHeader → key
        const matched = [];  // 매핑 성공 목록
        const ignored = [];  // 매핑 실패 목록

        rawHeaders.forEach(h => {
            const trimmed = h.trim();
            if (!trimmed) return;
            const key = HEADER_KEY_MAP[trimmed] || HEADER_KEY_MAP[trimmed.toLowerCase()];
            if (key) {
                mapped[h] = key;
                matched.push({ header: trimmed, key, label: KEY_LABELS[key] || key });
            } else {
                ignored.push(trimmed);
            }
        });

        // 필수 컬럼 체크
        const mappedKeys = new Set(Object.values(mapped));
        const missing = REQUIRED_KEYS.filter(k => !mappedKeys.has(k));

        return { mapped, matched, ignored, missing, mappedKeys };
    }

    function renderHeaderReport(headerResult, source) {
        const { matched, ignored, missing } = headerResult;

        let html = `<div class="bulk-header-report">`;
        html += `<div class="header-report-title">${App.esc(source)} 헤더 인식 결과</div>`;

        // 매핑 성공
        if (matched.length > 0) {
            html += `<div class="header-matched">`;
            html += matched.map(m =>
                `<span class="header-chip ok">${App.esc(m.header)} → ${App.esc(m.label)}</span>`
            ).join(' ');
            html += `</div>`;
        }

        // 무시된 헤더
        if (ignored.length > 0) {
            html += `<div class="header-ignored">`;
            html += `<span class="header-ignored-label">무시됨:</span> `;
            html += ignored.map(h => `<span class="header-chip ignored">${App.esc(h)}</span>`).join(' ');
            html += `</div>`;
        }

        // 필수 누락
        if (missing.length > 0) {
            html += `<div class="header-missing">`;
            html += `필수 컬럼 누락: `;
            html += missing.map(k => `<strong>${App.esc(KEY_LABELS[k] || k)}</strong>`).join(', ');
            html += `</div>`;
        }

        html += `</div>`;
        return html;
    }

    // ── 파일 파싱 (공통 진입점) ──

    async function handleFile(file) {
        hideDownstreamSections();

        // 파일 크기 체크
        if (file.size > MAX_FILE_SIZE) {
            showStatus(`<span class="text-danger">파일 크기 초과: ${(file.size / 1024 / 1024).toFixed(1)}MB (최대 5MB)</span>`);
            return;
        }

        // 확장자 체크
        const ext = file.name.split('.').pop().toLowerCase();
        if (!['csv', 'xlsx', 'xls'].includes(ext)) {
            showStatus(`<span class="text-danger">지원하지 않는 파일 형식입니다: .${App.esc(ext)}<br>xlsx, xls, csv 파일만 업로드 가능합니다.</span>`);
            return;
        }

        showStatus(`<span class="text-muted">파일 읽는 중: ${App.esc(file.name)}...</span>`);

        try {
            let result;
            if (ext === 'csv') {
                result = await parseCsv(file);
            } else {
                if (typeof XLSX === 'undefined') {
                    showStatus(`<span class="text-danger">Excel 라이브러리가 아직 로드되지 않았습니다. 잠시 후 다시 시도해주세요.</span>`);
                    return;
                }
                result = await parseXlsx(file);
            }

            finalizeParsing(result, file.name);
        } catch (e) {
            showStatus(`<span class="text-danger">파일 읽기 오류: ${App.esc(e.message)}</span>`);
        }
    }

    /**
     * 파싱 결과를 검증하고 상태를 표시하는 공통 함수
     * @param {{ rows: object[], headerResult: object }} result
     * @param {string} source  출처 이름 (파일명 or '붙여넣기')
     */
    async function finalizeParsing(result, source) {
        const { rows, headerResult } = result;
        let statusHtml = renderHeaderReport(headerResult, source);

        // 필수 헤더 누락 → 중단
        if (headerResult.missing.length > 0) {
            const missingNames = headerResult.missing.map(k => KEY_LABELS[k] || k).join(', ');
            statusHtml += `<div class="bulk-parse-error">필수 컬럼이 없습니다: <strong>${App.esc(missingNames)}</strong><br>템플릿을 다운로드하여 헤더를 확인해주세요.</div>`;
            showStatus(statusHtml);
            return;
        }

        // 데이터 없음
        if (rows.length === 0) {
            statusHtml += `<div class="bulk-parse-error">데이터 행이 없습니다. 헤더 아래에 데이터를 입력해주세요.</div>`;
            showStatus(statusHtml);
            return;
        }

        // 행 수 초과 경고 (서버에서도 차단하지만 미리 안내)
        if (rows.length > MAX_ROWS) {
            statusHtml += `<div class="bulk-parse-error">데이터가 ${rows.length}행입니다. 한 번에 최대 ${MAX_ROWS}명까지 등록 가능합니다.<br>파일을 나누어 업로드해주세요.</div>`;
            showStatus(statusHtml);
            return;
        }

        // 성공
        statusHtml += `<div class="bulk-parse-ok">${App.esc(source)} — <strong>${rows.length}명</strong> 파싱 완료</div>`;
        showStatus(statusHtml);

        parsedRows = rows;
        await validateRows();
    }

    // ── CSV 파싱 ──

    function parseCsv(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                try {
                    let text = e.target.result;

                    // EUC-KR 감지: UTF-8 BOM 없고 한글이 깨져 보이면 EUC-KR로 재시도
                    if (!text.startsWith('\uFEFF') && /[\uFFFD]/.test(text.substring(0, 500))) {
                        // fallback을 위해 reject하면 handleFile에서 재시도
                        reader.onerror = null;
                        const eucReader = new FileReader();
                        eucReader.onload = (e2) => {
                            try {
                                resolve(parseCsvText(e2.target.result));
                            } catch (err) { reject(err); }
                        };
                        eucReader.onerror = reject;
                        eucReader.readAsText(file, 'EUC-KR');
                        return;
                    }

                    // BOM 제거
                    if (text.startsWith('\uFEFF')) text = text.substring(1);

                    resolve(parseCsvText(text));
                } catch (err) { reject(err); }
            };
            reader.onerror = () => reject(new Error('파일을 읽을 수 없습니다.'));
            reader.readAsText(file, 'UTF-8');
        });
    }

    function parseCsvText(text) {
        const lines = text.split(/\r?\n/).filter(l => l.trim());
        if (lines.length === 0) {
            return { rows: [], headerResult: { mapped: {}, matched: [], ignored: [], missing: [...REQUIRED_KEYS], mappedKeys: new Set() } };
        }

        // 구분자 감지: 탭 > 쉼표
        const sep = lines[0].includes('\t') ? '\t' : ',';
        const rawHeaders = parseCsvLine(lines[0], sep).map(h => h.trim().replace(/^["']+|["']+$/g, ''));
        const headerResult = mapHeaders(rawHeaders);

        if (lines.length < 2) {
            return { rows: [], headerResult };
        }

        const rows = [];
        for (let i = 1; i < lines.length; i++) {
            const vals = parseCsvLine(lines[i], sep);
            if (vals.every(v => v.trim() === '')) continue; // 빈 행 무시

            const row = {};
            rawHeaders.forEach((h, idx) => {
                const key = headerResult.mapped[h];
                if (key) row[key] = String(vals[idx] || '').trim();
            });

            // 최소 하나의 값이라도 있어야 유효 행
            if (Object.values(row).some(v => v !== '' && v !== undefined)) {
                rows.push(row);
            }
        }

        return { rows, headerResult };
    }

    function parseCsvLine(line, sep) {
        if (sep === '\t') return line.split('\t');
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

    // ── XLSX 파싱 ──

    function parseXlsx(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                try {
                    const wb = XLSX.read(e.target.result, { type: 'array', cellText: true, cellDates: false });
                    const ws = wb.Sheets[wb.SheetNames[0]];

                    // raw: true로 원본 값을 가져오되, 전화번호는 문자열로 보정
                    const data = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', raw: true });

                    if (data.length === 0) {
                        resolve({ rows: [], headerResult: { mapped: {}, matched: [], ignored: [], missing: [...REQUIRED_KEYS], mappedKeys: new Set() } });
                        return;
                    }

                    const rawHeaders = data[0].map(h => String(h).trim());
                    const headerResult = mapHeaders(rawHeaders);

                    if (data.length < 2) {
                        resolve({ rows: [], headerResult });
                        return;
                    }

                    // 전화번호 컬럼 인덱스 찾기 (숫자→문자열 보정용)
                    const phoneColIdx = rawHeaders.findIndex(h => headerResult.mapped[h] === 'phone');

                    const rows = [];
                    for (let i = 1; i < data.length; i++) {
                        const vals = data[i];
                        if (!Array.isArray(vals)) continue;
                        if (vals.every(v => String(v).trim() === '')) continue; // 빈 행 무시

                        const row = {};
                        rawHeaders.forEach((h, idx) => {
                            const key = headerResult.mapped[h];
                            if (!key) return;

                            let val = vals[idx];

                            // 전화번호 숫자 보정: Excel에서 01012345678이 숫자 1012345678로 읽힐 수 있음
                            if (key === 'phone' && typeof val === 'number') {
                                val = String(val);
                                // 10자리 숫자이고 0으로 시작하지 않으면 앞에 0 붙이기
                                if (val.length === 10 && !val.startsWith('0')) {
                                    val = '0' + val;
                                }
                            }

                            row[key] = String(val ?? '').trim();
                        });

                        if (Object.values(row).some(v => v !== '' && v !== undefined)) {
                            rows.push(row);
                        }
                    }

                    resolve({ rows, headerResult });
                } catch (err) {
                    reject(new Error('Excel 파일을 읽을 수 없습니다. 파일이 손상되었거나 지원하지 않는 형식입니다.'));
                }
            };
            reader.onerror = () => reject(new Error('파일을 읽을 수 없습니다.'));
            reader.readAsArrayBuffer(file);
        });
    }

    // ── 복붙 처리 ──

    async function handlePaste() {
        const text = document.getElementById('bulk-paste-area').value.trim();
        if (!text) { Toast.warning('데이터를 붙여넣기 해주세요.'); return; }

        hideDownstreamSections();

        const lines = text.split(/\r?\n/).filter(l => l.trim());
        if (lines.length === 0) {
            showStatus('<div class="bulk-parse-error">데이터가 없습니다.</div>');
            return;
        }

        const sep = lines[0].includes('\t') ? '\t' : ',';
        const rawHeaders = lines[0].split(sep).map(h => h.trim());
        const headerResult = mapHeaders(rawHeaders);

        const rows = [];
        for (let i = 1; i < lines.length; i++) {
            const vals = lines[i].split(sep);
            if (vals.every(v => v.trim() === '')) continue;
            const row = {};
            rawHeaders.forEach((h, idx) => {
                const key = headerResult.mapped[h];
                if (key) row[key] = (vals[idx] || '').trim();
            });
            if (Object.values(row).some(v => v !== '' && v !== undefined)) {
                rows.push(row);
            }
        }

        await finalizeParsing({ rows, headerResult }, '붙여넣기');
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
        const corrections = s.corrections || 0;

        // 오류/경고를 자동보정과 분리
        const realWarnings = validationResult.warnings.filter(w => !w.includes('자동 보정:'));
        const correctionWarnings = validationResult.warnings.filter(w => w.includes('자동 보정:'));

        body.innerHTML = `
            <div class="bulk-summary ${s.error > 0 ? 'has-error' : 'all-ok'}">
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
                ${realWarnings.length > 0 ? `
                <div class="bulk-summary-item warning">
                    <span class="summary-num">${realWarnings.length}</span>
                    <span class="summary-label">경고</span>
                </div>` : ''}
                ${corrections > 0 ? `
                <div class="bulk-summary-item corrected">
                    <span class="summary-num">${corrections}</span>
                    <span class="summary-label">자동 보정</span>
                </div>` : ''}
            </div>
            ${validationResult.errors.length > 0 ? `
            <div class="bulk-error-list">
                <h4>오류 목록 (등록 불가)</h4>
                <ul>
                    ${validationResult.errors.map(e =>
                        `<li><strong>${e.row_num}행</strong> ${App.esc(e.real_name || '(이름없음)')} — ${e.errors.map(App.esc).join(', ')}</li>`
                    ).join('')}
                </ul>
            </div>` : ''}
            ${realWarnings.length > 0 ? `
            <div class="bulk-warning-list">
                <h4>경고 (등록은 가능)</h4>
                <ul>
                    ${realWarnings.map(w => `<li>${App.esc(w)}</li>`).join('')}
                </ul>
            </div>` : ''}
            ${correctionWarnings.length > 0 ? `
            <div class="bulk-correction-list">
                <h4>자동 보정 내역</h4>
                <ul>
                    ${correctionWarnings.map(w => `<li>${App.esc(w.replace('자동 보정: ', ''))}</li>`).join('')}
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
                        ${all.map(row => {
                            const hasCorrectedPhone = row.phone_raw && row.phone_raw !== row.phone && row.phone;
                            const hasCorrectedStage = row.stage_raw !== undefined && row.stage_raw !== '' && String(row.stage_raw) !== String(row.stage_no);
                            // 비고: 오류 → 경고(보정 제외) → 보정 순서
                            const notes = [];
                            if (row.errors && row.errors.length > 0) {
                                row.errors.forEach(e => notes.push(`<span class="text-danger">${App.esc(e)}</span>`));
                            }
                            if (row.warnings) {
                                row.warnings.filter(w => !w.startsWith('자동 보정:')).forEach(w =>
                                    notes.push(`<span class="text-warning">${App.esc(w)}</span>`)
                                );
                            }
                            if (row.corrections && row.corrections.length > 0) {
                                row.corrections.forEach(c =>
                                    notes.push(`<span class="text-corrected">${App.esc(c)}</span>`)
                                );
                            }
                            return `
                        <tr class="bulk-row-${row.status}">
                            <td>${row.row_num}</td>
                            <td>${statusBadge(row.status)}</td>
                            <td>${App.esc(row.real_name || '')}</td>
                            <td>${App.esc(row.nickname || '')}</td>
                            <td>${App.esc(row.user_id || '-')}</td>
                            <td>${hasCorrectedPhone
                                ? `<span class="val-corrected" title="원본: ${App.esc(row.phone_raw)}">${App.esc(row.phone)}</span>`
                                : App.esc(row.phone || '-')}</td>
                            <td>${hasCorrectedStage
                                ? `<span class="val-corrected" title="원본: ${App.esc(row.stage_raw)}">${row.stage_no}</span>`
                                : (row.stage_no || '-')}</td>
                            <td class="bulk-note-cell">${notes.join('<br>')}</td>
                        </tr>`;
                        }).join('')}
                    </tbody>
                </table>
            </div>
        `;

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
            const memberTab = document.querySelector('[data-hash="members"]');
            if (memberTab) memberTab.click();
        };
        document.getElementById('bulk-more-btn').onclick = resetState;
    }

    return { init };
})();
