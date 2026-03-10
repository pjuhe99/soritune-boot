/* ── Member Dashboard ─────────────────────────────────────── */
const MemberApp = (() => {
    let root = null;
    let member = null;

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

    function showDashboard() {
        root.innerHTML = `
            <div class="member-dashboard">
                <div class="member-header">
                    <div class="header-title">소리튠 부트캠프</div>
                    <div class="member-cohort">${App.esc(member.cohort)}</div>
                </div>
                <div class="member-content">
                    <div class="member-info-card">
                        <div class="member-nickname">${App.esc(member.nickname)}</div>
                        <div class="member-realname">${App.esc(member.member_name)}</div>
                        <div class="member-point">${member.score ?? 0}</div>
                        <div class="member-point-label">점수</div>
                    </div>
                    <div class="member-placeholder">
                        <p>추가 기능이 곧 업데이트됩니다.</p>
                    </div>
                    <div class="member-logout-wrap">
                        <button class="btn btn-secondary" id="btn-member-logout">로그아웃</button>
                    </div>
                </div>
            </div>
        `;

        document.getElementById('btn-member-logout').onclick = async () => {
            await App.post('/api/member.php?action=logout');
            Toast.info('로그아웃 되었습니다.');
            showLoginForm();
        };
    }

    return { init };
})();
