# 알림톡 발송 시스템 (boot.soritune.com)

## 문제

운영팀이 특정 조건(예: 구글폼 미제출)에 해당하는 회원에게 카카오톡 알림톡을 보내야 한다. 현재는 수동 안내 또는 외부 도구로 처리하고 있어 누락·중복·이력 추적 부재의 문제가 있다.

핵심 요구:
- 조건과 발송 정의는 개발자가 코드로 작성 (운영팀이 UI에서 등록하지 않음)
- 운영팀은 관리 화면에서 (1) 즉시 발송 버튼, (2) 발송 결과 확인, (3) 스케줄 발송 on/off 토글
- 메시지는 솔라피(Solapi) 알림톡으로 발송, 시나리오별로 LMS 폴백 여부 선택
- 같은 사람에게 도배되지 않도록 쿨다운 + 최대 발송 횟수로 차단

## 결정 요약

| 항목 | 결정 |
|---|---|
| 발송 채널 | 솔라피 알림톡 (시나리오별 LMS 폴백 on/off) |
| 시나리오 단위 | 데이터 소스 1개 + 솔라피 템플릿 1개 + 스케줄 1개 |
| 데이터 소스 | 어댑터 패턴 (`google_sheet` / `db_query` 같은 인터페이스) |
| 시트 발송 후 처리 | read-only (시트의 N→Y 전환은 구글폼 수식이 처리) |
| 중복 차단 | 쿨다운 시간 + 최대 발송 횟수 (시나리오별 설정) |
| 시나리오 정의 위치 | PHP 파일에 코드 (운영 토글·발송 로그만 DB) |
| 트리거 | 스케줄 + 즉시 발송 둘 다 (모든 시나리오 공통) |
| 스케줄 메커니즘 | 분당 디스패처 cron 1줄 + 시나리오 정의 안의 cron 식 |
| 운영 화면 | 시나리오 목록·on-off, 배치 이력, 배치 상세, 실패자 재시도, 발송 전 미리보기 |
| 권한 | operation / head / subhead1 / subhead2 모두 발송·토글 가능 |
| DRY_RUN 모드 | 추가 (DEV 기본 ON, PROD 기본 OFF). 실제 솔라피 호출 없이 로그만 |
| 자동 재시도 | 없음 (운영팀 수동 재시도만, 도배 방지) |

## 아키텍처

```
┌──────────────────────┐    ┌──────────────────────┐
│ 시나리오 정의 (코드) │    │ 솔라피 자격증명       │
│ includes/notify/     │    │ keys/solapi.json     │
│   scenarios/*.php    │    │ (gitignored)         │
└──────────┬───────────┘    └──────────┬───────────┘
           │                           │
           ▼                           │
┌──────────────────────┐               │
│ 디스패처 cron        │               │
│ (분당 1회)           │               │
│ cron.php             │               │
│   notify_dispatch    │               │
└──────────┬───────────┘               │
           │ "지금 시각이 매칭되는      │
           │  시나리오 + on 상태"       │
           ▼                           │
┌──────────────────────┐    ┌─────────▼────────────┐
│ 데이터 소스 어댑터   │    │ 솔라피 클라이언트     │
│ - GoogleSheetSource  │    │ - 알림톡 + LMS 폴백   │
│ - DbQuerySource      │    │ - DRY_RUN 분기        │
└──────────┬───────────┘    └──────────┬───────────┘
           │ rows[]                    │ result[]
           └────────────┬──────────────┘
                        ▼
           ┌──────────────────────┐
           │ 발송 엔진            │
           │ - 쿨다운/최대횟수     │
           │   체크               │
           │ - 배치/메시지 기록   │
           └──────────┬───────────┘
                      │
              ┌───────▼────────┐
              │ DB 로그 테이블 │
              │ (3개)          │
              └───────┬────────┘
                      │
                      ▼
           ┌──────────────────────┐
           │ 운영 화면 (SPA)      │
           │ /operation           │
           │ + API services       │
           └──────────────────────┘
```

## 파일 배치

