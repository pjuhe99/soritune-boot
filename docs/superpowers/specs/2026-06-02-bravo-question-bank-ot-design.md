# BRAVO 4차 슬라이스 — 문제은행 + OT 관리 (설계 스펙)

- **작성일:** 2026-06-02
- **대상:** boot.soritune.com (dev 브랜치, DEV_BOOT)
- **선행:** BRAVO 1차(기반/자격)·2차(시험 관리)·3차(회원 상태) — origin/dev HEAD `82da6b1`
- **참조 문서:** `/root/260529_bootcamp_bravo_test.docx` (기능정의서 V4) §9, §14-1, §15-5

## 1. 목적과 범위

관리자가 **문제은행에 문제를 등록·관리**하고 **각 시험에 OT(오리엔테이션) 콘텐츠를 작성**할 수 있게 한다. 실제 회원 응시·OT 노출·자동출제·채점의 **전제 데이터를 쌓는 관리자 콘텐츠 작성 슬라이스**다.

### 범위 (이번 슬라이스)
- 문제은행 단건 CRUD + 목록/필터 (관리자).
- 시험별 OT 콘텐츠 작성 (시험 1 : OT 1).

### 비범위 (다음 슬라이스로 명시 연기)
- 시험-문제 배정 / 자동출제 (문제는 standalone bank로만 보관).
- 회원 OT 노출·마이크 테스트·필수 확인 체크 흐름, 실제 응시·녹음·채점·인증서.
- 문제은행 CSV 일괄 입력 (단건 폼만).
- §15-6 출제/채점/결과공개 커스터마이징 매트릭스.
- 전체 BRAVO UI 스타일링 (slice1~4 신규 클래스 CSS 미작성 누적 — 별도 패스).

### 불변식
- **순수 추가형.** 기존 `member_history_stats.bravo_grade`·기존 회원/시험/자격 경로 무수정. 회원 마이페이지 경로 무변경.

## 2. 데이터 모델

신규 마이그레이션 `migrate_bravo_questions.php` (멱등 `CREATE TABLE IF NOT EXISTS`, `require_once config.php`). DEV DB 먼저 적용.

### 2-1. `bravo_questions` (문제은행 — §15-5 + §14-1)

| 컬럼 | 타입 | 제약/기본 | 설명 |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | PK | 문제 ID (자동) |
| `question_type` | TINYINT UNSIGNED | NOT NULL | 유형 1/2/3 |
| `bravo_level` | TINYINT UNSIGNED | NOT NULL | BRAVO 등급 1/2/3 |
| `source` | VARCHAR(60) | NULL | 출제 원천 (자유 텍스트, datalist 3제안) |
| `korean_text` | TEXT | NOT NULL | 한국어 제시 문장 |
| `english_text` | TEXT | NOT NULL | 기준 영어 정답 문장 |
| `target_chunks` | VARCHAR(255) | NULL | 타겟 청크 (핵심 소리블록) |
| `accepted_answers` | TEXT | NULL | 허용 정답 (1줄 1개) |
| `reference_speech_sec` | DECIMAL(4,1) | NULL | 기준 발화 시간(초) |
| `response_time_limit_sec` | DECIMAL(4,1) | NULL | 반응 속도 기준(초) |
| `difficulty` | ENUM('easy','normal','hard') | NOT NULL DEFAULT 'normal' | 쉬움/보통/어려움 |
| `is_active` | TINYINT(1) | NOT NULL DEFAULT 1 | 활성/비활성 |
| `created_by` | INT UNSIGNED | NULL | 생성 admin id |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP ON UPDATE | |

인덱스: `KEY idx_bq_type_level (question_type, bravo_level)`, `KEY idx_bq_active (is_active)`.

- **source 자유 텍스트 근거:** 출제 원천은 진화 가능(VOD/줌특강 PPT/훈련영상 외 추가 가능) → VARCHAR + UI datalist 제안값(`소리블록 훈련VOD문장`/`줌특강 PPT`/`부트캠프 훈련영상`).
- **difficulty ENUM 근거:** 고정 소집합 → 기존 컨벤션(`exam_mode`/`status`)과 동일하게 ENUM.
- **accepted_answers:** MVP는 줄바꿈 구분 텍스트. 파싱은 다음 슬라이스(자동평가)에서.
- **문제-시험 미연결:** 이번엔 배정 컬럼/조인테이블 없음. 자동출제 슬라이스에서 추가.

