# 알림톡 UI 톤 정리 + 1회용 정책 우회 — 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 알림톡 운영 화면 버튼/카드 톤을 boot 운영 페이지(AdminReviews 등)와 통일하고, 미리보기 모달에서 쿨다운/최대횟수 정책을 1회만 우회할 수 있는 체크박스를 추가한다. 동시에 dispatcher가 시나리오 PHP의 `<= 0` 값을 "무제한"으로 인식하도록 가드를 정비한다.

**Architecture:** dispatcher의 `notifyRunScenario()`에 `$bypassCooldown`/`$bypassMaxAttempts` 두 인자 추가 + 가드를 if 블록으로 감싸 `> 0` 또는 bypass 미사용일 때만 검사. preview/send_now API가 두 값을 전달·저장. UI는 모달의 체크박스 즉시 → preview 재호출 → 후보 갱신. notify_batch에 bypass 컬럼 추가하여 감사 추적.

**Tech Stack:** PHP 8.x, MariaDB, vanilla JS, components.css 토큰 (`.btn .btn-*`).

**Spec:** `docs/superpowers/specs/2026-04-27-notify-ui-and-overrides-design.md`

**작업 디렉토리:** `/root/boot-dev` (dev 브랜치, 라이브 DEV 서버 심볼릭 링크 — worktree 사용 불가)

**진행 방식:** Subagent-Driven Development. task마다 general-purpose implementer → spec compliance 리뷰 → code-reviewer quality 리뷰 → 선별적 fixup.

---

## Task 1: 마이그레이션 작성 + DEV 실행

**Files:**
- Create: `/root/boot-dev/migrate_notify_bypass_columns.php`

기존 `migrate_notify_tables.php` 패턴 따라 멱등(`INFORMATION_SCHEMA.COLUMNS` 검사)으로 작성.

- [ ] **Step 1: 마이그레이션 파일 작성**

```php
<?php
/**
 * boot.soritune.com - notify_batch / notify_preview 에 bypass 컬럼 추가
 * 사용: php migrate_notify_bypass_columns.php
 * DEV/PROD 각각 실행. 멱등(컬럼 존재 시 skip).
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/public_html/config.php';

$db = getDB();
$dbName = $db->query("SELECT DATABASE()")->fetchColumn();

function columnExists(PDO $db, string $dbName, string $table, string $col): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$dbName, $table, $col]);
    return (bool)$stmt->fetchColumn();
}

$alters = [
    ['notify_batch',   'bypass_cooldown',     "ALTER TABLE notify_batch   ADD COLUMN bypass_cooldown     TINYINT(1) NOT NULL DEFAULT 0 AFTER dry_run"],
    ['notify_batch',   'bypass_max_attempts', "ALTER TABLE notify_batch   ADD COLUMN bypass_max_attempts TINYINT(1) NOT NULL DEFAULT 0 AFTER bypass_cooldown"],
    ['notify_preview', 'bypass_cooldown',     "ALTER TABLE notify_preview ADD COLUMN bypass_cooldown     TINYINT(1) NOT NULL DEFAULT 0"],
    ['notify_preview', 'bypass_max_attempts', "ALTER TABLE notify_preview ADD COLUMN bypass_max_attempts TINYINT(1) NOT NULL DEFAULT 0"],
];

foreach ($alters as [$table, $col, $sql]) {
    if (columnExists($db, $dbName, $table, $col)) {
        echo "SKIP  {$table}.{$col} (이미 존재)\n";
        continue;
    }
    $db->exec($sql);
    echo "ADD   {$table}.{$col}\n";
}

echo "마이그레이션 완료 (DB: {$dbName})\n";
```

- [ ] **Step 2: DEV 마이그레이션 실행**

Run: `cd /root/boot-dev && php migrate_notify_bypass_columns.php`
Expected output: 4줄의 `ADD` (또는 멱등 재실행 시 `SKIP`).

- [ ] **Step 3: 컬럼 추가 검증**

Run:
```bash
source /root/boot-dev/.db_credentials && \
mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "DESCRIBE notify_batch;" | grep -E "bypass_(cooldown|max_attempts)"
mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "DESCRIBE notify_preview;" | grep -E "bypass_(cooldown|max_attempts)"
```
Expected: 4 줄 (각 테이블에 2개씩, `tinyint(1) NO  0`).

- [ ] **Step 4: 멱등성 재실행 확인**

Run: `cd /root/boot-dev && php migrate_notify_bypass_columns.php`
Expected: 4줄의 `SKIP`.

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev
git add migrate_notify_bypass_columns.php
git commit -m "$(cat <<'EOF'
feat(notify): bypass 컬럼 마이그레이션 (notify_batch/notify_preview)

미리보기 1회용 정책 우회와 감사 추적을 위해 bypass_cooldown,
bypass_max_attempts 추가. 멱등 보장 (컬럼 존재 시 skip).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: dispatcher.php — 시그니처 + 가드 + INSERT 변경

**Files:**
- Modify: `/root/boot-dev/public_html/includes/notify/dispatcher.php`

bypass 두 인자 추가 + 가드 if 블록 + INSERT 컬럼 2개 추가.

- [ ] **Step 1: `notifyRunScenario()` 시그니처 확장**

`/root/boot-dev/public_html/includes/notify/dispatcher.php` L63-70 부근:

기존:
```php
function notifyRunScenario(
    PDO $db,
    array $def,
    string $trigger,
    ?string $triggeredBy = null,
    ?bool $dryRun = null,
    ?array $rowKeysFilter = null
): ?int {
```

