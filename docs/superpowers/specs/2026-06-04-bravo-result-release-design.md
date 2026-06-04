# BRAVO 8차 슬라이스 — 결과 발표·인증서 설계 스펙

날짜: 2026-06-04
상태: 설계 승인됨 (사용자 Q&A 3건 + 접근법 A + 섹션별 승인 완료)
참조: 기능정의서 `/root/260529_bootcamp_bravo_test.docx` §3(결과 안내), §6(상태값), §8/§13(재응시 정책), §17(발표), §18(인증서)
선행: slice1~7 — origin/dev `2b382ff`. slice6 `bravo_attempts`/slice7 `bravo_attempt_grades`(total_score/passing_score/result) 위에서 동작. **1차 MVP의 마지막 기능 조각.**

## 1. 목표와 범위

시험이 `released`로 전환되면 회원에게 합불+점수를 공개하고, 합격자는 동일 등급 재응시를 차단하며, 인증서(PDF)를 다운로드할 수 있게 한다.

**포함:** released 시 회원 결과 공개(합불+총점/합격선), 합격자 동일 등급 재응시 차단(등급 단위), 인증서 발급·다운로드(GD 렌더→Imagick PDF, PNG 폴백, 인증번호 영속 기록).

**제외 (2차~):** 기존 `bravo_grade`(완주 기반) 통합·grandfather 정책, 발표 알림톡, 점수별 세부 피드백(평가요소 분해), 인증서 재발급 관리·카톡 공유·이미지 다운로드 옵션, 발표일 자동 전환 cron(released 전환은 기존 시험 관리의 수동 status 변경).

**순수 추가형:** 기존 테이블 ALTER 없음. 기존 `bravo_grade` 레거시 경로 무수정(독립 운영 — 문서상 통합은 2차). `bravo_status` 응답은 필드 추가만.

## 2. 확정된 결정

| # | 결정 | 내용 |
|---|------|------|
| 1 | **범위 = 공개+차단+인증서** | §1 참조. |
| 2 | **공개 수준 = 합불+총점/합격선** | "총점 72.5 / 합격선 65 → 합격". 문항별·평가요소별 점수는 비공개(2차). |
| 3 | **인증서 = GD 렌더 → Imagick PDF** | 새 라이브러리 0(GD·Imagick 설치 확인됨). 한글 TTF(Pretendard, OFL) 저장소 동봉. PDF 변환 불가 시 PNG 폴백. |
| 4 | **불합격 재응시 = 다음 시험에서 자연 허용** | 문서 §13: 재응시는 "다음 도전 기간". slice6 잠금은 시험 단위라 새 시험은 이미 가능 — 해제할 것 없음. 막을 것은 반대로 **합격자의 동일 등급 재응시**. |

## 3. 데이터 모델 (신규 1테이블, 마이그 `migrate_bravo_certificates.php` 멱등)

### 3-1. `bravo_certificates` — 인증서 발급 기록 (첫 다운로드 시 1행)

