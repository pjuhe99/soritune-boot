# 후기 작성 & 코인 적립 기능 — 설계서

- 작성일: 2026-04-23
- 대상: `boot.soritune.com` — 부트캠프 회원 후기 수집
- 관련 스펙: `2026-04-20-reward-groups-coins-design.md`, `2026-04-21-coin-history-view-design.md` (기존 coin 레일 위에 얹음)

## 1. 배경 / 문제

- 부트캠프 홍보를 위해 회원들의 카페/블로그 후기가 필요하나, 현재는 작성·확인·보상 경로가 수작업이다.
- 보상(코인)으로 동기를 부여하되, 거짓/부실 제출은 운영자가 차단할 수 있어야 한다.
- 후기 내용 자동 검증은 하지 않는다 — 운영자가 링크를 직접 열어보고 판단.

## 2. 요구사항

### 기능

- 회원은 대시보드 바로가기에서 "후기 작성하기" 진입 → 한 화면에서 카페/블로그 각 1회씩 링크 제출 가능.
- 카페 후기 1회 +5 코인, 블로그 후기 1회 +5 코인. 타입당 최대 1회(기수당). 이미 제출한 타입은 "제출 완료" 상태로 고정(수정/재제출 없음).
- 코인은 현재 active cycle(= 12기)에 적립 — 기존 `reward_group_distribute` 파이프라인으로 12기 정산 시 자동 지급.
- 화면 상단에 작성 가이드(어디·어떻게·해시태그 등)를 타입별로 표시.
- 링크 입력은 URL 포맷만 검증(`https?://`, 길이 10~500). 내용은 확인하지 않음.
- 운영자는 `/operation`의 "후기" 탭에서 전체 제출을 조회하고, 거짓 의심 건을 사유와 함께 취소 → 코인 자동 회수.
- 카페/블로그 각각 접수 on/off 토글 존재. off이면 해당 섹션은 "현재 접수 중이 아닙니다" 표시, 양쪽 모두 off면 바로가기 카드 자체 숨김.

### 비기능

- 새 기능 전체를 기존 coin 인프라(`applyCoinChange`, `coin_cycles`, `coin_logs`, `reward_groups`) 위에 얹음 — 별도 정산 경로 신설 안 함.
- 회원 API는 `requireMember()`, 운영 API는 `requireAdmin(['operation','coach','head','subhead1','subhead2'])`.
- 마이그는 트랜잭션 + 재실행 안전(IF NOT EXISTS, INSERT IGNORE) + `--dry-run` 지원.

### 권한

- 본인 제출 조회/작성: 로그인한 본인.
- 전체 조회/취소: 운영·코치·본부 역할.

## 3. 정책

### 3.1 제출 규칙

- **기수당(= 현재 active cycle 기준) 타입당 active(= `cancelled_at IS NULL`) 제출 1건 제한**. 중복 판정 키: `(member_id, cycle_id, type, cancelled_at IS NULL)`. 이미 active 제출이 있는 (회원 × cycle × 타입) 조합은 API에서 거부.
- 기수가 바뀌면(새 active cycle로 전환되면) 회원은 동일 타입을 다시 제출 가능. 과거 cycle의 제출은 조회/중복 체크에 영향 없음.
- 같은 cycle 내에서 취소된 건은 active 제외라 DB상 재제출이 기술적으로 가능하나, **회원 UI에서는 재제출 경로를 열지 않음** (운영자가 취소했다는 건 해당 건이 거짓이라는 판단이므로).

### 3.2 대상 회원 scope

단일 규칙(eligible = 아래 3조건 AND):

1. `bootcamp_members.is_active = 1` AND `member_status NOT IN ('refunded','leaving','out_of_group_management')`.
2. `getActiveCycle()`가 반환하는 cycle이 존재.
3. `bootcamp_members.cohort_id == active_cycle.cohort_id`.

11기 단독 참여 회원(= 12기 미참여)은 조건 3에서 탈락해 바로가기 카드 자체를 숨김. 제출해도 코인이 12기 cycle에 쌓여 실질적으로 소실되기 때문.

**전제**: `bootcamp_members.cohort_id`는 "해당 회원이 현재 참여 중인 단일 cohort"를 가리킨다. 회원이 기수 전환 시 별도 row가 생기지 않고 `cohort_id`가 덮어써지는 모델을 가정. 이 전제가 운영 데이터와 어긋나면(예: 회원이 cohort별로 row가 별도 존재) 본 섹션을 개정해야 함 — 구현 단계에서 DB 스키마와 실제 데이터로 1차 확인(섹션 10.9 참조).

