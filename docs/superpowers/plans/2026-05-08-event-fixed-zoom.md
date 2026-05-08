# 이벤트 줌 링크 단계별 고정 URL 통일 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** boot 강의 달력의 1회성 "이벤트"가 매번 새 Zoom 미팅을 만들지 않고, 단계별 고정 Zoom URL(`getFixedZoomUrl`)을 쓰도록 통일하고, 12기 기존 이벤트는 소급 갱신.

**Architecture:** `lecture_events` 의 Zoom 처리를 `lecture_schedules` 와 동일한 방식으로 단순화. INSERT 직후 `getFixedZoomUrl(stage)` 로 즉시 URL 세팅 (Zoom API 호출 제거). 12기 active 이벤트는 1회성 PHP 마이그로 백필.

**Tech Stack:** PHP 8.x, MySQL/MariaDB (PDO), 기존 boot 코드베이스 패턴.

**Spec:** `docs/superpowers/specs/2026-05-08-event-fixed-zoom-design.md`

---

## File Structure

**Modify:**
- `public_html/api/services/lecture.php` — 이벤트 생성/재시도/Zoom 헬퍼 단순화

**Create:**
- `migrate_event_fixed_zoom.php` — 12기 active 이벤트 백필 1회성 스크립트

**Delete (cleanup):**
- `public_html/includes/zoom/zoom_client.php` — 이벤트 외 사용처 없음 확인됨, 마지막 단계에서 제거

---

## Task 1: 이벤트 생성에서 Zoom API 호출 제거 → fixed URL 사용

**Files:**
- Modify: `public_html/api/services/lecture.php` (현재 line ~439-456, `handleLectureEventCreate` 의 Zoom 미팅 생성 블록)

- [ ] **Step 1: 현재 구현 확인**

Read `public_html/api/services/lecture.php` 의 line 426~457 (`handleLectureEventCreate` 의 INSERT 후 Zoom 처리부).
현재는 INSERT 후 `callLectureEventZoomWebhook` 호출 → 응답에 Zoom 결과 포함.

- [ ] **Step 2: 코드 변경**

`handleLectureEventCreate` 함수에서 다음 블록을 교체.

기존 (line ~439-456):
```php
    // ── Zoom 미팅 생성 ──
    $zoomResult = callLectureEventZoomWebhook($db, $eventId, [
        'event_id'    => $eventId,
        'title'       => $title,
        'event_date'  => $eventDate,
        'start_time'  => $startTime,
        'end_time'    => $endDt->format('H:i'),
        'duration'    => 60,
        'host_account' => $hostAccount,
        'scheduled_at' => (new DateTime("{$eventDate} {$startTimeFull}", new DateTimeZone('Asia/Seoul')))->format('c'),
    ]);

    jsonSuccess([
        'event_id'     => $eventId,
        'title'        => $title,
        'zoom_join_url' => $zoomResult['zoom_join_url'] ?? null,
        'zoom_status'  => $zoomResult['success'] ? 'ready' : 'failed',
    ], '이벤트가 생성되었습니다.');
```

변경:
```php
    // ── 단계별 고정 Zoom URL 설정 (반복 강의와 동일 방식) ──
    $zoomJoinUrl = getFixedZoomUrl((int)$stage);

    $db->prepare("
        UPDATE lecture_events
        SET zoom_join_url = ?, zoom_status = 'ready', zoom_error_message = NULL
        WHERE id = ?
    ")->execute([$zoomJoinUrl, $eventId]);

    jsonSuccess([
        'event_id'     => $eventId,
        'title'        => $title,
        'zoom_join_url' => $zoomJoinUrl,
        'zoom_status'  => 'ready',
    ], '이벤트가 생성되었습니다.');
```

`stage` 가 NULL 이어도 `(int)null === 0` → `getFixedZoomUrl` 의 fallback 으로 1단계 URL. 사용자 요구(전체/1단계 → 1단계) 충족.

- [ ] **Step 3: PHP 문법 체크**

