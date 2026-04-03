/* ══════════════════════════════════════════════════════════════
   StudyCreate — 복습스터디 예약 모달 (공유 모듈)
   study.js, member-calendar.js 양쪽에서 사용
   ══════════════════════════════════════════════════════════════ */
const StudyCreate = (() => {
    const API = '/api/bootcamp.php?action=';

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

    /**
     * 복습스터디 예약 모달 열기
     * @param {object} opts
     * @param {function} opts.onCreated - 생성 완료 콜백 (studyDate, response)
     */
    async function open(opts = {}) {
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
        App.openModal('복습스터디 예약', body, footer);

        // ── Host selection state ──
        const state = { hostMemberId: null, hostNickname: '', hasOverlap: false, overlapSessions: [] };
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

        // ── Overlap check ──
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
            const password = document.getElementById('create-pw').value.trim();

            if (!state.hostMemberId) return Toast.warning('개설자를 선택해주세요.');
            if (!studyDate) return Toast.warning('날짜를 선택해주세요.');
            if (password.length !== 4 || !/^\d{4}$/.test(password)) return Toast.warning('4자리 숫자 비밀번호를 입력해주세요.');

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
                host_member_id: state.hostMemberId,
                study_date: studyDate,
                start_time: startTime,
                password: password,
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

    /** 캐시 초기화 (로그아웃 시) */
    function clearCache() {
        groupsCache = null;
        allMembersCache = null;
    }

    return { open, clearCache };
})();
