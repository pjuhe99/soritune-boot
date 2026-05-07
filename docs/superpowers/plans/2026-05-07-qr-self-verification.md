# QR 출석/패자부활 본인 확인 강화 — 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 익명 임의 ID 출석/패자부활 통로를 막되 운영 유연성은 유지. 회원 세션 강제 + reserve-first race 가드 + actor audit log + open redirect 차단.

**Architecture:** boot.soritune.com 의 PHP+MariaDB 스택. `api/qr.php` 의 4개 공개 case 에 `requireMember()` 추가, `record`/`revival_record` 는 케이스 본문을 helper 함수로 추출하고 reserve-first 패턴 (UNIQUE 가드 first → side effects later)으로 재배치. 프론트엔드는 `qr/index.php` 의 자체 `api()` 헬퍼에 401 redirect 추가, `MemberApp.init()` 에 returnTo 파라미터 처리 + `^/qr/` 화이트리스트.

**Tech Stack:** PHP 8.5, MariaDB 10.5, vanilla JS (PHP 임베드), boot 자체 CLI 테스트 패턴 (`tests/` + `t()` 헬퍼).

**Spec:** `docs/superpowers/specs/2026-05-07-qr-self-verification-design.md` (commit `df2b890`)

---

## 파일 구조

**신규**
- `migrate_qr_audit.php` — `qr_attendance.actor_member_id` + `revival_logs.actor_member_id` + `revival_logs.qr_session_id` 컬럼 추가 (idempotent)
- `public_html/includes/qr_actions.php` — `qrRecordAttendance()` / `qrRecordRevival()` 헬퍼 (case 본문 추출 + 테스트 가능)
- `tests/qr_auth_invariants.php` — CLI 테스트

**수정**
- `public_html/api/qr.php` — 4개 case 에 `requireMember()`, `record`/`revival_record` case 본문을 헬퍼 호출로 교체
- `public_html/qr/index.php` — `api()` 함수에 401 catch + `/?returnTo=` redirect
- `public_html/js/member.js` — `MemberApp.init()` 에 returnTo 파라미터 처리, `validateReturnTo()` 헬퍼 추가

---

## Task 1: 마이그 스크립트

**Files:**
- Create: `migrate_qr_audit.php`

- [ ] **Step 1: 마이그 스크립트 작성**

```php
<?php
/**
 * boot.soritune.com - qr_attendance + revival_logs 에 audit 컬럼 추가
 * 사용: php migrate_qr_audit.php
 * DEV/PROD 각각 실행. 멱등.
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

function indexExists(PDO $db, string $dbName, string $table, string $idx): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$dbName, $table, $idx]);
    return (bool)$stmt->fetchColumn();
}

$alters = [
    ['qr_attendance', 'col', 'actor_member_id',
        "ALTER TABLE qr_attendance ADD COLUMN actor_member_id INT UNSIGNED NULL COMMENT '실제 요청한 회원 (member_id 와 다르면 대리 출석)' AFTER member_id"],
    ['qr_attendance', 'idx', 'idx_qa_actor',
        "ALTER TABLE qr_attendance ADD KEY idx_qa_actor (actor_member_id)"],
    ['revival_logs',  'col', 'actor_member_id',
        "ALTER TABLE revival_logs ADD COLUMN actor_member_id INT UNSIGNED NULL AFTER member_id"],
    ['revival_logs',  'col', 'qr_session_id',
        "ALTER TABLE revival_logs ADD COLUMN qr_session_id INT UNSIGNED NULL AFTER actor_member_id"],
    ['revival_logs',  'idx', 'idx_rl_actor',
        "ALTER TABLE revival_logs ADD KEY idx_rl_actor (actor_member_id)"],
    ['revival_logs',  'idx', 'idx_rl_session',
        "ALTER TABLE revival_logs ADD KEY idx_rl_session (qr_session_id)"],
];

foreach ($alters as [$table, $kind, $name, $sql]) {
    $exists = ($kind === 'col')
        ? columnExists($db, $dbName, $table, $name)
        : indexExists($db, $dbName, $table, $name);
    if ($exists) {
        echo "SKIP  {$table}.{$kind}.{$name} (already exists)\n";
        continue;
    }
    $db->exec($sql);
    echo "ADD   {$table}.{$kind}.{$name}\n";
}

echo "\nDone. DB: {$dbName}\n";
```

- [ ] **Step 2: DEV 실행**

Run: `cd /root/boot-dev && php migrate_qr_audit.php`
Expected:
```
ADD   qr_attendance.col.actor_member_id
ADD   qr_attendance.idx.idx_qa_actor
ADD   revival_logs.col.actor_member_id
ADD   revival_logs.col.qr_session_id
ADD   revival_logs.idx.idx_rl_actor
ADD   revival_logs.idx.idx_rl_session

Done. DB: SORITUNECOM_DEV_BOOT
```

- [ ] **Step 3: 멱등성 검증 — 한 번 더 실행**

