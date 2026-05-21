# 회원 카드에 네이버 카페 닉네임 표시 — 실행 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 부트캠프 체크리스트·현황판의 회원 카드 sub-line 에 `☕ 카페닉` 을 추가해, 조장/코치가 네이버 카페 게시글에서 본 닉을 회원과 즉시 매칭할 수 있게 한다.

**Architecture:**
- `bootcamp_members.cafe_nickname` 컬럼을 신설하고 cafe_posts ingest / bulk-apply 시점에 항상 최신값으로 sync 한다.
- 카드 헬퍼 `memberCellHtml(m)` 한 군데만 수정해서 4역할(leader/coach/head/operation) × 3뷰(체크리스트 일별/과제별/현황판) 자동 전파.
- API 3 핸들러 SELECT 에 컬럼 1줄씩 추가. 인덱스/필터/정렬 변경 0.

**Tech Stack:** PHP 8 + MariaDB 10.x + 바닐라 JS. boot 컨벤션의 `migrate_<주제>.php` CLI 마이그 + `tests/<주제>_test.php` PHP CLI 테스트 + transaction rollback 격리.

**관련 spec:** `docs/superpowers/specs/2026-05-20-cafe-nickname-display-design.md`

**작업 룰 (반드시 준수):**
- boot-dev (DEV_BOOT, dev 브랜치) 에서만 작업 / 코드 수정.
- Task 6 의 `git push origin dev` 후 **⛔ 멈춤**. 사용자가 운영 반영을 명시할 때만 main 머지 + prod pull (PROD 마이그 포함).

---

## File Structure

| 파일 | 역할 | 변경 종류 |
|------|------|----------|
| `migrate_cafe_nickname.php` (boot 루트) | 컬럼 추가 + 1회 백필 (멱등) | 신규 |
| `tests/cafe_ingest_nickname_test.php` | `ingestCafePosts` sync hook 단위 | 신규 |
| `tests/cafe_bulk_apply_test.php` | bulk-apply 시 즉시 백필 케이스 | 수정 |
| `tests/check_cafe_nickname_test.php` | `check.php` 3 핸들러 응답에 `cafe_nickname` 필드 | 신규 |
| `public_html/includes/cafe/cafe_ingest.php` | `ingestCafePosts()` 안 sync hook | 수정 |
| `public_html/includes/cafe/cafe_backfill_helper.php` | bulk-apply 가 부르는 helper 에 닉 백필 1줄 | 수정 |
| `public_html/api/services/check.php` | 3 SELECT 에 `bm.cafe_nickname` 추가 | 수정 |
| `public_html/js/bootcamp.js` | `memberCellHtml()` sub-line 에 `· ☕ {nick}` | 수정 |

---

## Task 1: 마이그 (`migrate_cafe_nickname.php`)

**Files:**
- Create: `migrate_cafe_nickname.php` (boot 루트)

- [ ] **Step 1: 마이그 스크립트 작성**

`migrate_cafe_nickname.php` 를 boot 루트에 작성. 멱등 가드 (`columnExists()`) + ALTER + 트랜잭션 백필 + 검증 출력 패턴은 `migrate_tasks_group_kind.php` 참고.

```php
<?php
/**
 * bootcamp_members.cafe_nickname 컬럼 추가 + 1회 백필.
 *
 * 사용: php migrate_cafe_nickname.php
 *
 * 멱등: 컬럼 존재 시 ALTER skip / `<>` 가드로 동일값 UPDATE skip.
 */
if (php_sapi_name() !== 'cli') exit("CLI only\n");
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

function columnExists(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

echo "== bootcamp_members.cafe_nickname 마이그 ==\n";

if (!columnExists($db, 'bootcamp_members', 'cafe_nickname')) {
    echo "ALTER: cafe_nickname VARCHAR(100) NULL 추가...\n";
    $db->exec("
        ALTER TABLE bootcamp_members
          ADD COLUMN cafe_nickname VARCHAR(100) NULL DEFAULT NULL
            COMMENT '네이버 카페 닉네임 (cafe_posts.nickname 최신값으로 cron/upsert 시 동기화)'
          AFTER cafe_member_key
    ");
} else {
    echo "skip: cafe_nickname 이미 존재\n";
}

echo "백필: cafe_member_key 매핑된 회원의 최신 cafe_posts.nickname 으로 채움\n";
$db->beginTransaction();
try {
    $stmt = $db->prepare("
        UPDATE bootcamp_members bm
        JOIN (
          SELECT cp.member_key, cp.nickname
          FROM cafe_posts cp
          WHERE cp.nickname IS NOT NULL AND cp.member_key IS NOT NULL
          AND cp.id = (
            SELECT cp2.id FROM cafe_posts cp2
            WHERE cp2.member_key = cp.member_key AND cp2.nickname IS NOT NULL
            ORDER BY cp2.posted_at DESC, cp2.id DESC LIMIT 1
          )
        ) latest ON latest.member_key = bm.cafe_member_key
        SET bm.cafe_nickname = latest.nickname
        WHERE bm.cafe_member_key IS NOT NULL
          AND (bm.cafe_nickname IS NULL OR bm.cafe_nickname <> latest.nickname)
    ");
    $stmt->execute();
    $updated = $stmt->rowCount();
    $db->commit();
    echo "백필 완료: {$updated} row\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "FAIL: 백필 rollback — " . $e->getMessage() . "\n";
    exit(1);
}

$total    = (int)$db->query("SELECT COUNT(*) FROM bootcamp_members WHERE cafe_member_key IS NOT NULL")->fetchColumn();
$withNick = (int)$db->query("SELECT COUNT(*) FROM bootcamp_members WHERE cafe_member_key IS NOT NULL AND cafe_nickname IS NOT NULL")->fetchColumn();
echo "검증: cafe_member_key 매핑 {$total} / 그 중 cafe_nickname 채워진 {$withNick}\n";
echo "PASS\n";
```

