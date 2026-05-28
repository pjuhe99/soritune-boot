# 「내보내기 (expelled)」 약한 조치로 전환 — 디자인

- **작성일**: 2026-05-28
- **대상 사이트**: boot.soritune.com (DEV: dev-boot.soritune.com)
- **DB**: SORITUNECOM_BOOT (DEV: SORITUNECOM_DEV_BOOT)
- **선행 작업**:
  - [2026-05-22 member-expelled-design](2026-05-22-member-expelled-design.md) — `expelled` enum 값 도입, 단체활동 차단 게이트 9곳 추가
  - [2026-05-22 leaving-member-activity-tracking-design](2026-05-22-leaving-member-activity-tracking-design.md) — `leaving` 의미 재정의

## 1. 배경

2026-05-22 spec 으로 `expelled` 는 "단체활동 전반 차단" 의 **강한 조치** 로 도입됐다. 운영하다 보니 이 강도가 실제 필요한 것보다 과해서 운영자가 "내보내기" 를 누르기 부담스럽다는 피드백.

이번 작업은 `expelled` 를 다음과 같이 **약한 조치** 로 재정의한다:

- 일반 `active` 회원과 **거의 모든 면에서 동일** (코인 적립, 후기, QR, 출석, 외부연동, 멤버페이지, cron — 전부 동일하게 처리)
- 단 `/leader` 화면의 **체크리스트** 와 **현황판** 에서만 기본 숨김. 상단의 "내보내기 회원 포함" 체크박스로 노출 토글
- 과제율 계산도 `active` 와 동일하게 반영

운영자가 부담 없이 "내보내기" 를 활용할 수 있는 가벼운 시각적 분리 도구가 된다.

## 2. 비목표

- `expelled` enum 값 자체 제거 — **하지 않음** (의미 유지, 동작만 약화)
- `/operation` 회원 관리 화면 UI 변경 — **하지 않음** (expelled 별도 탭/카운트 그대로)
- `out_of_group_management` 동작 변경 — **하지 않음**
- `refunded` 동작 변경 — **하지 않음**
- 신규 컬럼/플래그 추가 — **하지 않음** (DB 마이그 0개)
- 기존 PROD expelled 회원의 `group_id` 자동 복구 — **하지 않음** (수동 배정)
- expel 시 `member_role` 강등 — **하지 않음** (조장이 expel 돼도 role 유지)

## 3. 의미 재정의 — 5-state 표

| `member_status` | 의미 | 로그인 | 본인 페이지 | 조 소속 (`group_id`) | 단체활동 (zoom/카페/점수/코인) | 체크리스트·현황판 |
|----------------|------|--------|------------|---------------------|---------------------------------|------------------|
| `active` | 정상 + 조 소속 | ✅ | ✅ | ✅ | ✅ | ✅ 표시 |
| `out_of_group_management` | 점수 미달 자동 강등 | ✅ | ✅ | ❌ (NULL) | ✅ | (group 기반이라 자동 제외) |
| `leaving` | 자발적 조 탈퇴 | ✅ | ✅ | ❌ (NULL) | ✅ | (group 기반이라 자동 제외) |
| **`expelled` (재정의)** | **운영자 결정 약한 차단** | ✅ | ✅ | ✅ (**유지**) | ✅ (active 와 동일) | ❌ **기본 숨김** + 토글 |
| `refunded` | 환불 | ❌ | ❌ | ❌ | ❌ | ❌ |

**핵심 규칙 변화**:
- 직전 spec: `is_active = 1 AND member_status NOT IN ('refunded', 'expelled')` (단체활동 게이트)
- 이번 spec: `is_active = 1 AND member_status != 'refunded'` (expelled 도 통과)
- 새 추가 규칙 (체크리스트·현황판 전용): `bm.member_status != 'expelled'` (단, `include_expelled=1` 이면 생략)

## 4. DB 마이그

**0개.** enum 그대로, 컬럼 그대로. 코드만 변경.

