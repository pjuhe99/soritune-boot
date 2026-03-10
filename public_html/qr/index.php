<?php
/**
 * QR 스캔 랜딩 페이지
 * 학생이 QR을 스캔하면 이 페이지에서 조 선택 → 닉네임 선택 → 출석 처리
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
    <title>출석체크 - 소리튠 부트캠프</title>
    <meta name="theme-color" content="#2563EB">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Pretendard Variable', Pretendard, -apple-system, sans-serif;
            background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
            min-height: 100vh;
        }
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
            background: linear-gradient(135deg, #2563EB, #1D4ED8);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: 16px;
        }
        .scan-group-name { font-size: 13px; font-weight: 600; color: #555; text-align: center; line-height: 1.2; }

        /* Member grid */
        .scan-member-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .scan-member-btn {
            padding: 14px 4px; border: 1.5px solid #F0F0F0; border-radius: 12px;
            background: #fff; cursor: pointer; transition: all .15s; text-align: center;
            -webkit-tap-highlight-color: transparent;
        }
        .scan-member-btn:active { background: #EFF6FF; border-color: #2563EB; transform: scale(.96); }
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
            background: linear-gradient(135deg, #2563EB, #1D4ED8);
            color: #fff; border: none; border-radius: 14px; font-size: 16px; font-weight: 700;
            cursor: pointer; font-family: inherit; text-decoration: none; text-align: center;
        }
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

        /* Loading */
        .scan-loading { text-align: center; padding: 40px 0; }
        .scan-loading-spinner {
            width: 36px; height: 36px; border: 3px solid #f0f0f0; border-top: 3px solid #2563EB;
            border-radius: 50%; animation: spin .8s linear infinite; margin: 0 auto 14px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .scan-loading-text { color: #999; font-size: 14px; }
    </style>
</head>
<body>
    <div class="scan-app">
        <div class="scan-header">
            <div class="scan-logo">&#x1F4F8;</div>
            <div class="scan-title">출석체크</div>
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
                <div class="scan-section-title">조를 선택하세요</div>
                <div class="scan-section-desc">본인이 속한 조를 눌러주세요</div>
                <div class="scan-group-grid" id="group-grid"></div>
            </div>

            <!-- Member Selection -->
            <div class="scan-state" id="st-members">
                <div class="scan-section-title" id="members-group-title">이름을 선택하세요</div>
                <div class="scan-section-desc">본인 닉네임을 눌러주세요</div>
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
                    <div class="scan-result-icon">&#x2705;</div>
                    <div class="scan-result-title" id="success-name">출석 완료!</div>
                    <div class="scan-result-desc" id="success-desc">출석이 기록되었습니다</div>
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

        // 1. 세션 검증
        try {
            const verify = await api(API + 'verify&code=' + encodeURIComponent(SESSION_CODE));
            if (!verify.valid) {
                if (verify.reason === 'expired') {
                    showError('\u23F0', '세션이 만료되었습니다', '코치에게 새 QR코드를 요청해 주세요');
                } else if (verify.reason === 'closed') {
                    showError('\uD83D\uDD12', '세션이 종료되었습니다', '출석체크가 종료되었습니다');
                } else {
                    showError('\uD83D\uDD0D', '세션을 찾을 수 없습니다', 'QR 코드가 올바르지 않습니다');
                }
                return;
            }

            // 2. 조 목록 로드
            const groupsRes = await api(API + 'groups&code=' + encodeURIComponent(SESSION_CODE));
            if (!groupsRes.success || !groupsRes.groups.length) {
                showError('\uD83D\uDE05', '조가 없습니다', '등록된 조가 없습니다');
                return;
            }

            showGroupGrid(groupsRes.groups);
        } catch (e) {
            showError('\u274C', '네트워크 오류', '인터넷 연결을 확인해 주세요');
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
                            showError('\uD83D\uDE05', '회원 없음', '이 조에 등록된 회원이 없습니다');
                        }
                    } catch (e) {
                        showError('\u274C', '네트워크 오류', '인터넷 연결을 확인해 주세요');
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
            document.getElementById('confirm-desc').textContent = particle + ' \uCD9C\uC11D\uD560\uAE4C\uC694?';
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
                await recordAttendance(memberId);
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
                    document.getElementById('success-name').textContent = name + '님 출석 완료!';
                    document.getElementById('success-desc').textContent =
                        result.already ? '이미 출석이 기록되어 있습니다' : '출석이 성공적으로 기록되었습니다';
                    showState('success');
                    // 진동 피드백
                    if (navigator.vibrate) navigator.vibrate(200);
                } else {
                    showError('\u26A0\uFE0F', '출석 오류', result.error || '출석 처리에 실패했습니다');
                }
            } catch (e) {
                showError('\u274C', '네트워크 오류', '인터넷 연결을 확인해 주세요');
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