- [ ] **Step 2: DEV 마이그 실행**

Run: `cd /root/boot-dev && php migrate_cafe_nickname.php`

Expected output (대략):
```
== bootcamp_members.cafe_nickname 마이그 ==
ALTER: cafe_nickname VARCHAR(100) NULL 추가...
백필: cafe_member_key 매핑된 회원의 최신 cafe_posts.nickname 으로 채움
백필 완료: 60+ row
검증: cafe_member_key 매핑 64 / 그 중 cafe_nickname 채워진 60+
PASS
```

매핑 수는 매핑된 회원 중 post 가 있는 회원의 수와 일치한다. `withNick === total` 일 필요 없음 (post 가 아직 없는 매핑은 다음 cron 에서 채워짐).

- [ ] **Step 3: 멱등성 확인 — 한 번 더 실행**

Run: `cd /root/boot-dev && php migrate_cafe_nickname.php`

Expected output:
```
== bootcamp_members.cafe_nickname 마이그 ==
skip: cafe_nickname 이미 존재
백필: ...
백필 완료: 0 row
검증: cafe_member_key 매핑 64 / 그 중 cafe_nickname 채워진 60+
PASS
```

`<>` 가드 덕에 두 번째 실행에서 `백필 완료: 0 row` 가 떠야 한다.

- [ ] **Step 4: 컬럼 검증 (CLI)**

Run:
```bash
mysql -h localhost -u SORITUNECOM_DEV_BOOT -p"$(grep ^DB_PASS=/root/boot-dev/.db_credentials | cut -d= -f2-)" SORITUNECOM_DEV_BOOT -e \
  "SELECT id, nickname, cafe_member_key, cafe_nickname FROM bootcamp_members WHERE cafe_nickname IS NOT NULL LIMIT 5;"
```

Expected: 5 행 출력. `cafe_nickname` 컬럼이 채워져 있고 의미상 카페 닉처럼 보이면 OK.

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev
git add migrate_cafe_nickname.php
git commit -m "feat: add bootcamp_members.cafe_nickname column + backfill migration"
```

---

## Task 2: `ingestCafePosts` sync hook (TDD)

**Files:**
- Create: `tests/cafe_ingest_nickname_test.php`
- Modify: `public_html/includes/cafe/cafe_ingest.php:63` (`ingestCafePosts()`)

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/cafe_ingest_nickname_test.php`:

