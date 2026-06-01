/**
 * 줌 회의 ID / 비밀번호 표시 + 개별 복사 줄 렌더.
 * 값이 있을 때만 줄을 만든다. data-* 로 복사값을 담아 이벤트 위임으로 처리.
 *
 * 전역: window.ZoomCreds = { html, bind }
 */
(function () {
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /**
     * @param {object} data zoom_meeting_id_display, zoom_password_display 키
     * @returns {string} HTML (값 없으면 빈 문자열)
     */
    function html(data) {
        var id = data && data.zoom_meeting_id_display;
        var pw = data && data.zoom_password_display;
        var rows = '';
        if (id) {
            rows += '<div class="zoom-cred-row">' +
                '<span class="zoom-cred-label">회의 ID</span>' +
                '<span class="zoom-cred-value">' + esc(id) + '</span>' +
                '<button type="button" class="zoom-cred-copy" data-zoom-copy="' + esc(id) +
                '" data-zoom-kind="회의 ID">복사</button>' +
                '</div>';
        }
        if (pw) {
            rows += '<div class="zoom-cred-row">' +
                '<span class="zoom-cred-label">비밀번호</span>' +
                '<span class="zoom-cred-value">' + esc(pw) + '</span>' +
                '<button type="button" class="zoom-cred-copy" data-zoom-copy="' + esc(pw) +
                '" data-zoom-kind="비밀번호">복사</button>' +
                '</div>';
        }
        return rows ? '<div class="zoom-cred">' + rows + '</div>' : '';
    }

    function copyText(text, msg) {
        if (window.MemberUtils && MemberUtils.copyToClipboard) {
            MemberUtils.copyToClipboard(text, msg);
            return;
        }
        if (navigator.clipboard) navigator.clipboard.writeText(text);
        if (window.Toast) Toast.success(msg);
    }

    /**
     * 컨테이너에 복사 버튼 이벤트 위임 바인딩. 모달 새로 열 때마다 호출해도 안전(중복 방지).
     */
    function bind(container) {
        if (!container || container.__zoomCredBound) return;
        container.__zoomCredBound = true;
        container.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('[data-zoom-copy]');
            if (!btn || !container.contains(btn)) return;
            var val = btn.getAttribute('data-zoom-copy');
            var kind = btn.getAttribute('data-zoom-kind') || '값';
            copyText(val, kind + '가 복사되었습니다.');
        });
    }

    window.ZoomCreds = { html: html, bind: bind };
})();
