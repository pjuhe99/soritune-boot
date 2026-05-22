# 운영자 결정 「퇴출 (expelled)」 — 디자인

- **작성일**: 2026-05-22
- **대상 사이트**: boot.soritune.com (DEV: dev-boot.soritune.com)
- **DB**: SORITUNECOM_BOOT (DEV: SORITUNECOM_DEV_BOOT)
- **선행 작업**: [leaving redefinition](2026-05-22-leaving-member-activity-tracking-design.md) — `leaving` = 자발적 조 탈퇴 (단체활동 OK) 로 재정의 완료. 본 작업은 그 위에 "퇴출" 을 별개 enum 값으로 추가한다.

## 1. 배경

이번 leaving redefinition 으로 `member_status` 값 4개 (`active` / `out_of_group_management` / `leaving` / `refunded`) 의 정렬은 끝났지만, 운영자가 명시적으로 회원을 "내보내고 싶다 — 단체활동까지 차단" 라고 결정하는 케이스가 없다. 점수 미달 자동 강등은 `out_of_group_management` 로 분리됐고 그 회원도 단체활동은 유지되므로, 운영자 결정 기반의 강한 차단 상태가 spec §8 후속 작업으로 미뤄져 있던 상태.

이번 작업은 그 후속을 빼내서 `expelled` enum 값으로 도입한다.

## 2. 비목표

- 자동 expulsion (점수 임계값으로 cron 자동 전환) — 안 함
- expelled 회원의 점수/코인 회수 (이미 쌓인 것 환수) — 안 함
- expelled → refunded 자동 전환 — 안 함, 별도 액션
- 알림 (회원에게 퇴출 통보) — 이번 범위 밖
- expelled 회원의 자기 페이지 (`/member.php`) 접근 차단 — 안 함, 본인 이력 열람 유지
- `leaving_reason` / `expelled_reason` 등 구조화된 사유 컬럼 추가 — 안 함, 자유 텍스트만

## 3. 의미 재정의 — 5-state 표

| `member_status` | 의미 | 로그인 | 본인 페이지 | 조 활동 | 단체활동 (zoom/카페/점수/코인) |
|----------------|------|--------|------------|---------|---------------------------------|
| `active` | 정상 + 조 소속 | ✅ | ✅ | ✅ (`group_id` 보유) | ✅ |
| `out_of_group_management` | 점수 미달 자동 강등, 부활 가능 | ✅ | ✅ | ❌ (`group_id=NULL`) | ✅ |
| `leaving` | 자발적 조 탈퇴 | ✅ | ✅ | ❌ (`group_id=NULL`) | ✅ |
| **`expelled` (NEW)** | **운영자 결정 퇴출** | ✅ | ✅ | ❌ (`group_id=NULL`) | ❌ |
| `refunded` | 환불 | ❌ | ❌ | ❌ | ❌ |

**핵심 규칙 (단체활동 게이트)**: `is_active = 1 AND member_status NOT IN ('refunded', 'expelled')`.

`refunded` 와 `expelled` 둘 다 차단하는 셋(set) 비교. 직전 redefinition 으로 모든 게이트가 `!= 'refunded'` 패턴으로 정렬됐으므로 이번엔 그 패턴을 `NOT IN ('refunded', 'expelled')` 로 한 칸 더 좁힌다.

## 4. DB 마이그

### 4.1 enum 확장 (마이그 1개)

```sql
ALTER TABLE bootcamp_members
  MODIFY COLUMN member_status
    ENUM('active','leaving','out_of_group_management','refunded','expelled')
    NOT NULL DEFAULT 'active';
```

- backward-compatible (기존 값 보존, 새 값만 추가)
- 데이터 마이그 0건 (PROD 에 expelled 회원 0명)
- 롤백: 코드 revert + 마이그 reverse. enum 축소 시 expelled 회원이 0명이어야 ALTER 성공.

## 5. 게이트 변경 — 9곳

직전 redefinition 으로 `!= 'refunded'` 로 정렬된 게이트를 `NOT IN ('refunded','expelled')` 로 좁힌다. **`cafe_ingest.php` 의 `resolveMemberByKey` 만 새 게이트 추가 (현재는 `is_active=1` 만 체크)**.

