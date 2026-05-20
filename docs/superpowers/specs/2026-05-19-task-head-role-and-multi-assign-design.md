# Task: head 권한 + 다중 부여 방식 (역할별 / 전체 / 특정인)

날짜: 2026-05-19
작업 도메인: `boot.soritune.com` (DEV `_______site_SORITUNECOM_DEV_BOOT`, dev 브랜치)

## 1. 배경 · 목표

현재 `boot.soritune.com` 의 Task 관리 (Task 생성·수정·삭제, 묶음 표) 는 `operation` role 만 접근·조작 가능하며, Task 부여 방식은 "역할(role) 단위" 1가지뿐이다. 한 사람만 콕 집어 부여하거나 모든 역할을 한 번에 부여하려면 운영팀이 매번 우회해야 한다.

### 목표
1. **`head` 권한 확장** — `head`/`subhead1`/`subhead2` 에게도 Task 관리 권한과 화면을 부여.
2. **부여 방식 3종** — 같은 Task 생성 폼에서 세 방식을 명시적으로 선택할 수 있게 한다.
   - **역할별** (현행): 1개 이상의 role 을 골라 해당 role 전원에게 부여
   - **전체**: 현재 cohort 의 활성 운영진(admin) + 조장/부조장(member) 전원에게 부여
   - **특정 인물**: 검색으로 한 명을 골라 그 사람에게만 부여

### 비목표
- 조원(일반 회원) 학생 자신에게 Task 부여 — 본 작업 범위 밖
- Task 관리 외 운영 도구(회원 관리, 알림톡, 리텐션 등) 에 대한 head 권한 — 본 작업 범위 밖
- cohort 간 cross-assignment — 현 cohort 내로 한정

## 2. 데이터 모델

### 2.1 `tasks` 테이블에 컬럼 2개 추가

```sql
ALTER TABLE tasks
  ADD COLUMN group_kind ENUM('role','everyone','person') NOT NULL DEFAULT 'role'
    AFTER role,
  ADD COLUMN group_scope VARCHAR(80) NULL
    AFTER group_kind,
  ADD INDEX idx_cohort_group (cohort, title, group_kind, group_scope);
```

### 2.2 시맨틱

| `group_kind` | `group_scope` | `role` 컬럼 | `assignee_*` |
|---|---|---|---|
| `role`     | role 값 (예: `'coach'`)            | role 값 (동일) | 사람마다 row, 해당 role 한정 |
| `everyone` | `NULL`                             | 각 row 의 실제 사람 role | 사람마다 row, 모든 role 포함 |
| `person`   | `'admin:<id>'` 또는 `'member:<id>'`| 그 사람의 role | row 1개(date 1쌍 당), 그 사람만 |

- **묶음 키** = `(cohort, title, group_kind, group_scope)` — 기존 `(cohort, title, role)` 를 대체한다.
- `everyone` 의 `group_scope` 가 `NULL` 이므로 group-by 시 `COALESCE(group_scope, '')` 또는 NULL-safe `<=>` 사용.
- `person` 의 `group_scope` 에 `admin:<id>` / `member:<id>` 텍스트를 저장 → 사람이 삭제(`assignee_*` SET NULL)되어도 묶음 식별이 보존된다.
- 기존 `role` 컬럼은 모든 row 에서 유지. `today_tasks` / `overdue_tasks` 의 "내 role 매칭" SQL 이 신규 부여 종류에서도 자연스럽게 작동한다.

### 2.3 백필 마이그레이션 (`migrate_tasks_group_kind.php`)

```sql
UPDATE tasks
  SET group_kind = 'role', group_scope = role
  WHERE group_scope IS NULL;
```
멱등(`group_scope IS NULL` 가드). ALTER 직후 즉시 실행.

## 3. API

### 3.1 `task_create` 확장

신규 입력 필드:
```json
{
  "assignment_kind": "role" | "everyone" | "person",
  "roles": ["coach", "leader"],                 // kind=role 일 때만
  "target_person": { "type": "admin"|"member", "id": 123 }  // kind=person 일 때만
}
```
하위호환: `assignment_kind` 누락 시 `'role'` 폴백.

**검증**
- `role`: `roles` 비면 에러 (기존 동일)
- `everyone`: 추가 필드 없음
- `person`: `target_person.type/id` 필수. 룩업 시 active=1 + cohort 일치 확인. 비활성/타cohort/존재X 거부.