### 3.3 취소 정책

- **운영자만** 취소 가능. 회원은 본인 제출을 취소/수정할 수 없음.
- 취소는 **소프트 삭제** — `cancelled_at`, `cancelled_by`, `cancel_reason` 채우고 row는 유지. 감사 목적.
- 취소 시 차감액은 **row에 저장된 `coin_amount`** (= 제출 시 실제 적립된 금액, 섹션 3.4/5.2 참조). `applyCoinChange`에 음수로 전달. `coin_logs`에 `coin_change = -coin_amount`, `reason_type = "review_cafe"` 또는 `"review_blog"`, `reason_detail = "cancel:review_submission_id:{id} reason:{...}"` 기록.
- `coin_amount`가 제출 시 적립된 실제 금액이므로, cap에 걸려 5보다 적게 적립된 건을 취소해도 과차감이 발생하지 않음.
- 기존 `coinReasonLabel()` 로직이 음수 변동에 " (취소)" 접미를 자동 부착하므로 회원 코인 내역에도 "카페 후기 (취소)" / "-N"로 노출됨.

### 3.4 cycle cap 상호작용

- `applyCoinChange`는 해당 cycle `max_coin`(기본 200) 초과분을 자동 클램프. 반환되는 `applied` 값이 실제 적립액.
- 제출 플로우는 `applied`를 `review_submissions.coin_amount` 컬럼에 저장(섹션 5.2 참조). 이후 취소 시 이 값을 그대로 차감해 **정합성 보장** (과차감/부족차감 없음).
- **엣지 케이스 — `applied === 0`**: cycle이 이미 cap에 꽉 찬 상태. 제출을 **rollback하고 에러 반환** (`"이번 기수 코인이 이미 최대치에 도달하여 후기 제출을 처리할 수 없습니다"`). "제출 완료인데 코인 0" 같은 기이한 상태를 DB에 남기지 않음.
- `0 < applied < 5`인 경우: 제출 성공, `coin_amount = applied`로 저장, 응답 메시지에 `"이번 기수 최대치에 가까워 {applied}코인만 적립되었습니다"` 안내.
- 실무상 캡 도달 케이스는 드묾.

### 3.5 하차자 처리

- 제출 후 12기 cohort에서 하차하면 선행 스펙(`reward_forfeited`) 정책에 따라 12기 cycle 코인 전체(후기 포함)가 소실됨. 본 스펙은 별도 처리 없음.
- 가이드 마크다운에 "기수 중도 하차 시 적립된 코인은 지급되지 않습니다" 한 줄 포함을 운영팀에 권장(스펙 외 커뮤니케이션).

## 4. 데이터 모델

### 4.1 신규 테이블 `review_submissions`

```sql
CREATE TABLE review_submissions (
    id            INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    member_id     INT UNSIGNED NOT NULL,
    cycle_id      INT UNSIGNED NOT NULL,
    type          ENUM('cafe','blog') NOT NULL,
    url           VARCHAR(500) NOT NULL,
    coin_amount   INT NOT NULL DEFAULT 5,
    submitted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_at  DATETIME NULL,
    cancelled_by  INT UNSIGNED NULL,
    cancel_reason VARCHAR(255) NULL,
    INDEX idx_submitted_at (submitted_at DESC),
    INDEX idx_cycle_type (cycle_id, type),
    INDEX idx_member_cycle_type (member_id, cycle_id, type),
    CONSTRAINT fk_review_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id),
    CONSTRAINT fk_review_cycle  FOREIGN KEY (cycle_id)  REFERENCES coin_cycles(id)
);
```

`INT UNSIGNED`는 `bootcamp_members.id`, `coin_cycles.id`가 `INT UNSIGNED`이기 때문. MySQL FK는 참조 컬럼과 타입/부호가 일치해야 함.