변경 후:
```php
function notifyRunScenario(
    PDO $db,
    array $def,
    string $trigger,
    ?string $triggeredBy = null,
    ?bool $dryRun = null,
    ?array $rowKeysFilter = null,
    bool $bypassCooldown = false,
    bool $bypassMaxAttempts = false
): ?int {
```

- [ ] **Step 2: INSERT INTO notify_batch 에 bypass 컬럼 추가**

L92-98 부근, 기존:
```php
$ins = $db->prepare("
    INSERT INTO notify_batch
      (scenario_key, trigger_type, triggered_by, started_at, dry_run, status, target_count)
    VALUES (?, ?, ?, NOW(), ?, 'running', 0)
");
$ins->execute([$key, $trigger, $triggeredBy, (int)$dryRun]);
```

변경 후:
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

- [ ] **Step 3: 쿨다운 가드를 if 블록으로 감싸기**

L161-177 부근, 기존:
```php
            // 쿨다운 (sent + unknown)
            $cd = $db->prepare("
                SELECT MAX(processed_at) FROM notify_message
                 WHERE scenario_key = ? AND phone = ?
                   AND status IN ('sent','unknown')
                   AND processed_at >= NOW() - INTERVAL ? HOUR
            ");
            $cd->execute([$key, $phoneNorm, (int)$def['cooldown_hours']]);
            if ($cd->fetchColumn()) {
                $insMsg->execute([
                    $batchId, $key, $row['row_key'], $phoneNorm,
                    $row['name'] ?? null, $templateId, $renderedText,
                    'skipped', 'cooldown', date('Y-m-d H:i:s'),
                ]);
                $skipped++;
                continue;
            }
```

변경 후:
```php
            // 쿨다운 (sent + unknown). bypass=true 이거나 cooldown_hours<=0 이면 가드 자체를 건너뜀.
            $cooldownHours = (int)($def['cooldown_hours'] ?? 0);
            if (!$bypassCooldown && $cooldownHours > 0) {
                $cd = $db->prepare("
                    SELECT MAX(processed_at) FROM notify_message
                     WHERE scenario_key = ? AND phone = ?
                       AND status IN ('sent','unknown')
                       AND processed_at >= NOW() - INTERVAL ? HOUR
                ");
                $cd->execute([$key, $phoneNorm, $cooldownHours]);
                if ($cd->fetchColumn()) {
                    $insMsg->execute([
                        $batchId, $key, $row['row_key'], $phoneNorm,
                        $row['name'] ?? null, $templateId, $renderedText,
                        'skipped', 'cooldown', date('Y-m-d H:i:s'),
                    ]);
                    $skipped++;
                    continue;
                }
            }
```

- [ ] **Step 4: 최대횟수 가드를 if 블록으로 감싸기**

L179-193 부근, 기존:
```php
            // 최대횟수 (sent + unknown 만 카운트)
            $mx = $db->prepare("
                SELECT COUNT(*) FROM notify_message
                 WHERE scenario_key = ? AND phone = ? AND status IN ('sent','unknown')
            ");
            $mx->execute([$key, $phoneNorm]);
            if ((int)$mx->fetchColumn() >= (int)$def['max_attempts']) {
                $insMsg->execute([
                    $batchId, $key, $row['row_key'], $phoneNorm,
                    $row['name'] ?? null, $templateId, $renderedText,
                    'skipped', 'max_attempts', date('Y-m-d H:i:s'),
                ]);
                $skipped++;
                continue;
            }
```

변경 후:
```php
            // 최대횟수 (sent + unknown 만 카운트). bypass=true 이거나 max_attempts<=0 이면 가드 건너뜀.
            $maxAttempts = (int)($def['max_attempts'] ?? 0);
            if (!$bypassMaxAttempts && $maxAttempts > 0) {
                $mx = $db->prepare("
                    SELECT COUNT(*) FROM notify_message
                     WHERE scenario_key = ? AND phone = ? AND status IN ('sent','unknown')
                ");
                $mx->execute([$key, $phoneNorm]);
                if ((int)$mx->fetchColumn() >= $maxAttempts) {
                    $insMsg->execute([
                        $batchId, $key, $row['row_key'], $phoneNorm,
                        $row['name'] ?? null, $templateId, $renderedText,
                        'skipped', 'max_attempts', date('Y-m-d H:i:s'),
                    ]);
                    $skipped++;
                    continue;
                }
            }
```

- [ ] **Step 5: PHP 문법 검사**

Run: `php -l /root/boot-dev/public_html/includes/notify/dispatcher.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev
git add public_html/includes/notify/dispatcher.php
git commit -m "$(cat <<'EOF'
feat(notify): dispatcher에 bypass 두 인자 + cooldown_hours/max_attempts<=0 무제한 인식

- notifyRunScenario(): bypassCooldown, bypassMaxAttempts 인자 추가 (기본 false)
- 쿨다운/최대횟수 가드를 if 블록으로 감싸 bypass=true 또는 값<=0 일 때
  가드 자체를 건너뜀
- notify_batch INSERT 시 두 bypass 컬럼 기록

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: dispatcher 가드 단위 테스트 (`test_notify.php`)

**Files:**
- Modify: `/root/boot-dev/test_notify.php`

dispatcher의 가드 분기 결정만 떼어낸 순수 함수를 추가하고 단위 테스트를 붙인다(실 DB·실 SQL 없이 로직만 검증).

- [ ] **Step 1: dispatcher에 헬퍼 함수 추가**

`/root/boot-dev/public_html/includes/notify/dispatcher.php` 파일 맨 끝에 추가:

```php
/**
 * 쿨다운 가드를 적용해야 하는지 판단.
 * - bypass=true → 적용 안 함
 * - cooldown_hours <= 0 → 적용 안 함 (무제한 발송 시나리오)
 * - 그 외 → 적용
 */
