# BRAVO UI 스타일링 패스 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** slice1~8 BRAVO 전 화면(~80 bare 클래스)에 boot 디자인 토큰 기반 CSS 부여 + GD 인증서 코드 레벨 폴리시.

**Architecture:** 신규 CSS 2파일(`css/bravo.css` 회원 / `css/admin-bravo.css` 관리자) + 진입 페이지 `<link>` 2줄. 토큰 변수만 사용, 기존 CSS·JS 무수정(인증서 버튼 앰버, 판정 토글, 확정 success 전부 CSS override 로 해결). 인증서는 `bravoCertificateRender` 내부만 수정 — 프레임 장식=무배경 전용, 텍스트 폴리시=항상 적용.

**Tech Stack:** CSS (디자인 토큰 var), PHP GD (인증서). 테스트 = 기존 BRAVO 19파일 회귀 + 클래스 커버리지 grep + 브레이스 밸런스.

**스펙:** `docs/superpowers/specs/2026-06-05-bravo-styling-pass-design.md`
**작업 디렉토리:** `/root/boot-dev` (dev 브랜치). ⛔ git push 금지(컨트롤러가 마지막에), PROD 접근 금지, main 체크아웃 금지, `git add .` 금지.
**참조 파일 (수정 금지, 읽기만):** `css/design-tokens.css`(토큰), `css/components.css`(.btn/.card 문법), `css/admin.css:601-603`(.multipass-subtab 서브탭 선례), JS 6파일(마크업 — 클래스 진실원).

**알려진 footgun:** base 룰은 반드시 `@media` **위에** (last-rule-wins cascade — PT 2026-05-20 사고). `v()` mtime 캐시버스터라 CSS 수정 시 자동 갱신.

---

### Task 1: 회원 CSS (`css/bravo.css`) + index.php link

**Files:**
- Create: `public_html/css/bravo.css`
- Modify: `public_html/index.php:25` (member-notices.css 링크 다음 줄에 추가)

- [ ] **Step 1: CSS 파일 작성**

`public_html/css/bravo.css` 전체 내용:

```css
/* ══════════════════════════════════════════════════════════════
   boot — BRAVO 회원 화면 (도전 탭 + 응시 플로우)
   slice1~8 스타일링 패스. design-tokens 변수만 사용.
   ⚠️ base 룰은 @media 위에 (cascade 순서).
   ══════════════════════════════════════════════════════════════ */

/* ── 탭 컨테이너 ── */
.member-bravo { padding: var(--space-4); }
.member-bravo-head { margin-bottom: var(--space-4); }
.member-bravo-title { margin: 0 0 var(--space-1); font-size: var(--text-xl); font-weight: var(--font-bold); color: var(--color-text); }
.member-bravo-sub { margin: 0; font-size: var(--text-base); color: var(--color-text-sub); }
.member-bravo-note { margin-top: var(--space-4); font-size: var(--text-sm); color: var(--color-text-muted); }
.member-bravo-loading,
.member-bravo-error { padding: var(--space-8) 0; text-align: center; color: var(--color-text-sub); }
.member-bravo-error { color: var(--color-danger-600); }

/* ── 등급 카드 ── */
.bravo-level-cards { display: grid; grid-template-columns: 1fr; gap: var(--space-4); }
.bravo-level-card {
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-left: 4px solid var(--color-border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    padding: var(--space-4) var(--space-5);
}
.bravo-level-card.is-eligible { border-left-color: var(--color-primary); }
.bravo-level-head { display: flex; align-items: center; justify-content: space-between; gap: var(--space-2); margin-bottom: var(--space-2); }
.bravo-level-name { font-size: var(--text-lg); font-weight: var(--font-bold); color: var(--color-text); }
.bravo-level-card:not(.is-eligible) .bravo-level-name { color: var(--color-text-sub); }
.bravo-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: var(--radius-full); font-size: var(--text-sm); font-weight: var(--font-semibold); white-space: nowrap; }
.bravo-badge.eligible { background: var(--color-success-50); color: var(--color-success-600); }
.bravo-badge.ineligible { background: var(--color-bg-subtle); color: var(--color-text-muted); }
.bravo-level-detail { font-size: var(--text-base); color: var(--color-text-sub); line-height: var(--leading-normal); }
.bravo-level-action { margin-top: var(--space-3); }
.bravo-level-action .btn { min-height: 44px; }

/* ── 카드 상태/결과 ── */
.bravo-state { margin: 0; font-size: var(--text-base); color: var(--color-text-sub); }
.bravo-result-pass {
    padding: var(--space-3) var(--space-4);
    background: var(--color-success-50);
    border: 1px solid var(--color-success-100);
    border-radius: var(--radius-md);
    color: var(--color-success-600);
    font-weight: var(--font-semibold);
    line-height: var(--leading-normal);
}
.bravo-result-fail {
    padding: var(--space-3) var(--space-4);
    background: var(--color-bg-subtle);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    color: var(--color-text-sub);
    line-height: var(--leading-normal);
}
/* 인증서 버튼 — 성취의 앰버. 마크업이 btn-primary 라 CSS 로 override (JS 무수정) */
.btn.bravo-cert { margin-top: var(--space-2); background: var(--color-accent-500); }
.btn.bravo-cert:hover { background: var(--color-accent-600); color: #fff; }

/* ── 응시 플로우 (모바일 우선) ── */
.bravo-exam {
    max-width: 560px;
    margin: 0 auto;
    padding: var(--space-4);
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
}
.bravo-exam h3 { margin: 0 0 var(--space-1); font-size: var(--text-xl); }
.bravo-exam h4 { margin: var(--space-3) 0 var(--space-2); font-size: var(--text-md); }
.bx-meta { margin: 0 0 var(--space-4); font-size: var(--text-base); color: var(--color-text-sub); }
.bx-ot { padding: var(--space-4); background: var(--color-bg-page); border-radius: var(--radius-md); font-size: var(--text-base); line-height: var(--leading-relaxed); }
.bx-ot h4 { margin-top: 0; }
.bx-pre { white-space: pre-wrap; margin: var(--space-2) 0; }
.bx-type-guide { margin: var(--space-3) 0; padding-left: var(--space-3); border-left: 3px solid var(--color-primary-200); }
.bx-type-guide p { margin: var(--space-1) 0 0; }
.bx-mic { margin-top: var(--space-4); padding: var(--space-4); border: 2px dashed var(--color-primary-300); border-radius: var(--radius-md); background: var(--color-primary-50); }
.bx-mic h4 { margin-top: 0; }
.bx-mic p { margin: var(--space-1) 0; font-size: var(--text-base); }
.bx-mic audio { display: block; width: 100%; margin: var(--space-2) 0; }
.bx-mic label { display: block; margin-top: var(--space-2); font-weight: var(--font-semibold); }
.bx-check { display: block; margin-top: var(--space-4); font-weight: var(--font-semibold); }
.bx-warn {
    margin: var(--space-4) 0 0;
    padding: var(--space-3) var(--space-4);
    background: var(--color-warning-50);
    border-left: 3px solid var(--color-warning-500);
    border-radius: var(--radius-sm);
    font-size: var(--text-base);
    line-height: var(--leading-normal);
}
.bx-actions { display: flex; flex-direction: column; gap: var(--space-2); margin-top: var(--space-5); }
.bx-actions .btn { min-height: 48px; font-size: var(--text-md); }
.bx-progress {
    display: inline-block;
    margin: 0 0 var(--space-3);
    padding: 2px 12px;
    background: var(--color-primary-50);
    color: var(--color-primary-700);
    border-radius: var(--radius-full);
    font-size: var(--text-sm);
    font-weight: var(--font-semibold);
}
.bx-question { margin: var(--space-4) 0; padding: var(--space-6) var(--space-4); background: var(--color-bg-page); border-radius: var(--radius-md); text-align: center; }
.bx-korean { margin: 0; font-size: 22px; font-weight: var(--font-bold); color: var(--color-text); line-height: var(--leading-normal); word-break: keep-all; }
.bx-chunks { margin: var(--space-3) 0 0; font-size: var(--text-base); color: var(--color-text-sub); }
.bx-rec { display: flex; align-items: center; gap: var(--space-2); margin: var(--space-2) 0 0; font-size: var(--text-md); font-weight: var(--font-semibold); color: var(--color-danger-600); }
.bx-rec-dot { animation: bravo-rec-pulse 1s ease-in-out infinite; }
@keyframes bravo-rec-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.25; }
}

/* ── PC (base 룰 뒤, 파일 마지막) ── */
@media (min-width: 768px) {
    .bravo-level-cards { grid-template-columns: repeat(3, 1fr); }
    .bravo-exam { padding: var(--space-6); }
    .bx-korean { font-size: 28px; }
    .bx-actions { flex-direction: row; }
    .bx-actions .btn { flex: 1; }
}
```

- [ ] **Step 2: index.php 에 link 추가**

`public_html/index.php` 의 `member-notices.css` 링크 줄(현재 25행) 바로 다음에:

```php
    <link rel="stylesheet" href="/css/bravo.css<?= v('/css/bravo.css') ?>">
```

- [ ] **Step 3: 검증 — 문법·커버리지·서빙**