| 파일:라인 | 현재 (leaving redefinition 후) | 변경 후 |
|----------|------------------------------|---------|
| `public_html/cron.php:89` (initDailyChecks) | `bm.member_status != 'refunded'` | `bm.member_status NOT IN ('refunded','expelled')` |
| `public_html/cron.php:158` (backfillChecks) | `bm.member_status != 'refunded'` | `bm.member_status NOT IN ('refunded','expelled')` |
| `public_html/api/services/attendance.php:21` | `member_status != 'refunded'` | `member_status NOT IN ('refunded','expelled')` |
| `public_html/api/services/review.php:32` | `in_array($status, ['refunded'])` | `in_array($status, ['refunded','expelled'])` |
| `public_html/api/services/member_page.php:402` (부티즈) | `bm.member_status != 'refunded'` | `bm.member_status NOT IN ('refunded','expelled')` |
| `public_html/api/admin.php:594` (operator member_list 기본) | `bm.member_status != 'refunded'` | `bm.member_status NOT IN ('refunded','expelled')` |
| `public_html/includes/qr_actions.php:28` | `member_status != 'refunded'` | `member_status NOT IN ('refunded','expelled')` |
| `public_html/api/qr.php:178, 258` | `member_status != 'refunded'` | `member_status NOT IN ('refunded','expelled')` |
| `public_html/includes/cafe/cafe_ingest.php` `resolveMemberByKey` | `WHERE cafe_member_key = ? AND is_active = 1` | `WHERE cafe_member_key = ? AND is_active = 1 AND member_status NOT IN ('refunded','expelled')` |
| `public_html/api/services/coin_reward_group.php:221` (INACTIVE_STATUSES) | `['refunded', 'leaving', 'out_of_group_management']` | `['refunded', 'leaving', 'out_of_group_management', 'expelled']` |

### 5.1 변경 안 함

- `public_html/auth.php` — 로그인 게이트. expelled 는 로그인 허용 (`is_active=1` 으로 자연 통과). 변경 불필요.
- `public_html/api/services/member_page.php:72, 181` — 본인 페이지 진입 (`is_active=1 OR member_status='leaving'`). expelled 도 본인 페이지 OK (`is_active=1` 자연 통과). 변경 불필요.
- `public_html/includes/bootcamp_functions.php:220-225` — 점수 자동 강등 (active ↔ OOM 전환). `WHERE member_status='active'` / `='out_of_group_management'` 매칭이라 expelled 회원에 절대 손대지 않음. 추가 가드 불필요.

## 6. 운영자 액션 — `handleMemberSetStatus` 확장

### 6.1 입력 검증 확장

현재 `public_html/api/services/member.php:155` 의 `handleMemberSetStatus` 는 `['active', 'leaving']` 만 허용. 다음으로 확장:

```php
if (!in_array($status, ['active', 'leaving', 'expelled'])) jsonError('유효하지 않은 상태입니다.');
```

`/api/admin.php:788` 의 같은 case 도 동일하게.

### 6.2 분기 로직

```php
if ($status === 'leaving') {
    $db->prepare("UPDATE bootcamp_members SET member_status='leaving', group_id=NULL WHERE id=?")
       ->execute([$id]);
} elseif ($status === 'expelled') {
    $db->prepare("UPDATE bootcamp_members SET member_status='expelled', group_id=NULL WHERE id=?")
       ->execute([$id]);
} else {
    // active 복원
    $db->prepare("UPDATE bootcamp_members SET member_status='active' WHERE id=?")
       ->execute([$id]);
}
```

`expelled → active` 복원도 같은 endpoint (status='active') 로 처리. UPDATE 는 `WHERE id=?` 만 가지므로 leaving/OOM/expelled 어디서든 active 로 변경 가능 (기존 동작 그대로).

### 6.3 감사 로그 — `admin_action_logs` 도입

현재 `handleMemberSetStatus` 는 어떤 로그도 안 남김. 이번 작업으로 expelled 의 모든 진입/이탈 + 김에 leaving 진입/이탈도 `admin_action_logs` 에 기록한다.

UPDATE 전후를 트랜잭션으로 묶고, UPDATE 직전에 기존 status 를 SELECT 로 캐치한 뒤 log INSERT:

```php
$db->beginTransaction();
try {
    $prev = $db->prepare("SELECT member_status FROM bootcamp_members WHERE id = ? FOR UPDATE");
    $prev->execute([$id]);
    $previousStatus = $prev->fetchColumn();
    if ($previousStatus === false) { $db->rollBack(); jsonError('회원을 찾을 수 없습니다.', 404); }

    // ...UPDATE 분기 (6.2)...

    $reason = trim((string)($input['reason'] ?? ''));
    $db->prepare("INSERT INTO admin_action_logs
        (actor_admin_id, action_type, target_table, target_id, payload_json)
        VALUES (?, 'member_status_change', 'bootcamp_members', ?, ?)")
       ->execute([
         $admin['id'],
         $id,
         json_encode(['from' => $previousStatus, 'to' => $status, 'reason' => $reason !== '' ? $reason : null],
                     JSON_UNESCAPED_UNICODE),
       ]);
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
}
```

