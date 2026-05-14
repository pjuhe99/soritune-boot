# 운영자 문의 큐 자동 검사 + 일괄 자동 해결 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 운영자 화면 `/operation/#issues` 에 pending 문의별 미션 체크 상태를 자동 표시하고, 모두 체크된 문의를 단건/일괄로 운영자 버튼 한 번에 `resolved` 처리.

**Architecture:** read-only 검사 헬퍼 (`inspectIssueMission`) + 두 개의 신규 admin API (`issue_admin_resolve_auto`, `issue_admin_resolve_auto_bulk`) + 기존 `issue_admin_list` 응답 확장. 자동 cron 없음. 회원 화면/알림 변경 없음. DB 마이그 없음.

**Tech Stack:** PHP 8 (PDO), MariaDB 10.5, 바닐라 JS (admin-issues.js), CSS (admin-issues.css). 테스트는 `php tests/*.php` CLI 패턴.

**Spec:** `docs/superpowers/specs/2026-05-14-issues-auto-resolve-design.md`

---

## File Structure

| 파일 | 역할 | 변경 |
|------|------|------|
| `public_html/api/services/issue_report.php` | 핸들러 + 헬퍼 | 추가 (헬퍼 1, 핸들러 2) |
| `public_html/api/bootcamp.php` | 라우팅 | case 2개 추가 |
| `public_html/js/admin-issues.js` | 운영자 UI | chip 렌더 + 자동 해결 버튼 (단건/일괄) |
| `public_html/css/admin-issues.css` | chip 스타일 | mission-chip 3종 추가 |
| `public_html/operation/index.php` | 자원 버전 갱신 | `v()` 캐시버스터 자동 |
| `tests/issue_auto_resolve_test.php` | 단위 + DB write 테스트 | 신규 |
| `tests/issue_auto_resolve_invariants.php` | PROD 인보리언트 | 신규 |

테스트는 CLI 로 직접 실행 (`php tests/issue_auto_resolve_test.php`). DEV DB 에 임시 row INSERT → 검증 → DELETE 패턴 (multipass_api_test 와 동일 스타일이지만 HTTP 없이 함수 직접 호출).

---

## 매핑 / 상수 (모든 태스크 공통 참조)

```php
// public_html/api/services/issue_report.php 상단에 추가
const ISSUE_MISSION_MAP = [
    'naemat33' => 'inner33',
    'malkka'   => 'speak_mission',
    'zoom'     => 'zoom_daily',
    'daily'    => 'daily_mission',
    // study_create/study_join 은 false positive 위험으로 보류 (spec out of scope)
];

const ISSUE_INSPECT_RANGE_DAYS = 7;
```

mission_status 값: `'all_checked' | 'has_unchecked' | 'no_data' | 'unsupported'`.

---

## Task 1: inspectIssueMission 헬퍼 (TDD)

**Files:**
- Modify: `public_html/api/services/issue_report.php` (파일 하단에 함수 추가)
- Create: `tests/issue_auto_resolve_test.php`

- [ ] **Step 1.1: 테스트 파일 헤더 + 셋업 작성**

Create `tests/issue_auto_resolve_test.php`:

```php
<?php
/**
 * Issue auto-resolve 단위/통합 테스트 (CLI).
 *
 * 사용: php tests/issue_auto_resolve_test.php
 *
 * 사전: DEV DB. 테스트 회원 user_id 는 '__test_iar_%' 로 prefix.
 * 모든 테스트는 setUp 에서 fresh fixture 를 만들고 tearDown 에서 삭제한다.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/issue_report.php';

$db = getDB();
$pass = 0; $fail = 0;

function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// ── 픽스처 헬퍼 ──
function setupFixture(PDO $db): array {
    // 정리
    teardownFixture($db);

    // 최신 active cohort 선택 + 시작일을 7일 전으로 보장하는 임시 row 만들지 않고,
    // 실제 active cohort 에 테스트 회원만 추가 (member 와 mission_checks 만 정리)
    $cohort = $db->query("SELECT id, start_date FROM cohorts WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1")->fetch();
    if (!$cohort) { echo "SKIP — active cohort 없음\n"; exit(0); }

    // 테스트 회원 1명
    $db->prepare("
        INSERT INTO bootcamp_members (cohort_id, real_name, nickname, user_id, member_status)
        VALUES (?, 'IAR테스트', 'iar01', '__test_iar_01@k', 'active')
    ")->execute([(int)$cohort['id']]);
    $memberId = (int)$db->lastInsertId();

    return [
        'cohort_id'  => (int)$cohort['id'],
        'cohort_start' => $cohort['start_date'],
        'member_id'  => $memberId,
    ];
}

function teardownFixture(PDO $db): void {
    // CASCADE 안 되는 row 도 명시적으로 정리
    $db->exec("DELETE FROM issue_report_logs WHERE issue_id IN (SELECT id FROM issue_reports WHERE member_id IN (SELECT id FROM bootcamp_members WHERE user_id LIKE '__test_iar_%'))");
    $db->exec("DELETE FROM issue_reports WHERE member_id IN (SELECT id FROM bootcamp_members WHERE user_id LIKE '__test_iar_%')");
    $db->exec("DELETE FROM member_mission_checks WHERE member_id IN (SELECT id FROM bootcamp_members WHERE user_id LIKE '__test_iar_%')");
    $db->exec("DELETE FROM bootcamp_members WHERE user_id LIKE '__test_iar_%'");
}

function insertIssue(PDO $db, int $memberId, int $cohortId, string $issueType, ?string $createdAt = null): int {
    $createdAt = $createdAt ?: date('Y-m-d H:i:s');
    $db->prepare("
        INSERT INTO issue_reports (member_id, cohort_id, issue_type, status, created_at)
        VALUES (?, ?, ?, 'pending', ?)
    ")->execute([$memberId, $cohortId, $issueType, $createdAt]);
    return (int)$db->lastInsertId();
}

function insertCheck(PDO $db, int $memberId, int $cohortId, string $missionCode, string $date, int $status): void {
    $missionId = (int)$db->query("SELECT id FROM mission_types WHERE code = " . $db->quote($missionCode))->fetchColumn();
    if (!$missionId) throw new RuntimeException("mission_types.$missionCode 없음");
    $db->prepare("
        INSERT INTO member_mission_checks (member_id, cohort_id, mission_type_id, check_date, status, source)
        VALUES (?, ?, ?, ?, ?, 'manual')
    ")->execute([$memberId, $cohortId, $missionId, $date, $status]);
}

// ── 테스트는 다음 태스크에서 추가 ──
echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 1.2: 실패 테스트 추가 (unsupported issue_type)**

Append to `tests/issue_auto_resolve_test.php` (위 `echo` 직전):

```php
// ── T1: unsupported issue_type ──
{
    $fx = setupFixture($db);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'study_create');
    $r = inspectIssueMission($db, $issueId);
    t('T1 unsupported.status', $r['mission_status'] === 'unsupported');
    t('T1 unsupported.no checks', empty($r['unchecked_dates']) && empty($r['checked_dates']));
    teardownFixture($db);
}
```

- [ ] **Step 1.3: 실행하여 함수 미정의로 실패 확인**

Run: `cd /root/boot-dev && php tests/issue_auto_resolve_test.php`
Expected: PHP Fatal error: Uncaught Error: Call to undefined function inspectIssueMission()

- [ ] **Step 1.4: 헬퍼 최소 구현 (모든 issue_type → unsupported)**

Append to `public_html/api/services/issue_report.php` (파일 맨 아래에):

```php
// ══════════════════════════════════════════════════════════════
// Auto resolve: 미션 체크 상태 검사 (read-only)
// ══════════════════════════════════════════════════════════════

