/* ══════════════════════════════════════════════════════════════
   CohortChip — 헤더의 기수 전환 칩 (회원/어드민 공용)
   ══════════════════════════════════════════════════════════════ */
window.CohortChip = (() => {
    /**
     * @param {Object} opts
     *   - container: 부착할 DOM (button 으로 교체 또는 append)
     *   - currentLabel: 현재 표시 라벨 ('12기')
     *   - options: [{cohort_id: int|null, label: string}]   ('전체' 면 cohort_id=null)
     *   - apiUrl: switch_cohort 호출 URL
     *   - onSwitched: function(cohortId) — switch 성공 후 reload 직전 호출 (선택)
     */
    function attach({ container, currentLabel, options, apiUrl, onSwitched }) {
        if (!container) return;

        container.innerHTML = '';
        container.classList.add('cohort-chip');
        container.setAttribute('type', 'button');

        const labelSpan = document.createElement('span');
        labelSpan.className = 'cohort-chip-label';
        labelSpan.textContent = currentLabel;
        container.appendChild(labelSpan);

        const arrow = document.createElement('span');
        arrow.className = 'cohort-chip-arrow';
        arrow.textContent = '▾';
        container.appendChild(arrow);

        const dropdown = document.createElement('div');
        dropdown.className = 'cohort-chip-dropdown';
        dropdown.style.display = 'none';
        options.forEach(opt => {
            const item = document.createElement('div');
            item.className = 'cohort-chip-item';
            const isCurrent = opt.label === currentLabel;
            item.innerHTML = `<span>${opt.label}</span>${isCurrent ? '<span class="check">✓</span>' : ''}`;
            item.onclick = async () => {
                if (isCurrent) { close(); return; }
                try {
                    const r = await fetch(apiUrl, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        credentials: 'same-origin',
                        body: JSON.stringify({cohort_id: opt.cohort_id}),
                    });
                    const j = await r.json();
                    if (!j.success) { alert(j.error || '전환 실패'); return; }
                    if (typeof onSwitched === 'function') onSwitched(opt.cohort_id);
                    location.reload();
                } catch (e) { alert('네트워크 오류'); }
            };
            dropdown.appendChild(item);
        });
        container.appendChild(dropdown);

        function close() { dropdown.style.display = 'none'; }
        function open() { dropdown.style.display = ''; }

        container.addEventListener('click', (e) => {
            e.stopPropagation();
            if (dropdown.style.display === 'none') open(); else close();
        });
        document.addEventListener('click', close);

        return { close };
    }

    return { attach };
})();
