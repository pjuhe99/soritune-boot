# BRAVO 7차 슬라이스 — 관리자 채점 (수동채점·확정) 설계 스펙

날짜: 2026-06-04
상태: 설계 승인됨 (사용자 Q&A 2건 + 접근법 A + 섹션별 승인 완료)
참조: 기능정의서 `/root/260529_bootcamp_bravo_test.docx` §11(평가요소·배점), §12(합격기준), §14, §16(검수)
선행: slice1~6 — origin/dev `7ab38d5`. slice6의 `bravo_attempts`(question_ids 스냅샷, status in_progress/submitted)/`bravo_answers`(audio_path/audio_mime/duration_ms/retake_used)/`bravo_uploads/` 위에서 동작.

## 1. 목표와 범위

관리자가 제출된(submitted) BRAVO 응시의 녹음을 들으며 문항별 간단 판정을 입력하면 시스템이 등급별 배점표로 자동 환산하고, 합불 자동 판정(+오버라이드)을 거쳐 채점을 확정한다.

**포함:** 채점 대상 시험/응시 목록, 오디오 스트리밍 재생, 문항별 판정(즉시 저장)→자동 환산, 실시간 총점, 합불 자동 판정+관리자 오버라이드(사유 필수), 채점 메모(문항/전체), 확정/확정 취소(released 전).

**제외 (다음 슬라이스/2차):** 회원 결과 공개·불합격 재응시 해제·인증서(발표 슬라이스), STT·자동평가·재채점 버튼(2차), 응시 횟수 복구·오류 신고(2차), 채점 전용 RBAC 역할 분리(operation 사용).

**순수 추가형:** 기존 테이블 ALTER 없음. 회원 대면 경로 무수정(확정돼도 회원에겐 기존 "제출완료 — 결과 발표 대기" 그대로). `bravoExamDelete` cascade에 grades 2테이블 추가만.

## 2. 확정된 결정

| # | 결정 | 내용 |
|---|------|------|
| 1 | **문항별 간단 판정 + 자동 환산** | 판정 버튼(정답도 3단/청크 2단/반응 3단/유창 3단/완성 2단(B2·B3만)) 클릭 → 등급별 배점표로 환산. 직접 점수 입력 없음. |
| 2 | **범위 = 채점 확정까지** | 회원 공개는 발표 슬라이스. 발표 전 회원에겐 아무것도 안 보임. |
| 3 | **접근법 A** | 신규 테이블 2개(answer 판정/attempt 확정), 기존 테이블 무수정. |

## 3. 데이터 모델 (신규 2테이블, 마이그 `migrate_bravo_grades.php` 멱등)

### 3-1. `bravo_answer_grades` — 답안별 판정 (판정 완성 시 upsert)