## 5. 게이트 변경 — 10곳에서 `expelled` 제거

직전 spec 으로 `NOT IN ('refunded','expelled')` 로 좁혔던 게이트를 모두 `!= 'refunded'` 로 되돌린다. (직전 spec §5 의 거의 모든 항목을 되돌리는 작업)

| 파일:라인 | 현재 | 변경 후 |
|----------|------|---------|
| `public_html/cron.php:89` (initDailyChecks) | `bm.member_status NOT IN ('refunded','expelled')` | `bm.member_status != 'refunded'` |
| `public_html/cron.php:158` (backfillChecks) | 동일 | 동일 |
| `public_html/api/qr.php:178` | `member_status NOT IN ('refunded','expelled')` | `member_status != 'refunded'` |
| `public_html/api/qr.php:247` | 동일 | 동일 |
| `public_html/api/qr.php:278` | 동일 | 동일 |
| `public_html/api/qr.php:285` | 동일 | 동일 |
| `public_html/includes/qr_actions.php:28` | `member_status NOT IN ('refunded','expelled')` | `member_status != 'refunded'` |
| `public_html/includes/qr_actions.php:115` | 동일 | 동일 |
| `public_html/api/services/attendance.php:21` | `member_status NOT IN ('refunded','expelled')` | `member_status != 'refunded'` |
| `public_html/api/services/integration.php:177` | 동일 | 동일 |
| `public_html/api/services/review.php:32` | `in_array($status, ['refunded','expelled'])` | `in_array($status, ['refunded'])` |
| `public_html/api/services/member_page.php:402` | `bm.member_status NOT IN ('refunded','expelled')` | `bm.member_status != 'refunded'` |
| `public_html/api/services/coin_reward_group.php:221` | `['refunded','leaving','out_of_group_management','expelled']` | `['refunded','leaving','out_of_group_management']` |
| `public_html/includes/bootcamp_functions.php:334` (`resolveMemberByKey`) | `member_status NOT IN ('refunded','expelled')` | `member_status != 'refunded'` |

### 5.1 변경 안 함 (유지)

- `public_html/api/admin.php:594` — `/operation` 회원 목록 기본 필터. expelled 가 기본 숨김인 채로 유지 (운영자가 "환불·퇴출 회원 포함" 체크박스로 켤 수 있음). UI 분류 일관성 보존.
- `public_html/api/admin.php:628` — `$statusCounts` 의 `'expelled' => 0` 키. 운영자 카운트 표시용. 유지.
- `auth.php` — 로그인 게이트. expelled 도 로그인 OK (이전부터 그랬음).
- `includes/bootcamp_functions.php:220-225` — 점수 자동 강등 (active ↔ OOM). `WHERE member_status='active'` / `='out_of_group_management'` 명시 매칭이라 expelled 안 건드림. **단, 약한 조치로 바뀌면서 expelled 회원이 점수 미달돼도 OOM 자동 강등 안 되는 게 의도된 동작인지 확인 필요** (§9 의문점 참조).

## 6. 운영자 액션 — `handleMemberSetStatus` 변경

### 6.1 expel 분기에서 `group_id=NULL` 제거

두 곳 동시 수정 (route 가 둘 다 살아 있음):

**`public_html/api/admin.php:807-809`**
```php
// 변경 전
} elseif ($status === 'expelled') {
    $db->prepare("UPDATE bootcamp_members SET member_status='expelled', group_id=NULL WHERE id=?")->execute([$id]);
}

// 변경 후
} elseif ($status === 'expelled') {
    $db->prepare("UPDATE bootcamp_members SET member_status='expelled' WHERE id=?")->execute([$id]);
}
```

**`public_html/api/services/member.php:175-178`** — 동일.

`leaving` 분기는 그대로 (`group_id=NULL` 유지 — leaving 은 자발적 조 탈퇴이므로 의미적으로 일관).

### 6.2 active 복원 — 변경 없음

