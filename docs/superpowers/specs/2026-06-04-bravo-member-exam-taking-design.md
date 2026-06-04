# BRAVO 6차 슬라이스 — 회원 응시 흐름 (OT→응시→제출) 설계 스펙

날짜: 2026-06-04
상태: 설계 승인됨 (사용자 Q&A 5건 + 섹션별 승인 완료)
참조: 기능정의서 `/root/260529_bootcamp_bravo_test.docx` §8~§15, §22(1차 MVP 범위)
선행: slice1(자격)~slice5(시험-문제 배정) — origin/dev `f88a870`

## 1. 목표와 범위

회원이 자격을 갖춘 BRAVO 시험에 대해 **OT(안내+필수확인체크+마이크테스트) → 응시(문제 순차 제시+자동 녹음+즉시 업로드) → 최종 제출**까지 수행할 수 있게 한다.

**이번 슬라이스 제외 (다음 슬라이스):** 채점(수동채점 UI·관리자 녹음 청취), 결과 발표·합격/불합격 공개, 인증서, 자동출제(이번엔 slice5 수동 배정 사용), STT/자동평가(2차 개발), OT 영상 업로드(3차 — video_url 링크 표시만), 오류 신고·횟수 복구(2차).

**순수 추가형 보장:** 기존 테이블·기존 member/admin 경로·`bravo_grade` 레거시 무수정. `bravo_status` 응답은 필드 추가만.

## 2. 확정된 정책 결정 (사용자 Q&A)

| # | 결정 | 내용 |
|---|------|------|
| 1 | **시작 시 차감 + 이어하기** | [응시 시작] 시 attempt 1회 생성(=차감). 중도 이탈해도 같은 attempt로 복귀, 답변 완료된 문제 다음부터 재개. 이어하기는 시험 기간 내에만. |
| 2 | **재녹음 1회 허용** | 문제당 최대 2회 녹음(최초+재녹음 1회). 제출본은 마지막 녹음. `retake_used` 기록(채점자 참고). 유형 공통 단일 정책. |
| 3 | **PC + 모바일 모두 지원** | webm/opus(Chrome·Android)와 mp4/aac(iOS Safari) 모두 수용. OT 마이크테스트가 기기별 녹음·재생 검증 관문. |
| 4 | **제시와 동시 자동 녹음** | "다음 문제" 클릭 → 문제 공개와 동시에 녹음 자동 시작. 침묵~발화가 녹음에 그대로 담겨 수동 채점자가 반응속도를 귀로 확인 가능. |
| 5 | **범위 = OT→응시→제출** | 위 §1 제외 목록 참조. |

## 3. 데이터 모델 (신규 2테이블, 마이그 `migrate_bravo_attempts.php` 멱등)

### 3-1. `bravo_attempts` — 응시 회차 (시작 시 1행 = 차감)

