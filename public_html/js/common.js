/* ══════════════════════════════════════════════════════════════
   App Common Module (LMS 기반)
   ══════════════════════════════════════════════════════════════ */
const App = (() => {
    // ── API Wrapper ──
    async function api(endpoint, options = {}) {
        const { method = 'GET', data = null, showError = true } = options;
        const fetchOpts = {
            method,
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        };

        let url = endpoint;

        if (data && method === 'GET') {
            const params = new URLSearchParams(data);
            url += (url.includes('?') ? '&' : '?') + params.toString();
        } else if (data && method !== 'GET') {
            if (data instanceof FormData) {
                fetchOpts.body = data;
            } else {
                fetchOpts.headers['Content-Type'] = 'application/json';
                fetchOpts.body = JSON.stringify(data);
            }
        }

        try {
            const resp = await fetch(url, fetchOpts);
            const json = await resp.json();

            if (resp.status === 401) {
                if (showError) Toast.warning('세션이 만료되었습니다. 다시 로그인해주세요.');
                return { success: false, error: 'Unauthorized' };
            }

            if (!json.success && showError && json.error) {
                Toast.error(json.error);
            }

            return json;
        } catch (err) {
            if (showError) Toast.error('서버 연결에 실패했습니다.');
            return { success: false, error: err.message };
        }
    }

    function get(endpoint, params = null) {
        return api(endpoint, { method: 'GET', data: params });
    }

    function post(endpoint, data = null) {
        return api(endpoint, { method: 'POST', data });
    }

    // ── Loading ──
    let loadingEl = null;

    function showLoading() {
        if (loadingEl) return;
        loadingEl = document.createElement('div');
        loadingEl.className = 'loading-overlay';
        loadingEl.innerHTML = '<div class="spinner"></div>';
        document.body.appendChild(loadingEl);
    }

    function hideLoading() {
        if (loadingEl) { loadingEl.remove(); loadingEl = null; }
    }

    // ── Layer / Modal (LMS 패턴) ──
    function openModal(title, bodyHtml, footerHtml = '') {
        closeModal();
        const overlay = document.createElement('div');
        overlay.className = 'layer-overlay';
        overlay.id = 'app-modal';
        overlay.innerHTML = `
            <div class="layer_wrap">
                <div class="header">
                    <h3>${esc(title)}</h3>
                    <button class="close" onclick="App.closeModal()">&times;</button>
                </div>
                <div class="contents">${bodyHtml}</div>
                ${footerHtml ? `<div class="footer">${footerHtml}</div>` : ''}
            </div>
        `;
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal();
        });
        document.body.appendChild(overlay);
    }

    function closeModal() {
        const m = document.getElementById('app-modal');
        if (m) m.remove();
    }

    // ── Confirm (LMS confirm 패턴) ──
    function confirm(message) {
        return new Promise(resolve => {
            closeModal();
            const overlay = document.createElement('div');
            overlay.className = 'layer-overlay';
            overlay.id = 'app-modal';
            overlay.innerHTML = `
                <div class="layer_wrap confirm">
                    <div class="contents" style="padding:20px 10px;">
                        <p style="font-size:14px;line-height:1.6;color:var(--color-semi-black)">${esc(message)}</p>
                    </div>
                    <div class="footer">
                        <button class="btn btn-secondary btn-sm" id="confirm-no">취소</button>
                        <button class="btn-confirm-ok" id="confirm-yes">확인</button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
            document.getElementById('confirm-yes').onclick = () => { closeModal(); resolve(true); };
            document.getElementById('confirm-no').onclick = () => { closeModal(); resolve(false); };
        });
    }

    // ── Date Helpers ──
    const WEEKDAYS = ['일', '월', '화', '수', '목', '금', '토'];

    function formatDate(date) {
        if (typeof date === 'string') date = new Date(date + 'T00:00:00');
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function formatDateKo(dateStr) {
        const d = new Date(dateStr + 'T00:00:00');
        const m = d.getMonth() + 1;
        const day = d.getDate();
        const w = WEEKDAYS[d.getDay()];
        return `${m}월 ${day}일 (${w})`;
    }

    function today() {
        return formatDate(new Date());
    }

    // ── Tabs (LMS tab 패턴) ──
    function initTabs(container) {
        const btns = container.querySelectorAll('.tab');
        const contents = container.querySelectorAll('.tab-content');

        btns.forEach(btn => {
            btn.addEventListener('click', () => {
                btns.forEach(b => b.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
                const target = container.querySelector(btn.dataset.tab);
                if (target) target.classList.add('active');
            });
        });
    }

    // ── Escape HTML ──
    function esc(str) {
        if (str == null) return '';
        const d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }

    // ── Debounce ──
    function debounce(fn, ms = 300) {
        let t;
        return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    }

    return {
        api, get, post,
        showLoading, hideLoading,
        openModal, closeModal, confirm,
        formatDate, formatDateKo, today,
        initTabs, esc, debounce,
    };
})();
