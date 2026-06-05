# BRAVO 등급 단일화·강등·누적 횟수 정책 — 설계 스펙

날짜: 2026-06-05
상태: 설계 승인됨 (정책 Q&A 4건 + 접근법 B + 세부 설계 사용자 승인)
선행: slice1~8 + 스타일링 패스 (origin/dev `6af929e`). 기능정의서 §13(재응시)·§15-3(자격) 참조.
배경: slice8 까지 보류했던 **grandfather 정책의 확정판**. 운영 일괄 반영 전 마지막 정책 슬라이스.

## 1. 목표와 범위

"브라보 등급"의 진실원을 사람 단위 `current_level` 하나로 통일하고, ① 자동 등급 부여 중단(완주 기준은 이제 **응시 자격**만), ② 기존 달성자 grandfather(현 등급 그대로 backfill), ③ 회원 셀프 **강등 신청**(재시험 목적), ④ **등급당 평생 3회** 누적 응시 한도 + 관리자 추가 부여를 구현한다.

**포함:** 신규 등급 테이블+이력 로그+backfill 마이그, 레거시 자동 재계산 freeze, 표시 경로 전환(5곳), 응시 게이트 재정의(보유 차단·이전등급 요건·누적 한도), 강등 신청(모달), 관리자 등급 수동 조정 + 등급별 추가 횟수 부여 UI.

**제외 (이후):** 결제 연동(추가 횟수의 "유료"는 운영 수동 — 관리자 부여로 갈음), 강등 신청 알림톡, 등급 이력 회원 노출 UI, released 취소 시 자동 등급 회수(관리자 수동 조정으로 갈음).

## 2. 확정된 정책

| # | 정책 | 내용 |
|---|------|------|
| 1 | **자동 등급 부여 중단** | 완주 3/6/10회 도달 = 응시 **자격**(기존 slice1 자격 계산 그대로). `refreshMemberStats` 의 `bravo_grade` 자동 재계산·upsert 제거(freeze). `calcBravoGrade` 는 backfill 전용으로만 잔존. |
| 2 | **Grandfather** | 기존 `member_history_stats.bravo_grade` 보유자(분포: B1 157/B2 76/B3 5 — DEV 기준)는 현 등급 그대로 신규 테이블에 backfill (source=`grandfather`). 이후 건드리지 않음. |
| 3 | **합격 = 등급 취득** | 시험 status 가 **released 로 전환되는 시점**에 그 시험의 확정 pass 전원 `current_level = max(현재, 시험등급)` + 로그(source=`exam_pass`). released 취소해도 자동 회수 없음 — 관리자 수동 조정으로 해결. |
| 4 | **강등 신청** | 회원이 BRAVO 탭에서 한 단계 강등 가능 (B3→B2→B1→무등급(0), 횟수 제한 없음). 경고 모달 확인 후 실행. 목적: 동일 등급 재시험. |
| 5 | **이전등급 요건 활성화** | B2 응시 = `current_level ≥ 1`, B3 응시 = `current_level ≥ 2` (`bravo_levels.requires_previous_level` 메타 활용). B1 은 요건 없음. |
| 6 | **누적 횟수** | 사람×등급당 **평생 3회** — 같은 등급의 모든 시험(이월 포함) attempt 합산. 시험별 `attempt_limit` 은 **사용 중단**(컬럼·폼 값 잔존하되 게이트에서 미사용, 시험 폼에서 입력 숨김). 초과 응시는 관리자가 등급별 추가 횟수 부여(유료 정책은 운영 수동). |

## 3. 데이터 모델 (마이그 `migrate_bravo_member_grades.php` 멱등)

### 3-1. `bravo_member_grades` — 사람 단위 현재 등급 (진실원)

