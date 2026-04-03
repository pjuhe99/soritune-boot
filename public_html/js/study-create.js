/* ══════════════════════════════════════════════════════════════
   StudyCreate — 복습스터디 예약 모달 (공유 모듈)
   study.js, member-calendar.js 양쪽에서 사용
   로그인된 본인 이름으로 예약, 비밀번호 없음
   ══════════════════════════════════════════════════════════════ */
const StudyCreate = (() => {
    const API = '/api/bootcamp.php?action=';

    /**
     * 복습스터디 예약 모달 열기
     * @param {object} opts
     * @param {function} opts.onCreated - 생성 완료 콜백 (studyDate, response)
     */
    async function open(opts = {}) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        const defaultDate = App.formatDate(tomorrow);

        const hourOpts = Array.from({ length: 18 }, (_, i) => {
            const h = String(i + 6).padStart(2, '0');
            return `<option value="${h}">${h}시</option>`;
        }).join('');

        const body = `
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
        `;
        const footer = `
            <button class="btn btn-secondary" onclick="App.closeModal()">닫기</button>
            <button class="btn btn-primary" id="btn-submit-create">예약하기</button>
        `;
        App.openModal('복습스터디 예약', body, footer);

        // ── Overlap check state ──
        const state = { hasOverlap: false, overlapSessions: [] };
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

        // ── Submit ──
        document.getElementById('btn-submit-create').onclick = async () => {
            const studyDate = dateInput.value;
            const startTime = `${hourSelect.value}:${minuteSelect.value}`;

            if (!studyDate) return Toast.warning('날짜를 선택해주세요.');

            const startDt = new Date(`${studyDate}T${startTime}:00`);
            const now = new Date();
            if (startDt.getTime() - now.getTime() < 3 * 60 * 60 * 1000) {
                return Toast.warning('복습스터디는 시작 시간 3시간 전까지만 개설할 수 있습니다.');
            }

            const slotStart = timeToMinutes(startTime);
            const overlap = (state.overlapSessions || []).find(s => {
                const exStart = timeToMinutes((s.start_time || '').substring(0, 5));
                return exStart === slotStart;
            });
            if (overlap) {
                return Toast.warning('이미 해당 날짜/시간에 예약된 복습스터디가 있습니다');
            }

            const level = parseInt(document.querySelector('input[name="create-level"]:checked').value);

            App.showLoading();
            const r = await App.post(API + 'study_session_create', {
                study_date: studyDate,
                start_time: startTime,
                level: level,
            });
            App.hideLoading();

            if (r.success) {
                if (r.zoom_status === 'failed') {
                    Toast.warning(r.message || '복습스터디가 생성되었지만 Zoom 생성에 실패했습니다.');
                } else {
                    Toast.success(r.message || '복습스터디가 생성되었습니다.');
                }
                App.closeModal();

                if (opts.onCreated) opts.onCreated(studyDate, r);
            }
        };
    }

    function timeToMinutes(timeStr) {
        const [h, m] = timeStr.split(':').map(Number);
        return h * 60 + m;
    }

    return { open };
})();
