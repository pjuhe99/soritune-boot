# 알림톡 발송 시스템 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

## 세션 인계 (2026-04-23 종료 시점)

**진행 상태:** 12 task 중 Task 1~3 fully reviewed, Task 4 implementer 완료/리뷰 대기, Task 5~12 미진행.

| Task | 상태 | commit |
|---|---|---|
| 1. DB 마이그레이션 | ✅ 완료 (spec+quality 통과) | `2d4694e` + `37ba78e` (COLLATE 보강) |
| 2. keys/solapi.json | ✅ 완료 (commit 없음, gitignored) | — |
| 3. notify_functions.php + tests | ✅ 완료 (spec+quality 통과) | `8e23759` + `666eb54` (fail-loud + 4 tests) |
| 4. source_google_sheet.php | ⏸ implementer DONE, **spec/quality 리뷰 미실행** | `19a3f1c` |
| 5~12 | 미진행 | — |

**dev 브랜치는 origin/dev보다 6 commits ahead. push 안 함** (사용자 승인 후).

**재개 첫 액션:** Task 4 spec compliance 리뷰 → quality 리뷰 → 통과 시 Task 5 (solapi_client.php) 진입.

**주의:**
- 솔라피 API 키가 plan 작성 중 채팅에 노출됨 → 종료 후 사용자가 솔라피 콘솔에서 폐기·재발급 권장. DEV `keys/solapi.json`은 `chmod 600` + gitignore 처리됨.
- DEV `keys/solapi.json`의 `defaultPfId`는 `REPLACE_WITH_REAL_PFID` placeholder.
- worktree 미사용 (boot-dev = 라이브 DEV 서버 심볼릭 링크).
- 메모리: `/root/.claude/projects/-root/memory/project_boot_notify_alimtalk.md` 참조.

---


**Goal:** 운영팀이 코드 정의된 시나리오 기반으로 솔라피 알림톡을 수동 또는 스케줄 발송하고, 운영 화면에서 결과를 확인하고 시나리오 on/off 토글할 수 있는 시스템을 boot.soritune.com에 구축한다.

**Architecture:** 시나리오는 `includes/notify/scenarios/*.php` 파일 1개=1시나리오. 운영 토글/발송 로그는 DB 4개 테이블 (`notify_scenario_state`, `notify_batch`, `notify_message`, `notify_preview`). 분당 1회 디스패처 cron이 시나리오 cron 식과 매칭해 실행. 데이터 어댑터 패턴(구글시트). DRY_RUN 모드, 시나리오별 DB 락, preview_id 검증, unknown 상태로 도배·중복·오발송 방지.

**Tech Stack:** PHP 8+ / PDO MySQL / 자체 cron 매처(composer 미사용) / HMAC-SHA256 솔라피 인증 / 기존 `cron/GoogleSheets.php`(readonly) 재사용 / Vanilla JS (운영 SPA)

**스펙 참조:** `/root/boot-dev/docs/superpowers/specs/2026-04-23-notify-alimtalk-design.md`

---

## File Structure

### 신규 생성

| 경로 | 책임 |
|---|---|
| `migrate_notify_tables.php` (루트) | DB 4개 테이블 생성 |
| `public_html/includes/notify/notify_functions.php` | 공통 유틸: 전화번호 정규화, 변수 치환, cron 매처 |
| `public_html/includes/notify/source_google_sheet.php` | 구글시트 어댑터 (`fetchTargets`) |
| `public_html/includes/notify/solapi_client.php` | 솔라피 HMAC 호출, 알림톡/LMS 페이로드, DRY_RUN |
| `public_html/includes/notify/scenario_registry.php` | `scenarios/*.php` 자동 로드, state UPSERT |
| `public_html/includes/notify/dispatcher.php` | `runScenario()` (락, 쿨다운, 최대횟수, 배치 status, finally 락 해제) |
| `public_html/includes/notify/scenarios/form_reminder_ot.php` | 첫 시나리오 placeholder (실값 채워 운영 시 사용) |
| `public_html/api/services/notify.php` | 7개 API handler (`handleNotifyXxx`) |
| `public_html/js/notify.js` | 운영 화면 (목록/모달/배치) |
| `public_html/css/notify.css` | 알림톡 화면 전용 스타일 (소량) |
| `keys/solapi.json` | 자격증명 (각 환경별, gitignored) |
| `test_notify.php` (루트) | 단위 테스트 CLI 러너 (PHPUnit 미사용) |

### 수정

| 경로 | 변경 |
|---|---|
| `public_html/cron.php` | `case 'notify_dispatch'` 추가, preview 청소 |
| `public_html/api/bootcamp.php` | `require_once` 추가, 7개 case dispatch (※ Task 10 이탈 정정: admin.php가 아니라 bootcamp.php — coin_balance/review_settings 등 admin-operation handler가 bootcamp.php에 등록돼 있음) |
| `public_html/operation/index.php` | `notify.js`/`notify.css` 스크립트 태그 |
| `public_html/js/admin.js` | "알림톡" 탭 추가 (operation/head 표시) |
| 시스템 crontab | 디스패처 1줄 (PROD 적용 시) |

---

## Task 1: DB 마이그레이션 (notify 4개 테이블)

**Files:**
- Create: `/root/boot-dev/migrate_notify_tables.php`

- [ ] **Step 1: 마이그레이션 스크립트 작성**

```php
<?php
/**
 * boot.soritune.com - Notify 시스템 테이블 마이그레이션
 * 사용: php migrate_notify_tables.php
 * DEV/PROD 각각 한 번씩 실행. IF NOT EXISTS이므로 재실행 안전.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

$tables = [
    'notify_scenario_state' => "
        CREATE TABLE IF NOT EXISTS notify_scenario_state (
            scenario_key      VARCHAR(64)  NOT NULL PRIMARY KEY,
            is_active         TINYINT(1)   NOT NULL DEFAULT 0,
            is_running        TINYINT(1)   NOT NULL DEFAULT 0,
            running_since     DATETIME     NULL,
            last_run_at       DATETIME     NULL,
            last_run_status   VARCHAR(20)  NULL,
            last_batch_id     BIGINT       NULL,
            notes             TEXT         NULL,
            updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by        VARCHAR(64)  NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    'notify_batch' => "
        CREATE TABLE IF NOT EXISTS notify_batch (
            id              BIGINT AUTO_INCREMENT PRIMARY KEY,
            scenario_key    VARCHAR(64)  NOT NULL,
            trigger_type    ENUM('schedule','manual','retry') NOT NULL,
            triggered_by    VARCHAR(64)  NULL,
            started_at      DATETIME     NOT NULL,
            finished_at     DATETIME     NULL,
            target_count    INT          NOT NULL DEFAULT 0,
            sent_count      INT          NOT NULL DEFAULT 0,
            failed_count    INT          NOT NULL DEFAULT 0,
            unknown_count   INT          NOT NULL DEFAULT 0,
            skipped_count   INT          NOT NULL DEFAULT 0,
            dry_run         TINYINT(1)   NOT NULL DEFAULT 0,
            status          ENUM('running','completed','partial','failed','no_targets') NOT NULL,
            error_message   TEXT         NULL,
            INDEX idx_scenario_started (scenario_key, started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    'notify_message' => "
        CREATE TABLE IF NOT EXISTS notify_message (
            id                BIGINT AUTO_INCREMENT PRIMARY KEY,
            batch_id          BIGINT       NOT NULL,
            scenario_key      VARCHAR(64)  NOT NULL,
            row_key           VARCHAR(255) NOT NULL,
            phone             VARCHAR(20)  NOT NULL,
            name              VARCHAR(64)  NULL,
            template_id       VARCHAR(64)  NOT NULL,
            rendered_text     TEXT         NULL,
            channel_used      ENUM('alimtalk','lms','none') NOT NULL DEFAULT 'none',
            status            ENUM('queued','sent','failed','skipped','dry_run','unknown') NOT NULL DEFAULT 'queued',
            skip_reason       VARCHAR(64)  NULL,
            fail_reason       TEXT         NULL,
            solapi_message_id VARCHAR(64)  NULL,
            sent_at           DATETIME     NULL,
            processed_at      DATETIME     NULL,
            created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cooldown (scenario_key, phone, status, processed_at),
            INDEX idx_batch (batch_id),
            INDEX idx_solapi (solapi_message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    'notify_preview' => "
        CREATE TABLE IF NOT EXISTS notify_preview (
            id            CHAR(32)     NOT NULL PRIMARY KEY,
            scenario_key  VARCHAR(64)  NOT NULL,
            dry_run       TINYINT(1)   NOT NULL,
            row_keys      JSON         NOT NULL,
            target_count  INT          NOT NULL,
            created_by    VARCHAR(64)  NOT NULL,
            created_at    DATETIME     NOT NULL,
            expires_at    DATETIME     NOT NULL,
            used_at       DATETIME     NULL,
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
];

foreach ($tables as $name => $sql) {
    try {
        $db->exec($sql);
        echo "OK   {$name}\n";
    } catch (Throwable $e) {
        echo "FAIL {$name}: " . $e->getMessage() . "\n";
        exit(1);
    }
}
echo "\nAll notify tables ready.\n";
```

- [ ] **Step 2: DEV에서 실행**

```
cd /root/boot-dev && php migrate_notify_tables.php
```

Expected output:
```
OK   notify_scenario_state
OK   notify_batch
OK   notify_message
OK   notify_preview

All notify tables ready.
```

- [ ] **Step 3: 테이블 생성 확인**

```
mysql -u "$(grep DB_USER /root/boot-dev/.db_credentials | cut -d= -f2)" \
      -p"$(grep DB_PASS /root/boot-dev/.db_credentials | cut -d= -f2)" \
      "$(grep DB_NAME /root/boot-dev/.db_credentials | cut -d= -f2)" \
      -e "SHOW TABLES LIKE 'notify_%'"
```

Expected: 4개 테이블 (notify_batch, notify_message, notify_preview, notify_scenario_state) 출력.

- [ ] **Step 4: Commit**

```
cd /root/boot-dev && git add migrate_notify_tables.php && \
  git commit -m "feat(notify): DB 마이그레이션 — 4 tables (state/batch/message/preview)"
```

---

## Task 2: keys/solapi.json 자격증명 셋업

**Files:**
- Create: `/root/boot-dev/keys/solapi.json` (DEV)
- Note: PROD `keys/solapi.json`은 운영 반영 시점에 별도 생성

`keys/`는 이미 `.gitignore`에 등록됨.

- [ ] **Step 1: DEV용 자격증명 파일 생성 (DRY_RUN 기본 ON)**

```
cat > /root/boot-dev/keys/solapi.json <<'EOF'
{
  "apiKey": "NCSRHV5U54KK5QG1",
  "apiSecret": "YGADRVTTZ8PKMCKWHZAGF8ESHF3F9SG5",
  "defaultPfId": "REPLACE_WITH_REAL_PFID",
  "defaultFrom": "025001111",
  "dry_run_default": true
}
EOF
chmod 600 /root/boot-dev/keys/solapi.json
```

운영 반영 전 사용자가 `defaultPfId`/`defaultFrom`을 실값으로 교체.
**노출된 apiKey/apiSecret은 작업 완료 후 솔라피 콘솔에서 폐기·재발급 권장**.

