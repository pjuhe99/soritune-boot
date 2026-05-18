/* ══════════════════════════════════════════════════════════════
   MemberNotices — 회원 메인 상단 공지 카드 영역
   프로필 카드 직후, 커리큘럼 직전에 렌더
   ══════════════════════════════════════════════════════════════ */
const MemberNotices = (() => {
    const API = '/api/bootcamp.php?action=';

    function renderMarkdown(text) {
        if (typeof marked !== 'undefined') {
            return marked.parse(text || '', { breaks: true });
        }
        return App.esc(text || '').replace(/\n/g, '<br>');
    }

    function fmtDate(s) {
        // 'YYYY-MM-DD HH:MM:SS' → 'YYYY-MM-DD'
        return (s || '').slice(0, 10);
    }

    async function render(rootEl) {
        if (!rootEl) return;
        rootEl.innerHTML = '';
        rootEl.style.display = 'none';   // 기본은 숨김 (실패/0건 시 공간 안 차지)
        const r = await App.get(API + 'notices');
        if (!r.success) return;

        const notices = r.notices || [];
        if (notices.length === 0) return;

        rootEl.style.display = '';  // 카드가 있을 때만 다시 보이게
        rootEl.innerHTML = notices.map(n => `
            <div class="member-notice-card">
                <h3 class="member-notice-title">${App.esc(n.title)}</h3>
                <hr class="member-notice-divider">
                <div class="member-notice-body markdown-body">${renderMarkdown(n.body_markdown)}</div>
                <div class="member-notice-meta">
                    ${App.esc(n.created_by_admin_name)} · ${App.esc(fmtDate(n.created_at))}
                </div>
            </div>
        `).join('');
    }

    return { render };
})();
