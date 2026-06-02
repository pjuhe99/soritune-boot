# BRAVO 5차 슬라이스 — 시험-문제 배정 (수동 출제 구성) 설계 스펙

- **작성일:** 2026-06-02
- **대상:** boot.soritune.com (dev 브랜치, DB SORITUNECOM_DEV_BOOT)
- **선행:** BRAVO 1차(기반/자격)·2차(시험 관리)·3차(회원 상태)·4차(문제은행+OT) — origin/dev HEAD `bc65486`
- **참조 문서:** `/root/260529_bootcamp_bravo_test.docx` (기능정의서 V4) §12(등급별 추천 문항 구성), §15-4·15-5

## 1. 목적과 범위

관리자가 **각 시험에 문제은행의 문제를 수동 선택해 배정**한다. 시험은 고정 문항 세트(응시자 전원 동일)를 갖는다. 실제 응시 흐름의 직전 선행 데이터를 만든다.

### 범위 (이번 슬라이스)
- 시험 ↔ 문제 N:M 배정 (수동 선택, 벌크 set 방식).
- 배정 패널: 후보 문제 체크박스 + 전체보기 토글 + 출제 구성 요약(추천 대비, 비차단).

### 비범위 (다음 슬라이스로 명시 연기)
- 자동출제(유형별 문항수 기반 랜덤 추출).
- 회원 응시·OT 노출·마이크테스트·녹음·STT·채점·인증서.
- §15-6 출제/제출 방식(음성/텍스트/선택형) 커스터마이징 매트릭스.
- 문제 순서 드래그 재정렬(배정 순서 = 저장 시 제출 리스트 인덱스로 충분).
- 전체 BRAVO UI 스타일링(slice1~5 신규 클래스 CSS 미작성 누적 — 별도 패스).

### 불변식
- **순수 추가형.** 기존 `member_history_stats.bravo_grade`·회원 마이페이지·이전 BRAVO 경로 무수정.

## 2. 데이터 모델

신규 마이그레이션 `migrate_bravo_exam_questions.php` (멱등 `CREATE TABLE IF NOT EXISTS`, `require_once config.php`). DEV DB 먼저 적용.

### `bravo_exam_questions` (시험 ↔ 문제 N:M)

| 컬럼 | 타입 | 제약/기본 | 설명 |
|---|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT | PK | |
| `exam_id` | INT UNSIGNED | NOT NULL | bravo_exams.id |
| `question_id` | INT UNSIGNED | NOT NULL | bravo_questions.id |
| `display_order` | SMALLINT UNSIGNED | NOT NULL DEFAULT 0 | 제시 순서(= 저장 시 제출 리스트 인덱스) |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP | |

- 인덱스: `UNIQUE KEY uk_beq_exam_question (exam_id, question_id)`, `KEY idx_beq_exam (exam_id)`, `KEY idx_beq_question (question_id)`.
- FK는 기존 BRAVO 컨벤션대로 DB 미선언(앱 레벨 정합). 정리는 §4 cascade로 보장.

## 3. 서비스 / API

### 3-1. 신규 파일 `public_html/api/services/bravo_exam_questions.php`

exam↔question 배정이라는 경계 도메인으로 분리한다(bravo.php·bravo_questions.php 비대화 방지).

- `bravoExamQuestionAssignedIds(PDO $db, int $examId): array` — 배정된 question_id 정수 배열(`ORDER BY display_order, id`).
- `bravoExamQuestionList(PDO $db, int $examId): array` — 배정된 문제 행(bravo_questions 조인, `ORDER BY beq.display_order, beq.id`). 응시/검토용 전체 컬럼.
- `bravoExamQuestionSet(PDO $db, int $examId, array $questionIds): void` — **트랜잭션 전체 교체**.
  1. `$questionIds`를 정수화·중복제거.
  2. `bravo_questions`에 실제 존재하는 id만 필터(미존재 id 무시 — 무결성).
  3. `DELETE FROM bravo_exam_questions WHERE exam_id = ?`.
  4. 남은 id를 입력 순서대로 `display_order = 0,1,2,...`로 INSERT.
  - 호출부가 이미 트랜잭션 안이면 중첩 방지: 함수는 `inTransaction()` 가드로 자체 begin/commit(이미 진행 중이면 begin/commit 생략하고 그대로 수행). (테스트가 외부 트랜잭션으로 감싸므로 필수.)

### 3-2. 삭제 정합 (추가형 보강)

- `bravoExamDelete`(bravo.php): 시험 삭제 시 `bravo_exam_questions WHERE exam_id=?` 도 삭제(OT 삭제 줄 옆에 한 줄 추가).
- `bravoQuestionDelete`(bravo_questions.php): 문제 삭제 시 `bravo_exam_questions WHERE question_id=?` 도 삭제 → 모든 시험 배정에서 자동 제거.

### 3-3. `admin.php` 신규 case (모두 `requireAdmin(['operation'])`)

| action | 메서드 | 처리 |
|---|---|---|
| `bravo_exam_question_list` | GET | `exam_id`(필수, ≥1) + 선택 `show_all`. 반환 `jsonSuccess(['exam'=>{id,title,bravo_level}, 'assigned_ids'=>[...], 'candidates'=>[...]])`. candidates = `show_all=1`이면 **등급·활성 무관 전체**, 아니면 `bravo_level` 일치 + `is_active=1`. |
| `bravo_exam_question_save` | POST | `{exam_id, question_ids:[...]}` → `bravoExamQuestionSet`. exam_id<1 이면 jsonError. |