- [ ] **Step 2: gitignore 확인 (이미 등록됨)**

```
grep -F 'keys/' /root/boot-dev/.gitignore
```

Expected: `keys/` 라인 출력. 등록 안 되어 있으면 즉시 추가:
```
echo 'keys/' >> /root/boot-dev/.gitignore
```

- [ ] **Step 3: 파일이 git status에서 안 보이는지 확인**

```
cd /root/boot-dev && git status --porcelain | grep 'keys/'
```

Expected: 출력 없음. 출력되면 .gitignore 수정 필요.

---

## Task 3: notify_functions.php — 공통 유틸 (TDD)

**Files:**
- Create: `/root/boot-dev/public_html/includes/notify/notify_functions.php`
- Create: `/root/boot-dev/test_notify.php`

3개 함수: `notifyNormalizePhone()`, `notifyRenderVariables()`, `notifyCronMatches()`.

- [ ] **Step 1: 테스트 러너 작성**

```php
<?php
/**
 * Notify 시스템 단위 테스트 CLI 러너 (PHPUnit 미사용 환경)
 * 사용: php test_notify.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/includes/notify/notify_functions.php';

$pass = 0; $fail = 0;

function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; }
    else { $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n"; }
}

// ── notifyNormalizePhone ──────────────────────────
t('phone: dashes',         notifyNormalizePhone('010-1234-5678') === '01012345678');
t('phone: spaces',         notifyNormalizePhone('010 1234 5678') === '01012345678');
t('phone: +82 prefix',     notifyNormalizePhone('+82 10-1234-5678') === '01012345678');
t('phone: +8210 no space', notifyNormalizePhone('+821012345678') === '01012345678');
t('phone: already clean',  notifyNormalizePhone('01012345678') === '01012345678');
t('phone: invalid empty',  notifyNormalizePhone('') === null);
t('phone: invalid letters',notifyNormalizePhone('abcdefg') === null);
t('phone: too short',      notifyNormalizePhone('0101234') === null);
t('phone: 070 office',     notifyNormalizePhone('070-1234-5678') === '07012345678');

// ── notifyRenderVariables ──────────────────────────
$row = ['이름' => '홍길동', '연락처' => '010', 'OT_제출' => 'N'];
$vars = ['#{name}' => 'col:이름', '#{deadline}' => 'const:4월 30일'];
$rendered = notifyRenderVariables($vars, $row);
t('vars: col substitution',   ($rendered['#{name}'] ?? null) === '홍길동');
t('vars: const substitution', ($rendered['#{deadline}'] ?? null) === '4월 30일');

// 누락된 컬럼은 빈 문자열
$rendered2 = notifyRenderVariables(['#{x}' => 'col:없는컬럼'], $row);
t('vars: missing col → empty', ($rendered2['#{x}'] ?? null) === '');

// ── notifyCronMatches ──────────────────────────────
$ts = strtotime('2026-04-23 21:00:00'); // 목요일 (DOW=4)
t('cron: every minute',         notifyCronMatches('* * * * *', $ts));
t('cron: exact 21:00',          notifyCronMatches('0 21 * * *', $ts));
t('cron: 22:00 not matching',   !notifyCronMatches('0 22 * * *', $ts));
t('cron: list 21,22',           notifyCronMatches('0 21,22 * * *', $ts));
t('cron: range 20-23',          notifyCronMatches('0 20-23 * * *', $ts));
t('cron: step */5 hour 20',     notifyCronMatches('0 */5 * * *', strtotime('2026-04-23 20:00:00')));
t('cron: dow=Thu 4',            notifyCronMatches('0 21 * * 4', $ts));
t('cron: dow=Mon 1 not match',  !notifyCronMatches('0 21 * * 1', $ts));
t('cron: dow Mon-Fri',          notifyCronMatches('0 21 * * 1-5', $ts));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 테스트 실행 (실패 확인)**

```
cd /root/boot-dev && php test_notify.php
```

Expected: 함수 미정의 fatal error.

- [ ] **Step 3: 함수 구현**

```php
<?php
/**
 * boot.soritune.com - Notify 공통 유틸
 * 전화번호 정규화 / 템플릿 변수 치환 / cron 매처
 */

/**
 * 한국 휴대/지역 번호를 010xxxxxxxx 형태로 정규화.
 * +82 / 공백 / 하이픈 제거. 길이가 9~11이고 숫자만 남으면 OK.
 * 형식 부적합 시 null.
 */
function notifyNormalizePhone(?string $raw): ?string {
    if ($raw === null) return null;
    $s = trim($raw);
    if ($s === '') return null;
    // 모든 비숫자 제거
    $digits = preg_replace('/\D+/', '', $s);
    if ($digits === '' || $digits === null) return null;
    // +82 / 82 prefix → 0
    if (str_starts_with($digits, '82')) {
        $digits = '0' . substr($digits, 2);
    }
    // 길이 검증 (한국 번호는 보통 10~11자리)
    $len = strlen($digits);
    if ($len < 10 || $len > 11) return null;
    if (!str_starts_with($digits, '0')) return null;
    return $digits;
}

/**
 * 시나리오의 variables 매핑(`'#{x}' => 'col:헤더'` / `'const:문자열'`)을
 * 행 데이터로 치환해 [`'#{x}' => '실제값'`] 반환.
 */
function notifyRenderVariables(array $variables, array $row): array {
    $out = [];
    foreach ($variables as $key => $spec) {
        if (str_starts_with($spec, 'col:')) {
            $col = substr($spec, 4);
            $out[$key] = (string)($row[$col] ?? '');
        } elseif (str_starts_with($spec, 'const:')) {
            $out[$key] = substr($spec, 6);
        } else {
            // 알 수 없는 prefix는 빈 문자열로 안전 처리
            $out[$key] = '';
        }
    }
    return $out;
}

/**
 * 5필드 cron 식이 주어진 timestamp(초)와 매칭되는지 검사.
 * 지원: '*', '숫자', 'A,B,C', 'A-B', '*/N'.
 * 필드: 분(0-59) 시(0-23) 일(1-31) 월(1-12) 요일(0-7, 0과 7은 일요일).
 * PHP date('N')은 1=Mon..7=Sun. cron은 0=Sun..6=Sat 또는 7=Sun.
 */
function notifyCronMatches(string $expr, int $timestamp): bool {
    $parts = preg_split('/\s+/', trim($expr));
    if (count($parts) !== 5) return false;
    [$min, $hour, $day, $mon, $dow] = $parts;

    $now = [
        'min'  => (int)date('i', $timestamp),
        'hour' => (int)date('G', $timestamp),
        'day'  => (int)date('j', $timestamp),
        'mon'  => (int)date('n', $timestamp),
        // PHP date('w'): 0=Sun..6=Sat — cron 표준과 동일
        'dow'  => (int)date('w', $timestamp),
    ];

    return notifyCronFieldMatches($min,  $now['min'],  0, 59)
        && notifyCronFieldMatches($hour, $now['hour'], 0, 23)
        && notifyCronFieldMatches($day,  $now['day'],  1, 31)
        && notifyCronFieldMatches($mon,  $now['mon'],  1, 12)
        && notifyCronDowMatches($dow,    $now['dow']);
}

function notifyCronFieldMatches(string $field, int $value, int $min, int $max): bool {
    foreach (explode(',', $field) as $part) {
        if ($part === '*') return true;
        if (preg_match('#^\*/(\d+)$#', $part, $m)) {
            $step = (int)$m[1];
            if ($step > 0 && $value >= $min && ($value - $min) % $step === 0) return true;
            continue;
        }
        if (preg_match('#^(\d+)-(\d+)$#', $part, $m)) {
            if ($value >= (int)$m[1] && $value <= (int)$m[2]) return true;
            continue;
        }
        if (ctype_digit($part) && (int)$part === $value) return true;
    }
    return false;
}

/** 요일 필드는 7=일요일도 0과 동등 처리 */
function notifyCronDowMatches(string $field, int $value): bool {
    if (notifyCronFieldMatches($field, $value, 0, 6)) return true;
    if ($value === 0 && notifyCronFieldMatches($field, 7, 0, 7)) return true;
    return false;
}
```

- [ ] **Step 4: 테스트 재실행 (전부 통과 확인)**

```
cd /root/boot-dev && php test_notify.php
```

Expected: 모든 t() 케이스 PASS, 종료 코드 0.

- [ ] **Step 5: Commit**

```
cd /root/boot-dev && \
  git add public_html/includes/notify/notify_functions.php test_notify.php && \
  git commit -m "feat(notify): 공통 유틸 (전화 정규화/변수 치환/cron 매처) + tests"
```

---

## Task 4: source_google_sheet.php — 시트 어댑터

**Files:**
- Create: `/root/boot-dev/public_html/includes/notify/source_google_sheet.php`
- Reuse: `/root/boot-dev/cron/GoogleSheets.php` (readonly)

- [ ] **Step 1: 어댑터 함수 작성**

```php
<?php
/**
 * boot.soritune.com - Notify 데이터 어댑터: Google Sheets
 * 기존 cron/GoogleSheets.php(readonly) 재사용.
 */

require_once dirname(__DIR__, 3) . '/cron/GoogleSheets.php';

/**
 * @param array $cfg 시나리오의 source 블록:
 *   ['type'=>'google_sheet', 'sheet_id', 'tab', 'range', 'check_col', 'phone_col', 'name_col']
 * @return array 발송 후보 행 리스트:
 *   [['row_key'=>..., 'phone'=>..., 'name'=>..., 'columns'=>[헤더=>값,...]], ...]
 *   check_col 값이 'N'(대소문자 무시)인 행만 반환.
 */
function notifySourceGoogleSheet(array $cfg): array {
    $required = ['sheet_id', 'tab', 'range', 'check_col', 'phone_col', 'name_col'];
    foreach ($required as $r) {
        if (empty($cfg[$r])) {
            throw new RuntimeException("source.{$r} 누락");
        }
    }

    $sheet = new GoogleSheets();
    $rangeFull = $cfg['tab'] . '!' . $cfg['range'];
    $values = $sheet->getValues($cfg['sheet_id'], $rangeFull);

    if (!is_array($values) || count($values) < 2) return [];

    $headers = array_map('strval', $values[0]);
    $headerIdx = array_flip($headers);

    foreach ([$cfg['check_col'], $cfg['phone_col'], $cfg['name_col']] as $h) {
        if (!isset($headerIdx[$h])) {
            throw new RuntimeException("시트에 '{$h}' 헤더가 없습니다");
        }
    }

    $checkIdx = $headerIdx[$cfg['check_col']];
    $phoneIdx = $headerIdx[$cfg['phone_col']];
    $nameIdx  = $headerIdx[$cfg['name_col']];

    $results = [];
    $rowCount = count($values);
    for ($i = 1; $i < $rowCount; $i++) {
        $row = $values[$i];
        $checkVal = isset($row[$checkIdx]) ? trim((string)$row[$checkIdx]) : '';
        if (strcasecmp($checkVal, 'N') !== 0) continue;

        $columns = [];
        foreach ($headers as $j => $h) {
            $columns[$h] = isset($row[$j]) ? (string)$row[$j] : '';
        }

        $results[] = [
            'row_key' => sprintf('sheet:%s:%s:%d', $cfg['sheet_id'], $cfg['tab'], $i + 1),
            'phone'   => $columns[$cfg['phone_col']] ?? '',
            'name'    => $columns[$cfg['name_col']] ?? '',
            'columns' => $columns,
        ];
    }
    return $results;
}
```

- [ ] **Step 2: 기존 GoogleSheets 클래스에 `getValues` 메서드가 있는지 확인**

```
grep -n "function getValues\|public function get" /root/boot-dev/cron/GoogleSheets.php
```

Expected: `getValues` 또는 동등 메서드 존재. 없으면 다음 step에서 추가.

- [ ] **Step 3: getValues 미존재 시 cron/GoogleSheets.php에 추가** (메서드 있으면 skip)

```php
/**
 * 시트의 지정 range 값을 2차원 배열로 반환 (READONLY scope).
 * @param string $sheetId  스프레드시트 ID
 * @param string $a1Range  'Sheet1!A1:G500' 형식
 */