const ISSUE_MISSION_MAP = [
    'naemat33' => 'inner33',
    'malkka'   => 'speak_mission',
    'zoom'     => 'zoom_daily',
    'daily'    => 'daily_mission',
];
const ISSUE_INSPECT_RANGE_DAYS = 7;

/**
 * 한 issue 의 미션 체크 상태를 검사한다 (read-only).
 *
 * 반환:
 *   mission_status    'all_checked' | 'has_unchecked' | 'no_data' | 'unsupported'
 *   unchecked_dates   ['YYYY-MM-DD', ...]
 *   checked_dates     ['YYYY-MM-DD', ...]
 *   inspected_range   ['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD']  ※ unsupported 시 null
 */
function inspectIssueMission(PDO $db, int $issueId): array {
    $stmt = $db->prepare("SELECT member_id, cohort_id, issue_type, created_at FROM issue_reports WHERE id = ?");
    $stmt->execute([$issueId]);
    $issue = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$issue) {
        return [
            'mission_status' => 'unsupported',
            'unchecked_dates' => [],
            'checked_dates' => [],
            'inspected_range' => null,
        ];
    }

    if (!isset(ISSUE_MISSION_MAP[$issue['issue_type']])) {
        return [
            'mission_status' => 'unsupported',
            'unchecked_dates' => [],
            'checked_dates' => [],
            'inspected_range' => null,
        ];
    }

    // 다음 스텝에서 채움
    return [
        'mission_status' => 'unsupported',
        'unchecked_dates' => [],
        'checked_dates' => [],
        'inspected_range' => null,
    ];
}
```

- [ ] **Step 1.5: 실행하여 T1 PASS 확인**

Run: `php tests/issue_auto_resolve_test.php`
Expected: `PASS  T1 unsupported.status` + `PASS  T1 unsupported.no checks` + `0 fail`

- [ ] **Step 1.6: no_data 테스트 추가**

Append `tests/issue_auto_resolve_test.php` (T1 블록 뒤):

```php
// ── T2: no_data — 검사 범위에 row 없음 ──
{
    $fx = setupFixture($db);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $r = inspectIssueMission($db, $issueId);
    t('T2 no_data.status', $r['mission_status'] === 'no_data', "got: {$r['mission_status']}");
    t('T2 no_data.range from', !empty($r['inspected_range']['from']));
    t('T2 no_data.range to', !empty($r['inspected_range']['to']));
    teardownFixture($db);
}
```

- [ ] **Step 1.7: 실행하여 fail 확인**

Run: `php tests/issue_auto_resolve_test.php`
Expected: T1 PASS, T2 FAIL (현재 항상 `unsupported`)

- [ ] **Step 1.8: 검사 범위 + 쿼리 구현**

Replace the body of `inspectIssueMission` (Step 1.4 의 두 번째 return 블록 — `// 다음 스텝에서 채움` 부분 전체를) with:

```php
    $missionCode = ISSUE_MISSION_MAP[$issue['issue_type']];

    // 검사 범위 (KST). DB time_zone = +09:00 이므로 created_at, NOW() 모두 KST.
    $createdDate = substr($issue['created_at'], 0, 10);
    $rangeFrom = date('Y-m-d', strtotime($createdDate . ' -' . ISSUE_INSPECT_RANGE_DAYS . ' days'));

    // cohort.start_date 하한 적용
    $cs = $db->prepare("SELECT start_date FROM cohorts WHERE id = ?");
    $cs->execute([(int)$issue['cohort_id']]);
    $cohortStart = $cs->fetchColumn();
    if ($cohortStart && $cohortStart > $rangeFrom) {
        $rangeFrom = $cohortStart;
    }
    $rangeTo = $createdDate;

    // 회원 + mission_type + 범위 내 체크 row 조회
    $checkStmt = $db->prepare("
        SELECT mmc.check_date, mmc.status
        FROM member_mission_checks mmc
        JOIN mission_types mt ON mmc.mission_type_id = mt.id
        WHERE mmc.member_id = ?
          AND mmc.cohort_id = ?
          AND mt.code = ?
          AND mmc.check_date BETWEEN ? AND ?
        ORDER BY mmc.check_date ASC
    ");
    $checkStmt->execute([
        (int)$issue['member_id'],
        (int)$issue['cohort_id'],
        $missionCode,
        $rangeFrom,
        $rangeTo,
    ]);
    $rows = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

    $checked = [];
    $unchecked = [];
    foreach ($rows as $r) {
        if ((int)$r['status'] === 1) $checked[] = $r['check_date'];
        else $unchecked[] = $r['check_date'];
    }

    if (empty($rows)) {
        $status = 'no_data';
    } elseif (empty($unchecked)) {
        $status = 'all_checked';
    } else {
        $status = 'has_unchecked';
    }

    return [
        'mission_status' => $status,
        'unchecked_dates' => $unchecked,
        'checked_dates' => $checked,
        'inspected_range' => ['from' => $rangeFrom, 'to' => $rangeTo],
    ];
```

