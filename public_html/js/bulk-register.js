/* ── Bulk Member Registration ──────────────────────────────── */
const BulkRegisterApp = (() => {
    let container = null;
    let parsedRows = [];
    let validationResult = null;
    let uploadSource = '';  // 파일명 또는 '붙여넣기'

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
                        <p class="bulk-desc">아래 템플릿을 다운로드한 뒤, 회원 데이터를 입력하여 업로드하세요.</p>
                        <div class="bulk-template-info">
                            <table class="data-table bulk-template-table">
                                <thead>
                                    <tr>
                                        <th>이름 <span class="required">*필수</span></th>
                                        <th>닉네임 <span class="required">*필수</span></th>
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
                                        <td></td>
                                    </tr>
                                </tbody>
                            </table>

                            <div class="bulk-guide">
                                <div class="bulk-guide-title">컬럼별 작성 안내</div>
                                <dl class="bulk-guide-list">
                                    <dt>이름 <span class="required">*필수</span></dt>
                                    <dd>실명을 입력합니다. 비어 있으면 등록할 수 없습니다.</dd>
                                    <dt>닉네임 <span class="required">*필수</span></dt>
                                    <dd>부트캠프에서 사용할 이름입니다. 비어 있으면 등록할 수 없습니다.</dd>
                                    <dt>아이디</dt>
                                    <dd>카페 ID 등 외부 서비스 구분용입니다. 없으면 비워두세요.</dd>
                                    <dt>전화번호</dt>
                                    <dd>회원 로그인에 사용됩니다. 하이픈(-), 공백, 점(.) 포함 가능 (자동 제거).<br>없으면 비워둘 수 있지만, <strong>로그인이 불가</strong>합니다.</dd>
                                    <dt>단계</dt>
                                    <dd>1 또는 2를 입력합니다. 비워두면 자동으로 <strong>1단계</strong>가 됩니다.<br>"1단계", "stage2" 같은 표현도 자동 변환됩니다.</dd>
                                </dl>
                            </div>

                            <div class="bulk-caution">
                                <div class="bulk-caution-title">업로드 전 확인사항</div>
                                <ul>
                                    <li>첫 번째 행은 반드시 <strong>헤더</strong>여야 합니다 (이름, 닉네임, ...)</li>
                                    <li>같은 기수에 <strong>전화번호 또는 아이디가 이미 등록된 회원</strong>은 중복으로 제외됩니다</li>
                                    <li>같은 파일 안에 <strong>동일한 전화번호/아이디</strong>가 있으면 중복 오류가 됩니다</li>
                                    <li>조 배정은 등록 후 <strong>"조 배정" 탭</strong>에서 별도로 진행합니다</li>
                                    <li>한 번에 최대 <strong>${MAX_ROWS}명</strong>까지 등록 가능합니다</li>
                                </ul>
                            </div>
                        </div>
                        <div class="bulk-btn-row">
                            <button class="btn btn-secondary btn-sm" id="bulk-dl-csv">CSV 템플릿 다운로드</button>
                            <button class="btn btn-secondary btn-sm" id="bulk-dl-xlsx">Excel 템플릿 다운로드</button>
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
                                <p>파일을 여기에 끌어다 놓거나 버튼을 클릭하세요</p>
                                <button class="btn btn-primary btn-sm" id="bulk-file-btn">파일 선택</button>
                                <input type="file" id="bulk-file-input" accept=".xlsx,.xls,.csv" style="display:none">
                                <p class="bulk-file-hint">Excel(.xlsx, .xls) 또는 CSV 파일 / 최대 ${MAX_ROWS}명, 5MB</p>
                            </div>
                        </div>
                        <div class="bulk-upload-area" id="bulk-upload-paste" style="display:none">
                            <p class="bulk-desc">구글시트 또는 엑셀에서 <strong>헤더 포함</strong>하여 범위를 선택 → 복사(Ctrl+C) → 아래에 붙여넣기(Ctrl+V) 하세요.</p>
                            <textarea id="bulk-paste-area" class="form-input bulk-paste-textarea" placeholder="이름&#9;닉네임&#9;아이디&#9;전화번호&#9;단계&#10;홍길동&#9;길동이&#9;4114325139@n&#9;010-1234-5678&#9;1&#10;김철수&#9;Bella&#9;&#9;01098765432&#9;1" rows="8"></textarea>
                            <button class="btn btn-primary btn-sm mt-sm" id="bulk-paste-btn">데이터 적용</button>
                        </div>
                        <div id="bulk-file-status" class="bulk-file-status" style="display:none"></div>
                    </div>
                </div>

                <!-- Step 3: 검증 결과 + 미리보기 -->
                <div class="bulk-section" id="bulk-result-section" style="display:none">
                    <div class="bulk-section-header">
                        <span class="bulk-step-badge">3</span>
                        <span>검증 결과 및 미리보기</span>
                    </div>
                    <div class="bulk-section-body">
                        <div id="bulk-summary-area"></div>
                        <div id="bulk-detail-area"></div>
                        <div id="bulk-filter-area"></div>
                        <div id="bulk-preview-area"></div>
                    </div>
                </div>

                <!-- Step 4: 최종 등록 -->
                <div class="bulk-section" id="bulk-register-section" style="display:none">
                    <div class="bulk-section-body" id="bulk-register-body"></div>
                </div>

                <!-- 업로드 이력 -->
                <div class="bulk-section">
                    <div class="bulk-section-header">
                        <span class="bulk-step-badge" style="background:var(--text-secondary,#6b7280)">&#128203;</span>
                        <span>업로드 이력</span>
                    </div>
                    <div class="bulk-section-body" id="bulk-logs-area">
                        <div class="text-muted" style="font-size:0.88rem">로딩 중...</div>
                    </div>
                </div>
            </div>
        `;
        bindEvents();
        loadImportLogs();
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
        ['bulk-result-section', 'bulk-register-section'].forEach(id => {
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
        uploadSource = source;
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
        previewFilter = 'all';
        renderResult();
    }

    // ── 통합 결과 렌더 (요약 + 상세 + 필터 + 테이블 + 등록 버튼) ──

    let previewFilter = 'all'; // 'all' | 'ok' | 'warning' | 'error' | 'duplicate'

    function renderResult() {
        const sec = document.getElementById('bulk-result-section');
        sec.style.display = '';

        renderSummary();
        renderDetailLists();
        renderFilterBar();
        renderPreviewTable();
        renderRegisterButton();
    }

    function renderSummary() {
        const el = document.getElementById('bulk-summary-area');
        const s = validationResult.summary;
        const corrections = s.corrections || 0;
        const duplicates = s.duplicates || 0;

        el.innerHTML = `
            <div class="bulk-summary ${s.error > 0 ? 'has-error' : 'all-ok'}">
                <div class="bulk-summary-item">
                    <span class="summary-num">${s.total}</span>
                    <span class="summary-label">전체 업로드</span>
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
                ${duplicates > 0 ? `
                <div class="bulk-summary-item duplicate">
                    <span class="summary-num">${duplicates}</span>
                    <span class="summary-label">중복</span>
                </div>` : ''}
                ${corrections > 0 ? `
                <div class="bulk-summary-item corrected">
                    <span class="summary-num">${corrections}</span>
                    <span class="summary-label">자동 보정</span>
                </div>` : ''}
            </div>
        `;
    }

    function renderDetailLists() {
        const el = document.getElementById('bulk-detail-area');
        const realWarnings = validationResult.warnings.filter(w => !w.includes('자동 보정:'));
        const correctionWarnings = validationResult.warnings.filter(w => w.includes('자동 보정:'));

        let html = '';

        if (validationResult.errors.length > 0) {
            html += `
            <details class="bulk-detail-block error" open>
                <summary>오류 ${validationResult.errors.length}건 (등록 불가)</summary>
                <ul>
                    ${validationResult.errors.map(e =>
                        `<li><strong>${e.row_num}행</strong> ${App.esc(e.real_name || '(이름없음)')} — ${e.errors.map(App.esc).join(', ')}</li>`
                    ).join('')}
                </ul>
            </details>`;
        }

        if (realWarnings.length > 0) {
            html += `
            <details class="bulk-detail-block warning">
                <summary>경고 ${realWarnings.length}건 (등록은 가능)</summary>
                <ul>${realWarnings.map(w => `<li>${App.esc(w)}</li>`).join('')}</ul>
            </details>`;
        }

        if (correctionWarnings.length > 0) {
            html += `
            <details class="bulk-detail-block correction">
                <summary>자동 보정 ${correctionWarnings.length}건</summary>
                <ul>${correctionWarnings.map(w => `<li>${App.esc(w.replace('자동 보정: ', ''))}</li>`).join('')}</ul>
            </details>`;
        }

        el.innerHTML = html;
    }

    function renderFilterBar() {
        const el = document.getElementById('bulk-filter-area');
        const s = validationResult.summary;
        const all = [...validationResult.valid, ...validationResult.errors];
        const warningCount = validationResult.valid.filter(r => r.status === 'warning').length;
        const okCount = validationResult.valid.filter(r => r.status === 'ok').length;
        const dupCount = s.duplicates || 0;

        el.innerHTML = `
            <div class="bulk-filter-bar">
                <span class="bulk-filter-label">필터:</span>
                <button class="bulk-filter-btn ${previewFilter === 'all' ? 'active' : ''}" data-filter="all">전체 (${s.total})</button>
                ${okCount > 0 ? `<button class="bulk-filter-btn ${previewFilter === 'ok' ? 'active' : ''}" data-filter="ok">정상 (${okCount})</button>` : ''}
                ${warningCount > 0 ? `<button class="bulk-filter-btn ${previewFilter === 'warning' ? 'active' : ''}" data-filter="warning">경고 (${warningCount})</button>` : ''}
                ${s.error > 0 ? `<button class="bulk-filter-btn ${previewFilter === 'error' ? 'active' : ''}" data-filter="error">오류 (${s.error})</button>` : ''}
                ${dupCount > 0 ? `<button class="bulk-filter-btn ${previewFilter === 'duplicate' ? 'active' : ''}" data-filter="duplicate">중복 (${dupCount})</button>` : ''}
            </div>
        `;

        el.querySelectorAll('.bulk-filter-btn').forEach(btn => {
            btn.onclick = () => {
                previewFilter = btn.dataset.filter;
                renderFilterBar();
                renderPreviewTable();
            };
        });
    }

    function renderPreviewTable() {
        const el = document.getElementById('bulk-preview-area');

        let all = [...validationResult.valid, ...validationResult.errors]
            .sort((a, b) => a.row_num - b.row_num);

        // 필터 적용
        if (previewFilter === 'ok') all = all.filter(r => r.status === 'ok');
        else if (previewFilter === 'warning') all = all.filter(r => r.status === 'warning');
        else if (previewFilter === 'error') all = all.filter(r => r.status === 'error');
        else if (previewFilter === 'duplicate') all = all.filter(r => r.is_duplicate);

        if (all.length === 0) {
            el.innerHTML = `<div class="bulk-empty-filter">해당하는 행이 없습니다.</div>`;
            return;
        }

        el.innerHTML = `
            <div class="bulk-preview-scroll">
                <table class="data-table bulk-preview-table">
                    <thead>
                        <tr>
                            <th class="col-num">#</th>
                            <th class="col-status">상태</th>
                            <th>이름</th>
                            <th>닉네임</th>
                            <th>아이디</th>
                            <th>전화번호</th>
                            <th class="col-stage">단계</th>
                            <th class="col-note">비고</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${all.map(row => renderPreviewRow(row)).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    function renderPreviewRow(row) {
        const hasCorrectedPhone = row.phone_raw && row.phone_raw !== row.phone && row.phone;
        const hasCorrectedStage = row.stage_raw !== undefined && row.stage_raw !== '' && String(row.stage_raw) !== String(row.stage_no);

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
        <tr class="bulk-row-${row.status}${row.is_duplicate ? ' bulk-row-dup' : ''}">
            <td>${row.row_num}</td>
            <td>${statusBadge(row.status)}${row.is_duplicate ? '<span class="badge badge-dup" title="중복">DUP</span>' : ''}</td>
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
    }

    function renderRegisterButton() {
        const sec = document.getElementById('bulk-register-section');
        const body = document.getElementById('bulk-register-body');
        sec.style.display = '';

        const validCount = validationResult.valid.length;
        const errorCount = validationResult.summary.error;

        body.innerHTML = `
            <div class="bulk-register-confirm">
                ${errorCount > 0 ? `<p class="bulk-register-note text-danger">${errorCount}건의 오류는 제외되고, 정상 데이터만 등록됩니다.</p>` : ''}
                ${validCount > 0 ? `<p class="bulk-register-note">등록 후 조 배정은 별도로 진행합니다.</p>` : ''}
                <div class="bulk-register-actions">
                    <button class="btn btn-primary btn-lg" id="bulk-register-btn" ${validCount === 0 ? 'disabled' : ''}>
                        ${validCount > 0 ? `${validCount}명 등록하기` : '등록 가능한 데이터가 없습니다'}
                    </button>
                    <button class="btn btn-secondary btn-lg" id="bulk-reset-btn">초기화</button>
                </div>
            </div>
        `;

        document.getElementById('bulk-register-btn').onclick = handleRegister;
        document.getElementById('bulk-reset-btn').onclick = resetState;
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

        const errorCount = validationResult.summary.error;
        let msg = `${validMembers.length}명을 등록하시겠습니까?`;
        if (errorCount > 0) {
            msg += `\n\n(오류 ${errorCount}건은 제외됩니다)`;
        }
        msg += '\n\n조 배정은 등록 후 별도로 진행됩니다.';

        const confirmed = await App.confirm(msg);
        if (!confirmed) return;

        App.showLoading();
        const members = validMembers.map(m => ({
            real_name: m.real_name,
            nickname: m.nickname,
            user_id: m.user_id || '',
            phone: m.phone,
            stage_no: m.stage_no,
        }));

        const r = await App.post('/api/admin.php?action=member_bulk_register', {
            members,
            file_name: uploadSource,
            total_count: validationResult.summary.total,
            error_count: validationResult.summary.error,
            duplicate_count: validationResult.summary.duplicates || 0,
        });
        App.hideLoading();

        if (r.success) {
            Toast.success(r.message);
            renderSuccess(r.inserted);
        } else {
            Toast.error(r.error || '등록 실패');
        }
    }

    // ── 업로드 이력 ──

    async function loadImportLogs() {
        const el = document.getElementById('bulk-logs-area');
        if (!el) return;

        const r = await App.get('/api/admin.php?action=member_bulk_logs');
        if (!r.success || !r.logs || r.logs.length === 0) {
            el.innerHTML = '<div class="text-muted" style="font-size:0.88rem">업로드 이력이 없습니다.</div>';
            return;
        }

        el.innerHTML = `
            <div class="bulk-preview-scroll">
                <table class="data-table bulk-logs-table">
                    <thead>
                        <tr>
                            <th>일시</th>
                            <th>관리자</th>
                            <th>기수</th>
                            <th>파일</th>
                            <th>전체</th>
                            <th>성공</th>
                            <th>오류</th>
                            <th>중복</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${r.logs.map(log => `
                        <tr>
                            <td class="log-date">${formatLogDate(log.created_at)}</td>
                            <td>${App.esc(log.admin_name)}</td>
                            <td>${App.esc(log.cohort_name)}</td>
                            <td class="log-file" title="${App.esc(log.file_name || '')}">${App.esc(truncate(log.file_name || '-', 20))}</td>
                            <td>${log.total_count}</td>
                            <td class="text-success"><strong>${log.success_count}</strong></td>
                            <td>${log.error_count > 0 ? `<span class="text-danger">${log.error_count}</span>` : '0'}</td>
                            <td>${log.duplicate_count > 0 ? `<span style="color:#be185d">${log.duplicate_count}</span>` : '0'}</td>
                        </tr>`).join('')}
                    </tbody>
                </table>
            </div>
        `;
    }

    function formatLogDate(dt) {
        if (!dt) return '-';
        const d = new Date(dt);
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        const hh = String(d.getHours()).padStart(2, '0');
        const mi = String(d.getMinutes()).padStart(2, '0');
        return `${mm}/${dd} ${hh}:${mi}`;
    }

    function truncate(str, max) {
        return str.length > max ? str.substring(0, max - 1) + '…' : str;
    }

    function renderSuccess(insertedCount) {
        const s = validationResult.summary;
        const excludedCount = s.error;

        container.innerHTML = `
            <div class="bulk-register">
                <div class="bulk-success">
                    <div class="bulk-success-icon">&#10003;</div>
                    <h3>등록 완료</h3>

                    <div class="bulk-success-summary">
                        <div class="success-stat">
                            <span class="success-stat-num">${s.total}</span>
                            <span class="success-stat-label">전체 업로드</span>
                        </div>
                        <div class="success-stat ok">
                            <span class="success-stat-num">${insertedCount}</span>
                            <span class="success-stat-label">등록 완료</span>
                        </div>
                        ${excludedCount > 0 ? `
                        <div class="success-stat error">
                            <span class="success-stat-num">${excludedCount}</span>
                            <span class="success-stat-label">오류 제외</span>
                        </div>` : ''}
                    </div>

                    <div class="bulk-success-next">
                        <div class="bulk-success-next-title">다음 단계</div>
                        <ol class="bulk-success-steps">
                            <li><strong>회원 관리</strong> 탭에서 등록된 회원을 확인하세요</li>
                            <li><strong>조 배정</strong> 탭에서 회원들을 조에 배정하세요</li>
                        </ol>
                        <p class="bulk-success-note">모든 회원은 조 미배정 상태로 등록되었습니다.</p>
                    </div>

                    <div class="bulk-btn-row mt-md">
                        <button class="btn btn-primary" id="bulk-done-btn">회원 관리 탭으로 이동</button>
                        <button class="btn btn-secondary" id="bulk-more-btn">추가 등록하기</button>
                    </div>
                </div>
            </div>
        `;
        document.getElementById('bulk-done-btn').onclick = () => {
            // 회원 관리 탭의 loaded 플래그 초기화하여 목록 새로고침
            const memberTab = document.getElementById('tab-members');
            if (memberTab) memberTab.dataset.loaded = '';
            const memberBtn = document.querySelector('[data-hash="members"]');
            if (memberBtn) memberBtn.click();
        };
        document.getElementById('bulk-more-btn').onclick = resetState;
    }

    return { init };
})();
