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
                    <thead><tr><th>이름</th><th>기간</th><th>상태</th><th>리워드 구간</th><th>참여자</th><th>총 코인</th><th></th></tr></thead>
                    <tbody>
                        ${r.cycles.map(c => `
                            <tr>
                                <td><strong>${esc(c.name)}</strong></td>
                                <td>${c.start_date} ~ ${c.end_date}</td>
                                <td>${c.status === 'active' ? '<span class="badge badge-success">진행중</span>' : '<span class="badge badge-secondary">마감</span>'}</td>
                                <td>${esc(c.reward_group_name || '-')}</td>
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
            container.querySelector('tbody').innerHTML = '<tr><td colspan="7" class="empty-state">등록된 Coin Cycle이 없습니다.</td></tr>';
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

    /**
     * 응원상 지급 UI.
     * - groupId 없이 호출 (leader/subleader): 백엔드가 세션으로 자기 조 자동 결정
     * - groupId 지정 (operation/coach/head/subhead*): 해당 조 대상
     */
    async function showCheerAward(container, cycleId, groupId = null) {
        if (!cycleId) { container.innerHTML = '<p class="text-danger">active cycle이 없습니다.</p>'; return; }
        const qs = `&cycle_id=${cycleId}` + (groupId ? `&group_id=${groupId}` : '');
        const r = await api('coin_cheer_status', { qs });
        if (!r.success) { container.innerHTML = `<p class="text-danger">${r.error || r.message}</p>`; return; }

        const { awards, members, remaining, group } = r;

        container.innerHTML = `
            <h3>${esc(group.name)} 응원상 <small style="font-weight:normal;opacity:0.7">(${awards.length}/${r.max_targets} 지급, 남은 자리: ${remaining})</small></h3>
            ${awards.length ? `
                <div class="cheer-awarded" style="margin:8px 0 16px">
                    ${awards.map(a => `<span class="badge badge-primary" style="margin:2px" title="by ${esc(a.granted_by_nickname || '-')}">${esc(a.nickname)} (+${a.coin_amount})</span>`).join('')}
                </div>
            ` : ''}
            ${remaining > 0 ? `
                <div class="cheer-select" style="display:flex;gap:8px;align-items:center">
                    <select id="cheer-target" class="form-control" style="max-width:280px">
                        <option value="">회원 선택...</option>
                        ${members.map(m => `<option value="${m.id}">${esc(m.nickname)} (${esc(m.member_role || 'member')})</option>`).join('')}
                    </select>
                    <button class="btn btn-primary btn-sm" id="btn-cheer-grant">응원상 주기</button>
                </div>
            ` : '<p class="empty-state">이 조의 응원상이 모두 지급되었습니다.</p>'}
        `;

        const grantBtn = document.getElementById('btn-cheer-grant');
        if (grantBtn) {
            grantBtn.onclick = async () => {
                const targetId = document.getElementById('cheer-target').value;
                if (!targetId) { App.toast('회원을 선택해주세요.', 'error'); return; }
                const body = { cycle_id: cycleId, target_member_ids: [parseInt(targetId)] };
                if (groupId) body.group_id = groupId;
                const r = await api('coin_cheer_award', { body });
                if (!r.success) { App.toast(r.error || r.message, 'error'); return; }
                App.toast(r.message);
                showCheerAward(container, cycleId, groupId);
            };
        }
    }

    /**
     * 조 선택기 + 응원상 지급 UI (operation/coach/head/subhead* 전용).
     * 현재 active cohort의 조 목록에서 선택.
     */
    async function showCheerPicker(container, cycleId) {
        if (!cycleId) { container.innerHTML = '<p class="text-danger">active cycle이 없습니다.</p>'; return; }
        const r = await api('coin_cheer_groups');
        if (!r.success) { container.innerHTML = `<p class="text-danger">${r.error || r.message}</p>`; return; }

        const options = (r.groups || []).map(g =>
            `<option value="${g.id}">${esc(g.cohort_name)} ${esc(g.name)} (${g.stage_no}단계)</option>`
        ).join('');

        container.innerHTML = `
            <div class="mgmt-toolbar" style="margin-bottom:12px">
                <span style="font-weight:600">응원상 관리</span>
                <select id="cheer-group-picker" class="form-control" style="max-width:300px;margin-left:auto">
                    <option value="">조 선택...</option>
                    ${options}
                </select>
            </div>
            <div id="cheer-group-body"></div>
        `;

        const picker = document.getElementById('cheer-group-picker');
        const body = document.getElementById('cheer-group-body');
        picker.onchange = () => {
            const gid = parseInt(picker.value) || null;
            if (gid) showCheerAward(body, cycleId, gid);
            else body.innerHTML = '';
        };
    }

    // ── Reward Groups ───────────────────────────────────────

    async function showRewardGroups(container) {
        const r = await api('coin_reward_groups');
        if (!r.success) { container.innerHTML = `<p class="text-danger">${r.error || r.message}</p>`; return; }

        const groupsHtml = r.groups.length ? r.groups.map(g => {
            const cycleBadges = (g.cycles || []).map(c =>
                `<span class="badge">${esc(c.name)}</span>`
            ).join(' ');
            const statusBadge = g.status === 'open'
                ? '<span class="badge badge-success">열림</span>'
                : '<span class="badge badge-secondary">지급완료</span>';
            const actions = g.status === 'open'
                ? `<button class="btn-icon" onclick="CoinApp.rgAttachCycle(${g.id})">cycle 추가</button>
                   <button class="btn-icon" onclick="CoinApp.rgPreview(${g.id})">지급</button>
                   <button class="btn-icon" onclick="CoinApp.rgEdit(${g.id}, '${esc(g.name)}')">수정</button>
                   <button class="btn-icon danger" onclick="CoinApp.rgDelete(${g.id})">삭제</button>`
                : `<button class="btn-icon" onclick="CoinApp.rgDetail(${g.id})">내역</button>`;
            return `
                <tr>
                    <td><strong>${esc(g.name)}</strong></td>
                    <td>${cycleBadges} (${g.cycle_count}/2)</td>
                    <td>${statusBadge}</td>
                    <td>${g.active_total || 0}</td>
                    <td class="actions">${actions}</td>
                </tr>
            `;
        }).join('') : '<tr><td colspan="5" class="empty-state">등록된 reward group이 없습니다.</td></tr>';

        container.innerHTML = `
            <div class="mgmt-toolbar mt-md">
                <span style="font-weight:600">Reward Groups</span>
                <button class="btn btn-primary btn-sm" id="btn-add-rg">새 Reward Group</button>
            </div>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead><tr><th>이름</th><th>소속 Cycle</th><th>상태</th><th>활성 합계</th><th></th></tr></thead>
                    <tbody>${groupsHtml}</tbody>
                </table>
            </div>
        `;
        document.getElementById('btn-add-rg').onclick = async () => {
            const name = prompt('Reward Group 이름 (예: 11-12기 리워드)');
            if (!name) return;
            const r2 = await api('coin_reward_group_create', { body: { name } });
            if (!r2.success) { App.toast(r2.error || r2.message, 'error'); return; }
            App.toast(r2.message);
            const rgSection = document.getElementById('rg-section');
            if (rgSection) showRewardGroups(rgSection);
        };
    }

    async function rgEdit(id, currentName) {
        const name = prompt('새 이름', currentName);
        if (!name || name === currentName) return;
        const r = await api('coin_reward_group_update', { body: { id, name } });
        if (!r.success) { App.toast(r.error || r.message, 'error'); return; }
        App.toast(r.message);
        const rgSection = document.getElementById('rg-section');
        if (rgSection) showRewardGroups(rgSection);
    }

    async function rgDelete(id) {
        if (!confirm('이 reward group을 삭제합니다. 계속하시겠습니까?')) return;
        const r = await api('coin_reward_group_delete', { body: { id } });
        if (!r.success) { App.toast(r.error || r.message, 'error'); return; }
        App.toast(r.message);
        const rgSection = document.getElementById('rg-section');
        if (rgSection) showRewardGroups(rgSection);
    }

    async function rgAttachCycle(groupId) {
        const cr = await api('coin_cycles');
        if (!cr.success) { App.toast('cycle 조회 실패', 'error'); return; }
        const freeCycles = (cr.cycles || []).filter(c => !c.reward_group_id);
        if (!freeCycles.length) { App.toast('붙일 수 있는 cycle이 없습니다.', 'error'); return; }
        const options = freeCycles.map(c => `<option value="${c.id}">${esc(c.name)} (${c.start_date}~${c.end_date})</option>`).join('');
        App.modal('Cycle 추가', `
            <div class="form-group"><label>Cycle</label><select id="rg-attach-cycle">${options}</select></div>
        `, async () => {
            const cycleId = parseInt(document.getElementById('rg-attach-cycle').value);
            const r = await api('coin_reward_group_attach_cycle', { body: { group_id: groupId, cycle_id: cycleId } });
            if (!r.success) { App.toast(r.error || r.message, 'error'); return false; }
            App.toast(r.message);
            const rgSection = document.getElementById('rg-section');
            if (rgSection) showRewardGroups(rgSection);
        });
    }

    async function rgPreview(groupId) {
        const r = await api('coin_reward_group_preview', { qs: `&group_id=${groupId}` });
        if (!r.success) { App.toast(r.error || r.message, 'error'); return; }
        const membersHtml = r.members.length ? r.members.map(m => {
            const perCycleStr = Object.entries(m.per_cycle).map(([k,v]) => `${esc(k)}: ${v}`).join(', ');
            return `<tr><td>${esc(m.nickname)}</td><td>${perCycleStr}</td><td style="font-weight:700">${m.total}</td></tr>`;
        }).join('') : '<tr><td colspan="3" class="empty-state">지급 대상이 없습니다.</td></tr>';

        const blockerHtml = r.can_distribute ? '' :
            `<div style="margin-bottom:12px;padding:10px;background:#fee;border-left:4px solid #c00">
                <strong>지급 불가:</strong> ${r.blockers.map(esc).join(', ')}
             </div>`;

        const title = `리워드 지급 미리보기 — ${esc(r.group.name)}`;
        App.modal(title, `
            ${blockerHtml}
            <div style="overflow-x:auto;max-height:50vh">
                <table class="data-table" style="font-size:13px">
                    <thead><tr><th>회원</th><th>Cycle별</th><th>합계</th></tr></thead>
                    <tbody>${membersHtml}</tbody>
                </table>
            </div>
        `, r.can_distribute && r.members.length ? async () => {
            if (!confirm(`${r.members.length}명에게 지급합니다. 확정하시겠습니까?`)) return false;
            const er = await api('coin_reward_group_distribute', { body: { group_id: groupId } });
            if (!er.success) { App.toast(er.error || er.message, 'error'); return false; }
            App.toast(er.message);
            App.closeModal();
            const rgSection = document.getElementById('rg-section');
            if (rgSection) showRewardGroups(rgSection);
        } : null, { wide: true });
    }

    async function rgDetail(groupId) {
        const r = await api('coin_reward_group_distribution_detail', { qs: `&group_id=${groupId}` });
        if (!r.success) { App.toast(r.error || r.message, 'error'); return; }
        const rowsHtml = r.distributions.length ? r.distributions.map(d => {
            const bd = Object.entries(d.cycle_breakdown || {}).map(([k,v]) => `${esc(k)}: ${v}`).join(', ');
            return `<tr><td>${esc(d.nickname)}</td><td>${bd}</td><td style="font-weight:700">${d.total_amount}</td></tr>`;
        }).join('') : '<tr><td colspan="3" class="empty-state">지급 내역 없음</td></tr>';
        App.modal(`지급 내역 — ${esc(r.group.name)}`, `
            <p>지급 시점: ${r.group.distributed_at || '-'} / 담당자: ${esc(r.group.distributor_name || '-')}</p>
            <div style="overflow-x:auto;max-height:60vh">
                <table class="data-table" style="font-size:13px">
                    <thead><tr><th>회원</th><th>Cycle별</th><th>합계</th></tr></thead>
                    <tbody>${rowsHtml}</tbody>
                </table>
            </div>
        `, null, { wide: true });
    }

    function esc(s) { return App.esc ? App.esc(s) : String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    return {
        showCycles,
        showCycleMembers,
        leaderGrant,
        showSettlement,
        closeCycle,
        showCheerAward,
        showRewardGroups,
        rgEdit,
        rgDelete,
        rgAttachCycle,
        rgPreview,
        rgDetail,
        showCheerPicker,
    };
})();