Run: `cd /root/boot-dev && php migrate_qr_audit.php`
Expected: 6개 모두 `SKIP ... (already exists)`

- [ ] **Step 4: DB 직접 확인**

Run: `cd /root/boot-dev && source .db_credentials && mariadb -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "DESC qr_attendance; DESC revival_logs;"`
Expected: `qr_attendance.actor_member_id`, `revival_logs.actor_member_id`, `revival_logs.qr_session_id` 컬럼 존재.

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev
git add migrate_qr_audit.php
git commit -m "$(cat <<'EOF'
feat(qr): #2 audit 컬럼 마이그 스크립트

qr_attendance.actor_member_id + revival_logs.actor_member_id + qr_session_id.
NULL 허용, FK 없음, idempotent (column/index 존재 검사).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: QR 액션 헬퍼 추출 — `qrRecordAttendance()`

case 본문을 includes 모듈로 옮겨 테스트 가능하게 만든다. 동작 변경 없이 추출만.

**Files:**
- Create: `public_html/includes/qr_actions.php`
- Modify: `public_html/api/qr.php:277-350` (record case 본문)

- [ ] **Step 1: 헬퍼 모듈 작성 — 기존 동작 그대로**

Create `public_html/includes/qr_actions.php`:

```php
<?php
/**
 * boot.soritune.com - QR action 헬퍼
 * api/qr.php 의 record / revival_record 본문을 추출.
 */

require_once __DIR__ . '/bootcamp_functions.php';
require_once __DIR__ . '/coin_functions.php';

/**
 * QR 출석 처리 — reserve-first 패턴.
 *
 * @return array {
 *   ok: bool,
 *   already?: bool,
 *   member_name?: string,
 *   error?: string,
 *   http_status?: int,    // error 일 때
 * }
 */
function qrRecordAttendance(PDO $db, array $session, int $memberId, ?int $actorMemberId, string $clientIp, string $userAgent): array {
    // 멤버 검증
    $memberStmt = $db->prepare("
        SELECT id, nickname, group_id, cohort_id FROM bootcamp_members
        WHERE id = ? AND cohort_id = ? AND is_active = 1 AND member_status != 'refunded'
    ");
    $memberStmt->execute([$memberId, $session['cohort_id']]);
    $member = $memberStmt->fetch();
    if (!$member) {
        return ['ok' => false, 'error' => '유효하지 않은 회원입니다.', 'http_status' => 400];
    }

    // Reserve-first: UNIQUE 가드 가장 먼저
    $insert = $db->prepare("
        INSERT IGNORE INTO qr_attendance (qr_session_id, member_id, actor_member_id, group_id, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([$session['id'], $memberId, $actorMemberId, $member['group_id'], $clientIp, $userAgent]);

    if ($insert->rowCount() === 0) {
        // 이미 처리된 회원
        return ['ok' => true, 'already' => true, 'member_name' => $member['nickname']];
    }

    // 첫 처리: saveCheck 부수 효과
    $studyLink = $db->prepare("SELECT id, study_date FROM study_sessions WHERE qr_session_id = ?");
    $studyLink->execute([$session['id']]);
    $studyRow = $studyLink->fetch();

    if ($studyRow) {
        $missionCode = 'bookclub_join';
        $checkDate = $studyRow['study_date'];
        $sourceRef = 'study_qr:' . $studyRow['id'];
    } else {
        $missionCode = 'zoom_daily';
        $checkDate = date('Y-m-d');
        $sourceRef = 'qr_session:' . $session['session_code'];
    }

    $missionTypeId = getMissionTypeId($db, $missionCode);
    if ($missionTypeId) {
        saveCheck(
            $db,
            $memberId,
            $checkDate,
            $missionTypeId,
            1,                              // pass
            'manual',                       // QR 은 manual (automation 보다 우선)
            $sourceRef,
            $session['admin_id'] ? (int)$session['admin_id'] : null
        );
    }

    return ['ok' => true, 'already' => false, 'member_name' => $member['nickname']];
}
```

- [ ] **Step 2: api/qr.php record case 를 헬퍼 호출로 교체**

Modify `public_html/api/qr.php:277-350`. 기존 case 'record': 본문 전체 (라인 277~350 사이) 를 다음으로 교체:

```php
case 'record':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);

    $s = requireMember();   // ← 회원 세션 강제
    $input = getJsonInput();
    $code = trim($input['session_code'] ?? '');
    $memberId = (int)($input['member_id'] ?? 0);
    if (!$code || !$memberId) jsonError('session_code와 member_id가 필요합니다.');

    $db = getDB();
    $session = getActiveSession($db, $code);
    if (!$session || $session['status'] !== 'active') {
        jsonError('세션이 만료되었거나 종료되었습니다.');
    }

    require_once __DIR__ . '/../includes/qr_actions.php';

    $result = qrRecordAttendance(
        $db,
        $session,
        $memberId,
        (int)$s['member_id'],
        getClientIP(),
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    );

    if (!$result['ok']) {
        jsonError($result['error'], $result['http_status'] ?? 400);
    }

    jsonSuccess([
        'member_name' => $result['member_name'],
        'already'     => $result['already'],
    ]);
    break;
```

