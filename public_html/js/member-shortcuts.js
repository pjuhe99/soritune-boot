/* ══════════════════════════════════════════════════════════════
   MemberShortcuts — 바로가기 버튼 영역
   프로필 카드 아래, 탭 영역 위에 위치
   ══════════════════════════════════════════════════════════════ */
const MemberShortcuts = (() => {
    const API = '/api/bootcamp.php?action=';

    // ── 바로가기 버튼 데이터 ──
    // url이 null이면 회원 DB에서 가져오는 동적 링크
    const SHORTCUTS = [
        { key: 'lecture',   label: '소리블록 VOD 강의 들으러 가기',                url: 'https://www.sorimaster.com',                                    color: 'blue' },
        { key: 'daily',     label: '데일리 미션 하러 가기 (네이버 카페로 이동)',   url: 'https://m.cafe.naver.com/ca-fe/web/cafes/23243775/menus/288',   color: 'green' },
        { key: 'naemat33',  label: '내맛33미션 하러 가기 (네이버 카페로 이동)',    url: 'https://m.cafe.naver.com/ca-fe/web/cafes/23243775/menus/322',   color: 'amber' },
        { key: 'malkka',    label: '말까 미션 하러 가기 (네이버 카페로 이동)',     url: 'https://m.cafe.naver.com/ca-fe/web/cafes/23243775/menus/290',   color: 'violet' },
        { key: 'kakao',     label: '조별 카톡방 들어가기',    url: null,                                                            color: 'rose' },
    ];

    async function render(containerEl, member) {
        // 1) 기본 shortcut HTML
        const kakaoLink = member.kakao_link || null;
        const baseButtons = SHORTCUTS.map(s => {
            const href = s.url || kakaoLink;
            const disabled = !href;
            const disabledClass = disabled ? ' shortcut-btn--disabled' : '';
            const disabledAttr = disabled ? ' disabled aria-disabled="true"' : '';
            const colorClass = ` shortcut-btn--${s.color}`;

            if (disabled) {
                const hint = s.key === 'kakao' ? '<span class="shortcut-hint">조별 카톡방 링크는 목요일 중에 오픈됩니다</span>' : '';
                return `<button class="shortcut-btn${disabledClass}" type="button"${disabledAttr}>
                    <span class="shortcut-label">${App.esc(s.label)}</span>
                </button>${hint}`;
            }
            return `<a class="shortcut-btn${colorClass}" href="${App.esc(href)}" target="_blank" rel="noopener noreferrer">
                <span class="shortcut-label">${App.esc(s.label)}</span>
                <span class="shortcut-arrow">&#8250;</span>
            </a>`;
        }).join('');

        containerEl.innerHTML = `
            <div class="shortcuts-card">
                <div class="shortcuts-title">바로가기</div>
                <div class="shortcuts-list" id="shortcuts-list-inner">${baseButtons}</div>
            </div>
        `;

        // 2) 후기 카드 조건부 prepend (서버 상태에 따라)
        try {
            const r = await App.get(API + 'my_review_status');
            if (!r.success) return;
            const anyEnabled = (r.cafe?.enabled || r.blog?.enabled);
            if (!r.eligible || !anyEnabled) return;

            const reviewBtn = `<button class="shortcut-btn shortcut-btn--amber" id="shortcut-review-submit" type="button">
                <span class="shortcut-label">후기 작성하기 (+5 코인/편)</span>
                <span class="shortcut-arrow">&#8250;</span>
            </button>`;
            const inner = document.getElementById('shortcuts-list-inner');
            if (inner) inner.insertAdjacentHTML('afterbegin', reviewBtn);

            const btn = document.getElementById('shortcut-review-submit');
            if (btn) btn.addEventListener('click', () => MemberApp.openReviewSubmit());
        } catch (_e) { /* 네트워크/권한 에러는 조용히 무시 — 후기 카드만 안 보임 */ }
    }

    return { render };
})();
