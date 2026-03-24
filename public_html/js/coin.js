/**
 * Coin Cycle Management UI
 * operation: cycle CRUD, 리더코인 일괄지급, 정산
 * leader/subleader: 응원상
 * all roles: cycle별 코인 현황 조회
 */
const CoinApp = (() => {
    const API = '/api/bootcamp.php';
    let currentCycleId = null;

    async function api(action, opts = {}) {
        const url = `${API}?action=${action}` + (opts.qs || '');
        const fetchOpts = { credentials: 'include' };
        if (opts.body) {
            fetchOpts.method = 'POST';
            fetchOpts.headers = { 'Content-Type': 'application/json' };
            fetchOpts.body = JSON.stringify(opts.body);
        }
        const res = await fetch(url, fetchOpts);
        return res.json();
    }

    // ── Cycle 목록 ──────────────────────────────────────────

    async function showCycles(container) {
        const r = await api('coin_cycles');
        if (!r.success) { container.innerHTML = `<p class="text-danger">${r.error || r.message}</p>`; return; }

        container.innerHTML = `
            <div class="mgmt-toolbar mt-md">
                <span style="font-weight:600">Coin Cycles</span>
                <button class="btn btn-primary btn-sm" id="btn-add-cycle">새 Cycle</button>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead><tr><th>이름</th><th>기간</th><th>상태</th><th>참여자</th><th>총 코인</th><th></th></tr></thead>
                    <tbody>
                        ${r.cycles.map(c => `
                            <tr>
                                <td><strong>${esc(c.name)}</strong></td>
                                <td>${c.start_date} ~ ${c.end_date}</td>
                                <td>${c.status === 'active' ? '<span class="badge badge-success">진행중</span>' : '<span class="badge badge-secondary">마감</span>'}</td>
                                <td>${c.member_count || 0}명</td>
                                <td>${c.total_earned || 0}</td>
                                <td class="actions">
                                    <button class="btn-icon" onclick="CoinApp.showCycleMembers(${c.id}, '${esc(c.name)}')">상세</button>
                                    ${c.status === 'active' ? `
                                        <button class="btn-icon" onclick="CoinApp.leaderGrant(${c.id})">리더코인</button>
                                        <button class="btn-icon" onclick="CoinApp.showSettlement(${c.id})">정산</button>
                                        <button class="btn-icon danger" onclick="CoinApp.closeCycle(${c.id})">마감</button>
                                    ` : ''}
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
        if (!r.cycles.length) {
            container.querySelector('tbody').innerHTML = '<tr><td colspan="6" class="empty-state">등록된 Coin Cycle이 없습니다.</td></tr>';
        }
        document.getElementById('btn-add-cycle').onclick = () => showCycleForm(container);
    }

    function showCycleForm(container) {
        App.modal('Coin Cycle 생성', `
            <div class="form-group"><label>이름</label><input type="text" id="cycle-name" placeholder="예: 3기 전반기"></div>
            <div class="form-group"><label>시작일</label><input type="date" id="cycle-start"></div>
            <div class="form-group"><label>종료일 (일요일)</label><input type="date" id="cycle-end"></div>
        `, async () => {
            const r = await api('coin_cycle_create', { body: {
                name: document.getElementById('cycle-name').value,
                start_date: document.getElementById('cycle-start').value,
                end_date: document.getElementById('cycle-end').value,
            }});
            if (!r.success) { App.toast(r.error || r.message, 'error'); return false; }
            App.toast(r.message);
            App.closeModal();
            showCycles(container);
        });
    }

    // ── Cycle 상세 (회원별 코인) ────────────────────────────

    async function showCycleMembers(cycleId, cycleName) {
        currentCycleId = cycleId;
        const r = await api('coin_cycle_members', { qs: `&cycle_id=${cycleId}` });
        if (!r.success) { App.toast(r.error || r.message, 'error'); return; }

        const members = r.members;
        App.modal(`${esc(cycleName)} - 회원 코인 현황`, `
            <div style="overflow-x:auto;max-height:70vh">
                <table class="data-table" style="font-size:13px">
                    <thead><tr><th>회원</th><th>조</th><th>역할</th><th>코인</th><th>복스개설</th><th>복스참여</th><th>리더</th><th>찐완주</th><th>하멈말</th></tr></thead>
                    <tbody>
                        ${members.map(m => `
                            <tr>
                                <td>${esc(m.nickname)}</td>
                                <td>${esc(m.group_name || '-')}</td>
                                <td>${esc(m.member_role)}</td>
                                <td style="font-weight:700">${m.earned_coin}</td>
                                <td>${m.study_open_count}/10</td>
                                <td>${m.study_join_count}/15</td>
                                <td>${parseInt(m.leader_coin_granted) ? 'O' : '-'}</td>
                                <td>${parseInt(m.perfect_attendance_granted) ? 'O' : '-'}</td>
                                <td>${parseInt(m.hamemmal_granted) ? 'O' : '-'}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `, null, { wide: true });
    }

    // ── 리더 코인 일괄 지급 ─────────────────────────────────

    async function leaderGrant(cycleId) {
        if (!confirm('이 cycle의 조장/부조장에게 리더 코인을 일괄 지급합니다.\n계속하시겠습니까?')) return;
        const r = await api('coin_leader_grant', { body: { cycle_id: cycleId } });
        if (!r.success) { App.toast(r.error || r.message, 'error'); return; }
        App.toast(r.message);
    }

    // ── 정산 ────────────────────────────────────────────────

    async function showSettlement(cycleId) {
        const r = await api('coin_settlement_preview', { qs: `&cycle_id=${cycleId}` });
        if (!r.success) { App.toast(r.error || r.message, 'error'); return; }

        const { members, summary } = r;
        const eligible = members.filter(m => m.perfect_attendance || m.hamemmal_eligible);

        App.modal('정산 미리보기', `
            <div class="settlement-summary" style="margin-bottom:16px;padding:12px;background:#f5f5f5;border-radius:8px">
                <p><strong>찐완주:</strong> ${summary.perfect_attendance_count}명 (${summary.perfect_attendance_total_coin} 코인)</p>
                <p><strong>하멈말:</strong> ${summary.hamemmal_count}명 (${summary.hamemmal_total_coin} 코인)</p>
            </div>
            ${eligible.length ? `
            <div style="overflow-x:auto;max-height:50vh">
                <table class="data-table" style="font-size:13px">
                    <thead><tr><th>회원</th><th>조</th><th>찐완주</th><th>하멈말(횟수)</th><th>지급 코인</th></tr></thead>
                    <tbody>
                        ${eligible.map(m => `
                            <tr>
                                <td>${esc(m.nickname)}</td>
                                <td>${esc(m.group_name || '-')}</td>
                                <td>${m.perfect_attendance ? `+${m.perfect_attendance_coin}` : '-'}</td>
                                <td>${m.hamemmal_eligible ? `+${m.hamemmal_coin} (${m.hamemmal_count}회)` : `- (${m.hamemmal_count}회)`}</td>
                                <td style="font-weight:700">${m.perfect_attendance_coin + m.hamemmal_coin}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>` : '<p class="empty-state">지급 대상이 없습니다.</p>'}
        `, eligible.length ? async () => {
            if (!confirm('정산을 실행합니다. 계속하시겠습니까?')) return false;
            const er = await api('coin_settlement_execute', { body: { cycle_id: cycleId } });
            if (!er.success) { App.toast(er.error || er.message, 'error'); return false; }
            App.toast(er.message || '정산 완료');
            App.closeModal();
        } : null, { wide: true });
    }

    // ── Cycle 마감 ──────────────────────────────────────────

    async function closeCycle(cycleId) {
        if (!confirm('이 cycle을 마감합니다. 마감 후에도 코인 변동은 가능하지만, 정산/리더코인 일괄지급 버튼이 사라집니다.\n계속하시겠습니까?')) return;
        const r = await api('coin_cycle_close', { body: { id: cycleId } });
        if (!r.success) { App.toast(r.error || r.message, 'error'); return; }
        App.toast(r.message);
        const container = document.querySelector('.coin-cycles-container');
        if (container) showCycles(container);
    }

    // ── 응원상 ──────────────────────────────────────────────

    async function showCheerAward(container, cycleId) {
        const [statusR, membersR] = await Promise.all([
            api('coin_cheer_status', { qs: `&cycle_id=${cycleId}` }),
            api('study_members'),
        ]);

        if (!statusR.success) { container.innerHTML = `<p class="text-danger">${statusR.error || statusR.message}</p>`; return; }

        const { awards, remaining } = statusR;
        const members = membersR.success ? (membersR.members || []) : [];

        container.innerHTML = `
            <h3>응원상 (남은 선택: ${remaining}명)</h3>
            ${awards.length ? `
                <div class="cheer-awarded" style="margin-bottom:16px">
                    ${awards.map(a => `<span class="badge badge-primary" style="margin:2px">${esc(a.nickname)} (+${a.coin_amount})</span>`).join('')}
                </div>
            ` : ''}
            ${remaining > 0 ? `
                <div class="cheer-select">
                    <select id="cheer-target" class="form-control">
                        <option value="">회원 선택...</option>
                        ${members.filter(m => !awards.some(a => a.target_member_id == m.id))
                            .map(m => `<option value="${m.id}">${esc(m.nickname)} (${esc(m.group_name || '-')})</option>`).join('')}
                    </select>
                    <button class="btn btn-primary btn-sm mt-sm" id="btn-cheer-grant">응원상 주기</button>
                </div>
            ` : '<p>선택 완료</p>'}
        `;

        const grantBtn = document.getElementById('btn-cheer-grant');
        if (grantBtn) {
            grantBtn.onclick = async () => {
                const targetId = document.getElementById('cheer-target').value;
                if (!targetId) { App.toast('회원을 선택해주세요.', 'error'); return; }
                const r = await api('coin_cheer_award', { body: {
                    cycle_id: cycleId,
                    target_member_ids: [parseInt(targetId)],
                }});
                if (!r.success) { App.toast(r.error || r.message, 'error'); return; }
                App.toast(r.message);
                showCheerAward(container, cycleId);
            };
        }
    }

    function esc(s) { return App.esc ? App.esc(s) : String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    return {
        showCycles,
        showCycleMembers,
        leaderGrant,
        showSettlement,
        closeCycle,
        showCheerAward,
    };
})();