```php
<?php
/**
 * ingestCafePosts() 가 bootcamp_members.cafe_nickname 을 sync 하는지.
 * DEV DB transaction rollback 으로 격리.
 * 사용: php tests/cafe_ingest_nickname_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/cafe/cafe_ingest.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();
$db->beginTransaction();

try {
    // 시드: cohort + group + member with cafe_member_key
    $cohortLabel = 'TEST_NICK_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $groupCode = 'tn_lisa_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, stage_no, code) VALUES (?, '리사조', 1, ?)")
       ->execute([$cohortId, $groupCode]);
    $groupId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, cafe_member_key, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 'active', 1, 1, CURDATE())");
    $ins->execute([$cohortId, $groupId, '김명식', '그릭이', 'KEY_ALICE']);
    $alice = (int)$db->lastInsertId();
    $ins->execute([$cohortId, $groupId, '이서연', '서연쓰', null]); // 매핑 안 됨
    $bob = (int)$db->lastInsertId();

    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');

    // ── Case 1: 신규 post → cafe_nickname 채움
    ingestCafePosts([
        ['cafe_article_id' => 'TN_ART1', 'title' => 't1', 'member_key' => 'KEY_ALICE', 'nickname' => 'gricky',
         'board_type' => 'inner33', 'posted_at' => $now, 'assignment_date' => $today],
    ]);
    $nick = $db->query("SELECT cafe_nickname FROM bootcamp_members WHERE id={$alice}")->fetchColumn();
    t('case1_alice_nick_set', $nick === 'gricky', "got: " . var_export($nick, true));

    // ── Case 2: 같은 회원 글이 batch 안에 여러 개 → 마지막값으로 통일, UPDATE 1회만 (cache hit)
    ingestCafePosts([
        ['cafe_article_id' => 'TN_ART2', 'title' => 't2', 'member_key' => 'KEY_ALICE', 'nickname' => 'gricky2',
         'board_type' => 'inner33', 'posted_at' => $now, 'assignment_date' => $today],
        ['cafe_article_id' => 'TN_ART3', 'title' => 't3', 'member_key' => 'KEY_ALICE', 'nickname' => 'gricky2',
         'board_type' => 'inner33', 'posted_at' => $now, 'assignment_date' => $today],
    ]);
    $nick = $db->query("SELECT cafe_nickname FROM bootcamp_members WHERE id={$alice}")->fetchColumn();
    t('case2_alice_nick_updated', $nick === 'gricky2', "got: " . var_export($nick, true));

    // ── Case 3: 동일값 재호출 → 변경 없음 (rowCount 영향 검증은 직접 못하지만 nick 동일하면 OK)
    ingestCafePosts([
        ['cafe_article_id' => 'TN_ART4', 'title' => 't4', 'member_key' => 'KEY_ALICE', 'nickname' => 'gricky2',
         'board_type' => 'inner33', 'posted_at' => $now, 'assignment_date' => $today],
    ]);
    $nick = $db->query("SELECT cafe_nickname FROM bootcamp_members WHERE id={$alice}")->fetchColumn();
    t('case3_alice_nick_unchanged', $nick === 'gricky2');

    // ── Case 4: nickname 빈 문자열 → 변경 안 함
    ingestCafePosts([
        ['cafe_article_id' => 'TN_ART5', 'title' => 't5', 'member_key' => 'KEY_ALICE', 'nickname' => '',
         'board_type' => 'inner33', 'posted_at' => $now, 'assignment_date' => $today],
    ]);
    $nick = $db->query("SELECT cafe_nickname FROM bootcamp_members WHERE id={$alice}")->fetchColumn();
    t('case4_empty_nickname_noop', $nick === 'gricky2');

    // ── Case 5: member_key 미매핑 (bob) → bootcamp_members 변경 없음
    ingestCafePosts([
        ['cafe_article_id' => 'TN_ART6', 'title' => 't6', 'member_key' => 'KEY_BOB_UNMAPPED', 'nickname' => 'seoyeon',
         'board_type' => 'inner33', 'posted_at' => $now, 'assignment_date' => $today],
    ]);
    $bobNick = $db->query("SELECT cafe_nickname FROM bootcamp_members WHERE id={$bob}")->fetchColumn();
    t('case5_unmapped_member_untouched', $bobNick === null);

    echo "\nResult: {$pass} PASS / {$fail} FAIL\n";
    exit($fail === 0 ? 0 : 1);

} finally {
    $db->rollBack();
}
```

- [ ] **Step 2: 테스트 실행 → 실패 확인**

Run: `cd /root/boot-dev && php tests/cafe_ingest_nickname_test.php`

Expected: case1~4 FAIL (`cafe_nickname` 이 NULL 인 채로 남음). case5 PASS (당연히 미매핑은 영향 없음).

- [ ] **Step 3: `ingestCafePosts()` 에 sync hook 추가**

`public_html/includes/cafe/cafe_ingest.php` 의 `ingestCafePosts()` 함수 안, `$insertStmt` prepare 바로 뒤에 닉 UPDATE prepare 를 추가. 그리고 try 블록 안, `$insertStmt->execute(...)` 직후 (saveCheck 호출 옆) 에 sync hook 추가.

기존 코드:
```php
    $insertStmt = $db->prepare("
        INSERT INTO cafe_posts (cafe_article_id, title, member_key, nickname, board_type, posted_at, member_id, mission_checked, assignment_date, raw_data)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            nickname = VALUES(nickname),
            member_id = VALUES(member_id),
            mission_checked = VALUES(mission_checked),
            assignment_date = VALUES(assignment_date)
    ");

    $memberKeyCache = [];
```

