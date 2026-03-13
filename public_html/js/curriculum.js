/* ── CurriculumApp — 진도 관리 탭 ──────────────────────────── */
const CurriculumApp = (() => {

    const WEEKDAY_LABELS = ['일', '월', '화', '수', '목', '금', '토'];

    let admin = null;
    let tabEl = null;
    let taskTypes = []; // [{key, label}]

    // ── Public: init (lazy-load from AdminApp) ──
    async function initTab(containerEl, adminData) {
        admin = adminData;
        tabEl = containerEl;
        await loadTaskTypes();
        await loadTable();
    }

    // ── Task Types ──
    async function loadTaskTypes() {
        if (taskTypes.length) return;
        const r = await App.get('/api/admin.php?action=curriculum_task_types');
        if (r.success) taskTypes = r.task_types;
    }

    function getTypeLabel(key) {
        const t = taskTypes.find(x => x.key === key);
        return t ? t.label : key;
    }

    // ══════════════════════════════════════════════════════════
    // Table (목록)
    // ══════════════════════════════════════════════════════════

    async function loadTable() {
        tabEl.innerHTML = '<div class="empty-state">로딩 중...</div>';
        const r = await App.get('/api/admin.php?action=curriculum_list');
        if (!r.success) { tabEl.innerHTML = '<div class="empty-state">데이터를 불러올 수 없습니다.</div>'; return; }

        const items = r.items || [];

        tabEl.innerHTML = `
            <div class="mgmt-toolbar mt-md">
                <span style="font-weight:600">진도 관리 <span class="count">${items.length}개</span></span>
                <button class="btn btn-primary btn-sm" id="btn-curriculum-add">추가</button>
            </div>
            ${items.length ? renderTable(items) : '<div class="empty-state mt-md">등록된 진도가 없습니다.</div>'}
        `;

        document.getElementById('btn-curriculum-add').onclick = () => showCreateModal();
    }

    function renderTable(items) {
        const rows = items.map(item => {
            const dateKo = App.formatDateKo(item.target_date);
            const createdAt = (item.created_at || '').substring(0, 10);
            return `
                <tr>
                    <td style="white-space:nowrap">${App.esc(dateKo)}</td>
                    <td><span class="badge badge-primary">${App.esc(item.task_type_label)}</span></td>
                    <td>${App.esc(item.note || '-')}</td>
                    <td style="white-space:nowrap;color:var(--color-gray)">${App.esc(createdAt)}</td>
                </tr>
            `;
        }).join('');

        return `
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>날짜</th>
                            <th>할 일</th>
                            <th>비고</th>
                            <th>등록일</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        `;
    }

    // ══════════════════════════════════════════════════════════
    // Create Modal (진도 추가 팝업)
    // ══════════════════════════════════════════════════════════

    function showCreateModal() {
        const body = buildForm();
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">취소</button>
            <button class="btn btn-primary" id="cf-save">추가</button>
        `;
        App.openModal('진도 추가', body, footer);

        bindDateModeSwitch();
        document.getElementById('cf-save').onclick = handleSave;
    }

    // ══════════════════════════════════════════════════════════
    // Form Builder
    // ══════════════════════════════════════════════════════════

    function buildForm() {
        // Date mode selector
        const dateModeSection = `
            <div class="form-group">
                <label class="form-label">날짜 설정 방식 *</label>
                <div style="display:flex;flex-wrap:wrap;gap:8px;padding:4px 0">
                    <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">
                        <input type="radio" name="cf-date-mode" value="direct" checked> 날짜 직접 선택
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">
                        <input type="radio" name="cf-date-mode" value="week"> 주차/요일 선택
                    </label>
                </div>
            </div>
        `;

        // Direct date
        const directSection = `
            <div id="cf-mode-direct" class="cf-date-section">
                <div class="form-group">
                    <label class="form-label">날짜 *</label>
                    <input type="date" class="form-input" id="cf-date" value="${App.today()}">
                </div>
            </div>
        `;

        // Week/day (task_create week 모드 UI 동일)
        const weekSection = `
            <div id="cf-mode-week" class="cf-date-section" style="display:none">
                <div class="form-group">
                    <label class="form-label">주차 *</label>
                    <input type="number" class="form-input" id="cf-week-num" min="1" max="52" placeholder="예: 1">
                </div>
                <div class="form-group">
                    <label class="form-label">요일 *</label>
                    <select class="form-select" id="cf-weekday">
                        <option value="1">월요일</option>
                        <option value="2">화요일</option>
                        <option value="3">수요일</option>
                        <option value="4">목요일</option>
                        <option value="5">금요일</option>
                        <option value="6">토요일</option>
                        <option value="0">일요일</option>
                    </select>
                </div>
                <p class="text-muted" style="font-size:0.8rem;margin-top:-8px">* cohort 시작일 기준 N주차의 해당 요일로 날짜가 계산됩니다.</p>
            </div>
        `;

        // Task type selector
        const typeOptions = taskTypes.map(t =>
            `<option value="${App.esc(t.key)}">${App.esc(t.label)}</option>`
        ).join('');

        const typeSection = `
            <div class="form-group">
                <label class="form-label">할 일 *</label>
                <select class="form-select" id="cf-task-type">
                    <option value="">선택해주세요</option>
                    ${typeOptions}
                </select>
            </div>
        `;

        // Note
        const noteSection = `
            <div class="form-group">
                <label class="form-label">비고</label>
                <input type="text" class="form-input" id="cf-note" placeholder="선택 입력">
            </div>
        `;

        return dateModeSection + directSection + weekSection + typeSection + noteSection;
    }

    // ══════════════════════════════════════════════════════════
    // Date Mode Switch (직접선택 ↔ 주차/요일)
    // ══════════════════════════════════════════════════════════

    function bindDateModeSwitch() {
        const radios = document.querySelectorAll('input[name="cf-date-mode"]');
        radios.forEach(radio => {
            radio.addEventListener('change', () => {
                document.querySelectorAll('.cf-date-section').forEach(s => s.style.display = 'none');
                const target = document.getElementById('cf-mode-' + radio.value);
                if (target) target.style.display = '';
            });
        });
    }

    // ══════════════════════════════════════════════════════════
    // Date Calculation (날짜 계산)
    // ══════════════════════════════════════════════════════════

    function resolveDate() {
        const mode = document.querySelector('input[name="cf-date-mode"]:checked').value;

        if (mode === 'direct') {
            const date = document.getElementById('cf-date').value;
            if (!date) return { error: '날짜를 선택해주세요.' };
            return { target_date: date };
        }

        // week mode → server-side calculation
        const weekNumber = parseInt(document.getElementById('cf-week-num').value);
        const weekday = parseInt(document.getElementById('cf-weekday').value);
        if (!weekNumber || isNaN(weekday)) return { error: '주차와 요일을 선택해주세요.' };
        return { week_number: weekNumber, weekday: weekday };
    }

    // ══════════════════════════════════════════════════════════
    // Save Handler
    // ══════════════════════════════════════════════════════════

    async function handleSave() {
        const taskType = document.getElementById('cf-task-type').value;
        const note = document.getElementById('cf-note').value.trim();

        if (!taskType) return Toast.warning('할 일을 선택해주세요.');

        const dateResult = resolveDate();
        if (dateResult.error) return Toast.warning(dateResult.error);

        const payload = {
            task_type: taskType,
            note: note || null,
            ...dateResult,
        };

        App.showLoading();
        const r = await App.post('/api/admin.php?action=curriculum_create', payload);
        App.hideLoading();

        if (r.success) {
            App.closeModal();
            Toast.success(r.message);
            loadTable();
        }
    }

    // ── Public API ──
    return { initTab };
})();