Run: `php -l /root/boot-dev/public_html/api/services/lecture.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: DEV 수동 스모크 — 신규 이벤트 생성**

브라우저에서 `dev-boot.soritune.com` 어드민 → 강의 달력 → "이벤트 생성"
- 1단계 이벤트 생성 → 응답 `zoom_join_url` 이 1단계 fixed URL(`83473209444`) 로 시작하는지 확인
- 2단계 이벤트 생성 → 응답 `zoom_join_url` 이 2단계 fixed URL(`88641942993`) 로 시작하는지 확인
- "선택 안 함 (전체)" 이벤트 생성 → 1단계 fixed URL 로 시작하는지 확인

DB 확인:
```bash
cd /root/boot-dev && source .db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT id, stage, zoom_status, LEFT(zoom_join_url, 60) AS url
FROM lecture_events WHERE status='active'
ORDER BY id DESC LIMIT 5;"
```
Expected: 새로 만든 이벤트들의 `zoom_status='ready'`, URL 이 단계별로 정확히 매핑됨.

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/lecture.php
git commit -m "$(cat <<'EOF'
refactor(lecture): event creation uses fixed zoom url per stage

이벤트(lecture_events) 생성 시 Zoom API 로 새 미팅을 만드는 대신,
반복 강의(lecture_schedules)와 동일하게 getFixedZoomUrl(stage)
의 단계별 고정 URL 사용. stage NULL/0/1 → 1단계 URL, 2 → 2단계 URL.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: 재시도 엔드포인트를 fixed URL 세팅으로 단순화

**Files:**
- Modify: `public_html/api/services/lecture.php` (`handleLectureEventZoomRetry`, 현재 line ~515-555)

- [ ] **Step 1: 코드 변경**

`handleLectureEventZoomRetry` 함수 본문을 다음으로 교체.

변경 후 (전체 함수):
```php
/**
 * 이벤트 Zoom 재시도 — 단계별 fixed URL 로 (재)세팅
 * legacy 'failed' 이벤트 복구 또는 stage 변경 후 갱신용
 */
