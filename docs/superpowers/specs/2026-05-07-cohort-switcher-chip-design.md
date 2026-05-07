# Cohort Switcher Chip — 기수 전환 칩 설계

**작성일:** 2026-05-07
**대상:** boot.soritune.com (junior/PT 비대상)
**배경:** 기수 전환 시점의 1~2주 동안 운영자·코치·회원 모두 11기/12기 양쪽을 동시에 봐야 하는 니즈가 있음. 현재는 한 시점에 active 기수가 1개라는 단일 가정 위에서 동작하여 충돌이 발생함.

---

## 1. 문제 정의

### 1.1 발생 증상
- 코치가 어드민에서 강의 스케줄/이벤트(특강) 추가 시 **"유효한 기수를 찾을 수 없습니다"** 에러
- `lecture.php` 의 `SELECT * FROM cohorts WHERE id = ? AND is_active = 1` 검증에서 12기(`is_active=0`) 가 통과 못 함

### 1.2 데이터 상태 (PROD, 2026-05-07)
| 위치 | 값 |
|---|---|
| `settings.current_cohort` | `12기` |
| `cohorts.is_active` | 11기=1, **12기=0** |
| 12기에 이미 등록된 데이터 | lecture_schedules 8건, lecture_events 3건 (과거 활성화 상태에서 등록됨) |

### 1.3 근본 원인
이전 작업("boot 활성 기수 로그인 fix") 메모에 따라 운영자가 11기·12기 양쪽 등록 회원의 로그인 충돌을 피하기 위해 12기를 비활성으로 두었으나, `settings.current_cohort` 와 `cohorts.is_active` 가 분리되어 있어 코드 검증이 어긋남.

### 1.4 더 큰 요구사항
이 충돌은 매 기수 전환마다 1~2주 반복됨. 영구적인 multi-active 인프라가 필요하되, 기수가 1개일 때의 평소 UX 는 변하지 않아야 함.

---

## 2. 데이터 모델 발견

`bootcamp_members` 는 **이미 cohort 별로 row 분리**되어 있음:
- `id` (PK), `cohort_id` (별개 컬럼), `phone`, `user_id` 등
- 한 사람이 11기·12기 양쪽 등록 시 row 가 2개 존재
- PROD 확인: 일부 회원은 최대 12개 cohort row 보유
- 점수·코인·출석·조 정보 등은 모두 `member_id`(row 단위) 종속 → 자연스럽게 cohort 별 분리

**핵심 아이디어:** 칩으로 기수를 전환한다 = 같은 사람의 다른 cohort row 로 `$_SESSION['member_id']` 를 swap. 기존 cohort-scoped 로직은 그대로 두고 login flow + 칩 endpoint 만 신규.

---

## 3. 결정 사항

| 항목 | 결정 |
|---|---|
| 칩 전환 시 영향 범위 | 보기·쓰기 모두 전환 |
| 회원 default 기수 | 가장 최근 cohort_id (12기). sticky 쿠키 없음. 매 로그인 reset |
| 어드민 칩 옵션 | active 기수 + "전체" |
| 기존 cohort-bar 와 관계 | 흡수 — chip 하나로 통일. settings.current_cohort 변경 기능은 "기수 관리" 탭으로 이동 |

---

## 4. Architecture

### 4.1 세션 schema

**Member 세션 (필드 추가)**
- 기존: `member_id`, `member_name`, `cohort`, `nickname`
- 신규: `accessible_cohorts: [{member_id, cohort_id, cohort_label}, ...]` (cohort_id DESC)

**Admin 세션 (필드 추가)**
- 기존: `admin_id`, `admin_name`, `admin_roles`, `cohort`, `bootcamp_group_id`
- 신규: `admin_view_cohort_id: int | null` (null = "전체")

### 4.2 신규/변경 함수 (`auth.php`)

```php
// 신규
function findMemberAccessibleRows(PDO $db, string $phone): array
// phone 으로 active cohort 의 모든 row 반환 (cohort_id DESC)

function swapMemberCohort(int $cohortId): bool
// 세션 accessible 안에 있는 cohort_id 인지 검증 후
// member_id, cohort 갱신 + session_regenerate_id(true)

function getEffectiveCohortId(array $session): ?int
// admin_view_cohort_id 우선, null fallback to settings.current_cohort 매핑

function getCohortLabelById(int $id): ?string
function getCohortIdByLabel(string $label): ?int

function resolveAdminCohortId(?int $explicit, array $session, bool $supportsAll = false): ?int
// 명시 cohort_id → admin_view_cohort_id → (supportsAll ? null : getCohortIdByLabel(settings.current_cohort))
```

`loginMember()` 시그니처 확장: `accessible_cohorts` 도 세션에 저장.
`getEffectiveCohort($session)` 확장: `admin_view_cohort_id` 우선.

### 4.3 신규 endpoint