```sql
CREATE TABLE IF NOT EXISTS bravo_member_grades (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_key    VARCHAR(120) NOT NULL COMMENT 'user_id ?: p:<phone> — bravoAttemptMemberKey 와 동일 규약',
    current_level TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=무등급, 1~3',
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bmg_member (member_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

- **행 부재 = 무등급(0)** 과 동치. backfill 은 등급 보유자만 행 생성.
- member_key 규약은 slice6 `bravoAttemptMemberKey()` 재사용 (user_id 우선, 없으면 `p:`+phone).

### 3-2. `bravo_grade_log` — 변경 이력 (감사)

```sql
CREATE TABLE IF NOT EXISTS bravo_grade_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_key  VARCHAR(120) NOT NULL,
    from_level  TINYINT UNSIGNED NOT NULL,
    to_level    TINYINT UNSIGNED NOT NULL,
    source      ENUM('grandfather','exam_pass','self_demotion','admin_adjust') NOT NULL,
    ref_id      INT UNSIGNED NULL COMMENT 'exam_pass=exam_id, admin_adjust=admin_id, 그 외 NULL',
    note        VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bgl_member (member_key, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### 3-3. `bravo_member_settings` 확장 (ALTER — 기존 행 보존)

```sql
ALTER TABLE bravo_member_settings
    ADD COLUMN extra_attempts_1 TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'B1 추가 응시 횟수 (관리자 부여)',
    ADD COLUMN extra_attempts_2 TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN extra_attempts_3 TINYINT UNSIGNED NOT NULL DEFAULT 0
```

(멱등: `information_schema.columns` 존재 확인 후 ALTER. ⚠️ 이 프로젝트 첫 ALTER — "기존 테이블 무수정" 원칙의 의도적 예외, 추가 컬럼만이라 안전.)

### 3-4. Backfill (마이그 안에서 1회, 멱등)

- `member_history_stats` 의 `bravo_grade IS NOT NULL` 행을 사람 단위로 집계: user_id 행 우선, phone 행은 **그 phone 을 가진 bootcamp_members 의 user_id 행이 이미 커버하지 않을 때만** `p:<phone>` 키로 (이중 카운트 방지 — COALESCE(user-row, phone-row) 읽기 규약과 동일 우선순위).
- `'Bravo N'` → N 파싱 → `INSERT ... ON DUPLICATE KEY UPDATE current_level = GREATEST(current_level, VALUES(current_level))` + 로그(source=`grandfather`, 기존 로그 있으면 skip — 멱등).

## 4. 서비스 계층 (`api/services/bravo_grades.php` 신규)

| 함수 | 역할 |
|------|------|
| `bravoGradeCurrentLevel(PDO, string $memberKey): int` | 현재 등급 (행 없으면 0) |
| `bravoGradeSet(PDO, string $memberKey, int $to, string $source, ?int $refId, ?string $note): void` | upsert + 로그 (from=현재). from=to 면 no-op |
| `bravoGradeApplyExamPass(PDO, array $exam): int` | 그 시험의 확정 pass 전원에 `max(현재, 등급)` 적용, 변경 인원 반환 — **released 전환 훅에서 호출** |
| `bravoGradeDemote(PDO, string $memberKey): array` | 한 단계 강등. 0 이면 `['error'=>'내릴 등급이 없습니다.']`. 성공 `['from','to']` |
| `bravoAttemptQuotaForLevel(PDO, string $memberKey, int $level): array` | `['used'(등급 전체 시험 누적 attempt 수), 'limit'(3+extra), 'left']` |

상수: `BRAVO_BASE_ATTEMPTS_PER_LEVEL = 3`.

### 4-1. released 전환 훅

`bravoExamUpdate`(bravo.php) 에서 status 가 released 로 **바뀌는** 경우(이전 status ≠ released) 트랜잭션 안에서 `bravoGradeApplyExamPass` 호출. (admin.php case 가 아니라 서비스 함수 안 — 모든 저장 경로 커버.)

### 4-2. 응시 게이트 재정의 (`bravoAttemptExamAccess`, bravo_attempts.php)

기존 검사(404/open/기간/대상/회독자격) 뒤의 **released-pass 차단을 교체**:

```
$cur = bravoGradeCurrentLevel($db, $memberKey);
① 보유 차단:      $cur >= exam.bravo_level → '이미 BRAVO {n} 등급을 보유하고 있습니다. 재응시하려면 강등 신청을 해주세요.' (403)
② 이전등급 요건:   exam.bravo_level - 1 > $cur (requires_previous_level=1 인 등급만) → 'BRAVO {n-1} 등급 취득 후 응시할 수 있습니다.' (403)
③ 누적 한도:      quota.left <= 0 → '응시 횟수를 모두 사용했습니다. (누적 {used}/{limit}) 추가 응시는 운영진에게 문의해주세요.' (403)
```

`bravoHasReleasedPass` 는 **삭제** (slice8 도입 — 이 게이트로 대체. 테스트도 함께 정리). `bravoAttemptStart` 의 시험단위 FOR UPDATE 카운트 게이트도 누적 quota 기준으로 교체(레이스 가드 유지 — 카운트 쿼리만 등급 전체 시험 합산으로 변경, FOR UPDATE 는 그 사람 attempts 행 잠금 그대로).

### 4-3. status 응답 변경 (bravo.php)

- `bravoMemberStatus`: member 에 `current_level` 추가. levels[] 의 `passed_level` → `held`(현재 등급 ≥ 레벨) 로 의미 교체 + `quota:{used,limit,left}` + `prev_required:bool(요건 미충족)` 추가. `attempts.used/limit` 축은 카드 시험이 아니라 **등급 누적** 값으로 교체.
- `bravoStatusAttempts` 의 released `result` 노출(slice8)은 유지 — 단 `cert_issued`/인증서 흐름 무변경.

## 5. 표시 경로 전환 (5곳 — 레거시 `bravo_grade` 읽기 중단)

| 파일 | 변경 |
|------|------|
| `api/member.php` login + check_session | `COALESCE(mhs.bravo_grade)` 셀렉트 → `bravoGradeCurrentLevel` 기반 `bravo_grade` 응답 값으로 교체 (응답 키는 유지, 값 포맷 `'Bravo N'`/null 그대로 — 프론트 무수정) |
| `api/services/member.php:34` | 동일 교체 (조인 → 신규 테이블 LEFT JOIN, member_key 매칭) |
| `api/services/member_page.php:393` | 동일 |
| `api/admin.php:919` | 동일 |
| `api/services/member_stats.php` | upsert 2곳에서 `bravo_grade` 컬럼 제거(freeze). `calcBravoGrade` 는 마이그 backfill 참조용 주석 달고 잔존 |

조인 패턴: `LEFT JOIN bravo_member_grades bmg ON bmg.member_key = COALESCE(NULLIF(bm.user_id,''), CONCAT('p:', bm.phone))` — 표시 값 `CASE WHEN bmg.current_level >= 1 THEN CONCAT('Bravo ', bmg.current_level) END`. (프론트 `member-home.js` 등은 문자열 그대로 표시 — 무수정.)

## 6. 회원 UI (member-bravo.js + member.php case)

- **강등 신청**: BRAVO 탭 상단(등급 보유자만)에 현재 등급 표시 + `[강등 신청]` 버튼. 클릭 → **경고 모달** (`<dialog>`): "BRAVO {n} → {n-1 또는 '무등급'} 으로 내려갑니다. 되돌리려면 시험에 다시 합격해야 합니다. 누적 응시 횟수는 환불되지 않습니다." + [강등 확인]/[취소]. ⚠️ 취소 버튼 `formnovalidate` 또는 `type=button` (feedback_dialog_cancel_formnovalidate). 확인 → `POST action=bravo_demote` → 성공 토스트 + 탭 재로드.
- member.php case `bravo_demote`: requireMember → memberKey → `bravoGradeDemote` → jsonSuccess.
- 카드 분기 갱신: `held`(보유) → `✅ BRAVO {n} 보유` (기존 passed_level 분기 재사용), `prev_required` → `BRAVO {n-1} 취득 후 도전 가능`, quota 소진 → `누적 응시 {used}/{limit} 소진 — 추가 응시는 운영진 문의`. 도전 버튼 라벨의 횟수도 누적 quota 로.

## 7. 관리자 UI (admin-bravo.js 자격 탭 확장 + admin.php)

- `bravo_member_list` 응답에 `current_level` + `extra_attempts_{1,2,3}` + 등급별 누적 used 추가.
- 자격 테이블에 컬럼 추가: **현재 등급**(select 0~3 — 수동 조정, 변경 시 즉시 저장 + 로그 source=`admin_adjust`, ref=admin_id), **추가횟수 B1/B2/B3**(number 입력, `bravo_member_update` 에 포함).
- `bravo_member_update` 확장: extra_attempts 3필드 upsert + (등급 변경 시) `bravoGradeSet`.
- 시험 폼(admin-bravo-exams.js): `응시횟수(attempt_limit)` 입력 **숨김** (저장 payload 에선 기존값 유지 — 컬럼 잔존).

## 8. 엣지 케이스

- 강등 직후 재응시: 보유 차단 해제 — 단 누적 quota 그대로 (3회 소진자는 관리자 추가 부여 필요. 모달 문구로 고지).
- 무등급(0)에서 강등 호출: 에러 메시지 (버튼 자체가 미노출이지만 API 가드).
- released 전환 시 이미 더 높은 등급 보유자: `max()` 라 변동 없음 — 로그도 안 남김(no-op).
- 같은 시험 released 재전환(released→closed→released): `max()` 멱등 — 중복 로그 없음(no-op 조건).
- grandfather 보유자의 이전등급 요건: current_level 로 판정하므로 자연 인정 (B2 보유자는 B3 응시 가능).
- phone-only 회원(user_id 없음): member_key=`p:<phone>` — backfill·조인·강등 모두 동일 규약.
- 동시 강등 더블클릭: 모달 confirm 1회 + 서버 from=현재 재조회로 멱등(두 번 눌러도 2단계 강등 방지 — **모달 닫힘+버튼 disable + 서버는 강등 전 레벨을 응답에 포함해 프론트 표시**). 연속 2회 신청은 모달 2회 — 허용(정책상 제한 없음).
- 누적 used 집계는 **시험 삭제된 attempt 도 포함?** — `bravoExamDelete` 가 attempts 를 지우므로 삭제 시험의 횟수는 자연 환불됨 (허용 — 시험 삭제는 운영 실수 복구 수단).
- slice8 인증서·결과 공개 흐름 무변경 (released result/cert 는 attempt_grades 기반 그대로).

## 9. 테스트 전략 (CLI, DEV DB 트랜잭션 롤백)

- `tests/bravo_member_grades_schema_invariants.php`: 2테이블 + settings ALTER 3컬럼.
- `tests/bravo_grade_canonical_test.php`:
  - bravoGradeSet/CurrentLevel/로그, no-op(같은 레벨), demote 단계·0 가드
  - ApplyExamPass: released 훅(bravoExamUpdate 경유) — pass 만 승급·fail 제외·기존 상위등급 no-op·재전환 멱등
  - 게이트: 보유 차단(+강등 후 해제), 이전등급 요건(B2 에 B1 필요, B1 은 면제), 누적 quota(두 시험 합산 3회 소진→차단→extra_attempts 부여→통과), bravoAttemptStart 레이스 가드가 누적 기준인지
  - backfill 함수: 'Bravo 2' 파싱, user/phone 행 dedupe, 멱등(2회 실행 동일)
  - 표시: member_list/check_session 류 쿼리 결과의 bravo_grade 값이 신규 테이블 기준인지 (강등 반영)
- 기존 21파일 회귀 — ⚠️ slice8 의 `bravo_release_test.php` 는 차단 의미 교체로 **단언 수정 필요** (released-pass 차단 → 보유 차단; 발표 전 비공개 회귀 단언은 유지).
- 브라우저 검증(사용자): 강등 모달 풀사이클, 누적 횟수 표시, 관리자 등급 조정·추가횟수, 홈 카드 등급 반영.

## 10. 파일 구조

- **Create** `migrate_bravo_member_grades.php` (테이블 2 + ALTER + backfill)
- **Create** `public_html/api/services/bravo_grades.php`
- **Modify** `public_html/api/services/bravo.php` — released 훅(bravoExamUpdate), bravoMemberStatus 재구성, bravoHasReleasedPass 삭제
- **Modify** `public_html/api/services/bravo_attempts.php` — 게이트 교체(보유/이전등급/누적 quota), start 카운트 교체
- **Modify** `public_html/api/services/member_stats.php` — freeze
- **Modify** `public_html/api/member.php` — case `bravo_demote` + login/check_session 표시 교체
- **Modify** `public_html/api/services/member.php`, `member_page.php`, `public_html/api/admin.php` — 표시 교체
- **Modify** `public_html/js/member-bravo.js` — 강등 모달 + 카드 분기(quota/held/prev_required)
- **Modify** `public_html/js/admin-bravo.js` — 자격 탭 등급 조정·추가횟수
- **Modify** `public_html/js/admin-bravo-exams.js` — attempt_limit 숨김
- **Create** `tests/bravo_member_grades_schema_invariants.php`, `tests/bravo_grade_canonical_test.php`
- **Modify** `tests/bravo_release_test.php` — 차단 단언 교체

CSS: 강등 버튼·모달은 기존 토큰/`bravo.css` 에 소폭 추가 (스타일링 패스 기준 유지).

## 11. 배포

dev 에만. 운영 일괄 반영 시 **마이그 실행 순서 주의**: `migrate_bravo_member_grades.php` 의 backfill 이 PROD 의 현재 `bravo_grade` 값 기준으로 실행된 후 코드(freeze 포함)가 활성화됨 — git pull 직후 마이그를 바로 실행하면 됨 (pull 과 마이그 사이 `refreshMemberStats` 가 돌아도 freeze 전 코드라 무해, 마이그가 그 값을 읽음).
