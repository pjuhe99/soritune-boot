/* ══════════════════════════════════════════════════════════════
   MemberBravoExam — BRAVO 응시 플로우 (OT→마이크테스트→응시→제출)
   진입: MemberBravoExam.open(el, examId, onExit)
   - el: BRAVO 탭 컨테이너 (플로우가 내용을 대체, 종료 시 onExit() 로 복귀)
   - 녹음: MediaRecorder (webm/opus → mp4 폴백), 문제 공개와 동시 자동 시작
   ══════════════════════════════════════════════════════════════ */
const MemberBravoExam = (() => {
    const DEFAULT_LIMIT_SEC = 60; // 문제별 제한시간 기본값 (limit/reference 합산 NULL 일 때)

    let el = null, onExit = null;
    let stream = null;          // OT 마이크테스트에서 획득, 플로우 종료 시 해제
    let mimeType = '';
    let recorder = null, chunks = [], recTimer = null, recStartedAt = 0;
    let st = null;              // { examId, intro, attemptId, questions, answered:Set, idx, retakeDone, blob, durationMs }

    function pickMime() {
        if (!window.MediaRecorder || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return '';
        const cands = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'];
        for (const m of cands) {
            try { if (MediaRecorder.isTypeSupported(m)) return m; } catch (e) { /* ignore */ }
        }
        return '';
    }

    function releaseStream() {
        if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
        if (recTimer) { clearInterval(recTimer); recTimer = null; }
        recorder = null;
    }

    function exit() {
        releaseStream();
        st = null;
        if (onExit) onExit();
    }

    // ── 진입: intro 로드 → OT 화면 ──
    async function open(container, examId, exitCb) {
        el = container; onExit = exitCb;
        el.innerHTML = '<div class="bravo-exam"><p>불러오는 중...</p></div>';
        const r = await App.get('/api/member.php?action=bravo_exam_intro', { exam_id: examId });
        if (!r || r.success === false) { exit(); return; }
        st = { examId, intro: r, attemptId: null, questions: [], answered: new Set(), idx: -1, retakeDone: false, blob: null, durationMs: 0 };
        renderOt();
    }

    // ── OT + 마이크테스트 화면 ──
    function renderOt() {
        const { exam, ot, question_count, attempts } = st.intro;
        const resuming = !!(attempts && attempts.in_progress);
        const otHtml = ot ? `
            ${ot.title ? `<h4>${App.esc(ot.title)}</h4>` : ''}
            ${ot.intro_text ? `<p class="bx-pre">${App.esc(ot.intro_text)}</p>` : ''}
            ${ot.video_url ? `<p><a href="${App.esc(ot.video_url)}" target="_blank" rel="noopener">OT 영상 보기</a></p>` : ''}
            ${[1, 2, 3].map(n => ot['type' + n + '_text'] ? `<div class="bx-type-guide"><strong>유형 ${n}</strong><p class="bx-pre">${App.esc(ot['type' + n + '_text'])}</p></div>` : '').join('')}
        ` : '<p>시험 안내가 곧 등록됩니다.</p>';
        const needCheck = !!(ot && parseInt(ot.require_check, 10) === 1) && !resuming;

        el.innerHTML = `
            <div class="bravo-exam">
                <h3>${App.esc(exam.title || 'BRAVO 시험')}</h3>
                <p class="bx-meta">BRAVO ${exam.bravo_level} · ${question_count}문항 · 응시 ${attempts.used}/${attempts.limit}회 사용</p>
                <div class="bx-ot">${otHtml}</div>
                <div class="bx-mic">
                    <h4>🎤 마이크 테스트 (필수)</h4>
                    <p>3초간 녹음 후 재생해 소리를 확인하세요. 통과해야 응시할 수 있습니다.</p>
                    <button class="btn" id="bx-mic-rec">3초 녹음</button>
                    <audio id="bx-mic-play" controls style="display:none"></audio>
                    <label id="bx-mic-ok-wrap" style="display:none"><input type="checkbox" id="bx-mic-ok"> 내 목소리가 잘 들립니다</label>
                    <p id="bx-mic-msg"></p>
                </div>
                ${needCheck ? '<label class="bx-check"><input type="checkbox" id="bx-ot-check"> 위 안내를 모두 확인했습니다 (필수)</label>' : ''}
                ${resuming
                    ? `<p class="bx-warn">진행 중인 응시가 있습니다. (답변 ${attempts.in_progress.answered}/${attempts.in_progress.total})</p>`
                    : '<p class="bx-warn">⚠️ 시작하면 응시 1회가 차감됩니다. 중도 이탈 시 시험 기간 내 이어하기가 가능합니다.</p>'}
                <div class="bx-actions">
                    <button class="btn btn-primary" id="bx-start" disabled>${resuming ? '이어서 응시' : '응시 시작'}</button>
                    <button class="btn" id="bx-back">돌아가기</button>
                </div>
            </div>`;

        const micBtn = el.querySelector('#bx-mic-rec');
        const micMsg = el.querySelector('#bx-mic-msg');
        const startBtn = el.querySelector('#bx-start');
        const otCheck = el.querySelector('#bx-ot-check');

        function refreshStart() {
            const micOk = el.querySelector('#bx-mic-ok');
            const ok = micOk && micOk.checked && (!otCheck || otCheck.checked);
            startBtn.disabled = !ok;
        }
        if (otCheck) otCheck.addEventListener('change', refreshStart);

        micBtn.addEventListener('click', async () => {
            mimeType = pickMime();
            if (!mimeType) { micMsg.textContent = '이 브라우저는 녹음을 지원하지 않습니다. 크롬/사파리 최신 버전을 사용해주세요.'; return; }
            try {
                if (!stream) stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (e) {
                micMsg.textContent = '마이크 권한이 거부되었습니다. 브라우저 설정에서 허용 후 다시 시도해주세요.';
                return;
            }
            micBtn.disabled = true; micMsg.textContent = '녹음 중... (3초)';
            const rec = new MediaRecorder(stream, { mimeType });
            const buf = [];
            rec.ondataavailable = e => { if (e.data && e.data.size) buf.push(e.data); };
            rec.onstop = () => {
                const blob = new Blob(buf, { type: mimeType });
                const player = el.querySelector('#bx-mic-play');
                player.src = URL.createObjectURL(blob);
                player.style.display = '';
                el.querySelector('#bx-mic-ok-wrap').style.display = '';
                el.querySelector('#bx-mic-ok').addEventListener('change', refreshStart);
                micMsg.textContent = '재생해서 확인 후 체크해주세요.';
                micBtn.disabled = false; micBtn.textContent = '다시 녹음';
            };
            rec.start();
            setTimeout(() => { if (rec.state !== 'inactive') rec.stop(); }, 3000);
        });

        startBtn.addEventListener('click', async () => {
            startBtn.disabled = true;
            const r = await App.post('/api/member.php?action=bravo_attempt_start',
                { exam_id: st.examId, ot_checked: otCheck ? (otCheck.checked ? 1 : 0) : 0 });
            if (!r || r.success === false) { startBtn.disabled = false; return; }
            st.attemptId = r.attempt_id;
            st.questions = r.questions || [];
            st.answered = new Set((r.answered_ids || []).map(Number));
            st.idx = -1;
            renderInterstitial();
        });
        el.querySelector('#bx-back').addEventListener('click', exit);
    }

    // ── 다음 미답변 문제 인덱스 (없으면 -1) ──
    function nextIdx() {
        for (let i = 0; i < st.questions.length; i++) {
            if (!st.answered.has(parseInt(st.questions[i].id, 10))) return i;
        }
        return -1;
    }

    // ── 문제 사이 대기 화면 ("다음 문제" → 공개+자동녹음) ──
    function renderInterstitial() {
        const ni = nextIdx();
        if (ni === -1) { renderSubmit(); return; }
        const total = st.questions.length;
        el.innerHTML = `
            <div class="bravo-exam">
                <p class="bx-progress">진행 ${st.answered.size} / ${total}</p>
                <p>다음 문제를 누르면 <strong>문제 공개와 동시에 녹음이 시작</strong>됩니다.<br>준비되면 눌러주세요.</p>
                <div class="bx-actions"><button class="btn btn-primary" id="bx-next">다음 문제</button></div>
            </div>`;
        el.querySelector('#bx-next').addEventListener('click', () => { st.idx = ni; st.retakeDone = false; renderQuestion(); });
    }

    function typeLabel(t) {
        return { 1: '유형1 · 청크 스피드', 2: '유형2 · 문장변형', 3: '유형3 · 한문장' }[parseInt(t, 10)] || '유형 ' + t;
    }

    function limitSecOf(q) {
        const a = parseFloat(q.response_time_limit_sec), b = parseFloat(q.reference_speech_sec);
        const sum = (isNaN(a) ? 0 : a) + (isNaN(b) ? 0 : b);
        return sum > 0 ? Math.ceil(sum) : DEFAULT_LIMIT_SEC;
    }

    // ── 문제 화면: 공개와 동시 자동 녹음 ──
    function renderQuestion() {
        const q = st.questions[st.idx];
        const limitSec = limitSecOf(q);
        el.innerHTML = `
            <div class="bravo-exam">
                <p class="bx-progress">문제 ${st.idx + 1} / ${st.questions.length} · ${App.esc(typeLabel(q.question_type))}</p>
                <div class="bx-question">
                    <p class="bx-korean">${App.esc(q.korean_text || '')}</p>
                    ${q.target_chunks ? `<p class="bx-chunks">청크: ${App.esc(q.target_chunks)}</p>` : ''}
                </div>
                <p class="bx-rec"><span class="bx-rec-dot">●</span> 녹음 중 <span id="bx-elapsed">0</span>s / 최대 ${limitSec}s</p>
                <div class="bx-actions"><button class="btn btn-primary" id="bx-done">말하기 완료</button></div>
            </div>`;
        startRecording(limitSec, () => stopAndUpload());
        el.querySelector('#bx-done').addEventListener('click', () => stopAndUpload());
    }

    function startRecording(limitSec, onLimit) {
        chunks = [];
        recorder = new MediaRecorder(stream, { mimeType });
        recorder.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
        recStartedAt = Date.now();
        recorder.start();
        const elapsedEl = el.querySelector('#bx-elapsed');
        recTimer = setInterval(() => {
            const sec = Math.floor((Date.now() - recStartedAt) / 1000);
            if (elapsedEl) elapsedEl.textContent = sec;
            if (sec >= limitSec) { clearInterval(recTimer); recTimer = null; onLimit(); }
        }, 250);
    }

    function stopAndUpload() {
        if (!recorder || recorder.state === 'inactive') return;
        if (recTimer) { clearInterval(recTimer); recTimer = null; }
        st.durationMs = Date.now() - recStartedAt;
        recorder.onstop = () => {
            st.blob = new Blob(chunks, { type: mimeType });
            uploadCurrent();
        };
        recorder.stop();
    }

    async function uploadCurrent(isManualRetry) {
        const q = st.questions[st.idx];
        el.innerHTML = '<div class="bravo-exam"><p>답안 업로드 중...</p></div>';
        let r = await postAnswer(q);
        if ((!r || r.success === false) && !isManualRetry) r = await postAnswer(q); // 자동 재시도 1회
        if (!r || r.success === false) { renderUploadRetry(); return; }
        st.answered.add(parseInt(q.id, 10));
        renderAfterUpload(r);
    }

    function postAnswer(q) {
        const fd = new FormData();
        fd.append('attempt_id', st.attemptId);
        fd.append('question_id', q.id);
        fd.append('duration_ms', st.durationMs);
        const ext = mimeType.indexOf('mp4') !== -1 ? 'm4a' : 'webm';
        fd.append('audio', st.blob, 'answer.' + ext);
        return App.post('/api/member.php?action=bravo_answer_save', fd);
    }

    function renderUploadRetry() {
        el.innerHTML = `
            <div class="bravo-exam">
                <p class="bx-warn">업로드에 실패했습니다. 네트워크 확인 후 다시 시도해주세요.<br>(녹음은 보관 중 — 화면을 떠나면 사라집니다)</p>
                <div class="bx-actions"><button class="btn btn-primary" id="bx-retry">재전송</button></div>
            </div>`;
        el.querySelector('#bx-retry').addEventListener('click', () => uploadCurrent(true));
    }

    // ── 업로드 성공 후: 재녹음 1회 or 다음 ──
    function renderAfterUpload(r) {
        const canRetake = !st.retakeDone;
        el.innerHTML = `
            <div class="bravo-exam">
                <p class="bx-progress">진행 ${st.answered.size} / ${st.questions.length}</p>
                <p>답안이 저장되었습니다.</p>
                <div class="bx-actions">
                    ${canRetake ? '<button class="btn" id="bx-retake">재녹음 (1회)</button>' : ''}
                    <button class="btn btn-primary" id="bx-go-next">${r.all_answered ? '제출 단계로' : '다음 문제'}</button>
                </div>
            </div>`;
        const rt = el.querySelector('#bx-retake');
        if (rt) rt.addEventListener('click', () => { st.retakeDone = true; renderQuestion(); });
        el.querySelector('#bx-go-next').addEventListener('click', () => renderInterstitial());
    }

    // ── 제출 화면 ──
    function renderSubmit() {
        const rel = st.intro.exam.result_release_at;
        el.innerHTML = `
            <div class="bravo-exam">
                <p>전체 ${st.questions.length}문항 답변 완료 ✅</p>
                <div class="bx-actions"><button class="btn btn-primary" id="bx-submit">최종 제출</button></div>
            </div>`;
        el.querySelector('#bx-submit').addEventListener('click', async () => {
            const btn = el.querySelector('#bx-submit');
            btn.disabled = true;
            const r = await App.post('/api/member.php?action=bravo_attempt_submit', { attempt_id: st.attemptId });
            if (!r || r.success === false) { btn.disabled = false; return; }
            releaseStream();
            el.innerHTML = `
                <div class="bravo-exam">
                    <h3>제출되었습니다 🎉</h3>
                    <p>결과는 발표일에 공개됩니다.${rel ? ' (발표: ' + App.esc(String(rel).slice(0, 10)) + ')' : ''}</p>
                    <div class="bx-actions"><button class="btn" id="bx-finish">확인</button></div>
                </div>`;
            el.querySelector('#bx-finish').addEventListener('click', exit);
        });
    }

    // ── 카드의 [제출 마무리] 전용 (응시 화면 없이 submit 만) ──
    async function finalize(attemptId, exitCb) {
        const r = await App.post('/api/member.php?action=bravo_attempt_submit', { attempt_id: attemptId });
        if (r && r.success !== false) Toast.success('제출되었습니다.');
        if (exitCb) exitCb();
    }

    return { open, finalize };
})();