`reason` 빈 문자열이면 payload 에 `null` 저장. `FOR UPDATE` 로 race condition 방지 (동시에 두 운영자가 같은 회원 status 변경 시).

### 6.4 사용 가능 권한

기존 `handleMemberSetStatus` 는 `requireAdmin(['operation', 'coach', 'head', 'subhead1', 'subhead2'])` 허용. expelled 도 동일 권한 (별도 권한 분리 안 함).

## 7. 운영자 UI

### 7.1 회원 카드 버튼

`/root/boot-dev/public_html/js/memberTable.js` (`render()` 함수의 status 액션 영역) — active/OOM 상태:

```html
<button onclick="...AdminApp._setMemberStatus(${m.id}, 'leaving', '${m.nickname}')">조에서 빼기</button>
<button onclick="...AdminApp._setMemberStatusExpel(${m.id}, '${m.nickname}')">내보내기</button>
```

`expelled` 상태:

```html
<button onclick="...AdminApp._setMemberStatus(${m.id}, 'active', '${m.nickname}')">복원</button>
```

`leaving` 상태 (직전 redefinition 으로 도입된 "조에서 빼기" 결과):

```html
<button onclick="...AdminApp._setMemberStatus(${m.id}, 'active', '${m.nickname}')">조에 복귀</button>
```

### 7.2 confirm + 사유 입력

`_setMemberStatusExpel(id, nickname)` 신규 JS 함수. confirm 후 prompt 로 사유 받기:

```js
_setMemberStatusExpel(id, nickname) {
    if (!confirm(`${nickname} 회원을 내보내시겠습니까?\n이후 단체활동(zoom/카페/점수/후기/부티즈)에서 모두 빠집니다.`)) return;
    const reason = prompt('사유 (선택, 빈칸 가능):', '') || '';
    return App.post('/api/bootcamp.php?action=member_set_status',
                    { id, status: 'expelled', reason });
}
```

서버에서 `$input['reason']` 을 받아 6.3 의 admin_action_logs payload 에 저장.

복원은 confirm 만:

```js
// (활성 복원 경로의 confirm 메시지 추가)
if (!confirm(`${nickname} 회원을 '활성' 상태로 복원하시겠습니까?`)) return;
```

### 7.3 라벨 (이번 redefinition 패턴 따라)

| 컨텍스트 | 새 라벨 |
|----------|---------|
| 배지 (작은) | `<span class="badge badge-danger-solid">퇴출</span>` 또는 `badge-warning-solid` (회색/주황 톤) |
| 버튼 (verb) | `내보내기` (active/OOM → expelled), `복원` (expelled → active) |
| 명사 (목록 라벨) | `퇴출 회원` |
| 운영자 footer | `환불 N, 퇴출 M 미포함` (refundedN/expelledN 둘 다 표시) |
| 체크박스 라벨 | `환불·퇴출 회원 포함` (현재 `환불 회원 포함` 에서 확장) |

UI 라벨 변경 site 목록 (직전 redefinition 의 9곳 패턴을 따라):

| 파일:라인 | 추가/변경 |
|----------|----------|
| `public_html/api/services/member.php:173` | leaving/expelled 분기 라벨 |
| `public_html/api/admin.php:807` | leaving/expelled 분기 라벨 |
| `public_html/js/memberTable.js:41` | expelled 배지 |
| `public_html/js/memberTable.js:155` (배지 영역) | "내보내기" 버튼 + "복원" 버튼 추가 |
| `public_html/js/admin.js:1116-1122` (inactiveExtra) | `if (expelledN) inactiveExtra.push(\`퇴출 ${expelledN}\`)` 추가 + `sc.expelled` 파싱 |
| `public_html/js/admin.js:1132` (체크박스 라벨) | `환불 회원 포함` → `환불·퇴출 회원 포함` |
| `public_html/js/admin.js:1375` | leaving/expelled 분기 라벨 |
| `public_html/js/bootcamp.js:1672` | leaving/expelled 분기 라벨 |
| `public_html/js/bootcamp.js:2287` | leaving 배지 옆에 expelled 배지 |
| `public_html/api/admin.php:628` (`$statusCounts` 초기화) | `'expelled' => 0` 추가 |

### 7.4 운영자 기본 필터 (admin.php:594)

직전 redefinition 으로 `bm.member_status != 'refunded'` 가 됐는데, 이번에 `expelled` 도 기본 숨김:

```php
if (!$includeInactive) {
    $where[] = "bm.member_status NOT IN ('refunded', 'expelled')";
}
```

`include_inactive` 체크박스 ON → 모든 상태 표시.