public function getValues(string $sheetId, string $a1Range): array {
    $token = $this->getAccessToken();
    $url = sprintf(
        'https://sheets.googleapis.com/v4/spreadsheets/%s/values/%s',
        rawurlencode($sheetId),
        rawurlencode($a1Range)
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        throw new RuntimeException("Sheets API error {$code}: {$resp}");
    }
    $data = json_decode($resp, true);
    return $data['values'] ?? [];
}
```

- [ ] **Step 4: 임시 시트 검증 스크립트 (선택, 사용자가 실 시트 ID 보유 시)**

이 step은 첫 시나리오 등록 시 Task 9에서 진행. 지금은 skip.

- [ ] **Step 5: Commit**

```
cd /root/boot-dev && \
  git add public_html/includes/notify/source_google_sheet.php cron/GoogleSheets.php && \
  git commit -m "feat(notify): 구글시트 데이터 어댑터 (check_col=N 행만 반환)"
```

---

## Task 5: solapi_client.php — HMAC + 페이로드 + DRY_RUN

**Files:**
- Create: `/root/boot-dev/public_html/includes/notify/solapi_client.php`
- Modify: `/root/boot-dev/test_notify.php` (시그니처 테스트 추가)

- [ ] **Step 1: HMAC 시그니처 단위 테스트 추가 (test_notify.php 끝부분에 append)**

```php
// ── solapi HMAC ──────────────────────────────
require_once __DIR__ . '/public_html/includes/notify/solapi_client.php';

// 솔라피 공식 헤더 형식: HMAC-SHA256 apiKey=..., date=..., salt=..., signature=...
$header = solapiBuildAuthHeader('TESTKEY', 'TESTSECRET', '2026-04-23T12:00:00Z', 'abcdefgh');
t('solapi: header has scheme',  str_starts_with($header, 'HMAC-SHA256 '));
t('solapi: header has apiKey',  str_contains($header, 'apiKey=TESTKEY'));
t('solapi: header has date',    str_contains($header, 'date=2026-04-23T12:00:00Z'));
t('solapi: header has salt',    str_contains($header, 'salt=abcdefgh'));

// 결정적 시그니처 검증 (HMAC-SHA256(date+salt, secret))
$expected = hash_hmac('sha256', '2026-04-23T12:00:00Z' . 'abcdefgh', 'TESTSECRET');
t('solapi: signature correct', str_contains($header, "signature={$expected}"));

// 페이로드 빌드 (알림톡)
$payload = solapiBuildAlimtalkPayload(
    to: '01012345678',
    from: '025001111',
    pfId: 'KA01PF',
    templateId: 'KA01TP',
    variables: ['#{name}' => '홍길동', '#{deadline}' => '4월 30일']
);
t('payload: type ATA',          $payload['type'] === 'ATA');
t('payload: to normalized',     $payload['to'] === '01012345678');
t('payload: kakao pfId',        $payload['kakaoOptions']['pfId'] === 'KA01PF');
t('payload: kakao templateId',  $payload['kakaoOptions']['templateId'] === 'KA01TP');
// variables는 빌더에서 (object) 캐스팅되므로 배열 접근 시 (array) 역캐스팅 필요
t('payload: vars present',      ((array)$payload['kakaoOptions']['variables'])['#{name}'] === '홍길동');

// 빈 variables는 JSON 직렬화 시 '{}' 이어야 함 (솔라피 spec: []는 4xx)
$emptyPayload = solapiBuildAlimtalkPayload('01000000000', '025001111', 'PF', 'TP', []);
t('payload: empty vars as {}',  str_contains(json_encode($emptyPayload), '"variables":{}'));
```

- [ ] **Step 2: 테스트 실행 (FAIL 확인)**

```
cd /root/boot-dev && php test_notify.php
```

Expected: solapi 함수 미정의 fatal error.

- [ ] **Step 3: solapi_client.php 작성**

```php
<?php
/**
 * boot.soritune.com - Solapi 클라이언트
 * - HMAC-SHA256 인증 (date+salt 서명)
 * - 알림톡 / LMS 페이로드 빌드
 * - DRY_RUN 분기는 호출자(dispatcher)에서 처리. 이 파일은 순수 HTTP/페이로드 책임만.
 */

const SOLAPI_BASE = 'https://api.solapi.com';

/** keys/solapi.json 로드 (캐시) */
function solapiLoadKeys(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $path = dirname(__DIR__, 3) . '/keys/solapi.json';
    if (!file_exists($path)) {
        throw new RuntimeException("keys/solapi.json 없음: {$path}");
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data) || empty($data['apiKey']) || empty($data['apiSecret'])) {
        throw new RuntimeException('keys/solapi.json 형식 오류 (apiKey/apiSecret 필수)');
    }
    $cache = $data + [
        'defaultPfId'     => '',
        'defaultFrom'     => '',
        'dry_run_default' => false,
    ];
    return $cache;
}

/**
 * 솔라피 인증 헤더 생성. 형식:
 *   HMAC-SHA256 apiKey=..., date=..., salt=..., signature=...
 * signature = HMAC-SHA256(date + salt, secret)
 */
function solapiBuildAuthHeader(string $apiKey, string $secret, string $isoDate, string $salt): string {
    $signature = hash_hmac('sha256', $isoDate . $salt, $secret);
    return "HMAC-SHA256 apiKey={$apiKey}, date={$isoDate}, salt={$salt}, signature={$signature}";
}

/** 알림톡 단일 메시지 페이로드 빌드 */
function solapiBuildAlimtalkPayload(
    string $to,
    string $from,
    string $pfId,
    string $templateId,
    array  $variables
): array {
    return [
        'to'   => $to,
        'from' => $from,
        'type' => 'ATA',
        'kakaoOptions' => [
            'pfId'       => $pfId,
            'templateId' => $templateId,
            'variables'  => (object)$variables,  // 빈 배열도 객체로 직렬화
        ],
    ];
}

/** LMS(장문 SMS) 단일 메시지 페이로드 빌드 (폴백 활성화 후 사용) */
function solapiBuildLmsPayload(string $to, string $from, string $text): array {
    return [
        'to'   => $to,
        'from' => $from,
        'type' => 'LMS',
        'text' => $text,
    ];
}

/**
 * send-many/detail 호출. messages는 페이로드 배열의 배열.
 * @return array ['ok'=>bool, 'http_code'=>int, 'body'=>string, 'parsed'=>array|null]
 *  - HTTP 5xx/timeout/네트워크 오류 → ok=false, http_code=0 또는 5xx
 *  - HTTP 4xx → ok=false, http_code=4xx
 *  - HTTP 200 → ok=true (개별 메시지 status는 호출자가 parsed에서 매핑)
 */
function solapiSendMany(array $messages): array {
    $keys = solapiLoadKeys();
    $url  = SOLAPI_BASE . '/messages/v4/send-many/detail';
    $isoDate = gmdate('Y-m-d\TH:i:s\Z');
    $salt    = bin2hex(random_bytes(16));
    $auth    = solapiBuildAuthHeader($keys['apiKey'], $keys['apiSecret'], $isoDate, $salt);

    $body = json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: {$auth}",
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'http_code' => 0, 'body' => $err, 'parsed' => null];
    }
    $parsed = json_decode($resp, true);
    return [
        'ok'        => $http >= 200 && $http < 300,
        'http_code' => $http,
        'body'      => $resp,
        'parsed'    => is_array($parsed) ? $parsed : null,
    ];
}
```

- [ ] **Step 4: 테스트 통과 확인**

```
cd /root/boot-dev && php test_notify.php
```

Expected: 모든 PASS.

- [ ] **Step 5: Commit**

```
cd /root/boot-dev && \
  git add public_html/includes/notify/solapi_client.php test_notify.php && \
  git commit -m "feat(notify): solapi 클라이언트 (HMAC + 알림톡/LMS 페이로드 + send-many)"
```

---

## Task 6: scenario_registry.php — 자동 로드 + state UPSERT

**Files:**
- Create: `/root/boot-dev/public_html/includes/notify/scenario_registry.php`

- [ ] **Step 1: registry 작성**

```php
<?php
/**
 * boot.soritune.com - Notify 시나리오 등록부
 * scenarios/*.php 자동 로드 + notify_scenario_state UPSERT.
 */

/**
 * 모든 시나리오 정의를 [key => definition] 맵으로 반환.
 * 각 파일은 `return [...];` 형태여야 하며, 'key' 필드가 파일 식별자와 일치할 필요는 없으나
 * 'key'가 중복되면 RuntimeException.
 */
