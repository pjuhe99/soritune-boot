# Task 결과물 제출 기능 설계

작성일: 2026-05-15
대상: boot 프로젝트 (`tasks` 테이블 — 운영진용 task 관리)
작성자 검토 대상: spec 검증 완료 후 implementation plan 으로 전환

## 1. 배경 / 목적

운영진(operation/coach/leader/subleader/head/subhead 등)이 사용하는 task 체크 시스템은 현재 단순 체크박스(`tasks.completed = 0/1`) 만 있다.

운영자가 매주 task 별 결과물(예: 카페 점검 결과, 출석체크 사유, 주간 회고 요약)을 모아 다음 주 운영 판단에 활용하려면 체크와 동시에 텍스트 결과물을 남길 수 있어야 한다. 운영자(operation 권한)가 묶음 단위로 "이 task 는 결과물 제출 필수" 플래그를 켜고, 해당 묶음의 task 를 완료할 때 텍스트 입력을 강제한다.

**비목표** (이번 spec 에서 다루지 않음):
- 결과물 변경 이력(audit log)
- 파일/이미지 첨부
- 마크다운 렌더링 (plain text 만)
- 알림(메일/카톡 등)
- 회원용 미션 체크(`member_mission_checks`) 와 무관

## 2. 데이터 모델

`tasks` 테이블에 컬럼 3개 추가:

```sql
ALTER TABLE tasks
  ADD COLUMN requires_submission TINYINT(1) NOT NULL DEFAULT 0 AFTER completed,
  ADD COLUMN submission_text TEXT NULL AFTER requires_submission,
  ADD COLUMN submitted_at DATETIME NULL AFTER submission_text;
```

- `requires_submission`: 묶음 단위 플래그(같은 (cohort,title,role) 묶음 안 모든 row 가 동일 값을 갖도록 운영). denormalized.
- `submission_text`: 제출 텍스트. NULL = 미제출. trim 후 빈 문자열 저장 금지.
- `submitted_at`: 마지막 제출(성공) 시각. 인라인 표시용.

별도 `task_submissions` 테이블을 만들지 않는 이유:
- row 당 제출 1개만 필요(한 row = 한 assignee = 한 결과물).
- 변경 이력 요구 없음.
- 묶음 펼침 SELECT 가 이미 tasks 단일 SELECT — JOIN 추가 회피.

새 인덱스 없음. 묶음 SELECT 가 기존 (cohort,title,role) 인덱스 사용. submission_text 검색 요구 없음.

마이그레이션 파일: `migrate_tasks_submission.php` (boot 프로젝트는 별도 `migrations/` 폴더 없이 루트에 단일 PHP runner 파일을 두는 패턴 — 기존 `migrate_multipass.php`, `migrate_event_fixed_zoom.php` 등과 동일). 멱등 보장은 information_schema 조회 후 컬럼 없을 때만 ADD.

## 3. 정책

### 3.1 묶음 플래그 변경 시점

**0 → 1 (제출 필수로 전환)**:
- 묶음 모든 row 의 `requires_submission` UPDATE.
- 기존 `completed=1` row 는 그대로 유지(소급 적용 X). `submission_text` 가 NULL 이라도 강제 보충 입력 요청하지 않음.
- 새 토글(미완료 → 완료)부터 텍스트 강제.

**1 → 0 (제출 필수 해제)**:
- 묶음 모든 row 의 `requires_submission=0` UPDATE.
- 기존 `submission_text` 보존(읽기 가능). 새 토글에는 텍스트 안 받음.

**근거**: forward-only. 이미 끝난 일에 텍스트 소급 입력 요청은 운영 정책에 안 맞음. 운영자가 정책을 바꾼 시점부터 새 동작.

### 3.2 체크 해제 동작

- `requires_submission=1` 인 row 가 `completed=1` 상태 → uncheck:
  - 즉시 `completed=0` 갱신. `submission_text`, `submitted_at` 보존.
  - 모달 안 띄움.
- 다시 check 할 때 모달이 열리며 `submission_text` 가 pre-fill 됨(편집 가능).

**근거**: 실수로 uncheck 했을 때 작성한 글 날리지 않음.

### 3.3 검증 (서버)

`toggle_task` 에서 `completed=1` 로 변경하는데 row `requires_submission=1` 이고 입력 `submission_text` 가 없거나 trim 후 빈 문자열:
- HTTP 400 `결과물을 입력해주세요.`

`task_submission_update` (텍스트만 단독 편집) 호출인데 row `requires_submission=0`:
- HTTP 400 `이 task 는 결과물 제출 대상이 아닙니다.` (정합성 가드)

