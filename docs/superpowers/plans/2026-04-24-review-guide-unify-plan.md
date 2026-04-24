# 후기 작성 가이드 통일 + UI 단순화 — 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 카페/블로그로 분리된 후기 가이드를 단일 `review_guide`로 통합하고, 회원 `/후기작성` 화면에서 탭 구조를 제거해 상단 공통 가이드 + 카페/블로그 섹션 세로 나열로 재구성.

**Architecture:** `system_contents`에 새 키 `review_guide` 하나를 seed하고 기존 `review_cafe_guide`/`review_blog_guide`는 제거. API 3개(`my_review_status`, `review_settings`, `review_settings_save`)의 필드를 guide 단일 필드로 정리. 회원 UI는 `renderSection()`에서 가이드 블록을 빼고 `load()`에서 한 번만 렌더. 코인 적립/취소/eligibility/제출 이력 스키마는 무변경.

**Tech Stack:** PHP 8 (`api/services/review.php`), Vanilla JS (`public_html/js/member-review.js`), CSS (`public_html/css/member.css`), MySQL (`system_contents` 테이블), 마크다운 렌더는 `MemberHome.renderMarkdown` 재사용.

**스펙:** `docs/superpowers/specs/2026-04-24-review-guide-unify-design.md`

---

## 배포 게이트 (중요)

이 프로젝트의 메모리 규칙상:

1. 모든 코드 작업은 `boot-dev`(dev 브랜치)에서만.
2. Task 1~6을 모두 dev에서 완성 → `git push origin dev` → **여기서 멈춤**. 사용자에게 dev 확인 요청.
3. 사용자가 "운영 반영" 명시적으로 요청한 경우에만 main 머지 + prod pull + prod 마이그 실행 (Task 7).

Task 7은 위 게이트를 문서화하고, `boot-prod` 작업은 사용자 요청이 있을 때만 진행.

---

## 파일 구조

**신규 생성:**
- `/root/boot-dev/migrate_review_guide_unify.php` — 마이그 스크립트 (system_contents 키 재편).

**수정:**
- `/root/boot-dev/public_html/api/services/review.php` — 3개 핸들러 응답/허용키 조정.
- `/root/boot-dev/public_html/js/member-review.js` — 탭 제거, 상단 가이드 + 섹션 2개 세로 나열.
- `/root/boot-dev/public_html/css/member.css` — `.review-guide-top` 신규, `.review-guide` 제거.

**변경 없음 (명시):**
- `public_html/api/bootcamp.php` — action 라우팅 그대로.
- `review_submissions` 테이블, `applyCoinChange`, `coin_cycles`, `reward_group_distribute`.
- `public_html/js/admin-reviews.js` — 운영 UI는 이번 변경에서 건드리지 않음.

---

### Task 1: 마이그 스크립트 작성 + DEV DB 적용

**Files:**
- Create: `/root/boot-dev/migrate_review_guide_unify.php`

**목적:** `system_contents`에 `review_guide` 1건 seed(또는 덮어쓰기), 기존 `review_cafe_guide`/`review_blog_guide` 삭제. 실행 전에 구 키 값 백업용 SQL을 stdout에 출력.

- [ ] **Step 1: 스크립트 작성**

새 파일 `/root/boot-dev/migrate_review_guide_unify.php`:

```php
<?php
/**
 * boot.soritune.com - 후기 가이드 통일 마이그
 * - review_guide 키 UPSERT (최종 문구)
 * - review_cafe_guide / review_blog_guide 삭제
 * - 삭제 전 기존 값 stdout 덤프 (수동 롤백용 백업)
 *
 * 실행: php migrate_review_guide_unify.php
 * Dry-run: php migrate_review_guide_unify.php --dry-run
 */

require_once __DIR__ . '/public_html/config.php';

$dryRun = in_array('--dry-run', $argv);
$db = getDB();

$newGuide = <<<'MD'
🟠 작성 위치
- 카페: 소리튠 공식 카페 "소리블록 부트캠프 경험담"
- 블로그: 본인 네이버 블로그/티스토리 등 (전체공개 필수)

🟠 필수 해시태그
- 제목 또는 본문에 아래 해시태그를 반드시 포함해주세요.
  #소리튠영어 #영어스피킹 #소리블록 #소리튠부트캠프 #소리튠부트캠프방법

🟠 분량/내용 기준
- 글자 수: 공백 포함 600자 이상 (학습 동기, 구체적 훈련 방법 혹은 팁, 변화된 점 포함)
- 사진 첨부: 직접 촬영/캡처한 이미지 3장 이상 필수

🟠 적립 안내
- 작성한 글의 URL을 아래 해당 칸(카페/블로그)에 입력하면 확인 후 5코인이 적립됩니다.
- 12기 수강생에 한해, 12기 코인으로 사용 가능합니다.

⚠️ 코인 회수 및 반려 기준 (필독)
아래 기준에 미달하면 적립된 코인이 회수될 수 있습니다.

- 분량 미달: 글자 수 600자 미만 또는 사진 3장 미만인 경우
- 부정 행위: 타인의 글/사진 도용, 적립 후 3개월 이내 삭제/비공개 전환
- 확인 불가: 링크 오류 또는 친구공개/비공개 글로 설정된 경우
MD;

echo "=== Review Guide Unify Migration" . ($dryRun ? " [DRY-RUN]" : "") . " ===\n\n";

try {
    // 0. 기존 값 백업 (stdout 덤프)
    echo "[0] 기존 review_cafe_guide / review_blog_guide 백업 (복원용 SQL):\n";
    $backup = $db->query("SELECT content_key, content_markdown FROM system_contents WHERE content_key IN ('review_cafe_guide','review_blog_guide')")->fetchAll();
    if (!$backup) {
        echo "  - 해당 키 없음 (이미 제거되었거나 최초 설치 상태).\n";
    }
    foreach ($backup as $row) {
        $escaped = str_replace("'", "''", $row['content_markdown']);
        echo "  -- {$row['content_key']}\n";
        echo "  INSERT INTO system_contents (content_key, content_markdown) VALUES ('{$row['content_key']}', '{$escaped}') ON DUPLICATE KEY UPDATE content_markdown=VALUES(content_markdown);\n";
    }
    echo "\n";

    if (!$dryRun) $db->beginTransaction();

    // 1. review_guide UPSERT
    echo "[1] review_guide UPSERT...\n";
    if (!$dryRun) {
        $db->prepare("
            INSERT INTO system_contents (content_key, content_markdown) VALUES ('review_guide', ?)
            ON DUPLICATE KEY UPDATE content_markdown = VALUES(content_markdown)
        ")->execute([$newGuide]);
    }
    echo "  - " . ($dryRun ? "upsert 예정" : "upsert 완료") . "\n";

    // 2. 구 키 DELETE
    echo "[2] review_cafe_guide / review_blog_guide 삭제...\n";
    if (!$dryRun) {
        $db->exec("DELETE FROM system_contents WHERE content_key IN ('review_cafe_guide','review_blog_guide')");
    }
    echo "  - " . ($dryRun ? "삭제 예정" : "삭제 완료") . "\n";

    // 3. 검증
    echo "[3] 검증...\n";
    $countNew = (int)$db->query("SELECT COUNT(*) FROM system_contents WHERE content_key = 'review_guide'")->fetchColumn();
    $countOld = (int)$db->query("SELECT COUNT(*) FROM system_contents WHERE content_key IN ('review_cafe_guide','review_blog_guide')")->fetchColumn();
    echo "  - review_guide: {$countNew}건 (기대: 1)\n";
    echo "  - 구 키 잔존: {$countOld}건 (기대: 0, dry-run이면 2 가능)\n";

    if (!$dryRun && $db->inTransaction()) $db->commit();
    echo "\n완료" . ($dryRun ? " (dry-run)" : "") . ".\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "\n실패: " . $e->getMessage() . "\n";
    exit(1);
}
```

- [ ] **Step 2: dry-run 실행해서 안전 확인**

```bash
cd /root/boot-dev && php migrate_review_guide_unify.php --dry-run
```

Expected:
- `[0]` 섹션에 기존 cafe_guide/blog_guide 내용이 복원용 INSERT SQL로 출력됨.
- `[3]` 검증에서 `review_guide: 0건`, `구 키 잔존: 2건` (dry-run이라 변경 없음).
- 예외 없이 `완료 (dry-run)` 로 끝.

- [ ] **Step 3: 실제 실행**

```bash
cd /root/boot-dev && php migrate_review_guide_unify.php
```

Expected:
- `[1]` upsert 완료, `[2]` 삭제 완료.
- `[3]` 검증: `review_guide: 1건`, `구 키 잔존: 0건`.

