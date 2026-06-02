/* ── BRAVO 문제은행 (operation) ── */
const AdminBravoQuestionApp = (() => {
    let admin = null;
    let root = null;
    let questions = [];
    let editingId = null;

    const DIFF = { easy: '쉬움', normal: '보통', hard: '어려움' };
    const SOURCES = ['소리블록 훈련VOD문장', '줌특강 PPT', '부트캠프 훈련영상'];

    async function init(adminSession, containerId) {
        admin = adminSession;
        root = document.getElementById(containerId);
        if (!root) return;
        editingId = null;
        root.innerHTML = `
            <div class="bravo-q">
                <div class="bravo-q-filters">
                    <select id="bq-f-type"><option value="">유형 전체</option><option value="1">유형 1</option><option value="2">유형 2</option><option value="3">유형 3</option></select>
                    <select id="bq-f-level"><option value="">등급 전체</option><option value="1">BRAVO 1</option><option value="2">BRAVO 2</option><option value="3">BRAVO 3</option></select>
                    <select id="bq-f-diff"><option value="">난이도 전체</option><option value="easy">쉬움</option><option value="normal">보통</option><option value="hard">어려움</option></select>
                    <select id="bq-f-active"><option value="">활성 전체</option><option value="1">활성</option><option value="0">비활성</option></select>
                    <input type="text" id="bq-f-kw" placeholder="문장 검색">
                    <button class="btn btn-sm" id="bq-search">검색</button>
                    <button class="btn btn-primary btn-sm" id="bq-new">+ 문제 추가</button>
                </div>
                <div id="bq-form"></div>
                <table class="data-table bravo-q-table">
                    <thead><tr><th>유형</th><th>등급</th><th>한국어</th><th>영어</th><th>난이도</th><th>활성</th><th></th></tr></thead>
                    <tbody id="bq-tbody"></tbody>
                </table>
            </div>`;
        root.querySelector('#bq-search').addEventListener('click', load);
        root.querySelector('#bq-new').addEventListener('click', () => openForm(null));
        await load();
    }

    function filterQuery() {
        const q = new URLSearchParams();
        const t = root.querySelector('#bq-f-type').value; if (t) q.set('question_type', t);
        const l = root.querySelector('#bq-f-level').value; if (l) q.set('bravo_level', l);
        const d = root.querySelector('#bq-f-diff').value; if (d) q.set('difficulty', d);
        const a = root.querySelector('#bq-f-active').value; if (a !== '') q.set('is_active', a);
        const k = root.querySelector('#bq-f-kw').value.trim(); if (k) q.set('keyword', k);
        return q.toString();
    }

    async function load() {
        const qs = filterQuery();
        const r = await App.get('/api/admin.php?action=bravo_question_list' + (qs ? '&' + qs : ''));
        questions = (r && r.success !== false) ? (r.questions || []) : [];
        renderRows();
    }

    function renderRows() {
        const tb = root.querySelector('#bq-tbody');
        if (!questions.length) { tb.innerHTML = '<tr><td colspan="7">문제가 없습니다.</td></tr>'; return; }
        tb.innerHTML = questions.map(q => `
            <tr data-id="${q.id}">
                <td>유형 ${q.question_type}</td>
                <td>BRAVO ${q.bravo_level}</td>
                <td>${App.esc((q.korean_text || '').slice(0, 30))}</td>
                <td>${App.esc((q.english_text || '').slice(0, 30))}</td>
                <td>${DIFF[q.difficulty] || App.esc(q.difficulty)}</td>
                <td>${parseInt(q.is_active, 10) ? '활성' : '비활성'}</td>
                <td>
                    <button class="btn btn-sm bq-edit" data-id="${q.id}">수정</button>
                    <button class="btn btn-sm btn-danger bq-del" data-id="${q.id}">삭제</button>
                </td>
            </tr>`).join('');
        tb.querySelectorAll('.bq-edit').forEach(b => b.addEventListener('click', () => openForm(parseInt(b.dataset.id, 10))));
        tb.querySelectorAll('.bq-del').forEach(b => b.addEventListener('click', () => onDelete(parseInt(b.dataset.id, 10))));
    }

    function openForm(id) {
        editingId = id;
        const q = id ? questions.find(x => x.id === id) : null;
        const sel = (v, opts) => opts.map(o => `<option value="${o.v}" ${String(v) === String(o.v) ? 'selected' : ''}>${o.t}</option>`).join('');
        const typeOpts = sel(q && q.question_type, [{v:1,t:'유형 1'},{v:2,t:'유형 2'},{v:3,t:'유형 3'}]);
        const levelOpts = sel(q && q.bravo_level, [{v:1,t:'BRAVO 1'},{v:2,t:'BRAVO 2'},{v:3,t:'BRAVO 3'}]);
        const diffOpts = sel(q ? q.difficulty : 'normal', [{v:'easy',t:'쉬움'},{v:'normal',t:'보통'},{v:'hard',t:'어려움'}]);
        const f = root.querySelector('#bq-form');
        f.innerHTML = `
            <div class="bravo-q-form">
                <h4>${id ? '문제 수정' : '문제 추가'}</h4>
                <label>유형 <select id="bq-type">${typeOpts}</select></label>
                <label>등급 <select id="bq-level">${levelOpts}</select></label>
                <label>난이도 <select id="bq-diff">${diffOpts}</select></label>
                <label>출제원천 <input type="text" id="bq-source" list="bq-source-list" value="${q ? App.esc(q.source || '') : ''}"></label>
                <datalist id="bq-source-list">${SOURCES.map(s => `<option value="${App.esc(s)}">`).join('')}</datalist>
                <label class="bq-wide">한국어 문장 <textarea id="bq-ko" rows="2">${q ? App.esc(q.korean_text || '') : ''}</textarea></label>
                <label class="bq-wide">기준 영어 문장 <textarea id="bq-en" rows="2">${q ? App.esc(q.english_text || '') : ''}</textarea></label>
                <label class="bq-wide">타겟 청크 <input type="text" id="bq-chunks" value="${q ? App.esc(q.target_chunks || '') : ''}"></label>
                <label class="bq-wide">허용 정답 (1줄 1개) <textarea id="bq-accepted" rows="2">${q ? App.esc(q.accepted_answers || '') : ''}</textarea></label>
                <label>기준 발화(초) <input type="number" step="0.1" min="0" id="bq-ref" value="${q && q.reference_speech_sec !== null ? q.reference_speech_sec : ''}" style="width:6em"></label>
                <label>반응속도(초) <input type="number" step="0.1" min="0" id="bq-resp" value="${q && q.response_time_limit_sec !== null ? q.response_time_limit_sec : ''}" style="width:6em"></label>
                <label>활성 <input type="checkbox" id="bq-active" ${!q || parseInt(q.is_active, 10) ? 'checked' : ''}></label>
                <div><button class="btn btn-primary btn-sm" id="bq-save">저장</button>
                <button class="btn btn-sm" id="bq-cancel">취소</button></div>
            </div>`;
        f.querySelector('#bq-save').addEventListener('click', onSave);
        f.querySelector('#bq-cancel').addEventListener('click', () => { f.innerHTML = ''; editingId = null; });
    }

    async function onSave() {
        const f = root.querySelector('#bq-form');
        const payload = {
            id: editingId || 0,
            question_type: parseInt(f.querySelector('#bq-type').value, 10),
            bravo_level: parseInt(f.querySelector('#bq-level').value, 10),
            difficulty: f.querySelector('#bq-diff').value,
            source: f.querySelector('#bq-source').value,
            korean_text: f.querySelector('#bq-ko').value,
            english_text: f.querySelector('#bq-en').value,
            target_chunks: f.querySelector('#bq-chunks').value,
            accepted_answers: f.querySelector('#bq-accepted').value,
            reference_speech_sec: f.querySelector('#bq-ref').value,
            response_time_limit_sec: f.querySelector('#bq-resp').value,
            is_active: f.querySelector('#bq-active').checked ? 1 : 0,
        };
        const r = await App.post('/api/admin.php?action=bravo_question_save', payload);
        if (r && r.success !== false) {
            Toast.success('저장되었습니다.');
            f.innerHTML = ''; editingId = null;
            await load();
        } else {
            Toast.error((r && r.error) || '저장 실패');
        }
    }

    async function onDelete(id) {
        if (!confirm('이 문제를 삭제할까요?')) return;
        const r = await App.post('/api/admin.php?action=bravo_question_delete', { id });
        if (r && r.success !== false) { Toast.success('삭제되었습니다.'); await load(); }
        else Toast.error((r && r.error) || '삭제 실패');
    }

    return { init };
})();
