# 후기 작성 & 코인 적립 기능 — 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 회원이 카페/블로그 후기 링크를 제출하고 5코인씩 받는 기능 + 운영자가 거짓 제출을 취소/회수하는 기능 추가.

**Architecture:** 기존 coin 레일(`applyCoinChange`, `coin_cycles`, `reward_groups`) 재사용. 새 테이블 `review_submissions` 1개 + API 4개 + 회원/운영 UI 각 1개. 코인은 현재 active cycle에 적립되어 기존 `reward_group_distribute` 파이프라인으로 12기 정산 시 자동 지급.

**Tech Stack:** PHP 8 (api/bootcamp.php, api/services/, includes/coin_functions.php), Vanilla JS (member.js, member-shortcuts.js, admin.js), MySQL, Markdown 가이드는 `system_contents` 테이블.

**스펙:** `docs/superpowers/specs/2026-04-23-review-submission-design.md`

---

## 배포 게이트 (중요)

이 프로젝트의 메모리 규칙상:

1. 모든 코드 작업은 `boot-dev`(dev 브랜치)에서만.
2. Task 1~11을 모두 dev에서 완성 → `git push origin dev` → **여기서 멈춤**. 사용자에게 dev 확인 요청.
3. 사용자가 "운영 반영" 명시적으로 요청한 경우에만 main 머지 + prod pull + prod 마이그 실행.

Task 12는 위 게이트를 문서화하고, `boot-prod` 작업은 사용자 요청이 있을 때만 진행.

---

### Task 1: 마이그 스크립트 작성 + DEV DB 적용

**Files:**
- Create: `/root/boot-dev/migrate_review_submissions.php`

**목적:** `review_submissions` 테이블 생성 + `system_contents`에 토글/가이드 키 4개 시드.

- [ ] **Step 1: 스크립트 작성**

새 파일 `/root/boot-dev/migrate_review_submissions.php`:

```php
<?php
/**
 * boot.soritune.com - 후기 제출(review_submissions) 마이그
 * - review_submissions 테이블 생성
 * - system_contents에 토글/가이드 키 4개 seed (INSERT IGNORE)
 *
 * 실행: php migrate_review_submissions.php
 * Dry-run: php migrate_review_submissions.php --dry-run
 */

require_once __DIR__ . '/public_html/config.php';

$dryRun = in_array('--dry-run', $argv);
$db = getDB();

echo "=== Review Submissions Migration" . ($dryRun ? " [DRY-RUN]" : "") . " ===\n\n";

try {
    if (!$dryRun) $db->beginTransaction();

    // 1. review_submissions 테이블 생성
    echo "[1] review_submissions 테이블 생성...\n";
    $stmt = $db->query("SHOW TABLES LIKE 'review_submissions'");
    if ($stmt->fetch()) {
        echo "  - 이미 존재\n";
    } else {
        $sql = "CREATE TABLE review_submissions (
            id            INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            member_id     INT UNSIGNED NOT NULL,
            cycle_id      INT UNSIGNED NOT NULL,
            type          ENUM('cafe','blog') NOT NULL,
            url           VARCHAR(500) NOT NULL,
            coin_amount   INT NOT NULL DEFAULT 5,
            submitted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cancelled_at  DATETIME NULL,
            cancelled_by  INT UNSIGNED NULL,
            cancel_reason VARCHAR(255) NULL,
            INDEX idx_submitted_at (submitted_at DESC),
            INDEX idx_cycle_type (cycle_id, type),
            INDEX idx_member_cycle_type (member_id, cycle_id, type),
            CONSTRAINT fk_review_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id),
            CONSTRAINT fk_review_cycle  FOREIGN KEY (cycle_id)  REFERENCES coin_cycles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$dryRun) $db->exec($sql);
        echo "  - " . ($dryRun ? "생성 예정" : "생성 완료") . "\n";
    }

    // 2. system_contents 시드
    echo "[2] system_contents 시드...\n";
    $seeds = [
        ['review_cafe_enabled', 'on'],
        ['review_blog_enabled', 'on'],
        ['review_cafe_guide', "## 카페 후기 작성 안내\n\n1. 소리튠 공식 카페의 \"후기 게시판\"에 글을 올려주세요.\n2. 제목 또는 본문에 `#소리튠부트캠프12기` 해시태그를 포함해주세요.\n3. 본문에 학습 경험을 자유롭게 작성해주세요 (최소 3문장 권장).\n4. 작성한 글의 URL을 아래에 입력하면 **5코인**이 적립됩니다.\n\n※ 기수 중도 하차 시 적립된 코인은 지급되지 않습니다.\n※ 부실하거나 거짓으로 판단되는 후기는 운영자가 취소할 수 있으며, 이때 코인이 회수됩니다."],
        ['review_blog_guide', "## 블로그 후기 작성 안내\n\n1. 본인 블로그(네이버/티스토리/브런치 등)에 글을 올려주세요.\n2. 제목 또는 본문에 `#소리튠부트캠프12기` 해시태그를 포함해주세요.\n3. 본문에 학습 경험을 자유롭게 작성해주세요 (최소 3문장 권장).\n4. 작성한 글의 URL을 아래에 입력하면 **5코인**이 적립됩니다.\n\n※ 기수 중도 하차 시 적립된 코인은 지급되지 않습니다.\n※ 부실하거나 거짓으로 판단되는 후기는 운영자가 취소할 수 있으며, 이때 코인이 회수됩니다."],
    ];
    foreach ($seeds as [$key, $val]) {
        $check = $db->prepare("SELECT id FROM system_contents WHERE content_key = ?");
        $check->execute([$key]);
        if ($check->fetch()) {
            echo "  - {$key} : 이미 존재\n";
        } else {
            if (!$dryRun) {
                $db->prepare("INSERT INTO system_contents (content_key, content_markdown) VALUES (?, ?)")
                   ->execute([$key, $val]);
            }
            echo "  - {$key} : " . ($dryRun ? "삽입 예정" : "삽입 완료") . "\n";
        }
    }

    // 3. 검증
    echo "[3] 검증...\n";
    $check = $db->query("SHOW TABLES LIKE 'review_submissions'")->fetch();
    echo "  - review_submissions 테이블: " . ($check ? "OK" : "MISSING") . "\n";
    $keys = $db->query("SELECT content_key FROM system_contents WHERE content_key LIKE 'review_%'")
               ->fetchAll(PDO::FETCH_COLUMN);
    echo "  - system_contents 키: " . count($keys) . "개 (" . implode(', ', $keys) . ")\n";

    // MySQL은 CREATE TABLE 같은 DDL에서 암묵적으로 commit하므로,
    // 여기 도달한 시점에 transaction이 이미 닫혔을 수 있음 → 가드 필수
    if (!$dryRun && $db->inTransaction()) $db->commit();
    echo "\n완료" . ($dryRun ? " (dry-run)" : "") . ".\n";
} catch (Throwable $e) {
    if (!$dryRun && $db->inTransaction()) $db->rollBack();
    echo "\n실패: " . $e->getMessage() . "\n";
    exit(1);
}
```

- [ ] **Step 2: dry-run 실행**

```bash
cd /root/boot-dev && php migrate_review_submissions.php --dry-run
```

Expected: 각 단계 "생성 예정" / "삽입 예정" 로그 + "검증 OK" + 마지막 "완료 (dry-run)". 에러 없음.

- [ ] **Step 3: 실제 실행**

```bash
cd /root/boot-dev && php migrate_review_submissions.php
```

Expected: "생성 완료" / "삽입 완료" + "완료".

- [ ] **Step 4: DB 직접 확인**

```bash
cd /root/boot-dev && php -r "
require 'public_html/config.php';
\$db = getDB();
var_dump(\$db->query('DESCRIBE review_submissions')->fetchAll(PDO::FETCH_COLUMN));
var_dump(\$db->query(\"SELECT content_key FROM system_contents WHERE content_key LIKE 'review_%'\")->fetchAll(PDO::FETCH_COLUMN));
"
```

Expected: 테이블 컬럼 목록(id, member_id, cycle_id, type, url, coin_amount, submitted_at, cancelled_at, cancelled_by, cancel_reason), `review_%` 키 4개.

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add migrate_review_submissions.php && git commit -m "feat: 후기 제출 테이블 + system_contents 시드 마이그"
```

---

### Task 2: `coinReasonLabel()`에 라벨 추가

**Files:**
- Modify: `/root/boot-dev/public_html/includes/coin_functions.php` (`coinReasonLabel` 함수 내 `$map`)

- [ ] **Step 1: 라벨 2개 추가**

`coin_functions.php`의 `coinReasonLabel()` (현재 파일 line ~693). `$map` 배열에 2줄 추가 — 기존 `'reward_forfeited' => '하차로 인한 소실',` 라인 뒤:

```php
        'review_cafe'         => '카페 후기',
        'review_blog'         => '블로그 후기',
```

수정 후 `$map` 전체 예시:

```php
    $map = [
        'study_open'          => '복습스터디 개설',
        'study_join'          => '복습스터디 참여',
        'leader_coin'         => '리더 코인',
        'perfect_attendance'  => '찐완주 보너스',
        'hamemmal_bonus'      => '하멈말 보너스',
        'cheer_award'         => '응원상',
        'manual_adjustment'   => '운영자 조정',
        'reward_distribution' => '적립금 지급',
        'reward_forfeited'    => '하차로 인한 소실',
        'review_cafe'         => '카페 후기',
        'review_blog'         => '블로그 후기',
    ];
```

- [ ] **Step 2: 구문 오류 검증**

```bash
cd /root/boot-dev && php -l public_html/includes/coin_functions.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add public_html/includes/coin_functions.php && git commit -m "feat: coinReasonLabel에 review_cafe/review_blog 라벨 추가"
```

---

### Task 3: `api/services/review.php` 생성

**Files:**
- Create: `/root/boot-dev/public_html/api/services/review.php`

이 파일에 4개 핸들러(`handleMyReviewStatus`, `handleSubmitReview`, `handleReviewsList`, `handleReviewCancel`) + 헬퍼를 모은다.

- [ ] **Step 1: 파일 생성 — 헤더 + 헬퍼**

```php
<?php
/**
 * Review Service
 * 후기 제출/조회/취소 엔드포인트
 * 스펙: docs/superpowers/specs/2026-04-23-review-submission-design.md
 */

// ── 헬퍼 ─────────────────────────────────────────────────────

/**
 * system_contents 값 조회. 없으면 $default 반환.
 */
function getSystemContent($db, $key, $default = '') {
    $stmt = $db->prepare("SELECT content_markdown FROM system_contents WHERE content_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

/**
 * 회원 eligibility 판정.
 * @return array ['eligible' => bool, 'reason' => string|null, 'active_cycle' => array|null, 'member' => array]
 */
function evaluateReviewEligibility($db, $memberId) {
    // 회원 상태
    $mStmt = $db->prepare("SELECT id, is_active, member_status, cohort_id FROM bootcamp_members WHERE id = ?");
    $mStmt->execute([$memberId]);
    $member = $mStmt->fetch();
    if (!$member) return ['eligible' => false, 'reason' => 'member_inactive', 'active_cycle' => null, 'member' => null];
    if ((int)$member['is_active'] !== 1 ||
        in_array($member['member_status'], ['refunded', 'leaving', 'out_of_group_management'])) {
        return ['eligible' => false, 'reason' => 'member_inactive', 'active_cycle' => null, 'member' => $member];
    }

    // active cycle
    $cycle = getActiveCycle($db);
    if (!$cycle) {
        return ['eligible' => false, 'reason' => 'no_active_cycle', 'active_cycle' => null, 'member' => $member];
    }

    // cohort 매칭
    if ((int)$cycle['cohort_id'] !== (int)$member['cohort_id']) {
        return ['eligible' => false, 'reason' => 'cohort_mismatch', 'active_cycle' => $cycle, 'member' => $member];
    }

    return ['eligible' => true, 'reason' => null, 'active_cycle' => $cycle, 'member' => $member];
}

/**
 * URL 포맷 검증. 유효하면 true.
 */
function isValidReviewUrl($url) {
    if (!is_string($url)) return false;
    $len = strlen($url);
    if ($len < 10 || $len > 500) return false;
    return (bool)preg_match('#^https?://#i', $url);
}
```

- [ ] **Step 2: `handleMyReviewStatus` 추가**

위 파일에 이어서:

```php

// ── 회원: my_review_status ───────────────────────────────────

function handleMyReviewStatus() {
    $session = requireMember();
    $memberId = (int)$session['member_id'];

    $db = getDB();
    $elig = evaluateReviewEligibility($db, $memberId);

    // 현재 active cycle의 active 제출 조회 (eligible이 아니어도 과거 cycle은 반환 안 함)
    $cafeSubmitted = null;
    $blogSubmitted = null;
    if ($elig['active_cycle']) {
        $cycleId = (int)$elig['active_cycle']['id'];
        $sStmt = $db->prepare("
            SELECT id, type, url, coin_amount, submitted_at
            FROM review_submissions
            WHERE member_id = ? AND cycle_id = ? AND cancelled_at IS NULL
        ");
        $sStmt->execute([$memberId, $cycleId]);
        foreach ($sStmt->fetchAll() as $row) {
            $out = [
                'id' => (int)$row['id'],
                'url' => $row['url'],
                'submitted_at' => $row['submitted_at'],
                'coin_amount' => (int)$row['coin_amount'],
            ];
            if ($row['type'] === 'cafe') $cafeSubmitted = $out;
            else $blogSubmitted = $out;
        }
    }

    jsonSuccess([
        'eligible' => $elig['eligible'],
        'ineligible_reason' => $elig['reason'],
        'cafe' => [
            'enabled'   => getSystemContent($db, 'review_cafe_enabled', 'off') === 'on',
            'guide'     => getSystemContent($db, 'review_cafe_guide', ''),
            'submitted' => $cafeSubmitted,
        ],
        'blog' => [
            'enabled'   => getSystemContent($db, 'review_blog_enabled', 'off') === 'on',
            'guide'     => getSystemContent($db, 'review_blog_guide', ''),
            'submitted' => $blogSubmitted,
        ],
    ]);
}
```

- [ ] **Step 3: `handleSubmitReview` 추가**

위 파일에 이어서:

```php

// ── 회원: submit_review ──────────────────────────────────────

function handleSubmitReview($method) {
    if ($method !== 'POST') jsonError('POST only', 405);

    $session = requireMember();
    $memberId = (int)$session['member_id'];

    $input = getJsonInput();
    $type = trim($input['type'] ?? '');
    $url  = trim($input['url'] ?? '');

    if (!in_array($type, ['cafe', 'blog'], true)) jsonError('type은 cafe 또는 blog여야 합니다.');

    $db = getDB();

    // 토글 확인
    $enabled = getSystemContent($db, "review_{$type}_enabled", 'off');
    if ($enabled !== 'on') jsonError('현재 접수 중이 아닙니다.');

    // eligibility
    $elig = evaluateReviewEligibility($db, $memberId);
    if (!$elig['eligible']) {
        $msg = [
            'no_active_cycle' => '현재 진행 중인 기수가 없습니다.',
            'cohort_mismatch' => '이번 기수 후기 접수 대상이 아닙니다.',
            'member_inactive' => '현재 후기 제출이 불가능한 상태입니다.',
        ][$elig['reason']] ?? '후기 제출이 불가능합니다.';
        jsonError($msg);
    }

    // URL 검증
    if (!isValidReviewUrl($url)) jsonError('URL 형식이 올바르지 않습니다. (https:// 로 시작하는 10~500자 링크)');

    $cycleId = (int)$elig['active_cycle']['id'];

    // 트랜잭션: 동시 제출 방어 + 원자적 적립
    $db->beginTransaction();
    try {
        // active 제출 있는지 lock하며 확인
        $dup = $db->prepare("
            SELECT id FROM review_submissions
            WHERE member_id = ? AND cycle_id = ? AND type = ? AND cancelled_at IS NULL
            FOR UPDATE
        ");
        $dup->execute([$memberId, $cycleId, $type]);
        if ($dup->fetch()) {
            $db->rollBack();
            jsonError('이미 제출하셨습니다.');
        }

        // row INSERT (coin_amount=0, 뒤에서 UPDATE)
        $db->prepare("
            INSERT INTO review_submissions (member_id, cycle_id, type, url, coin_amount)
            VALUES (?, ?, ?, ?, 0)
        ")->execute([$memberId, $cycleId, $type, $url]);
        $insertId = (int)$db->lastInsertId();

        // 코인 적립
        $result = applyCoinChange(
            $db, $memberId, $cycleId, 5,
            "review_{$type}",
            "review_submission_id:{$insertId}",
            null
        );
        $applied = (int)$result['applied'];

        // applied === 0 → 제출 rollback
        if ($applied === 0) {
            $db->rollBack();
            jsonError('이번 기수 코인이 이미 최대치에 도달하여 후기 제출을 처리할 수 없습니다.');
        }

        // coin_amount를 실제 적립액으로 UPDATE
        $db->prepare("UPDATE review_submissions SET coin_amount = ? WHERE id = ?")
           ->execute([$applied, $insertId]);

        $db->commit();

        $submission = [
            'id' => $insertId,
            'url' => $url,
            'submitted_at' => date('Y-m-d H:i:s'),
            'coin_amount' => $applied,
        ];
        $message = $applied < 5
            ? "이번 기수 최대치에 가까워 {$applied}코인만 적립되었습니다."
            : "+{$applied} 코인이 적립되었습니다.";
        jsonSuccess([
            'applied_coin' => $applied,
            'submission' => $submission,
        ], $message);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 4: `handleReviewsList` 추가**

위 파일에 이어서:

```php

// ── 운영: reviews_list ───────────────────────────────────────

function handleReviewsList() {
    $admin = requireAdmin(['operation','coach','head','subhead1','subhead2']);

    $db = getDB();
    $active = getActiveCycle($db);
    $cycleId = (int)($_GET['cycle_id'] ?? ($active ? $active['id'] : 0));
    $type    = $_GET['type'] ?? 'all';   // cafe | blog | all
    $status  = $_GET['status'] ?? 'active'; // active | cancelled | all
    $q       = trim($_GET['q'] ?? '');

    // 공통 필터: cycle_id, type, q (status는 counts/items 각각 다르게 적용)
    $commonWhere = "rs.cycle_id = ?";
    $commonParams = [$cycleId];
    if (in_array($type, ['cafe','blog'], true)) {
        $commonWhere .= " AND rs.type = ?";
        $commonParams[] = $type;
    }
    if ($q !== '') {
        $commonWhere .= " AND (bm.nickname LIKE ? OR bm.real_name LIKE ?)";
        $commonParams[] = "%{$q}%";
        $commonParams[] = "%{$q}%";
    }

    // counts — status 미적용
    $cStmt = $db->prepare("
        SELECT
          COUNT(*) AS total,
          SUM(CASE WHEN rs.cancelled_at IS NULL THEN 1 ELSE 0 END) AS active_cnt,
          SUM(CASE WHEN rs.cancelled_at IS NOT NULL THEN 1 ELSE 0 END) AS cancelled_cnt
        FROM review_submissions rs
        JOIN bootcamp_members bm ON bm.id = rs.member_id
        WHERE {$commonWhere}
    ");
    $cStmt->execute($commonParams);
    $c = $cStmt->fetch();
    $counts = [
        'total'     => (int)($c['total'] ?? 0),
        'active'    => (int)($c['active_cnt'] ?? 0),
        'cancelled' => (int)($c['cancelled_cnt'] ?? 0),
    ];

    // items — 전 필터 적용
    $where = $commonWhere;
    $params = $commonParams;
    if ($status === 'active')         { $where .= " AND rs.cancelled_at IS NULL"; }
    elseif ($status === 'cancelled')  { $where .= " AND rs.cancelled_at IS NOT NULL"; }

    $sql = "
        SELECT rs.id, rs.type, rs.url, rs.coin_amount, rs.submitted_at,
               rs.cancelled_at, rs.cancel_reason,
               bm.id AS member_id, bm.nickname, bm.real_name,
               bg.name AS group_name,
               a.name AS cancelled_by_name
        FROM review_submissions rs
        JOIN bootcamp_members bm ON bm.id = rs.member_id
        LEFT JOIN bootcamp_groups bg ON bg.id = bm.group_id
        LEFT JOIN admins a ON a.id = rs.cancelled_by
        WHERE {$where}
        ORDER BY rs.submitted_at DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    jsonSuccess(['counts' => $counts, 'items' => $items]);
}
```

- [ ] **Step 5: `handleReviewCancel` 추가**

위 파일에 이어서:

```php

// ── 운영: review_cancel ──────────────────────────────────────

function handleReviewCancel($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation','coach','head','subhead1','subhead2']);

    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    $reason = trim($input['cancel_reason'] ?? '');

    if (!$id) jsonError('id가 필요합니다.');
    if ($reason === '' || mb_strlen($reason) > 255) {
        jsonError('취소 사유는 1~255자여야 합니다.');
    }

    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            SELECT id, member_id, cycle_id, type, coin_amount, cancelled_at
            FROM review_submissions WHERE id = ? FOR UPDATE
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            $db->rollBack();
            jsonError('해당 후기를 찾을 수 없습니다.');
        }
        if ($row['cancelled_at'] !== null) {
            $db->rollBack();
            jsonError('이미 취소된 후기입니다.');
        }

        $upd = $db->prepare("
            UPDATE review_submissions
               SET cancelled_at = NOW(),
                   cancelled_by = ?,
                   cancel_reason = ?
             WHERE id = ? AND cancelled_at IS NULL
        ");
        $upd->execute([$admin['admin_id'], $reason, $id]);
        if ($upd->rowCount() !== 1) {
            $db->rollBack();
            jsonError('취소 처리에 실패했습니다. 다시 시도해주세요.');
        }

        $coinAmount = (int)$row['coin_amount'];
        if ($coinAmount > 0) {
            applyCoinChange(
                $db, (int)$row['member_id'], (int)$row['cycle_id'],
                -$coinAmount,
                "review_{$row['type']}",
                "cancel:review_submission_id:{$id} reason:{$reason}",
                $admin['admin_id']
            );
        }

        $db->commit();
        jsonSuccess([
            'applied_coin' => -$coinAmount,
            'coin_amount'  => $coinAmount,
        ], "후기가 취소되고 코인 {$coinAmount}이 회수되었습니다.");
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
```

- [ ] **Step 6: 구문 오류 검증**

```bash
cd /root/boot-dev && php -l public_html/api/services/review.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 7: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/review.php && git commit -m "feat: 후기 제출/조회/취소 서비스 레이어 추가"
```

---

### Task 4: `bootcamp.php`에 route 연결

**Files:**
- Modify: `/root/boot-dev/public_html/api/bootcamp.php` (상단 require + switch case 추가)

- [ ] **Step 1: require 추가**

`bootcamp.php`의 상단 require 블록(다른 services require들이 있는 위치 — `grep -n "require_once __DIR__ . '/services/" public_html/api/bootcamp.php` 로 근처 라인 확인). 그 블록에 한 줄 추가:

```php
require_once __DIR__ . '/services/review.php';
```

- [ ] **Step 2: route 4개 추가**

같은 파일의 `switch ($action)` 블록. `case 'my_coin_history':` 근처(line 251) 또는 맨 끝 `default:` 직전에 추가:

```php
// ── Review Submissions ───────────────────────────────────────

case 'my_review_status':  handleMyReviewStatus(); break;
case 'submit_review':     handleSubmitReview($method); break;
case 'reviews_list':      handleReviewsList(); break;
case 'review_cancel':     handleReviewCancel($method); break;
```

- [ ] **Step 3: 구문 검증 + 회원 API 호출 smoke test**

```bash
cd /root/boot-dev && php -l public_html/api/bootcamp.php
```

Expected: `No syntax errors detected`.

```bash
cd /root/boot-dev && curl -sS "http://localhost/api/bootcamp.php?action=my_review_status" -b "dummy=1" | head -c 500
```

Expected: `{"success":false,"error":"로그인이 필요합니다"}` 류 — `requireMember()`가 돌았음을 확인. (실제 로그인 상태 smoke test는 Task 11 회귀.)

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/bootcamp.php && git commit -m "feat: 후기 관련 4개 API route 추가"
```

---

### Task 5: 회원 UI — 바로가기 카드 조건부 표시

**Files:**
- Modify: `/root/boot-dev/public_html/js/member-shortcuts.js`

**목적:** "후기 작성하기" 카드를 조건(eligible + 토글 on)에 따라 추가. 서버에서 상태를 먼저 fetch해서 판단.

- [ ] **Step 1: 후기 카드 동적 추가 로직 넣기**

`member-shortcuts.js` 전체 교체 — 기존 정적 SHORTCUTS는 유지하되, `render()`에서 my_review_status를 호출해 조건부로 "후기 작성" 카드 prepend:

```javascript
/* ══════════════════════════════════════════════════════════════
   MemberShortcuts — 바로가기 버튼 영역
   프로필 카드 아래, 탭 영역 위에 위치
   ══════════════════════════════════════════════════════════════ */
const MemberShortcuts = (() => {
    const API = '/api/bootcamp.php?action=';

    // ── 바로가기 버튼 데이터 ──
    // url이 null이면 회원 DB에서 가져오는 동적 링크
    const SHORTCUTS = [
        { key: 'lecture',   label: '소리블록 VOD 강의 들으러 가기',                url: 'https://www.sorimaster.com',                                    color: 'blue' },
        { key: 'daily',     label: '데일리 미션 하러 가기 (네이버 카페로 이동)',   url: 'https://m.cafe.naver.com/ca-fe/web/cafes/23243775/menus/288',   color: 'green' },
        { key: 'naemat33',  label: '내맛33미션 하러 가기 (네이버 카페로 이동)',    url: 'https://m.cafe.naver.com/ca-fe/web/cafes/23243775/menus/322',   color: 'amber' },
        { key: 'malkka',    label: '말까 미션 하러 가기 (네이버 카페로 이동)',     url: 'https://m.cafe.naver.com/ca-fe/web/cafes/23243775/menus/290',   color: 'violet' },
        { key: 'kakao',     label: '조별 카톡방 들어가기',    url: null,                                                            color: 'rose' },
    ];

    async function render(containerEl, member) {
        // 1) 기본 shortcut HTML
        const kakaoLink = member.kakao_link || null;
        const baseButtons = SHORTCUTS.map(s => {
            const href = s.url || kakaoLink;
            const disabled = !href;
            const disabledClass = disabled ? ' shortcut-btn--disabled' : '';
            const disabledAttr = disabled ? ' disabled aria-disabled="true"' : '';
            const colorClass = ` shortcut-btn--${s.color}`;

            if (disabled) {
                const hint = s.key === 'kakao' ? '<span class="shortcut-hint">조별 카톡방 링크는 목요일 중에 오픈됩니다</span>' : '';
                return `<button class="shortcut-btn${disabledClass}" type="button"${disabledAttr}>
                    <span class="shortcut-label">${App.esc(s.label)}</span>
                </button>${hint}`;
            }
            return `<a class="shortcut-btn${colorClass}" href="${App.esc(href)}" target="_blank" rel="noopener noreferrer">
                <span class="shortcut-label">${App.esc(s.label)}</span>
                <span class="shortcut-arrow">&#8250;</span>
            </a>`;
        }).join('');

        containerEl.innerHTML = `
            <div class="shortcuts-card">
                <div class="shortcuts-title">바로가기</div>
                <div class="shortcuts-list" id="shortcuts-list-inner">${baseButtons}</div>
            </div>
        `;

        // 2) 후기 카드 조건부 prepend (서버 상태에 따라)
        try {
            const r = await App.get(API + 'my_review_status');
            if (!r.success) return;
            const anyEnabled = (r.cafe?.enabled || r.blog?.enabled);
            if (!r.eligible || !anyEnabled) return;

            const reviewBtn = `<button class="shortcut-btn shortcut-btn--amber" id="shortcut-review-submit" type="button">
                <span class="shortcut-label">후기 작성하기 (+5 코인/편)</span>
                <span class="shortcut-arrow">&#8250;</span>
            </button>`;
            const inner = document.getElementById('shortcuts-list-inner');
            if (inner) inner.insertAdjacentHTML('afterbegin', reviewBtn);

            const btn = document.getElementById('shortcut-review-submit');
            if (btn) btn.addEventListener('click', () => MemberApp.openReviewSubmit());
        } catch (_e) { /* 네트워크/권한 에러는 조용히 무시 — 후기 카드만 안 보임 */ }
    }

    return { render };
})();
```

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/member-shortcuts.js && git commit -m "feat: member-shortcuts에 후기 작성 카드 조건부 추가"
```

---

### Task 6: 회원 UI — `member.js`에 `openReviewSubmit` 추가

**Files:**
- Modify: `/root/boot-dev/public_html/js/member.js` (현재 line ~191 `openCoinHistory` 근처)

**목적:** `MemberApp` 모듈에 후기 화면 진입/복귀 함수 추가. 기존 `openCoinHistory` 패턴과 동일.

- [ ] **Step 1: `showDashboard` HTML에 후기 영역 `<div>` 추가**

`member.js`의 `showDashboard()` 함수(line ~155) innerHTML에서 기존 `<div id="member-coin-history-area" style="display:none"></div>` 바로 밑에 한 줄 추가:

```html
                <div id="member-coin-history-area" style="display:none"></div>
                <div id="member-review-submit-area" style="display:none"></div>
```

- [ ] **Step 2: `openReviewSubmit` / `closeReviewSubmit` 함수 추가**

`closeCoinHistory` 함수 직후 추가:

```javascript
    function openReviewSubmit() {
        const dashboardContent = root.querySelector('.member-content');
        const area = document.getElementById('member-review-submit-area');
        if (!area) return;
        dashboardContent.style.display = 'none';
        area.style.display = '';
        window.scrollTo({ top: 0, behavior: 'instant' });
        MemberReview.render(area, closeReviewSubmit);
    }

    function closeReviewSubmit() {
        const dashboardContent = root.querySelector('.member-content');
        const area = document.getElementById('member-review-submit-area');
        if (!area) return;
        area.style.display = 'none';
        area.innerHTML = '';
        dashboardContent.style.display = '';
        window.scrollTo({ top: 0, behavior: 'instant' });
        // 후기 제출/취소 후 대시보드 복귀 시 프로필(코인 값) 최신화 — check_session 재호출
        if (typeof MemberApp !== 'undefined' && typeof MemberApp.refreshMember === 'function') {
            MemberApp.refreshMember();
        }
    }
```

- [ ] **Step 3: return에 export 추가**

`member.js` 말미 `return { init, openCoinHistory, closeCoinHistory };` 를 교체:

```javascript
    return { init, openCoinHistory, closeCoinHistory, openReviewSubmit, closeReviewSubmit, refreshMember };
```

- [ ] **Step 4: `refreshMember` 구현 추가**

`closeReviewSubmit` 함수 다음에 추가. 현재 member 변수가 module closure의 `let member`이므로 check_session 재호출 후 stat card 업데이트:

```javascript
    async function refreshMember() {
        try {
            const r = await App.post('/api/member.php?action=check_session');
            if (r.success && r.member) {
                member = r.member;
                const homeArea = document.getElementById('member-home-area');
                if (homeArea) MemberHome.render(homeArea, member);
            }
        } catch (_e) { /* 실패해도 UI 유지 */ }
    }
```

(member.js 내 `member` 변수가 closure에서 재할당 가능한 `let`인지 확인 — 만약 `const`면 `member = r.member` 대신 `Object.assign(member, r.member)` 로 수정.)

- [ ] **Step 5: 구문 검증 (headless)**

```bash
cd /root/boot-dev && node -e "new Function(require('fs').readFileSync('public_html/js/member.js','utf8')); console.log('OK')"
```

Expected: `OK`.

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/member.js && git commit -m "feat: MemberApp에 후기 작성 화면 진입/복귀 + refreshMember API"
```

---

### Task 7: 회원 UI — `member-review.js` 모듈 생성

**Files:**
- Create: `/root/boot-dev/public_html/js/member-review.js`

- [ ] **Step 1: 모듈 생성**

```javascript
/* ══════════════════════════════════════════════════════════════
   MemberReview — /후기작성 상세 화면
   바로가기의 "후기 작성하기" 카드를 탭하면 진입, "← 뒤로"로 복귀.
   스펙: docs/superpowers/specs/2026-04-23-review-submission-design.md
   ══════════════════════════════════════════════════════════════ */
const MemberReview = (() => {
    const API = '/api/bootcamp.php?action=';

    async function render(root, onBack) {
        root.innerHTML = `
            <div class="review-submit-page">
                <div class="review-submit-header">
                    <button class="review-submit-back" id="review-submit-back-btn">← 뒤로</button>
                    <div class="review-submit-title">후기 작성하기</div>
                </div>
                <div class="review-submit-body" id="review-submit-body">
                    <div class="review-submit-loading">불러오는 중…</div>
                </div>
            </div>
        `;
        document.getElementById('review-submit-back-btn').onclick = onBack;

        await load();
    }

    async function load() {
        const body = document.getElementById('review-submit-body');
        const r = await App.get(API + 'my_review_status');
        if (!r.success) {
            body.innerHTML = '<div class="review-submit-empty">정보를 불러오지 못했습니다.</div>';
            return;
        }

        if (!r.eligible) {
            const msg = {
                no_active_cycle: '현재 진행 중인 기수가 없습니다.',
                cohort_mismatch: '이번 기수 후기 접수 대상이 아닙니다.',
                member_inactive: '현재 후기 제출이 불가능한 상태입니다.',
            }[r.ineligible_reason] || '후기 제출이 불가능합니다.';
            body.innerHTML = `<div class="review-submit-empty">${App.esc(msg)}</div>`;
            return;
        }

        body.innerHTML = renderSection('cafe', '카페 후기', r.cafe) + renderSection('blog', '블로그 후기', r.blog);
        attachHandlers();
    }

    function renderSection(type, title, data) {
        if (!data.enabled) {
            return `
                <div class="review-section review-section-${type}">
                    <div class="review-section-head">
                        <div class="review-section-title">${App.esc(title)}</div>
                        <div class="review-section-reward">+5 코인</div>
                    </div>
                    <div class="review-section-disabled">현재 접수 중이 아닙니다.</div>
                </div>
            `;
        }

        // 가이드 마크다운 렌더 — MemberHome.renderMarkdown 재사용
        const guideHtml = (typeof MemberHome !== 'undefined' && typeof MemberHome.renderMarkdown === 'function')
            ? MemberHome.renderMarkdown(data.guide || '')
            : `<pre>${App.esc(data.guide || '')}</pre>`;

        if (data.submitted) {
            const d = formatDate(data.submitted.submitted_at);
            return `
                <div class="review-section review-section-${type}">
                    <div class="review-section-head">
                        <div class="review-section-title">${App.esc(title)}</div>
                        <div class="review-section-reward">+${data.submitted.coin_amount} 코인 적립</div>
                    </div>
                    <details class="review-guide">
                        <summary>작성 가이드</summary>
                        <div class="review-guide-body">${guideHtml}</div>
                    </details>
                    <div class="review-submitted">
                        <div class="review-submitted-badge">✓ 제출 완료 · ${App.esc(d)}</div>
                        <a class="review-submitted-url" href="${App.esc(data.submitted.url)}" target="_blank" rel="noopener noreferrer">${App.esc(data.submitted.url)}</a>
                    </div>
                </div>
            `;
        }

        return `
            <div class="review-section review-section-${type}">
                <div class="review-section-head">
                    <div class="review-section-title">${App.esc(title)}</div>
                    <div class="review-section-reward">+5 코인</div>
                </div>
                <details class="review-guide" open>
                    <summary>작성 가이드</summary>
                    <div class="review-guide-body">${guideHtml}</div>
                </details>
                <div class="review-form">
                    <label class="review-form-label" for="review-url-${type}">${App.esc(title)} 링크</label>
                    <input type="url" class="review-form-input" id="review-url-${type}"
                           placeholder="https://..." maxlength="500" autocomplete="off">
                    <button class="btn btn-primary review-form-submit" data-type="${type}">제출하기</button>
                </div>
            </div>
        `;
    }

    function attachHandlers() {
        document.querySelectorAll('.review-form-submit[data-type]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const type = btn.dataset.type;
                const input = document.getElementById(`review-url-${type}`);
                const url = (input?.value || '').trim();
                if (!/^https?:\/\//i.test(url) || url.length < 10 || url.length > 500) {
                    Toast.error('URL 형식이 올바르지 않습니다 (https://로 시작, 10~500자).');
                    return;
                }

                btn.disabled = true;
                btn.textContent = '제출 중…';
                try {
                    const r = await App.post(API + 'submit_review', { type, url });
                    if (r.success) {
                        Toast.success(r.message);
                        await load();
                    } else {
                        Toast.error(r.error || r.message || '제출에 실패했습니다.');
                        btn.disabled = false;
                        btn.textContent = '제출하기';
                    }
                } catch (_e) {
                    Toast.error('네트워크 오류');
                    btn.disabled = false;
                    btn.textContent = '제출하기';
                }
            });
        });
    }

    function formatDate(ts) {
        // "2026-04-22 14:30:00" → "4/22"
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ts || '');
        if (!m) return ts || '';
        return `${parseInt(m[2])}/${parseInt(m[3])}`;
    }

    return { render };
})();
```

- [ ] **Step 2: 구문 검증**

```bash
cd /root/boot-dev && node -e "new Function(require('fs').readFileSync('public_html/js/member-review.js','utf8')); console.log('OK')"
```

Expected: `OK`.

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/member-review.js && git commit -m "feat: member-review 모듈 — 후기 작성 화면"
```

---

### Task 8: 회원 UI — `index.php` script include + renderMarkdown export

**Files:**
- Modify: `/root/boot-dev/public_html/index.php` (회원 화면 진입점)
- Modify: `/root/boot-dev/public_html/js/member-home.js` (renderMarkdown을 모듈에서 노출)

- [ ] **Step 1: `member-home.js`의 renderMarkdown을 외부에서 호출 가능하도록 export**

`member-home.js` 말미의 `return { render };` 를 교체:

```javascript
    return { render, renderMarkdown };
```

- [ ] **Step 2: 구문 검증**

```bash
cd /root/boot-dev && node -e "new Function(require('fs').readFileSync('public_html/js/member-home.js','utf8')); console.log('OK')"
```

Expected: `OK`.

- [ ] **Step 3: `index.php`에 member-review.js 추가**

`grep -n "member-coin-history.js\|member.js\|member-shortcuts.js" public_html/index.php` 로 현재 script 나열 위치 확인 후, `member-coin-history.js` 다음 줄에 추가:

```html
    <script src="/js/member-review.js<?= v('/js/member-review.js') ?>"></script>
```

(만약 `v()` 캐시버스터 사용 중인지 한번 확인 — `public_html/operation/index.php`와 동일 패턴.)

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev && git add public_html/index.php public_html/js/member-home.js && git commit -m "feat: member-review.js 번들 포함 + renderMarkdown export"
```

---

### Task 9: 회원 UI — CSS 스타일

**Files:**
- Modify: `/root/boot-dev/public_html/css/bootcamp.css` (또는 같은 규모의 회원 화면용 CSS — 기존 `coin-history-page` 클래스가 어느 파일에 있는지 확인 후 그 파일에 추가)

- [ ] **Step 1: 현재 `coin-history-page` 스타일 위치 확인**

```bash
grep -l "coin-history-page\|coin-cycle-card" /root/boot-dev/public_html/css/*.css
```

Expected: 대상 파일 1개 (예: `bootcamp.css`).

- [ ] **Step 2: 후기 화면용 CSS 블록 추가**

확인된 파일 하단에 추가:

```css
/* ══════════════════════════════════════════════════════════════
   후기 작성 화면 (MemberReview)
   ══════════════════════════════════════════════════════════════ */
.review-submit-page { padding: 16px; max-width: 720px; margin: 0 auto; }
.review-submit-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.review-submit-back {
    background: none; border: none; color: #555; font-size: 15px; cursor: pointer; padding: 4px 8px;
}
.review-submit-title { font-size: 18px; font-weight: 700; color: #222; }
.review-submit-loading,
.review-submit-empty {
    padding: 40px 16px; text-align: center; color: #888; background: #fff;
    border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.review-section {
    background: #fff; border-radius: 12px; padding: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06); margin-bottom: 16px;
}
.review-section-head {
    display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;
}
.review-section-title { font-size: 16px; font-weight: 700; color: #222; }
.review-section-reward {
    color: #D97706; font-weight: 700; font-size: 14px;
    background: #FEF3C7; padding: 4px 10px; border-radius: 999px;
}
.review-section-disabled {
    color: #888; text-align: center; padding: 24px 0; font-size: 14px;
}

.review-guide { margin-bottom: 12px; }
.review-guide summary {
    cursor: pointer; color: #555; font-size: 14px; font-weight: 600; padding: 6px 0;
}
.review-guide-body {
    margin-top: 8px; padding: 12px; background: #F9FAFB; border-radius: 8px;
    font-size: 14px; line-height: 1.6; color: #333;
}
.review-guide-body h1, .review-guide-body h2, .review-guide-body h3 { margin: 8px 0 6px; font-size: 15px; }
.review-guide-body p { margin: 6px 0; }
.review-guide-body ul, .review-guide-body ol { padding-left: 20px; margin: 6px 0; }
.review-guide-body code {
    background: #E5E7EB; padding: 1px 5px; border-radius: 4px; font-size: 13px;
}

.review-form { margin-top: 12px; }
.review-form-label { display: block; font-size: 14px; color: #444; margin-bottom: 6px; }
.review-form-input {
    width: 100%; padding: 10px 12px; border: 1px solid #D1D5DB;
    border-radius: 8px; font-size: 14px; box-sizing: border-box; margin-bottom: 10px;
}
.review-form-input:focus { outline: none; border-color: #2563EB; }
.review-form-submit { width: 100%; }

.review-submitted {
    margin-top: 8px; padding: 12px; background: #ECFDF5; border-radius: 8px;
}
.review-submitted-badge { color: #065F46; font-weight: 700; font-size: 14px; margin-bottom: 6px; }
.review-submitted-url {
    display: block; color: #2563EB; font-size: 13px; word-break: break-all;
    text-decoration: underline;
}
```

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add public_html/css/ && git commit -m "style: 후기 작성 화면 CSS"
```

---

### Task 10: 운영 UI — `admin-reviews.js` 모듈 생성

**Files:**
- Create: `/root/boot-dev/public_html/js/admin-reviews.js`

- [ ] **Step 1: 모듈 생성**

```javascript
/* ══════════════════════════════════════════════════════════════
   AdminReviews — /operation 후기 관리 탭
   회원 후기 제출 목록 조회 + 취소(사유 입력) 처리.
   스펙: docs/superpowers/specs/2026-04-23-review-submission-design.md
   ══════════════════════════════════════════════════════════════ */
const AdminReviews = (() => {
    const API = '/api/bootcamp.php?action=';

    let state = {
        cycles: [],
        cycleId: 0,
        type: 'all',
        status: 'active',
        q: '',
    };

    async function init(container) {
        container.innerHTML = `
            <div class="admin-reviews-page">
                <div class="admin-reviews-filters">
                    <label>Cycle:
                        <select id="ar-cycle"></select>
                    </label>
                    <label>타입:
                        <select id="ar-type">
                            <option value="all">전체</option>
                            <option value="cafe">카페</option>
                            <option value="blog">블로그</option>
                        </select>
                    </label>
                    <label>상태:
                        <select id="ar-status">
                            <option value="active">활성</option>
                            <option value="cancelled">취소됨</option>
                            <option value="all">전체</option>
                        </select>
                    </label>
                    <label>닉네임: <input type="text" id="ar-q" placeholder="검색"></label>
                    <button class="btn btn-secondary" id="ar-reload">조회</button>
                </div>
                <div class="admin-reviews-counts" id="ar-counts"></div>
                <div class="admin-reviews-list" id="ar-list"></div>
            </div>
        `;

        // cycles 로드
        const cr = await App.get(API + 'coin_cycles');
        state.cycles = (cr.cycles || []).slice(0, 10);
        const active = state.cycles.find(c => c.status === 'active') || state.cycles[0];
        state.cycleId = active ? parseInt(active.id) : 0;

        const sel = document.getElementById('ar-cycle');
        sel.innerHTML = state.cycles.map(c =>
            `<option value="${c.id}" ${parseInt(c.id) === state.cycleId ? 'selected' : ''}>${App.esc(c.name)}${c.status === 'active' ? ' (active)' : ''}</option>`
        ).join('');

        // 이벤트
        sel.onchange = () => { state.cycleId = parseInt(sel.value); load(); };
        document.getElementById('ar-type').onchange = (e) => { state.type = e.target.value; load(); };
        document.getElementById('ar-status').onchange = (e) => { state.status = e.target.value; load(); };
        document.getElementById('ar-reload').onclick = () => {
            state.q = document.getElementById('ar-q').value.trim();
            load();
        };

        await load();
    }

    async function load() {
        const list = document.getElementById('ar-list');
        const counts = document.getElementById('ar-counts');
        list.innerHTML = '<div class="empty-state">조회 중…</div>';

        const params = new URLSearchParams({
            cycle_id: String(state.cycleId),
            type: state.type,
            status: state.status,
        });
        if (state.q) params.set('q', state.q);

        const r = await App.get(API + 'reviews_list&' + params.toString());
        if (!r.success) {
            list.innerHTML = '<div class="empty-state">조회 실패</div>';
            counts.innerHTML = '';
            return;
        }

        counts.innerHTML = `전체 ${r.counts.total} · 활성 ${r.counts.active} · 취소 ${r.counts.cancelled}`;

        if (!r.items.length) {
            list.innerHTML = '<div class="empty-state">제출된 후기가 없습니다.</div>';
            return;
        }

        list.innerHTML = `
            <table class="admin-reviews-table">
                <thead>
                    <tr>
                        <th>제출일</th><th>조</th><th>닉네임</th><th>타입</th>
                        <th>URL</th><th>코인</th><th>상태</th><th></th>
                    </tr>
                </thead>
                <tbody>${r.items.map(renderRow).join('')}</tbody>
            </table>
        `;

        list.querySelectorAll('.ar-cancel-btn[data-id]').forEach(btn => {
            btn.addEventListener('click', () => showCancelModal(btn.dataset));
        });
    }

    function renderRow(item) {
        const typeLabel = item.type === 'cafe' ? '카페' : '블로그';
        const submitted = (item.submitted_at || '').slice(5, 16).replace('T', ' '); // MM-DD HH:MM
        const cancelled = !!item.cancelled_at;
        const statusCell = cancelled
            ? `<span class="ar-status ar-status-cancelled">취소됨</span>`
            : `<span class="ar-status ar-status-active">활성</span>`;
        const actionCell = cancelled
            ? ''
            : `<button class="btn btn-small btn-danger ar-cancel-btn"
                       data-id="${item.id}"
                       data-nickname="${App.esc(item.nickname || '')}"
                       data-type="${item.type}"
                       data-url="${App.esc(item.url || '')}"
                       data-submitted="${App.esc(submitted)}"
                       data-amount="${item.coin_amount}">취소</button>`;
        const cancelRow = cancelled
            ? `<tr class="ar-cancel-meta"><td colspan="8">└ 사유: "${App.esc(item.cancel_reason || '')}" · by ${App.esc(item.cancelled_by_name || '?')} · ${App.esc((item.cancelled_at || '').slice(5,16))}</td></tr>`
            : '';

        return `
            <tr class="${cancelled ? 'ar-row-cancelled' : ''}">
                <td>${App.esc(submitted)}</td>
                <td>${App.esc(item.group_name || '-')}</td>
                <td>${App.esc(item.nickname || '')}</td>
                <td>${typeLabel}</td>
                <td><a href="${App.esc(item.url)}" target="_blank" rel="noopener noreferrer">↗ 링크</a></td>
                <td>${item.coin_amount}</td>
                <td>${statusCell}</td>
                <td>${actionCell}</td>
            </tr>
            ${cancelRow}
        `;
    }

    function showCancelModal(ds) {
        App.openModal('후기 취소', `
            <div class="ar-cancel-modal">
                <div class="ar-cancel-meta-lines">
                    <div>닉네임: ${App.esc(ds.nickname)} · ${ds.type === 'cafe' ? '카페 후기' : '블로그 후기'}</div>
                    <div>제출: ${App.esc(ds.submitted)}</div>
                    <div>URL: <a href="${App.esc(ds.url)}" target="_blank" rel="noopener noreferrer">${App.esc(ds.url)}</a></div>
                    <div class="ar-cancel-warn">※ 회원의 해당 cycle에서 ${ds.amount}코인이 차감됩니다.</div>
                </div>
                <label class="ar-cancel-label">취소 사유 (필수)</label>
                <textarea id="ar-cancel-reason" maxlength="255" rows="3" class="form-input"></textarea>
                <div class="ar-cancel-actions">
                    <button class="btn btn-secondary" id="ar-cancel-close">닫기</button>
                    <button class="btn btn-danger" id="ar-cancel-confirm">취소 처리</button>
                </div>
            </div>
        `);

        document.getElementById('ar-cancel-close').onclick = () => App.closeModal();
        document.getElementById('ar-cancel-confirm').onclick = async () => {
            const reason = document.getElementById('ar-cancel-reason').value.trim();
            if (!reason) { Toast.error('취소 사유를 입력해주세요.'); return; }
            App.showLoading();
            const r = await App.post(API + 'review_cancel', { id: parseInt(ds.id), cancel_reason: reason });
            App.hideLoading();
            if (r.success) {
                Toast.success(r.message);
                App.closeModal();
                await load();
            } else {
                Toast.error(r.error || r.message || '취소 실패');
            }
        };
    }

    return { init };
})();
```

- [ ] **Step 2: 구문 검증**

```bash
cd /root/boot-dev && node -e "new Function(require('fs').readFileSync('public_html/js/admin-reviews.js','utf8')); console.log('OK')"
```

Expected: `OK`.

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/admin-reviews.js && git commit -m "feat: admin-reviews 모듈 — 후기 관리 탭"
```

---

### Task 11: 운영 UI — `admin.js` 탭 등록 + `operation/index.php` include

**Files:**
- Modify: `/root/boot-dev/public_html/js/admin.js` (operation role의 탭 버튼/콘텐츠 배열 + lazy load hook)
- Modify: `/root/boot-dev/public_html/operation/index.php` (script 태그)

- [ ] **Step 1: 탭 버튼 추가**

`admin.js` line ~193 `<button class="tab" data-tab="#bc-tab-study" data-hash="study">복습스터디</button>` 다음 줄에 추가:

```html
                            <button class="tab" data-tab="#tab-reviews" data-hash="reviews">후기</button>
```

- [ ] **Step 2: 탭 콘텐츠 영역 추가**

같은 파일 line ~213 `<div class="tab-content" id="bc-tab-study"></div>` 다음 줄에 추가:

```html
                        <div class="tab-content" id="tab-reviews"></div>
```

- [ ] **Step 3: lazy load hook 추가**

`admin.js` line ~420 근처, 기존 "Lecture 탭 lazy load" 블록 **다음에** 새 블록 추가:

```javascript
            // Reviews 탭 lazy load
            if (typeof AdminReviews !== 'undefined') {
                const revTab = document.getElementById('tab-reviews');
                if (revTab) {
                    const revObserver = new MutationObserver(() => {
                        if (revTab.classList.contains('active') && !revTab.dataset.loaded) {
                            revTab.dataset.loaded = '1';
                            AdminReviews.init(revTab);
                        }
                    });
                    revObserver.observe(revTab, { attributes: true, attributeFilter: ['class'] });
                }
            }
```

- [ ] **Step 4: `operation/index.php`에 script 추가**

`operation/index.php`의 script 나열 마지막(`bulk-register.js` 근처) 다음에 추가:

```html
    <script src="/js/admin-reviews.js<?= v('/js/admin-reviews.js') ?>"></script>
```

- [ ] **Step 5: 구문 검증**

```bash
cd /root/boot-dev && node -e "new Function(require('fs').readFileSync('public_html/js/admin.js','utf8')); console.log('OK')"
cd /root/boot-dev && php -l public_html/operation/index.php
```

Expected: 둘 다 OK / No syntax errors.

- [ ] **Step 6: CSS 추가 (admin-reviews 스타일)**

`grep -l "admin-issues\|admin-tabs" public_html/css/*.css` 로 admin CSS 파일 확인(예: `admin.css`). 해당 파일 말미에 추가:

```css
/* ══════════════════════════════════════════════════════════════
   후기 관리 탭 (AdminReviews)
   ══════════════════════════════════════════════════════════════ */
.admin-reviews-page { padding: 12px; }
.admin-reviews-filters {
    display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 12px;
}
.admin-reviews-filters label { font-size: 13px; color: #444; }
.admin-reviews-filters select,
.admin-reviews-filters input { padding: 4px 8px; font-size: 13px; }
.admin-reviews-counts { font-size: 13px; color: #555; margin-bottom: 8px; }
.admin-reviews-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-reviews-table th,
.admin-reviews-table td { padding: 8px 6px; border-bottom: 1px solid #eee; text-align: left; }
.admin-reviews-table th { background: #F9FAFB; font-weight: 600; color: #555; }
.admin-reviews-table a { color: #2563EB; }
.ar-row-cancelled { background: #FAFAFA; color: #888; }
.ar-row-cancelled a { color: #888; }
.ar-cancel-meta td { padding-left: 16px; font-size: 12px; color: #888; font-style: italic; }
.ar-status-active { color: #059669; font-weight: 600; }
.ar-status-cancelled { color: #9CA3AF; font-weight: 600; }
.ar-cancel-modal { padding: 8px 4px; }
.ar-cancel-meta-lines { font-size: 13px; color: #444; margin-bottom: 12px; }
.ar-cancel-meta-lines > div { margin-bottom: 4px; }
.ar-cancel-warn { color: #B91C1C; margin-top: 6px !important; font-weight: 600; }
.ar-cancel-label { display: block; font-size: 13px; margin-top: 8px; margin-bottom: 4px; }
.ar-cancel-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px; }
```

- [ ] **Step 7: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/admin.js public_html/operation/index.php public_html/css/ && git commit -m "feat: /operation에 후기 관리 탭 등록 + lazy load + 스타일"
```

---

### Task 12: 회귀 테스트 (수동)

테스트는 DEV 환경(`dev-boot.soritune.com`)에서 수행. 사전에 테스트 회원 1명(12기, 활성) + 운영자 1명 로그인 가능해야 함.

- [ ] **Step 1: 정상 플로우 — 카페 후기 제출**

1. 12기 회원으로 로그인 → 대시보드에 "후기 작성하기 (+5 코인/편)" 바로가기 카드 노출 확인.
2. 카드 클릭 → "후기 작성하기" 화면 진입, 카페/블로그 두 섹션 모두 표시, 가이드 마크다운 렌더 확인.
3. 카페 URL(유효) 입력 → "제출하기" → Toast `"+5 코인이 적립되었습니다."` + 해당 섹션이 "✓ 제출 완료" 상태로 재렌더.
4. "← 뒤로" → 대시보드 복귀 + 코인 stat 카드 +5 반영 확인.
5. DB: `SELECT * FROM review_submissions WHERE member_id={X};`에서 row 1개 + `coin_amount=5`.
6. DB: `SELECT * FROM coin_logs WHERE member_id={X} ORDER BY id DESC LIMIT 1;`에서 `reason_type='review_cafe'`, `coin_change=5`.

- [ ] **Step 2: 중복 제출 차단**

1. 같은 회원이 같은 cycle에서 카페 후기 재제출 시도 → 제출 완료 상태라 입력폼 자체가 없어야 함.
2. (API 직접) `curl -X POST /api/bootcamp.php?action=submit_review -d '{"type":"cafe","url":"https://..."}' -b <cookie>` → `{"success":false,"error":"이미 제출하셨습니다."}`.

- [ ] **Step 3: 기수 전환 재개방 (조건부 — 전환 환경 없으면 skip)**

DEV DB에서 임시로 현재 active cycle `status='closed'` 변경 + 새 cycle을 `status='active'`로 만든 후 확인. 복원 주의.
1. 같은 회원으로 카페 후기 재제출 → 성공 (새 cycle id로 row 생성).
2. 테스트 후 DB 원복.

- [ ] **Step 4: Eligibility 케이스**

1. 11기 단독 참여 회원(`cohort_id=11`)으로 로그인 → 바로가기 카드 숨김. (`/api/bootcamp.php?action=my_review_status` 호출 시 `eligible=false`, `ineligible_reason='cohort_mismatch'`.)
2. `member_status='refunded'` 회원으로 테스트 → 바로가기 카드 숨김, API `ineligible_reason='member_inactive'`.

- [ ] **Step 5: Cap 엣지 케이스**

```sql
UPDATE member_cycle_coins SET earned_coin = 199 WHERE member_id = {X} AND cycle_id = {active};
```
1. 카페 후기 제출 → Toast `"이번 기수 최대치에 가까워 1코인만 적립되었습니다."` + DB `review_submissions.coin_amount = 1`.
2. 운영 탭에서 이 건 취소 → 코인 `-1` 차감 확인 (not -5).
3. DB 원복:
   ```sql
   UPDATE member_cycle_coins SET earned_coin = {원래값} WHERE member_id = {X} AND cycle_id = {active};
   ```

- [ ] **Step 6: Cap fully saturated**

```sql
UPDATE member_cycle_coins SET earned_coin = 200 WHERE member_id = {X} AND cycle_id = {active};
```
1. 제출 시도 → Toast `"이번 기수 코인이 이미 최대치에 도달하여 후기 제출을 처리할 수 없습니다."`.
2. DB: `review_submissions`에 row **없음** 확인 (rollback). `coin_logs`에도 새 기록 없음.
3. DB 원복.

- [ ] **Step 7: 토글 off**

```sql
UPDATE system_contents SET content_markdown='off' WHERE content_key='review_cafe_enabled';
```
1. 회원 화면: 카페 섹션이 "현재 접수 중이 아닙니다" 만 표시. 블로그 섹션은 정상.
2. 양쪽 모두 off →  바로가기 카드 자체 숨김.
3. 원복: `UPDATE system_contents SET content_markdown='on' WHERE content_key IN ('review_cafe_enabled','review_blog_enabled');`.

- [ ] **Step 8: 운영 목록 조회 + 취소**

1. 운영자로 `/operation` → "후기" 탭 클릭 → 현재 cycle 기본 선택, 활성 제출 리스트 출력.
2. 상단 카운트 "전체 N · 활성 M · 취소 K" 확인. 상태 필터를 "활성"에서 "취소됨"으로 바꿔도 카운트는 동일(전체 기준) 유지.
3. 활성 건 "취소" 버튼 → 모달 → 사유 미입력 확인 버튼 눌러 에러 토스트 확인.
4. 사유 입력 후 취소 → Toast + 목록 갱신, 해당 행 "취소됨" + 2번째 줄 사유/취소자/시각 표시.
5. 회원 쪽 코인 내역 화면에서 "카페 후기 (취소) -5" (또는 `-coin_amount`) 보이는지 확인.

- [ ] **Step 9: 권한**

1. 비로그인으로 `curl .../api/bootcamp.php?action=my_review_status` → 401/에러.
2. 일반 회원 세션으로 `curl .../api/bootcamp.php?action=reviews_list` → 관리자 권한 에러.
3. 일반 관리자(operation 등) 외 역할(있다면 leader)로 reviews_list → 관리자 권한 에러.

- [ ] **Step 10: 최종 점검 + 푸시**

```bash
cd /root/boot-dev && git log --oneline -15
cd /root/boot-dev && git status
```

git status clean, 모든 커밋 누적 확인. 마지막으로:

```bash
cd /root/boot-dev && git push origin dev
```

**⛔ 여기서 작업 정지. 사용자에게 DEV 확인 요청.** 사용자가 `dev-boot.soritune.com`에서 직접 회귀한 뒤 "운영 반영해줘"라고 명시적으로 요청할 때까지 main 머지/prod pull 금지.

---

### Task 13 (사용자 요청 시에만 실행): 운영 반영

사용자 승인 후 실행.

- [ ] **Step 1: main 머지 + push**

```bash
cd /root/boot-dev && git checkout main && git merge dev && git push origin main && git checkout dev
```

- [ ] **Step 2: PROD 백업**

```bash
cd /root/boot-prod && mysqldump --defaults-extra-file=<(awk -F= '/^DB_(USER|PASS|NAME)/{gsub(/"/,"",$2); print tolower($1)"="$2}' .db_credentials | sed 's/db_name/databases/') $(grep DB_NAME .db_credentials | cut -d= -f2) > /tmp/boot-prod-backup-$(date +%Y%m%d-%H%M%S).sql
```

(실제로는 사용자와 함께 실행. `.db_credentials` 포맷 확인 후 `mysqldump` 명령을 맞춤.)

- [ ] **Step 3: PROD pull**

```bash
cd /root/boot-prod && git pull origin main
```

- [ ] **Step 4: PROD 마이그 dry-run + 실제 실행**

```bash
cd /root/boot-prod && php migrate_review_submissions.php --dry-run
```

결과 확인 후:

```bash
cd /root/boot-prod && php migrate_review_submissions.php
```

- [ ] **Step 5: PROD 가이드 문구 주입**

운영팀이 실제 해시태그/카페 링크로 `system_contents.review_cafe_guide` / `review_blog_guide` 를 업데이트. DEV에서 문구 완성 후 dump해서 PROD에 UPDATE.

- [ ] **Step 6: PROD 스모크 테스트**

운영 계정 1명·실제 12기 회원 1명으로 Task 12의 Step 1, 2, 8 핵심 플로우만 반복 확인.

---

## 자가 점검 체크리스트

구현자가 모든 task 완료 후 commit 전에 스스로 체크:

- [ ] 스펙 섹션 3.1의 "기수당 1회" 정책이 API와 인덱스에 반영됐나?
- [ ] 스펙 섹션 3.4의 `applied === 0` rollback이 구현됐나?
- [ ] 스펙 섹션 5.3의 counts와 items 필터 정책 차이가 반영됐나?
- [ ] `coin_amount`가 항상 "실제 적립액"으로 저장되나?
- [ ] 취소 시 `-coin_amount`(not -5)로 차감되나?
- [ ] 가이드 마크다운이 DB에서 읽혀 렌더되나? 하드코딩 아님?
- [ ] 모든 커밋이 dev 브랜치에 있고, main에 직접 커밋된 게 없나?
- [ ] PHP/JS 구문 오류 없나? (`php -l`, `node -e "new Function(...)"`)