변경:
```php
    $insertStmt = $db->prepare("
        INSERT INTO cafe_posts (cafe_article_id, title, member_key, nickname, board_type, posted_at, member_id, mission_checked, assignment_date, raw_data)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            nickname = VALUES(nickname),
            member_id = VALUES(member_id),
            mission_checked = VALUES(mission_checked),
            assignment_date = VALUES(assignment_date)
    ");

    // 닉 sync: 동일값 update 회피 가드 + PHP 측 batch cache 로 query 자체를 줄임.
    $updateNickStmt = $db->prepare("
        UPDATE bootcamp_members
           SET cafe_nickname = ?
         WHERE id = ?
           AND (cafe_nickname IS NULL OR cafe_nickname <> ?)
    ");
    $memberKeyCache = [];
    $memberCafeNickCache = [];
```

그리고 기존 saveCheck 블록:
```php
            if ($memberId && $boardType && $assignmentDate) {
                $missionTypeId = getMissionTypeId($db, $boardType);
                if ($missionTypeId) {
                    saveCheck($db, $memberId, $assignmentDate, $missionTypeId, true, 'automation', "cafe:{$articleId}", null);
                }
            }
```

바로 위 또는 옆에 닉 sync 블록 추가:
```php
            // 닉 sync: 매핑된 회원 + 비어있지 않은 닉만, batch 안 같은 회원은 1회만.
            if ($memberId && $nickname !== null && $nickname !== '') {
                if (!isset($memberCafeNickCache[$memberId])
                    || $memberCafeNickCache[$memberId] !== $nickname) {
                    $updateNickStmt->execute([$nickname, $memberId, $nickname]);
                    $memberCafeNickCache[$memberId] = $nickname;
                }
            }

            if ($memberId && $boardType && $assignmentDate) {
                $missionTypeId = getMissionTypeId($db, $boardType);
                if ($missionTypeId) {
                    saveCheck($db, $memberId, $assignmentDate, $missionTypeId, true, 'automation', "cafe:{$articleId}", null);
                }
            }
```

- [ ] **Step 4: 테스트 재실행 → 전부 PASS**

Run: `cd /root/boot-dev && php tests/cafe_ingest_nickname_test.php`

Expected: `Result: 5 PASS / 0 FAIL`.

- [ ] **Step 5: 기존 cafe_bulk_apply_test 회귀 0 확인**

Run: `cd /root/boot-dev && php tests/cafe_bulk_apply_test.php`

Expected: 기존 케이스 모두 PASS (회귀 0).

- [ ] **Step 6: Commit**

```bash
cd /root/boot-dev
git add public_html/includes/cafe/cafe_ingest.php tests/cafe_ingest_nickname_test.php
git commit -m "feat: sync bootcamp_members.cafe_nickname from cafe_posts ingest"
```

---

## Task 3: bulk-apply 즉시 백필 (TDD)

**Files:**
- Modify: `tests/cafe_bulk_apply_test.php` (케이스 추가)
- Modify: `public_html/includes/cafe/cafe_backfill_helper.php` (helper 안에서 nickname 도 같이 갱신)

**설계 결정:** `cafe_bulk_apply.php` 는 이미 step 3 에서 `backfillPostsForMembers()` 를 호출한다. 그 helper 가 cafe_posts.member_id 를 매핑된 회원으로 채우는데, 같은 호출에서 `bootcamp_members.cafe_nickname` 도 갱신하면 spec 의 "즉시 백필" 이 자연스럽게 만족된다. helper 한 군데에 응집.

- [ ] **Step 1: `cafe_bulk_apply_test.php` 에 닉 백필 검증 추가**

기존 `tests/cafe_bulk_apply_test.php` 의 Case 1 (alice 가 NEW_KEY_ALICE 에 등록되어 3건 백필) 직후, alice 의 cafe_nickname 도 'gricky' 로 채워졌는지 검증 추가.

기존 (line 60~70 부근):
```php
    t('case1_summary', $r['summary']['applied'] === 1 && $r['summary']['skipped'] === 0 && $r['summary']['failed'] === 0);
    t('case1_backfill', $r['results'][0]['backfilled_posts'] === 3);
    t('case1_missions', $r['results'][0]['missions_saved'] === 2);

    // 적용 후 DB 검증
    $aliceKey = $db->query("SELECT cafe_member_key FROM bootcamp_members WHERE id={$alice}")->fetchColumn();
    t('case1_key_set', $aliceKey === 'NEW_KEY_ALICE');
    $aliceBackfilled = (int)$db->query("SELECT COUNT(*) FROM cafe_posts WHERE member_id={$alice}")->fetchColumn();
    t('case1_posts', $aliceBackfilled === 3);
    $aliceMissions = (int)$db->query("SELECT COUNT(*) FROM member_mission_checks WHERE member_id={$alice} AND source_ref LIKE 'cafe:%'")->fetchColumn();
    t('case1_mission_rows', $aliceMissions === 2);
```

