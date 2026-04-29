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
        pairs = (r.pairs || []).slice().reverse();
        if (pairs.length === 0) {
            root.innerHTML = '<div class="empty">분석 가능한 페어가 없습니다. 다음 기수에 등록자가 1명 이상 있어야 합니다.</div>';
            return;
        }
        renderShell();
        // default: 가장 최근 anchor (목록 첫 번째)
        selectPair(pairs[0].anchor_cohort_id);
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

    function renderTitle(d) {
        const t = document.getElementById('ret-pair-title');
        const ts = (d.generated_at || '').replace('T', ' ').slice(0, 19);
        t.innerHTML = `
            <div>▎<strong>${App.esc(d.anchor.name)} → ${App.esc(d.next.name)}</strong>
                 리텐션 (anchor ${d.anchor.total_with_user_id}명 · 다음 ${d.next.total_with_user_id}명)</div>
            <div class="muted ret-pair-meta">조회 시각: ${App.esc(ts)}</div>
        `;
    }

    function renderCards(d) {
        const c = d.cards;
        const totalNext = c.next_total_with_user_id;
        const pct = (n) => totalNext > 0 ? Math.round(n / totalNext * 100) : 0;
        document.getElementById('ret-cards').innerHTML = `
            <div class="ret-card">
                <div class="ret-card-label">잔존 (직전 → 다음)</div>
                <div class="ret-card-num">${pct(c.stay)}% · ${c.stay}명</div>
                <div class="muted">리텐션 ${c.retention_pct}%</div>
            </div>
            <div class="ret-card">
                <div class="ret-card-label">회귀 (과거 → 다음)</div>
                <div class="ret-card-num">${pct(c.returning)}% · ${c.returning}명</div>
            </div>
            <div class="ret-card">
                <div class="ret-card-label">신규 (첫 참여)</div>
                <div class="ret-card-num">${pct(c.brand_new)}% · ${c.brand_new}명</div>
            </div>
            <div class="ret-card ret-card-sum">
                <div class="ret-card-label">${App.esc(d.next.name)} 총 (user_id 보유)</div>
                <div class="ret-card-num">${totalNext}명</div>
                <div class="muted">전체 row ${d.next.total}건</div>
            </div>
        `;
    }

    function renderCurve(d) {
        const ctx = document.getElementById('ret-curve');
        if (chart) { chart.destroy(); chart = null; }
        const labels = d.curve.map(p => p.cohort_name);
        const data   = d.curve.map(p => p.pct);
        const counts = d.curve.map(p => p.count);
        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Retention %',
                    data,
                    fill: false,
                    tension: 0.15,
                    borderColor: '#2563EB',
                    backgroundColor: '#2563EB',
                    pointRadius: 5,
                    pointHoverRadius: 7,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } },
                    x: { title: { display: true, text: 'Cohort' } },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const i = ctx.dataIndex;
                                return `${data[i]}%  (${counts[i]}명)`;
                            }
                        }
                    },
                },
            },
        });
    }
    function renderBreakdowns(d) {
        const cards = [];

        // 조별
        if (d.breakdown.group) {
            cards.push(buildBreakdownCard(
                '조별',
                d.breakdown.group.rows.map(r => ({
                    label: r.name + (r.kind === 'unassigned' ? ' (미배정)' : r.kind === 'anomaly' ? ' (조 정보 이상)' : ''),
                    total: r.total, transitioned: r.transitioned, pct: r.pct,
                })),
                ''
            ));
        } else {
            cards.push(disabledCard('조별', '이 anchor 기수에는 조 데이터가 없습니다.'));
        }

        // 점수
        if (d.breakdown.score) {
            const meta = `점수 보유 ${d.breakdown.score.scored_total}명 (anchor의 ${d.breakdown.score.coverage_pct}%) 기준`;
            cards.push(buildBreakdownCard(
                '점수 범위',
                d.breakdown.score.rows.map(r => ({
                    label: r.band, total: r.total, transitioned: r.transitioned, pct: r.pct,
                })),
                meta
            ));
        } else {
            cards.push(disabledCard('점수 범위', '점수 데이터가 충분하지 않거나 없습니다 (anchor의 50% 미만).'));
        }

        // 누적 참여 횟수
        cards.push(buildBreakdownCard(
            '누적 참여 횟수',
            d.breakdown.participation.rows.map(r => ({
                label: r.bucket, total: r.total, transitioned: r.transitioned, pct: r.pct,
            })),
            ''
        ));

        document.getElementById('ret-breakdowns').innerHTML = cards.join('');
    }

    function buildBreakdownCard(title, rows, meta) {
        const trs = rows.map(r => `
            <tr>
                <td class="ret-bd-label">${App.esc(r.label)}</td>
                <td class="ret-bd-num">${r.total}</td>
                <td class="ret-bd-num">${r.transitioned}</td>
                <td class="ret-bd-bar">
                    <div class="ret-bar"><div class="ret-bar-fill" style="width:${r.pct}%"></div></div>
                </td>
                <td class="ret-bd-pct">${r.pct}%</td>
            </tr>
        `).join('');
        return `
            <div class="ret-bd-card">
                <h4>${App.esc(title)}</h4>
                ${meta ? `<div class="muted ret-bd-meta">${App.esc(meta)}</div>` : ''}
                <table class="ret-bd-table">
                    <thead><tr><th>구간</th><th>인원</th><th>진출</th><th>진출률</th><th></th></tr></thead>
                    <tbody>${trs}</tbody>
                </table>
            </div>`;
    }

    function disabledCard(title, msg) {
        return `
            <div class="ret-bd-card ret-bd-card-disabled">
                <h4>${App.esc(title)}</h4>
                <div class="muted">${App.esc(msg)}</div>
            </div>`;
    }

    function renderFootnote(d) {
        const f = document.getElementById('ret-footnote');
        const lines = [];
        if (d.cards.excluded_null_user_id > 0) {
            lines.push(`user_id 미입력 회원 ${d.cards.excluded_null_user_id}명은 분석에서 제외됨 (anchor + next 합산).`);
        }
        f.innerHTML = lines.length
            ? `<div class="muted">※ ${lines.map(App.esc).join(' · ')}</div>`
            : '';
    }

    return { init };
})();
