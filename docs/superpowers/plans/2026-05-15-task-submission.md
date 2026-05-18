# Task 결과물 제출 기능 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** boot 프로젝트 운영진용 `tasks` 묶음에 "결과물 제출 필수" 플래그 + 완료 시 텍스트 입력 모달을 추가하고, 운영자가 묶음 펼침 안에서 제출 텍스트를 인라인으로 검토할 수 있게 한다.

**Architecture:** `tasks` 테이블에 컬럼 3개(`requires_submission`, `submission_text`, `submitted_at`) 추가. 묶음 단위 forward-only 정책(같은 cohort/title/role 모든 row 같은 플래그). 체크 시 모달 → toggle_task 호출. 운영자는 묶음 펼침 row 아래 인라인 표시. 별도 테이블 없음.

**Tech Stack:** PHP 8 (PDO/MySQL), MariaDB 10, vanilla JS + 자체 App helper, integration test via curl + PROD invariants via SELECT.

**Spec:** `docs/superpowers/specs/2026-05-15-task-submission-design.md`

---

## File Structure

| 파일 | 신규/수정 | 책임 |
|---|---|---|
| `migrate_tasks_submission.php` | 신규 (루트) | 멱등 컬럼 ADD runner |
| `public_html/api/admin.php` | 수정 | task_create/task_group_get/task_group_update/task_group_rows/today_tasks/all_tasks/toggle_task 응답·입력 확장, 신규 task_submission_update |
| `public_html/js/admin.js` | 수정 | renderTaskCard chip+인라인, bindTaskEvents 모달, 묶음 펼침 인라인+edit, showTaskForm 체크박스, 신규 showSubmissionModal helper |
| `public_html/css/admin.css` | 수정 | `.task-requires-submission-chip`, `.task-submission-text`, `.task-submission-meta` |
| `tests/task_submission_api_test.php` | 신규 | toggle_task / task_submission_update / task_group_update 시나리오 |
| `tests/task_submission_invariants.php` | 신규 | INV-S1~S3 |
| `tests/task_group_api_test.php` | 수정 | requires_submission round-trip 1~2 case 추가 |

---

## Task 1: 마이그레이션 runner 작성

**Files:**
- Create: `migrate_tasks_submission.php`

- [ ] **Step 1: 작성**

```php
<?php
/**
 * boot.soritune.com - DB Migration: tasks 결과물 제출 컬럼 3개 추가.
 *
 * - requires_submission TINYINT(1) NOT NULL DEFAULT 0
 * - submission_text     TEXT NULL
 * - submitted_at        DATETIME NULL
 *
 * 멱등: information_schema 조회 후 컬럼 없을 때만 ADD.
 *
 * 실행:
 *   cd /root/boot-dev   && php migrate_tasks_submission.php   (DEV)
 *   cd /root/boot-prod  && php migrate_tasks_submission.php   (PROD, 사용자 명시 후)
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/public_html/config.php';
$db = getDB();

echo "=== boot.soritune.com DB Migration: tasks submission ===\n\n";

function colExists(\PDO $db, string $table, string $col): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $col]);
    return (bool)$stmt->fetchColumn();
}

$specs = [
    'requires_submission' => "ADD COLUMN requires_submission TINYINT(1) NOT NULL DEFAULT 0 AFTER completed",
    'submission_text'     => "ADD COLUMN submission_text TEXT NULL AFTER requires_submission",
    'submitted_at'        => "ADD COLUMN submitted_at DATETIME NULL AFTER submission_text",
];

foreach ($specs as $col => $clause) {
    if (colExists($db, 'tasks', $col)) {
        echo "  - tasks.$col 이미 존재 (skip)\n";
        continue;
    }
    $db->exec("ALTER TABLE tasks $clause");
    echo "  - tasks.$col 추가 완료\n";
}

echo "\n검증:\n";
$check = $db->query("
    SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks'
       AND COLUMN_NAME IN ('requires_submission','submission_text','submitted_at')
     ORDER BY ORDINAL_POSITION
")->fetchAll();
foreach ($check as $r) {
    printf("  %-22s %s NULL=%s DEFAULT=%s\n",
        $r['COLUMN_NAME'], $r['COLUMN_TYPE'], $r['IS_NULLABLE'], $r['COLUMN_DEFAULT'] ?? 'NULL');
}
echo "\n완료.\n";
```

- [ ] **Step 2: 실행 (DEV)**

Run: `cd /root/boot-dev && php migrate_tasks_submission.php`

Expected:
```
- tasks.requires_submission 추가 완료
- tasks.submission_text 추가 완료
- tasks.submitted_at 추가 완료
검증:
  requires_submission    tinyint(1) NULL=NO DEFAULT=0
  submission_text        text NULL=YES DEFAULT=NULL
  submitted_at           datetime NULL=YES DEFAULT=NULL
완료.
```

- [ ] **Step 3: 멱등성 검증 (재실행)**

Run: `cd /root/boot-dev && php migrate_tasks_submission.php`

Expected: 모든 컬럼이 "이미 존재 (skip)" 으로 출력.

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev
git add migrate_tasks_submission.php
git commit -m "$(cat <<'EOF'
feat(migrate): tasks 결과물 제출 컬럼 3개 추가 runner

requires_submission / submission_text / submitted_at.
information_schema 가드로 멱등 보장.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: SELECT 쿼리에 신규 컬럼 노출

`tasks.*` 또는 명시 컬럼 SELECT 가 today_tasks/all_tasks/task_group_rows 에 있다. `tasks.*` 는 컬럼 추가만으로 자동 노출되지만 task_group_rows 는 명시 컬럼이라 추가 필요.

**Files:**
- Modify: `public_html/api/admin.php:1348-1357` (task_group_rows SELECT)

- [ ] **Step 1: task_group_rows SELECT 확장**

Find (admin.php:1347-1363):

```php
    $sql = "
        SELECT t.id,
               t.start_date,
               t.end_date,
               t.completed,
               COALESCE(a.name, bm.real_name) AS assignee_name,
               CASE
                 WHEN t.assignee_admin_id  IS NOT NULL THEN 'admin'
                 WHEN t.assignee_member_id IS NOT NULL THEN 'member'
                 ELSE 'unassigned'
               END AS assignee_kind
          FROM tasks t
          LEFT JOIN admins a            ON t.assignee_admin_id  = a.id
          LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
          $where
         ORDER BY t.start_date ASC, assignee_name ASC
    ";
```

Replace with:

```php
    $sql = "
        SELECT t.id,
               t.start_date,
               t.end_date,
               t.completed,
               t.requires_submission,
               t.submission_text,
               t.submitted_at,
               COALESCE(a.name, bm.real_name) AS assignee_name,
               CASE
                 WHEN t.assignee_admin_id  IS NOT NULL THEN 'admin'
                 WHEN t.assignee_member_id IS NOT NULL THEN 'member'
                 ELSE 'unassigned'
               END AS assignee_kind
          FROM tasks t
          LEFT JOIN admins a            ON t.assignee_admin_id  = a.id
          LEFT JOIN bootcamp_members bm ON t.assignee_member_id = bm.id
          $where
         ORDER BY t.start_date ASC, assignee_name ASC
    ";
```

- [ ] **Step 2: today_tasks / all_tasks 확인**

Run: `grep -n "SELECT t\\.\\*" public_html/api/admin.php`

Expected: today_tasks (앞쪽) 와 all_tasks 가 `SELECT t.*` 또는 `SELECT t.*, ...` 패턴 사용. 새 컬럼이 자동 포함됨. 변경 불필요.

(Note: 만약 어떤 SELECT 가 명시 컬럼 리스트면 추가하라.)

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/admin.php
git commit -m "feat(api): task_group_rows 응답에 submission 컬럼 3개 추가

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: toggle_task 에 submission_text 입력·검증·저장 추가

**Files:**
- Modify: `public_html/api/admin.php:359-372` (toggle_task case)

- [ ] **Step 1: toggle_task 교체**

Find (admin.php:359-372):

```php
case 'toggle_task':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin();
    $input = getJsonInput();
    $taskId = (int)($input['task_id'] ?? 0);
    $completed = !empty($input['completed']) ? 1 : 0;

    if (!$taskId) jsonError('task_id가 필요합니다.');

    $db = getDB();
    $stmt = $db->prepare('UPDATE tasks SET completed = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$completed, $taskId]);
    jsonSuccess([], $completed ? '완료 처리되었습니다.' : '미완료로 변경되었습니다.');
    break;
```