직후 (case2 시작 전) 에 다음 라인 추가:
```php
    $aliceNick = $db->query("SELECT cafe_nickname FROM bootcamp_members WHERE id={$alice}")->fetchColumn();
    t('case1_cafe_nickname_backfilled', $aliceNick === 'gricky', "got: " . var_export($aliceNick, true));
```

같은 식으로 case2 (charlie 에 displaced key 등록) 직후, charlie 의 cafe_nickname 도 'seoyeon' 으로 채워졌는지 검증 추가. case2 의 마지막 `t('case2_charlie_set', ...)` 다음 줄에:
```php
    $charlieNick = $db->query("SELECT cafe_nickname FROM bootcamp_members WHERE id={$charlie}")->fetchColumn();
    t('case2_cafe_nickname_backfilled', $charlieNick === 'seoyeon', "got: " . var_export($charlieNick, true));
```

- [ ] **Step 2: 테스트 실행 → 실패 확인**

Run: `cd /root/boot-dev && php tests/cafe_bulk_apply_test.php`

Expected: 신규 두 assertion (case1_cafe_nickname_backfilled, case2_cafe_nickname_backfilled) FAIL.

- [ ] **Step 3: `backfillPostsForMembers()` 안에서 nickname 도 갱신**

`public_html/includes/cafe/cafe_backfill_helper.php` 의 끝 `return $result;` 바로 위에 다음 블록 추가 (각 회원의 최신 cafe_posts.nickname → bootcamp_members.cafe_nickname):

```php
    // 닉 백필: 방금 매핑된 회원들의 최신 cafe_posts.nickname 으로 cafe_nickname 1회 갱신.
    // post 가 없는 회원은 자연 skip (다음 cron poll 에서 ingest sync 가 채움).
    if (!empty($keyToMember)) {
        $nickStmt = $db->prepare("
            SELECT nickname FROM cafe_posts
            WHERE member_key = ? AND nickname IS NOT NULL
            ORDER BY posted_at DESC, id DESC LIMIT 1
        ");
        $memberNickStmt = $db->prepare("
            UPDATE bootcamp_members
               SET cafe_nickname = ?
             WHERE id = ?
               AND (cafe_nickname IS NULL OR cafe_nickname <> ?)
        ");
        foreach ($keyToMember as $key => $mid) {
            $nickStmt->execute([$key]);
            $latestNick = $nickStmt->fetchColumn();
            if ($latestNick) {
                $memberNickStmt->execute([$latestNick, $mid, $latestNick]);
            }
        }
    }

    return $result;
```

- [ ] **Step 4: 테스트 재실행 → 전부 PASS**

Run: `cd /root/boot-dev && php tests/cafe_bulk_apply_test.php`

Expected: 모든 PASS (기존 + case1_cafe_nickname_backfilled + case2_cafe_nickname_backfilled).

- [ ] **Step 5: ingest 회귀 0 확인**

Run: `cd /root/boot-dev && php tests/cafe_ingest_nickname_test.php`

Expected: `Result: 5 PASS / 0 FAIL`.

- [ ] **Step 6: Commit**

```bash
cd /root/boot-dev
git add public_html/includes/cafe/cafe_backfill_helper.php tests/cafe_bulk_apply_test.php
git commit -m "feat: backfill cafe_nickname when applying bulk cafe_member_key mapping"
```

---

## Task 4: `check.php` 3 SELECT 에 `bm.cafe_nickname` 추가 (TDD)

**Files:**
- Create: `tests/check_cafe_nickname_test.php`
- Modify: `public_html/api/services/check.php` (3 SELECT)

- [ ] **Step 1: 실패하는 테스트 작성**

`tests/check_cafe_nickname_test.php`:

```php
<?php
/**
 * check.php 의 3 핸들러 (handleChecklist / handleChecklistByMission / handleStatusBoard)
 * 응답 members[] 에 cafe_nickname 필드가 들어오는지.
 *
 * Auth 우회 (requireAdmin) 위해 SQL 만 직접 실행해서 응답 모양을 모사:
 *   - bootcamp_members + cafe_nickname 시드
 *   - handleChecklist 이 사용하는 SELECT 와 동일한 SELECT 를 직접 돌리고 결과에 cafe_nickname 키 존재 확인
 *
 * 핸들러를 직접 호출하려면 requireAdmin / $_GET 셋업 / jsonSuccess 가 출력 버퍼링이 필요해서 단위는 SQL 검증으로 충분.
 * 사용: php tests/check_cafe_nickname_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();
$db->beginTransaction();

try {
    // 시드: cohort + group + member (cafe_nickname 채워진 1명, NULL 인 1명)
    $cohortLabel = 'TEST_CHK_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    $groupCode = 'tc_lisa_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, stage_no, code) VALUES (?, '리사조', 1, ?)")
       ->execute([$cohortId, $groupCode]);
    $groupId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, cafe_member_key, cafe_nickname, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, ?, 'active', 1, 1, CURDATE())");
    $ins->execute([$cohortId, $groupId, '김명식', '그릭이', 'KEY_A', 'gricky']);
    $alice = (int)$db->lastInsertId();
    $ins->execute([$cohortId, $groupId, '이서연', '서연쓰', null, null]);
    $bob = (int)$db->lastInsertId();

    // handleChecklist SELECT 와 동일
    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
               bm.group_id, bm.cafe_nickname, bg.name AS group_name,
               COALESCE(ms.current_score, 0) AS current_score,
               COALESCE(mcb.current_coin, 0) AS current_coin
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        LEFT JOIN member_coin_balances mcb ON bm.id = mcb.member_id
        WHERE bm.cohort_id = ? AND bm.is_active = 1
        ORDER BY bg.name, bm.nickname
    ");
    $stmt->execute([$cohortId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    t('checklist_member_count', count($members) === 2);
    t('checklist_has_cafe_nickname_key', isset($members[0]['cafe_nickname']));

    $aliceRow = null; $bobRow = null;
    foreach ($members as $m) {
        if ((int)$m['id'] === $alice) $aliceRow = $m;
        if ((int)$m['id'] === $bob)   $bobRow = $m;
    }
    t('checklist_alice_nick', $aliceRow && $aliceRow['cafe_nickname'] === 'gricky');
    t('checklist_bob_nick_null', $bobRow && $bobRow['cafe_nickname'] === null);

    // handleStatusBoard SELECT (member_status 추가)
    $stmt = $db->prepare("
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
               bm.group_id, bm.member_status, bm.cafe_nickname, bg.name AS group_name,
               COALESCE(ms.current_score, 0) AS current_score,
               COALESCE(mcb.current_coin, 0) AS current_coin
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bm.group_id = bg.id
        LEFT JOIN member_scores ms ON bm.id = ms.member_id
        LEFT JOIN member_coin_balances mcb ON bm.id = mcb.member_id
        WHERE bm.cohort_id = ? AND bm.is_active = 1
        ORDER BY bg.name, bm.nickname
    ");
    $stmt->execute([$cohortId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    t('statusboard_alice_nick', $members[0]['cafe_nickname'] === 'gricky' || $members[1]['cafe_nickname'] === 'gricky');

    echo "\nResult: {$pass} PASS / {$fail} FAIL\n";
    exit($fail === 0 ? 0 : 1);

} finally {
    $db->rollBack();
}
```

- [ ] **Step 2: 테스트 실행 → 일부 통과**

Run: `cd /root/boot-dev && php tests/check_cafe_nickname_test.php`

Expected: 모두 PASS (Task 1 마이그로 컬럼이 이미 있고, 시드 INSERT 가 직접 채웠으니까).

> 주의: 이 테스트는 컬럼 존재만 확인. 다음 step 의 SELECT 수정은 응답 JSON 에 키가 노출되는지 측면.

- [ ] **Step 3: `check.php` 3 SELECT 에 `bm.cafe_nickname` 추가**

`public_html/api/services/check.php` 의 3 SELECT 모두에서 `bm.group_id` 다음에 `bm.cafe_nickname` 추가.

**1) `handleChecklist()` (line ~28)** — 기존:
```php
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
               bm.group_id, bg.name AS group_name,
```
변경:
```php
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
               bm.group_id, bm.cafe_nickname, bg.name AS group_name,
```

**2) `handleChecklistByMission()` (line ~201)** — 동일한 패턴으로 같은 자리에 `bm.cafe_nickname` 추가.

**3) `handleStatusBoard()` (line ~334)** — 기존:
```php
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
               bm.group_id, bm.member_status, bg.name AS group_name,
```
변경:
```php
        SELECT bm.id, bm.nickname, bm.real_name, bm.member_role, bm.stage_no,
               bm.group_id, bm.member_status, bm.cafe_nickname, bg.name AS group_name,
```

- [ ] **Step 4: 응답에 cafe_nickname 키 노출 확인 (수동 curl)**

다음 명령은 운영자 세션 쿠키가 필요하다. 단순화를 위해 단위 테스트의 SQL 검증으로 갈음하고, 실제 응답 검증은 Task 6 의 브라우저 검증에서 (`/leader/#status` Network 탭 → response JSON 에 cafe_nickname 키 확인).

Run (생략 가능 — 다음 단계 통합 검증에서 확인): 패스.

- [ ] **Step 5: 회귀 0 확인 — 기존 모든 체크리스트 테스트**

