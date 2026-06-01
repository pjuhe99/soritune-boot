# 줌 회의 ID/비밀번호 노출 + 개별 복사

**날짜:** 2026-06-01
**대상:** boot.soritune.com (부트캠프)
**작업 환경:** `boot-dev` (DEV_BOOT, dev 브랜치)

## 배경 / 문제

멤버는 복습스터디·특강에서 줌 입장 시 **입장 링크**로만 들어간다(바로 열기 / 링크 복사).
링크 접속에 문제가 생기면(앱 줌 핸들러 오류, 사내망 차단, 모바일 브라우저 이슈 등) 대안이 없다.
줌은 **회의 ID + 숫자 비밀번호**로 수동 입장이 가능하므로, 이 둘을 화면에 함께 노출하고
각각 복사할 수 있게 해 링크 장애 시에도 입장할 수 있게 한다.

## 현재 구조 (조사 결과)

### 데이터 소스 두 갈래

| 종류 | 테이블 | 줌 생성 방식 | `zoom_meeting_id` | `zoom_password`(평문 숫자) |
|------|--------|--------------|-------------------|----------------------------|
| **복습스터디** | `study_sessions` | `settings.study_fixed_zoom_url` **고정 줌방** 공유 | 대부분 NULL | **NULL (출처 없음)** |
| **특강** | `lecture_schedules` | n8n webhook으로 줌 API 회의 생성 | 채워짐 | 채워짐 (예 `415217`) |

- 고정 줌방 URL: `https://us02web.zoom.us/j/82511251269?pwd=Ol9HUZJ...`
  → 회의 ID `82511251269`는 URL의 `/j/` 뒤 숫자로 **추출 가능**
  → URL의 `pwd=` 토큰은 **암호화된 값**이라 사람이 입력하는 숫자 비번이 아님 → 평문 비번 출처 없음
- 두 테이블 모두 zoom 컬럼 동일: `zoom_meeting_id, zoom_join_url, zoom_start_url, zoom_password, zoom_status, zoom_error_message`

### 멤버에게 줌을 내려주는 엔드포인트 (3개)

모두 `/api/bootcamp.php?action=...`:

| action | 핸들러 | 사용 화면 |
|--------|--------|-----------|
| `study_session_detail` | `handleStudySessionDetail` (study.php) | `study.js` 복습 상세 + `member-calendar-detail.js` 복습 블록 |
| `lecture_session_detail` | `handleLectureSessionDetail` (lecture.php) | `lecture.js` 세션 블록 + `member-calendar-detail.js` 특강 블록 |
| `lecture_event_detail` | (lecture.php ~599) | `lecture.js` 이벤트 블록 + 캘린더 이벤트 |

- `lecture.php`는 이미 `zoom_password`를 **전 멤버에게** 내려줌(숨기는 건 `zoom_start_url`뿐).
  `lecture.js`가 JS단에서 `if (isHost)` / `if (zoom_password)`로 **개설자에게만** 표기 중.
- `study.php` detail은 `SELECT ss.*`로 zoom 컬럼 전부 내려주나, 고정 줌방은 id/pw 모두 NULL.

### 설정 관리

- `study_fixed_zoom_url`은 **관리자 UI 없이 `settings` 테이블에서 직접 관리**.
- `settings` 스키마: `key(PK), value(text), description, updated_at`.
- `getSetting(key, default)` 헬퍼 존재 (`config.php:101`).

### 클립보드 헬퍼 (재사용)

- `MemberUtils.copyToClipboard(text, successMsg)` — `member-utils.js:45` (study.js / member-calendar-detail.js에서 사용)
- `LectureApp._copyZoom(url)` — `lecture.js:254` (navigator.clipboard + textarea fallback + Toast)

### 테스트 하베스

- `tests/*.php` — CLI 전용, 순수 함수 require 후 `t(name, cond)` assert 패턴.

## 결정 사항 (사용자 확정)

1. **복습스터디 비번 출처**: 설정 `study_fixed_zoom_password` **신설** (DB 행, 관리자 UI 없음 — URL과 동일 방식).
2. **노출 대상**: **참가 멤버 전원** (특강의 host-전용 비번 표기는 제거하고 전원 노출로 통일).
3. **표시/복사**: **각 항목 개별 복사 버튼**. 회의 ID / 비밀번호 두 줄, 각 줄에 복사 버튼.

## 설계

### A. 백엔드 — 공용 헬퍼 + 엔드포인트 주입

