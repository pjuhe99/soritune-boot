/* ── BRAVO 시험 관리 (operation) ───────────────────────────── */
const AdminBravoExamApp = (() => {
    let container = null;
    let exams = [], levels = [], cohorts = [];
    let editingId = null;

    const STATUS = { preparing:'준비중', open:'오픈', closed:'종료', released:'결과발표' };
    const STATUS_KEYS = ['preparing','open','closed','released'];

    async function init(adminSession, containerId) {
        container = document.getElementById(containerId);
        if (!container) return;
        await load();
        render();
    }

    async function load() {
        const r = await App.get('/api/admin.php?action=bravo_exam_list');
        if (!r || r.success === false) { exams = []; levels = []; cohorts = []; return; }
        exams = r.exams || []; levels = r.levels || []; cohorts = r.cohorts || [];
    }

    function periodText(e) {
        if (e.exam_mode === 'always') return '상시';
        const s = (e.start_at || '').slice(0, 16);
        const en = (e.end_at || '').slice(0, 16);
        return `${s} ~ ${en}`;
    }

    function targetText(e) {
        return e.target_type === 'cohort'
            ? `${App.esc(e.target_cohort_label || '?')} 기수`
            : '전체';
    }

    function render() {
        if (!container) return;
        const rows = exams.map(e => `
            <tr>
                <td>${App.esc(e.title)}</td>
                <td>${App.esc(e.level_name || ('BRAVO ' + e.bravo_level))}</td>
                <td>${App.esc(periodText(e))}</td>
                <td>${targetText(e)}</td>
                <td class="num">${e.attempt_limit}회</td>
                <td>${STATUS[e.status] || App.esc(e.status)}</td>
                <td>
                    <button class="btn btn-sm bravo-exam-edit" data-id="${e.id}">수정</button>
                    <button class="btn btn-sm btn-danger bravo-exam-del" data-id="${e.id}">삭제</button>
                    <button class="btn btn-sm bravo-exam-ot" data-id="${e.id}">OT</button>
                </td>
            </tr>`).join('');

        container.innerHTML = `
            <div class="bravo-exam-admin">
                <div class="bravo-exam-toolbar">
                    <button class="btn btn-primary btn-sm" id="bravo-exam-new">+ 시험 추가</button>
                </div>
                <div id="bravo-exam-form"></div>
                <table class="data-table">
                    <thead><tr>
                        <th>시험명</th><th>등급</th><th>기간</th><th>대상</th><th>응시횟수</th><th>상태</th><th></th>
                    </tr></thead>
                    <tbody>${rows || '<tr><td colspan="7">등록된 시험이 없습니다.</td></tr>'}</tbody>
                </table>
            </div>`;

        container.querySelector('#bravo-exam-new').addEventListener('click', () => openForm(null));
        container.querySelectorAll('.bravo-exam-edit').forEach(b =>
            b.addEventListener('click', () => openForm(parseInt(b.dataset.id, 10))));
        container.querySelectorAll('.bravo-exam-del').forEach(b =>
            b.addEventListener('click', () => onDelete(parseInt(b.dataset.id, 10))));
        container.querySelectorAll('.bravo-exam-ot').forEach(b =>
            b.addEventListener('click', () => openOt(parseInt(b.dataset.id, 10))));
    }

    function toLocal(v) { return v ? v.slice(0, 16).replace(' ', 'T') : ''; }

    function openForm(id) {
        editingId = id;
        const e = id ? exams.find(x => parseInt(x.id, 10) === id) : null;
        const levelOpts = levels.map(l =>
            `<option value="${l.level}" ${e && parseInt(e.bravo_level, 10) === parseInt(l.level, 10) ? 'selected' : ''}>${App.esc(l.name)}</option>`).join('');
        const cohortOpts = cohorts.map(c =>
            `<option value="${c.id}" ${e && parseInt(e.target_cohort_id, 10) === parseInt(c.id, 10) ? 'selected' : ''}>${App.esc(c.cohort)}</option>`).join('');
        const mode = e ? e.exam_mode : 'period';
        const tgt = e ? e.target_type : 'all';
        const status = e ? e.status : 'preparing';
        const statusOpts = STATUS_KEYS.map(s =>
            `<option value="${s}" ${status === s ? 'selected' : ''}>${STATUS[s]}</option>`).join('');

        const formEl = container.querySelector('#bravo-exam-form');
        formEl.innerHTML = `
            <div class="bravo-exam-fields">
                <label>시험명 <input type="text" id="bx-title" value=""></label>
                <label>등급 <select id="bx-level">${levelOpts}</select></label>
                <label>응시방식 <select id="bx-mode">
                    <option value="period" ${mode === 'period' ? 'selected' : ''}>기간제</option>
                    <option value="always" ${mode === 'always' ? 'selected' : ''}>상시</option>
                </select></label>
                <span class="bx-dates">
                    <label>시작 <input type="datetime-local" id="bx-start" value="${toLocal(e && e.start_at)}"></label>
                    <label>종료 <input type="datetime-local" id="bx-end" value="${toLocal(e && e.end_at)}"></label>
                    <label>발표 <input type="datetime-local" id="bx-release" value="${toLocal(e && e.result_release_at)}"></label>
                </span>
                <label>응시횟수 <input type="number" id="bx-limit" min="1" value="${e ? e.attempt_limit : 3}" style="width:4em"></label>
                <label>대상 <select id="bx-target">
                    <option value="all" ${tgt === 'all' ? 'selected' : ''}>전체</option>
                    <option value="cohort" ${tgt === 'cohort' ? 'selected' : ''}>특정 기수</option>
                </select></label>
                <select id="bx-cohort" ${tgt === 'cohort' ? '' : 'disabled'}>${cohortOpts}</select>
                <label>상태 <select id="bx-status">${statusOpts}</select></label>
                <button class="btn btn-primary btn-sm" id="bx-save">저장</button>
                <button class="btn btn-sm" id="bx-cancel">취소</button>
            </div>`;

        formEl.querySelector('#bx-title').value = e ? e.title : '';

        const modeSel = formEl.querySelector('#bx-mode');
        const datesEl = formEl.querySelector('.bx-dates');
        const toggleDates = () => { datesEl.style.display = modeSel.value === 'always' ? 'none' : ''; };
        modeSel.addEventListener('change', toggleDates); toggleDates();

        const tgtSel = formEl.querySelector('#bx-target');
        const cohortSel = formEl.querySelector('#bx-cohort');
        tgtSel.addEventListener('change', () => { cohortSel.disabled = tgtSel.value !== 'cohort'; });

        formEl.querySelector('#bx-save').addEventListener('click', onSave);
        formEl.querySelector('#bx-cancel').addEventListener('click', () => { formEl.innerHTML = ''; editingId = null; });
    }

    function localToDt(v) { return v ? v.replace('T', ' ') + ':00' : null; }

    async function onSave() {
        const f = container.querySelector('#bravo-exam-form');
        const mode = f.querySelector('#bx-mode').value;
        const target = f.querySelector('#bx-target').value;
        const payload = {
            title: f.querySelector('#bx-title').value,
            bravo_level: parseInt(f.querySelector('#bx-level').value, 10),
            exam_mode: mode,
            start_at: mode === 'period' ? localToDt(f.querySelector('#bx-start').value) : null,
            end_at: mode === 'period' ? localToDt(f.querySelector('#bx-end').value) : null,
            result_release_at: mode === 'period' ? localToDt(f.querySelector('#bx-release').value) : null,
            attempt_limit: parseInt(f.querySelector('#bx-limit').value, 10) || 1,
            target_type: target,
            target_cohort_id: target === 'cohort' ? parseInt(f.querySelector('#bx-cohort').value, 10) : null,
            status: f.querySelector('#bx-status').value,
        };
        if (editingId) payload.id = editingId;
        const r = await App.post('/api/admin.php?action=bravo_exam_save', payload);
        if (r && r.success !== false) {
            Toast.success('저장되었습니다.');
            editingId = null;
            await load();
            render();
        } else {
            Toast.error((r && r.error) || '저장 실패');
        }
    }

    async function onDelete(id) {
        if (!confirm('이 시험을 삭제할까요?')) return;
        const r = await App.post('/api/admin.php?action=bravo_exam_delete', { id });
        if (r && r.success !== false) {
            Toast.success('삭제되었습니다.');
            await load();
            render();
        } else {
            Toast.error((r && r.error) || '삭제 실패');
        }
    }

    async function openOt(examId) {
        const r = await App.get('/api/admin.php?action=bravo_ot_get&exam_id=' + examId);
        const ot = (r && r.success !== false) ? (r.ot || {}) : {};
        const v = k => ot && ot[k] != null ? App.esc(ot[k]) : '';
        editingId = null; // 시험 편집 모드 해제(같은 host 공유)
        const host = container.querySelector('#bravo-exam-form');
        if (!host) return;
        host.innerHTML = `
            <div class="bravo-ot-form">
                <h4>시험 #${examId} OT 안내</h4>
                <label>OT 제목 <input type="text" id="ot-title" value="${v('title')}"></label>
                <label class="ot-wide">전체 안내문 <textarea id="ot-intro" rows="3">${v('intro_text')}</textarea></label>
                <label class="ot-wide">영상 URL <input type="text" id="ot-video" value="${v('video_url')}"></label>
                <label class="ot-wide">유형 1 안내문 <textarea id="ot-t1" rows="2">${v('type1_text')}</textarea></label>
                <label class="ot-wide">유형 2 안내문 <textarea id="ot-t2" rows="2">${v('type2_text')}</textarea></label>
                <label class="ot-wide">유형 3 안내문 <textarea id="ot-t3" rows="2">${v('type3_text')}</textarea></label>
                <label>필수 확인 체크 <input type="checkbox" id="ot-require" ${ot.require_check == null || parseInt(ot.require_check, 10) ? 'checked' : ''}></label>
                <div><button class="btn btn-primary btn-sm" id="ot-save">OT 저장</button>
                <button class="btn btn-sm" id="ot-cancel">닫기</button></div>
            </div>`;
        host.querySelector('#ot-save').addEventListener('click', () => saveOt(examId, host));
        host.querySelector('#ot-cancel').addEventListener('click', () => { host.innerHTML = ''; });
    }

    async function saveOt(examId, host) {
        const payload = {
            exam_id: examId,
            title: host.querySelector('#ot-title').value,
            intro_text: host.querySelector('#ot-intro').value,
            video_url: host.querySelector('#ot-video').value,
            type1_text: host.querySelector('#ot-t1').value,
            type2_text: host.querySelector('#ot-t2').value,
            type3_text: host.querySelector('#ot-t3').value,
            require_check: host.querySelector('#ot-require').checked ? 1 : 0,
        };
        const r = await App.post('/api/admin.php?action=bravo_ot_save', payload);
        if (r && r.success !== false) { Toast.success('OT가 저장되었습니다.'); host.innerHTML = ''; }
        else Toast.error((r && r.error) || 'OT 저장 실패');
    }

    return { init };
})();
