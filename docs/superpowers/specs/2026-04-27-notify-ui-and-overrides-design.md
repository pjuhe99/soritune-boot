# 알림톡 운영 UI 톤 정리 + 1회용 정책 우회

## 문제

알림톡 시스템(`2026-04-23-notify-alimtalk-design.md`)이 PROD에 배포되어 동작 중이지만 두 가지 운영 불편함이 있다:

1. **시나리오 카드의 액션 버튼들이 raw `<button>`이라 boot 운영 페이지(AdminReviews 등)의 톤과 어긋남.** components.css에 이미 `.btn .btn-primary/secondary/ghost/danger/sm` 토큰이 정의되어 있는데 알림톡 영역에서만 안 쓰고 있음.

2. **쿨다운/최대횟수 정책을 우회할 방법이 없다.** 두 가지 운영 케이스가 발생:
   - **케이스 A — "한 명한테만 다시 보내야 함":** 1회용 우회(이번 수동 발송에만 적용). 자동 스케줄에는 절대 새지 않아야 함.
   - **케이스 B — "완료될 때까지 매일 발송":** 시나리오 자체의 정책 (영구). 운영 토글이 아닌 시나리오 정의에서 끄는 게 의미상 맞음.

## 결정 요약

| 항목 | 결정 |
|---|---|
| 영구 무제한 (B) | 시나리오 PHP에서 `cooldown_hours <= 0` / `max_attempts <= 0` 이면 dispatcher가 해당 가드 자체를 건너뜀. UI 변경 없음. |
| 1회용 우회 (A) | 미리보기 모달에 체크박스 2개 (쿨다운 무시 / 최대횟수 무시), 분리. 수동 발송에서만 사용 가능. |
| 우회 적용 시 미리보기 동작 | 체크 즉시 `notify_preview` 재호출 → 후보/스킵 카운트와 표가 갱신. "화면 = 진실" 원칙. |
| 자동 트리거에서 우회 | 절대 불가 — `schedule` / `retry` 트리거에서 `notifyRunScenario()` 호출 시 bypass 두 인자 모두 false 강제. |
| 감사 기록 | `notify_batch`에 `bypass_cooldown`, `bypass_max_attempts` 컬럼 추가. 이력 화면 트리거 옆에 ⚠ 표시. |
| 헷갈림 방지 안내 | 시나리오 헤더에 1줄 안내, 모달 체크박스 옆에 의미 설명, 우회 활성 시 confirm 버튼 라벨에 "⚠ 쿨다운 우회" 표시. |
| 버튼 톤 | components.css `.btn .btn-*` 클래스 적용. 지금 발송=danger, DRY=secondary, 이력/상세/새로고침=ghost or secondary, 취소=secondary. 모두 `btn-sm`. |
| 카드 톤 | `.notify-row` 헤더-메타 간격, 영역 구분선, hover 살짝 정리. |

## 데이터 모델 변경

### 마이그레이션 (DEV/PROD 둘 다)

`migrate_notify_bypass_columns.php` 신규 작성 (저장소 루트, 기존 `migrate_*.php` 컨벤션 따름).

```sql
ALTER TABLE notify_batch
  ADD COLUMN bypass_cooldown TINYINT(1) NOT NULL DEFAULT 0 AFTER dry_run,
  ADD COLUMN bypass_max_attempts TINYINT(1) NOT NULL DEFAULT 0 AFTER bypass_cooldown;

ALTER TABLE notify_preview
  ADD COLUMN bypass_cooldown TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN bypass_max_attempts TINYINT(1) NOT NULL DEFAULT 0;
```

기존 행은 자동으로 `0,0`으로 채워진다 (소급 영향 없음).

### 트리거별 가능 값
| `trigger_type` | `bypass_cooldown` | `bypass_max_attempts` | 비고 |
|---|---|---|---|
| `schedule` | 항상 0 | 항상 0 | 자동 발송, 우회 불가 |
| `manual` | 0 또는 1 | 0 또는 1 | 운영자가 모달에서 선택 |
| `retry` | 항상 0 | 항상 0 | 실패자 재시도는 정책 따름 |