```sql
CREATE TABLE IF NOT EXISTS bravo_certificates (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id  INT UNSIGNED NOT NULL COMMENT 'bravo_attempts.id — 응시당 1발급',
    cert_no     VARCHAR(40) NOT NULL COMMENT 'BRAVO{level}-{YYYYMMDD}-{seq4} (예: BRAVO2-20260612-0001)',
    member_name VARCHAR(50) NOT NULL COMMENT '발급 시점 회원명 스냅샷 (개명 후에도 인증서 불변)',
    bravo_level TINYINT UNSIGNED NOT NULL COMMENT '등급 스냅샷',
    passed_on   DATE NOT NULL COMMENT '합격일 = exam.result_release_at 의 날짜 (NULL 이면 발급일)',
    issued_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bc_attempt (attempt_id),
    UNIQUE KEY uk_bc_cert_no (cert_no),
    KEY idx_bc_level_date (bravo_level, passed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### 3-2. 핵심 결정

- **발급 = 첫 다운로드 시점.** seq = 같은 `(bravo_level, passed_on)` 범위 발급 수 + 1, 4자리 zero-pad. 트랜잭션 안 `SELECT COUNT(*) ... FOR UPDATE` + INSERT, `uk_bc_cert_no` 충돌(23000) catch 시 seq 재계산 1회 재시도 후 에러(slice6 start 패턴).
- **재다운로드 = 기존 행 재렌더.** 번호·이름·날짜 불변.
- **인증서 행은 영구 보존.** `bravoExamDelete` cascade에서 **의도적으로 제외** — 발급된 사실의 기록(cert_no 진위 확인 근거). attempt가 삭제되면 attempt_id는 참조 불가한 이력 값이 됨(허용). FK 없음.
- 기존 테이블 무수정.

## 4. 결과 공개 + 합격 차단 (서버)

### 4-1. `bravoStatusAttempts` 확장 (bravo.php)

- 시그니처 확장: 호출부가 exam row를 가지므로 `bravoStatusAttempts(PDO $db, array $exam, string $memberKey)` 형태로 변경하거나 released 여부 파라미터 추가 — 구현 시 기존 두 호출부(bravoMemberStatus, member.php intro)와 함께 일관 변경 (둘 다 이 슬라이스 내).
- **exam.status='released'일 때만**: 그 회원의 submitted attempt 중 확정(bravo_attempt_grades) 행이 있으면
  `result: {attempt_id, result:'pass'|'fail', total_score, passing_score, cert_issued:bool}` 필드를 attempts 객체에 추가 (attempt_id는 인증서 버튼용). (passing_score는 확정 시점 스냅샷 값 — bravo_attempt_grades 컬럼.)
- released가 아니면 result 필드 자체가 없음 — **발표 전 비공개가 쿼리 조건으로 보장.** 기존 필드(used/limit/in_progress/submitted) 무변경.
- `bravoMemberStatus` levels[]에 등급 단위 `passed_level: bool` 추가 — 그 등급에서 released+pass 확정 보유 여부 (카드의 "합격 완료" 분기·차단 안내용).

### 4-2. 합격자 등급 차단 (bravo_attempts.php)

- `bravoAttemptExamAccess`에 검증 추가: 같은 `bravo_level`에서 **released 시험의 pass 확정**을 보유한 member_key → `['error'=>'이미 BRAVO {n} 등급에 합격했습니다.', 'code'=>403]`.
  ```sql
  SELECT COUNT(*) FROM bravo_attempts a
  JOIN bravo_attempt_grades g ON g.attempt_id = a.id AND g.result = 'pass'
  JOIN bravo_exams e ON e.id = a.exam_id AND e.status = 'released'
  WHERE a.member_key = ? AND e.bravo_level = ?
  ```
- **released 전 합격(채점 확정만)은 기준에서 제외** — 차단 메시지로 발표 전 합격이 유추되는 정보 누설 방지.
- 불합격자: 추가 차단 없음 — 같은 등급 다음 시험 응시 자연 허용(slice6 submitted 잠금은 시험 단위).
- intro/start 둘 다 이 공통 검증을 경유(기존 구조 그대로).

### 4-3. 인증서 발급·다운로드 (신규 서비스 `api/services/bravo_certificates.php` + member.php case)

| case | method | 동작 |
|------|--------|------|
| `bravo_certificate` | GET `attempt_id` | requireMember → `bravoAttemptForMember` 소유 검증 → exam `released` + 확정 `result='pass'` 검증 → 발급 행 조회/생성(`bravoCertificateIssue`) → 렌더 → 바이너리 다운로드 (`Content-Disposition: attachment; filename="bravo{n}_certificate.pdf"; filename*=UTF-8''{RFC5987 인코딩 한글 포함명}` — ASCII 폴백+한글명 병기, PNG 폴백 시 .png) |

서비스 함수:
- `bravoCertificateIssue(PDO $db, array $attempt, array $exam, string $memberName): array` — 기존 행 반환 or 신규 발급(트랜잭션+FOR UPDATE+23000 재시도). passed_on = `result_release_at` 날짜(없으면 오늘).
- `bravoCertificateRender(array $cert): array{bytes, mime, ext}` — GD로 A4 가로 비율 캔버스(예: 1754×1240px)에 배경/테두리/텍스트(인증 문구·이름·등급·합격일·발급처 "소리튠영어"·cert_no) 렌더 → Imagick 사용 가능하면 PDF 변환(`['bytes'=>..., 'mime'=>'application/pdf', 'ext'=>'pdf']`), 불가(클래스 부재/변환 예외)면 PNG 그대로. 디자인은 텍스트 중심 1차(배경 이미지 교체는 추후 — 코드상 배경 PNG 파일 있으면 사용하는 훅만).
- 폰트: `public_html/assets/fonts/Pretendard-Bold.ttf`, `Pretendard-Regular.ttf` 저장소 동봉(OFL 라이선스 — LICENSE 파일 포함). imagettftext 사용.

## 5. 화면 (member-bravo.js 카드 분기 + 인증서 버튼)

`actionHtml`에 released 분기를 **최상단**(submitted 분기보다 먼저)에 추가:

| 조건 | 표시 |
|------|------|
| `at.result` 존재 + result='pass' | `🎉 합격! 총점 {total_score} / 합격선 {passing_score}` + **[인증서 다운로드]** 버튼 — `window.open('/api/member.php?action=bravo_certificate&attempt_id=...')` (다운로드 attachment) |
| `at.result` 존재 + result='fail' | `아쉽게 불합격 — 총점 {total_score} / 합격선 {passing_score}. 다음 도전 기간에 다시 도전할 수 있어요.` |
| exam released + submitted + result 없음 (채점 누락) | 기존 "제출완료 — 결과 발표 대기" 유지 — 회원에겐 대기로 보임(운영이 채점 후 released 유지로 해소) |
| `lv.passed_level` true + 다른 open 시험 존재 | [도전하기] 대신 `✅ BRAVO {n} 합격 완료` 표시 |

attempt가 어느 것인지: result는 카드 시험(attempts.exam_id) 기준이므로 인증서 버튼의 attempt_id는 status 응답에 포함 — `result`에 `attempt_id` 필드 추가.

## 6. 엣지 케이스

- released인데 확정 없음(채점 누락): 회원은 대기 상태 유지, 인증서·결과 비공개. 관리자 채점 탭에서 released 시험도 채점은 가능(slice7 — 확정 취소만 released에서 차단됨. 채점 save/confirm은 attempt status 기준이라 가능 — 의도 확인됨).
- 발표 전 `bravo_certificate` 직접 호출: released 검증 거부. 불합격/타인/미확정 동일 거부.
- 동시 첫 다운로드 2탭: FOR UPDATE+UNIQUE catch → 한쪽 발급, 다른 쪽 재조회 — 단일 cert_no.
- Imagick 미가용/변환 실패: PNG 폴백(다운로드 파일명 .png). 렌더 실패(폰트 유실 등): jsonError 500 메시지.
- 개명 후 재다운로드: 스냅샷 이름 유지.
- 같은 등급 시험이 여러 번 released(이월): passed_level은 어느 한 released pass라도 있으면 true — 카드 시험이 다른 released여도 차단 일관.
- cert 페이지 캐시: `Cache-Control: private, no-store` (개인정보 포함 파일).

## 7. 테스트 전략 (CLI, DEV DB 트랜잭션 롤백)

- `tests/bravo_certificates_schema_invariants.php`: 컬럼/UNIQUE 2종/인덱스.
- `tests/bravo_release_test.php`:
  - cert 발급: 형식(`BRAVO2-YYYYMMDD-0001`), 같은 (level,date) seq 증가, 재호출 동일 행, 23000 catch 경로(테스트 훅), passed_on=발표일
  - 발급 조건 거부: 미released/불합격/미확정/타인 attempt
  - status 확장: released에서만 result 노출(미released 시 result 키 부재 — **발표 전 비공개 회귀 단언**), cert_issued 반영, passed_level
  - 등급 차단: released pass 보유 시 같은 등급 다른 open 시험 access 거부, released 전 pass는 차단 안 함, 불합격자는 통과
  - 렌더: PNG 바이트 시그니처(\x89PNG) 생성, Imagick 가용 시 %PDF 시그니처, 폰트 파일 존재
- 기존 BRAVO 18파일 회귀 0.
- 브라우저 검증(사용자): released 전환 → 합격/불합격 카드 → 인증서 PDF 다운로드·내용 확인 → 합격자 같은 등급 도전하기 버튼 사라짐.

## 8. 파일 구조

- **Create** `migrate_bravo_certificates.php`
- **Create** `public_html/api/services/bravo_certificates.php` — 발급·렌더
- **Modify** `public_html/api/services/bravo.php` — bravoStatusAttempts released 확장 + passed_level
- **Modify** `public_html/api/services/bravo_attempts.php` — 합격자 등급 차단
- **Modify** `public_html/api/member.php` — case `bravo_certificate`
- **Modify** `public_html/js/member-bravo.js` — 카드 released 분기 + 인증서 버튼
- **Add** `public_html/assets/fonts/Pretendard-{Regular,Bold}.ttf` + LICENSE
- **Create** `tests/bravo_certificates_schema_invariants.php`, `tests/bravo_release_test.php`

CSS 미작성(스타일링 패스 별도 — 이 슬라이스 후 1차 MVP 기능 완성, 다음은 스타일링 패스 권장). 운영(main) 반영은 BRAVO 전체 완성 시 1회 일괄.
