# 줌 회의 ID/비밀번호 노출 + 개별 복사 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 복습스터디·특강 줌 화면에 회의 ID와 숫자 비밀번호를 함께 노출하고 각각 개별 복사 버튼을 달아, 입장 링크 장애 시에도 멤버가 수동으로 입장할 수 있게 한다.

**Architecture:** 백엔드에 순수 헬퍼 `zoomDisplayInfo()`를 추가해 줌 row에서 표시용 회의 ID(컬럼값 또는 `join_url`의 `/j/` 파싱)와 비밀번호(컬럼값 또는 복습 고정방 설정 fallback)를 파생하고, 멤버에게 줌을 내려주는 엔드포인트 3개에 주입한다. 프론트엔드는 줌 블록마다 값이 있을 때만 "회의 ID / 비밀번호" 두 줄 + 개별 복사 버튼을 렌더한다. 복습 고정방 비번은 신설 설정 `study_fixed_zoom_password`로 관리한다.

**Tech Stack:** PHP 8 (PDO/MariaDB), 바닐라 JS (모듈 패턴), CLI PHP 테스트 하베스(`tests/*.php`)

**작업 환경:** `boot-dev` (DEV_BOOT, dev 브랜치). 모든 명령은 `/root/boot-dev`에서 실행.

---

## File Structure

| 파일 | 책임 | 변경 |
|------|------|------|
| `public_html/includes/bootcamp_functions.php` | 공용 줌 표시 헬퍼 `zoomDisplayInfo()` | 추가 (파일 끝) |
| `tests/zoom_display_info_test.php` | 헬퍼 단위 테스트 | 생성 |
| `public_html/api/services/study.php` | `study_session_detail`에 display 필드 주입 | 수정 (~134) |
| `public_html/api/services/lecture.php` | `lecture_session_detail`, `lecture_event_detail`에 주입 | 수정 (~262, ~607) |
| `migrate_seed_study_fixed_zoom_password.php` | `study_fixed_zoom_password` 빈 값 시드 (idempotent) | 생성 |
| `public_html/js/zoom-credentials.js` | 회의 ID/비번 줄 렌더 공용 헬퍼 (전역 `ZoomCreds`) | 생성 |
| `public_html/js/study.js` | 복습 상세 zoom 섹션에 줄 추가 | 수정 (~152) |
| `public_html/js/lecture.js` | 세션/이벤트 블록 host-전용 비번 → 전원 줄로 교체 | 수정 (~182, ~845) |
| `public_html/js/member-calendar-detail.js` | 복습/특강 블록에 줄 추가 | 수정 (~89, ~303) |
| `public_html/head/*.php` (멤버 레이아웃) | `zoom-credentials.js` 로드 | 수정 |

---

## Task 1: 백엔드 헬퍼 `zoomDisplayInfo()` (TDD)

**Files:**
- Test: `tests/zoom_display_info_test.php` (생성)
- Modify: `public_html/includes/bootcamp_functions.php` (파일 끝, 현재 377줄)

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/zoom_display_info_test.php` 생성:

```php
<?php
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/includes/bootcamp_functions.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// 1. 특강: 컬럼에 id/pw 둘 다 있음 → 그대로
$r = zoomDisplayInfo([
    'zoom_meeting_id' => '87444618976',
    'zoom_join_url'   => 'https://us02web.zoom.us/j/81330750588?pwd=duq',
    'zoom_password'   => '415217',
]);
t('lecture_columns', $r['zoom_meeting_id_display'] === '87444618976' && $r['zoom_password_display'] === '415217');

// 2. 복습 고정방: id NULL → URL /j/ 파싱
$r = zoomDisplayInfo([
    'zoom_meeting_id' => null,
    'zoom_join_url'   => 'https://us02web.zoom.us/j/82511251269?pwd=Ol9HUZJ',
    'zoom_password'   => null,
]);
t('parse_id_from_url', $r['zoom_meeting_id_display'] === '82511251269');