Replace with:

```php
case 'toggle_task':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin();
    $input = getJsonInput();
    $taskId = (int)($input['task_id'] ?? 0);
    $completed = !empty($input['completed']) ? 1 : 0;
    $submissionText = isset($input['submission_text']) ? trim((string)$input['submission_text']) : null;

    if (!$taskId) jsonError('task_id가 필요합니다.');

    $db = getDB();

    // 현재 row 의 requires_submission 조회 (검증용)
    $check = $db->prepare('SELECT requires_submission FROM tasks WHERE id = ?');
    $check->execute([$taskId]);
    $row = $check->fetch();
    if (!$row) jsonError('해당 task 를 찾을 수 없습니다.', 404);

    if ($completed === 1 && (int)$row['requires_submission'] === 1) {
        if ($submissionText === null || $submissionText === '') {
            jsonError('결과물을 입력해주세요.', 400);
        }
    }

    if ($completed === 1 && $submissionText !== null && $submissionText !== '') {
        // 완료 + 텍스트 있음 → 텍스트와 시각 함께 갱신
        $stmt = $db->prepare('
            UPDATE tasks
               SET completed = 1, submission_text = ?, submitted_at = NOW(), updated_at = NOW()
             WHERE id = ?
        ');
        $stmt->execute([$submissionText, $taskId]);
    } else {
        // 미완료 또는 (완료 + 텍스트 없음 + requires_submission=0) → completed 만 갱신, 텍스트 보존
        $stmt = $db->prepare('UPDATE tasks SET completed = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$completed, $taskId]);
    }

    jsonSuccess([], $completed ? '완료 처리되었습니다.' : '미완료로 변경되었습니다.');
    break;
```

- [ ] **Step 2: 수동 검증**

Run (DEV cohort 에 임시 task 1개 — `requires_submission=1` 강제 후 toggle):

```bash
cd /root/boot-dev && mysql -u $(grep DB_USER .db_credentials | cut -d= -f2) -p$(grep DB_PASS .db_credentials | cut -d= -f2) $(grep DB_NAME .db_credentials | cut -d= -f2) -e "
INSERT INTO tasks (title, role, completed, requires_submission, start_date, end_date, content_markdown, cohort)
VALUES ('__sub_smoke', 'operation', 0, 1, '2099-03-01', '2099-03-01', NULL, (SELECT cohort FROM cohorts ORDER BY start_date DESC LIMIT 1));
SELECT id, title, completed, requires_submission FROM tasks WHERE title='__sub_smoke';
"
```

Expected: row 1개, requires_submission=1.

- [ ] **Step 3: API 호출 검증 (텍스트 없이)**

`ADMIN_COOKIE` 환경변수 (운영권한 admin 의 PHPSESSID_ADMIN) 필요.

```bash
TID=$(mysql -N -u $(grep DB_USER /root/boot-dev/.db_credentials | cut -d= -f2) -p$(grep DB_PASS /root/boot-dev/.db_credentials | cut -d= -f2) $(grep DB_NAME /root/boot-dev/.db_credentials | cut -d= -f2) -e "SELECT id FROM tasks WHERE title='__sub_smoke' LIMIT 1")
curl -s -X POST "https://dev-boot.soritune.com/api/admin.php?action=toggle_task" \
  -H "Cookie: $ADMIN_COOKIE" -H "Content-Type: application/json" \
  -d "{\"task_id\":$TID,\"completed\":true}"
```

Expected: `{"success":false,"message":"결과물을 입력해주세요."}` (HTTP 400)

- [ ] **Step 4: API 호출 검증 (텍스트 있음)**

```bash
curl -s -X POST "https://dev-boot.soritune.com/api/admin.php?action=toggle_task" \
  -H "Cookie: $ADMIN_COOKIE" -H "Content-Type: application/json" \
  -d "{\"task_id\":$TID,\"completed\":true,\"submission_text\":\"테스트 결과물 1줄\"}"
```

Expected: `{"success":true,"message":"완료 처리되었습니다."}` + DB 에 submission_text/submitted_at 채워짐.

```bash
mysql -u ... -e "SELECT completed, submission_text, submitted_at FROM tasks WHERE id=$TID"
```

Expected: completed=1, submission_text='테스트 결과물 1줄', submitted_at=now.

- [ ] **Step 5: API 호출 검증 (uncheck → 텍스트 보존)**

```bash
curl -s -X POST "https://dev-boot.soritune.com/api/admin.php?action=toggle_task" \
  -H "Cookie: $ADMIN_COOKIE" -H "Content-Type: application/json" \
  -d "{\"task_id\":$TID,\"completed\":false}"
mysql -u ... -e "SELECT completed, submission_text, submitted_at FROM tasks WHERE id=$TID"
```

Expected: completed=0, submission_text='테스트 결과물 1줄' (보존), submitted_at 도 보존.

- [ ] **Step 6: 정리 + 커밋**

```bash
cd /root/boot-dev && mysql -u ... -e "DELETE FROM tasks WHERE title='__sub_smoke'"
git add public_html/api/admin.php
git commit -m "feat(api): toggle_task 에 submission_text 입력·검증·저장

requires_submission=1 + completed=1 인데 텍스트 비어있으면 400.
완료 시 submission_text/submitted_at 갱신, 미완료 toggle 시 텍스트 보존.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: 신규 task_submission_update endpoint

**Files:**
- Modify: `public_html/api/admin.php` (task_submission_update case 추가 — task_group_rows case 직후가 자연스러움, admin.php:1377 부근)

- [ ] **Step 1: 신규 case 추가**

Insert after `case 'task_group_rows':` 의 `break;` (admin.php:1377 직후):

```php
case 'task_submission_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin();
    $input = getJsonInput();
    $taskId = (int)($input['task_id'] ?? 0);
    $submissionText = isset($input['submission_text']) ? trim((string)$input['submission_text']) : '';

    if (!$taskId) jsonError('task_id가 필요합니다.');
    if ($submissionText === '') jsonError('결과물을 입력해주세요.', 400);

    $db = getDB();
    $check = $db->prepare('SELECT requires_submission FROM tasks WHERE id = ?');
    $check->execute([$taskId]);
    $row = $check->fetch();
    if (!$row) jsonError('해당 task 를 찾을 수 없습니다.', 404);
    if ((int)$row['requires_submission'] !== 1) {
        jsonError('이 task 는 결과물 제출 대상이 아닙니다.', 400);
    }

    $stmt = $db->prepare('
        UPDATE tasks
           SET submission_text = ?, submitted_at = NOW(), updated_at = NOW()
         WHERE id = ?
    ');
    $stmt->execute([$submissionText, $taskId]);
    jsonSuccess([], '결과물이 수정되었습니다.');
    break;
```

- [ ] **Step 2: 수동 검증**

(Task 3 와 동일한 임시 row 만들고)

```bash
curl -s -X POST "https://dev-boot.soritune.com/api/admin.php?action=task_submission_update" \
  -H "Cookie: $ADMIN_COOKIE" -H "Content-Type: application/json" \
  -d "{\"task_id\":$TID,\"submission_text\":\"수정된 결과물\"}"
```

Expected: `{"success":true,...}`. DB 의 submission_text 가 갱신되고 submitted_at 도 새 시각.

- [ ] **Step 3: requires_submission=0 row 에 호출 → 400 검증**

```bash
mysql -u ... -e "UPDATE tasks SET requires_submission=0 WHERE id=$TID"
curl -s -X POST "..." -d "{\"task_id\":$TID,\"submission_text\":\"x\"}"
```

Expected: `{"success":false,"message":"이 task 는 결과물 제출 대상이 아닙니다."}`

- [ ] **Step 4: 정리 + 커밋**

```bash
mysql -u ... -e "DELETE FROM tasks WHERE title='__sub_smoke'"
git add public_html/api/admin.php
git commit -m "feat(api): task_submission_update — 텍스트만 단독 편집

requires_submission=0 row 에선 400 가드.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: task_create 에 requires_submission 입력 추가

**Files:**
- Modify: `public_html/api/admin.php:1381~1510` (task_create case)

- [ ] **Step 1: 입력 파싱 + INSERT 컬럼 추가**

Find (admin.php:1381-1389 부근):

```php
case 'task_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $title    = trim($input['title'] ?? '');
    $roles    = $input['roles'] ?? [];
    $content  = trim($input['content_markdown'] ?? '') ?: null;
    $cohort   = trim($input['cohort'] ?? '') ?: getEffectiveCohort($admin);
    $dateMode = $input['date_mode'] ?? 'direct';
```

Add line after `$dateMode = ...`:

```php
    $requiresSubmission = !empty($input['requires_submission']) ? 1 : 0;
```

Find (admin.php:1462-1463):

```php
    $insertAdminStmt = $db->prepare('INSERT INTO tasks (title, role, assignee_admin_id, start_date, end_date, content_markdown, cohort) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insertMemberStmt = $db->prepare('INSERT INTO tasks (title, role, assignee_member_id, start_date, end_date, content_markdown, cohort) VALUES (?, ?, ?, ?, ?, ?, ?)');
```

Replace with:

```php
    $insertAdminStmt = $db->prepare('INSERT INTO tasks (title, role, assignee_admin_id, start_date, end_date, content_markdown, cohort, requires_submission) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $insertMemberStmt = $db->prepare('INSERT INTO tasks (title, role, assignee_member_id, start_date, end_date, content_markdown, cohort, requires_submission) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
```

Find (admin.php:1497-1505 부근):

```php
            if (empty($assignees)) {
                $insertAdminStmt->execute([$title, $role, null, $sd, $ed, $content, $cohort]);
                $createdCount++;
            } else {
                $ins = $isMemberRole ? $insertMemberStmt : $insertAdminStmt;
                foreach ($assignees as $a) {
                    $ins->execute([$title, $role, $a['id'], $sd, $ed, $content, $cohort]);
                    $createdCount++;
                }
            }
```

Replace with:

```php
            if (empty($assignees)) {
                $insertAdminStmt->execute([$title, $role, null, $sd, $ed, $content, $cohort, $requiresSubmission]);
                $createdCount++;
            } else {
                $ins = $isMemberRole ? $insertMemberStmt : $insertAdminStmt;
                foreach ($assignees as $a) {
                    $ins->execute([$title, $role, $a['id'], $sd, $ed, $content, $cohort, $requiresSubmission]);
                    $createdCount++;
                }
            }
```

- [ ] **Step 2: 수동 검증**

```bash
curl -s -X POST "https://dev-boot.soritune.com/api/admin.php?action=task_create" \
  -H "Cookie: $ADMIN_COOKIE" -H "Content-Type: application/json" \
  -d '{"title":"__sub_create","roles":["operation"],"date_mode":"direct","start_date":"2099-04-01","end_date":"2099-04-01","content_markdown":"x","requires_submission":1}'

mysql -u ... -e "SELECT id, requires_submission FROM tasks WHERE title='__sub_create'"
```

Expected: row(s) created, requires_submission=1.

- [ ] **Step 3: 정리 + 커밋**

```bash
mysql -u ... -e "DELETE FROM tasks WHERE title='__sub_create'"
git add public_html/api/admin.php
git commit -m "feat(api): task_create 에 requires_submission 입력 추가

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: task_group_get 응답에 requires_submission 포함 + task_group_update 입력 추가

**Files:**
- Modify: `public_html/api/admin.php:1187-1211` (task_group_get)
- Modify: `public_html/api/admin.php:1278-1298` (task_group_update)

- [ ] **Step 1: task_group_get SELECT 확장**

Find (admin.php:1197-1210):

```php
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
```

Replace with:

```php
    $stmt = $db->prepare("
        SELECT title, content_markdown, requires_submission
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
        'requires_submission' => (int)$row['requires_submission'],
    ]);
