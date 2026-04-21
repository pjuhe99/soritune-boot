/* ══════════════════════════════════════════════════════════════
   MemberCoinHistory — /내코인 상세 화면
   대시보드의 코인 stat 카드를 탭하면 진입, "뒤로" 버튼으로 복귀.
   스펙: docs/superpowers/specs/2026-04-21-coin-history-view-design.md
   ══════════════════════════════════════════════════════════════ */
const MemberCoinHistory = (() => {
    const API = '/api/bootcamp.php?action=';

    /**
     * 화면을 root 요소에 렌더. onBack 콜백은 "뒤로" 시 호출.
     */
    async function render(root, onBack) {
        root.innerHTML = `
            <div class="coin-history-page">
                <div class="coin-history-header">
                    <button class="coin-history-back" id="coin-history-back-btn">← 뒤로</button>
                    <div class="coin-history-title">내 코인 내역</div>
                </div>
                <div class="coin-history-body" id="coin-history-body">
                    <div class="coin-history-loading">불러오는 중…</div>
                </div>
            </div>
        `;
        document.getElementById('coin-history-back-btn').onclick = onBack;

        const r = await App.get(API + 'my_coin_history');
        const body = document.getElementById('coin-history-body');
        if (!r.success) {
            body.innerHTML = '<div class="coin-history-empty">내역을 불러오지 못했습니다.</div>';
            return;
        }
        body.innerHTML = renderGroups(r.groups || []);
    }

    function renderGroups(groups) {
        if (!groups.length) {
            return '<div class="coin-history-empty">아직 받은 코인이 없습니다.<br>복습스터디에 참여해 보세요.</div>';
        }
        const cycleCards = [];
        for (const g of groups) {
            for (const c of (g.cycles || [])) {
                cycleCards.push(renderCycleCard(c));
            }
        }
        return cycleCards.join('');
    }

    function renderCycleCard(cycle) {
        const statusBadge = cycle.cycle_status === 'active'
            ? '<span class="coin-cycle-badge coin-cycle-active">적립 중</span>'
            : '';
        const bannerClass = cycle.cycle_status === 'closed'
            ? 'coin-cycle-banner-closed'
            : 'coin-cycle-banner-active';
        const logs = (cycle.logs || []).map(renderLog).join('');
        const emptyLogs = !(cycle.logs || []).length
            ? '<div class="coin-history-empty-logs">이 cycle에 기록이 없습니다.</div>' : '';
        return `
            <div class="coin-cycle-card">
                <div class="coin-cycle-head">
                    <div class="coin-cycle-name">${App.esc(cycle.cycle_name)} 코인 ${statusBadge}</div>
                    <div class="coin-cycle-total">${parseInt(cycle.earned) || 0}</div>
                </div>
                <div class="coin-cycle-banner ${bannerClass}">${App.esc(cycle.payout_message)}</div>
                <div class="coin-cycle-logs">${logs}${emptyLogs}</div>
            </div>
        `;
    }

    function renderLog(log) {
        const change = parseInt(log.change) || 0;
        const sign = change >= 0 ? '+' : '';
        const changeClass = change >= 0 ? 'coin-log-plus' : 'coin-log-minus';
        return `
            <div class="coin-log-row">
                <span class="coin-log-date">${App.esc(formatDate(log.date))}</span>
                <span class="coin-log-label">${App.esc(log.label)}</span>
                <span class="coin-log-change ${changeClass}">${sign}${change}</span>
            </div>
        `;
    }

    function formatDate(yyyymmdd) {
        // "2026-04-18" → "4/18"
        const m = /^\d{4}-(\d{2})-(\d{2})$/.exec(yyyymmdd || '');
        if (!m) return yyyymmdd || '';
        return `${parseInt(m[1])}/${parseInt(m[2])}`;
    }

    return { render };
})();