// 3. pw NULL + fallback → fallback 사용
$r = zoomDisplayInfo([
    'zoom_meeting_id' => null,
    'zoom_join_url'   => 'https://us02web.zoom.us/j/82511251269?pwd=Ol9',
    'zoom_password'   => null,
], '600091');
t('password_fallback', $r['zoom_password_display'] === '600091');

// 4. pw NULL + fallback 없음 → null (줄 생략)
$r = zoomDisplayInfo([
    'zoom_meeting_id' => null,
    'zoom_join_url'   => 'https://us02web.zoom.us/j/82511251269?pwd=Ol9',
    'zoom_password'   => null,
]);
t('no_password_no_fallback', $r['zoom_password_display'] === null);

// 5. fallback 빈 문자열 → null (빈 값 시드 케이스)
$r = zoomDisplayInfo([
    'zoom_meeting_id' => '82511251269',
    'zoom_join_url'   => 'https://us02web.zoom.us/j/82511251269',
    'zoom_password'   => null,
], '');
t('empty_fallback_is_null', $r['zoom_password_display'] === null);

// 6. id/url 모두 없음 → id null
$r = zoomDisplayInfo(['zoom_meeting_id' => null, 'zoom_join_url' => null, 'zoom_password' => null]);
t('no_id_no_url', $r['zoom_meeting_id_display'] === null);

// 7. /j/ 없는 이상 URL → id null (크래시 안 함)
$r = zoomDisplayInfo([
    'zoom_meeting_id' => null,
    'zoom_join_url'   => 'https://example.com/weird',
    'zoom_password'   => null,
]);
t('malformed_url_no_crash', $r['zoom_meeting_id_display'] === null);

// 8. 빈 문자열 컬럼 → URL 파싱으로 폴백
$r = zoomDisplayInfo([
    'zoom_meeting_id' => '',
    'zoom_join_url'   => 'https://us02web.zoom.us/j/82511251269?pwd=x',
    'zoom_password'   => '',
], '600091');
t('empty_string_columns', $r['zoom_meeting_id_display'] === '82511251269' && $r['zoom_password_display'] === '600091');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 테스트 실패 확인**

Run: `cd /root/boot-dev && php tests/zoom_display_info_test.php`
Expected: FATAL — `Call to undefined function zoomDisplayInfo()`

- [ ] **Step 3: 헬퍼 구현**

`public_html/includes/bootcamp_functions.php` 파일 **맨 끝**(377줄 뒤)에 추가:

```php

/**
 * 줌 row에서 멤버에게 노출할 표시용 회의 ID / 비밀번호를 파생한다.
 * - id: zoom_meeting_id 컬럼, 없으면 zoom_join_url의 /j/(숫자)에서 추출
 * - password: zoom_password 컬럼, 없으면 $passwordFallback (복습 고정방 설정값)
 * 값이 없으면 null (프론트는 null이면 줄 생략).
 *
 * @param array $row zoom_meeting_id, zoom_join_url, zoom_password 키를 가진 행
 * @param ?string $passwordFallback 컬럼 비번이 없을 때 쓸 대체 비번
 * @return array{zoom_meeting_id_display: ?string, zoom_password_display: ?string}
 */
function zoomDisplayInfo(array $row, ?string $passwordFallback = null): array {
    $id = $row['zoom_meeting_id'] ?? null;
    if (($id === null || $id === '') && !empty($row['zoom_join_url'])) {
        if (preg_match('#/j/(\d+)#', $row['zoom_join_url'], $m)) {
            $id = $m[1];
        }
    }
    if ($id === '') $id = null;

    $pw = $row['zoom_password'] ?? null;
    if ($pw === null || $pw === '') {
        $pw = ($passwordFallback !== null && $passwordFallback !== '') ? $passwordFallback : null;
    }

    return [
        'zoom_meeting_id_display' => $id,
        'zoom_password_display'   => $pw,
    ];
}
```

- [ ] **Step 4: 테스트 통과 확인**