```sql
CREATE TABLE IF NOT EXISTS bravo_attempts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id       INT UNSIGNED NOT NULL COMMENT 'bravo_exams.id',
    user_id       VARCHAR(100) NOT NULL COMMENT '소리튠 아이디 — 횟수 집계 키',
    member_id     INT UNSIGNED NOT NULL COMMENT '세션의 bootcamp_members.id (기수 맥락)',
    attempt_no    TINYINT UNSIGNED NOT NULL COMMENT '이 시험에서 이 사람의 n번째 응시 (1~attempt_limit)',
    question_ids  TEXT NOT NULL COMMENT '시작 시점 배정 문제 스냅샷 JSON [qid,...] (순서 보존)',
    status        ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
    ot_checked_at DATETIME NULL COMMENT '필수확인체크 시각 (require_check=1인 시험만)',
    started_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at  DATETIME NULL,
    UNIQUE KEY uk_ba_exam_user_no (exam_id, user_id, attempt_no),
    KEY idx_ba_exam_user (exam_id, user_id),
    KEY idx_ba_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### 3-2. `bravo_answers` — 문제별 답안 (업로드 성공 시 1행)

```sql
CREATE TABLE IF NOT EXISTS bravo_answers (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id   INT UNSIGNED NOT NULL COMMENT 'bravo_attempts.id',
    question_id  INT UNSIGNED NOT NULL COMMENT 'bravo_questions.id',
    seq          SMALLINT UNSIGNED NOT NULL COMMENT '제시 순서 (스냅샷 인덱스 0-base)',
    audio_path   VARCHAR(255) NOT NULL COMMENT '저장 루트 기준 상대경로',
    audio_mime   VARCHAR(50) NOT NULL COMMENT 'audio/webm | audio/mp4 | audio/ogg',
    duration_ms  INT UNSIGNED NULL COMMENT '녹음 길이 (클라이언트 보고값, 채점 참고)',
    retake_used  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '재녹음 사용 여부',
    answered_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bans_attempt_question (attempt_id, question_id),
    KEY idx_bans_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### 3-3. 핵심 결정

- **횟수 집계 키 = user_id.** 자격(회독수·`bravo_member_settings` 수동부여)이 user_id 기준이므로 응시 가능한 회원은 user_id 보유가 보장됨(자격 계산 자체가 user_id 경유). 기수가 달라져도 같은 사람의 횟수 합산. user_id 없는 회원은 자격 단계에서 이미 응시불가.
- **문제 스냅샷.** start 시점의 `bravo_exam_questions`(display_order 순)를 `question_ids` JSON으로 동결. 응시 중 관리자가 배정을 바꿔도 이어하기·submit 검증이 깨지지 않음. 스냅샷이 빈 시험(배정 0건)은 start 거부.
- **이어하기 판정 = answers 행 존재 여부.** 스냅샷 순서상 첫 미답변 문제부터 재개. 포인터 컬럼 없음.
- **재녹음 = 같은 행 교체.** UNIQUE(attempt_id, question_id), 파일도 같은 경로에 덮어씀. `retake_used=1`로 마킹 후 추가 교체 거부(=총 2회).
- **FK 없음, 앱 레벨 cascade** (기존 bravo 패턴): `bravoExamDelete`에 attempts/answers 삭제 + 업로드 디렉토리 정리 추가. `bravoQuestionDelete`는 answers를 건드리지 않음(스냅샷·답안은 응시 사실의 기록 — 문제가 은행에서 삭제돼도 보존; 채점 화면에서 누락 문제 표시는 다음 슬라이스 처리).

## 4. 회원 화면 흐름 (`BRAVO 도전` 탭 확장)

### 4-1. 등급 카드 상태 (slice3 카드에 추가)

| 상태 | 표시 |
|------|------|
| 응시가능 + open + 잔여>0 + in_progress 없음 | [도전하기] 버튼 (+ `n/limit회 사용` 표기) |
| in_progress attempt 존재 (기간 내) | [이어하기] 버튼 + 진행도 `답변 k / 총 N` |
| in_progress + **전 문항 답안 완료** (기간 무관) | [제출 마무리] 버튼 — submit만 호출 (마감 직전 마지막 업로드 후 제출 못 누른 케이스 구제. §5 submit은 기간 체크 없음) |
| in_progress + 미완료 + 기간 만료 | "응시 기간 종료 (미제출)" — 이어하기 불가, 부분 답안은 보존 |
| 해당 시험에 submitted attempt 존재 | "제출완료 — 결과 발표 대기" (+발표일). 잔여 횟수가 남아도 같은 시험 재응시 버튼은 미노출(합격 후 재응시 불가 취지 — 채점 전이므로 보수적으로 잠금; 불합격 재응시는 결과 공개 슬라이스에서) |
| 잔여 0회 | "응시 횟수 소진 (limit/limit)" |

### 4-2. OT 화면 ([도전하기] 클릭)

1. `bravo_exam_ot` 표시: intro_text, type1/2/3_text(해당 등급에 출제되는 유형만), video_url 있으면 외부 링크.
2. **마이크 테스트 (UX 관문 — 클라이언트 강제):** 3초 녹음 → 로컬 재생(업로드 없음). MediaRecorder 포맷 협상(`audio/webm;codecs=opus` → 미지원 시 `audio/mp4`)이 여기서 함께 검증됨. 성공해야 다음 단계 활성화. getUserMedia 거부/미지원 시 안내 문구 + 진행 차단. **서버는 마이크테스트 통과 여부를 검증하지 않음(의도된 설계)** — 프론트 우회로 start를 직접 호출해 건너뛸 수 있으나, 녹음 불가 상태로 응시하면 불이익은 본인 귀책이며 보안·데이터 무결성과 무관.
3. **필수 확인 체크** (`require_check=1`인 시험만): 체크해야 [응시 시작] 활성화.
4. 시작 직전 경고: "시작하면 응시 1회가 차감됩니다. 중도 이탈 시 시험 기간 내 이어하기가 가능합니다."
5. OT 레코드가 없는 시험은 OT 내용 없이 마이크테스트+시작 단계만 표시.
6. 이어하기 진입도 같은 화면 경유(마이크테스트 재수행 — 기기 변경 가능성), 단 확인체크·차감 경고는 생략하고 [이어서 응시] 버튼.

### 4-3. 응시 화면 (문제 1개씩, 뒤로가기 없음)

- 상단: 진행도 `k / N` + 유형 라벨(유형1 청크 스피드 / 유형2 문장변형 / 유형3 한문장).
- [다음 문제] 클릭 → **문제 공개와 동시에 자동 녹음 시작** (● REC + 경과 초 표시).
- 문제 표시: `korean_text` + (유형3) `target_chunks`. **english_text/accepted_answers는 절대 미노출.**
- 제한시간 = `response_time_limit_sec + reference_speech_sec` (NULL이면 합산 기본 60초). 만료 시 자동 종료.
- [말하기 완료] 또는 만료 → 녹음 종료 → **즉시 업로드** (multipart).
- 업로드 성공 시: [재녹음 1회](미사용 시에만 표시) / [다음 문제] 버튼.
- 재녹음: 같은 문제 재제시 + 자동 녹음 → 재업로드(교체, retake_used=1) → [다음 문제]만 표시.
- 업로드 실패: 자동 재시도 1회 → 실패 시 blob 메모리 유지 + [재전송] 버튼 + "네트워크 확인" 안내. 업로드 성공 전엔 다음 문제 진행 불가.
- 이어하기 진입 시 첫 미답변 문제부터 동일 흐름.
- 모바일: 단일 컬럼 모바일 우선. 백그라운드 전환 등으로 녹음 중단 시 해당 문제 재시도 안내(답안 미저장 상태이므로 재진입 시 같은 문제부터).

### 4-4. 제출 화면

- 마지막 문제 답변 후: "전체 N문항 답변 완료" → [최종 제출] → 완료 화면("제출되었습니다. 결과는 발표일에 공개됩니다." + result_release_at 표시).

## 5. API (member.php 얇은 case 4개 + 신규 서비스 `api/services/bravo_attempts.php`)

전부 `requireMember()`. 공통 검증 헬퍼: 시험 존재 + `status='open'` + (exam_mode='period'면 start_at≤now≤end_at) + 대상(target_type all | cohort=회원 접근 가능 기수) + 자격(`bravoMemberStatus` 재사용 — 해당 등급 eligible).

| case | method | 요청 → 응답 | 추가 검증 |
|------|--------|------------|----------|
| `bravo_exam_intro` | GET | `exam_id` → `{exam, ot, question_count, attempts:{used,limit,remaining}, resume:{attempt_id,answered_count}|null}` | 공통 검증만 |
| `bravo_attempt_start` | POST | `{exam_id, ot_checked}` → `{attempt_id, attempt_no, questions:[...], answered_ids:[...]}` | **in_progress 존재 시 그 attempt 반환(이어하기 겸용, 새 차감 없음)**. 신규 생성 시: 잔여>0 (`SELECT COUNT(*) ... FOR UPDATE` 트랜잭션 가드), require_check=1이면 ot_checked truthy 필수(ot_checked_at 기록), 배정 스냅샷 생성(0건이면 거부). **동시 시작 race**: 첫 attempt가 없는 상태의 동시 요청은 FOR UPDATE 만으로 직렬화가 보장되지 않으므로(빈 범위), INSERT 가 UNIQUE(exam_id,user_id,attempt_no) duplicate key(SQLSTATE 23000)로 실패하면 **catch 후 해당 (exam_id,user_id)의 in_progress attempt 를 재조회해 반환** (재조회도 없으면 1회 attempt_no 재계산 재시도 후 에러) |
| `bravo_answer_save` | POST multipart | `attempt_id, question_id, duration_ms, retake(0/1), audio(file)` → `{saved:true, answered_count, all_answered:bool}` | attempt 소유(user_id 일치)+in_progress+**기간내**, question_id ∈ 스냅샷, 신규 저장 또는 재녹음 교체(기존 행 retake_used=0일 때만), MIME 화이트리스트(webm/mp4/ogg — 서버는 finfo 실측+확장자 매핑)+크기≤10MB+is_uploaded_file |
| `bravo_attempt_submit` | POST | `{attempt_id}` → `{submitted:true}` | 소유+in_progress, **스냅샷 전 문항 답안 존재** (미답 시 `missing:[qid...]` 반환 거부), status=submitted+submitted_at. **기간 체크 없음(의도)**: 답안은 기간 내에만 저장 가능(save가 가드)하므로 전 문항 완비 자체가 기간 내 완료의 증명 — 마감 몇 초 차이로 [최종 제출]이 막혀 차감만 남는 봉쇄를 방지. 미완료 attempt는 기간 후 save 가 막히므로 영원히 제출 불가(=미제출 종료) |
| `bravo_status` (기존 확장) | GET | levels[]에 `attempts:{exam_id,used,limit,in_progress:{attempt_id,answered,total}|null,submitted:bool}` 추가 | 기존 필드 무변경. **attempts 의 기준 축 = 그 레벨 카드에 표시된 단일 시험(exam_id)** — bravoMemberStatus 는 레벨당 시험 1건(open 우선→최신 id)만 고르므로 used/in_progress/submitted 전부 그 exam_id 로 한정 집계. 매칭 시험이 없으면 attempts=null |

- 문제 응답 필드 최소화: `id, seq, question_type, korean_text, target_chunks, reference_speech_sec, response_time_limit_sec`. **english_text/accepted_answers 미포함.**
- 기간 만료 시 intro/start/save 거부 (submit 은 위 표대로 기간 체크 없음 — 완비된 attempt 의 마무리 제출 허용). 미완료인 채 만료된 attempt 는 그대로 종료(차감 유지, 부분 답안 보존 — 다음 슬라이스에서 관리자 열람).
- jsonSuccess 평탄화 주의: JS는 `r.attempt_id` 등 직접 접근 (`r.data.*` 아님).

## 6. 녹음 (프론트, 신규 `js/member-bravo-exam.js`)

- 모듈 분리: 기존 `member-bravo.js`(상태 카드)는 카드 버튼 → `MemberBravoExam.open(examId, level)` 호출만 추가. 응시 플로우 전체는 신규 모듈.
- MediaRecorder 래퍼: `MediaRecorder.isTypeSupported('audio/webm;codecs=opus')` → 폴백 `audio/mp4`. 둘 다 불가면 미지원 안내(마이크테스트 단계에서 차단).
- 스트림은 OT 마이크테스트에서 `getUserMedia` 1회 획득 후 응시 동안 유지(문제마다 권한 팝업 방지). 응시 종료/이탈 시 트랙 stop.
- duration_ms는 녹음 시작~종료 시각 차로 클라이언트 계산(채점 참고용 — 신뢰 데이터 아님).
- 업로드: `FormData` + 기존 `App.post`(FormData 지원 확인됨).

## 7. 업로드 저장 인프라

- 경로: `<레포루트>/bravo_uploads/answers/<attempt_id>/<question_id>.<webm|m4a|ogg>` — **docroot(public_html) 밖** → 직접 URL 접근 불가. 서빙(관리자 청취)은 채점 슬라이스에서 PHP 스트리밍으로.
- 확장자는 서버가 실측 MIME(finfo)에서 매핑(클라이언트 파일명 무시).
- 셋업(마이그에 포함 + 안내 echo): 디렉토리 생성, `apache:apache`, 권한 750, **SELinux `httpd_sys_rw_content_t`** (미설정 시 httpd 전체 다운 이력 — 마이그가 `semanage fcontext`+`restorecon` 명령을 echo로 안내, 실행은 수동 root), `.gitignore`에 `bravo_uploads/` 등록.
- PHP 업로드 한도: 현재 `upload_max_filesize`/`post_max_size` 확인 후 10MB 미만이면 vhost/`.user.ini`로 16M 확보(플랜에서 확인 단계).
- `bravoExamDelete` cascade 시 해당 시험 attempts의 업로드 디렉토리도 재귀 삭제(경로 prefix 검증 후).

## 8. 에러 처리·엣지 케이스

- 동시 시작(중복 클릭/탭 2개): UNIQUE(exam_id,user_id,attempt_no) + FOR UPDATE COUNT + **duplicate key catch→in_progress 재조회 반환** (§5 start 참조) → 한쪽만 생성, 다른 쪽은 기존 attempt 수신.
- 마감 직전 완주: 마지막 답안이 기간 내 업로드됐다면 [최종 제출]은 기간 후에도 성공 (§5 submit). 카드의 [제출 마무리] 버튼이 동일 경로 — 제출 화면을 못 본 채 이탈해도 구제됨.
- 재녹음 2회 시도(클라이언트 우회): 서버가 retake_used=1 행 교체 거부.
- 스냅샷 밖 question_id 업로드: 거부.
- 다른 회원 attempt 접근: user_id 불일치 거부.
- submitted attempt에 save/submit: 거부.
- 시험이 응시 중 closed/기간만료: save/submit 거부 — 안내 문구("응시 기간이 종료되었습니다").
- LEFT JOIN NULL → `e()` TypeError 방지: OT 텍스트 등 nullable 컬럼은 `?? ''` 평탄화 (boot 기존 사고 패턴).
- 업로드 부분 실패로 고아 파일: 행 INSERT 전 파일 저장 → INSERT 실패 시 파일 삭제 시도. 잔여 고아는 attempt 디렉토리 단위라 cascade 정리에 포섭.

## 9. 테스트 전략 (CLI, DEV DB 트랜잭션 롤백 — 파괴적 op 금지)

- `tests/bravo_attempts_schema_invariants.php`: 2테이블 컬럼/NOT NULL/UNIQUE/인덱스.
- `tests/bravo_attempt_test.php` (서비스 통합):
  - 공통 검증: 미존재/preparing/closed 시험, 기간 밖, cohort 대상 불일치, 자격 미달 → 거부
  - start: 정상 생성+스냅샷 동결, 배정 0건 거부, require_check 미체크 거부, in_progress 재호출 시 동일 attempt 반환(차감 없음), limit 도달 시 거부, attempt_no 증가, duplicate key 시 기존 in_progress 반환(단위 테스트로 catch 경로 검증)
  - submit 기간 무관: 전 문항 완비 attempt 는 기간 만료 후에도 submit 성공, 미완비는 기간 내라도 missing 거부
  - answer: 정상 저장, 스냅샷 밖 거부, 재녹음 1회 교체+2회 거부, 타인 attempt 거부, submitted 후 거부 (파일 I/O는 tmp 경로 주입으로 검증)
  - submit: 전 문항 완료 시 성공, 미답 목록 반환 거부, 중복 submit 거부
  - cascade: bravoExamDelete → attempts/answers 0건
  - status 확장: used/in_progress/submitted 반영
- 기존 BRAVO 12파일 회귀 0.
- 브라우저 검증(사용자): PC Chrome / Android Chrome / iPhone Safari 각 1회 풀사이클(OT 마이크테스트→응시→이탈→이어하기→제출).

## 10. 파일 구조

- **Create** `migrate_bravo_attempts.php` — 2테이블 + 업로드 디렉토리 셋업/안내
- **Create** `public_html/api/services/bravo_attempts.php` — 검증·start/save/submit/상태 함수
- **Modify** `public_html/api/services/bravo.php` — `bravoExamDelete`에 attempts/answers/파일 cascade, `bravoMemberStatus` levels에 attempts 필드 추가
- **Modify** `public_html/api/member.php` — require_once + case 4개(intro/start/save/submit)
- **Create** `public_html/js/member-bravo-exam.js` — OT/응시/제출 플로우 + MediaRecorder 래퍼
- **Modify** `public_html/js/member-bravo.js` — 카드 버튼(도전/이어하기/상태 표기) 연결
- **Modify** `public_html/index.php` — script include (v() mtime 캐시버스터 자동)
- **Create** `tests/bravo_attempts_schema_invariants.php`, `tests/bravo_attempt_test.php`

CSS는 기존 방침대로 미작성(스타일링 패스 별도). 운영(main) 반영은 BRAVO 전체 완성 시 1회 일괄.
