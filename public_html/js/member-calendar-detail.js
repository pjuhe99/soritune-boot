/* ══════════════════════════════════════════════════════════════
   MemberCalendarDetail — 복습클래스 / 코치 강의 상세 모달
   ══════════════════════════════════════════════════════════════ */
const MemberCalendarDetail = (() => {
    const API = '/api/bootcamp.php?action=';

    // 타입별 설정 — 공통 렌더링 함수에서 참조
    const TYPE_CONFIG = {
        study: {
            action: 'study_session_detail',
            dateField: 'study_date',
            dotClass: 'member-legend-study',
            typeLabel: '복습클래스',
            defaultTitle: '복습클래스 상세',
            rows: s => [
                { label: '진행자', value: s.host_nickname || '' },
                { label: '참여', value: (s.participant_count ?? 0) + '명' },
            ],
        },
        lecture: {
            action: 'lecture_session_detail',
            dateField: 'lecture_date',
            dotClass: 'member-legend-lecture',
            typeLabel: '코치 강의',
            defaultTitle: '강의 상세',
            rows: s => [
                { label: '코치', value: s.coach_name || '' },
                { label: '단계', value: (s.stage == 1 ? '1단계' : '2단계') },
            ],
        },
    };

    function open(type, id) {
        if (!id || isNaN(id)) return;
        const config = TYPE_CONFIG[type];
        if (!config) return;
        openDetail(config, id);
    }

    async function openDetail(config, sessionId) {
        App.showLoading();
        const r = await App.get(API + config.action, { session_id: sessionId });
        App.hideLoading();
        if (!r.success) return;

        const s = r.session;
        const dateKo = App.formatDateKo(s[config.dateField]);
        const timeLabel = (s.start_time || '').substring(0, 5) + ' ~ ' + (s.end_time || '').substring(0, 5);

        const extraRows = config.rows(s).map(row =>
            `<div class="lec-detail-row"><span class="lec-detail-label">${App.esc(row.label)}</span><span class="lec-detail-value">${App.esc(row.value)}</span></div>`
        ).join('');

        let body = `
            <div class="member-detail-type"><span class="member-legend-dot ${config.dotClass}"></span>${config.typeLabel}</div>
            <div class="lec-detail-info">
                <div class="lec-detail-row"><span class="lec-detail-label">날짜</span><span class="lec-detail-value">${dateKo}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">시간</span><span class="lec-detail-value">${App.esc(timeLabel)}</span></div>
                ${extraRows}
            </div>
        `;

        if (s.zoom_status === 'ready' && s.zoom_join_url) {
            body += `
                <div class="lec-detail-actions">
                    <a href="${App.esc(s.zoom_join_url)}" target="_blank" class="lec-btn-zoom">Zoom 입장</a>
                    <button class="lec-btn-copy" id="btn-copy-zoom">Zoom 링크 복사</button>
                </div>
            `;
        } else if (s.zoom_status === 'pending') {
            body += '<div class="lec-notice muted">Zoom 준비 중입니다.</div>';
        }

        App.openModal(s.title || config.defaultTitle, body);

        const btn = document.getElementById('btn-copy-zoom');
        if (btn && s.zoom_join_url) {
            btn.onclick = () => MemberUtils.copyToClipboard(s.zoom_join_url, 'Zoom 링크가 복사되었습니다.');
        }
    }

    return { open };
})();