- [ ] **Step 3: DEV 빠른 회귀 — 기존 출석 동작 확인**

Run (브라우저 또는 curl 로 DEV QR 시나리오):
1. DEV 에서 admin 으로 QR 세션 생성
2. 회원 로그인 후 record 액션 호출 → 정상 200 + `actor_member_id = 본인 member_id` 확인
3. 같은 회원으로 한 번 더 호출 → `already: true`

```bash
cd /root/boot-dev && source .db_credentials && mariadb -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT id, qr_session_id, member_id, actor_member_id, scanned_at FROM qr_attendance ORDER BY id DESC LIMIT 5;"
```

Expected: 가장 최근 row 의 `actor_member_id` 가 NULL 아닌 회원 ID.

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev
git add public_html/includes/qr_actions.php public_html/api/qr.php
git commit -m "$(cat <<'EOF'
feat(qr): #2 record 액션 reserve-first + actor_member_id

qrRecordAttendance() 헬퍼로 추출. requireMember() 강제.
INSERT IGNORE qr_attendance 를 가장 먼저 → rowCount===0 이면 즉시 already 반환,
saveCheck 부수 효과는 첫 호출에서만 실행.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: QR 액션 헬퍼 — `qrRecordRevival()`

**Files:**
- Modify: `public_html/includes/qr_actions.php` (헬퍼 추가)
- Modify: `public_html/api/qr.php:352-460` (revival_record case 본문)

- [ ] **Step 1: qr_actions.php 에 헬퍼 추가**

Append to `public_html/includes/qr_actions.php`:

```php
/**
 * QR 패자부활 처리 — reserve-first 패턴.
 *
 * @return array {
 *   ok: bool,
 *   already?: bool,
 *   not_eligible?: bool,
 *   member_name?: string,
 *   before_score?: int,
 *   after_score?: int,
 *   bonus?: int,
 *   current_score?: int,    // not_eligible 일 때
 *   error?: string,
 *   http_status?: int,
 * }
 */
function qrRecordRevival(PDO $db, array $session, int $memberId, ?int $actorMemberId, string $clientIp, string $userAgent): array {
    if (($session['session_type'] ?? '') !== 'revival') {
        return ['ok' => false, 'error' => '패자부활 세션이 아닙니다.', 'http_status' => 400];
    }

    // 멤버 검증
    $memberStmt = $db->prepare("
        SELECT id, nickname, group_id, cohort_id FROM bootcamp_members
        WHERE id = ? AND cohort_id = ? AND is_active = 1 AND member_status != 'refunded'
    ");
    $memberStmt->execute([$memberId, $session['cohort_id']]);
    $member = $memberStmt->fetch();
    if (!$member) {
        return ['ok' => false, 'error' => '유효하지 않은 회원입니다.', 'http_status' => 400];
    }

    // Reserve-first: UNIQUE 가드 가장 먼저
    $insert = $db->prepare("
        INSERT IGNORE INTO qr_attendance (qr_session_id, member_id, actor_member_id, group_id, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([$session['id'], $memberId, $actorMemberId, $member['group_id'], $clientIp, $userAgent]);

    if ($insert->rowCount() === 0) {
        return ['ok' => true, 'already' => true, 'member_name' => $member['nickname']];
    }

    // 점수 최신화 + 부적격 체크
    ensureMemberScoreFresh($db, $memberId);
    $scoreStmt = $db->prepare("SELECT current_score FROM member_scores WHERE member_id = ?");
    $scoreStmt->execute([$memberId]);
    $scoreRow = $scoreStmt->fetch();
    $beforeScore = $scoreRow ? (int)$scoreRow['current_score'] : 0;

    if ($beforeScore > SCORE_REVIVAL_ELIGIBLE) {
        // 가드 row 는 이미 만들어졌음 — 의도된 동작 (재진입 차단)
        return [
            'ok' => true,
            'not_eligible' => true,
            'member_name' => $member['nickname'],
            'current_score' => $beforeScore,
        ];
    }

    // 점수 +7 적용
    $afterScore = $beforeScore + SCORE_REVIVAL_BONUS;
    $change = SCORE_REVIVAL_BONUS;
    $sessionAdminId = $session['admin_id'] ? (int)$session['admin_id'] : null;
    $note = 'QR 패자부활 (세션: ' . $session['session_code'] . ')';

    // revival_logs (qr_session_id 포함, actor 기록)
    $db->prepare("
        INSERT INTO revival_logs (member_id, actor_member_id, qr_session_id, before_score, after_score, note, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$memberId, $actorMemberId, $session['id'], $beforeScore, $afterScore, $note, $sessionAdminId]);

    // score_logs
    $db->prepare("
        INSERT INTO score_logs (member_id, score_change, before_score, after_score, reason_type, reason_detail, created_by)
        VALUES (?, ?, ?, ?, 'revival_adjustment', ?, ?)
    ")->execute([$memberId, $change, $beforeScore, $afterScore, $note, $sessionAdminId]);

    // member_scores 갱신
    $db->prepare("
        INSERT INTO member_scores (member_id, current_score, last_calculated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE current_score = VALUES(current_score), last_calculated_at = NOW()
    ")->execute([$memberId, $afterScore]);

    // 조관리 제외 상태 해제
    if ($afterScore > SCORE_OUT_THRESHOLD) {
        $db->prepare("UPDATE bootcamp_members SET member_status = 'active' WHERE id = ? AND member_status = 'out_of_group_management'")
           ->execute([$memberId]);
    }

    return [
        'ok' => true,
        'already' => false,
        'not_eligible' => false,
        'member_name' => $member['nickname'],
        'before_score' => $beforeScore,
        'after_score' => $afterScore,
        'bonus' => $change,
    ];
}
```