```
public_html/
├── api/services/
│   └── notify.php                    ← 운영 화면용 API
├── includes/
│   └── notify/
│       ├── scenarios/                ← 시나리오 정의 (1파일=1시나리오)
│       │   └── form_reminder_ot.php  ← 첫 시나리오 예시
│       ├── scenario_registry.php     ← scenarios/*.php 자동 로드
│       ├── source_google_sheet.php   ← 데이터 어댑터 (시트)
│       ├── source_db_query.php       ← 데이터 어댑터 (DB)
│       ├── solapi_client.php         ← 솔라피 HTTP 호출 + DRY_RUN
│       ├── dispatcher.php            ← cron 매칭 + 발송 엔진
│       └── notify_functions.php      ← 공통 유틸 (변수 치환, 전화 정규화)
└── cron.php                          ← case 'notify_dispatch' 추가

keys/
└── solapi.json                       ← {apiKey, apiSecret, defaultPfId, defaultFrom, dry_run_default} (gitignored)

operation/index.php                   ← AdminApp에 "알림톡" 탭 추가
js/notify.js                          ← 운영 화면 JS
```

시스템 crontab 추가 1줄:
```cron
* * * * * /usr/bin/php /var/www/html/_______site_SORITUNECOM_BOOT/public_html/cron.php notify_dispatch >> /var/www/html/_______site_SORITUNECOM_BOOT/logs/notify.log 2>&1
```

## 시나리오 정의 형식

`public_html/includes/notify/scenarios/form_reminder_ot.php`:

```php
<?php
return [
    'key'         => 'form_reminder_ot',
    'name'        => 'OT 출석 폼 미제출자 리마인드',
    'description' => '구글시트 OT_제출 컬럼이 N인 회원에게 폼 작성 안내',

    'source' => [
        'type'        => 'google_sheet',
        'sheet_id'    => '1AbCDeFGhIJklMNOpQrStUvWxYz',
        'tab'         => 'OT명단',
        'range'       => 'A1:G500',
        'check_col'   => 'OT_제출',           // 헤더명, 'N'인 행만 대상
        'phone_col'   => '연락처',
        'name_col'    => '이름',
    ],

    'template' => [
        // pfId는 keys/solapi.json의 defaultPfId 자동 사용
        'templateId'   => 'KA01TP...',
        'fallback_lms' => false,
        'variables' => [
            '#{name}'     => 'col:이름',       // 그 행의 컬럼값
            '#{deadline}' => 'const:4월 30일', // 모든 수신자 공통 상수
        ],
    ],

    // 'pfId'는 keys/solapi.json의 defaultPfId 사용. 다중 채널 필요 시
    // 'pfId_override' 필드를 추후 추가.

    'schedule'       => '0 21 * * *',         // cron 식 (매일 21:00)
    'cooldown_hours' => 24,
    'max_attempts'   => 3,
];
```

