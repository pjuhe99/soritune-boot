/* ══════════════════════════════════════════════════════════════
   MemberBravo — BRAVO 도전 탭
   등급 카드(자격/기간) + 응시 액션(도전하기/이어하기/제출 마무리)
   응시 플로우는 MemberBravoExam 모듈로 위임
   ══════════════════════════════════════════════════════════════ */
const MemberBravo = (() => {
    MemberTabs.register('bravo', { mount });

    function statusBadge(lv) {
        return lv.eligible
            ? '<span class="bravo-badge eligible">응시 가능</span>'
            : '<span class="bravo-badge ineligible">응시 불가</span>';
    }

    function detailText(lv) {
        if (!lv.eligible) {
            return `부트캠프 ${lv.required_review_count}회독 이상이면 도전할 수 있어요.`;
        }
        const ex = lv.exam;
        if (ex && ex.status === 'open') {
            if (ex.exam_mode === 'always') return '상시 도전 가능';
            const s = (ex.start_at || '').slice(0, 16);
            const e = (ex.end_at || '').slice(0, 16);
            const rel = ex.result_release_at ? ` · 결과 발표 ${ex.result_release_at.slice(0, 10)}` : '';
            return `도전 기간: ${s} ~ ${e}${rel}`;
        }
        return '도전 기간이 곧 안내됩니다.';
    }

    // 카드 액션 (slice6 상태표 + slice8 released 분기 — 스펙 §5)
    function actionHtml(lv) {
        const ex = lv.exam, at = lv.attempts;
        if (!lv.eligible || !ex || !at) return '';
        // released 결과 분기 최상단 (submitted 보다 먼저)
        if (at.result) {
            const r = at.result;
            if (r.result === 'pass') {
                const certLabel = r.cert_issued ? '인증서 다시 받기' : '인증서 다운로드';
                return `<p class="bravo-state bravo-result-pass">🎉 합격! 총점 ${parseFloat(r.total_score)} / 합격선 ${parseFloat(r.passing_score)}</p>
                    <button class="btn btn-primary bravo-cert" data-attempt-id="${r.attempt_id}">${certLabel}</button>`;
            }
            return `<p class="bravo-state bravo-result-fail">아쉽게 불합격 — 총점 ${parseFloat(r.total_score)} / 합격선 ${parseFloat(r.passing_score)}. 다음 도전 기간에 다시 도전할 수 있어요.</p>`;
        }
        // 다른 시험이 카드에 떠도 이미 합격한 등급이면 도전 대신 합격 완료 표시
        if (lv.passed_level) {
            return `<p class="bravo-state bravo-result-pass">✅ BRAVO ${parseInt(lv.level, 10)} 합격 완료</p>`;
        }
        if (at.submitted) {
            return '<p class="bravo-state">제출완료 — 결과 발표 대기</p>'; // released+미확정(채점 누락)도 여기로 — 대기 유지
        }
        if (at.in_progress) {
            const ip = at.in_progress;
            if (ip.answered >= ip.total && ip.total > 0) {
                return `<button class="btn btn-primary bravo-finalize" data-attempt-id="${ip.attempt_id}">제출 마무리</button>`;
            }
            if (ex.status === 'open') {
                return `<button class="btn btn-primary bravo-challenge" data-exam-id="${at.exam_id}">이어하기 (${ip.answered}/${ip.total})</button>`;
            }
            return '<p class="bravo-state">응시 기간 종료 (미제출)</p>';
        }
        if (ex.status === 'open') {
            if (at.used >= at.limit && at.limit > 0) {
                return `<p class="bravo-state">응시 횟수 소진 (${at.used}/${at.limit})</p>`;
            }
            return `<button class="btn btn-primary bravo-challenge" data-exam-id="${at.exam_id}">도전하기 (${at.used}/${at.limit}회 사용)</button>`;
        }
        return '';
    }

    function render(el, member, levels) {
        const cards = levels.map(lv => `
            <div class="bravo-level-card ${lv.eligible ? 'is-eligible' : ''}">
                <div class="bravo-level-head">
                    <span class="bravo-level-name">${App.esc(lv.name || ('BRAVO ' + lv.level))}</span>
                    ${statusBadge(lv)}
                </div>
                <div class="bravo-level-detail">${App.esc(detailText(lv))}</div>
                <div class="bravo-level-action">${actionHtml(lv)}</div>
            </div>`).join('');

        const sub = member
            ? `${App.esc(member.cohort || '')} · 등록 회독 ${parseInt(member.effective_review_count, 10) || 0}회`
            : '';

        el.innerHTML = `
            <div class="member-bravo">
                <div class="member-bravo-head">
                    <h3 class="member-bravo-title">BRAVO 도전</h3>
                    <p class="member-bravo-sub">${sub}</p>
                </div>
                <div class="bravo-level-cards">${cards}</div>
                <p class="member-bravo-note">합격/불합격 결과는 응시 후 결과 발표일에 공개됩니다.</p>
            </div>`;

        el.querySelectorAll('.bravo-challenge').forEach(b =>
            b.addEventListener('click', () =>
                MemberBravoExam.open(el, parseInt(b.dataset.examId, 10), () => mount(el, member))));
        el.querySelectorAll('.bravo-finalize').forEach(b =>
            b.addEventListener('click', () => {
                b.disabled = true;
                MemberBravoExam.finalize(parseInt(b.dataset.attemptId, 10), () => mount(el, member));
            }));
        el.querySelectorAll('.bravo-cert').forEach(b =>
            b.addEventListener('click', () =>
                window.open('/api/member.php?action=bravo_certificate&attempt_id=' + b.dataset.attemptId)));
    }

    async function mount(el, member) {
        el.innerHTML = '<div class="member-bravo"><p class="member-bravo-loading">불러오는 중...</p></div>';
        const r = await App.get('/api/member.php?action=bravo_status');
        if (!r || r.success === false) {
            el.innerHTML = '<div class="member-bravo"><p class="member-bravo-error">상태를 불러오지 못했습니다.</p></div>';
            return;
        }
        render(el, r.member, r.levels || []);
    }

    return {};
})();