| Endpoint | 동작 |
|---|---|
| `POST /api/member.php?action=switch_cohort` | body `{cohort_id}`. swapMemberCohort. 응답 `{success}` → 클라 reload |
| `POST /api/admin.php?action=switch_cohort` | body `{cohort_id: int\|null}`. `admin_view_cohort_id` 갱신. 응답 `{success}` → 클라 reload |

### 4.4 기존 endpoint 변경
- `/api/member.php?action=login`: `findMemberByPhone` → `findMemberAccessibleRows`. 첫 row(최신)로 시작. 응답에 `accessible_cohorts` 포함
- `/api/admin.php?action=change_cohort` (settings.current_cohort): 유지하되 헤더 `<select>` 에서 호출 안 됨. 트리거 위치를 "기수 관리" 탭의 cohort row 옆 신규 버튼으로 이동
- `loginAdmin()`: `admin_view_cohort_id = settings.current_cohort 매핑 cohort.id` 로 default 설정. inactive면 가장 최근 active 로 fallback

### 4.5 보안
- `switch_cohort` 는 POST 전용
- 멤버 swap 은 `member_id` 자체가 바뀌므로 `session_regenerate_id(true)` 필수 (fixation 방지)
- 어드민은 view filter 라 regenerate 불필요
- accessible 검증: 세션의 `accessible_cohorts` 안에 있는 cohort_id 만 허용

---

## 5. UI/UX

### 5.1 회원 측 헤더

**현재**
```html
<div class="member-header">
    <div class="header-title">소리튠 부트캠프</div>
    <div class="member-cohort">12기</div>
</div>
```

**변경 후**
- 단일 cohort 회원: 그대로 정적 텍스트
- 다중 cohort 회원: dropdown chip
```html
<button class="member-cohort cohort-chip" id="btn-cohort-switch">
    12기 ▾
</button>
```

클릭 시 dropdown:
```
┌──────────┐
│ 12기 ✓   │
│ 11기     │
└──────────┘
```

### 5.2 어드민/코치 측 헤더
- 헤더 우측 (이름·로그아웃 좌측) 에 chip 부착
- 기존 운영자 한정 `cohort-bar` (셀렉트) 제거
- 항상 표시. 옵션: active 기수 + "전체"

```
┌──────────┐
│ 12기 ✓   │
│ 11기     │
│ 전체     │
└──────────┘
```

### 5.3 전환 흐름
1. chip 클릭 → dropdown
2. 선택 → switch_cohort API 호출
3. 서버 세션 swap
4. 클라이언트 `location.reload()` (모든 모듈을 새 cohort 로 일괄 재로드)

### 5.4 default 동작
| 주체 | 로그인 default | sticky |
|---|---|---|
| 회원 | accessible_cohorts[0] (cohort_id DESC, 12기) | 세션 동안만 |
| 어드민 | settings.current_cohort 매핑 cohort.id | 세션 동안만 |

로그아웃·재로그인 시 default 로 reset.

### 5.5 chip 컴포넌트
- 신규 `js/cohort-chip.js` 공통 모듈
- DOM 생성, dropdown 토글, 전환 API 호출, reload
- `member.js` / `admin.js` 양쪽이 import

### 5.6 settings.current_cohort 변경 위치 이동
- 기존: 헤더 `<select>` change_cohort
- 신규: "기수 관리" 탭 — 각 cohort row 옆 "운영 기수로 설정" 버튼
- 운영자가 자주 바꾸지 않는 액션이라 탭 안으로 이동해도 무방

---

## 6. 영향 페이지 audit

### 6.1 회원 측 — 자동 적용
모든 회원 API 가 `$_SESSION['member_id']` 기반. row swap 만으로 동작. **변경 불필요**. login/me 응답에 `accessible_cohorts` 만 추가.

### 6.2 어드민/코치 측

| 탭 | API | 변경 | 전체 지원 |
|---|---|---|---|
| 대시보드 | dashboard.php, admin.php | view_cohort_id 우선 | ✗ fallback |
| Task 관리 | admin.php | view_cohort_id 우선 | ✗ fallback |
| 캘린더 관리 | admin.php | view_cohort_id 우선 | ✗ fallback |
| **특강 관리** | lecture.php | view_cohort_id default | ✓ |
| 출석 현황 | attendance.php | view_cohort_id default | ✗ fallback |
| 체크리스트 | check.php | view_cohort_id default | ✗ fallback |
| 현황판 | check.php / dashboard.php | view_cohort_id default | ✗ fallback |
| 패자부활전 | check.php (revival) | view_cohort_id default | ✗ fallback |
| 회원 관리 | admin.php | view_cohort_id default | ✓ |
| 조 배정 | group_assignment.php | chip 이 default 만 변경 | (자체 UI) |
| 카페 게시글 | admin.php (cafe) | view_cohort_id 우선 | ✓ |
| 코인 Cycle | coin_functions / admin.php | view_cohort_id default | ✗ fallback |
| 가이드 관리 | admin.php (guides) | view_cohort_id 우선 | ✗ fallback |
| 진도 관리 | curriculum.php | view_cohort_id default | ✗ fallback |
| 일괄 등록 | member_bulk.php | view_cohort_id 우선 | ✗ fallback |
| 복습스터디 | study.php | view_cohort_id default | ✗ fallback |
| 후기 | review.php | view_cohort_id default | ✗ fallback |
| 오류 문의 | admin.php (issues) | view_cohort_id 우선 | ✗ fallback |
| 기수 관리 | admin.php | chip 무관 | (해당 없음) |
| 관리자 관리 | admin.php | chip 무관 | (해당 없음) |
| 알림톡 | notify*.php | chip 무관 (자체 UI) | (자체 UI) |
| 리텐션 관리 | retention.php | chip 무관 (자체 UI) | (자체 UI) |

