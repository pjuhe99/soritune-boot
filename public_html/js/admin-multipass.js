/* ── Multipass (다회권 확인) ────────────────────────────────── */
const AdminMultipassApp = (() => {
    let container = null;
    let admin = null;

    function init(adminSession, containerId) {
        admin = adminSession;
        container = document.getElementById(containerId);
        if (!container) return;
        container.innerHTML = '<p style="padding:24px">다회권 확인 — 곧 채워집니다.</p>';
    }

    return { init };
})();