- [ ] **Step 4: DB에서 직접 확인**

```bash
source /root/boot-dev/.db_credentials && mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT content_key, LEFT(content_markdown, 60) AS preview FROM system_contents WHERE content_key LIKE 'review_%';"
```

Expected: `review_cafe_enabled`, `review_blog_enabled`, `review_guide` 3건만 출력. `review_*_guide` 구 키 없음.

- [ ] **Step 5: 재실행 멱등성 확인**

```bash
cd /root/boot-dev && php migrate_review_guide_unify.php
```

Expected: 한 번 더 돌려도 에러 없이 동일 결과 (UPSERT + 없는 키 DELETE는 안전).

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev && git add migrate_review_guide_unify.php && git commit -m "$(cat <<'EOF'
feat(review): 가이드 통일 마이그 스크립트

system_contents의 review_cafe_guide / review_blog_guide를 단일
review_guide 키로 통합. 실행 전 구 값을 stdout에 백업용 SQL로 덤프.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: API 수정 — `my_review_status`

**Files:**
- Modify: `/root/boot-dev/public_html/api/services/review.php:58-102`

**목적:** `cafe.guide` / `blog.guide` 필드 제거, 최상위 `guide` 필드 하나로 통합.

- [ ] **Step 1: `handleMyReviewStatus()` 응답 수정**

`review.php` 88-101 라인 교체:

```php
    jsonSuccess([
        'eligible' => $elig['eligible'],
        'ineligible_reason' => $elig['reason'],
        'guide' => getSystemContent($db, 'review_guide', ''),
        'cafe' => [
            'enabled'   => getSystemContent($db, 'review_cafe_enabled', 'off') === 'on',
            'submitted' => $cafeSubmitted,
        ],
        'blog' => [
            'enabled'   => getSystemContent($db, 'review_blog_enabled', 'off') === 'on',
            'submitted' => $blogSubmitted,
        ],
    ]);
```

변경점: `cafe.guide`, `blog.guide` 제거. 응답 최상위에 `guide` 추가.

- [ ] **Step 2: 회원 세션으로 API 수동 호출해 응답 확인**

브라우저에서 `dev-boot.soritune.com` 로그인된 회원 세션으로 DevTools 콘솔 실행:

```js
fetch('/api/bootcamp.php?action=my_review_status').then(r=>r.json()).then(console.log)
```

Expected:
- `guide`: 새 통합 가이드 마크다운 문자열 (🟠 작성 위치... 로 시작).
- `cafe`: `{enabled: true, submitted: null}` (또는 제출된 경우 submitted object, `guide` 키 없음).
- `blog`: 동일.

만약 로그인된 테스트 계정이 없으면 DEV DB에서 세션 조회하거나 `테스트22` (id=2029)로 로그인해 확인. eligible=true 상태여야 guide가 의미 있게 나옴.

- [ ] **Step 3: eligible=false 케이스도 확인**

DEV DB에서 테스트22 상태를 임시로 inactive로 돌린 뒤:

```bash
source /root/boot-dev/.db_credentials && mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "UPDATE bootcamp_members SET member_status='out_of_group_management' WHERE id=2029;"
```

API 재호출 Expected: `eligible:false, ineligible_reason:"member_inactive", guide:<문자열>` (guide는 여전히 포함되어도 프론트에서 숨기므로 무해). 이후 다시 `active`로 원복:

```bash
source /root/boot-dev/.db_credentials && mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "UPDATE bootcamp_members SET member_status='active' WHERE id=2029;"
```

---

### Task 3: API 수정 — `review_settings` (GET) + `review_settings_save` (POST)

**Files:**
- Modify: `/root/boot-dev/public_html/api/services/review.php:344-385`

**목적:** 운영자 설정 조회/저장 핸들러에서 guide 단일 키만 허용. 기존 cafe_guide/blog_guide 제거.

- [ ] **Step 1: `handleReviewSettingsGet()` 응답 수정**

`review.php` 344-353 라인 교체:

```php
function handleReviewSettingsGet() {
    requireAdmin(['operation','coach','head','subhead1','subhead2']);
    $db = getDB();
    jsonSuccess([
        'cafe_enabled' => getSystemContent($db, 'review_cafe_enabled', 'off') === 'on',
        'blog_enabled' => getSystemContent($db, 'review_blog_enabled', 'off') === 'on',
        'guide'        => getSystemContent($db, 'review_guide', ''),
    ]);
}
```

