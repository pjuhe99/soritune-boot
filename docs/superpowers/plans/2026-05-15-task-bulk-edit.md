# Task 관리 일괄 수정/삭제 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** boot.soritune.com `/operation` → "Task 관리" 탭에서 (cohort+title+role) 묶음 단위로 일괄 수정·삭제 지원.

**Architecture:** DB 마이그 0건. 기존 (cohort, title, role) 컬럼 조합을 그룹 키로 쓴다. API 4개(`task_group_get`, `all_tasks_grouped`, `task_group_update`, `task_group_delete`) 추가하고 운영자 화면(JS) 의 task 관리 탭만 그룹화 UI 로 교체. 회원·코치·조장 화면은 row 단위 응답을 그대로 받으므로 영향 없다.

**Tech Stack:** PHP 8 (`api/admin.php`), MariaDB 10, Vanilla JS (`js/admin.js`), cURL 기반 통합 테스트 (`tests/*.php`).

**Spec:** [docs/superpowers/specs/2026-05-15-task-bulk-edit-design.md](../specs/2026-05-15-task-bulk-edit-design.md)

---

## File Structure

| 파일 | 변경 | 책임 |
|---|---|---|
| `public_html/api/admin.php` | 수정 (case 4개 추가) | task_group_* 액션 |
| `public_html/js/admin.js` | 수정 (Task 관리 영역) | 그룹화 목록 + 그룹 수정/삭제 모달 |
| `tests/task_group_api_test.php` | 신규 | 4개 endpoint HTTP 통합 테스트 |
| `tests/task_group_invariants.php` | 신규 | 그룹 정책 인보리언트 (today_tasks 무영향, completed 보존) |

---

### Task 1: 통합 테스트 fixture 와 첫 endpoint (`task_group_get`)

**Files:**
- Create: `tests/task_group_api_test.php`
- Modify: `public_html/api/admin.php` (1184 line 직전, "Task CRUD" 주석 위)

가장 단순한 endpoint 부터. fixture 패턴은 `tests/multipass_api_test.php` 와 동일.

- [ ] **Step 1.1: 테스트 파일 생성 (fixture + first failing test)**

```php
<?php
/**
 * Task 그룹 일괄 수정/삭제 API 통합 테스트.
 *
 * 사용:
 *   ADMIN_COOKIE='PHPSESSID_ADMIN=...' DEV_BASE='https://dev-boot.soritune.com' \
 *     php tests/task_group_api_test.php
 *
 * 사전:
 *   - operation 권한 admin 으로 로그인된 PHPSESSID_ADMIN 쿠키 필요
 *   - DEV cohorts 에 12기 row 존재 (chip 활성)
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

$base = getenv('DEV_BASE') ?: 'https://dev-boot.soritune.com';
$cookie = getenv('ADMIN_COOKIE') ?: '';
if (!$cookie) { echo "ADMIN_COOKIE 환경변수 필수\n"; exit(2); }

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

function req(string $method, string $url, array $headers, ?array $json = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    if ($raw === false) return ['code' => 0, 'body' => null];
    $body = substr($raw, $info['header_size']);
    return ['code' => $info['http_code'], 'body' => json_decode($body, true) ?? ['raw' => $body]];
}

require_once __DIR__ . '/../public_html/config.php';
$db = getDB();

$base = rtrim($base, '/');
$api = $base . '/api/admin.php';
$h = ["Cookie: $cookie", "Content-Type: application/json"];

// ── Fixture ─────────────────────────────────────
// 안전하게 isolated cohort 사용: 가장 최근 cohort
$cohortRow = $db->query("SELECT cohort FROM cohorts ORDER BY start_date DESC LIMIT 1")->fetch();
if (!$cohortRow) { echo "SKIP — cohorts 비어있음\n"; exit(0); }
$cohort = $cohortRow['cohort'];

// 시드 task: 같은 (cohort, title='__test_group__', role='operation') 5개 row
$titleA = '__test_group__';
$db->prepare("DELETE FROM tasks WHERE title LIKE '__test_group%'")->execute();
$insert = $db->prepare("
    INSERT INTO tasks (title, role, assignee_admin_id, completed, start_date, end_date, content_markdown, cohort)
    VALUES (?, 'operation', NULL, ?, ?, ?, ?, ?)
");
$dates = ['2099-01-01','2099-01-02','2099-01-03','2099-01-04','2099-01-05'];
foreach ($dates as $i => $d) {
    // 마지막 2개는 완료 처리 (group_delete 정책 검증용)
    $completed = ($i >= 3) ? 1 : 0;
    $insert->execute([$titleA, $completed, $d, $d, '본문 v1', $cohort]);
}

// ── Test: task_group_get ────────────────────────
$r = req('POST', "$api?action=task_group_get", $h, [
    'cohort' => $cohort, 'title' => $titleA, 'role' => 'operation',
]);
t('task_group_get returns 200', $r['code'] === 200, "code={$r['code']}");
t('task_group_get returns title', ($r['body']['title'] ?? null) === $titleA, json_encode($r['body']));
t('task_group_get returns content_markdown', ($r['body']['content_markdown'] ?? null) === '본문 v1');

// 없는 그룹
$r = req('POST', "$api?action=task_group_get", $h, [
    'cohort' => $cohort, 'title' => '__no_such_group__', 'role' => 'operation',
]);
t('task_group_get 404 on missing group', $r['code'] === 404, "code={$r['code']}");

// ── Cleanup ─────────────────────────────────────
$db->prepare("DELETE FROM tasks WHERE title LIKE '__test_group%'")->execute();

echo "\n결과: PASS={$pass}  FAIL={$fail}\n";
exit($fail ? 1 : 0);
```