### 6.3 "전체" 미지원 페이지 동작
- chip 이 "전체" 인 상태로 진입 시 `resolveAdminCohortId(supportsAll=false)` → settings.current_cohort 로 fallback
- 페이지(탭) 진입 시마다 1회 토스트: "이 화면은 단일 기수만 지원합니다 (현재: 12기)"
- chip 표시는 "전체" 그대로 유지 (다른 페이지 이동 시 거기 적용)

### 6.4 자체 cohort UI 페이지 (조 배정/리텐션/알림톡)
- 자체 셀렉터 유지
- 초기값을 `admin_view_cohort_id` 와 동기화 (있는 경우)

---

## 7. 롤아웃

### 7.1 DB 변경
**없음.** 기존 schema 그대로 사용.

### 7.2 세션 호환성
- 배포 직후 기존 세션은 신규 필드 누락
- `getMemberSession()` / `getAdminSession()` 진입 시 누락 필드 inline populate
- 강제 로그아웃 불필요

### 7.3 단계화

**P1 (즉시 해결, 코드 변경 0):**
운영자가 어드민 "기수 관리" 탭에서 **12기 is_active=1 토글** → lecture.php 의 `is_active = 1` 검사 자연 통과 → 코치가 12기 강의/이벤트 추가 가능. 이전 로그인 충돌은 이미 WHERE 필터로 코드 보호되어 있고, 11기 종료(4/23) 후라 신규 등록자 대부분 12기로 들어감.

**P2 (풀 구현):**
chip 시스템 전체 배포. P1 으로 즉시 차단 해제 후 P2 정식 구현 권장.

### 7.4 DEV 검증 시나리오 (P2)
1. 단일 cohort 회원: chip 안 보임, 기존 동작 동일
2. 다중 cohort 테스트 회원 (11기·12기 양쪽 등록): chip 노출, 12기 default, 11기 전환 시 대시보드/스터디/QR 모두 11기 데이터
3. swap 후 점수/코인이 11기 row 값으로 표시
4. 운영자 chip "11기" → 출석/체크리스트 11기 데이터
5. 운영자 chip "전체" → 회원 관리 multi-cohort, 대시보드는 fallback + 토스트
6. 코치 chip "전체" → 권한 없는 cohort 데이터 backend 차단 (회귀 없음)
7. 특강 추가: chip 12기 → 12기 강의 추가 성공
8. settings.current_cohort 변경 (기수 관리 탭) → 새 로그인 admin default 변경

### 7.5 PROD 배포 순서 (P2)
1. DEV 검증 시나리오 모두 PASS
2. `dev` push → 사용자 확인
3. main 머지 → prod pull
4. PROD smoke:
   - 운영자 chip 동작 (12기/11기/전체 전환)
   - 코치 1명 12기 특강 시범 추가 → 성공
   - 다중 cohort 회원 1명 chip 노출 (DB 쿼리로 후보 식별)
5. 24시간 모니터링: 로그인 실패율, 특강 추가 에러, php error log

### 7.6 Rollback
- 코드: `git revert` 또는 이전 main hard-reset
- 세션은 forward-compatible (이전 코드는 새 필드 무시)
- `is_active` 토글은 별개 액션이라 rollback 영향 없음

### 7.7 코드 변경 규모 (P2)
- 신규: `js/cohort-chip.js`
- 변경: `auth.php` (helpers), `api/member.php` (login + switch), `api/admin.php` (switch + change_cohort 트리거 이동), `js/member.js` + `js/admin.js` (chip 부착), 어드민 API 다수에 `resolveAdminCohortId` 헬퍼 일괄 적용
- 마이그레이션: 없음

---

## 8. 비목표 (Non-goals)

- junior / PT / routines 등 다른 사이트는 대상 아님 (보트 전용)
- inactive 기수(과거 1~10기) 의 view filter 진입은 별도 기능 (기수 관리 탭의 read-only 보기로 충분)
- 회원 개인이 "기본 기수" 를 sticky 로 저장하는 cookie/UI 는 미도입 (매 로그인 reset 합의)
- "전체" 모드에서 페이지마다 cohort 컬럼 추가하는 UI 정밀화는 Phase 2 이후 점진적 개선
