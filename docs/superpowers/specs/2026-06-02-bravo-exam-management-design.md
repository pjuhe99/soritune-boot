# 소리블록 BRAVO 도전 시스템 — 2차 슬라이스 (관리자 시험 관리) 설계

작성일: 2026-06-02
원본 기능정의서: `/root/260529_bootcamp_bravo_test.docx` (기능정의서 V4)
선행 슬라이스: `2026-06-02-bravo-test-foundation-design.md` (기반 + 관리자 자격 골격, 완료·dev 반영)

## 배경 / 목적

BRAVO 도전 시스템의 두 번째 슬라이스. 관리자가 **BRAVO 시험(도전)을 생성·수정하고
운영 기간/상태를 관리**할 수 있게 한다. 실제 응시·문제은행·채점·인증서는 이후 슬라이스.

## 확정된 선행 결정 (전체 프로젝트)

- **배포 전략: 전 슬라이스를 dev 에서 끝까지 개발 → BRAVO 시스템 완성 시점에 운영 1회 일괄 반영.**
  슬라이스마다 운영 배포하지 않는다.
- 로그인은 boot 재사용. 신규 BRAVO 시스템은 기존 `member_history_stats.bravo_grade` 와 무관한 추가형.

## 이 슬라이스의 범위 결정 (2026-06-02 브레인스토밍)

1. **코어 시험 CRUD만** (doc 15-4 필드). doc 15-6 의 거대한 출제/제시/시간/녹음/채점/결과공개
   커스터마이징 매트릭스와 OT(글/영상)는 이후 슬라이스로 연기. (실제 응시 흐름이 없어 그 설정을
   소비할 곳이 아직 없음 → YAGNI)
2. **응시 대상은 전체 + 특정 기수**만 (컬럼). 개별 회원 타겟(join 테이블)은 연기.
3. **시험 상태는 관리자 수동 설정.** 응시 시작/종료/발표 날짜는 컬럼으로 저장하되(향후 cron
   자동전환용), 이번 슬라이스의 상태 전환은 수동.

## 기존 boot 아키텍처 (참고)

- PHP+PDO. `api/admin.php` 단일 `switch($action)` 모놀리스, `requireAdmin(['operation'])` 게이팅,
  `jsonSuccess`/`jsonError`/`getJsonInput`/`getMethod`. `getEffectiveCohort($admin)` = 현재 기수 라벨.
- 관리자 SPA `operation/index.php` → JS IIFE 모듈 + 탭(MutationObserver lazy-load) → `AdminApp.init()`.
- 슬라이스1 산출물: `api/services/bravo.php`(자격 순수함수 + bravoMemberList/Upsert),
  admin.php case `bravo_member_list`/`bravo_member_update`, `js/admin-bravo.js`(AdminBravoApp),
  operation 탭 `tab-bravo`("BRAVO 자격"). 테이블 `bravo_levels`/`bravo_member_settings`.

## 데이터모델 — 신규 테이블 `bravo_exams` (추가형)

| 컬럼 | 타입 | 설명 |
|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT PK | |
| `title` | VARCHAR(120) NOT NULL | 시험명 |
| `bravo_level` | TINYINT UNSIGNED NOT NULL | 1/2/3 (개념상 bravo_levels.level 참조) |
| `exam_mode` | ENUM('period','always') NOT NULL DEFAULT 'period' | 기간제/상시 |
| `start_at` | DATETIME DEFAULT NULL | 응시 시작 (period면 필수, 상시면 NULL) |
| `end_at` | DATETIME DEFAULT NULL | 응시 종료 (period면 필수) |
| `result_release_at` | DATETIME DEFAULT NULL | 결과 발표일 (period면 필수, ≥ end_at) |
| `attempt_limit` | TINYINT UNSIGNED NOT NULL DEFAULT 3 | 응시 횟수 |
| `target_type` | ENUM('all','cohort') NOT NULL DEFAULT 'all' | 대상 |
| `target_cohort_id` | INT UNSIGNED DEFAULT NULL | cohort일 때 cohorts.id, all이면 NULL |
| `status` | ENUM('preparing','open','closed','released') NOT NULL DEFAULT 'preparing' | 준비중/오픈/종료/결과발표 (수동) |
| `created_by` | INT UNSIGNED DEFAULT NULL | 생성 admin id |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | |

- 인덱스: `KEY idx_bravo_exams_status (status)`, `KEY idx_bravo_exams_cohort (target_cohort_id)`.
- 엔진/charset: InnoDB, utf8mb4_unicode_ci.
- `target_cohort_id` 는 라벨 변경에 강건하도록 cohorts.id 로 저장. 표시는 cohorts 조인으로 라벨 해석.
- 기존 테이블 일절 미수정 (추가형).

## 서비스 (`api/services/bravo.php` 확장)

- `bravoValidateExam(array $data): array` — 순수 검증 함수. 에러 메시지 배열 반환(빈 배열 = 통과). 규칙:
  - `title` 비어있지 않음
  - `bravo_level` ∈ {1,2,3}
  - `exam_mode` ∈ {period, always}
  - period 인 경우: `start_at`/`end_at`/`result_release_at` 필수, `start_at ≤ end_at`, `result_release_at ≥ end_at`
  - always 인 경우: 날짜 무시(NULL 허용)
  - `attempt_limit` 정수 ≥ 1
  - `target_type` ∈ {all, cohort}; cohort 면 `target_cohort_id` 필수(양의 정수)
  - `status` ∈ {preparing, open, closed, released}
  - (날짜 형식은 `DateTime` 파싱 가능 여부로 검증)