- [ ] **Step 2: api/qr.php revival_record case 를 헬퍼 호출로 교체**

Modify `public_html/api/qr.php:352-` 의 `case 'revival_record':` 본문 전체 (return 응답 직전까지) 를 다음으로 교체:

```php
case 'revival_record':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);

    $s = requireMember();   // ← 회원 세션 강제
    $input = getJsonInput();
    $code = trim($input['session_code'] ?? '');
    $memberId = (int)($input['member_id'] ?? 0);
    if (!$code || !$memberId) jsonError('session_code와 member_id가 필요합니다.');

    $db = getDB();
    $session = getActiveSession($db, $code);
    if (!$session || $session['status'] !== 'active') {
        jsonError('세션이 만료되었거나 종료되었습니다.');
    }

    require_once __DIR__ . '/../includes/qr_actions.php';

    $result = qrRecordRevival(
        $db,
        $session,
        $memberId,
        (int)$s['member_id'],
        getClientIP(),
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    );

    if (!$result['ok']) {
        jsonError($result['error'], $result['http_status'] ?? 400);
    }

    if (!empty($result['already'])) {
        jsonSuccess([
            'member_name' => $result['member_name'],
            'already' => true,
        ], '이미 패자부활이 처리되었습니다.');
    }

    if (!empty($result['not_eligible'])) {
        jsonSuccess([
            'member_name' => $result['member_name'],
            'not_eligible' => true,
            'current_score' => $result['current_score'],
        ], '패자부활전 대상이 아닙니다. (현재 점수: ' . $result['current_score'] . '점)');
    }

    jsonSuccess([
        'member_name'  => $result['member_name'],
        'not_eligible' => false,
        'before_score' => $result['before_score'],
        'after_score'  => $result['after_score'],
        'bonus'        => $result['bonus'],
    ], '패자부활 처리되었습니다. (+' . $result['bonus'] . '점)');
    break;
```

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev
git add public_html/includes/qr_actions.php public_html/api/qr.php
git commit -m "$(cat <<'EOF'
feat(qr): #2 revival_record reserve-first + actor + qr_session_id

qrRecordRevival() 헬퍼로 추출. IP+UA 가드 제거하고 UNIQUE 가드 first.
부적격 케이스도 가드 row 보존 (점수 회복 후 재진입 차단).
revival_logs 에 actor_member_id + qr_session_id 기록.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: 나머지 case 에 requireMember()

**Files:**
- Modify: `public_html/api/qr.php:234-273` (groups, group_members case)

- [ ] **Step 1: groups + group_members case 에 requireMember() 추가**

Modify `public_html/api/qr.php:234` 부근의 `case 'groups':` 본문 시작에 추가:

```php
case 'groups':
    requireMember();   // ← 추가
    $code = trim($_GET['code'] ?? '');
    // ... 기존 코드
```

같은 방식으로 `case 'group_members':` 본문 시작 (`public_html/api/qr.php:255` 부근) 에 추가:

```php
case 'group_members':
    requireMember();   // ← 추가
    $code = trim($_GET['code'] ?? '');
    // ... 기존 코드
```

`case 'verify':` 는 그대로 (회원 세션 불필요).

- [ ] **Step 2: 빠른 검증 — 비로그인 차단 확인**

Run:
```bash
curl -s "https://dev-boot.soritune.com/api/qr.php?action=groups&code=anycode" | head -3
```
Expected: `{"success":false,"error":"로그인이 필요합니다."}` (HTTP 401)

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/qr.php
git commit -m "$(cat <<'EOF'
feat(qr): #2 groups/group_members 회원 세션 강제

비로그인 시 401. verify 는 변경 없음 (코드 검증만).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: 프론트엔드 qr/index.php 401 redirect

**Files:**
- Modify: `public_html/qr/index.php` (api() 헬퍼)

