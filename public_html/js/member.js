/* ══════════════════════════════════════════════════════════════
   MemberApp — 사용자 페이지 진입점
   역할: 세션 체크, 로그인/로그아웃, 대시보드 레이아웃 + 탭 초기화
   개별 탭 로직은 member-home.js, member-calendar.js 등에서 처리
   ══════════════════════════════════════════════════════════════ */
const MemberApp = (() => {
    let root = null;
    let member = null;

    // ── Init ──
    async function init() {
        root = document.getElementById('member-root');

        App.showLoading();
        const r = await App.get('/api/member.php?action=check_session');
        App.hideLoading();

        if (r.logged_in) {
            member = r.member;
            showDashboard();
        } else {
            showLoginForm();
        }
    }

    // ══════════════════════════════════════════════════════════
    // Login
    // ══════════════════════════════════════════════════════════

    function showLoginForm() {
        root.innerHTML = `
            <div class="member-login">
                <div class="login-box">
                    <div class="login-title">소리튠 부트캠프</div>
                    <p class="login-subtitle">회원 로그인</p>
                    <form id="login-form">
                        <div class="form-group">
                            <label class="form-label">이름</label>
                            <input type="text" class="form-input" id="login-name" placeholder="홍길동" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">전화번호 뒤 4자리</label>
                            <input type="tel" class="form-input" id="login-phone" placeholder="5678" maxlength="4" pattern="[0-9]{4}" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block btn-lg mt-md">로그인</button>
                    </form>
                </div>
            </div>
        `;

        document.getElementById('login-form').onsubmit = async (e) => {
            e.preventDefault();
            const name = document.getElementById('login-name').value.trim();
            const phoneLast4 = document.getElementById('login-phone').value.trim();
            if (!name || !phoneLast4) return;

            App.showLoading();
            const r = await App.post('/api/member.php?action=login', { name, phone_last4: phoneLast4 });
            App.hideLoading();

            if (r.success) {
                member = r.member;
                Toast.success(r.message);
                showDashboard();
            }
        };
    }

    // ══════════════════════════════════════════════════════════
    // Dashboard — 레이아웃만 담당, 콘텐츠는 각 모듈에 위임
    // ══════════════════════════════════════════════════════════

    function showDashboard() {
        root.innerHTML = `
            <div class="member-dashboard">
                <div class="member-header">
                    <div class="header-title">소리튠 부트캠프</div>
                    <div class="member-cohort">${App.esc(member.cohort)}</div>
                </div>
                <div class="member-content">
                    <div id="member-home-area"></div>
                    <div id="member-tabs-area"></div>
                    <div class="member-logout-wrap">
                        <button class="btn btn-secondary" id="btn-member-logout">로그아웃</button>
                    </div>
                </div>
            </div>
        `;

        // 로그아웃
        document.getElementById('btn-member-logout').onclick = async () => {
            await App.post('/api/member.php?action=logout');
            Toast.info('로그아웃 되었습니다.');
            member = null;
            showLoginForm();
        };

        // 프로필 + 커리큘럼 렌더링
        MemberHome.render(document.getElementById('member-home-area'), member);

        // 탭 초기화
        MemberTabs.init(document.getElementById('member-tabs-area'), member);
    }

    return { init };
})();
