# Zoom 자체 구현 (n8n 의존 제거) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** boot의 `lecture_events` 1회성 강의 Zoom 미팅 생성을 n8n webhook 대신 boot 서버의 PHP 코드로 직접 호출하도록 전환하고, dead code 정리.

**Architecture:** 신규 모듈 `public_html/includes/zoom/zoom_client.php` (Server-to-Server OAuth + 미팅 생성 API 래퍼) → `lecture.php` 의 기존 함수 본문만 교체. 토큰은 `settings` 테이블 (`zoom_oauth_token`) 에 캐시. 호스트 매핑은 `settings` 테이블 (`zoom_host_coach1`, `zoom_host_coach2`).

**Tech Stack:** PHP 8.x, cURL, MariaDB (settings 테이블), Zoom REST API v2, Zoom OAuth (Server-to-Server, `meeting:write:admin` scope).

**Spec:** `docs/superpowers/specs/2026-05-06-zoom-self-hosted-design.md`

**Working directory:** `/root/boot-dev` (dev 브랜치). 코드 작업은 모두 여기서. PROD 반영은 사용자 명시 요청 시에만.

---

## File Structure

신규
- `public_html/includes/zoom/zoom_client.php` — OAuth + 미팅 생성 래퍼 (단일 파일, ~150줄 예상)

수정
- `public_html/api/services/lecture.php`
  - `callLectureEventZoomWebhook()` 본문 교체 (Task 4)
  - `failLectureZoomEvent()` 헬퍼 추가 (Task 4)
  - 이벤트 생성 핸들러 응답의 `zoom_status` 'pending' → 'failed' (Task 5)
- `public_html/api/services/study.php` — dead code 제거 (Task 6)

서버 사이드 (코드 외)
- `keys/zoom.json` 배치 (Task 7, 사용자 또는 작업자가 서버에서 직접 수행)
- DEV DB `settings` 행 INSERT (Task 7)

---

### Task 1: `zoom_client.php` 골격 + `zoomLoadKeys()`

**Files:**
- Create: `/root/boot-dev/public_html/includes/zoom/zoom_client.php`

- [ ] **Step 1: 디렉토리 확인 후 파일 생성**

```bash
mkdir -p /root/boot-dev/public_html/includes/zoom
```

파일 내용:

```php
<?php
/**
 * Zoom Server-to-Server OAuth + 미팅 API 클라이언트.
 * - keys/zoom.json 에서 자격증명 로드
 * - settings.zoom_oauth_token 에 access_token 캐시 (만료 60초 전부터 갱신)
 * - 401 발생 시 토큰 무효화 + 1회 재시도
 */

declare(strict_types=1);

const ZOOM_API_BASE                  = 'https://api.zoom.us/v2';
const ZOOM_OAUTH_BASE                = 'https://zoom.us/oauth';
const ZOOM_TOKEN_REFRESH_BUFFER_SEC  = 60;

/**
 * keys/zoom.json 로드 (정적 캐시).
 * 기대 형식: {"accountId":"...","clientId":"...","clientSecret":"..."}
 */
function zoomLoadKeys(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $path = dirname(__DIR__, 3) . '/keys/zoom.json';
    if (!file_exists($path)) {
        throw new RuntimeException("keys/zoom.json 없음: {$path}");
    }
    $raw = file_get_contents($path);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)
        || empty($data['accountId'])
        || empty($data['clientId'])
        || empty($data['clientSecret'])) {
        throw new RuntimeException('keys/zoom.json 형식 오류 (accountId/clientId/clientSecret 필수)');
    }
    $cache = $data;
    return $cache;
}
```

- [ ] **Step 2: 문법 체크**

```bash
php -l /root/boot-dev/public_html/includes/zoom/zoom_client.php
```
Expected: `No syntax errors detected ...`

