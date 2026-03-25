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
        overlay.className = 'modal-overlay';
        overlay.id = 'app-modal';
        overlay.innerHTML = `
            <div class="modal">
                <div class="modal-header">
                    <h3 class="modal-title">${esc(title)}</h3>
                    <button class="modal-close" onclick="App.closeModal()">&times;</button>
                </div>
                <div class="modal-body">${bodyHtml}</div>
                ${footerHtml ? `<div class="modal-footer">${footerHtml}</div>` : ''}
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

    /**
     * modal(title, bodyHtml, onConfirm, opts)
     * onConfirm: async callback (null이면 확인 버튼 없음, return false면 닫지 않음)
     * opts: { wide: true } 등
     */
    function modal(title, bodyHtml, onConfirm, opts = {}) {
        closeModal();
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.id = 'app-modal';
        const wideClass = opts.wide ? ' modal--wide' : '';
        const footer = onConfirm ? `
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" id="modal-cancel">닫기</button>
                <button class="btn btn-primary btn-sm" id="modal-ok">확인</button>
            </div>` : '';
        overlay.innerHTML = `
            <div class="modal${wideClass}">
                <div class="modal-header">
                    <h3 class="modal-title">${esc(title)}</h3>
                    <button class="modal-close" onclick="App.closeModal()">&times;</button>
                </div>
                <div class="modal-body">${bodyHtml}</div>
                ${footer}
            </div>
        `;
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal();
        });
        document.body.appendChild(overlay);
        if (onConfirm) {
            document.getElementById('modal-cancel').onclick = () => closeModal();
            document.getElementById('modal-ok').onclick = async () => {
                const result = await onConfirm();
                if (result !== false) closeModal();
            };
        }
    }

    function toast(msg, type = 'success') {
        if (typeof Toast !== 'undefined') {
            type === 'error' ? Toast.error(msg) : Toast.success(msg);
        }
    }

    // ── Confirm ──
    function confirm(message) {
        return new Promise(resolve => {
            const prev = document.getElementById('app-confirm');
            if (prev) prev.remove();
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.id = 'app-confirm';
            overlay.style.zIndex = '10001';
            overlay.innerHTML = `
                <div class="modal modal--confirm">
                    <div class="modal-body" style="padding:4px 0 12px">
                        <p style="font-size:14px;line-height:1.6;white-space:pre-line">${esc(message)}</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary btn-sm" id="confirm-no">취소</button>
                        <button class="btn btn-primary btn-sm" id="confirm-yes">확인</button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
            const cleanup = () => { const el = document.getElementById('app-confirm'); if (el) el.remove(); };
            document.getElementById('confirm-yes').onclick = () => { cleanup(); resolve(true); };
            document.getElementById('confirm-no').onclick = () => { cleanup(); resolve(false); };
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

        // fade 힌트 + 화살표 버튼용 wrapper 생성
        const tabWrap = container.querySelector('.tab-wrap');
        let outer = null;
        if (tabWrap) {
            outer = document.createElement('div');
            outer.className = 'tab-wrap-outer';
            tabWrap.parentNode.insertBefore(outer, tabWrap);
            outer.appendChild(tabWrap);

            // 좌우 화살표 버튼
            const arrowLeft = document.createElement('button');
            arrowLeft.className = 'tab-arrow tab-arrow-left';
            arrowLeft.textContent = '\u25C0';
            arrowLeft.onclick = () => tabWrap.scrollBy({ left: -200, behavior: 'smooth' });

            const arrowRight = document.createElement('button');
            arrowRight.className = 'tab-arrow tab-arrow-right';
            arrowRight.textContent = '\u25B6';
            arrowRight.onclick = () => tabWrap.scrollBy({ left: 200, behavior: 'smooth' });

            outer.insertBefore(arrowLeft, tabWrap);
            outer.appendChild(arrowRight);

            function updateFade() {
                const sl = tabWrap.scrollLeft;
                const maxScroll = tabWrap.scrollWidth - tabWrap.clientWidth;
                outer.classList.toggle('fade-left', sl > 4);
                outer.classList.toggle('fade-right', sl < maxScroll - 4);
            }
            tabWrap.addEventListener('scroll', updateFade, { passive: true });
            // 초기 + resize 시 업데이트
            requestAnimationFrame(updateFade);
            window.addEventListener('resize', updateFade);
        }

        // ── Side Menu (햄버거) ── 탭 5개 이상이면 자동 생성
        let sideOverlay = null, sidePanel = null;
        const sideMenuItems = [];
        if (btns.length >= 5) {
            const header = document.querySelector('.admin-header');
            if (header) {
                const headerLeft = header.querySelector('.admin-header-left');
                header.classList.add('has-side-menu');

                // 햄버거 버튼
                const hamburger = document.createElement('button');
                hamburger.className = 'side-menu-toggle';
                hamburger.innerHTML = '&#9776;';
                hamburger.setAttribute('aria-label', '메뉴');
                headerLeft.insertBefore(hamburger, headerLeft.firstChild);

                // 오버레이
                sideOverlay = document.createElement('div');
                sideOverlay.className = 'side-menu-overlay';
                document.body.appendChild(sideOverlay);

                // 패널
                sidePanel = document.createElement('div');
                sidePanel.className = 'side-menu-panel';

                const panelHeader = document.createElement('div');
                panelHeader.className = 'side-menu-header';
                panelHeader.textContent = '메뉴';
                sidePanel.appendChild(panelHeader);

                const panelList = document.createElement('div');
                panelList.className = 'side-menu-list';

                btns.forEach(btn => {
                    const item = document.createElement('button');
                    item.className = 'side-menu-item';
                    if (btn.classList.contains('active')) item.classList.add('active');
                    item.textContent = btn.textContent;
                    item.addEventListener('click', () => {
                        btn.click();
                        closeSideMenu();
                    });
                    panelList.appendChild(item);
                    sideMenuItems.push({ item, btn });
                });

                sidePanel.appendChild(panelList);
                document.body.appendChild(sidePanel);

                function openSideMenu() {
                    sideOverlay.classList.add('open');
                    sidePanel.classList.add('open');
                }
                function closeSideMenu() {
                    sideOverlay.classList.remove('open');
                    sidePanel.classList.remove('open');
                }

                hamburger.addEventListener('click', openSideMenu);
                sideOverlay.addEventListener('click', closeSideMenu);
            }
        }

        function scrollToTab(btn) {
            if (tabWrap) {
                btn.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }
        }

        function activateTab(btn) {
            btns.forEach(b => b.classList.remove('active'));
            contents.forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            const target = container.querySelector(btn.dataset.tab);
            if (target) target.classList.add('active');
            scrollToTab(btn);
            // 사이드 메뉴 active 상태 동기화
            sideMenuItems.forEach(({ item, btn: b }) => {
                item.classList.toggle('active', b === btn);
            });
        }

        btns.forEach(btn => {
            btn.addEventListener('click', () => {
                activateTab(btn);
                if (btn.dataset.hash) {
                    history.replaceState(null, '', '#' + btn.dataset.hash);
                }
            });
        });

        // hash 기반 탭 활성화 (외부에서 호출 가능하도록 반환)
        function activateFromHash() {
            const hash = location.hash.slice(1);
            if (hash) {
                const match = container.querySelector('.tab[data-hash="' + hash + '"]');
                if (match) { activateTab(match); return true; }
            }
            return false;
        }

        return { activateFromHash };
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
        openModal, closeModal, modal, confirm, toast,
        formatDate, formatDateKo, today,
        initTabs, esc, debounce,
    };
})();

// ── DEV Server Badge ──
if (location.hostname.startsWith('dev-')) {
    document.addEventListener('DOMContentLoaded', () => {
        const badge = document.createElement('div');
        badge.id = 'dev-badge';
        badge.textContent = 'DEV';
        badge.style.cssText = 'position:fixed;top:0;left:50%;transform:translateX(-50%);z-index:99999;background:#ef4444;color:#fff;font-size:11px;font-weight:700;padding:2px 12px;border-radius:0 0 6px 6px;letter-spacing:1px;pointer-events:none;opacity:0.9;';
        document.body.appendChild(badge);
    });
}