권한은 1차 범위에서 시나리오 정의에 두지 않고 **API 진입 시점에 일괄 적용** (operation/head/subhead1/subhead2 = 모두 동일 권한). 시나리오마다 권한이 달라야 하는 요구가 생기면 그때 시나리오 정의에 `roles_view`/`roles_send` 필드 추가.
```

### 데이터 어댑터 인터페이스

각 source 어댑터는 다음 한 가지 메서드를 구현:

```php
interface NotifySource {
    /**
     * @return array 발송 후보 행 리스트.
     * 각 행:
     * [
     *   'row_key' => 'sheet:1AbC...:OT명단:7',  // 멱등성 키 (행 식별)
     *   'phone'   => '010-1234-5678',           // 정규화 전 원본
     *   'name'    => '홍길동',
     *   'columns' => ['이름' => '홍길동', '연락처' => '...', 'OT_제출' => 'N', ...],
     * ]
     * 어댑터는 check_col == 'N'인 행만 반환할 책임이 있음.
     */
    public function fetchTargets(array $sourceConfig): array;
}
```

- `GoogleSheetSource`: 기존 `cron/GoogleSheets.php`(readonly) 재사용
- `DbQuerySource`: 시나리오 정의에 SQL 쿼리를 박아두는 형태로 시작 (구현은 첫 시나리오 이후 미래 작업)

### 변수 치환 규칙

| prefix | 의미 | 예 |
|---|---|---|
| `col:헤더명` | 그 행의 컬럼값 | `'#{name}' => 'col:이름'` |
| `const:문자열` | 모든 수신자 공통 상수 | `'#{deadline}' => 'const:4월 30일'` |

### 전화번호 정규화

`010-1234-5678` / `01012345678` / `+82 10-1234-5678` 등을 `01012345678` 형태로 통일. 정규화 실패 시 그 행은 `skipped` (`skip_reason='phone_invalid'`).

## DB 스키마

DEV: `SORITUNECOM_DEV_BOOT`, PROD: `SORITUNECOM_BOOT`. 양쪽 동일 적용.

### 1. `notify_scenario_state` — 시나리오 운영 상태

```sql
CREATE TABLE notify_scenario_state (
    scenario_key      VARCHAR(64)  NOT NULL PRIMARY KEY,
    is_active         TINYINT(1)   NOT NULL DEFAULT 0,      -- 스케줄 on/off
    is_running        TINYINT(1)   NOT NULL DEFAULT 0,      -- 시나리오별 실행 락
    running_since     DATETIME     NULL,                    -- 락 획득 시각 (stale 감지용)
    last_run_at       DATETIME     NULL,
    last_run_status   VARCHAR(20)  NULL,                    -- success / partial / no_targets / failed
    last_batch_id     BIGINT       NULL,
    notes             TEXT         NULL,
    updated_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by        VARCHAR(64)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- 시나리오 코드 파일이 새로 생기면 디스패처가 자동으로 row 생성 (UPSERT, 기본 `is_active=0`)
- `is_active=0` 이면 스케줄 발송 안 됨. 단 **수동 발송 버튼은 동작** (신규 시나리오 검증용)
- **시나리오별 실행 락**: `runScenario` 시작 시 단일 UPDATE로 claim:
  ```sql
  UPDATE notify_scenario_state
     SET is_running=1, running_since=NOW()
   WHERE scenario_key=?
     AND (is_running=0 OR running_since < NOW() - INTERVAL 10 MINUTE)
  ```
  affected_rows=0이면 락 획득 실패 → 즉시 종료. 완료 시 `is_running=0`로 해제.
  디스패처/`send_now`/`retry_failed` 모두 같은 패턴 사용. flock은 디스패처에만 보조로 유지.

### 2. `notify_batch` — 발송 배치 (1 트리거 = 1 배치)

```sql
CREATE TABLE notify_batch (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    scenario_key    VARCHAR(64)  NOT NULL,
    trigger_type    ENUM('schedule','manual','retry') NOT NULL,
    triggered_by    VARCHAR(64)  NULL,
    started_at      DATETIME     NOT NULL,
    finished_at     DATETIME     NULL,
    target_count    INT          NOT NULL DEFAULT 0,
    sent_count      INT          NOT NULL DEFAULT 0,
    failed_count    INT          NOT NULL DEFAULT 0,
    unknown_count   INT          NOT NULL DEFAULT 0,        -- timeout/5xx 등 미확정
    skipped_count   INT          NOT NULL DEFAULT 0,
    dry_run         TINYINT(1)   NOT NULL DEFAULT 0,
    status          ENUM('running','completed','partial','failed','no_targets') NOT NULL,
    error_message   TEXT         NULL,
    INDEX idx_scenario_started (scenario_key, started_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**최종 status 결정 규칙** (디스패처 자체 예외 없이 정상 종료한 경우):

| 조건 | status |
|---|---|
| `target_count = 0` | `no_targets` |
| `sent_count > 0` 이고 `failed_count + unknown_count = 0` | `completed` |
| `sent_count > 0` 이고 (`failed_count > 0` 또는 `unknown_count > 0`) | `partial` |
| `sent_count = 0` 이고 (`failed_count > 0` 또는 `unknown_count > 0`) | `failed` |
| 디스패처 자체 예외 | `failed` (+ `error_message` 기록) |

`scenario_state.last_run_status`는 위 batch.status와 동일 값으로 갱신.

### 3. `notify_message` — 개별 수신자/메시지

```sql
CREATE TABLE notify_message (
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
    sent_at           DATETIME     NULL,                    -- 실발송(status='sent')에만 채움
    processed_at      DATETIME     NULL,                    -- sent/failed/skipped/dry_run/unknown 모두 채움
    created_at        TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cooldown (scenario_key, phone, status, processed_at),
    INDEX idx_batch (batch_id),
    INDEX idx_solapi (solapi_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**상태 의미**

| status | 솔라피 호출 | 추정 결과 | 쿨다운/최대횟수에 카운트? |
|---|---|---|---|
| `queued` | 아직 안 함 | — | 카운트 안 됨 (in-flight) |
| `sent` | 성공 응답(2000 등) | 솔라피가 접수 확인 | **카운트** |
| `failed` | 4xx 등 명확한 실패 | 솔라피가 거절 확인 | 카운트 안 됨 (재시도 가능) |
| `unknown` | 5xx / timeout / 네트워크 오류 | **알 수 없음** (서버에 접수됐을 수도) | **카운트** (도배 방지 우선) |
| `skipped` | 안 함 | 정책으로 사전 차단 | 카운트 안 됨 |
| `dry_run` | 안 함 (DRY_RUN 모드) | — | 카운트 안 됨 |

`sent_at`은 `status='sent'`일 때만 채움. 그 외 상태는 모두 `processed_at`만 채움. (감사·분석 명확성)

### 핵심 쿼리

쿨다운 체크 (도배 방지를 위해 `unknown`도 포함):
```sql
SELECT MAX(processed_at) FROM notify_message
WHERE scenario_key = ? AND phone = ?
  AND status IN ('sent','unknown')
  AND processed_at >= NOW() - INTERVAL ? HOUR
```

최대횟수 체크:
```sql
SELECT COUNT(*) FROM notify_message
WHERE scenario_key = ? AND phone = ?
  AND status IN ('sent','unknown')
```

DRY_RUN 메시지(`status='dry_run'`)는 **쿨다운/최대횟수에서 제외** — DEV에서 반복 검증해도 PROD 정책 영향 없음.

**재시도 정책**: "실패자 재시도" 버튼은 `status='failed'`만 대상. `unknown`은 별도 "조사 필요" 라벨로 표시되며 운영자가 솔라피 콘솔에서 실 발송 여부 확인 후 수동 판단 (자동 재시도 안 함).

### 4. `notify_preview` — 미리보기 토큰

미리보기→발송 race 및 임의 row_keys 주입 방지용.

```sql
CREATE TABLE notify_preview (
    id            CHAR(32)     NOT NULL PRIMARY KEY,        -- 랜덤 hex 토큰 (preview_id)
    scenario_key  VARCHAR(64)  NOT NULL,
    dry_run       TINYINT(1)   NOT NULL,
    row_keys      JSON         NOT NULL,                    -- 발송 대상 row_key 배열
    target_count  INT          NOT NULL,
    created_by    VARCHAR(64)  NOT NULL,                    -- admin id
    created_at    DATETIME     NOT NULL,
    expires_at    DATETIME     NOT NULL,                    -- created_at + 10분
    used_at       DATETIME     NULL,                        -- 발송에 사용된 시각 (1회용)
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**흐름**:
1. `preview` API 호출 → 어댑터 실행, 정책 적용, 본문 1건 렌더링.
   동시에 `notify_preview` row 생성, `preview_id` 반환.
2. 사용자가 모달에서 발송 버튼 클릭 → `send_now`에 `preview_id`만 전달.
3. 서버는 `preview_id`로 row 조회 → `created_by` 일치, 만료 전, `used_at` NULL 검증 → `used_at` 채우고 그 row의 `row_keys`/`scenario_key`/`dry_run`으로 발송.
4. 검증 실패 시 발송 거부 (만료 등).

만료된 preview row는 디스패처가 가끔 청소(예: 1시간에 한 번 `expires_at < NOW() - INTERVAL 1 DAY` 삭제).

### 마이그레이션

기존 패턴(`/root/boot-dev/migrate_*.php`)을 따라 `migrate_notify_tables.php` 1개로 작성 (4 테이블 모두 포함). DEV에서 먼저 실행 후 검증, 운영 반영 시 PROD에서도 1회 실행.

## 디스패처 동작

### 진입점: `cron.php notify_dispatch`

```
1. flock('/tmp/notify_dispatch.lock') — 동시 실행 방지. 못 잡으면 즉시 종료.
2. keys/solapi.json 로드 (없으면 종료)
3. includes/notify/scenarios/*.php 모두 로드 → 메모리 등록부
4. notify_scenario_state UPSERT (신규 시나리오는 is_active=0으로 row 생성)
5. 등록부 순회:
     for each scenario:
         if not is_active: skip
         if scenario['schedule'] cron 식이 "현재 분"과 매칭 안 되면: skip
         runScenario(scenario, trigger='schedule')
6. flock 해제 후 종료
```

### `runScenario($scenario, $trigger, $triggered_by=null, $dry_run=null, $row_keys_filter=null)`

스케줄/수동/재시도가 모두 같은 함수 호출:

```
1. 시나리오별 락 claim (UPDATE notify_scenario_state SET is_running=1 WHERE scenario_key=?
   AND (is_running=0 OR running_since < NOW() - INTERVAL 10 MINUTE))
   → affected_rows=0이면 즉시 종료 ("이미 실행 중")
2. notify_batch INSERT (status='running', dry_run 결정)
   - dry_run 인자 명시되면 그것 사용
   - 아니면 keys.dry_run_default 사용 (DEV true / PROD false)
3. source 어댑터 호출 → rows[]
   - 예외 시 batch.status='failed', error_message 기록 + 락 해제 후 종료
   - row_keys_filter가 주어지면 그 row만 골라냄 (preview_id 검증 후 또는 재시도 시)
4. for each row:
     - 전화번호 정규화 → 실패 시 status='skipped', skip_reason='phone_invalid', processed_at=now
     - 쿨다운 체크 (sent+unknown 포함) → 걸리면 status='skipped', skip_reason='cooldown'
     - 최대횟수 체크 (sent+unknown 포함) → 걸리면 status='skipped', skip_reason='max_attempts'
     - 변수 치환 → rendered_text
     - notify_message INSERT (status='queued')
5. queued 메시지 묶음 → 솔라피 send-many 호출 (또는 DRY_RUN 분기)
6. 응답 파싱 → 메시지별로 status(sent/failed/unknown)/channel_used/solapi_message_id/fail_reason
   /sent_at(sent만)/processed_at 업데이트
7. notify_batch UPDATE (sent/failed/unknown/skipped 카운트, finished_at,
   status= [no_targets/completed/partial/failed 결정 규칙 적용])
8. notify_scenario_state UPDATE (last_run_at, last_run_status, last_batch_id, is_running=0)
```

`finally` 보장: 어떤 경로로 종료되든 `is_running=0` 해제 (예외 발생 시에도 `try/finally` 또는 `register_shutdown_function`으로 락 해제).

### cron 식 매칭

5필드 표준 cron(`분 시 일 월 요일`). 자체 PHP 구현 (composer 미사용).
지원 표현: `*` / `숫자` / `A-B` / `A,B,C` / `*/N`.

### 동시 실행 방지

2단계 보호:
1. **디스패처 전체 락 (보조)**: `flock('/tmp/notify_dispatch.lock')`. 분당 cron 겹침 방지.
2. **시나리오별 DB 락 (필수)**: `notify_scenario_state.is_running` claim. 디스패처/`send_now`/`retry_failed` 모두 같은 시나리오 동시 실행 차단. stale lock(>10분)은 자동 해제.

## 솔라피 클라이언트

### 호출

```
POST https://api.solapi.com/messages/v4/send-many/detail

Authorization: HMAC-SHA256 apiKey=<key>, date=<ISO8601>, salt=<random>, signature=<HMAC_SHA256(date+salt, secret)>
Content-Type: application/json
```

### 페이로드 (알림톡)

```json
{
  "messages": [
    {
      "to": "01012345678",
      "from": "025001111",                  // keys.defaultFrom (LMS 폴백용 발신번호)
      "kakaoOptions": {
        "pfId": "KA01PF...",
        "templateId": "KA01TP...",
        "variables": {
          "#{name}": "홍길동",
          "#{deadline}": "4월 30일"
        }
      },
      "type": "ATA"
    }
  ]
}
```

### LMS 폴백 — 1차 비활성, 응답 모델 검증 후 활성화

솔라피 `send-many/detail`의 응답에서 **개별 메시지 status·errorCode·typeCode가 즉시 확정되는지** (= webhook 없이 폴백 판단 가능한지)를 구현 단계 첫머리에 반드시 검증해야 한다. 즉시 확정이 안 되면 (큐잉만 되고 결과는 비동기) 우리 쪽 2단계 폴백은 동작하지 않으며 webhook 또는 polling이 필요하다.

**1차 범위 동작**:
1. 모든 시나리오는 `fallback_lms=false`로 시작 → 알림톡 단독 발송만 사용
2. 솔라피 클라이언트 코드는 폴백 분기를 구현해두되 호출 경로 비활성
3. 응답 모델 검증 + 작은 실 테스트로 검증되면 시나리오별로 `fallback_lms=true` 활성화

**활성화 시 흐름**:
1. 1차: 알림톡(`type:'ATA'`) 단독 발송
2. 응답이 즉시 확정 실패코드(예: 4044 카톡친구 아님 등)이고 `fallback_lms=true`면 → LMS(`type:'LMS'`)로 재호출
3. 결과를 같은 `notify_message` 행의 `channel_used`에 `'alimtalk'` 또는 `'lms'`로 기록
4. 응답이 미확정(`unknown`)이면 폴백 안 함 (도배 방지) — 운영자가 솔라피 콘솔에서 실 발송 여부 확인 후 결정

### DRY_RUN 분기

```php
if ($dry_run) {
    foreach ($queuedMessages as $m) {
        // 솔라피 호출 안 함
        $m->status = 'dry_run';
        $m->channel_used = 'none';
        $m->sent_at = null;             // 실발송이 아니므로 NULL
        $m->processed_at = now();
        // rendered_text는 INSERT 시 이미 채워져 있어 운영 화면에서 본문 검수 가능
    }
}
```

DEV/PROD 기본값은 `keys/solapi.json`의 `dry_run_default` 플래그로 제어 (DEV true, PROD false). 운영 화면 수동 발송 시 명시적 `dry_run=true/false` 토글 가능.

### 에러/재시도 정책

| 상황 | 메시지 status | 비고 |
|---|---|---|
| 솔라피 HTTP 5xx / timeout / 네트워크 오류 | **`unknown`** | 서버 접수됐을 수 있음 → 쿨다운/최대횟수 카운트, 자동 재시도 안 함 |
| 솔라피 4xx (페이로드 오류, 잘못된 변수, 차단 등 즉시 확정) | `failed` | 응답 본문 그대로 `fail_reason` 저장. 재시도 가능. |
| 알림톡 실패 + `fallback_lms=true` (활성 후) | LMS 재호출 1회 | 그것도 실패하면 `failed` |
| 디스패처 자체 예외 (DB 다운 등) | — | 배치 `status='failed'`, error_message 기록, 락 해제 |
| 자동 재시도 | — | **없음** — 운영팀 수동 재시도만 (`failed`만 대상) |
| `unknown` 처리 | — | 운영 화면에 "조사 필요" 라벨, 솔라피 콘솔에서 실 발송 여부 확인 후 수동 판단 |

## 운영 화면 (`/operation` SPA의 "알림톡" 탭)

### 화면 1 — 시나리오 목록 (메인)

행 컴포넌트:
- 시나리오 이름 + 설명 (펼치면 정의 노출: sheet ID, 컬럼, 쿨다운/최대횟수, 다음 실행 예정)
- **스케줄 on/off 토글**
- **[지금 발송] 버튼** → 미리보기 모달
- **[DRY] 버튼** → DRY_RUN 모드 미리보기→발송
- **[이력] 버튼** → 배치 이력
- 마지막 실행 요약 (성공/실패/스킵 카운트, 클릭 시 해당 배치 상세)

### 화면 2 — 발송 전 미리보기 모달 (필수 단계)

- 모달 열 때 `preview` API 호출 → 어댑터 실행, 정책 적용, 본문 1건 렌더링, **`preview_id` 발급** (서버에 row_keys 저장)
- 쿨다운/최대횟수 적용해 "발송 vs 스킵" 사전 분류
- 본문 미리보기(첫 1건) — 변수 치환된 실제 본문
- **race·주입 방지**: 발송 시 `send_now`에는 `preview_id`만 전달. 서버는 `created_by`/만료/1회용 검증 후 저장된 `row_keys`로 발송. 임의 row_key 주입 불가, 오래된 미리보기 발송 불가.
- 환경 표시(DEV/PROD)로 잘못된 환경 발송 방지

### 화면 3 — 배치 이력 + 배치 상세

- 좌측: 시나리오의 최근 배치 리스트 (트리거 타입, 시각, 카운트, 상태[completed/partial/failed/no_targets])
- 우측: 선택한 배치의 메시지 리스트 (이름/전화/채널/상태/사유). `unknown` 상태는 별도 색·"조사 필요" 라벨.
- **[실패자만 재시도]** 버튼 = 같은 시나리오 새 배치 생성, `trigger_type='retry'`, **`status='failed'`인 row_key만 대상** (`unknown`은 자동 재시도 안 함)
- 재시도도 쿨다운/최대횟수(`sent`+`unknown` 포함)에 걸리면 다시 skipped

### API (`/api/services/notify.php`)

| action | 메서드 | 권한 | 설명 |
|---|---|---|---|
| `list_scenarios` | GET | view | 시나리오 + state + 다음 실행 예정 시각 |
| `toggle` | POST | send | `{key, is_active}` 스케줄 on/off |
| `preview` | POST | send | `{key, dry_run}` → 어댑터 호출 + 쿨다운 적용한 발송 후보 + 본문 1건 + **`preview_id` 발급** |
| `send_now` | POST | send | `{preview_id}` → 서버 저장된 row_keys/dry_run/scenario로 발송 (1회용, 만료 10분, `created_by` 일치 검증) |
| `list_batches` | GET | view | `{key, limit}` → 시나리오별 최근 배치 |
| `batch_detail` | GET | view | `{batch_id}` → 메시지 리스트 |
| `retry_failed` | POST | send | `{batch_id}` → 그 배치의 failed 메시지 재시도 (새 배치) |

권한: `view`/`send` 모두 `operation/head/subhead1/subhead2` 중 하나의 역할 보유 시 허용. 시나리오별 차등 권한은 1차 범위 외.

### 감사 로그

별도 테이블 없이 `notify_batch.triggered_by` + `notify_scenario_state.updated_by`로 추적.

## 보안

- `keys/solapi.json` 은 `.gitignore`에 등록, 권한 600.
- API key/secret은 코드/로그에 절대 출력하지 않음.
- 운영 화면의 모든 send 액션은 admin 세션 + 권한 검사.
- 본 설계 작성 시점에 채팅에 노출된 키는 작업 완료 후 솔라피 콘솔에서 폐기·재발급 권장.

## 테스트 전략

- 단위: cron 식 매처, 변수 치환, 전화번호 정규화 — 작은 PHP 테스트 스크립트
- 통합 (DEV, DRY_RUN ON):
  1. 시나리오 1개 등록 → 디스패처 1분 실행 → batch row 생성 확인
  2. 운영 화면에서 "지금 발송 (DRY)" → 미리보기→발송→배치 상세 흐름 확인
  3. 같은 발송 2회 → 두 번째는 cooldown으로 skip 확인
  4. `max_attempts=1` 설정 후 두 번째 발송 → skip 확인
- 운영 첫 발송 (PROD):
  1. 시나리오 등록 + `is_active=0` (수동만)
  2. 운영 화면에서 DRY_RUN으로 미리보기 → 본문/명단 확인
  3. 실발송 1건만 (row_keys 1개로) → 실제 알림톡 수신 확인
  4. 전체 발송 → 배치 상세에서 채널/실패 확인
  5. 안정화 후 `is_active=1` 토글로 스케줄 활성화

## 범위에서 제외 (1차 작업 아님)

- 솔라피 webhook 수신 (전송완료/읽음 상태 동기화)
- 시나리오 정의 UI (DB 등록·수정)
- 다중 카카오 채널 (현재 채널 1개만 지원, 필요 시 시나리오에 `pfId_override` 필드 추가)
- 친구톡(CTA) 지원 (현재 알림톡만)
- 발송 비용 통계/예산 알림
- 발송 이력 보존기간 정책 (TTL 삭제) — 데이터 누적 양 보고 추후 결정