function notifyShouldCheckCooldown(int $cooldownHours, bool $bypass): bool {
    if ($bypass) return false;
    return $cooldownHours > 0;
}

/**
 * 최대횟수 가드를 적용해야 하는지 판단.
 */
function notifyShouldCheckMaxAttempts(int $maxAttempts, bool $bypass): bool {
    if ($bypass) return false;
    return $maxAttempts > 0;
}
```

- [ ] **Step 2: dispatcher의 가드 분기에서 헬퍼 사용하도록 변경**

Task 2에서 작성한 가드 코드 두 군데를 다음과 같이 헬퍼 호출로 통일:

쿨다운(L161 부근):
```php
            $cooldownHours = (int)($def['cooldown_hours'] ?? 0);
            if (notifyShouldCheckCooldown($cooldownHours, $bypassCooldown)) {
                // 기존 SELECT MAX(processed_at) ... 체크 본문
            }
```

최대횟수(L179 부근):
```php
            $maxAttempts = (int)($def['max_attempts'] ?? 0);
            if (notifyShouldCheckMaxAttempts($maxAttempts, $bypassMaxAttempts)) {
                // 기존 SELECT COUNT ... 체크 본문
            }
```

- [ ] **Step 3: test_notify.php 에 단위 테스트 추가**

`/root/boot-dev/test_notify.php` 파일 끝(요약 출력 전)에 추가. 기존 require 두 줄(`config.php`, `notify_functions.php`) 다음에 dispatcher 한 줄도 require 필요하니, require 블록도 함께 보강:

기존 상단 require 블록:
```php
require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/includes/notify/notify_functions.php';
```

변경 후:
```php
require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/includes/notify/notify_functions.php';
require_once __DIR__ . '/public_html/includes/notify/dispatcher.php';
```

요약 출력(`echo "{$pass} passed / {$fail} failed\n";` 류) 직전에 다음 블록 삽입:

```php
// ── 쿨다운 가드 분기 ──────────────────────────
t('cooldown: 평소(24h, bypass=false) → 검사함',  notifyShouldCheckCooldown(24, false) === true);
t('cooldown: 0h, bypass=false → 무제한, 검사 안 함', notifyShouldCheckCooldown(0,  false) === false);
t('cooldown: 음수, bypass=false → 검사 안 함',    notifyShouldCheckCooldown(-1, false) === false);
t('cooldown: 24h, bypass=true → 우회',           notifyShouldCheckCooldown(24, true)  === false);
t('cooldown: 0h, bypass=true → 어쨌든 우회',      notifyShouldCheckCooldown(0,  true)  === false);

// ── 최대횟수 가드 분기 ─────────────────────────
t('max_attempts: 평소(3, bypass=false) → 검사함',     notifyShouldCheckMaxAttempts(3,  false) === true);
t('max_attempts: 0, bypass=false → 무제한, 검사 안 함',  notifyShouldCheckMaxAttempts(0,  false) === false);
t('max_attempts: 음수, bypass=false → 검사 안 함',     notifyShouldCheckMaxAttempts(-1, false) === false);
t('max_attempts: 3, bypass=true → 우회',              notifyShouldCheckMaxAttempts(3,  true)  === false);
t('max_attempts: 0, bypass=true → 어쨌든 우회',         notifyShouldCheckMaxAttempts(0,  true)  === false);
```

- [ ] **Step 4: 테스트 실행 — 추가된 10개 모두 통과**

Run: `cd /root/boot-dev && php test_notify.php`
Expected: 기존 55 passed에 더해 10개 추가 (`65 passed / 0 failed`).

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev
git add public_html/includes/notify/dispatcher.php test_notify.php
git commit -m "$(cat <<'EOF'
test(notify): bypass + 무제한 가드 분기 단위 테스트 + 헬퍼 분리

notifyShouldCheckCooldown / notifyShouldCheckMaxAttempts 헬퍼로 가드
판단을 분리하고, dispatcher 가드도 헬퍼 호출로 통일. 10개 단위 테스트.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: API 변경 (`api/services/notify.php`)

**Files:**
- Modify: `/root/boot-dev/public_html/api/services/notify.php`

`handleNotifyPreview` / `handleNotifySendNow` / `handleNotifyListBatches` 세 곳 변경.

- [ ] **Step 1: handleNotifyPreview에 bypass 파라미터 받기**

`/root/boot-dev/public_html/api/services/notify.php` L79-91 부근, `$dryRun` 한 줄 다음에 두 줄 추가:

기존:
```php
    $dryRun = isset($input['dry_run']) ? (bool)$input['dry_run'] : (bool)($keys['dry_run_default'] ?? false);
```

변경 후:
```php
    $dryRun            = isset($input['dry_run']) ? (bool)$input['dry_run'] : (bool)($keys['dry_run_default'] ?? false);
    $bypassCooldown    = (bool)($input['bypass_cooldown']     ?? false);
    $bypassMaxAttempts = (bool)($input['bypass_max_attempts'] ?? false);
