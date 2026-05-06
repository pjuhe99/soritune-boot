# Zoom 미팅 생성 자체 구현 (n8n 의존 제거) — 설계

날짜: 2026-05-06
범위: boot.soritune.com (boot-dev → boot-prod)

## 배경

`lecture_events` 1회성 코치 강의 이벤트를 생성할 때, n8n 외부 인스턴스(`https://yekong.app.n8n.cloud`)의 webhook을 호출해 Zoom 미팅을 만들고 있다. 자체 코드로 대체해 외부 의존을 제거한다.

조사 결과 실제 n8n 호출 경로는 1곳뿐이다.

- `public_html/api/services/lecture.php:716 callLectureEventZoomWebhook()` — `lecture_zoom_webhook_url` setting을 사용. 호출처 `lecture.php:437`(이벤트 생성), `lecture.php:534`(Zoom 재시도).
- `public_html/api/services/study.php`의 `callZoomWebhook()`, `retryZoomForSession()` — 정의만 있고 호출 경로 없음 (dead code). study session은 모두 `study_fixed_zoom_url` 고정 링크 사용.

자체 구현 후 dead code도 함께 정리한다.

## 변경 범위

신규
- `public_html/includes/zoom/zoom_client.php` — Server-to-Server OAuth + 미팅 API 래퍼
- `keys/zoom.json` — `{accountId, clientId, clientSecret}` (gitignore, chmod 600)

수정
- `public_html/api/services/lecture.php`
  - `callLectureEventZoomWebhook()` 본문을 `zoom_client.php` 호출로 교체. 함수 시그니처/반환값 동일.
  - 실패 시 `lecture_events` 갱신 SQL 중복 → 헬퍼 `failLectureZoomEvent()` 로 추출.
  - 주석에서 "n8n webhook" 표현 제거.

삭제 (dead code 정리)
- `public_html/api/services/study.php`의 `callZoomWebhook()`, `retryZoomForSession()` 제거.

설정
- `settings` 테이블에 `zoom_host_coach1`, `zoom_host_coach2` 두 행 추가 (값은 운영자가 직접 입력).
- `lecture_zoom_webhook_url`, `study_zoom_webhook_url` 설정 행은 즉시 삭제하지 않음. 1~2주 안정 운영 후 별도 PR로 cleanup.

영향 없음
- `study_fixed_zoom_url` 와 study session 생성 흐름은 그대로 유지.
- 어드민 UI / 회원 페이지 / DB 스키마 변경 없음.

## 자격증명 & 토큰 캐시

`keys/zoom.json` (n8n credential에서 복사)
```json
{
  "accountId": "...",
  "clientId": "...",
  "clientSecret": "..."
}
```
- 위치: `_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json` (DEV), `_______site_SORITUNECOM_BOOT/keys/zoom.json` (PROD)
- 권한: `chmod 600`. owner는 웹서버 계정 — `keys/solapi.json` 와 동일.
- gitignore: `keys/` 디렉토리는 이미 `.gitignore` 에 등록돼 있어 자동 제외됨 (`keys/solapi.json` 와 동일).

토큰 캐시
- 경로: `keys/zoom_token_cache.json`
- 형식: `{"access_token":"...","expires_at":1745000000}` (epoch seconds)
- 만료 60초 전부터 갱신.
- 동시 요청 충돌 방지: `flock()` 으로 파일 락.

## 인터페이스

`zoom_client.php` 공개 함수

```php
function zoomLoadKeys(): array;       // keys/zoom.json 로드 (정적 캐시)
function zoomGetAccessToken(): string; // OAuth 토큰 (캐시/갱신)
function zoomCreateMeeting(string $hostUserId, array $payload): array;
//   payload: { topic, start_time(ISO8601 with offset), duration(분), timezone, type=2 }
//   return:  { meeting_id, join_url, start_url, password }
//   throws RuntimeException on HTTP error / parse failure
```

호출 흐름 (`lecture.php`)
```php
function callLectureEventZoomWebhook(PDO $db, int $eventId, array $payload): array {
    require_once __DIR__ . '/../../includes/zoom/zoom_client.php';

    $hostKey = $payload['host_account']; // 'coach1' | 'coach2'
    $hostUserId = getSetting("zoom_host_{$hostKey}");
    if (!$hostUserId) {
        return failLectureZoomEvent($db, $eventId, "zoom_host_{$hostKey} 미설정");
    }

    try {
        $result = zoomCreateMeeting($hostUserId, [
            'topic'      => $payload['title'],
            'type'       => 2,
            'start_time' => $payload['scheduled_at'],   // 이미 ISO8601 +09:00
            'duration'   => (int)$payload['duration'],
            'timezone'   => 'Asia/Seoul',
        ]);
    } catch (RuntimeException $e) {
        return failLectureZoomEvent($db, $eventId, mb_substr($e->getMessage(), 0, 500));
    }

    $db->prepare("
        UPDATE lecture_events
        SET zoom_meeting_id = ?, zoom_join_url = ?, zoom_start_url = ?, zoom_password = ?,
            zoom_status = 'ready', zoom_error_message = NULL
        WHERE id = ?
    ")->execute([
        (string)$result['meeting_id'],
        $result['join_url'],
        $result['start_url'],
        $result['password'],
        $eventId,
    ]);

    return ['success' => true, 'zoom_join_url' => $result['join_url']];
}
```