`UPDATE bootcamp_members SET member_status='active' WHERE id=?` — 이미 group_id 손대지 않음. expel 시 group_id 가 끊기지 않으니 복원 시 자동으로 active 와 완전 동일한 상태가 됨.

### 6.3 admin_action_logs — 변경 없음

전후 status, reason 기록 그대로.

### 6.4 권한 — 변경 없음

`requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2'])` 그대로.

## 7. 체크리스트·현황판 토글 (신규)

### 7.1 서버 — `api/services/check.php` 3개 핸들러

대상 함수: `handleChecklist` (line 7), `handleChecklistByMission` (line 161), `handleStatusBoard` (line 313).

각 함수의 WHERE 구성 직후 같은 패턴 추가:

```php
$where = ["bm.cohort_id = ?", "bm.is_active = 1"];
$params = [$cohortId];
// ... 기존 group_id / stage_no 필터 ...

// 신규: expelled 기본 숨김
if (empty($_GET['include_expelled'])) {
    $where[] = "bm.member_status != 'expelled'";
}
```

`handleMemberChecklistAll` (line 250) 은 단일 회원 id 직접 조회라 토글 불필요.

### 7.2 클라이언트 — `bootcamp.js`

체크리스트·현황판 UI 는 `public_html/js/bootcamp.js` 가 소유한다 (`loadChecklist` / `loadStatusBoard` / `loadChecklistByMission` + `renderChecklist*` / `renderStatusBoard`). `/leader` 와 `/admin` 두 페이지 모두 이 파일을 로드하므로 한 곳만 수정하면 leader/operation/coach 모두 토글 적용된다.

수정 지점:
- `filterBarHtml(...)` (체크리스트·현황판 공통 필터 바 헬퍼) 에 체크박스 한 줄 추가:
  ```html
  <label class="filter-chip">
    <input type="checkbox" id="bc-include-expelled" />
    내보내기 회원 포함
  </label>
  ```
- 초기화 시 `localStorage.getItem('boot.include_expelled') === '1'` 로 checked 상태 복원 (기본: off)
- change 이벤트에서 `localStorage.setItem('boot.include_expelled', checked ? '1' : '0')` + 현재 화면 reload (`loadChecklist()` / `loadStatusBoard()`)
- 모든 API 호출 (`/api/bootcamp.php?action=checklist` / `=checklist_by_mission` / `=status_board`) 에 체크박스 on 이면 `&include_expelled=1` 부착

체크리스트·현황판이 **동일 localStorage 키** 공유 → 운영자가 한 번 켜면 두 화면 모두 적용 (인지 부담 ↓).

### 7.3 행 시각 차별화

expelled 회원 행은 시각적으로 약하게 + 배지로 즉시 인지 가능하게:

- `<tr class="mt-row mt-row--expelled" ...>` 클래스 추가 (현재 status 가 expelled 일 때)
- CSS: `.mt-row--expelled { opacity: 0.7; background: var(--color-fff5f5); }`
- 이름 옆에 작은 배지 `<span class="badge badge-danger">퇴출</span>` 표시

표시 위치는 체크리스트·현황판 행 렌더 코드. 정확한 라인은 구현 시점에 확인 (검색: `mt-row` 또는 status_board row 렌더).

## 8. UI 라벨·메시지 변경

### 8.1 confirm 메시지

`public_html/js/admin.js:1376-1378` (그리고 `bootcamp.js` 의 동일 코드):

```js
// 변경 전
if (status === 'expelled') {
    confirmMsg += '\n이후 단체활동(zoom/카페/점수/후기/부티즈)에서 모두 빠집니다.';
}

// 변경 후
if (status === 'expelled') {
    confirmMsg += '\n다른 활동은 active 회원과 동일하게 유지되며, /leader 의 체크리스트·현황판에서만 기본 숨김됩니다. (상단 체크박스로 표시 가능)';
}
```

### 8.2 운영자 footer / 카운트 라벨 — 변경 없음