```bash
cd /root/boot-dev/public_html
php -l index.php
php -r '$c=file_get_contents("css/bravo.css"); exit(substr_count($c,"{")===substr_count($c,"}")?0:1);' && echo "braces OK"
# 회원 JS 클래스 커버리지 (전부 selector 존재해야 함)
for cls in member-bravo member-bravo-head member-bravo-title member-bravo-sub member-bravo-note member-bravo-loading member-bravo-error bravo-level-cards bravo-level-card bravo-level-head bravo-level-name bravo-badge bravo-level-detail bravo-level-action bravo-state bravo-result-pass bravo-result-fail bravo-cert bravo-exam bx-meta bx-ot bx-pre bx-type-guide bx-mic bx-check bx-warn bx-actions bx-progress bx-question bx-korean bx-chunks bx-rec bx-rec-dot; do
  grep -q "\.$cls" css/bravo.css || echo "MISSING .$cls"
done; echo "coverage done"
curl -s https://dev-boot.soritune.com/ | grep -c 'css/bravo.css'
```
Expected: `No syntax errors` / `braces OK` / MISSING 출력 0건 / curl `1`
(참고: `.bravo-challenge`/`.bravo-finalize` 는 `.btn btn-primary` 공통 스타일로 충분 — 전용 룰 불필요, 커버리지 목록에서 의도적 제외.)

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev
git add public_html/css/bravo.css public_html/index.php
git commit -m "style(bravo): 회원 BRAVO 탭·응시 플로우 CSS (모바일 우선, 토큰 기반)"
```

---

### Task 2: 관리자 CSS 1/2 — 셸·자격·시험·문제은행·OT·배정 (`css/admin-bravo.css`) + link

**Files:**
- Create: `public_html/css/admin-bravo.css`
- Modify: `public_html/operation/index.php:23` (retention.css 링크 다음 줄에 추가)

- [ ] **Step 1: CSS 파일 작성**

`public_html/css/admin-bravo.css` 전체 내용 (채점 섹션은 Task 3 에서 append):

```css
/* ══════════════════════════════════════════════════════════════
   boot — BRAVO 관리자 (operation): 서브탭/자격/시험/문제은행/OT/배정/채점
   slice1~8 스타일링 패스. design-tokens 변수만 사용.
   ⚠️ base 룰은 @media 위에 (cascade 순서).
   ══════════════════════════════════════════════════════════════ */

/* ── 서브탭 (admin.css .multipass-subtab 선례와 동일 문법, 토큰화) ── */
.bravo-subtabs { display: flex; gap: var(--space-1); margin-bottom: var(--space-4); flex-wrap: wrap; }
.bravo-subtab {
    padding: 6px 14px;
    border: 1px solid var(--color-gray-300);
    background: var(--color-bg);
    cursor: pointer;
    border-radius: var(--radius-sm);
    font-size: var(--text-base);
    color: var(--color-text-sub);
    transition: all var(--transition-fast);
}
.bravo-subtab:hover { border-color: var(--color-primary-300); color: var(--color-primary-600); }
.bravo-subtab.active { background: var(--color-primary-600); color: #fff; border-color: var(--color-primary-600); font-weight: var(--font-semibold); }

/* ── 자격 (qual) ── */
.bravo-help { margin: 0 0 var(--space-3); padding: var(--space-2) var(--space-3); background: var(--color-info-50); border-radius: var(--radius-sm); font-size: var(--text-sm); color: var(--color-text-sub); }
.bravo-table .num { text-align: right; }
.bravo-table .bravo-override { text-align: right; }
.bravo-table .bravo-notes { width: 100%; min-width: 120px; box-sizing: border-box; }
.bravo-grant { display: inline-flex; align-items: center; gap: 2px; margin-right: var(--space-2); font-size: var(--text-sm); white-space: nowrap; cursor: pointer; }
.bravo-chip { display: inline-block; padding: 1px 8px; border-radius: var(--radius-full); font-size: var(--text-xs); font-weight: var(--font-semibold); white-space: nowrap; }
.bravo-chip.none { background: var(--color-bg-subtle); color: var(--color-text-muted); }
.bravo-chip.lv1 { background: var(--color-primary-50); color: var(--color-primary-600); }
.bravo-chip.lv2 { background: var(--color-primary-100); color: var(--color-primary-700); }
.bravo-chip.lv3 { background: var(--color-accent-100); color: var(--color-accent-600); }

/* ── 시험 관리 ── */
.bravo-exam-toolbar { display: flex; justify-content: flex-end; margin-bottom: var(--space-3); }
.bravo-exam-fields {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2) var(--space-3);
    align-items: center;
    margin: var(--space-3) 0;
    padding: var(--space-4);
    background: var(--color-bg-page);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    font-size: var(--text-base);
}
.bravo-exam-fields label { display: inline-flex; align-items: center; gap: var(--space-1); white-space: nowrap; }
.bx-dates { display: inline-flex; flex-wrap: wrap; gap: var(--space-2) var(--space-3); padding: var(--space-1) var(--space-2); border-left: 3px solid var(--color-primary-200); }