- **유니크 제약**(동일 회원 + 동일 cycle + 동일 타입 + active 1건): MySQL 조건부 유니크 미지원. **애플리케이션 레벨에서 트랜잭션 내 선조회**(`SELECT ... WHERE member_id=? AND cycle_id=? AND type=? AND cancelled_at IS NULL FOR UPDATE`) 후 INSERT로 해결. 이중 클릭/동시 제출 방어.
- `coin_amount`는 **실제 적립된 코인(cap 적용 후 `applyCoinChange.applied` 값)** 을 저장. 취소 차감액의 진실 소스(source of truth). 정책 기본값은 5지만 cap에 걸린 경우 0~5 사이 값이 들어갈 수 있음. `applied === 0`은 제출 자체를 rollback하므로 DB에 남지 않음 (섹션 3.4).

### 4.2 `system_contents` 시드

키 4개 (INSERT IGNORE):

| key | 초기값 | 용도 |
|---|---|---|
| `review_cafe_enabled` | `"on"` | 카페 후기 접수 토글 |
| `review_blog_enabled` | `"on"` | 블로그 후기 접수 토글 |
| `review_cafe_guide`   | 플레이스홀더 마크다운 | 카페 후기 작성 가이드 |
| `review_blog_guide`   | 플레이스홀더 마크다운 | 블로그 후기 작성 가이드 |

플레이스홀더 초기값(배포 후 운영팀이 실제 해시태그/링크로 덮어씀):

```markdown
## 카페 후기 작성 안내

1. 소리튠 공식 카페(https://cafe.naver.com/…)의 "후기 게시판"에 글을 올려주세요.
2. 제목에 `#소리튠부트캠프12기` 해시태그를 포함해주세요.
3. 본문에 학습 경험을 자유롭게 작성해주세요 (최소 3문장).
4. 작성한 글의 URL을 아래에 입력하면 **5코인**이 적립됩니다.

※ 기수 중도 하차 시 적립된 코인은 지급되지 않습니다.
※ 부실하거나 거짓으로 판단되는 후기는 운영자가 취소할 수 있으며, 이때 코인이 회수됩니다.
```

### 4.3 마이그 스크립트 `migrate_review_submissions.php`

- 프로젝트 기존 마이그 스타일(`migrate_split_11_12_groups.php` 등) 따름.
- `--dry-run` 지원, 트랜잭션, 실패 시 rollback.
- 단계:
  1. `CREATE TABLE IF NOT EXISTS review_submissions ...`
  2. `INSERT IGNORE INTO system_contents ...` 4건
  3. 검증: 테이블 존재 확인, 키 4개 존재 확인.

## 5. API

모든 엔드포인트는 `/api/bootcamp.php?action=…`. 로직은 새 파일 `public_html/api/services/review.php`에 모음.

### 5.1 `GET my_review_status` (회원)

- 인증: `requireMember()` → `$memberId`.
- 응답:

```json
{
  "success": true,
  "eligible": true,
  "ineligible_reason": null,
  "cafe": {
    "enabled": true,
    "guide": "...마크다운...",
    "submitted": null
  },
  "blog": {
    "enabled": true,
    "guide": "...마크다운...",
    "submitted": {
      "id": 12,
      "url": "https://blog.naver.com/...",
      "submitted_at": "2026-04-22 14:30:00",
      "coin_amount": 5
    }
  }
}
```

- `eligible` 판정: active cycle 존재 AND 회원이 활성(`is_active=1` AND `member_status NOT IN ('refunded','leaving','out_of_group_management')`) AND 회원의 `cohort_id` == active cycle의 `cohort_id`.
- `ineligible_reason` 코드 → 프론트 노출 문구:
  - `"no_active_cycle"` → "현재 진행 중인 기수가 없습니다."
  - `"cohort_mismatch"` → "이번 기수 후기 접수 대상이 아닙니다."
  - `"member_inactive"` → "현재 후기 제출이 불가능한 상태입니다."
- `submitted`: **현재 active cycle 기준** 해당 타입의 active row (`cycle_id = active_cycle.id AND cancelled_at IS NULL`). 없으면 `null`. 과거 cycle의 제출은 이 응답에 포함되지 않음.
- `submitted.coin_amount`: 실제 적립된 금액(0~5). 5 미만일 수 있음(섹션 3.4).
- `guide`: `system_contents`에서 직접 읽은 원본 마크다운. 프론트가 `renderMarkdown()`으로 렌더.

### 5.2 `POST submit_review` (회원)

- 인증: `requireMember()`.
- 요청: `{type: "cafe"|"blog", url: "https://..."}`.
- 검증 순서(트랜잭션 밖):
  1. `type` ∈ {cafe, blog}.
  2. 해당 타입 `review_{type}_enabled` = `"on"`. 아니면 에러 `"현재 접수 중이 아닙니다"`.
  3. eligible (섹션 3.2). 아니면 에러 (ineligible_reason에 따라 한글 문구).
  4. `url` 포맷: 정규식 `^https?://` + 길이 10~500. 아니면 에러 `"URL 형식이 올바르지 않습니다"`.
  5. `getActiveCycle()`로 cycle 조회. 없으면 에러 `"현재 진행 중인 기수가 없습니다"`.