- [ ] **Step 1: api() 헬퍼에 401 처리 추가**

Modify `public_html/qr/index.php` 의 `async function api(url, options = {})` 함수를 다음으로 교체:

```javascript
async function api(url, options = {}) {
    const resp = await fetch(url, {
        method: options.method || 'GET',
        headers: options.body ? { 'Content-Type': 'application/json' } : {},
        body: options.body ? JSON.stringify(options.body) : undefined,
    });
    if (resp.status === 401) {
        // 회원 세션 없음 → 로그인 페이지로 (현재 URL 보존)
        const returnTo = encodeURIComponent(window.location.pathname + window.location.search);
        window.location.href = '/?returnTo=' + returnTo;
        // pending Promise — 페이지 전환됨
        return new Promise(() => {});
    }
    return resp.json();
}
```

- [ ] **Step 2: 빠른 수동 검증 — 비로그인 redirect**

Browser:
1. 로그아웃 상태에서 `https://dev-boot.soritune.com/qr/?code=ANYVALIDCODE` 방문
2. verify 까지는 정상 (페이지 로드)
3. 조 목록 로드 단계 (`groups` 호출) 에서 401 → `/?returnTo=%2Fqr%2F%3Fcode%3D...` 로 redirect 확인

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev
git add public_html/qr/index.php
git commit -m "$(cat <<'EOF'
feat(qr): #2 frontend 401 redirect with returnTo

api() 가 401 받으면 /?returnTo=<현재 URL> 로 이동.
verify 는 비로그인 가능하므로 페이지 진입 자체는 막지 않음.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: MemberApp returnTo 처리 + 화이트리스트

**Files:**
- Modify: `public_html/js/member.js:1-80` (MemberApp init + showLoginForm)

- [ ] **Step 1: validateReturnTo() 추가 + init/login 흐름에 적용**

Modify `public_html/js/member.js`. `const MemberApp = (() => {` 블록 안의 IIFE 본문 시작 부근(상수/state 선언 다음, `async function init()` 직전)에 헬퍼 추가:

```javascript
// returnTo 화이트리스트 — open redirect 차단
function validateReturnTo(returnTo) {
    if (typeof returnTo !== 'string' || !returnTo) return null;
    // 절대 URL / protocol-relative / 다른 호스트 차단
    if (returnTo.startsWith('//') || returnTo.startsWith('http://') || returnTo.startsWith('https://')) {
        return null;
    }
    // 허용 prefix: /qr/ 만
    if (!returnTo.startsWith('/qr/')) return null;
    return returnTo;
}

function consumeReturnTo() {
    const params = new URLSearchParams(window.location.search);
    const raw = params.get('returnTo');
    return validateReturnTo(raw);
}
```

기존 `async function init()` 끝 부분 (`showDashboard()` 호출 케이스) 을 수정:

```javascript
async function init() {
    root = document.getElementById('member-root');

    App.showLoading();
    const r = await App.get('/api/member.php?action=check_session');
    App.hideLoading();

    if (r.logged_in) {
        member = r.member;
        if (member.needs_nickname) {
            showNicknameSetup();
        } else {
            // 이미 로그인된 상태에서 returnTo 있으면 즉시 이동
            const rt = consumeReturnTo();
            if (rt) {
                window.location.href = rt;
                return;
            }
            showDashboard();
        }
    } else {
        showLoginForm();
    }
}
```

기존 `showLoginForm()` 함수의 onsubmit handler 안 (`if (r.success) { ... showDashboard(); }`) 을 수정:

```javascript
if (r.success) {
    member = r.member;
    Toast.success(r.message);
    if (member.needs_nickname) {
        showNicknameSetup();
    } else {
        const rt = consumeReturnTo();
        if (rt) {
            window.location.href = rt;
            return;
        }
        showDashboard();
    }
}
```

`showNicknameSetup()` 의 닉네임 저장 후에도 동일 처리:

```javascript
if (r.success) {
    member.nickname = r.nickname;
    member.needs_nickname = false;
    Toast.success(r.message);
    const rt = consumeReturnTo();
    if (rt) {
        window.location.href = rt;
        return;
    }
    showDashboard();
}
```

- [ ] **Step 2: 빠른 수동 검증 — 화이트리스트**

Browser DevTools console 에서:
```javascript
// 페이지 로드 후 (MemberApp scope 외에서는 직접 호출 불가)
// 대신 URL 직접 변경으로 검증:
```

1. 로그아웃 → `/?returnTo=https://example.com` 방문 → 로그인 후 `/` 에 머무름 (returnTo 무시)
2. 로그아웃 → `/?returnTo=//example.com` 방문 → 로그인 후 `/` 에 머무름
3. 로그아웃 → `/?returnTo=/qr/?code=xxx` 방문 → 로그인 후 `/qr/?code=xxx` 로 이동

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/member.js
git commit -m "$(cat <<'EOF'
feat(qr): #2 MemberApp returnTo 처리 + ^/qr/ 화이트리스트