**부여 펼침 헬퍼 (신규)**

```php
expandAssignees($db, $cohort, $kind, $rolesOrPerson): array<{role, admin_id|null, member_id|null}>
```

| kind | 결과 |
|---|---|
| `role` | 기존 SELECT 그대로. role 별 사람 0명이면 NULL placeholder 1개 (기존 동작 유지). |
| `everyone` | 8개 role union. leader/subleader 는 `bootcamp_members`(active=1, cohort_id 매칭) / 나머지는 `admins`+`admin_roles`(active=1, cohort 매칭 또는 NULL). (admin_id, member_id) 튜플 dedupe. 0명이면 에러. |
| `person` | 1행 룩업 + active/cohort 검증. |

각 date pair × 각 assignee 로 row INSERT. 모든 row 에 `group_kind`/`group_scope` 동일 값 박음.

응답 `created_count` 유지.

### 3.2 `cohort_people_search` (신규 endpoint)

```
GET /api/admin.php?action=cohort_people_search&cohort=12&q=김
```
- 권한: `requireAdmin(['operation','head','subhead1','subhead2'])`
- `q` 필수, 길이 ≥ 1
- admin: `admins`+`admin_roles` JOIN, cohort 일치 또는 cohort IS NULL, active=1, 이름 LIKE
- member: `bootcamp_members` (active=1, cohort_id 매칭, role IN ('leader','subleader')), 이름+닉네임 LIKE
- 결과 최대 20개

```json
{ "success": true, "people": [
    { "type": "admin",  "id": 7, "name": "김운영", "role_labels": "운영팀,총괄코치" },
    { "type": "member", "id": 233, "name": "박샘플", "nickname": "샘플",
                        "role_labels": "조장", "group_no": 3 }
]}
```

### 3.3 묶음 endpoint 식별 키 확장

`task_group_get` / `task_group_update` / `task_group_delete` / `task_group_rows` / `all_tasks_grouped`:

- 입력 식별 키 `(cohort, title, role)` 을 `(cohort, title, group_kind, group_scope)` 로 확장.
- 기존 JS 호출자는 데이터가 자동 백필되어 있으므로 `group_kind='role'`/`group_scope=role` 으로 호환.
- `all_tasks_grouped` 의 GROUP BY 키도 동일 확장. SELECT 에 `t.group_kind, t.group_scope, MIN(person_name) AS person_name` 추가 (admin/member LEFT JOIN 한 줄).

### 3.4 권한

다음 endpoint 의 `requireAdmin(['operation'])` → `requireAdmin(['operation','head','subhead1','subhead2'])`:
- `task_create`, `task_update`, `task_delete`
- `task_group_get`, `task_group_update`, `task_group_delete`, `task_group_rows`
- `all_tasks_grouped` 의 `hasRole($admin,'operation')` 도 동일 확장
- 신규 `cohort_people_search`

`today_tasks`, `overdue_tasks`, `toggle_task`, `task_submission_update` 는 변경 없음. `filter_role='mine|all|<role>'` 분기는 operation 전용에서 `canManageTasks()` 로 확장.

`filter_role` 값에 `'kind:everyone'` / `'kind:person'` 추가 (kind 기준 필터).

## 4. 클라이언트 (JS)

### 4.1 `head/index.php` 와 admin.js 탭

`admin.js` 의 탭 분기에서 head 분기 (현재 `else`) 의 탭 목록에 Task 관리 탭 추가:

```html
<button class="tab" data-tab="#tab-tasks-mgmt" data-hash="tasks">Task 관리</button>
...
<div class="tab-content" id="tab-tasks-mgmt"></div>
```

탭 핸들러는 기존 `loadTasksMgmt()` 재사용.

`isOperation()` 분기를 다음 헬퍼로 치환:
```js
function canManageTasks() {
  return admin?.admin_roles?.some(r =>
    ['operation','head','subhead1','subhead2'].includes(r));
}
function isOperationOrHead() { return canManageTasks(); }
```

치환 지점:
- 대시보드 `sec-task-filter` 노출 조건
- `renderTaskCard()` 의 role 배지 노출 조건
- `loadTasksMgmt()` 의 filter chip 도구
- `today_tasks` / `overdue_tasks` 의 `filter_role` 파라미터 전송 조건

### 4.2 생성 폼 (`showTaskForm`) — 3-way 라디오

