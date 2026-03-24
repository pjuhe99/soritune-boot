/* ══════════════════════════════════════════════════════════════
   AttendanceApp — QR 출석 현황 탭
   coach / head / operation 공통
   ══════════════════════════════════════════════════════════════ */
const AttendanceApp = (() => {
    const API = '/api/bootcamp.php?action=';
    let container = null;
    let currentAdmin = null;
    let currentRole = null;

    function init(el, admin, role) {
        container = el;
        currentAdmin = admin;
        currentRole = role;
        render();
    }

    function render() {
        const today = new Date().toISOString().slice(0, 10);
        const thirtyAgo = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);

        container.innerHTML = `
            <div class="att-container">
                <div class="att-filter-bar">
                    <div class="att-filter-group">
                        <label class="form-label">기간</label>
                        <div class="att-date-range">
                            <input type="date" class="form-input" id="att-date-from" value="${thirtyAgo}">
                            <span>~</span>
                            <input type="date" class="form-input" id="att-date-to" value="${today}">
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm" id="att-search-btn">조회</button>
                </div>
                <div id="att-summary"></div>
                <div id="att-list"></div>
                <div id="att-coach-summary"></div>
            </div>
        `;

        document.getElementById('att-search-btn').onclick = loadStats;
        loadStats();
    }

    async function loadStats() {
        const dateFrom = document.getElementById('att-date-from').value;
        const dateTo = document.getElementById('att-date-to').value;

        App.showLoading();
        const r = await App.get(API + 'attendance_stats', { date_from: dateFrom, date_to: dateTo });
        App.hideLoading();

        if (!r.success) return;

        renderSummary(r);
        renderList(r.stats, r.total_members);
        renderCoachSummary(r.summary);
    }

    function renderSummary(data) {
        const stats = data.stats || [];
        const lectureStats = stats.filter(s => s.category === 'lecture');
        const totalSessions = lectureStats.length;
        const avgAttendees = totalSessions > 0
            ? Math.round(lectureStats.reduce((s, x) => s + x.attendee_count, 0) / totalSessions)
            : 0;
        const avgRate = totalSessions > 0
            ? (lectureStats.reduce((s, x) => s + x.rate, 0) / totalSessions).toFixed(1)
            : '0.0';

        document.getElementById('att-summary').innerHTML = `
            <div class="att-summary-cards">
                <div class="att-summary-card">
                    <div class="att-summary-value">${totalSessions}</div>
                    <div class="att-summary-label">강의 세션</div>
                </div>
                <div class="att-summary-card">
                    <div class="att-summary-value">${avgAttendees}</div>
                    <div class="att-summary-label">평균 출석</div>
                </div>
                <div class="att-summary-card">
                    <div class="att-summary-value">${avgRate}%</div>
                    <div class="att-summary-label">평균 출석률</div>
                </div>
                <div class="att-summary-card">
                    <div class="att-summary-value">${data.total_members}</div>
                    <div class="att-summary-label">전체 인원</div>
                </div>
            </div>
        `;
    }

    function renderList(stats, totalMembers) {
        if (!stats.length) {
            document.getElementById('att-list').innerHTML = '<p class="att-empty">조회된 출석 기록이 없습니다.</p>';
            return;
        }

        // 날짜별 그룹핑
        const byDate = {};
        for (const s of stats) {
            const d = s.lecture_date;
            if (!byDate[d]) byDate[d] = [];
            byDate[d].push(s);
        }

        let html = '<div class="att-date-groups">';
        for (const [date, sessions] of Object.entries(byDate)) {
            const weekday = ['일', '월', '화', '수', '목', '금', '토'][new Date(date + 'T00:00:00').getDay()];
            html += `<div class="att-date-group">`;
            html += `<div class="att-date-header">${date} (${weekday})</div>`;

            for (const s of sessions) {
                const catLabel = getCategoryLabel(s);
                const catClass = `att-cat-${s.category}`;
                const title = getSessionTitle(s);
                const timeStr = s.lecture_start_time ? s.lecture_start_time.slice(0, 5) : s.created_at.slice(11, 16);
                const rateStr = s.category === 'study'
                    ? `${s.attendee_count}명 참여`
                    : `${s.attendee_count}/${totalMembers} (${s.rate}%)`;

                html += `
                    <div class="att-session-row">
                        <span class="att-cat-badge ${catClass}">${catLabel}</span>
                        <span class="att-session-time">${timeStr}</span>
                        <span class="att-session-title">${App.esc(title)}</span>
                        <span class="att-session-rate">${rateStr}</span>
                    </div>
                `;
            }
            html += `</div>`;
        }
        html += '</div>';

        document.getElementById('att-list').innerHTML = html;
    }

    function renderCoachSummary(summary) {
        const el = document.getElementById('att-coach-summary');
        const byCoach = summary.by_coach || [];
        const byStage = summary.by_stage || [];

        if (!byCoach.length && !byStage.length) {
            el.innerHTML = '';
            return;
        }

        let html = '<div class="att-bottom-summaries">';

        if (byCoach.length) {
            html += `
                <div class="att-bottom-card">
                    <div class="att-bottom-title">코치별 요약</div>
                    <table class="att-table">
                        <thead><tr><th>코치</th><th>강의 수</th><th>평균 출석</th><th>평균 출석률</th></tr></thead>
                        <tbody>
            `;
            for (const c of byCoach) {
                html += `<tr>
                    <td>${App.esc(c.admin_name)}</td>
                    <td>${c.session_count}회</td>
                    <td>${c.avg_attendees}명</td>
                    <td>${c.avg_rate}%</td>
                </tr>`;
            }
            html += '</tbody></table></div>';
        }

        if (byStage.length) {
            html += `
                <div class="att-bottom-card">
                    <div class="att-bottom-title">단계별 요약</div>
                    <table class="att-table">
                        <thead><tr><th>단계</th><th>강의 수</th><th>평균 출석</th><th>평균 출석률</th></tr></thead>
                        <tbody>
            `;
            for (const st of byStage) {
                html += `<tr>
                    <td>${st.stage}단계</td>
                    <td>${st.session_count}회</td>
                    <td>${st.avg_attendees}명</td>
                    <td>${st.avg_rate}%</td>
                </tr>`;
            }
            html += '</tbody></table></div>';
        }

        html += '</div>';
        el.innerHTML = html;
    }

    function getCategoryLabel(s) {
        switch (s.category) {
            case 'lecture': return '강의';
            case 'study':   return '복습스터디';
            case 'revival': return '패자부활';
            default:        return '기타';
        }
    }

    function getSessionTitle(s) {
        if (s.lecture_title) return s.lecture_title;
        if (s.study_title)  return s.study_title;
        if (s.category === 'revival') return '패자부활 QR';
        return `${s.admin_name} QR 세션`;
    }

    return { init };
})();
