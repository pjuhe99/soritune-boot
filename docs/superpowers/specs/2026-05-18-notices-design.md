# 공지사항 기능 설계

작성일: 2026-05-18
대상: boot 프로젝트 (`boot.soritune.com` / `dev-boot.soritune.com`)
다음 단계: writing-plans 로 implementation plan 작성

## 1. 배경 / 목적

부트캠프 운영진(coach / head / operation)이 회원에게 알리고 싶은 짧은 공지(주차 안내, 일정 변경, 이벤트 등)를 어드민에서 직접 등록·수정·숨김 처리하고, 활성 기수 회원의 메인 화면 상단(프로필 카드 직후, "오늘의 진도 & 할 일" 직전)에 마크다운으로 렌더된 카드 형태로 노출한다.

현재는 회원에게 알리는 채널이 카카오톡 그룹과 카페뿐이라 메인 화면을 보는 회원이 운영 공지를 놓친다. 별도 push 채널 없이도 메인 진입 시 한 번에 보이게 하는 것이 목표.

**비목표** (이번 spec 에서 다루지 않음):
- 외부 push (알림톡, 이메일, 웹 push)
- 회원별 dismiss / read-state
- 노출 기간 자동 종료 (start/end 날짜 스케줄)
- 첨부 파일 / 이미지 업로드
- 작성 이력 audit log (`change_logs` 등 별도 연동)
- 마크다운 sanitize (입력자가 어드민 한정이라 trust)
- 알림 카운트 / 배지

## 2. 데이터 모델

