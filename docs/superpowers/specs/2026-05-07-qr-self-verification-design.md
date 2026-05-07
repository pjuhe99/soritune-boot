# QR 출석/패자부활 본인 확인 강화 — 설계

날짜: 2026-05-07
범위: boot.soritune.com (boot-dev → boot-prod)
배경 audit: 2026-05-07 외부 코드 리뷰 — 12기 오픈 전 보완 항목 #2 (QR 본인 확인)

## 배경

`api/qr.php` 의 공개 endpoint(`group_members`, `record`, `revival_record`)는 유효한 QR 세션 코드만 있으면 누구나 호출 가능하다. 결과:

- 임의 `member_id` 로 출석/패자부활 처리 가능 — 본인 확인 0
- 조원 닉네임 목록이 공개 (privacy 약화)
- 중복 방지가 IP+UA 기반 — 같은 사용자가 다른 기기에서 우회 가능 (특히 패자부활)

12기 오픈(2026-05-11) 전에 익명 사기 통로를 차단하되, 운영 유연성(예: 조장이 결석한 조원의 출석을 대신 입력해주는 예외 케이스)은 막지 않는 방향으로 강화한다.

## 정책 결정

사용자 의사 결정 요약:

1. **회원 세션 강제** — 모든 QR endpoint(verify 제외) 가 로그인 회원만 호출 가능. 비로그인 사용자는 익명 임의 ID 로 출석/부활 시도 불가.
2. **본인 강제는 하지 않음** — 로그인된 회원이 다른 회원의 출석/부활을 입력하는 것은 허용. 운영 예외 대응(조장 대리 입력 등)을 막았을 때의 부작용이 더 크다고 판단. 대신 **누가 누구를 찍었는지 audit log** 로 사후 검증 가능하게 한다.
3. **출석/패자부활 동일 정책** — 둘 다 같은 검증·로깅 규칙. 패자부활을 별도로 강하게 가드하지 않음.
4. **OTP / SMS 같은 추가 본인 확인은 도입하지 않음** — 알림톡이 한국 번호만 발송 가능해 해외 회원 차별 발생. 별도 #1 spec 자체가 보류.

## 변경 범위

수정
- `public_html/api/qr.php` — 4개 case (`groups`, `group_members`, `record`, `revival_record`) 에 `requireMember()` 추가. `record`/`revival_record` 가 `actor_member_id` 컬럼에 요청 주체 회원 ID 기록. `revival_record` 의 IP+UA 중복 가드를 `qr_attendance.uk_session_member` UNIQUE 제약 활용으로 단순화.
- `public_html/qr/index.php` — fetch 호출이 401 응답을 받을 때 `/` 로 redirect (URL 에 returnTo 쿼리 포함). `verify` 단계는 그대로 비로그인 허용.
- `public_html/index.php` 의 SPA (MemberApp) — `?returnTo=` 파라미터를 읽어, 로그인 성공 시 해당 URL 로 이동.

신규
- `migrate_qr_audit.php` — `qr_attendance.actor_member_id` + `revival_logs.actor_member_id` + `revival_logs.qr_session_id` 컬럼 추가.

영향 없음
- `verify` action 은 변경 없음 (단순 코드 검증, 로그인 불필요)
- `create_session`, `close_session`, `session_status` 는 이미 `requireAdmin()` 으로 코치 전용
- `qr_sessions`, `bootcamp_members`, `score_logs`, `member_scores` 테이블 변경 없음
- 기존 admin (코치/조장) 의 QR 세션 생성 흐름은 동일

## 데이터 모델

### 마이그 (`migrate_qr_audit.php`)

```sql
ALTER TABLE qr_attendance
  ADD COLUMN actor_member_id INT UNSIGNED NULL
    COMMENT '실제 요청한 회원 (member_id 와 다르면 대리 출석)'
    AFTER member_id,
  ADD KEY idx_qa_actor (actor_member_id);

ALTER TABLE revival_logs
  ADD COLUMN actor_member_id INT UNSIGNED NULL AFTER member_id,
  ADD COLUMN qr_session_id INT UNSIGNED NULL AFTER actor_member_id,
  ADD KEY idx_rl_actor (actor_member_id),
  ADD KEY idx_rl_session (qr_session_id);
```

전부 NULLable + idempotent 가드(`IF NOT EXISTS` PHP 측에서). 12기 데이터 거의 없는 시점이라 lock 영향 무시 가능.

### audit log 의 의미

- `actor_member_id = member_id` → 본인 입력 (정상 패턴)
- `actor_member_id ≠ member_id` → 대리 입력 (예외 패턴, 사후 검토 대상)
- `actor_member_id IS NULL` → 마이그 이전 데이터 (백필 안 함)

어드민 페이지에서 "대리 출석/부활 목록" 필터 추가는 별도 spec 으로 분리. 이번 작업은 데이터 수집까지만.

## 데이터 플로우

```
1. QR 스캔 → /qr/?code=<12hex>
2. qr/index.php 로드 → verify 호출 (회원 세션 불필요) → QR 코드 유효성 확인
3. groups + group_members 호출 (회원 세션 필요)
   - 비로그인 → 401 → frontend 가 location.href = "/?returnTo=" + encodeURIComponent(현재 URL)
   - 로그인 → 그룹/조원 닉네임 표시
4. 사용자가 본인 또는 다른 조원 클릭
5. record 또는 revival_record 호출 (회원 세션 필요)
   - 비로그인 → 401 → 위와 동일하게 redirect
   - 로그인 → 출석/부활 처리, actor_member_id = 세션 member_id 기록
6. 로그인 페이지(/) 에서 로그인 성공 → returnTo 파라미터 있으면 해당 URL 로 이동
```

