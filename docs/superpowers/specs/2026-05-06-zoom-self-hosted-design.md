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
- `keys/zoom.json` — `{accountId, clientId, clientSecret}` (gitignore 자동 제외, 권한 `640 root:apache` — 아래 자격증명 섹션 참조)

수정
- `public_html/api/services/lecture.php`
  - `callLectureEventZoomWebhook()` 본문을 `zoom_client.php` 호출로 교체. 함수 시그니처/반환값 동일.
  - 실패 시 `lecture_events` 갱신 SQL 중복 → 헬퍼 `failLectureZoomEvent()` 로 추출.
  - 주석에서 "n8n webhook" 표현 제거.
  - **버그 동반 수정**: 이벤트 생성 핸들러 (`lecture.php:448`) 응답의 `zoom_status` 가 실패 시 `'pending'` 으로 내려가는 문제 — DB와 어긋남. `'failed'` 로 통일. retry 핸들러도 동일 점검.
  - **`zoom_start_url` 미저장 변경**: Zoom의 start_url 은 발급 후 약 2시간 후 만료될 수 있어, 며칠 뒤 예약된 강의에서 저장된 start_url 이 강의 시작 시점에 만료된 상태가 되는 위험이 있음. 현재 frontend 어느 파일도 `zoom_start_url` 을 사용하지 않음 (`grep -rln "start_url" public_html/js public_html/coach ... ` → 백엔드 저장/반환 코드만 hit). 따라서 `zoom_start_url` 컬럼은 NULL 로 두고 저장 흐름에서 제외. 향후 호스트 시작 링크가 실제로 필요해지면 별도 endpoint `lecture_event_zoom_start_url` 을 추가해 호출 시점에 `GET /meetings/{id}` 로 fresh 값 조회. (이번 범위 밖, 후속 hook으로만 명시.)

삭제 (dead code 정리)
- `public_html/api/services/study.php`의 `callZoomWebhook()`, `retryZoomForSession()` 제거.
- 삭제 직전 검증 단계: `grep -rn "callZoomWebhook\b" public_html` 와 `grep -rn "retryZoomForSession\b" public_html` 로 호출처 0 확인 후 삭제 (라우터·include 어디에서도 안 부르는지 재확인).

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
- 권한: `chown root:apache keys/zoom.json && chmod 640 keys/zoom.json` — `solapi.json` 실제 권한과 동일 (DEV `keys/` 디렉토리는 `750 root:apache`, `solapi.json` 은 `640 root:apache` 확인).
- gitignore: `keys/` 디렉토리는 이미 `.gitignore` 에 등록돼 있어 자동 제외됨.

Zoom 앱 권한 (Server-to-Server OAuth)
- 필요한 scope: `meeting:write:admin` (관리자 단위 — 다른 사용자 명의로 미팅 생성 가능). 단일 사용자 모드라면 `meeting:write` 로도 가능. n8n 에서 사용 중인 자격증명을 그대로 재사용하므로 scope 가 이미 부여돼 있을 가능성 높음 — 첫 호출 시 401 발생하면 Zoom Marketplace 앱 설정에서 추가.

토큰 캐시 — `settings` 테이블 사용 (파일 캐시 아님)
- 키: `zoom_oauth_token`, 값: `{"access_token":"...","expires_at":<epoch>}` JSON.
- 파일 권한 이슈 회피 (`keys/` 디렉토리에 apache 가 새 파일 만들기 어려움), `updateSetting()` / `getSettingFresh()` 로 충분.
- 만료 60초 전부터 갱신. 매 요청마다 `getSettingFresh('zoom_oauth_token')` 1회 SELECT, 갱신 시 1회 UPSERT — 비용 무시할 수준.
- 동시 요청에서 중복 발급될 수 있으나 Zoom S2S OAuth 는 기존 토큰을 무효화하지 않으므로 무해. 별도 락 불필요.
- 캐시 쓰기 실패 시 (DB 오류 등) → 경고 로그 + 메모리 토큰만으로 현재 요청 처리, 다음 요청에서 재발급. 실패가 사용자 노출 에러로 번지지 않음.

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

    // zoom_start_url 은 만료 위험 + 현재 frontend 미사용 → NULL 로 둔다
    $db->prepare("
        UPDATE lecture_events
        SET zoom_meeting_id = ?, zoom_join_url = ?, zoom_start_url = NULL, zoom_password = ?,
            zoom_status = 'ready', zoom_error_message = NULL
        WHERE id = ?
    ")->execute([
        (string)$result['meeting_id'],
        $result['join_url'],
        $result['password'],
        $eventId,
    ]);

    return ['success' => true, 'zoom_join_url' => $result['join_url']];
}
```

함수 시그니처/반환값은 유지하므로 기존 호출처 (`lecture.php:437`, `lecture.php:534`) 는 함수 호출 자체는 수정하지 않는다. 단 호출 후 응답 조립부 (`lecture.php:448` jsonSuccess) 에서 `'zoom_status' => $zoomResult['success'] ? 'ready' : 'pending'` 을 `'failed'` 로 바꿔 DB 와 일치시킨다.

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
  "password": "abc123"
}
```
`id` 는 number 이지만 컬럼이 `VARCHAR(50)` 이므로 `(string)` 캐스팅해 저장. `start_url` 은 응답에 포함되지만 사용·저장하지 않는다 (만료 위험 + 미사용).

