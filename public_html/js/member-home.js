/* ══════════════════════════════════════════════════════════════
   MemberHome — 프로필 카드 + 오늘의 진도 (대시보드 상단 영역)
   member.js의 showDashboard에서 분리
   ══════════════════════════════════════════════════════════════ */
const MemberHome = (() => {
    const API = '/api/bootcamp.php?action=';

    // ── 설명 팝업 데이터 ──
    const CURRICULUM_HELP = {
        malkka_mission: {
            title: '말까미션이란?',
            description: '말까미션은 매일 30초~1분 동안 또박또박 발음하며 말을 깨끗하게 하는 연습입니다.\n\n목표 문장을 읽고 녹음하여 카페에 인증해주세요.',
            images: ['/images/help/malkka_mission.png'],
        },
        naemat33_mission: {
            title: '내맛33미션이란?',
            description: '내 맛을 33번 반복하여 체화하는 미션입니다.\n\n지정된 연습 문장을 33번 반복하고 카페에 인증해주세요.',
            images: ['/images/help/naemat33_mission.png'],
        },
        zoom_or_daily_mission: {
            title: '줌 강의 / 데일리미션이란?',
            description: '줌 강의가 있는 날은 줌 강의에 참석하고, 줌 강의가 없는 날은 데일리미션을 수행합니다.\n\n줌 강의 참석 또는 데일리미션 완료 후 카페에 인증해주세요.',
            images: ['/images/help/zoom_or_daily_mission.png'],
        },
        hamummal: {
            title: '하멈말이란?',
            description: '하루를 멈추고 말하기 — 하루를 돌아보며 짧게 말해보는 시간입니다.\n\n오늘 하루를 한 문장으로 정리하여 카페에 인증해주세요.',
            images: ['/images/help/hamummal.png'],
        },
    };

    /**
     * 프로필 카드 + 커리큘럼 렌더링
     * @param {HTMLElement} headerEl - 헤더 아래 프로필 영역
     * @param {object} member - 로그인 멤버 정보
     */
    function render(headerEl, member) {
        headerEl.innerHTML = `
            <div class="member-info-card">
                <div class="member-nickname">${App.esc(member.nickname)}</div>
                <div class="member-realname">${App.esc(member.member_name)}${member.group_name ? ` · ${App.esc(member.group_name)}` : ''}</div>
                <div class="member-stats">
                    <div class="member-stat">
                        <div class="member-point">${member.score ?? 0}</div>
                        <div class="member-point-label">점수 <button class="cur-help-btn" data-guide="score_guide">?</button></div>
                    </div>
                    <div class="member-stat">
                        <div class="member-coin">${member.coin ?? 0}</div>
                        <div class="member-coin-label">코인 <button class="cur-help-btn" data-guide="coin_guide">?</button></div>
                    </div>
                </div>
            </div>
            <div id="member-curriculum-section"></div>
            <div id="member-shortcuts-section"></div>
        `;

        headerEl.querySelectorAll('.cur-help-btn[data-guide]').forEach(btn => {
            btn.onclick = () => showGuide(btn.dataset.guide);
        });

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
            const helpBtn = CURRICULUM_HELP[item.task_type]
                ? `<button class="cur-help-btn" data-task-type="${App.esc(item.task_type)}">?</button>`
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

        sec.querySelectorAll('.cur-help-btn').forEach(btn => {
            btn.onclick = () => showCurriculumHelp(btn.dataset.taskType);
        });
    }

    function showCurriculumHelp(taskType) {
        const help = CURRICULUM_HELP[taskType];
        if (!help) return;

        const imagesHtml = (help.images || []).map(url =>
            `<img src="${App.esc(url)}" class="cur-help-img" alt="" onerror="this.style.display='none'">`
        ).join('');

        const descHtml = App.esc(help.description || '').replace(/\n/g, '<br>');

        App.openModal(help.title, `
            <div class="cur-help-body">
                ${imagesHtml}
                <div class="cur-help-desc">${descHtml}</div>
            </div>
        `);
    }

    // ── 점수/코인 안내 팝업 ──

    function renderMarkdown(md) {
        if (!md) return '';
        const lines = md.split('\n');
        let html = '';
        let inList = false;
        let inBlockquote = false;
        let inTable = false;
        let tableHeaderDone = false;

        function closeOpen() {
            if (inList) { html += '</ul>'; inList = false; }
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

            // 리스트
            if (trimmed.startsWith('- ')) {
                if (inBlockquote) { html += '</blockquote>'; inBlockquote = false; }
                if (!inList) { html += '<ul>'; inList = true; }
                html += '<li>' + trimmed.slice(2) + '</li>';
                continue;
            }
            if (inList) { html += '</ul>'; inList = false; }

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

        return html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    }

    const GUIDE_TITLES = {
        score_guide: '점수 안내',
        coin_guide: '코인 안내',
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

    return { render };
})();
