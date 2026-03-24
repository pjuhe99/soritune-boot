/* ══════════════════════════════════════════════════════════════
   MemberHome — 프로필 카드 + 오늘의 진도 (대시보드 상단 영역)
   member.js의 showDashboard에서 분리
   ══════════════════════════════════════════════════════════════ */
const MemberHome = (() => {
    const API = '/api/bootcamp.php?action=';

    // ── 커리큘럼 도움말 키 매핑 (task_type → system_contents key) ──
    const CURRICULUM_HELP_KEYS = {
        malkka_mission: 'help_malkka_mission',
        naemat33_mission: 'help_naemat33_mission',
        zoom_or_daily_mission: 'help_zoom_or_daily_mission',
        hamummal: 'help_hamummal',
    };

    /**
     * 프로필 카드 + 커리큘럼 렌더링
     * @param {HTMLElement} headerEl - 헤더 아래 프로필 영역
     * @param {object} member - 로그인 멤버 정보
     */
    function render(headerEl, member) {
        headerEl.innerHTML = `
            <div class="member-info-card">
                <div class="member-nickname">
                    ${App.esc(member.nickname || member.member_name)}
                    <button class="nickname-edit-btn" title="닉네임 수정">✏️</button>
                </div>
                <div class="member-realname">${App.esc(member.member_name)}${member.group_name ? ` · ${App.esc(member.group_name)}` : ''}</div>
                <div class="member-stats-grid">
                    <div class="stat-card stat-score">
                        <div class="stat-card-icon">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 2l2.09 4.26L17 7.27l-3.5 3.42.82 4.81L10 13.27 5.68 15.5l.82-4.81L3 7.27l4.91-1.01L10 2z" fill="currentColor"/></svg>
                        </div>
                        <div class="stat-card-body">
                            <div class="stat-card-value">${member.score ?? 0}</div>
                            <div class="stat-card-label">점수 <button class="cur-help-btn" data-guide="score_guide">?</button></div>
                        </div>
                    </div>
                    <div class="stat-card stat-coin">
                        <div class="stat-card-icon">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.8" fill="none"/><text x="10" y="14" text-anchor="middle" font-size="10" font-weight="700" fill="currentColor">C</text></svg>
                        </div>
                        <div class="stat-card-body">
                            <div class="stat-card-value">${member.coin ?? 0}</div>
                            <div class="stat-card-label">코인 <button class="cur-help-btn" data-guide="coin_guide">?</button></div>
                        </div>
                    </div>
                    <div class="stat-card stat-completed">
                        <div class="stat-card-icon">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M6.5 3.5h7a2 2 0 012 2v9a2 2 0 01-2 2h-7a2 2 0 01-2-2v-9a2 2 0 012-2z" stroke="currentColor" stroke-width="1.8" fill="none"/><path d="M7.5 9.5l2 2 3.5-3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </div>
                        <div class="stat-card-body">
                            <div class="stat-card-value">${member.completed_count ?? 0}<span class="stat-card-unit">회</span></div>
                            <div class="stat-card-label">완주</div>
                        </div>
                    </div>
                    <div class="stat-card stat-bravo">
                        <div class="stat-card-icon">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 2.5c-1.5 0-2.5 1-2.5 2.5 0 1.2.8 2.2 2 2.4V9H7a.5.5 0 000 1h2.5v1.5a4 4 0 00-3 3.87.5.5 0 00.5.5h6a.5.5 0 00.5-.5 4 4 0 00-3-3.87V10H13a.5.5 0 000-1h-2.5V7.4c1.2-.2 2-1.2 2-2.4 0-1.5-1-2.5-2.5-2.5z" fill="currentColor"/></svg>
                        </div>
                        <div class="stat-card-body">
                            <div class="stat-card-value">${member.bravo_grade ? App.esc(member.bravo_grade) : '-'}</div>
                            <div class="stat-card-label">브라보</div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="member-curriculum-section"></div>
            <div id="member-shortcuts-section"></div>
        `;

        headerEl.querySelectorAll('.cur-help-btn[data-guide]').forEach(btn => {
            btn.onclick = () => showGuide(btn.dataset.guide);
        });

        headerEl.querySelector('.nickname-edit-btn').onclick = () => showNicknameEditModal(headerEl, member);

        loadCurriculumToday();
        MemberShortcuts.render(document.getElementById('member-shortcuts-section'), member);
    }

    async function loadCurriculumToday() {
        const sec = document.getElementById('member-curriculum-section');
        if (!sec) return;

        const r = await App.get(API + 'curriculum_today');
        if (!r.success || !r.items || !r.items.length) {
            sec.innerHTML = '';
            return;
        }

        const progressItems = r.items.filter(i => i.task_type === 'progress');
        const taskItems = r.items.filter(i => i.task_type !== 'progress');

        const progressHtml = progressItems.map(item => {
            const note = item.note ? `<div class="cur-progress-note">${App.esc(item.note)}</div>` : '';
            return `<div class="cur-progress-box"><div class="cur-progress-label">${App.esc(item.task_type_label)}</div>${note}</div>`;
        }).join('');

        const taskHtml = taskItems.map(item => {
            const note = item.note ? `<span class="cur-note">${App.esc(item.note)}</span>` : '';
            const helpBtn = CURRICULUM_HELP_KEYS[item.task_type]
                ? `<button class="cur-help-btn" data-guide="${App.esc(CURRICULUM_HELP_KEYS[item.task_type])}">?</button>`
                : '';
            return `<div class="cur-item"><span class="cur-label">${App.esc(item.task_type_label)}</span>${helpBtn}${note}</div>`;
        }).join('');

        const items = progressHtml + (taskHtml ? `<div class="cur-list">${taskHtml}</div>` : '');

        sec.innerHTML = `
            <div class="member-curriculum-card">
                <div class="cur-title">오늘의 진도 &amp; 할 일</div>
                ${items}
            </div>
        `;

        sec.querySelectorAll('.cur-help-btn[data-guide]').forEach(btn => {
            btn.onclick = () => showGuide(btn.dataset.guide);
        });
    }

    // ── 안내 팝업 (점수/코인 + 커리큘럼 공용) ──

    function renderMarkdown(md) {
        if (!md) return '';
        const lines = md.split('\n');
        let html = '';
        let inList = false;
        let inBlockquote = false;
        let inTable = false;
        let tableHeaderDone = false;

        function closeOpen() {
            if (inList) { html += inList === 'ol' ? '</ol>' : '</ul>'; inList = false; }
            if (inBlockquote) { html += '</blockquote>'; inBlockquote = false; }
            if (inTable) { html += '</tbody></table>'; inTable = false; tableHeaderDone = false; }
        }

        for (const line of lines) {
            const trimmed = line.trim();

            // 테이블 구분선 (|---|---|) — 건너뜀
            if (/^\|[\s\-:|]+\|$/.test(trimmed)) {
                tableHeaderDone = true;
                continue;
            }

            // 테이블 행
            if (trimmed.startsWith('|') && trimmed.endsWith('|')) {
                if (inList) { html += '</ul>'; inList = false; }
                if (inBlockquote) { html += '</blockquote>'; inBlockquote = false; }
                const cells = trimmed.slice(1, -1).split('|').map(c => c.trim());
                if (!inTable) {
                    html += '<table class="guide-table"><thead><tr>';
                    cells.forEach(c => { html += '<th>' + c + '</th>'; });
                    html += '</tr></thead><tbody>';
                    inTable = true;
                    tableHeaderDone = false;
                } else {
                    html += '<tr>';
                    cells.forEach(c => { html += '<td>' + c + '</td>'; });
                    html += '</tr>';
                }
                continue;
            }

            // 테이블이 끝남
            if (inTable) { html += '</tbody></table>'; inTable = false; tableHeaderDone = false; }

            // 비순서 리스트
            if (trimmed.startsWith('- ')) {
                if (inBlockquote) { html += '</blockquote>'; inBlockquote = false; }
                if (inList !== 'ul') { if (inList) html += inList === 'ol' ? '</ol>' : '</ul>'; html += '<ul>'; inList = 'ul'; }
                html += '<li>' + trimmed.slice(2) + '</li>';
                continue;
            }

            // 순서 리스트
            const olMatch = trimmed.match(/^(\d+)\.\s+(.+)$/);
            if (olMatch) {
                if (inBlockquote) { html += '</blockquote>'; inBlockquote = false; }
                if (inList !== 'ol') { if (inList) html += inList === 'ul' ? '</ul>' : '</ol>'; html += '<ol>'; inList = 'ol'; }
                html += '<li>' + olMatch[2] + '</li>';
                continue;
            }

            if (inList) { html += inList === 'ol' ? '</ol>' : '</ul>'; inList = false; }

            // 인용구
            if (trimmed.startsWith('> ')) {
                if (!inBlockquote) { html += '<blockquote>'; inBlockquote = true; }
                html += '<p>' + trimmed.slice(2) + '</p>';
                continue;
            }
            if (inBlockquote) { html += '</blockquote>'; inBlockquote = false; }

            // 구분선
            if (/^---+$/.test(trimmed)) { html += '<hr>'; continue; }

            // 제목
            if (trimmed.startsWith('### ')) { html += '<h3>' + trimmed.slice(4) + '</h3>'; }
            else if (trimmed.startsWith('## ')) { html += '<h2>' + trimmed.slice(3) + '</h2>'; }
            else if (trimmed.startsWith('# ')) { html += '<h1>' + trimmed.slice(2) + '</h1>'; }
            else if (trimmed === '') { /* 빈 줄 무시 */ }
            else { html += '<p>' + trimmed + '</p>'; }
        }
        closeOpen();

        return html
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/`(.+?)`/g, '<code>$1</code>');
    }

    const GUIDE_TITLES = {
        score_guide: '점수 안내',
        coin_guide: '코인 안내',
        help_malkka_mission: '말까 미션 안내',
        help_naemat33_mission: '내맛33 미션 안내',
        help_zoom_or_daily_mission: '줌 특강 / 데일리 미션 안내',
        help_hamummal: '하멈말 안내',
    };

    const guideCache = {};

    async function showGuide(key) {
        if (!(key in guideCache)) {
            const r = await App.get(API + 'system_content', { key });
            guideCache[key] = r.success ? r.content : null;
        }

        const title = GUIDE_TITLES[key] || '안내';
        const html = guideCache[key]
            ? `<div class="score-coin-guide-content">${renderMarkdown(guideCache[key])}</div>`
            : '<p class="score-coin-guide-empty">안내가 준비되지 않았습니다.</p>';

        App.openModal(title, html);
    }

    function showNicknameEditModal(headerEl, member) {
        App.openModal('닉네임 수정', `
            <div class="nickname-edit-form">
                <div class="form-group">
                    <label class="form-label">새 닉네임</label>
                    <input type="text" class="form-input" id="nickname-edit-input"
                           value="${App.esc(member.nickname || member.member_name)}"
                           placeholder="닉네임 입력 (1~20자)" maxlength="20">
                </div>
                <div class="nickname-edit-actions">
                    <button class="btn btn-secondary" id="nickname-edit-cancel">취소</button>
                    <button class="btn btn-primary" id="nickname-edit-save">저장</button>
                </div>
            </div>
        `);

        const input = document.getElementById('nickname-edit-input');
        input.focus();
        input.select();

        document.getElementById('nickname-edit-cancel').onclick = () => App.closeModal();
        document.getElementById('nickname-edit-save').onclick = async () => {
            const nickname = input.value.trim();
            if (!nickname) { Toast.error('닉네임을 입력해주세요.'); return; }
            if (nickname.length > 20) { Toast.error('닉네임은 20자 이내로 입력해주세요.'); return; }

            App.showLoading();
            const r = await App.post('/api/member.php?action=save_nickname', { nickname });
            App.hideLoading();

            if (r.success) {
                member.nickname = r.nickname;
                headerEl.querySelector('.member-nickname').childNodes[0].textContent = r.nickname;
                App.closeModal();
                Toast.success(r.message);
            }
        };
    }

    return { render };
})();
