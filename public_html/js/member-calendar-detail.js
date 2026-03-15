/* ══════════════════════════════════════════════════════════════
   MemberCalendarDetail — 복습클래스 / 코치 강의 상세 모달
   기존 member.js에서 분리
   ══════════════════════════════════════════════════════════════ */
const MemberCalendarDetail = (() => {
    const API = '/api/bootcamp.php?action=';

    function open(type, id) {
        if (type === 'study') {
            openStudy(id);
        } else {
            openLecture(id);
        }
    }

    async function openStudy(sessionId) {
        App.showLoading();
        const r = await App.get(API + 'study_session_detail', { session_id: sessionId });
        App.hideLoading();
        if (!r.success) return;

        const s = r.session;
        const dateKo = App.formatDateKo(s.study_date);
        const timeLabel = (s.start_time || '').substring(0, 5) + ' ~ ' + (s.end_time || '').substring(0, 5);

        let body = `
            <div class="member-detail-type"><span class="member-legend-dot member-legend-study"></span>복습클래스</div>
            <div class="lec-detail-info">
                <div class="lec-detail-row"><span class="lec-detail-label">날짜</span><span class="lec-detail-value">${dateKo}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">시간</span><span class="lec-detail-value">${App.esc(timeLabel)}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">진행자</span><span class="lec-detail-value">${App.esc(s.host_nickname || '')}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">참여</span><span class="lec-detail-value">${s.participant_count ?? 0}명</span></div>
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

        App.openModal(s.title || '복습클래스 상세', body);
        bindCopyButton(s.zoom_join_url);
    }

    async function openLecture(sessionId) {
        App.showLoading();
        const r = await App.get(API + 'lecture_session_detail', { session_id: sessionId });
        App.hideLoading();
        if (!r.success) return;

        const s = r.session;
        const dateKo = App.formatDateKo(s.lecture_date);
        const timeLabel = (s.start_time || '').substring(0, 5) + ' ~ ' + (s.end_time || '').substring(0, 5);
        const stageLabel = s.stage === 1 || s.stage === '1' ? '1단계' : '2단계';

        let body = `
            <div class="member-detail-type"><span class="member-legend-dot member-legend-lecture"></span>코치 강의</div>
            <div class="lec-detail-info">
                <div class="lec-detail-row"><span class="lec-detail-label">날짜</span><span class="lec-detail-value">${dateKo}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">시간</span><span class="lec-detail-value">${App.esc(timeLabel)}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">코치</span><span class="lec-detail-value">${App.esc(s.coach_name || '')}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">단계</span><span class="lec-detail-value">${App.esc(stageLabel)}</span></div>
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

        App.openModal(s.title || '강의 상세', body);
        bindCopyButton(s.zoom_join_url);
    }

    function bindCopyButton(url) {
        const btn = document.getElementById('btn-copy-zoom');
        if (!btn || !url) return;
        btn.onclick = () => {
            navigator.clipboard.writeText(url).then(() => {
                Toast.success('Zoom 링크가 복사되었습니다.');
            }).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = url;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                Toast.success('Zoom 링크가 복사되었습니다.');
            });
        };
    }

    return { open };
})();