## Dispatcher 변경 (`includes/notify/dispatcher.php`)

### `notifyRunScenario()` 시그니처 확장

```php
function notifyRunScenario(
    PDO $db,
    array $def,
    string $trigger,
    ?string $triggeredBy = null,
    ?bool $dryRun = null,
    ?array $rowKeysFilter = null,
    bool $bypassCooldown = false,        // 신규
    bool $bypassMaxAttempts = false      // 신규
): ?int
```

호출자 책임:
- `notifyDispatch()` (스케줄): bypass 두 인자 전달 안 함 → 기본값 false 유지
- `notify_send_now` API (수동): preview에 저장된 bypass 값 그대로 전달
- `notify_retry_failed` API (재시도): bypass 두 인자 전달 안 함

### 가드 로직 변경

쿨다운 체크:
```php
// 기존
$cd = $db->prepare("SELECT MAX(processed_at) FROM ... INTERVAL ? HOUR");
$cd->execute([$key, $phoneNorm, (int)$def['cooldown_hours']]);
if ($cd->fetchColumn()) { /* skip cooldown */ }

// 변경 후
$cooldownHours = (int)$def['cooldown_hours'];
if (!$bypassCooldown && $cooldownHours > 0) {
    $cd = $db->prepare("SELECT MAX(processed_at) FROM ... INTERVAL ? HOUR");
    $cd->execute([$key, $phoneNorm, $cooldownHours]);
    if ($cd->fetchColumn()) { /* skip cooldown */ }
}
```

최대횟수 체크:
```php
// 변경 후
$maxAttempts = (int)$def['max_attempts'];
if (!$bypassMaxAttempts && $maxAttempts > 0) {
    $mx = $db->prepare("SELECT COUNT(*) FROM ... AND status IN ('sent','unknown')");
    $mx->execute([$key, $phoneNorm]);
    if ((int)$mx->fetchColumn() >= $maxAttempts) { /* skip max_attempts */ }
}
```

→ 시나리오에서 `cooldown_hours => 0` 이면 가드 자체가 건너뛰어짐 (매번 발송 후보), `max_attempts => 0` 이면 횟수 무제한.

### 배치 INSERT

```php
$ins = $db->prepare("
    INSERT INTO notify_batch
      (scenario_key, trigger_type, triggered_by, started_at,
       dry_run, bypass_cooldown, bypass_max_attempts,
       status, target_count)
    VALUES (?, ?, ?, NOW(), ?, ?, ?, 'running', 0)
");
$ins->execute([
    $key, $trigger, $triggeredBy,
    (int)$dryRun, (int)$bypassCooldown, (int)$bypassMaxAttempts,
]);
```

## API 변경 (`api/services/notify.php`)

### `notify_preview` 입력
- `bypass_cooldown` (bool, 기본 false)
- `bypass_max_attempts` (bool, 기본 false)

→ preview 결과의 `skips`에서 `reason='cooldown'`/`'max_attempts'` 항목이 사라지고 `candidates`에 흡수됨. `phone_invalid` skip은 그대로 유지.

### `notify_preview` 응답 추가 필드
- `bypass_cooldown` (echo back)
- `bypass_max_attempts` (echo back)

### `notify_preview_id` 저장 시
preview 레코드(notify_preview 테이블)에 두 bypass 값 함께 저장 → send_now 시점에 일관성 보장. (마이그레이션은 위 데이터 모델 섹션에서 함께 처리)

### `notify_send_now` 동작
preview row에서 두 bypass 값 읽어 `notifyRunScenario(..., bypassCooldown: $b1, bypassMaxAttempts: $b2)` 호출.