변경점: `cafe_guide`, `blog_guide` 제거. `guide` 단일 필드 추가.

- [ ] **Step 2: `handleReviewSettingsUpdate()` 허용 키 배열 수정**

`review.php` 366 라인 교체:

```php
    $allowed = ['review_cafe_enabled', 'review_blog_enabled', 'review_guide'];
```

변경점: `review_cafe_guide`, `review_blog_guide` 제거. `review_guide` 추가. 나머지 검증(`str_ends_with(..., '_enabled')`로 토글 on/off 체크, else 브랜치에서 guide 길이 5000자 제한)은 그대로 — `review_guide`도 `_enabled`로 끝나지 않으므로 else 브랜치를 타게 되어 그대로 동작.

- [ ] **Step 3: 운영자 세션으로 `review_settings` GET 확인**

`dev-boot.soritune.com/operation` 로그인 세션으로 DevTools:

```js
fetch('/api/bootcamp.php?action=review_settings').then(r=>r.json()).then(console.log)
```

Expected: `{success:true, cafe_enabled:true, blog_enabled:true, guide:"<통합 가이드>"}`, `cafe_guide`/`blog_guide` 키 **없음**.

- [ ] **Step 4: `review_settings_save` POST 검증 (허용 키)**

DevTools에서:

```js
fetch('/api/bootcamp.php?action=review_settings_save', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({key:'review_guide', value:'테스트 수정'})}).then(r=>r.json()).then(console.log)
```

Expected: `{success:true, ...}`. 그 후 DB 확인:

```bash
source /root/boot-dev/.db_credentials && mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT content_markdown FROM system_contents WHERE content_key='review_guide';"
```

Expected: `테스트 수정`.

- [ ] **Step 5: 원본 가이드 복원**

```bash
cd /root/boot-dev && php migrate_review_guide_unify.php
```

마이그는 UPSERT라 재실행하면 최종 문구로 되돌아감. DB로 확인:

```bash
source /root/boot-dev/.db_credentials && mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT LEFT(content_markdown, 40) FROM system_contents WHERE content_key='review_guide';"
```

Expected: `🟠 작성 위치...`.

- [ ] **Step 6: 거부 케이스 검증 — 삭제된 키로 POST**

```js
fetch('/api/bootcamp.php?action=review_settings_save', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({key:'review_cafe_guide', value:'x'})}).then(r=>r.json()).then(console.log)
```

Expected: `{success:false, error:"허용되지 않은 key입니다."}`. (블로그도 동일 확인.)

- [ ] **Step 7: API 수정 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/review.php && git commit -m "$(cat <<'EOF'
feat(review): API 응답/허용키를 단일 review_guide로 통합

my_review_status: cafe.guide/blog.guide 제거, 최상위 guide 필드 추가.
review_settings: cafe_guide/blog_guide 제거, guide 단일 필드 추가.
review_settings_save: 허용 키에서 구 키 2개 제거, review_guide 추가.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: 회원 UI — `member-review.js` 구조 변경

**Files:**
- Modify: `/root/boot-dev/public_html/js/member-review.js`

**목적:** 섹션마다 들어있던 가이드를 제거하고, `load()`에서 상단에 단일 가이드 블록을 한 번만 렌더. 카페/블로그 섹션은 URL 폼과 제출 완료 뱃지만 남김.

- [ ] **Step 1: `load()` 함수 수정**

`member-review.js` 26-46 라인의 `load()` 함수를 통째로 교체:

```js
    async function load() {
        const body = document.getElementById('review-submit-body');
        const r = await App.get(API + 'my_review_status');
        if (!r.success) {
            body.innerHTML = '<div class="review-submit-empty">정보를 불러오지 못했습니다.</div>';
            return;
        }

        if (!r.eligible) {
            const msg = {
                no_active_cycle: '현재 진행 중인 기수가 없습니다.',
                cohort_mismatch: '이번 기수 후기 접수 대상이 아닙니다.',
                member_inactive: '현재 후기 제출이 불가능한 상태입니다.',
            }[r.ineligible_reason] || '후기 제출이 불가능합니다.';
            body.innerHTML = `<div class="review-submit-empty">${App.esc(msg)}</div>`;
            return;
        }

        // 공통 가이드 블록 (상단, 펼침 기본)
        const guideHtml = (typeof MemberHome !== 'undefined' && typeof MemberHome.renderMarkdown === 'function')
            ? MemberHome.renderMarkdown(r.guide || '')
            : `<pre>${App.esc(r.guide || '')}</pre>`;
        const guideBlock = `
            <details class="review-guide-top" open>
                <summary>작성 가이드</summary>
                <div class="review-guide-body">${guideHtml}</div>
            </details>
        `;

        body.innerHTML = guideBlock
            + renderSection('cafe', '카페 후기', r.cafe)
            + renderSection('blog', '블로그 후기', r.blog);
        attachHandlers();
    }
```

