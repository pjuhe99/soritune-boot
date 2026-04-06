<?php
/**
 * QR 스캔 랜딩 페이지
 * 학생이 QR을 스캔하면 이 페이지에서 조 선택 → 닉네임 선택 → 출석/패자부활 처리
 */
require_once __DIR__ . '/../config.php';

$code = trim($_GET['code'] ?? '');
if (!$code) {
    header('Location: /');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>소리튠 부트캠프</title>
    <meta name="theme-color" content="#2563EB">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Pretendard Variable', Pretendard, -apple-system, sans-serif;
            min-height: 100vh;
        }
        body.mode-attendance, body.mode-revival { background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%); }
        .scan-app { max-width: 480px; margin: 0 auto; padding: 20px 16px; min-height: 100vh; }

        /* Header */
        .scan-header { text-align: center; color: #fff; padding: 20px 0 24px; }
        .scan-logo { font-size: 44px; margin-bottom: 4px; }
        .scan-title { font-size: 22px; font-weight: 800; letter-spacing: -0.5px; }

        /* Card */
        .scan-card {
            background: #fff; border-radius: 24px; padding: 28px 24px;
            box-shadow: 0 12px 40px rgba(0,0,0,.15);
        }

        /* States */
        .scan-state { display: none; }
        .scan-state.active { display: block; animation: fadeIn .3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

        .scan-section-title { font-size: 18px; font-weight: 800; color: #333; text-align: center; margin-bottom: 4px; }
        .scan-section-desc { font-size: 13px; color: #9E9E9E; text-align: center; margin-bottom: 20px; }

        /* Group grid */
        .scan-group-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .scan-group-item {
            display: flex; flex-direction: column; align-items: center; gap: 6px;
            padding: 16px 8px; border: 2px solid #F0F0F0; border-radius: 14px;
            cursor: pointer; transition: all .2s; background: #fff;
            -webkit-tap-highlight-color: transparent;
        }
        .scan-group-item:active { transform: scale(.96); }
        .scan-group-item .group-icon {
            width: 40px; height: 40px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: 16px;
        }
        .scan-group-item .group-icon { background: linear-gradient(135deg, #2563EB, #1D4ED8); }
        .scan-group-name { font-size: 13px; font-weight: 600; color: #555; text-align: center; line-height: 1.2; }

        /* Member grid */
        .scan-member-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .scan-member-btn {
            padding: 14px 4px; border: 1.5px solid #F0F0F0; border-radius: 12px;
            background: #fff; cursor: pointer; transition: all .15s; text-align: center;
            -webkit-tap-highlight-color: transparent;
        }
        .scan-member-btn:active { transform: scale(.96); }
        .scan-member-btn:active { background: #EFF6FF; border-color: #2563EB; }
        .scan-member-name { font-size: 14px; font-weight: 700; color: #333; }

        /* Back button */
        .scan-btn-back {
            display: inline-flex; align-items: center; gap: 4px;
            background: none; border: none; color: #9E9E9E; font-size: 13px;
            cursor: pointer; margin-top: 14px; font-family: inherit; padding: 6px 0;
        }

        /* Primary button */
        .scan-btn {
            display: inline-block; padding: 14px 40px;
            color: #fff; border: none; border-radius: 14px; font-size: 16px; font-weight: 700;
            cursor: pointer; font-family: inherit; text-decoration: none; text-align: center;
        }
        .scan-btn { background: linear-gradient(135deg, #2563EB, #1D4ED8); }
        .scan-btn:active { transform: scale(.98); }

        /* Confirm overlay */
        .scan-confirm-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45);
            z-index: 100; align-items: center; justify-content: center; padding: 20px;
        }
        .scan-confirm-overlay.active { display: flex; }
        .scan-confirm-box {
            background: #fff; border-radius: 20px; padding: 28px 24px; max-width: 320px;
            width: 100%; text-align: center; box-shadow: 0 12px 40px rgba(0,0,0,.2);
        }
        .scan-confirm-name { font-size: 20px; font-weight: 800; color: #333; margin-bottom: 6px; }
        .scan-confirm-desc { font-size: 14px; color: #999; margin-bottom: 22px; }
        .scan-confirm-btns { display: flex; gap: 10px; }
        .scan-confirm-btns button {
            flex: 1; padding: 13px; border: none; border-radius: 12px;
            font-size: 15px; font-weight: 700; cursor: pointer; font-family: inherit;
        }
        .scan-confirm-no { background: #F0F0F0; color: #777; }
        .scan-confirm-yes { background: linear-gradient(135deg, #2563EB, #1D4ED8); color: #fff; }

        /* Success / Error */
        .scan-result { text-align: center; padding: 16px 0; }
        .scan-result-icon { font-size: 64px; margin-bottom: 12px; }
        .scan-result-title { font-size: 22px; font-weight: 800; color: #333; margin-bottom: 6px; }
        .scan-result-desc { color: #777; margin-bottom: 28px; font-size: 14px; }
        .scan-result-score { font-size: 16px; font-weight: 700; margin-bottom: 20px; }
        .scan-result-score .before { color: #DC2626; text-decoration: line-through; }
        .scan-result-score .arrow { color: #999; margin: 0 6px; }
        .scan-result-score .after { color: #16A34A; }

        /* Loading */
        .scan-loading { text-align: center; padding: 40px 0; }
        .scan-loading-spinner {
            width: 36px; height: 36px; border: 3px solid #f0f0f0;
            border-radius: 50%; animation: spin .8s linear infinite; margin: 0 auto 14px;
        }
        .scan-loading-spinner { border-top-color: #2563EB; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .scan-loading-text { color: #999; font-size: 14px; }
    </style>
</head>
<body class="mode-attendance">
    <div class="scan-app">
        <div class="scan-header">
            <div class="scan-logo" id="header-logo">&#x1F4F8;</div>
            <div class="scan-title" id="header-title">출석체크</div>
        </div>

        <div class="scan-card">
            <!-- Loading -->
            <div class="scan-state active" id="st-loading">
                <div class="scan-loading">
                    <div class="scan-loading-spinner"></div>
                    <div class="scan-loading-text">확인 중...</div>
                </div>
            </div>

            <!-- Error -->
            <div class="scan-state" id="st-error">
                <div class="scan-result">
                    <div class="scan-result-icon" id="error-icon">&#x26A0;&#xFE0F;</div>
                    <div class="scan-result-title" id="error-title">오류</div>
                    <div class="scan-result-desc" id="error-desc"></div>
                </div>
            </div>

            <!-- Group Selection -->
            <div class="scan-state" id="st-groups">
                <div class="scan-section-title" id="groups-title">조를 선택하세요</div>
                <div class="scan-section-desc">본인이 속한 조를 눌러주세요</div>
                <div class="scan-group-grid" id="group-grid"></div>
            </div>

            <!-- Member Selection -->
            <div class="scan-state" id="st-members">
                <div class="scan-section-title" id="members-group-title">이름을 선택하세요</div>
                <div class="scan-section-desc" id="members-desc">본인 닉네임을 눌러주세요</div>
                <div id="member-list"></div>
                <div style="text-align:center;">
                    <button class="scan-btn-back" id="btn-back-group">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                        다른 조 선택
                    </button>
                </div>
            </div>

            <!-- Success -->
            <div class="scan-state" id="st-success">
                <div class="scan-result">
                    <div class="scan-result-icon" id="success-icon">&#x2705;</div>
                    <div class="scan-result-title" id="success-name">완료!</div>
                    <div class="scan-result-score" id="success-score" style="display:none"></div>
                    <div class="scan-result-desc" id="success-desc"></div>
                </div>
            </div>

            <!-- Confirm overlay -->
            <div class="scan-confirm-overlay" id="confirm-overlay">
                <div class="scan-confirm-box">
                    <div class="scan-confirm-name" id="confirm-name"></div>
                    <div class="scan-confirm-desc" id="confirm-desc"></div>
                    <div class="scan-confirm-btns">
                        <button class="scan-confirm-no" id="confirm-no">아니오</button>
                        <button class="scan-confirm-yes" id="confirm-yes">네</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    (async function() {
        const SESSION_CODE = '<?= htmlspecialchars($code, ENT_QUOTES, "UTF-8") ?>';
        const API = '/api/qr.php?action=';
        let SESSION_TYPE = 'attendance'; // will be set after verify

        function showState(id) {
            document.querySelectorAll('.scan-state').forEach(s => s.classList.remove('active'));
            const el = document.getElementById('st-' + id);
            if (el) el.classList.add('active');
        }

        function showError(icon, title, desc) {
            document.getElementById('error-icon').textContent = icon;
            document.getElementById('error-title').textContent = title;
            document.getElementById('error-desc').textContent = desc;
            showState('error');
        }

        async function api(url, options = {}) {
            const resp = await fetch(url, {
                method: options.method || 'GET',
                headers: options.body ? { 'Content-Type': 'application/json' } : {},
                body: options.body ? JSON.stringify(options.body) : undefined,
            });
            return resp.json();
        }

        function esc(str) {
            if (str == null) return '';
            const d = document.createElement('div');
            d.textContent = String(str);
            return d.innerHTML;
        }

        function applyMode(type) {
            SESSION_TYPE = type;
            document.body.className = type === 'revival' ? 'mode-revival' : 'mode-attendance';
            if (type === 'revival') {
                document.getElementById('header-logo').innerHTML = '&#x1F3C6;';
                document.getElementById('header-title').textContent = '\uD328\uC790\uBD80\uD65C\uC804';
                document.title = '\uD328\uC790\uBD80\uD65C\uC804 - \uC18C\uB9AC\uD280 \uBD80\uD2B8\uCEA0\uD504';
                document.querySelector('meta[name="theme-color"]').content = '#2563EB';
            }
        }

        // 1. 세션 검증
        try {
            const verify = await api(API + 'verify&code=' + encodeURIComponent(SESSION_CODE));
            if (!verify.valid) {
                if (verify.reason === 'expired') {
                    showError('\u23F0', '\uC138\uC158\uC774 \uB9CC\uB8CC\uB418\uC5C8\uC2B5\uB2C8\uB2E4', '\uCF54\uCE58\uC5D0\uAC8C \uC0C8 QR\uCF54\uB4DC\uB97C \uC694\uCCAD\uD574 \uC8FC\uC138\uC694');
                } else if (verify.reason === 'closed') {
                    showError('\uD83D\uDD12', '\uC138\uC158\uC774 \uC885\uB8CC\uB418\uC5C8\uC2B5\uB2C8\uB2E4', '\uC138\uC158\uC774 \uC885\uB8CC\uB418\uC5C8\uC2B5\uB2C8\uB2E4');
                } else {
                    showError('\uD83D\uDD0D', '\uC138\uC158\uC744 \uCC3E\uC744 \uC218 \uC5C6\uC2B5\uB2C8\uB2E4', 'QR \uCF54\uB4DC\uAC00 \uC62C\uBC14\uB974\uC9C0 \uC54A\uC2B5\uB2C8\uB2E4');
                }
                return;
            }

            // 세션 타입 적용
            applyMode(verify.session_type || 'attendance');

            // 2. 조 목록 로드
            const groupsRes = await api(API + 'groups&code=' + encodeURIComponent(SESSION_CODE));
            if (!groupsRes.success || !groupsRes.groups.length) {
                showError('\uD83D\uDE05', '\uC870\uAC00 \uC5C6\uC2B5\uB2C8\uB2E4', '\uB4F1\uB85D\uB41C \uC870\uAC00 \uC5C6\uC2B5\uB2C8\uB2E4');
                return;
            }

            showGroupGrid(groupsRes.groups);
        } catch (e) {
            showError('\u274C', '\uB124\uD2B8\uC6CC\uD06C \uC624\uB958', '\uC778\uD130\uB137 \uC5F0\uACB0\uC744 \uD655\uC778\uD574 \uC8FC\uC138\uC694');
        }

        // ── 조 선택 그리드 ──
        function showGroupGrid(groups) {
            const grid = document.getElementById('group-grid');
            grid.innerHTML = groups.map(g => `
                <div class="scan-group-item" data-group-id="${g.id}" data-group-name="${esc(g.name)}">
                    <div class="group-icon">${esc(g.name.charAt(0))}</div>
                    <div class="scan-group-name">${esc(g.name)}</div>
                </div>
            `).join('');

            grid.querySelectorAll('.scan-group-item').forEach(item => {
                item.addEventListener('click', async () => {
                    const groupId = item.dataset.groupId;
                    const groupName = item.dataset.groupName;
                    document.getElementById('members-group-title').textContent = groupName;
                    showState('loading');
                    try {
                        const r = await api(API + 'group_members&code=' + encodeURIComponent(SESSION_CODE) + '&group_id=' + groupId);
                        if (r.success && r.members && r.members.length > 0) {
                            showMemberList(r.members);
                        } else {
                            showError('\uD83D\uDE05', '\uD68C\uC6D0 \uC5C6\uC74C', '\uC774 \uC870\uC5D0 \uB4F1\uB85D\uB41C \uD68C\uC6D0\uC774 \uC5C6\uC2B5\uB2C8\uB2E4');
                        }
                    } catch (e) {
                        showError('\u274C', '\uB124\uD2B8\uC6CC\uD06C \uC624\uB958', '\uC778\uD130\uB137 \uC5F0\uACB0\uC744 \uD655\uC778\uD574 \uC8FC\uC138\uC694');
                    }
                });
            });

            showState('groups');
        }

        // ── 멤버 목록 ──
        function showMemberList(members) {
            const list = document.getElementById('member-list');
            list.innerHTML = '<div class="scan-member-grid">' + members.map(m =>
                `<div class="scan-member-btn" data-member-id="${m.id}" data-member-name="${esc(m.nickname)}">
                    <div class="scan-member-name">${esc(m.nickname)}</div>
                </div>`
            ).join('') + '</div>';

            list.querySelectorAll('.scan-member-btn').forEach(item => {
                item.addEventListener('click', () => {
                    showConfirm(item.dataset.memberName, parseInt(item.dataset.memberId));
                });
            });

            showState('members');
        }

        // ── 확인 팝업 ──
        function hasJongseong(ch) {
            const code = ch.charCodeAt(0);
            if (code < 0xAC00 || code > 0xD7A3) return false;
            return (code - 0xAC00) % 28 !== 0;
        }

        function showConfirm(name, memberId) {
            document.getElementById('confirm-name').textContent = name;
            const particle = hasJongseong(name[name.length - 1]) ? '\uC73C\uB85C' : '\uB85C';
            if (SESSION_TYPE === 'revival') {
                document.getElementById('confirm-desc').textContent = particle + ' \uD328\uC790\uBD80\uD65C \uCC98\uB9AC\uD560\uAE4C\uC694?';
            } else {
                document.getElementById('confirm-desc').textContent = particle + ' \uCD9C\uC11D\uD560\uAE4C\uC694?';
            }
            const overlay = document.getElementById('confirm-overlay');
            overlay.classList.add('active');

            const yesBtn = document.getElementById('confirm-yes');
            const noBtn = document.getElementById('confirm-no');
            const newYes = yesBtn.cloneNode(true);
            const newNo = noBtn.cloneNode(true);
            yesBtn.replaceWith(newYes);
            noBtn.replaceWith(newNo);

            newNo.addEventListener('click', () => overlay.classList.remove('active'));
            newYes.addEventListener('click', async () => {
                overlay.classList.remove('active');
                if (SESSION_TYPE === 'revival') {
                    await recordRevival(memberId);
                } else {
                    await recordAttendance(memberId);
                }
            });
        }

        // ── 출석 기록 ──
        async function recordAttendance(memberId) {
            showState('loading');
            try {
                const result = await api(API + 'record', {
                    method: 'POST',
                    body: { session_code: SESSION_CODE, member_id: memberId }
                });
                if (result.success) {
                    const name = result.member_name || '';
                    document.getElementById('success-icon').innerHTML = '&#x2705;';
                    document.getElementById('success-name').textContent = name + '\uB2D8 \uCD9C\uC11D \uC644\uB8CC!';
                    document.getElementById('success-score').style.display = 'none';
                    document.getElementById('success-desc').textContent =
                        result.already ? '\uC774\uBBF8 \uCD9C\uC11D\uC774 \uAE30\uB85D\uB418\uC5B4 \uC788\uC2B5\uB2C8\uB2E4' : '\uCD9C\uC11D\uC774 \uC131\uACF5\uC801\uC73C\uB85C \uAE30\uB85D\uB418\uC5C8\uC2B5\uB2C8\uB2E4';
                    showState('success');
                    if (navigator.vibrate) navigator.vibrate(200);
                } else {
                    showError('\u26A0\uFE0F', '\uCD9C\uC11D \uC624\uB958', result.error || '\uCD9C\uC11D \uCC98\uB9AC\uC5D0 \uC2E4\uD328\uD588\uC2B5\uB2C8\uB2E4');
                }
            } catch (e) {
                showError('\u274C', '\uB124\uD2B8\uC6CC\uD06C \uC624\uB958', '\uC778\uD130\uB137 \uC5F0\uACB0\uC744 \uD655\uC778\uD574 \uC8FC\uC138\uC694');
            }
        }

        // ── 패자부활 처리 ──
        async function recordRevival(memberId) {
            showState('loading');
            try {
                const result = await api(API + 'revival_record', {
                    method: 'POST',
                    body: { session_code: SESSION_CODE, member_id: memberId }
                });
                if (result.success) {
                    const name = result.member_name || '';
                    if (result.not_eligible) {
                        // 대상이 아닌 경우
                        showError('\u26D4', '\uD328\uC790\uBD80\uD65C\uC804 \uB300\uC0C1\uC774 \uC544\uB2D9\uB2C8\uB2E4', '\uD604\uC7AC \uC810\uC218: ' + result.current_score + '\uC810 (-10\uC810 \uC774\uD558\uB9CC \uB300\uC0C1)');
                    } else {
                        // 부활 성공
                        document.getElementById('success-icon').innerHTML = '&#x1F3C6;';
                        document.getElementById('success-name').textContent = name + '\uB2D8 \uBD80\uD65C \uC644\uB8CC!';
                        const scoreEl = document.getElementById('success-score');
                        scoreEl.style.display = 'block';
                        scoreEl.innerHTML = '<span class="before">' + result.before_score + '\uC810</span>' +
                            '<span class="arrow">\u2192</span>' +
                            '<span class="after">' + result.after_score + '\uC810</span>' +
                            ' <span style="color:#16A34A">(+' + result.bonus + ')</span>';
                        document.getElementById('success-desc').textContent = result.message || '\uD328\uC790\uBD80\uD65C \uCC98\uB9AC\uAC00 \uC644\uB8CC\uB418\uC5C8\uC2B5\uB2C8\uB2E4';
                        showState('success');
                        if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
                    }
                } else {
                    showError('\u26A0\uFE0F', '\uCC98\uB9AC \uC624\uB958', result.error || '\uD328\uC790\uBD80\uD65C \uCC98\uB9AC\uC5D0 \uC2E4\uD328\uD588\uC2B5\uB2C8\uB2E4');
                }
            } catch (e) {
                showError('\u274C', '\uB124\uD2B8\uC6CC\uD06C \uC624\uB958', '\uC778\uD130\uB137 \uC5F0\uACB0\uC744 \uD655\uC778\uD574 \uC8FC\uC138\uC694');
            }
        }

        // ── 뒤로가기 ──
        document.getElementById('btn-back-group').addEventListener('click', () => {
            showState('groups');
        });
    })();
    </script>
</body>
</html>
