/* ══════════════════════════════════════════════════════════════
   MemberReview — /후기작성 상세 화면
   바로가기의 "후기 작성하기" 카드를 탭하면 진입, "← 뒤로"로 복귀.
   스펙: docs/superpowers/specs/2026-04-23-review-submission-design.md
   ══════════════════════════════════════════════════════════════ */
const MemberReview = (() => {
    const API = '/api/bootcamp.php?action=';

    async function render(root, onBack) {
        root.innerHTML = `
            <div class="review-submit-page">
                <div class="review-submit-header">
                    <button class="review-submit-back" id="review-submit-back-btn">← 뒤로</button>
                    <div class="review-submit-title">후기 작성하기</div>
                </div>
                <div class="review-submit-body" id="review-submit-body">
                    <div class="review-submit-loading">불러오는 중…</div>
                </div>
            </div>
        `;
        document.getElementById('review-submit-back-btn').onclick = onBack;

        await load();
    }

    async function load() {
        const body = document.getElementById('review-submit-body');
        const r = await App.get(API + 'my_review_status');
        if (!r.success) {
            body.innerHTML = '<div class="review-submit-empty">정보를 불러오지 못했습니다.</div>';
            return;
        }

        if (!r.eligible) {
            const msg = {
                no_active_cycle: '현재 진행 중인 기수가 없습니다.',
                cohort_mismatch: '이번 기수 후기 접수 대상이 아닙니다.',
                member_inactive: '현재 후기 제출이 불가능한 상태입니다.',
            }[r.ineligible_reason] || '후기 제출이 불가능합니다.';
            body.innerHTML = `<div class="review-submit-empty">${App.esc(msg)}</div>`;
            return;
        }

        // 공통 가이드 블록 (상단, 펼침 기본)
        const guideHtml = (typeof MemberHome !== 'undefined' && typeof MemberHome.renderMarkdown === 'function')
            ? MemberHome.renderMarkdown(r.guide || '')
            : `<pre>${App.esc(r.guide || '')}</pre>`;
        const guideBlock = `
            <details class="review-guide-top" open>
                <summary>작성 가이드</summary>
                <div class="review-guide-body">${guideHtml}</div>
            </details>
        `;

        body.innerHTML = guideBlock
            + renderSection('cafe', '카페 후기', r.cafe)
            + renderSection('blog', '블로그 후기', r.blog);
        attachHandlers();
    }

    function renderSection(type, title, data) {
        if (!data.enabled) {
            return `
                <div class="review-section review-section-${type}">
                    <div class="review-section-head">
                        <div class="review-section-title">${App.esc(title)}</div>
                        <div class="review-section-reward">+5 코인</div>
                    </div>
                    <div class="review-section-disabled">현재 접수 중이 아닙니다.</div>
                </div>
            `;
        }

        if (data.submitted) {
            const d = formatDate(data.submitted.submitted_at);
            return `
                <div class="review-section review-section-${type}">
                    <div class="review-section-head">
                        <div class="review-section-title">${App.esc(title)}</div>
                        <div class="review-section-reward">${data.submitted.coin_amount}코인 적립 완료</div>
                    </div>
                    <div class="review-submitted">
                        <div class="review-submitted-badge">✓ 제출 완료 · ${App.esc(d)}</div>
                        <a class="review-submitted-url" href="${App.esc(data.submitted.url)}" target="_blank" rel="noopener noreferrer">${App.esc(data.submitted.url)}</a>
                    </div>
                </div>
            `;
        }

        return `
            <div class="review-section review-section-${type}">
                <div class="review-section-head">
                    <div class="review-section-title">${App.esc(title)}</div>
                    <div class="review-section-reward">+5 코인</div>
                </div>
                <div class="review-form">
                    <label class="review-form-label" for="review-url-${type}">${App.esc(title)} 링크</label>
                    <input type="url" class="review-form-input" id="review-url-${type}"
                           placeholder="https://..." maxlength="500" autocomplete="off">
                    <button class="btn btn-primary review-form-submit" data-type="${type}">제출하기</button>
                </div>
            </div>
        `;
    }

    function attachHandlers() {
        document.querySelectorAll('.review-form-submit[data-type]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const type = btn.dataset.type;
                const input = document.getElementById(`review-url-${type}`);
                const url = (input?.value || '').trim();
                if (!/^https?:\/\//i.test(url) || url.length < 10 || url.length > 500) {
                    Toast.error('URL 형식이 올바르지 않습니다 (https://로 시작, 10~500자).');
                    return;
                }

                btn.disabled = true;
                btn.textContent = '제출 중…';
                try {
                    const r = await App.post(API + 'submit_review', { type, url });
                    if (r.success) {
                        Toast.success(r.message);
                        await load();
                    } else {
                        Toast.error(r.error || r.message || '제출에 실패했습니다.');
                        btn.disabled = false;
                        btn.textContent = '제출하기';
                    }
                } catch (_e) {
                    Toast.error('네트워크 오류');
                    btn.disabled = false;
                    btn.textContent = '제출하기';
                }
            });
        });
    }

    function formatDate(ts) {
        // "2026-04-22 14:30:00" → "4/22"
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ts || '');
        if (!m) return ts || '';
        return `${parseInt(m[2])}/${parseInt(m[3])}`;
    }

    return { render };
})();
