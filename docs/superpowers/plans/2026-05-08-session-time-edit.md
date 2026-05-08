# 회차별 강의 시간 변경 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** boot 강의 달력에서 운영자가 한 회차(`lecture_sessions`)의 시작 시간만 다른 시간으로 옮길 수 있게 한다. 다른 회차에는 영향 없음.

**Architecture:** 새 API `lecture_session_update_time` 추가. 검증 + host 중복 검사(기존 `checkLectureOverlap`/`checkEventOverlap` 재사용) 후 `lecture_sessions` 의 `start_time`/`end_time`/`title` 한 행만 UPDATE. 부모 `lecture_schedules.start_time` 와 비교해 "시간변경" 배지 자동 표시 (신규 컬럼 없음).

**Tech Stack:** PHP 8.x, MySQL/MariaDB (PDO), 기존 boot 코드베이스 패턴, vanilla JS.

**Spec:** `docs/superpowers/specs/2026-05-08-session-time-edit-design.md`

---

## File Structure

**Modify:**
- `public_html/api/services/lecture.php` — 새 함수 + SQL 응답 보강 + checkLectureOverlap 시그니처 확장
- `public_html/api/bootcamp.php` — 신규 라우트 등록
- `public_html/js/lecture.js` — 모달 편집 폼 + 칩 배지 렌더링
- `public_html/css/lecture.css` (있으면 거기, 없으면 `style.css` 또는 인라인) — 시간변경 배지 스타일

---

## Task 1: `checkLectureOverlap` 에 `excludeSessionId` 옵션 추가

**Files:**
- Modify: `public_html/api/services/lecture.php` (line ~639, `checkLectureOverlap`)

기존 `excludeScheduleId` 는 schedule 단위로 제외 — 회차별 편집은 같은 schedule 의 다른 회차가 같은 날 다른 시간에 있을 가능성이 있어 너무 헐겁다. session id 단위 exclude 옵션을 추가한다 (backwards compatible).

- [ ] **Step 1: 코드 변경**

`public_html/api/services/lecture.php` 의 `checkLectureOverlap` 시그니처/본문을 다음으로 교체.

기존 (line ~639-665):
```php
function checkLectureOverlap(PDO $db, array $dates, string $startTime, string $hostAccount, ?int $excludeScheduleId = null): ?array {
    if (empty($dates)) return null;

    $placeholders = implode(',', array_fill(0, count($dates), '?'));
    $params = $dates;
    $params[] = $startTime;
    $params[] = $hostAccount;

    $excludeClause = '';
    if ($excludeScheduleId) {
        $excludeClause = 'AND ls.schedule_id != ?';
        $params[] = $excludeScheduleId;
    }

    $stmt = $db->prepare("
        SELECT ls.id, ls.lecture_date, ls.title, ls.start_time, ls.host_account
        FROM lecture_sessions ls
        WHERE ls.lecture_date IN ({$placeholders})
          AND ls.start_time = ?
          AND ls.host_account = ?
          AND ls.status = 'active'
          {$excludeClause}
        LIMIT 1
    ");
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}
```

변경:
```php
function checkLectureOverlap(
    PDO $db,
    array $dates,
    string $startTime,
    string $hostAccount,
    ?int $excludeScheduleId = null,
    ?int $excludeSessionId = null
): ?array {
    if (empty($dates)) return null;

    $placeholders = implode(',', array_fill(0, count($dates), '?'));
    $params = $dates;
    $params[] = $startTime;
    $params[] = $hostAccount;

    $excludeClauses = [];
    if ($excludeScheduleId) {
        $excludeClauses[] = 'AND ls.schedule_id != ?';
        $params[] = $excludeScheduleId;
    }
    if ($excludeSessionId) {
        $excludeClauses[] = 'AND ls.id != ?';
        $params[] = $excludeSessionId;
    }
    $excludeClause = implode(' ', $excludeClauses);

    $stmt = $db->prepare("
        SELECT ls.id, ls.lecture_date, ls.title, ls.start_time, ls.host_account
        FROM lecture_sessions ls
        WHERE ls.lecture_date IN ({$placeholders})
          AND ls.start_time = ?
          AND ls.host_account = ?
          AND ls.status = 'active'
          {$excludeClause}
        LIMIT 1
    ");
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}
```