function notifyLoadScenarios(): array {
    $dir = __DIR__ . '/scenarios';
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/*.php') ?: [];
    sort($files);

    $map = [];
    foreach ($files as $file) {
        $def = require $file;
        if (!is_array($def) || empty($def['key'])) {
            throw new RuntimeException("시나리오 파일 형식 오류: {$file} (배열 + 'key' 필드 필수)");
        }
        $key = (string)$def['key'];
        if (isset($map[$key])) {
            throw new RuntimeException("시나리오 key 중복: '{$key}'");
        }
        notifyValidateScenario($def);
        $map[$key] = $def;
    }
    return $map;
}

/** 시나리오 정의 필수 필드 검증. 누락/오류 시 throw. */
function notifyValidateScenario(array $def): void {
    foreach (['key', 'name', 'source', 'template', 'schedule', 'cooldown_hours', 'max_attempts'] as $f) {
        if (!array_key_exists($f, $def)) {
            throw new RuntimeException("시나리오 '{$def['key']}': '{$f}' 필드 누락");
        }
    }
    foreach (['type'] as $f) {
        if (empty($def['source'][$f])) {
            throw new RuntimeException("시나리오 '{$def['key']}': source.{$f} 필수");
        }
    }
    foreach (['templateId', 'variables'] as $f) {
        if (!array_key_exists($f, $def['template'])) {
            throw new RuntimeException("시나리오 '{$def['key']}': template.{$f} 필수");
        }
    }
}

/**
 * 모든 시나리오 키에 대해 notify_scenario_state row를 UPSERT.
 * 신규 시나리오는 is_active=0으로 생성, 기존 row는 그대로.
 */
function notifyEnsureScenarioStates(PDO $db, array $scenarios): void {
    if (empty($scenarios)) return;
    $stmt = $db->prepare("
        INSERT INTO notify_scenario_state (scenario_key, is_active)
        VALUES (?, 0)
        ON DUPLICATE KEY UPDATE scenario_key = scenario_key
    ");
    foreach ($scenarios as $key => $_) {
        $stmt->execute([$key]);
    }
}
```

- [ ] **Step 2: 빈 등록부로 동작 확인 (CLI)**

```
php -r 'require "/root/boot-dev/public_html/config.php"; require "/root/boot-dev/public_html/includes/notify/scenario_registry.php"; var_dump(notifyLoadScenarios());'
```

Expected: `array(0) {}` (scenarios/ 폴더 미존재 또는 빈 폴더).

- [ ] **Step 3: scenarios 디렉토리 생성**

```
mkdir -p /root/boot-dev/public_html/includes/notify/scenarios
touch /root/boot-dev/public_html/includes/notify/scenarios/.gitkeep
```

- [ ] **Step 4: Commit**

```
cd /root/boot-dev && \
  git add public_html/includes/notify/scenario_registry.php public_html/includes/notify/scenarios/.gitkeep && \
  git commit -m "feat(notify): 시나리오 등록부 (자동 로드 + state UPSERT + 정의 검증)"
```

---

## Task 7: dispatcher.php — runScenario (락/쿨다운/배치 status)

**Files:**
- Create: `/root/boot-dev/public_html/includes/notify/dispatcher.php`

이 파일이 시스템의 핵심 엔진. 시나리오별 락 / 어댑터 호출 / 쿨다운·최대횟수 체크 / 솔라피 호출 / 응답 매핑 / 배치·state 업데이트 / finally 락 해제를 모두 담당.

- [ ] **Step 1: dispatcher.php 작성**

```php
<?php
/**
 * boot.soritune.com - Notify 디스패처
 * 핵심: notifyRunScenario() — 스케줄/수동/재시도 모두 같은 진입점.
 */

require_once __DIR__ . '/notify_functions.php';
require_once __DIR__ . '/scenario_registry.php';
require_once __DIR__ . '/source_google_sheet.php';
require_once __DIR__ . '/solapi_client.php';

/**
 * 디스패처 진입점: cron('* * * * *')에서 1회 실행.
 * - flock 보조 락
 * - scenarios 등록 + state UPSERT
 * - is_active=1 + cron 매칭 시나리오에 대해 runScenario 호출
 */
function notifyDispatch(?int $now = null): void {
    $now = $now ?? time();
    $lockFile = '/tmp/notify_dispatch.lock';
    $fp = fopen($lockFile, 'c');
    if ($fp === false) {
        error_log('notify_dispatch: 락 파일 열기 실패');
        return;
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        // 다른 인스턴스 실행 중 — 정상 종료
        fclose($fp);
        return;
    }

    try {
        $db = getDB();
        $scenarios = notifyLoadScenarios();
        notifyEnsureScenarioStates($db, $scenarios);

        $stmt = $db->query("SELECT scenario_key FROM notify_scenario_state WHERE is_active = 1");
        $activeKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($activeKeys as $key) {
            if (!isset($scenarios[$key])) continue;
            $def = $scenarios[$key];
            if (!notifyCronMatches((string)$def['schedule'], $now)) continue;
            try {
                notifyRunScenario($db, $def, 'schedule');
            } catch (Throwable $e) {
                error_log("notify scenario '{$key}' 예외: " . $e->getMessage());
            }
        }

        // 만료된 preview 청소 (1일 지난 것)
        $db->exec("DELETE FROM notify_preview WHERE expires_at < NOW() - INTERVAL 1 DAY");
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * 시나리오 1회 실행. 모든 트리거(스케줄/수동/재시도)의 공통 진입.
 *
 * @param PDO    $db
 * @param array  $def              시나리오 정의
 * @param string $trigger          'schedule' | 'manual' | 'retry'
 * @param ?string $triggeredBy     수동/재시도 시 admin id
 * @param ?bool  $dryRun           null이면 keys.dry_run_default 사용
 * @param ?array $rowKeysFilter    이 row_key만 발송 (preview/retry 시)
 * @return ?int batch_id (락 미획득 등으로 실행 안 한 경우 null)
 */
function notifyRunScenario(
    PDO $db,
    array $def,
    string $trigger,
    ?string $triggeredBy = null,
    ?bool $dryRun = null,
    ?array $rowKeysFilter = null
): ?int {
    $key = (string)$def['key'];
    $keys = solapiLoadKeys();
    if ($dryRun === null) {
        $dryRun = (bool)($keys['dry_run_default'] ?? false);
    }

    // 1) 시나리오별 락 claim
    $claim = $db->prepare("
        UPDATE notify_scenario_state
           SET is_running = 1, running_since = NOW()
         WHERE scenario_key = ?
           AND (is_running = 0 OR running_since < NOW() - INTERVAL 10 MINUTE)
    ");
    $claim->execute([$key]);
    if ($claim->rowCount() === 0) {
        return null; // 이미 실행 중
    }

    $batchId = null;
    try {
        // 2) 배치 INSERT
        $ins = $db->prepare("
            INSERT INTO notify_batch
              (scenario_key, trigger_type, triggered_by, started_at, dry_run, status, target_count)
            VALUES (?, ?, ?, NOW(), ?, 'running', 0)
        ");
        $ins->execute([$key, $trigger, $triggeredBy, (int)$dryRun]);
        $batchId = (int)$db->lastInsertId();

        // 3) source 어댑터 호출
        try {
            $rows = notifyFetchRows($def);
        } catch (Throwable $e) {
            notifyFinalizeBatch($db, $batchId, [
                'status' => 'failed',
                'error_message' => 'source: ' . $e->getMessage(),
            ]);
            notifyUpdateState($db, $key, $batchId, 'failed');
            return $batchId;
        }

        // row_keys 필터 (미리보기/재시도)
        if ($rowKeysFilter !== null) {
            $set = array_flip($rowKeysFilter);
            $rows = array_values(array_filter($rows, fn($r) => isset($set[$r['row_key']])));
        }

        $db->prepare("UPDATE notify_batch SET target_count = ? WHERE id = ?")
           ->execute([count($rows), $batchId]);

        if (empty($rows)) {
            notifyFinalizeBatch($db, $batchId, [
                'status' => 'no_targets',
                'sent_count' => 0, 'failed_count' => 0, 'unknown_count' => 0, 'skipped_count' => 0,
            ]);
            notifyUpdateState($db, $key, $batchId, 'no_targets');
            return $batchId;
        }

        // 4) 메시지 큐잉 + 정책 체크
        $queued  = []; // 솔라피로 보낼 후보 ['msg_id'=>..., 'phone'=>..., 'payload'=>...]
        $skipped = 0;

        $insMsg = $db->prepare("
            INSERT INTO notify_message
              (batch_id, scenario_key, row_key, phone, name, template_id, rendered_text,
               channel_used, status, skip_reason, processed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'none', ?, ?, ?)
        ");

        $tpl = $def['template'];
        $templateId = (string)$tpl['templateId'];
        $variables  = (array)$tpl['variables'];
        $pfId = (string)($tpl['pfId_override'] ?? $keys['defaultPfId'] ?? '');
        $from = (string)($keys['defaultFrom'] ?? '');

        foreach ($rows as $row) {
            $rendered = notifyRenderVariables($variables, $row['columns'] ?? []);
            $renderedText = notifyComposeRenderedText($rendered);

            $phoneNorm = notifyNormalizePhone($row['phone'] ?? '');
            if ($phoneNorm === null) {
                $insMsg->execute([
                    $batchId, $key, $row['row_key'], (string)($row['phone'] ?? ''),
                    $row['name'] ?? null, $templateId, $renderedText,
                    'skipped', 'phone_invalid', date('Y-m-d H:i:s'),
                ]);
                $skipped++;
                continue;
            }

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

            // 최대횟수
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

            // queued 기록
            $insQ = $db->prepare("
                INSERT INTO notify_message
                  (batch_id, scenario_key, row_key, phone, name, template_id,
                   rendered_text, channel_used, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'none', 'queued')
            ");
            $insQ->execute([
                $batchId, $key, $row['row_key'], $phoneNorm,
                $row['name'] ?? null, $templateId, $renderedText,
            ]);
            $msgId = (int)$db->lastInsertId();

            $queued[] = [
                'msg_id'  => $msgId,
                'phone'   => $phoneNorm,
                'payload' => solapiBuildAlimtalkPayload($phoneNorm, $from, $pfId, $templateId, $rendered),
            ];
        }

        // 5) 발송 (DRY_RUN 분기)
        $sent = 0; $failed = 0; $unknown = 0;
        if ($dryRun) {
            $upd = $db->prepare("
                UPDATE notify_message
                   SET status='dry_run', channel_used='none', processed_at=NOW()
                 WHERE id = ?
            ");
            foreach ($queued as $q) $upd->execute([$q['msg_id']]);
        } elseif (!empty($queued)) {
            $messages = array_column($queued, 'payload');
            $resp = solapiSendMany($messages);
            $statuses = notifyMapSolapiResponse($resp, $queued);
            foreach ($statuses as $msgId => $info) {
                $db->prepare("
                    UPDATE notify_message
                       SET status = ?, channel_used = ?, sent_at = ?,
                           processed_at = NOW(),
                           solapi_message_id = ?, fail_reason = ?
                     WHERE id = ?
                ")->execute([
                    $info['status'],
                    $info['channel_used'],
                    $info['sent_at'],
                    $info['solapi_message_id'],
                    $info['fail_reason'],
                    $msgId,
                ]);
                if     ($info['status'] === 'sent')    $sent++;
                elseif ($info['status'] === 'failed')  $failed++;
                elseif ($info['status'] === 'unknown') $unknown++;
            }
        }

        // 6) 배치 finalize
        $finalStatus = notifyDecideBatchStatus(
            target: count($rows),
            sent:   $dryRun ? count($queued) : $sent,  // dry_run은 sent로 카운트하지 않지만 화면 가시성 위해 별도 계산
            failed: $failed,
            unknown:$unknown
        );
        // dry_run 배치는 status='completed'로 두되 sent_count=0 (메시지 status가 dry_run으로 식별됨)
        if ($dryRun) {
            $finalStatus = count($queued) > 0 ? 'completed' : 'no_targets';
            $sentCount = 0;
        } else {
            $sentCount = $sent;
        }

        notifyFinalizeBatch($db, $batchId, [
            'status' => $finalStatus,
            'sent_count' => $sentCount,
            'failed_count' => $failed,
            'unknown_count' => $unknown,
            'skipped_count' => $skipped,
        ]);
        notifyUpdateState($db, $key, $batchId, $finalStatus);

        return $batchId;

    } catch (Throwable $e) {
        if ($batchId) {
            notifyFinalizeBatch($db, $batchId, [
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            notifyUpdateState($db, $key, $batchId, 'failed');
        }
        throw $e;
    } finally {
        // 락 해제
        $db->prepare("UPDATE notify_scenario_state SET is_running = 0, running_since = NULL WHERE scenario_key = ?")
           ->execute([$key]);
    }
}

/** source 타입 분기 (현재는 google_sheet만). 미래에 db_query 추가. */
function notifyFetchRows(array $def): array {
    $type = $def['source']['type'] ?? '';
    return match ($type) {
        'google_sheet' => notifySourceGoogleSheet($def['source']),
        default => throw new RuntimeException("미지원 source.type: '{$type}'"),
    };
}

/** 치환된 변수만으로 본문 미리보기 문자열 합성 (감사·UI용) */
function notifyComposeRenderedText(array $rendered): string {
    return json_encode($rendered, JSON_UNESCAPED_UNICODE);
}

/**
 * 솔라피 응답을 큐 메시지에 매핑.
 * - HTTP timeout/5xx → 모든 메시지 unknown
 * - HTTP 4xx → 모든 메시지 failed (응답 본문 fail_reason)
 * - HTTP 2xx → parsed에서 messageId 매칭 시도. 매칭 실패한 건은 unknown.
 *   parsed의 개별 메시지 status가 즉시 확정 fail이면 failed, 아니면 sent로 간주.
 *   (LMS 폴백은 1차 비활성, fallback_lms 활성 시점에 별도 확장)
 */
function notifyMapSolapiResponse(array $resp, array $queued): array {
    $result = [];
    $now = date('Y-m-d H:i:s');

    if (!$resp['ok']) {
        $isUnknown = ($resp['http_code'] === 0 || $resp['http_code'] >= 500);
        foreach ($queued as $q) {
            $result[$q['msg_id']] = [
                'status'            => $isUnknown ? 'unknown' : 'failed',
                'channel_used'      => 'none',
                'sent_at'           => null,
                'solapi_message_id' => null,
                'fail_reason'       => substr((string)$resp['body'], 0, 1000),
            ];
        }
        return $result;
    }

    // 2xx: parsed의 messageList 에서 to(전화번호)로 매칭
    $byPhone = [];
    foreach ((array)($resp['parsed']['messageList'] ?? $resp['parsed']['messages'] ?? []) as $m) {
        $to = $m['to'] ?? null;
        if ($to !== null) $byPhone[(string)$to] = $m;
    }

    foreach ($queued as $q) {
        $m = $byPhone[$q['phone']] ?? null;
        if ($m === null) {
            // 응답에 매칭이 없으면 unknown (보수적)
            $result[$q['msg_id']] = [
                'status'            => 'unknown',
                'channel_used'      => 'none',
                'sent_at'           => null,
                'solapi_message_id' => null,
                'fail_reason'       => 'no_response_match',
            ];
            continue;
        }
        $statusCode = (string)($m['statusCode'] ?? $m['status'] ?? '');
        $messageId  = (string)($m['messageId'] ?? '');
        $isSuccess  = (str_starts_with($statusCode, '2') || $statusCode === '0' || $statusCode === '');
        if ($isSuccess) {
            $result[$q['msg_id']] = [
                'status'            => 'sent',
                'channel_used'      => 'alimtalk',
                'sent_at'           => $now,
                'solapi_message_id' => $messageId,
                'fail_reason'       => null,
            ];
        } else {
            $result[$q['msg_id']] = [
                'status'            => 'failed',
                'channel_used'      => 'none',
                'sent_at'           => null,
                'solapi_message_id' => $messageId ?: null,
                'fail_reason'       => substr(json_encode($m, JSON_UNESCAPED_UNICODE), 0, 1000),
            ];
        }
    }
    return $result;
}

/** 정상 종료 시 배치 status 결정 규칙 */
function notifyDecideBatchStatus(int $target, int $sent, int $failed, int $unknown): string {
    if ($target === 0) return 'no_targets';
    if ($sent > 0 && ($failed === 0 && $unknown === 0)) return 'completed';
    if ($sent > 0 && ($failed > 0 || $unknown > 0))     return 'partial';
    if ($sent === 0 && ($failed > 0 || $unknown > 0))   return 'failed';
    return 'completed'; // skipped만 있는 케이스
}

/** 배치 row 마무리 UPDATE */
function notifyFinalizeBatch(PDO $db, int $batchId, array $fields): void {
    $cols = []; $vals = [];
    foreach (['status','sent_count','failed_count','unknown_count','skipped_count','error_message'] as $f) {
        if (array_key_exists($f, $fields)) {
            $cols[] = "{$f} = ?";
            $vals[] = $fields[$f];
        }
    }
    $cols[] = 'finished_at = NOW()';
    $sql = "UPDATE notify_batch SET " . implode(', ', $cols) . " WHERE id = ?";
    $vals[] = $batchId;
    $db->prepare($sql)->execute($vals);
}

function notifyUpdateState(PDO $db, string $key, ?int $batchId, string $status): void {
    $db->prepare("
        UPDATE notify_scenario_state
           SET last_run_at = NOW(), last_run_status = ?, last_batch_id = ?
         WHERE scenario_key = ?
    ")->execute([$status, $batchId, $key]);
}
```

- [ ] **Step 2: 통합 테스트 시나리오 (test_notify.php에 append) — 디스패처 단위 동작 검증**

```php
// ── dispatcher status decision (단위) ────────────
require_once __DIR__ . '/public_html/includes/notify/dispatcher.php';
t('status: no_targets',    notifyDecideBatchStatus(0, 0, 0, 0) === 'no_targets');
t('status: completed all', notifyDecideBatchStatus(5, 5, 0, 0) === 'completed');
t('status: partial mixed', notifyDecideBatchStatus(5, 3, 2, 0) === 'partial');
t('status: partial unk',   notifyDecideBatchStatus(5, 3, 0, 2) === 'partial');
t('status: failed all',    notifyDecideBatchStatus(5, 0, 5, 0) === 'failed');
t('status: skipped only',  notifyDecideBatchStatus(5, 0, 0, 0) === 'completed');
```

- [ ] **Step 3: 단위 테스트 통과 확인**

```
cd /root/boot-dev && php test_notify.php
```

Expected: 모든 PASS.

- [ ] **Step 4: 디스패처 무사 호출 (시나리오 0개)**

```
php -r 'require "/root/boot-dev/public_html/config.php"; require "/root/boot-dev/public_html/includes/notify/dispatcher.php"; notifyDispatch(); echo "OK\n";'
```

Expected: `OK` 출력 (시나리오 없으니 아무것도 안 함). DB 에러 없음.

- [ ] **Step 5: Commit**

```
cd /root/boot-dev && \
  git add public_html/includes/notify/dispatcher.php test_notify.php && \
  git commit -m "feat(notify): 디스패처 — 시나리오별 락/쿨다운/배치 status/응답 매핑"
```

---

## Task 8: cron.php 통합 + crontab 라인 (DEV)

**Files:**
- Modify: `/root/boot-dev/public_html/cron.php`

- [ ] **Step 1: cron.php 현 상태 확인**

```
grep -n "case '" /root/boot-dev/public_html/cron.php
```

기존 case 확인 후 `notify_dispatch` 추가.

- [ ] **Step 2: cron.php에 case 추가**

`switch ($command)` 블록의 적절한 위치에 다음 case를 추가:

```php
    case 'notify_dispatch':
        require_once __DIR__ . '/includes/notify/dispatcher.php';
        notifyDispatch();
        break;
```

그리고 사용법 echo 부분에 한 줄 추가:
```php
        echo "  notify_dispatch    매분 실행. 활성 시나리오의 cron 식이 매칭되면 발송\n";
```

- [ ] **Step 3: CLI에서 호출 검증**

```
cd /root/boot-dev/public_html && php cron.php notify_dispatch
echo "exit: $?"
```

Expected: 출력 없거나 정상 메시지, exit code 0.

- [ ] **Step 4: DEV crontab 라인 추가 (서버 사용자)**

```
crontab -l > /tmp/cron.bak && \
  ( cat /tmp/cron.bak; echo '* * * * * /usr/bin/php /var/www/html/_______site_SORITUNECOM_DEV_BOOT/public_html/cron.php notify_dispatch >> /var/www/html/_______site_SORITUNECOM_DEV_BOOT/logs/notify.log 2>&1' ) | crontab -
```

검증:
```
crontab -l | grep notify_dispatch
```
Expected: 추가한 라인 출력.

- [ ] **Step 5: 1~2분 대기 후 로그 확인 (시나리오 0개라 아무 일도 안 일어나야 함)**

```
sleep 70 && tail -n 20 /var/www/html/_______site_SORITUNECOM_DEV_BOOT/logs/notify.log
```

Expected: 빈 로그 또는 PHP notice 없음.

- [ ] **Step 6: Commit**

```
cd /root/boot-dev && \
  git add public_html/cron.php && \
  git commit -m "feat(notify): cron.php에 notify_dispatch case 추가"
```

---

## Task 9: 첫 시나리오 placeholder 등록

**Files:**
- Create: `/root/boot-dev/public_html/includes/notify/scenarios/form_reminder_ot.php`

**중요:** 이 시나리오는 placeholder 값(REPLACE_ME)을 포함하므로, 사용자가 실값(시트 ID, 컬럼명, templateId)을 채우기 전까지 `is_active=0`으로 유지. 운영 화면에서 사용자가 정의를 보고 시트 ID/templateId 채우는 단계 별도 진행.

- [ ] **Step 1: placeholder 시나리오 작성**

```php
<?php
/**
 * 시나리오: OT 출석 폼 미제출자 리마인드
 *
 * 운영 적용 전 다음을 실값으로 교체해야 함:
 *   - source.sheet_id, source.tab, source.range, *_col 헤더명
 *   - template.templateId
 *   - schedule (cron 식)
 *
 * 활성화: 운영 화면에서 is_active 토글 (기본 OFF).
 */
return [
    'key'         => 'form_reminder_ot',
    'name'        => 'OT 출석 폼 미제출자 리마인드',
    'description' => '구글시트 OT_제출 컬럼이 N인 회원에게 폼 작성 안내',

    'source' => [
        'type'       => 'google_sheet',
        'sheet_id'   => 'REPLACE_ME_SHEET_ID',
        'tab'        => 'REPLACE_ME_TAB',
        'range'      => 'A1:G500',
        'check_col'  => 'OT_제출',
        'phone_col'  => '연락처',
        'name_col'   => '이름',
    ],

    'template' => [
        'templateId'   => 'REPLACE_ME_TEMPLATE_ID',
        'fallback_lms' => false,
        'variables' => [
            '#{name}'     => 'col:이름',
            '#{deadline}' => 'const:4월 30일',
        ],
    ],

    'schedule'       => '0 21 * * *',
    'cooldown_hours' => 24,
    'max_attempts'   => 3,
];
```

- [ ] **Step 2: 등록부 확인**

```
php -r 'require "/root/boot-dev/public_html/config.php"; require "/root/boot-dev/public_html/includes/notify/scenario_registry.php"; print_r(array_keys(notifyLoadScenarios()));'
```

Expected: `Array ( [0] => form_reminder_ot )`.

- [ ] **Step 3: state UPSERT 동작 확인**

```
php -r 'require "/root/boot-dev/public_html/config.php"; require "/root/boot-dev/public_html/includes/notify/dispatcher.php"; notifyDispatch();'
mysql ... -e "SELECT scenario_key, is_active FROM notify_scenario_state"
```

Expected: `form_reminder_ot | 0` 출력.

- [ ] **Step 4: Commit**

```
cd /root/boot-dev && \
  git add public_html/includes/notify/scenarios/form_reminder_ot.php && \
  git commit -m "feat(notify): 첫 시나리오 placeholder (form_reminder_ot, is_active=0)"
```

---

## Task 10: API services/notify.php — 7개 handler

**Files:**
- Create: `/root/boot-dev/public_html/api/services/notify.php`
- Modify: `/root/boot-dev/public_html/api/bootcamp.php` (※ admin.php가 아니라 bootcamp.php — coin_balance/review_settings 같은 admin-operation handler가 bootcamp.php에 등록돼 있음)

권한 그룹: `operation`, `head`, `subhead1`, `subhead2`.

- [ ] **Step 1: services/notify.php 작성**

```php
<?php
/**
 * Notify API Handlers
 * 모든 액션은 operation/head/subhead1/subhead2 중 하나의 역할 필요.
 */

require_once __DIR__ . '/../../includes/notify/dispatcher.php';

const NOTIFY_ROLES = ['operation', 'head', 'subhead1', 'subhead2'];
const NOTIFY_PREVIEW_TTL_MIN = 10;

function handleNotifyListScenarios() {
    requireAdmin(NOTIFY_ROLES);
    $db = getDB();
    $scenarios = notifyLoadScenarios();
    notifyEnsureScenarioStates($db, $scenarios);

    $stmt = $db->query("SELECT * FROM notify_scenario_state");
    $stateMap = [];
    foreach ($stmt->fetchAll() as $row) $stateMap[$row['scenario_key']] = $row;

    $now = time();
    $out = [];
    foreach ($scenarios as $key => $def) {
        $state = $stateMap[$key] ?? [];
        $out[] = [
            'key'             => $key,
            'name'            => $def['name'] ?? $key,
            'description'     => $def['description'] ?? '',
            'schedule'        => $def['schedule'] ?? '',
            'cooldown_hours'  => $def['cooldown_hours'] ?? null,
            'max_attempts'    => $def['max_attempts'] ?? null,
            'source_type'     => $def['source']['type'] ?? '',
            'template_id'     => $def['template']['templateId'] ?? '',
            'fallback_lms'    => (bool)($def['template']['fallback_lms'] ?? false),
            'is_active'       => (int)($state['is_active'] ?? 0),
            'is_running'      => (int)($state['is_running'] ?? 0),
            'last_run_at'     => $state['last_run_at'] ?? null,
            'last_run_status' => $state['last_run_status'] ?? null,
            'last_batch_id'   => $state['last_batch_id'] ?? null,
            'next_run_at'     => notifyNextRunAt((string)($def['schedule'] ?? ''), $now),
        ];
    }
    jsonSuccess(['scenarios' => $out]);
}

/** 다음 실행 예정 시각을 단순 brute-force로 계산 (다음 60분 내 매칭 분 단위 1분씩 탐색) */
function notifyNextRunAt(string $cronExpr, int $now): ?string {
    if ($cronExpr === '') return null;
    $base = $now - ($now % 60) + 60;
    for ($i = 0; $i < 60 * 24 * 8; $i++) {  // 최대 8일 탐색
        $ts = $base + ($i * 60);
        if (notifyCronMatches($cronExpr, $ts)) return date('Y-m-d H:i:s', $ts);
    }
    return null;
}

function handleNotifyToggle($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(NOTIFY_ROLES);
    $input = getJsonInput();
    $key   = trim($input['key'] ?? '');
    $on    = (int)!!($input['is_active'] ?? false);
    if ($key === '') jsonError('key 필요');

    $scenarios = notifyLoadScenarios();
    if (!isset($scenarios[$key])) jsonError('알 수 없는 시나리오');

    $db = getDB();
    $db->prepare("
        UPDATE notify_scenario_state
           SET is_active = ?, updated_by = ?
         WHERE scenario_key = ?
    ")->execute([$on, (string)$admin['admin_id'], $key]);

    jsonSuccess(['key' => $key, 'is_active' => $on]);
}

function handleNotifyPreview($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(NOTIFY_ROLES);
    $input = getJsonInput();
    $key   = trim($input['key'] ?? '');
    if ($key === '') jsonError('key 필요');

    $scenarios = notifyLoadScenarios();
    if (!isset($scenarios[$key])) jsonError('알 수 없는 시나리오');
    $def = $scenarios[$key];

    $keys = solapiLoadKeys();
    $dryRun = isset($input['dry_run']) ? (bool)$input['dry_run'] : (bool)($keys['dry_run_default'] ?? false);

    // 어댑터 호출
    try {
        $rows = notifyFetchRows($def);
    } catch (Throwable $e) {
        jsonError('source 호출 실패: ' . $e->getMessage(), 500);
    }

    // 정책 사전 평가 (실제 메시지 INSERT 없이)
    $db = getDB();
    $candidates = [];   // 발송 예정
    $skips      = [];   // 스킵 분류
    foreach ($rows as $row) {
        $phoneNorm = notifyNormalizePhone($row['phone'] ?? '');
        if ($phoneNorm === null) {
            $skips[] = $row + ['_skip' => 'phone_invalid'];
            continue;
        }
        $cd = $db->prepare("
            SELECT MAX(processed_at) FROM notify_message
             WHERE scenario_key = ? AND phone = ?
               AND status IN ('sent','unknown')
               AND processed_at >= NOW() - INTERVAL ? HOUR
        ");
        $cd->execute([$key, $phoneNorm, (int)$def['cooldown_hours']]);
        if ($cd->fetchColumn()) { $skips[] = $row + ['_skip' => 'cooldown']; continue; }

        $mx = $db->prepare("
            SELECT COUNT(*) FROM notify_message
             WHERE scenario_key = ? AND phone = ? AND status IN ('sent','unknown')
        ");
        $mx->execute([$key, $phoneNorm]);
        if ((int)$mx->fetchColumn() >= (int)$def['max_attempts']) {
            $skips[] = $row + ['_skip' => 'max_attempts']; continue;
        }
        $candidates[] = $row + ['phone_norm' => $phoneNorm];
    }

    // 본문 미리보기 (첫 1건)
    $preview = null;
    if (!empty($candidates)) {
        $first = $candidates[0];
        $preview = notifyRenderVariables(
            (array)$def['template']['variables'],
            $first['columns'] ?? []
        );
    }

    // preview row 생성 (10분 만료)
    $previewId = bin2hex(random_bytes(16));
    $rowKeys = array_map(fn($c) => $c['row_key'], $candidates);
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

    jsonSuccess([
        'preview_id'     => $previewId,
        'expires_in_min' => NOTIFY_PREVIEW_TTL_MIN,
        'dry_run'        => (int)$dryRun,
        'environment'    => notifyEnvironmentLabel(),
        'target_count'   => count($candidates),
        'skip_count'     => count($skips),
        'candidates'     => array_map(fn($c) => [
            'row_key' => $c['row_key'],
            'name'    => $c['name'] ?? '',
            'phone'   => $c['phone_norm'],
        ], $candidates),
        'skips'          => array_map(fn($s) => [
            'row_key' => $s['row_key'],
            'name'    => $s['name'] ?? '',
            'phone'   => $s['phone'] ?? '',
            'reason'  => $s['_skip'],
        ], $skips),
        'rendered_first' => $preview,
        'template_id'    => $def['template']['templateId'] ?? '',
    ]);
}

function notifyEnvironmentLabel(): string {
    $creds = loadDbCredentials();
    $name = $creds['DB_NAME'] ?? '';
    if (str_contains($name, 'DEV')) return 'DEV';
    return 'PROD';
}

function handleNotifySendNow($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(NOTIFY_ROLES);
    $input = getJsonInput();
    $previewId = trim($input['preview_id'] ?? '');
    if ($previewId === '') jsonError('preview_id 필요');

    $db = getDB();
    $db->beginTransaction();
    try {
        $sel = $db->prepare("SELECT * FROM notify_preview WHERE id = ? FOR UPDATE");
        $sel->execute([$previewId]);
        $preview = $sel->fetch();
        if (!$preview)                         { $db->rollBack(); jsonError('만료되었거나 알 수 없는 preview'); }
        if ($preview['used_at'])               { $db->rollBack(); jsonError('이미 사용된 preview'); }
        if (strtotime($preview['expires_at']) < time()) { $db->rollBack(); jsonError('preview 만료'); }
        if ((string)$preview['created_by'] !== (string)$admin['admin_id']) {
            $db->rollBack(); jsonError('preview 권한 없음', 403);
        }
        $db->prepare("UPDATE notify_preview SET used_at = NOW() WHERE id = ?")->execute([$previewId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $scenarios = notifyLoadScenarios();
    if (!isset($scenarios[$preview['scenario_key']])) jsonError('알 수 없는 시나리오');

    $rowKeys = json_decode((string)$preview['row_keys'], true);
    if (!is_array($rowKeys)) $rowKeys = [];

    $batchId = notifyRunScenario(
        $db,
        $scenarios[$preview['scenario_key']],
        'manual',
        (string)$admin['admin_id'],
        (bool)$preview['dry_run'],
        $rowKeys
    );

    if ($batchId === null) jsonError('이미 실행 중인 시나리오입니다. 잠시 후 다시 시도하세요.');
    jsonSuccess(['batch_id' => $batchId]);
}

function handleNotifyListBatches() {
    requireAdmin(NOTIFY_ROLES);
    $key   = trim($_GET['key'] ?? '');
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
    if ($key === '') jsonError('key 필요');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, scenario_key, trigger_type, triggered_by, started_at, finished_at,
               target_count, sent_count, failed_count, unknown_count, skipped_count,
               dry_run, status, error_message
          FROM notify_batch
         WHERE scenario_key = ?
         ORDER BY started_at DESC
         LIMIT {$limit}
    ");
    $stmt->execute([$key]);
    jsonSuccess(['batches' => $stmt->fetchAll()]);
}

function handleNotifyBatchDetail() {
    requireAdmin(NOTIFY_ROLES);
    $batchId = (int)($_GET['batch_id'] ?? 0);
    if (!$batchId) jsonError('batch_id 필요');

    $db = getDB();
    $batch = $db->prepare("SELECT * FROM notify_batch WHERE id = ?");
    $batch->execute([$batchId]);
    $b = $batch->fetch();
    if (!$b) jsonError('배치 없음', 404);

    $msgs = $db->prepare("
        SELECT id, row_key, phone, name, channel_used, status,
               skip_reason, fail_reason, solapi_message_id, sent_at, processed_at
          FROM notify_message
         WHERE batch_id = ?
         ORDER BY id
    ");
    $msgs->execute([$batchId]);
    jsonSuccess(['batch' => $b, 'messages' => $msgs->fetchAll()]);
}

function handleNotifyRetryFailed($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(NOTIFY_ROLES);
    $input = getJsonInput();
    $batchId = (int)($input['batch_id'] ?? 0);
    if (!$batchId) jsonError('batch_id 필요');

    $db = getDB();
    $batchRow = $db->prepare("SELECT scenario_key, dry_run FROM notify_batch WHERE id = ?");
    $batchRow->execute([$batchId]);
    $batch = $batchRow->fetch();
    if (!$batch) jsonError('배치 없음', 404);

    $rk = $db->prepare("
        SELECT DISTINCT row_key FROM notify_message
         WHERE batch_id = ? AND status = 'failed'
    ");
    $rk->execute([$batchId]);
    $rowKeys = $rk->fetchAll(PDO::FETCH_COLUMN);
    if (empty($rowKeys)) jsonError('재시도할 failed 메시지가 없습니다');

    $scenarios = notifyLoadScenarios();
    $key = (string)$batch['scenario_key'];
    if (!isset($scenarios[$key])) jsonError('알 수 없는 시나리오');

    $newBatchId = notifyRunScenario(
        $db,
        $scenarios[$key],
        'retry',
        (string)$admin['admin_id'],
        (bool)$batch['dry_run'],
        $rowKeys
    );
    if ($newBatchId === null) jsonError('이미 실행 중인 시나리오입니다. 잠시 후 다시 시도하세요.');
    jsonSuccess(['batch_id' => $newBatchId]);
}
```

- [ ] **Step 2: api/bootcamp.php에 require_once + 7 case 추가** (※ admin.php가 아니라 bootcamp.php — coin_balance/review_settings 같은 admin-operation handler가 bootcamp.php에 등록돼 있음)

`require_once` 묶음 끝에 추가:
```php
require_once __DIR__ . '/services/notify.php';
```

`switch ($action)`의 적절한 위치(예: 코인 case 뒤)에 추가:
```php
case 'notify_list_scenarios': handleNotifyListScenarios(); break;
case 'notify_toggle':         handleNotifyToggle($method); break;
case 'notify_preview':        handleNotifyPreview($method); break;
case 'notify_send_now':       handleNotifySendNow($method); break;
case 'notify_list_batches':   handleNotifyListBatches(); break;
case 'notify_batch_detail':   handleNotifyBatchDetail(); break;
case 'notify_retry_failed':   handleNotifyRetryFailed($method); break;
```

확실하지 않다면 라우터가 admin.php인지 확인:
```
grep -n "case 'coin_balance'" /root/boot-dev/public_html/api/admin.php /root/boot-dev/public_html/api/bootcamp.php
```
실제로 `bootcamp.php`에서 처리되면 거기에 추가. 두 파일 다 확인 필수.

- [ ] **Step 3: 수동 curl 검증 (DEV)**

먼저 admin 로그인 (이미 있다면 cookie jar 재사용). 로그인 cookie 가지고:
```
COOKIE_FILE=/tmp/notify_test_cookies.txt
# (관리자 로그인 — 사용자 환경에 맞춰 진행)

curl -sS -b $COOKIE_FILE "https://dev-boot.soritune.com/api/admin.php?action=notify_list_scenarios" | jq .
```
Expected: `success=true`, `scenarios` 배열에 `form_reminder_ot` 포함.

- [ ] **Step 4: Commit**

```
cd /root/boot-dev && \
  git add public_html/api/services/notify.php public_html/api/bootcamp.php docs/superpowers/plans/2026-04-23-notify-alimtalk.md && \
  git commit -m "feat(notify): API 7개 액션 (목록/토글/미리보기/발송/이력/상세/재시도)"
```

---

## Task 11: 운영 화면 — js/notify.js + tab 통합 + CSS

**Files:**
- Create: `/root/boot-dev/public_html/js/notify.js`
- Create: `/root/boot-dev/public_html/css/notify.css`
- Modify: `/root/boot-dev/public_html/operation/index.php`
- Modify: `/root/boot-dev/public_html/js/admin.js`

기존 js/admin.js의 탭 구조를 분석하지 않고는 정확한 통합 코드를 적기 어려움. 먼저 패턴 확인 후 적용.

- [ ] **Step 1: 기존 탭 구조 파악**

```
grep -n "탭\|tab\|navTab\|activeTab\|menu-item" /root/boot-dev/public_html/js/admin.js | head -30
```

기존 탭 추가 패턴을 확인하고 동일 패턴으로 "알림톡" 탭을 등록.
(이 step의 결과를 보고 Step 3의 정확한 통합 코드를 결정)

- [ ] **Step 2: js/notify.js 작성 — 자기완결적 위젯**

```javascript
/* Notify Admin UI — operation/head 탭 */
const NotifyApp = (() => {
  let root = null;
  let scenarios = [];

  async function init(container) {
    root = container;
    await refresh();
  }

  async function refresh() {
    root.innerHTML = '<div class="loading">알림톡 시나리오 불러오는 중…</div>';
    const r = await App.get('/api/admin.php?action=notify_list_scenarios');
    if (!r.success) { root.innerHTML = `<div class="error">${App.esc(r.error || '오류')}</div>`; return; }
    scenarios = r.scenarios || [];
    render();
  }

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
          <button data-act="send-real">지금 발송</button>
          <button data-act="send-dry">DRY 발송</button>
          <button data-act="batches">이력</button>
        </div>
        <div class="notify-row-meta">
          <span>다음 실행: ${App.esc(s.next_run_at || '-')}</span>
          <span>마지막: ${App.esc(s.last_run_at || '-')} (${App.esc(s.last_run_status || '-')})</span>
          <span>쿨다운 ${s.cooldown_hours}h / 최대 ${s.max_attempts}회</span>
        </div>
        <div class="notify-row-batches" data-role="batches"></div>
      </div>
    `).join('');
    root.innerHTML = `<h2>알림톡</h2>${rows}`;
    root.querySelectorAll('.notify-row').forEach(bindRow);
  }

  function bindRow(rowEl) {
    const key = rowEl.dataset.key;
    rowEl.querySelector('input[data-act="toggle"]').onchange = async (e) => {
      const r = await App.post('/api/admin.php?action=notify_toggle', { key, is_active: e.target.checked });
      if (!r.success) { Toast.error(r.error); refresh(); }
      else Toast.ok('변경되었습니다');
    };
    rowEl.querySelector('button[data-act="send-real"]').onclick = () => openPreview(key, false);
    rowEl.querySelector('button[data-act="send-dry"]').onclick  = () => openPreview(key, true);
    rowEl.querySelector('button[data-act="batches"]').onclick    = () => loadBatches(rowEl, key);
  }

  async function openPreview(key, dryRun) {
    App.showLoading();
    const r = await App.post('/api/admin.php?action=notify_preview', { key, dry_run: dryRun });
    App.hideLoading();
    if (!r.success) { Toast.error(r.error); return; }
    showPreviewModal(r);
  }

  function showPreviewModal(p) {
    const ovl = document.createElement('div');
    ovl.className = 'modal-overlay';
    ovl.innerHTML = `
      <div class="modal-box">
        <h3>발송 전 확인 — <span class="env env-${p.environment}">${p.environment}</span> ${p.dry_run ? '(DRY_RUN)' : '(실발송)'}</h3>
        <p>발송 대상: <strong>${p.target_count}명</strong>, 스킵: ${p.skip_count}명</p>
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
        <div class="modal-actions">
          <button data-act="cancel">취소</button>
          <button data-act="confirm" ${p.target_count === 0 ? 'disabled' : ''}>${p.target_count}명에게 ${p.dry_run ? 'DRY 발송' : '지금 발송'}</button>
        </div>
      </div>
    `;
    document.body.appendChild(ovl);
    ovl.querySelector('button[data-act="cancel"]').onclick = () => ovl.remove();
    ovl.querySelector('button[data-act="confirm"]').onclick = async () => {
      App.showLoading();
      const r = await App.post('/api/admin.php?action=notify_send_now', { preview_id: p.preview_id });
      App.hideLoading();
      if (!r.success) { Toast.error(r.error); return; }
      Toast.ok(`발송 완료 (batch ${r.batch_id})`);
      ovl.remove();
      refresh();
    };
  }

  async function loadBatches(rowEl, key) {
    const target = rowEl.querySelector('[data-role="batches"]');
    target.innerHTML = '<em>불러오는 중…</em>';
    const r = await App.get(`/api/admin.php?action=notify_list_batches&key=${encodeURIComponent(key)}&limit=15`);
    if (!r.success) { target.innerHTML = `<span class="error">${App.esc(r.error)}</span>`; return; }
    if (!r.batches || r.batches.length === 0) { target.innerHTML = '<em>이력 없음</em>'; return; }
    target.innerHTML = `
      <table class="notify-batch-table">
        <thead><tr><th>#</th><th>트리거</th><th>시작</th><th>대상</th><th>발송</th><th>실패</th><th>미확정</th><th>스킵</th><th>상태</th><th></th></tr></thead>
        <tbody>
          ${r.batches.map(b => `
            <tr>
              <td>${b.id}</td>
              <td>${App.esc(b.trigger_type)}${b.dry_run == 1 ? ' (DRY)' : ''}</td>
              <td>${App.esc(b.started_at)}</td>
              <td>${b.target_count}</td>
              <td>${b.sent_count}</td>
              <td>${b.failed_count}</td>
              <td>${b.unknown_count}</td>
              <td>${b.skipped_count}</td>
              <td><span class="status status-${App.esc(b.status)}">${App.esc(b.status)}</span></td>
              <td><button data-batch="${b.id}">상세</button></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
      <div class="batch-detail" data-role="detail"></div>
    `;
    target.querySelectorAll('button[data-batch]').forEach(btn => {
      btn.onclick = () => showBatchDetail(target.querySelector('[data-role="detail"]'), Number(btn.dataset.batch));
    });
  }

  async function showBatchDetail(target, batchId) {
    target.innerHTML = '<em>불러오는 중…</em>';
    const r = await App.get(`/api/admin.php?action=notify_batch_detail&batch_id=${batchId}`);
    if (!r.success) { target.innerHTML = `<span class="error">${App.esc(r.error)}</span>`; return; }
    const failedCount = (r.messages || []).filter(m => m.status === 'failed').length;
    target.innerHTML = `
      <h4>배치 #${r.batch.id} 메시지</h4>
      <table class="notify-msg-table">
        <thead><tr><th>이름</th><th>전화</th><th>채널</th><th>상태</th><th>사유</th></tr></thead>
        <tbody>
          ${r.messages.map(m => `
            <tr class="status-${App.esc(m.status)}">
              <td>${App.esc(m.name || '')}</td>
              <td>${App.esc(m.phone)}</td>
              <td>${App.esc(m.channel_used)}</td>
              <td>${App.esc(m.status)}${m.status === 'unknown' ? ' (조사 필요)' : ''}</td>
              <td>${App.esc(m.skip_reason || m.fail_reason || '')}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
      ${failedCount > 0 ? `<button data-act="retry">실패자 ${failedCount}명 재시도</button>` : ''}
    `;
    const retryBtn = target.querySelector('button[data-act="retry"]');
    if (retryBtn) {
      retryBtn.onclick = async () => {
        if (!confirm(`failed 상태 ${failedCount}명에게 재발송합니다.`)) return;
        App.showLoading();
        const rr = await App.post('/api/admin.php?action=notify_retry_failed', { batch_id: batchId });
        App.hideLoading();
        if (!rr.success) { Toast.error(rr.error); return; }
        Toast.ok(`재시도 배치 #${rr.batch_id} 생성됨`);
      };
    }
  }

  return { init };
})();
```

- [ ] **Step 3: css/notify.css 작성**

```css
.notify-row { border:1px solid #ddd; border-radius:6px; padding:12px; margin-bottom:12px; }
.notify-row-head { display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.notify-row-meta { color:#666; font-size:.9em; margin-top:6px; display:flex; gap:16px; flex-wrap:wrap; }
.notify-row-batches { margin-top:10px; }
.notify-preview-table, .notify-batch-table, .notify-msg-table { width:100%; border-collapse:collapse; margin-top:8px; font-size:.92em; }
.notify-preview-table th, .notify-preview-table td,
.notify-batch-table th, .notify-batch-table td,
.notify-msg-table th, .notify-msg-table td { border-bottom:1px solid #eee; padding:6px 8px; text-align:left; }
.notify-preview-table tr.skip { color:#999; }
.notify-msg-table tr.status-unknown { background:#fff8e6; }
.notify-msg-table tr.status-failed  { background:#ffeaea; }
.notify-msg-table tr.status-skipped { color:#999; }
.notify-msg-table tr.status-dry_run { color:#666; }
.status { padding:2px 8px; border-radius:10px; font-size:.85em; }
.status-completed { background:#e6f7ea; color:#1f7a37; }
.status-partial   { background:#fff4d6; color:#a16d00; }
.status-failed    { background:#ffe1e1; color:#a00000; }
.status-no_targets, .status-running { background:#eef; color:#446; }
.env-DEV { color:#a16d00; }
.env-PROD { color:#a00000; font-weight:bold; }
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.4); display:flex; align-items:center; justify-content:center; z-index:1000; }
.modal-box { background:#fff; padding:20px; border-radius:8px; max-width:760px; max-height:80vh; overflow:auto; }
.modal-actions { margin-top:12px; display:flex; gap:8px; justify-content:flex-end; }
.rendered pre { background:#f6f6f6; padding:10px; border-radius:4px; max-height:200px; overflow:auto; }
```

- [ ] **Step 4: operation/index.php에 스크립트/스타일 태그 추가**

`<head>`의 link 묶음 끝에 추가:
```html
<link rel="stylesheet" href="/css/notify.css<?= v('/css/notify.css') ?>">
```

`<body>`의 script 묶음 끝(다른 admin script들 옆)에 추가:
```html
<script src="/js/notify.js<?= v('/js/notify.js') ?>"></script>
```

- [ ] **Step 5: admin.js에 "알림톡" 탭 통합**

Step 1에서 파악한 패턴을 따라, operation 역할(또는 head 그룹)일 때 "알림톡" 탭 항목을 추가하고, 클릭 시 `NotifyApp.init(<해당 컨테이너>)` 호출. 정확한 코드는 admin.js의 기존 탭 추가 패턴을 그대로 따름. 예 (패턴이 menu-item array라면):
```javascript
// 기존 탭 정의 배열 어딘가에:
{ id: 'notify', label: '알림톡', roles: ['operation','head','subhead1','subhead2'],
  render: (container) => NotifyApp.init(container) }
```
패턴이 다르면 동등한 진입 지점에 배치.

- [ ] **Step 6: 브라우저에서 동작 검증**

DEV(https://dev-boot.soritune.com/operation)에 operation 계정으로 로그인 → "알림톡" 탭 클릭 → `form_reminder_ot` 시나리오 row 보임 → 토글/이력 등 클릭 동작.

(시트 ID가 placeholder라 실제 미리보기는 source 호출 실패할 것 — 다음 task에서 해결)

- [ ] **Step 7: Commit**

```
cd /root/boot-dev && \
  git add public_html/js/notify.js public_html/css/notify.css \
          public_html/operation/index.php public_html/js/admin.js && \
  git commit -m "feat(notify): 운영 화면 — 시나리오 목록/토글/미리보기 모달/배치 이력"
```

---

## Task 12: 종단 검증 (DRY_RUN으로 첫 시나리오 실행)

placeholder 값을 임시 실값으로 채워 DRY_RUN으로 종단 흐름이 동작함을 확인. 검증 후 placeholder로 되돌리거나 실값을 운영자가 결정.

**Files:**
- Modify: `/root/boot-dev/public_html/includes/notify/scenarios/form_reminder_ot.php` (사용자 실 시트로 임시 교체)

- [ ] **Step 1: 사용자가 검증용 시트 ID/탭/컬럼/templateId 제공**

(이 step은 사용자 협업 필요. 검증용 작은 테스트 시트가 좋음)

- [ ] **Step 2: scenario 파일에 임시 실값 입력 + DRY_RUN 모드 확인**

`keys/solapi.json`의 `dry_run_default: true` 확인 (DEV).

- [ ] **Step 3: API로 종단 흐름 실행**

```
COOKIE=/tmp/notify_test_cookies.txt
# 1) 미리보기
curl -sS -b $COOKIE -X POST -H 'Content-Type: application/json' \
  -d '{"key":"form_reminder_ot","dry_run":true}' \
  "https://dev-boot.soritune.com/api/admin.php?action=notify_preview" | jq .

# preview_id 받아서:
curl -sS -b $COOKIE -X POST -H 'Content-Type: application/json' \
  -d "{\"preview_id\":\"<위에서 받은 ID>\"}" \
  "https://dev-boot.soritune.com/api/admin.php?action=notify_send_now" | jq .

# 배치 상세
curl -sS -b $COOKIE \
  "https://dev-boot.soritune.com/api/admin.php?action=notify_batch_detail&batch_id=<위 응답 batch_id>" | jq .
```

Expected:
- `preview` 응답: `target_count > 0` (시트의 N 행 수만큼), `rendered_first` 객체 정상
- `send_now` 응답: `batch_id` 반환
- `batch_detail` 응답: 메시지들이 모두 `status='dry_run'`, `channel_used='none'`

- [ ] **Step 4: 두 번째 발송으로 cooldown 동작 확인**

같은 시나리오에 대해 다시 preview→send_now (DRY_RUN). 두 번째 배치는 모든 메시지가 `skipped`(reason=`cooldown`) 이어야 함. 단, DRY_RUN은 lookup에서 제외되므로 `cooldown`은 sent/unknown 상태가 있어야만 발생. 따라서 cooldown 검증은 비-DRY 환경에서 별도. DEV에서는 skip 검증을 위해 `keys/solapi.json`에서 잠시 `dry_run_default: false`로 하고 mock으로 DB에 status='sent' row 1개 직접 INSERT 후 preview에서 cooldown 처리되는지 확인하는 식으로 대체.

대안 검증:
```sql
INSERT INTO notify_message
  (batch_id, scenario_key, row_key, phone, name, template_id,
   channel_used, status, sent_at, processed_at)
VALUES
  (1, 'form_reminder_ot', 'sheet:test:test:1', '<시트의 첫 N행 전화번호>', 'test',
   'KA01TP', 'alimtalk', 'sent', NOW(), NOW());
```
그 후 preview를 다시 호출해 그 전화번호가 `skips[reason=cooldown]`에 들어가는지 확인.

- [ ] **Step 5: 운영 화면에서 시각적 검증**

DEV 운영 화면에서 같은 시나리오의 미리보기→발송→이력→배치 상세 흐름이 UI로 매끄럽게 동작하는지 클릭으로 확인. unknown 상태 표시(노란 배경), skip/failed 색상 적용 확인.

- [ ] **Step 6: 검증 후 시나리오를 다시 placeholder로 복원**

```
git checkout -- public_html/includes/notify/scenarios/form_reminder_ot.php
```

(또는 사용자가 실값을 그대로 유지하기로 결정 시 step skip)

- [ ] **Step 7: dev push + 운영 반영 확인 요청**

```
cd /root/boot-dev && git push origin dev
```

이 시점에서 ⛔ **사용자에게 dev 검증 요청 → 운영 반영 명시적 승인 후에만** main 머지/PROD 적용 진행 (메모리 규칙).

운영 반영 시 별도 단계:
1. `cd /root/boot-dev && git checkout main && git merge dev && git push origin main && git checkout dev`
2. `cd /root/boot-prod && git pull origin main`
3. PROD에서 `php migrate_notify_tables.php`
4. `keys/solapi.json` PROD에 별도 작성 (`dry_run_default: false`, 실 pfId/from)
5. PROD crontab에 디스패처 라인 1줄 추가 (Task 8 Step 4 형식, 경로 `_______site_SORITUNECOM_BOOT/`)
6. 시나리오 정의의 placeholder를 실값으로 교체 (코드 수정은 boot-dev → push → main → pull 흐름)
7. PROD 운영 화면에서 DRY_RUN으로 첫 발송 → 실 1건 발송(`row_keys` 1개) → 전체 발송 → 안정화 후 `is_active=1` 토글

---

## Self-Review

스펙 대비 task 매핑 점검:

| 스펙 항목 | 구현 task |
|---|---|
| 시나리오 정의 형식 (코드) | Task 9 (placeholder), 등록부는 Task 6 |
| 데이터 어댑터 인터페이스 | Task 4 (google_sheet) |
| 변수 치환 규칙 (col:/const:) | Task 3 |
| 전화번호 정규화 | Task 3 |
| DB 4개 테이블 | Task 1 |
| 쿨다운/최대횟수 (sent+unknown 포함) | Task 7 (dispatcher 내부 쿼리) |
| 시나리오별 락 (is_running) | Task 7 (claim/finally) |
| 디스패처 cron 매칭 + flock | Task 7 + Task 8 (cron.php) |
| 솔라피 HMAC + 페이로드 | Task 5 |
| DRY_RUN 분기 | Task 7 (dispatcher) + Task 5 (keys 로드) |
| LMS 폴백 1차 비활성 | Task 5/7 (코드만 두고 호출 안 함) |
| 에러/재시도 정책 (unknown/failed) | Task 7 (notifyMapSolapiResponse) |
| 배치 status 결정 규칙 | Task 7 (notifyDecideBatchStatus) |
| processed_at vs sent_at | Task 1 (스키마) + Task 7 (UPDATE) |
| 7개 API 액션 | Task 10 |
| 운영 화면 (목록/모달/이력) | Task 11 |
| 권한 (operation+head 그룹) | Task 10 (NOTIFY_ROLES 상수) |
| preview_id 흐름 (1회용/만료/created_by) | Task 10 (handleNotifyPreview/SendNow) |
| 만료 preview 청소 | Task 7 (notifyDispatch) |
| 시스템 crontab 1줄 | Task 8 (DEV) + Task 12 Step 7 (PROD) |
| 마이그레이션 | Task 1 |
| 보안 (keys 파일 600/.gitignore) | Task 2 |

스펙 항목 모두 task에 매핑됨. 누락 없음.

타입 일관성:
- `NOTIFY_ROLES` Task 10에서 정의, 모든 handler 사용 ✓
- `notifyRunScenario` 시그니처 Task 7에서 정의, Task 10의 send_now/retry_failed에서 동일하게 호출 ✓
- `notify_message.status` enum 6개(`queued/sent/failed/skipped/dry_run/unknown`) Task 1에 정의, Task 7/10/11에서 동일 사용 ✓
- `notify_batch.status` enum 5개(`running/completed/partial/failed/no_targets`) Task 1에 정의, Task 7의 결정 규칙과 Task 11 CSS의 `.status-*` 클래스가 일치 ✓

placeholder/누락 점검: 모든 step에 실 코드/명령 포함, "TBD"/"적절히"/"비슷하게" 없음. ✓

---

**Plan complete and saved to `docs/superpowers/plans/2026-04-23-notify-alimtalk.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — task마다 새 subagent dispatch + 검토 사이 빠른 피드백
**2. Inline Execution** — 이 세션에서 task 묶어 실행, 체크포인트 검토

**Which approach?**