응답 검증 — `zoom_client.php` 안에서
- `id` 또는 `join_url` 누락 → `RuntimeException("Zoom 응답 누락: id/join_url")`. 빈 row 저장 방지.
- `password` 는 누락 가능 (Zoom 계정 설정에 따라). NULL 허용.

## 에러 처리

- HTTP 4xx → `RuntimeException("Zoom API {code}: {message}")`. 상위에서 `zoom_status='failed'` + `zoom_error_message` (500자 잘라 저장).
- HTTP 5xx / cURL error → 동일 처리.
- 401 (토큰 만료/무효) 발생 시 → `settings.zoom_oauth_token` 행 만료 처리(`expires_at=0`) 후 1회만 재발급+재시도. 재시도도 401이면 throw.
- `getSetting("zoom_host_*")` 가 비어 있으면 API 호출 자체를 시도하지 않고 즉시 실패 처리.
- 동작은 기존 n8n 호출 실패와 동일 — 어드민 화면의 재시도 버튼 (`handleLectureEventZoomRetry`) 그대로 사용.

## 마이그레이션 & 배포

DEV (`boot-dev`)
1. `keys/zoom.json` 작성:
   ```bash
   sudo tee /var/www/html/_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json > /dev/null
   sudo chown root:apache /var/www/html/_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json
   sudo chmod 640 /var/www/html/_______site_SORITUNECOM_DEV_BOOT/keys/zoom.json
   ```
2. DEV DB
   ```sql
   INSERT INTO settings (`key`,`value`,updated_at) VALUES
     ('zoom_host_coach1','<email-or-userid>', NOW()),
     ('zoom_host_coach2','<email-or-userid>', NOW())
   ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),updated_at=NOW();
   ```
3. 코드 commit & push (dev 브랜치)
4. `dev-boot.soritune.com` 검증
   - 강의 이벤트 1개 생성 → `zoom_status='ready'` + `zoom_meeting_id` / `zoom_join_url` / `zoom_password` 채워지는지 (`zoom_start_url` 은 NULL).
   - 어드민 detail 응답에서 `zoom_join_url` 정상, 어드민 본인 Zoom 로그인 상태에서 join_url 클릭 시 호스트로 입장됨.
   - 첫 호출에서 `settings.zoom_oauth_token` row 가 생성됐는지 확인.
   - host 매핑 비어있는 케이스 → `zoom_status='failed'` + 메시지 저장. **API 응답의 `zoom_status` 도 `'failed'` 로 내려가는지** (기존 'pending' 버그 fix 검증).
   - 재시도 버튼 (`lecture_event_zoom_retry`) 정상 동작.
   - 의도적으로 `keys/zoom.json` 의 clientSecret 을 망가뜨려 401 시나리오 → 캐시 토큰 무효화 후 재시도 1회, 그래도 실패면 `zoom_status='failed'`.
5. **사용자 확인 요청 후 정지**

PROD (`boot-prod`) — 사용자 명시 요청 후에만
6. PROD DB 동일 INSERT.
7. PROD `keys/zoom.json` 동일 자격증명/권한 (`640 root:apache`).
8. main 머지 + `git pull`.
9. 다음 강의 이벤트 생성 시 자체 코드 동작 확인.

롤백
- `lecture_zoom_webhook_url` setting 행 즉시 삭제 안 함. 비상시 함수 본문 git revert 만으로 옛 흐름 복귀 가능.
- n8n 워크플로우 즉시 비활성화하지 않음. PROD 검증 후 양쪽 의존성 끊기.
- 1~2주 안정 운영 후 별도 PR로 webhook URL setting 행과 n8n 워크플로우 정리.

테스트 자동화 — 이번 범위 밖
- Zoom API mocking 가치 낮음, 실제 검증은 DEV/PROD 호출이 더 신뢰성 있음.
- `zoom_client.php` 토큰 캐시 만료 로직만 가벼운 단위 테스트 가능 — 후속.
