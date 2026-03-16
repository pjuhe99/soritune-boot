/* ══════════════════════════════════════════════════════════════
   MemberApp — 사용자 페이지 진입점
   역할: 세션 체크, 로그인/로그아웃, 닉네임 온보딩, 대시보드 레이아웃 + 탭 초기화
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
            if (member.needs_nickname) {
                showNicknameSetup();
            } else {
                showDashboard();
            }
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
                            <label class="form-label">휴대폰번호</label>
                            <input type="tel" class="form-input" id="login-phone"
                                   placeholder="01012345678" maxlength="11"
                                   inputmode="numeric" pattern="[0-9]*" required>
                            <p class="form-hint">하이픈(-) 없이 숫자만 입력해주세요</p>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block btn-lg mt-md">로그인</button>
                    </form>
                </div>
            </div>
        `;

        document.getElementById('login-form').onsubmit = async (e) => {
            e.preventDefault();
            const phoneRaw = document.getElementById('login-phone').value.trim();
            const phone = phoneRaw.replace(/[^0-9]/g, '');

            if (!phone) return;
            if (phone.length < 10 || phone.length > 11) {
                Toast.error('올바른 휴대폰번호를 입력해주세요.');
                return;
            }

            App.showLoading();
            const r = await App.post('/api/member.php?action=login', { phone });
            App.hideLoading();

            if (r.success) {
                member = r.member;
                Toast.success(r.message);
                if (member.needs_nickname) {
                    showNicknameSetup();
                } else {
                    showDashboard();
                }
            }
        };
    }

    // ══════════════════════════════════════════════════════════
    // Nickname Setup (온보딩)
    // ══════════════════════════════════════════════════════════

    function showNicknameSetup() {
        root.innerHTML = `
            <div class="member-login">
                <div class="login-box">
                    <div class="login-title">소리튠 부트캠프</div>
                    <p class="login-subtitle">닉네임 설정</p>
                    <p class="login-desc">부트캠프에서 사용할 닉네임을 입력해주세요.</p>
                    <form id="nickname-form">
                        <div class="form-group">
                            <label class="form-label">닉네임</label>
                            <input type="text" class="form-input" id="nickname-input"
                                   placeholder="닉네임 입력 (1~20자)" maxlength="20" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block btn-lg mt-md">확인</button>
                    </form>
                </div>
            </div>
        `;

        document.getElementById('nickname-form').onsubmit = async (e) => {
            e.preventDefault();
            const nickname = document.getElementById('nickname-input').value.trim();

            if (!nickname) {
                Toast.error('닉네임을 입력해주세요.');
                return;
            }
            if (nickname.length > 20) {
                Toast.error('닉네임은 20자 이내로 입력해주세요.');
                return;
            }

            // 확인 다이얼로그
            const confirmed = await confirmNickname(nickname);
            if (!confirmed) return;

            App.showLoading();
            const r = await App.post('/api/member.php?action=save_nickname', { nickname });
            App.hideLoading();

            if (r.success) {
                member.nickname = r.nickname;
                member.needs_nickname = false;
                Toast.success(r.message);
                showDashboard();
            }
        };
    }

    function confirmNickname(nickname) {
        return new Promise((resolve) => {
            App.openModal('닉네임 확인', `
                <div class="nickname-confirm">
                    <p class="nickname-confirm-msg"><strong>${App.esc(nickname)}</strong>이 맞나요?</p>
                    <div class="nickname-confirm-actions">
                        <button class="btn btn-secondary" id="nickname-cancel">다시 입력</button>
                        <button class="btn btn-primary" id="nickname-ok">맞아요</button>
                    </div>
                </div>
            `);
            document.getElementById('nickname-cancel').onclick = () => { App.closeModal(); resolve(false); };
            document.getElementById('nickname-ok').onclick = () => { App.closeModal(); resolve(true); };
        });
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