로그인/이미-로그인/닉네임 저장 후 returnTo 검증 통과 시 이동.
^/qr/ 만 허용, // 와 절대 URL 무시 (open redirect 차단).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: CLI 테스트 스크립트

**Files:**
- Create: `tests/qr_auth_invariants.php`

- [ ] **Step 1: 테스트 스크립트 작성**

Create `tests/qr_auth_invariants.php`:

```php
<?php
/**
 * QR 본인 확인 인보리언트 테스트
 * 사용: php tests/qr_auth_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/qr_actions.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; }
    else { $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n"; }
}

$db = getDB();

// ── Setup: 가짜 attendance QR session + 테스트 회원 (12기 활성 그룹) ──
// PROD 데이터 영향 없도록 transaction 사용
$db->beginTransaction();

try {
    // 첫 12기 활성 그룹 + 그 그룹의 활성 회원 2명 가져오기
    $cohort = $db->query("SELECT id FROM cohorts WHERE cohort = '12기' LIMIT 1")->fetch();
    $cohortId = (int)$cohort['id'];
    $group = $db->prepare("SELECT id FROM bootcamp_groups WHERE cohort_id = ? LIMIT 1");
    $group->execute([$cohortId]);
    $groupId = (int)$group->fetch()['id'];

    $members = $db->prepare("
        SELECT id FROM bootcamp_members
        WHERE cohort_id = ? AND group_id = ? AND is_active = 1 AND member_status != 'refunded'
        LIMIT 2
    ");
    $members->execute([$cohortId, $groupId]);
    $rows = $members->fetchAll();
    if (count($rows) < 2) {
        echo "SKIP  insufficient test data (need ≥2 active members in a 12기 group)\n";
        $db->rollBack();
        exit(0);
    }
    $memberA = (int)$rows[0]['id'];
    $memberB = (int)$rows[1]['id'];

    // 테스트용 attendance QR session
    $db->prepare("
        INSERT INTO qr_sessions (session_code, session_type, admin_id, cohort_id, status, expires_at, created_at)
        VALUES ('test_att_xxxxx', 'attendance', NULL, ?, 'active', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
    ")->execute([$cohortId]);
    $attSessionId = (int)$db->lastInsertId();
    $attSession = $db->query("SELECT * FROM qr_sessions WHERE id = $attSessionId")->fetch();

    // ── 1. 본인 출석 ──
    $r = qrRecordAttendance($db, $attSession, $memberA, $memberA, '127.0.0.1', 'test-ua');
    t('record: 본인 첫 출석', $r['ok'] === true && $r['already'] === false);

    $row = $db->query("SELECT actor_member_id FROM qr_attendance WHERE qr_session_id = $attSessionId AND member_id = $memberA")->fetch();
    t('record: actor_member_id == member_id 기록', (int)$row['actor_member_id'] === $memberA);

    // ── 2. 동일 회원 중복 호출 (race 시뮬) ──
    $r2 = qrRecordAttendance($db, $attSession, $memberA, $memberA, '127.0.0.1', 'test-ua');
    t('record: 중복 호출 already=true', $r2['ok'] === true && $r2['already'] === true);

    // saveCheck 부수 효과는 1회만 적용됐는지 — 첫 호출에서만 record 가 만들어졌어야 함
    $cnt = $db->prepare("SELECT COUNT(*) FROM qr_attendance WHERE qr_session_id = ? AND member_id = ?");
    $cnt->execute([$attSessionId, $memberA]);
    t('record: qr_attendance 중복 행 없음', (int)$cnt->fetchColumn() === 1);

    // ── 3. 대리 출석 (memberA 가 memberB 의 출석을 찍음) ──
    $r3 = qrRecordAttendance($db, $attSession, $memberB, $memberA, '127.0.0.1', 'test-ua');
    t('record: 대리 출석 정상', $r3['ok'] === true && $r3['already'] === false);

    $proxyRow = $db->query("SELECT actor_member_id FROM qr_attendance WHERE qr_session_id = $attSessionId AND member_id = $memberB")->fetch();
    t('record: 대리 시 actor_member_id != member_id', (int)$proxyRow['actor_member_id'] === $memberA);

    // ── 4. revival 세션 셋업 ──
    $db->prepare("
        INSERT INTO qr_sessions (session_code, session_type, admin_id, cohort_id, status, expires_at, created_at)
        VALUES ('test_rev_xxxxx', 'revival', NULL, ?, 'active', DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())
    ")->execute([$cohortId]);
    $revSessionId = (int)$db->lastInsertId();
    $revSession = $db->query("SELECT * FROM qr_sessions WHERE id = $revSessionId")->fetch();

    // 대상 회원 점수를 -10 으로 강제 (테스트용)
    $db->prepare("INSERT INTO member_scores (member_id, current_score, last_calculated_at) VALUES (?, -10, NOW()) ON DUPLICATE KEY UPDATE current_score = -10")->execute([$memberA]);

    $r4 = qrRecordRevival($db, $revSession, $memberA, $memberA, '127.0.0.1', 'test-ua');
    t('revival: 적격 회원 첫 부활', $r4['ok'] === true && empty($r4['already']) && empty($r4['not_eligible']) && $r4['after_score'] === -3);

    $r5 = qrRecordRevival($db, $revSession, $memberA, $memberA, '127.0.0.1', 'test-ua');
    t('revival: 같은 세션 중복 호출 already=true', $r5['ok'] === true && $r5['already'] === true);

    // revival_logs 가 1건만 있어야 함
    $rcnt = $db->prepare("SELECT COUNT(*) FROM revival_logs WHERE qr_session_id = ? AND member_id = ?");
    $rcnt->execute([$revSessionId, $memberA]);
    t('revival: revival_logs 중복 없음', (int)$rcnt->fetchColumn() === 1);

    // ── 5. 부적격 회원 — 점수 회복 후 재진입 차단 ──
    // memberB 점수 +5 로 강제 (부적격)
    $db->prepare("INSERT INTO member_scores (member_id, current_score, last_calculated_at) VALUES (?, 5, NOW()) ON DUPLICATE KEY UPDATE current_score = 5")->execute([$memberB]);

    $r6 = qrRecordRevival($db, $revSession, $memberB, $memberB, '127.0.0.1', 'test-ua');
    t('revival: 부적격 not_eligible=true', $r6['ok'] === true && !empty($r6['not_eligible']));

    // 가드 row 는 만들어졌는지
    $guardCnt = $db->prepare("SELECT COUNT(*) FROM qr_attendance WHERE qr_session_id = ? AND member_id = ?");
    $guardCnt->execute([$revSessionId, $memberB]);
    t('revival: 부적격이어도 가드 row 생성', (int)$guardCnt->fetchColumn() === 1);

    // 점수 조작 시뮬: memberB 점수를 -10 으로 내리고 재호출 → 같은 세션 차단되는지
    $db->prepare("UPDATE member_scores SET current_score = -10 WHERE member_id = ?")->execute([$memberB]);
    $r7 = qrRecordRevival($db, $revSession, $memberB, $memberB, '127.0.0.1', 'test-ua');
    t('revival: 부적격 후 점수 조작 재진입 차단', $r7['ok'] === true && $r7['already'] === true);

    // revival_logs 는 memberB 에 대해 0건이어야 함
    $rcntB = $db->prepare("SELECT COUNT(*) FROM revival_logs WHERE qr_session_id = ? AND member_id = ?");
    $rcntB->execute([$revSessionId, $memberB]);
    t('revival: 부적격 회원은 revival_logs 미생성', (int)$rcntB->fetchColumn() === 0);

} finally {
    // 모든 테스트 데이터 롤백
    $db->rollBack();
}

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 테스트 실행**

Run: `cd /root/boot-dev && php tests/qr_auth_invariants.php`
Expected: 모든 테스트 PASS, 마지막 줄 `12 passed, 0 failed.`

만약 `SKIP insufficient test data` 메시지: DEV DB 12기 활성 그룹에 회원 2명 이상 있는지 확인. 메모리 기준 320명 있으니 정상 케이스.

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev
git add tests/qr_auth_invariants.php
git commit -m "$(cat <<'EOF'
test(qr): #2 본인 확인 인보리언트 테스트

- 본인 출석 + actor_member_id 기록
- 중복 출석 → already, 부수 효과 1회만
- 대리 출석 → actor != member 기록
- revival 적격/부적격 분기 + 부적격 가드 row + 점수 조작 재진입 차단

트랜잭션 + rollback 으로 PROD 데이터 영향 없음.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: 통합 smoke + DEV push

**Files:**
- 변경 없음 (검증 + git push 만)

- [ ] **Step 1: 전체 테스트 실행**

Run: `cd /root/boot-dev && php tests/qr_auth_invariants.php`
Expected: `12 passed, 0 failed.`

- [ ] **Step 2: 비로그인 차단 검증 (curl)**

Run:
```bash
for action in groups group_members record revival_record; do
    if [ "$action" = "groups" ] || [ "$action" = "group_members" ]; then
        echo "--- GET $action ---"
        curl -s "https://dev-boot.soritune.com/api/qr.php?action=$action&code=test" | head -1
    else
        echo "--- POST $action ---"
        curl -sX POST "https://dev-boot.soritune.com/api/qr.php?action=$action" \
            -H "Content-Type: application/json" \
            -d '{"session_code":"test","member_id":1}' | head -1
    fi