```sql
CREATE TABLE notices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cohort_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  body_markdown TEXT NOT NULL,
  is_visible TINYINT(1) NOT NULL DEFAULT 1,
  created_by_admin_id INT UNSIGNED NOT NULL,
  created_by_admin_name VARCHAR(100) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cohort_visible_created (cohort_id, is_visible, created_at),
  CONSTRAINT fk_notices_cohort  FOREIGN KEY (cohort_id) REFERENCES cohorts(id),
  CONSTRAINT fk_notices_admin   FOREIGN KEY (created_by_admin_id) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

설계 노트 (FK 타입 매칭):
- `cohorts.id` / `admins.id` 가 `INT UNSIGNED` 이므로 FK 컬럼도 동일하게 `INT UNSIGNED` (InnoDB 는 FK 양쪽 타입/사인 정확히 일치 요구).

설계 노트:
- `created_by_admin_name`: 등록 시점 어드민 이름 **스냅샷**. 어드민이 이름을 바꾸거나 비활성화돼도 회원이 본 표시명이 흔들리지 않게 함. footer 의 `등록자명 · 날짜` 표시용. footer 에 별도 JOIN 회피.
- `is_visible`: 1=노출 / 0=숨김. 회원은 1 만, 어드민은 둘 다 봄.
- `title` 은 NULL 금지. body 만으로 운영하면 footer 한 줄 공지가 돼서 모바일 카드 첫 줄 식별이 어려움 (요구사항 §6 에서 확정).
- 인덱스 `(cohort_id, is_visible, created_at DESC)`: 회원 조회의 핵심 패턴 그대로. 어드민 리스트도 cohort_id 만으로도 활용 가능.
- 별도 `notice_dismissals` 류 테이블 없음 (회원 닫기 미지원).
- 마이그레이션 파일: `migrate_notices.php` (boot 루트 단일 PHP runner 패턴, 멱등 보장: `information_schema.TABLES` 조회 후 없을 때만 CREATE).

## 3. 정책

### 3.1 노출 범위

- 회원: 본인 소속 cohort 의 `is_visible=1` 공지만 노출. `bootcamp_members.cohort_id` 기준.
- 어드민: `resolveAdminCohortId()` 로 결정된 현재 보기 cohort 의 공지를 모두(visible/hidden 무관) 노출.
- '전체 활성 기수 공통 공지' 없음. cohort 마다 따로 작성.

### 3.2 권한

- 등록(`notice_create`), 수정(`notice_update`), 숨김 토글(`notice_toggle_visible`), 삭제(`notice_delete`), 어드민 조회(`notice_list`): `requireAdmin(['coach','head','operation'])`.
  - 단, head/operation 권한 안에 포함되는 sub-role(`sub_coach`, `subhead1`, `subhead2`) 도 허용해야 같은 페이지에 노출된 UI 가 동작한다. 권한 가드는 `requireAdmin(['coach','sub_coach','head','subhead1','subhead2','operation'])` 로 확장.
- leader / subleader 는 공지 관리에서 제외 (현재 [공지] 탭을 그들 페이지에 노출하지 않음).
- 본인 작성 여부와 무관하게 모든 공지 수정·삭제 가능 (요구사항 §2).
- 회원 조회(`bootcamp.php?action=notices`): `requireMember()`.

### 3.3 라이프사이클

- `is_visible` 토글만으로 노출/숨김 관리. 자동 만료 없음.
- 삭제(`notice_delete`)는 hard delete. soft delete 컬럼 안 둠. 숨김(`is_visible=0`)이 사실상의 retention 수단. 정말 지우고 싶을 때만 [삭제].
- 회원은 닫을 수 없음. `is_visible=1` 인 한 매번 노출.

### 3.4 검증 (서버)

- `title`: trim 후 빈 문자열 금지, 255 자 초과 금지. 위반 시 400.
- `body_markdown`: trim 후 빈 문자열 금지. 길이 제한 없음 (TEXT 64KB 자연 한계).
- `is_visible`: 0 또는 1 만 허용.
- `cohort_id`: 어드민 세션의 보기 cohort 와 일치해야 함. mismatch 시 400 (cross-cohort 가드).
- `id` (수정/삭제/토글): 존재 + cohort_id 가 어드민 보기 cohort 와 일치 검증. 다른 cohort 의 공지 건드리려 하면 403/404.

## 4. API

### 4.1 어드민 (api/admin.php)

| action | method | params | response |
|--------|--------|--------|----------|
| `notice_list` | GET | `cohort_id` (선택) | `{ notices: [{id, title, body_markdown, is_visible, created_by_admin_id, created_by_admin_name, created_at, updated_at}] }` — `is_visible DESC, created_at DESC` 정렬 (노출 중인 게 위) |
| `notice_create` | POST | `{title, body_markdown, is_visible(default 1)}` | `{ id }` |
| `notice_update` | POST | `{id, title, body_markdown}` | `{ ok: true }` |
| `notice_toggle_visible` | POST | `{id, is_visible}` | `{ ok: true, is_visible }` |
| `notice_delete` | POST | `{id}` | `{ ok: true }` |

`notice_create` 는 `created_by_admin_id = $admin['admin_id']`, `created_by_admin_name = $admin['admin_name']` 를 세션에서 자동 채움 (클라이언트 입력 무시).

### 4.2 회원 (api/bootcamp.php)

| action | method | params | response |
|--------|--------|--------|----------|
| `notices` | GET | — | `{ notices: [{id, title, body_markdown, created_by_admin_name, created_at}] }` — `created_at DESC`, `is_visible=1` 만 |

회원 응답에서 의도적으로 제외: `is_visible`, `created_by_admin_id`, `updated_at` (회원이 알 필요 없는 운영 메타).

## 5. 서비스 레이어

신규 파일 `public_html/api/services/notice.php` — 단일 도메인 service.

함수 (모두 `PDO`/세션 안 받고 `cohort_id`, `admin_id` 등 명시적 인자로 받음):
- `noticeListAdmin(PDO $db, int $cohortId): array`
- `noticeListMember(PDO $db, int $cohortId): array`
- `noticeCreate(PDO $db, int $cohortId, int $adminId, string $adminName, string $title, string $bodyMarkdown, int $isVisible): int`
- `noticeUpdate(PDO $db, int $cohortId, int $id, string $title, string $bodyMarkdown): void` (cohort 일치 가드 내부)
- `noticeToggleVisible(PDO $db, int $cohortId, int $id, int $isVisible): int` (변경 후 is_visible 반환)
- `noticeDelete(PDO $db, int $cohortId, int $id): void`

검증(§3.4) 은 service 안에서 수행하고 위반은 `InvalidArgumentException` 으로 throw. admin.php case 에서 catch → `jsonError(400)`.

## 6. UI — 어드민

### 6.1 탭 배치

`public_html/js/admin.js` 의 tabs 정의에 신규 탭 `[공지]` 추가, **coach / head / operation 페이지 모두에 노출**. leader/subleader 페이지 분기에는 추가하지 않음.

위치: 기존 [오류 문의(issues)] 탭 옆 (운영성 알림성격이 인접). 정확한 인덱스는 implementation 단계에서 확정.

### 6.2 화면

```
[공지]
┌──────────────────────────────────────────┐
│ [+ 새 공지]                              │
├──────────────────────────────────────────┤
│ [노출중] 5월 18일 휴강 안내               │
│   운영진(jane) · 2026-05-17 14:23        │
│   [수정] [숨기기] [삭제]                  │
├──────────────────────────────────────────┤
│ [숨김] 4월 행사 종료                      │
│   ...                                    │
└──────────────────────────────────────────┘
```

- 정렬: `is_visible DESC, created_at DESC` (노출중이 위, 그 안에서 최신순). 어드민은 숨김도 같은 리스트에서 보고 다시 켤 수 있음.
- chip: `[노출중]` / `[숨김]` 색 다른 배지 (기존 admin.css 배지 클래스 재사용).
- [숨기기]/[보이기]: 토글, 즉시 적용.
- [삭제]: `App.confirm('이 공지를 영구 삭제할까요?')` 후 진행.

### 6.3 작성/수정 모달

기존 `App.openModal` 패턴 (다른 어드민 폼과 동일):
- 제목 input (`required`, maxlength 255)
- 본문 textarea (`required`, `rows="8"`, resize: vertical)
- 본문 우측 또는 하단 [👁 미리보기] 토글 → `marked.parse(text, {breaks:true})` 결과를 옆/아래 영역에 innerHTML 으로 렌더 (task content_markdown 패턴 그대로)
- `[노출 시작]` 체크박스 → `is_visible` (수정 모달에서는 현재 값 prefill, 새 글 기본 체크)
- [저장] / [취소]

### 6.4 신규 JS / CSS

- `public_html/js/admin-notices.js` — `AdminNotices` 모듈 (`admin-issues.js` 패턴: `init / load / render / openForm / handleSubmit / toggleVisible / delete`)
- `public_html/css/admin-notices.css` — 카드 레이아웃, chip 색 (필요 최소만, 가능한 공용 admin.css 재사용)
- coach/head/operation `index.php` 셋 모두에 `<script src="/js/admin-notices.js">` + `<link href="/css/admin-notices.css">` 추가 (`asset_version` helper 사용)

## 7. UI — 회원

### 7.1 위치

`public_html/js/member-home.js` 의 `render(headerEl, member)` 안에서 `member-info-card` 직후 / 커리큘럼(`cur-title` 영역) 직전에 `<div id="member-notices"></div>` 자리 마련. 같은 render 함수 안에서 `MemberNotices.render(document.getElementById('member-notices'), member)` 호출.

### 7.2 화면

```
[프로필 카드]