Run:
```bash
cd /root/boot-dev
for f in tests/cafe_ingest_nickname_test.php tests/cafe_bulk_apply_test.php tests/check_cafe_nickname_test.php; do
  echo "── $f ──"
  php "$f" || echo "FAIL: $f"
done
```

Expected: 전부 PASS.

- [ ] **Step 6: Commit**

```bash
cd /root/boot-dev
git add public_html/api/services/check.php tests/check_cafe_nickname_test.php
git commit -m "feat: surface bootcamp_members.cafe_nickname in check.php 3 handlers"
```

---

## Task 5: `memberCellHtml()` 에 sub-line segment 추가

**Files:**
- Modify: `public_html/js/bootcamp.js:449-455` (`memberCellHtml`)

- [ ] **Step 1: 헬퍼 변경**

기존 (line 449-455):
```javascript
    function memberCellHtml(m) {
        return `
            <button class="bc-member-btn" data-member-id="${m.id}" type="button">
                <div class="member-name">${App.esc(m.nickname)}${m.real_name ? ` <span style="color:#888;font-size:12px">(${App.esc(m.real_name)})</span>` : ''}${parseInt(m.participation_count || 0) > 1 ? ` <span class="badge badge-info" style="font-size:10px">${m.participation_count}회차</span>` : ''}</div>
                <div class="member-sub">${App.esc(m.group_name || '-')} · ${m.stage_no}단계</div>
            </button>`;
    }
```

변경:
```javascript
    function memberCellHtml(m) {
        const cafeNickHtml = m.cafe_nickname ? ` · ☕ ${App.esc(m.cafe_nickname)}` : '';
        return `
            <button class="bc-member-btn" data-member-id="${m.id}" type="button">
                <div class="member-name">${App.esc(m.nickname)}${m.real_name ? ` <span style="color:#888;font-size:12px">(${App.esc(m.real_name)})</span>` : ''}${parseInt(m.participation_count || 0) > 1 ? ` <span class="badge badge-info" style="font-size:10px">${m.participation_count}회차</span>` : ''}</div>
                <div class="member-sub">${App.esc(m.group_name || '-')} · ${m.stage_no}단계${cafeNickHtml}</div>
            </button>`;
    }
```

- [ ] **Step 2: 캐시 버스터 갱신 확인**

`bootcamp.js` 가 어디서 `<script src="...bootcamp.js?v=..."` 로 로드되는지 확인:

```bash
cd /root/boot-dev
grep -rn "bootcamp.js" public_html --include="*.php" | grep -v ".bak"
```

각 진입점 (`operation/index.php`, `coach/index.php`, `head/index.php`, `leader/index.php`) 의 `?v=` 쿼리를 현재 날짜 또는 단조 증가 숫자로 갱신. boot 컨벤션이 timestamp 인지 commit hash 인지 확인 후 동일 패턴 적용.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev
git add public_html/js/bootcamp.js public_html/operation/index.php public_html/coach/index.php public_html/head/index.php public_html/leader/index.php
git commit -m "feat: show cafe nickname on member card sub-line"
```

(`?v=` 변경 안 했으면 .php 파일은 add 에서 제외)

---

## Task 6: 통합 검증 + DEV push

**Files:** 없음 (검증만)

- [ ] **Step 1: 전체 테스트 PASS 확인**

Run:
```bash
cd /root/boot-dev
for f in tests/cafe_ingest_nickname_test.php tests/cafe_bulk_apply_test.php tests/check_cafe_nickname_test.php; do
  echo "── $f ──"
  php "$f" || { echo "FAIL: $f"; exit 1; }
done
echo "── ALL PASS ──"
```

Expected: 모든 테스트 PASS.

- [ ] **Step 2: DEV 마이그 멱등 재확인**

Run: `cd /root/boot-dev && php migrate_cafe_nickname.php`

Expected: `skip: cafe_nickname 이미 존재` + `백필 완료: 0 row`.

- [ ] **Step 3: DEV DB 행 수 검증**

Run:
```bash
mysql -h localhost -u SORITUNECOM_DEV_BOOT -p"$(grep ^DB_PASS=/root/boot-dev/.db_credentials | cut -d= -f2-)" SORITUNECOM_DEV_BOOT -e \
  "SELECT
     SUM(cafe_member_key IS NOT NULL) AS mapped,
     SUM(cafe_member_key IS NOT NULL AND cafe_nickname IS NOT NULL) AS with_nick,
     SUM(cafe_member_key IS NOT NULL AND cafe_nickname IS NULL) AS mapped_no_nick
   FROM bootcamp_members
   WHERE is_active = 1;"