## 8. 동작 시나리오

### 8.1 active 회원 → 운영자가 "내보내기" 클릭

1. confirm + 사유 prompt
2. POST `/api/bootcamp.php?action=member_set_status` `{id, status: 'expelled', reason}`
3. `handleMemberSetStatus()`:
   - 변경 전 status 캐치
   - UPDATE `member_status='expelled', group_id=NULL`
   - INSERT `admin_action_logs` (action_type='member_status_change', payload={from, to: 'expelled', reason})
4. 다음 cron 부터 inbox 생성 차단, 출석률 분모 제외, 부티즈 목록 제외
5. 회원이 QR 스캔하면 `qr_actions.php:28` 게이트가 거부
6. 카페 게시물은 `resolveMemberByKey` 가 NULL 반환 → ingest 스킵
7. 후기 작성 페이지: `evaluateReviewEligibility` 가 `eligible=false`

### 8.2 OOM 회원 → 운영자가 "내보내기" 클릭

- 동일. OOM → expelled 직행. 점수가 아무리 변해도 expelled 는 자동 복원 X.

### 8.3 운영자가 "복원" 클릭

1. confirm
2. POST `{id, status: 'active'}` (sans reason)
3. UPDATE `member_status='active'` (group_id 유지 = NULL, 운영자가 별도로 조 배정 필요)
4. INSERT `admin_action_logs` (action_type='member_status_change', payload={from: 'expelled', to: 'active'})

### 8.4 expelled 회원의 자기 페이지 접근

- 로그인 OK (`is_active=1`)
- `/member.php` OK (member_page.php:72 의 `is_active=1` 으로 자연 통과)
- 점수/코인/이력 화면 정상 표시 (단, 신규 적립은 없음)
- 후기 작성 시도 → 권한 거부 메시지

## 9. 테스트 / 회귀 가드

`tests/expelled_*_invariants.php` 5개:

1. **`expelled_cron_invariants.php`** — `init_daily_checks` SELECT 가 expelled 를 제외하는지. fixture: active / leaving / OOM / refunded(is_active=0) / refunded(is_active=1) / **expelled(is_active=1)** 6명. expected 결과: active + leaving + OOM 만. (refunded × 2, expelled 제외)
2. **`expelled_qr_scan_invariants.php`** — expelled 회원의 QR 스캔 → `qrRecordAttendance` 가 `ok=false` + `error='inactive'` (또는 동등) 반환. `member_mission_checks` INSERT 안 됨.
3. **`expelled_cafe_ingest_invariants.php`** — expelled 회원의 cafe_member_key 매핑이 있어도 `resolveMemberByKey` 가 NULL → ingest 가 row 무시. `member_mission_checks` INSERT 안 됨.
4. **`expelled_review_invariants.php`** — expelled 회원 `evaluateReviewEligibility` 가 `eligible=false`, `reason='member_inactive'`. PHP-level blocklist `['refunded','expelled']` invariant.
5. **`expelled_bootees_invariants.php`** — `handleMemberBootees` SELECT 가 expelled 를 제외.

각 테스트는 boot 의 기존 `tests/*_invariants.php` 패턴 (CLI, transaction rollback, `t()` 헬퍼) 그대로.

추가로 `tests/leaving_*` 5개도 fixture 에 expelled 한 row 더 추가해서 "expelled 도 제외" 가드 강화 (regression — 새 enum 값 도입 후 이 테스트들 모두 통과 유지).

## 10. 배포 영향 / 롤백

- **DB 변경**: enum 확장 1개. PROD 데이터 0 영향 (expelled 회원 0명).
- **즉시 영향**: 0. expelled 0명이라 게이트 변경이 아무도 가리지 않음. 운영자가 처음 "내보내기" 클릭한 시점부터 효과 발생.
- **PROD 반영 순서**: 직전 leaving redefinition 을 먼저 따로 PROD 반영 → 안정화 (출석률 분모 변동 등 운영자 확인) → 이번 expelled 작업 PROD 반영.
- **롤백**:
  1. 코드 revert
  2. `bootcamp_members` 의 expelled 회원이 있으면 모두 `active` 또는 다른 status 로 UPDATE
  3. enum reverse: `ENUM('active','leaving','out_of_group_management','refunded')`

## 11. 후속 작업 (이번 범위 밖)

- 자동 expulsion (점수 임계값 / OOM 장기화 기반 cron) — 별도 plan
- expelled 사유의 구조화 (자유 텍스트 → enum/태그) — 별도 plan
- expelled 회원에게 자동 카카오톡 통보 — 별도 plan
- 점수/코인 회수 정책 — 별도 plan
