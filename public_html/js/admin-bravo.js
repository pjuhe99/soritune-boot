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

    function renderQual() {
        if (!qualContainer) return;
        const thresholdInfo = levels.map(l => `BRAVO ${l.level}: ${l.required_review_count}회독·${l.passing_score}점`).join(' / ');
        const rows = members.map(m => {
            const ov = m.review_count_override === null ? '' : m.review_count_override;
            return `
            <tr data-user="${App.esc(m.user_id)}">
                <td>${App.esc(m.real_name || '')}<br><small>${App.esc(m.nickname || '')}</small></td>
                <td>${App.esc(m.phone || '')}</td>
                <td class="num">${m.completed_bootcamp_count}</td>
                <td><input type="number" class="bravo-override" min="0" max="99" value="${ov}" placeholder="자동(${m.completed_bootcamp_count})" style="width:5em"></td>
                <td class="num">${m.effective_review_count}</td>
                <td>${grantCheckboxes(m.granted_levels)}</td>
                <td>${levelChip(m.eligible_levels)}</td>
                <td><input type="text" class="bravo-notes" value="${App.esc(m.notes || '')}" placeholder="메모"></td>
                <td><button class="btn btn-primary btn-sm bravo-save">저장</button></td>
            </tr>`;
        }).join('');

        qualContainer.innerHTML = `
            <div class="bravo-admin">
                <p class="bravo-help">응시 자격 임계 — ${App.esc(thresholdInfo)}. 회독수 override 비우면 자동(완주횟수) 사용. 수동부여는 계산과 무관하게 응시 허용.</p>
                <table class="data-table bravo-table">
                    <thead><tr>
                        <th>회원</th><th>전화번호</th><th>완주(자동)</th><th>override</th><th>유효회독</th><th>수동부여</th><th>응시가능</th><th>메모</th><th></th>
                    </tr></thead>
                    <tbody>${rows || '<tr><td colspan="9">회원이 없습니다.</td></tr>'}</tbody>
                </table>
            </div>`;

        qualContainer.querySelectorAll('.bravo-save').forEach(btn => {
            btn.addEventListener('click', onSaveQual);
        });
    }

    async function onSaveQual(e) {
        const tr = e.target.closest('tr');
        if (!tr) return;
        const userId = tr.dataset.user;
        const ovRaw = tr.querySelector('.bravo-override').value.trim();
        const granted = Array.from(tr.querySelectorAll('input[data-grant]:checked')).map(c => parseInt(c.dataset.grant, 10));
        const notes = tr.querySelector('.bravo-notes').value;
        const payload = {
            user_id: userId,
            review_count_override: ovRaw === '' ? null : parseInt(ovRaw, 10),
            granted_levels: granted,
            notes: notes,
        };
        const r = await App.post('/api/admin.php?action=bravo_member_update', payload);
        if (r && r.success !== false) {
            Toast.success('저장되었습니다.');
            await loadQual();
            renderQual();
        } else {
            Toast.error((r && r.error) || '저장 실패');
        }
    }

    return { init };
})();