- [ ] **Step 1.9: 실행하여 T1+T2 PASS 확인**

Run: `php tests/issue_auto_resolve_test.php`
Expected: 5 pass, 0 fail

- [ ] **Step 1.10: all_checked / has_unchecked 테스트 추가**

Append (T2 뒤):

```php
// ── T3: all_checked — 범위 내 모든 row status=1 ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $today, 1);
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $yesterday, 1);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $r = inspectIssueMission($db, $issueId);
    t('T3 all_checked.status', $r['mission_status'] === 'all_checked', "got: {$r['mission_status']}");
    t('T3 all_checked.dates', count($r['checked_dates']) === 2);
    t('T3 all_checked.no unchecked', empty($r['unchecked_dates']));
    teardownFixture($db);
}

// ── T4: has_unchecked — 하나라도 status=0 ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $today, 1);
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $yesterday, 0);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $r = inspectIssueMission($db, $issueId);
    t('T4 has_unchecked.status', $r['mission_status'] === 'has_unchecked');
    t('T4 has_unchecked.dates', $r['unchecked_dates'] === [$yesterday]);
    teardownFixture($db);
}
```

- [ ] **Step 1.11: 실행 → 9 pass, 0 fail**

Run: `php tests/issue_auto_resolve_test.php`
Expected: `9 pass, 0 fail`

- [ ] **Step 1.12: cohort.start_date 하한 + 다른 mission_type 테스트**

Append:

```php
// ── T5: cohort.start_date 가 7일 전보다 더 최근이면 range.from 이 좁혀짐 ──
{
    $fx = setupFixture($db);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $r = inspectIssueMission($db, $issueId);
    $expectedFromMin = $fx['cohort_start'];
    t('T5 range >= cohort.start_date', $r['inspected_range']['from'] >= $expectedFromMin,
       "from={$r['inspected_range']['from']} cohort_start={$expectedFromMin}");
}

// ── T6: 다른 issue_type 도 매핑됨 (malkka → speak_mission) ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'speak_mission', $today, 1);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'malkka');
    $r = inspectIssueMission($db, $issueId);
    t('T6 malkka all_checked', $r['mission_status'] === 'all_checked', "got: {$r['mission_status']}");
    teardownFixture($db);
}
```

- [ ] **Step 1.13: 실행 → 11 pass**

Run: `php tests/issue_auto_resolve_test.php`
Expected: `11 pass, 0 fail`

- [ ] **Step 1.14: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/issue_report.php tests/issue_auto_resolve_test.php
git commit -m "feat(issues): inspectIssueMission read-only 헬퍼 + 테스트"
```

---

## Task 2: 단건 자동 해결 핸들러 (TDD)

**Files:**
- Modify: `public_html/api/services/issue_report.php`

- [ ] **Step 2.1: T7~T9 실패 테스트 추가**

Append `tests/issue_auto_resolve_test.php`:

```php
// ── T7: attemptAutoResolveIssue all_checked → resolved ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $today, 1);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $r = attemptAutoResolveIssue($db, $issueId, /*adminId*/ 1);
    t('T7 ok=true', $r['ok'] === true, json_encode($r));
    $row = $db->query("SELECT status, admin_note, resolved_by FROM issue_reports WHERE id={$issueId}")->fetch();
    t('T7 status=resolved', $row['status'] === 'resolved');
    t('T7 admin_note prefix', is_string($row['admin_note']) && strpos($row['admin_note'], 'auto:') === 0);
    t('T7 resolved_by', (int)$row['resolved_by'] === 1);
    $logCnt = (int)$db->query("SELECT COUNT(*) FROM issue_report_logs WHERE issue_id={$issueId} AND new_status='resolved'")->fetchColumn();
    t('T7 audit log', $logCnt === 1);
    teardownFixture($db);
}

// ── T8: has_unchecked 거부 ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $today, 0);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $r = attemptAutoResolveIssue($db, $issueId, 1);
    t('T8 ok=false', $r['ok'] === false);
    t('T8 reason=not_eligible', $r['reason'] === 'not_eligible');
    $row = $db->query("SELECT status FROM issue_reports WHERE id={$issueId}")->fetch();
    t('T8 status unchanged', $row['status'] === 'pending');
    teardownFixture($db);
}

// ── T9: 이미 resolved 인 row → 거부 ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $today, 1);
    $issueId = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');
    $db->exec("UPDATE issue_reports SET status='resolved' WHERE id={$issueId}");
    $r = attemptAutoResolveIssue($db, $issueId, 1);
    t('T9 ok=false', $r['ok'] === false);
    t('T9 reason=not_pending', $r['reason'] === 'not_pending');
    teardownFixture($db);
}
```

- [ ] **Step 2.2: 실행하여 함수 미정의로 실패**

Run: `php tests/issue_auto_resolve_test.php`
Expected: T1~T6 PASS, T7~ FAIL (`Call to undefined function attemptAutoResolveIssue`)

- [ ] **Step 2.3: attemptAutoResolveIssue 구현**

Append `public_html/api/services/issue_report.php` (inspectIssueMission 아래):

```php
/**
 * 한 issue 를 자동 해결 처리한다 (write).
 *
 * 조건:
 *  - issue_reports.status='pending'
 *  - inspectIssueMission()의 mission_status === 'all_checked'
 *
 * 반환:
 *  - ok=true 시  ['ok'=>true, 'inspection'=>array]
 *  - ok=false 시 ['ok'=>false, 'reason'=>'not_pending'|'not_eligible'|'not_found', 'inspection'=>array|null]
 */