- 처리(트랜잭션):
  1. `SELECT id FROM review_submissions WHERE member_id=? AND cycle_id=? AND type=? AND cancelled_at IS NULL FOR UPDATE`. 결과 있으면 에러 `"이미 제출하셨습니다"`, rollback.
  2. `INSERT INTO review_submissions (member_id, cycle_id, type, url, coin_amount) VALUES (?, ?, ?, ?, 0)` — `coin_amount`는 일단 0, 직후 UPDATE. `$insertId` 획득.
  3. `$result = applyCoinChange($db, $memberId, $cycleId, 5, "review_{type}", "review_submission_id:{$insertId}", null)`. `$adminId`는 null(회원 self-action).
  4. `$applied = $result['applied']`.
  5. **`$applied === 0`인 경우 rollback + 에러 반환** (`"이번 기수 코인이 이미 최대치에 도달하여 후기 제출을 처리할 수 없습니다"`). 이 상태에서는 row도 coin_logs 기록도 남지 않음.
  6. `UPDATE review_submissions SET coin_amount = ? WHERE id = ?` ([applied, insertId]).
  7. commit.
- 응답: `{success: true, applied_coin: $applied, submission: {id, url, submitted_at, coin_amount: $applied}, message: "..." }`.
- `0 < $applied < 5`이면 메시지에 `"이번 기수 최대치에 가까워 {applied}코인만 적립되었습니다"` 포함. 그 외 정상 케이스는 `"+{applied} 코인이 적립되었습니다"`.

### 5.3 `GET reviews_list` (운영)

- 인증: `requireAdmin(['operation','coach','head','subhead1','subhead2'])`.
- 쿼리: `cycle_id` (기본 현재 active), `type` (`cafe|blog|all`, 기본 `all`), `status` (`active|cancelled|all`, 기본 `active`), `q` (닉네임 부분일치, optional).
- SQL: `review_submissions` + LEFT JOIN `bootcamp_members bm`, `bootcamp_groups bg`, `admins a`(취소자). `ORDER BY submitted_at DESC`.
- 응답:

```json
{
  "success": true,
  "counts": {"total": 47, "active": 42, "cancelled": 5},
  "items": [
    {
      "id": 12, "type": "cafe", "url": "https://...",
      "submitted_at": "2026-04-22 14:30",
      "coin_amount": 5,
      "member_id": 301, "nickname": "까망이", "real_name": "김...", "group_name": "3조",
      "cancelled_at": null, "cancelled_by_name": null, "cancel_reason": null
    },
    {
      "id": 11, "type": "blog", "url": "https://...",
      "submitted_at": "2026-04-22 11:05",
      "coin_amount": 5,
      "member_id": 205, "nickname": "하늘", "real_name": "이...", "group_name": "1조",
      "cancelled_at": "2026-04-22 15:00",
      "cancelled_by_name": "운영자A",
      "cancel_reason": "내용 없음"
    }
  ]
}
```

- 페이징 없음(기수당 ~수백 건 상한).
- **`items`**: `cycle_id`, `type`, `status`, `q` **모든** 필터 적용 결과.
- **`counts`**: `cycle_id`, `type`, `q` 필터는 적용하되 **`status` 필터는 적용하지 않음**. 즉 `{total: 모든상태 합, active: cancelled_at IS NULL, cancelled: cancelled_at IS NOT NULL}` 의 의미가 유지됨 → UI는 status 필터와 무관하게 "전체/활성/취소" 탭 형태의 카운트 배너를 고정 표시 가능. `counts.total === counts.active + counts.cancelled`.
- 예시: `?status=active`로 호출해도 `counts`는 전체 47/활성 42/취소 5 그대로 반환, `items`만 활성 42건 반환.

### 5.4 `POST review_cancel` (운영)

