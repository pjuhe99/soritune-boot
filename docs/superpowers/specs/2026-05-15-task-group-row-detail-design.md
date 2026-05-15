# Task 묶음 미완료 상세 펼침 설계 (boot)

- **작성일**: 2026-05-15
- **사이트**: `boot.soritune.com`
- **대상 화면**: `/operation` → "Task 관리" 탭 (그룹화 목록)
- **DB 마이그**: 없음
- **선행 spec**: [2026-05-15-task-bulk-edit-design.md](2026-05-15-task-bulk-edit-design.md) (PROD 배포 완료)

## 배경 / 문제

운영자가 새 그룹화된 Task 관리 탭에서 진행 배지 (`35/63`) 만 봐서는 "누가 어느 날짜 task 를 빠뜨렸는지" 파악이 안 된다. leader 의 `출석 독려 & 분위기 유지` 같은 297 row 묶음에서 어느 조장이 며칠 누락했는지 확인할 길이 없다.

## 목표

그룹 행을 펼쳐 묶음 안의 row 단위 (날짜 + 담당자 + 완료 여부) 를 확인하고, 그 자리에서 토글로 완료 처리할 수 있게 한다.

## 비목표 (Out of scope)

- 매트릭스 표시 (담당자 × 날짜 셀). 큰 묶음 가로 스크롤 부담.
- 담당자별 그룹화 보기. (필요 시 후속.)
- CSV export, 미완료 알림 발송.
- 그룹 안에서 단일 row 의 title/content 만 다르게 수정 (group_update 로 일괄 갱신만 지원).

## UX

### 진입

Task 관리 테이블의 **그룹 row 를 클릭**하면 그 행 바로 아래에 expand row 가 삽입된다.

- 행 좌측에 화살표 (▶ 닫힘 / ▼ 열림) 추가 — clickable affordance
- 한 번에 하나의 그룹만 펼침. 다른 그룹 클릭하면 이전 펼침은 자동으로 닫힘.
- 수정/삭제 버튼은 `event.stopPropagation()` 으로 행 클릭과 분리.

### 펼침 안

```
┌─ 펼침 ─────────────────────────────────────────────┐
│  [○ 미완료만 | ● 전체]   (오늘까지: end_date ≤ 2026-05-15) │
│                                                      │
│  5/12(월)  김조장      [☐ 완료하기]                  │
│  5/13(화)  김조장      [☐ 완료하기]                  │
│  5/13(화)  박조장      [☐ 완료하기]                  │
│  5/14(수)  김조장      [☐ 완료하기]                  │
│  ... (28개)                                          │
└──────────────────────────────────────────────────────┘
```

- 상단 토글: `미완료만` (default) ↔ `전체`. 토글 변경 시 lazy-fetch.
- 보조 텍스트: 현재 필터가 적용된 컷오프 날짜 — "오늘까지" 체크 상태일 때만 노출.
- 리스트 한 row: `M/D(요일)  담당자명  [완료/미완료 토글]`
- 미배정 row: 담당자명 자리에 "미배정" (회색).
- 빈 상태:
  - 미완료만 토글 + 0건: `"이 묶음은 오늘까지 미완료가 없습니다."`
  - 전체 토글 + 0건: `"이 묶음에 row 가 없습니다."` (실제로는 거의 발생 안 함)

### Toggle (그 자리에서 완료 처리)

리스트 row 의 체크박스 클릭 → 기존 `toggle_task` API 호출. 응답 후:
- 그 row 의 체크 상태 + "완료/미완료" 라벨 client-side 갱신
- 상위 그룹 row 의 진행 배지 (`35/63`) 도 client-side 로 ±1 갱신
- 펼침 닫지 않음 — 연속 토글 가능
- 토글 실패 시 (네트워크 에러 등) 토스트 + 롤백

## API 변경 (api/admin.php)

### 추가: `task_group_rows`

