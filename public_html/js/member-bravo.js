/* ══════════════════════════════════════════════════════════════
   MemberBravo — BRAVO 도전 탭
   등급 카드(자격/기간) + 응시 액션(도전하기/이어하기/제출 마무리)
   응시 플로우는 MemberBravoExam 모듈로 위임
   ══════════════════════════════════════════════════════════════ */
const MemberBravo = (() => {
    MemberTabs.register('bravo', { mount });

    function statusBadge(lv) {
        if (lv.held) return '<span class="bravo-badge held">보유 중</span>';
        return lv.eligible
            ? '<span class="bravo-badge eligible">응시 가능</span>'
            : '<span class="bravo-badge ineligible">응시 불가</span>';
    }

    function detailText(lv) {
        if (lv.held) {
            return `BRAVO ${parseInt(lv.level, 10)} 등급을 보유하고 있어요.`;
        }
        if (!lv.eligible) {
            return `부트캠프 ${lv.required_review_count}회독 이상이면 도전할 수 있어요.`;
        }
        if (lv.prev_required) {
            return `BRAVO ${parseInt(lv.level, 10) - 1} 등급 취득 후 도전할 수 있어요.`;
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

    // 카드 액션 — 분기 순서: ① 진행 중 응시(가리면 안 됨) ② released 결과 ③ 보유 ④ 이전등급 요건 ⑤ quota ⑥ 도전 (스펙 §6)
    function actionHtml(lv) {
        const ex = lv.exam, at = lv.attempts;
        if (lv.held && (!at || !at.in_progress)) {
            // 보유 등급: 결과 카드(인증서)가 있으면 그쪽 우선
            if (at && at.result && at.result.result === 'pass') {
                const r = at.result;
                const certLabel = r.cert_issued ? '인증서 다시 받기' : '인증서 다운로드';
                return `<p class="bravo-state bravo-result-pass">🎉 합격! 총점 ${parseFloat(r.total_score)} / 합격선 ${parseFloat(r.passing_score)}</p>
                    <button class="btn btn-primary bravo-cert" data-attempt-id="${r.attempt_id}">${certLabel}</button>`;
            }
            return `<p class="bravo-state bravo-result-pass">✅ BRAVO ${parseInt(lv.level, 10)} 보유</p>`;
        }
        if (!lv.eligible || !ex || !at) return '';
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
        if (at.result) {
            const r = at.result;
            if (r.result === 'pass') {
                // 여기 도달 = released 합격이지만 현재 미보유(강등/관리자 조정). 보유 중이면 위 held 분기에서 이미 처리됨.
                // stale "합격" 축하 대신 합격 이력으로 표시하고, 인증서(획득 기록)는 계속 제공.
                const certLabel = r.cert_issued ? '인증서 다시 받기' : '인증서 다운로드';
                return `<p class="bravo-state">BRAVO ${parseInt(lv.level, 10)} 합격 이력이 있어요 · 현재 미보유. 재취득하려면 새 시험에 다시 합격해주세요.</p>
                    <button class="btn btn-primary bravo-cert" data-attempt-id="${r.attempt_id}">${certLabel}</button>`;
            }
            const retry = (ex.status === 'open' && lv.quota && lv.quota.left > 0 && !lv.held && !lv.prev_required)
                ? `<button class="btn btn-primary bravo-challenge" data-exam-id="${at.exam_id}">다시 도전하기 (누적 ${at.used}/${at.limit}회 사용)</button>` : '';
            return `<p class="bravo-state bravo-result-fail">아쉽게 불합격 — 총점 ${parseFloat(r.total_score)} / 합격선 ${parseFloat(r.passing_score)}.</p>${retry}`;
        }
        if (lv.prev_required) {
            return `<p class="bravo-state">BRAVO ${parseInt(lv.level, 10) - 1} 등급 취득 후 도전할 수 있어요.</p>`;
        }
        if (at.submitted) {
            return '<p class="bravo-state">제출완료 — 결과 발표 대기</p>';
        }
        if (ex.status === 'open') {
            if (lv.quota && lv.quota.left <= 0) {
                return `<p class="bravo-state">누적 응시 ${at.used}/${at.limit}회 소진 — 추가 응시는 운영진에게 문의해주세요.</p>`;
            }
            return `<button class="btn btn-primary bravo-challenge" data-exam-id="${at.exam_id}">도전하기 (누적 ${at.used}/${at.limit}회 사용)</button>`;
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

        const cur = member ? parseInt(member.current_level, 10) || 0 : 0;
        const gradeHtml = cur >= 1 ? `
                    <div class="member-bravo-grade">
                        <span>내 등급: <strong>BRAVO ${cur}</strong></span>
                        <button class="btn btn-sm bravo-demote-btn" type="button">강등 신청</button>
                    </div>` : '';

        el.innerHTML = `
            <div class="member-bravo">
                <div class="member-bravo-head">
                    <h3 class="member-bravo-title">BRAVO 도전</h3>
                    <p class="member-bravo-sub">${sub}</p>
                    ${gradeHtml}
                </div>
                <div class="bravo-level-cards">${cards}</div>
                <p class="member-bravo-note">합격/불합격 결과는 응시 후 결과 발표일에 공개됩니다.</p>
                <dialog class="bravo-demote-dialog" id="bravo-demote-dialog">
                    <h4>등급 강등 신청</h4>
                    <p>BRAVO ${cur} → ${cur - 1 >= 1 ? 'BRAVO ' + (cur - 1) : '무등급'} 으로 내려갑니다.</p>
                    <p class="bravo-demote-warn">⚠️ 되돌리려면 시험에 다시 합격해야 합니다.<br>누적 응시 횟수는 초기화되지 않습니다.</p>
                    <div class="bravo-demote-actions">
                        <button class="btn btn-danger" id="bravo-demote-confirm" type="button">강등 확인</button>
                        <button class="btn" id="bravo-demote-cancel" type="button">취소</button>
                    </div>
                </dialog>
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

        const demoteBtn = el.querySelector('.bravo-demote-btn');
        if (demoteBtn) {
            const dlg = el.querySelector('#bravo-demote-dialog');
            demoteBtn.addEventListener('click', () => dlg.showModal());
            el.querySelector('#bravo-demote-cancel').addEventListener('click', () => dlg.close());
            el.querySelector('#bravo-demote-confirm').addEventListener('click', async (e) => {
                e.currentTarget.disabled = true;
                const r = await App.post('/api/member.php?action=bravo_demote', {});
                dlg.close();
                if (r && r.success !== false) {
                    Toast.success(r.message || '강등되었습니다.');
                    mount(el, member);
                } else {
                    e.currentTarget.disabled = false;
                }
            });
        }
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
