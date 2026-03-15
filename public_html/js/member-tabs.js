/* ══════════════════════════════════════════════════════════════
   MemberTabs — 사용자 페이지 탭 관리 + 해시 라우팅
   App.initTabs() 기반, pushState + popstate 지원
   ══════════════════════════════════════════════════════════════ */
const MemberTabs = (() => {
    const DEFAULT_TAB = 'calendar';

    const TABS = [
        { id: 'calendar',    label: '캘린더',     icon: '📅' },
        { id: 'assignments', label: '과제 이력',  icon: '✅' },
        { id: 'curriculum',  label: '진도 달력',  icon: '📖' },
        { id: 'members',     label: '다른 부티즈', icon: '👥' },
    ];

    // 탭별 핸들러 등록소 — 각 탭 모듈이 register()로 등록
    const handlers = {};
    let activeTab = null;
    let container = null;

    /**
     * 탭 모듈 등록
     * @param {string} tabId - TABS의 id와 일치
     * @param {object} handler - { mount(el, member), unmount?() }
     *   mount: 탭 활성화 시 호출 (el = 콘텐츠 영역, member = 현재 로그인 멤버)
     *   unmount: 탭 비활성화 시 호출 (선택)
     */
    function register(tabId, handler) {
        handlers[tabId] = handler;
    }

    /**
     * 탭 UI 렌더링 + 이벤트 바인딩
     * @param {HTMLElement} parentEl - 탭을 넣을 부모 요소
     * @param {object} member - 로그인된 멤버 정보
     */
    function init(parentEl, member) {
        container = parentEl;

        // 탭 바 + 콘텐츠 영역 HTML 생성
        const tabBtns = TABS.map(t =>
            `<button class="tab" data-tab="#mtab-${t.id}" data-hash="${t.id}">${t.label}</button>`
        ).join('');

        const tabPanels = TABS.map(t =>
            `<div class="tab-content" id="mtab-${t.id}"></div>`
        ).join('');

        container.innerHTML = `
            <div class="member-tab-bar">
                <div class="tab-wrap">${tabBtns}</div>
            </div>
            <div class="member-tab-panels">${tabPanels}</div>
        `;

        // App.initTabs 활용 (fade 힌트, 스크롤 등)
        const tabResult = App.initTabs(container);

        // 해시 → 탭 활성화 (pushState 대응)
        overrideToPushState(container);

        // popstate로 뒤로가기/앞으로가기 대응
        window.addEventListener('popstate', () => {
            activateFromHash(member);
        });

        // 초기 탭 활성화
        activateFromHash(member);
    }

    /**
     * App.initTabs가 replaceState를 쓰므로,
     * 탭 클릭 시 pushState로 변경 + 핸들러 호출
     */
    function overrideToPushState(container) {
        container.querySelectorAll('.tab').forEach(btn => {
            // 기존 click 리스너 위에 추가 (App.initTabs가 이미 DOM 전환 처리)
            btn.addEventListener('click', () => {
                const hash = btn.dataset.hash;
                if (hash && location.hash !== '#' + hash) {
                    history.pushState(null, '', '#' + hash);
                }
                // 핸들러 호출
                switchTo(hash, getMemberFromClosure());
            });
        });
    }

    // member를 클로저로 유지
    let _member = null;
    function getMemberFromClosure() { return _member; }

    /**
     * 해시에서 탭 ID 추출 → 활성화
     */
    function activateFromHash(member) {
        if (member) _member = member;
        const hash = location.hash.slice(1) || DEFAULT_TAB;
        const validTab = TABS.find(t => t.id === hash);
        const tabId = validTab ? hash : DEFAULT_TAB;

        // URL 해시 보정
        if (!validTab && !location.hash) {
            history.replaceState(null, '', '#' + DEFAULT_TAB);
        }

        // DOM 탭 버튼 활성화
        const btn = container.querySelector(`.tab[data-hash="${tabId}"]`);
        if (btn) {
            container.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
            container.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            btn.classList.add('active');
            const panel = container.querySelector(btn.dataset.tab);
            if (panel) panel.classList.add('active');
        }

        switchTo(tabId, _member);
    }

    /**
     * 탭 전환 실행 — 이전 탭 unmount, 새 탭 mount
     */
    function switchTo(tabId, member) {
        if (tabId === activeTab) return;

        // 이전 탭 unmount
        if (activeTab && handlers[activeTab] && handlers[activeTab].unmount) {
            handlers[activeTab].unmount();
        }

        activeTab = tabId;
        const panel = container.querySelector(`#mtab-${tabId}`);

        if (handlers[tabId]) {
            handlers[tabId].mount(panel, member);
        } else {
            // 핸들러 미등록 시 placeholder
            panel.innerHTML = `
                <div class="member-tab-placeholder">
                    <p>${getTabLabel(tabId)} 준비 중입니다.</p>
                </div>
            `;
        }
    }

    function getTabLabel(tabId) {
        const t = TABS.find(tab => tab.id === tabId);
        return t ? t.label : tabId;
    }

    /** 현재 활성 탭 ID */
    function getActiveTab() {
        return activeTab;
    }

    /** 외부에서 특정 탭으로 전환 */
    function goTo(tabId) {
        history.pushState(null, '', '#' + tabId);
        activateFromHash(_member);
    }

    return { register, init, getActiveTab, goTo };
})();