- 인증: 위와 동일 권한.
- 요청: `{id, cancel_reason}`. `cancel_reason` trim 후 1~255자.
- 검증: row 존재 AND `cancelled_at IS NULL`.
- 처리(트랜잭션):
  1. `UPDATE review_submissions SET cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ? WHERE id = ? AND cancelled_at IS NULL`. 영향 행 0이면 "이미 취소된 건" 에러.
  2. `applyCoinChange($db, $member_id, $cycle_id, -$coin_amount, "review_{type}", "cancel:review_submission_id:{id} reason:{cancel_reason}", $admin_id)`. `$coin_amount`는 row에 저장된 실제 적립액이므로 cap 상황에서도 과/부족 차감 없음.
  3. commit.
- 응답: `{success, applied_coin: -$coin_amount, coin_amount: $coin_amount, message: "후기가 취소되고 코인 {coin_amount}이 회수되었습니다"}`.

### 5.5 `coinReasonLabel()` 라벨 추가

`public_html/includes/coin_functions.php`의 `coinReasonLabel()` `$map`에:

```php
'review_cafe' => '카페 후기',
'review_blog' => '블로그 후기',
```

음수 변동은 기존 로직이 " (취소)" 접미를 자동 부착.

## 6. UI — 회원

### 6.1 진입: 바로가기 카드

`public_html/js/member-shortcuts.js`에 새 카드 추가. 노출 조건(서버에서 판정해 내려주거나 프론트에서 `my_review_status` 미리 조회 — 이 결정은 구현 단계에서 `member-shortcuts.js`가 데이터를 어떻게 받는지에 맞춰 정함):

- `review_cafe_enabled === "on"` OR `review_blog_enabled === "on"`
- AND `eligible === true`

카드 클릭 → `MemberApp.openReviewSubmit()`.

### 6.2 화면 `/후기작성` — 새 모듈 `member-review.js`

레이아웃(A안, 한 화면 두 섹션):

```
┌─ 헤더 ──────────────────────────────┐
│ ← 뒤로     후기 작성하기             │
└──────────────────────────────────────┘

┌─ 카페 후기 ─────────────── +5 코인 ┐
│ ▾ 작성 가이드 (기본 펼침)          │
│   [마크다운 렌더]                   │
│ ─────────────────────────────────── │
│ 카페 후기 링크                      │
│ [ https://cafe.naver.com/...     ] │
│ [       제출하기       ]            │
└─────────────────────────────────────┘

┌─ 블로그 후기 ───────────── +5 코인 ┐
│ (동일 레이아웃)                     │
└─────────────────────────────────────┘
```

섹션별 상태 렌더:

- **enabled=on + submitted=null**: 가이드 + 입력폼 + 제출 버튼.
- **enabled=on + submitted=not null**: 가이드(접힘) + "✓ 제출 완료 · MM/DD" 뱃지 + 제출한 URL(클릭 시 새 탭). 입력폼 미표시.
- **enabled=off**: "현재 접수 중이 아닙니다" 한 줄만. 가이드/입력 숨김.

### 6.3 제출 흐름

1. 사용자가 URL 입력 → "제출하기" 클릭.
2. 프론트에서 간단 검증(`https?://` 시작, 길이 10~500) → `submit_review` POST.
3. 성공:
   - Toast `"카페 후기가 제출되어 +5 코인 적립되었습니다"` (applied < 5면 서버 메시지 그대로 사용).
   - 해당 섹션을 "제출 완료" 상태로 **즉시 재렌더**(이 화면 내에서 로컬 상태 갱신).
   - 대시보드로 뒤로 이동 시 대시보드의 코인 stat 카드 값을 최신으로 갱신해야 함 — 구체적 방법(전역 이벤트 dispatch, `MemberApp.refresh*()` 호출, 또는 대시보드 진입 시 재조회)은 기존 member.js 패턴 확인 후 구현 단계에서 결정.
4. 실패: 서버 에러 메시지를 Toast로 노출.

### 6.4 가이드 마크다운 렌더

기존 `member-home.js`의 `renderMarkdown()` 재사용. 호출 가능하도록 `MemberHome.renderMarkdown`으로 노출(또는 공통 유틸로 추출 — 구현 단계 판단).

### 6.5 eligible=false 케이스

바로가기 카드 자체를 노출하지 않음(서버가 판정). 혹시 URL 직접 접근 시 화면 상단에 "이번 기수 후기 접수 대상이 아닙니다" 안내만 표시하고 섹션은 렌더하지 않음.

