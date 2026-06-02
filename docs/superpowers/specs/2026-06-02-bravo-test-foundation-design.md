# 소리블록 BRAVO 도전 시스템 — 1차 슬라이스 (기반 + 관리자 골격) 설계

작성일: 2026-06-02
원본 기능정의서: `/root/260529_bootcamp_bravo_test.docx` (기능정의서 V4, 24섹션·3단계)

## 배경 / 목적

boot.soritune.com 부트캠프 수강생이 자신이 훈련한 내용을 실제로 얼마나 말할 수 있게
되었는지 확인하는 **등급 도전(시험) 시스템**. 회원은 BRAVO 1/2/3 도전을 통해 성장 단계를
확인하고 합격/불합격 결과를 받는다.

전체 시스템은 크다(로그인·마이페이지·OT·문제은행·응시·STT·자동채점·검수·인증서 등).
본 문서는 **첫 번째 슬라이스 = "기반 + 관리자 골격"** 만 다룬다.

## 확정된 선행 결정

1. **로그인은 boot 재사용.** 별도 회원 테이블/별도 비밀번호(doc 5장의 1111 임시비번 스킴) 없음.
   회원은 이미 `bootcamp_members` 에 존재하며 boot 로그인으로 식별된다. → doc 5장(별도 로그인)은
   이 결정으로 **대체(supersede)** 된다.
2. **기존 `member_history_stats.bravo_grade` 와의 관계: 방향은 "시험이 자동등급을 대체"(option 1)** 이나,
   기존에 시험 없이 등급을 달성한 회원의 처리(grandfather) 정책은 **미정 — 추후 확정**.
   따라서 이번 슬라이스는 **순수 추가형(additive)**: 기존 `bravo_grade`/표시 로직을 일절 손대지 않고
   신규 테이블에만 쓴다. 표시 등급을 시험합격 기반으로 전환할지/기존 달성자 인정 여부는 **다음 슬라이스의
   정책 결정**으로 완전히 분리한다.
3. **응시 자격 기준 "회독수"는 하이브리드.** 유효 회독수 = 관리자 수동 override 가 있으면 그 값,
   없으면 기존 자동 집계 `member_history_stats.completed_bootcamp_count`. 자동·수동 둘 다 수용.

## 기존 boot 아키텍처 (참고)

- PHP + PDO. `api/admin.php` 단일 `switch($action)` 모놀리스, `requireAdmin(['operation'])` 로 운영권한 게이팅.
- `jsonSuccess`/`jsonError`, `getAction`/`getMethod` 헬퍼 (config.php).
- 관리자 SPA: `operation/index.php` 셸이 JS 모듈 다수 로드 후 `AdminApp.init()`.
- 회원 식별: `bootcamp_members` ⨝ `member_history_stats` (user_id 우선 COALESCE, phone 폴백).
  `user_id` 가 회원 식별 중심키 (소리튠 아이디, 크로스 기수 연결). member_create 가 user_id 필수.

## 데이터모델 (신규 테이블 2개 — 추가형)

### `bravo_levels` — 등급 설정 (시드 3행, 자격계산의 단일 진실원)

| 컬럼 | 타입 | 설명 |
|---|---|---|
| `level` | TINYINT UNSIGNED PK | 1 / 2 / 3 |
| `name` | VARCHAR(20) NOT NULL | 'BRAVO 1' 등 |
| `required_review_count` | TINYINT UNSIGNED NOT NULL | 3 / 6 / 10 |
| `passing_score` | TINYINT UNSIGNED NOT NULL | 50 / 65 / 80 |
| `requires_previous_level` | TINYINT(1) NOT NULL DEFAULT 0 | 메타데이터(doc 7-2 권장). **첫 슬라이스 자동계산엔 미적용** |
| `created_at` | DATETIME DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME ... ON UPDATE CURRENT_TIMESTAMP | |

시드: `(1,'BRAVO 1',3,50,0)`, `(2,'BRAVO 2',6,65,1)`, `(3,'BRAVO 3',10,80,1)`

`requires_previous_level` 은 doc 7-2 의 "이전 단계 합격 권장" 을 위해 저장만 한다. 첫 슬라이스에는
응시기록(attempts)이 없어 이전등급 합격 여부를 판정할 수 없으므로, 자동계산은 **doc 15-3 대로
회독수만으로** 한다. 이전등급 요건 적용은 응시기록이 생기는 다음 슬라이스에서.

### `bravo_member_settings` — 회원별 override + 수동부여 + 메모

| 컬럼 | 타입 | 설명 |
|---|---|---|
| `id` | INT UNSIGNED AUTO_INCREMENT PK | |
| `user_id` | VARCHAR(100) NOT NULL, UNIQUE | 회원 식별 중심키 |
| `review_count_override` | TINYINT UNSIGNED DEFAULT NULL | **NULL = 자동(completed_bootcamp_count) 사용**, 값 있으면 그 값 |
| `granted_levels` | SET('1','2','3') DEFAULT NULL | 수동부여 등급(계산과 무관하게 응시 허용). doc 7 "수동 자격 부여" |
| `notes` | TEXT DEFAULT NULL | 운영자 메모 |
| `updated_by` | INT UNSIGNED DEFAULT NULL | 마지막 수정 admin id |
| `created_at` | DATETIME DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME ... ON UPDATE CURRENT_TIMESTAMP | |

