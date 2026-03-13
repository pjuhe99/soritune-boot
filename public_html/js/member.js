/* ══════════════════════════════════════════════════════════════
   MemberApp — 유저 대시보드 + 통합 달력 (복습클래스 + 코치 강의)
   CalendarUI 공통 컴포넌트 사용
   ══════════════════════════════════════════════════════════════ */
const MemberApp = (() => {
    const API = '/api/bootcamp.php?action=';

    let root = null;
    let member = null;
    let cal = null;

    // ── Init ──
    async function init() {
        root = document.getElementById('member-root');

        App.showLoading();
        const r = await App.get('/api/member.php?action=check_session');
        App.hideLoading();

        if (r.logged_in) {
            member = r.member;
            showDashboard();
        } else {
            showLoginForm();
        }
    }

    // ══════════════════════════════════════════════════════════
    // Login
    // ══════════════════════════════════════════════════════════

    function showLoginForm() {
        root.innerHTML = `
            <div class="member-login">
                <div class="login-box">
                    <div class="login-title">소리튠 부트캠프</div>
                    <p class="login-subtitle">회원 로그인</p>
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
                showDashboard();
            }
        };
    }

    // ══════════════════════════════════════════════════════════
    // Dashboard
    // ══════════════════════════════════════════════════════════

    function showDashboard() {
        root.innerHTML = `
            <div class="member-dashboard">
                <div class="member-header">
                    <div class="header-title">소리튠 부트캠프</div>
                    <div class="member-cohort">${App.esc(member.cohort)}</div>
                </div>
                <div class="member-content">
                    <div class="member-info-card">
                        <div class="member-nickname">${App.esc(member.nickname)}</div>
                        <div class="member-realname">${App.esc(member.member_name)}${member.group_name ? ` · ${App.esc(member.group_name)}` : ''}</div>
                        <div class="member-stats">
                            <div class="member-stat">
                                <div class="member-point">${member.score ?? 0}</div>
                                <div class="member-point-label">점수</div>
                            </div>
                            <div class="member-stat">
                                <div class="member-coin">${member.coin ?? 0}</div>
                                <div class="member-coin-label">코인</div>
                            </div>
                        </div>
                    </div>

                    <div class="member-cal-section">
                        <div class="member-legend">
                            <span class="member-legend-item"><span class="member-legend-dot member-legend-study"></span>복습클래스</span>
                            <span class="member-legend-item"><span class="member-legend-dot member-legend-lecture"></span>코치 강의</span>
                        </div>
                        <div id="member-cal-container"></div>
                    </div>

                    <div class="member-logout-wrap">
                        <button class="btn btn-secondary" id="btn-member-logout">로그아웃</button>
                    </div>
                </div>
            </div>
        `;

        document.getElementById('btn-member-logout').onclick = async () => {
            await App.post('/api/member.php?action=logout');
            Toast.info('로그아웃 되었습니다.');
            member = null;
            showLoginForm();
        };

        // 달력 초기화
        cal = CalendarUI.create(document.getElementById('member-cal-container'), {
            onMonthChange: () => loadCalendarData(),
            chipSelector: '.member-chip',
            onChipClick: (e, chip) => openEventDetail(chip.dataset.type, parseInt(chip.dataset.id)),
            renderChips(events) {
                return events.map(ev => {
                    if (ev._type === 'study') {
                        return renderStudyChip(ev);
                    } else {
                        return renderLectureChip(ev);
                    }
                }).join('');
            },
        }).mount();

        loadCalendarData();
    }

    // ══════════════════════════════════════════════════════════
    // Data Loading — 복습 + 강의 통합
    // ══════════════════════════════════════════════════════════

    async function loadCalendarData() {
        const { year, month } = cal.getMonth();
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;

        const [studyRes, lectureRes] = await Promise.all([
            App.get(API + 'study_sessions', { month: monthStr }),
            App.get(API + 'lecture_sessions', { month: monthStr }),
        ]);

        const events = [];

        // 복습클래스 → _type: 'study', date: study_date
        if (studyRes.success) {
            (studyRes.sessions || []).forEach(s => {
                events.push({ ...s, _type: 'study', date: s.study_date });
            });
        }

        // 코치 강의 → _type: 'lecture', date: lecture_date
        if (lectureRes.success) {
            (lectureRes.sessions || []).forEach(s => {
                events.push({ ...s, _type: 'lecture', date: s.lecture_date });
            });
        }

        cal.setEvents(events).render();
    }

    // ══════════════════════════════════════════════════════════
    // Chip Rendering
    // ══════════════════════════════════════════════════════════

    function renderStudyChip(s) {
        const timeLabel = (s.start_time || '').substring(0, 5);
        const levelLabel = s.level ? s.level + '단계' : '';
        const host = s.host_nickname || '';
        const firstLine = timeLabel + (levelLabel ? ' ' + levelLabel : '');
        return `<div class="member-chip member-chip-study" data-type="study" data-id="${s.id}" title="${App.esc(s.title)}"><span class="chip-line1">${App.esc(firstLine)}</span><span class="chip-line2">${App.esc(host)}</span></div>`;
    }

    function renderLectureChip(s) {
        const timeLabel = (s.start_time || '').substring(0, 5);
        const coach = s.coach_name || '';
        const firstLine = timeLabel;
        return `<div class="member-chip member-chip-lecture" data-type="lecture" data-id="${s.id}" title="${App.esc(s.title)}"><span class="chip-line1">${App.esc(firstLine)}</span><span class="chip-line2">${App.esc(coach)}</span></div>`;
    }

    // ══════════════════════════════════════════════════════════
    // Detail Modal
    // ══════════════════════════════════════════════════════════

    function openEventDetail(type, id) {
        if (type === 'study') {
            openStudyDetail(id);
        } else {
            openLectureDetail(id);
        }
    }

    async function openStudyDetail(sessionId) {
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

    async function openLectureDetail(sessionId) {
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

    return { init };
})();
