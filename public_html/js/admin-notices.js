/* ══════════════════════════════════════════════════════════════
   AdminNotices — 운영용 공지 관리 탭
   목록 (노출/숨김 모두) + 작성/수정 모달 + 토글/삭제
   ══════════════════════════════════════════════════════════════ */
const AdminNotices = (() => {
    const API = '/api/admin.php?action=';

    let container = null;
    let admin = null;
    let notices = [];

    function renderMarkdown(text) {
        if (typeof marked !== 'undefined') {
            return marked.parse(text || '', { breaks: true });
        }
        return App.esc(text || '').replace(/\n/g, '<br>');
    }

    function fmtDate(s) {
        if (!s) return '';
        return s.slice(0, 16).replace('T', ' '); // 'YYYY-MM-DD HH:MM'
    }

    async function init(containerEl, adminObj) {
        container = containerEl;
        admin = adminObj;
        container.innerHTML = `
            <div class="notices-admin">
                <div class="notices-toolbar">
                    <button class="btn btn-primary btn-sm" id="notice-new-btn">+ 새 공지</button>
                </div>
                <div class="notices-list" id="notices-list"></div>
            </div>
        `;
        document.getElementById('notice-new-btn').onclick = () => openForm(null);
        await load();
    }

    async function load() {
        const listEl = document.getElementById('notices-list');
        if (!listEl) return;
        listEl.innerHTML = '<div class="empty-state" style="padding:16px">로딩 중...</div>';
        const r = await App.get(API + 'notice_list');
        if (!r.success) {
            listEl.innerHTML = '<div class="empty-state" style="padding:16px;text-align:center">불러오기 실패</div>';
            return;
        }
        notices = r.notices || [];
        render();
    }

    function render() {
        const listEl = document.getElementById('notices-list');
        if (!listEl) return;
        if (notices.length === 0) {
            listEl.innerHTML = '<div class="empty-state" style="padding:24px;text-align:center">등록된 공지가 없습니다.</div>';
            return;
        }
        listEl.innerHTML = notices.map(n => {
            const chipCls = n.is_visible == 1 ? 'notice-chip notice-chip--on' : 'notice-chip notice-chip--off';
            const chipText = n.is_visible == 1 ? '노출중' : '숨김';
            const toggleLabel = n.is_visible == 1 ? '숨기기' : '보이기';
            return `
                <div class="notice-card" data-id="${n.id}">
                    <div class="notice-card-head">
                        <span class="${chipCls}">${chipText}</span>
                        <span class="notice-card-title">${App.esc(n.title)}</span>
                    </div>
                    <div class="notice-card-meta">
                        ${App.esc(n.created_by_admin_name)} · ${App.esc(fmtDate(n.created_at))}
                    </div>
                    <div class="notice-card-body markdown-body">${renderMarkdown(n.body_markdown)}</div>
                    <div class="notice-card-actions">
                        <button class="btn btn-secondary btn-sm" data-act="edit" data-id="${n.id}">수정</button>
                        <button class="btn btn-secondary btn-sm" data-act="toggle" data-id="${n.id}">${toggleLabel}</button>
                        <button class="btn btn-danger btn-sm" data-act="delete" data-id="${n.id}">삭제</button>
                    </div>
                </div>
            `;
        }).join('');

        listEl.querySelectorAll('button[data-act]').forEach(btn => {
            btn.onclick = () => onAction(btn.dataset.act, parseInt(btn.dataset.id));
        });
    }

    async function onAction(act, id) {
        const notice = notices.find(n => parseInt(n.id) === id);
        if (!notice) return;
        if (act === 'edit') return openForm(notice);
        if (act === 'toggle') {
            const next = notice.is_visible == 1 ? 0 : 1;
            const r = await App.post(API + 'notice_toggle_visible', { id, is_visible: next });
            if (r.success) { Toast.success(next ? '노출 처리' : '숨김 처리'); await load(); }
            return;
        }
        if (act === 'delete') {
            const ok = await App.confirm('이 공지를 영구 삭제할까요?\n복구할 수 없습니다.');
            if (!ok) return;
            const r = await App.post(API + 'notice_delete', { id });
            if (r.success) { Toast.success('삭제됨'); await load(); }
            return;
        }
    }

    function openForm(notice) {
        const isEdit = !!notice;
        const title  = notice ? notice.title : '';
        const body   = notice ? notice.body_markdown : '';
        const isVis  = notice ? notice.is_visible : 1;

        const bodyHtml = `
            <div class="form-group">
                <label class="form-label">제목</label>
                <input type="text" class="form-input" id="notice-title"
                       maxlength="255" value="${App.esc(title)}">
            </div>
            <div class="form-group">
                <label class="form-label">본문 (마크다운)</label>
                <textarea class="form-textarea" id="notice-body" rows="8"
                          style="resize:vertical">${App.esc(body)}</textarea>
            </div>
            <div class="form-group">
                <button type="button" class="btn btn-secondary btn-sm" id="notice-preview-btn">👁 미리보기</button>
                <div class="markdown-body" id="notice-preview"
                     style="display:none;margin-top:8px;padding:12px;border:1px solid var(--gray-200,#ddd);border-radius:6px;background:#fafafa"></div>
            </div>
            ${isEdit ? '' : `
                <div class="form-group">
                    <label><input type="checkbox" id="notice-visible" ${isVis ? 'checked' : ''}> 즉시 노출</label>
                </div>
            `}
        `;
        const footer = `
            <button class="btn btn-secondary btn-sm" onclick="App.closeModal()">취소</button>
            <button class="btn btn-primary btn-sm" id="notice-save-btn">${isEdit ? '저장' : '등록'}</button>
        `;
        App.openModal(isEdit ? '공지 수정' : '새 공지', bodyHtml, footer);

        document.getElementById('notice-preview-btn').onclick = () => {
            const prev = document.getElementById('notice-preview');
            const txt  = document.getElementById('notice-body').value;
            prev.innerHTML = renderMarkdown(txt);
            prev.style.display = prev.style.display === 'none' ? 'block' : 'none';
        };

        document.getElementById('notice-save-btn').onclick = async () => {
            const t = document.getElementById('notice-title').value.trim();
            const b = document.getElementById('notice-body').value.trim();
            if (!t) { Toast.warning('제목을 입력해주세요.'); return; }
            if (!b) { Toast.warning('본문을 입력해주세요.'); return; }

            if (isEdit) {
                const r = await App.post(API + 'notice_update', {
                    id: notice.id, title: t, body_markdown: b,
                });
                if (!r.success) return;
                Toast.success('저장됨');
            } else {
                const v = document.getElementById('notice-visible').checked ? 1 : 0;
                const r = await App.post(API + 'notice_create', {
                    title: t, body_markdown: b, is_visible: v,
                });
                if (!r.success) return;
                Toast.success('등록됨');
            }
            App.closeModal();
            await load();
        };
    }

    return { init };
})();