### 2-2. `bravo_exam_ot` (시험별 OT 1:1 — §9-2)

| 컬럼 | 타입 | 제약/기본 | 설명 |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | PK | |
| `exam_id` | INT UNSIGNED | NOT NULL, **UNIQUE** | bravo_exams.id (1:1) |
| `title` | VARCHAR(120) | NULL | OT 제목 |
| `intro_text` | TEXT | NULL | 전체 시험 안내문 |
| `video_url` | VARCHAR(500) | NULL | OT 영상 URL (선택, 2차 영상은 URL만) |
| `type1_text` | TEXT | NULL | 유형 1 안내문 |
| `type2_text` | TEXT | NULL | 유형 2 안내문 |
| `type3_text` | TEXT | NULL | 유형 3 안내문 |
| `require_check` | TINYINT(1) | NOT NULL DEFAULT 1 | 필수 확인 체크 ON/OFF |
| `updated_by` | INT UNSIGNED | NULL | 마지막 수정 admin id |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP ON UPDATE | |

- `exam_id` UNIQUE 로 1:1 보장. upsert(`INSERT ... ON DUPLICATE KEY UPDATE`).
- FK는 기존 BRAVO 테이블 컨벤션(앱 레벨 정합, DB FK 미선언)을 따른다. 시험 삭제 시 OT 정리는 `bravoExamDelete` 에서 OT 행도 함께 삭제하도록 보강.

## 3. 서비스 / API

### 3-1. 신규 파일 `public_html/api/services/bravo_questions.php`

bravo.php(365줄)가 이미 자격·시험·회원상태를 모두 담고 있어, 문제은행은 독립 도메인으로 분리한다.

- `bravoQuestionValidate(array $d): array` — 에러 메시지 배열(빈=통과). 순수.
  - `question_type` ∈ {1,2,3}, `bravo_level` ∈ {1,2,3}, `korean_text`·`english_text` 필수, `difficulty` ∈ {easy,normal,hard}.
  - `reference_speech_sec`/`response_time_limit_sec` 는 비어있거나 ≥0 숫자.
- `bravoQuestionPersistData(array $d): array` — 폼 입력 → 정규화 컬럼 배열(trim, 빈→NULL, 숫자 캐스팅, difficulty 기본 'normal', is_active 0/1). 순수.
- `bravoQuestionList(PDO $db, array $filters = []): array` — 필터: `question_type`/`bravo_level`/`difficulty`/`is_active`/`keyword`(korean/english LIKE). `ORDER BY id DESC`.
- `bravoQuestionCreate(PDO $db, array $d, int $adminId): int` — INSERT, 신규 id 반환.
- `bravoQuestionUpdate(PDO $db, int $id, array $d): void`.
- `bravoQuestionDelete(PDO $db, int $id): void` — 하드 삭제.

### 3-2. OT 함수 (`public_html/api/services/bravo.php` 에 추가 — 시험 강결합)

- `bravoOtValidate(array $d): array` — `exam_id` ≥ 1 필수. 나머지 필드는 모두 선택(빈 허용). 순수.
- `bravoOtPersistData(array $d): array` — trim, 빈→NULL, `require_check` 0/1. 순수.
- `bravoOtGet(PDO $db, int $examId): ?array` — exam_id 로 OT 1행(없으면 null).
- `bravoOtUpsert(PDO $db, int $examId, array $d, int $adminId): void` — `INSERT ... ON DUPLICATE KEY UPDATE`.
- `bravoExamDelete` 보강: 시험 삭제 시 `bravo_exam_ot` 동일 exam_id 행도 삭제.

### 3-3. `admin.php` 신규 case (모두 `requireAdmin(['operation'])`)

