/* ══════════════════════════════════════════════════════════════
   MemberGrowthRecord — 성장기록 제출 화면 (기존 후기 작성 대체)
   바로가기의 "성장기록 제출" 카드를 탭하면 진입, "← 뒤로"로 복귀.
   스펙: docs/superpowers/specs/2026-06-11-growth-record-submission-design.md
   ══════════════════════════════════════════════════════════════ */
const MemberGrowthRecord = (() => {
    const API = '/api/bootcamp.php?action=';

    async function render(root, onBack) {
        root.innerHTML = `
            <div class="growth-submit-page">
                <div class="growth-submit-header">
                    <button class="growth-submit-back" id="growth-submit-back-btn">← 뒤로</button>
                    <div class="growth-submit-title">성장기록 제출</div>
                </div>
                <div class="growth-submit-body" id="growth-submit-body">
                    <div class="growth-submit-loading">불러오는 중…</div>
                </div>
            </div>
        `;
        document.getElementById('growth-submit-back-btn').onclick = onBack;
        await load();
    }

    async function load() {
        const body = document.getElementById('growth-submit-body');
        const r = await App.get(API + 'my_growth_record_status');
        if (!r.success) {
            body.innerHTML = '<div class="growth-submit-empty">정보를 불러오지 못했습니다.</div>';
            return;
        }

        // 안내 가이드 — renderMarkdown 은 이스케이프 없이 렌더하는 운영진 신뢰 모델
        // (편집 권한이 admin 가이드 설정으로 한정 — 기존 review_guide 와 동일)
        const guideHtml = (typeof MemberHome !== 'undefined' && typeof MemberHome.renderMarkdown === 'function')
            ? MemberHome.renderMarkdown(r.guide || '')
            : `<pre>${App.esc(r.guide || '')}</pre>`;
        const guideBlock = `
            <details class="growth-guide-top" open>
                <summary>성장기록 미션 안내</summary>
                <div class="growth-guide-body">${guideHtml}</div>
            </details>
        `;

        if (r.submitted) {
            body.innerHTML = guideBlock + renderDone(r.submitted);
            return;
        }
        if (!r.eligible) {
            body.innerHTML = `<div class="growth-submit-empty">현재 제출이 불가능한 상태입니다.</div>`;
            return;
        }
        if (!r.open) {
            const msg = r.closed_reason === 'deadline_passed'
                ? '제출 기간이 마감되었습니다.' : '현재 접수 중이 아닙니다.';
            body.innerHTML = guideBlock + `<div class="growth-submit-empty">${App.esc(msg)}</div>`;
            return;
        }

        body.innerHTML = guideBlock + renderForm();
        attachHandlers();
    }

    function renderDone(s) {
        const d = (s.submitted_at || '').slice(0, 16);
        return `
            <div class="growth-done">
                <div class="growth-done-badge">✓ 제출 완료 · ${App.esc(d)}</div>
                <div class="growth-done-msg">
                    성장기록 제출이 완료되었습니다.<br>
                    소중한 후기와 음성 파일을 남겨주셔서 감사합니다.<br>
                    VOD 5주 연장은 제출 데이터 취합 후 <strong>2026년 6월 17일 수요일</strong>에 일괄 반영될 예정입니다.
                </div>
                <div class="growth-done-detail">
                    <div>후기 링크: <a href="${App.esc(s.url)}" target="_blank" rel="noopener noreferrer">${App.esc(s.url)}</a></div>
                    <div>Before 음성: ${App.esc(s.before_orig_name)}
                        <audio controls preload="none" src="${API}growth_record_audio&id=${s.id}&which=before"></audio></div>
                    <div>After 음성: ${App.esc(s.after_orig_name)}
                        <audio controls preload="none" src="${API}growth_record_audio&id=${s.id}&which=after"></audio></div>
                </div>
            </div>
        `;
    }

    function renderForm() {
        return `
            <div class="growth-form">
                <label class="growth-form-label" for="growth-url">후기 링크 (카페/블로그)</label>
                <input type="url" class="growth-form-input" id="growth-url"
                       placeholder="https://..." maxlength="500" autocomplete="off">

                <label class="growth-form-label">Before 음성 파일</label>
                <input type="file" id="growth-before" accept="audio/*,.mp3,.m4a,.wav,.webm,.ogg">

                <label class="growth-form-label">After 음성 파일</label>
                <input type="file" id="growth-after" accept="audio/*,.mp3,.m4a,.wav,.webm,.ogg">

                <div class="growth-consent-box">
                    🏅 <strong>안내 사항</strong>: 제출해 주신 소중한 후기와 음성 파일은 <strong>'베스트 성장러'</strong>로
                    선정될 경우, 소리튠영어 공식 블로그 및 카페에 우수회원 성장 스토리 콘텐츠로 감사히 재가공되어
                    활용될 예정입니다. 소리튠의 스타가 되어있을 기회! 많은 참여 부탁드립니다.
                </div>
                <label class="growth-consent-check">
                    <input type="checkbox" id="growth-consent">
                    <span>위 안내 사항을 확인했으며, 제출한 후기와 음성 파일이 베스트 성장러 콘텐츠로
                    활용될 수 있음에 동의합니다. <em>(필수)</em></span>
                </label>

                <button class="btn btn-primary growth-form-submit" id="growth-submit-btn" disabled>제출하기</button>
                <div class="growth-form-hint">음성 파일은 mp3/m4a/wav/webm/ogg, 개당 20MB까지 업로드할 수 있어요.</div>
            </div>
        `;
    }

    function attachHandlers() {
        const consent = document.getElementById('growth-consent');
        const btn = document.getElementById('growth-submit-btn');
        consent.addEventListener('change', () => { btn.disabled = !consent.checked; });

        btn.addEventListener('click', async () => {
            const url = (document.getElementById('growth-url').value || '').trim();
            const before = document.getElementById('growth-before').files[0];
            const after = document.getElementById('growth-after').files[0];

            if (!/^https?:\/\//i.test(url) || url.length < 10 || url.length > 500) {
                Toast.error('후기 링크 형식이 올바르지 않습니다 (https://로 시작, 10~500자).');
                return;
            }
            if (!before) { Toast.error('Before 음성 파일을 선택해주세요.'); return; }
            if (!after)  { Toast.error('After 음성 파일을 선택해주세요.'); return; }
            const MAX = 20 * 1024 * 1024;
            if (before.size > MAX || after.size > MAX) {
                Toast.error('음성 파일은 개당 20MB 이하여야 합니다.');
                return;
            }
            if (!consent.checked) { Toast.error('동의에 체크해주세요.'); return; }

            btn.disabled = true;
            btn.textContent = '업로드 중… (수십 초 걸릴 수 있어요)';
            try {
                const fd = new FormData();
                fd.append('url', url);
                fd.append('consent', '1');
                fd.append('before_audio', before);
                fd.append('after_audio', after);
                const res = await fetch(API + 'submit_growth_record', { method: 'POST', body: fd });
                const r = await res.json();
                if (r.success) {
                    Toast.success(r.message || '제출이 완료되었습니다.');
                    await load();
                } else {
                    Toast.error(r.error || r.message || '제출에 실패했습니다.');
                    btn.disabled = false;
                    btn.textContent = '제출하기';
                }
            } catch (_e) {
                Toast.error('네트워크 오류 — 다시 시도해주세요.');
                btn.disabled = false;
                btn.textContent = '제출하기';
            }
        });
    }

    return { render };
})();
