/* ══════════════════════════════════════════════════════════════
   MemberShortcuts — 바로가기 버튼 영역
   프로필 카드 아래, 탭 영역 위에 위치
   ══════════════════════════════════════════════════════════════ */
const MemberShortcuts = (() => {

    // ── 바로가기 버튼 데이터 ──
    // url이 null이면 회원 DB에서 가져오는 동적 링크
    const SHORTCUTS = [
        { key: 'lecture',   label: '강의 들으러 가기',        url: 'https://www.sorimaster.com',                                    color: 'blue' },
        { key: 'naemat33',  label: '내맛33미션 하러 가기',    url: 'https://m.cafe.naver.com/ca-fe/web/cafes/23243775/menus/322',   color: 'amber' },
        { key: 'daily',     label: '데일리 미션 하러 가기',   url: 'https://m.cafe.naver.com/ca-fe/web/cafes/23243775/menus/288',   color: 'green' },
        { key: 'malkka',    label: '말까 미션 하러 가기',     url: 'https://m.cafe.naver.com/ca-fe/web/cafes/23243775/menus/290',   color: 'violet' },
        { key: 'kakao',     label: '조별 카톡방 들어가기',    url: null,                                                            color: 'rose' },
    ];

    /**
     * 바로가기 영역 렌더링
     * @param {HTMLElement} containerEl - 버튼을 넣을 부모 요소
     * @param {object} member - 로그인 멤버 정보 (kakao_link 포함)
     */
    function render(containerEl, member) {
        const kakaoLink = member.kakao_link || null;

        const buttons = SHORTCUTS.map(s => {
            const href = s.url || kakaoLink;
            const disabled = !href;
            const disabledClass = disabled ? ' shortcut-btn--disabled' : '';
            const disabledAttr = disabled ? ' disabled aria-disabled="true"' : '';

            const colorClass = ` shortcut-btn--${s.color}`;

            if (disabled) {
                return `<button class="shortcut-btn${disabledClass}" type="button"${disabledAttr}>
                    <span class="shortcut-label">${App.esc(s.label)}</span>
                </button>`;
            }

            return `<a class="shortcut-btn${colorClass}" href="${App.esc(href)}" target="_blank" rel="noopener noreferrer">
                <span class="shortcut-label">${App.esc(s.label)}</span>
                <span class="shortcut-arrow">&#8250;</span>
            </a>`;
        }).join('');

        containerEl.innerHTML = `
            <div class="shortcuts-card">
                <div class="shortcuts-title">바로가기</div>
                <div class="shortcuts-list">${buttons}</div>
            </div>
        `;
    }

    return { render };
})();