| action | 메서드 | 처리 |
|---|---|---|
| `bravo_question_list` | GET | 필터 파싱 → `bravoQuestionList` → `jsonSuccess(['questions'=>...])` |
| `bravo_question_save` | POST | `bravoQuestionValidate` → 에러 시 `jsonError`; id 있으면 Update, 없으면 Create |
| `bravo_question_delete` | POST | id → `bravoQuestionDelete` |
| `bravo_ot_get` | GET | exam_id → `bravoOtGet` → `jsonSuccess(['ot'=>...])` |
| `bravo_ot_save` | POST | `bravoOtValidate` → 에러 시 `jsonError`; `bravoOtUpsert` |

- `admin.php` 상단에 `require_once __DIR__ . '/services/bravo_questions.php';` 추가.
- 응답은 기존 `jsonSuccess` 평탄화 컨벤션 — JS는 `r.questions`/`r.ot` 직접 접근.

## 4. 프론트엔드 (vanilla JS SPA)

기존 BRAVO 서브탭 셸(`admin-bravo.js`: 회원 자격 / 시험 관리)을 확장한다.

### 4-1. 문제은행 — 신규 서브탭
- `admin-bravo.js` 셸에 서브탭 버튼 `문제은행` 추가(`data-sub="questions"`), 마운트 시 `AdminBravoQuestionApp.init(admin, 'bravo-sub-questions')`.
- 신규 `public_html/js/admin-bravo-questions.js` (`AdminBravoQuestionApp`):
  - 필터 바(유형/등급/난이도/활성/검색어) + 목록 테이블(유형·등급·한국어요약·난이도·활성·수정/삭제).
  - 단건 폼(신규/수정): 유형·등급·출제원천(datalist)·한국어·영어·타겟청크·허용정답(textarea)·기준발화초·반응속도초·난이도·활성.
  - 저장/삭제 후 목록 reload. `App.get`/`App.post`/`App.esc`/`Toast` 기존 헬퍼 사용.

### 4-2. OT — 시험 관리 서브탭 내 per-exam 편집
- `public_html/js/admin-bravo-exams.js` 확장: 각 시험 행에 `OT` 버튼 추가 → 클릭 시 `bravo_ot_get` 로드 후 OT 편집 폼(제목/안내문/영상URL/유형1·2·3 안내문/필수확인 체크) 표시, `bravo_ot_save` 로 upsert.
- "시험별 OT(1:1)" 결정에 자연스러운 배치(별도 탭 불필요).

### 4-3. 셸/include
- `public_html/operation/index.php`: `admin-bravo-questions.js` script include 추가 + 모든 BRAVO JS `?v=` cache-buster 갱신(동반 갱신 규칙).
- admin.js 탭 라벨·라우팅은 기존 `BRAVO` 탭 그대로(서브탭만 1개 증가).

> 신규 UI 클래스 CSS는 이번에도 미작성(기능 우선) — slice1~3와 동일. 누적 스타일링은 별도 패스.

## 5. 테스트 (기존 `tests/bravo_*.php` 패턴)

- `tests/bravo_question_test.php`:
  - 순수: validate(필수/범위/숫자 경계), persistData(trim/빈→NULL/difficulty 기본/is_active).
  - 통합(DEV DB): Create→List(필터: type/level/difficulty/active/keyword)→Update→Delete 라운드트립.
- `tests/bravo_ot_test.php`:
  - 순수: validate(exam_id 필수), persistData(require_check 0/1, 빈→NULL).
  - 통합: upsert 신규→get→upsert 갱신(중복 안 생김, UNIQUE)→exam 삭제 시 OT 동반 삭제.
- 기존 97개 테스트 회귀 없음(자격·시험·회원상태·기존 member_list/bravo_grade 경로 무변경) 확인.

## 6. 배포

- **dev 에만 반영.** 운영(main)은 BRAVO 전체 완성 시 1회 일괄 — 사용자 명시 요청 시에만 (프로젝트 배포 전략).
- DEV DB 마이그(`php migrate_bravo_questions.php`)는 DEV 자격증명으로 먼저 적용.

## 7. 관련 메모/패턴
- jsonSuccess 평탄화: JS는 `r.<key>` 직접(`r.data.data` 아님).
- 마이그/CLI는 `require_once`(silent fatal 방지).
- ENUM=고정소집합 / VARCHAR=진화가능 (기존 bravo_exams 컨벤션).
- 회원식별·자격 계산은 slice1 순수함수 재사용(이번 슬라이스 변경 없음).