변경점: `r.cafe.guide` / `r.blog.guide` 대신 `r.guide`를 `load()`에서 한 번만 렌더해 body 상단에 prepend.

- [ ] **Step 2: `renderSection()` 함수에서 가이드 블록 제거**

`member-review.js` 48-104 라인의 `renderSection()`을 통째로 교체:

```js
    function renderSection(type, title, data) {
        if (!data.enabled) {
            return `
                <div class="review-section review-section-${type}">
                    <div class="review-section-head">
                        <div class="review-section-title">${App.esc(title)}</div>
                        <div class="review-section-reward">+5 코인</div>
                    </div>
                    <div class="review-section-disabled">현재 접수 중이 아닙니다.</div>
                </div>
            `;
        }

        if (data.submitted) {
            const d = formatDate(data.submitted.submitted_at);
            return `
                <div class="review-section review-section-${type}">
                    <div class="review-section-head">
                        <div class="review-section-title">${App.esc(title)}</div>
                        <div class="review-section-reward">${data.submitted.coin_amount}코인 적립 완료</div>
                    </div>
                    <div class="review-submitted">
                        <div class="review-submitted-badge">✓ 제출 완료 · ${App.esc(d)}</div>
                        <a class="review-submitted-url" href="${App.esc(data.submitted.url)}" target="_blank" rel="noopener noreferrer">${App.esc(data.submitted.url)}</a>
                    </div>
                </div>
            `;
        }

        return `
            <div class="review-section review-section-${type}">
                <div class="review-section-head">
                    <div class="review-section-title">${App.esc(title)}</div>
                    <div class="review-section-reward">+5 코인</div>
                </div>
                <div class="review-form">
                    <label class="review-form-label" for="review-url-${type}">${App.esc(title)} 링크</label>
                    <input type="url" class="review-form-input" id="review-url-${type}"
                           placeholder="https://..." maxlength="500" autocomplete="off">
                    <button class="btn btn-primary review-form-submit" data-type="${type}">제출하기</button>
                </div>
            </div>
        `;
    }
```

변경점:
- `data.guide` 참조 제거 (`guideHtml` 로컬 변수, `<details class="review-guide">` 블록 둘 다 제거).
- 3개 분기(disabled / submitted / form)에서 가이드 블록만 빼고 나머지는 그대로.

- [ ] **Step 3: `attachHandlers()` / `formatDate()` 변경 없음 확인**

`attachHandlers()` (기존 106-136라인)와 `formatDate()` (138-143)는 손대지 않음. 파일 하단 `return { render };`도 그대로.

- [ ] **Step 4: JS 문법 검사**

```bash
node --check /root/boot-dev/public_html/js/member-review.js
```

Expected: 출력 없음(문법 OK). 에러 있으면 메시지 따라 수정.

---

### Task 5: CSS — 상단 가이드 스타일 추가, 죽은 스타일 제거

**Files:**
- Modify: `/root/boot-dev/public_html/css/member.css:653-666`

**목적:** 섹션 밖 상단 가이드 블록을 위한 `.review-guide-top` 스타일 추가. 섹션 내부에 있던 `.review-guide` 스타일은 더 이상 사용되지 않으므로 제거하되 공통 자식 선택자(`.review-guide-body`)는 재사용.

- [ ] **Step 1: CSS 교체**

`member.css` 653-666 라인(`.review-guide`, `.review-guide summary`, `.review-guide-body *` 규칙들)을 다음으로 교체:

```css
.review-guide-top {
    background: #fff; border-radius: 12px; padding: 14px 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06); margin-bottom: 16px;
}
.review-guide-top summary {
    cursor: pointer; color: #222; font-size: 15px; font-weight: 700; padding: 4px 0;
}
.review-guide-body {
    margin-top: 10px; padding: 12px; background: #F9FAFB; border-radius: 8px;
    font-size: 14px; line-height: 1.6; color: #333;
    white-space: pre-wrap;
}
.review-guide-body h1, .review-guide-body h2, .review-guide-body h3 { margin: 8px 0 6px; font-size: 15px; }
.review-guide-body p { margin: 6px 0; }
.review-guide-body ul, .review-guide-body ol { padding-left: 20px; margin: 6px 0; }
.review-guide-body code {
    background: #E5E7EB; padding: 1px 5px; border-radius: 4px; font-size: 13px;
}
```

변경점:
- `.review-guide`, `.review-guide summary` → `.review-guide-top`, `.review-guide-top summary` 로 이름만 교체(섹션 밖 사용).
- `.review-guide-top` 는 섹션 카드처럼 흰 배경 + 그림자를 가짐 (전엔 섹션 내부에 있어 필요 없었음).
- `.review-guide-body` 의 기존 스타일 유지 + `white-space: pre-wrap` 추가 (마크다운 줄바꿈이 list 외 구간에서 보존되도록 — 현재 `renderMarkdown`은 non-list/header 라인을 `<p>`로 감싸지 않고 그냥 누적하므로 줄바꿈을 CSS로 보존).

- [ ] **Step 2: 브라우저 캐시 무효화 확인**

`member.css` 가 `<link href="...?v=">` 같은 버전 쿼리를 쓰는지 확인:

```bash
grep -n "member.css" /root/boot-dev/public_html/*.html /root/boot-dev/public_html/**/*.html 2>/dev/null | head
```

만약 `?v=...` 쿼리가 있으면 버전 번호를 올려 강제 리로드. 없으면 수동 새로고침(Shift+Reload) 안내.

---

### Task 6: DEV 종합 브라우저 스모크 테스트 + 커밋 + push

**Files:**
- 없음 (수동 검증).

**목적:** 실제 화면에서 통합 가이드 + 섹션 2개 세로 나열이 제대로 뜨는지, 제출/완료 뱃지/토글 OFF 동작이 회귀하지 않는지 확인한 뒤 dev 브랜치 push.

- [ ] **Step 1: 사전 상태 정리**

테스트22(id=2029)가 이미 이전 세션에서 active. 이번 기수(12기, cycle_id=3)에 미제출 상태로 맞추기:

```bash
source /root/boot-dev/.db_credentials && mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id, type, cancelled_at, url FROM review_submissions WHERE member_id=2029 AND cycle_id=3;"
```

남아 있는 active 제출 건수가 있으면, 제출 → 코인 적립 → 코인 차감 순으로 되돌린다. 우선 delta(이미 적립된 후기 코인 합계)를 조회:

```bash
source /root/boot-dev/.db_credentials && mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT COALESCE(SUM(coin_change), 0) AS delta FROM coin_logs WHERE member_id=2029 AND cycle_id=3 AND reason_type IN ('review_cafe','review_blog');"
```

반환된 값 `<delta>` 를 사용해 차감:

```bash
DELTA=<위 쿼리 결과 숫자>
source /root/boot-dev/.db_credentials && mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
UPDATE member_cycle_coins SET earned_coin = GREATEST(earned_coin - ${DELTA}, 0)
  WHERE member_id=2029 AND cycle_id=3;
DELETE FROM coin_logs WHERE member_id=2029 AND cycle_id=3 AND reason_type IN ('review_cafe','review_blog');
DELETE FROM review_submissions WHERE member_id=2029 AND cycle_id=3;
"
```

`member_coin_balances.current_coin`도 재동기화(전체 cycle SUM(earned-used)):

```bash
source /root/boot-dev/.db_credentials && mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
UPDATE member_coin_balances
   SET current_coin = (SELECT COALESCE(SUM(earned_coin - used_coin), 0)
                         FROM member_cycle_coins WHERE member_id=2029)
 WHERE member_id=2029;
"
```

(테스트 계정 한정, member_id=2029만 — 다른 회원 이력 건드리지 말 것.)

- [ ] **Step 2: 회원 화면 최초 렌더 확인**

`dev-boot.soritune.com`에 테스트22로 로그인 → `/후기작성` (바로가기 카드에서 진입) →