### 6.6 접기/펼치기

섹션별 가이드 영역에 `<details open>` 유사 동작. 기본 펼침, 사용자 클릭 시 접힘. 접힘 상태라도 입력폼은 하단에 그대로 표시.

## 7. UI — /operation

### 7.1 탭 추가

`public_html/js/admin.js`의 탭 네비 배열에 `{key: 'reviews', label: '후기', render: AdminReviews.render}` 추가. 기존 admin.js의 탭 추가 패턴 따름.

### 7.2 새 모듈 `admin-reviews.js`

`public_html/operation/index.php`에 `<script src="/js/admin-reviews.js">` 추가.

레이아웃:

```
┌─ 후기 탭 ────────────────────────────────────────┐
│ Cycle: [12기 ▾]  타입: [전체 ▾]  상태: [활성 ▾] │
│ 닉네임: [       ]   [조회]                        │
│                                                   │
│ 전체 47 · 활성 42 · 취소 5                        │
│                                                   │
│ ┌──────────────────────────────────────────────┐│
│ │ 날짜        조   닉네임   타입   URL   상태  ││
│ │ 4/22 14:30  3조  까망이   카페   ↗    활성[취소]
│ │ 4/22 11:05  1조  하늘     블로그 ↗    취소됨 ││
│ │  └ 사유: "내용 없음" · by 운영자A · 4/22 15:00
│ └──────────────────────────────────────────────┘│
└───────────────────────────────────────────────────┘
```

- Cycle 셀렉트: `coin_cycles` 조회해 최근 5개 표시. 기본 현재 active.
- URL 링크는 `target="_blank" rel="noopener noreferrer"`.
- 취소 버튼 클릭 → 확인 모달:

```
후기 취소
  닉네임: 까망이 · 3조
  타입: 카페 후기 · 제출: 4/22 14:30
  URL: https://...
  ※ 회원의 12기 cycle에서 5코인이 차감됩니다.
  취소 사유 [                             ] (필수)
  [취소 처리]  [닫기]
```

확인 시 `review_cancel` POST. 성공 시 Toast + 목록 재조회. 취소 사유 미입력 시 모달에서 inline 에러.

- 취소된 행: 배경 톤 다운 + 2번째 줄에 취소 메타. 복원 버튼 없음.
- 빈 상태: "제출된 후기가 없습니다."
- 정렬: `submitted_at DESC` 고정.

### 7.3 권한

- **API 권한** (`reviews_list`, `review_cancel`): operation/coach/head/subhead1/subhead2 모두 허용.
- **UI 탭 노출**: 1차 릴리즈는 `/operation` 전용 (operation role). coach/head UI 진입점에는 탭을 두지 않는다. 실제 후기 감시/취소는 운영팀이 주로 수행하므로 MVP 범위를 좁혔다. 추후 코치/head 측 니즈가 확인되면 `admin.js`의 해당 role 탭 블록에 `<button data-tab="#tab-reviews" ...>`와 `<div id="tab-reviews">` 추가 + 해당 role 진입점 `index.php`에 `admin-reviews.js` 로드로 확장 가능.

## 8. 마이그 경로

### 8.1 DEV

1. 스펙 → 플랜 → 구현.
2. `migrate_review_submissions.php --dry-run` 검증 → 실제 실행.
3. `system_contents`의 가이드 키 2개를 운영팀과 함께 실제 문구로 업데이트(DB 직접 UPDATE 또는 `phpMyAdmin`).
4. 엔드투엔드 회귀 테스트 매트릭스:
   - **정상 플로우**: 카페/블로그 각 제출 → 코인 +5 적립 → 내역에 "카페 후기 +5" → 운영 탭에서 취소 → 코인 `-coin_amount` → 내역에 "카페 후기 (취소) -5".
   - **중복 제출 차단**: 같은 회원이 같은 cycle에서 동일 타입 재제출 시도 → 에러 "이미 제출하셨습니다".
   - **기수 전환 시 재개방**: DB에서 active cycle을 13기로 이동시키고, 12기에서 이미 제출한 회원이 다시 카페 후기 제출 가능한지 확인(스테이징 조작).
   - **Eligibility**: 11기 단독 참여 회원(cohort_id=11)으로 로그인 → 바로가기 카드 숨김 + API `eligible=false`. 12기 참여 회원 → eligible=true. 기수 전환 회원(있다면) → 본문 3.2의 가정대로 동작하는지 확인.
   - **Cap 엣지 케이스**: 테스트 회원의 `member_cycle_coins.earned_coin`을 199로 세팅 후 제출 → `applied=1`, `coin_amount=1` 저장, Toast 문구 확인. 이후 취소 → `-1` 차감 확인.
   - **Cap fully saturated**: earned=200 세팅 후 제출 → `applied=0` → rollback → 에러 toast + DB에 row/log 없음.
   - **토글**: 각 타입 off → 해당 섹션 "현재 접수 중이 아닙니다", 양쪽 off → 바로가기 카드 숨김.
   - **권한**: 비로그인/권한 없는 역할로 각 엔드포인트 호출 → 403.

