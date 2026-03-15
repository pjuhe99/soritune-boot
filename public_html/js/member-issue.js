/* ══════════════════════════════════════════════════════════════
   MemberIssue — 사용자용 오류 문의 (등록 + 내역 조회)
   과제 이력 탭 상단 안내 영역에서 호출
   ══════════════════════════════════════════════════════════════ */
const MemberIssue = (() => {
    const API = '/api/bootcamp.php?action=';

    // ── 문의 유형 (서버 ISSUE_TYPES와 동기화) ──
    const ISSUE_TYPES = [
        { key: 'naemat33',     label: '내맛33미션' },
        { key: 'zoom',         label: '줌 특강' },
        { key: 'daily',        label: '데일리 미션' },
        { key: 'malkka',       label: '말까미션' },
        { key: 'study_create', label: '복습클래스 개설' },
        { key: 'study_join',   label: '복습클래스 참여' },
    ];

    // ── 상태 배지 매핑 ──
    const STATUS_MAP = {
        pending:     { label: '접수됨',    cls: 'issue-badge--pending' },
        in_progress: { label: '확인 중',   cls: 'issue-badge--progress' },
        resolved:    { label: '처리 완료', cls: 'issue-badge--resolved' },
        rejected:    { label: '반려',      cls: 'issue-badge--rejected' },
    };

    const DESC_PREVIEW_LEN = 50;

    // ══════════════════════════════════════════════════════════
    // 안내 영역 렌더링
    // ══════════════════════════════════════════════════════════

    function renderNotice(containerEl) {
        if (!containerEl) return;

        containerEl.innerHTML = `
            <div class="assignments-notice">
                <p class="assignments-notice-text">
                    과제 이력이 반영되기까지는 최대 12시간 소요될 수 있습니다
                </p>
                <div class="assignments-notice-actions">
                    <button type="button" class="notice-link-btn" id="btn-report-issue">
                        과제를 했지만 체크되지 않았어요
                    </button>
                    <span class="notice-separator">|</span>
                    <button type="button" class="notice-link-btn" id="btn-my-issues">
                        내 오류 문의 내역
                    </button>
                </div>
            </div>
        `;

        document.getElementById('btn-report-issue').onclick = openReportModal;
        document.getElementById('btn-my-issues').onclick = openMyIssuesModal;
    }

    // ══════════════════════════════════════════════════════════
    // 오류 문의 등록 모달
    // ══════════════════════════════════════════════════════════

    function openReportModal() {
        MemberUtils.logEvent('open_issue_report');

        const typeOptions = ISSUE_TYPES.map(t =>
            `<label class="issue-radio-label">
                <input type="radio" name="issue_type" value="${t.key}" class="issue-radio">
                <span class="issue-radio-text">${App.esc(t.label)}</span>
            </label>`
        ).join('');

        const bodyHtml = `
            <div class="issue-form">
                <div class="issue-form-group">
                    <div class="issue-form-label">어떤 과제가 체크되지 않았나요? <span class="issue-required">*</span></div>
                    <div class="issue-radio-group" id="issue-type-group">
                        ${typeOptions}
                    </div>
                    <p class="issue-form-hint">하멈말은 조장님께 문의해주세요</p>
                </div>
                <div class="issue-form-group">
                    <div class="issue-form-label">추가로 설명하고 싶은 내용이 있다면 말씀해주세요.</div>
                    <textarea class="issue-textarea" id="issue-description" rows="3" maxlength="1000"
                        placeholder="카페 과제 링크를 올려주시거나, 상황을 구체적으로 설명해주시면 더욱 빠른 확인이 가능합니다."></textarea>
                </div>
                <div id="issue-form-error" class="issue-form-error"></div>
            </div>
        `;

        App.modal('오류 문의 등록', bodyHtml, submitReport);
    }

    async function submitReport() {
        const errorEl = document.getElementById('issue-form-error');
        const selected = document.querySelector('input[name="issue_type"]:checked');

        if (!selected) {
            errorEl.textContent = '문의 유형을 선택해주세요.';
            return false;
        }

        const description = (document.getElementById('issue-description')?.value || '').trim();
        errorEl.textContent = '';

        const okBtn = document.getElementById('modal-ok');
        if (okBtn) { okBtn.disabled = true; okBtn.textContent = '제출 중...'; }

        const r = await App.post(API + 'issue_create', {
            issue_type: selected.value,
            description,
        });

        if (r.success) {
            Toast.success(r.message || '오류 문의가 등록되었습니다.');
            return true;
        } else {
            errorEl.textContent = r.error || '등록에 실패했습니다.';
            if (okBtn) { okBtn.disabled = false; okBtn.textContent = '확인'; }
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════
    // 내 오류 문의 내역 모달
    // ══════════════════════════════════════════════════════════

    async function openMyIssuesModal() {
        MemberUtils.logEvent('open_my_issues');

        App.openModal('내 오류 문의 내역', `
            <div class="issue-modal-body">
                <p class="issue-modal-placeholder">불러오는 중...</p>
            </div>
        `);

        const r = await App.get(API + 'issue_list');
        const body = document.querySelector('#app-modal .issue-modal-body');
        if (!body) return;

        if (!r.success) {
            body.innerHTML = '<p class="issue-modal-placeholder">목록을 불러올 수 없습니다.</p>';
            return;
        }

        if (!r.issues || r.issues.length === 0) {
            body.innerHTML = `
                <div class="issue-empty">
                    <div class="issue-empty-icon">&#128196;</div>
                    <p class="issue-empty-text">아직 등록된 문의가 없습니다.</p>
                    <button type="button" class="btn btn-primary btn-sm" id="issue-empty-cta">오류 문의하기</button>
                </div>
            `;
            const cta = document.getElementById('issue-empty-cta');
            if (cta) cta.onclick = () => { App.closeModal(); openReportModal(); };
            return;
        }

        body.innerHTML = `
            <div class="issue-list-count">${r.issues.length}건</div>
            <div class="issue-list">
                ${r.issues.map(renderIssueRow).join('')}
            </div>
        `;

        // 각 행 클릭 → 상세 모달
        body.querySelectorAll('.issue-row[data-id]').forEach(row => {
            row.onclick = () => {
                const issue = r.issues.find(i => String(i.id) === row.dataset.id);
                if (issue) openDetailModal(issue);
            };
        });
    }

    // ══════════════════════════════════════════════════════════
    // 목록 행 렌더링
    // ══════════════════════════════════════════════════════════

    function renderIssueRow(issue) {
        const st = STATUS_MAP[issue.status] || STATUS_MAP.pending;
        const dateStr = formatDateTime(issue.created_at);

        // 설명 미리보기 (길면 자르기)
        let descPreview = '';
        if (issue.description) {
            const full = issue.description;
            descPreview = full.length > DESC_PREVIEW_LEN
                ? `<p class="issue-row-desc">${App.esc(full.slice(0, DESC_PREVIEW_LEN))}…</p>`
                : `<p class="issue-row-desc">${App.esc(full)}</p>`;
        }

        // 운영팀 답변 여부 표시
        const hasNote = issue.admin_note
            ? '<span class="issue-row-replied">답변 있음</span>'
            : '';

        return `
            <div class="issue-row issue-row--clickable" data-id="${issue.id}">
                <div class="issue-row-header">
                    <span class="issue-row-type">${App.esc(issue.issue_type_label)}</span>
                    <span class="issue-badge ${st.cls}">${st.label}</span>
                </div>
                ${descPreview}
                <div class="issue-row-footer">
                    <span class="issue-row-date">${dateStr}</span>
                    ${hasNote}
                </div>
            </div>
        `;
    }

    // ══════════════════════════════════════════════════════════
    // 상세 모달
    // ══════════════════════════════════════════════════════════

    function openDetailModal(issue) {
        const st = STATUS_MAP[issue.status] || STATUS_MAP.pending;
        const dateStr = formatDateTime(issue.created_at);

        const descHtml = issue.description
            ? `<div class="issue-detail-section">
                   <div class="issue-detail-label">내가 작성한 설명</div>
                   <div class="issue-detail-value">${App.esc(issue.description).replace(/\n/g, '<br>')}</div>
               </div>`
            : '';

        const noteHtml = issue.admin_note
            ? `<div class="issue-detail-section">
                   <div class="issue-detail-label">운영팀 답변</div>
                   <div class="issue-detail-note">${App.esc(issue.admin_note).replace(/\n/g, '<br>')}</div>
               </div>`
            : '';

        const resolvedHtml = issue.resolved_at
            ? `<div class="issue-detail-meta">처리일: ${formatDateTime(issue.resolved_at)}</div>`
            : '';

        App.openModal('문의 상세', `
            <div class="issue-detail">
                <div class="issue-detail-header">
                    <span class="issue-detail-type">${App.esc(issue.issue_type_label)}</span>
                    <span class="issue-badge ${st.cls}">${st.label}</span>
                </div>
                <div class="issue-detail-meta">등록일: ${dateStr}</div>
                ${resolvedHtml}
                ${descHtml}
                ${noteHtml}
            </div>
        `, `<button class="btn btn-secondary btn-sm" onclick="App.closeModal()">닫기</button>`);
    }

    // ══════════════════════════════════════════════════════════
    // 유틸
    // ══════════════════════════════════════════════════════════

    function formatDateTime(dt) {
        if (!dt) return '';
        // "2026-03-16 14:30:00" → "3/16 14:30"
        const d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d)) return dt.slice(0, 10);
        const m = d.getMonth() + 1;
        const day = d.getDate();
        const h = String(d.getHours()).padStart(2, '0');
        const min = String(d.getMinutes()).padStart(2, '0');
        return `${m}/${day} ${h}:${min}`;
    }

    function renderStatusBadge(status) {
        const st = STATUS_MAP[status] || STATUS_MAP.pending;
        return `<span class="issue-badge ${st.cls}">${st.label}</span>`;
    }

    return { renderNotice, openReportModal, openMyIssuesModal, renderStatusBadge };
})();