Expected 레이아웃:
- 상단: "작성 가이드" 접기/펼치기 (기본 펼침), 안에 🟠 섹션 4개 + ⚠️ 섹션 1개 포함 마크다운 렌더.
- 중단: `카페 후기 +5 코인` 제목, 링크 입력창, 제출 버튼.
- 하단: `블로그 후기 +5 코인` 제목, 링크 입력창, 제출 버튼.
- 탭 UI 없음.

- [ ] **Step 3: 카페 후기 제출 → 완료 뱃지 확인**

유효한 테스트 URL(`https://cafe.naver.com/sori/test-1`) 입력 → "제출하기" 클릭 →

Expected:
- 토스트 "+5 코인이 적립되었습니다."
- 카페 섹션이 "5코인 적립 완료 / ✓ 제출 완료 · 4/24 / 링크" 로 바뀜.
- 블로그 섹션은 입력 폼 그대로.
- 상단 가이드 블록 여전히 존재.

- [ ] **Step 4: 블로그 후기 제출**

`https://blog.naver.com/test/test-1` 입력 → 제출 → 동일한 완료 뱃지. 두 섹션 모두 완료 뱃지 + 상단 가이드만 남는 상태.

- [ ] **Step 5: 페이지 리로드 후 제출 상태 유지 확인**

페이지 새로고침 → 두 섹션 모두 "✓ 제출 완료" 뱃지로 복원되어야 함(GET `my_review_status`가 submitted를 반환). 상단 가이드 여전히 노출.

- [ ] **Step 6: 토글 OFF 동작 확인**

`/operation` 운영 화면 → "후기" 탭 → "카페 후기 접수" 체크박스 해제 → 토스트 "카페 접수: OFF". 다시 회원 `/후기작성` 새로고침 →

Expected: 상단 가이드는 그대로, 카페 섹션은 "현재 접수 중이 아닙니다" 비활성 상태, 블로그 섹션은 제출 완료 뱃지 유지.

테스트 후 토글 다시 ON으로 복원.

- [ ] **Step 7: eligible=false 케이스 확인**

테스트22 상태를 임시 변경:

```bash
source /root/boot-dev/.db_credentials && mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "UPDATE bootcamp_members SET member_status='out_of_group_management' WHERE id=2029;"
```

회원 `/후기작성` 새로고침 →

Expected: "현재 후기 제출이 불가능한 상태입니다." 만 노출. 가이드/섹션 모두 숨김.

확인 후 원복:

```bash
source /root/boot-dev/.db_credentials && mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "UPDATE bootcamp_members SET member_status='active' WHERE id=2029;"
```

- [ ] **Step 8: 운영 화면 목록 회귀 확인**

`/operation > 후기` → 목록에 방금 제출한 2건(카페/블로그 테스트22)이 조회되는지 확인. 한 건 취소 → 회원 화면에서 해당 섹션이 다시 입력 폼으로 바뀌는지 확인. 코인 차감도 이전과 동일하게 동작.

- [ ] **Step 9: 테스트 흔적 정리**

브라우저 테스트로 들어간 제출 row + 코인이 실제 사용자 테스트에 혼동을 줄 수 있으므로 Step 1과 동일한 cleanup 시퀀스를 다시 수행:

1. delta 조회 → `SELECT COALESCE(SUM(coin_change), 0) FROM coin_logs WHERE member_id=2029 AND cycle_id=3 AND reason_type IN ('review_cafe','review_blog');`
2. `UPDATE member_cycle_coins SET earned_coin = GREATEST(earned_coin - <delta>, 0) WHERE member_id=2029 AND cycle_id=3;`
3. `DELETE FROM coin_logs WHERE member_id=2029 AND cycle_id=3 AND reason_type IN ('review_cafe','review_blog');`
4. `DELETE FROM review_submissions WHERE member_id=2029 AND cycle_id=3;`
5. `UPDATE member_coin_balances SET current_coin = (SELECT COALESCE(SUM(earned_coin - used_coin), 0) FROM member_cycle_coins WHERE member_id=2029) WHERE member_id=2029;`

실행 후 확인:

```bash
source /root/boot-dev/.db_credentials && mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT COUNT(*) AS submissions FROM review_submissions WHERE member_id=2029 AND cycle_id=3;
SELECT COUNT(*) AS review_logs FROM coin_logs WHERE member_id=2029 AND cycle_id=3 AND reason_type IN ('review_cafe','review_blog');
"
```