실 호출 검증은 `keys/zoom.json` 과 네트워크가 필요하므로 Task 7~8 까지 보류.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && \
git add public_html/includes/zoom/zoom_client.php && \
git commit -m "feat(zoom): zoom_client.php 골격 + zoomLoadKeys"
```

---

### Task 2: `zoomGetAccessToken()` — settings 테이블 캐시 + OAuth 발급

**Files:**
- Modify: `/root/boot-dev/public_html/includes/zoom/zoom_client.php` (함수 추가)

`getSettingFresh()` / `updateSetting()` 는 `public_html/config.php` 에 이미 존재하며 boot.php 라우터를 거치는 모든 요청에서 자동 로드됨. CLI smoke 에서는 `require_once __DIR__ . '/../../config.php'` 가 필요.

- [ ] **Step 1: 함수 추가 — 파일 끝에 append**

`/root/boot-dev/public_html/includes/zoom/zoom_client.php` 의 끝에 추가:

```php
/**
 * Server-to-Server OAuth access token 조회.
 * - settings.zoom_oauth_token 에서 캐시된 토큰을 읽고, 만료 60초 전이 아니면 재사용
 * - 만료/없음 또는 $forceRefresh=true 면 OAuth 호출 후 캐시 갱신
 *
 * @throws RuntimeException OAuth HTTP/parse 실패 시
 */