```

- [ ] **Step 2: handleNotifyPreview의 skip 분류에 bypass + `<= 0` 무제한 적용**

L99-126 부근의 candidates/skips 루프를 다음으로 교체:

기존:
```php
    $cd = $db->prepare("
        SELECT MAX(processed_at) FROM notify_message
         WHERE scenario_key = ? AND phone = ?
           AND status IN ('sent','unknown')
           AND processed_at >= NOW() - INTERVAL ? HOUR
    ");
    $mx = $db->prepare("
        SELECT COUNT(*) FROM notify_message
         WHERE scenario_key = ? AND phone = ? AND status IN ('sent','unknown')
    ");
    foreach ($rows as $row) {
        $phoneNorm = notifyNormalizePhone($row['phone'] ?? '');
        if ($phoneNorm === null) {
            $skips[] = $row + ['_skip' => 'phone_invalid'];
            continue;
        }
        $cd->execute([$key, $phoneNorm, (int)$def['cooldown_hours']]);
        if ($cd->fetchColumn()) { $skips[] = $row + ['_skip' => 'cooldown']; continue; }

        $mx->execute([$key, $phoneNorm]);
        if ((int)$mx->fetchColumn() >= (int)$def['max_attempts']) {
            $skips[] = $row + ['_skip' => 'max_attempts']; continue;
        }
        $candidates[] = $row + ['phone_norm' => $phoneNorm];
    }
```

변경 후:
```php
    $cd = $db->prepare("
        SELECT MAX(processed_at) FROM notify_message
         WHERE scenario_key = ? AND phone = ?
           AND status IN ('sent','unknown')
           AND processed_at >= NOW() - INTERVAL ? HOUR
    ");
    $mx = $db->prepare("
        SELECT COUNT(*) FROM notify_message
         WHERE scenario_key = ? AND phone = ? AND status IN ('sent','unknown')
    ");

    $cooldownHours = (int)($def['cooldown_hours'] ?? 0);
    $maxAttempts   = (int)($def['max_attempts']   ?? 0);
    $checkCd       = notifyShouldCheckCooldown($cooldownHours, $bypassCooldown);
    $checkMx       = notifyShouldCheckMaxAttempts($maxAttempts, $bypassMaxAttempts);

    foreach ($rows as $row) {
        $phoneNorm = notifyNormalizePhone($row['phone'] ?? '');
        if ($phoneNorm === null) {
            $skips[] = $row + ['_skip' => 'phone_invalid'];
            continue;
        }
        if ($checkCd) {
            $cd->execute([$key, $phoneNorm, $cooldownHours]);
            if ($cd->fetchColumn()) { $skips[] = $row + ['_skip' => 'cooldown']; continue; }
        }
        if ($checkMx) {
            $mx->execute([$key, $phoneNorm]);
            if ((int)$mx->fetchColumn() >= $maxAttempts) {
                $skips[] = $row + ['_skip' => 'max_attempts']; continue;
            }
        }
        $candidates[] = $row + ['phone_norm' => $phoneNorm];
    }
```

- [ ] **Step 3: handleNotifyPreview 의 INSERT INTO notify_preview 에 bypass 컬럼 저장**

L139-148 부근, 기존:
```php
    $db->prepare("
        INSERT INTO notify_preview
          (id, scenario_key, dry_run, row_keys, target_count, created_by, created_at, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL ? MINUTE)
    ")->execute([
        $previewId, $key, (int)$dryRun,
        json_encode($rowKeys, JSON_UNESCAPED_UNICODE),
        count($rowKeys), (string)$admin['admin_id'],
        NOTIFY_PREVIEW_TTL_MIN,
    ]);
```

변경 후:
```php
    $db->prepare("
        INSERT INTO notify_preview
          (id, scenario_key, dry_run, bypass_cooldown, bypass_max_attempts,
           row_keys, target_count, created_by, created_at, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL ? MINUTE)
    ")->execute([
        $previewId, $key, (int)$dryRun,
        (int)$bypassCooldown, (int)$bypassMaxAttempts,
        json_encode($rowKeys, JSON_UNESCAPED_UNICODE),
        count($rowKeys), (string)$admin['admin_id'],
        NOTIFY_PREVIEW_TTL_MIN,
    ]);
```

- [ ] **Step 4: handleNotifyPreview 응답에 bypass echo back**

L150-170 부근의 jsonSuccess 호출에 두 필드 추가:

기존:
```php
    jsonSuccess([
        'preview_id'     => $previewId,
        'expires_in_min' => NOTIFY_PREVIEW_TTL_MIN,
        'dry_run'        => (int)$dryRun,
        'environment'    => notifyEnvironmentLabel(),
        ...
```

변경 후:
```php
    jsonSuccess([
        'preview_id'           => $previewId,
        'expires_in_min'       => NOTIFY_PREVIEW_TTL_MIN,
        'dry_run'              => (int)$dryRun,
        'bypass_cooldown'      => (int)$bypassCooldown,
        'bypass_max_attempts'  => (int)$bypassMaxAttempts,
        'environment'          => notifyEnvironmentLabel(),
        ...
```
(나머지 필드는 그대로 유지)

- [ ] **Step 5: handleNotifySendNow가 preview 저장 값을 dispatcher에 전달**

L214-222 부근, 기존:
```php
        $batchId = notifyRunScenario(
            $db,
            $scenarios[$preview['scenario_key']],
            'manual',
            (string)$admin['admin_id'],
            (bool)$preview['dry_run'],
            $rowKeys
        );
```

변경 후:
```php
        $batchId = notifyRunScenario(
            $db,
            $scenarios[$preview['scenario_key']],
            'manual',
            (string)$admin['admin_id'],
            (bool)$preview['dry_run'],
            $rowKeys,
            (bool)($preview['bypass_cooldown']     ?? false),
            (bool)($preview['bypass_max_attempts'] ?? false)
        );
```

- [ ] **Step 6: handleNotifyListBatches SELECT 에 bypass 두 컬럼 추가**

L237-247 부근, 기존:
```php
    $stmt = $db->prepare("
        SELECT id, scenario_key, trigger_type, triggered_by, started_at, finished_at,
               target_count, sent_count, failed_count, unknown_count, skipped_count,
               dry_run, status, error_message
          FROM notify_batch
         WHERE scenario_key = ?
         ORDER BY started_at DESC
         LIMIT {$limit}
    ");
```

변경 후:
```php
    $stmt = $db->prepare("
        SELECT id, scenario_key, trigger_type, triggered_by, started_at, finished_at,
               target_count, sent_count, failed_count, unknown_count, skipped_count,
               dry_run, bypass_cooldown, bypass_max_attempts, status, error_message
          FROM notify_batch
         WHERE scenario_key = ?
         ORDER BY started_at DESC
         LIMIT {$limit}
    ");
```

- [ ] **Step 7: PHP 문법 검사**

Run: `php -l /root/boot-dev/public_html/api/services/notify.php`
Expected: `No syntax errors detected`.

- [ ] **Step 8: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/services/notify.php
git commit -m "$(cat <<'EOF'
feat(notify): API에 bypass 파라미터 + preview 영속화 + 이력 응답

- handleNotifyPreview: bypass_cooldown/max_attempts 입력, skip 분류에
  헬퍼 적용, notify_preview에 두 값 저장, 응답에 echo back
- handleNotifySendNow: preview row의 두 bypass 값을 notifyRunScenario에 전달
- handleNotifyListBatches: SELECT에 두 컬럼 추가

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: notify.css — 안내 / 카드 / 우회 영역 / ⚠ 마크

**Files:**
- Modify: `/root/boot-dev/public_html/css/notify.css`

기존 클래스(`.notify-row` 등)는 유지하고 새 클래스 추가 + 카드 톤 보정.

- [ ] **Step 1: 안내·우회·⚠ 클래스 추가**

`/root/boot-dev/public_html/css/notify.css` 끝에 다음 추가:

```css
/* ── 운영자 안내 (헤더 아래 1줄) ─────────────────────────── */
.notify-help {
    color: var(--color-text-sub);
    font-size: var(--text-sm);
    margin: 4px 0 12px;
    line-height: 1.5;
}

/* ── 미리보기 모달의 1회용 우회 영역 ─────────────────────── */
.notify-bypass {
    margin: 12px 0;
    padding: 10px 12px;
    background: var(--color-bg-subtle);
    border: 1px solid var(--color-border);
    border-radius: 6px;
}
.notify-bypass-title {
    font-size: var(--text-sm);
    font-weight: var(--font-semibold);
    margin-bottom: 6px;
    color: var(--color-text);
}
.notify-bypass label {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    font-size: var(--text-sm);
    margin: 4px 0;
    cursor: pointer;
}
.notify-bypass label small {
    color: var(--color-text-sub);
    font-weight: normal;
}

/* ── 이력 표 trigger 옆 ⚠ ────────────────────────────── */
.bypass-warn {
    margin-left: 6px;
    color: var(--color-danger);
    font-size: 0.9em;
    cursor: help;
}
```

- [ ] **Step 2: 카드 톤 보정 (`.notify-row`) — 미세 정리**

기존 L7 부근 `.notify-row` 한 줄 룰을:
```css
.notify-row { border:1px solid #ddd; border-radius:6px; padding:12px; margin-bottom:12px; }
```
다음으로 교체:
```css
.notify-row {
    border: 1px solid var(--color-border);
    border-radius: 8px;
    padding: 14px 16px;
    margin-bottom: 12px;
    background: var(--color-bg);
    transition: box-shadow .15s ease;
}
.notify-row:hover { box-shadow: 0 1px 4px rgba(0,0,0,.06); }
.notify-row-batches { margin-top: 12px; padding-top: 10px; border-top: 1px dashed var(--color-border); }
```
(기존 `.notify-row-batches` 룰은 위 재정의로 덮임 — 위에서 한 번에 정의했으니 기존 줄은 삭제할 것.)

기존 줄:
```css
.notify-row-batches { margin-top:10px; }
```
이 줄 삭제.

- [ ] **Step 3: 모달 액션 영역 정렬 보정 (옵션) — confirm 버튼 라벨 길어질 가능성**

기존 `.notify-modal-actions` 룰은 그대로 유지(이미 flex). 추가 변경 없음.

- [ ] **Step 4: 시각 검증 (브라우저)**

Run (브라우저): `https://dev-boot.soritune.com/operation` 진입 → "알림톡" 탭 클릭 → 시나리오 카드가 hover 시 살짝 그림자 뜨는지, 안내 줄이 회색 작은 글씨로 보이는지 확인.

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev
git add public_html/css/notify.css
git commit -m "$(cat <<'EOF'
style(notify): 카드 톤·안내·우회 영역·⚠ 마크 CSS

components.css 토큰(var(--color-*))을 사용해 알림톡 카드/모달 톤을
boot 운영 페이지와 통일. .notify-help / .notify-bypass / .bypass-warn
세 새 영역 + .notify-row hover/구분선.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: notify.js — 헤더 안내 / 모달 체크박스 / 버튼 클래스 / ⚠

**Files:**
- Modify: `/root/boot-dev/public_html/js/notify.js`

크게 3 묶음: (1) `render()` 헤더 안내·버튼 클래스, (2) `showPreviewModal` 체크박스·재호출·confirm 라벨, (3) `loadBatches`/`showBatchDetail` 버튼 클래스 + ⚠.

- [ ] **Step 1: `render()` 헤더 안내 줄 + 카드 버튼 클래스**

`/root/boot-dev/public_html/js/notify.js` 의 `render()` 함수 본문(L25~L59 부근)을 다음으로 교체:

```js
    function render() {
        if (scenarios.length === 0) {
            root.innerHTML = '<div class="empty">등록된 시나리오가 없습니다.</div>';
            return;
        }
        const rows = scenarios.map(s => `
            <div class="notify-row" data-key="${App.esc(s.key)}">
                <div class="notify-row-head">
                    <strong>${App.esc(s.name)}</strong>
                    <span class="muted">${App.esc(s.schedule)}</span>
                    <label class="toggle">
                        <input type="checkbox" ${s.is_active ? 'checked' : ''} data-act="toggle">
                        <span>스케줄</span>
                    </label>
                    <button class="btn btn-danger btn-sm"    data-act="send-real">지금 발송</button>
                    <button class="btn btn-secondary btn-sm" data-act="send-dry">DRY 발송</button>
                    <button class="btn btn-ghost btn-sm"     data-act="batches">이력</button>
                </div>
                <div class="notify-row-meta">
                    <span>다음 실행: ${App.esc(s.next_run_at || '-')}</span>
                    <span>마지막: ${App.esc(s.last_run_at || '-')} (${App.esc(s.last_run_status || '-')})</span>
                    <span>쿨다운 ${s.cooldown_hours}h / 최대 ${s.max_attempts}회</span>
                </div>
                <div class="notify-row-batches" data-role="batches"></div>
            </div>
        `).join('');
        root.innerHTML = `
            <div class="notify-header">
                <h2>알림톡</h2>
                <button data-act="refresh" class="btn btn-secondary btn-sm">새로고침</button>
            </div>
            <div class="notify-help">
                수동 발송 시 미리보기 모달에서 쿨다운/최대횟수를 일시적으로 우회할 수 있습니다.
                자동 스케줄에는 적용되지 않습니다.
            </div>
            ${rows}`;
        root.querySelector('button[data-act="refresh"]').onclick = () => refresh();
        root.querySelectorAll('.notify-row').forEach(bindRow);
    }
```

- [ ] **Step 2: `openPreview` — bypass 상태를 모달에 들고 가기**

기존 `openPreview` 함수(L73~L79):
```js
    async function openPreview(key, dryRun) {
        App.showLoading();
        const r = await App.post(API + 'notify_preview', { key, dry_run: dryRun });
        App.hideLoading();
        if (!r.success) { Toast.error(r.error); return; }
        showPreviewModal(r);
    }
```

다음으로 교체 (체크박스 두 개 상태와 함께 시작):
```js
    async function openPreview(key, dryRun) {
        App.showLoading();
        const r = await App.post(API + 'notify_preview', {
            key, dry_run: dryRun,
            bypass_cooldown: false,
            bypass_max_attempts: false,
        });
        App.hideLoading();
        if (!r.success) { Toast.error(r.error); return; }
        showPreviewModal({ key, dryRun, ...r });
    }
```

- [ ] **Step 3: `showPreviewModal` — 체크박스 / 즉시 재호출 / 동적 confirm 라벨**

기존 `showPreviewModal(p)` 함수(L81~L116) 전체를 다음으로 교체:

```js
    function showPreviewModal(p) {
        const ovl = document.createElement('div');
        ovl.className = 'notify-modal-overlay';

        let state = {
            preview: p,
            bypassCooldown: !!p.bypass_cooldown,
            bypassMaxAttempts: !!p.bypass_max_attempts,
        };

        function confirmLabel() {
            const verb = state.preview.dry_run ? 'DRY 발송' : '지금 발송';
            const base = `${state.preview.target_count}명에게 ${verb}`;
            const both = state.bypassCooldown && state.bypassMaxAttempts;
            const any  = state.bypassCooldown || state.bypassMaxAttempts;
            if (!any) return base;
            const label = both ? '정책 우회'
                        : state.bypassCooldown ? '쿨다운 우회'
                        : '최대 우회';
            return `⚠ ${label} — ${base}`;
        }

        function paint() {
            const p = state.preview;
            ovl.innerHTML = `
                <div class="notify-modal-box">
                    <h3>발송 전 확인 — <span class="env env-${p.environment}">${p.environment}</span> ${p.dry_run ? '(DRY_RUN)' : '(실발송)'}</h3>
                    <p>발송 대상: <strong>${p.target_count}명</strong>, 스킵: ${p.skip_count}명</p>

                    <div class="notify-bypass">
                        <div class="notify-bypass-title">정책 우회 (이번 1회만)</div>
                        <label>
                            <input type="checkbox" data-bypass="cooldown" ${state.bypassCooldown ? 'checked' : ''}>
                            <span>쿨다운 무시 <small>— 마지막 발송 후 쿨다운 시간 안의 사람도 후보에 포함</small></span>
                        </label>
                        <label>
                            <input type="checkbox" data-bypass="max" ${state.bypassMaxAttempts ? 'checked' : ''}>
                            <span>최대횟수 무시 <small>— 누적 발송 횟수가 한도에 도달한 사람도 후보에 포함</small></span>
                        </label>
                    </div>

                    <table class="notify-preview-table">
                        <thead><tr><th>이름</th><th>전화</th><th>상태</th></tr></thead>
                        <tbody>
                            ${p.candidates.map(c => `<tr><td>${App.esc(c.name)}</td><td>${App.esc(c.phone)}</td><td>발송예정</td></tr>`).join('')}
                            ${p.skips.map(s => `<tr class="skip"><td>${App.esc(s.name)}</td><td>${App.esc(s.phone)}</td><td>스킵 (${App.esc(s.reason)})</td></tr>`).join('')}
                        </tbody>
                    </table>
                    <div class="rendered">
                        <h4>본문 변수 미리보기 (첫 1건) — 템플릿 ${App.esc(p.template_id)}</h4>
                        <pre>${App.esc(JSON.stringify(p.rendered_first || {}, null, 2))}</pre>
                    </div>
                    <div class="notify-modal-actions">
                        <button class="btn btn-secondary" data-act="cancel">취소</button>
                        <button class="btn ${p.dry_run ? 'btn-secondary' : 'btn-danger'}" data-act="confirm" ${p.target_count === 0 ? 'disabled' : ''}>
                            ${confirmLabel()}
                        </button>
                    </div>
                </div>
            `;
            bindModal();
        }

        async function reloadPreview() {
            App.showLoading();
            const r = await App.post(API + 'notify_preview', {
                key: state.preview.key,
                dry_run: state.preview.dry_run,
                bypass_cooldown: state.bypassCooldown,
                bypass_max_attempts: state.bypassMaxAttempts,
            });
            App.hideLoading();
            if (!r.success) { Toast.error(r.error); return; }
            state.preview = { key: state.preview.key, dryRun: state.preview.dry_run, ...r };
            paint();
        }

        function bindModal() {
            ovl.querySelector('button[data-act="cancel"]').onclick = () => ovl.remove();
            ovl.querySelector('input[data-bypass="cooldown"]').onchange = (e) => {
                state.bypassCooldown = e.target.checked;
                reloadPreview();
            };
            ovl.querySelector('input[data-bypass="max"]').onchange = (e) => {
                state.bypassMaxAttempts = e.target.checked;
                reloadPreview();
            };
            ovl.querySelector('button[data-act="confirm"]').onclick = async () => {
                App.showLoading();
                const r = await App.post(API + 'notify_send_now', { preview_id: state.preview.preview_id });
                App.hideLoading();
                if (!r.success) { Toast.error(r.error); return; }
                Toast.ok(`발송 완료 (batch ${r.batch_id})`);
                ovl.remove();
                refresh();
            };
        }

        document.body.appendChild(ovl);
        paint();
    }
```

- [ ] **Step 4: `loadBatches` 의 trigger 컬럼에 ⚠ + 상세 버튼 클래스**

기존 `loadBatches`(L118~L149)의 batches 루프를 다음으로 교체:

기존:
```js
            <tbody>
                ${r.batches.map(b => `
                    <tr>
                        <td>${b.id}</td>
                        <td>${App.esc(b.trigger_type)}${b.dry_run == 1 ? ' (DRY)' : ''}</td>
                        ...
                        <td><button data-batch="${b.id}">상세</button></td>
                    </tr>
                `).join('')}
            </tbody>
```

변경 후:
```js
            <tbody>
                ${r.batches.map(b => {
                    const both = b.bypass_cooldown == 1 && b.bypass_max_attempts == 1;
                    const any  = b.bypass_cooldown == 1 || b.bypass_max_attempts == 1;
                    const warnTitle = both ? '정책 우회 (쿨다운+최대)'
                                    : b.bypass_cooldown == 1 ? '쿨다운 우회'
                                    : b.bypass_max_attempts == 1 ? '최대 우회' : '';
                    const warn = any ? `<span class="bypass-warn" title="${App.esc(warnTitle)}">⚠</span>` : '';
                    return `
                        <tr>
                            <td>${b.id}</td>
                            <td>${App.esc(b.trigger_type)}${b.dry_run == 1 ? ' (DRY)' : ''}${warn}</td>
                            <td>${App.esc(b.started_at)}</td>
                            <td>${b.target_count}</td>
                            <td>${b.sent_count}</td>
                            <td>${b.failed_count}</td>
                            <td>${b.unknown_count}</td>
                            <td>${b.skipped_count}</td>
                            <td><span class="status status-${App.esc(b.status)}">${App.esc(b.status)}</span></td>
                            <td><button class="btn btn-ghost btn-sm" data-batch="${b.id}">상세</button></td>
                        </tr>
                    `;
                }).join('')}
            </tbody>
```

- [ ] **Step 5: `showBatchDetail` 의 재시도 버튼 클래스**

`showBatchDetail`(L151~L185) 안의 재시도 버튼 마크업을 변경:

기존:
```js
            ${failedCount > 0 ? `<button data-act="retry">실패자 ${failedCount}명 재시도</button>` : ''}
```

변경 후:
```js
            ${failedCount > 0 ? `<button class="btn btn-secondary btn-sm" data-act="retry">실패자 ${failedCount}명 재시도</button>` : ''}
```

- [ ] **Step 6: 시각 검증 (브라우저)**

Run (브라우저): `https://dev-boot.soritune.com/operation` → "알림톡" 탭 → 시나리오 카드 버튼 빨강/회색/외곽선으로 보이는지, 헤더 아래 안내 줄이 회색 글씨로 보이는지 확인. "DRY 발송"클릭 → 모달 안에 정책 우회 영역(2 체크박스) 보이는지 확인. 체크 변경 시 후보 카운트가 달라지는지 확인. (실 발송은 다음 Task에서 검증)

- [ ] **Step 7: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/notify.js
git commit -m "$(cat <<'EOF'
feat(notify): 모달 1회용 우회 체크박스 + 헤더 안내 + .btn 클래스 + 이력 ⚠

- 시나리오 카드 헤더 아래 안내 줄(.notify-help)
- 모든 액션 버튼에 components.css btn 토큰 적용 (지금 발송=danger 등)
- 미리보기 모달에 쿨다운 무시 / 최대 무시 체크박스 2개,
  체크 변경 시 notify_preview 즉시 재호출하여 후보/스킵 갱신,
  confirm 라벨에 우회 종류 표시
- 이력 표 trigger 컬럼 옆 ⚠ tooltip

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: DEV 종단 통합 검증 (수동)

**Files:** (코드 변경 없음, 검증만)

DEV 환경에서 실제로 우회를 켜고 dry/실 발송을 1번씩 돌려 batch 컬럼에 기록되는지 확인. 사용자 협업 필요(브라우저 + DB 확인).

- [ ] **Step 1: 운영 화면 접속 + DRY 우회 발송**

브라우저: `https://dev-boot.soritune.com/operation` → "알림톡" 탭 → `form_reminder_ot` 카드의 "DRY 발송" 클릭 → 모달에서 "쿨다운 무시" 체크 → 후보 카운트 변화 확인 → confirm 라벨이 `⚠ 쿨다운 우회 — N명에게 DRY 발송` 으로 변경되는지 → confirm 클릭.

- [ ] **Step 2: notify_batch에 bypass 1이 기록되었는지 검증**

Run:
```bash
source /root/boot-dev/.db_credentials && \
mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT id, trigger_type, dry_run, bypass_cooldown, bypass_max_attempts, status, sent_count
  FROM notify_batch ORDER BY id DESC LIMIT 1;"
```
Expected: 가장 최근 행에 `dry_run=1, bypass_cooldown=1, bypass_max_attempts=0`.

- [ ] **Step 3: 이력 화면에 ⚠ 표시 확인**

브라우저: 같은 카드의 "이력" 클릭 → 방금 만든 배치 행의 trigger 컬럼이 `manual (DRY) ⚠`로 표시되는지, 마우스 hover 시 tooltip "쿨다운 우회"가 뜨는지 확인.

- [ ] **Step 4: 무제한 시나리오 검증 (선택, 시나리오 PHP 임시 변경)**

검증을 위해 `form_reminder_ot.php` 의 `'cooldown_hours' => 24,` 를 `'cooldown_hours' => 0,` 로 일시 변경 → DRY 발송 → 후보 표에 `cooldown` skip이 사라지는지 확인 → 다시 `24`로 원복. (이 단계는 옵션이며, dispatcher가 `<= 0`을 무제한으로 인식하는지를 사람이 직접 확인하는 검증이다.)

- [ ] **Step 5: 검증 결과 사용자에게 보고 + 사용자 명시 승인 요청**

dev push 직전 사용자에게 결과 보고 후, "운영 반영해줘" 등 명시적 요청을 받기 전까지 main 머지/PROD pull 진행 안 함.

---

## Task 8: dev 푸시

**Files:** (코드 변경 없음)

전체 7개 커밋을 origin/dev에 푸시.

- [ ] **Step 1: 푸시 전 상태 확인**

Run: `cd /root/boot-dev && git log --oneline origin/dev..HEAD`
Expected: Task 1~6 의 6개 commit (Task 3은 단일 commit) 표시.

- [ ] **Step 2: 푸시**

Run: `cd /root/boot-dev && git push origin dev`
Expected: `dev -> dev` 푸시 완료.

- [ ] **Step 3: 사용자에게 push 완료 보고 후 dev 검증 요청**

⛔ **여기서 멈춤.** 사용자가 dev 검증을 마치고 "운영 반영해줘" 등을 명시적으로 요청한 경우에만 다음 단계 진행.

---

## (사용자 명시 승인 후) PROD 반영 절차

**참고만:** 위 8개 task와 별개로, 사용자가 운영 반영 요청 시 다음 순서대로 진행.

1. `cd /root/boot-dev && git checkout main && git merge dev && git push origin main && git checkout dev`
2. `cd /root/boot-prod && git pull origin main`
3. PROD 마이그레이션: `cd /root/boot-prod && php migrate_notify_bypass_columns.php`
4. PROD 운영 화면에서 가벼운 종단 검증(우회 안 켜고 DRY 1회) 후 사용자 보고

---

## Self-Review

**Spec coverage:**
- 데이터 모델 (notify_batch + notify_preview ALTER) → Task 1 ✓
- dispatcher 시그니처 + 가드 if + INSERT → Task 2 ✓
- 시나리오 PHP `<= 0` 무제한 인식 → Task 2 (가드 if 안의 `> 0` 검사) + Task 3 헬퍼 단위 테스트 ✓
- API: notify_preview 입력/저장/응답 → Task 4 Step 1~4 ✓
- API: notify_send_now → Task 4 Step 5 ✓
- API: notify_list_batches → Task 4 Step 6 ✓
- API: notify_retry_failed (변경 없음) → spec 명시, plan에서 변경 없음으로 일관 ✓
- UI: 헤더 안내 → Task 6 Step 1 ✓
- UI: 모달 체크박스 + 즉시 재호출 + confirm 라벨 → Task 6 Step 2-3 ✓
- UI: 버튼 클래스 → Task 6 Step 1, 4, 5 + Task 5 ✓
- UI: 이력 ⚠ → Task 6 Step 4 ✓
- CSS: 안내·우회·⚠·카드 톤 → Task 5 ✓
- 테스트 (단위) → Task 3 ✓
- 마이그레이션·배포 절차 → Task 1, 7, 8 + PROD 반영 섹션 ✓

**Placeholder scan:** 없음. 모든 step에 코드/명령어 명시.

**Type 일관성:**
- `notifyShouldCheckCooldown(int, bool): bool` / `notifyShouldCheckMaxAttempts(int, bool): bool` — Task 3 정의, Task 4 Step 2에서 호출, 인자 순서 일치 ✓
- `notifyRunScenario(... bool $bypassCooldown = false, bool $bypassMaxAttempts = false)` — Task 2 정의, Task 4 Step 5에서 호출, 인자 순서 일치 ✓
- JS 측 `bypass_cooldown`/`bypass_max_attempts` 키 — Task 6 Step 2-3에서 일관 사용, API 측 Task 4와 일치 ✓
- `state.preview.preview_id` — Task 6 Step 3 confirm 핸들러, API 응답의 `preview_id` 키와 일치 ✓