`/operation` 회원목록의 `환불 N, 퇴출 M 미포함` 표기, `환불·퇴출 회원 포함` 체크박스 등은 기능적으로 그대로 (admin.php:594/628 미변경).

### 8.3 status badge — 변경 없음

`memberTable.js:41` 의 `<span class="badge badge-danger">퇴출</span>` 그대로. 운영자에게 expelled 임을 알리는 시각 신호는 유지.

## 9. 의문점 / 보강 검토

### 9.1 expelled + 점수 미달 → OOM 자동 강등?

`includes/bootcamp_functions.php:220-225` 의 자동 강등 cron 은 `WHERE member_status='active'` 만 매칭. expelled 는 자동 강등 안 됨.

**현재 spec 의 결정**: 변경하지 않음. expelled 는 운영자가 명시적으로 다시 status 변경하기 전까지 expelled 유지. OOM 자동 강등은 active 회원에만 적용.

`active` 와 거의 동일이라는 의도와 모순으로 보일 수 있으나, expelled 는 운영자 결정의 명시적 표시라 자동 cron 이 덮어쓰지 않는 게 맞음. (운영자가 복원 → active 만들면 그 다음 cron 부터 OOM 자동 강등 적용)

### 9.2 기존 PROD expelled 회원 (group_id=NULL)

마이그 안 함. /operation 에서 운영자가 필요 시 개별 조 배정. 조가 없는 동안은 group 기반 기능 (체크리스트의 group_id 필터 등) 에서 자동 제외돼 사용자가 "안 보임" 으로 체감.

소급 적용되는 영향:
- 코인 적립 (coin_reward_group): 자동 적립 cron 이 INACTIVE 목록에서 빠지므로 적립 시작. 단 group 기반 일부 로직은 group_id 가 없으면 0 적립 가능 → 실제 파급 적음.
- 카페·줌 cron 백필: 다음 cron 실행 시 미반영분 백필. (논의 결과 의도된 동작)
- 후기 등록: 가능해짐.
- 멤버페이지: 노출 시작.

### 9.3 leader 화면 외 다른 진입점

확인된 expelled 관련 화면:
- `/operation/#members` — 변경 없음 (별도 카운트 유지)
- `/leader` → `/admin/index.php` (실은 같은 코드) 의 체크리스트·현황판 — 토글 추가 대상
- `/member.php` (본인 페이지) — 표시 변경 없음 (active 와 동일하게 보임)

leader 가 쓰는 다른 탭 (출석, 부티즈, 후기 등) 은 expelled 가 active 와 동일하게 노출됨 (의도).

## 10. 동작 시나리오

### 10.1 active 회원 → 운영자가 "내보내기" 클릭
1. confirm: "다른 활동은 active 회원과 동일하게 유지되며, /leader 의 체크리스트·현황판에서만 기본 숨김됩니다." + 사유 prompt
2. POST `/api/admin.php?action=member_set_status` `{id, status: 'expelled', reason}`
3. UPDATE `member_status='expelled'` (group_id 유지, role 유지)
4. admin_action_logs INSERT
5. **다음 새로고침부터**: 체크리스트·현황판에서 해당 회원 사라짐 (기본 토글 off 시). 다른 모든 화면 (출석/후기/QR/카페/코인) 은 active 와 동일하게 처리.

### 10.2 leader 가 체크박스 "내보내기 회원 포함" 클릭
1. localStorage 저장 + 화면 reload
2. 다음 API 호출에 `include_expelled=1` 부착
3. 체크리스트·현황판 에 expelled 회원 표시 (회색 배경 + `[퇴출]` 배지)
4. 체크/저장 등 액션은 일반 회원과 동일

### 10.3 운영자가 "복원" 클릭
1. confirm
2. POST `{id, status: 'active'}`
3. UPDATE `member_status='active'` (group_id/role 변화 없음 — expel 시 이미 끊기지 않았으므로)
4. 모든 화면에서 즉시 active 와 동일하게 표시