Run: `cd /root/boot-dev && php tests/zoom_display_info_test.php`
Expected: `8 passed, 0 failed`

- [ ] **Step 5: lint + commit**

```bash
cd /root/boot-dev
php -l public_html/includes/bootcamp_functions.php
git add tests/zoom_display_info_test.php public_html/includes/bootcamp_functions.php
git commit -m "feat(boot): zoomDisplayInfo 헬퍼 — 줌 표시용 ID/비번 파생"
```

---

## Task 2: 엔드포인트에 display 필드 주입

**Files:**
- Modify: `public_html/api/services/study.php` (~134)
- Modify: `public_html/api/services/lecture.php` (~262, ~607)

- [ ] **Step 1: study_session_detail 주입**

`public_html/api/services/study.php`에서 `unset($session['password']);`(134줄) 바로 **다음 줄**에 추가:

```php
    // 멤버 수동 입장용 회의 ID / 비밀번호 (복습 고정방은 설정 비번 fallback)
    $session = array_merge(
        $session,
        zoomDisplayInfo($session, getSetting('study_fixed_zoom_password'))
    );
```

- [ ] **Step 2: lecture_session_detail 주입**

`public_html/api/services/lecture.php`의 `handleLectureSessionDetail`에서, `if (!$isAdmin) { unset($session['zoom_start_url']); }` 블록 **다음**, `jsonSuccess(['session' => $session]);`(~264) **앞**에 추가:

```php
    // 멤버 수동 입장용 회의 ID / 비밀번호 (특강은 컬럼값, fallback 없음)
    $session = array_merge($session, zoomDisplayInfo($session));

```

- [ ] **Step 3: lecture_event_detail 주입**

`public_html/api/services/lecture.php`의 이벤트 상세 핸들러에서, `if (!$isAdmin) { unset($event['zoom_start_url']); }` 블록 **다음**, `jsonSuccess(['event' => $event]);`(~609) **앞**에 추가:

```php
    // 멤버 수동 입장용 회의 ID / 비밀번호
    $event = array_merge($event, zoomDisplayInfo($event));

```

- [ ] **Step 4: lint**

Run:
```bash
cd /root/boot-dev && php -l public_html/api/services/study.php && php -l public_html/api/services/lecture.php
```
Expected: 둘 다 `No syntax errors detected`

- [ ] **Step 5: commit**

```bash
cd /root/boot-dev
git add public_html/api/services/study.php public_html/api/services/lecture.php
git commit -m "feat(boot): 줌 엔드포인트 3개에 zoom_meeting_id_display/zoom_password_display 주입"
```

---

## Task 3: `study_fixed_zoom_password` 설정 시드

**Files:**
- Create: `migrate_seed_study_fixed_zoom_password.php`

- [ ] **Step 1: 마이그레이션 스크립트 작성**

`migrate_seed_study_fixed_zoom_password.php` 생성 (idempotent, DEV/PROD 선택):

```php
<?php
/**
 * settings 에 study_fixed_zoom_password (복습스터디 고정 줌방 입력용 숫자 비밀번호) 시드.
 * 빈 값으로 행만 생성 — 실제 비번 값은 운영자가 UPDATE로 채운다.
 *
 *   php migrate_seed_study_fixed_zoom_password.php --db=dev
 *   php migrate_seed_study_fixed_zoom_password.php --db=prod
 *
 * 멱등: 행이 이미 있으면 value는 건드리지 않고 description만 보정.
 */
$opts = getopt('', ['db:']);
$dbTarget = $opts['db'] ?? 'dev';

$path = $dbTarget === 'prod' ? '/root/boot-prod/.db_credentials' : '/root/boot-dev/.db_credentials';
if (!is_readable($path)) die("Credentials not found: {$path}\n");
$env = [];
foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_contains($line, '=')) {
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
}
$dsn = "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4";
$pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->prepare("
    INSERT INTO settings (`key`, `value`, `description`)
    VALUES ('study_fixed_zoom_password', '', '복습스터디 고정 줌방 입력용 숫자 비밀번호')
    ON DUPLICATE KEY UPDATE `description` = VALUES(`description`)
")->execute();

$row = $pdo->query("SELECT `key`, `value`, `description` FROM settings WHERE `key`='study_fixed_zoom_password'")->fetch(PDO::FETCH_ASSOC);
echo "[{$dbTarget}] seeded: key={$row['key']} value='{$row['value']}' desc={$row['description']}\n";
echo "→ 실제 비번 설정: UPDATE settings SET value='<숫자>' WHERE `key`='study_fixed_zoom_password';\n";
```

