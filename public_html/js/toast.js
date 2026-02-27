/* ── Toast Notification System ────────────────────────────── */
const Toast = (() => {
    let container = null;

    function getContainer() {
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        return container;
    }

    function show(message, type = 'info', duration = 3000) {
        const el = document.createElement('div');
        el.className = `toast toast-${type}`;
        el.textContent = message;
        getContainer().appendChild(el);

        setTimeout(() => {
            el.classList.add('toast-out');
            el.addEventListener('animationend', () => el.remove());
        }, duration);
    }

    return {
        success: (msg, dur) => show(msg, 'success', dur),
        error:   (msg, dur) => show(msg, 'error', dur),
        warning: (msg, dur) => show(msg, 'warning', dur),
        info:    (msg, dur) => show(msg, 'info', dur),
    };
})();
