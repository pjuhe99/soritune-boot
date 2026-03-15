/* ══════════════════════════════════════════════════════════════
   MemberUtils — 사용자 페이지 공통 유틸
   로그, 날짜 헬퍼, 상수 등
   ══════════════════════════════════════════════════════════════ */
const MemberUtils = (() => {
    const LOG_API = '/api/bootcamp.php?action=member_event_log';

    /**
     * 이벤트 로그 저장 (non-blocking)
     * 실패해도 무시 — 화면 기능에 영향 없음
     * @param {string} eventName  ALLOWED_EVENTS 중 하나
     * @param {string|null} eventValue 필터값, 항목ID 등
     */
    function logEvent(eventName, eventValue) {
        const data = { event_name: eventName };
        if (eventValue != null) data.event_value = String(eventValue);
        App.post(LOG_API, data).catch(() => {});
    }

    return { logEvent };
})();
