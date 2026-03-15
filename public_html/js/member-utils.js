/* ══════════════════════════════════════════════════════════════
   MemberUtils — 사용자 페이지 공통 유틸
   로그, 필터 칩 바인딩, 클립보드 복사 등
   ══════════════════════════════════════════════════════════════ */
const MemberUtils = (() => {
    const LOG_API = '/api/bootcamp.php?action=member_event_log';

    /**
     * 이벤트 로그 저장 (non-blocking)
     * 실패해도 무시 — 화면 기능에 영향 없음
     */
    function logEvent(eventName, eventValue) {
        const data = { event_name: eventName };
        if (eventValue != null) data.event_value = String(eventValue);
        App.post(LOG_API, data).catch(() => {});
    }

    /**
     * 필터 칩 바인딩 (공통)
     * @param {string} containerId  - 칩 컨테이너 DOM id
     * @param {string} dataAttr     - 칩의 data-* 속성명 (예: 'stage', 'filter')
     * @param {function} onChange   - (value) => void, 선택 변경 시 콜백
     */
    function bindFilterChips(containerId, dataAttr, onChange) {
        const container = document.getElementById(containerId);
        if (!container) return;

        container.addEventListener('click', (e) => {
            const chip = e.target.closest('.filter-chip');
            if (!chip) return;

            const value = chip.dataset[dataAttr];
            const current = container.querySelector('.filter-chip.active');
            if (current && current.dataset[dataAttr] === value) return;

            container.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            onChange(value);
        });
    }

    /**
     * 클립보드 복사 (fallback 포함)
     */
    function copyToClipboard(text, successMsg) {
        navigator.clipboard.writeText(text).then(() => {
            Toast.success(successMsg || '복사되었습니다.');
        }).catch(() => {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            Toast.success(successMsg || '복사되었습니다.');
        });
    }

    return { logEvent, bindFilterChips, copyToClipboard };
})();