function handleLectureEventZoomRetry($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);
    $input = getJsonInput();

    $eventId = (int)($input['event_id'] ?? 0);
    if (!$eventId) jsonError('event_id 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT le.id, le.stage
        FROM lecture_events le
        WHERE le.id = ? AND le.status = 'active'
    ");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();
    if (!$event) jsonError('이벤트를 찾을 수 없습니다.', 404);

    $zoomJoinUrl = getFixedZoomUrl((int)$event['stage']);

    $db->prepare("
        UPDATE lecture_events
        SET zoom_join_url = ?, zoom_status = 'ready', zoom_error_message = NULL
        WHERE id = ?
    ")->execute([$zoomJoinUrl, $eventId]);

    jsonSuccess([
        'zoom_join_url' => $zoomJoinUrl,
    ], 'Zoom URL이 설정되었습니다.');
}
```

- [ ] **Step 2: PHP 문법 체크**

Run: `php -l /root/boot-dev/public_html/api/services/lecture.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: DEV 수동 스모크 — 재시도 버튼**

기존 이벤트(예: id=4, stage=NULL) 가 있는 상태에서 강의 달력 UI 의 "재시도" 또는 zoom_join_url 갱신 버튼을 클릭. 또는 직접 API 호출:
```bash
curl -X POST 'https://dev-boot.soritune.com/api/bootcamp.php?action=lecture_event_zoom_retry' \
  -H 'Content-Type: application/json' \
  --cookie '...' \
  -d '{"event_id":4}'
```
Expected: `success: true`, `zoom_join_url` 이 1단계 fixed URL.

DB 확인 후, 해당 이벤트의 zoom_join_url 이 fixed URL 로 갱신되었는지 확인.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/lecture.php
git commit -m "$(cat <<'EOF'
refactor(lecture): simplify event zoom retry to set fixed url

재시도 엔드포인트는 더 이상 Zoom API 를 호출하지 않고 단계별 fixed URL
을 (재)세팅. legacy 'failed' 이벤트 1건씩 복구 또는 stage 변경 후
갱신 용도. UI 버튼 호환 유지.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: 사용 안 하는 Zoom API 헬퍼 제거

**Files:**
- Modify: `public_html/api/services/lecture.php` (`callLectureEventZoomWebhook`, `failLectureZoomEvent` 두 함수 제거)

- [ ] **Step 1: 다른 사용처 재확인**

Run:
```bash
grep -rn "callLectureEventZoomWebhook\|failLectureZoomEvent" /root/boot-dev --include="*.php"
```
Expected: 두 함수 모두 lecture.php 정의부 외 호출처 없음 (Task 1·2 에서 제거됨).

- [ ] **Step 2: 코드 변경 — 두 함수 삭제**

`public_html/api/services/lecture.php` 의 line ~723-767 (현재 spec 기준; Task 1·2 변경으로 줄 번호 이동했을 수 있음)

다음 두 함수 블록을 통째로 삭제:
- `function callLectureEventZoomWebhook(PDO $db, int $eventId, array $payload): array { ... }` (현재 line 723)
- `function failLectureZoomEvent(PDO $db, int $eventId, string $errorMsg): array { ... }` (현재 line 761)

함수 위의 `/** ... */` 주석 블록도 함께 제거. 다른 함수에 영향 없는 독립 블록.

- [ ] **Step 3: PHP 문법 체크**

Run: `php -l /root/boot-dev/public_html/api/services/lecture.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: 다른 함수 lint — undefined function 호출 없음 확인**

Run:
```bash
grep -n "callLectureEventZoomWebhook\|failLectureZoomEvent" /root/boot-dev/public_html/api/services/lecture.php
```
Expected: 출력 없음 (모두 제거됨).

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/lecture.php
git commit -m "$(cat <<'EOF'
chore(lecture): remove unused Zoom API event helpers

callLectureEventZoomWebhook / failLectureZoomEvent 는 이벤트가
fixed URL 로 전환되면서 호출처 사라짐. YAGNI.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: 12기 이벤트 백필 마이그레이션 스크립트 작성

**Files:**
- Create: `migrate_event_fixed_zoom.php`

- [ ] **Step 1: 마이그 스크립트 작성**

`/root/boot-dev/migrate_event_fixed_zoom.php` 파일 생성:

```php
<?php
/**
 * 12기 active lecture_events 의 zoom_join_url 을 단계별 fixed URL 로 백필.
 * 1회성. 실행:
 *   cd /root/boot-dev && php migrate_event_fixed_zoom.php           # dry-run
 *   cd /root/boot-dev && php migrate_event_fixed_zoom.php --apply  # 실제 적용
 */

require_once __DIR__ . '/public_html/includes/db.php';
require_once __DIR__ . '/public_html/api/services/lecture.php'; // getFixedZoomUrl

$apply = in_array('--apply', $argv ?? [], true);

$db = getDB();

$stmt = $db->prepare("
    SELECT le.id, le.stage, le.event_date, le.title, le.zoom_status,
           LEFT(le.zoom_join_url, 60) AS url_prefix
    FROM lecture_events le
    JOIN cohorts c ON le.cohort_id = c.id
    WHERE c.cohort = '12기' AND le.status = 'active'
    ORDER BY le.event_date
");
$stmt->execute();
$events = $stmt->fetchAll();

if (!$events) {
    echo "12기 active 이벤트 없음. 종료.\n";
    exit(0);
}

echo "대상 이벤트 " . count($events) . "건:\n";
foreach ($events as $e) {
    $newUrl = getFixedZoomUrl((int)$e['stage']);
    $newPrefix = substr($newUrl, 0, 60);
    $changes = $e['url_prefix'] !== $newPrefix ? '⇒ 변경' : '동일(skip)';
    echo sprintf(
        "  id=%d stage=%s date=%s '%s' status=%s\n    before: %s\n    after : %s  %s\n",
        $e['id'], $e['stage'] ?? 'NULL', $e['event_date'], $e['title'],
        $e['zoom_status'], $e['url_prefix'], $newPrefix, $changes
    );
}

if (!$apply) {
    echo "\n--apply 옵션 없음. dry-run 종료.\n";
    exit(0);
}

echo "\n실제 적용 시작...\n";
$db->beginTransaction();
try {
    $upd = $db->prepare("
        UPDATE lecture_events
        SET zoom_join_url = ?, zoom_status = 'ready',
            zoom_error_message = NULL,
            zoom_meeting_id = NULL, zoom_password = NULL
        WHERE id = ?
    ");
    foreach ($events as $e) {
        $newUrl = getFixedZoomUrl((int)$e['stage']);
        $upd->execute([$newUrl, $e['id']]);
    }
    $db->commit();
    echo "완료: " . count($events) . "건 갱신.\n";
} catch (\Throwable $ex) {
    $db->rollBack();
    echo "롤백. 오류: " . $ex->getMessage() . "\n";
    exit(1);
}
```

- [ ] **Step 2: PHP 문법 체크**

Run: `php -l /root/boot-dev/migrate_event_fixed_zoom.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: DEV dry-run**

Run: `cd /root/boot-dev && php migrate_event_fixed_zoom.php`
Expected: 12기 이벤트 1건(`id=4`, stage=NULL → 1단계 URL) 변경 예정으로 출력. 적용 안 됨.

- [ ] **Step 4: DEV 실제 적용**

Run: `cd /root/boot-dev && php migrate_event_fixed_zoom.php --apply`
Expected: `완료: 1건 갱신.`

DB 확인:
```bash
source /root/boot-dev/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT id, stage, zoom_status, LEFT(zoom_join_url, 60) AS url, zoom_meeting_id, zoom_password
FROM lecture_events le JOIN cohorts c ON le.cohort_id=c.id
WHERE c.cohort='12기' AND le.status='active';"
```
Expected: id=4 의 url 이 `https://us02web.zoom.us/j/83473209444?...` (1단계 URL) 로 시작, `zoom_status='ready'`, `zoom_meeting_id`/`zoom_password` NULL.

- [ ] **Step 5: 재실행 idempotent 확인**

Run: `cd /root/boot-dev && php migrate_event_fixed_zoom.php`
Expected: 모두 "동일(skip)" 로 표시.

Run again with `--apply`:
Expected: 똑같이 1건 갱신 (이미 같은 값을 다시 SET — DB row 변화 없음). 안전하게 재실행 가능.

- [ ] **Step 6: Commit**

```bash
cd /root/boot-dev
git add migrate_event_fixed_zoom.php
git commit -m "$(cat <<'EOF'
chore(migrate): backfill 12기 events to use fixed zoom url

1회성 마이그. 12기 active lecture_events 의 zoom_join_url 을
단계별 getFixedZoomUrl 결과로 갱신. zoom_meeting_id/password 정리.
--apply 없이 dry-run, idempotent.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: zoom_client.php 정리 (cleanup)

**Files:**
- Delete: `public_html/includes/zoom/zoom_client.php`
- Possibly delete: `public_html/includes/zoom/` (디렉토리 비면)

- [ ] **Step 1: 다른 사용처 재확인**

Run:
```bash
grep -rn "zoom_client\|zoomCreateMeeting\|zoomGetAccessToken" /root/boot-dev --include="*.php" | grep -v "includes/zoom/zoom_client.php"
```
Expected: 출력 없음 (lecture.php 의 require/호출이 Task 3 에서 제거됨).

만약 다른 사용처가 출력되면 이 Task 5 는 skip 하고 plan 종료.

- [ ] **Step 2: 파일 삭제**

```bash
rm /root/boot-dev/public_html/includes/zoom/zoom_client.php
rmdir /root/boot-dev/public_html/includes/zoom 2>/dev/null || true
```

`rmdir` 실패해도 무시 (다른 파일 있으면 디렉토리 유지).

- [ ] **Step 3: 디렉토리 상태 확인**

Run: `ls /root/boot-dev/public_html/includes/zoom/ 2>/dev/null || echo "directory removed"`
Expected: 디렉토리가 비어 있어 제거되었거나, 다른 파일이 남아 있음.

- [ ] **Step 4: 전체 grep 으로 dangling reference 없음 확인**

Run:
```bash
grep -rn "includes/zoom\|zoom_client" /root/boot-dev/public_html --include="*.php" 2>/dev/null
```
Expected: 출력 없음.

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev
git add -A public_html/includes/zoom
git commit -m "$(cat <<'EOF'
chore: remove unused Zoom API client

이벤트가 fixed URL 로 전환되면서 zoom_client.php 의 호출처 사라짐.
(반복 강의는 이전부터 fixed URL 사용.) 향후 다시 필요해지면 git
history 에서 복구 가능.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: DEV 종합 검증

- [ ] **Step 1: DEV 신규 이벤트 3건 생성 후 URL 확인**

브라우저 또는 API 로 stage=1, stage=2, stage=전체 이벤트 각 1건 생성.

DB 확인:
```bash
source /root/boot-dev/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT id, stage, zoom_status, LEFT(zoom_join_url, 60) AS url
FROM lecture_events WHERE status='active'
ORDER BY id DESC LIMIT 5;"
```
Expected:
- stage=1 → URL `83473209444` 로 시작
- stage=2 → URL `88641942993` 로 시작
- stage=NULL(전체) → URL `83473209444` 로 시작
- 모든 row `zoom_status='ready'`

- [ ] **Step 2: 12기 백필 결과 재확인**

```bash
source /root/boot-dev/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT le.id, le.stage, le.zoom_status, LEFT(le.zoom_join_url, 60) AS url
FROM lecture_events le JOIN cohorts c ON le.cohort_id=c.id
WHERE c.cohort='12기' AND le.status='active';"
```
Expected: id=4 (DEV 12기 이벤트) URL 이 1단계 fixed URL.

- [ ] **Step 3: 강의 달력 UI 정상 렌더 확인**

브라우저에서 `dev-boot.soritune.com/admin` 또는 코치 페이지에서 강의 달력 진입 → 4월·5월·6월 이벤트 칩이 정상 표시되고 클릭 시 줌 링크가 fixed URL 로 열리는지 확인.

테스트로 만든 이벤트는 DB 에서 직접 정리하거나 UI 에서 취소.

- [ ] **Step 4: Push to dev**

```bash
cd /root/boot-dev && git push origin dev
```

- [ ] **Step 5: STOP — 사용자 검증 대기**

⛔ 여기서 멈춤. 사용자에게 DEV 검증 + 운영 반영 승인 요청. 사용자가 "운영 반영해줘" 등으로 명시할 때까지 main 머지/PROD pull/PROD 백필 진행 금지.

---

## Task 7: PROD 반영 (사용자 승인 후)

⚠️ **사용자가 "운영 반영해줘" 등으로 명시한 경우에만 실행.**

- [ ] **Step 1: main 머지 + push**

```bash
cd /root/boot-dev
git checkout main
git merge dev --no-ff -m "Merge dev: event fixed zoom unification + 12기 backfill"
git push origin main
git checkout dev
```

- [ ] **Step 2: PROD pull**

```bash
cd /root/boot-prod && git pull origin main
```

- [ ] **Step 3: PROD 백필 dry-run**

```bash
cd /root/boot-prod && php migrate_event_fixed_zoom.php
```
Expected: 12기 active 이벤트 3건(id=15/17/19, stage=1) 모두 "변경" 으로 출력.

- [ ] **Step 4: PROD 백필 적용**

```bash
cd /root/boot-prod && php migrate_event_fixed_zoom.php --apply
```
Expected: `완료: 3건 갱신.`

- [ ] **Step 5: PROD DB 검증**

```bash
source /root/boot-prod/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT le.id, le.stage, le.zoom_status, LEFT(le.zoom_join_url, 60) AS url
FROM lecture_events le JOIN cohorts c ON le.cohort_id=c.id
WHERE c.cohort='12기' AND le.status='active'
ORDER BY le.event_date;"
```
Expected: 3건 모두 URL `83473209444` (1단계 fixed) 로 시작, `zoom_status='ready'`.

- [ ] **Step 6: PROD UI 스모크**

`boot.soritune.com` 강의 달력에서 12기 이벤트 칩 클릭 → 1단계 fixed Zoom URL 로 열림 확인.

- [ ] **Step 7: 작업 완료 보고**

사용자에게 PROD 반영 완료, 12기 이벤트 3건 fixed URL 통일 완료 보고.