done
```
Expected: 4개 모두 `{"success":false,"error":"로그인이 필요합니다."}`

- [ ] **Step 3: verify 는 비로그인 가능**

Run:
```bash
curl -s "https://dev-boot.soritune.com/api/qr.php?action=verify&code=anything" | head -1
```
Expected: `{"success":true,"valid":false,"reason":"not_found"}` (200 OK, 회원 세션 없어도 동작)

- [ ] **Step 4: 브라우저 수동 시나리오**

1. **시나리오 A**: 로그아웃 상태에서 DEV admin 으로 새 attendance QR 세션 만들고, 일반 브라우저(시크릿 창) 에서 그 QR URL 접근 → verify 통과 → 조 클릭 시 로그인 페이지로 redirect (returnTo 포함) → 휴대폰 입력 후 로그인 → 자동으로 QR 페이지 복귀 → 조원 클릭 → 출석 처리 성공

2. **시나리오 B**: `/?returnTo=https://example.com` 직접 방문 → 로그인 → `/` 에 머무름 (open redirect 차단)

3. **시나리오 C**: revival QR 세션 생성, 점수 -10 인 회원으로 시나리오 A 와 같이 → +7 점수 + 메시지 확인

- [ ] **Step 5: 로그/audit 확인**

Run:
```bash
cd /root/boot-dev && source .db_credentials && mariadb -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
SELECT id, qr_session_id, member_id, actor_member_id, scanned_at
  FROM qr_attendance ORDER BY id DESC LIMIT 10;
SELECT id, member_id, actor_member_id, qr_session_id, before_score, after_score, created_at
  FROM revival_logs ORDER BY id DESC LIMIT 5;
"
```
Expected: 시나리오 A/C 에서 만든 row 의 `actor_member_id` 가 비어있지 않음. revival_logs 에 `qr_session_id` 채워짐.