/* ── 폼 공통 (문제은행 + OT) — 2열 그리드, wide 풀폭 ── */
.bravo-q-form,
.bravo-ot-form {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-2) var(--space-4);
    margin: var(--space-3) 0;
    padding: var(--space-4);
    background: var(--color-bg-page);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    font-size: var(--text-base);
}
.bravo-q-form h4,
.bravo-ot-form h4 { grid-column: 1 / -1; margin: 0; }
.bravo-q-form label,
.bravo-ot-form label { display: flex; flex-direction: column; gap: var(--space-1); }
.bravo-q-form .bq-wide,
.bravo-ot-form .ot-wide { grid-column: 1 / -1; }
.bravo-q-form textarea,
.bravo-ot-form textarea,
.bravo-q-form input[type="text"],
.bravo-ot-form input[type="text"] { width: 100%; box-sizing: border-box; }
.bravo-q-form > div,
.bravo-ot-form > div { grid-column: 1 / -1; display: flex; gap: var(--space-2); }
/* 체크박스 라벨은 가로 정렬 유지 */
.bravo-ot-form label:has(> input[type="checkbox"]),
.bravo-q-form label:has(> input[type="checkbox"]) { flex-direction: row; align-items: center; }

/* ── 문제은행 필터 ── */
.bravo-q-filters { display: flex; flex-wrap: wrap; gap: var(--space-2); margin-bottom: var(--space-3); align-items: center; }
.bravo-q-filters #bq-new { margin-left: auto; }

/* ── 문제 배정 패널 ── */
.bravo-eq-panel { margin: var(--space-3) 0; padding: var(--space-4); background: var(--color-bg-page); border: 1px solid var(--color-border); border-radius: var(--radius-md); font-size: var(--text-base); }
.bravo-eq-panel h4 { margin: 0 0 var(--space-2); }
.eq-note { display: block; margin: var(--space-1) 0 var(--space-2); color: var(--color-text-muted); }
.eq-summary { margin-bottom: var(--space-2); padding: var(--space-2) var(--space-3); background: var(--color-accent-50); border-radius: var(--radius-sm); font-weight: var(--font-semibold); color: var(--color-accent-600); }
.eq-list { max-height: 320px; overflow-y: auto; border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-bg); margin-bottom: var(--space-3); }
.bravo-eq-row { display: block; padding: var(--space-2) var(--space-3); border-bottom: 1px solid var(--color-gray-100); cursor: pointer; }
.bravo-eq-row:last-child { border-bottom: none; }
.bravo-eq-row:hover { background: var(--color-primary-50); }
```

- [ ] **Step 2: operation/index.php 에 link 추가**

`public_html/operation/index.php` 의 `retention.css` 링크 줄(현재 23행) 바로 다음에:

```php
    <link rel="stylesheet" href="/css/admin-bravo.css<?= v('/css/admin-bravo.css') ?>">
```

- [ ] **Step 3: 검증**

```bash
cd /root/boot-dev/public_html
php -l operation/index.php
php -r '$c=file_get_contents("css/admin-bravo.css"); exit(substr_count($c,"{")===substr_count($c,"}")?0:1);' && echo "braces OK"
for cls in bravo-subtabs bravo-subtab bravo-help bravo-table bravo-override bravo-notes bravo-grant bravo-chip bravo-exam-toolbar bravo-exam-fields bx-dates bravo-q-form bravo-ot-form bq-wide ot-wide bravo-q-filters bravo-eq-panel eq-note eq-summary eq-list bravo-eq-row; do
  grep -q "\.$cls" css/admin-bravo.css || echo "MISSING .$cls"
done; echo "coverage done"
curl -s https://dev-boot.soritune.com/operation/ | grep -c 'css/admin-bravo.css'
```
Expected: 전부 통과, MISSING 0건, curl `1`
(참고: `.bravo-admin`/`.bravo-q`/`.bravo-exam-admin`/`.bravo-q-table` 컨테이너와 `.bq-edit` 등 버튼 클래스는 `.data-table`/`.btn` 공통 스타일로 충분 — 의도적 제외. `:has()` 는 2023+ 전 브라우저 지원 — 관리자는 최신 브라우저 전제, 미지원 시에도 세로 라벨로 동작만 함(기능 무영향).)

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev
git add public_html/css/admin-bravo.css public_html/operation/index.php
git commit -m "style(bravo): 관리자 서브탭·자격·시험·문제은행·OT·배정 CSS"
```

---

### Task 3: 관리자 CSS 2/2 — 채점 패널 (admin-bravo.css 에 append)

**Files:**
- Modify: `public_html/css/admin-bravo.css` (파일 끝에 append — 단 `@media` 블록이 이미 있으면 그 **위에**)