- `bravoExamList(PDO $db, array $filters = []): array` — 시험 목록. `bravo_level` → level명, `target_cohort_id`
  → cohort 라벨 LEFT JOIN. 선택 필터: status / bravo_level / target_cohort_id. 최신순(`ORDER BY id DESC`).
- `bravoExamCreate(PDO $db, array $data, int $adminId): int` — INSERT, lastInsertId 반환. always 모드면 날짜 NULL 저장.
- `bravoExamUpdate(PDO $db, int $id, array $data): void` — UPDATE (status 포함 전체 필드).
- `bravoExamDelete(PDO $db, int $id): void` — 하드 삭제 (이번 슬라이스엔 참조 테이블 없음).

검증·저장 시 always 모드는 날짜 컬럼 NULL 로 정규화한다(폼에서 값이 와도 무시).

## API (admin.php 얇은 case, `requireAdmin(['operation'])`)

- `bravo_exam_list` (GET) → `jsonSuccess(['exams' => bravoExamList(...), 'levels' => bravoLoadLevels($db), 'cohorts' => <기수 목록>])`.
  기수 목록은 기존 `cohort_list` 가 쓰는 소스 재사용(드롭다운용; id+label).
- `bravo_exam_save` (POST) — `id` 있으면 update, 없으면 create. `bravoValidateExam` 실패 시 첫 에러로 `jsonError`.
  성공 시 `jsonSuccess([], '저장되었습니다.')` (또는 신규 id 포함).
- `bravo_exam_delete` (POST) — `id` 필수, 삭제 후 `jsonSuccess([], '삭제되었습니다.')`.

입력 방어: user_id 슬라이스에서와 동일하게 비-스칼라 입력 가드(문자열/숫자 타입 확인). 날짜는 문자열로 받아 검증.

## 프론트 — BRAVO 영역 서브탭화

operation 탭바 혼잡을 피하고 BRAVO 영역을 향후 슬라이스(문제은행/채점)까지 흡수하도록, 단일 BRAVO
영역에 서브탭을 도입한다 (멀티패스 서브탭 패턴 차용).

- 슬라이스1 탭 라벨 `BRAVO 자격` → **`BRAVO`** 로 변경. `tab-bravo` 컨테이너 안에 서브탭 바 2개:
  **회원 자격** / **시험 관리**.
- 셸 책임: `js/admin-bravo.js` 의 `AdminBravoApp.init` 이 서브탭 바를 그리고, 선택된 서브탭 영역에
  자격 뷰(기존 render 로직) 또는 시험 뷰(신규 모듈)를 마운트. 기존 자격 로직은 보존하고 서브탭 아래로 이동.
- 신규 모듈 `js/admin-bravo-exams.js` (`AdminBravoExamApp`): 시험 목록 테이블(시험명/등급/기간 or 상시/대상/상태/동작) +
  생성·수정 폼(인라인 또는 간단 폼 영역) + 상태 변경 드롭다운(저장 시 bravo_exam_save) + 삭제 버튼.
  `App.get`/`App.post`/`App.esc`, `Toast.success`/`Toast.error` 사용(슬라이스1과 동일 실제 API).
- `operation/index.php` 에 `admin-bravo-exams.js` include 추가(v() 캐시버스터), `admin-bravo.js`/`AdminApp.init()` 보다 앞.
- exam_mode='always' 선택 시 폼의 날짜 입력 비활성/무시.

## 마이그레이션 / 가드

- `migrate_bravo_exams.php`: `require_once __DIR__ . '/public_html/config.php'`, 멱등 `CREATE TABLE IF NOT EXISTS bravo_exams`.
  DEV DB 먼저. (운영 반영은 BRAVO 전체 완성 시 일괄)

## 테스트 / 검증

- `bravoValidateExam` 순수 함수 단위 테스트: period 누락 날짜/역전 날짜/release<end, always 날짜무시,
  attempt_limit<1, level/mode/target/status 범위, cohort 타겟 cohort_id 누락 등 경계.
- 통합 테스트(DEV DB txn rollback): create→list(라벨/level명 조인 확인)→update(status 포함)→delete.
  always 모드 날짜 NULL 정규화 확인. target cohort 라벨 해석 확인.
- 프론트 node --check + DEV HTTP 스모크(서브탭 전환, 시험 생성/수정/상태변경/삭제, 자격 서브탭 회귀).
- 기존 `bravo_member_list`/자격 뷰 회귀 없음(서브탭 이동 후에도 동작).

## 이번 슬라이스에서 명시적으로 제외 (다음 슬라이스)

OT(글/영상) · 15-6 출제/제시/시간/녹음/채점/결과공개 커스터마이징 · 합격기준 override · 개별 회원 타겟 ·
날짜기반 자동 상태전환(cron) · 문제은행 · 자동출제 · 실제 응시/녹음/채점/검수/인증서 · 회원 마이페이지 표시 ·
신규 RBAC 롤(평가관리자/읽기전용).
