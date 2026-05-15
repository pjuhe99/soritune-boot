# Task 관리 일괄 수정/삭제 설계 (boot)

- **작성일**: 2026-05-15
- **사이트**: `boot.soritune.com`
- **대상 화면**: `/operation` → "Task 관리" 탭
- **DB 마이그**: 없음 (기존 컬럼만 사용)

## 배경 / 문제

운영자가 "Task 관리" 탭에서 데일리 체크리스트를 만들면 (`date_mode=daily`), cohort 기간 동안 선택한 요일마다 `tasks` row 가 자동으로 한 묶음 생성된다. 그런데 수정·삭제는 row 1개 단위로만 가능해서, 묶음의 제목이나 내용 한 줄만 고치려 해도 모두 삭제 후 재생성하는 방식으로 우회하고 있다.

## 목표

같은 주제 묶음을 한 번에 수정·삭제할 수 있게 한다. row 단위 토글(`toggle_task`)·점검 체크 등 기존 동작은 그대로 둔다.

## 비목표 (Out of scope)

- DB 스키마 변경 (`task_group_id` 등 신규 컬럼). YAGNI: 기존 (cohort, title, role) 키만으로 충분.
- 날짜·요일 재설정 기능. completed row 와의 충돌 위험. 필요하면 그룹 삭제 후 재생성 패턴.
- 회원·코치·조장 화면의 today/overdue task 표시 구조 변경. 응답 구조는 row 단위 그대로.
- 그룹 펼치기로 개별 row 수정 (필요 시 향후 확장).

## 묶음 정의

**묶음 키 = (`cohort`, `title`, `role`) 동일한 `tasks` row 의 집합.**

- `assignee_admin_id` / `assignee_member_id` 는 묶음 키에 들어가지 않는다 → 한 묶음 안에 여러 담당자가 자연스럽게 포함된다 (예: `role='leader'` 묶음에 조장 5명).
- 단일 row 도 "크기 1 그룹" 으로 같은 UX 로 표시한다.

## API 변경 (api/admin.php)

### 추가: `all_tasks_grouped`

- `requireAdmin(['operation'])`
- 입력: `filter_role` (기존 `all_tasks` 와 동일 의미: `mine` / `all` / 특정 role)
- SQL 골자:
  ```sql
  SELECT
    cohort,
    title,
    role,
    COUNT(*)                                AS total_count,
    SUM(completed)                          AS done_count,
    MIN(start_date)                         AS min_start_date,
    MAX(end_date)                           AS max_end_date,
    COUNT(DISTINCT COALESCE(assignee_admin_id, 0),
                   COALESCE(assignee_member_id, 0)) AS assignee_count
  FROM tasks t
  WHERE …filter…
  GROUP BY cohort, title, role
  ORDER BY cohort DESC, role, MIN(start_date)
  ```
- 필터 의미는 기존 `all_tasks` 와 동일 (`filter_role='mine'` 은 `assignee_admin_id = ?` 또는 leader/subleader 의 경우 회원 매칭 등). 기존 SQL 의 WHERE 절을 그대로 재사용.
- 응답: `{ groups: [ { cohort, title, role, total_count, done_count, min_start_date, max_end_date, assignee_count } ] }`
- `content_markdown` 은 GROUP BY 결과에 포함하지 않는다. 수정 모달에서 필요할 때 `task_group_get` (아래 신규) 로 1 row 만 별도 조회.

### 추가: `task_group_get`

수정 모달이 기존 `title` / `content_markdown` 값을 채우기 위한 단건 조회.

- `requireAdmin(['operation'])`
- 입력: `{ cohort, title, role }`
- SQL:
  ```sql
  SELECT title, content_markdown
    FROM tasks
   WHERE cohort = ? AND title = ? AND role = ?
   ORDER BY start_date ASC
   LIMIT 1
  ```
- 응답: `{ title, content_markdown }`
- 주의: 같은 묶음 안의 row 들은 `task_create` 가 동일한 content 로 INSERT 하므로 보통 일치한다. 다만 누가 단건 `task_update` 로 한 row 만 바꿨을 가능성이 있어 첫 row(가장 빠른 start_date) 를 대표값으로 채택. 사용자가 모달에서 그대로 저장하면 묶음 전체가 그 값으로 일관화된다.

### 추가: `task_group_update`