- [ ] **Step 2: DEV에 적용**

Run: `cd /root/boot-dev && php migrate_seed_study_fixed_zoom_password.php --db=dev`
Expected: `[dev] seeded: key=study_fixed_zoom_password value='' desc=복습스터디 고정 줌방 입력용 숫자 비밀번호`

- [ ] **Step 3: 멱등성 확인 (재실행)**

Run: `cd /root/boot-dev && php migrate_seed_study_fixed_zoom_password.php --db=dev`
Expected: 동일 출력, 에러 없음 (value 유지)

- [ ] **Step 4: commit**

```bash
cd /root/boot-dev
git add migrate_seed_study_fixed_zoom_password.php
git commit -m "feat(boot): study_fixed_zoom_password 설정 시드 마이그레이션"
```

---

## Task 4: 프론트 공용 렌더 헬퍼 `ZoomCreds`

**Files:**
- Create: `public_html/js/zoom-credentials.js`
- Modify: 멤버 레이아웃 head include (script 로드)

- [ ] **Step 1: 렌더 헬퍼 작성**

`public_html/js/zoom-credentials.js` 생성:

```javascript
/**
 * 줌 회의 ID / 비밀번호 표시 + 개별 복사 줄 렌더.
 * 값이 있을 때만 줄을 만든다. data-* 로 복사값을 담아 이벤트 위임으로 처리.
 *
 * 전역: window.ZoomCreds = { html, bind }
 */
(function () {
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /**
     * @param {object} data zoom_meeting_id_display, zoom_password_display 키
     * @returns {string} HTML (값 없으면 빈 문자열)
     */
    function html(data) {
        var id = data && data.zoom_meeting_id_display;
        var pw = data && data.zoom_password_display;
        var rows = '';
        if (id) {
            rows += '<div class="zoom-cred-row">' +
                '<span class="zoom-cred-label">회의 ID</span>' +
                '<span class="zoom-cred-value">' + esc(id) + '</span>' +
                '<button type="button" class="zoom-cred-copy" data-zoom-copy="' + esc(id) +
                '" data-zoom-kind="회의 ID">복사</button>' +
                '</div>';
        }
        if (pw) {
            rows += '<div class="zoom-cred-row">' +
                '<span class="zoom-cred-label">비밀번호</span>' +
                '<span class="zoom-cred-value">' + esc(pw) + '</span>' +
                '<button type="button" class="zoom-cred-copy" data-zoom-copy="' + esc(pw) +
                '" data-zoom-kind="비밀번호">복사</button>' +
                '</div>';
        }
        return rows ? '<div class="zoom-cred">' + rows + '</div>' : '';
    }

    function copyText(text, msg) {
        if (window.MemberUtils && MemberUtils.copyToClipboard) {
            MemberUtils.copyToClipboard(text, msg);
            return;
        }
        // fallback
        navigator.clipboard && navigator.clipboard.writeText(text);
        if (window.Toast) Toast.success(msg);
    }

    /**
     * 컨테이너에 복사 버튼 이벤트 위임 바인딩. 모달 새로 열 때마다 호출해도 안전(중복 방지).
     */
    function bind(container) {
        if (!container || container.__zoomCredBound) return;
        container.__zoomCredBound = true;
        container.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('[data-zoom-copy]');
            if (!btn || !container.contains(btn)) return;
            var val = btn.getAttribute('data-zoom-copy');
            var kind = btn.getAttribute('data-zoom-kind') || '값';
            copyText(val, kind + '가 복사되었습니다.');
        });
    }

    window.ZoomCreds = { html: html, bind: bind };
})();
```