**핵심 제약 (스펙 §4-4):** 판정 인라인 갱신(오디오 재생 보존) 위에 얹으므로 **"재렌더 없이 동일 행 공간 유지"** — 고정 `height` 금지(모바일 Safari native audio UI 높이 편차로 clipping), `min-height` 공간 예약 + 점수 tabular-nums. 판정 선택 상태는 JS 가 `.on`+`.btn-primary` 를 토글(`admin-bravo-grading.js:205-206`) — CSS 는 기본 outline 만 정의하면 색은 기존 `.btn-primary` 가 담당.

- [ ] **Step 1: 채점 섹션 append**

`public_html/css/admin-bravo.css` 파일 끝에 추가:

```css
/* ── 채점 ── */
.grading-toolbar { margin-bottom: var(--space-3); }
.grading-toolbar select { max-width: 100%; padding: var(--space-2); font-size: var(--text-base); border: 1px solid var(--color-border); border-radius: var(--radius-sm); }
.grading-chip { display: inline-block; padding: 1px 8px; border-radius: var(--radius-full); font-size: var(--text-xs); font-weight: var(--font-semibold); white-space: nowrap; }
.grading-chip.done { background: var(--color-success-50); color: var(--color-success-600); }
.grading-chip.ing { background: var(--color-warning-50); color: var(--color-warning-500); }
.grading-chip.none { background: var(--color-bg-subtle); color: var(--color-text-muted); }

.grading-panel { margin-top: var(--space-4); padding: var(--space-4); background: var(--color-bg-page); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.grading-panel h4 { margin: 0 0 var(--space-2); }
.grading-progress {
    margin: 0 0 var(--space-3);
    padding: var(--space-2) var(--space-3);
    background: var(--color-accent-50);
    border-radius: var(--radius-sm);
    font-weight: var(--font-semibold);
    color: var(--color-accent-600);
    font-variant-numeric: tabular-nums;
}

/* 문항 카드 — 재렌더 없이 동일 행 공간 유지 (고정 height 금지, min-height 예약) */
.grading-card { margin-bottom: var(--space-3); padding: var(--space-3) var(--space-4); background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-md); }
.grading-q { font-size: var(--text-base); line-height: var(--leading-normal); }
.grading-answer-key { display: flex; flex-direction: column; gap: 2px; margin-top: var(--space-2); padding: var(--space-2) var(--space-3); background: var(--color-info-50); border-radius: var(--radius-sm); font-size: var(--text-sm); color: var(--color-text-sub); }
.grading-audio { display: flex; align-items: center; gap: var(--space-2); min-height: 56px; margin: var(--space-2) 0; }
.grading-audio audio { flex: 1; min-width: 0; max-width: 420px; }
.grading-audio-missing { color: var(--color-danger-600); }

/* 판정 — 선택 상태는 JS 가 .on + .btn-primary 토글, 여기선 기본 outline 만 */
.grading-judges { display: flex; flex-direction: column; gap: var(--space-1); margin: 0 0 var(--space-2); padding: 0; border: none; }
.grading-judges > div { display: flex; align-items: center; gap: var(--space-1); flex-wrap: wrap; min-height: 32px; font-size: var(--text-sm); color: var(--color-text-sub); }
.grading-judges .grading-judge { border: 1px solid var(--color-gray-300); background: var(--color-bg); color: var(--color-text-sub); }
.grading-judges .grading-judge.on { font-weight: var(--font-bold); }
.grading-judges[disabled] { opacity: 0.65; }

.grading-score { min-height: 24px; margin: var(--space-1) 0; font-size: var(--text-md); color: var(--color-accent-600); font-variant-numeric: tabular-nums; }
.grading-score strong { font-size: var(--text-lg); }
.grading-memo { width: 100%; box-sizing: border-box; }

.grading-confirm { display: flex; flex-wrap: wrap; align-items: center; gap: var(--space-2); margin-top: var(--space-4); padding-top: var(--space-3); border-top: 1px solid var(--color-border); font-size: var(--text-base); }
.grading-confirm p { margin: 0; flex-basis: 100%; }
/* 확정 = success (마크업 btn-primary 를 override, JS 무수정) */
.grading-confirm .btn-primary { background: var(--color-success-500); }
.grading-confirm .btn-primary:hover { background: var(--color-success-600); color: #fff; }
.grading-confirm .btn-primary:disabled { background: var(--color-gray-300); }

/* ── 좁은 화면 (base 룰 뒤, 파일 마지막) ── */
@media (max-width: 640px) {
    .bravo-q-form,
    .bravo-ot-form { grid-template-columns: 1fr; }
    .grading-audio audio { max-width: 100%; }
    .bravo-q-filters #bq-new { margin-left: 0; }
}
```

- [ ] **Step 2: 검증**