`isEdit === false` 일 때 `roleSection` 위치에 다음 패널을 둔다:

```html
<div class="form-group">
  <label class="form-label">부여 방식 *</label>
  <div style="display:flex;gap:12px;padding:4px 0">
    <label><input type="radio" name="tf-kind" value="role" checked> 역할별</label>
    <label><input type="radio" name="tf-kind" value="everyone"> 전체</label>
    <label><input type="radio" name="tf-kind" value="person"> 특정 인물</label>
  </div>
</div>

<div id="tf-kind-role" class="tf-kind-section">
  <label class="form-label">담당 역할 * (복수 선택 가능)</label>
  <div style="display:flex;flex-wrap:wrap;padding:8px 0">
    ${renderRoleCheckboxes([], 'tf')}
  </div>
</div>

<div id="tf-kind-everyone" class="tf-kind-section" style="display:none">
  <p class="text-muted">현재 cohort 의 활성 운영진 + 조장/부조장 전원에게 부여됩니다.
     각자 자기 화면에서 개별 체크합니다.</p>
</div>

<div id="tf-kind-person" class="tf-kind-section" style="display:none">
  <label class="form-label">담당자 *</label>
  <input type="text" class="form-input" id="tf-person-search"
         placeholder="이름·닉네임으로 검색" autocomplete="off">
  <div id="tf-person-results" class="person-search-results"></div>
  <input type="hidden" id="tf-person-type">
  <input type="hidden" id="tf-person-id">
  <div id="tf-person-selected" style="margin-top:6px"></div>
</div>
```

- 라디오 change → `.tf-kind-section` 표시 토글 (`tf-date-section` 패턴과 동일)
- 사람 검색 input → 300ms debounce → `cohort_people_search` 호출 → 결과 리스트 클릭 시 hidden id 채움 + chip 표시 (×버튼 해제)
- 날짜 모드 (direct/week/daily) 는 부여 방식과 무관하게 모두 사용 가능

저장(`tf-save`) 시 payload:
- `assignment_kind` = 선택된 라디오 값
- `role`: `roles` = `getCheckedRoles('tf')`
- `everyone`: 추가 필드 없음
- `person`: `target_person` = `{ type: tf-person-type, id: tf-person-id }`. id 없으면 경고

### 4.3 묶음 표 (`loadTasksMgmt`) — '대상' 컬럼

```html
<thead><tr><th>제목</th><th>대상</th><th>담당자</th><th>기간</th><th>진행</th><th></th></tr></thead>
```

`대상` 셀 렌더:
- `role` → `<span class="badge badge-primary">${ROLE_LABELS[scope]}</span>`
- `everyone` → `<span class="badge badge-info">📣 전체</span>`
- `person` → `<span class="badge badge-info">👤 ${assignee_name}</span>`

필터 chip 에 추가:
```js
{ key: 'kind:everyone', label: '📣 전체 부여' },
{ key: 'kind:person',   label: '👤 개인 부여' },
```

### 4.4 묶음 수정 모달

`isEdit` 분기의 묶음 정보 박스에 부여 방식 표시:
- 역할별 → "역할: 메인강사"
- 전체 → "대상: 전체 (운영진+조장 전원)"
- 개인 → "대상: 👤 김아무개"

부여 방식·범위는 묶음 식별이므로 수정 불가 (기존 role 비활성 정책과 동일). 변경하려면 삭제 후 재생성 안내.

### 4.5 `renderTaskCard` 카드 배지

- `everyone`/`person` 배지는 **모든 사용자**에게 노출 (`"📣 전체"` / `"👤 개인 지정"`)
- 기존 role 배지는 `canManageTasks()` 만 노출 (관리자 시야 유지)

## 5. 테스트

### 5.1 인보리언트 (`tests/task_group_invariants.php` 확장)

- `INV-G1`: 모든 row 의 `group_kind` ∈ {role, everyone, person} 이고 `group_scope` NULL 여부가 kind 와 일치
  - role: scope NOT NULL AND = role
  - everyone: scope IS NULL
  - person: scope LIKE 'admin:%' OR 'member:%' AND assignee_admin_id/member_id 와 매칭
- `INV-G2`: `person` 묶음은 묶음 키 당 assignee 1명 (date pair 별로 row 여러 개여도 같은 사람)
- `INV-G3`: `everyone` 묶음에 같은 (admin_id 또는 member_id, start_date, end_date) 중복 없음

