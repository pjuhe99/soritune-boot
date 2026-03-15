/* ══════════════════════════════════════════════════════════════
   MemberIssue — 사용자용 오류 문의 (등록 + 내역 조회)
   과제 이력 탭 상단 안내 영역에서 호출
   ══════════════════════════════════════════════════════════════ */
const MemberIssue = (() => {

    /**
     * 과제 이력 탭 상단에 안내 + 버튼 영역 렌더링
     * @param {HTMLElement} containerEl - #assignments-notice 영역
     */
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

    /**
     * 오류 문의 등록 모달 (껍데기 — 다음 단계에서 폼 + 저장 연결)
     */
    function openReportModal() {
        MemberUtils.logEvent('open_issue_report');

        App.openModal('오류 문의 등록', `
            <div class="issue-modal-body">
                <p class="issue-modal-placeholder">
                    오류 문의 등록 기능은 준비 중입니다.
                </p>
            </div>
        `);
    }

    /**
     * 내 오류 문의 내역 모달 (껍데기 — 다음 단계에서 목록 연결)
     */
    function openMyIssuesModal() {
        MemberUtils.logEvent('open_my_issues');

        App.openModal('내 오류 문의 내역', `
            <div class="issue-modal-body">
                <p class="issue-modal-placeholder">
                    아직 등록된 문의가 없습니다.
                </p>
            </div>
        `);
    }

    return { renderNotice, openReportModal, openMyIssuesModal };
})();