- [ ] **Step 2: CSS 추가**

멤버 공용 CSS(`public_html/css/` 에서 study/lecture 모달이 쓰는 파일, 예 `member.css`)에 추가:

```css
.zoom-cred { margin-top: 8px; display: flex; flex-direction: column; gap: 6px; }
.zoom-cred-row { display: flex; align-items: center; gap: 8px; font-size: 14px; }
.zoom-cred-label { min-width: 56px; color: var(--color-gray-500, #888); font-size: 12px; }
.zoom-cred-value { font-weight: 700; letter-spacing: 0.5px; flex: 1; word-break: break-all; }
.zoom-cred-copy { flex: 0 0 auto; padding: 4px 10px; font-size: 12px; border: 1px solid var(--color-gray-200, #ddd); border-radius: 6px; background: #fff; cursor: pointer; }
.zoom-cred-copy:active { background: var(--color-gray-100, #f2f2f2); }
```

> CSS 파일 경로는 구현 시 `grep -rln "lec-detail-row" public_html/css` 로 확인해 같은 파일에 둔다.

- [ ] **Step 3: 멤버 레이아웃에 스크립트 로드**

멤버 페이지가 `member-utils.js`를 로드하는 곳을 찾아 그 **다음에** `zoom-credentials.js`를 추가:

```bash
cd /root/boot-dev && grep -rln "member-utils.js" public_html/head public_html/*.php public_html/includes
```

찾은 각 include에서 `member-utils.js` `<script>` 다음 줄에 (cache-buster 동반):

```html
<script src="/js/zoom-credentials.js?v=20260601"></script>
```

- [ ] **Step 4: commit**

```bash
cd /root/boot-dev
git add public_html/js/zoom-credentials.js public_html/css/ public_html/head/ public_html/*.php public_html/includes/ 2>/dev/null
git commit -m "feat(boot): 줌 회의 ID/비번 표시·복사 공용 헬퍼 ZoomCreds + 스타일"
```

---

## Task 5: study.js 복습 상세에 줄 추가

**Files:**
- Modify: `public_html/js/study.js` (~152 zoomSection, ~247 bind)

- [ ] **Step 1: zoomSection ready 블록에 줄 추가**

`public_html/js/study.js`에서 `s.zoom_status === 'ready'` 분기의 zoomSection (입장/복사 버튼 `study-action-group`) 마크업 **마지막 `</div>` 직전**에 추가:

```javascript
                ${ZoomCreds.html(s)}
```

즉:
```javascript
            zoomSection = `
                <div class="study-action-group">
                    <a href="${App.esc(s.zoom_join_url)}" target="_blank" class="btn btn-block study-btn-zoom" id="btn-zoom-join">Zoom 입장하기</a>
                    <button class="btn btn-secondary btn-block" id="btn-zoom-copy">Zoom 링크 복사하기</button>
                    ${ZoomCreds.html(s)}
                </div>
            `;
```

- [ ] **Step 2: 모달 오픈 후 복사 바인딩**

`study.js`에서 모달이 열린 뒤(기존 `btn-zoom-copy` onclick 바인딩 부근, ~247) 컨테이너에 바인딩 추가. study.js가 모달 DOM 루트를 얻는 방식에 맞춰 — 모달 컨테이너(예 `document.getElementById('modal')` 또는 App 모달 루트)에:

```javascript
        if (window.ZoomCreds) ZoomCreds.bind(document.body);
```

> `document.body`에 위임 바인딩하면 모달이 body 하위에 그려지는 한 안전하며 `__zoomCredBound` 가드로 1회만 바인딩된다. 더 좁은 모달 루트가 있으면 그것을 전달.

- [ ] **Step 3: 수동 확인**