### 5.2 단위 테스트 (`tests/task_create_api_test.php` 신규)

- `kind=role`: 2 role × 3 date → 정확한 카운트, role 분리 묶음 (회귀)
- `kind=everyone`: cohort 활성 admin N + 활성 leader/subleader M → (N+M) × date 수
- `kind=everyone`: 활성 0명이면 에러
- `kind=person, type=admin`: 1 admin × date 수 row, `group_scope='admin:<id>'`
- `kind=person, type=member`: bootcamp_member 룩업, 비활성 거부, 타 cohort 거부
- `cohort_people_search`: q='ㅋ' → 매칭만 반환, 비활성/타cohort 제외, 최대 20개

### 5.3 권한 회귀 (`tests/task_permissions_test.php` 신규)

- operation / head / subhead1 / subhead2: 4 endpoint 모두 200
- coach / sub_coach / leader / subleader: 403
- 비로그인: 401

### 5.4 wrapper_verify

`tests/wrapper_verify.php` 에 신규 endpoint `cohort_people_search` 포함 (있을 경우).

## 6. 마이그레이션 · 배포

### 6.1 마이그 스크립트 — `migrate_tasks_group_kind.php`

- `INFORMATION_SCHEMA.COLUMNS` 로 `group_kind` 컬럼 존재 확인 → 없으면 `ALTER` 실행
- `UPDATE` 백필은 `group_scope IS NULL` 가드로 멱등
- 트랜잭션으로 감싸기. 12기 진행 중이라 동시 INSERT 가능성 → ALTER 직후 백필까지 한 호흡

### 6.2 DEV 배포

1. dev: 마이그 적용 (`junior-prod` 룰과 동일하게 항상 dev 먼저)
2. 인보리언트 + 단위 + 권한 테스트 PASS 확인
3. dev push → `dev-boot.soritune.com` 에서 사용자 시각 검증
4. 사용자 명시 시점에 main 머지 + prod pull + PROD 마이그 + 스모크

### 6.3 PROD 스모크 체크리스트

- 운영팀 1명 + 총괄 1명 로그인 → Task 관리 진입 (탭 보임)
- 기존 묶음들이 `대상` 컬럼에 role 배지로 보임 (회귀 없음)
- kind=role 추가 (회귀)
- kind=everyone 추가 → 8 role 사람 수 만큼 row, 모두 자기 카드에 노출
- kind=person 추가 → 검색해서 1명만 부여 → 그 사람만 카드 노출
- 묶음 수정 모달에서 부여 방식 정보 표시 확인
- 묶음 삭제 → 미완료만 삭제, 완료 row 보존 (기존 동작)
- 토글 / 결과물 제출 / 진행 배지 (회귀)

## 7. 위험 · 결정 기록

- **NULL group_scope (everyone)**: GROUP BY 시 NULL-safe 처리 필요. SQL 작성 시 `COALESCE(group_scope, '')` 패턴 일관 적용. 인보리언트에서 NULL 허용 룰 명시.
- **person scope 텍스트 키**: `assignee_*` 가 SET NULL 되어도 group 식별 보존. 단 표시명(`assignee_name`) 은 LEFT JOIN 이라 NULL 가능. UI에서 "삭제된 사용자" 라벨 폴백.
- **everyone 부여 폭주**: 사람 N+M 명 × date 수 만큼 row. cohort 30~50명 × daily 28일 → 1000+ row 가능. 인보리언트 통과 가능하나 단일 INSERT 트랜잭션 묶기 권장.
- **하위호환**: `assignment_kind` 누락 호출자 (없을 것으로 보이지만 방어적으로) → `'role'` 폴백. 기존 호출자/모바일 PWA 캐시 호환.
- **subhead 토글 가시화**: head 페이지에 접근하는 subhead1/2 도 Task 관리 탭 보임. 운영팀 외 3 role 까지 노출 — 사용자 결정 (확인 답변: "head/subhead1/subhead2 모두 허용").

## 8. 메모리 룰 준수

- 작업은 `boot-dev` (DEV_BOOT, dev 브랜치) 에서만 수행.
- DB 수정은 `boot-dev` `.db_credentials` 로 DEV DB 먼저.
- dev push 후 사용자 명시 확인 전까지 main 머지·prod pull·PROD 마이그 절대 금지.