function zoomGetAccessToken(bool $forceRefresh = false): string {
    if (!$forceRefresh) {
        $cached = getSettingFresh('zoom_oauth_token');
        if ($cached) {
            $data = json_decode((string)$cached, true);
            if (is_array($data)
                && !empty($data['access_token'])
                && (int)($data['expires_at'] ?? 0) > time() + ZOOM_TOKEN_REFRESH_BUFFER_SEC) {
                return (string)$data['access_token'];
            }
        }
    }

    $keys = zoomLoadKeys();
    $url  = ZOOM_OAUTH_BASE . '/token?grant_type=account_credentials&account_id=' . urlencode($keys['accountId']);
    $auth = base64_encode($keys['clientId'] . ':' . $keys['clientSecret']);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new RuntimeException("Zoom OAuth cURL: {$err}");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Zoom OAuth HTTP {$code}: " . substr((string)$response, 0, 300));
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data) || empty($data['access_token']) || empty($data['expires_in'])) {
        throw new RuntimeException('Zoom OAuth 응답 파싱 실패');
    }

    // 캐시 저장 (실패해도 현재 요청은 진행)
    try {
        updateSetting('zoom_oauth_token', json_encode([
            'access_token' => $data['access_token'],
            'expires_at'   => time() + (int)$data['expires_in'],
        ], JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {
        error_log('zoom_oauth_token settings 저장 실패: ' . $e->getMessage());
    }

    return (string)$data['access_token'];
}
```

- [ ] **Step 2: 문법 체크**

```bash
php -l /root/boot-dev/public_html/includes/zoom/zoom_client.php
```
Expected: `No syntax errors detected ...`

- [ ] **Step 3: 실 호출 검증은 Task 7~8 까지 보류**

이유: 실제 OAuth 호출에는 `keys/zoom.json` 과 네트워크 연결이 필요. 이 task 단계에서는 lint/구조만 검증.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && \
git add public_html/includes/zoom/zoom_client.php && \
git commit -m "feat(zoom): zoomGetAccessToken — settings 테이블 토큰 캐시"
```

---

### Task 3: `zoomCreateMeeting()` + 401 재시도

**Files:**
- Modify: `/root/boot-dev/public_html/includes/zoom/zoom_client.php` (함수 추가)

- [ ] **Step 1: 함수 추가**

파일 끝에 추가:

```php
/**
 * Zoom 미팅 생성. 토큰 캐시 사용.
 * - 401 발생 시 캐시 토큰을 강제 갱신하여 1회 재시도 (재시도도 401이면 throw)
 * - 응답에 id 또는 join_url 누락 시 throw (빈 row 저장 방지)
 *
 * @param string $hostUserId  Zoom 사용자 ID 또는 이메일
 * @param array  $payload     Zoom POST body (topic, type, start_time, duration, timezone 등)
 * @return array { meeting_id: string, join_url: string, password: ?string }
 * @throws RuntimeException
 */
function zoomCreateMeeting(string $hostUserId, array $payload): array {
    return zoomCreateMeetingWithToken($hostUserId, $payload, zoomGetAccessToken(false), false);
}

function zoomCreateMeetingWithToken(string $hostUserId, array $payload, string $token, bool $isRetry): array {
    $url  = ZOOM_API_BASE . '/users/' . rawurlencode($hostUserId) . '/meetings';
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new RuntimeException("Zoom API cURL: {$err}");
    }

    if ($code === 401 && !$isRetry) {
        $newToken = zoomGetAccessToken(true);
        return zoomCreateMeetingWithToken($hostUserId, $payload, $newToken, true);
    }

    if ($code < 200 || $code >= 300) {
        $errData = json_decode((string)$response, true);
        $msg     = is_array($errData) && !empty($errData['message'])
            ? $errData['message']
            : substr((string)$response, 0, 300);
        throw new RuntimeException("Zoom API {$code}: {$msg}");
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data) || empty($data['id']) || empty($data['join_url'])) {
        throw new RuntimeException('Zoom 응답 누락: id/join_url');
    }

    return [
        'meeting_id' => (string)$data['id'],
        'join_url'   => (string)$data['join_url'],
        'password'   => isset($data['password']) ? (string)$data['password'] : null,
    ];
}
```

- [ ] **Step 2: 문법 체크**

```bash
php -l /root/boot-dev/public_html/includes/zoom/zoom_client.php
```
Expected: `No syntax errors detected ...`

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && \
git add public_html/includes/zoom/zoom_client.php && \
git commit -m "feat(zoom): zoomCreateMeeting — POST + 401 재시도 + 응답 검증"
```

---

### Task 4: `lecture.php` — `callLectureEventZoomWebhook()` 본문 교체 + `failLectureZoomEvent()` 추출

**Files:**
- Modify: `/root/boot-dev/public_html/api/services/lecture.php` (함수 본문 교체, 헬퍼 추가)

기존 함수 본문 (line 713~779) 을 교체하고, 그 위치에 헬퍼 `failLectureZoomEvent()` 도 함께 둔다. 호출처 (`lecture.php:437`, `lecture.php:534`) 는 함수 호출 인자/반환값이 동일해서 수정 불필요.

- [ ] **Step 1: 기존 함수와 주변 주석을 통째로 교체**

기존 (Read 로 정확한 매칭 확보 필요):

```php
/**
 * n8n webhook 호출하여 이벤트 Zoom meeting 생성
 */
function callLectureEventZoomWebhook(PDO $db, int $eventId, array $payload): array {
    $webhookUrl = getSetting('lecture_zoom_webhook_url');
    if (!$webhookUrl) {
        $db->prepare("
            UPDATE lecture_events SET zoom_status = 'failed', zoom_error_message = 'webhook URL 미설정'
            WHERE id = ?
        ")->execute([$eventId]);
        return ['success' => false, 'error' => 'Zoom webhook URL이 설정되지 않았습니다.'];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode < 200 || $httpCode >= 300) {
        $errorMsg = $curlError ?: "HTTP {$httpCode}";
        $db->prepare("
            UPDATE lecture_events SET zoom_status = 'failed', zoom_error_message = ?
            WHERE id = ?
        ")->execute([mb_substr($errorMsg, 0, 500), $eventId]);
        return ['success' => false, 'error' => $errorMsg];
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['success'])) {
        $errorMsg = $data['error'] ?? $data['message'] ?? 'n8n 응답 파싱 실패';
        $db->prepare("
            UPDATE lecture_events SET zoom_status = 'failed', zoom_error_message = ?
            WHERE id = ?
        ")->execute([mb_substr($errorMsg, 0, 500), $eventId]);
        return ['success' => false, 'error' => $errorMsg];
    }

    $db->prepare("
        UPDATE lecture_events
        SET zoom_meeting_id = ?, zoom_join_url = ?, zoom_start_url = ?, zoom_password = ?,
            zoom_status = 'ready', zoom_error_message = NULL
        WHERE id = ?
    ")->execute([
        $data['zoom_meeting_id'] ?? null,
        $data['zoom_join_url'] ?? null,
        $data['zoom_start_url'] ?? null,
        $data['zoom_password'] ?? null,
        $eventId,
    ]);

    return [
        'success' => true,
        'zoom_join_url' => $data['zoom_join_url'] ?? null,
    ];
}
```

신규 (위 블록 전체를 아래로 치환):

```php
/**
 * Zoom 미팅 생성 (boot 자체 구현, n8n 의존 제거).
 * - host_account('coach1'|'coach2') → settings.zoom_host_<key> → Zoom userId 변환
 * - 실패 시 zoom_status='failed' + zoom_error_message 저장 후 반환
 * - zoom_start_url 은 만료 위험 + frontend 미사용 → NULL 유지
 */
function callLectureEventZoomWebhook(PDO $db, int $eventId, array $payload): array {
    require_once __DIR__ . '/../../includes/zoom/zoom_client.php';

    $hostKey = (string)($payload['host_account'] ?? '');
    if ($hostKey === '') {
        return failLectureZoomEvent($db, $eventId, 'host_account 미지정');
    }

    $hostUserId = getSetting("zoom_host_{$hostKey}");
    if (!$hostUserId) {
        return failLectureZoomEvent($db, $eventId, "zoom_host_{$hostKey} 미설정");
    }

    try {
        $result = zoomCreateMeeting((string)$hostUserId, [
            'topic'      => (string)($payload['title'] ?? ''),
            'type'       => 2,
            'start_time' => (string)($payload['scheduled_at'] ?? ''),
            'duration'   => (int)($payload['duration'] ?? 60),
            'timezone'   => 'Asia/Seoul',
        ]);
    } catch (\Throwable $e) {
        return failLectureZoomEvent($db, $eventId, mb_substr($e->getMessage(), 0, 500));
    }

    $db->prepare("
        UPDATE lecture_events
        SET zoom_meeting_id = ?, zoom_join_url = ?, zoom_start_url = NULL, zoom_password = ?,
            zoom_status = 'ready', zoom_error_message = NULL
        WHERE id = ?
    ")->execute([
        $result['meeting_id'],
        $result['join_url'],
        $result['password'],
        $eventId,
    ]);

    return [
        'success'       => true,
        'zoom_join_url' => $result['join_url'],
    ];
}

function failLectureZoomEvent(PDO $db, int $eventId, string $errorMsg): array {
    $db->prepare("
        UPDATE lecture_events SET zoom_status = 'failed', zoom_error_message = ?
        WHERE id = ?
    ")->execute([mb_substr($errorMsg, 0, 500), $eventId]);
    return ['success' => false, 'error' => $errorMsg];
}
```

- [ ] **Step 2: 문법 체크 + 호출처 인터페이스 회귀 확인**

```bash
php -l /root/boot-dev/public_html/api/services/lecture.php
```
Expected: `No syntax errors detected ...`

호출처가 `success`/`error`/`zoom_join_url` 키만 본다는 점 재확인:
```bash
grep -n "zoomResult" /root/boot-dev/public_html/api/services/lecture.php
```
Expected: `$zoomResult['success']`, `$zoomResult['zoom_join_url']`, `$zoomResult['error']` 만 등장. 다른 키 참조 없음.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && \
git add public_html/api/services/lecture.php && \
git commit -m "refactor(lecture): callLectureEventZoomWebhook 본문을 zoom_client 호출로 교체"
```

---

### Task 5: 이벤트 생성 응답의 `zoom_status` 'pending' → 'failed' 버그 수정

**Files:**
- Modify: `/root/boot-dev/public_html/api/services/lecture.php:448` (jsonSuccess 한 줄)

기존 동작: 생성 핸들러가 Zoom 실패해도 응답에 `zoom_status: 'pending'` 을 내려보내는데, DB 에는 `'failed'` 저장. UI 가 DB 와 어긋난 값으로 갱신될 가능성. 한 줄 변경.

- [ ] **Step 1: 줄 변경**

기존 (line 452):
```php
        'zoom_status'  => $zoomResult['success'] ? 'ready' : 'pending',
```

신규:
```php
        'zoom_status'  => $zoomResult['success'] ? 'ready' : 'failed',
```

- [ ] **Step 2: retry 핸들러는 영향 없음 확인**

`handleLectureEventZoomRetry` (line 512+) 는 실패 시 `jsonError(...)` 로 4xx 응답해서 `zoom_status` 키를 응답에 안 보냄 (`lecture.php:545~`). 따라서 retry 쪽 추가 변경 불필요.

```bash
grep -n "zoom_status" /root/boot-dev/public_html/api/services/lecture.php
```
Expected: `'zoom_status' => $zoomResult['success'] ? 'ready' : 'failed'` 한 곳만 jsonSuccess 응답에 등장. 그 외는 DB 갱신 SQL.

- [ ] **Step 3: 문법 체크**

```bash
php -l /root/boot-dev/public_html/api/services/lecture.php
```
Expected: `No syntax errors detected ...`

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && \
git add public_html/api/services/lecture.php && \
git commit -m "fix(lecture): 이벤트 생성 응답 zoom_status pending → failed (DB와 일치)"
```

---

### Task 6: `study.php` dead code 제거 (`callZoomWebhook`, `retryZoomForSession`)

**Files:**
- Modify: `/root/boot-dev/public_html/api/services/study.php` (함수 2개 삭제)

- [ ] **Step 1: 호출처 0개 재확인**

```bash
grep -rn "callZoomWebhook\b" /root/boot-dev/public_html
grep -rn "retryZoomForSession\b" /root/boot-dev/public_html
```
Expected: study.php 자기 자신 외 hit 없음 (선언/주석만). 만약 다른 파일에서 hit 발생하면 **삭제 보류** 후 사용자에게 보고.

- [ ] **Step 2: `retryZoomForSession()` 제거 (line ~475~494)**

기존 블록 (정확한 매칭은 Read 로 확보):

```php
/**
 * Zoom webhook 재시도 실행 (세션 row 기반)
 * callZoomWebhook()을 감싸는 편의 함수
 */
function retryZoomForSession($db, $sessionRow) {
    $sessionId = (int)$sessionRow['id'];
    $timeLabel = substr($sessionRow['start_time'], 0, 5);
    $endLabel = substr($sessionRow['end_time'], 0, 5);

    return callZoomWebhook($db, $sessionId, [
        'study_session_id' => $sessionId,
        'title' => $sessionRow['title'],
        'study_date' => $sessionRow['study_date'],
        'start_time' => $timeLabel,
        'end_time' => $endLabel,
        'duration' => 60,
        'host_nickname' => $sessionRow['host_nickname'],
        'scheduled_at' => (new DateTime("{$sessionRow['study_date']} {$sessionRow['start_time']}", new DateTimeZone('Asia/Seoul')))->format('c'),
    ]);
}
```

→ 함수 + 그 위 주석 블록을 통째로 삭제.

- [ ] **Step 3: `callZoomWebhook()` 제거 (line ~753~821)**

기존 블록:

```php
/**
 * n8n webhook 호출하여 Zoom meeting 생성
 */
function callZoomWebhook($db, $sessionId, $payload) {
    $webhookUrl = getSetting('study_zoom_webhook_url');
    if (!$webhookUrl) {
        // ... 본문 ...
    }
    // ... 끝까지 ...
}
```

→ 함수 + 위 주석 블록 통째로 삭제.

- [ ] **Step 4: 문법 체크**

```bash
php -l /root/boot-dev/public_html/api/services/study.php
```
Expected: `No syntax errors detected ...`

- [ ] **Step 5: 호출처 재검증 (lint 후)**

```bash
grep -rn "callZoomWebhook\|retryZoomForSession" /root/boot-dev/public_html
```
Expected: 0 hit (선언도 없어진 상태).

- [ ] **Step 6: Commit**

```bash
cd /root/boot-dev && \
git add public_html/api/services/study.php && \
git commit -m "refactor(study): 사용처 0인 callZoomWebhook/retryZoomForSession 제거"
```

---

### Task 7: 서버 사이드 준비 (DEV) — `keys/zoom.json` + settings 행

**Files:** (코드 변경 없음)

이 task 는 commit 대상이 아니다. 서버 상에서 직접 수행.

- [ ] **Step 1: `keys/zoom.json` 배치 (DEV)**

n8n credential 에서 `accountId`, `clientId`, `clientSecret` 3개 값을 확보한 뒤:

```bash
sudo install -m 640 -o root -g apache /dev/null /var/www/html/_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json
sudo tee /var/www/html/_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json > /dev/null <<'JSON'
{
  "accountId": "<n8n credential의 accountId>",
  "clientId": "<n8n credential의 clientId>",
  "clientSecret": "<n8n credential의 clientSecret>"
}
JSON
sudo chown root:apache /var/www/html/_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json
sudo chmod 640 /var/www/html/_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json
ls -la /var/www/html/_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json
```
Expected: `-rw-r----- 1 root apache ...`

- [ ] **Step 2: DEV DB settings INSERT**

n8n 워크플로우의 Zoom 노드에서 `coach1`/`coach2` 가 어떤 Zoom 사용자(이메일 또는 userId)에 매핑돼 있는지 확인 후:

```bash
source /root/boot-dev/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<SQL
INSERT INTO settings (\`key\`, \`value\`, updated_at) VALUES
  ('zoom_host_coach1', '<coach1@... 또는 zoom userId>', NOW()),
  ('zoom_host_coach2', '<coach2@... 또는 zoom userId>', NOW())
ON DUPLICATE KEY UPDATE \`value\`=VALUES(\`value\`), updated_at=NOW();
SELECT \`key\`, \`value\` FROM settings WHERE \`key\` LIKE 'zoom_host_%';
SQL
```
Expected: 두 행이 SELECT 에 표시됨.

- [ ] **Step 3: dev 브랜치 push (코드는 Task 1~6에서 commit 완료)**

```bash
cd /root/boot-dev && git push origin dev
```

---

### Task 8: DEV 검증 (`dev-boot.soritune.com`)

**Files:** (코드 변경 없음)

DEV 환경에서 실제 동작 확인. 어떤 단계라도 실패하면 사용자에게 보고하고 정지.

- [ ] **Step 1: 정상 케이스 — 강의 이벤트 1개 생성**

운영자 어드민으로 dev-boot.soritune.com 접속 → 강의 이벤트 1개 생성 (host: coach1 or coach2 중 하나, 시작 시간은 현재 +1시간 내외).

DB 상태 확인:
```bash
source /root/boot-dev/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
"SELECT id, title, host_account, zoom_status, zoom_meeting_id, LEFT(zoom_join_url,50) AS join_url, zoom_start_url, zoom_error_message FROM lecture_events ORDER BY id DESC LIMIT 1\G"
```
Expected:
- `zoom_status = ready`
- `zoom_meeting_id` 채워짐 (숫자 문자열)
- `zoom_join_url` 채워짐
- `zoom_start_url IS NULL`
- `zoom_error_message IS NULL`

토큰 캐시 행 생성 확인:
```bash
source /root/boot-dev/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
"SELECT \`key\`, LEFT(\`value\`,40) FROM settings WHERE \`key\`='zoom_oauth_token'"
```
Expected: 1행, value 가 `{"access_token":"..."` 로 시작.

- [ ] **Step 2: 호스트 입장 검증**

해당 코치가 본인 Zoom 계정으로 로그인된 상태에서 어드민 detail 의 `zoom_join_url` 클릭 → Zoom 클라이언트가 호스트로 인식하고 입장됨.

- [ ] **Step 3: 실패 케이스 1 — host 매핑 미설정**

settings 행 임시 비우기:
```bash
source /root/boot-dev/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
"UPDATE settings SET \`value\`='' WHERE \`key\`='zoom_host_coach1'"
```

어드민에서 coach1 호스트 강의 이벤트 1개 생성 시도 → 응답:
- HTTP 200, `data.zoom_status = 'failed'` (← Task 5 의 버그 fix 검증)
- DB: `zoom_status='failed'`, `zoom_error_message LIKE '%zoom_host_coach1 미설정%'`

확인 후 settings 복구:
```bash
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
"UPDATE settings SET \`value\`='<원래값>' WHERE \`key\`='zoom_host_coach1'"
```

- [ ] **Step 4: 실패 케이스 2 — 자격증명 손상 (OAuth 단계 실패 경로 검증)**

(주: 미팅 생성 401 retry 경로는 실 운영에서만 자연 발생하며 수동 재현 어렵다. 여기서는 OAuth 단계 실패 → 에러 전파를 검증한다.)

`keys/zoom.json` 의 clientSecret 을 임시 변경:
```bash
sudo cp /var/www/html/_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json /tmp/zoom_backup.json
sudo sed -i 's/"clientSecret": *"[^"]*"/"clientSecret":"BROKEN"/' /var/www/html/_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json
```

기존 토큰 캐시도 비워야 함 (안 비우면 캐시된 유효 토큰으로 통과):
```bash
source /root/boot-dev/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
"DELETE FROM settings WHERE \`key\`='zoom_oauth_token'"
```

어드민에서 강의 이벤트 1개 생성 시도 → 응답:
- DB: `zoom_status='failed'`, `zoom_error_message LIKE '%Zoom OAuth HTTP%'` (OAuth 단계에서 실패 → meeting create 자체에 가지 않음)

복구:
```bash
sudo cp /tmp/zoom_backup.json /var/www/html/_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json
sudo chown root:apache /var/www/html/_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json
sudo chmod 640 /var/www/html/_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json
sudo rm /tmp/zoom_backup.json
```

- [ ] **Step 5: 재시도 버튼 검증**

Step 3 또는 Step 4 에서 만든 `zoom_status='failed'` 이벤트 1개를 어드민에서 재시도 버튼 (`lecture_event_zoom_retry`) 클릭 (host 매핑/자격증명 모두 정상 복구 후) → DB `zoom_status='ready'` 로 전환되고 새 `zoom_meeting_id`/`zoom_join_url` 채워짐.

- [ ] **Step 6: dead code 회귀 확인 (optional)**

```bash
grep -rn "callZoomWebhook\|retryZoomForSession\|n8n" /root/boot-dev/public_html
```
Expected: 코드 hit 0. (`docs/` 의 spec/plan hit 만 무관하게 남음.)

---

### Task 9: 정지 후 사용자 확인 요청

- [ ] **Step 1: 사용자에게 보고**

다음 항목을 정리해서 보고하고 응답을 기다린다:
- DEV commits 목록 (Task 1~6)
- DEV 검증 결과 요약 (Task 8 의 6개 항목 통과 여부)
- PROD 반영 시 필요한 작업: PROD `keys/zoom.json` 배치, PROD DB INSERT (Task 7 과 동일), main 머지 + `git pull`
- "운영 반영해도 될까요?" 명시적 질문

⛔ **사용자가 "운영 반영해줘" 등 명시적 요청을 하기 전까지 PROD 작업 시작 금지.**

---

### Task 10: PROD 반영 (사용자 명시 요청 시에만)

**Files:** (코드 변경 없음)

- [ ] **Step 1: PROD `keys/zoom.json` 배치**

```bash
sudo install -m 640 -o root -g apache /dev/null /var/www/html/_______site_SORITUNECOM_BOOT/keys/zoom.json
sudo tee /var/www/html/_______site_SORITUNECOM_BOOT/keys/zoom.json > /dev/null <<'JSON'
{
  "accountId": "<DEV와 동일>",
  "clientId": "<DEV와 동일>",
  "clientSecret": "<DEV와 동일>"
}
JSON
sudo chown root:apache /var/www/html/_______site_SORITUNECOM_BOOT/keys/zoom.json
sudo chmod 640 /var/www/html/_______site_SORITUNECOM_BOOT/keys/zoom.json
```

- [ ] **Step 2: PROD DB settings INSERT**

```bash
source /root/boot-prod/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<SQL
INSERT INTO settings (\`key\`, \`value\`, updated_at) VALUES
  ('zoom_host_coach1', '<DEV와 동일>', NOW()),
  ('zoom_host_coach2', '<DEV와 동일>', NOW())
ON DUPLICATE KEY UPDATE \`value\`=VALUES(\`value\`), updated_at=NOW();
SELECT \`key\`, \`value\` FROM settings WHERE \`key\` LIKE 'zoom_host_%';
SQL
```

- [ ] **Step 3: main 머지 + push**

```bash
cd /root/boot-dev && \
git checkout main && \
git merge dev && \
git push origin main && \
git checkout dev
```

- [ ] **Step 4: PROD pull**

```bash
cd /root/boot-prod && git pull origin main
```

- [ ] **Step 5: PROD smoke**

다음 강의 이벤트 1건이 생성되는 즉시 (운영자가 일상 작업 중) DB 상태 확인:
```bash
source /root/boot-prod/.db_credentials && \
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
"SELECT id, title, host_account, zoom_status, zoom_meeting_id, LEFT(zoom_join_url,50) AS join_url, zoom_start_url FROM lecture_events ORDER BY id DESC LIMIT 1\G"
```
Expected: `zoom_status='ready'` + `zoom_join_url` 채워짐 + `zoom_start_url IS NULL`.

토큰 캐시:
```bash
mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e \
"SELECT \`key\`, LEFT(\`value\`,40) FROM settings WHERE \`key\`='zoom_oauth_token'"
```
Expected: 1행 존재.

---

## 후속 작업 (이번 plan 범위 밖)

별도 PR 로 처리:
- `lecture_zoom_webhook_url`, `study_zoom_webhook_url` settings 행 삭제 (1~2주 안정 운영 후)
- n8n 의 lecture-event Zoom 워크플로우 비활성화/삭제
- 호스트 시작 링크가 실제로 필요해지면 `lecture_event_zoom_start_url` endpoint 추가 (`GET /v2/meetings/{id}` 로 fresh start_url)