```

- [ ] **Step 2: task_group_update 에 requires_submission 입력·UPDATE 확장**

Find (admin.php:1278-1297):

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

Replace with:

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
    $newRequiresSubmission = !empty($input['requires_submission']) ? 1 : 0;
    if (!$cohort || !$title || !$role) jsonError('cohort/title/role 필수.');
    if ($newTitle === '') jsonError('새 제목을 입력해주세요.');

    $db = getDB();
    $stmt = $db->prepare("
        UPDATE tasks
           SET title = ?, content_markdown = ?, requires_submission = ?
         WHERE cohort = ? AND title = ? AND role = ?
    ");
    $stmt->execute([$newTitle, $newContent ?: null, $newRequiresSubmission, $cohort, $title, $role]);
    jsonSuccess(['affected_count' => $stmt->rowCount()], 'Task 묶음이 수정되었습니다.');
    break;
```

(주: 기존 `completed`, `submission_text`, `submitted_at` 은 SET 절에 없으므로 자동 보존.)

- [ ] **Step 3: 수동 검증 (round-trip)**

DEV 에서 임시 묶음 만들고 group_get → 0 → group_update(=1) → group_get → 1 → group_update(=0) → group_get → 0 round-trip 확인.

```bash
mysql -u ... -e "
INSERT INTO tasks (title, role, completed, start_date, end_date, content_markdown, cohort)
VALUES ('__sub_grp', 'operation', 0, '2099-05-01', '2099-05-01', NULL, (SELECT cohort FROM cohorts ORDER BY start_date DESC LIMIT 1)),
       ('__sub_grp', 'operation', 0, '2099-05-02', '2099-05-02', NULL, (SELECT cohort FROM cohorts ORDER BY start_date DESC LIMIT 1));
"
COHORT=$(mysql -N -u ... -e "SELECT cohort FROM cohorts ORDER BY start_date DESC LIMIT 1")
curl -s -X POST "https://dev-boot.soritune.com/api/admin.php?action=task_group_get" \
  -H "Cookie: $ADMIN_COOKIE" -H "Content-Type: application/json" \
  -d "{\"cohort\":\"$COHORT\",\"title\":\"__sub_grp\",\"role\":\"operation\"}"
# requires_submission=0 확인

curl -s -X POST "https://dev-boot.soritune.com/api/admin.php?action=task_group_update" \
  -H "Cookie: $ADMIN_COOKIE" -H "Content-Type: application/json" \
  -d "{\"cohort\":\"$COHORT\",\"title\":\"__sub_grp\",\"role\":\"operation\",\"new_title\":\"__sub_grp\",\"new_content_markdown\":\"\",\"requires_submission\":1}"
# affected_count=2 확인

mysql -u ... -e "SELECT requires_submission, COUNT(*) FROM tasks WHERE title='__sub_grp' GROUP BY requires_submission"
# requires_submission=1 / count=2 확인
```

- [ ] **Step 4: 정리 + 커밋**

