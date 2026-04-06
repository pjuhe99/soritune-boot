/**
 * MemberTable — 회원 테이블 공통 렌더링 (admin.js, bootcamp.js 공용)
 *
 * 기본 행: 핵심 정보만 노출
 * 펼침 행: 상세 정보 (연락처, 이력, 점수/코인 등)
 */
const MemberTable = (() => {
    const ROLE_LABELS = { member: '회원', leader: '조장', subleader: '부조장' };

    function bravoHtml(grade) {
        if (!grade) return '';
        const cls = grade === 'Bravo 3' ? 'badge-dark' : grade === 'Bravo 2' ? 'badge-warning-solid' : 'badge-warning';
        return `<span class="badge ${cls}">${App.esc(grade)}</span>`;
    }

    function scoreHtml(score) {
        const s = parseInt(score);
        let cls = '';
        if (s <= -25) cls = 'out';
        else if (s <= -10) cls = 'danger';
        else if (s <= -8) cls = 'revival-warning';
        else if (s < 0) cls = 'negative';
        else if (s > 0) cls = 'positive';
        return `<span class="score-val ${cls}">${s}</span>`;
    }

    function statusBadge(m) {
        if (m.is_active == 0) return '<span class="badge badge-danger">비활성</span>';
        if (m.member_status === 'out_of_group_management') return '<span class="badge badge-danger">탈락</span>';
        return '<span class="badge badge-success">활성</span>';
    }

    function enteredBadge(m) {
        return parseInt(m.entered) ? '<span class="badge badge-success">입장</span>' : '<span class="badge badge-neutral">미입장</span>';
    }

    function historyHtml(m) {
        const s1 = parseInt(m.stage1_participation_count) || 0;
        const s2 = parseInt(m.stage2_participation_count) || 0;
        const comp = parseInt(m.completed_bootcamp_count) || 0;
        const parts = [];
        if (s1) parts.push(`1단계 ${s1}회`);
        if (s2) parts.push(`2단계 ${s2}회`);
        parts.push(`완주 ${comp}회`);
        return parts.join(' · ');
    }

    /**
     * 테이블 HTML 생성
     * @param {Array} members
     * @param {Object} opts
     *   - mode: 'operation' | 'bootcamp'
     *   - editFn: 'AdminApp._editMember' | 'BootcampApp._editMember'
     *   - deleteFn: 'AdminApp._deleteMember' | 'BootcampApp._deleteMember'
     */
    function render(members, opts = {}) {
        const mode = opts.mode || 'bootcamp';
        const editFn = opts.editFn || 'BootcampApp._editMember';
        const deleteFn = opts.deleteFn || 'BootcampApp._deleteMember';
        const showGroup = opts.showGroup !== false;

        if (!members.length) {
            return '<div class="empty-state">회원이 없습니다.</div>';
        }

        const headerCols = `
            <th class="mt-col-name">이름</th>
            <th class="mt-col-userid">아이디</th>
            ${showGroup ? '<th class="mt-col-group">조</th>' : ''}
            <th class="mt-col-hist">이력</th>
            <th class="mt-col-bravo">등급</th>
            <th class="mt-col-score">점수</th>
            <th class="mt-col-entered">입장</th>
            <th class="mt-col-status">상태</th>
        `;
        const colCount = (showGroup ? 7 : 6) + 1;

        const rows = members.map(m => {
            const pc = parseInt(m.participation_count);
            const pcBadge = pc > 1 ? `<span class="badge badge-info mt-pc-badge">${pc}회차</span>` : '';
            const roleBadge = m.member_role !== 'member'
                ? `<span class="badge badge-primary mt-role-badge">${App.esc(ROLE_LABELS[m.member_role] || m.member_role)}</span>`
                : '';
            const bravo = bravoHtml(m.bravo_grade);
            const stageBadge = `<span class="badge badge-neutral mt-stage-badge">${m.stage_no}단계</span>`;

            // 기본 행
            const mainRow = `
            <tr class="mt-row" data-id="${m.id}" data-entered="${parseInt(m.entered) ? '1' : '0'}" data-group="${App.esc(m.group_name || '')}">
                <td class="mt-col-name">
                    <div class="mt-name-primary">${App.esc(m.nickname)} ${roleBadge} ${pcBadge}</div>
                    <div class="mt-name-sub">${App.esc(m.real_name || '')}</div>
                </td>
                <td class="mt-col-userid"><span class="mt-userid-text">${App.esc(m.user_id || '-')}</span></td>
                ${showGroup ? `<td class="mt-col-group">${App.esc(m.group_name || '-')}</td>` : ''}
                <td class="mt-col-hist">
                    <div class="mt-hist-summary">${historyHtml(m)}</div>
                </td>
                <td class="mt-col-bravo">${bravo || '-'}</td>
                <td class="mt-col-score">${scoreHtml(m.current_score)}</td>
                <td class="mt-col-entered">${enteredBadge(m)}</td>
                <td class="mt-col-status">${statusBadge(m)}</td>
            </tr>`;

            // 펼침 행
            const detailRow = `
            <tr class="mt-detail" data-for="${m.id}" style="display:none">
                <td colspan="${colCount}">
                    <div class="mt-detail-grid">
                        <div class="mt-detail-section">
                            <div class="mt-detail-label">기본 정보</div>
                            <div class="mt-detail-items">
                                <span>${stageBadge}</span>
                                ${mode === 'operation' ? `<span>ID: ${App.esc(m.user_id || '-')}</span>` : ''}
                                <span>전화: ${App.esc(m.phone || '-')}</span>
                                ${m.cafe_member_key ? `<span>카페: 연동됨</span>` : ''}
                            </div>
                        </div>
                        <div class="mt-detail-section">
                            <div class="mt-detail-label">참여 이력</div>
                            <div class="mt-detail-items">
                                <span>1단계: ${parseInt(m.stage1_participation_count) || 0}회</span>
                                <span>2단계: ${parseInt(m.stage2_participation_count) || 0}회</span>
                                <span>완주: ${parseInt(m.completed_bootcamp_count) || 0}회</span>
                            </div>
                        </div>
                        <div class="mt-detail-section">
                            <div class="mt-detail-label">점수 / 코인</div>
                            <div class="mt-detail-items">
                                <span>점수: ${scoreHtml(m.current_score)}</span>
                                <span>코인: ${m.current_coin || 0}</span>
                            </div>
                        </div>
                        <div class="mt-detail-actions">
                            <button class="btn btn-sm btn-secondary" onclick="${editFn}(${m.id})">수정</button>
                            <button class="btn btn-sm btn-danger-outline" onclick="${deleteFn}(${m.id}, '${App.esc(m.nickname)}')">삭제</button>
                        </div>
                    </div>
                </td>
            </tr>`;

            return mainRow + detailRow;
        }).join('');

        return `
        <div class="mt-container">
            <table class="data-table mt-table">
                <thead><tr>${headerCols}</tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
    }

    /**
     * 테이블 내 행 클릭 토글 이벤트 바인딩
     * @param {HTMLElement} container - 테이블이 들어 있는 컨테이너
     */
    function bindToggle(container) {
        container.querySelectorAll('.mt-row').forEach(row => {
            row.addEventListener('click', (e) => {
                // 버튼 클릭은 무시
                if (e.target.closest('button') || e.target.closest('a')) return;
                const id = row.dataset.id;
                const detail = container.querySelector(`.mt-detail[data-for="${id}"]`);
                if (!detail) return;
                const isOpen = detail.style.display !== 'none';
                // 다른 열린 상세 닫기
                container.querySelectorAll('.mt-detail').forEach(d => d.style.display = 'none');
                container.querySelectorAll('.mt-row').forEach(r => r.classList.remove('mt-row--open'));
                if (!isOpen) {
                    detail.style.display = '';
                    row.classList.add('mt-row--open');
                }
            });
        });
    }

    /**
     * 검색 바 HTML 생성
     */
    function entranceStatsHtml(members) {
        const total = members.length;
        const entered = members.filter(m => parseInt(m.entered)).length;
        return `
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:var(--space-2)">
            <div class="task-filter-chips mt-entrance-filters">
                <button class="chip active" data-filter="all">전체</button>
                <button class="chip" data-filter="entered">입장 완료</button>
                <button class="chip" data-filter="not_entered">미입장</button>
            </div>
            <span class="mt-entrance-stats" style="font-size:var(--text-sm);color:var(--color-text-sub)">${entered} / ${total}명 입장 완료</span>
        </div>`;
    }

    function searchBarHtml(count, members) {
        return `${members ? entranceStatsHtml(members) : ''}
        <div class="mt-search-bar">
            <input type="text" class="form-input mt-search-input" placeholder="이름 또는 아이디로 검색" id="mt-search">
            <span class="mt-search-count" id="mt-search-count">${count}명</span>
        </div>`;
    }

    /**
     * 검색 이벤트 바인딩 — 테이블 행을 실시간 필터링
     */
    function bindSearch(container) {
        const input = container.querySelector('#mt-search');
        const countEl = container.querySelector('#mt-search-count');
        if (!input) return;

        input.addEventListener('input', App.debounce(() => {
            const q = input.value.trim().toLowerCase();
            let visible = 0;
            container.querySelectorAll('.mt-row').forEach(row => {
                const name = row.querySelector('.mt-col-name')?.textContent.toLowerCase() || '';
                const userId = row.querySelector('.mt-col-userid')?.textContent.toLowerCase() || '';
                const match = !q || name.includes(q) || userId.includes(q);
                row.style.display = match ? '' : 'none';
                const detail = container.querySelector(`.mt-detail[data-for="${row.dataset.id}"]`);
                if (detail && !match) {
                    detail.style.display = 'none';
                    row.classList.remove('mt-row--open');
                }
                if (match) visible++;
            });
            if (countEl) countEl.textContent = visible + '명';
        }, 150));
    }

    function bindEntranceFilter(container) {
        const filterWrap = container.querySelector('.mt-entrance-filters');
        if (!filterWrap) return;

        filterWrap.querySelectorAll('.chip').forEach(btn => {
            btn.onclick = () => {
                filterWrap.querySelectorAll('.chip').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const filter = btn.dataset.filter;
                let visible = 0;
                container.querySelectorAll('.mt-row').forEach(row => {
                    const entered = row.dataset.entered === '1';
                    const searchHidden = row.style.display === 'none' && !row.dataset._entranceHidden;
                    let show = filter === 'all' || (filter === 'entered' && entered) || (filter === 'not_entered' && !entered);
                    if (show) {
                        delete row.dataset._entranceHidden;
                        // 조 필터도 함께 적용
                        const groupFilter = container.querySelector('.mt-group-filters .chip.active');
                        if (show && groupFilter && groupFilter.dataset.group !== 'all') {
                            show = (row.dataset.group || '') === groupFilter.dataset.group;
                        }
                        // 검색 필터도 함께 적용
                        const searchInput = container.querySelector('#mt-search');
                        if (show && searchInput && searchInput.value.trim()) {
                            const q = searchInput.value.trim().toLowerCase();
                            const name = row.querySelector('.mt-col-name')?.textContent.toLowerCase() || '';
                            const userId = row.querySelector('.mt-col-userid')?.textContent.toLowerCase() || '';
                            show = name.includes(q) || userId.includes(q);
                        }
                    } else {
                        row.dataset._entranceHidden = '1';
                    }
                    row.style.display = show ? '' : 'none';
                    const detail = container.querySelector(`.mt-detail[data-for="${row.dataset.id}"]`);
                    if (detail && !show) {
                        detail.style.display = 'none';
                        row.classList.remove('mt-row--open');
                    }
                    if (show) visible++;
                });
                const countEl = container.querySelector('#mt-search-count');
                if (countEl) countEl.textContent = visible + '명';
            };
        });
    }

    function groupFilterHtml(members) {
        const groups = [...new Set(members.map(m => m.group_name).filter(Boolean))].sort();
        if (!groups.length) return '';
        return `
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:var(--space-2)">
            <span style="font-size:var(--text-sm);color:var(--color-text-sub);font-weight:600;">조 필터:</span>
            <div class="task-filter-chips mt-group-filters">
                <button class="chip active" data-group="all">전체</button>
                ${groups.map(g => `<button class="chip" data-group="${App.esc(g)}">${App.esc(g)}</button>`).join('')}
            </div>
        </div>`;
    }

    function bindGroupFilter(container) {
        const filterWrap = container.querySelector('.mt-group-filters');
        if (!filterWrap) return;

        filterWrap.querySelectorAll('.chip').forEach(btn => {
            btn.onclick = () => {
                filterWrap.querySelectorAll('.chip').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const group = btn.dataset.group;
                let visible = 0;
                container.querySelectorAll('.mt-row').forEach(row => {
                    const rowGroup = row.dataset.group || '';
                    let show = group === 'all' || rowGroup === group;
                    if (show) {
                        delete row.dataset._groupHidden;
                        // 검색 필터도 함께 적용
                        const searchInput = container.querySelector('#mt-search');
                        if (searchInput && searchInput.value.trim()) {
                            const q = searchInput.value.trim().toLowerCase();
                            const name = row.querySelector('.mt-col-name')?.textContent.toLowerCase() || '';
                            const userId = row.querySelector('.mt-col-userid')?.textContent.toLowerCase() || '';
                            show = name.includes(q) || userId.includes(q);
                        }
                        // 입장 필터도 함께 적용
                        const entranceFilter = container.querySelector('.mt-entrance-filters .chip.active');
                        if (show && entranceFilter) {
                            const ef = entranceFilter.dataset.filter;
                            if (ef !== 'all') {
                                const entered = row.dataset.entered === '1';
                                show = (ef === 'entered' && entered) || (ef === 'not_entered' && !entered);
                            }
                        }
                    } else {
                        row.dataset._groupHidden = '1';
                    }
                    row.style.display = show ? '' : 'none';
                    const detail = container.querySelector(`.mt-detail[data-for="${row.dataset.id}"]`);
                    if (detail && !show) {
                        detail.style.display = 'none';
                        row.classList.remove('mt-row--open');
                    }
                    if (show) visible++;
                });
                const countEl = container.querySelector('#mt-search-count');
                if (countEl) countEl.textContent = visible + '명';
            };
        });
    }

    return { render, bindToggle, bindSearch, bindEntranceFilter, bindGroupFilter, groupFilterHtml, searchBarHtml, ROLE_LABELS };
})();