┌──────────────────────────────────────────┐
│ 5월 18일 휴강 안내                        │
│ ────────                                 │
│ 이번 주 토요일은 강사 일정으로 휴강합니다. │
│ - 다음 줌 일정은 일요일 오전 10시         │
│                                          │
│ 운영진(jane) · 2026-05-17                │
└──────────────────────────────────────────┘
┌──────────────────────────────────────────┐
│ ... 두번째 공지                           │
└──────────────────────────────────────────┘

[오늘의 진도 & 할 일]
```

- 0건이면 `#member-notices` 영역 자체 미렌더 (DOM 비움) → 빈 공간 차지 안 함.
- 카드당: title (h3 굵게) + horizontal rule + `marked.parse(body, {breaks:true})` 결과 innerHTML + footer (`등록자명 · YYYY-MM-DD`, KST date format).
- 카드 사이 간격 12~16px (기존 카드 spacing 일관).
- 회원 [닫기] 버튼 없음.

### 7.3 신규 JS / CSS / 의존성

- `public_html/js/member-notices.js` — `MemberNotices` 모듈 (`render(rootEl, member)` 안에서 `App.get('/api/bootcamp.php?action=notices')` 호출 후 카드 렌더)
- `public_html/css/notices.css` — 회원/어드민 공통 마크다운 카드 스타일 (h1~h3 사이즈, ul/ol 들여쓰기, code, blockquote 등 최소 reset; 또는 회원 전용 `member-notices.css` 로 분리)
- **marked.js 회원 측 신규 로드**: `public_html/index.php` 의 `<script>` 블록에 `<script src="https://cdn.jsdelivr.net/npm/marked@15/marked.min.js"></script>` 1줄 추가 (어드민에서 이미 동일 CDN 사용 중 — version pin 일치). 자산 캐시 변경.

