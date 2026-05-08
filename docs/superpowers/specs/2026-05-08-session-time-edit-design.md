# 회차별 강의 시간 변경 기능

**날짜**: 2026-05-08
**대상**: boot.soritune.com 강의 달력의 개별 `lecture_sessions` 시간 변경

## 배경

`lecture_schedules` 는 반복 강의의 부모 (요일·시간 템플릿). 매 회차는 `lecture_sessions` 에 row 로 펼쳐지며 자체 `start_time`/`end_time`/`title` 을 가진다. 데이터 모델은 이미 회차별 시간 차이를 표현할 수 있지만, UI/API 에는 회차별 **취소** 만 있고 **시간 변경** 이 없다. 운영자가 일정상 한 회차만 시간을 옮겨야 할 때 이벤트 추가나 직접 SQL 외에 방법이 없어 혼선이 생긴다.

이 작업은 회차별 시간 변경 (`lecture_sessions.start_time`/`end_time`) 을 정공법으로 추가한다.

## 요구사항

1. 운영자가 강의 달력의 세션 상세 모달에서 한 회차의 **시간만** 변경 가능. 시작 시간 입력 → 종료 시간은 부모 schedule 의 `duration_minutes` 로 자동 계산.
2. 변경 시 같은 날·같은 host_account 의 다른 강의 세션 / 이벤트와 중복되면 거부 (기존 `checkLectureOverlap` / `checkEventOverlap` 재사용).
3. 변경된 회차는 달력 칩과 상세 모달에 "시간변경" 배지 표시. 신규 컬럼 없이 `lecture_sessions.start_time ≠ lecture_schedules.start_time` 비교로 판정.
4. 세션 제목이 `[HH:MM] ...` prefix 패턴을 따르면 시간 prefix 만 새 시간으로 갱신. 매칭되지 않으면 제목 그대로.
5. 권한: `operation`, `head`, `subhead1`, `subhead2` (기존 session cancel 권한과 동일). 코치는 본인 반이라도 시간 편집 불가.
6. 알림톡 재발송·multi-session 일괄 변경·날짜/코치/단계 변경은 범위 외.

## 설계

### 코드 변경

**API (`public_html/api/services/lecture.php`)**

- 신규 라우트 `case 'lecture_session_update_time'` → `handleLectureSessionUpdateTime` 추가 (in `bootcamp.php` switch + lecture.php 함수 정의).
- 함수 시그니처: 입력 `{session_id, start_time}` (HH:MM). 검증 → host overlap 검사 → UPDATE.
- 응답: 성공 시 `{session_id, start_time, end_time, title, badge_changed: true|false}`.

검증 흐름:
1. POST + admin role guard.
2. session 존재 + status='active' 확인.
3. start_time 형식 `HH:MM`.
4. parent schedule 조회 → `duration_minutes` 기준 end_time 계산.
5. `checkLectureOverlap($db, [$session.lecture_date], $newStartTimeFull, $session.host_account, excludeSessionId=$sessionId)` 로 충돌 확인.
6. `checkEventOverlap($db, $session.lecture_date, $newStartTimeFull, $session.host_account)` 로 이벤트 충돌 확인.
7. 제목 `[HH:MM] ...` 패턴 매칭 시 prefix 업데이트.
8. UPDATE `start_time`, `end_time`, `title`.

기존 `checkLectureOverlap` 시그니처에 `excludeSessionId` 옵션이 없을 수 있다. 있으면 재사용, 없으면 옵션 파라미터 추가 (기존 호출 영향 없도록 default null).

**JS (`public_html/js/lecture.js`)**

세션 상세 모달 (`openDetail` → `s.zoom_status === 'ready'` 영역 인근) 에 "시간 변경" 인라인 폼 추가. 운영자 권한일 때만 노출 (`role === 'operation' || ...`).

폼 요소:
- `<input type="time" id="lec-edit-time" value="${s.start_time.slice(0,5)}">`
- 저장 버튼 → POST `lecture_session_update_time`.
- 성공 시 모달 다시 열기 (재조회) + 달력 새로고침.

달력 칩 렌더 (`renderChip` 또는 해당 위치): API 응답에 부모 schedule 의 `schedule_start_time` 가 포함되어야 하므로 `lecture_sessions` 월별 조회 SQL 에 JOIN 으로 추가. 칩 텍스트에 `s.start_time !== s.schedule_start_time` 이면 작은 "시간변경" 텍스트 또는 배지 추가.

**SQL JOIN 추가 (`handleLectureSessions` 월별 목록)**

기존 SELECT 에 `lecture_schedules.start_time AS schedule_start_time` 컬럼 노출. 이미 schedule_id 로 JOIN 되어 있으면 컬럼만 추가; 안 되어 있으면 LEFT JOIN 추가.

### 데이터 흐름

1. 운영자 → 세션 클릭 → 모달 열림 (`lecture_session_detail`)
2. 모달에 "시간 변경" 입력 + 저장
3. POST `lecture_session_update_time` → 검증/충돌 검사/UPDATE
4. 모달 + 달력 reload → "시간변경" 배지 표시

### 보존되는 동작

- 부모 `lecture_schedules.start_time` 은 변경되지 않음 (template 보존, 미래 회차 영향 없음).
- 출석/QR/Zoom URL: 모두 session 단위로 동작 → 시간만 바뀐 회차에 자연스럽게 따라옴.
- session cancel/zoom retry: 영향 없음.

### 에러 처리

- 시간 형식 오류 → 400.
- session 없음/이미 cancelled → 404 또는 명시적 메시지.
- host 중복 (다른 강의 또는 이벤트) → 충돌 대상 표시하는 메시지로 거부.
- duration 0 또는 음수 (이론적으로 불가) → 400.

## 검증

- **DEV 단위**:
  - 5/11 같은 날 다른 시간대 강의 세션이 있는 가상 케이스로 충돌 거부 확인.
  - 이벤트와 새 시간이 겹치는 경우 거부 확인.
  - 정상 변경 후 session row 의 start_time/end_time/title 확인.
  - 모달이 변경된 시간 표시 + "시간변경" 배지 노출.
  - 부모 schedule.start_time 미변경 확인 (다른 회차 영향 없음).

- **PROD 적용 후**:
  - 5/11 stage=2 active session 시간을 19:30 으로 운영자가 직접 수정 → 정상 작동 확인.

## 범위 외

- 알림톡 자동 재발송 (운영자 수동 안내).
- 한 회차에 여러 필드 동시 변경 (날짜/코치/단계).
- 다중 회차 일괄 시간 변경.
- 변경 이력 audit log 별도 테이블.
- 회원에게 변경 알림 (현재 회원 페이지에서 시간이 자연스럽게 갱신됨; 별도 푸시 없음).
