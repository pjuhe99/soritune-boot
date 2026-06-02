/* ── BRAVO 자격 관리 (operation) ───────────────────────────── */
const AdminBravoApp = (() => {
    let container = null;
    let levels = [];
    let members = [];

    async function init(adminSession, containerId) {
        container = document.getElementById(containerId);
        if (!container) return;
        await load();
        render();
    }

    async function load() {
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

    function render() {
        if (!container) return;
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

        container.innerHTML = `
            <div class="bravo-admin">
                <p class="bravo-help">응시 자격 임계 — ${App.esc(thresholdInfo)}. 회독수 override 비우면 자동(완주횟수) 사용. 수동부여는 계산과 무관하게 응시 허용.</p>
                <table class="data-table bravo-table">
                    <thead><tr>
                        <th>회원</th><th>전화번호</th><th>완주(자동)</th><th>override</th><th>유효회독</th><th>수동부여</th><th>응시가능</th><th>메모</th><th></th>
                    </tr></thead>
                    <tbody>${rows || '<tr><td colspan="9">회원이 없습니다.</td></tr>'}</tbody>
                </table>
            </div>`;

        container.querySelectorAll('.bravo-save').forEach(btn => {
            btn.addEventListener('click', onSave);
        });
    }

    async function onSave(e) {
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
            await load();
            render();
        } else {
            Toast.error((r && r.error) || '저장 실패');
        }
    }

    return { init };
})();