- `requireAdmin(['operation'])`
- HTTP: GET (단순 조회). 입력은 query string.
- 입력:
  - `cohort` (string, required)
  - `title` (string, required)
  - `role` (string, required)
  - `only_incomplete` (query string, `'1'` / `'0'`, default `'1'`). PHP 측 판정: `($_GET['only_incomplete'] ?? '1') === '1'`.
  - `only_until_today` (query string, `'1'` / `'0'`, default `'1'`). 동일 패턴.
- SQL 골자:
  ```sql
  SELECT t.id,
         t.start_date,
         t.end_date,
         t.completed,
         COALESCE(a.name, bm.real_name) AS assignee_name,
         CASE
           WHEN t.assignee_admin_id  IS NOT NULL THEN 'admin'
           WHEN t.assignee_member_id IS NOT NULL THEN 'member'
           ELSE 'unassigned'
         END AS assignee_kind
    FROM tasks t
    LEFT JOIN admins a            ON t.assignee_admin_id  = a.id
    LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
   WHERE t.cohort = ? AND t.title = ? AND t.role = ?
     [ AND t.completed = 0 ]            -- only_incomplete=1 일 때
     [ AND t.end_date <= CURDATE() ]    -- only_until_today=1 일 때
   ORDER BY t.start_date ASC, assignee_name ASC
  ```
- 응답:
  ```json
  {
    "rows": [
      { "id": 12345, "start_date": "2026-05-12", "end_date": "2026-05-12",
        "completed": 0, "assignee_name": "김조장", "assignee_kind": "member" },
      ...
    ],
    "cutoff_today": "2026-05-15",
    "filters": { "only_incomplete": true, "only_until_today": true }
  }
  ```

`cutoff_today` 는 펼침 UI 의 보조 텍스트("오늘까지: end_date ≤ 2026-05-15") 표시용. 서버 시각 기준으로 운영자 시계와 차이가 나도 일관된다.

### 변경 없음

- `toggle_task` (기존, row 단위 PUT) 그대로 재사용. 펼침 안에서 토글하면 이걸 호출.
- 다른 group_* endpoint 영향 없음.

## UI 변경 (js/admin.js)

영향 함수:
- `loadTasksMgmt` — 그룹 row 에 click 핸들러 + 화살표 prefix 추가, 수정/삭제 버튼은 stopPropagation
- 신규 `_toggleGroupExpand(cohortEnc, titleEnc, roleEnc, btnEl)` — 펼침 토글 (열기/닫기/다른 행 닫기)
- 신규 `_renderGroupExpand(container, cohort, title, role, opts)` — 펼침 영역 렌더 + 필터 토글 + lazy fetch
- 신규 `_toggleRowComplete(taskId, btnEl, groupRowEl)` — row 토글 + 진행 배지 클라이언트 갱신
- 기존 `_editTaskGroup` / `_deleteTaskGroup` — 변경 없음

### 펼침 행 위치

```html
<tr class="group-row" data-group-key="…"> … </tr>
<tr class="group-expand" style="display:none">
  <td colspan="6"> [펼침 컨테이너] </td>
</tr>
```

전 그룹 row 마다 hidden expand row 를 같이 렌더하거나, 클릭 시 동적 삽입. **클릭 시 동적 삽입** 방식 채택 — 큰 묶음 297 row 같은 케이스에서 메모리 절약.

### 진행 배지 client-side 갱신 패턴

펼침 안에서 row 토글 시:
- 새 completed 상태가 1 → 그룹 row 의 done_count 셀 +1
- 새 completed 상태가 0 → done_count 셀 -1
- total_count 는 변하지 않음
- 배지 클래스 (`badge-success/primary/warning`) 도 done==total / done==0 / 중간 에 따라 재계산해서 swap

서버 round-trip 없이 DOM 만 업데이트. 다음에 `loadTasksMgmt()` 가 호출되면 (예: 그룹 수정/삭제) 다시 fresh 로 덮어쓰임.