길이 제한 없음. TEXT 컬럼(64KB) 자연 한계.

### 3.4 권한

- `toggle_task`, `task_submission_update`: `requireAdmin()` (assignee 본인이 아닌 운영자도 대신 가능 — 기존 toggle_task 정책 보존).
- `task_group_update` 의 `requires_submission` 변경: 기존대로 `requireAdmin(['operation'])`.

## 4. API 변경

| Endpoint | 변경 |
|---|---|
| `task_create` (POST) | 입력 `requires_submission` (0/1) 추가. 생성된 모든 row 에 동일 값. 기본 0. |
| `task_group_get` | 응답에 `requires_submission` 추가(묶음 첫 row 값). |
| `task_group_update` (POST) | 입력 `requires_submission` 추가. 묶음 모든 row 에 일괄 UPDATE. 기존 completed/submission_text/submitted_at 보존. |
| `task_group_rows` | 응답 row 마다 `requires_submission`, `submission_text`, `submitted_at` 포함. |
| `today_tasks`, `all_tasks` | 응답 row 마다 위 3 필드 포함. |
| `toggle_task` (POST) | 입력 `submission_text` (string, optional) 받기. `completed=1` 시도 + row `requires_submission=1` + 텍스트 비어있음 → 400. 성공 시 `submission_text` 갱신(트림), `submitted_at=NOW()`. `completed=0` 으로 갱신할 땐 `submission_text`/`submitted_at` 보존. |
| **신규** `task_submission_update` (POST) | 입력 `task_id`, `submission_text`. row `requires_submission=0` 이면 400. 성공 시 `submission_text` 갱신(트림), `submitted_at=NOW()` 갱신. `completed` 는 안 건드림. |

**Backwards compat 영향**:
- `requires_submission=0` 인 모든 기존 row 는 새 컬럼 무시 — 동작 변화 없음.
- 옛 클라이언트가 `requires_submission=1` row 에 `submission_text` 없이 `toggle_task` 호출 → 400 (의도된 fail). 운영 시 모든 클라이언트가 새 JS 받았는지 cache buster 로 보장.

## 5. UI 변경

### 5.1 운영자 Task 생성/수정 모달 (`/operation/#tasks`)

`task_create` form:
- `<label><input type="checkbox" name="requires_submission"> 결과물 제출 필수</label>` 추가(체크박스 1줄).

묶음 수정 모달(`task_group_update`):
- 동일 체크박스 추가. 현재 값 prefill. 변경 시 묶음 일괄 UPDATE.

### 5.2 대시보드 Task 카드 (assignee 본인 화면)

`renderTaskCard` (admin.js:893):
- `requires_submission=1` 인 카드 우상단(`task-meta`)에 `<span class="badge badge-info task-requires-submission-chip">📝 결과물</span>` 표시.
- `completed=1 + submission_text` 있으면 카드 하단(content_markdown 펼침과 동일 패턴)에 `📝 결과물 (5/15 18:02) [수정]` 줄. 클릭 시 textarea 토글로 plain text(\n→<br>) 전체 표시.

`bindTaskEvents` (admin.js:915) 체크박스 onchange:
1. row `requires_submission=0` → 기존대로 즉시 `toggle_task` 호출.
2. `requires_submission=1` + 체크 (off→on) → 새 모달 열림.
   - textarea (`submission_text` pre-fill if any)
   - [저장]: `toggle_task({task_id, completed:1, submission_text})`. 성공 시 모달 닫고 list 새로고침.
   - [취소] / 백드롭 클릭: 체크박스 원복(`cb.checked = false`).
3. `requires_submission=1` + 체크 해제 (on→off) → 모달 안 띄우고 즉시 `toggle_task({task_id, completed:0})`.

[수정] 버튼 → 동일 모달(텍스트 prefill, [저장] 시 `task_submission_update` 호출).

### 5.3 운영자 묶음 펼침 (방금 만든 펼침 안)

묶음 row(접힘 상태):
- `requires_submission=1` 묶음에 `📝` 작은 아이콘 prefix(title 좌측).

펼침 안 row:
- 기존 row 표시(assignee, end_date, completed 체크박스) 유지.
- `submission_text` 있는 row 아래 `📝 [김코치 5/15 18:02] (앞 60자 말줄임…)` 한 줄. 클릭 시 전체 텍스트 toggle 표시.
- 운영자도 [수정] 버튼 보임 — 동일 모달.

### 5.4 미완료/전체 chip 토글

변경 없음. submission_text 유무와 무관하게 `completed=0/1` 만 봄.

### 5.5 CSS 신규

