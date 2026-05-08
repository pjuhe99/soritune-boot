# 이벤트 줌 링크를 단계별 고정 URL로 통일

**날짜**: 2026-05-08
**대상**: boot.soritune.com 강의 달력의 1회성 "이벤트" Zoom 라우팅

## 배경

현재 boot 강의 달력의 두 종류 일정은 Zoom 링크 처리 방식이 다르다.

- **반복 강의 (`lecture_schedules`)**: 단계별 고정 Zoom URL(`getFixedZoomUrl(stage)`) 직접 사용. Zoom API 호출 없음.
- **1회성 이벤트 (`lecture_events`)**: 생성 시마다 Zoom API 로 새 미팅을 만들어 매번 다른 링크 발급.

회원·코치 입장에서 이벤트 줌이 매번 바뀌면 익숙한 특강 링크와 분리되어 혼선이 있다. 이벤트도 강의와 동일한 단계별 고정 링크로 통일한다.

## 요구사항

1. 신규 이벤트 생성 시 `getFixedZoomUrl(stage)` 로 `zoom_join_url` 즉시 세팅. Zoom API 호출 제거.
2. stage 매핑: 전체(NULL/0) 또는 1단계 → 1단계 URL, 2단계 → 2단계 URL. (`getFixedZoomUrl` 의 기본 fallback 이 이미 그렇게 동작.)
3. 12기에 이미 만들어진 active 이벤트의 `zoom_join_url` 을 단계별 고정 URL로 소급 갱신. (DEV 1건, PROD 3건 — 모두 stage=1 → 1단계 URL.)

## 설계

### 코드 변경 (`public_html/api/services/lecture.php`)

1. **`handleLectureEventCreate`** (현재 line ~440):
   - 기존: INSERT 후 `callLectureEventZoomWebhook(...)` 으로 Zoom API 호출 → 결과 UPDATE.
   - 변경: INSERT 후 `getFixedZoomUrl((int)$stage)` 로 즉시 zoom_join_url + zoom_status='ready' UPDATE. 응답에 그대로 반환.

2. **`handleLectureEventZoomRetry`** (현재 line ~515):
   - 단순화: fixed URL 로 재세팅하는 동일한 동작으로 변경. 'failed' 상태 legacy 이벤트를 UI 버튼으로 1건씩 복구 가능. UI 호환 유지.

3. **`callLectureEventZoomWebhook`** + **`failLectureZoomEvent`** (현재 line ~723, 761):
   - 삭제. 다른 호출처 없음 확인됨.

4. **`includes/zoom/zoom_client.php`**:
   - 이벤트가 마지막 사용처면 같이 정리. 다른 사용처 있으면 유지. (구현 단계에서 grep 으로 확인.)

### 백필 마이그 (`migrate_event_fixed_zoom.php`)

1회성 PHP 스크립트. 다음 SQL 의도를 PHP 로 실행 (단계별 URL 매핑은 `getFixedZoomUrl` 재사용):

```sql
UPDATE lecture_events le
JOIN cohorts c ON le.cohort_id = c.id
SET le.zoom_join_url = <getFixedZoomUrl(le.stage)>,
    le.zoom_status = 'ready',
    le.zoom_error_message = NULL,
    le.zoom_meeting_id = NULL,
    le.zoom_password = NULL
WHERE c.cohort = '12기' AND le.status = 'active';
```

DEV 먼저 실행 → SELECT 검증 → PROD 실행 → SELECT 검증.

`zoom_meeting_id` / `zoom_password` 는 NULL 로 정리. 더 이상 의미 없는 컬럼(이미 만들어진 Zoom 미팅을 가리키지만 사용하지 않음).

### 보존되는 동작

- `host_account` 자동 매핑 (`stage===2 ? 'coach2' : 'coach1'`) 그대로. → overlap 체크가 "같은 시간 같은 단계 중복" 차단 그대로 동작.
- `checkLectureOverlap` / `checkEventOverlap` 그대로.
- 이벤트 컬러·제목·날짜·시간 폼은 변경 없음.

## 검증

- **DEV 단위 검증**:
  - 백필 후 12기 이벤트 1건의 `zoom_join_url` 이 1단계 fixed URL 과 일치.
  - 신규 이벤트 stage=1 / stage=2 / stage=전체 각각 생성 후 zoom_join_url 확인.
  - '재시도' 버튼 클릭 시 fixed URL 로 (재)세팅되는지 확인.

- **PROD 적용 후**:
  - 12기 active 이벤트 3건 모두 1단계 fixed URL 로 통일됨을 SELECT 로 확인.

## 범위 외

- 11기 이전 이벤트: 사용자 요청 범위 밖. 손대지 않음.
- 반복 강의 로직: 이미 fixed URL 사용 중이므로 변경 없음.
- 신규 단계 추가(3단계 등): 현재 1·2 단계만 운영되므로 YAGNI.
- 호스트 계정 / overlap 체크 로직 재설계: 이번 작업 범위 밖.