### `notify_list_batches` 응답
배치 행에 `bypass_cooldown`, `bypass_max_attempts` 두 필드 추가.

### `notify_retry_failed` 동작
변경 없음 (bypass 두 인자 전달 안 함, 항상 false).

## UI 변경

### `notify.js`

**1) 시나리오 카드 헤더 안내 줄 추가** (`render()` 안):
- `.notify-header` 아래, `.notify-row` 들 위에 1줄 안내:
  > "수동 발송 시 미리보기 모달에서 쿨다운/최대횟수를 일시적으로 우회할 수 있습니다. 자동 스케줄에는 적용되지 않습니다."
- 클래스: `.notify-help` (회색 작은 글씨)

**2) 미리보기 모달 (`showPreviewModal`) 변경:**
- 대상 표 위에 체크박스 2개 영역 추가 (`.notify-bypass`):
  ```
  [ ] 쿨다운 무시  ← 마지막 발송 후 쿨다운 시간 안의 사람도 후보에 포함
  [ ] 최대횟수 무시 ← 누적 발송 횟수가 한도에 도달한 사람도 후보에 포함
  ```
- 체크 변경 시: 모달은 그대로 두고 `notify_preview` 재호출 → 응답으로 `target_count`, `skip_count`, `candidates`, `skips`, `rendered_first`만 갱신 (preview_id는 새로 받아 교체).
- confirm 버튼 라벨:
  - 평소: `"N명에게 지금 발송"` / `"N명에게 DRY 발송"`
  - 우회 활성 시: 선두에 `"⚠ 쿨다운 우회 — "` 또는 `"⚠ 최대 우회 — "` 또는 `"⚠ 정책 우회 — "` (둘 다 켜진 경우)

**3) 시나리오 카드 + 모달 버튼에 클래스 적용:**

| 버튼 | 클래스 |
|---|---|
| 지금 발송 | `btn btn-danger btn-sm` |
| DRY 발송 | `btn btn-secondary btn-sm` |
| 이력 | `btn btn-ghost btn-sm` |
| 상세 (배치 표 행) | `btn btn-ghost btn-sm` |
| 새로고침 (헤더) | `btn btn-secondary btn-sm` (이미 있음, 유지) |
| 취소 (모달) | `btn btn-secondary` |
| confirm — DRY | `btn btn-secondary` |
| confirm — 실 발송 | `btn btn-danger` |
| 실패자 재시도 | `btn btn-secondary btn-sm` |

**4) 이력 표 trigger 컬럼:**
- `bypass_cooldown==1` 또는 `bypass_max_attempts==1` 이면 trigger 텍스트 뒤에 `<span class="bypass-warn" title="...">⚠</span>` 추가
- title은 우회 종류 안내: `"쿨다운 우회"`, `"최대 우회"`, `"정책 우회 (쿨다운+최대)"`

### `notify.css`

**1) 안내 텍스트:**
```css
.notify-help { color: var(--color-text-sub); font-size: var(--text-sm); margin-bottom: 12px; }
```

**2) 카드 톤 정리:**
- `.notify-row` hover 시 살짝 들뜨기 (box-shadow 미세)
- `.notify-row-head` 행 안 정렬 미세 조정
- `.notify-row-meta`와 `.notify-row-batches` 사이 구분선 (`border-top: 1px dashed`)

**3) 모달 우회 영역:**
```css
.notify-bypass { margin: 12px 0; padding: 10px; background: var(--color-bg-subtle); border-radius: 6px; }
.notify-bypass label { display: block; font-size: var(--text-sm); margin: 4px 0; cursor: pointer; }
.notify-bypass small { color: var(--color-text-sub); }
```

**4) 우회 ⚠ 마크:**
```css
.bypass-warn { margin-left: 6px; color: var(--color-danger); font-size: 0.9em; cursor: help; }
```

기존 `.status` / `.env-*` 등은 유지.

## 데이터 흐름 (수동 발송, 우회 사용 시)

