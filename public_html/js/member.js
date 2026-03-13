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

                    <div id="member-curriculum-section"></div>

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
        loadCurriculumToday();
    }

    // ══════════════════════════════════════════════════════════
    // Curriculum — 오늘의 진도 & 할 일
    // ══════════════════════════════════════════════════════════

    // ── 설명 팝업 데이터 (task_type → help content) ──
    // 추후 API/관리자 관리로 전환 가능하도록 분리된 config 구조
    const CURRICULUM_HELP = {
        malkka_mission: {
            title: '말까미션이란?',
            description: '말까미션은 매일 30초~1분 동안 또박또박 발음하며 말을 깨끗하게 하는 연습입니다.\n\n목표 문장을 읽고 녹음하여 카페에 인증해주세요.',
            images: ['/images/help/malkka_mission.png'],
        },
        naemat33_mission: {
            title: '내맛33미션이란?',
            description: '내 맛을 33번 반복하여 체화하는 미션입니다.\n\n지정된 연습 문장을 33번 반복하고 카페에 인증해주세요.',
            images: ['/images/help/naemat33_mission.png'],
        },
        zoom_or_daily_mission: {
            title: '줌 강의 / 데일리미션이란?',
            description: '줌 강의가 있는 날은 줌 강의에 참석하고, 줌 강의가 없는 날은 데일리미션을 수행합니다.\n\n줌 강의 참석 또는 데일리미션 완료 후 카페에 인증해주세요.',
            images: ['/images/help/zoom_or_daily_mission.png'],
        },
        hamummal: {
            title: '하멈말이란?',
            description: '하루를 멈추고 말하기 — 하루를 돌아보며 짧게 말해보는 시간입니다.\n\n오늘 하루를 한 문장으로 정리하여 카페에 인증해주세요.',
            images: ['/images/help/hamummal.png'],
        },
    };

    async function loadCurriculumToday() {
        const sec = document.getElementById('member-curriculum-section');
        const r = await App.get(API + 'curriculum_today');
        if (!r.success || !r.items || !r.items.length) {
            sec.innerHTML = '';
            return;
        }

        const items = r.items.map(item => {
            const note = item.note ? `<span class="cur-note">${App.esc(item.note)}</span>` : '';
            const helpBtn = CURRICULUM_HELP[item.task_type]
                ? `<button class="cur-help-btn" data-task-type="${App.esc(item.task_type)}">?</button>`
                : '';
            return `<div class="cur-item"><span class="cur-label">${App.esc(item.task_type_label)}</span>${helpBtn}${note}</div>`;
        }).join('');

        sec.innerHTML = `
            <div class="member-curriculum-card">
                <div class="cur-title">오늘의 진도 &amp; 할 일</div>
                <div class="cur-list">${items}</div>
            </div>
        `;

        // [?] 버튼 이벤트 바인딩
        sec.querySelectorAll('.cur-help-btn').forEach(btn => {
            btn.onclick = () => showCurriculumHelp(btn.dataset.taskType);
        });
    }

    function showCurriculumHelp(taskType) {
        const help = CURRICULUM_HELP[taskType];
        if (!help) return;

        const imagesHtml = (help.images || []).map(url =>
            `<img src="${App.esc(url)}" class="cur-help-img" alt="" onerror="this.style.display='none'">`
        ).join('');

        const descHtml = App.esc(help.description || '').replace(/\n/g, '<br>');

        const body = `
            <div class="cur-help-body">
                ${imagesHtml}
                <div class="cur-help-desc">${descHtml}</div>
            </div>
        `;

        App.openModal(help.title, body);
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
        const stageLabel = s.stage ? s.stage + '단계' : '';
        const coach = s.coach_name || '';
        const firstLine = timeLabel + (stageLabel ? ' ' + stageLabel : '');
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
