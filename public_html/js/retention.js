/* ══════════════════════════════════════════════════════════════
   RetentionApp — /operation 리텐션 관리 탭
   페어 선택 → 카드, GA4 잔존 곡선, breakdown 3종 표시.
   Spec: docs/superpowers/specs/2026-04-28-retention-management-design.md
   ══════════════════════════════════════════════════════════════ */
const RetentionApp = (() => {
    const API = '/api/admin.php?action=';
    let root = null;
    let pairs = [];
    let currentAnchorId = null;
    let chart = null;

    async function init(container) {
        root = container;
        await loadPairs();
    }

    async function loadPairs() {
        root.innerHTML = '<div class="loading">페어 목록 로드 중…</div>';
        const r = await App.get(API + 'retention_pairs');
        if (!r.success) {
            root.innerHTML = `<div class="error">${App.esc(r.error || '오류')}</div>`;
            return;
        }
        pairs = r.pairs || [];
        if (pairs.length === 0) {
            root.innerHTML = '<div class="empty">분석 가능한 페어가 없습니다. 다음 기수에 등록자가 1명 이상 있어야 합니다.</div>';
            return;
        }
        renderShell();
        // default: 가장 최근 anchor (페어 목록의 마지막)
        selectPair(pairs[pairs.length - 1].anchor_cohort_id);
    }

    function renderShell() {
        const buttons = pairs.map(p => `
            <button class="ret-pair-btn" data-anchor="${p.anchor_cohort_id}">
                ${App.esc(p.anchor_name)} → ${App.esc(p.next_name)}
            </button>
        `).join('');
        root.innerHTML = `
            <div class="ret-header">
                <h2>리텐션 관리</h2>
                <button class="btn btn-secondary btn-sm" id="ret-refresh">새로고침</button>
            </div>
            <div class="ret-pair-strip">${buttons}</div>
            <div class="ret-pair-title" id="ret-pair-title"></div>
            <div class="ret-cards" id="ret-cards"></div>
            <div class="ret-section">
                <h3>코호트 잔존 곡선 (anchor 이후 기수, step-independent)</h3>
                <div class="ret-curve-wrap"><canvas id="ret-curve"></canvas></div>
                <div class="muted ret-curve-note">직전·과거 모두 포함, 한 기수 빠진 후 돌아와도 잔존으로 카운트.</div>
            </div>
            <div class="ret-section">
                <h3>상황별 리텐션 breakdown</h3>
                <div class="ret-breakdowns" id="ret-breakdowns"></div>
            </div>
            <div class="ret-footnote" id="ret-footnote"></div>
        `;
        root.querySelector('.ret-pair-strip').addEventListener('click', e => {
            const btn = e.target.closest('.ret-pair-btn');
            if (!btn) return;
            selectPair(parseInt(btn.dataset.anchor, 10));
        });
        root.querySelector('#ret-refresh').addEventListener('click', () => {
            if (currentAnchorId) selectPair(currentAnchorId);
        });
    }

    async function selectPair(anchorId) {
        currentAnchorId = anchorId;
        root.querySelectorAll('.ret-pair-btn').forEach(b => {
            b.classList.toggle('active', parseInt(b.dataset.anchor, 10) === anchorId);
        });
        document.getElementById('ret-cards').innerHTML = '<div class="loading">로드 중…</div>';
        document.getElementById('ret-breakdowns').innerHTML = '';

        const r = await App.get(API + 'retention_summary&anchor_cohort_id=' + anchorId);
        if (!r.success) {
            document.getElementById('ret-cards').innerHTML = `<div class="error">${App.esc(r.error || '오류')}</div>`;
            return;
        }
        renderSummary(r);
    }

    function renderSummary(d) {
        // Tasks 14, 15, 16 implement these.
        renderTitle(d);
        renderCards(d);
        renderCurve(d);
        renderBreakdowns(d);
        renderFootnote(d);
    }

    function renderTitle(d)      { /* Task 14 */ }
    function renderCards(d)      { /* Task 14 */ }
    function renderCurve(d)      { /* Task 15 */ }
    function renderBreakdowns(d) { /* Task 16 */ }
    function renderFootnote(d)   { /* Task 14 */ }

    return { init };
})();
