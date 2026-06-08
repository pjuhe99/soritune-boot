/* ── BRAVO 관리 (operation) — 서브탭 셸: 회원 자격 / 시험 관리 ── */
const AdminBravoApp = (() => {
    let admin = null;
    let root = null;
    let active = 'qual';
    let examsMounted = false;
    let questionsMounted = false;
    let gradingMounted = false;

    // 회원 자격 뷰 상태
    let qualContainer = null;
    let levels = [];
    let members = [];

    async function init(adminSession, containerId) {
        admin = adminSession;
        root = document.getElementById(containerId);
        if (!root) return;
        active = 'qual';
        examsMounted = false;
        questionsMounted = false;
        gradingMounted = false;
        root.innerHTML = `
            <div class="bravo-subtabs">
                <button class="bravo-subtab active" data-sub="qual">회원 자격</button>
                <button class="bravo-subtab" data-sub="exams">시험 관리</button>
                <button class="bravo-subtab" data-sub="questions">문제은행</button>
                <button class="bravo-subtab" data-sub="grading">채점</button>
            </div>
            <div class="bravo-sub" id="bravo-sub-qual"></div>
            <div class="bravo-sub" id="bravo-sub-exams" style="display:none"></div>
            <div class="bravo-sub" id="bravo-sub-questions" style="display:none"></div>
            <div class="bravo-sub" id="bravo-sub-grading" style="display:none"></div>`;
        root.querySelectorAll('.bravo-subtab').forEach(b =>
            b.addEventListener('click', () => switchSub(b.dataset.sub)));
        qualContainer = root.querySelector('#bravo-sub-qual');
        await loadQual();
        renderQual();
    }

    function switchSub(sub) {
        if (sub === active) return;
        active = sub;
        root.querySelectorAll('.bravo-subtab').forEach(b =>
            b.classList.toggle('active', b.dataset.sub === sub));
        root.querySelector('#bravo-sub-qual').style.display = sub === 'qual' ? '' : 'none';
        root.querySelector('#bravo-sub-exams').style.display = sub === 'exams' ? '' : 'none';
        root.querySelector('#bravo-sub-questions').style.display = sub === 'questions' ? '' : 'none';
        root.querySelector('#bravo-sub-grading').style.display = sub === 'grading' ? '' : 'none';
        if (sub === 'exams' && !examsMounted && typeof AdminBravoExamApp !== 'undefined') {
            examsMounted = true;
            AdminBravoExamApp.init(admin, 'bravo-sub-exams');
        }
        if (sub === 'questions' && !questionsMounted && typeof AdminBravoQuestionApp !== 'undefined') {
            questionsMounted = true;
            AdminBravoQuestionApp.init(admin, 'bravo-sub-questions');
        }
        if (sub === 'grading' && !gradingMounted && typeof AdminBravoGradingApp !== 'undefined') {
            gradingMounted = true;
            AdminBravoGradingApp.init(admin, 'bravo-sub-grading');
        }
    }

    // ── 회원 자격 뷰 (슬라이스1 로직 보존) ──
    async function loadQual() {
        const r = await App.get('/api/admin.php?action=bravo_member_list');
        if (!r || r.success === false) { members = []; levels = []; return; }
        members = r.members || [];
        levels = r.levels || [];
    }

    function levelChip(eligible) {
        if (!eligible || eligible.length === 0) return '<span class="bravo-chip none">없음</span>';
        return eligible.map(l => `<span class="bravo-chip lv${l}">BRAVO ${l}</span>`).join(' ');
    }

    function grantCheckboxes(granted) {
        return [1,2,3].map(l => {
            const checked = granted.includes(l) ? 'checked' : '';
            return `<label class="bravo-grant"><input type="checkbox" data-grant="${l}" ${checked}> ${l}</label>`;
        }).join(' ');
    }

    function curLevelChip(lv) {
        const L = lv || 0;
        return L === 0 ? '<span class="bravo-chip none">무등급</span>' : `<span class="bravo-chip lv${L}">BRAVO ${L}</span>`;
    }

    // 목록 = 핵심 컬럼만(회원·전화·응시가능·현재등급), 상세 편집은 [수정] → 모달 (가로 스크롤 제거)
    function renderQual() {
        if (!qualContainer) return;
        const thresholdInfo = levels.map(l => `BRAVO ${l.level}: ${l.required_review_count}회독·${l.passing_score}점`).join(' / ');
        const rows = members.map(m => `
            <tr>
                <td>${App.esc(m.real_name || '')}<br><small>${App.esc(m.nickname || '')}</small></td>
                <td>${App.esc(m.phone || '')}</td>
                <td>${levelChip(m.eligible_levels)}</td>
                <td>${curLevelChip(m.current_level)}</td>
                <td><button class="btn btn-sm bravo-qual-edit" data-key="${App.esc(m.member_key)}">수정</button></td>
            </tr>`).join('');

        qualContainer.innerHTML = `
            <div class="bravo-admin">
                <div class="bravo-help">
                    <strong>회원 자격·등급 관리</strong> — 회원별 BRAVO 응시 자격과 등급을 관리합니다. 행의 <b>수정</b>을 눌러 상세 항목(회독수 수동설정·등급 수동부여·현재 등급·추가 응시횟수·메모)을 편집하세요. <small>(응시 기준 — ${App.esc(thresholdInfo)})</small>
                </div>
                <table class="data-table bravo-table">
                    <thead><tr>
                        <th>회원</th><th>전화번호</th><th>응시 가능</th><th>현재 등급</th><th></th>
                    </tr></thead>
                    <tbody>${rows || '<tr><td colspan="5">회원이 없습니다.</td></tr>'}</tbody>
                </table>
                <dialog class="bravo-qual-dialog" id="bravo-qual-dialog"></dialog>
            </div>`;

        qualContainer.querySelectorAll('.bravo-qual-edit').forEach(btn =>
            btn.addEventListener('click', () => openQualEdit(btn.dataset.key)));
    }

    function openQualEdit(key) {
        const m = members.find(x => String(x.member_key) === String(key));
        if (!m) return;
        const dlg = qualContainer.querySelector('#bravo-qual-dialog');
        if (!dlg) return;
        const hasUser = !!m.user_id;
        const ov = (m.review_count_override === null || m.review_count_override === undefined) ? '' : m.review_count_override;
        const used = m.used_attempts || {};
        const extra = m.extra_attempts || {};
        dlg.innerHTML = `
            <div class="bravo-qual-form">
                <h4>${App.esc(m.real_name || '')} ${m.nickname ? `<small>(${App.esc(m.nickname)})</small>` : ''}</h4>
                <p class="bravo-qual-meta">${App.esc(m.phone || '')} · 완주(자동) ${m.completed_bootcamp_count}회 · 적용 회독수 ${m.effective_review_count}회 · 응시가능 ${levelChip(m.eligible_levels)}</p>
                ${hasUser ? `
                <label class="bravo-qf-row">회독수 수동설정
                    <input type="number" class="bravo-override" min="0" max="99" value="${ov}" placeholder="자동(${m.completed_bootcamp_count})">
                    <small>비우면 완주 횟수를 자동 사용</small>
                </label>
                <div class="bravo-qf-row">등급 수동부여
                    <span class="bravo-grants">${grantCheckboxes(m.granted_levels)}</span>
                    <small>회독수와 무관하게 해당 등급 응시 허용</small>
                </div>` : `<p class="bravo-qf-note">소리튠 미연동 회원은 회독수 수동설정·등급 수동부여·메모를 지원하지 않습니다. (현재 등급·추가 응시횟수만 변경 가능)</p>`}
                <label class="bravo-qf-row">현재 등급
                    <select class="bravo-cur-level">
                        ${[0,1,2,3].map(l => `<option value="${l}" ${(m.current_level || 0) === l ? 'selected' : ''}>${l === 0 ? '무등급' : 'BRAVO ' + l}</option>`).join('')}
                    </select>
                </label>
                <div class="bravo-qf-row">누적 응시 (B1·B2·B3)
                    <span class="bravo-qf-static">${used[1] ?? 0} · ${used[2] ?? 0} · ${used[3] ?? 0}</span>
                </div>
                <div class="bravo-qf-row">추가 응시횟수 (B1·B2·B3)
                    <span class="bravo-extras">${[1,2,3].map(l => `<input type="number" class="bravo-extra" data-lv="${l}" min="0" max="99" value="${extra[l] ?? 0}">`).join(' ')}</span>
                    <small>기본 3회 외 추가로 부여</small>
                </div>
                ${hasUser ? `<label class="bravo-qf-row">메모<input type="text" class="bravo-notes" value="${App.esc(m.notes || '')}" placeholder="메모"></label>` : ''}
                <div class="bravo-qf-actions">
                    <button type="button" class="btn btn-primary bravo-qual-save" data-user="${App.esc(m.user_id || '')}" data-key="${App.esc(m.member_key)}">저장</button>
                    <button type="button" class="btn bravo-qual-cancel">취소</button>
                </div>
            </div>`;
        dlg.querySelector('.bravo-qual-save').addEventListener('click', onSaveQual);
        dlg.querySelector('.bravo-qual-cancel').addEventListener('click', () => dlg.close());
        dlg.showModal();
    }

    async function onSaveQual(e) {
        const btn = e.target;
        const dlg = btn.closest('dialog');
        if (!dlg) return;
        btn.disabled = true;
        const userId = btn.dataset.user;
        const memberKey = btn.dataset.key;
        let ok = true;
        if (userId) { // user_id 있는 회원만 기존 설정 저장 (phone-only 는 회독수 수동설정/등급 수동부여 미지원 — 기존 한계)
            const ovEl = dlg.querySelector('.bravo-override');
            const ovRaw = ovEl ? ovEl.value.trim() : '';
            const granted = Array.from(dlg.querySelectorAll('input[data-grant]:checked')).map(c => parseInt(c.dataset.grant, 10));
            const notesEl = dlg.querySelector('.bravo-notes');
            const r1 = await App.post('/api/admin.php?action=bravo_member_update', {
                user_id: userId,
                review_count_override: ovRaw === '' ? null : parseInt(ovRaw, 10),
                granted_levels: granted,
                notes: notesEl ? notesEl.value : '',
            });
            ok = ok && r1 && r1.success !== false;
        }
        const payload = { member_key: memberKey, current_level: parseInt(dlg.querySelector('.bravo-cur-level').value, 10) };
        dlg.querySelectorAll('.bravo-extra').forEach(inp => { payload['extra_attempts_' + inp.dataset.lv] = parseInt(inp.value, 10) || 0; });
        const r2 = await App.post('/api/admin.php?action=bravo_grade_update', payload);
        ok = ok && r2 && r2.success !== false;
        if (ok) {
            Toast.success('저장되었습니다.');
            dlg.close();
            await loadQual();
            renderQual();
        } else {
            btn.disabled = false;
            Toast.error('저장 실패 — 일부 항목을 확인해주세요.');
        }
    }

    return { init };
})();