```bash
mysql -u ... -e "DELETE FROM tasks WHERE title='__sub_grp'"
git add public_html/api/admin.php
git commit -m "feat(api): task_group_get/update 에 requires_submission 추가

묶음 단위 일괄 갱신. completed/submission_text/submitted_at 보존.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: 통합 테스트 (`tests/task_submission_api_test.php`) 작성

**Files:**
- Create: `tests/task_submission_api_test.php`

- [ ] **Step 1: 작성**

```php
<?php
/**
 * Task 결과물 제출 API 통합 테스트.
 *
 * 사용:
 *   ADMIN_COOKIE='PHPSESSID_ADMIN=...' DEV_BASE='https://dev-boot.soritune.com' \
 *     php tests/task_submission_api_test.php
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
$api  = $base . '/api/admin.php';
$h    = ["Cookie: $cookie", "Content-Type: application/json"];

// ── Fixture: requires_submission=1 row 1개, requires_submission=0 row 1개
$cohortRow = $db->query("SELECT cohort FROM cohorts ORDER BY start_date DESC LIMIT 1")->fetch();
if (!$cohortRow) { echo "SKIP — cohorts 비어있음\n"; exit(0); }
$cohort = $cohortRow['cohort'];

$db->prepare("DELETE FROM tasks WHERE title LIKE '__sub_test%'")->execute();
$db->exec("
    INSERT INTO tasks (title, role, completed, requires_submission, start_date, end_date, content_markdown, cohort)
    VALUES
      ('__sub_test_req', 'operation', 0, 1, '2099-06-01', '2099-06-01', NULL, " . $db->quote($cohort) . "),
      ('__sub_test_opt', 'operation', 0, 0, '2099-06-02', '2099-06-02', NULL, " . $db->quote($cohort) . ")
");
$reqId = (int)$db->query("SELECT id FROM tasks WHERE title='__sub_test_req'")->fetchColumn();
$optId = (int)$db->query("SELECT id FROM tasks WHERE title='__sub_test_opt'")->fetchColumn();

try {
    // ── toggle_task: requires_submission=1 + 텍스트 없음 → 400 ──
    $r = req('POST', "$api?action=toggle_task", $h, ['task_id' => $reqId, 'completed' => true]);
    t('toggle_task 텍스트 없이 → 400', $r['code'] === 400, "code={$r['code']}");

    // ── toggle_task: 빈 문자열 → 400 ──
    $r = req('POST', "$api?action=toggle_task", $h, ['task_id' => $reqId, 'completed' => true, 'submission_text' => '   ']);
    t('toggle_task 빈 문자열 → 400', $r['code'] === 400, "code={$r['code']}");

    // ── toggle_task: 정상 텍스트 → 200 ──
    $r = req('POST', "$api?action=toggle_task", $h, ['task_id' => $reqId, 'completed' => true, 'submission_text' => '결과물 v1']);
    t('toggle_task 정상 → 200', $r['code'] === 200);
    $row = $db->query("SELECT completed, submission_text, submitted_at FROM tasks WHERE id=$reqId")->fetch();
    t('  완료 + 텍스트 저장', (int)$row['completed'] === 1 && $row['submission_text'] === '결과물 v1');
    t('  submitted_at set', $row['submitted_at'] !== null);

    // ── toggle_task: uncheck → 텍스트 보존 ──
    $r = req('POST', "$api?action=toggle_task", $h, ['task_id' => $reqId, 'completed' => false]);
    t('toggle_task uncheck → 200', $r['code'] === 200);
    $row = $db->query("SELECT completed, submission_text FROM tasks WHERE id=$reqId")->fetch();
    t('  미완료 + 텍스트 보존', (int)$row['completed'] === 0 && $row['submission_text'] === '결과물 v1');

    // ── toggle_task: 다시 check + 새 텍스트 → 덮어쓰기 ──
    $r = req('POST', "$api?action=toggle_task", $h, ['task_id' => $reqId, 'completed' => true, 'submission_text' => '결과물 v2']);
    t('toggle_task 재완료 → 200', $r['code'] === 200);
    $row = $db->query("SELECT submission_text FROM tasks WHERE id=$reqId")->fetch();
    t('  텍스트 덮어쓰기', $row['submission_text'] === '결과물 v2');

    // ── task_submission_update: requires_submission=0 row → 400 ──
    $r = req('POST', "$api?action=task_submission_update", $h, ['task_id' => $optId, 'submission_text' => 'x']);
    t('task_submission_update on flag=0 row → 400', $r['code'] === 400);

    // ── task_submission_update: 정상 → 텍스트만 갱신, completed 미변경 ──
    $r = req('POST', "$api?action=task_submission_update", $h, ['task_id' => $reqId, 'submission_text' => '결과물 v3']);
    t('task_submission_update 정상 → 200', $r['code'] === 200);
    $row = $db->query("SELECT completed, submission_text FROM tasks WHERE id=$reqId")->fetch();
    t('  텍스트 갱신', $row['submission_text'] === '결과물 v3');
    t('  completed 미변경', (int)$row['completed'] === 1);

    // ── task_group_update: 0→1 → 묶음 모든 row 갱신, 기존 completed/text 보존 ──
    // 추가 fixture: '__sub_test_grp' 묶음 3 row (1개는 completed+text)
    $db->prepare("DELETE FROM tasks WHERE title = '__sub_test_grp'")->execute();
    $db->exec("
        INSERT INTO tasks (title, role, completed, requires_submission, submission_text, submitted_at, start_date, end_date, content_markdown, cohort)
        VALUES
          ('__sub_test_grp', 'operation', 1, 0, '기존 텍스트', NOW(), '2099-07-01', '2099-07-01', NULL, " . $db->quote($cohort) . "),
          ('__sub_test_grp', 'operation', 0, 0, NULL, NULL, '2099-07-02', '2099-07-02', NULL, " . $db->quote($cohort) . "),
          ('__sub_test_grp', 'operation', 0, 0, NULL, NULL, '2099-07-03', '2099-07-03', NULL, " . $db->quote($cohort) . ")
    ");
    $r = req('POST', "$api?action=task_group_update", $h, [
        'cohort' => $cohort, 'title' => '__sub_test_grp', 'role' => 'operation',
        'new_title' => '__sub_test_grp', 'new_content_markdown' => '', 'requires_submission' => 1,
    ]);
    t('task_group_update 0→1 → 200', $r['code'] === 200);
    $rows = $db->query("SELECT requires_submission, completed, submission_text FROM tasks WHERE title='__sub_test_grp' ORDER BY start_date")->fetchAll();
    t('  3 row 모두 requires_submission=1', count(array_filter($rows, fn($r) => (int)$r['requires_submission'] === 1)) === 3);
    t('  기존 completed=1 보존', (int)$rows[0]['completed'] === 1);
    t('  기존 submission_text 보존', $rows[0]['submission_text'] === '기존 텍스트');

    // ── task_group_update: 1→0 → 묶음 모든 row 갱신, 기존 submission_text 보존 ──
    $r = req('POST', "$api?action=task_group_update", $h, [
        'cohort' => $cohort, 'title' => '__sub_test_grp', 'role' => 'operation',
        'new_title' => '__sub_test_grp', 'new_content_markdown' => '', 'requires_submission' => 0,
    ]);
    t('task_group_update 1→0 → 200', $r['code'] === 200);
    $rows = $db->query("SELECT requires_submission, submission_text FROM tasks WHERE title='__sub_test_grp' ORDER BY start_date")->fetchAll();
    t('  3 row 모두 requires_submission=0', count(array_filter($rows, fn($r) => (int)$r['requires_submission'] === 0)) === 3);
    t('  기존 submission_text 보존', $rows[0]['submission_text'] === '기존 텍스트');

    // ── task_group_get: requires_submission 응답 포함 ──
    $r = req('POST', "$api?action=task_group_get", $h, [
        'cohort' => $cohort, 'title' => '__sub_test_grp', 'role' => 'operation',
    ]);
    t('task_group_get → 200', $r['code'] === 200);
    t('  requires_submission 필드 포함', isset($r['body']['requires_submission']));
    t('  값=0 (방금 1→0 했으므로)', (int)($r['body']['requires_submission'] ?? -1) === 0);

    // ── task_group_rows: row 응답에 submission 컬럼 포함 ──
    $r = req('GET', "$api?action=task_group_rows&cohort=" . urlencode($cohort) . "&title=__sub_test_grp&role=operation&only_incomplete=0&only_until_today=0", $h);
    t('task_group_rows → 200', $r['code'] === 200);
    $row0 = ($r['body']['rows'] ?? [])[0] ?? null;
    t('  row 에 requires_submission 포함', $row0 && array_key_exists('requires_submission', $row0));
    t('  row 에 submission_text 포함',     $row0 && array_key_exists('submission_text', $row0));
    t('  row 에 submitted_at 포함',        $row0 && array_key_exists('submitted_at', $row0));

} finally {
    $db->prepare("DELETE FROM tasks WHERE title LIKE '__sub_test%'")->execute();
}

echo "\n=== {$pass} PASS / {$fail} FAIL ===\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 실행**

```bash
ADMIN_COOKIE='PHPSESSID_ADMIN=...' php /root/boot-dev/tests/task_submission_api_test.php
```

Expected: 모든 테스트 PASS, exit 0.

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add tests/task_submission_api_test.php
git commit -m "test(api): task 결과물 제출 통합 테스트

toggle_task / task_submission_update / task_group_update 시나리오.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: 인보리언트 테스트 (`tests/task_submission_invariants.php`)

**Files:**
- Create: `tests/task_submission_invariants.php`

- [ ] **Step 1: 작성**

```php
<?php
/**
 * Task 결과물 제출 인보리언트.
 *
 * 사용:
 *   php tests/task_submission_invariants.php  (DEV/PROD DB 직접 조회)
 *
 * INV-S1: completed=1 AND requires_submission=1 → submission_text NOT NULL AND TRIM != ''
 * INV-S2: 같은 (cohort, title, role) 묶음 안 모든 row 의 requires_submission 동일
 * INV-S3: submission_text NOT NULL → submitted_at NOT NULL
 *
 * ⚠️ SQL 동기화 주의:
 *   API 변경 시 admin.php 의 toggle_task / task_submission_update /
 *   task_group_update 와 INV 정의가 일관되게 유지돼야 함.
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

// ── INV-S1: completed=1 + requires_submission=1 인데 텍스트 없음 = 위반 ──
$bad = $db->query("
    SELECT id, title, cohort, role
      FROM tasks
     WHERE completed = 1 AND requires_submission = 1
       AND (submission_text IS NULL OR TRIM(submission_text) = '')
")->fetchAll();
t('INV-S1 completed+requires → 텍스트 있음', count($bad) === 0,
    'violations: ' . json_encode($bad, JSON_UNESCAPED_UNICODE));

// ── INV-S2: 묶음 안 requires_submission 값 일관성 ──
$mixed = $db->query("
    SELECT cohort, title, role,
           MIN(requires_submission) AS min_v,
           MAX(requires_submission) AS max_v,
           COUNT(*) AS row_cnt
      FROM tasks
     GROUP BY cohort, title, role
    HAVING MIN(requires_submission) <> MAX(requires_submission)
")->fetchAll();
t('INV-S2 묶음 안 requires_submission 일관', count($mixed) === 0,
    'mixed groups: ' . json_encode($mixed, JSON_UNESCAPED_UNICODE));

// ── INV-S3: submission_text 있으면 submitted_at 도 있음 ──
$noTime = $db->query("
    SELECT id, title, cohort
      FROM tasks
     WHERE submission_text IS NOT NULL AND submitted_at IS NULL
")->fetchAll();
t('INV-S3 text→timestamp 동행', count($noTime) === 0,
    'violations: ' . json_encode($noTime, JSON_UNESCAPED_UNICODE));

echo "\n=== {$pass} PASS / {$fail} FAIL ===\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 실행 (DEV)**

```bash
cd /root/boot-dev && php tests/task_submission_invariants.php
```

Expected: 마이그 직후라 모든 row `requires_submission=0` / `submission_text=NULL` / `submitted_at=NULL` → INV 3개 모두 PASS.

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add tests/task_submission_invariants.php
git commit -m "test(invariants): task 결과물 제출 INV-S1~S3

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: showTaskForm 모달에 "결과물 제출 필수" 체크박스 추가

**Files:**
- Modify: `public_html/js/admin.js:1608-1622` (showTaskForm body) + 1641-1654 (save handler)

- [ ] **Step 1: body 에 체크박스 추가**

Find (admin.js:1608-1622):

```javascript
        const body = `
            <div class="form-group">
                <label class="form-label">제목 *</label>
                <input type="text" class="form-input" id="tf-title" value="${App.esc(data.title || '')}">
            </div>
            ${roleSection}
            ${dateModeSection}
            ${directDateSection}
            ${weekDaySection}
            ${dailySection}
            <div class="form-group">
                <label class="form-label">내용</label>
                <textarea class="form-textarea" id="tf-content" rows="4" style="resize:vertical">${App.esc(data.content_markdown || '')}</textarea>
            </div>
        `;
```

Replace with:

```javascript
        const reqSubChecked = data.requires_submission === 1 || data.requires_submission === '1' ? 'checked' : '';
        const body = `
            <div class="form-group">
                <label class="form-label">제목 *</label>
                <input type="text" class="form-input" id="tf-title" value="${App.esc(data.title || '')}">
            </div>
            ${roleSection}
            ${dateModeSection}
            ${directDateSection}
            ${weekDaySection}
            ${dailySection}
            <div class="form-group">
                <label class="form-label">내용</label>
                <textarea class="form-textarea" id="tf-content" rows="4" style="resize:vertical">${App.esc(data.content_markdown || '')}</textarea>
            </div>
            <div class="form-group">
                <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                    <input type="checkbox" id="tf-requires-submission" ${reqSubChecked}>
                    <span>📝 결과물 제출 필수 (완료 체크 시 텍스트 입력 강제)</span>
                </label>
            </div>
        `;
```

- [ ] **Step 2: save handler 에 payload 추가**

Find (admin.js:1641-1655):

```javascript
        document.getElementById('tf-save').onclick = async () => {
            const payload = {
                title: document.getElementById('tf-title').value.trim(),
                content_markdown: document.getElementById('tf-content').value.trim(),
            };
```

Replace with:

```javascript
        document.getElementById('tf-save').onclick = async () => {
            const payload = {
                title: document.getElementById('tf-title').value.trim(),
                content_markdown: document.getElementById('tf-content').value.trim(),
                requires_submission: document.getElementById('tf-requires-submission').checked ? 1 : 0,
            };
```

- [ ] **Step 3: _editTaskGroup 이 prefill 값을 넘기도록 수정**

Find (admin.js:1711-1718):

```javascript
        showTaskForm({
            groupKey: { cohort, title, role },
            title: single.title,
            content_markdown: single.content_markdown,
            periodLabel,
            totalCount: parseInt(g.total_count) || 0,
            doneCount:  parseInt(g.done_count)  || 0,
        });
```

Replace with:

```javascript
        showTaskForm({
            groupKey: { cohort, title, role },
            title: single.title,
            content_markdown: single.content_markdown,
            requires_submission: parseInt(single.requires_submission) || 0,
            periodLabel,
            totalCount: parseInt(g.total_count) || 0,
            doneCount:  parseInt(g.done_count)  || 0,
        });
```

- [ ] **Step 4: cache buster 갱신**

`grep -nE 'admin\\.js\\?v=' public_html/operation/index.php public_html/coach/index.php public_html/leader/index.php` 등으로 admin.js 가 로드되는 모든 곳의 버전 쿼리스트링 갱신 (`?v=20260515` → `?v=20260515b` 같은 형태).

- [ ] **Step 5: 수동 검증**

브라우저에서 dev-boot.soritune.com /operation/#tasks 로그인 → [+ Task 추가] → "결과물 제출 필수" 체크박스 노출 확인 → 1개 만들고 → 묶음 [수정] 클릭 시 prefill 동작 확인 → 체크 토글하고 저장 → DB 에서 묶음 모든 row 의 requires_submission 변화 확인.

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/admin.js public_html/operation/index.php public_html/coach/index.php public_html/leader/index.php
git commit -m "feat(ui): Task 생성/수정 모달에 결과물 제출 필수 체크박스

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: 신규 showSubmissionModal helper + 대시보드 카드 체크박스 모달 연동

**Files:**
- Modify: `public_html/js/admin.js:893-913` (renderTaskCard) + 915-937 (bindTaskEvents)

- [ ] **Step 1: renderTaskCard 에 chip + completed 시 인라인 영역 추가**

Find (admin.js:893-913):

```javascript
    function renderTaskCard(task, isOverdue = false) {
        const completed = parseInt(task.completed);
        const hasContent = !!task.content_markdown;
        return `
            <div class="task-card ${completed ? 'completed' : ''} ${isOverdue && !completed ? 'overdue' : ''}" data-id="${task.id}">
                <div class="task-top">
                    <input type="checkbox" class="task-checkbox" ${completed ? 'checked' : ''} data-task-id="${task.id}">
                    <div class="task-info">
                        <div class="task-title">${App.esc(task.title)}</div>
                        <div class="task-meta">
                            <span>${task.start_date} ~ ${task.end_date}</span>
                            ${task.assignee_name ? `<span class="badge badge-primary">${App.esc(task.assignee_name)}</span>` : ''}
                            ${isOperation() ? `<span class="badge badge-primary">${App.esc(ROLE_LABELS[task.role] || task.role)}</span>` : ''}
                            ${hasContent ? `<button class="task-toggle-content" data-task-id="${task.id}">내용 보기</button>` : ''}
                        </div>
                        ${hasContent ? `<div class="task-content collapsed" id="task-content-${task.id}">${renderMarkdown(task.content_markdown)}</div>` : ''}
                    </div>
                </div>
            </div>
        `;
    }
```

Replace with:

```javascript
    function renderTaskCard(task, isOverdue = false) {
        const completed = parseInt(task.completed);
        const hasContent = !!task.content_markdown;
        const requiresSub = parseInt(task.requires_submission) === 1;
        const submissionText = task.submission_text || '';
        const submittedAt = task.submitted_at || '';
        const hasSubmission = completed && requiresSub && submissionText !== '';

        const requiresChip = requiresSub
            ? `<span class="badge task-requires-submission-chip">📝 결과물</span>`
            : '';
        const submissionInline = hasSubmission ? `
            <div class="task-submission-text" id="task-sub-${task.id}">
                <div class="task-submission-meta">
                    📝 ${App.esc(submittedAt)}
                    <button class="task-toggle-submission" data-task-id="${task.id}">전체 보기</button>
                    <button class="task-edit-submission" data-task-id="${task.id}">수정</button>
                </div>
                <div class="task-submission-body collapsed">${App.esc(submissionText).replace(/\n/g, '<br>')}</div>
            </div>
        ` : '';

        return `
            <div class="task-card ${completed ? 'completed' : ''} ${isOverdue && !completed ? 'overdue' : ''}" data-id="${task.id}" data-requires-submission="${requiresSub ? 1 : 0}">
                <div class="task-top">
                    <input type="checkbox" class="task-checkbox" ${completed ? 'checked' : ''} data-task-id="${task.id}">
                    <div class="task-info">
                        <div class="task-title">${App.esc(task.title)}</div>
                        <div class="task-meta">
                            <span>${task.start_date} ~ ${task.end_date}</span>
                            ${task.assignee_name ? `<span class="badge badge-primary">${App.esc(task.assignee_name)}</span>` : ''}
                            ${isOperation() ? `<span class="badge badge-primary">${App.esc(ROLE_LABELS[task.role] || task.role)}</span>` : ''}
                            ${requiresChip}
                            ${hasContent ? `<button class="task-toggle-content" data-task-id="${task.id}">내용 보기</button>` : ''}
                        </div>
                        ${hasContent ? `<div class="task-content collapsed" id="task-content-${task.id}">${renderMarkdown(task.content_markdown)}</div>` : ''}
                        ${submissionInline}
                    </div>
                </div>
            </div>
        `;
    }
```

- [ ] **Step 2: bindTaskEvents 의 체크박스 핸들러 + 신규 토글/수정 핸들러**

Find (admin.js:915-937):

```javascript
    function bindTaskEvents(container) {
        container.querySelectorAll('.task-checkbox').forEach(cb => {
            cb.onchange = async () => {
                const taskId = parseInt(cb.dataset.taskId);
                const r = await App.post('/api/admin.php?action=toggle_task', { task_id: taskId, completed: cb.checked });
                if (r.success) {
                    Toast.success(r.message);
                    loadTodayTasks();
                    loadOverdueTasks();
                }
            };
        });

        container.querySelectorAll('.task-toggle-content').forEach(btn => {
            btn.onclick = () => {
                const el = document.getElementById(`task-content-${btn.dataset.taskId}`);
                if (el) {
                    el.classList.toggle('collapsed');
                    btn.textContent = el.classList.contains('collapsed') ? '내용 보기' : '내용 접기';
                }
            };
        });
    }
```

Replace with:

```javascript
    function bindTaskEvents(container) {
        container.querySelectorAll('.task-checkbox').forEach(cb => {
            cb.onchange = async () => {
                const taskId = parseInt(cb.dataset.taskId);
                const card = cb.closest('.task-card');
                const requiresSub = card && card.dataset.requiresSubmission === '1';

                if (requiresSub && cb.checked) {
                    // 모달 → toggle_task with submission_text
                    const prevText = (card.querySelector('.task-submission-body')?.innerText || '').replace(/<br\\s*\\/?>/gi, '\n');
                    showSubmissionModal({
                        taskId,
                        prefill: prevText,
                        title: card.querySelector('.task-title')?.textContent || '',
                        onConfirm: async (text) => {
                            const r = await App.post('/api/admin.php?action=toggle_task', { task_id: taskId, completed: true, submission_text: text });
                            if (r.success) { Toast.success(r.message); loadTodayTasks(); loadOverdueTasks(); }
                            return r.success;
                        },
                        onCancel: () => { cb.checked = false; },
                    });
                    return;
                }

                // 기존 즉시 toggle (requires_submission=0 또는 uncheck)
                const r = await App.post('/api/admin.php?action=toggle_task', { task_id: taskId, completed: cb.checked });
                if (r.success) {
                    Toast.success(r.message);
                    loadTodayTasks();
                    loadOverdueTasks();
                }
            };
        });

        container.querySelectorAll('.task-toggle-content').forEach(btn => {
            btn.onclick = () => {
                const el = document.getElementById(`task-content-${btn.dataset.taskId}`);
                if (el) {
                    el.classList.toggle('collapsed');
                    btn.textContent = el.classList.contains('collapsed') ? '내용 보기' : '내용 접기';
                }
            };
        });

        container.querySelectorAll('.task-toggle-submission').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                const wrap = document.getElementById(`task-sub-${btn.dataset.taskId}`);
                const body = wrap?.querySelector('.task-submission-body');
                if (body) {
                    body.classList.toggle('collapsed');
                    btn.textContent = body.classList.contains('collapsed') ? '전체 보기' : '접기';
                }
            };
        });

        container.querySelectorAll('.task-edit-submission').forEach(btn => {
            btn.onclick = async (e) => {
                e.stopPropagation();
                const taskId = parseInt(btn.dataset.taskId);
                const wrap = document.getElementById(`task-sub-${taskId}`);
                const body = wrap?.querySelector('.task-submission-body');
                const prev = body ? body.innerText : '';
                showSubmissionModal({
                    taskId,
                    prefill: prev,
                    title: btn.closest('.task-card')?.querySelector('.task-title')?.textContent || '',
                    onConfirm: async (text) => {
                        const r = await App.post('/api/admin.php?action=task_submission_update', { task_id: taskId, submission_text: text });
                        if (r.success) { Toast.success(r.message); loadTodayTasks(); loadOverdueTasks(); }
                        return r.success;
                    },
                    onCancel: () => {},
                });
            };
        });
    }
```

- [ ] **Step 3: showSubmissionModal helper 추가**

Insert (somewhere logical — 예: bindTaskEvents 바로 위 또는 전역 helper 영역):

```javascript
    // ── 결과물 제출 입력 모달 ──
    function showSubmissionModal({ taskId, prefill, title, onConfirm, onCancel }) {
        const safeTitle = App.esc(title || '');
        const safeText = App.esc(prefill || '');
        const body = `
            <div class="form-group">
                <p class="text-muted" style="font-size:0.9rem;margin:0 0 8px">${safeTitle}</p>
                <label class="form-label">결과물 *</label>
                <textarea class="form-textarea" id="sub-text" rows="6" style="resize:vertical" placeholder="처리한 내용 / 결과 / 회고를 자유롭게 입력">${safeText}</textarea>
                <p class="text-muted" style="font-size:0.8rem;margin-top:4px">* 운영자 검토용으로 저장됩니다.</p>
            </div>
        `;
        const footer = `
            <button class="btn btn-secondary" id="sub-cancel">취소</button>
            <button class="btn btn-primary" id="sub-save">저장</button>
        `;
        App.openModal('결과물 제출', body, footer);

        const ta = document.getElementById('sub-text');
        const saveBtn = document.getElementById('sub-save');
        const cancelBtn = document.getElementById('sub-cancel');
        const update = () => { saveBtn.disabled = ta.value.trim() === ''; };
        ta.oninput = update; update();
        ta.focus();

        let confirmed = false;

        saveBtn.onclick = async () => {
            const text = ta.value.trim();
            if (text === '') { Toast.warning('결과물을 입력해주세요.'); return; }
            saveBtn.disabled = true;
            const ok = await onConfirm(text);
            saveBtn.disabled = false;
            if (ok) { confirmed = true; App.closeModal(); }
        };
        cancelBtn.onclick = () => { App.closeModal(); };

        // 모달 닫힘(취소/백드롭) 시 onCancel 호출 — App.openModal 의 close 이벤트 hook 이 없으면 setTimeout 폴링으로 감지 (보통 App.closeModal 호출이 명시적이므로 cancelBtn 만으로 충분)
        // 안전하게: confirmed=false 인 채 modal 사라지면 onCancel 호출
        const observer = new MutationObserver(() => {
            if (!document.querySelector('.modal-backdrop, .modal[style*="display"]')) {
                if (!confirmed) onCancel();
                observer.disconnect();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
```

(주: `App.openModal` 의 정확한 close hook 이 다르면 cancelBtn 만으로 onCancel 처리하도록 단순화. MutationObserver 는 백드롭 클릭 케이스 가드.)

- [ ] **Step 4: 수동 검증**

DEV 에서 임시 묶음 1개 (`requires_submission=1`) 생성 → 본인 assignee 로 dashboard 진입 → 카드에 📝 chip 보임 → 체크 → 모달 → 텍스트 없으면 [저장] disabled → 입력 후 저장 → 카드 hidden 또는 reload 후 인라인에 텍스트 + 시각 + [전체 보기] [수정] 보임 → [수정] 클릭 → 모달 prefill → 새 텍스트 → 저장 → 갱신 확인 → 체크 해제 (모달 안 뜨고 즉시 처리, DB 텍스트 보존)

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/admin.js
git commit -m "feat(ui): 대시보드 task 카드 결과물 제출 모달 + 인라인 표시

- requires_submission=1 카드에 📝 chip
- 체크 시 textarea 모달 (off→on 만, uncheck 는 즉시)
- 완료 카드에 인라인 결과물 + [전체 보기] [수정]
- 신규 showSubmissionModal helper

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 11: 묶음 펼침 안 row 에 인라인 결과물 + 운영자 [수정] 버튼

**Files:**
- Modify: `public_html/js/admin.js:1818-1832` (rowLine in _renderGroupExpand) + 1862-1867 (button binding)

- [ ] **Step 1: rowLine 확장**

Find (admin.js:1818-1832):

```javascript
        function rowLine(row) {
            const d = new Date(row.start_date + 'T00:00:00');
            const dateLabel = `${d.getMonth()+1}/${d.getDate()}(${WD[d.getDay()]})`;
            const assignee  = row.assignee_kind === 'unassigned'
                ? '<span class="text-muted">미배정</span>'
                : App.esc(row.assignee_name || '?');
            const completed = parseInt(row.completed) === 1;
            const btnLabel  = completed ? '☑ 완료'      : '☐ 완료하기';
            const btnClass  = completed ? 'btn btn-success btn-sm' : 'btn btn-secondary btn-sm';
            return `<div class="group-row-line" style="display:grid;grid-template-columns:90px 1fr auto;gap:12px;align-items:center;padding:6px 12px;border-bottom:1px solid var(--gray-100,#eee)">
                <span style="font-family:monospace">${dateLabel}</span>
                <span>${assignee}</span>
                <button class="${btnClass}" data-task-id="${row.id}" data-completed="${completed?1:0}">${btnLabel}</button>
            </div>`;
        }
```

Replace with:

```javascript
        function rowLine(row) {
            const d = new Date(row.start_date + 'T00:00:00');
            const dateLabel = `${d.getMonth()+1}/${d.getDate()}(${WD[d.getDay()]})`;
            const assignee  = row.assignee_kind === 'unassigned'
                ? '<span class="text-muted">미배정</span>'
                : App.esc(row.assignee_name || '?');
            const completed = parseInt(row.completed) === 1;
            const requiresSub = parseInt(row.requires_submission) === 1;
            const subText = row.submission_text || '';
            const subAt = row.submitted_at || '';
            const btnLabel  = completed ? '☑ 완료'      : '☐ 완료하기';
            const btnClass  = completed ? 'btn btn-success btn-sm' : 'btn btn-secondary btn-sm';

            const subInline = (requiresSub && subText !== '') ? `
                <div class="group-row-submission" style="grid-column: 1 / -1; padding:4px 12px 8px 102px; font-size:0.88rem; color:var(--gray-700,#444)">
                    <div class="task-submission-meta">
                        📝 <span class="text-muted">${App.esc(subAt)}</span>
                        <button class="link-btn group-row-toggle-sub" data-task-id="${row.id}">전체 보기</button>
                        <button class="link-btn group-row-edit-sub" data-task-id="${row.id}">수정</button>
                    </div>
                    <div class="task-submission-body collapsed" id="grp-sub-${row.id}">${App.esc(subText).replace(/\n/g, '<br>')}</div>
                </div>
            ` : '';

            return `<div class="group-row-line" style="display:grid;grid-template-columns:90px 1fr auto;gap:12px;align-items:center;padding:6px 12px;border-bottom:1px solid var(--gray-100,#eee)" data-row-id="${row.id}" data-requires-submission="${requiresSub?1:0}">
                <span style="font-family:monospace">${dateLabel}</span>
                <span>${assignee}</span>
                <button class="${btnClass}" data-task-id="${row.id}" data-completed="${completed?1:0}">${btnLabel}</button>
                ${subInline}
            </div>`;
        }
```

- [ ] **Step 2: 신규 버튼 바인딩 추가**

Find (admin.js:1862-1867):

```javascript
        // row 토글 버튼 (Task 4 의 _toggleRowComplete 호출)
        body.querySelectorAll('button[data-task-id]').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                _toggleRowComplete(parseInt(btn.dataset.taskId, 10), btn);
            };
        });
```

Replace with:

```javascript
        // row 토글 버튼
        body.querySelectorAll('button.btn[data-task-id]').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                _toggleRowComplete(parseInt(btn.dataset.taskId, 10), btn);
            };
        });

        // 묶음 펼침 row 의 결과물 [전체 보기] 토글
        body.querySelectorAll('.group-row-toggle-sub').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                const el = document.getElementById(`grp-sub-${btn.dataset.taskId}`);
                if (el) {
                    el.classList.toggle('collapsed');
                    btn.textContent = el.classList.contains('collapsed') ? '전체 보기' : '접기';
                }
            };
        });

        // 묶음 펼침 row 의 결과물 [수정]
        body.querySelectorAll('.group-row-edit-sub').forEach(btn => {
            btn.onclick = (e) => {
                e.stopPropagation();
                const taskId = parseInt(btn.dataset.taskId, 10);
                const bodyEl = document.getElementById(`grp-sub-${taskId}`);
                const prev = bodyEl ? bodyEl.innerText : '';
                showSubmissionModal({
                    taskId,
                    prefill: prev,
                    title: '',
                    onConfirm: async (text) => {
                        const r = await App.post('/api/admin.php?action=task_submission_update', { task_id: taskId, submission_text: text });
                        if (r.success) { Toast.success(r.message); _renderGroupExpand(body); }
                        return r.success;
                    },
                    onCancel: () => {},
                });
            };
        });
```

- [ ] **Step 3: _toggleRowComplete 에 requires_submission 분기 추가**

Find (admin.js:1870-1879):

```javascript
    async function _toggleRowComplete(taskId, btn) {
        const wasCompleted = parseInt(btn.dataset.completed, 10) === 1;
        const newCompleted = !wasCompleted;

        btn.disabled = true;
        const r = await App.post('/api/admin.php?action=toggle_task', {
            task_id: taskId,
            completed: newCompleted,
        });
        btn.disabled = false;
```

Replace with:

```javascript
    async function _toggleRowComplete(taskId, btn) {
        const wasCompleted = parseInt(btn.dataset.completed, 10) === 1;
        const newCompleted = !wasCompleted;
        const rowLine = btn.closest('.group-row-line');
        const requiresSub = rowLine && rowLine.dataset.requiresSubmission === '1';

        if (requiresSub && newCompleted) {
            const expandBody = btn.closest('.group-expand-body');
            const existing = document.getElementById(`grp-sub-${taskId}`);
            const prev = existing ? existing.innerText : '';
            showSubmissionModal({
                taskId,
                prefill: prev,
                title: '',
                onConfirm: async (text) => {
                    const rr = await App.post('/api/admin.php?action=toggle_task', { task_id: taskId, completed: true, submission_text: text });
                    if (rr.success) { Toast.success(rr.message); _renderGroupExpand(expandBody); }
                    return rr.success;
                },
                onCancel: () => {},
            });
            return;
        }

        btn.disabled = true;
        const r = await App.post('/api/admin.php?action=toggle_task', {
            task_id: taskId,
            completed: newCompleted,
        });
        btn.disabled = false;
```

- [ ] **Step 4: 묶음 (접힘 상태) row 에 📝 prefix 추가**

`grep -nE 'all_tasks_grouped' public_html/js/admin.js` 로 묶음 목록 렌더 위치 찾기.

(예: `loadTasksMgmt` 안 또는 `renderTaskGroupList` 등 — 실제 코드에서 row 의 title 셀 렌더 부분에 다음 패턴 적용)

```javascript
const requiresSubIcon = parseInt(g.requires_submission) === 1 ? '📝 ' : '';
// title 셀 출력에 prefix
`<td>${requiresSubIcon}${App.esc(g.title)}</td>`
```

(주: `all_tasks_grouped` 응답이 묶음 첫 row 의 requires_submission 을 포함해야 한다 — Task 6 에서 task_group_get 만 추가했음. all_tasks_grouped 의 SELECT 에 `MAX(t.requires_submission) AS requires_submission` 추가 필요. admin.php:1213-1275 의 3개 SELECT 모두 GROUP BY 절 안 SELECT list 에 다음 추가:)

```sql
MAX(t.requires_submission) AS requires_submission,
```

- [ ] **Step 5: cache buster 갱신** (Task 9 와 동일 파일들)

- [ ] **Step 6: 수동 검증**

DEV /operation/#tasks → 묶음 목록에 📝 prefix → 펼침 → row 별 인라인 텍스트 → [전체 보기] 토글 → [수정] 모달 → row 의 [☐ 완료하기] 클릭 → 모달 → 저장 → 펼침 새로고침 → 인라인 노출

- [ ] **Step 7: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/admin.php public_html/js/admin.js
git commit -m "feat(ui): 묶음 펼침 row 에 결과물 인라인 + 운영자 수정 버튼

- all_tasks_grouped SELECT 에 MAX(requires_submission) 추가
- 묶음 row title 에 📝 prefix
- 펼침 안 row 별 인라인 결과물 + [전체 보기] [수정]
- _toggleRowComplete 에 requires_submission 분기

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 12: CSS 추가 (`public_html/css/admin.css`)

**Files:**
- Modify: `public_html/css/admin.css` (파일 끝에 append)

- [ ] **Step 1: 추가**

Append:

```css
/* ── Task 결과물 제출 ── */
.task-requires-submission-chip {
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
    font-size: 0.78rem;
    padding: 2px 6px;
    border-radius: 4px;
    font-weight: 500;
}