- `requireAdmin(['operation'])`
- 입력 (POST JSON):
  ```json
  {
    "cohort": "12기",
    "title": "데일리 체크리스트",
    "role": "leader",
    "new_title": "데일리 체크리스트 (수정)",
    "new_content_markdown": "..."
  }
  ```
- 동작:
  1. `cohort` / `title` / `role` 모두 비어있지 않은지 검증. `new_title` 빈 문자 금지.
  2. 단일 SQL UPDATE — 트랜잭션 불필요 (한 statement):
     ```sql
     UPDATE tasks
        SET title = ?, content_markdown = ?, updated_at = NOW()
      WHERE cohort = ? AND title = ? AND role = ?
     ```
  3. 영향받은 row 수를 `affected_count` 로 응답.
- 인보리언트: title 을 바꾸면 묶음 키 자체가 바뀐다. 한 statement 안에서 처리되므로 race 없음.

### 추가: `task_group_delete`

- `requireAdmin(['operation'])`
- 입력: `{ cohort, title, role }`
- 동작:
  ```sql
  DELETE FROM tasks
   WHERE cohort = ? AND title = ? AND role = ? AND completed = 0
  ```
- 그룹의 다른 모든 row 가 `completed=1` 인 경우 `deleted_count=0` 가 정상이다 (이력 보존 정책).
- 응답:
  ```json
  { "deleted_count": 3, "kept_count": 2 }
  ```
  여기서 `kept_count` 는 응답 직후 동일 그룹 키로 다시 SELECT COUNT 한 결과.

### 변경 없음

- `today_tasks`, `overdue_tasks`, `toggle_task`: 회원·코치·조장 화면용 row 단위 응답 그대로.
- `task_create`, `task_update`(단건), `task_delete`(단건): 기존 단건 경로 유지. 향후 그룹 펼치기 기능을 만들 때 재사용 가능.

## UI 변경 (`/js/admin.js`)

영향 함수: `loadTasksMgmt`, `showTaskForm`, `_editTask`, `_deleteTask`. 새 함수 `_editTaskGroup`, `_deleteTaskGroup` 추가.

### `loadTasksMgmt()` — 그룹 단위 테이블

- 호출: `App.get('/api/admin.php?action=all_tasks_grouped', { filter_role: taskMgmtFilter })`
- 컬럼:
  | 컬럼 | 표시 |
  |---|---|
  | 제목 | `title` |
  | 역할 | `ROLE_LABELS[role]` 배지 |
  | 담당자 | `assignee_count`명 (예: "5명"). 1명이면 별도 single-row API 호출 없이 그냥 "1명" |
  | 기간 | `min_start_date === max_end_date` 면 한 날짜, 아니면 `min ~ max` |
  | 진행 | `done/total` (예: `12/30`). 100% 면 `<span class="badge badge-success">완료 N/N</span>`, 0% 면 미완료 배지, 중간이면 진행 배지 |
  | 수정/삭제 | 버튼 |
- 필터 칩(`mine`/`all`/role 별)은 그대로. 같은 `taskMgmtFilter` 를 새 액션에 전달.
- 빈 상태: `Task가 없습니다.` 동일.

### `showTaskForm` — 수정 모드 분기 변경

기존 `showTaskForm(data)` 의 `isEdit` 분기를 "그룹 수정" 의미로 바꾼다 (단건 수정 UI 는 현재 운영자가 사용하지 않음).

수정 모달 본문:

```
┌──────────────────────────────────────┐
│ [회색 박스: 식별 정보 — 수정 불가]    │
│  역할:  조장                           │
│  기간:  2026-05-12 ~ 2026-07-31 (60개) │
│  담당자: 5명                           │
└──────────────────────────────────────┘

제목 *           [_______________________]
내용              [_______________________]
                 [_______________________]
                 [_______________________]
```

- 저장 → `App.post('/api/admin.php?action=task_group_update', { cohort, title, role, new_title, new_content_markdown })`
- 성공 토스트 후 `loadTasksMgmt()` / `loadTodayTasks()` / `loadOverdueTasks()` 새로고침.

### `_editTaskGroup(cohort, title, role)`

- `groups` 배열에서 해당 그룹을 찾아 `showTaskForm({ groupKey: { cohort, title, role }, ... })` 호출.
- 모달 안에서 `cohort/title/role` 은 hidden 으로 보존하며, 사용자 입력은 `new_title`, `new_content_markdown` 만.