function attemptAutoResolveIssue(PDO $db, int $issueId, int $adminId): array {
    $row = $db->prepare("SELECT id, status FROM issue_reports WHERE id = ? FOR UPDATE");
    $inTx = $db->inTransaction();
    if (!$inTx) $db->beginTransaction();
    try {
        $row->execute([$issueId]);
        $issue = $row->fetch(PDO::FETCH_ASSOC);
        if (!$issue) {
            if (!$inTx) $db->rollBack();
            return ['ok' => false, 'reason' => 'not_found', 'inspection' => null];
        }
        if ($issue['status'] !== 'pending') {
            if (!$inTx) $db->rollBack();
            return ['ok' => false, 'reason' => 'not_pending', 'inspection' => null];
        }

        $inspection = inspectIssueMission($db, $issueId);
        if ($inspection['mission_status'] !== 'all_checked') {
            if (!$inTx) $db->rollBack();
            return ['ok' => false, 'reason' => 'not_eligible', 'inspection' => $inspection];
        }

        $rangeLabel = $inspection['inspected_range']['from'] . '~' . $inspection['inspected_range']['to'];
        $autoNote = 'auto: 폴링 후 모두 체크 완료 확인 (' . $rangeLabel . ')';

        $db->prepare("
            UPDATE issue_reports
            SET status = 'resolved',
                admin_note = CASE
                    WHEN admin_note IS NULL OR admin_note = '' THEN ?
                    ELSE CONCAT(admin_note, ' / ', ?)
                END,
                resolved_by = ?,
                resolved_at = NOW()
            WHERE id = ? AND status = 'pending'
        ")->execute([$autoNote, $autoNote, $adminId, $issueId]);

        logIssueStatusChange($db, $issueId, 'pending', 'resolved', 'admin', $adminId, $autoNote);

        if (!$inTx) $db->commit();
        return ['ok' => true, 'inspection' => $inspection];
    } catch (\Throwable $e) {
        if (!$inTx && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 2.4: 실행 → T7~T9 PASS**

Run: `php tests/issue_auto_resolve_test.php`
Expected: `19 pass, 0 fail`

- [ ] **Step 2.5: 단건 핸들러 추가 (HTTP wrapper)**

Append `public_html/api/services/issue_report.php`:

```php
// ══════════════════════════════════════════════════════════════
// 운영용: 단건 자동 해결
// ══════════════════════════════════════════════════════════════

function handleIssueAdminResolveAuto($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $db = getDB();
    $r = attemptAutoResolveIssue($db, $id, (int)$admin['admin_id']);
    if (!$r['ok']) {
        $msg = match ($r['reason']) {
            'not_found'    => '문의를 찾을 수 없습니다.',
            'not_pending'  => '이미 처리된 문의입니다.',
            'not_eligible' => '자동 해결 조건을 충족하지 않습니다 (미체크 일자 존재).',
            default        => '자동 해결 실패',
        };
        jsonError($msg, 409);
    }
    jsonSuccess(['inspection' => $r['inspection']], '자동 해결되었습니다.');
}
```

- [ ] **Step 2.6: Commit**

```bash
git add public_html/api/services/issue_report.php tests/issue_auto_resolve_test.php
git commit -m "feat(issues): 단건 자동 해결 헬퍼 + HTTP 핸들러 + 테스트 9건"
```

---

## Task 3: 일괄 자동 해결 핸들러 (TDD)

**Files:**
- Modify: `public_html/api/services/issue_report.php`

- [ ] **Step 3.1: T10 실패 테스트 추가**

Append `tests/issue_auto_resolve_test.php`:

```php
// ── T10: bulk — 섞여 있는 pending 중 all_checked 만 resolve ──
{
    $fx = setupFixture($db);
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    // A: naemat33 + all_checked
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'inner33', $today, 1);
    $idA = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'naemat33');

    // B: zoom + has_unchecked (mission 다름)
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'zoom_daily', $today, 1);
    insertCheck($db, $fx['member_id'], $fx['cohort_id'], 'zoom_daily', $yesterday, 0);
    $idB = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'zoom');

    // C: study_create + unsupported
    $idC = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'study_create');

    // D: malkka + no_data
    $idD = insertIssue($db, $fx['member_id'], $fx['cohort_id'], 'malkka');

    $r = bulkAutoResolveIssues($db, [$idA, $idB, $idC, $idD], /*adminId*/ 1);
    t('T10 resolved A only', $r['resolved_ids'] === [$idA], json_encode($r));
    t('T10 skipped 3', count($r['skipped']) === 3);

    $statuses = $db->query("SELECT id, status FROM issue_reports WHERE id IN ({$idA},{$idB},{$idC},{$idD}) ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
    t('T10 A resolved', $statuses[$idA] === 'resolved');
    t('T10 B pending',  $statuses[$idB] === 'pending');
    t('T10 C pending',  $statuses[$idC] === 'pending');
    t('T10 D pending',  $statuses[$idD] === 'pending');
    teardownFixture($db);
}
```

- [ ] **Step 3.2: 실행하여 함수 미정의로 실패**

Run: `php tests/issue_auto_resolve_test.php`
Expected: `Call to undefined function bulkAutoResolveIssues`

- [ ] **Step 3.3: bulk 함수 + HTTP 핸들러 구현**

Append `public_html/api/services/issue_report.php`:

```php
/**
 * 여러 issue 를 일괄 자동 해결. 각 row 마다 attemptAutoResolveIssue 호출.
 * 한 건 실패해도 나머지는 진행.
 *
 * 반환: ['resolved_ids' => [...], 'skipped' => [['id'=>..., 'reason'=>...], ...]]
 */
function bulkAutoResolveIssues(PDO $db, array $issueIds, int $adminId): array {
    $resolved = [];
    $skipped  = [];
    foreach ($issueIds as $rawId) {
        $id = (int)$rawId;
        if (!$id) continue;
        try {
            $r = attemptAutoResolveIssue($db, $id, $adminId);
            if ($r['ok']) {
                $resolved[] = $id;
            } else {
                $skipped[] = ['id' => $id, 'reason' => $r['reason']];
            }
        } catch (\Throwable $e) {
            error_log("bulkAutoResolveIssues id=$id failed: " . $e->getMessage());
            $skipped[] = ['id' => $id, 'reason' => 'exception'];
        }
    }
    return ['resolved_ids' => $resolved, 'skipped' => $skipped];
}

// ══════════════════════════════════════════════════════════════
// 운영용: 일괄 자동 해결
// ══════════════════════════════════════════════════════════════

function handleIssueAdminResolveAutoBulk($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $ids = $input['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) jsonError('ids 배열 필요');
    if (count($ids) > 200) jsonError('일괄 처리는 200건 이내', 400);

    $db = getDB();
    $r = bulkAutoResolveIssues($db, $ids, (int)$admin['admin_id']);
    jsonSuccess($r, count($r['resolved_ids']) . '건 자동 해결되었습니다.');
}
```

- [ ] **Step 3.4: 실행 → T10 PASS**

Run: `php tests/issue_auto_resolve_test.php`
Expected: `24 pass, 0 fail`

- [ ] **Step 3.5: Commit**

```bash
git add public_html/api/services/issue_report.php tests/issue_auto_resolve_test.php
git commit -m "feat(issues): 일괄 자동 해결 함수 + HTTP 핸들러 + 통합 테스트"
```

---

## Task 4: admin_list 응답 확장 + 라우팅

**Files:**
- Modify: `public_html/api/services/issue_report.php` (handleIssueAdminList)
- Modify: `public_html/api/bootcamp.php`

- [ ] **Step 4.1: handleIssueAdminList 응답에 mission_inspection 포함**

Edit `public_html/api/services/issue_report.php` — `handleIssueAdminList` 함수 안에서 `foreach ($rows as &$r)` 루프를 다음으로 교체:

```php
    foreach ($rows as &$r) {
        $r['issue_type_label'] = ISSUE_TYPES[$r['issue_type']] ?? $r['issue_type'];
        $r['status_label'] = ISSUE_STATUSES[$r['status']] ?? $r['status'];
        // pending 만 검사 (resolved/rejected 는 의미 없음)
        if ($r['status'] === 'pending') {
            $r['mission_inspection'] = inspectIssueMission($db, (int)$r['id']);
        } else {
            $r['mission_inspection'] = null;
        }
    }
    unset($r);
```

- [ ] **Step 4.2: 라우팅 case 2개 추가**

Edit `public_html/api/bootcamp.php` — 기존 `case 'issue_admin_note': ...` 다음 줄에 추가:

```php
case 'issue_admin_resolve_auto':      handleIssueAdminResolveAuto($method); break;
case 'issue_admin_resolve_auto_bulk': handleIssueAdminResolveAutoBulk($method); break;
```

- [ ] **Step 4.3: smoke — admin_list 응답에 mission_inspection 있는지 확인**

Run:

```bash
source /root/boot-dev/.db_credentials && mysql -u"$DB_USER" -p"$DB_PASS" SORITUNECOM_DEV_BOOT -e "
SELECT 'DEV pending count'; SELECT COUNT(*) FROM issue_reports WHERE status='pending';
" 2>&1
```

Expected: 0 또는 양수. DEV pending 없으면 다음 스텝에서 직접 만들어서 검증.

- [ ] **Step 4.4: PHP unit 회귀**

Run: `php tests/issue_auto_resolve_test.php`
Expected: `24 pass, 0 fail` (회귀 없음)

- [ ] **Step 4.5: Commit**

```bash
git add public_html/api/services/issue_report.php public_html/api/bootcamp.php
git commit -m "feat(issues): admin_list 응답 mission_inspection 확장 + 라우팅 2건"
```

---

## Task 5: 프론트엔드 — mission chip 표시

**Files:**
- Modify: `public_html/js/admin-issues.js`
- Modify: `public_html/css/admin-issues.css`

- [ ] **Step 5.1: STATUS_MAP 옆에 MISSION_CHIP 상수 추가**

Edit `public_html/js/admin-issues.js` — `const STATUS_MAP = {...};` 직후 추가:

```javascript
    // ── 미션 검사 chip ──
    const MISSION_CHIP = {
        all_checked:   { label: '✅ 모두 체크됨', cls: 'mission-chip mission-chip--ok' },
        has_unchecked: { label: '❌ 미체크',      cls: 'mission-chip mission-chip--miss' },
        no_data:       { label: '데이터 없음',     cls: 'mission-chip mission-chip--none' },
        unsupported:   null, // 표시 안 함
    };

    function renderMissionChip(insp) {
        if (!insp) return '';
        const def = MISSION_CHIP[insp.mission_status];
        if (!def) return '';
        let extra = '';
        if (insp.mission_status === 'has_unchecked' && insp.unchecked_dates?.length) {
            const dates = insp.unchecked_dates.map(d => d.slice(5)).join(', ');
            extra = ` ${dates}`;
        }
        return `<span class="${def.cls}">${def.label}${extra}</span>`;
    }
```

- [ ] **Step 5.2: renderRow 에 chip 컬럼 추가**

Edit `renderRow` 안 `<td>${App.esc(issue.issue_type_label)}</td>` 직후에 다음 한 줄 삽입:

```javascript
                <td class="issue-adm-mission">${renderMissionChip(issue.mission_inspection)}</td>
```

그리고 같은 파일의 `<thead><tr>` 안 `<th>유형</th>` 직후에:

```html
                        <th>미션</th>
```

- [ ] **Step 5.3: openDetail 상세 모달에 미션 검사 섹션 추가**

Edit `openDetail` 함수 — `${noteHtml}` 직전에 다음 변수 정의 + 본문 삽입:

```javascript
        const inspectionHtml = (() => {
            const i = issue.mission_inspection;
            if (!i) return '';
            const chip = renderMissionChip(i);
            const range = i.inspected_range
                ? `${i.inspected_range.from} ~ ${i.inspected_range.to}`
                : '';
            const detail = [];
            if (i.checked_dates?.length)   detail.push(`체크됨: ${i.checked_dates.join(', ')}`);
            if (i.unchecked_dates?.length) detail.push(`미체크: ${i.unchecked_dates.join(', ')}`);
            return `
                <div class="issue-adm-detail-section">
                    <div class="issue-adm-detail-label">미션 자동 검사</div>
                    <div class="issue-adm-detail-value">
                        ${chip}
                        <div class="issue-adm-inspect-range">검사 범위: ${range}</div>
                        ${detail.length ? `<div class="issue-adm-inspect-detail">${detail.join(' · ')}</div>` : ''}
                    </div>
                </div>
            `;
        })();
```

그리고 본문 `${descHtml}${noteHtml}` 부분을 다음으로 교체:

```javascript
                ${descHtml}
                ${inspectionHtml}
                ${noteHtml}
```

- [ ] **Step 5.4: CSS 추가 — mission chip 스타일**

Append `public_html/css/admin-issues.css`:

```css
/* ── 미션 검사 chip ───────────────────────────────────────── */
.mission-chip {
    display: inline-block;
    font-size: var(--text-xs);
    font-weight: var(--font-medium);
    padding: 2px 8px;
    border-radius: var(--radius-full, 9999px);
    white-space: nowrap;
    line-height: 1.4;
}

.mission-chip--ok {
    background: var(--color-success-50, #ecfdf5);
    color: #047857;
    border: 1px solid #a7f3d0;
}

.mission-chip--miss {
    background: var(--color-danger-50, #fef2f2);
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.mission-chip--none {
    background: var(--color-gray-100, #f3f4f6);
    color: var(--color-gray-600, #4b5563);
    border: 1px solid var(--color-gray-300, #d1d5db);
}

.issue-adm-mission {
    text-align: center;
    white-space: nowrap;
}

.issue-adm-inspect-range,
.issue-adm-inspect-detail {
    font-size: var(--text-xs);
    color: var(--color-gray-600, #4b5563);
    margin-top: 4px;
}
```

- [ ] **Step 5.5: Commit**

```bash
git add public_html/js/admin-issues.js public_html/css/admin-issues.css
git commit -m "feat(issues): 운영자 목록/상세에 미션 자동 검사 chip 표시"
```

---

## Task 6: 프론트엔드 — 단건 자동 해결 버튼

**Files:**
- Modify: `public_html/js/admin-issues.js`

- [ ] **Step 6.1: 상세 모달 상태 변경 영역에 [자동 해결] 버튼 추가**

Edit `openDetail` — 기존 `const statusBtns = ...` 줄을 다음으로 교체:

```javascript
        // 상태 변경 버튼 (자동 해결 + 일반 4개)
        const canAuto =
            issue.status === 'pending' &&
            issue.mission_inspection?.mission_status === 'all_checked';

        const autoBtnHtml = canAuto
            ? `<button class="btn btn-sm issue-adm-auto-btn" id="adm-auto-resolve">🪄 자동 해결</button>`
            : '';

        const statusBtns = Object.entries(STATUS_MAP)
            .filter(([key]) => key !== issue.status)
            .map(([key, val]) =>
                `<button class="btn btn-sm issue-adm-status-btn ${val.cls}" data-status="${key}">${val.label}</button>`
            ).join('');
```

그리고 본문 `<div class="issue-adm-status-btns" id="adm-status-btns">${statusBtns}</div>` 줄을 다음으로 교체:

```javascript
                    <div class="issue-adm-status-btns" id="adm-status-btns">${autoBtnHtml}${statusBtns}</div>
```

- [ ] **Step 6.2: 자동 해결 버튼 핸들러 추가**

Edit `openDetail` — 함수 맨 끝 (`document.querySelectorAll('#adm-status-btns .issue-adm-status-btn')...` 블록 직후)에 추가:

```javascript
        const autoBtn = document.getElementById('adm-auto-resolve');
        if (autoBtn) {
            autoBtn.onclick = async () => {
                if (!confirm('이 문의를 자동 해결로 처리하시겠습니까?\n(회원에게는 알림이 가지 않습니다)')) return;
                const r = await App.post(API + 'issue_admin_resolve_auto', { id: issue.id });
                if (r.success) {
                    Toast.success('자동 해결되었습니다.');
                    issue.status = 'resolved';
                    issue.resolved_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
                    App.closeModal();
                    renderStatusFilters();
                    renderList();
                } else {
                    Toast.error(r.message || '자동 해결 실패');
                }
            };
        }
```

- [ ] **Step 6.3: CSS — 자동 해결 버튼 스타일**

Append `public_html/css/admin-issues.css`:

```css
.issue-adm-auto-btn {
    background: var(--color-primary-600, #2563eb);
    color: #fff;
    border: 1px solid var(--color-primary-700, #1d4ed8);
}
.issue-adm-auto-btn:hover {
    background: var(--color-primary-700, #1d4ed8);
}
```

- [ ] **Step 6.4: Commit**

```bash
git add public_html/js/admin-issues.js public_html/css/admin-issues.css
git commit -m "feat(issues): 상세 모달 단건 자동 해결 버튼 (all_checked 한정)"
```

---

## Task 7: 프론트엔드 — 일괄 자동 해결 버튼

**Files:**
- Modify: `public_html/js/admin-issues.js`

- [ ] **Step 7.1: 툴바에 일괄 버튼 추가**

Edit `init` 의 `container.innerHTML` 안 toolbar 부분을 다음으로 교체:

```javascript
        container.innerHTML = `
            <div class="issue-adm">
                <div class="issue-adm-toolbar">
                    <h3 class="issue-adm-title">오류 문의</h3>
                    <div class="issue-adm-toolbar-actions">
                        <button class="btn btn-primary btn-sm" id="issue-adm-bulk-auto" style="display:none;">🪄 모두 체크된 <span id="issue-adm-bulk-count">0</span>건 일괄 자동 해결</button>
                        <button class="btn btn-secondary btn-sm" id="issue-adm-refresh">새로고침</button>
                    </div>
                </div>
                <div class="issue-adm-filter-bar" id="issue-adm-filter-bar">
                    <div class="issue-adm-filter-group" id="issue-adm-status-filters"></div>
                    <div class="issue-adm-filter-group" id="issue-adm-type-filters"></div>
                </div>
                <div class="issue-adm-count" id="issue-adm-count"></div>
                <div class="issue-adm-list" id="issue-adm-list"></div>
                <div class="issue-adm-pager" id="issue-adm-pager"></div>
            </div>
        `;
```

- [ ] **Step 7.2: 일괄 버튼 노출/카운트 갱신 함수 추가**

Edit `admin-issues.js` — `renderList` 함수 끝부분 `if (pagerEl) { ... }` 블록 직후에 다음 함수 호출 추가:

```javascript
        updateBulkAutoButton();
```

그리고 `renderList` 함수 정의 위에 `updateBulkAutoButton` 함수 추가:

```javascript
    function updateBulkAutoButton() {
        const btn = document.getElementById('issue-adm-bulk-auto');
        const cntEl = document.getElementById('issue-adm-bulk-count');
        if (!btn || !cntEl) return;

        // 현재 필터에서 all_checked + pending 만
        const eligible = getFiltered().filter(i =>
            i.status === 'pending' &&
            i.mission_inspection?.mission_status === 'all_checked'
        );
        if (eligible.length === 0) {
            btn.style.display = 'none';
            return;
        }
        cntEl.textContent = eligible.length;
        btn.style.display = '';
        btn.onclick = () => runBulkAuto(eligible.map(i => i.id));
    }

    async function runBulkAuto(ids) {
        if (!confirm(`${ids.length}건을 일괄 자동 해결 처리합니다.\n진행할까요?`)) return;

        const r = await App.post(API + 'issue_admin_resolve_auto_bulk', { ids });
        if (!r.success) {
            Toast.error(r.message || '일괄 처리 실패');
            return;
        }
        const ok = r.resolved_ids?.length ?? 0;
        const skipped = r.skipped?.length ?? 0;
        Toast.success(`${ok}건 처리, ${skipped}건 스킵`);
        // 새로고침
        container.dataset.loaded = '';
        loadList();
    }
```

- [ ] **Step 7.3: CSS — 툴바 액션 배치**

Append `public_html/css/admin-issues.css`:

```css
.issue-adm-toolbar-actions {
    display: flex;
    gap: var(--space-2);
    align-items: center;
}
```

- [ ] **Step 7.4: Commit**

```bash
git add public_html/js/admin-issues.js public_html/css/admin-issues.css
git commit -m "feat(issues): 일괄 자동 해결 버튼 (현재 필터의 all_checked 만)"
```

---

## Task 8: 인보리언트 테스트

**Files:**
- Create: `tests/issue_auto_resolve_invariants.php`

- [ ] **Step 8.1: 인보리언트 파일 작성**

Create `tests/issue_auto_resolve_invariants.php`:

```php
<?php
/**
 * issue auto-resolve 인보리언트.
 * 사용: php tests/issue_auto_resolve_invariants.php
 *
 * 자동 해결로 status='resolved' 가 된 row 의 무결성을 검증.
 * (admin_note 가 'auto: ...' prefix 인 row 만 대상.)
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$db = getDB();
$pass = 0; $fail = 0;
function inv(string $name, int $expected, int $actual, string $sql = ''): void {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "PASS  {$name}  (= {$expected})\n"; return; }
    $fail++;
    echo "FAIL  {$name}  expected={$expected} actual={$actual}\n";
    if ($sql) echo "  SQL: {$sql}\n";
}

// INV-1: auto: 마커가 있는 row 는 모두 status='resolved'
$sql1 = "SELECT COUNT(*) FROM issue_reports WHERE admin_note LIKE 'auto:%' AND status <> 'resolved'";
inv('INV-1 auto-marker only on resolved', 0, (int)$db->query($sql1)->fetchColumn(), $sql1);

// INV-2: auto resolve 된 row 는 resolved_by / resolved_at 모두 NOT NULL
$sql2 = "SELECT COUNT(*) FROM issue_reports WHERE admin_note LIKE 'auto:%' AND (resolved_by IS NULL OR resolved_at IS NULL)";
inv('INV-2 auto resolve completeness', 0, (int)$db->query($sql2)->fetchColumn(), $sql2);

// INV-3: auto resolve 된 row 는 issue_report_logs 에 changed_by_type='admin', new_status='resolved' 로그가 최소 1건
$sql3 = "
SELECT COUNT(*) FROM (
  SELECT ir.id
  FROM issue_reports ir
  LEFT JOIN issue_report_logs il
    ON il.issue_id = ir.id AND il.new_status = 'resolved' AND il.changed_by_type = 'admin'
  WHERE ir.admin_note LIKE 'auto:%'
  GROUP BY ir.id
  HAVING COUNT(il.id) = 0
) t";
inv('INV-3 auto resolve has admin log', 0, (int)$db->query($sql3)->fetchColumn(), $sql3);

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 8.2: DEV 에서 실행**

Run: `cd /root/boot-dev && php tests/issue_auto_resolve_invariants.php`
Expected: `3 pass, 0 fail` (auto resolve 한 row 가 없으면 모두 0건이라 통과).

- [ ] **Step 8.3: Commit**

```bash
git add tests/issue_auto_resolve_invariants.php
git commit -m "test(issues): auto-resolve PROD 인보리언트 3건"
```

---

## Task 9: DEV 통합 smoke (수동 실행)

**Files:** 없음 (수동 검증)

- [ ] **Step 9.1: DEV 회원으로 pending 문의 생성**

Run:

```bash
source /root/boot-dev/.db_credentials && mysql -u"$DB_USER" -p"$DB_PASS" SORITUNECOM_DEV_BOOT <<'SQL'
-- 임시 회원 + 미션 체크 + 문의
DELETE FROM issue_reports WHERE description = '__smoke_auto_resolve__';

INSERT INTO bootcamp_members (cohort_id, real_name, nickname, user_id, member_status)
SELECT id, 'SMK테스트', 'smkar', '__test_iar_smk@k', 'active'
FROM cohorts WHERE is_active=1 ORDER BY start_date DESC LIMIT 1;

SET @mid := LAST_INSERT_ID();
SET @cid := (SELECT cohort_id FROM bootcamp_members WHERE id=@mid);

-- inner33 오늘 체크됨
INSERT INTO member_mission_checks (member_id, cohort_id, mission_type_id, check_date, status, source)
SELECT @mid, @cid, id, CURDATE(), 1, 'automation' FROM mission_types WHERE code='inner33';

-- pending 문의
INSERT INTO issue_reports (member_id, cohort_id, issue_type, description, status)
VALUES (@mid, @cid, 'naemat33', '__smoke_auto_resolve__', 'pending');

SELECT LAST_INSERT_ID() AS new_issue_id;
SQL
```

기록된 `new_issue_id` 를 메모.

- [ ] **Step 9.2: 브라우저에서 검증**

운영자 계정으로 `https://dev-boot.soritune.com/operation/#issues` 접속:

확인:
1. 목록에 방금 만든 문의 row 가 ✅ 모두 체크됨 chip 으로 표시되는가
2. 상단에 "🪄 모두 체크된 1건 일괄 자동 해결" 버튼 노출되는가
3. 행 클릭 → 상세 모달 → "미션 자동 검사" 섹션 + [🪄 자동 해결] 버튼 노출되는가

- [ ] **Step 9.3: 단건 자동 해결 클릭 → resolved 확인**

상세 모달에서 [🪄 자동 해결] 클릭 → confirm 수락 → toast 확인 → 목록 갱신 확인.

DB 확인:

```bash
source /root/boot-dev/.db_credentials && mysql -u"$DB_USER" -p"$DB_PASS" SORITUNECOM_DEV_BOOT -e "
SELECT id, status, admin_note, resolved_by, resolved_at FROM issue_reports WHERE description = '__smoke_auto_resolve__';
SELECT issue_id, old_status, new_status, changed_by_type, note FROM issue_report_logs WHERE issue_id IN (SELECT id FROM issue_reports WHERE description = '__smoke_auto_resolve__');
"
```

Expected: `status='resolved'`, `admin_note LIKE 'auto: %'`, log row 2건 (생성 시 'system'→'pending', 자동 해결 시 'admin'→'resolved').

- [ ] **Step 9.4: 인보리언트 재검증**

Run: `php tests/issue_auto_resolve_invariants.php`
Expected: `3 pass, 0 fail`

- [ ] **Step 9.5: 정리**

```bash
source /root/boot-dev/.db_credentials && mysql -u"$DB_USER" -p"$DB_PASS" SORITUNECOM_DEV_BOOT <<'SQL'
DELETE FROM issue_report_logs WHERE issue_id IN (SELECT id FROM issue_reports WHERE description = '__smoke_auto_resolve__');
DELETE FROM issue_reports WHERE description = '__smoke_auto_resolve__';
DELETE FROM member_mission_checks WHERE member_id IN (SELECT id FROM bootcamp_members WHERE user_id LIKE '__test_iar_%');
DELETE FROM bootcamp_members WHERE user_id LIKE '__test_iar_%';
SQL
```

- [ ] **Step 9.6: has_unchecked / no_data 음성 시나리오 smoke**

위 Step 9.1 을 다음으로 변형해서 한 번 더:
- inner33 어제 status=0, 오늘 status=1 (has_unchecked) → 목록에 "❌ 미체크" chip + 상세 모달 자동 해결 버튼 미노출
- inner33 row 없음 (no_data) → "데이터 없음" chip + 자동 해결 버튼 미노출

각 케이스 확인 후 Step 9.5 와 동일 SQL 로 정리.

---

## Task 10: dev push + 사용자 확인 대기

**Files:** 없음

- [ ] **Step 10.1: dev push**

```bash
cd /root/boot-dev
git status        # 모든 변경이 commit 되었는지 확인
git push origin dev
```

- [ ] **Step 10.2: 사용자에게 DEV 검증 요청**

다음 메시지로 보고:

> DEV 푸시 완료. https://dev-boot.soritune.com/operation/#issues 에서 자동 검사 chip + 자동 해결 버튼이 의도대로 보이는지 확인 부탁드려요. 운영 반영은 명시 요청 시에만 진행합니다.

⛔ **본 플랜은 여기서 종료한다.** 운영 반영(main 머지 + boot-prod pull) 은 사용자가 명시적으로 요청할 때만 별도 진행. 자동으로 진행하지 않는다.

---

## Self-Review

**1. Spec coverage**

| Spec section | Task |
|--------------|------|
| §1 적용 대상 (4 issue_type) | Task 1.4 ISSUE_MISSION_MAP, Task 1 T6 |
| §2 판별 로직 (3-state) | Task 1.8 + T1~T6 |
| §3 운영자 화면 UX | Task 5 (chip 목록/상세), Task 6 (단건 버튼), Task 7 (일괄 버튼) |
| §4 자동 해결 처리 (admin_note CONCAT_WS, log) | Task 2.3 attemptAutoResolveIssue, Task 8 INV |
| §5 안전장치 #1 재계산 | Task 2.3 (attemptAutoResolveIssue 내부 inspect 재호출) |
| §5 안전장치 #2 status='pending' 가드 | Task 2.3 UPDATE WHERE status='pending' + SELECT FOR UPDATE |
| §5 안전장치 #3 트랜잭션 + 한 row 실패 격리 | Task 3.3 bulkAutoResolveIssues (try/catch per row) |
| §5 안전장치 #4 no_data 자동 제외 | Task 2.3 mission_status==='all_checked' 만 통과 |
| §5 안전장치 #5 admin_note CONCAT | Task 2.3 CASE WHEN |
| §5 안전장치 #6 audit log | Task 2.3 logIssueStatusChange, Task 8 INV-3 |
| §5 안전장치 #7 operation 권한 | Task 2.5/3.3 requireAdmin(['operation']) |
| §6 테스트 invariants/regression/smoke | Task 1~3 (unit), Task 8 (invariants), Task 9 (DEV smoke) |
| §7 영향 범위 (DB 마이그 0) | 어느 태스크에도 ALTER/CREATE 없음 — OK |

**2. Placeholder scan**

- "TBD" / "TODO" / "..." 없음. 모든 code block 완성.
- "Add appropriate error handling" 같은 추상 지시 없음 — try/catch 포함된 구체 코드.

**3. Type consistency**

- `inspectIssueMission` 반환 키: `mission_status`, `unchecked_dates`, `checked_dates`, `inspected_range` — 모든 사용처(Task 4/5/6/7) 일치.
- `attemptAutoResolveIssue` 반환: `ok`, `reason`, `inspection` — Task 3 bulkAutoResolveIssues 에서 `r['ok']`, `r['reason']` 사용 — 일치.
- API endpoint 명: `issue_admin_resolve_auto`, `issue_admin_resolve_auto_bulk` — Task 4/6/7 일치.
- chip class 이름: `mission-chip`, `mission-chip--ok/miss/none` — JS (Task 5.1) 와 CSS (Task 5.4) 일치.

**4. Risk note**

- Task 4.1 의 `handleIssueAdminList` 가 N개 pending row 마다 `inspectIssueMission` 을 호출 → 각 호출이 2 SELECT (issue + cohort + member_mission_checks). N=200 일 때 ~600 query. dev DB 부담 가벼움. PROD 도 admin 한 명이 가끔 새로고침하므로 OK. 부하 문제 발생 시 후속 spec 으로 JOIN one-shot 또는 캐시.