```bash
cd /root/boot-dev/public_html
php -r '$c=file_get_contents("css/admin-bravo.css"); exit(substr_count($c,"{")===substr_count($c,"}")?0:1);' && echo "braces OK"
for cls in grading-toolbar grading-chip grading-panel grading-progress grading-card grading-q grading-answer-key grading-audio grading-audio-missing grading-judges grading-judge grading-score grading-memo grading-confirm; do
  grep -q "\.$cls" css/admin-bravo.css || echo "MISSING .$cls"
done; echo "coverage done"
# @media 가 base 룰 뒤(파일 끝)에만 있는지 — @media 이후에 base 셀렉터가 나오면 안 됨
php -r '$c=file_get_contents("css/admin-bravo.css"); $p=strpos($c,"@media"); $rest=substr($c,$p); preg_match_all("/^\.[a-z]/m",$rest,$m); exit(count($m[0])===0?0:1);' && echo "media order OK"
```
Expected: 전부 통과, MISSING 0건
(참고: `.grading-open`/`.grading-table` 은 `.btn`/`.data-table` 공통으로 충분 — 의도적 제외. 단 `.grading-table` 셀렉터가 필요해지면 base 영역에 추가.)

⚠️ media order 체크: `@media (max-width: 640px)` 블록 안 셀렉터는 들여쓰기돼 있어 `^\.` 에 안 걸림 — 들여쓰기 유지할 것.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/css/admin-bravo.css
git commit -m "style(bravo): 채점 패널 CSS — min-height 공간 예약 (오디오 재생 보존 레이아웃)"
```

---

### Task 4: 인증서 GD 렌더 폴리시

**Files:**
- Modify: `public_html/api/services/bravo_certificates.php` — `bravoCertificateRender` 교체 + 헬퍼 1개 추가
- Test: `tests/bravo_certificates_test.php` (기존 — 통과 유지가 계약, 수정 없음)

**스펙 §4-5 규칙:** 프레임 장식(테두리·모서리)=무배경 전용. 텍스트 폴리시(등급별 포인트 색·자간·이름 구분선·서명선)=배경 PNG 유무와 무관하게 항상 적용. 색상 고정값: 네이비 `#1A2B4C`(26,43,76), 골드 `#B08D57`(176,141,87), 인증번호 그레이 `#64748B`(100,116,139), B1 `#2563EB`(37,99,235), B2 `#D97706`(217,119,6), B3 `#B08D57`(176,141,87). 캔버스 1754×1240·시그니처·반환 계약 유지.

- [ ] **Step 1: 자간 헬퍼 추가**

`bravoCertCenteredText` 함수 정의 바로 다음에 추가:

```php
/**
 * 자간(tracking) 적용 가운데 정렬 텍스트.
 * GD 는 letter-spacing 미지원 — 문자 단위로 폭을 재며 전진 그리기 (커닝 무시는 디스플레이용 허용).
 */
function bravoCertTrackedCenteredText($im, string $font, float $size, int $color, int $centerX, int $baselineY, string $text, float $tracking): void {
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$chars) return;
    $widths = [];
    $total = 0.0;
    foreach ($chars as $ch) {
        $b = imagettfbbox($size, 0, $font, $ch);
        $cw = $b[2] - $b[0];
        $widths[] = $cw;
        $total += $cw;
    }
    $total += $tracking * (count($chars) - 1);
    $x = $centerX - $total / 2;
    foreach ($chars as $i => $ch) {
        imagettftext($im, $size, 0, (int)round($x), $baselineY, $color, $font, $ch);
        $x += $widths[$i] + $tracking;
    }
}
```

- [ ] **Step 2: bravoCertificateRender 본문 교체**

함수 전체를 다음으로 교체 (시그니처·docblock 계약 동일, 본문만 변경):