- [ ] **Step 6: dev push**

```bash
cd /root/boot-dev
git status   # 클린 한지 확인
git push origin dev
```

- [ ] **Step 7: ⛔ 사용자 검증 대기**

dev 배포 완료. 사용자에게 다음 보고:
- 12 passed (CLI 테스트)
- 비로그인 4개 endpoint 차단 확인
- DEV 브라우저 수동 시나리오 통과 (출석/부활/returnTo 화이트리스트)
- audit log column 정상 채워짐

사용자가 운영 반영 명시 요청 시에만 PROD 진행 (별도 작업 — main 머지 + boot-prod pull + PROD 마이그 + smoke).

---

## Self-Review

### Spec coverage 체크

- [x] 회원 세션 강제 (4개 case) — Task 2, 3, 4
- [x] reserve-first 패턴 (record + revival_record) — Task 2 Step 1, Task 3 Step 1
- [x] actor_member_id audit log — Task 1 마이그 + Task 2/3 INSERT
- [x] revival_logs 에 qr_session_id — Task 1 마이그 + Task 3 INSERT
- [x] FK 없이 인덱스만 — Task 1 마이그 (인덱스만, FK 없음)
- [x] returnTo 화이트리스트 (`^/qr/`) — Task 6 validateReturnTo
- [x] frontend 401 redirect — Task 5
- [x] 부적격 케이스 가드 row 보존 — Task 3 Step 1 + Task 7 시나리오 5
- [x] IP+UA 가드 제거 — Task 3 Step 1 (qrRecordRevival 에 SELECT 없음)
- [x] verify 는 비로그인 가능 (변경 없음) — Task 4 명시

### 9개 spec 시나리오 커버리지

1. 비로그인 차단 → Task 8 Step 2 (curl 4개)
2. 본인 출석 → Task 7 시나리오 1
3. 대리 출석 → Task 7 시나리오 3
4. 중복 출석 race → Task 7 시나리오 2 (sequential 시뮬, 동일 효과)
5. 중복 패자부활 race → Task 7 시나리오 4 (동일)
6. 로그인 redirect + returnTo → Task 8 Step 4 시나리오 A
7. returnTo open redirect 차단 → Task 8 Step 4 시나리오 B
8. 부적격 + 가드 row + 재진입 차단 → Task 7 시나리오 5
9. verify 비로그인 가능 → Task 8 Step 3

전부 커버됨.

### Type/시그니처 일관성

- `qrRecordAttendance($db, $session, $memberId, $actorMemberId, $clientIp, $userAgent)` — Task 2 정의, Task 2 Step 2 호출, Task 7 호출 일치
- `qrRecordRevival(...)` 동일
- `validateReturnTo(returnTo): string|null` — Task 6 정의, `consumeReturnTo()` 가 호출

일관성 확인됨.

---

## 안 함

- PROD 반영 (별도 작업; 사용자 명시 요청 후 main 머지 + boot-prod git pull + PROD 마이그 + smoke)
- 마이그 이전 데이터 백필 (요청 주체 알 수 없음)
- 어드민 audit 검토 페이지 (별도 spec)
- saveCheck/applyCoinChange 의 BEGIN/COMMIT 트랜잭션화 (#3 spec 에서)
- admin endpoint (`create_session`, `close_session`) 변경 (이미 requireAdmin 으로 보호됨)