### Lazy fetch 시점

- 펼침 처음 여는 시점
- 미완료/전체 토글 변경 시점
- toggle_task 후에는 fetch 안 함 (이미 client-side 갱신)

## 인보리언트

1. **펼침 row 가 그룹 키와 일치**: SQL WHERE `cohort=? AND title=? AND role=?` 가 `task_group_*` 와 동일 → 펼침에 보이는 row 는 항상 그 그룹 안.
2. **toggle 무영향**: `toggle_task` (단일 row UPDATE) 는 회원·코치·조장 화면용 today/overdue task 응답 구조에 영향 없음.
3. **only_until_today 정책**: `end_date <= CURDATE()` — 오늘 끝나는 row 도 포함 (오늘 미완료 = 운영자 관심사). 미래 row 는 토글 ON 시 자연스럽게 미완료로 표시되므로 노이즈.
4. **client-side 진행 배지 갱신 vs 서버 진실**: 토글 후 즉시 보이는 배지는 client 추정값. 다음 `loadTasksMgmt` reload 시 서버 진실로 동기화. 한 세션 내 단일 운영자만 토글하므로 desync 가능성 낮음.

## 테스트 시나리오 (DEV)

DEV 데이터 없으니 시드 후 검증 — `(cohort='12기', title='__테스트', role='head')` 로 30 row INSERT (완료/미완료 혼합).

1. **펼침 lazy fetch**: 그룹 행 클릭 → 펼침 등장, GET `task_group_rows` 1회 호출 확인.
2. **다른 행 펼치면 이전 닫힘**: A 펼침 → B 클릭 → A 자동 닫힘.
3. **미완료/전체 토글**: 토글 변경 시 새로운 fetch + 리스트 갱신.
4. **오늘까지 컷오프**: cohort 종료일이 미래인 묶음에서 미완료만 모드 → end_date ≤ 오늘 row 만 보임. 전체 모드 → 미래 row 도 보임.
5. **row 토글**: 미완료 row 클릭 → 완료 처리 + 그 row UI 변경 + 그룹 진행 배지 +1 갱신.
6. **미배정 row**: 담당자명 자리에 회색 "미배정" 표시.
7. **수정/삭제 버튼 충돌 X**: 수정 버튼 클릭 시 펼침 안 발생.
8. **그룹 수정/삭제 후 refresh**: 펼침 상태에서 수정/삭제 → loadTasksMgmt() reload → 펼침 자연스럽게 닫힘.
9. **권한**: head/coach 권한 admin 으로 GET `task_group_rows` 호출 시 403.
10. **회원·코치·조장 화면 무영향**: today_tasks/overdue_tasks 표시 정상.

## 영향 범위 / 리스크

- DB 마이그: 0건
- 변경 파일:
  - `public_html/api/admin.php` — case `task_group_rows` 추가 (~30 lines)
  - `public_html/js/admin.js` — `loadTasksMgmt` 수정 + 신규 함수 3개 (~120 lines)
- 자산 버전: js/admin.js mtime 변경 → `?v=` 자동
- 기존 `toggle_task` 무수정 — fallback 경로 안전
- 큰 묶음 (297 row) 펼침 시: 미완료만 default 라 운영 단계에서 보통 수십 row 이내. 전체 토글 시 297 row 한꺼번에 DOM 렌더 — 부담은 있지만 modal/페이지 reload 없이 한 화면에 처리 가능 수준.

## 배포

- DEV (`boot-dev`, dev 브랜치) 작업 → push origin dev
- ⛔ 사용자 명시 시 main 머지 + prod pull
- DB 마이그 없음

## 차후 가능성 (현재 out of scope)

- 담당자별 그룹화 보기 (한 사람이 며칠을 빼먹었는지 한눈에)
- 미완료 row CSV export / 알림톡 일괄 발송
- 페이지네이션 (300+ row 묶음에서 가상 스크롤)