### 10.4 기존 expelled (group_id=NULL) 회원 케이스
- 복원 → active 되지만 group_id=NULL 그대로
- 운영자가 /operation 에서 별도로 조 배정 필요 (기존과 동일한 운영 흐름)

## 11. 테스트 / 회귀 가드

### 11.1 기존 테스트 — fixture 수정

`tests/expelled_*_invariants.php` 5개 (직전 spec §9 에서 도입) 는 expelled 가 단체활동에서 차단되는지 검증. 이번 변경으로 **반대 동작** 이 expected 가 됨 → expected 결과 뒤집기:

- `expelled_cron_invariants.php`: expelled 도 cron 결과에 포함되는지
- `expelled_qr_scan_invariants.php`: expelled QR 스캔 → `ok=true` + check INSERT 됨
- `expelled_cafe_ingest_invariants.php`: expelled 회원의 cafe 글도 ingest 됨
- `expelled_review_invariants.php`: expelled 후기 작성 가능 (`eligible=true`)
- `expelled_bootees_invariants.php`: expelled 도 부티즈 목록 노출

### 11.2 신규 테스트

- `tests/expelled_soft_checklist_invariants.php`:
  - active + expelled fixture 셋업
  - `handleChecklist` (param `include_expelled` 없음) → active 만 반환
  - `handleChecklist` (param `include_expelled=1`) → active + expelled 둘 다 반환
  - 같은 검증을 `handleChecklistByMission`, `handleStatusBoard` 에도 반복

- `tests/expelled_soft_group_preserve_invariants.php`:
  - active 회원 → `member_set_status status=expelled`
  - 다시 SELECT 해서 `group_id` 가 변하지 않았는지, `member_role` 도 그대로인지

### 11.3 수동 검증 시나리오 (verify 단계)

1. DEV 회원 1명 active → 내보내기. DB 에서 group_id 변하지 않음 확인.
2. 체크리스트 기본 화면 → 해당 회원 안 보임.
3. "내보내기 회원 포함" 체크 → 회색 배경 + 배지로 표시.
4. 현황판 동일 검증.
5. 같은 회원 QR 스캔 → 출석 체크 정상.
6. 같은 회원 후기 등록 페이지 진입 → 가능.
7. cron `init_daily_checks` 수동 실행 → 해당 회원의 일일 row 생성 확인.
8. 복원 → 모든 화면에서 즉시 active 와 동일하게 노출.

## 12. 배포 영향 / 롤백

### 12.1 PROD 즉시 영향

기존 PROD expelled 회원이 N 명 있다면 (DEV 0명, PROD 수는 사용자 확인 예정):

- 코인 적립 cron: 다음 실행부터 INACTIVE 목록에서 빠지므로 적립 가능. 단 group_id=NULL 인 경우 group 의존 적립은 0.
- 카페·줌 백필: 다음 cron 실행 시 미반영분 백필. (의도된 동작)
- 후기 등록·멤버페이지·QR: 즉시 가능해짐.
- 체크리스트·현황판: 기본 토글 off 인 상태로 노출 안 됨 (운영자 인지 부담 적음).

운영 영향 작음. PROD 반영 시점에 별도 announcement 불필요.

### 12.2 롤백

코드 단일 git revert 로 끝. DB 마이그 없으므로 reverse 작업 0건.

테스트 fixture 가 expected 반대로 뒤집힌 상태로 들어가므로, revert 시 테스트도 같이 revert 됨 (한 PR 묶음).

## 13. 후속 작업 (이번 범위 밖)

- 기존 expelled 회원 group_id 자동 복구 스크립트 — 운영자 판단에 따라 별도 작업
- "내보내기 회원 포함" 체크박스 UX 다른 화면으로 확장 (출석/후기 화면 등) — 현 시점 미요청
- expelled 회원에 대한 운영자 메모/태그 — 별도 plan