### 7.4 갱신 정책

- 회원이 메인 진입 시 1회 fetch. 폴링/실시간 갱신 없음 (요구사항: 외부 push 없음, 메인 진입 시에만 확인).
- 캐싱: HTTP 캐시 헤더 안 붙임 (기존 bootcamp.php 패턴 그대로 `Cache-Control: no-store`).

## 8. 보안 / 마크다운 렌더

- 입력자가 어드민(coach/head/operation) 한정이라 XSS trust boundary 안에 있음 → 기존 task `content_markdown` 렌더와 동일하게 `marked.parse(text, {breaks:true})` 결과를 그대로 `innerHTML` 박음. DOMPurify 등 sanitizer 의존성 추가하지 않음. (consistency, 의존성 최소화)
- 어드민 화면에서도 같은 렌더러 사용 (어드민이 본인이 입력한 마크다운을 미리보기로 확인).
- 어드민 input 자체는 PDO prepared statement 로 안전. SQL injection 위험 없음.

## 9. 테스트

PHPUnit 단위 + 인보리언트 (boot 기존 `tests/` 패턴):

### 9.1 단위 (`tests/services/NoticeServiceTest.php`)
- create → list → update → toggle hide → toggle show → delete 사이클
- title trim 빈 문자열 / body trim 빈 문자열 → InvalidArgumentException
- title 256자 초과 → InvalidArgumentException
- is_visible 잘못된 값(예: 2) → InvalidArgumentException
- 다른 cohort 의 id 로 update/delete/toggle → InvalidArgumentException (또는 NotFound)
- 회원 list: is_visible=0 row 제외 확인

### 9.2 인보리언트 (`tests/invariants/NoticesInvariantTest.php`)
- INV-N1: 회원 응답에 다른 cohort 공지 row 0건 (cohort 분리)
- INV-N2: 회원 응답에 is_visible=0 row 0건
- INV-N3: 모든 notices row 의 `created_by_admin_id` 가 `admins.id` 에 존재 (FK 무결성)

## 10. 마이그레이션 / 배포

1. DEV: `migrate_notices.php` 실행 → `notices` 테이블 생성 (멱등)
2. DEV: 코드 푸시 (DEV 검증)
3. ⛔ 사용자 DEV 검증 + 운영 반영 명시 요청 대기
4. main 머지 + PROD pull + PROD `migrate_notices.php` 실행

## 11. 영향 범위

- 신규 파일: 마이그 1, 서비스 1, JS 2, CSS 1~2, 테스트 2
- 수정 파일:
  - `public_html/api/admin.php` (5개 case 추가)
  - `public_html/api/bootcamp.php` (1개 case 추가)
  - `public_html/js/admin.js` (3개 role 탭 정의에 [공지] 추가)
  - `public_html/coach/index.php`, `public_html/head/index.php`, `public_html/operation/index.php` (script/link 추가)
  - `public_html/index.php` (marked.js + member-notices.js + notices.css 추가)
  - `public_html/js/member-home.js` (`render` 안에서 `MemberNotices.render()` 호출 1줄)
- 회원/leader/subleader 화면 외 기존 기능 무영향
- 어드민 [오류 문의] 등 기존 탭 무영향

## 12. 미해결 / 향후

- 별도 push 채널 필요 시 알림톡 어댑터 연동 (이번 spec 비목표)
- 회원별 dismiss/read state 필요 시 `notice_reads(notice_id, member_id, read_at)` 테이블 추가
- 자동 종료 일정이 필요해지면 `published_at`, `expires_at` 추가 후 회원/어드민 조회 조건에 NOW() 비교 가드