함수 시그니처/반환값을 유지하므로 기존 호출처 (`lecture.php:437`, `lecture.php:534`) 는 수정하지 않는다.

## Zoom API 호출

OAuth 토큰 발급
```
POST https://zoom.us/oauth/token?grant_type=account_credentials&account_id={accountId}
Authorization: Basic base64(clientId:clientSecret)
```
응답 `{access_token, expires_in (=3600)}`.

미팅 생성
```
POST https://api.zoom.us/v2/users/{hostUserId}/meetings
Authorization: Bearer {access_token}
Content-Type: application/json
```
요청 body
```json
{
  "topic": "이벤트 제목",
  "type": 2,
  "start_time": "2026-05-10T20:00:00+09:00",
  "duration": 60,
  "timezone": "Asia/Seoul"
}
```
미팅 settings 객체는 보내지 않는다. Zoom 사용자 기본 설정을 그대로 사용 (어드민에서 한 번 정해두면 일관, 변경 시 코드 수정 불필요).

응답 사용 부분
```json
{
  "id": 12345678901,
  "join_url": "https://us02web.zoom.us/j/.../?pwd=...",
  "start_url": "https://us02web.zoom.us/s/.../?zak=...",
  "password": "abc123"
}
```
`id` 는 number 이지만 컬럼이 `VARCHAR(50)` 이므로 `(string)` 캐스팅해 저장.

## 에러 처리

- HTTP 4xx → `RuntimeException("Zoom API {code}: {message}")`. 상위에서 `zoom_status='failed'` + `zoom_error_message` (500자 잘라 저장).
- HTTP 5xx / cURL error → 동일 처리.
- 401 (토큰 만료/무효) 발생 시 → 캐시 파일 무효화 후 1회만 재시도. 재시도도 401이면 throw.
- `getSetting("zoom_host_*")` 가 비어 있으면 API 호출 자체를 시도하지 않고 즉시 실패 처리.
- 동작은 기존 n8n 호출 실패와 동일 — 어드민 화면의 재시도 버튼 (`handleLectureEventZoomRetry`) 그대로 사용.

## 마이그레이션 & 배포

DEV (`boot-dev`)
1. `keys/zoom.json` 작성, `chmod 600`.
2. DEV DB
   ```sql
   INSERT INTO settings (`key`,`value`,updated_at) VALUES
     ('zoom_host_coach1','<email-or-userid>', NOW()),
     ('zoom_host_coach2','<email-or-userid>', NOW())
   ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),updated_at=NOW();
   ```
3. 코드 commit & push (dev 브랜치)
4. `dev-boot.soritune.com` 검증
   - 강의 이벤트 1개 생성 → `zoom_status='ready'` + 4개 컬럼 채워지는지
   - 어드민 입장 링크 정상 진입
   - host 매핑 비어있는 케이스 → `zoom_status='failed'` + 메시지 저장
   - 재시도 버튼 정상 동작
5. **사용자 확인 요청 후 정지**

PROD (`boot-prod`) — 사용자 명시 요청 후에만
6. PROD DB 동일 INSERT.
7. PROD `keys/zoom.json` 동일 자격증명/권한.
8. main 머지 + `git pull`.
9. 다음 강의 이벤트 생성 시 자체 코드 동작 확인.

롤백
- `lecture_zoom_webhook_url` setting 행 즉시 삭제 안 함. 비상시 함수 본문 git revert 만으로 옛 흐름 복귀 가능.
- n8n 워크플로우 즉시 비활성화하지 않음. PROD 검증 후 양쪽 의존성 끊기.
- 1~2주 안정 운영 후 별도 PR로 webhook URL setting 행과 n8n 워크플로우 정리.

테스트 자동화 — 이번 범위 밖
- Zoom API mocking 가치 낮음, 실제 검증은 DEV/PROD 호출이 더 신뢰성 있음.
- `zoom_client.php` 토큰 캐시 만료 로직만 가벼운 단위 테스트 가능 — 후속.