Run: `cd /root/boot-dev && php -l public_html/js 2>/dev/null; node --check public_html/js/study.js`
(node 없으면 생략) Expected: 문법 에러 없음

- [ ] **Step 4: commit**

```bash
cd /root/boot-dev
git add public_html/js/study.js
git commit -m "feat(boot): 복습 상세에 줌 회의 ID/비번 줄 추가"
```

---

## Task 6: member-calendar-detail.js 복습/특강 블록에 줄 추가

**Files:**
- Modify: `public_html/js/member-calendar-detail.js` (~89 복습, ~303 특강)

- [ ] **Step 1: 복습 블록에 줄 추가**

`renderStudy`(또는 복습 actionItems 블록, ~89)에서 zoom 입장/복사 push 직후에:

```javascript
                actionItems.push(ZoomCreds.html(s));
```

(`ZoomCreds.html`은 값 없으면 빈 문자열 → push해도 무해)

- [ ] **Step 2: 특강 블록에 줄 추가**

특강 블록(~303)에서 zoom 입장/복사 마크업 뒤에 동일하게 `ZoomCreds.html(<특강객체>)` 결과를 붙인다. 변수명은 해당 블록의 줌 데이터 객체(예 `ev` 또는 `s`)에 맞춘다.

- [ ] **Step 3: 모달 오픈 후 바인딩**

각 상세 모달을 여는 `App.openModal(...)` 호출 **직후**에:

```javascript
        if (window.ZoomCreds) ZoomCreds.bind(document.body);
```

(파일 내 모달 오픈 지점 — 복습 ~118 `App.openModal`, 특강 블록 모달 오픈 지점 — 각각에 1줄. 중복 호출은 가드로 무해)

- [ ] **Step 4: commit**

```bash
cd /root/boot-dev
git add public_html/js/member-calendar-detail.js
git commit -m "feat(boot): 캘린더 복습/특강 상세에 줌 회의 ID/비번 줄 추가"
```

---

## Task 7: lecture.js — host-전용 비번 표기를 전원 줄로 교체

**Files:**
- Modify: `public_html/js/lecture.js` (~182 세션, ~845 이벤트)

- [ ] **Step 1: 세션 블록 교체**

`public_html/js/lecture.js`의 세션 블록(~182)에서 기존:

```javascript
            if (s.zoom_password) {
                body += `<div class="lec-host-guide">Zoom 비밀번호: <strong>${App.esc(s.zoom_password)}</strong></div>`;
            }
```

을 **삭제**하고, 그 자리(입장/복사 버튼 마크업 뒤)에:

```javascript
            body += ZoomCreds.html(s);
```

- [ ] **Step 2: 이벤트 블록 교체**

이벤트 블록(~845~848)의 동일 패턴:

```javascript
            if (ev.zoom_password) {
                body += `<div class="lec-host-guide">Zoom 비밀번호: <strong>${App.esc(ev.zoom_password)}</strong></div>`;
            }
            body += `</div>`;
```

에서 비번 `if` 블록을 **삭제**하고, `body += '</div>';` **앞**에:

```javascript
            body += ZoomCreds.html(ev);
```

- [ ] **Step 3: 모달 오픈 후 바인딩**

lecture.js가 모달을 그리는 지점(`App.openModal` 또는 모달 컨테이너 채우는 곳) 직후에:

```javascript
        if (window.ZoomCreds) ZoomCreds.bind(document.body);
```

- [ ] **Step 4: 잔존 참조 확인**

Run: `cd /root/boot-dev && grep -n "lec-host-guide\|Zoom 비밀번호" public_html/js/lecture.js`
Expected: 출력 없음 (host-전용 비번 표기 모두 제거됨)

- [ ] **Step 5: commit**

```bash
cd /root/boot-dev
git add public_html/js/lecture.js
git commit -m "feat(boot): 특강 비번 host전용 표기 제거, 전원 노출 줌 ID/비번 줄로 통일"
```

---

