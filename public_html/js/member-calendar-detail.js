/* ══════════════════════════════════════════════════════════════
   MemberCalendarDetail — 복습스터디 / 코치 강의 상세 모달
   복습스터디: 출석체크(QR), 참여자 목록, 취소 기능 포함
   ══════════════════════════════════════════════════════════════ */
const MemberCalendarDetail = (() => {
    const API = '/api/bootcamp.php?action=';

    // 타입별 설정 — 공통 렌더링 함수에서 참조
    const TYPE_CONFIG = {
        study: {
            action: 'study_session_detail',
            dateField: 'study_date',
            dotClass: 'member-legend-study',
            typeLabel: '복습스터디',
            defaultTitle: '복습스터디 상세',
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
        event: {
            action: 'lecture_event_detail',
            responseKey: 'event',
            idParam: 'event_id',
            dateField: 'event_date',
            dotClass: 'member-legend-event',
            typeLabel: '이벤트',
            defaultTitle: '이벤트 상세',
            rows: s => {
                const r = [{ label: '코치', value: s.coach_name || '' }];
                if (s.stage) r.push({ label: '단계', value: s.stage == 1 ? '1단계' : '2단계' });
                return r;
            },
        },
    };

    function open(type, id) {
        if (!id || isNaN(id)) return;
        if (type === 'study') return openStudyDetail(id);
        const config = TYPE_CONFIG[type];
        if (!config) return;
        openGenericDetail(config, id);
    }

    // ══════════════════════════════════════════════════════════
    // Study Detail — 출석체크, 참여자, 취소 포함
    // ══════════════════════════════════════════════════════════

    async function openStudyDetail(sessionId) {
        App.showLoading();
        const r = await App.get(API + 'study_session_detail', { session_id: sessionId });
        App.hideLoading();
        if (!r.success) return;

        const s = r.session;
        const isHost = r.is_host;
        const participants = r.participants || [];
        const canCancel = r.can_cancel;
        const canStartQr = r.can_start_qr;
        const hasQrSession = r.has_qr_session;

        const startTime = (s.start_time || '').substring(0, 5);
        const endTime = (s.end_time || '').substring(0, 5);
        const dateKo = App.formatDateKo(s[TYPE_CONFIG.study.dateField]);
        const hasZoomUrl = s.zoom_status === 'ready' && s.zoom_join_url;

        // ── Info rows ──
        let body = `
            <div class="member-detail-type"><span class="member-legend-dot ${TYPE_CONFIG.study.dotClass}"></span>${TYPE_CONFIG.study.typeLabel}</div>
            <div class="lec-detail-info">
                <div class="lec-detail-row"><span class="lec-detail-label">날짜</span><span class="lec-detail-value">${dateKo}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">시간</span><span class="lec-detail-value">${App.esc(startTime + ' ~ ' + endTime)}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">진행자</span><span class="lec-detail-value">${App.esc(s.host_nickname || '')}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">참여</span><span class="lec-detail-value" id="detail-participant-count">${participants.length}명</span></div>
            </div>
        `;

        // ── Zoom + QR section ──
        {
            const actionItems = [];

            if (s.zoom_status === 'ready' && s.zoom_join_url) {
                actionItems.push(`<a href="${App.esc(s.zoom_join_url)}" target="_blank" class="lec-btn-zoom">Zoom 입장</a>`);
                actionItems.push(`<button class="lec-btn-copy" id="btn-copy-zoom">Zoom 링크 복사</button>`);
            } else if (s.zoom_status === 'pending') {
                actionItems.push('<div class="lec-notice muted">Zoom 준비 중입니다.</div>');
            } else if (s.zoom_status === 'failed' && isHost) {
                actionItems.push('<div class="lec-notice muted">Zoom 생성에 실패했습니다.</div>');
                actionItems.push(`<button class="lec-btn-zoom" id="btn-retry-zoom" style="width:100%;border:none;">Zoom 다시 생성하기</button>`);
            }

            if (isHost && s.status === 'active') {
                actionItems.push(`<button class="lec-btn-zoom" id="btn-start-qr" style="width:100%;border:none;">출석체크 진행하기</button>`);
            }

            if (actionItems.length) {
                body += `<div class="lec-detail-actions">${actionItems.join('')}</div>`;
            }
        }

        // ── Participants list ──
        body += `<div id="study-participant-list">${renderParticipants(participants)}</div>`;

        // ── Cancel section (host only) ──
        if (isHost && s.status !== 'cancelled') {
            if (canCancel) {
                body += `<div style="margin-top:var(--space-3);padding-top:var(--space-3);border-top:1px solid var(--color-gray-100);"><button class="btn btn-danger btn-block btn-sm" id="btn-cancel-study">복습스터디 취소하기</button></div>`;
            }
        }

        App.openModal(s.title || TYPE_CONFIG.study.defaultTitle, body);

        // ── Bind events ──
        const copyBtn = document.getElementById('btn-copy-zoom');
        if (copyBtn && hasZoomUrl) {
            copyBtn.onclick = () => MemberUtils.copyToClipboard(s.zoom_join_url, 'Zoom 링크가 복사되었습니다.');
        }

        const retryBtn = document.getElementById('btn-retry-zoom');
        if (retryBtn) {
            retryBtn.onclick = async () => {
                App.showLoading();
                const res = await App.post(API + 'study_session_retry_zoom', { session_id: s.id });
                App.hideLoading();
                if (res.success) {
                    Toast.success(res.message || 'Zoom이 생성되었습니다.');
                    App.closeModal();
                    openStudyDetail(sessionId);
                }
            };
        }

        const qrBtn = document.getElementById('btn-start-qr');
        if (qrBtn) {
            qrBtn.onclick = () => startQr(sessionId);
        }

        const cancelBtn = document.getElementById('btn-cancel-study');
        if (cancelBtn) {
            cancelBtn.onclick = () => openCancelFlow(sessionId, s.title);
        }
    }

    function renderParticipants(participants) {
        if (!participants.length) return '';
        return `
            <div style="margin-top:var(--space-3);padding-top:var(--space-3);border-top:1px solid var(--color-gray-100);">
                <div style="font-weight:var(--font-bold);font-size:var(--text-sm);color:var(--color-text-sub);margin-bottom:var(--space-2);">출석 현황 (${participants.length}명)</div>
                ${participants.map(p => `
                    <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:var(--text-sm);border-bottom:1px solid var(--color-gray-100);">
                        <span>${App.esc(p.nickname)} <span class="text-muted">${App.esc(p.group_name || '')}</span></span>
                        <span class="text-sub">${formatTime(p.scanned_at)}</span>
                    </div>
                `).join('')}
            </div>
        `;
    }

    // ── QR ──
    let qrPollTimer = null;

    async function startQr(sessionId) {
        App.showLoading();
        const r = await App.post(API + 'study_session_qr', { session_id: sessionId });
        App.hideLoading();
        if (!r.success) return;

        Toast.success(r.message || 'QR 출석체크가 시작되었습니다.');
        App.closeModal();

        const qrBody = `
            <div style="display:flex;flex-direction:column;align-items:center;">
                <p class="mb-sm" style="font-size:var(--sm-font-size);color:var(--color-777)">참여자에게 아래 QR을 보여주세요</p>
                <div id="qr-code-area" style="margin:16px 0;"></div>
                <button class="btn btn-secondary btn-sm" id="btn-copy-qr-url">링크 복사</button>
            </div>
            <div id="qr-participant-list" style="width:100%;margin-top:var(--space-4);"></div>
        `;
        App.openModal('출석체크 QR', qrBody,
            `<button class="btn btn-secondary btn-sm" id="btn-close-qr">닫기</button>`
        );

        document.getElementById('btn-close-qr').onclick = () => {
            stopQrPolling();
            App.closeModal();
        };

        document.getElementById('btn-copy-qr-url').onclick = async () => {
            try {
                await navigator.clipboard.writeText(r.scan_url);
            } catch {
                const ta = document.createElement('textarea');
                ta.value = r.scan_url; ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
            }
            Toast.success('링크가 복사되었습니다');
        };

        if (typeof QRCode === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';
            script.onload = () => generateQR(r.scan_url);
            document.head.appendChild(script);
        } else {
            generateQR(r.scan_url);
        }

        // 참여자 목록 폴링 시작
        pollParticipants(sessionId);
        qrPollTimer = setInterval(() => pollParticipants(sessionId), 5000);
    }

    async function pollParticipants(sessionId) {
        const r = await App.get(API + 'study_session_detail', { session_id: sessionId });
        if (!r.success) return;
        const el = document.getElementById('qr-participant-list');
        if (!el) { stopQrPolling(); return; }
        el.innerHTML = renderParticipants(r.participants || []);
    }

    function stopQrPolling() {
        if (qrPollTimer) { clearInterval(qrPollTimer); qrPollTimer = null; }
    }

    function generateQR(url) {
        const el = document.getElementById('qr-code-area');
        if (!el) return;
        new QRCode(el, { text: url, width: 200, height: 200 });
    }

    // ── Cancel ──
    function openCancelFlow(sessionId, title) {
        App.closeModal();
        const body = `
            <p style="font-size:var(--md-font-size);line-height:1.6;margin-bottom:16px;">
                <strong>${App.esc(title)}</strong>을(를) 취소하시겠습니까?
            </p>
        `;
        const footer = `
            <button class="btn btn-secondary btn-sm" onclick="App.closeModal()">닫기</button>
            <button class="btn btn-danger btn-sm" id="btn-confirm-cancel">취소하기</button>
        `;
        App.openModal('복습스터디 취소', body, footer);

        document.getElementById('btn-confirm-cancel').onclick = async () => {
            App.showLoading();
            const r = await App.post(API + 'study_session_cancel', { session_id: sessionId });
            App.hideLoading();
            if (r.success) {
                Toast.success('복습스터디가 취소되었습니다.');
                App.closeModal();
                if (typeof MemberCalendar !== 'undefined') MemberCalendar.loadData();
            }
        };
    }

    function formatTime(datetimeStr) {
        if (!datetimeStr) return '';
        const d = new Date(datetimeStr);
        return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    }

    // ══════════════════════════════════════════════════════════
    // Generic Detail — lecture / event
    // ══════════════════════════════════════════════════════════

    async function openGenericDetail(config, sessionId) {
        App.showLoading();
        const paramKey = config.idParam || 'session_id';
        const r = await App.get(API + config.action, { [paramKey]: sessionId });
        App.hideLoading();
        if (!r.success) return;

        const responseKey = config.responseKey || 'session';
        const s = r[responseKey];
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