**공용 헬퍼** (`public_html/includes/bootcamp_functions.php`에 추가):

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
    $pw = $row['zoom_password'] ?? null;
    if ($pw === null || $pw === '') {
        $pw = ($passwordFallback !== null && $passwordFallback !== '') ? $passwordFallback : null;
    }
    return [
        'zoom_meeting_id_display' => ($id === '' ? null : $id),
        'zoom_password_display'   => $pw,
    ];
}
```

**엔드포인트 주입:**

- `handleStudySessionDetail` (study.php):
  `$session = array_merge($session, zoomDisplayInfo($session, getSetting('study_fixed_zoom_password')));`
  (복습은 고정방 설정값을 fallback으로 전달)
- `handleLectureSessionDetail` (lecture.php):
  `$session = array_merge($session, zoomDisplayInfo($session));`
  (특강은 컬럼에 비번이 있음 → fallback 없음)
- `lecture_event_detail` 핸들러 (lecture.php ~599):
  `$event = array_merge($event, zoomDisplayInfo($event));`

`zoom_start_url`의 host/admin-only 마스킹은 **현행 유지**(변경 없음).

### B. 설정 시드 — `study_fixed_zoom_password`

마이그레이션 스크립트(`migrate_*.php`, idempotent):

```sql
INSERT INTO settings (`key`, `value`, `description`)
VALUES ('study_fixed_zoom_password', '', '복습스터디 고정 줌방 입력용 숫자 비밀번호')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
```

- 빈 값으로 시드 → 비번 미설정 시 복습 화면엔 회의 ID만 노출(비번 줄 생략, graceful).
- 실제 숫자 비번 값은 사용자가 알려주면 DEV/PROD에 `UPDATE settings SET value=... WHERE key='study_fixed_zoom_password'` 적용.

### C. 프론트엔드 — 개별 복사 ID/비번 줄

각 zoom 입장/복사 버튼 블록 아래에, **값이 있을 때만** 두 줄을 렌더:

```
회의 ID    82511251269   [복사]
비밀번호    600091        [복사]
```

- `zoom_meeting_id_display`가 있으면 "회의 ID" 줄 + 복사 버튼
- `zoom_password_display`가 있으면 "비밀번호" 줄 + 복사 버튼
- 둘 다 없으면 아무것도 추가 안 함

**공용 렌더 헬퍼** 도입(중복 방지) — 각 JS 파일 스타일에 맞게:
값과 라벨을 받아 `회의 ID / 비밀번호` 줄 + 복사 버튼 마크업을 만들고, 복사는 기존 클립보드 헬퍼 사용.

**적용 위치:**

| 파일 | 블록 | 클립보드 헬퍼 |
|------|------|----------------|
| `study.js` | 복습 상세 zoom 섹션(`zoomSection`, ~152) | `MemberUtils.copyToClipboard` |
| `member-calendar-detail.js` | 복습 블록(~89), 특강 블록(~303) | `MemberUtils.copyToClipboard` |
| `lecture.js` | 세션 블록(~182), 이벤트 블록(~845) | `_copyZoom` 패턴(또는 값 복사 헬퍼) |

**특강 host-전용 비번 표기 제거:** `lecture.js`의 기존 `Zoom 비밀번호: <strong>...` (host/ready 시) 블록(~184, ~847)을 새 전원-노출 ID/비번 줄로 **대체**.

복사 토스트: 회의 ID → "회의 ID가 복사되었습니다.", 비밀번호 → "비밀번호가 복사되었습니다."

스타일: 기존 `lec-detail-row` / `study-*` 클래스 재사용, 복사 버튼은 기존 `lec-btn-copy` 등 활용. 필요 최소 CSS만.

### D. 테스트

**단위 테스트** `tests/zoom_display_info_test.php` (CLI, `zoomDisplayInfo` require):

1. 컬럼에 id/pw 둘 다 있음 → 그대로 반환 (특강 케이스)
2. id NULL + URL `/j/82511251269?pwd=...` → id `82511251269` 파싱 (고정방)
3. pw NULL + fallback `'600091'` → pw `'600091'` (복습 fallback)
4. pw NULL + fallback NULL → pw `null` (비번 미설정 → 줄 생략)
5. id/url 모두 없음 → id `null`
6. URL에 `/j/` 없음(이상 URL) → id `null` (크래시 안 함)

**회귀(스모크):** 각 엔드포인트 응답에 `zoom_meeting_id_display`/`zoom_password_display` 키가 포함되는지 확인(가능한 범위에서).

## 범위 밖 (YAGNI)

- 관리자용 줌 설정 입력 화면 (현 URL과 동일하게 DB 직접 관리)
- `study_start_url` 마스킹 정책 변경
- 줌 생성/webhook 로직 변경

## 배포

1. `boot-dev`에서 구현 + 테스트 통과 → commit → push origin dev
2. ⛔ 멈춤. 사용자에게 https://dev-boot.soritune.com 확인 요청 + 고정방 숫자 비번 값 수령
3. 사용자가 운영 반영 요청 시: main 머지 → prod pull → PROD `settings`에 비번 값 적용