Expected: submissions=0, review_logs=0.

- [ ] **Step 10: 프론트 변경 커밋**

```bash
cd /root/boot-dev && git add public_html/js/member-review.js public_html/css/member.css && git commit -m "$(cat <<'EOF'
feat(review): 회원 /후기작성 UI 통일 - 탭 제거, 상단 공통 가이드

탭(카페/블로그) 구조를 없애고 상단에 단일 review-guide-top 블록 +
하단에 카페/블로그 섹션 2개 세로 나열. renderSection에서 가이드
블록 제거, load()에서 r.guide 한 번만 렌더. CSS .review-guide →
.review-guide-top 으로 이동.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 11: dev 브랜치 push + 사용자 확인 대기**

```bash
cd /root/boot-dev && git push origin dev
```

Expected: 커밋 3개(Task 1 마이그, Task 3 API, Task 6 UI) + spec 커밋 1개가 원격 dev에 푸시됨.

push 후 **반드시 멈추고** 사용자에게 dev 확인 요청. 사용자가 "운영 반영" 명시 요청할 때까지 Task 7 진행하지 말 것.

---

### Task 7: 운영 반영 (사용자 승인 후에만)

**Files:**
- 없음 (git + 마이그 실행만).

**목적:** 사용자가 DEV 검증 후 "운영 반영" 요청 시에만 main 머지 → prod pull → prod 마이그.

- [ ] **Step 1: 사용자 명시적 요청 확인**

"운영 반영해줘" / "prod에 적용" 같은 명시 요청이 없으면 **절대 실행 안 함**. 메모 규칙.

- [ ] **Step 2: dev → main 머지**

```bash
cd /root/boot-dev && git checkout main && git merge dev && git push origin main && git checkout dev
```

- [ ] **Step 3: prod 디렉토리 pull**

```bash
cd /root/boot-prod && git pull origin main
```

- [ ] **Step 4: PROD 마이그 dry-run**

```bash
cd /root/boot-prod && php migrate_review_guide_unify.php --dry-run
```

Expected:
- `[0]` 섹션에 PROD의 기존 `review_cafe_guide` / `review_blog_guide` 값이 복원용 SQL로 출력됨 — **이 출력을 별도 파일로 저장**(롤백 대비).
- 예외 없음.

```bash
cd /root/boot-prod && php migrate_review_guide_unify.php --dry-run > /tmp/review_guide_prod_backup.txt 2>&1
```

- [ ] **Step 5: PROD 마이그 실제 실행**

```bash
cd /root/boot-prod && php migrate_review_guide_unify.php
```

Expected: `review_guide: 1건`, `구 키 잔존: 0건`.

- [ ] **Step 6: PROD 스모크 테스트**

`boot.soritune.com` 실제 회원 1명(운영팀과 사전 합의) 로그인 → `/후기작성` → 상단 통합 가이드 + 카페/블로그 섹션 2개 레이아웃 확인.

제출 테스트 1회(카페 또는 블로그 중 하나) → 5코인 적립 확인 → 운영 `/operation > 후기` 에서 해당 제출 조회 확인.

문제 없으면 완료. 문제 있으면 이전 커밋으로 revert + `/tmp/review_guide_prod_backup.txt` 의 SQL로 구 가이드 복원.

---

## 검증 체크리스트 (최종)

- [ ] DEV `system_contents`: `review_guide` 1건, `review_cafe_guide` / `review_blog_guide` 0건.
- [ ] `my_review_status` 응답: `guide` 최상위 존재, `cafe.guide` / `blog.guide` 없음.
- [ ] `review_settings` 응답: `guide` 단일 필드.
- [ ] `review_settings_save`가 `review_guide`만 허용, 구 키는 거부.
- [ ] 회원 `/후기작성`: 상단 가이드 1개 + 섹션 2개 세로 나열, 탭 없음.
- [ ] 카페/블로그 제출 → +5 코인씩 적립 동작.
- [ ] 제출 완료 후 섹션이 뱃지로 전환.
- [ ] 토글 OFF 시 해당 섹션 비활성, 가이드는 유지.
- [ ] eligible=false 시 가이드/섹션 모두 숨김.
- [ ] 운영 `/operation > 후기` 목록/취소 동작 회귀 없음.
- [ ] DEV 테스트 흔적 정리 완료.
- [ ] dev push 후 사용자 확인 대기 게이트 준수.
