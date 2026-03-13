/* ══════════════════════════════════════════════════════════════
   StudyApp — 복습클래스 달력 & 예약
   CalendarUI 공통 컴포넌트 사용
   ══════════════════════════════════════════════════════════════ */
const StudyApp = (() => {
    const API = '/api/bootcamp.php?action=';

    let root = null;
    let member = null;
    let cal = null; // CalendarUI instance

    // ── Init ──
    async function init() {
        root = document.getElementById('study-root');

        App.showLoading();
        const r = await App.get('/api/member.php?action=check_session');
        App.hideLoading();

        if (r.logged_in) {
            member = r.member;
            showMain();
            loadSessions();
        } else {
            showLogin();
        }
    }

    // ── Login ──
    function showLogin() {
        root.innerHTML = `
            <div class="study-login">
                <div class="login-box">
                    <div class="login-title">소리튠 부트캠프</div>
                    <p class="login-subtitle">복습클래스</p>
                    <form id="login-form">
                        <div class="form-group">
                            <label class="form-label">이름</label>
                            <input type="text" class="form-input" id="login-name" placeholder="홍길동" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">전화번호 뒤 4자리</label>
                            <input type="tel" class="form-input" id="login-phone" placeholder="5678" maxlength="4" pattern="[0-9]{4}" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block btn-lg mt-md">로그인</button>
                    </form>
                </div>
            </div>
        `;
        document.getElementById('login-form').onsubmit = async (e) => {
            e.preventDefault();
            const name = document.getElementById('login-name').value.trim();
            const phoneLast4 = document.getElementById('login-phone').value.trim();
            if (!name || !phoneLast4) return;

            App.showLoading();
            const r = await App.post('/api/member.php?action=login', { name, phone_last4: phoneLast4 });
            App.hideLoading();

            if (r.success) {
                member = r.member;
                Toast.success(r.message);
                showMain();
                loadSessions();
            }
        };
    }

    // ── Main Layout ──
    function showMain() {
        root.innerHTML = `
            <div class="study-app">
                <div class="study-header">
                    <div class="study-header-left">
                        <div class="study-header-title">복습클래스</div>
                        <span class="study-header-user">${App.esc(member.nickname)}</span>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <button class="btn btn-primary btn-sm" id="btn-create-study">복습클래스 예약</button>
                        <button class="btn btn-secondary btn-sm" id="btn-logout">로그아웃</button>
                    </div>
                </div>
                <div id="study-cal-container"></div>
            </div>
        `;

        document.getElementById('btn-create-study').onclick = openCreateModal;
        document.getElementById('btn-logout').onclick = async () => {
            await App.post('/api/member.php?action=logout');
            Toast.info('로그아웃 되었습니다.');
            member = null;
            groupsCache = null;
            allMembersCache = null;
            showLogin();
        };

        // CalendarUI 인스턴스 생성
        cal = CalendarUI.create(document.getElementById('study-cal-container'), {
            onMonthChange: () => loadSessions(),
            chipSelector: '.study-chip',
            onChipClick: (e, chip) => openDetail(parseInt(chip.dataset.id)),
            renderChips(events) {
                return events.map(s => {
                    const statusClass = `status-${s.status}`;
                    const timeLabel = (s.start_time || '').substring(0, 5);
                    const levelLabel = s.level ? s.level + '단계' : '';
                    const nick = hostName(s.title);
                    const firstLine = timeLabel + ' ' + levelLabel;
                    return `<div class="study-chip ${statusClass}" data-id="${s.id}" title="${App.esc(s.title)}"><span class="chip-line1">${App.esc(firstLine)}</span><span class="chip-line2">${App.esc(nick)}</span></div>`;
                }).join('');
            },
        }).mount();
    }

    // ── Data Loading ──
    async function loadSessions() {
        const { year, month } = cal.getMonth();
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;
        const r = await App.get(API + 'study_sessions', { month: monthStr });
        const sessions = r.success ? (r.sessions || []) : [];
        // Map study_date → date for CalendarUI
        cal.setEvents(sessions.map(s => ({ ...s, date: s.study_date }))).render();
    }

    function hostName(title) {
        // "[HH:MM] N단계 닉네임님의 복습 클래스" → "닉네임"
        const m = title.match(/단계\s+(.+?)님의/) || title.match(/\]\s*(.+?)님의/);
        return m ? m[1] : '';
    }

    // ── Detail Modal ──
    async function openDetail(sessionId) {
        App.showLoading();
        const r = await App.get(API + 'study_session_detail', { session_id: sessionId });
        App.hideLoading();
        if (!r.success) return;

        const s = r.session;
        const isHost = r.is_host;
        const participants = r.participants || [];
        const canCancel = r.can_cancel;
        const canStartQr = r.can_start_qr;

        const startTime = (s.start_time || '').substring(0, 5);
        const endTime = (s.end_time || '').substring(0, 5);
        const dateKo = App.formatDateKo(s.study_date);
        const hasZoomUrl = s.zoom_status === 'ready' && s.zoom_join_url;

        // ── Zoom section ──
        let zoomSection = '';
        if (s.zoom_status === 'ready' && s.zoom_join_url) {
            zoomSection = `
                <div class="study-action-group">
                    <a href="${App.esc(s.zoom_join_url)}" target="_blank" class="btn btn-block study-btn-zoom" id="btn-zoom-join">Zoom 입장하기</a>
                    <button class="btn btn-secondary btn-block" id="btn-zoom-copy">Zoom 링크 복사하기</button>
                </div>
            `;
        } else if (s.zoom_status === 'pending') {
            zoomSection = `
                <div class="study-action-group">
                    <div class="study-notice info">Zoom 링크를 생성 중입니다. 잠시만 기다려주세요.</div>
                    <button class="btn btn-secondary btn-block" disabled>Zoom 입장하기</button>
                    <button class="btn btn-secondary btn-block" disabled>Zoom 링크 복사하기</button>
                </div>
            `;
        } else if (s.zoom_status === 'failed') {
            zoomSection = `
                <div class="study-action-group">
                    <div class="study-notice warning">Zoom 링크 생성에 실패했습니다.${isHost ? '' : ' 개설자에게 문의해주세요.'}</div>
                    <button class="btn btn-secondary btn-block" disabled>Zoom 입장하기</button>
                    <button class="btn btn-secondary btn-block" disabled>Zoom 링크 복사하기</button>
                    ${isHost ? `<button class="btn btn-primary btn-block btn-sm mt-sm" id="btn-retry-zoom">Zoom 다시 생성하기</button>` : ''}
                </div>
            `;
        }

        // ── QR attendance section ──
        let qrSection = '';
        if (isHost && s.status === 'active') {
            if (canStartQr) {
                qrSection = `<button class="btn btn-primary btn-block" id="btn-start-qr">출석체크 진행하기</button>`;
            } else {
                qrSection = `
                    <button class="btn btn-secondary btn-block" disabled>출석체크 진행하기</button>
                    <p class="study-notice muted">복습클래스 시간에만 출석체크를 진행할 수 있습니다</p>
                `;
            }
        }

        // ── Cancel section ──
        let cancelSection = '';
        if (isHost && s.status !== 'cancelled') {
            if (canCancel) {
                cancelSection = `<button class="btn btn-danger btn-block btn-sm" id="btn-cancel-study">복습클래스 취소하기</button>`;
            } else {
                cancelSection = `<button class="btn btn-secondary btn-block btn-sm" disabled>복습클래스 취소하기</button>
                    <p class="study-notice muted">시작된 복습클래스는 취소할 수 없습니다</p>`;
            }
        }

        // ── Participants ──
        let partHtml = '';
        if (participants.length) {
            partHtml = `<div class="study-participants">
                <div class="study-detail-label mb-sm">출석 현황 (${participants.length}명)</div>
                ${participants.map(p => `
                    <div class="study-participant-item">
                        <span>${App.esc(p.nickname)} <span class="text-muted">${App.esc(p.group_name || '')}</span></span>
                        <span class="text-sub">${formatTime(p.scanned_at)}</span>
                    </div>
                `).join('')}
            </div>`;
        }

        const body = `
            <div class="study-detail-info">
                <div class="study-detail-row">
                    <span class="study-detail-label">날짜</span>
                    <span class="study-detail-value">${dateKo}</span>
                </div>
                <div class="study-detail-row">
                    <span class="study-detail-label">시간</span>
                    <span class="study-detail-value">${startTime} ~ ${endTime}</span>
                </div>
                <div class="study-detail-row">
                    <span class="study-detail-label">개설자</span>
                    <span class="study-detail-value">${App.esc(s.host_nickname)}</span>
                </div>
                <div class="study-detail-row">
                    <span class="study-detail-label">상태</span>
                    <span class="study-detail-value"><span class="badge badge-${statusBadge(s.status)}">${statusLabel(s.status)}</span></span>
                </div>
            </div>
            <div class="study-detail-actions">
                ${zoomSection}
                ${qrSection}
                ${partHtml}
                ${cancelSection ? `<div class="study-detail-cancel-area">${cancelSection}</div>` : ''}
            </div>
        `;

        App.openModal(s.title, body, '');

        // ── Bind events ──
        const copyBtn = document.getElementById('btn-zoom-copy');
        if (copyBtn && hasZoomUrl) {
            copyBtn.onclick = async () => {
                try {
                    await navigator.clipboard.writeText(s.zoom_join_url);
                    Toast.success('링크가 복사되었습니다');
                } catch {
                    const ta = document.createElement('textarea');
                    ta.value = s.zoom_join_url;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    ta.remove();
                    Toast.success('링크가 복사되었습니다');
                }
            };
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
                    loadSessions();
                    openDetail(s.id);
                }
            };
        }

        const qrBtn = document.getElementById('btn-start-qr');
        if (qrBtn) {
            qrBtn.onclick = () => startQr(s.id);
        }

        const cancelBtn = document.getElementById('btn-cancel-study');
        if (cancelBtn) {
            cancelBtn.onclick = () => openCancelFlow(s.id, s.title);
        }
    }

    function statusLabel(status) {
        const map = { pending: '생성 중', active: '예약됨', closed: '종료', cancelled: '취소됨' };
        return map[status] || status;
    }

    function statusBadge(status) {
        const map = { pending: 'info', active: 'success', closed: 'chapter', cancelled: 'warning' };
        return map[status] || 'light';
    }

    function formatTime(datetimeStr) {
        if (!datetimeStr) return '';
        const d = new Date(datetimeStr);
        return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
    }

    // ── Start QR ──
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
        `;
        App.openModal('출석체크 QR', qrBody,
            `<button class="btn btn-secondary btn-sm" onclick="App.closeModal()">닫기</button>`
        );

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
    }

    function generateQR(url) {
        const el = document.getElementById('qr-code-area');
        if (!el) return;
        new QRCode(el, { text: url, width: 200, height: 200 });
    }

    // ── Cancel Flow ──
    async function openCancelFlow(sessionId, title) {
        App.closeModal();
        const body = `
            <p style="font-size:var(--md-font-size);line-height:1.6;margin-bottom:16px;">
                <strong>${App.esc(title)}</strong>을(를) 취소하시겠습니까?<br>
                취소하려면 복습클래스를 예약할 때 입력한 비밀번호 4자리를 입력해주세요.
            </p>
            <div class="form-group">
                <input type="tel" class="form-input" id="cancel-pw" maxlength="4" pattern="[0-9]{4}" placeholder="0000" inputmode="numeric" style="text-align:center;font-size:24px;letter-spacing:8px;">
            </div>
        `;
        const footer = `
            <button class="btn btn-secondary btn-sm" onclick="App.closeModal()">닫기</button>
            <button class="btn btn-danger btn-sm" id="btn-confirm-cancel">취소하기</button>
        `;
        App.openModal('복습클래스 취소', body, footer);

        document.getElementById('cancel-pw').focus();
        document.getElementById('btn-confirm-cancel').onclick = async () => {
            const pw = document.getElementById('cancel-pw').value.trim();
            if (pw.length !== 4) {
                Toast.warning('4자리 비밀번호를 입력해주세요.');
                return;
            }

            App.showLoading();
            const r = await App.post(API + 'study_session_cancel', { session_id: sessionId, password: pw });
            App.hideLoading();
            if (r.success) {
                Toast.success('복습클래스가 취소되었습니다.');
                App.closeModal();
                loadSessions();
            }
        };
    }

    // ── Create Modal ──
    let groupsCache = null;
    let allMembersCache = null;

    async function loadGroupsAndMembers() {
        if (groupsCache && allMembersCache) return;
        const [gRes, mRes] = await Promise.all([
            App.get(API + 'study_groups'),
            App.get(API + 'study_members'),
        ]);
        groupsCache = gRes.success ? gRes.groups : [];
        allMembersCache = mRes.success ? mRes.members : [];
    }

    async function openCreateModal() {
        App.showLoading();
        await loadGroupsAndMembers();
        App.hideLoading();

        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        const defaultDate = App.formatDate(tomorrow);

        const groupOpts = groupsCache.map(g =>
            `<option value="${g.id}">${App.esc(g.name)}</option>`
        ).join('');

        const hourOpts = Array.from({ length: 18 }, (_, i) => {
            const h = String(i + 6).padStart(2, '0');
            return `<option value="${h}">${h}시</option>`;
        }).join('');

        const body = `
            <div class="form-group">
                <label class="form-label">개설자</label>
                <div class="study-host-select">
                    <select class="form-select" id="create-group" style="margin-bottom:6px;">
                        <option value="">조 선택</option>
                        ${groupOpts}
                    </select>
                    <div class="study-search-wrap" style="position:relative;">
                        <input type="text" class="form-input" id="create-member-search" placeholder="닉네임 검색..." autocomplete="off">
                        <div class="study-member-dropdown" id="member-dropdown"></div>
                    </div>
                    <div id="selected-host-display" class="study-selected-host"></div>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">단계</label>
                <div style="display:flex;gap:8px;">
                    <label style="display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="radio" name="create-level" value="1" checked> 1단계</label>
                    <label style="display:flex;align-items:center;gap:4px;cursor:pointer;"><input type="radio" name="create-level" value="2"> 2단계</label>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">날짜</label>
                <input type="date" class="form-input" id="create-date" value="${defaultDate}">
            </div>
            <div class="form-group">
                <label class="form-label">시작 시간</label>
                <div style="display:flex;gap:8px;">
                    <select class="form-select" id="create-hour" style="flex:1;">
                        ${hourOpts}
                    </select>
                    <select class="form-select" id="create-minute" style="flex:1;">
                        <option value="00">00분</option>
                        <option value="30">30분</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">비밀번호</label>
                <input type="tel" class="form-input" id="create-pw" maxlength="4" pattern="[0-9]{4}" placeholder="0000" style="text-align:center;font-size:20px;letter-spacing:6px;" inputmode="numeric">
                <p class="text-sub mt-xs" style="font-size:var(--sm-font-size)">스터디를 취소할 때 확인하기 위한 비밀번호입니다. 4자리로 등록해주세요</p>
            </div>
        `;
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">닫기</button>
            <button class="btn btn-primary" id="btn-submit-create">예약하기</button>
        `;
        App.openModal('복습클래스 예약', body, footer);

        const state = { hostMemberId: null, hostNickname: '' };
        const groupSelect = document.getElementById('create-group');
        const searchInput = document.getElementById('create-member-search');
        const dropdown = document.getElementById('member-dropdown');
        const hostDisplay = document.getElementById('selected-host-display');

        function getFilteredMembers() {
            const groupId = groupSelect.value;
            const keyword = searchInput.value.trim().toLowerCase();
            return allMembersCache.filter(m => {
                if (groupId && String(m.group_id) !== groupId) return false;
                if (keyword) {
                    const nick = (m.nickname || '').toLowerCase();
                    const real = (m.real_name || '').toLowerCase();
                    if (!nick.includes(keyword) && !real.includes(keyword)) return false;
                }
                return true;
            });
        }

        function showDropdown() {
            const filtered = getFilteredMembers();
            if (!filtered.length) {
                dropdown.innerHTML = '<div class="study-dd-empty">검색 결과가 없습니다</div>';
            } else {
                dropdown.innerHTML = filtered.map(m =>
                    `<div class="study-dd-item" data-id="${m.id}" data-nick="${App.esc(m.nickname)}">
                        <span class="study-dd-nick">${App.esc(m.nickname)}</span>
                        <span class="study-dd-group">${App.esc(m.group_name || '')}</span>
                    </div>`
                ).join('');
            }
            dropdown.classList.add('open');
        }

        function hideDropdown() {
            setTimeout(() => dropdown.classList.remove('open'), 150);
        }

        function selectHost(id, nickname) {
            state.hostMemberId = id;
            state.hostNickname = nickname;
            searchInput.value = '';
            dropdown.classList.remove('open');
            hostDisplay.innerHTML = `
                <span class="badge badge-light">${App.esc(nickname)}</span>
                <button class="study-host-clear" title="선택 해제">&times;</button>
            `;
            hostDisplay.querySelector('.study-host-clear').onclick = () => {
                state.hostMemberId = null;
                state.hostNickname = '';
                hostDisplay.innerHTML = '';
            };
        }

        searchInput.onfocus = () => showDropdown();
        searchInput.oninput = () => showDropdown();
        searchInput.onblur = hideDropdown;
        groupSelect.onchange = () => {
            if (dropdown.classList.contains('open')) showDropdown();
        };

        dropdown.onmousedown = (e) => {
            e.preventDefault();
            const item = e.target.closest('.study-dd-item');
            if (item) {
                selectHost(parseInt(item.dataset.id), item.dataset.nick);
            }
        };

        const dateInput = document.getElementById('create-date');
        const hourSelect = document.getElementById('create-hour');
        const minuteSelect = document.getElementById('create-minute');

        async function checkOverlapIndicator() {
            const d = dateInput.value;
            if (!d) return;
            const [y, m] = d.split('-');
            const monthStr = `${y}-${m}`;
            const r = await App.get(API + 'study_sessions', { month: monthStr });
            const daySessions = (r.sessions || []).filter(s => s.study_date === d);

            const h = hourSelect.value;
            const min = minuteSelect.value;
            const time = `${h}:${min}`;
            const slotStart = timeToMinutes(time);
            const overlaps = daySessions.some(s => {
                const exStart = timeToMinutes((s.start_time || '').substring(0, 5));
                return exStart === slotStart;
            });
            state.hasOverlap = overlaps;
            state.overlapSessions = daySessions;
        }

        dateInput.onchange = checkOverlapIndicator;
        hourSelect.onchange = checkOverlapIndicator;
        minuteSelect.onchange = checkOverlapIndicator;
        checkOverlapIndicator();

        document.getElementById('btn-submit-create').onclick = async () => {
            const studyDate = dateInput.value;
            const startTime = `${hourSelect.value}:${minuteSelect.value}`;
            const password = document.getElementById('create-pw').value.trim();

            if (!state.hostMemberId) return Toast.warning('개설자를 선택해주세요.');
            if (!studyDate) return Toast.warning('날짜를 선택해주세요.');
            if (password.length !== 4 || !/^\d{4}$/.test(password)) return Toast.warning('4자리 숫자 비밀번호를 입력해주세요.');

            const slotStart = timeToMinutes(startTime);
            const overlap = (state.overlapSessions || []).find(s => {
                const exStart = timeToMinutes((s.start_time || '').substring(0, 5));
                return exStart === slotStart;
            });
            if (overlap) {
                return Toast.warning(`이미 해당 날짜/시간에 예약된 복습클래스가 있습니다`);
            }

            const level = parseInt(document.querySelector('input[name="create-level"]:checked').value);

            App.showLoading();
            const r = await App.post(API + 'study_session_create', {
                host_member_id: state.hostMemberId,
                study_date: studyDate,
                start_time: startTime,
                password: password,
                level: level,
            });
            App.hideLoading();

            if (r.success) {
                if (r.zoom_status === 'failed') {
                    Toast.warning(r.message || '복습클래스가 생성되었지만 Zoom 생성에 실패했습니다.');
                } else {
                    Toast.success(r.message || '복습클래스가 생성되었습니다.');
                }
                App.closeModal();

                const [y, m] = studyDate.split('-');
                cal.setMonth(parseInt(y), parseInt(m) - 1);
                loadSessions();
            }
        };
    }

    function timeToMinutes(timeStr) {
        const [h, m] = timeStr.split(':').map(Number);
        return h * 60 + m;
    }

    return { init };
})();