- candidates 조회는 `bravoQuestionList($db, $filters)`(slice4) 재사용: `show_all=1`이면 필터 없음(등급·활성 무관 전체 — 예외 배정 허용), 아니면 `['bravo_level'=>$examLevel,'is_active'=>1]`.
- 단, `show_all`이 아니어도 이미 배정된 문제가 후보 필터 밖(예: 비활성화됨/등급 다름)일 수 있으므로, 프론트는 `assigned_ids` 기준으로 체크 상태를 그린다(후보에 없으면 표시만 누락될 수 있음 — 안전하게는 candidates에 배정분 합집합 포함 권장). **구현: candidates 에 "필터 결과 ∪ 현재 배정 문제" 를 합쳐 반환**(배정된 문제가 항상 패널에 보이도록).
- `jsonSuccess` 평탄화 — JS는 `r.exam`/`r.assigned_ids`/`r.candidates` 직접 접근.

## 4. 프론트엔드 (`public_html/js/admin-bravo-exams.js` 확장)

기존 OT 편집(slice4)과 동일 패턴으로 per-exam 배정 패널을 추가한다.

- 각 시험 행에 **"문제" 버튼**(`bravo-exam-eq`, OT 버튼 옆) → `openExamQuestions(examId)`.
- `openExamQuestions`: `bravo_exam_question_list` GET → `#bravo-exam-form` host(기존 재사용, `editingId=null` 가드) 에 배정 패널 렌더:
  - **후보 목록**: 각 문제를 체크박스 행으로(`유형 N · BRAVO L · 한국어요약 · 난이도`). `assigned_ids`에 있으면 체크. 모든 DB 파생 텍스트는 `App.esc`.
  - **전체보기 토글**: 체크 시 `show_all=1`로 재요청(등급일치+활성 ↔ 전체). 재요청 시 현재 체크 상태는 서버 assigned 기준으로 다시 그림(미저장 변경은 유실 — 토글은 저장 전 후보 범위 전환용, 안내 문구 표기).
  - **출제 구성 요약**: 현재 체크된 항목의 유형별 개수 vs 추천 구성. 추천은 JS const `RECOMMENDED`(§12): `{1:{1:15,2:5,3:0}, 2:{1:8,2:7,3:5}, 3:{1:8,2:7,3:5}}`. 예: `유형1 3/15 · 유형2 0/5 · 유형3 0/0 (총 3)`. 비차단(불일치여도 저장 가능).
  - **저장**: 체크된 question_id 배열 → `bravo_exam_question_save` POST → toast → 패널 유지 또는 닫기.
- 체크 토글 시 구성 요약 즉시 갱신(이벤트 위임 또는 각 체크박스 listener).

> 신규 클래스(.bravo-eq-*, .eq-summary 등) CSS 미작성 — slice1~4와 동일하게 bare. 기능 우선.

## 5. 테스트 (기존 `tests/bravo_*.php` 패턴, DEV DB 트랜잭션 롤백)

- `tests/bravo_exam_questions_schema_invariants.php`: 테이블 존재 가드 + 컬럼 + `uk_beq_exam_question` UNIQUE 확인.
- `tests/bravo_exam_question_test.php`:
  - 통합: 시험+문제 3개 생성 → `bravoExamQuestionSet([q1,q3])` → `bravoExamQuestionList` 순서/내용 확인 → 재-set `[q2,q1,q3]` 멱등(중복 없음, display_order 갱신) → 미존재 id 포함 set 시 필터 → `bravoExamQuestionAssignedIds` 일치.
  - cascade: `bravoExamDelete` 후 junction 0행, `bravoQuestionDelete`(다른 시험에 배정된 문제) 후 해당 question_id junction 제거.
  - ⚠️ `bravoExamQuestionSet`의 inTransaction 가드: 테스트가 외부 `beginTransaction`으로 감싸므로, 함수 내부에서 begin/commit을 중복 호출하지 않아야 한다(PDO 중첩 트랜잭션 예외 방지). 이미 진행 중이면 statement만 실행.
- 전체 BRAVO 테스트(슬라이스1~4 + 본 슬라이스) 회귀 0 확인.

## 6. 배포

- **dev 에만 반영.** 운영(main)은 BRAVO 전체 완성 시 1회 일괄 — 사용자 명시 요청 시에만 (프로젝트 배포 전략).
- DEV DB 마이그(`php migrate_bravo_exam_questions.php`)는 DEV 자격증명으로 먼저 적용.

## 7. 관련 메모/패턴
- jsonSuccess 평탄화: JS는 `r.<key>` 직접.
- 마이그/CLI는 `require_once`(silent fatal 방지).
- `bravoExamQuestionSet` 트랜잭션: caller-관리 패턴 존중 — slice4에서 `bravoExamDelete` 내부 begin이 테스트의 외부 트랜잭션과 충돌(PDO 중첩 미지원)했던 교훈 반영 → `inTransaction()` 가드 필수.
- ENUM/VARCHAR 컨벤션, 회원식별·자격은 기존 순수함수 재사용(이번 변경 없음).