```
1. 운영자가 시나리오 카드 "지금 발송" 클릭
   → notify_preview(key, dry_run=false, bypass_cooldown=false, bypass_max_attempts=false)
   → 모달 오픈, 후보 N명 / 스킵 M명 표시

2. 운영자가 "쿨다운 무시" 체크
   → notify_preview(key, dry_run=false, bypass_cooldown=true, bypass_max_attempts=false)
   → 응답으로 모달의 후보/스킵 카운트, 표, preview_id 갱신
   → confirm 버튼 라벨 "⚠ 쿨다운 우회 — N+α명에게 지금 발송"

3. 운영자가 confirm 클릭
   → notify_send_now(preview_id)
   → 서버: preview row에서 bypass 두 값 읽어 notifyRunScenario(..., bypassCooldown=true, bypassMaxAttempts=false)
   → notify_batch INSERT 시 bypass_cooldown=1 기록

4. 이력 화면
   → trigger 컬럼에 "manual ⚠" 표시 (tooltip "쿨다운 우회")
```

## 에러 처리

- `bypass_cooldown` / `bypass_max_attempts` 가 boolean이 아닌 값으로 들어오면 PHP에서 `(bool)` 캐스트 (느슨하게 처리)
- 시나리오 PHP에 `cooldown_hours` 또는 `max_attempts` 키 자체가 없으면 `(int)null = 0` → 무제한으로 동작 (기존 시나리오 호환성: 모든 시나리오 PHP에 두 키가 정의되어 있으므로 영향 없음)
- preview row가 만료/삭제된 상태에서 send_now 호출 시 → 기존 에러 처리 그대로 ("발송 가능 시간이 만료되었습니다" 등)

## 테스트 (`test_notify.php` 추가)

dispatcher 단위 테스트:
- `cooldown_hours=0`, bypass=false → 쿨다운 가드 통과 (skip 없음)
- `max_attempts=0`, bypass=false → 최대횟수 가드 통과 (skip 없음)
- `cooldown_hours=24`, `bypassCooldown=true` → 24시간 안에 sent 기록 있어도 발송됨
- `max_attempts=3`, `bypassMaxAttempts=true` → 4번째 발송도 허용됨
- 두 bypass 모두 true + 두 정책 모두 활성 → 모두 발송됨
- batch INSERT 후 `bypass_cooldown`, `bypass_max_attempts` 컬럼 값 검증

API 테스트(테스트 환경 가능 범위):
- `notify_preview` 에 bypass 파라미터 전달 시 응답에 echo back 됨
- preview row에 bypass 값 저장됨

## 마이그레이션 / 배포 절차

1. 저장소 루트에 `migrate_notify_bypass_columns.php` 작성 (기존 `migrate_*.php` 컨벤션) — `.db_credentials`로 DB 연결, 위 ALTER 4개를 실행, 멱등 보장(컬럼 존재 여부 확인 후 ADD).
2. DEV 머신에서 마이그레이션 실행 (DEV DB)
3. 코드 변경 dev 푸시 + 사용자 검증
4. 사용자 명시 승인 후 main 머지 + PROD pull
5. PROD 머신에서 마이그레이션 실행 (PROD DB)

## 범위 외

- 시나리오 PHP의 `cooldown_hours` / `max_attempts` 를 UI에서 변경하는 화면은 만들지 않음 — 코드 수정으로 처리 (영구 정책 변경은 코드 리뷰가 적절하다는 판단)
- 자동 스케줄에서 일시 우회는 막음 — 자동 발송에 우회를 풀어두면 도배 위험이 너무 큼
- 재시도 트리거(`notify_retry_failed`)는 정책 따름 — 실패자 재시도가 도배 트리거가 되지 않도록 보수적으로 운영
- 우회 사용 권한 분리 안 함 — 알림톡 운영 자체가 operation/head 권한 안에서 이미 제한되므로 추가 권한 분리는 과함