```php
/**
 * 인증서 렌더. GD 1754×1240 (A4 가로 ~150dpi) → Imagick PDF, 불가 시 PNG.
 * 반환: ['bytes'=>string, 'mime'=>string, 'ext'=>string]
 * $forcePng: 테스트/폴백 검증용 — Imagick 변환 생략.
 *
 * 장식 규칙 (스펙 2026-06-05 §4-5):
 * - 프레임 장식(테두리·모서리 액센트)은 기본(무배경) 디자인 전용 — 배경 PNG 가 있으면 PNG 가 완성 디자인.
 * - 텍스트 폴리시(등급별 포인트 색·자간·이름 구분선·서명선)는 배경 유무와 무관하게 항상 적용.
 */
function bravoCertificateRender(array $cert, bool $forcePng = false): array {
    $bold = bravoCertFontPath('Bold');
    $regular = bravoCertFontPath('Regular');
    if ($bold === null || $regular === null) {
        throw new RuntimeException('인증서 폰트 파일이 없습니다: ' . BRAVO_CERT_FONT_DIR);
    }

    $w = 1754; $h = 1240;
    $im = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($im, 255, 255, 255);
    $ink   = imagecolorallocate($im, 26, 43, 76);     // #1A2B4C 본문 네이비
    $gold  = imagecolorallocate($im, 176, 141, 87);   // #B08D57 골드 (보더·장식)
    $gray  = imagecolorallocate($im, 100, 116, 139);  // #64748B 인증번호 (--color-gray-500)
    // 등급별 타이틀 포인트 색 (스펙 고정값: B1 primary-600 / B2 accent-600 / B3 골드)
    $levelRgb = [1 => [37, 99, 235], 2 => [217, 119, 6], 3 => [176, 141, 87]];
    $lv = $levelRgb[(int)$cert['bravo_level']] ?? [26, 43, 76];
    $accent = imagecolorallocate($im, $lv[0], $lv[1], $lv[2]);

    $bgUsed = false;
    if (is_file(BRAVO_CERT_BG_PNG)) {
        $bg = @imagecreatefrompng(BRAVO_CERT_BG_PNG);
        if ($bg) {
            imagecopyresampled($im, $bg, 0, 0, 0, 0, $w, $h, imagesx($bg), imagesy($bg));
            imagedestroy($bg);
            $bgUsed = true;
        }
    }
    if (!$bgUsed) {
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $white);
        // 이중 테두리: 외곽 3px 띠 + 내곽 1px (간격 보강: 36 / 52)
        for ($i = 0; $i < 3; $i++) {
            imagerectangle($im, 36 + $i, 36 + $i, $w - 37 - $i, $h - 37 - $i, $gold);
        }
        imagerectangle($im, 52, 52, $w - 53, $h - 53, $gold);
        // 모서리 L 액센트 (내곽 모서리 4곳, 직선 조합)
        $cl = 36;
        imagesetthickness($im, 3);
        foreach ([[52, 52, 1, 1], [$w - 53, 52, -1, 1], [52, $h - 53, 1, -1], [$w - 53, $h - 53, -1, -1]] as $c) {
            [$cx, $cy, $dx, $dy] = $c;
            imageline($im, $cx + $dx * 8, $cy + $dy * 8, $cx + $dx * ($cl + 8), $cy + $dy * 8, $gold);
            imageline($im, $cx + $dx * 8, $cy + $dy * 8, $cx + $dx * 8, $cy + $dy * ($cl + 8), $gold);
        }
        imagesetthickness($im, 1);
    }

    $level = (int)$cert['bravo_level'];
    $ts = strtotime($cert['passed_on']);
    $passedKo = date('Y', $ts) . '년 ' . date('n', $ts) . '월 ' . date('j', $ts) . '일';

    // 텍스트 폴리시 — 배경 유무와 무관하게 항상 적용
    bravoCertCenteredText($im, $regular, 22, $gray, (int)($w / 2), 140, '제 ' . $cert['cert_no'] . ' 호');
    bravoCertTrackedCenteredText($im, $bold, 64, $accent, (int)($w / 2), 300, "BRAVO {$level} 등급 인증서", 6.0);
    bravoCertCenteredText($im, $bold, 52, $ink, (int)($w / 2), 520, $cert['member_name']);
    // 이름 아래 구분선 (이름 폭 + 양쪽 40px)
    $nb = imagettfbbox(52, 0, $bold, $cert['member_name']);
    $nw = $nb[2] - $nb[0];
    imagesetthickness($im, 2);
    imageline($im, (int)($w / 2 - $nw / 2 - 40), 552, (int)($w / 2 + $nw / 2 + 40), 552, $gold);
    imagesetthickness($im, 1);
    bravoCertCenteredText($im, $regular, 32, $ink, (int)($w / 2), 660, "위 사람은 소리튠영어 소리블록 BRAVO {$level} 등급 시험에");
    bravoCertCenteredText($im, $regular, 32, $ink, (int)($w / 2), 720, '합격하였음을 증명합니다.');
    bravoCertCenteredText($im, $regular, 30, $ink, (int)($w / 2), 940, $passedKo);
    // 발급처 위 서명선 느낌의 가는 선
    imageline($im, (int)($w / 2 - 150), 1000, (int)($w / 2 + 150), 1000, $gold);
    bravoCertCenteredText($im, $bold, 44, $ink, (int)($w / 2), 1060, '소리튠영어');

    ob_start();
    imagepng($im);
    $png = ob_get_clean();
    imagedestroy($im);

    if (!$forcePng && class_exists('Imagick')) {
        try {
            $ik = new Imagick();
            $ik->readImageBlob($png);
            $ik->setImageFormat('pdf');
            $pdf = $ik->getImagesBlob();
            $ik->clear();
            return ['bytes' => $pdf, 'mime' => 'application/pdf', 'ext' => 'pdf'];
        } catch (Throwable $e) {
            // PNG 폴백
        }
    }
    return ['bytes' => $png, 'mime' => 'image/png', 'ext' => 'png'];
}
```

- [ ] **Step 3: 계약 테스트 + 미리보기 생성**