### `_deleteTaskGroup(cohort, title, role, totalCount, doneCount)`

- 미완료 = `totalCount - doneCount`
- confirm 문구:
  - 미완료=0: `"이 묶음은 이미 모두 완료되어 삭제할 row 가 없습니다."` 알림 후 종료.
  - 완료=0: `"이 task 묶음 N개를 삭제하시겠습니까?"`
  - 둘 다 있음: `"이 task 묶음의 미완료 X개를 삭제합니다. 이력 보존을 위해 완료된 Y개는 남깁니다. 진행할까요?"`
- 응답 후 토스트: `"X개 삭제 / Y개 보존"`
- 새로고침 동일.

### 기존 `_editTask(id)` / `_deleteTask(id, title)`

- Task 관리 탭에서는 호출되지 않게 한다 (목록 자체가 그룹 단위로 바뀜).
- 함수 자체는 유지 가능 — 향후 그룹 펼치기 기능에서 재사용. 단, dead 코드 회피를 위해 같이 제거해도 무방.

## 권한

기존 `task_update` / `task_delete` 와 동일하게 `requireAdmin(['operation'])`. operation 외 role 은 호출 시 401.

## 영향 범위

- 회원/코치/조장 화면: 영향 없음. `today_tasks`/`overdue_tasks`/`toggle_task` 응답 구조 그대로.
- 다른 cohort: cohort 키가 묶음 식별의 일부라 cohort 11 ↔ cohort 12 의 동명 task 는 자동 분리.
- DB 마이그: 0건.
- 변경 파일:
  - `public_html/api/admin.php` — case 3개 추가
  - `public_html/js/admin.js` — `loadTasksMgmt`, `showTaskForm`, `_editTask`/`_deleteTask` 영역 수정 + 신규 2함수
- 자산 버전: `js/admin.js` 변경 → `?v=` 자동 (asset_version helper 가 mtime 기반).

## 인보리언트

1. **그룹 키 변경 안전성**: `task_group_update` 에서 title 변경 시, 같은 statement 내 한 번의 UPDATE 만 실행되므로 동시 다른 운영자가 같은 그룹을 동시에 수정해도 row lock 으로 직렬화된다.
2. **삭제 정책**: `task_group_delete` 후 동일 그룹 키로 다시 SELECT 했을 때 결과는 모두 `completed=1` 이어야 한다. (assertion 으로 확인, 실패 시 운영 audit log 권장)
3. **today/overdue 무영향**: 그룹 수정/삭제 후 `today_tasks` 응답이 정상 작동해야 한다 (단순 row UPDATE/DELETE 라 자동).
4. **단건 호환성**: 묶음 크기 1 그룹도 같은 API 로 수정/삭제 가능해야 한다.

## 테스트 시나리오 (DEV 검증)

1. **데일리 묶음 일괄 수정**: 12기 cohort 에서 daily 모드로 새 task 생성 → 묶음 크기 N 확인 → 그룹 수정으로 title 변경 → 모든 row 의 title·content 일괄 변경 확인.
2. **일부 완료 후 삭제**: 그룹의 일부 row 를 `toggle_task` 로 완료 처리 → 그룹 삭제 → 미완료 row 만 사라지고 완료 row 남는지 확인 + `deleted_count`/`kept_count` 응답 일치.
3. **모두 완료된 그룹 삭제 시도**: 모든 row 완료 → 삭제 시 `deleted_count=0` 응답 + UI 알림 처리.
4. **단건 (크기 1) 그룹**: direct/week 모드로 생성한 단건 task 도 그룹 수정·삭제 동작 확인.
5. **권한 거부**: operation 외 role 로 새 액션 호출 시 401.
6. **cohort 분리**: 11기·12기에 같은 title·role 묶음을 만들고 12기 묶음만 수정해도 11기 묶음에 영향 없는지 확인.
7. **today_tasks 무영향**: 그룹 수정 후 회원·조장 화면의 오늘 task / 지난 미완료 task 정상 표시.

## 배포

- DEV (`boot-dev`, `dev` 브랜치) 에서 작업 → push origin dev
- ⛔ 사용자 DEV 검증 + 명시적 운영 반영 요청 시에만 main 머지 후 prod pull
- DB 마이그 없음
