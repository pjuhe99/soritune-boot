/* ── BRAVO 채점 (operation) — 응시 목록 → 녹음 듣고 문항별 판정 → 자동 환산 → 확정 ── */
const AdminBravoGradingApp = (() => {
    let admin = null;
    let root = null;
    let exams = [];
    let currentExamId = 0;
    let attempts = [];
    let detail = null;           // bravo_grading_detail 응답
    let currentAttemptId = 0;
    let pending = {};            // answer_id → 진행 중 판정 선택값 (저장 전)
    let saving = {};             // answer_id → true (POST in-flight)
    let cancelling = false;      // 확정 취소 더블클릭 가드

    const ACCURACY = [['correct', '정답'], ['partial', '부분'], ['wrong', '오답']];
    const RATING = [['good', '좋음'], ['normal', '보통'], ['poor', '미흡']];

    async function init(adminSession, containerId) {
        admin = adminSession;
        root = document.getElementById(containerId);
        if (!root) return;
        await loadExams();
        renderList();
    }

    async function loadExams() {
        const r = await App.get('/api/admin.php?action=bravo_grading_exam_list');
        exams = (r && r.success !== false) ? (r.exams || []) : [];
    }

    async function loadAttempts(examId) {
        const r = await App.get('/api/admin.php?action=bravo_grading_attempt_list&exam_id=' + examId);
        attempts = (r && r.success !== false) ? (r.attempts || []) : [];
    }

    function statusChip(a) {
        if (a.confirmed) return `<span class="grading-chip done">확정 ${a.confirmed.total_score}점 · ${a.confirmed.result === 'pass' ? '합격' : '불합격'}</span>`;
        if (a.graded_count > 0) return `<span class="grading-chip ing">채점중 ${a.graded_count}/${a.total_count}</span>`;
        return '<span class="grading-chip none">미채점</span>';
    }

    function renderList() {
        const opts = exams.map(e =>
            `<option value="${e.id}" ${e.id === currentExamId ? 'selected' : ''}>[BRAVO ${e.bravo_level}] ${App.esc(e.title)} (${e.status}) — 응시 ${e.counts.total} · 미채점 ${e.counts.ungraded} · 채점중 ${e.counts.grading} · 확정 ${e.counts.confirmed}</option>`).join('');
        const rows = attempts.map(a => `
            <tr>
                <td>${App.esc(a.member_name || '')}<br><small>${App.esc(a.cohort || '')}</small></td>
                <td class="num">${a.attempt_no}회차</td>
                <td>${App.esc((a.submitted_at || '').slice(0, 16))}</td>
                <td>${statusChip(a)}</td>
                <td><button class="btn btn-primary btn-sm grading-open" data-id="${a.attempt_id}">채점</button></td>
            </tr>`).join('');

        root.innerHTML = `
            <div class="bravo-grading">
                <div class="bravo-help">
                    <strong>채점</strong> — 제출된 응시를 회원별로 채점하고 합불을 확정합니다.
                    <ul class="bravo-help-list">
                        <li>위에서 시험을 고르면 제출된 응시 목록이 나옵니다. 각 행의 <b>채점</b>으로 문항별 판정을 입력하세요.</li>
                        <li>채점을 마치면 <b>확정</b>합니다. 회원에게는 시험을 <b>결과발표</b> 상태로 바꿔야 점수·합불이 공개됩니다.</li>
                    </ul>
                </div>
                <div class="grading-toolbar">
                    <select id="grading-exam">
                        <option value="0">시험 선택 (제출된 응시가 있는 시험만)</option>${opts}
                    </select>
                </div>
                <div class="bravo-table-wrap">
                <table class="data-table grading-table">
                    <thead><tr><th>회원</th><th>회차</th><th>제출일시</th><th>상태</th><th></th></tr></thead>
                    <tbody>${rows || '<tr><td colspan="5">시험을 선택하세요.</td></tr>'}</tbody>
                </table>
                </div>
                <div id="grading-detail"></div>
            </div>`;

        root.querySelector('#grading-exam').addEventListener('change', async (e) => {
            currentExamId = parseInt(e.target.value, 10) || 0;
            detail = null; currentAttemptId = 0;
            if (currentExamId) await loadAttempts(currentExamId); else attempts = [];
            renderList();
        });
        root.querySelectorAll('.grading-open').forEach(b =>
            b.addEventListener('click', () => openDetail(parseInt(b.dataset.id, 10))));
    }

    async function openDetail(attemptId) {
        const r = await App.get('/api/admin.php?action=bravo_grading_detail&attempt_id=' + attemptId);
        if (!r || r.success === false) return;
        detail = r;
        currentAttemptId = attemptId;
        pending = {};
        renderDetail();
    }

    function judgeBtns(answerId, field, options, selected) {
        return options.map(([val, label]) =>
            `<button class="btn btn-sm grading-judge ${selected === val ? 'on btn-primary' : ''}" data-answer="${answerId}" data-field="${field}" data-val="${val}">${label}</button>`).join('');
    }

    function toggleBtns(answerId, field, selected) {
        return [[1, '예'], [0, '아니오']].map(([val, label]) =>
            `<button class="btn btn-sm grading-judge ${selected === val ? 'on btn-primary' : ''}" data-answer="${answerId}" data-field="${field}" data-val="${val}">${label}</button>`).join('');
    }

    function itemCard(item, level, confirmed) {
        const q = item.question, g = item.grade;
        const sel = pending[item.answer_id] || (g ? {
            accuracy: g.accuracy, chunk_ok: g.chunk_ok,
            response_rating: g.response_rating, fluency_rating: g.fluency_rating,
            completion_ok: g.completion_ok,
        } : {});
        const dur = item.answer.duration_ms != null ? (item.answer.duration_ms / 1000).toFixed(1) + 's' : '';
        const ro = confirmed ? 'disabled' : '';
        return `
            <div class="grading-card" data-answer="${item.answer_id}">
                <div class="grading-q">
                    <strong>#${item.seq + 1} 유형${q.question_type}</strong> ${App.esc(q.korean_text || '')}
                    <div class="grading-answer-key">
                        <span>기준: ${App.esc(q.english_text || '')}</span>
                        ${q.accepted_answers ? `<span>허용: ${App.esc(q.accepted_answers)}</span>` : ''}
                        ${q.target_chunks ? `<span>청크: ${App.esc(q.target_chunks)}</span>` : ''}
                    </div>
                </div>
                <div class="grading-audio">
                    <audio controls preload="none" src="/api/admin.php?action=bravo_answer_audio&answer_id=${item.answer_id}"></audio>
                    <small>${dur}${item.answer.retake_used ? ' · 재녹음' : ''}</small>
                </div>
                <fieldset class="grading-judges" ${ro}>
                    <div>정답도: ${judgeBtns(item.answer_id, 'accuracy', ACCURACY, sel.accuracy)}</div>
                    <div>청크: ${toggleBtns(item.answer_id, 'chunk_ok', sel.chunk_ok)}</div>
                    <div>반응: ${judgeBtns(item.answer_id, 'response_rating', RATING, sel.response_rating)}</div>
                    <div>유창: ${judgeBtns(item.answer_id, 'fluency_rating', RATING, sel.fluency_rating)}</div>
                    ${level >= 2 ? `<div>완성: ${toggleBtns(item.answer_id, 'completion_ok', sel.completion_ok)}</div>` : ''}
                </fieldset>
                <div class="grading-score">${g ? `점수 <strong>${g.score}</strong>` : '<em>미판정</em>'}</div>
                <input type="text" class="grading-memo" data-answer="${item.answer_id}" value="${App.esc((g && g.memo) || '')}" placeholder="문항 메모" maxlength="255" ${confirmed ? 'disabled' : ''}>
            </div>`;
    }

    function renderDetail() {
        const host = root.querySelector('#grading-detail');
        if (!host || !detail) return;
        const level = detail.exam.bravo_level;
        const confirmed = detail.confirmed;
        const s = detail.summary;
        const autoText = s.auto_result === null
            ? `판정 ${s.graded_count}/${s.total_count} — 전 문항 판정 후 확정 가능`
            : `총점 ${s.total_so_far} / 합격선 ${detail.passing_score} → 자동 판정: ${s.auto_result === 'pass' ? '합격' : '불합격'}`;
        const cards = detail.items.map(it => itemCard(it, level, !!confirmed)).join('');

        host.innerHTML = `
            <div class="grading-panel">
                <h4>${App.esc(detail.member.name || '')} (${App.esc(detail.member.cohort || '')}) — ${App.esc(detail.exam.title)} ${detail.attempt.attempt_no}회차</h4>
                <p class="grading-progress" id="grading-progress">${App.esc(autoText)}</p>
                ${cards || '<p>채점 대상 문항이 없습니다.</p>'}
                <div class="grading-confirm">
                    ${confirmed ? `
                        <p>✅ 확정: <strong>${confirmed.total_score}점</strong> / 합격선 ${confirmed.passing_score} → <strong>${confirmed.result === 'pass' ? '합격' : '불합격'}</strong>
                        ${confirmed.result_overridden ? ` (오버라이드: ${App.esc(confirmed.override_reason || '')})` : ''}
                        · ${App.esc((confirmed.confirmed_at || '').slice(0, 16))}</p>
                        ${confirmed.memo ? `<p>메모: ${App.esc(confirmed.memo)}</p>` : ''}
                        ${detail.exam.status !== 'released' ? '<button class="btn" id="grading-cancel">확정 취소</button>' : '<p><small>발표된 시험 — 취소 불가</small></p>'}
                    ` : `
                        <label>합불: <select id="grading-result">
                            <option value="">자동 판정 따름</option>
                            <option value="pass">합격</option>
                            <option value="fail">불합격</option>
                        </select></label>
                        <input type="text" id="grading-reason" placeholder="자동 판정과 다르면 사유 필수" maxlength="255" style="display:none">
                        <input type="text" id="grading-memo-all" placeholder="전체 메모 (선택)" maxlength="500">
                        <button class="btn btn-primary" id="grading-confirm-btn" ${s.auto_result === null ? 'disabled' : ''}>확정</button>
                    `}
                    <button class="btn" id="grading-close">목록으로</button>
                </div>
            </div>`;

        // 파일 유실(404) 시 카드에 안내 — 판정은 가능 (스펙 §7)
        host.querySelectorAll('.grading-audio audio').forEach(a =>
            a.addEventListener('error', () => {
                if (!a.nextElementSibling || !a.nextElementSibling.classList.contains('grading-audio-missing')) {
                    a.insertAdjacentHTML('afterend', '<small class="grading-audio-missing">⚠️ 녹음 파일 없음 — 판정은 가능</small>');
                }
            }));

        if (!confirmed) {
            host.querySelectorAll('.grading-judge').forEach(b => b.addEventListener('click', onJudge));
            host.querySelectorAll('.grading-memo').forEach(inp => inp.addEventListener('blur', onMemoBlur));
            const resultSel = host.querySelector('#grading-result');
            resultSel.addEventListener('change', () => {
                const auto = detail.summary.auto_result;
                const v = resultSel.value;
                host.querySelector('#grading-reason').style.display = (v && v !== auto) ? '' : 'none';
            });
            host.querySelector('#grading-confirm-btn').addEventListener('click', onConfirm);
        } else {
            const cancelBtn = host.querySelector('#grading-cancel');
            if (cancelBtn) cancelBtn.addEventListener('click', onCancelConfirm);
        }
        host.querySelector('#grading-close').addEventListener('click', async () => {
            detail = null; currentAttemptId = 0;
            await loadExams();
            if (currentExamId) await loadAttempts(currentExamId);
            renderList();
        });
    }

    function highlightJudge(answerId, field, val) {
        const card = root.querySelector(`.grading-card[data-answer="${answerId}"]`);
        if (!card) return;
        card.querySelectorAll(`.grading-judge[data-field="${field}"]`).forEach(btn => {
            const isSel = (field === 'chunk_ok' || field === 'completion_ok')
                ? parseInt(btn.dataset.val, 10) === val
                : btn.dataset.val === val;
            btn.classList.toggle('on', isSel);
            btn.classList.toggle('btn-primary', isSel);
        });
    }

    function refreshProgress() {
        const s = detail.summary;
        const el = root.querySelector('#grading-progress');
        if (el) el.textContent = s.auto_result === null
            ? `판정 ${s.graded_count}/${s.total_count} — 전 문항 판정 후 확정 가능`
            : `총점 ${s.total_so_far} / 합격선 ${detail.passing_score} → 자동 판정: ${s.auto_result === 'pass' ? '합격' : '불합격'}`;
        const btn = root.querySelector('#grading-confirm-btn');
        if (btn) btn.disabled = s.auto_result === null;
        const resultSel = root.querySelector('#grading-result');
        const reason = root.querySelector('#grading-reason');
        if (resultSel && reason) reason.style.display = (resultSel.value && resultSel.value !== s.auto_result) ? '' : 'none';
    }

    function judgmentComplete(sel, level) {
        return ['accuracy', 'chunk_ok', 'response_rating', 'fluency_rating']
            .every(k => sel[k] !== undefined && sel[k] !== null)
            && (level < 2 || (sel.completion_ok !== undefined && sel.completion_ok !== null));
    }

    async function onJudge(e) {
        const b = e.currentTarget;
        const answerId = parseInt(b.dataset.answer, 10);
        const field = b.dataset.field;
        const val = (field === 'chunk_ok' || field === 'completion_ok') ? parseInt(b.dataset.val, 10) : b.dataset.val;
        const item = detail.items.find(i => i.answer_id === answerId);
        if (!item) return;
        const level = detail.exam.bravo_level;
        const base = item.grade ? {
            accuracy: item.grade.accuracy, chunk_ok: item.grade.chunk_ok,
            response_rating: item.grade.response_rating, fluency_rating: item.grade.fluency_rating,
            completion_ok: item.grade.completion_ok,
        } : {};
        pending[answerId] = Object.assign({}, base, pending[answerId] || {}, { [field]: val });

        // 판정 미완료 — 버튼 강조만 인라인 갱신, 재렌더 없음
        if (!judgmentComplete(pending[answerId], level)) {
            highlightJudge(answerId, field, val);
            return;
        }

        // POST in-flight 가드
        if (saving[answerId]) return;
        saving[answerId] = true;
        highlightJudge(answerId, field, val);

        const snapshot = Object.assign({}, pending[answerId]);
        const memoEl = root.querySelector(`.grading-memo[data-answer="${answerId}"]`);
        const payload = Object.assign({ answer_id: answerId, memo: memoEl ? memoEl.value : '' }, snapshot);
        const r = await App.post('/api/admin.php?action=bravo_answer_grade_save', payload);
        delete saving[answerId];

        // POST 실패 시에만 전면 재렌더 (서버 상태로 복원 — 오디오 끊김보다 정확성 우선)
        if (!r || r.success === false) { renderDetail(); return; }

        item.grade = Object.assign({}, snapshot, { score: r.score, memo: payload.memo || null });
        delete pending[answerId];
        detail.summary = { graded_count: r.graded_count, total_count: r.total_count, total_so_far: r.total_so_far, auto_result: r.auto_result };

        // 인라인 갱신 (전면 재렌더 금지 — 오디오 재생 보존)
        const card = root.querySelector(`.grading-card[data-answer="${answerId}"]`);
        if (card) card.querySelector('.grading-score').innerHTML = '점수 <strong>' + r.score + '</strong>';
        refreshProgress();
    }

    async function onMemoBlur(e) {
        const inp = e.currentTarget;
        const answerId = parseInt(inp.dataset.answer, 10);
        if (saving[answerId]) return; // 판정 POST와 메모 POST 경합 방지
        const item = detail.items.find(i => i.answer_id === answerId);
        if (!item || !item.grade) return; // 판정 전 메모는 판정 저장 시 함께 전송됨
        if ((item.grade.memo || '') === inp.value) return;
        const payload = Object.assign({ answer_id: answerId, memo: inp.value }, {
            accuracy: item.grade.accuracy, chunk_ok: item.grade.chunk_ok,
            response_rating: item.grade.response_rating, fluency_rating: item.grade.fluency_rating,
            completion_ok: item.grade.completion_ok,
        });
        const r = await App.post('/api/admin.php?action=bravo_answer_grade_save', payload);
        if (r && r.success !== false) item.grade.memo = inp.value || null;
    }

    async function onConfirm() {
        const host = root.querySelector('#grading-detail');
        const btn = host.querySelector('#grading-confirm-btn');
        btn.disabled = true;
        const auto = detail.summary.auto_result;
        const chosen = host.querySelector('#grading-result').value || auto;
        const payload = {
            attempt_id: currentAttemptId,
            result: chosen,
            override_reason: host.querySelector('#grading-reason').value,
            memo: host.querySelector('#grading-memo-all').value,
        };
        const r = await App.post('/api/admin.php?action=bravo_attempt_confirm', payload);
        if (!r || r.success === false) { btn.disabled = false; return; }
        Toast.success(`확정되었습니다 (${r.total_score}점 · ${r.result === 'pass' ? '합격' : '불합격'}).`);
        await openDetail(currentAttemptId);
    }

    async function onCancelConfirm() {
        if (cancelling) return;
        cancelling = true;
        const r = await App.post('/api/admin.php?action=bravo_attempt_confirm', { attempt_id: currentAttemptId, action: 'cancel' });
        cancelling = false;
        if (!r || r.success === false) return;
        Toast.success('확정이 취소되었습니다.');
        await openDetail(currentAttemptId);
    }

    return { init };
})();