### 8.2 PROD

사용자의 "운영 반영" 명시적 요청 후에만. `mysqldump` 백업 → 코드 pull → 마이그 dry-run → 실행 → 가이드 키 실제 문구 주입.

### 8.3 롤백

- 마이그는 트랜잭션. 실패 시 자동 rollback.
- 코드는 git revert. 이미 제출된 row가 있으면 테이블 삭제 전에 백업 권장.

## 9. 스코프 외

- 회원 본인 취소/수정 (URL 재입력).
- 취소 후 재제출 UI 경로.
- 타입당 복수 제출 (여러 후기 플랫폼 보상).
- 지난 기수 후기 조회, 후기 수/전환율 대시보드.
- 도메인 화이트리스트, 중복 URL 자동 차단.
- 이미지/스크린샷 업로드.
- 가이드 마크다운 편집용 어드민 UI.
- 카페/블로그 외 타입(유튜브, SNS 등) 추가.
- 회원 상세(memberTable) 드릴다운에 후기 이력.
- 취소 알림(이메일/푸시) — 회원에게 취소 사실 자동 통지.

## 10. 리스크 / 주의

1. **Active cycle 부재 타이밍**: cycle 전환 시 active cycle이 잠깐 없을 수 있음. 제출 API가 에러 응답. UX: Toast만. 운영자가 cycle 전환 작업을 짧게 유지하면 됨.
2. **하차 시 코인 소실**: 선행 스펙(`reward_forfeited`) 정책에 의해 12기 하차자의 12기 cycle 코인은 전체 소실(후기 포함). 본 화면에선 별도 안내 없음; 가이드 마크다운에 운영팀이 한 줄 포함 권장.
3. **Cycle cap(200) 도달 — 제출 시**: 후기 +5가 캡에 걸려 `applied < 5`인 경우 `coin_amount`에 실제 값 저장. `applied === 0`이면 제출 자체 실패(rollback). 섹션 3.4/5.2 참조.
4. **취소 시 earned 음수 가능**: `coin_amount`만큼 차감되므로 과차감은 없음. 단, 해당 회원이 이번 cycle에서 후기 외 활동이 없고 후기 코인도 cap에 걸려 작은 금액이었다면 차감 후 `member_cycle_coins.earned_coin`이 음수는 될 수 있음(예: earned 3에서 -3 차감으로 0). 회계상 정합. 회원 내코인 화면은 그대로 렌더.
5. **동일 URL 중복 제출(다른 회원 간)**: 허용. 운영자가 `reviews_list`에서 육안 감지 후 취소. 자동 탐지는 스코프 외.
6. **토글 off 후 이미 제출된 건**: 코인 유지. 토글은 앞으로의 제출만 차단.
7. **동시 제출(더블 클릭)**: `SELECT ... FOR UPDATE` 트랜잭션으로 방어. 프론트도 제출 버튼 누른 뒤 disabled 처리.
8. **거짓 신고성 취소**: 취소 사유가 `cancel_reason`에 남아 감사 가능. 잘못된 취소 시 DB 직접 롤백은 운영자가 수동으로(복원 API 없음). 이 운영 리스크는 권한 있는 역할 최소화로 관리.
9. **`bootcamp_members.cohort_id` 모델 가정 검증**: 섹션 3.2 정책은 "`member.cohort_id`가 현재 참여 cohort 단일 값"이라는 모델을 전제. 구현 시작 시 DB 스키마와 실제 데이터로 이 전제가 유효한지 먼저 확인하고, 어긋나면 본 스펙의 섹션 3.2/5.1을 개정하고 진행. 회귀 테스트에 11기 단독/12기 단독/전환 회원 각 1명 이상 포함.