## 에러 핸들링

| 상황 | 응답 | UX |
|---|---|---|
| QR 코드 만료/없음 | 200 `{valid: false, reason}` | 안내 메시지 (현재와 동일) |
| 비로그인 | 401 JSON `{error}` | frontend 가 `/?returnTo=...` 로 redirect |
| 본인/대리 첫 출석 | 200 `{already: false, member_name}` | 성공 메시지 |
| 동일 세션 동일 회원 중복 출석 | 200 `{already: true}` | "이미 출석 처리되었습니다" (UNIQUE 가드) |
| 동일 세션 동일 회원 중복 패자부활 | 200 `{already: true}` | "이미 부활 처리되었습니다" (UNIQUE 가드) |
| 패자부활 점수 부적격 (>-10) | 200 `{not_eligible: true, current_score}` | 안내 (현재와 동일) |
| 잘못된 member_id (cohort 불일치 등) | 400 JSON | "유효하지 않은 회원입니다" |

### IP+UA 중복 가드 제거

`revival_record` 의 기존 검사:
```php
SELECT id FROM qr_attendance WHERE qr_session_id = ? AND ip_address = ? AND user_agent = ?
```

는 같은 사용자가 다른 기기로 우회 가능. `qr_attendance.uk_session_member` UNIQUE 제약(이미 존재) 으로 대체. 의미: **한 부활 QR 세션 내에서 회원당 1회** (다른 QR 세션 = 다른 부활 이벤트 에서는 또 처리 가능). 기존 IP+UA 의도와 동일하나 더 정확. UA 가 NULL/empty 인 케이스도 자연스레 처리 — `INSERT IGNORE` 가 UNIQUE 충돌만 검사.

### actor_member_id NULL 처리

마이그 이전 데이터(백필 안 함) 또는 기능 변경 직후 일부 기간 → audit 페이지에서 "audit log 미수집" 표시. 백필은 의미 없음(과거 요청 주체를 알 길 없음).

## 테스트 (DEV)

### 시나리오 (필수)

1. **비로그인 차단** — 로그아웃 상태에서 `groups`, `group_members`, `record`, `revival_record` 호출 → 401 응답
2. **로그인 후 본인 출석** — 정상 200 + qr_attendance 의 `actor_member_id = member_id`
3. **로그인 후 대리 출석** — 본인이 아닌 다른 회원 ID 로 호출 → 정상 200 + `actor_member_id ≠ member_id` (audit 대상 표식)
4. **중복 출석 race** — 같은 회원 동시 2회 호출 → 첫 1번만 INSERT, 둘째는 `already: true`
5. **중복 패자부활 race** — 같은 회원 동시 2회 호출 → 첫 1번만 score 부여, 둘째는 `already: true`
6. **로그인 redirect** — 비로그인으로 `/qr/?code=xxx` 접근 → 그룹 클릭 시 401 → `/?returnTo=/qr/...` redirect → 로그인 → returnTo 로 자동 복귀
7. **패자부활 점수 정합성** — 4번 race 상황에서 score 가 +7 만 한 번 적용 (UNIQUE 가드로 보장)
8. **verify 는 비로그인 가능** — `verify` 만 호출 시 회원 세션 없어도 200 (랜딩 페이지가 무한 redirect 루프 안 만들어야 함)

### 회귀 (필수)

- 코치/조장의 QR 세션 생성 흐름 변화 없음 (admin 세션 사용)
- 정상 출석 후 `member_mission_checks`, `score_logs`, `coin_logs` 정합성 (saveCheck 흐름)
- 패자부활 후 `score_logs`, `revival_logs`, `member_scores` 정합성

## 롤아웃

```
1. boot-dev: migrate_qr_audit.php 작성 + 적용
2. boot-dev: qr.php / qr/index.php / index.php (returnTo) 수정 + commit
3. boot-dev: 8개 시나리오 + 회귀 통과
4. boot-dev: push origin dev → 사용자 검증 (DEV QR URL 로 직접 스캔/시뮬)
5. ⛔ 사용자 명시 요청 후 PROD 진행
6. boot-dev: main 머지 + push origin main
7. boot-prod: migrate_qr_audit.php 적용 + git pull
8. PROD 1회 수동 smoke (가능하면 코치에게 요청)
```

마이그는 ALTER ADD COLUMN NULL — 기존 행 수정 없음, lock 짧음. 12기 시작 전이라 트래픽 적음.

## 인접 spec과의 관계

- **#3 점수/코인 트랜잭션화** (별도 spec) — `revival_record` 의 `INSERT revival_logs → INSERT score_logs → UPDATE member_scores → UPDATE bootcamp_members.member_status → INSERT qr_attendance` 순차 처리는 #3 의 트랜잭션 가드 대상에 포함된다. 이번 spec 은 **인증/audit log 만**, 트랜잭션화는 #3 에서.
- **#5 admin.php 분리** (오픈 후) — QR 관련 코드는 별도 파일이라 영향 없음.

## 안 함

- 휴대폰 뒤 4자리 추가 인증 — 사용자 결정 (운영 유연성 우선)
- QR 세션별 1회용 서명 토큰 — 회원 세션 강제로 충분
- 조원 목록 비공개화 (자기 그룹만) — 사용자가 신뢰 기반 운영 노선 선택
- 어드민 audit 검토 페이지 — 별도 spec
- 마이그 이전 데이터 백필 — 요청 주체 알 길 없음