- [ ] **Step 1.2: 테스트 실행해서 실패 확인**

```bash
ADMIN_COOKIE='PHPSESSID_ADMIN=<운영자 세션>' php /root/boot-dev/tests/task_group_api_test.php
```

Expected:
```
FAIL  task_group_get returns 200  (code=400  또는 raw HTML)
FAIL  task_group_get returns title
FAIL  task_group_get returns content_markdown
FAIL  task_group_get 404 on missing group  (code=400)
```

(아직 endpoint 가 없어서 admin.php 의 default case "올바르지 않은 action" 으로 떨어진다.)

- [ ] **Step 1.3: `task_group_get` 구현**

`public_html/api/admin.php` 의 `// ── Task CRUD (operation only for create/update/delete) ─────` 주석 (line 1184) **위에** 다음 블록을 삽입:

```php
// ── Task Group CRUD (operation only) ─────
// 묶음 키 = (cohort, title, role)

case 'task_group_get':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $cohort = trim($input['cohort'] ?? '');
    $title  = trim($input['title']  ?? '');
    $role   = trim($input['role']   ?? '');
    if (!$cohort || !$title || !$role) jsonError('cohort/title/role 필수.');

    $db = getDB();
    $stmt = $db->prepare("
        SELECT title, content_markdown
          FROM tasks
         WHERE cohort = ? AND title = ? AND role = ?
         ORDER BY start_date ASC
         LIMIT 1
    ");
    $stmt->execute([$cohort, $title, $role]);
    $row = $stmt->fetch();
    if (!$row) jsonError('해당 묶음을 찾을 수 없습니다.', 404);
    jsonSuccess([
        'title' => $row['title'],
        'content_markdown' => $row['content_markdown'],
    ]);
    break;
```

- [ ] **Step 1.4: 테스트 재실행해서 통과 확인**

```bash
ADMIN_COOKIE='PHPSESSID_ADMIN=...' php /root/boot-dev/tests/task_group_api_test.php
```

Expected: `PASS=4  FAIL=0`

- [ ] **Step 1.5: Commit**

```bash
cd /root/boot-dev && git add tests/task_group_api_test.php public_html/api/admin.php \
  && git commit -m "feat(api): task_group_get endpoint + 통합 테스트 fixture

(cohort, title, role) 묶음 식별자로 첫 row 의 title·content_markdown 을
대표값으로 반환. 그룹 수정 모달이 prefill 용으로 호출.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: `all_tasks_grouped` 그룹 목록 endpoint

**Files:**
- Modify: `tests/task_group_api_test.php` (테스트 추가)
- Modify: `public_html/api/admin.php` (case 추가)

- [ ] **Step 2.1: 실패하는 테스트 추가 (Cleanup 직전에 삽입)**

`tests/task_group_api_test.php` 의 `// ── Cleanup ────` 주석 **위에** 다음 추가:

```php
// ── Test: all_tasks_grouped ─────────────────────
$r = req('GET', "$api?action=all_tasks_grouped&filter_role=all", $h);
t('all_tasks_grouped returns 200', $r['code'] === 200, "code={$r['code']}");

$groups = $r['body']['groups'] ?? [];
$ourGroup = null;
foreach ($groups as $g) {
    if ($g['title'] === $titleA && $g['role'] === 'operation' && $g['cohort'] === $cohort) {
        $ourGroup = $g; break;
    }
}
t('all_tasks_grouped includes seeded group', $ourGroup !== null);
t('all_tasks_grouped total_count=5', $ourGroup && (int)$ourGroup['total_count'] === 5,
   $ourGroup ? json_encode($ourGroup) : 'not found');
t('all_tasks_grouped done_count=2', $ourGroup && (int)$ourGroup['done_count'] === 2);
t('all_tasks_grouped min_start_date=2099-01-01', $ourGroup && $ourGroup['min_start_date'] === '2099-01-01');
t('all_tasks_grouped max_end_date=2099-01-05', $ourGroup && $ourGroup['max_end_date'] === '2099-01-05');
t('all_tasks_grouped assignee_count>=1', $ourGroup && (int)$ourGroup['assignee_count'] >= 1);
```

- [ ] **Step 2.2: 테스트 실행해서 신규 케이스 fail 확인**

Expected: 위 7개 새 케이스가 모두 FAIL (action not found → 400 default case).

- [ ] **Step 2.3: `all_tasks_grouped` 구현**

`task_group_get` case **다음에** 삽입:

```php
case 'all_tasks_grouped':
    $admin = requireAdmin();
    if (!hasRole($admin, 'operation')) jsonError('권한이 없습니다.', 403);
    $cohort = getEffectiveCohort($admin);
    $filterRole = $_GET['filter_role'] ?? '';
    $adminId = $admin['admin_id'];

    // 기존 all_tasks 의 필터 SQL 을 그대로 GROUP BY 로 변환
    $db = getDB();
    if ($filterRole === 'mine') {
        $stmt = $db->prepare("
            SELECT t.cohort, t.title, t.role,
                   COUNT(*)                                AS total_count,
                   SUM(t.completed)                        AS done_count,
                   MIN(t.start_date)                       AS min_start_date,
                   MAX(t.end_date)                         AS max_end_date,
                   COUNT(DISTINCT COALESCE(t.assignee_admin_id, 0),
                                  COALESCE(t.assignee_member_id, 0)) AS assignee_count
              FROM tasks t
             WHERE t.cohort = ?
               AND (t.assignee_admin_id = ? OR t.assignee_member_id = ?)
             GROUP BY t.cohort, t.title, t.role
             ORDER BY t.role, MIN(t.start_date) DESC, t.title
        ");
        $stmt->execute([$cohort, $adminId, $adminId]);
    } elseif ($filterRole && $filterRole !== 'all') {
        $stmt = $db->prepare("
            SELECT t.cohort, t.title, t.role,
                   COUNT(*)                                AS total_count,
                   SUM(t.completed)                        AS done_count,
                   MIN(t.start_date)                       AS min_start_date,
                   MAX(t.end_date)                         AS max_end_date,
                   COUNT(DISTINCT COALESCE(t.assignee_admin_id, 0),
                                  COALESCE(t.assignee_member_id, 0)) AS assignee_count
              FROM tasks t
             WHERE t.cohort = ? AND t.role = ?
             GROUP BY t.cohort, t.title, t.role
             ORDER BY MIN(t.start_date) DESC, t.title
        ");
        $stmt->execute([$cohort, $filterRole]);
    } else {
        $stmt = $db->prepare("
            SELECT t.cohort, t.title, t.role,
                   COUNT(*)                                AS total_count,
                   SUM(t.completed)                        AS done_count,
                   MIN(t.start_date)                       AS min_start_date,
                   MAX(t.end_date)                         AS max_end_date,
                   COUNT(DISTINCT COALESCE(t.assignee_admin_id, 0),
                                  COALESCE(t.assignee_member_id, 0)) AS assignee_count
              FROM tasks t
             WHERE t.cohort = ?
             GROUP BY t.cohort, t.title, t.role
             ORDER BY t.role, MIN(t.start_date) DESC, t.title
        ");
        $stmt->execute([$cohort]);
    }
    jsonSuccess(['groups' => $stmt->fetchAll()]);
    break;
```

- [ ] **Step 2.4: 테스트 재실행해서 모두 통과 확인**

Expected: `PASS=11  FAIL=0`

- [ ] **Step 2.5: Commit**

```bash
cd /root/boot-dev && git add tests/task_group_api_test.php public_html/api/admin.php \
  && git commit -m "feat(api): all_tasks_grouped — (cohort,title,role) 단위 그룹 목록

기존 all_tasks 의 필터 분기(mine/role/all)를 그대로 따라 GROUP BY 로 변환.
total_count, done_count, min/max 날짜, assignee_count 집계 반환.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: `task_group_update` 일괄 수정 endpoint

**Files:**
- Modify: `tests/task_group_api_test.php`
- Modify: `public_html/api/admin.php`

- [ ] **Step 3.1: 실패하는 테스트 추가 (Cleanup 직전에 삽입)**

```php
// ── Test: task_group_update ─────────────────────
$newTitle = '__test_group__v2';
$r = req('POST', "$api?action=task_group_update", $h, [
    'cohort' => $cohort, 'title' => $titleA, 'role' => 'operation',
    'new_title' => $newTitle, 'new_content_markdown' => '본문 v2',
]);
t('task_group_update returns 200', $r['code'] === 200, "code={$r['code']}");
t('task_group_update affected_count=5', (int)($r['body']['affected_count'] ?? 0) === 5,
   json_encode($r['body']));

// 모든 row 가 새 title/content 로 바뀌었는지 DB 직접 확인
$verify = $db->prepare("SELECT title, content_markdown FROM tasks WHERE cohort = ? AND role = 'operation' AND start_date BETWEEN '2099-01-01' AND '2099-01-05'");
$verify->execute([$cohort]);
$rows = $verify->fetchAll();
$allRenamed = !empty($rows) && count(array_filter($rows, fn($r) => $r['title'] === $newTitle)) === count($rows);
$allContent = !empty($rows) && count(array_filter($rows, fn($r) => $r['content_markdown'] === '본문 v2')) === count($rows);
t('task_group_update 모든 row title 변경', $allRenamed);
t('task_group_update 모든 row content 변경', $allContent);

// 잘못된 입력 — new_title 빈 문자
$r = req('POST', "$api?action=task_group_update", $h, [
    'cohort' => $cohort, 'title' => $newTitle, 'role' => 'operation',
    'new_title' => '', 'new_content_markdown' => 'x',
]);
t('task_group_update 빈 new_title 거부', $r['code'] === 400);

// 옛 title 로 다시 update 시도 → 매칭 0
$r = req('POST', "$api?action=task_group_update", $h, [
    'cohort' => $cohort, 'title' => $titleA, 'role' => 'operation',
    'new_title' => '__test_group__v3', 'new_content_markdown' => 'v3',
]);
t('task_group_update 옛 title 매칭 0', (int)($r['body']['affected_count'] ?? -1) === 0);

// 다음 단계 (delete) 테스트 위해 v2 → titleA 로 복원
$db->prepare("UPDATE tasks SET title = ?, content_markdown = '본문 v1' WHERE title = ?")
   ->execute([$titleA, $newTitle]);