```

Expected: `mapped = with_nick + mapped_no_nick`. 매핑된 회원 중 post 가 있는 회원은 닉이 채워지고, post 가 없는 회원은 NULL.

- [ ] **Step 4: 브라우저 수동 검증 (dev-boot.soritune.com)**

브라우저에서 다음 URL 들을 열어 회원 카드에 `☕ 카페닉` 이 sub-line 에 보이는지 확인 (cafe_nickname 이 채워진 회원에 한해):

- `https://dev-boot.soritune.com/leader/#checklist` — 체크리스트 일별 뷰
- `https://dev-boot.soritune.com/leader/#status` — 현황판
- `https://dev-boot.soritune.com/coach/#checklist` — 코치 (체크리스트)
- `https://dev-boot.soritune.com/coach/#status` — 코치 (현황판)
- `https://dev-boot.soritune.com/head/#status` — head
- `https://dev-boot.soritune.com/operation/#checklist` — 운영

체크 포인트:
- 카페 닉 있는 회원: `1조 · 1단계 · ☕ gricky` 패턴.
- 카페 닉 없는 회원: `1조 · 1단계` (sub-line 변경 없음).
- group NULL 회원: `- · 1단계` (회귀 0).
- 카드 클릭 → 회원 팝업 정상 동작 (변경 없음).

- [ ] **Step 5: dev branch push**

```bash
cd /root/boot-dev
git status
git log --oneline -10
git push origin dev
```

Expected: `dev → origin/dev` 6 commit (또는 그 이상) push 성공.

- [ ] **Step 6: ⛔ 멈춤 — 사용자 확인 요청**

사용자에게:
> "dev 푸시 완료했습니다. dev-boot.soritune.com 에서 회원 카드에 카페 닉 표시 확인해주세요. 운영 반영은 사용자 명시 후 진행할게요."

→ 사용자가 **운영 반영해줘** 등 명시할 때까지 main 머지 / PROD pull / PROD 마이그 진행 금지.

---

## (사용자 확인 후) Task 7: PROD 배포

**전제:** 사용자가 dev 검증 OK + 운영 반영 명시.

- [ ] **Step 1: main 머지 + push**

```bash
cd /root/boot-dev
git checkout main
git merge dev
git push origin main
git checkout dev
```

- [ ] **Step 2: PROD pull**

```bash
cd /root/boot-prod
git pull origin main
```

- [ ] **Step 3: PROD 마이그 실행**

```bash
cd /root/boot-prod
php migrate_cafe_nickname.php
```

Expected output:
```
== bootcamp_members.cafe_nickname 마이그 ==
ALTER: cafe_nickname VARCHAR(100) NULL 추가...
백필 완료: N row  (PROD 매핑 회원 수에 따라)
검증: cafe_member_key 매핑 ? / 그 중 cafe_nickname 채워진 ?
PASS
```

- [ ] **Step 4: PROD 검증**

```bash
mysql -h localhost -u SORITUNECOM_BOOT -p"$(grep ^DB_PASS=/root/boot-prod/.db_credentials | cut -d= -f2-)" SORITUNECOM_BOOT -e \
  "SELECT SUM(cafe_member_key IS NOT NULL) AS mapped,
          SUM(cafe_nickname IS NOT NULL) AS with_nick
   FROM bootcamp_members WHERE is_active = 1;"
```

브라우저로 `boot.soritune.com/leader/#status` 등에서 1차 시각 확인.

- [ ] **Step 5: 작업 종료 정리**

memory `project_boot_cafe_nickname_display_wip.md` → `project_boot_cafe_nickname_display_completed.md` 이전 + MEMORY.md 항목 갱신.

---

## 자체 리뷰

**Spec coverage:**
- §4 UX (sub-line · ☕ 닉) → Task 5 ✓
- §5 마이그 / 컬럼 / 백필 → Task 1 ✓
- §6.1 cafe_ingest sync → Task 2 ✓
- §6.2 cafe_bulk_apply 즉시 백필 → Task 3 (helper 안에서 통합) ✓
- §7 API 3 핸들러 SELECT → Task 4 ✓
- §8 테스트 (PHP 단위 + 통합) → Task 2/3/4/6 ✓
- §9 마이그 안전성 / 롤백 → Task 1 (멱등) + Task 7 ✓
- §10 작업 순서 → Task 1~7 ✓

**Placeholder 스캔:** N/A 표기 없음 ✓.

**Type 일관성:**
- 컬럼명 `cafe_nickname` 모든 task 에서 동일 ✓.
- JS 응답 키 `m.cafe_nickname` ↔ PHP SELECT `bm.cafe_nickname` 일치 ✓.
- 헬퍼명 `memberCellHtml(m)` Task 5 만 변경, 호출자 시그니처 변경 없음 ✓.

이 plan 대로 가면 spec 의 모든 결정사항이 task 안에 들어가 있고, 각 task 가 single commit 단위로 떨어진다.