`public_html/css/admin.css` (기존 task/badge 스타일과 같은 곳):
- `.task-requires-submission-chip`(badge 톤)
- `.task-submission-text`(인라인 텍스트 박스, 기존 `.task-content` 스타일 재사용 가능)
- `.task-submission-meta`(작성자/시각 줄)

### 5.6 변경 파일 (예상)

- `public_html/api/admin.php` — task_create, task_group_get, task_group_update, task_group_rows, today_tasks, all_tasks, toggle_task, **task_submission_update(신규)**
- `public_html/js/admin.js` — renderTaskCard, bindTaskEvents, 묶음 펼침 렌더, Task form modal, 묶음 수정 modal, **신규 제출 텍스트 modal**
- `public_html/css/admin.css` — chip/인라인 스타일
- `migrate_tasks_submission.php` (루트, 멱등 runner — 컬럼 3개 ADD)

## 6. 테스트

### 6.1 단위 테스트 (`tests/task_submission_api_test.php`)

각 fixture 는 트랜잭션 안에서 setup → assert → rollback.

- `requires_submission=1` row 에 `toggle_task(completed=1)` 텍스트 없이 → 400
- 텍스트 trim 후 빈 문자열 → 400
- 텍스트 정상 → success + `completed=1`, `submission_text` 저장, `submitted_at` set
- 동일 row uncheck (`completed=0`) → success + text/timestamp 보존
- uncheck 후 다시 check (새 텍스트) → 텍스트 덮어쓰기, `submitted_at` 갱신
- `task_submission_update` 가 `requires_submission=0` row 에 호출 → 400
- `task_submission_update` 정상 → text/timestamp 갱신, `completed` 미변경
- `task_group_update` 로 `requires_submission` 0→1 → 묶음 모든 row 갱신, 기존 completed/text 보존
- `task_group_update` 1→0 → 묶음 모든 row 갱신, 기존 submission_text 보존

### 6.2 인보리언트 (`tests/task_submission_invariants.php`)

PROD/DEV 어디서도 위반 없는지 SELECT 로 검증:

- **INV-S1**: `tasks.completed=1 AND requires_submission=1` 이면 `submission_text IS NOT NULL AND TRIM(submission_text) != ''`
- **INV-S2**: 같은 (cohort, title, role) 묶음 안 모든 row 의 `requires_submission` 값이 동일
- **INV-S3**: `submission_text IS NOT NULL` 이면 `submitted_at IS NOT NULL`

### 6.3 회귀

- 기존 `tests/task_group_api_test.php`, `tests/task_group_invariants.php` 재실행(컬럼 추가 영향 없음 확인)
- `tests/task_group_invariants.php` fixture 에 `requires_submission` 시나리오 1~2 row 추가(0/1 둘 다 묶음 안에 섞이지 않는지)

## 7. 롤아웃 순서 (DEV)

1. `migrate_tasks_submission.php` 멱등 runner 작성
2. DEV 실행: `php migrate_tasks_submission.php` → 컬럼 3개 ADD
3. API 변경 → `php tests/task_submission_api_test.php` PASS
4. UI 변경 → manual smoke (운영자 모달 / 대시보드 모달 / 묶음 펼침 인라인)
5. 인보리언트 PASS: `php tests/task_submission_invariants.php`
6. 회귀: 기존 task_group_api_test/task_group_invariants PASS
7. dev push
8. ⛔ 사용자 DEV 검증 + 운영 반영 명시 대기 (메모리 규칙 준수)
9. 사용자 명시 시 main merge → prod git pull → PROD 마이그 실행 → PROD 인보리언트 PASS

## 8. 롤백

컬럼 3개 ADD 단순 마이그.

```sql
ALTER TABLE tasks
  DROP COLUMN submitted_at,
  DROP COLUMN submission_text,
  DROP COLUMN requires_submission;
```

**주의**: 사용자 입력(submission_text) 발생 후 롤백 시 텍스트 영구 손실. 롤백 결정은 PROD 운영진의 첫 사용 발생 전에 내릴 것.

## 9. 결정 요약

- 데이터 모델: tasks 테이블 컬럼 3개 추가 (별도 테이블 X)
- 묶음 플래그 변경: forward-only (소급 X)
- 체크 해제: 텍스트 보존 (재체크 시 prefill)
- 검증: 서버 (trim 후 비어있음 = 400)
- 권한: 기존 requireAdmin 정책 그대로 (operator 가 대신 입력 가능)
- 텍스트 형식: plain multi-line, 길이 제한 없음
- 운영자 검토 위치: 묶음 펼침 인라인 (별도 탭 X)