기존 호출처(line ~410, ~155 등) 변경 없음 (5번째 인자만 사용).

- [ ] **Step 2: PHP 문법 체크**

Run: `php -l /root/boot-dev/public_html/api/services/lecture.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: 기존 호출처 영향 없음 확인**

Run:
```bash
grep -n "checkLectureOverlap(" /root/boot-dev/public_html/api/services/lecture.php
```
Expected: 정의 1개 + 호출 ≥1개. 모든 호출이 5개 이하 인자 (6번째 인자 미사용) — 새 옵션 호환됨.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/lecture.php
git commit -m "$(cat <<'EOF'
refactor(lecture): add excludeSessionId option to checkLectureOverlap

회차별 시간 편집 시 같은 schedule 의 본인 row 만 exclude 하도록
session id 옵션 추가. 기존 호출처는 5인자만 쓰므로 영향 없음.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: 세션 SQL 응답에 `schedule_start_time` / `duration_minutes` 노출

**Files:**
- Modify: `public_html/api/services/lecture.php` (line ~206-218 `handleLectureSessions`, line ~237-247 `handleLectureSessionDetail`)

배지 표시 + 편집 시 duration 참조용. 양쪽 SELECT 에 컬럼만 추가.

- [ ] **Step 1: 월별 목록 SELECT 보강**

`handleLectureSessions` (line ~206) 의 SELECT 절을 다음과 같이 변경.

기존:
```php
    $stmt = $db->prepare("
        SELECT ls.id, ls.schedule_id, ls.title, ls.lecture_date, ls.start_time, ls.end_time,
               ls.stage, ls.host_account, ls.status, ls.coach_admin_id,
               a.name AS coach_name,
               lsch.zoom_status
        FROM lecture_sessions ls
        JOIN admins a ON ls.coach_admin_id = a.id
        JOIN lecture_schedules lsch ON ls.schedule_id = lsch.id
        WHERE ls.lecture_date BETWEEN ? AND ?
          AND ls.status = 'active'
          {$cohortWhere}
        ORDER BY ls.lecture_date, ls.start_time
    ");
```

변경:
```php
    $stmt = $db->prepare("
        SELECT ls.id, ls.schedule_id, ls.title, ls.lecture_date, ls.start_time, ls.end_time,
               ls.stage, ls.host_account, ls.status, ls.coach_admin_id,
               a.name AS coach_name,
               lsch.zoom_status,
               lsch.start_time AS schedule_start_time
        FROM lecture_sessions ls
        JOIN admins a ON ls.coach_admin_id = a.id
        JOIN lecture_schedules lsch ON ls.schedule_id = lsch.id
        WHERE ls.lecture_date BETWEEN ? AND ?
          AND ls.status = 'active'
          {$cohortWhere}
        ORDER BY ls.lecture_date, ls.start_time
    ");
```

- [ ] **Step 2: 상세 SELECT 보강**

`handleLectureSessionDetail` (line ~237) 의 SELECT 절을 다음과 같이 변경.

기존:
```php
    $stmt = $db->prepare("
        SELECT ls.*, a.name AS coach_name,
               lsch.zoom_meeting_id, lsch.zoom_join_url, lsch.zoom_start_url,
               lsch.zoom_password, lsch.zoom_status, lsch.zoom_error_message,
               lsch.host_account AS schedule_host_account
        FROM lecture_sessions ls
        JOIN admins a ON ls.coach_admin_id = a.id
        JOIN lecture_schedules lsch ON ls.schedule_id = lsch.id
        WHERE ls.id = ?
    ");
```

변경:
```php
    $stmt = $db->prepare("
        SELECT ls.*, a.name AS coach_name,
               lsch.zoom_meeting_id, lsch.zoom_join_url, lsch.zoom_start_url,
               lsch.zoom_password, lsch.zoom_status, lsch.zoom_error_message,
               lsch.host_account AS schedule_host_account,
               lsch.start_time AS schedule_start_time,
               lsch.duration_minutes AS schedule_duration_minutes
        FROM lecture_sessions ls
        JOIN admins a ON ls.coach_admin_id = a.id
        JOIN lecture_schedules lsch ON ls.schedule_id = lsch.id
        WHERE ls.id = ?
    ");
```

- [ ] **Step 3: PHP 문법 체크**

Run: `php -l /root/boot-dev/public_html/api/services/lecture.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: SQL 검증**

Run:
```bash
source /root/boot-dev/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT ls.id, ls.start_time, ls.end_time, lsch.start_time AS schedule_start_time, lsch.duration_minutes
FROM lecture_sessions ls
JOIN lecture_schedules lsch ON ls.schedule_id = lsch.id
WHERE ls.status='active'
ORDER BY ls.id DESC LIMIT 3;"
```
Expected: 3 rows with both `start_time` and `schedule_start_time` columns; `duration_minutes` numeric (default 60).

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/lecture.php
git commit -m "$(cat <<'EOF'
feat(lecture): expose schedule template time/duration in session SQL

월별 list 응답과 상세 응답에 lecture_schedules.start_time /
duration_minutes 노출. 회차별 시간 편집 + 시간변경 배지 표시 기반.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: 백엔드 `handleLectureSessionUpdateTime` + 라우트 등록

**Files:**
- Modify: `public_html/api/services/lecture.php` (새 함수 추가)
- Modify: `public_html/api/bootcamp.php` (line ~287, 강의 라우트 그룹에 신규 case)

- [ ] **Step 1: bootcamp.php 라우트 등록**

`public_html/api/bootcamp.php` 의 line ~287-291 (강의 세션 라우트 그룹) 에서:

기존:
```php
case 'lecture_sessions':          handleLectureSessions(); break;
case 'lecture_session_detail':    handleLectureSessionDetail(); break;
case 'lecture_schedule_create':   handleLectureScheduleCreate($method); break;
case 'lecture_schedule_cancel':   handleLectureScheduleCancel($method); break;
case 'lecture_zoom_retry':        handleLectureZoomRetry($method); break;
```

변경:
```php
case 'lecture_sessions':            handleLectureSessions(); break;
case 'lecture_session_detail':      handleLectureSessionDetail(); break;
case 'lecture_session_update_time': handleLectureSessionUpdateTime($method); break;
case 'lecture_schedule_create':     handleLectureScheduleCreate($method); break;
case 'lecture_schedule_cancel':     handleLectureScheduleCancel($method); break;
case 'lecture_zoom_retry':          handleLectureZoomRetry($method); break;
```

- [ ] **Step 2: lecture.php 에 신규 함수 추가**

`public_html/api/services/lecture.php` 의 `handleLectureSessionDetail` 직후 (line ~262 이후) 에 다음 함수를 추가.

```php
/**
 * 회차별 시간 변경 — 한 lecture_sessions row 의 start_time / end_time / title 만 UPDATE.
 * 부모 lecture_schedules 와 다른 회차에는 영향 없음.
 *
 * 권한: operation / head / subhead1 / subhead2.
 * 검증: 시간 형식 + active 상태 + host 중복 (다른 강의 / 이벤트).
 * 제목은 `[HH:MM] ...` prefix 패턴 매칭 시 시간 부분만 갱신.
 */
function handleLectureSessionUpdateTime($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    $sessionId = (int)($input['session_id'] ?? 0);
    $startTime = trim($input['start_time'] ?? '');

    if (!$sessionId) jsonError('session_id 필요');
    if (!preg_match('/^\d{2}:\d{2}$/', $startTime)) jsonError('시간 형식: HH:MM');
    [$h, $m] = explode(':', $startTime);
    if ((int)$h < 0 || (int)$h > 23 || (int)$m < 0 || (int)$m > 59) {
        jsonError('유효하지 않은 시간입니다.');
    }

    $db = getDB();

    $stmt = $db->prepare("
        SELECT ls.id, ls.schedule_id, ls.lecture_date, ls.start_time, ls.end_time,
               ls.host_account, ls.title, ls.status,
               lsch.duration_minutes
        FROM lecture_sessions ls
        JOIN lecture_schedules lsch ON ls.schedule_id = lsch.id
        WHERE ls.id = ?
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    if (!$session) jsonError('강의 회차를 찾을 수 없습니다.', 404);
    if ($session['status'] !== 'active') jsonError('취소된 회차는 시간을 변경할 수 없습니다.');

    $duration = (int)$session['duration_minutes'];
    if ($duration <= 0) $duration = 60;

    $startTimeFull = $startTime . ':00';

    // 변경 후 같은 시작 시간이면 no-op (early return)
    if ($session['start_time'] === $startTimeFull) {
        jsonSuccess([
            'session_id' => $sessionId,
            'start_time' => $startTimeFull,
            'end_time'   => $session['end_time'],
            'title'      => $session['title'],
            'changed'    => false,
        ], '시간 변경 없음.');
    }

    // end_time 계산
    $startDt = new DateTime("2000-01-01 {$startTimeFull}");
    $endDt = clone $startDt;
    $endDt->modify("+{$duration} minutes");
    $endTimeFull = $endDt->format('H:i:s');

    // host 중복 — 다른 강의 회차
    $overlap = checkLectureOverlap(
        $db,
        [$session['lecture_date']],
        $startTimeFull,
        $session['host_account'],
        null,
        $sessionId
    );
    if ($overlap) {
        jsonError("중복: {$overlap['lecture_date']} {$overlap['title']} — 같은 시간·호스트 계정에 다른 강의가 있습니다.");
    }

    // host 중복 — 이벤트
    $overlapEvt = checkEventOverlap($db, $session['lecture_date'], $startTimeFull, $session['host_account']);
    if ($overlapEvt) {
        jsonError("중복: {$overlapEvt['event_date']} {$overlapEvt['title']} — 같은 시간·호스트 계정에 이벤트가 있습니다.");
    }

    // 제목 prefix 자동 갱신 (`[HH:MM] ...` 패턴만)
    $newTitle = $session['title'];
    if (preg_match('/^\[\d{2}:\d{2}\]/', $newTitle)) {
        $newTitle = preg_replace('/^\[\d{2}:\d{2}\]/', "[{$startTime}]", $newTitle);
    }

    $db->prepare("
        UPDATE lecture_sessions
        SET start_time = ?, end_time = ?, title = ?
        WHERE id = ?
    ")->execute([$startTimeFull, $endTimeFull, $newTitle, $sessionId]);

    jsonSuccess([
        'session_id' => $sessionId,
        'start_time' => $startTimeFull,
        'end_time'   => $endTimeFull,
        'title'      => $newTitle,
        'changed'    => true,
    ], '시간이 변경되었습니다.');
}
```

- [ ] **Step 3: PHP 문법 체크**

```bash
php -l /root/boot-dev/public_html/api/services/lecture.php
php -l /root/boot-dev/public_html/api/bootcamp.php
```
Expected: 둘 다 `No syntax errors detected`.

- [ ] **Step 4: 정적 검증 — 함수 / 라우트 둘 다 존재**

```bash
grep -n "handleLectureSessionUpdateTime\|lecture_session_update_time" /root/boot-dev/public_html/api/services/lecture.php /root/boot-dev/public_html/api/bootcamp.php
```
Expected: 라우트 case 1개 + 함수 정의 1개 + (옵션) 함수 호출 1개.

- [ ] **Step 5: 충돌 거부 케이스 1건 수동 검증**

DEV DB에 같은 날·같은 host_account 강의 세션이 2개 이상 있는 경우를 찾는다:
```bash
source /root/boot-dev/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT lecture_date, host_account, COUNT(*) AS cnt
FROM lecture_sessions WHERE status='active'
GROUP BY lecture_date, host_account HAVING cnt > 1 LIMIT 5;"
```

해당 케이스가 있으면 한 세션의 시간을 다른 세션의 시간으로 바꾸려고 시도해서 거부 메시지 확인. 없으면 이 step skip — Task 6 종합 검증에서 다룬다.

- [ ] **Step 6: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/lecture.php public_html/api/bootcamp.php
git commit -m "$(cat <<'EOF'
feat(lecture): per-session time update API

새 라우트 lecture_session_update_time + handleLectureSessionUpdateTime.
한 lecture_sessions row 의 start/end/title 만 갱신. 부모 schedule
미변경. host 중복 검사(강의/이벤트). 제목 [HH:MM] prefix 자동 갱신.
권한 = operation/head/subhead.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: 모달에 "시간 변경" 폼 추가

**Files:**
- Modify: `public_html/js/lecture.js` (line ~147-205 `openDetail`)

운영자 권한일 때만 노출. 현재 시작 시간 prefilled, 저장 버튼 클릭 시 신규 API 호출 → 모달 + 달력 reload.

- [ ] **Step 1: openDetail 함수 본문에 편집 폼 + handler 추가**

`public_html/js/lecture.js` 의 `openDetail` 함수 본문에서 "Cancel button (admin only)" 블록 직전(현재 line ~187 `const canCancel = ...` 직전)에 다음을 추가.

```javascript
        // Edit time form (admin only)
        const canEditTime = ['operation', 'head', 'subhead1', 'subhead2'].includes(role);
        if (canEditTime) {
            const curStart = (s.start_time || '').substring(0, 5);
            body += `
                <div class="lec-detail-edit-area">
                    <div class="lec-detail-edit-label">시간 변경</div>
                    <div class="lec-detail-edit-row">
                        <input type="time" id="lec-edit-time" class="form-input" value="${curStart}">
                        <button class="btn btn-primary btn-sm" id="btn-lec-edit-time" data-session="${s.id}">저장</button>
                    </div>
                </div>
            `;
        }
```

`App.openModal(...)` 호출 직후, "Bind events" 블록 (`const retryBtn = ...` 위) 에 다음 핸들러 바인딩을 추가.

```javascript
        const editBtn = document.getElementById('btn-lec-edit-time');
        if (editBtn) {
            editBtn.onclick = async () => {
                const newTime = document.getElementById('lec-edit-time').value;
                if (!newTime || !/^\d{2}:\d{2}$/.test(newTime)) {
                    Toast.error('시간 형식: HH:MM');
                    return;
                }
                App.showLoading();
                const r = await App.post(API + 'lecture_session_update_time', {
                    session_id: parseInt(editBtn.dataset.session),
                    start_time: newTime,
                });
                App.hideLoading();
                if (r.success) {
                    Toast.success(r.message || '시간이 변경되었습니다.');
                    App.closeModal();
                    loadAllData();
                }
            };
        }
```

- [ ] **Step 2: JS 문법 체크**

Run: `node --check /root/boot-dev/public_html/js/lecture.js`
Expected: 출력 없거나 OK. (구문 에러 시 실패.)

- [ ] **Step 3: DEV 수동 스모크 — 편집 폼 노출 확인**

브라우저로 `dev-boot.soritune.com` 어드민 → 강의 달력 → 임의의 세션 클릭. 운영자 계정으로 보면 모달에 "시간 변경" 입력 + 저장 버튼이 보이는지 확인. 코치 계정으로는 보이지 않는지 확인.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev
git add public_html/js/lecture.js
git commit -m "$(cat <<'EOF'
feat(lecture): session detail modal — per-session time edit form

운영자 권한일 때만 시간 변경 input + 저장 버튼 노출. 저장 시 신규
lecture_session_update_time API 호출 → 성공 시 모달 닫고 달력 reload.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: 달력 칩 + 모달에 "시간변경" 배지

**Files:**
- Modify: `public_html/js/lecture.js` (line ~80-87 칩 렌더링, line ~155-165 모달 시간 라벨)
- Modify: `public_html/css/lecture.css` (있으면 거기에 추가; 없으면 inline style)

`s.start_time !== s.schedule_start_time` 일 때 표시.

- [ ] **Step 1: lecture.css 위치 확인**

```bash
ls /root/boot-dev/public_html/css/lecture.css 2>&1 || ls /root/boot-dev/public_html/css/ | grep -i lecture
```
파일이 있으면 거기 추가. 없으면 Step 2 의 CSS 규칙은 `<style>` 태그로 lecture.js 의 init 시 한 번만 주입하거나 가장 가까운 기존 css 파일에 추가. 결과를 Step 4 의 `git add` 에 반영.

- [ ] **Step 2: 칩 렌더링에 배지 표시**

`public_html/js/lecture.js` 의 `renderChips` 함수 안 "기존 특강 칩" 분기(line ~79-87) 를 다음으로 교체.

기존:
```javascript
                    // 기존 특강 칩
                    const stageClass = `stage-${s.stage}`;
                    const zoomClass = s.zoom_status === 'failed' ? 'zoom-failed' : '';
                    const mineClass = highlightAdminId && parseInt(s.coach_admin_id) === highlightAdminId ? 'lec-chip-mine' : '';
                    const timeLabel = (s.start_time || '').substring(0, 5);
                    const stageLabel = STAGE_LABELS[s.stage] || '';
                    const firstLine = `${timeLabel} ${stageLabel}`;
                    const coachName = App.esc(s.coach_name || '');
                    return `<div class="lec-chip ${stageClass} ${zoomClass} ${mineClass}" data-id="${s.id}" title="${App.esc(s.title)}"><span class="chip-line1">${firstLine}</span><span class="chip-line2">${coachName}</span></div>`;
```

변경:
```javascript
                    // 기존 특강 칩
                    const stageClass = `stage-${s.stage}`;
                    const zoomClass = s.zoom_status === 'failed' ? 'zoom-failed' : '';
                    const mineClass = highlightAdminId && parseInt(s.coach_admin_id) === highlightAdminId ? 'lec-chip-mine' : '';
                    const timeChanged = s.schedule_start_time && s.start_time !== s.schedule_start_time;
                    const changedClass = timeChanged ? 'lec-chip-time-changed' : '';
                    const timeLabel = (s.start_time || '').substring(0, 5);
                    const stageLabel = STAGE_LABELS[s.stage] || '';
                    const badge = timeChanged ? '<span class="lec-chip-badge">시간변경</span>' : '';
                    const firstLine = `${timeLabel} ${stageLabel}${badge}`;
                    const coachName = App.esc(s.coach_name || '');
                    return `<div class="lec-chip ${stageClass} ${zoomClass} ${mineClass} ${changedClass}" data-id="${s.id}" title="${App.esc(s.title)}"><span class="chip-line1">${firstLine}</span><span class="chip-line2">${coachName}</span></div>`;
```

- [ ] **Step 3: 모달 시간 라벨에 원래 시간 안내**

`openDetail` 함수 안 시간 라벨 표시 부분(line ~155, `const timeLabel = ...`) 직후 to body building (line ~161 `<div class="lec-detail-row"><span class="lec-detail-label">시간</span> ...`) 부분을 다음과 같이 변경.

기존:
```javascript
        const timeLabel = (s.start_time || '').substring(0, 5) + ' ~ ' + (s.end_time || '').substring(0, 5);
        const stageLabel = STAGE_LABELS[s.stage] || s.stage;

        let body = `
            <div class="lec-detail-info">
                <div class="lec-detail-row"><span class="lec-detail-label">날짜</span><span class="lec-detail-value">${dateKo}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">시간</span><span class="lec-detail-value">${App.esc(timeLabel)}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">코치</span><span class="lec-detail-value">${App.esc(s.coach_name)}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">단계</span><span class="lec-detail-value">${App.esc(stageLabel)}</span></div>
            </div>
        `;
```

변경:
```javascript
        const timeLabel = (s.start_time || '').substring(0, 5) + ' ~ ' + (s.end_time || '').substring(0, 5);
        const stageLabel = STAGE_LABELS[s.stage] || s.stage;
        const timeChanged = s.schedule_start_time && s.start_time !== s.schedule_start_time;
        const origTimeNote = timeChanged
            ? ` <span class="lec-detail-changed-note">(원래 ${(s.schedule_start_time || '').substring(0, 5)})</span>`
            : '';
        const timeBadge = timeChanged
            ? ' <span class="lec-chip-badge lec-detail-badge">시간변경</span>'
            : '';

        let body = `
            <div class="lec-detail-info">
                <div class="lec-detail-row"><span class="lec-detail-label">날짜</span><span class="lec-detail-value">${dateKo}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">시간</span><span class="lec-detail-value">${App.esc(timeLabel)}${timeBadge}${origTimeNote}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">코치</span><span class="lec-detail-value">${App.esc(s.coach_name)}</span></div>
                <div class="lec-detail-row"><span class="lec-detail-label">단계</span><span class="lec-detail-value">${App.esc(stageLabel)}</span></div>
            </div>
        `;
```

- [ ] **Step 4: CSS 추가**

Step 1 에서 확인한 `lecture.css` 파일이 있으면 거기에, 없으면 가장 가까운 기존 강의 관련 CSS 파일(예: `style.css` 또는 `admin.css`)에 다음 규칙을 추가.

```css
/* 회차별 시간 변경 배지 */
.lec-chip-badge {
    display: inline-block;
    margin-left: 4px;
    padding: 0 4px;
    border-radius: 3px;
    background: #FF5E00;
    color: #fff;
    font-size: 10px;
    font-weight: 600;
    line-height: 14px;
}
.lec-chip.lec-chip-time-changed {
    box-shadow: inset 0 0 0 2px #FF5E00;
}
.lec-detail-badge {
    font-size: 11px;
    line-height: 16px;
}
.lec-detail-changed-note {
    color: #888;
    font-size: 12px;
}
.lec-detail-edit-area {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #eee;
}
.lec-detail-edit-label {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
    color: #555;
}
.lec-detail-edit-row {
    display: flex;
    gap: 8px;
    align-items: center;
}
.lec-detail-edit-row .form-input {
    max-width: 140px;
}
```

(메모리 룰: SoriTune Orange `#FF5E00` 사용.)

- [ ] **Step 5: JS / CSS 문법 체크**

```bash
node --check /root/boot-dev/public_html/js/lecture.js
```
CSS 는 별도 lint 없음 — 시각 확인은 Step 6 에서.

- [ ] **Step 6: DEV 수동 스모크 — 배지 표시**

브라우저로 `dev-boot.soritune.com` 강의 달력 → Task 4 완료 후 시간 변경한 적이 없으므로 아직 배지 보이지 않는 게 정상. Task 6 의 종합 검증에서 변경 후 배지 노출까지 확인.

- [ ] **Step 7: Commit**

`lecture.css` (또는 결정된 CSS 파일 경로) + `lecture.js` 모두 add.

```bash
cd /root/boot-dev
git add public_html/js/lecture.js public_html/css/lecture.css   # CSS 파일 경로는 Step 1 결과에 맞게
git commit -m "$(cat <<'EOF'
feat(lecture): chip + modal time-changed badge

s.start_time !== s.schedule_start_time 인 회차에 '시간변경' 배지
표시 (달력 칩 + 상세 모달). 모달 시간 라벨에 원래 시간 부주석.
SoriTune Orange 컬러 토큰.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: DEV 종합 검증 + dev push + STOP

- [ ] **Step 1: 모든 commit 확인**

```bash
cd /root/boot-dev && git log --oneline origin/dev..HEAD
```
Expected: spec 1건 + 본 plan 의 task 1~5 commits.

- [ ] **Step 2: DEV 정상 시간 변경 시나리오**

브라우저 → `dev-boot.soritune.com` 어드민 → 강의 달력 → 임의의 미래 세션 클릭 → 운영자 모달에서 시간 변경 (예: 20:30 → 19:30) → 저장.

확인:
- 토스트 "시간이 변경되었습니다." 노출
- 모달 닫힘 + 달력 reload
- 해당 회차 칩의 시간이 19:30 으로 변경
- 칩에 "시간변경" 배지 노출
- 다시 클릭 → 모달에 "시간변경" 배지 + "(원래 20:30)" 안내

DB 확인:
```bash
source /root/boot-dev/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT ls.id, ls.lecture_date, ls.start_time, ls.end_time, ls.title,
       lsch.start_time AS schedule_start_time
FROM lecture_sessions ls
JOIN lecture_schedules lsch ON ls.schedule_id = lsch.id
WHERE ls.id = <변경한 session id>;"
```
Expected:
- ls.start_time = 19:30:00, ls.end_time = 20:30:00 (60분)
- title 의 `[HH:MM]` prefix 가 `[19:30]` 으로 갱신 (원래 prefix 가 있던 경우)
- lsch.start_time 은 원래 값 유지 (변경 안 됨)

- [ ] **Step 3: DEV 충돌 거부 시나리오**

다른 회차가 같은 날·같은 host_account 의 다른 시간으로 있을 때, 그 시간으로 옮기려 시도 → 거부 메시지 + 변경 안 됨.

또는 같은 날 이벤트가 있을 때 그 시간으로 옮기려 시도 → 거부.

원하는 충돌 케이스가 없으면 임시로 sessions/events 를 INSERT 해서 검증 후 삭제.

- [ ] **Step 4: DEV 권한 분리 확인**

코치 계정으로 로그인 → 같은 모달에서 "시간 변경" 폼 미노출 확인.

- [ ] **Step 5: 코드 무결성 lint**

```bash
php -l /root/boot-dev/public_html/api/services/lecture.php
php -l /root/boot-dev/public_html/api/bootcamp.php
node --check /root/boot-dev/public_html/js/lecture.js
```
모두 OK.

- [ ] **Step 6: Push to dev**

```bash
cd /root/boot-dev && git push origin dev
```

- [ ] **Step 7: STOP — 사용자 검증 대기**

⛔ 여기서 멈춤. 사용자에게 DEV 검증 + 5/11 stage=2 시간 변경 적용 승인 요청. 사용자가 "운영 반영해줘" 등으로 명시할 때까지 main 머지/PROD pull 진행 금지.

---

## Task 7: PROD 반영 (사용자 승인 후)

⚠️ **사용자가 "운영 반영해줘" 등으로 명시한 경우에만 실행.**

- [ ] **Step 1: main 머지 + push**

```bash
cd /root/boot-dev
git checkout main
git merge dev --no-ff -m "Merge dev: per-session time edit"
git push origin main
git checkout dev
```

- [ ] **Step 2: PROD pull**

```bash
cd /root/boot-prod && git pull origin main
```
Expected: fast-forward.

- [ ] **Step 3: PROD lint**

```bash
php -l /root/boot-prod/public_html/api/services/lecture.php
php -l /root/boot-prod/public_html/api/bootcamp.php
```
Expected: OK.

- [ ] **Step 4: PROD 5/11 stage=2 회차 시간 변경**

`boot.soritune.com` 어드민 → 강의 달력 → 5월 11일 → 20:30 stage=2 회차 클릭 → 시간 변경 19:30 → 저장.

확인:
- 칩 시간이 19:30 으로 표시 + 시간변경 배지 노출
- 모달 다시 클릭 시 (원래 20:30) 안내 노출

DB 확인:
```bash
source /root/boot-prod/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT id, lecture_date, start_time, end_time, title
FROM lecture_sessions
WHERE lecture_date='2026-05-11' AND stage=2 AND status='active';"
```
Expected: start_time=19:30:00, end_time=20:30:00, title `[19:30]` prefix.

- [ ] **Step 5: 작업 완료 보고**

사용자에게 PROD 반영 + 5/11 회차 시간 변경 완료 보고.