엔진/charset: `InnoDB`, `utf8mb4_unicode_ci` (기존 테이블 관행).

→ 기존 `member_history_stats.bravo_grade` 및 그 표시 로직(member-home.js / member-bootees.js /
memberTable.js / member.php 등)은 **일절 미수정**.

## 자격 자동계산 (저장 안 함, 읽을 때 계산)

`api/services/bravo.php` 에 순수 함수로 구현:

- `bravoEffectiveReviewCount($override, $completedCount)` = `$override !== null ? (int)$override : (int)$completedCount`
- 자동 응시가능 등급: `bravo_levels.required_review_count` 임계 기준 (≥3→B1, ≥6→+B2, ≥10→+B3).
  임계값은 **하드코딩하지 않고 `bravo_levels` 에서 읽는다** (단일 진실원).
- 최종 응시가능 = 자동 등급 ∪ 수동부여(`granted_levels`).
- (이전등급 합격 요건은 다음 슬라이스에서 적용. 수동 revoke 는 미지원 — 필요 시 다음 슬라이스.)

`completed_bootcamp_count` 는 기존 member_list 조인 패턴 그대로 취득:
`COALESCE(mhs_u.completed_bootcamp_count, mhs_p.completed_bootcamp_count, 0)` (user_id-row 우선, phone-row 폴백).

## 관리자 골격 — "BRAVO 자격" 페이지

> ⚠️ doc 각색: doc "수강생 등록"은 신규 회원 레코드 생성이지만, boot 로그인/`bootcamp_members`
> 재사용이라 회원은 이미 존재. 따라서 이번 기능은 **신규 회원 생성이 아니라, 기존 회원의
> 회독수 override·수동자격·메모 관리**다.

### API (admin.php 얇은 case → `api/services/bravo.php` 위임, `requireAdmin(['operation'])`)

- `bravo_member_list` — 현재 기수 회원 목록 (기존 `member_list` 조인 패턴 재사용, 기수 스코프).
  각 회원: user_id, 이름, phone, 자동 `completed_bootcamp_count`, `review_count_override`,
  **계산된 응시가능 등급**, `granted_levels`, `notes`. 응답 meta 로 `bravo_levels` 설정 동봉(임계/합격점 표시용).
- `bravo_member_update` (POST) — `user_id` 기준 `bravo_member_settings` upsert
  (`review_count_override` / `granted_levels` / `notes`), `updated_by` = 현재 admin id 기록.

### 프론트

- 신규 `public_html/js/admin-bravo.js` 모듈 + operation SPA 탭 등록.
- `operation/index.php` 에 `<script src="/js/admin-bravo.js?v=...">` cache-buster 동반 추가.
- 스타일은 기존 `admin.css` 재사용, 필요 최소만 추가.

### RBAC

MVP 는 기존 `operation` 롤로 게이팅 (member 관리와 동일 수준). doc 의 평가관리자/읽기전용 롤은 후속 슬라이스.

## 마이그레이션 / 가드

- `migrate_bravo.php`: `require_once __DIR__ . '/public_html/config.php'` ([[feedback_php_require_silent_fatal]]),
  멱등 `CREATE TABLE IF NOT EXISTS` 2개 + `bravo_levels` 시드(`INSERT ... ON DUPLICATE KEY UPDATE`).
- **DEV DB 먼저 적용** (junior-dev/boot-dev 작업 규칙). PROD 적용은 사용자 명시 요청 시에만.

## 첫 슬라이스에서 명시적으로 제외 (다음 슬라이스들)

- 회원 마이페이지 BRAVO 도전 상태 표시(doc 6장, 회원 화면 일체)
- 시험 기간/상태(doc 8), OT(doc 9), 시험 유형/배점/합격기준 응시 흐름(doc 10–13),
  자동평가/STT(doc 14), 문제은행(doc 15-5)·자동출제(doc 15-6), 응시현황(15-7),
  검수(16), 결과발표(17), 인증서(18), 오류방지(19)
- `bravo_grade` 표시 등급의 시험합격 기반 전환 + grandfather 정책 (미정 — 추후 확정)
- 신규 RBAC 롤(평가관리자/읽기전용)
- exams/questions/attempts/answers/certificates 테이블 (각 슬라이스에서 그 슬라이스의 실제 요구에 맞춰 생성)

## 테스트 / 검증

- `bravo.php` 자격계산 순수 함수 단위 테스트 (override 유/무, 0~2/3~5/6~9/10+ 경계, 수동부여 합집합).
- 관리자 API HTTP 스모크: member_list 응답 형태, member_update upsert 후 재조회 반영.
- 기존 member_list/bravo_grade 표시 회귀 없음 확인(추가형이므로 미변경이지만 스모크).