```bash
cd /root/boot-dev
php -l public_html/api/services/bravo_certificates.php
php tests/bravo_certificates_test.php
php -r '
require "/root/boot-dev/public_html/api/services/bravo_certificates.php";
foreach ([1,2,3] as $lv) {
    $r = bravoCertificateRender(["cert_no"=>"BRAVO{$lv}-20260612-000{$lv}","member_name"=>"홍길동","bravo_level"=>$lv,"passed_on"=>"2026-06-12"], true);
    file_put_contents("/tmp/bravo_cert_preview_b{$lv}.png", $r["bytes"]);
    echo "b{$lv}: " . strlen($r["bytes"]) . " bytes\n";
}
'
```
Expected: `No syntax errors` / `19 pass, 0 fail` / 미리보기 PNG 3개 생성 (등급별 타이틀 색 차이는 컨트롤러가 이미지로 확인)
주의: 위 php -r 은 `bravo.php` 를 경유 require 하므로 config 없이도 함수 정의는 로드됨 — 만약 config 의존 에러가 나면 `require "/root/boot-dev/public_html/config.php";` 를 첫 줄에 추가.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/bravo_certificates.php
git commit -m "style(bravo): 인증서 GD 폴리시 — 등급별 포인트 색·자간·구분선·모서리 장식 (텍스트 폴리시는 배경 PNG 와 무관하게 유지)"
```

---

### Task 5: 전체 회귀 + 정리

- [ ] **Step 1: BRAVO 테스트 19파일 전체 회귀**

```bash
cd /root/boot-dev
for f in tests/bravo_*.php; do php "$f" >/dev/null 2>&1 || echo "FAIL $f"; done; echo done
```
Expected: FAIL 0건, `done`

- [ ] **Step 2: 수정 파일 외 변경 없음 확인**

```bash
git status --short   # 기존 untracked(CLAUDE.md/README.md/tmp/) 외 잔여 변경 없어야 함
git log --oneline -5
```

- [ ] **Step 3: dev 페이지 응답 스모크**

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://dev-boot.soritune.com/
curl -s -o /dev/null -w '%{http_code}\n' https://dev-boot.soritune.com/operation/
curl -s -o /dev/null -w '%{http_code}\n' https://dev-boot.soritune.com/css/bravo.css
curl -s -o /dev/null -w '%{http_code}\n' https://dev-boot.soritune.com/css/admin-bravo.css
```
Expected: 전부 `200`

---

## 완료 후 (컨트롤러)

1. 인증서 미리보기 3장(/tmp/bravo_cert_preview_b{1,2,3}.png) Read 로 시각 확인 — 텍스트 겹침/잘림/색 확인, 문제 있으면 좌표 fix-up
2. 최종 통합 리뷰 (base=스타일링 시작 전 HEAD): 기존 CSS 파일 무수정 확인(`git diff --stat` 에 css/ 기존 파일 없음), JS 무수정 확인, @media 순서, 토큰 외 하드코딩 색 없는지(`grep -nE '#[0-9a-fA-F]{3,6}' css/bravo.css css/admin-bravo.css` — `#fff` hover 보정 외 0건 기대)
3. dev push → ⛔ 게이트: 사용자 브라우저 검증 (회원 탭·응시 모바일/PC, 관리자 4서브탭, 채점, 인증서 PDF — **PDF 가 기대값**, PNG 면 Imagick 예외 조사)
4. 메모리 업데이트 (스타일링 패스 완료 기록)
5. 운영(main) 반영 금지 — 사용자 명시 요청 시에만

## Self-Review 결과

- **스펙 커버리지**: §4-1 회원 카드(T1) / §4-2 응시(T1) / §4-3 관리자 셸·폼(T2) / §4-4 채점+공간예약(T3) / §4-5 인증서+장식규칙+색고정(T4) / §5 원칙(전 태스크 — 토큰만·기존 파일 무수정·base before @media) / §6 검증(T4 미리보기+T5 회귀) — 누락 없음
- **클래스 커버리지**: JS 6파일 마크업 대조 — 스타일 불필요(공통 .btn/.data-table 로 충분)로 의도적 제외한 클래스는 각 태스크 참고에 명시 (`bravo-challenge/finalize/save/exam-edit/exam-del/exam-ot/exam-eq/bq-edit/bq-del/grading-open/grading-table/bravo-admin/bravo-q/bravo-exam-admin/bravo-q-table/eq-chk/bx-rec(컨테이너만)` 등)
- **타입/시그니처 일관성**: `bravoCertificateRender(array, bool): array` 유지, `bravoCertTrackedCenteredText` 정의(T4 Step1)=사용(T4 Step2) 일치. CSS 클래스명은 JS 마크업 원문에서 복사
- **하드코딩 색**: bravo.css/admin-bravo.css 는 `#fff`(primary/accent 버튼 hover 텍스트) 외 전부 var() — 의도적