```

- [ ] **Step 3.2: 테스트 실행해서 신규 케이스 fail 확인**

Expected: 위 5개 새 케이스 FAIL.

- [ ] **Step 3.3: `task_group_update` 구현**

`all_tasks_grouped` case **다음에** 삽입:

```php
case 'task_group_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $cohort   = trim($input['cohort'] ?? '');
    $title    = trim($input['title']  ?? '');
    $role     = trim($input['role']   ?? '');
    $newTitle = trim($input['new_title'] ?? '');
    $newContent = trim($input['new_content_markdown'] ?? '');
    if (!$cohort || !$title || !$role) jsonError('cohort/title/role 필수.');
    if ($newTitle === '') jsonError('새 제목을 입력해주세요.');

    $db = getDB();
    $stmt = $db->prepare("
        UPDATE tasks
           SET title = ?, content_markdown = ?
         WHERE cohort = ? AND title = ? AND role = ?
    ");
    $stmt->execute([$newTitle, $newContent ?: null, $cohort, $title, $role]);
    jsonSuccess(['affected_count' => $stmt->rowCount()], 'Task 묶음이 수정되었습니다.');
    break;
```

- [ ] **Step 3.4: 테스트 재실행해서 모두 통과 확인**

Expected: `PASS=16  FAIL=0`

- [ ] **Step 3.5: Commit**

```bash
cd /root/boot-dev && git add tests/task_group_api_test.php public_html/api/admin.php \
  && git commit -m "feat(api): task_group_update — title/content 일괄 갱신

(cohort, title, role) 매칭된 모든 tasks row 의 title·content_markdown 을
한 statement 로 UPDATE. updated_at 은 ON UPDATE CURRENT_TIMESTAMP 자동.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: `task_group_delete` 일괄 삭제 endpoint

**Files:**
- Modify: `tests/task_group_api_test.php`
- Modify: `public_html/api/admin.php`

- [ ] **Step 4.1: 실패하는 테스트 추가 (Cleanup 직전에 삽입)**

```php
// ── Test: task_group_delete (completed=0 만, completed=1 보존) ──
$r = req('POST', "$api?action=task_group_delete", $h, [
    'cohort' => $cohort, 'title' => $titleA, 'role' => 'operation',
]);
t('task_group_delete returns 200', $r['code'] === 200, "code={$r['code']}");
t('task_group_delete deleted_count=3', (int)($r['body']['deleted_count'] ?? -1) === 3,
   json_encode($r['body']));
t('task_group_delete kept_count=2', (int)($r['body']['kept_count'] ?? -1) === 2);

// DB 검증 — completed=1 row 만 남아야 함
$verify = $db->prepare("SELECT completed FROM tasks WHERE cohort = ? AND title = ? AND role = 'operation'");
$verify->execute([$cohort, $titleA]);
$leftover = $verify->fetchAll();
$allCompleted = !empty($leftover) && count(array_filter($leftover, fn($r) => (int)$r['completed'] === 1)) === count($leftover);
t('task_group_delete 후 남은 row 모두 completed=1', $allCompleted, json_encode($leftover));

// 모두 완료된 그룹에 대해 다시 호출 → deleted_count=0, kept_count=2
$r = req('POST', "$api?action=task_group_delete", $h, [
    'cohort' => $cohort, 'title' => $titleA, 'role' => 'operation',
]);
t('task_group_delete 모두 완료 그룹 deleted=0', (int)($r['body']['deleted_count'] ?? -1) === 0);
t('task_group_delete 모두 완료 그룹 kept=2',    (int)($r['body']['kept_count'] ?? -1) === 2);
```

- [ ] **Step 4.2: 테스트 실행해서 신규 케이스 fail 확인**

Expected: 위 6개 새 케이스 FAIL.

- [ ] **Step 4.3: `task_group_delete` 구현**

`task_group_update` case **다음에** 삽입:

```php
case 'task_group_delete':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $cohort = trim($input['cohort'] ?? '');
    $title  = trim($input['title']  ?? '');
    $role   = trim($input['role']   ?? '');
    if (!$cohort || !$title || !$role) jsonError('cohort/title/role 필수.');

    $db = getDB();
    $del = $db->prepare("
        DELETE FROM tasks
         WHERE cohort = ? AND title = ? AND role = ? AND completed = 0
    ");
    $del->execute([$cohort, $title, $role]);
    $deleted = $del->rowCount();

    $cnt = $db->prepare("
        SELECT COUNT(*) AS c
          FROM tasks
         WHERE cohort = ? AND title = ? AND role = ?
    ");
    $cnt->execute([$cohort, $title, $role]);
    $kept = (int)$cnt->fetch()['c'];

    jsonSuccess([
        'deleted_count' => $deleted,
        'kept_count'    => $kept,
    ], "{$deleted}개 삭제 / {$kept}개 보존");
    break;
```

- [ ] **Step 4.4: 테스트 재실행해서 모두 통과 확인**

Expected: `PASS=22  FAIL=0`

- [ ] **Step 4.5: Commit**

```bash
cd /root/boot-dev && git add tests/task_group_api_test.php public_html/api/admin.php \
  && git commit -m "feat(api): task_group_delete — 미완료 row 만 삭제, 완료 row 보존

이력 보존 정책: completed=1 row 는 그대로 두고 completed=0 만 DELETE.
응답에 deleted_count + kept_count 둘 다 포함.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: 인보리언트 테스트 — today_tasks/overdue_tasks 무영향 + 권한

**Files:**
- Create: `tests/task_group_invariants.php`

API 가 회원·코치·조장 화면용 row 단위 응답에 영향이 없음을 확인. DB 직접 조회 패턴 (cookie 불필요).

- [ ] **Step 5.1: invariants 테스트 작성**

```php
<?php
/**
 * Task 그룹 일괄 수정/삭제 인보리언트.
 *
 * 사용:
 *   php tests/task_group_invariants.php
 *
 * (DEV DB 직접 조회 — 운영 cookie 불필요)
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
$db = getDB();

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// ── Setup: 같은 묶음 4 row + 다른 그룹 1 row ─────
$cohortRow = $db->query("SELECT cohort FROM cohorts ORDER BY start_date DESC LIMIT 1")->fetch();
if (!$cohortRow) { echo "SKIP — cohorts 비어있음\n"; exit(0); }
$cohort = $cohortRow['cohort'];

$db->prepare("DELETE FROM tasks WHERE title LIKE '__inv_grp%'")->execute();
$ins = $db->prepare("
    INSERT INTO tasks (title, role, assignee_admin_id, completed, start_date, end_date, content_markdown, cohort)
    VALUES (?, 'operation', NULL, ?, ?, ?, 'c1', ?)
");
$ins->execute(['__inv_grp_a', 0, '2099-02-01', '2099-02-01', $cohort]);
$ins->execute(['__inv_grp_a', 0, '2099-02-02', '2099-02-02', $cohort]);
$ins->execute(['__inv_grp_a', 1, '2099-02-03', '2099-02-03', $cohort]);
$ins->execute(['__inv_grp_a', 1, '2099-02-04', '2099-02-04', $cohort]);
$ins->execute(['__inv_grp_b', 0, '2099-02-01', '2099-02-01', $cohort]);

// ── INV-1: group_update 시뮬레이트 — title 변경이 다른 그룹에 영향 없음 ──
$db->prepare("
    UPDATE tasks SET title = '__inv_grp_a_v2', content_markdown = 'c2'
     WHERE cohort = ? AND title = '__inv_grp_a' AND role = 'operation'
")->execute([$cohort]);

$other = $db->prepare("SELECT title, content_markdown FROM tasks WHERE cohort = ? AND title = '__inv_grp_b'");
$other->execute([$cohort]);
$otherRow = $other->fetch();
t('INV-1 다른 그룹(__inv_grp_b) title 보존', $otherRow && $otherRow['title'] === '__inv_grp_b');
t('INV-1 다른 그룹 content 보존', $otherRow && $otherRow['content_markdown'] === 'c1');

$updated = $db->prepare("SELECT COUNT(*) c FROM tasks WHERE cohort = ? AND title = '__inv_grp_a_v2'");
$updated->execute([$cohort]);
t('INV-1 그룹 4 row 모두 새 title', (int)$updated->fetch()['c'] === 4);

// ── INV-2: group_delete 시뮬레이트 — completed=1 row 보존 ──
$db->prepare("
    DELETE FROM tasks
     WHERE cohort = ? AND title = '__inv_grp_a_v2' AND role = 'operation' AND completed = 0
")->execute([$cohort]);

$leftover = $db->prepare("SELECT completed FROM tasks WHERE cohort = ? AND title = '__inv_grp_a_v2'");
$leftover->execute([$cohort]);
$rows = $leftover->fetchAll();
t('INV-2 미완료 2개 삭제 (남은 row 2개)', count($rows) === 2);
t('INV-2 남은 row 모두 completed=1',
   count($rows) === 2 && count(array_filter($rows, fn($r) => (int)$r['completed'] === 1)) === 2);

// ── INV-3: today_tasks 가 그룹 변경 후에도 정상 row 단위 응답 ──
//   (오늘 날짜에 매치되는 row 만 — 위 시드는 2099 라 매치 0 이지만,
//    오늘 일자 시드 1개 추가해서 row 단위 SELECT 가 작동함을 확인)
$today = date('Y-m-d');
$db->prepare("DELETE FROM tasks WHERE title = '__inv_today'")->execute();
$ins->execute(['__inv_today', 0, $today, $today, $cohort]);

$todayRows = $db->prepare("
    SELECT t.id, t.title, t.completed
      FROM tasks t
     WHERE t.cohort = ? AND t.start_date <= ? AND t.end_date >= ?
");
$todayRows->execute([$cohort, $today, $today]);
$found = false;
foreach ($todayRows->fetchAll() as $r) {
    if ($r['title'] === '__inv_today') { $found = true; break; }
}
t('INV-3 today_tasks row 단위 응답 정상', $found);

// ── Cleanup ─────────────────────────────────────
$db->prepare("DELETE FROM tasks WHERE title LIKE '__inv_grp%' OR title = '__inv_today'")->execute();

echo "\n결과: PASS={$pass}  FAIL={$fail}\n";
exit($fail ? 1 : 0);
```

- [ ] **Step 5.2: 인보리언트 실행 (DB 패턴이 spec 과 일치하는지만 확인 — 새 endpoint 없어도 PASS 해야 함)**

```bash
php /root/boot-dev/tests/task_group_invariants.php
```

Expected: `PASS=6  FAIL=0`

(이 테스트는 endpoint 가 아니라 SQL 패턴 자체를 검증하는 것이라, Task 3/4 의 endpoint 없이도 통과한다. endpoint 가 같은 SQL 을 쓴다는 것을 보장하기 위한 안전망.)

- [ ] **Step 5.3: Commit**

```bash
cd /root/boot-dev && git add tests/task_group_invariants.php \
  && git commit -m "test(invariants): task 그룹 정책 — 다른 그룹 보존 / completed 보존 / today_tasks 무영향

API endpoint 와 동일한 SQL 패턴을 DB 직접 실행해서 정책 자체를 검증.
endpoint 호출 없이 동작하므로 cookie 불필요.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: UI — `loadTasksMgmt()` 그룹화 테이블

**Files:**
- Modify: `public_html/js/admin.js:1422-1478` (`loadTasksMgmt` 함수 전체 교체)

기존 row 단위 테이블을 그룹 단위로 교체. `App.get('all_tasks_grouped')` 호출하고, 컬럼 구성 변경.

- [ ] **Step 6.1: 변경 전 코드 확인**

`public_html/js/admin.js` 의 line 1422 ~ 1478 (`loadTasksMgmt` 함수).

- [ ] **Step 6.2: `loadTasksMgmt` 함수 교체**

기존 함수 본문(`async function loadTasksMgmt() { ... }`) 을 다음으로 교체:

```javascript
    async function loadTasksMgmt() {
        const sec = document.getElementById('tab-tasks-mgmt');
        sec.innerHTML = '<div class="empty-state">로딩 중...</div>';

        const r = await App.get('/api/admin.php?action=all_tasks_grouped', { filter_role: taskMgmtFilter });
        const groups = r.success ? (r.groups || []) : [];

        const filters = [
            { key: 'mine', label: '내 Task' },
            { key: 'all', label: '전체' },
            { key: 'coach', label: '메인강사' },
            { key: 'sub_coach', label: '서브강사' },
            { key: 'head', label: '총괄' },
            { key: 'leader', label: '조장' },
            { key: 'operation', label: '운영팀' },
        ];

        // 진행 배지 헬퍼
        function progressBadge(done, total) {
            done = parseInt(done) || 0;
            total = parseInt(total) || 0;
            if (total === 0) return '-';
            if (done === 0)     return `<span class="badge badge-warning">미완료 0/${total}</span>`;
            if (done === total) return `<span class="badge badge-success">완료 ${done}/${total}</span>`;
            return `<span class="badge badge-primary">진행 ${done}/${total}</span>`;
        }
        function periodLabel(min, max) {
            if (!min || !max) return '-';
            return min === max ? min : `${min} ~ ${max}`;
        }

        sec.innerHTML = `
            <div class="mgmt-toolbar mt-md">
                <span style="font-weight:600">Task 관리 <span class="count">${groups.length}개 묶음</span></span>
                <button class="btn btn-primary btn-sm" id="btn-add-task">추가</button>
            </div>
            <div class="task-filter-chips" id="task-mgmt-filter" style="margin-bottom:var(--space-3)">
                ${filters.map(f => `
                    <button class="chip ${taskMgmtFilter === f.key ? 'active' : ''}" data-mgmt-filter="${f.key}">${App.esc(f.label)}</button>
                `).join('')}
            </div>
            <div style="overflow-x:auto">
                <table class="data-table">
                    <thead><tr><th>제목</th><th>역할</th><th>담당자</th><th>기간</th><th>진행</th><th></th></tr></thead>
                    <tbody>
                        ${groups.map(g => {
                            const cohortAttr = encodeURIComponent(g.cohort);
                            const titleAttr  = encodeURIComponent(g.title);
                            const roleAttr   = encodeURIComponent(g.role);
                            return `
                            <tr>
                                <td>${App.esc(g.title)}</td>
                                <td><span class="badge badge-primary">${App.esc(ROLE_LABELS[g.role] || g.role)}</span></td>
                                <td>${parseInt(g.assignee_count) || 0}명</td>
                                <td style="white-space:nowrap">${periodLabel(g.min_start_date, g.max_end_date)}</td>
                                <td>${progressBadge(g.done_count, g.total_count)}</td>
                                <td class="actions">
                                    <button class="btn-icon" onclick="AdminApp._editTaskGroup('${cohortAttr}','${titleAttr}','${roleAttr}')">수정</button>
                                    <button class="btn-icon danger" onclick="AdminApp._deleteTaskGroup('${cohortAttr}','${titleAttr}','${roleAttr}',${parseInt(g.total_count)||0},${parseInt(g.done_count)||0})">삭제</button>
                                </td>
                            </tr>
                        `;}).join('')}
                    </tbody>
                </table>
            </div>
        `;
        if (!groups.length) sec.querySelector('tbody').innerHTML = '<tr><td colspan="6" class="empty-state">Task 묶음이 없습니다.</td></tr>';
        document.getElementById('btn-add-task').onclick = () => showTaskForm();
        document.getElementById('task-mgmt-filter').querySelectorAll('.chip').forEach(btn => {
            btn.onclick = () => {
                taskMgmtFilter = btn.dataset.mgmtFilter;
                loadTasksMgmt();
            };
        });
    }
```

- [ ] **Step 6.3: dev-boot.soritune.com 에서 브라우저 확인 (수동)**

1. `https://dev-boot.soritune.com/operation` 접속
2. "Task 관리" 탭 클릭
3. 기존 row 가 `(cohort, title, role)` 단위로 그룹화되어 표시되는지 확인
4. 진행 배지 (완료 N/M / 진행 / 미완료) 표시 확인
5. 필터 칩 (mine/all/role 별) 동작 확인
6. (수정/삭제 버튼은 다음 task 에서 와이어업)

- [ ] **Step 6.4: Commit**

```bash
cd /root/boot-dev && git add public_html/js/admin.js \
  && git commit -m "feat(ui): Task 관리 목록을 (cohort,title,role) 그룹 단위로 표시

데일리 묶음이 row 수십 개로 늘어지던 문제 해소. 진행 배지(완료/진행/미완료)
+ 기간(min~max) + 담당자 N명 형태로 한 행에 요약. 수정/삭제 버튼 와이어업은
다음 commit.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: UI — `showTaskForm` 그룹 수정 모드 + `_editTaskGroup`/`_deleteTaskGroup`

**Files:**
- Modify: `public_html/js/admin.js:1482-1657` (`showTaskForm` 의 isEdit 분기 + `_editTask`/`_deleteTask` 영역)

수정 모드를 그룹 단위로 바꾸고 새 헬퍼 함수 추가.

- [ ] **Step 7.1: `showTaskForm` 의 `isEdit` 분기 변경**

기존 `function showTaskForm(data = {}) { const isEdit = !!data.id; ... }` 의 시작 부분을 다음과 같이 바꾼다 (그룹 키 기반으로 isEdit 판정):

```javascript
    function showTaskForm(data = {}) {
        const isEdit = !!data.groupKey; // { cohort, title, role }
```

`isEdit` 분기 안의 `roleSection` 을 다음으로 교체 (read-only 식별 정보 박스 + role 선택 제거):

```javascript
        let roleSection;
        if (isEdit) {
            roleSection = `
                <div class="form-group">
                    <label class="form-label">묶음 정보</label>
                    <div style="background:var(--gray-50,#f5f5f5);border:1px solid var(--gray-200,#e5e5e5);border-radius:8px;padding:12px;font-size:0.9rem;line-height:1.6">
                        <div><strong>역할</strong>: ${App.esc(ROLE_LABELS[data.groupKey.role] || data.groupKey.role)}</div>
                        <div><strong>기수</strong>: ${App.esc(data.groupKey.cohort)}</div>
                        <div><strong>기간</strong>: ${App.esc(data.periodLabel || '-')}</div>
                        <div><strong>총 ${data.totalCount || 0}개</strong> (완료 ${data.doneCount || 0} / 미완료 ${(data.totalCount || 0) - (data.doneCount || 0)})</div>
                    </div>
                    <p class="text-muted" style="font-size:0.8rem;margin-top:6px">* 역할·기간은 묶음 식별 정보라 일괄 수정 대상이 아닙니다. 변경하려면 삭제 후 다시 만들어주세요.</p>
                </div>
            `;
        } else {
            roleSection = `
                <div class="form-group">
                    <label class="form-label">담당 역할 * (복수 선택 가능)</label>
                    <div style="display:flex;flex-wrap:wrap;padding:8px 0">
                        ${renderRoleCheckboxes([], 'tf')}
                    </div>
                </div>
            `;
        }
```

`isEdit` 일 때 날짜 섹션은 보이지 않게 한다. 기존 코드의 `directDateSection` (line 1525-1536) 은 isEdit 분기에서 빈 문자열로:

```javascript
        // Direct date fields — 그룹 수정 모드에서는 비활성
        const directDateSection = isEdit ? '' : `
            <div id="tf-mode-direct" class="tf-date-section">
                <div class="form-group">
                    <label class="form-label">시작일 *</label>
                    <input type="date" class="form-input" id="tf-start" value="${data.start_date || ''}">
                </div>
                <div class="form-group">
                    <label class="form-label">종료일 *</label>
                    <input type="date" class="form-input" id="tf-end" value="${data.end_date || ''}">
                </div>
            </div>
        `;
```

`isEdit` 시 저장 payload 변경 — 기존 `if (isEdit) { payload.id = data.id; ... }` 블록을 다음으로 교체:

```javascript
            if (isEdit) {
                if (!payload.title) return Toast.warning('제목을 입력해주세요.');
                payload.cohort = data.groupKey.cohort;
                payload.title  = data.groupKey.title;       // 옛 title (식별용)
                payload.role   = data.groupKey.role;
                payload.new_title = document.getElementById('tf-title').value.trim();
                payload.new_content_markdown = document.getElementById('tf-content').value.trim();
                if (!payload.new_title) return Toast.warning('제목을 입력해주세요.');
            } else {
```

저장 호출 부분 (`App.post(\`/api/admin.php?action=${isEdit ? 'task_update' : 'task_create'}\`, payload)`) 을 다음으로:

```javascript
            App.showLoading();
            const action = isEdit ? 'task_group_update' : 'task_create';
            const r = await App.post(`/api/admin.php?action=${action}`, payload);
            App.hideLoading();
```

`isEdit` 인 경우 모달 입력 prefill 을 위해 `App.openModal` 직후가 아니라 함수 진입 시점에 데이터를 로드해야 한다. 다음 헬퍼를 `showTaskForm` 함수 **밖, 같은 IIFE 스코프 안에** 추가:

```javascript
    async function _editTaskGroup(cohortEnc, titleEnc, roleEnc) {
        const cohort = decodeURIComponent(cohortEnc);
        const title  = decodeURIComponent(titleEnc);
        const role   = decodeURIComponent(roleEnc);

        // 그룹 메타 (집계) 와 prefill 값 (title/content) 둘 다 필요
        const [grouped, single] = await Promise.all([
            App.get('/api/admin.php?action=all_tasks_grouped', { filter_role: 'all' }),
            App.post('/api/admin.php?action=task_group_get', { cohort, title, role }),
        ]);
        if (!single.success) return Toast.error(single.message || '묶음 조회 실패');
        const g = (grouped.groups || []).find(x =>
            x.cohort === cohort && x.title === title && x.role === role);
        if (!g) return Toast.error('묶음 메타를 찾을 수 없습니다.');

        const periodLabel = (g.min_start_date === g.max_end_date)
            ? g.min_start_date
            : `${g.min_start_date} ~ ${g.max_end_date}`;

        showTaskForm({
            groupKey: { cohort, title, role },
            title: single.title,
            content_markdown: single.content_markdown,
            periodLabel,
            totalCount: parseInt(g.total_count) || 0,
            doneCount:  parseInt(g.done_count)  || 0,
        });
    }

    async function _deleteTaskGroup(cohortEnc, titleEnc, roleEnc, totalCount, doneCount) {
        const cohort = decodeURIComponent(cohortEnc);
        const title  = decodeURIComponent(titleEnc);
        const role   = decodeURIComponent(roleEnc);
        const incomplete = (totalCount || 0) - (doneCount || 0);

        let msg;
        if (incomplete === 0) {
            Toast.info('이미 모두 완료된 묶음입니다. 삭제할 row 가 없습니다.');
            return;
        } else if (doneCount === 0) {
            msg = `'${title}' 묶음 ${incomplete}개를 삭제하시겠습니까?`;
        } else {
            msg = `'${title}' 묶음의 미완료 ${incomplete}개를 삭제합니다.\n이력 보존을 위해 완료된 ${doneCount}개는 남깁니다.\n진행할까요?`;
        }
        if (!await App.confirm(msg)) return;

        App.showLoading();
        const r = await App.post('/api/admin.php?action=task_group_delete', { cohort, title, role });
        App.hideLoading();
        if (r.success) {
            Toast.success(r.message || `${r.deleted_count}개 삭제 / ${r.kept_count}개 보존`);
            loadTasksMgmt();
            loadTodayTasks();
            loadOverdueTasks();
        }
    }
```

이 함수들을 모듈 export 객체에 등록. `js/admin.js:2013` 의 export 라인을 다음과 같이 변경:

```javascript
        _editTask, _deleteTask, _editTaskGroup, _deleteTaskGroup,
```

(기존 `_editTask`/`_deleteTask` 함수와 onclick 호출은 그대로 둔다 — 향후 그룹 펼치기 기능에서 재사용 가능. 다만 Task 관리 탭의 onclick 은 `_editTaskGroup`/`_deleteTaskGroup` 만 호출하게 Step 6.2 에서 이미 변경됨.)

- [ ] **Step 7.2: 변경 후 dev-boot.soritune.com 브라우저 검증 (수동)**

1. `https://dev-boot.soritune.com/operation` → "Task 관리" 탭
2. 그룹 1개 "수정" 클릭 → 모달 상단 회색 박스에 역할/기수/기간/N개 표시
3. 제목/내용 변경 후 저장 → 토스트 확인 → 새로고침 후 그룹 모두 새 title/content 로 바뀐 것 확인
4. "삭제" 클릭 → confirm 문구 확인 (미완료/완료 수에 따라 분기)
5. 삭제 후 토스트 "X개 삭제 / Y개 보존" 확인 + 목록 갱신
6. 추가 버튼은 기존 동작 유지 (변경 없음)

- [ ] **Step 7.3: Commit**

```bash
cd /root/boot-dev && git add public_html/js/admin.js \
  && git commit -m "feat(ui): Task 관리 — 그룹 단위 수정/삭제 모달

수정 모달은 역할/기간/총개수를 read-only 박스로 보여주고 제목·내용만 입력.
삭제는 미완료/완료 row 수에 따라 confirm 문구 분기 + 토스트에 deleted/kept
표시. 호출 액션은 task_group_update / task_group_delete.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: 회원·코치·조장 화면 회귀 검증 + DEV 통합 smoke

**Files:** 변경 없음. 검증만.

- [ ] **Step 8.1: 회원·코치·조장 화면이 그룹 수정 후에도 정상 동작하는지 확인**

브라우저로 다음 페이지 각각 열어 today/overdue task 표시 확인:

1. 운영자 첫 화면 — `오늘의 Task` 섹션 (taskFilter=mine 기본값)
2. 코치 (head/coach 권한 admin) 로그인 → today/overdue task 정상 표시
3. 조장 회원 로그인 → today/overdue task 정상 표시 (assignee_member_id 매칭)

체크 포인트: row 단위 응답이 정상이고, `toggle_task` 체크박스 동작도 그대로.

- [ ] **Step 8.2: HTTP API 통합 테스트 풀 실행**

```bash
ADMIN_COOKIE='PHPSESSID_ADMIN=...' php /root/boot-dev/tests/task_group_api_test.php
php /root/boot-dev/tests/task_group_invariants.php
```

Expected: API 22 PASS / Invariants 6 PASS.

- [ ] **Step 8.3: 다른 invariants 회귀 (영향 가능성 있는 것만)**

```bash
php /root/boot-dev/tests/cohort_switch_invariants.php
```

Expected: 기존 PASS 상태 유지.

- [ ] **Step 8.4: DEV push**

```bash
cd /root/boot-dev && git push origin dev
```

⛔ **여기서 멈춤. 사용자에게 dev 검증 요청.**

---

## 검증 시나리오 (사용자가 DEV 에서 돌릴 것)

배포 후 사용자가 직접 확인:

1. **데일리 묶음 일괄 수정**: daily 모드로 새 task 생성 → 묶음 1행 표시 확인 → 수정 → 모든 row 일괄 변경.
2. **일부 완료 후 삭제**: 그룹 일부 row 를 today_tasks 화면에서 toggle 완료 → 그룹 삭제 → 미완료만 사라지고 완료 row 남는 것 확인.
3. **모두 완료된 그룹 삭제 시도**: confirm 단계 전에 "이미 모두 완료" 토스트.
4. **단건(크기 1) 그룹 수정/삭제**: direct/week 모드로 만든 단건 task 도 같은 UX 로 동작.
5. **권한 거부**: head/coach 권한 admin 으로 group 수정/삭제 호출 시 401/403.
6. **cohort 분리**: 11기·12기 동명 묶음 둘 다 만들고 12기만 수정해도 11기 영향 없음.
7. **회원·조장 화면 무영향**: 그룹 수정 후 회원 today/overdue task 정상 표시.

---

## 운영 반영

⛔ 사용자가 DEV 검증 마치고 "운영 반영해줘" 라고 명시한 경우에만:

```bash
cd /root/boot-dev && git checkout main && git merge dev && git push origin main && git checkout dev
cd /root/boot-prod && git pull origin main
# DB 마이그 없음
```