```sql
CREATE TABLE IF NOT EXISTS bravo_answer_grades (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    answer_id       INT UNSIGNED NOT NULL COMMENT 'bravo_answers.id',
    attempt_id      INT UNSIGNED NOT NULL COMMENT '비정규화 — 목록 집계용',
    accuracy        ENUM('correct','partial','wrong') NOT NULL COMMENT '정답도 (1/0.5/0)',
    chunk_ok        TINYINT(1) NOT NULL COMMENT '핵심청크 포함 (1/0)',
    response_rating ENUM('good','normal','poor') NOT NULL COMMENT '반응속도 (1/0.5/0)',
    fluency_rating  ENUM('good','normal','poor') NOT NULL COMMENT '유창성 (1/0.5/0)',
    completion_ok   TINYINT(1) NULL COMMENT '발화완성도 — B2/B3만, B1은 NULL',
    score           DECIMAL(5,2) NOT NULL COMMENT '판정 시점 환산 점수 스냅샷',
    n_denominator   SMALLINT UNSIGNED NOT NULL COMMENT '환산에 사용한 분모 N (확정 시 일관성 검증/재환산용)',
    memo            VARCHAR(255) NULL COMMENT '문항 메모',
    graded_by       INT UNSIGNED NOT NULL,
    graded_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bag_answer (answer_id),
    KEY idx_bag_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### 3-2. `bravo_attempt_grades` — 응시별 확정 (행 존재 = 확정)

```sql
CREATE TABLE IF NOT EXISTS bravo_attempt_grades (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id        INT UNSIGNED NOT NULL COMMENT 'bravo_attempts.id',
    total_score       DECIMAL(5,2) NOT NULL COMMENT '확정 시점 합산 스냅샷',
    passing_score     DECIMAL(5,2) NOT NULL COMMENT '확정 시점 합격선 스냅샷 (bravo_levels 변경과 무관하게 판정 근거 보존)',
    result            ENUM('pass','fail') NOT NULL,
    result_overridden TINYINT(1) NOT NULL DEFAULT 0,
    override_reason   VARCHAR(255) NULL COMMENT '오버라이드 시 필수',
    memo              TEXT NULL COMMENT '전체 채점 메모',
    confirmed_by      INT UNSIGNED NOT NULL,
    confirmed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_batg_attempt (attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### 3-3. 핵심 결정

- **진행 상태는 행 분포로 표현.** 미채점 = answer_grades 0건. 채점중 = 일부 판정 + attempt_grades 없음. 확정 = attempt_grades 행 존재. 별도 status 컬럼 불필요(ENUM('confirmed') 같은 단일값 컬럼도 두지 않음).
- **score는 판정 시점 스냅샷.** 보호 대상은 **가중치 상수 변경**(미래에 바뀌어도 기존 채점 불변). 분모 N 변경은 보호 대상이 아님 — 판정 원본(accuracy 등)이 저장돼 있으므로 확정 시점에 순수함수로 자동 재환산(§4). total_score·passing_score도 확정 시점 스냅샷.
- **확정 취소 = attempt_grades 행 삭제** (answer_grades 보존 → 재확정 빠름). 시험 `released` 전까지만 허용.
- **B1의 completion_ok = NULL** (가중치 0, UI 토글 숨김). B2/B3는 NOT NULL 의미 검증을 서비스에서(판정 완성 조건에 포함).
- FK 없음, 앱 레벨 cascade: `bravoExamDelete`에 answer_grades(attempt 경유)/attempt_grades 삭제 추가. `bravoQuestionDelete`는 무관(answer 자체가 보존되므로 grades도 보존).

## 4. 환산 규칙 (순수함수 + 코드 상수)

```php
// 등급별 평가요소 가중치 (문서 §11 표 — DB화는 YAGNI)
BRAVO_GRADE_WEIGHTS = [
  1 => ['accuracy'=>60, 'chunk'=>20, 'response'=>10, 'fluency'=>10, 'completion'=>0],
  2 => ['accuracy'=>45, 'chunk'=>20, 'response'=>15, 'fluency'=>15, 'completion'=>5],
  3 => ['accuracy'=>40, 'chunk'=>15, 'response'=>20, 'fluency'=>20, 'completion'=>5],
];
판정계수: accuracy correct=1/partial=0.5/wrong=0, chunk_ok 1/0,
          response·fluency good=1/normal=0.5/poor=0, completion_ok 1/0 (가중치 0이면 무시)
```

- **N = 그 attempt의 채점 대상 문항수** = 스냅샷(question_ids) ∩ 현존 bravo_questions (slice6 submit 완비 판정과 동일 기준).
- 문항별 요소 만점 = 가중치 ÷ N. 문항 점수 = Σ(요소 만점 × 계수), DECIMAL(5,2) 반올림.
- 총점(확정 시) = **현존 채점 대상 문항에 대응하는 grade만** 합산 — 채점 후 문항이 삭제돼 화면에 안 보이는 "유령 grade"는 합산에서 제외. 자동 합불 = 총점 ≥ 확정 시점의 `bravo_levels.passing_score` (50/65/80), 그 값을 `attempt_grades.passing_score`에 스냅샷 저장. 확정 취소→재확정은 새로운 확정 행위로서 그 시점 기준을 다시 적용(스냅샷에 근거 기록).
- **N 일관성 (확정 시 자동 재환산):** grade의 `n_denominator`가 현재 N과 다른 행은 저장된 판정값으로 현재 가중치·N 기준 재환산해 UPDATE 후 합산 — 한 attempt 안에서 문항 만점 기준이 항상 단일 N으로 일관됨. 재판정 불필요.
- 반올림 정책: 문항 score를 소수 2자리로 반올림해 저장, 총점은 (재환산 반영 후) 저장된 score 합산 — 표시값과 확정값 불일치 방지.

## 5. 관리자 화면 흐름 (BRAVO 셸 4번째 서브탭 "채점", `js/admin-bravo-grading.js`)

### 5-1. 목록

- 시험 선택: submitted attempt ≥1건인 시험만 (등급·상태·응시/미채점/채점중/확정 카운트 표기).
- 응시자 목록: 회원명(기수)·attempt_no·submitted_at·진행도(판정 k/N)·상태 칩(미채점/채점중 k/N/확정: 점수+합불)·[채점] 버튼.

### 5-2. 채점 상세 패널 (응시 1건)

- 헤더: 회원명·시험명·attempt_no·제출일시·**실시간 총점/합격선**(판정마다 갱신).
- 문항 카드 ×N (스냅샷 순서): `#k 유형n` + korean_text + **english_text/accepted_answers/target_chunks**(회원에게 숨겼던 채점자용 필드) + `<audio controls>`(스트리밍, 재녹음 뱃지, duration_ms 표시) + 판정 버튼 그룹 + 문항 메모(blur 저장).
- **판정 5요소(B1은 4요소)가 모두 선택되는 순간 자동 upsert** → 카드에 환산 점수 표시. 변경도 즉시 재저장. 미완성 조합은 저장 안 함(클라이언트 보류).
- 하단: 전체 메모 + 자동 판정 표시("총점 72.5 ≥ 65 → 합격") + 합불 라디오(기본 = 자동 판정) + [확정].
  - 전 문항 판정 완료 시에만 [확정] 활성화.
  - 라디오를 자동 판정과 다르게 = 오버라이드 → 사유 입력 필수.
- 확정 후: 읽기 전용 + [확정 취소](시험 released 전까지). 취소 시 판정 보존 채 재채점 모드.
- 동시 채점은 last-write-wins(운영 규모상 잠금 YAGNI). 판정 즉시 저장이라 브라우저 닫아도 진행 보존.
- CSS 미작성(스타일링 패스 별도).

## 6. API (admin.php case 6개, 전부 `requireAdmin(['operation'])`, 신규 서비스 `api/services/bravo_grading.php`)

| case | method | 요청 → 응답 |
|------|--------|------------|
| `bravo_grading_exam_list` | GET | → `{exams:[{id,title,bravo_level,status, counts:{total,ungraded,grading,confirmed}}]}` (submitted attempt ≥1 시험만) |
| `bravo_grading_attempt_list` | GET | `exam_id` → `{attempts:[{attempt_id,member_name,cohort,attempt_no,submitted_at,graded_count,total_count,confirmed:{total_score,result}|null}]}` |
| `bravo_grading_detail` | GET | `attempt_id` → `{attempt:{...}, member:{name,cohort}, exam:{id,title,bravo_level,status}, passing_score, weights, items:[{answer_id,seq,question:{korean_text,english_text,accepted_answers,target_chunks,question_type,reference_speech_sec,response_time_limit_sec}, answer:{audio_mime,duration_ms,retake_used,answered_at}, grade:{...}|null}], confirmed:{...}|null}` |
| `bravo_answer_grade_save` | POST | `{answer_id, accuracy, chunk_ok, response_rating, fluency_rating, completion_ok?, memo?}` → 검증·환산·upsert → `{score, graded_count, total_count, total_so_far, auto_result}` |
| `bravo_attempt_confirm` | POST | `{attempt_id, result, override_reason?, memo?}` → 전 문항(현존 기준) 판정 검증 → n_denominator 불일치 grade 자동 재환산 → total 합산(**현존 문항 대응 grade만** — 유령 grade 제외) → 자동 판정(확정 시점 passing_score, 스냅샷 저장) 대비 오버라이드 검증(다르면 사유 필수) → attempt_grades INSERT → `{total_score, result}`. `{attempt_id, action:'cancel'}` → released 전 검증 → 행 삭제 |
| `bravo_answer_audio` | GET | `answer_id` → **JSON 아님** — 오디오 바이너리 (Content-Type=audio_mime, realpath 루트 검증; **HTTP Range 단일 범위 지원** — `bytes=start-end` 요청 시 206 Partial Content + Content-Range, 그 외 200 전체 응답, `Accept-Ranges: bytes`. 채점 중 탐색(scrub)·iOS 재생 안정성 필수 요건) |

서비스 검증: 채점/확정은 attempt.status='submitted'만. 확정된 attempt의 판정 save 거부("확정 취소 후 수정"). cancel은 attempt_grades 존재 + exam.status≠released. completion_ok는 B1이면 무시(NULL 저장), B2/B3면 필수. answer_id가 attempt 스냅샷의 현존 문항에 속하는지 검증.

## 7. 엣지 케이스

- 스냅샷 문항 일부가 은행에서 삭제된 attempt: 현존 문항만 채점 대상(N도 현존 기준 — slice6 submit과 동일 원칙). detail의 items도 현존 문항만.
- **채점 도중 문항 삭제**(일부 판정 저장 후 N이 줄어든 경우): 중간 상태에선 화면 표시 점수가 잠정치일 수 있으나, **확정 시 n_denominator 불일치 grade를 저장된 판정으로 자동 재환산**(§4)하므로 확정 총점은 항상 단일 N 기준으로 일관. 삭제된 문항의 유령 grade는 합산 제외. 재판정 불필요, 확정 차단 불필요.
- 오디오 행은 있는데 파일 유실: `bravo_answer_audio` 404 → 카드에 "파일 없음" 표시, 판정은 가능(채점자 재량).
- 확정 후 판정 save → 거부. released 후 cancel → 거부. override 사유 누락 → 거부. 미완 문항 있는 confirm → 거부(`missing_count` 안내).
- 같은 answer 동시 판정: UNIQUE(answer_id) upsert — last-write-wins.
- 회원이 채점 중 보는 화면: 변화 없음(발표 슬라이스 전까지 "제출완료 — 결과 발표 대기").

## 8. 테스트 전략 (CLI, DEV DB 트랜잭션 롤백 — 파괴적 op 금지)

- `tests/bravo_grades_schema_invariants.php`: 2테이블 컬럼/ENUM/UNIQUE/인덱스.
- `tests/bravo_grading_test.php`:
  - 환산 순수함수: 등급 3종 × 판정 조합(만점/0점/혼합), 반올림, N 분모(현존 문항 기준), B1 completion 무시
  - save: 정상 upsert(점수 스냅샷), 재판정 갱신, B2 completion 누락 거부, 비submitted attempt 거부, 확정 후 거부, 스냅샷 밖 answer 거부
  - confirm: 미완 거부, 자동 합불(경계값 = passing_score 정확히), passing_score 스냅샷 저장, 오버라이드 사유 필수/기록, 중복 확정 거부, cancel 정상(판정 보존), released 후 cancel 거부
  - 문항 삭제 엣지: 채점 후 문항 삭제 → 유령 grade 합산 제외 + 잔존 grade 자동 재환산(n_denominator 갱신) 후 확정 총점 단일 N 일관
  - 목록 카운트: 미채점/채점중/확정 집계
  - cascade: bravoExamDelete → grades 2테이블 정리
  - 오디오: 경로 검증 로직 단위(파일 유실 404) + Range 파싱 순수함수(bytes=0-99/중간/말단/비정상 → 200 폴백) — 실재생·scrub은 브라우저 검증
- 기존 BRAVO 14파일 회귀 0.
- 브라우저 검증(사용자): slice6 검증에서 만든 제출 데이터로 채점 풀사이클(목록→재생→판정→확정→취소→재확정).

## 9. 파일 구조

- **Create** `migrate_bravo_grades.php` — 2테이블.
- **Create** `public_html/api/services/bravo_grading.php` — 가중치 상수·`bravoGradeScore()` 순수 환산·save/confirm/cancel/목록/detail 함수.
- **Modify** `public_html/api/services/bravo.php` — `bravoExamDelete`에 grades cascade 추가.
- **Modify** `public_html/api/admin.php` — require_once + case 6개.
- **Create** `public_html/js/admin-bravo-grading.js` — 채점 서브탭 모듈.
- **Modify** `public_html/js/admin-bravo.js` — 셸에 4번째 서브탭 등록.
- **Modify** `public_html/operation/index.php` — script include (v() mtime 자동).
- **Create** `tests/bravo_grades_schema_invariants.php`, `tests/bravo_grading_test.php`.

배포: dev에만. 운영(main)은 BRAVO 전체 완성 시 1회 일괄 — 사용자 명시 요청 시에만.