## Task 8: 통합 스모크 + cache-buster 점검

**Files:**
- Modify (필요시): 각 진입 페이지의 `study.js` / `lecture.js` / `member-calendar-detail.js` cache-buster `?v=`

- [ ] **Step 1: 변경된 JS의 cache-buster 갱신**

수정한 JS 4개(`study.js`, `lecture.js`, `member-calendar-detail.js`, 신규 `zoom-credentials.js`)의 모든 진입점 `?v=` 갱신:

```bash
cd /root/boot-dev
for f in study lecture member-calendar-detail zoom-credentials; do grep -rn "${f}.js?v=" public_html; done
```

각 참조의 `?v=` 값을 `20260601`로 통일 (없는 신규 파일은 Task 4에서 추가됨).

- [ ] **Step 2: 단위 테스트 재실행**

Run: `cd /root/boot-dev && php tests/zoom_display_info_test.php`
Expected: `8 passed, 0 failed`

- [ ] **Step 3: 전체 lint**

Run:
```bash
cd /root/boot-dev
php -l public_html/includes/bootcamp_functions.php
php -l public_html/api/services/study.php
php -l public_html/api/services/lecture.php
```
Expected: 모두 `No syntax errors detected`

- [ ] **Step 4: 엔드포인트 응답 키 확인 (DEV DB에 ready 줌 있는 세션으로)**

study/lecture 상세 응답에 `zoom_meeting_id_display` 키가 포함되는지 가능한 방법으로 확인 (로그인 세션 필요 시 사용자 확인 단계로 위임). 최소한 헬퍼 단위 테스트로 로직은 보장됨.

- [ ] **Step 5: cache-buster commit**

```bash
cd /root/boot-dev
git add public_html
git commit -m "chore(boot): 줌 ID/비번 관련 JS cache-buster 갱신"
```

---

## Task 9: dev push + 사용자 확인 요청

- [ ] **Step 1: push origin dev**

```bash
cd /root/boot-dev && git push origin dev
```

- [ ] **Step 2: ⛔ 멈춤 — 사용자에게 확인 요청**

다음을 사용자에게 보고하고 **운영 반영은 사용자 명시 요청 시에만**:
- https://dev-boot.soritune.com 에서 복습스터디/특강/캘린더 상세 → 회의 ID·비번 표시 + 개별 복사 동작 확인 요청
- **복습 고정방 숫자 비밀번호 값**을 알려달라고 요청 → DEV `settings.study_fixed_zoom_password`에 `UPDATE`로 적용
  ```sql
  UPDATE settings SET value='<숫자비번>' WHERE `key`='study_fixed_zoom_password';
  ```
- 특강은 컬럼 비번이 있어 별도 입력 불필요

---

## 배포 (운영 반영 — 사용자 명시 요청 시에만)

1. `boot-dev`: `git checkout main && git merge dev && git push origin main && git checkout dev`
2. `boot-prod`: `git pull origin main`
3. PROD 설정 시드 + 비번 적용:
   ```bash
   cd /root/boot-prod && php /root/boot-dev/migrate_seed_study_fixed_zoom_password.php --db=prod
   ```
   이어서 PROD `.db_credentials`로 `UPDATE settings SET value='<숫자비번>' WHERE key='study_fixed_zoom_password';`

---

## Self-Review 메모

- **스펙 커버리지:** 헬퍼(A)=Task1, 엔드포인트 주입(A)=Task2, 설정 시드(B)=Task3, 프론트 공용+3파일(C)=Task4~7, 테스트(D)=Task1+Task8. 전원 노출/host표기제거=Task7. ✓
- **타입 일관성:** 헬퍼 반환 키 `zoom_meeting_id_display`/`zoom_password_display`를 프론트 `ZoomCreds.html(data.zoom_meeting_id_display ...)`에서 동일 사용. ✓
- **Placeholder:** CSS 파일/모달 루트/스크립트 include 경로는 구현 시 `grep`으로 확정하도록 명령 동봉. ✓