.task-submission-text {
    margin-top: 8px;
    padding: 8px 10px;
    background: #fffbeb;
    border-left: 3px solid #fcd34d;
    border-radius: 4px;
    font-size: 0.9rem;
}

.task-submission-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.82rem;
    color: var(--gray-600, #666);
    margin-bottom: 4px;
}

.task-submission-meta button.link-btn,
.task-submission-meta button.task-toggle-submission,
.task-submission-meta button.task-edit-submission {
    background: none;
    border: none;
    color: var(--primary, #3b82f6);
    cursor: pointer;
    font-size: 0.82rem;
    padding: 0;
    text-decoration: underline;
}

.task-submission-body {
    line-height: 1.5;
    white-space: normal;
    word-break: break-word;
}

.task-submission-body.collapsed {
    display: -webkit-box;
    -webkit-line-clamp: 1;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
```

- [ ] **Step 2: cache buster 갱신** (CSS 도 같이)

`grep -rnE 'admin\\.css\\?v=' public_html/` 로 모든 사용처 갱신.

- [ ] **Step 3: 수동 검증**

브라우저에서 chip / 인라인 박스 / 접힘-펼침 시각 확인.

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev && git add public_html/css/admin.css public_html/operation/index.php public_html/coach/index.php public_html/leader/index.php
git commit -m "feat(css): task 결과물 제출 chip / 인라인 박스 스타일

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 13: 회귀 테스트 — task_group_invariants 에 INV-S2 fixture 추가

**Files:**
- Modify: `tests/task_group_invariants.php`

- [ ] **Step 1: setup 에 requires_submission 시나리오 row 추가**

Find (task_group_invariants.php — fixture INSERT 직후, INV-1 직전):

```php
    $ins->execute(['__inv_grp_b', 0, '2099-02-01', '2099-02-01', $cohort]);
```

Insert after:

```php
    // requires_submission 묶음 (INV-S2 회귀)
    $insSub = $db->prepare("
        INSERT INTO tasks (title, role, assignee_admin_id, completed, requires_submission, start_date, end_date, content_markdown, cohort)
        VALUES (?, 'operation', NULL, ?, ?, ?, ?, 'cs', ?)
    ");
    $insSub->execute(['__inv_grp_sub', 0, 1, '2099-02-10', '2099-02-10', $cohort]);
    $insSub->execute(['__inv_grp_sub', 0, 1, '2099-02-11', '2099-02-11', $cohort]);
```

Insert after the existing INV checks (find the `// ── INV-3 ──` or 마지막 INV 직후):

```php
    // ── INV-S2: __inv_grp_sub 묶음 안 requires_submission 일관 ──
    $mixed = $db->prepare("
        SELECT MIN(requires_submission) AS mn, MAX(requires_submission) AS mx
          FROM tasks
         WHERE cohort = ? AND title = '__inv_grp_sub' AND role = 'operation'
    ");
    $mixed->execute([$cohort]);
    $m = $mixed->fetch();
    t('INV-S2 fixture 묶음 안 requires_submission 일관', (int)$m['mn'] === 1 && (int)$m['mx'] === 1);
```

(cleanup 시 `__inv_grp_sub` 도 삭제하는 DELETE 패턴 확인 — 기존 `LIKE '__inv_grp%'` 가 매치하므로 자동 정리됨.)

- [ ] **Step 2: 실행**

```bash
cd /root/boot-dev && php tests/task_group_invariants.php
```

Expected: 기존 PASS + 신규 1 PASS.

- [ ] **Step 3: 커밋**

```bash
git add tests/task_group_invariants.php
git commit -m "test(invariants): task_group_invariants 에 requires_submission 시나리오 추가

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 14: 통합 검증 + dev push

- [ ] **Step 1: 모든 테스트 재실행**

```bash
cd /root/boot-dev && php tests/task_submission_invariants.php && \
  php tests/task_group_invariants.php && \
  ADMIN_COOKIE='...' php tests/task_submission_api_test.php && \
  ADMIN_COOKIE='...' php tests/task_group_api_test.php
```

Expected: 모두 PASS.

- [ ] **Step 2: 수동 smoke (운영자 + 본인 assignee 양쪽)**

DEV 임시 묶음 (`requires_submission=1`, 본인 admin_id 로 생성) → 본인 dashboard 카드에서 체크 + 텍스트 → 저장 → 인라인 표시 → /operation/#tasks 묶음 펼침에서 같은 row 인라인 표시 + 수정 → 끝나면 묶음 [삭제] 로 정리.

- [ ] **Step 3: dev push**

```bash
cd /root/boot-dev && git push origin dev
```

- [ ] **Step 4: 사용자 검증 요청**

⛔ 멈추고 사용자에게 dev 검증 요청 메시지 출력. PROD 배포는 사용자가 명시적으로 요청한 경우에만.

(메모리 규칙: 운영 배포는 반드시 사용자 요청 시에만)

---

## Self-Review

(작성 후 plan 자체 검토 — 실행자가 아닌 plan 작성자 책임)

**Spec coverage**:
- ✅ 컬럼 3개 추가 → Task 1
- ✅ 묶음 단위 forward-only → Task 6 (UPDATE 절에 completed/text/timestamp 미포함으로 자동 보존)
- ✅ 체크 해제 시 텍스트 보존 → Task 3 (toggle_task 에서 completed=0 분기는 텍스트 안 건드림)
- ✅ 검증 (서버) → Task 3, 4
- ✅ 권한 → Task 3, 4 (requireAdmin 그대로)
- ✅ 텍스트 형식 → Task 10 (textarea, plain)
- ✅ 운영자 검토 위치 → Task 11 (묶음 펼침 인라인)
- ✅ task_create / task_group_get / task_group_update → Task 5, 6
- ✅ 신규 task_submission_update → Task 4
- ✅ today_tasks/all_tasks 응답에 컬럼 포함 → Task 2 (`SELECT t.*` 자동)
- ✅ task_group_rows 응답 → Task 2
- ✅ all_tasks_grouped 응답에 묶음 플래그 → Task 11 Step 4
- ✅ 단위 테스트 → Task 7
- ✅ 인보리언트 → Task 8
- ✅ 회귀 → Task 13

**Placeholder scan**: 모든 Step 에 실제 코드/명령. "TBD" / "..." 없음. (단 ADMIN_COOKIE 환경변수는 실행 시 설정 필요 — 명시됨.)

**Type consistency**:
- `requires_submission` 모든 곳 int (0/1).
- `submission_text` 모든 곳 string (trim 후).
- `submitted_at` 모든 곳 DATETIME (NOW() / NULL).
- API endpoint 이름 `task_submission_update` 일관.
- helper 이름 `showSubmissionModal` 일관.

**잠재 리스크**:
- Task 10 의 `App.openModal` close 이벤트 hook 이 명시적이지 않아 MutationObserver 폴백 사용. 실제 App helper 가 close hook 을 제공하면 단순화 가능.
- Task 11 Step 4 의 all_tasks_grouped SELECT 변경은 3개 if-branch 모두 동일하게 적용해야 함 — 누락 시 mine/role-filter/all 중 일부 분기에서 묶음 prefix 가 안 보임.
- Task 9 / 12 의 cache buster 갱신은 admin.js / admin.css 가 로드되는 모든 .php 페이지에서 갱신해야 함. 누락 시 옛 JS/CSS 잔존으로 사용자 화면 안 바뀜.
