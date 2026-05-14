# 다회권 확인 (multipass management) 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** boot.soritune.com 운영팀이 11~13기 같은 다회권 회원의 보유 권리·수강 기수·쿠폰 발급 현황을 한 화면에서 검색·관리할 수 있는 `/operation` 신규 탭과 데이터 모델을 만든다.

**Architecture:** `multipass` + `multipass_cohorts` 두 테이블에 권리 메타만 저장하고, 수강 여부는 매 조회 시 `bootcamp_members` EXISTS 로 derive 한다. PHP 백엔드는 `includes/multipass/{repo, csv_parser, bulk}` 헬퍼 3개로 책임 분리하고 admin.php 에 9 액션을 추가한다. 프론트엔드는 `js/admin-multipass.js` IIFE 한 파일에서 회원별/상품별 sub-탭 + 추가 모달 + CSV 일괄 모달을 구현한다.

**Tech Stack:** PHP 8.x + MariaDB 10.5 + PDO, vanilla JS (boot 기존 IIFE 패턴), `xlsx.full.min.js` (이미 로드됨), boot 기존 테스트 패턴 (CLI `php tests/<name>_test.php`). 신규 의존성 0.

**Spec:** `docs/superpowers/specs/2026-05-13-multipass-management-design.md`

---

## File Structure

신규:
- `migrate_multipass.php` — 두 테이블 CREATE TABLE IF NOT EXISTS (멱등)
- `public_html/includes/multipass/multipass_repo.php` — DB 액세스 (`findPasses`, `searchMembers`, `createPass`, `updatePass`, `deletePass`, `toggleCoupon`, `decorateCohorts`)
- `public_html/includes/multipass/multipass_csv_parser.php` — CSV/엑셀 paste → `[{row, user_id, product_name, cohort_labels, errors}]`
- `public_html/includes/multipass/multipass_bulk.php` — `validateBulk`, `applyBulk`
- `public_html/js/admin-multipass.js` — `AdminMultipassApp` IIFE
- `tests/multipass_csv_parser_test.php` — CSV 파서 unit
- `tests/multipass_repo_test.php` — DEV DB 통합 (CRUD + 토글 + UNIQUE/CASCADE + has_member_row/joined)
- `tests/multipass_api_test.php` — DEV DB 통합 (HTTP API 9 액션 + 권한 거부)
- `tests/multipass_invariants.php` — PROD smoke (orphan/중복/coupon at-by 일관성)

수정:
- `public_html/api/admin.php` — `multipass_*` 9 case 추가
- `public_html/operation/index.php` — `<script src="/js/admin-multipass.js">` 추가
- `public_html/js/admin.js` — operation 탭 목록에 `다회권 확인` 버튼/content div + 탭 lazy load 분기
- `public_html/css/admin.css` — 카드/배지/체크박스 (필요 최소)

영향 없음:
- `bootcamp_members`, `cohorts` 등 기존 스키마 — 변경 0
- 다른 admin.php 액션 — 변경 0

---

## Task 1: 마이그레이션 스크립트

**Files:**
- Create: `migrate_multipass.php`

- [ ] **Step 1: Create migration script**

Create `migrate_multipass.php`:

```php
<?php
/**
 * boot.soritune.com - Database Migration: Multipass (다회권)
 * - multipass: user_id 별 다회권 권리
 * - multipass_cohorts: 다회권 × 포함 기수 + 쿠폰 발급 상태
 *
 * Run once: php migrate_multipass.php  (DEV)
 *           php migrate_multipass.php  (PROD, 코드 push 전에 먼저 실행)
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== boot.soritune.com DB Migration: Multipass ===\n\n";

echo "[1] multipass 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS multipass (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       VARCHAR(100) NOT NULL  COMMENT '소리튠 아이디 (식별축, FK 안 검)',
    product_name  VARCHAR(100) NOT NULL  COMMENT '예: \"11~13기 묶음권\"',
    note          TEXT NULL              COMMENT '운영 메모',
    created_by    INT UNSIGNED NULL      COMMENT 'admins.id',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mp_user_id (user_id),
    KEY idx_mp_product (product_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

echo "\n[2] multipass_cohorts 테이블...\n";
$db->exec("
CREATE TABLE IF NOT EXISTS multipass_cohorts (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pass_id           INT UNSIGNED NOT NULL,
    cohort_id         INT UNSIGNED NOT NULL,
    coupon_issued     TINYINT(1) NOT NULL DEFAULT 0,
    coupon_issued_at  DATETIME NULL,
    coupon_issued_by  INT UNSIGNED NULL,
    note              VARCHAR(255) NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pass_cohort (pass_id, cohort_id),
    KEY idx_mpc_cohort (cohort_id),
    CONSTRAINT fk_mpc_pass   FOREIGN KEY (pass_id)   REFERENCES multipass(id) ON DELETE CASCADE,
    CONSTRAINT fk_mpc_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "  - 완료\n";

echo "\n[검증] SHOW CREATE TABLE multipass\n";
$row = $db->query('SHOW CREATE TABLE multipass')->fetch();
echo $row['Create Table'] . "\n";

echo "\n[검증] SHOW CREATE TABLE multipass_cohorts\n";
$row = $db->query('SHOW CREATE TABLE multipass_cohorts')->fetch();
echo $row['Create Table'] . "\n";

echo "\n=== 완료 ===\n";
```

- [ ] **Step 2: Run migration on DEV**

```bash
cd /root/boot-dev && php migrate_multipass.php
```

Expected: 두 테이블 생성 + SHOW CREATE TABLE 출력에서 FK/UNIQUE 표시 확인.

- [ ] **Step 3: Verify idempotency**

```bash
cd /root/boot-dev && php migrate_multipass.php
```

Expected: 같은 출력. 에러 없이 통과 (CREATE TABLE IF NOT EXISTS).

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && git add migrate_multipass.php && git commit -m "$(cat <<'EOF'
feat(multipass): DB 마이그 — multipass / multipass_cohorts 신설

다회권 권리 메타와 포함 기수 × 쿠폰 발급 상태를 저장할 두 테이블.
멱등 (CREATE TABLE IF NOT EXISTS).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: CSV 파서 (`multipass_csv_parser.php`)

**Files:**
- Create: `public_html/includes/multipass/multipass_csv_parser.php`
- Test: `tests/multipass_csv_parser_test.php`

- [ ] **Step 1: Write the failing test**

Create `tests/multipass_csv_parser_test.php`:

```php
<?php
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/includes/multipass/multipass_csv_parser.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// 1. 헤더 자동 감지 (3컬럼)
$r = parseMultipassCsv("user_id,product_name,cohorts\n3937726826@k,11~13기 묶음권,\"11,12,13\"");
t('header_detect',
    count($r['rows']) === 1
    && $r['rows'][0]['user_id'] === '3937726826@k'
    && $r['rows'][0]['product_name'] === '11~13기 묶음권'
    && $r['rows'][0]['cohort_labels'] === ['11', '12', '13']);

// 2. 헤더 한글 별칭
$r = parseMultipassCsv("아이디,상품명,기수\n4114325139@n,5~7기,\"5|6|7\"");
t('header_korean',
    count($r['rows']) === 1
    && $r['rows'][0]['cohort_labels'] === ['5', '6', '7']);

// 3. 헤더 없는 첫 행도 데이터로 처리 (감지 키워드 없음)
$r = parseMultipassCsv("3937726826@k,11~13기,\"11,12,13\"");
t('no_header',
    count($r['rows']) === 1
    && $r['rows'][0]['user_id'] === '3937726826@k');

// 4. BOM 제거
$r = parseMultipassCsv("\xEF\xBB\xBFuser_id,product_name,cohorts\n3937726826@k,X,11");
t('bom',
    count($r['rows']) === 1
    && $r['rows'][0]['user_id'] === '3937726826@k');

// 5. cohort 분리 — 쉼표/파이프/슬래시 혼용
$r = parseMultipassCsv("3937726826@k,X,\"11, 12 / 13|14\"");
t('cohort_split',
    count($r['rows']) === 1
    && $r['rows'][0]['cohort_labels'] === ['11', '12', '13', '14']);

// 6. cohort 라벨에 "기" 포함도 숫자만 추출
$r = parseMultipassCsv("3937726826@k,X,\"11기,12기,13기\"");
t('cohort_with_suffix',
    count($r['rows']) === 1
    && $r['rows'][0]['cohort_labels'] === ['11', '12', '13']);

// 7. cohort 라벨에 숫자 없음 → 그 라벨은 cohort_labels 에 빈 문자열로 보존 (검증 단계가 식별 실패 처리)
$r = parseMultipassCsv("3937726826@k,X,\"11,예비\"");
t('cohort_unparseable',
    count($r['rows']) === 1
    && $r['rows'][0]['cohort_labels'] === ['11', '예비']
    && $r['rows'][0]['cohort_raw'] === ['11', '예비']);

// 8. 빈 줄 무시
$r = parseMultipassCsv("\n3937726826@k,X,11\n\n4114325139@n,Y,12\n");
t('blank_lines',
    count($r['rows']) === 2);

// 9. 컬럼 수 부족
$r = parseMultipassCsv("3937726826@k,11~13기");
t('missing_col',
    count($r['rows']) === 0
    && count($r['errors']) === 1
    && $r['errors'][0]['reason'] === 'missing_columns');

// 10. RFC 4180 큰따옴표 + 쉼표
$r = parseMultipassCsv('3937726826@k,"X, with, comma","11,12"');
t('quoted_comma',
    count($r['rows']) === 1
    && $r['rows'][0]['product_name'] === 'X, with, comma'
    && $r['rows'][0]['cohort_labels'] === ['11', '12']);

// 11. 공백 trim
$r = parseMultipassCsv("  3937726826@k  ,  X  ,  11  ");
t('trim',
    count($r['rows']) === 1
    && $r['rows'][0]['user_id'] === '3937726826@k'
    && $r['rows'][0]['product_name'] === 'X');

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /root/boot-dev && php tests/multipass_csv_parser_test.php
```

Expected: `PHP Fatal error: ... Failed opening required ... multipass_csv_parser.php`

- [ ] **Step 3: Implement parser**

Create `public_html/includes/multipass/multipass_csv_parser.php`:

```php
<?php
/**
 * 다회권 CSV/엑셀 paste 파서.
 *
 * 입력: 텍스트 (CSV)
 * 출력: ['rows' => [{row, user_id, product_name, cohort_labels[], cohort_raw[]}], 'errors' => [{row, reason}]]
 *   - cohort_labels: 각 토큰에서 (\d+) 추출. 매칭 실패 시 원본 그대로.
 *   - cohort_raw: 분리만 한 원본 토큰 (검증 메시지에서 사용).
 */
declare(strict_types=1);

const MULTIPASS_HEADER_KEYWORDS = ['user_id', '아이디', 'product_name', '상품명', 'cohorts', '기수'];

function parseMultipassCsv(string $csv): array {
    // BOM 제거
    if (str_starts_with($csv, "\xEF\xBB\xBF")) {
        $csv = substr($csv, 3);
    }

    $rows = [];
    $errors = [];
    $rowNum = 0;
    $headerSeen = false;

    // RFC 4180 파싱 — fgetcsv 가 빠르고 표준
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);

    while (($cells = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $rowNum++;
        // 빈 행 (모든 셀 빈 문자열)
        if (count($cells) === 1 && trim((string)$cells[0]) === '') continue;
        // null 안전
        $cells = array_map(fn($c) => $c === null ? '' : trim((string)$c), $cells);

        // 컬럼 수 검증 (최소 3)
        if (count($cells) < 3) {
            $errors[] = ['row' => $rowNum, 'reason' => 'missing_columns'];
            continue;
        }

        // 첫 행이 헤더 키워드 포함하면 skip
        if (!$headerSeen) {
            $joined = mb_strtolower(implode(',', array_slice($cells, 0, 3)));
            foreach (MULTIPASS_HEADER_KEYWORDS as $kw) {
                if (str_contains($joined, mb_strtolower($kw))) {
                    $headerSeen = true;
                    continue 2;  // continue while loop
                }
            }
            $headerSeen = true;  // 첫 행 처리 완료, 다음 행부터는 무조건 데이터
        }

        [$userId, $productName, $cohortsStr] = [$cells[0], $cells[1], $cells[2]];

        // cohort 분리 (쉼표/파이프/슬래시), 빈 토큰 제거
        $tokens = preg_split('#[,|/]#', $cohortsStr) ?: [];
        $cohortRaw = [];
        $cohortLabels = [];
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;
            $cohortRaw[] = $tok;
            // (\d+) 추출
            if (preg_match('/(\d+)/', $tok, $m)) {
                $cohortLabels[] = $m[1];
            } else {
                $cohortLabels[] = $tok;  // 식별 실패 — 원본 보존, 검증이 잡음
            }
        }

        $rows[] = [
            'row'           => $rowNum,
            'user_id'       => $userId,
            'product_name'  => $productName,
            'cohort_labels' => $cohortLabels,
            'cohort_raw'    => $cohortRaw,
        ];
    }
    fclose($fh);

    return ['rows' => $rows, 'errors' => $errors];
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /root/boot-dev && php tests/multipass_csv_parser_test.php
```

Expected: `11 pass, 0 fail`

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/multipass/multipass_csv_parser.php tests/multipass_csv_parser_test.php && git commit -m "$(cat <<'EOF'
feat(multipass): CSV paste 파서 (multipass_csv_parser)

BOM/헤더 자동 감지/RFC4180 큰따옴표/cohort 토큰 쉼표·파이프·슬래시 분리 + (\d+) 추출.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Repo (DB 액세스 레이어)

**Files:**
- Create: `public_html/includes/multipass/multipass_repo.php`
- Test: `tests/multipass_repo_test.php`

- [ ] **Step 1: Write the failing test**

Create `tests/multipass_repo_test.php`:

```php
<?php
/**
 * Multipass repo 통합 테스트 (DEV DB).
 * 사용: php tests/multipass_repo_test.php
 *
 * 셋업:
 *   - cohorts 테이블에 11기, 12기, 13기 row 가 있어야 함 (DEV 기본 데이터)
 *   - bootcamp_members 에 user_id='__test_mp_001@k' 가 없어야 함 (이 테스트가 추가/제거)
 *
 * 테스트 후 자체 정리.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/multipass/multipass_repo.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();

// 셋업 — 테스트용 cohort id 3개 확보
$cohortRows = $db->query("SELECT id, cohort FROM cohorts ORDER BY start_date LIMIT 3")->fetchAll();
if (count($cohortRows) < 3) {
    echo "SKIP — cohorts 행 3개 미만\n";
    exit(0);
}
$c1 = (int)$cohortRows[0]['id'];
$c2 = (int)$cohortRows[1]['id'];
$c3 = (int)$cohortRows[2]['id'];
$testUserId = '__test_mp_001@k';

// 정리 (이전 실패 잔여물)
$db->exec("DELETE FROM multipass WHERE user_id = '__test_mp_001@k' OR user_id = '__test_mp_002@k'");
$db->exec("DELETE FROM bootcamp_members WHERE user_id = '__test_mp_001@k' OR user_id = '__test_mp_002@k'");

// 1. createPass — multipass 1행 + multipass_cohorts 3행 트랜잭션
$passId = createPass($db, $testUserId, '11~13기 묶음권', [$c1, $c2, $c3], '메모', 1);
t('create_returns_id', $passId > 0);
t('create_pass_row',
    (int)$db->query("SELECT COUNT(*) FROM multipass WHERE id = $passId")->fetchColumn() === 1);
t('create_cohort_rows',
    (int)$db->query("SELECT COUNT(*) FROM multipass_cohorts WHERE pass_id = $passId")->fetchColumn() === 3);

// 2. UNIQUE — 같은 (pass_id, cohort_id) 두번 INSERT
try {
    $db->prepare("INSERT INTO multipass_cohorts (pass_id, cohort_id) VALUES (?, ?)")
       ->execute([$passId, $c1]);
    t('unique_violation', false, '예외 안 던짐');
} catch (PDOException $e) {
    t('unique_violation', str_contains($e->getMessage(), 'Duplicate'));
}

// 3. toggleCoupon issued=1 → at/by 채움
$ret = toggleCoupon($db, $passId, $c1, true, 99);
t('toggle_on_returns_at', !empty($ret['coupon_issued_at']));
t('toggle_on_by_set', $ret['coupon_issued_by'] === 99);
$row = $db->query("SELECT coupon_issued, coupon_issued_at, coupon_issued_by FROM multipass_cohorts WHERE pass_id = $passId AND cohort_id = $c1")->fetch();
t('toggle_on_db', (int)$row['coupon_issued'] === 1 && $row['coupon_issued_at'] !== null && (int)$row['coupon_issued_by'] === 99);

// 4. toggleCoupon issued=0 → NULL 복귀
toggleCoupon($db, $passId, $c1, false, 99);
$row = $db->query("SELECT coupon_issued, coupon_issued_at, coupon_issued_by FROM multipass_cohorts WHERE pass_id = $passId AND cohort_id = $c1")->fetch();
t('toggle_off_resets', (int)$row['coupon_issued'] === 0 && $row['coupon_issued_at'] === null && $row['coupon_issued_by'] === null);

// 5. updatePass diff — c2 제거, c1 (이미 있음) 유지, 신규 cohort 없음 → only removal
$diff = updatePass($db, $passId, ['cohort_ids' => [$c1, $c3]], 99);
t('update_removed', $diff['removed_cohort_ids'] === [$c2]);
t('update_added', $diff['added_cohort_ids'] === []);
t('update_count', (int)$db->query("SELECT COUNT(*) FROM multipass_cohorts WHERE pass_id = $passId")->fetchColumn() === 2);

// 6. updatePass — user_id 변경 (오타 수정 시나리오)
updatePass($db, $passId, ['user_id' => '__test_mp_002@k'], 99);
$row = $db->query("SELECT user_id FROM multipass WHERE id = $passId")->fetch();
t('update_user_id', $row['user_id'] === '__test_mp_002@k');
// multipass_cohorts 보존
t('update_user_id_preserves_cohorts',
    (int)$db->query("SELECT COUNT(*) FROM multipass_cohorts WHERE pass_id = $passId")->fetchColumn() === 2);

// 7. deletePass CASCADE
deletePass($db, $passId);
t('delete_pass_row',
    (int)$db->query("SELECT COUNT(*) FROM multipass WHERE id = $passId")->fetchColumn() === 0);
t('delete_cohorts_cascade',
    (int)$db->query("SELECT COUNT(*) FROM multipass_cohorts WHERE pass_id = $passId")->fetchColumn() === 0);

// 8. has_member_row / joined 분리 — 환불 row 만 있으면 has_member_row=true, joined=false
$db->exec("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, user_id, member_status) VALUES ({$c1}, 'TestRefund', 'tr', '__test_mp_001@k', 'refunded')");
$passId2 = createPass($db, '__test_mp_001@k', 'X', [$c1], null, 1);
$passes = findPasses($db, ['user_id' => '__test_mp_001@k']);
t('decorate_count', count($passes) === 1 && count($passes[0]['cohorts']) === 1);
$cohort = $passes[0]['cohorts'][0];
t('has_member_row_true_for_refund', $cohort['has_member_row'] === true);
t('joined_false_for_refund', $cohort['joined'] === false);

// 정리
$db->exec("DELETE FROM multipass WHERE id = $passId2");
$db->exec("DELETE FROM bootcamp_members WHERE user_id = '__test_mp_001@k' OR user_id = '__test_mp_002@k'");

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /root/boot-dev && php tests/multipass_repo_test.php
```

Expected: `PHP Fatal error: ... Failed opening required ... multipass_repo.php`

- [ ] **Step 3: Implement repo**

Create `public_html/includes/multipass/multipass_repo.php`:

```php
<?php
/**
 * 다회권 (multipass) DB 액세스 레이어.
 *
 * 모든 함수는 PDO + 예외 모드. 호출자가 트랜잭션 관리.
 *
 * 핵심 설계:
 *   - has_member_row / joined 두 신호를 매 조회 시 derive (저장 안 함)
 *     - has_member_row = bootcamp_members row 존재
 *     - joined = row 존재 AND member_status IN ('active','leaving','out_of_group_management')
 *   - coupon_issued_at/by 는 toggleCoupon 이 자동 채움/리셋
 *   - createPass / updatePass / deletePass 모두 multipass_cohorts CASCADE 의존
 */
declare(strict_types=1);

/**
 * 다회권 1건 생성. multipass_cohorts UNIQUE 위반 시 PDOException.
 *
 * @return int 신규 pass.id
 */
function createPass(PDO $db, string $userId, string $productName, array $cohortIds, ?string $note, ?int $createdBy): int {
    if (trim($userId) === '') throw new InvalidArgumentException('user_id 필수');
    if (trim($productName) === '') throw new InvalidArgumentException('product_name 필수');
    if (empty($cohortIds)) throw new InvalidArgumentException('cohort_ids 필수');

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO multipass (user_id, product_name, note, created_by) VALUES (?, ?, ?, ?)")
           ->execute([$userId, $productName, $note, $createdBy]);
        $passId = (int)$db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO multipass_cohorts (pass_id, cohort_id) VALUES (?, ?)");
        foreach ($cohortIds as $cid) {
            $stmt->execute([$passId, (int)$cid]);
        }
        $db->commit();
        return $passId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 다회권 수정. 입력 키 (user_id, product_name, note, cohort_ids) 중 set 된 것만 반영.
 * cohort_ids 가 주어지면 diff INSERT/DELETE.
 *
 * @return array{removed_cohort_ids: int[], added_cohort_ids: int[]}
 */
function updatePass(PDO $db, int $passId, array $patch, ?int $updatedBy): array {
    $db->beginTransaction();
    try {
        // 메타 업데이트
        $sets = [];
        $vals = [];
        foreach (['user_id', 'product_name', 'note'] as $k) {
            if (array_key_exists($k, $patch)) {
                $sets[] = "$k = ?";
                $vals[] = $patch[$k];
            }
        }
        if ($sets) {
            $vals[] = $passId;
            $db->prepare("UPDATE multipass SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        }

        $removed = [];
        $added = [];
        if (array_key_exists('cohort_ids', $patch)) {
            $newSet = array_map('intval', $patch['cohort_ids']);
            $existing = array_map('intval', $db->query("SELECT cohort_id FROM multipass_cohorts WHERE pass_id = $passId")->fetchAll(PDO::FETCH_COLUMN));
            $removed = array_values(array_diff($existing, $newSet));
            $added = array_values(array_diff($newSet, $existing));

            if ($removed) {
                $place = implode(',', array_fill(0, count($removed), '?'));
                $stmt = $db->prepare("DELETE FROM multipass_cohorts WHERE pass_id = ? AND cohort_id IN ($place)");
                $stmt->execute(array_merge([$passId], $removed));
            }
            if ($added) {
                $stmt = $db->prepare("INSERT INTO multipass_cohorts (pass_id, cohort_id) VALUES (?, ?)");
                foreach ($added as $cid) $stmt->execute([$passId, $cid]);
            }
        }
        $db->commit();
        return ['removed_cohort_ids' => $removed, 'added_cohort_ids' => $added];
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** 다회권 삭제 (CASCADE 로 multipass_cohorts 함께 삭제). */
function deletePass(PDO $db, int $passId): void {
    $db->prepare("DELETE FROM multipass WHERE id = ?")->execute([$passId]);
}

/**
 * 쿠폰 발급 토글.
 *
 * @return array{coupon_issued: int, coupon_issued_at: ?string, coupon_issued_by: ?int, coupon_issued_by_name: ?string}
 */
function toggleCoupon(PDO $db, int $passId, int $cohortId, bool $issued, int $adminId): array {
    if ($issued) {
        $db->prepare("UPDATE multipass_cohorts SET coupon_issued = 1, coupon_issued_at = NOW(), coupon_issued_by = ? WHERE pass_id = ? AND cohort_id = ?")
           ->execute([$adminId, $passId, $cohortId]);
    } else {
        $db->prepare("UPDATE multipass_cohorts SET coupon_issued = 0, coupon_issued_at = NULL, coupon_issued_by = NULL WHERE pass_id = ? AND cohort_id = ?")
           ->execute([$passId, $cohortId]);
    }
    $stmt = $db->prepare("
        SELECT mc.coupon_issued, mc.coupon_issued_at, mc.coupon_issued_by, a.name AS coupon_issued_by_name
        FROM multipass_cohorts mc
        LEFT JOIN admins a ON mc.coupon_issued_by = a.id
        WHERE mc.pass_id = ? AND mc.cohort_id = ?
    ");
    $stmt->execute([$passId, $cohortId]);
    $row = $stmt->fetch();
    return [
        'coupon_issued'         => (int)$row['coupon_issued'],
        'coupon_issued_at'      => $row['coupon_issued_at'],
        'coupon_issued_by'      => $row['coupon_issued_by'] !== null ? (int)$row['coupon_issued_by'] : null,
        'coupon_issued_by_name' => $row['coupon_issued_by_name'],
    ];
}

/**
 * 다회권 조회. filters: user_id?, user_ids[]?, product_name?, cohort_id?, pass_id?
 * 응답에는 cohorts 배열이 decorate 되어 has_member_row/joined 포함.
 *
 * @return array<int, array{id:int, user_id:string, product_name:string, note:?string, created_at:string, cohorts: array}>
 */
function findPasses(PDO $db, array $filters): array {
    $where = ['1=1'];
    $params = [];
    if (!empty($filters['user_id'])) {
        $where[] = 'p.user_id = ?';
        $params[] = $filters['user_id'];
    }
    if (!empty($filters['user_ids'])) {
        $place = implode(',', array_fill(0, count($filters['user_ids']), '?'));
        $where[] = "p.user_id IN ($place)";
        foreach ($filters['user_ids'] as $u) $params[] = $u;
    }
    if (!empty($filters['product_name'])) {
        $where[] = 'p.product_name = ?';
        $params[] = $filters['product_name'];
    }
    if (!empty($filters['pass_id'])) {
        $where[] = 'p.id = ?';
        $params[] = (int)$filters['pass_id'];
    }
    if (!empty($filters['cohort_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM multipass_cohorts mc WHERE mc.pass_id = p.id AND mc.cohort_id = ?)';
        $params[] = (int)$filters['cohort_id'];
    }

    $sql = "SELECT p.id, p.user_id, p.product_name, p.note, p.created_at, p.created_by, a.name AS created_by_name
            FROM multipass p
            LEFT JOIN admins a ON p.created_by = a.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.user_id, p.created_at";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $passes = $stmt->fetchAll();
    if (!$passes) return [];

    return decorateCohorts($db, $passes);
}

/**
 * 입력 받은 pass 행들에 cohorts 배열을 채워 반환. 각 cohort row 에 has_member_row/joined 계산.
 */
function decorateCohorts(PDO $db, array $passes): array {
    $passIds = array_column($passes, 'id');
    if (!$passIds) return $passes;

    $place = implode(',', array_fill(0, count($passIds), '?'));
    $stmt = $db->prepare("
        SELECT mc.pass_id, mc.cohort_id, mc.coupon_issued, mc.coupon_issued_at, mc.coupon_issued_by,
               a.name AS coupon_issued_by_name,
               c.cohort, c.start_date, c.is_active
        FROM multipass_cohorts mc
        JOIN cohorts c ON mc.cohort_id = c.id
        LEFT JOIN admins a ON mc.coupon_issued_by = a.id
        WHERE mc.pass_id IN ($place)
        ORDER BY c.start_date
    ");
    $stmt->execute($passIds);
    $cohortRows = $stmt->fetchAll();

    // user_id × cohort_id → has_member_row / joined 일괄 조회
    $userIds = array_unique(array_column($passes, 'user_id'));
    $cohortIds = array_unique(array_column($cohortRows, 'cohort_id'));
    $memberMap = []; // "user_id|cohort_id" => ['has' => bool, 'joined' => bool]
    if ($userIds && $cohortIds) {
        $up = implode(',', array_fill(0, count($userIds), '?'));
        $cp = implode(',', array_fill(0, count($cohortIds), '?'));
        $stmt = $db->prepare("
            SELECT user_id, cohort_id,
                   SUM(member_status IN ('active','leaving','out_of_group_management')) AS joined_cnt
            FROM bootcamp_members
            WHERE user_id IN ($up) AND cohort_id IN ($cp) AND user_id IS NOT NULL AND user_id <> ''
            GROUP BY user_id, cohort_id
        ");
        $stmt->execute(array_merge(array_values($userIds), array_values($cohortIds)));
        foreach ($stmt->fetchAll() as $r) {
            $memberMap[$r['user_id'] . '|' . $r['cohort_id']] = [
                'has'    => true,
                'joined' => (int)$r['joined_cnt'] > 0,
            ];
        }
    }

    // pass.id → user_id 맵
    $passUser = [];
    foreach ($passes as $p) $passUser[$p['id']] = $p['user_id'];

    // pass_id → cohorts[]
    $byPass = [];
    foreach ($cohortRows as $cr) {
        $key = $passUser[$cr['pass_id']] . '|' . $cr['cohort_id'];
        $info = $memberMap[$key] ?? ['has' => false, 'joined' => false];
        $byPass[$cr['pass_id']][] = [
            'cohort_id'             => (int)$cr['cohort_id'],
            'cohort'                => $cr['cohort'],
            'start_date'            => $cr['start_date'],
            'is_active'             => (int)$cr['is_active'],
            'coupon_issued'         => (int)$cr['coupon_issued'],
            'coupon_issued_at'      => $cr['coupon_issued_at'],
            'coupon_issued_by'      => $cr['coupon_issued_by'] !== null ? (int)$cr['coupon_issued_by'] : null,
            'coupon_issued_by_name' => $cr['coupon_issued_by_name'],
            'has_member_row'        => $info['has'],
            'joined'                => $info['joined'],
        ];
    }

    foreach ($passes as &$p) {
        $p['id'] = (int)$p['id'];
        $p['cohorts'] = $byPass[$p['id']] ?? [];
    }
    return $passes;
}

/**
 * 회원 검색 — q 로 user_id/nickname/real_name/phone 부분일치 + 다회권 lookup.
 *
 * 결과는 user_id 별 그룹.
 *
 * @return array<int, array{user_id:string, profiles:array, passes:array}>
 */
function searchMembers(PDO $db, string $q): array {
    $q = trim($q);
    if ($q === '') return [];

    $like = '%' . $q . '%';

    // 1) bootcamp_members 에서 user_id 후보 수집
    $stmt = $db->prepare("
        SELECT bm.user_id, bm.nickname, bm.real_name, bm.phone, c.cohort, c.start_date
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.user_id IS NOT NULL AND bm.user_id <> ''
          AND (bm.user_id LIKE ? OR bm.nickname LIKE ? OR bm.real_name LIKE ? OR bm.phone LIKE ?)
        ORDER BY c.start_date DESC
        LIMIT 200
    ");
    $stmt->execute([$like, $like, $like, $like]);
    $rows = $stmt->fetchAll();

    $byUser = [];
    foreach ($rows as $r) {
        $byUser[$r['user_id']]['profiles'][] = [
            'nickname'      => $r['nickname'],
            'real_name'     => $r['real_name'],
            'phone'         => $r['phone'],
            'latest_cohort' => $r['cohort'],
            'start_date'    => $r['start_date'],
        ];
    }

    // 2) 다회권에만 있는 user_id (멤버 행 없음) — q 가 user_id 부분일치할 때만
    $stmt = $db->prepare("SELECT DISTINCT user_id FROM multipass WHERE user_id LIKE ?");
    $stmt->execute([$like]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = ['profiles' => []];
        }
    }

    if (!$byUser) return [];

    // 3) 각 user_id 의 다회권 가져오기
    $userIds = array_keys($byUser);
    $passes = findPasses($db, ['user_ids' => $userIds]);
    $passByUser = [];
    foreach ($passes as $p) $passByUser[$p['user_id']][] = $p;

    $out = [];
    foreach ($byUser as $uid => $info) {
        if (empty($passByUser[$uid])) continue;  // 다회권 없는 user_id 는 결과에서 제외
        $out[] = [
            'user_id'  => $uid,
            'profiles' => $info['profiles'] ?? [],
            'passes'   => $passByUser[$uid],
        ];
    }
    return $out;
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /root/boot-dev && php tests/multipass_repo_test.php
```

Expected: `15 pass, 0 fail` (count after all assertions).

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/multipass/multipass_repo.php tests/multipass_repo_test.php && git commit -m "$(cat <<'EOF'
feat(multipass): repo (CRUD + toggleCoupon + searchMembers + has_member_row/joined derive)

조회 시 매번 EXISTS 로 has_member_row 와 joined 두 신호를 derive.
joined 는 member_status active/leaving/oog 만 인정 (refunded 제외).
toggleCoupon 은 issued=1 시 at/by 자동, 0 시 NULL 복귀.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Bulk validate / apply 로직

**Files:**
- Create: `public_html/includes/multipass/multipass_bulk.php`

테스트는 Task 7(API HTTP 통합 테스트)에서 함께 검증한다 (bulk_validate / bulk_apply 액션 포함).

- [ ] **Step 1: Implement bulk module**

Create `public_html/includes/multipass/multipass_bulk.php`:

```php
<?php
/**
 * 다회권 CSV 일괄 검증 + 적용.
 *
 * 입력 row 형태 (parser 출력):
 *   ['row' => int, 'user_id' => string, 'product_name' => string,
 *    'cohort_labels' => string[], 'cohort_raw' => string[]]
 *
 * 검증 결과 row 추가 필드:
 *   ['status' => 'OK'|'WARN_*'|'ERROR_*', 'cohort_ids' => int[]?, 'unmatched_labels' => string[]?,
 *    'existing_pass_id' => int?, 'target_pass_in_batch' => int?]
 *
 * 적용 입력 row 추가 필드 (운영자 결정):
 *   ['mode' => 'extend'|'new'|'skip']  // WARN_DUPLICATE_PASS* 행만 필요
 */
declare(strict_types=1);

require_once __DIR__ . '/multipass_repo.php';

const MULTIPASS_BULK_MAX_ROWS = 200;

/**
 * 행 단위 검증.
 *
 * @return array{rows: array, summary: array{ok:int, warn:int, error:int}}
 */
function validateMultipassBulk(PDO $db, array $rows): array {
    // cohorts master — "11기" → cohort_id 매핑
    $cohortMap = [];  // "11" => cohort_id
    foreach ($db->query("SELECT id, cohort FROM cohorts")->fetchAll() as $c) {
        if (preg_match('/^(\d+)/', $c['cohort'], $m)) {
            $cohortMap[$m[1]] = (int)$c['id'];
        }
    }

    // boot 에 등록된 user_id set
    $knownUserIds = array_flip(
        $db->query("SELECT DISTINCT user_id FROM bootcamp_members WHERE user_id IS NOT NULL AND user_id <> ''")
           ->fetchAll(PDO::FETCH_COLUMN)
    );

    // 기존 multipass — (user_id, product_name) → pass_id
    $existingMap = [];
    foreach ($db->query("SELECT id, user_id, product_name FROM multipass")->fetchAll() as $p) {
        $existingMap[$p['user_id'] . '|' . $p['product_name']] = (int)$p['id'];
    }

    // 배치 내 첫 등장 추적
    $firstInBatch = []; // "user_id|product_name" => row_num

    $out = [];
    $summary = ['ok' => 0, 'warn' => 0, 'error' => 0];

    foreach ($rows as $r) {
        $r['user_id']      = trim($r['user_id'] ?? '');
        $r['product_name'] = trim($r['product_name'] ?? '');
        $r['cohort_labels'] = $r['cohort_labels'] ?? [];

        if ($r['user_id'] === '') {
            $r['status'] = 'ERROR_NO_USER_ID';
            $summary['error']++;
            $out[] = $r;
            continue;
        }
        if ($r['product_name'] === '') {
            $r['status'] = 'ERROR_NO_PRODUCT';
            $summary['error']++;
            $out[] = $r;
            continue;
        }
        if (empty($r['cohort_labels'])) {
            $r['status'] = 'ERROR_NO_COHORTS';
            $summary['error']++;
            $out[] = $r;
            continue;
        }

        // cohort 라벨 → cohort_id
        $cohortIds = [];
        $unmatched = [];
        foreach ($r['cohort_labels'] as $label) {
            // 라벨이 "11" 같이 숫자만이면 매칭, 아니면 unmatched
            if (preg_match('/^(\d+)$/', $label, $m) && isset($cohortMap[$m[1]])) {
                $cohortIds[] = $cohortMap[$m[1]];
            } else {
                $unmatched[] = $label;
            }
        }
        if ($unmatched) {
            $r['status'] = 'ERROR_COHORT_LABEL';
            $r['unmatched_labels'] = $unmatched;
            $summary['error']++;
            $out[] = $r;
            continue;
        }
        $r['cohort_ids'] = array_values(array_unique($cohortIds));

        // user_id 미존재 → WARN
        $hasMember = isset($knownUserIds[$r['user_id']]);

        // 중복 평가 (DB 우선)
        $key = $r['user_id'] . '|' . $r['product_name'];
        if (isset($existingMap[$key])) {
            $r['status'] = 'WARN_DUPLICATE_PASS';
            $r['existing_pass_id'] = $existingMap[$key];
            $summary['warn']++;
        } elseif (isset($firstInBatch[$key])) {
            $r['status'] = 'WARN_DUPLICATE_PASS_IN_BATCH';
            $r['target_pass_in_batch'] = $firstInBatch[$key];
            $summary['warn']++;
        } elseif (!$hasMember) {
            $r['status'] = 'WARN_NO_MEMBER';
            $summary['warn']++;
            $firstInBatch[$key] = $r['row'];
        } else {
            $r['status'] = 'OK';
            $summary['ok']++;
            $firstInBatch[$key] = $r['row'];
        }
        $out[] = $r;
    }

    return ['rows' => $out, 'summary' => $summary];
}

/**
 * 검증 통과 행을 일괄 적용. 행 단위 try/catch + 행 단위 트랜잭션.
 *
 * 입력 row 추가 필드:
 *   ['mode' => 'extend'|'new'|'skip']  // WARN_DUPLICATE_PASS* 일 때 필수
 *   ['existing_pass_id' => int?]       // extend 의 기본 타깃
 *
 * @return array{applied:int, failed:array<int, array{row:int, error:string}>}
 */
function applyMultipassBulk(PDO $db, array $rows, int $createdBy): array {
    if (count($rows) > MULTIPASS_BULK_MAX_ROWS) {
        throw new InvalidArgumentException('한 번에 ' . MULTIPASS_BULK_MAX_ROWS . '행 까지만 적용 가능합니다.');
    }

    $applied = 0;
    $failed = [];

    // 같은 batch 내 (user_id, product_name) → 첫 행이 만든 pass_id 누적 (extend 의 in-batch 타깃)
    $batchPassMap = [];

    foreach ($rows as $r) {
        $rowNum = (int)($r['row'] ?? 0);
        $mode = $r['mode'] ?? null;
        $status = $r['status'] ?? '';

        if ($status === 'skip' || $mode === 'skip') continue;
        if (str_starts_with($status, 'ERROR_')) continue;

        try {
            if ($status === 'WARN_DUPLICATE_PASS' && $mode === 'extend') {
                $passId = (int)($r['existing_pass_id'] ?? 0);
                if (!$passId) throw new RuntimeException('existing_pass_id 누락');
                _addCohortsToPass($db, $passId, $r['cohort_ids'] ?? []);
                $applied++;
                continue;
            }
            if ($status === 'WARN_DUPLICATE_PASS_IN_BATCH' && $mode === 'extend') {
                $key = $r['user_id'] . '|' . $r['product_name'];
                $passId = $batchPassMap[$key] ?? null;
                if (!$passId) throw new RuntimeException('배치 내 첫 행이 적용되지 않아 extend 불가');
                _addCohortsToPass($db, $passId, $r['cohort_ids'] ?? []);
                $applied++;
                continue;
            }
            // 'new' 또는 OK / WARN_NO_MEMBER → 새 pass 생성
            $passId = createPass($db, $r['user_id'], $r['product_name'], $r['cohort_ids'] ?? [], $r['note'] ?? null, $createdBy);
            $key = $r['user_id'] . '|' . $r['product_name'];
            if (!isset($batchPassMap[$key])) $batchPassMap[$key] = $passId;
            $applied++;
        } catch (Throwable $e) {
            $failed[] = ['row' => $rowNum, 'error' => $e->getMessage()];
        }
    }

    return ['applied' => $applied, 'failed' => $failed];
}

/** UNIQUE 위반 cohort 는 무시하고 나머지 INSERT. */
function _addCohortsToPass(PDO $db, int $passId, array $cohortIds): void {
    $stmt = $db->prepare("INSERT IGNORE INTO multipass_cohorts (pass_id, cohort_id) VALUES (?, ?)");
    foreach ($cohortIds as $cid) {
        $stmt->execute([$passId, (int)$cid]);
    }
}
```

- [ ] **Step 2: Quick smoke (no test file yet — covered by Task 7)**

```bash
cd /root/boot-dev && php -l public_html/includes/multipass/multipass_bulk.php
```

Expected: `No syntax errors detected ...`

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/multipass/multipass_bulk.php && git commit -m "$(cat <<'EOF'
feat(multipass): bulk validate/apply 로직

행 단위 검증: ERROR_* 5종 + WARN_NO_MEMBER + WARN_DUPLICATE_PASS(_IN_BATCH).
적용: mode = extend|new|skip + INSERT IGNORE 로 cohort UNIQUE 위반 무시.
≤200 행, 행 단위 try/catch + 트랜잭션 (createPass 안에서).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: admin.php 에 9 액션 추가

**Files:**
- Modify: `public_html/api/admin.php` (마지막 case 뒤에 추가, switch 안)

- [ ] **Step 1: Add require_once at top**

Read `public_html/api/admin.php` lines 1-15 to confirm where to add the require_once.

Edit line 12 (`require_once __DIR__ . '/services/member_bulk.php';`) — insert AFTER it:

```php
require_once __DIR__ . '/services/member_bulk.php';
require_once __DIR__ . '/services/retention.php';
require_once __DIR__ . '/../includes/multipass/multipass_repo.php';
require_once __DIR__ . '/../includes/multipass/multipass_csv_parser.php';
require_once __DIR__ . '/../includes/multipass/multipass_bulk.php';
header('Content-Type: application/json; charset=utf-8');
```

- [ ] **Step 2: Append 9 cases at end of switch**

Find the last `case` before `default:` or before `}` closing switch. Insert these cases just before it:

```php
// ── Multipass (다회권) ──────────────────────────────────────

case 'multipass_list':
    requireAdmin(['operation']);
    $db = getDB();
    $filters = [];
    if (!empty($_GET['user_id']))      $filters['user_id']      = trim($_GET['user_id']);
    if (!empty($_GET['product_name'])) $filters['product_name'] = trim($_GET['product_name']);
    if (!empty($_GET['cohort_id']))    $filters['cohort_id']    = (int)$_GET['cohort_id'];
    jsonSuccess(['passes' => findPasses($db, $filters)]);
    break;

case 'multipass_get':
    requireAdmin(['operation']);
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) jsonError('id 필수');
    $passes = findPasses(getDB(), ['pass_id' => $id]);
    if (!$passes) jsonError('찾을 수 없습니다.', 404);
    jsonSuccess(['pass' => $passes[0]]);
    break;

case 'multipass_create':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $userId      = trim($input['user_id'] ?? '');
    $productName = trim($input['product_name'] ?? '');
    $cohortIds   = $input['cohort_ids'] ?? [];
    $note        = $input['note'] ?? null;
    if ($userId === '')      jsonError('user_id 필수');
    if ($productName === '') jsonError('product_name 필수');
    if (!is_array($cohortIds) || !$cohortIds) jsonError('cohort_ids 필수');
    try {
        $passId = createPass(getDB(), $userId, $productName, $cohortIds, $note, (int)$admin['admin_id']);
        jsonSuccess(['id' => $passId], '다회권이 추가되었습니다.');
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) jsonError('이미 포함된 기수가 있습니다.');
        throw $e;
    }
    break;

case 'multipass_update':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonError('id 필수');
    $patch = [];
    foreach (['user_id', 'product_name', 'note'] as $k) {
        if (array_key_exists($k, $input)) $patch[$k] = $input[$k];
    }
    if (array_key_exists('cohort_ids', $input)) {
        if (!is_array($input['cohort_ids']) || !$input['cohort_ids']) jsonError('cohort_ids 비어있을 수 없습니다.');
        $patch['cohort_ids'] = $input['cohort_ids'];
    }
    try {
        $diff = updatePass(getDB(), $id, $patch, (int)$admin['admin_id']);
        jsonSuccess(['ok' => true, 'removed_cohort_ids' => $diff['removed_cohort_ids'], 'added_cohort_ids' => $diff['added_cohort_ids']], '수정되었습니다.');
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) jsonError('이미 포함된 기수가 있습니다.');
        throw $e;
    }
    break;

case 'multipass_delete':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonError('id 필수');
    deletePass(getDB(), $id);
    jsonSuccess(['ok' => true], '삭제되었습니다.');
    break;

case 'multipass_toggle_coupon':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $passId   = (int)($input['pass_id'] ?? 0);
    $cohortId = (int)($input['cohort_id'] ?? 0);
    $issued   = !empty($input['issued']);
    if (!$passId || !$cohortId) jsonError('pass_id, cohort_id 필수');
    $ret = toggleCoupon(getDB(), $passId, $cohortId, $issued, (int)$admin['admin_id']);
    jsonSuccess($ret);
    break;

case 'multipass_search_member':
    requireAdmin(['operation']);
    $q = trim($_GET['q'] ?? '');
    if ($q === '') jsonError('q 필수');
    jsonSuccess(['members' => searchMembers(getDB(), $q)]);
    break;

case 'multipass_bulk_validate':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $rows  = $input['rows'] ?? null;
    // CSV 텍스트가 들어오면 서버 측 파서 사용
    if ($rows === null && isset($input['csv'])) {
        $parsed = parseMultipassCsv((string)$input['csv']);
        $rows = $parsed['rows'];
        // 파싱 에러는 ERROR_PARSE 로 동봉
        foreach ($parsed['errors'] as $err) {
            $rows[] = ['row' => $err['row'], 'user_id' => '', 'product_name' => '', 'cohort_labels' => [], 'status' => 'ERROR_PARSE_' . strtoupper($err['reason'])];
        }
    }
    if (!is_array($rows)) jsonError('rows 또는 csv 필요');
    if (count($rows) > MULTIPASS_BULK_MAX_ROWS) jsonError('한 번에 ' . MULTIPASS_BULK_MAX_ROWS . '행 까지만 검증 가능합니다.');
    $result = validateMultipassBulk(getDB(), $rows);
    jsonSuccess($result);
    break;

case 'multipass_bulk_apply':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $rows  = $input['rows'] ?? null;
    if (!is_array($rows) || empty($rows)) jsonError('rows 필요');
    try {
        $result = applyMultipassBulk(getDB(), $rows, (int)$admin['admin_id']);
        jsonSuccess($result);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage());
    }
    break;
```

- [ ] **Step 3: Syntax check**

```bash
cd /root/boot-dev && php -l public_html/api/admin.php
```

Expected: `No syntax errors detected ...`

- [ ] **Step 4: Smoke endpoint via CLI**

```bash
cd /root/boot-dev && php -r "
require __DIR__.'/public_html/config.php';
require __DIR__.'/public_html/includes/multipass/multipass_repo.php';
\$db = getDB();
\$db->exec(\"DELETE FROM multipass WHERE user_id LIKE '__test_smoke%'\");
\$pid = createPass(\$db, '__test_smoke@k', 'smoke', [11], null, 1);
print_r(findPasses(\$db, ['pass_id' => \$pid]));
\$db->exec(\"DELETE FROM multipass WHERE id = \$pid\");
"
```

Expected: pass 1건 + cohorts 1개 (cohort_id=11 또는 동등) 출력. cohort_id 가 cohorts 테이블에 없으면 FK 에러 — DEV 의 실제 cohort id 로 조정 필요.

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/api/admin.php && git commit -m "$(cat <<'EOF'
feat(multipass): admin.php 에 9 액션 추가

list/get/create/update/delete/toggle_coupon/search_member/bulk_validate/bulk_apply
모두 requireAdmin(['operation']).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: HTTP API 통합 테스트 (권한 + bulk validate/apply 포함)

**Files:**
- Create: `tests/multipass_api_test.php`

DEV 서버가 켜져 있어야 함 (`https://dev-boot.soritune.com`). cURL 로 admin.php 호출.

- [ ] **Step 1: Write the failing test**

Create `tests/multipass_api_test.php`:

```php
<?php
/**
 * Multipass HTTP API 통합 테스트.
 *
 * 사용:
 *   ADMIN_COOKIE='PHPSESSID_ADMIN=...' DEV_BASE='https://dev-boot.soritune.com' php tests/multipass_api_test.php
 *
 * 사전:
 *   - operation 권한 admin 으로 로그인된 PHPSESSID_ADMIN 쿠키 필요
 *   - DEV cohorts 에 11기/12기/13기 동등 row 존재
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
        CURLOPT_TIMEOUT        => 10,
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

$base = rtrim($base, '/');
$api = $base . '/api/admin.php';
$h = ["Cookie: $cookie", "Content-Type: application/json"];

// 셋업 — DEV cohorts 에서 가장 최근 3 row 사용
require_once __DIR__ . '/../public_html/config.php';
$db = getDB();
$cohortRows = $db->query("SELECT id, cohort FROM cohorts ORDER BY start_date DESC LIMIT 3")->fetchAll();
if (count($cohortRows) < 3) { echo "SKIP — cohorts < 3\n"; exit(0); }
[$c1, $c2, $c3] = array_map(fn($r) => (int)$r['id'], $cohortRows);
$db->exec("DELETE FROM multipass WHERE user_id LIKE '__test_api_mp%'");
$db->exec("DELETE FROM bootcamp_members WHERE user_id LIKE '__test_api_mp%'");
$db->exec("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, user_id, member_status) VALUES ($c1, 'API홍길동', 'apihg', '__test_api_mp@k', 'active')");

// 1. multipass_create
$r = req('POST', "$api?action=multipass_create", $h, [
    'user_id' => '__test_api_mp@k', 'product_name' => 'API테스트권', 'cohort_ids' => [$c1, $c2, $c3],
]);
t('create_201', $r['code'] === 200 && !empty($r['body']['success']));
$passId = $r['body']['id'] ?? 0;
t('create_id', $passId > 0);

// 2. multipass_search_member q=user_id
$r = req('GET', "$api?action=multipass_search_member&q=__test_api_mp", $h);
t('search_200', $r['code'] === 200);
t('search_member_count', count($r['body']['members'] ?? []) === 1);
$cohorts = $r['body']['members'][0]['passes'][0]['cohorts'] ?? [];
t('search_cohort_count', count($cohorts) === 3);
$c1Found = false;
foreach ($cohorts as $c) if ($c['cohort_id'] === $c1) { $c1Found = true; t('joined_for_active', $c['joined'] === true && $c['has_member_row'] === true); }
if (!$c1Found) t('joined_for_active', false, 'c1 row 없음');

// 3. multipass_search_member q=nickname
$r = req('GET', "$api?action=multipass_search_member&q=apihg", $h);
t('search_by_nickname', count($r['body']['members'] ?? []) === 1);

// 4. has_member_row=true / joined=false (refunded 만)
$db->exec("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, user_id, member_status) VALUES ($c2, 'API환불', 'apirf', '__test_api_mp@k', 'refunded')");
$r = req('GET', "$api?action=multipass_search_member&q=__test_api_mp", $h);
$cohorts = $r['body']['members'][0]['passes'][0]['cohorts'] ?? [];
foreach ($cohorts as $c) if ($c['cohort_id'] === $c2) {
    t('has_member_row_for_refund', $c['has_member_row'] === true);
    t('joined_false_for_refund', $c['joined'] === false);
}

// 5. toggle_coupon on
$r = req('POST', "$api?action=multipass_toggle_coupon", $h, ['pass_id' => $passId, 'cohort_id' => $c2, 'issued' => true]);
t('toggle_on_200', $r['code'] === 200);
t('toggle_on_at', !empty($r['body']['coupon_issued_at']));

// 6. toggle_coupon off
$r = req('POST', "$api?action=multipass_toggle_coupon", $h, ['pass_id' => $passId, 'cohort_id' => $c2, 'issued' => false]);
t('toggle_off_at_null', $r['body']['coupon_issued_at'] === null);

// 7. update — user_id 변경
$r = req('POST', "$api?action=multipass_update", $h, ['id' => $passId, 'user_id' => '__test_api_mp2@k']);
t('update_user_id_200', $r['code'] === 200 && !empty($r['body']['success']));
$row = $db->query("SELECT user_id FROM multipass WHERE id = $passId")->fetch();
t('update_user_id_db', $row['user_id'] === '__test_api_mp2@k');

// 8. update — cohort_ids diff (c1, c3 만 → c2 제거)
$r = req('POST', "$api?action=multipass_update", $h, ['id' => $passId, 'cohort_ids' => [$c1, $c3]]);
t('update_diff_removed', in_array($c2, $r['body']['removed_cohort_ids'] ?? []));

// 9. delete CASCADE
$r = req('POST', "$api?action=multipass_delete", $h, ['id' => $passId]);
t('delete_200', $r['code'] === 200);
t('delete_cohorts_gone',
    (int)$db->query("SELECT COUNT(*) FROM multipass_cohorts WHERE pass_id = $passId")->fetchColumn() === 0);

// 10. bulk_validate — 정상 + WARN_NO_MEMBER + ERROR_COHORT_LABEL
// cohort 라벨 매칭을 위해 DEV cohorts 의 실제 숫자 라벨 사용
$cLabel = (function() use ($db) {
    foreach ($db->query("SELECT cohort FROM cohorts")->fetchAll(PDO::FETCH_COLUMN) as $c) {
        if (preg_match('/^(\d+)/', $c, $m)) return $m[1];
    }
    return '11';
})();
$r = req('POST', "$api?action=multipass_bulk_validate", $h, [
    'rows' => [
        ['row' => 1, 'user_id' => '__test_api_mp@k',  'product_name' => 'V1', 'cohort_labels' => [$cLabel]],
        ['row' => 2, 'user_id' => '__test_unknown@k', 'product_name' => 'V2', 'cohort_labels' => [$cLabel]],
        ['row' => 3, 'user_id' => '__test_api_mp@k',  'product_name' => 'V3', 'cohort_labels' => ['예비']],
    ],
]);
t('bulk_validate_200', $r['code'] === 200);
$rows = $r['body']['rows'] ?? [];
t('bulk_validate_ok', ($rows[0]['status'] ?? '') === 'OK');
t('bulk_validate_warn', ($rows[1]['status'] ?? '') === 'WARN_NO_MEMBER');
t('bulk_validate_err',  ($rows[2]['status'] ?? '') === 'ERROR_COHORT_LABEL');

// 11. bulk_apply — OK + WARN_NO_MEMBER 적용
$applyRows = array_filter($rows, fn($r) => !str_starts_with($r['status'], 'ERROR_'));
$r = req('POST', "$api?action=multipass_bulk_apply", $h, ['rows' => array_values($applyRows)]);
t('bulk_apply_200', $r['code'] === 200);
t('bulk_apply_count', ($r['body']['applied'] ?? 0) === 2);

// 정리
$db->exec("DELETE FROM multipass WHERE user_id LIKE '__test_api_mp%' OR user_id LIKE '__test_api_mp2%' OR user_id LIKE '__test_unknown%'");
$db->exec("DELETE FROM bootcamp_members WHERE user_id LIKE '__test_api_mp%'");

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: Run with admin cookie**

쿠키는 운영자가 dev-boot 에 operation 으로 로그인 후 브라우저 dev tools 에서 `PHPSESSID_ADMIN=...` 값 복사.

```bash
cd /root/boot-dev && ADMIN_COOKIE='PHPSESSID_ADMIN=...값...' DEV_BASE='https://dev-boot.soritune.com' php tests/multipass_api_test.php
```

Expected: 모든 PASS (`16 pass, 0 fail` 정도. 실제 카운트는 단계 추가/제거에 따라 다름).

쿠키가 없는 CI 환경에서는 SKIP. 권한 거부 (operation 외 role) 검증은 별도 Task 7 에서.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add tests/multipass_api_test.php && git commit -m "$(cat <<'EOF'
test(multipass): HTTP API 통합 테스트

DEV 서버 + operation cookie 기반 cURL.
9 액션 행복경로 + has_member_row/joined 분리 + bulk validate/apply.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: 권한 거부 검증 (operation 외 role 차단)

**Files:**
- Modify: `tests/multipass_api_test.php` (이전 Task 의 파일에 권한 케이스 추가)

- [ ] **Step 1: Append permission-denial cases at the end**

Edit `tests/multipass_api_test.php`. 정리 (`$db->exec("DELETE ...")`) 직전 위치에 추가:

```php
// 12. 권한 거부 — coach role 쿠키로 호출 시 403
$coachCookie = getenv('COACH_COOKIE') ?: '';
if ($coachCookie) {
    $hCoach = ["Cookie: $coachCookie", "Content-Type: application/json"];
    $r = req('GET', "$api?action=multipass_list", $hCoach);
    t('perm_list_403', $r['code'] === 403);
    $r = req('POST', "$api?action=multipass_create", $hCoach, ['user_id' => 'x', 'product_name' => 'x', 'cohort_ids' => [$c1]]);
    t('perm_create_403', $r['code'] === 403);
    $r = req('POST', "$api?action=multipass_toggle_coupon", $hCoach, ['pass_id' => 1, 'cohort_id' => $c1, 'issued' => true]);
    t('perm_toggle_403', $r['code'] === 403);
} else {
    echo "SKIP perm — COACH_COOKIE 미설정\n";
}
```

- [ ] **Step 2: Run with both cookies**

```bash
cd /root/boot-dev && \
  ADMIN_COOKIE='PHPSESSID_ADMIN=...운영...' \
  COACH_COOKIE='PHPSESSID_ADMIN=...코치...' \
  DEV_BASE='https://dev-boot.soritune.com' \
  php tests/multipass_api_test.php
```

Expected: 모든 PASS, 권한 거부 3건도 PASS.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add tests/multipass_api_test.php && git commit -m "$(cat <<'EOF'
test(multipass): 권한 거부 케이스 (coach role 으로 list/create/toggle → 403)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: admin.js 에 탭 추가 + operation/index.php 스크립트 등록

**Files:**
- Modify: `public_html/js/admin.js` (operation 탭 목록 — 회원 관리와 조 배정 사이)
- Modify: `public_html/operation/index.php` (script 태그 추가)
- Create: `public_html/js/admin-multipass.js` (placeholder — Task 9 에서 채움)

- [ ] **Step 1: Create empty admin-multipass.js stub**

Create `public_html/js/admin-multipass.js`:

```javascript
/* ── Multipass (다회권 확인) ────────────────────────────────── */
const AdminMultipassApp = (() => {
    let container = null;
    let admin = null;

    function init(adminSession, containerId) {
        admin = adminSession;
        container = document.getElementById(containerId);
        if (!container) return;
        container.innerHTML = '<p style="padding:24px">다회권 확인 — 곧 채워집니다.</p>';
    }

    return { init };
})();
```

- [ ] **Step 2: Add tab button + content div in admin.js**

Read `public_html/js/admin.js` lines 183-220 (operation 탭 목록).

Edit — find the `<button class="tab" data-tab="#tab-members" data-hash="members">회원 관리</button>` line. Insert AFTER it:

```html
<button class="tab" data-tab="#tab-members" data-hash="members">회원 관리</button>
                            <button class="tab" data-tab="#tab-multipass" data-hash="multipass">다회권 확인</button>
                            <button class="tab" data-tab="#tab-group-assign" data-hash="group-assign">조 배정</button>
```

Then find `<div class="tab-content" id="tab-members"></div>` and insert AFTER it:

```html
<div class="tab-content" id="tab-members"></div>
                        <div class="tab-content" id="tab-multipass"></div>
                        <div class="tab-content" id="tab-group-assign"></div>
```

- [ ] **Step 3: Add lazy load branch**

Read `public_html/js/admin.js` lines 480-510 (other tab lazy loads e.g. group-assign).

Insert a lazy load branch (similar pattern). Find a place near the operation tab init logic — for example after the `GroupAssignmentApp.init` block. Add:

```javascript
// Multipass 탭 lazy load (operation)
tabCtrl.on('multipass', () => {
    if (typeof AdminMultipassApp !== 'undefined') {
        AdminMultipassApp.init(admin, 'tab-multipass');
    }
});
```

If the existing tab lazy load uses a different pattern (e.g. checking `isOperation()`), adapt accordingly. Safer: grep for how `tab-cafe-posts` is initialized and follow the same pattern.

```bash
cd /root/boot-dev && grep -n "tab-cafe-posts\|tabCtrl.on" public_html/js/admin.js | head -10
```

- [ ] **Step 4: Add script tag in operation/index.php**

Edit `public_html/operation/index.php`. Find the `<script src="/js/admin.js...">` line. Insert AFTER `admin-cafe-bulk.js`:

```html
<script src="/js/admin-cafe-bulk.js<?= v('/js/admin-cafe-bulk.js') ?>"></script>
    <script src="/js/admin-multipass.js<?= v('/js/admin-multipass.js') ?>"></script>
    <script src="/js/coin.js<?= v('/js/coin.js') ?>"></script>
```

- [ ] **Step 5: Smoke in browser**

브라우저에서 `https://dev-boot.soritune.com/operation` 열기, operation 권한으로 로그인.

Expected: "다회권 확인" 탭 button 표시 + 클릭 시 stub 메시지 ("곧 채워집니다.") 노출.

- [ ] **Step 6: Commit**

```bash
cd /root/boot-dev && git add public_html/js/admin.js public_html/js/admin-multipass.js public_html/operation/index.php && git commit -m "$(cat <<'EOF'
feat(multipass): operation 탭 등록 + admin-multipass.js 스텁

탭은 회원 관리와 조 배정 사이.
실제 UI 는 후속 task 에서.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: admin-multipass.js — 회원별 보기 (검색 + 카드 + 쿠폰 토글)

**Files:**
- Modify: `public_html/js/admin-multipass.js` (스텁 → 회원별 보기 구현)

- [ ] **Step 1: Replace stub with members view**

Replace `public_html/js/admin-multipass.js` content:

```javascript
/* ── Multipass (다회권 확인) ────────────────────────────────── */
const AdminMultipassApp = (() => {
    let container = null;
    let admin = null;
    let cohorts = [];        // cohort_list 응답 cohort_details
    let activeSubTab = 'members';
    let searchTimer = null;

    async function init(adminSession, containerId) {
        admin = adminSession;
        container = document.getElementById(containerId);
        if (!container) return;
        await loadCohorts();
        renderShell();
        renderMembersView('');
    }

    async function loadCohorts() {
        try {
            const r = await App.get('/api/admin.php?action=cohort_list');
            cohorts = (r.cohort_details || []).slice().sort((a, b) =>
                new Date(b.start_date) - new Date(a.start_date));
        } catch (e) {
            cohorts = [];
        }
    }

    function renderShell() {
        container.innerHTML = `
            <div class="multipass">
                <div class="multipass-toolbar">
                    <div class="multipass-subtabs">
                        <button class="multipass-subtab active" data-sub="members">회원별 보기</button>
                        <button class="multipass-subtab" data-sub="products">상품별 보기</button>
                    </div>
                    <div class="multipass-actions">
                        <button class="btn btn-primary btn-sm" id="mp-add">+ 다회권 추가</button>
                        <button class="btn btn-secondary btn-sm" id="mp-bulk">CSV 일괄</button>
                    </div>
                </div>
                <div class="multipass-body" id="mp-body"></div>
            </div>
        `;
        container.querySelectorAll('.multipass-subtab').forEach(btn => {
            btn.addEventListener('click', () => {
                container.querySelectorAll('.multipass-subtab').forEach(b => b.classList.toggle('active', b === btn));
                activeSubTab = btn.dataset.sub;
                if (activeSubTab === 'members') renderMembersView('');
                else renderProductsView();
            });
        });
        document.getElementById('mp-add').addEventListener('click', openAddModal);
        document.getElementById('mp-bulk').addEventListener('click', openBulkModal);
    }

    function renderMembersView(initialQ) {
        const body = document.getElementById('mp-body');
        body.innerHTML = `
            <div class="mp-search">
                <input type="text" id="mp-search-input" placeholder="user_id / 닉네임 / 실명 / 전화번호" value="${App.esc(initialQ || '')}">
            </div>
            <div id="mp-results"></div>
        `;
        const input = document.getElementById('mp-search-input');
        input.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => doSearch(input.value), 300);
        });
        if (initialQ) doSearch(initialQ);
    }

    async function doSearch(q) {
        const results = document.getElementById('mp-results');
        q = q.trim();
        if (!q) { results.innerHTML = ''; return; }
        results.innerHTML = '<p>검색 중...</p>';
        try {
            const r = await App.get('/api/admin.php?action=multipass_search_member&q=' + encodeURIComponent(q));
            const members = r.members || [];
            if (!members.length) {
                results.innerHTML = `
                    <p>이 검색어로 매칭되는 다회권이 없습니다.</p>
                    <button class="btn btn-primary btn-sm" onclick="document.getElementById('mp-add').click()">+ 다회권 추가</button>
                `;
                return;
            }
            results.innerHTML = members.map(m => renderMemberCard(m)).join('');
            attachCardHandlers();
        } catch (e) {
            results.innerHTML = `<p class="text-danger">검색 실패: ${App.esc(e.message || e)}</p>`;
        }
    }

    function renderMemberCard(m) {
        const profile = (m.profiles && m.profiles[0]) || {};
        const profStr = [profile.real_name, profile.nickname, profile.phone].filter(Boolean).join(' / ');
        const passes = m.passes || [];
        return `
            <div class="mp-member-card">
                <div class="mp-member-header">
                    👤 user_id: <strong>${App.esc(m.user_id)}</strong>
                    ${profStr ? `<span class="mp-profile">(${App.esc(profStr)})</span>` : ''}
                </div>
                <div class="mp-passes">
                    <div class="mp-passes-label">── 보유 다회권 (${passes.length}건) ──</div>
                    ${passes.map(p => renderPassCard(p)).join('')}
                </div>
            </div>
        `;
    }

    function renderPassCard(p) {
        const total = p.cohorts.length;
        const joined = p.cohorts.filter(c => c.joined).length;
        const remain = total - joined;
        return `
            <div class="mp-pass-card" data-pass-id="${p.id}">
                <div class="mp-pass-header">
                    <strong>${App.esc(p.product_name)}</strong>
                    <span class="mp-pass-meta">(${(p.created_at || '').slice(0, 10)} 등록)</span>
                    <span class="mp-pass-actions">
                        <button class="btn btn-secondary btn-xs mp-edit" data-id="${p.id}">수정</button>
                        <button class="btn btn-danger btn-xs mp-delete" data-id="${p.id}">삭제</button>
                    </span>
                </div>
                <div class="mp-pass-summary">포함 ${total}기 · 수강 ${joined} / 남은 ${remain}</div>
                <div class="mp-pass-cohorts">
                    ${p.cohorts.map(c => renderCohortRow(p.id, c)).join('')}
                </div>
            </div>
        `;
    }

    function renderCohortRow(passId, c) {
        const badge = c.joined ? '✅' : '⚪';
        let label;
        if (c.joined) label = '수강함';
        else if (c.has_member_row) label = '미수강 (환불)';
        else label = '미수강';
        const inactive = !c.is_active ? '<span class="mp-inactive">(종료)</span>' : '';
        const issuedMeta = c.coupon_issued ? `
            <div class="mp-coupon-meta">└ ${(c.coupon_issued_at || '').slice(0, 10)} by ${App.esc(c.coupon_issued_by_name || '?')}</div>
        ` : '';
        return `
            <div class="mp-cohort-row" data-cohort-id="${c.cohort_id}">
                <span class="mp-cohort-badge">${badge}</span>
                <span class="mp-cohort-name">${App.esc(c.cohort)} ${inactive}</span>
                <span class="mp-cohort-status">${label}</span>
                <label class="mp-coupon-toggle">
                    <input type="checkbox" class="mp-coupon-cb" data-pass-id="${passId}" data-cohort-id="${c.cohort_id}" ${c.coupon_issued ? 'checked' : ''}>
                    쿠폰 발급
                </label>
                ${issuedMeta}
            </div>
        `;
    }

    function attachCardHandlers() {
        container.querySelectorAll('.mp-coupon-cb').forEach(cb => {
            cb.addEventListener('change', async () => {
                const passId   = parseInt(cb.dataset.passId);
                const cohortId = parseInt(cb.dataset.cohortId);
                const issued   = cb.checked;
                try {
                    await App.post('/api/admin.php?action=multipass_toggle_coupon', { pass_id: passId, cohort_id: cohortId, issued });
                    // 옵티미스틱 — 결과 재로드 대신 카드의 메타 부분만 갱신
                    doSearch(document.getElementById('mp-search-input').value);
                } catch (e) {
                    cb.checked = !issued;
                    Toast.error('쿠폰 토글 실패: ' + (e.message || e));
                }
            });
        });
        container.querySelectorAll('.mp-edit').forEach(btn => {
            btn.addEventListener('click', () => openEditModal(parseInt(btn.dataset.id)));
        });
        container.querySelectorAll('.mp-delete').forEach(btn => {
            btn.addEventListener('click', () => deletePass(parseInt(btn.dataset.id)));
        });
    }

    async function deletePass(id) {
        if (!confirm('이 다회권을 삭제할까요?\n(쿠폰 발급 이력도 함께 삭제됩니다)')) return;
        try {
            await App.post('/api/admin.php?action=multipass_delete', { id });
            Toast.success('삭제 완료');
            doSearch(document.getElementById('mp-search-input').value);
        } catch (e) {
            Toast.error('삭제 실패: ' + (e.message || e));
        }
    }

    // 후속 task 에서 채움
    function openAddModal() { Toast.info('Task 10 에서 구현'); }
    function openEditModal(id) { Toast.info('Task 10 에서 구현'); }
    function openBulkModal() { Toast.info('Task 11 에서 구현'); }
    function renderProductsView() { document.getElementById('mp-body').innerHTML = '<p>Task 12 에서 구현</p>'; }

    return { init };
})();
```

- [ ] **Step 2: Smoke in browser**

`https://dev-boot.soritune.com/operation` → "다회권 확인" 탭.

검색창에 (이미 다회권 등록한) user_id 일부 입력 → 카드 렌더 → 쿠폰 체크박스 ON/OFF → DB 에서 toggle 반영 확인.

DEV 데이터가 없으면 직접 한 건 SQL 로 넣어 테스트:

```bash
cd /root/boot-dev && mysql --defaults-file=<(printf "[client]\nuser=SORITUNECOM_DEV_BOOT\npassword=$(grep DB_PASS .db_credentials | cut -d= -f2)\n") SORITUNECOM_DEV_BOOT -e "
INSERT INTO bootcamp_members (cohort_id, real_name, nickname, user_id, member_status) VALUES (11, 'TestMP', 'tmp', '__test_smoke@k', 'active');
INSERT INTO multipass (user_id, product_name) VALUES ('__test_smoke@k', '테스트 묶음권');
INSERT INTO multipass_cohorts (pass_id, cohort_id) VALUES (LAST_INSERT_ID(), 11);
"
```

(cohort_id=11 이 실제 DEV cohort id 와 다르면 조정)

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add public_html/js/admin-multipass.js && git commit -m "$(cat <<'EOF'
feat(multipass): 회원별 보기 — 검색 + 카드 + 쿠폰 토글

300ms debounce 검색, user_id 별 카드 묶음, 쿠폰 체크박스 옵티미스틱 토글.
환불 row 만 있는 cohort 는 "(환불)" 표시.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: 추가 / 수정 모달

**Files:**
- Modify: `public_html/js/admin-multipass.js` (`openAddModal`, `openEditModal` 채움)

- [ ] **Step 1: Replace `openAddModal` / `openEditModal`**

Edit `public_html/js/admin-multipass.js` — replace the placeholder functions and add helpers:

```javascript
function openAddModal() {
    openPassModal({ mode: 'create' });
}

async function openEditModal(passId) {
    try {
        const r = await App.get('/api/admin.php?action=multipass_get&id=' + passId);
        openPassModal({ mode: 'edit', pass: r.pass });
    } catch (e) {
        Toast.error('불러오기 실패: ' + (e.message || e));
    }
}

function openPassModal({ mode, pass }) {
    const isEdit = mode === 'edit';
    const userId      = isEdit ? pass.user_id : '';
    const productName = isEdit ? pass.product_name : '';
    const note        = isEdit ? (pass.note || '') : '';
    const selectedIds = isEdit ? new Set(pass.cohorts.map(c => c.cohort_id)) : new Set();

    const cohortChecks = cohorts.map(c => {
        const checked = selectedIds.has(c.id);
        const inactive = !c.is_active ? 'mp-cohort-inactive' : '';
        return `<label class="mp-cohort-check ${inactive}">
            <input type="checkbox" value="${c.id}" ${checked ? 'checked' : ''}> ${App.esc(c.cohort)}
        </label>`;
    }).join('');

    const html = `
        <div class="mp-modal-body">
            <h3>${isEdit ? '다회권 수정' : '다회권 추가'}</h3>
            <div class="mp-form-row">
                <label>구매자 user_id</label>
                <input type="text" id="mp-f-userid" value="${App.esc(userId)}">
                <button class="btn btn-secondary btn-xs" id="mp-f-lookup">회원 조회</button>
                <span id="mp-f-lookup-result"></span>
            </div>
            <div class="mp-form-row">
                <label>상품명</label>
                <input type="text" id="mp-f-product" value="${App.esc(productName)}">
            </div>
            <div class="mp-form-row">
                <label>포함 기수</label>
                <div class="mp-cohort-checks">${cohortChecks}</div>
            </div>
            <div class="mp-form-row">
                <label>메모(선택)</label>
                <textarea id="mp-f-note" rows="2">${App.esc(note)}</textarea>
            </div>
            <div class="mp-form-actions">
                <button class="btn btn-secondary" id="mp-f-cancel">취소</button>
                <button class="btn btn-primary" id="mp-f-save">${isEdit ? '저장' : '추가'}</button>
            </div>
        </div>
    `;

    const modal = App.openModal(html, { width: 600 });
    modal.querySelector('#mp-f-cancel').onclick = () => App.closeModal(modal);
    modal.querySelector('#mp-f-lookup').onclick = async () => {
        const uid = modal.querySelector('#mp-f-userid').value.trim();
        if (!uid) return;
        try {
            const r = await App.get('/api/admin.php?action=multipass_search_member&q=' + encodeURIComponent(uid));
            const m = (r.members || []).find(x => x.user_id === uid);
            const out = modal.querySelector('#mp-f-lookup-result');
            if (m && m.profiles && m.profiles.length) {
                const p = m.profiles[0];
                out.innerHTML = `<span class="mp-profile-ok">${App.esc(p.real_name || '')} / ${App.esc(p.nickname || '')}</span>`;
            } else {
                out.innerHTML = `<span class="mp-profile-warn">⚠ boot 에 등록된 적 없는 user_id</span>`;
            }
        } catch (e) { /* swallow */ }
    };
    modal.querySelector('#mp-f-save').onclick = async () => {
        const newUid = modal.querySelector('#mp-f-userid').value.trim();
        const newProduct = modal.querySelector('#mp-f-product').value.trim();
        const newNote = modal.querySelector('#mp-f-note').value.trim() || null;
        const checkedIds = Array.from(modal.querySelectorAll('.mp-cohort-checks input:checked')).map(cb => parseInt(cb.value));
        if (!newUid)     { Toast.error('user_id 필수'); return; }
        if (!newProduct) { Toast.error('상품명 필수'); return; }
        if (!checkedIds.length) { Toast.error('포함 기수 1개 이상 선택'); return; }
        try {
            if (isEdit) {
                await App.post('/api/admin.php?action=multipass_update', {
                    id: pass.id, user_id: newUid, product_name: newProduct, note: newNote, cohort_ids: checkedIds,
                });
                Toast.success('수정되었습니다.');
            } else {
                await App.post('/api/admin.php?action=multipass_create', {
                    user_id: newUid, product_name: newProduct, note: newNote, cohort_ids: checkedIds,
                });
                Toast.success('다회권이 추가되었습니다.');
            }
            App.closeModal(modal);
            // 검색 결과 갱신
            const cur = document.getElementById('mp-search-input');
            if (cur && cur.value) doSearch(cur.value);
        } catch (e) {
            Toast.error('저장 실패: ' + (e.message || e));
        }
    };
}
```

`App.openModal` / `App.closeModal` 시그니처가 boot 에 어떻게 정의돼 있는지 사전 확인 필요:

```bash
cd /root/boot-dev && grep -n "openModal\|closeModal" public_html/js/common.js | head -10
```

다르면 그 시그니처에 맞춰 호출. (예: `App.openModal(html)` 만 받고 width 옵션이 없을 수도.)

- [ ] **Step 2: Smoke in browser**

회원별 보기 → [+ 다회권 추가] 클릭 → 모달 → user_id 입력 → [회원 조회] → 닉/실명 표시 → 상품명 입력 → 기수 체크 → [추가] → 카드 갱신.

기존 카드의 [수정] 클릭 → 모달이 기존 값으로 채워짐 → 변경 후 [저장] → 갱신.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add public_html/js/admin-multipass.js && git commit -m "$(cat <<'EOF'
feat(multipass): 추가/수정 모달

user_id 회원 조회 (미매칭 노란 배지) + cohorts 체크박스 + create/update API.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: CSV 일괄 모달

**Files:**
- Modify: `public_html/js/admin-multipass.js` (`openBulkModal` 채움)

- [ ] **Step 1: Replace `openBulkModal`**

Edit `public_html/js/admin-multipass.js` — replace the placeholder:

```javascript
function openBulkModal() {
    let parsedRows = null;

    const html = `
        <div class="mp-modal-body mp-bulk">
            <h3>다회권 CSV 일괄 등록</h3>

            <div class="mp-bulk-section">
                <div class="mp-bulk-step">1. 템플릿</div>
                <pre class="mp-bulk-template">user_id,product_name,cohorts
3937726826@k,11~13기 묶음권,"11,12,13"
4114325139@n,5~7기 패키지,"5|6|7"</pre>
                <p class="mp-bulk-note">cohorts 컬럼: 쉼표·파이프·슬래시 분리. "11"·"11기" 모두 인식.</p>
            </div>

            <div class="mp-bulk-section">
                <div class="mp-bulk-step">2. CSV 붙여넣기 또는 Excel 업로드</div>
                <textarea id="mp-bulk-csv" rows="6" placeholder="여기에 CSV 붙여넣기"></textarea>
                <div class="mp-bulk-upload">
                    <input type="file" id="mp-bulk-file" accept=".xlsx,.xls,.csv" style="display:none">
                    <button class="btn btn-secondary btn-sm" id="mp-bulk-file-btn">파일 선택 (Excel/CSV)</button>
                </div>
                <button class="btn btn-primary" id="mp-bulk-validate">검증</button>
            </div>

            <div class="mp-bulk-section" id="mp-bulk-result" style="display:none">
                <div class="mp-bulk-step">3. 검증 결과</div>
                <div id="mp-bulk-summary"></div>
                <div id="mp-bulk-table"></div>
                <button class="btn btn-primary" id="mp-bulk-apply">적용</button>
            </div>

            <div class="mp-form-actions">
                <button class="btn btn-secondary" id="mp-bulk-close">닫기</button>
            </div>
        </div>
    `;

    const modal = App.openModal(html, { width: 900 });

    modal.querySelector('#mp-bulk-close').onclick = () => App.closeModal(modal);

    modal.querySelector('#mp-bulk-file-btn').onclick = () => modal.querySelector('#mp-bulk-file').click();
    modal.querySelector('#mp-bulk-file').onchange = async (ev) => {
        const file = ev.target.files[0];
        if (!file) return;
        const ext = file.name.split('.').pop().toLowerCase();
        if (ext === 'csv') {
            const text = await file.text();
            modal.querySelector('#mp-bulk-csv').value = text;
        } else {
            // Excel — XLSX 사용 (operation/index.php 에서 이미 로드됨)
            const buf = await file.arrayBuffer();
            const wb = XLSX.read(buf);
            const ws = wb.Sheets[wb.SheetNames[0]];
            const csv = XLSX.utils.sheet_to_csv(ws);
            modal.querySelector('#mp-bulk-csv').value = csv;
        }
    };

    modal.querySelector('#mp-bulk-validate').onclick = async () => {
        const csv = modal.querySelector('#mp-bulk-csv').value;
        if (!csv.trim()) { Toast.error('CSV 가 비어있습니다.'); return; }
        try {
            const r = await App.post('/api/admin.php?action=multipass_bulk_validate', { csv });
            parsedRows = r.rows || [];
            const summary = r.summary || { ok: 0, warn: 0, error: 0 };
            modal.querySelector('#mp-bulk-summary').innerHTML = `
                정상 <strong>${summary.ok}</strong>건 ·
                WARN <strong style="color:#f59e0b">${summary.warn}</strong>건 ·
                ERROR <strong style="color:#dc2626">${summary.error}</strong>건
            `;
            modal.querySelector('#mp-bulk-table').innerHTML = renderBulkTable(parsedRows);
            modal.querySelector('#mp-bulk-result').style.display = '';
        } catch (e) {
            Toast.error('검증 실패: ' + (e.message || e));
        }
    };

    modal.querySelector('#mp-bulk-apply').onclick = async () => {
        if (!parsedRows) return;
        // 운영자 결정 (mode) 수집
        const applyRows = parsedRows
            .filter(r => !String(r.status || '').startsWith('ERROR_'))
            .map((r, idx) => {
                const modeSelect = modal.querySelector(`select[data-row="${r.row}"]`);
                const mode = modeSelect ? modeSelect.value : null;
                return mode ? { ...r, mode } : r;
            });
        if (!applyRows.length) { Toast.error('적용할 행이 없습니다.'); return; }
        try {
            const r = await App.post('/api/admin.php?action=multipass_bulk_apply', { rows: applyRows });
            const failedMsg = r.failed && r.failed.length ?
                `\n실패 ${r.failed.length}건:\n` + r.failed.map(f => `  행 ${f.row}: ${f.error}`).join('\n') : '';
            Toast.success(`적용 완료: ${r.applied}건${failedMsg}`);
            App.closeModal(modal);
            const cur = document.getElementById('mp-search-input');
            if (cur && cur.value) doSearch(cur.value);
        } catch (e) {
            Toast.error('적용 실패: ' + (e.message || e));
        }
    };
}

function renderBulkTable(rows) {
    return `
        <table class="data-table mp-bulk-table">
            <thead>
                <tr><th>#</th><th>user_id</th><th>상품명</th><th>기수</th><th>상태</th><th>처리</th></tr>
            </thead>
            <tbody>
                ${rows.map(r => {
                    const status = r.status || '';
                    const statusClass = status.startsWith('ERROR_') ? 'mp-status-err'
                                      : status.startsWith('WARN_')  ? 'mp-status-warn' : 'mp-status-ok';
                    let modeCtrl = '';
                    if (status === 'WARN_DUPLICATE_PASS' || status === 'WARN_DUPLICATE_PASS_IN_BATCH') {
                        const target = status === 'WARN_DUPLICATE_PASS'
                            ? `기존 pass#${r.existing_pass_id}`
                            : `같은 파일 행 ${r.target_pass_in_batch}`;
                        modeCtrl = `
                            <select data-row="${r.row}">
                                <option value="extend">extend (${target} 에 cohort 추가)</option>
                                <option value="new">new (별도 다회권)</option>
                                <option value="skip">skip (적용 안 함)</option>
                            </select>
                        `;
                    }
                    return `<tr>
                        <td>${r.row || ''}</td>
                        <td>${App.esc(r.user_id || '')}</td>
                        <td>${App.esc(r.product_name || '')}</td>
                        <td>${(r.cohort_labels || []).join(', ')}</td>
                        <td class="${statusClass}">${App.esc(status)}${r.unmatched_labels ? ' [' + r.unmatched_labels.join(',') + ']' : ''}</td>
                        <td>${modeCtrl}</td>
                    </tr>`;
                }).join('')}
            </tbody>
        </table>
    `;
}
```

- [ ] **Step 2: Smoke in browser**

회원별 보기 → [CSV 일괄] → textarea 에 5행 CSV (정상 3 + WARN 1 + ERROR 1) 붙여넣기 → [검증] → 표 노출 → [적용] → Toast.

테스트 CSV:
```
user_id,product_name,cohorts
__test_csv1@k,묶음A,"11,12"
__test_csv2@k,묶음B,"11"
__unknown_user@k,묶음C,"11,12"
__test_csv1@k,묶음A,"13"
__test_csv1@k,오류건,"예비"
```

(`__test_csv1` 행 4 는 행 1 과 동일 (user_id, product_name) → WARN_DUPLICATE_PASS_IN_BATCH)

- [ ] **Step 3: Cleanup test data after smoke**

```bash
cd /root/boot-dev && php -r "
require __DIR__.'/public_html/config.php';
\$db = getDB();
\$db->exec(\"DELETE FROM multipass WHERE user_id LIKE '__test_csv%' OR user_id LIKE '__unknown_user%'\");
"
```

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && git add public_html/js/admin-multipass.js && git commit -m "$(cat <<'EOF'
feat(multipass): CSV 일괄 모달

CSV paste/Excel 업로드 → 서버 측 파싱 → 검증 표 (WARN 행 mode 라디오) → 적용.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: 상품별 보기

**Files:**
- Modify: `public_html/js/admin-multipass.js` (`renderProductsView` 채움)

- [ ] **Step 1: Replace `renderProductsView`**

Edit `public_html/js/admin-multipass.js` — replace the placeholder:

```javascript
async function renderProductsView() {
    const body = document.getElementById('mp-body');
    body.innerHTML = '<p>로딩 중...</p>';
    try {
        const r = await App.get('/api/admin.php?action=multipass_list');
        const passes = r.passes || [];
        if (!passes.length) {
            body.innerHTML = '<p>등록된 다회권이 없습니다.</p>';
            return;
        }
        // 상품명 grouping
        const groups = {};
        passes.forEach(p => {
            (groups[p.product_name] ||= []).push(p);
        });
        const cards = Object.entries(groups).map(([name, ps]) => {
            const buyers = ps.length;
            const totalCohorts = ps.reduce((s, p) => s + p.cohorts.length, 0);
            const joined = ps.reduce((s, p) => s + p.cohorts.filter(c => c.joined).length, 0);
            const avg = (joined / buyers).toFixed(1);
            return `
                <div class="mp-product-card" data-name="${App.esc(name)}">
                    <div class="mp-product-name">${App.esc(name)}</div>
                    <div class="mp-product-stats">구매자 <strong>${buyers}</strong>명 · 평균 수강 <strong>${avg}</strong>기 / ${(totalCohorts/buyers).toFixed(0)}기</div>
                </div>
            `;
        }).join('');
        body.innerHTML = `
            <div class="mp-product-grid">${cards}</div>
            <div id="mp-product-detail"></div>
        `;
        body.querySelectorAll('.mp-product-card').forEach(card => {
            card.addEventListener('click', () => renderProductDetail(card.dataset.name, groups[card.dataset.name]));
        });
    } catch (e) {
        body.innerHTML = `<p class="text-danger">로딩 실패: ${App.esc(e.message || e)}</p>`;
    }
}

function renderProductDetail(name, passes) {
    const detail = document.getElementById('mp-product-detail');
    // 그 상품에 포함된 cohort 합집합 (start_date 정렬은 cohorts 마스터 기준)
    const cohortIds = new Set();
    passes.forEach(p => p.cohorts.forEach(c => cohortIds.add(c.cohort_id)));
    const cohortList = cohorts.filter(c => cohortIds.has(c.id))
        .sort((a, b) => new Date(a.start_date) - new Date(b.start_date));

    const headers = cohortList.map(c => `<th>${App.esc(c.cohort)}</th>`).join('');
    const rows = passes.map(p => {
        const byCid = {};
        p.cohorts.forEach(c => byCid[c.cohort_id] = c);
        const profile = (p.user_id) ? '' : '';
        const cells = cohortList.map(c => {
            const r = byCid[c.id];
            if (!r) return '<td>-</td>';
            const badge = r.joined ? '✅' : '⚪';
            const coupon = r.coupon_issued ? '🎟' : '';
            return `<td>${badge}${coupon}</td>`;
        }).join('');
        const remain = p.cohorts.filter(c => !c.joined).length;
        return `<tr class="mp-product-row" data-user-id="${App.esc(p.user_id)}">
            <td>${App.esc(p.user_id)}</td>
            ${cells}
            <td>${remain}</td>
        </tr>`;
    }).join('');
    detail.innerHTML = `
        <h4>상품: ${App.esc(name)} · ${passes.length}명</h4>
        <table class="data-table mp-product-table">
            <thead>
                <tr><th>user_id</th>${headers}<th>남은</th></tr>
            </thead>
            <tbody>${rows}</tbody>
        </table>
    `;
    detail.querySelectorAll('.mp-product-row').forEach(tr => {
        tr.addEventListener('click', () => {
            // 회원별 보기로 점프
            container.querySelector('.multipass-subtab[data-sub="members"]').click();
            renderMembersView(tr.dataset.userId);
        });
    });
}
```

- [ ] **Step 2: Smoke in browser**

다회권 [상품별 보기] → 카드 그리드 → 카드 클릭 → 표 펼침 → 회원 행 클릭 → 회원별 보기로 점프 (검색창에 user_id 채워짐).

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add public_html/js/admin-multipass.js && git commit -m "$(cat <<'EOF'
feat(multipass): 상품별 보기

product_name DISTINCT 카드 + 카드 클릭 시 cohorts 합집합 표.
표 행 클릭 시 회원별 보기로 점프.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: CSS 스타일

**Files:**
- Modify: `public_html/css/admin.css` (multipass 전용 클래스 추가)

- [ ] **Step 1: Append multipass styles**

Append to `public_html/css/admin.css`:

```css
/* ── Multipass (다회권) ───────────────────────────────────── */
.multipass { display: flex; flex-direction: column; gap: 16px; padding: 16px; }
.multipass-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
.multipass-subtabs { display: flex; gap: 4px; }
.multipass-subtab { padding: 6px 14px; border: 1px solid #d1d5db; background: #fff; cursor: pointer; border-radius: 6px; }
.multipass-subtab.active { background: #2563eb; color: #fff; border-color: #2563eb; }
.multipass-actions { display: flex; gap: 6px; }

.mp-search { margin-bottom: 12px; }
.mp-search input { width: 100%; max-width: 480px; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; }

.mp-member-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-bottom: 12px; background: #fff; }
.mp-member-header { font-size: 16px; margin-bottom: 8px; }
.mp-profile { color: #6b7280; font-size: 13px; margin-left: 8px; }
.mp-passes-label { color: #6b7280; font-size: 13px; margin: 8px 0; }

.mp-pass-card { border: 1px solid #d1d5db; border-radius: 6px; padding: 10px; margin-bottom: 8px; }
.mp-pass-header { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
.mp-pass-meta { color: #9ca3af; font-size: 12px; }
.mp-pass-actions { display: flex; gap: 4px; }
.mp-pass-summary { color: #6b7280; font-size: 13px; margin: 6px 0; }
.mp-pass-cohorts { display: flex; flex-direction: column; gap: 4px; }

.mp-cohort-row { display: grid; grid-template-columns: 24px 1fr 80px auto; gap: 8px; align-items: center; padding: 4px 0; }
.mp-cohort-badge { font-size: 16px; }
.mp-cohort-name { font-weight: 500; }
.mp-cohort-status { color: #6b7280; font-size: 13px; }
.mp-inactive { color: #9ca3af; font-size: 11px; margin-left: 4px; }
.mp-coupon-toggle { display: flex; align-items: center; gap: 4px; font-size: 13px; cursor: pointer; }
.mp-coupon-meta { grid-column: 2 / -1; color: #FF5E00; font-size: 11px; padding-left: 8px; }

/* 모달 폼 */
.mp-modal-body { padding: 16px; }
.mp-form-row { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.mp-form-row label { width: 110px; font-weight: 500; }
.mp-form-row input[type="text"], .mp-form-row textarea { flex: 1; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 4px; }
.mp-cohort-checks { display: flex; flex-wrap: wrap; gap: 8px; flex: 1; }
.mp-cohort-check { display: inline-flex; align-items: center; gap: 4px; }
.mp-cohort-inactive { opacity: 0.5; }
.mp-form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.mp-profile-ok { color: #16a34a; font-size: 13px; }
.mp-profile-warn { color: #f59e0b; font-size: 13px; }

/* CSV 일괄 모달 */
.mp-bulk-section { margin-bottom: 16px; }
.mp-bulk-step { font-weight: 600; margin-bottom: 8px; }
.mp-bulk-template { background: #f3f4f6; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; white-space: pre; overflow-x: auto; }
.mp-bulk-note { color: #6b7280; font-size: 12px; }
.mp-bulk textarea { width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-family: monospace; font-size: 12px; }
.mp-bulk-table th, .mp-bulk-table td { padding: 4px 8px; font-size: 12px; }
.mp-status-ok { color: #16a34a; font-weight: 500; }
.mp-status-warn { color: #f59e0b; font-weight: 500; }
.mp-status-err { color: #dc2626; font-weight: 500; }

/* 상품별 보기 */
.mp-product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; margin-bottom: 16px; }
.mp-product-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #fff; cursor: pointer; transition: box-shadow .15s; }
.mp-product-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.mp-product-name { font-weight: 600; margin-bottom: 6px; }
.mp-product-stats { color: #6b7280; font-size: 13px; }
.mp-product-table th, .mp-product-table td { padding: 4px 8px; text-align: center; }
.mp-product-row { cursor: pointer; }
.mp-product-row:hover { background: #f9fafb; }
```

- [ ] **Step 2: Smoke**

페이지 강력 새로고침 (Ctrl+Shift+R) → 카드/배지/모달 스타일 적용 확인.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add public_html/css/admin.css && git commit -m "$(cat <<'EOF'
feat(multipass): CSS 스타일

카드/배지/모달/CSV 표/상품별 그리드 — 기존 Soritune 톤(파랑 #2563eb / 오렌지 #FF5E00) 일치.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 14: PROD 인보리언트 스크립트

**Files:**
- Create: `tests/multipass_invariants.php`

- [ ] **Step 1: Create invariants script**

Create `tests/multipass_invariants.php`:

```php
<?php
/**
 * Multipass PROD 인보리언트 검증.
 * 사용: php tests/multipass_invariants.php
 *
 * 데이터 무결성 검증. 1건이라도 위반하면 exit 1.
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

// INV-1: orphan multipass_cohorts (존재 안 하는 cohort_id)
$sql1 = "SELECT COUNT(*) FROM multipass_cohorts mc LEFT JOIN cohorts c ON mc.cohort_id = c.id WHERE c.id IS NULL";
inv('INV-1 orphan cohorts', 0, (int)$db->query($sql1)->fetchColumn(), $sql1);

// INV-2: 동일 (pass_id, cohort_id) 중복
$sql2 = "SELECT COUNT(*) FROM (SELECT pass_id, cohort_id FROM multipass_cohorts GROUP BY pass_id, cohort_id HAVING COUNT(*) > 1) t";
inv('INV-2 duplicate', 0, (int)$db->query($sql2)->fetchColumn(), $sql2);

// INV-3: coupon_issued=1 인데 coupon_issued_at 이 NULL
$sql3 = "SELECT COUNT(*) FROM multipass_cohorts WHERE coupon_issued = 1 AND coupon_issued_at IS NULL";
inv('INV-3 coupon at consistency', 0, (int)$db->query($sql3)->fetchColumn(), $sql3);

// INV-4: coupon_issued=0 인데 coupon_issued_at 또는 coupon_issued_by 가 NULL 아님
$sql4 = "SELECT COUNT(*) FROM multipass_cohorts WHERE coupon_issued = 0 AND (coupon_issued_at IS NOT NULL OR coupon_issued_by IS NOT NULL)";
inv('INV-4 coupon off cleanup', 0, (int)$db->query($sql4)->fetchColumn(), $sql4);

// INV-5: orphan pass_id (FK 가 잡고 있어야 0)
$sql5 = "SELECT COUNT(*) FROM multipass_cohorts mc LEFT JOIN multipass p ON mc.pass_id = p.id WHERE p.id IS NULL";
inv('INV-5 orphan pass', 0, (int)$db->query($sql5)->fetchColumn(), $sql5);

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: Run on DEV**

```bash
cd /root/boot-dev && php tests/multipass_invariants.php
```

Expected: `5 pass, 0 fail`

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add tests/multipass_invariants.php && git commit -m "$(cat <<'EOF'
test(multipass): PROD 인보리언트 5건

orphan cohort/pass, (pass_id, cohort_id) 중복, coupon_issued at/by 일관성.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 15: DEV 통합 smoke (배포 전 최종 체크)

**Files:** (코드 변경 없음, 수동 체크리스트)

- [ ] **Step 1: Run all tests**

```bash
cd /root/boot-dev && \
  php tests/multipass_csv_parser_test.php && \
  php tests/multipass_repo_test.php && \
  php tests/multipass_invariants.php
```

Expected: 세 스크립트 모두 `0 fail`.

API 통합 테스트는 cookie 필요:

```bash
cd /root/boot-dev && ADMIN_COOKIE='PHPSESSID_ADMIN=...' DEV_BASE='https://dev-boot.soritune.com' php tests/multipass_api_test.php
```

Expected: `0 fail`.

- [ ] **Step 2: 브라우저 수동 smoke (5분)**

`https://dev-boot.soritune.com/operation` 에서:

1. [ ] "다회권 확인" 탭 button 표시
2. [ ] [+ 다회권 추가] → 모달 → user_id 입력 → [회원 조회] → 닉/실명 표시 → 저장 → 토스트 + 검색에 카드 표시
3. [ ] 카드의 쿠폰 체크박스 ON → DB UPDATE (`mysql ... -e "SELECT coupon_issued, coupon_issued_at FROM multipass_cohorts WHERE pass_id=N"`) → at 채워짐
4. [ ] 같은 체크박스 OFF → at NULL 복귀
5. [ ] [수정] → user_id 변경해서 저장 → 검색해도 보임 (새 user_id 로)
6. [ ] [CSV 일괄] → 5행 CSV 붙여넣기 → 검증 표 (정상/WARN/ERROR 구분 색상) → mode 라디오 → 적용
7. [ ] [상품별 보기] → 카드 → 표 펼침 → 행 클릭 → 회원별 보기로 점프
8. [ ] [삭제] → 컨펌 → 사라짐 → 인보리언트 재실행 (`php tests/multipass_invariants.php`) 0 fail

- [ ] **Step 3: dev push (사용자 확인 게이트)**

```bash
cd /root/boot-dev && git push origin dev
```

→ ⛔ **여기서 멈춤. 사용자에게 DEV 확인 요청.** "운영 반영해줘" 명시 요청 시에만 다음 task 진행.

---

## Task 16: PROD 배포 (사용자 명시 요청 시에만)

**Files:** (코드 변경 없음)

⛔ **사전 조건:** 사용자가 "운영 반영해줘" 등 명시적으로 요청한 경우에만 진행.

- [ ] **Step 1: main 머지 + push**

```bash
cd /root/boot-dev && git checkout main && git merge dev && git push origin main && git checkout dev
```

- [ ] **Step 2: PROD 마이그 먼저 (코드 pull 전)**

```bash
cd /root/boot-prod && php migrate_multipass.php
```

Expected: 두 테이블 생성 (PROD DB) + SHOW CREATE TABLE 출력.

이 시점에 PROD 코드는 아직 multipass 액션 없음 → 새 테이블 생성은 무해.

- [ ] **Step 3: PROD 코드 pull**

```bash
cd /root/boot-prod && git pull origin main
```

- [ ] **Step 4: PROD smoke (운영자 1명 사용 가정)**

브라우저에서 `https://boot.soritune.com/operation` → 다회권 확인 탭:

1. [ ] [+ 다회권 추가] → 실제 user_id 1건 등록 → 카드 노출
2. [ ] 쿠폰 체크 ON → at 표시 → OFF → 사라짐
3. [ ] [상품별 보기] → 카드 클릭 → 표
4. [ ] [삭제] → 컨펌 → 사라짐 (테스트 정리)

- [ ] **Step 5: PROD 인보리언트**

```bash
cd /root/boot-prod && php tests/multipass_invariants.php
```

Expected: `5 pass, 0 fail`.

- [ ] **Step 6: 운영자에게 CSV 일괄 임포트 안내**

수십 건 다회권 명단을 운영자가 CSV/Excel 로 정리 → [CSV 일괄] → 검증 → 적용.

배포 완료. 향후 단건 추가/수정/쿠폰 토글은 어드민 UI 사용.

---

## Self-Review Checklist (작성자 자체 점검)

- [x] **Spec coverage:** 데이터 모델, API 9 액션, CSV 임포트, UI 회원별/상품별, 마이그, 권한/감사, 테스트 인보리언트, 배포 순서 — 모두 task 매핑됨
- [x] **Placeholder scan:** "TBD"/"TODO"/"Add appropriate" 없음. 일부 helper 시그니처 (App.openModal width 옵션) 는 boot 내 실제 패턴 grep 후 조정 필요라고 명시
- [x] **Type consistency:** `createPass`/`updatePass`/`deletePass`/`toggleCoupon`/`findPasses`/`searchMembers`/`decorateCohorts` 함수 시그니처 task 3 에서 정의 후 task 5/9/10/11/12 에서 동일하게 사용
- [x] **Bulk module signature:** `validateMultipassBulk`/`applyMultipassBulk` task 4 정의, task 5 admin.php 에서 동일 호출
- [x] **Frontend identifier:** `AdminMultipassApp.init(adminSession, containerId)` task 8 stub → task 9 부터 동일 시그니처 유지
- [x] **WARN/ERROR status names:** task 4 (validate) → task 6 (테스트) → task 11 (CSV 모달 표) 에서 동일 문자열 (`OK`/`WARN_NO_MEMBER`/`WARN_DUPLICATE_PASS(_IN_BATCH)`/`ERROR_NO_USER_ID`/`ERROR_NO_PRODUCT`/`ERROR_NO_COHORTS`/`ERROR_COHORT_LABEL`)
- [x] **CSS class names:** task 9-12 에서 사용한 `.mp-*` 클래스가 task 13 CSS 에 모두 정의 (mp-member-card, mp-pass-card, mp-cohort-row, mp-bulk*, mp-product-*)
- [x] **DB schema vs queries:** task 1 의 컬럼명 (coupon_issued/coupon_issued_at/coupon_issued_by) 이 task 3 repo, task 14 인보리언트에서 동일하게 사용
- [x] **Deploy order:** task 15 (dev 검증 + push + 사용자 게이트) → task 16 (사용자 명시 요청 시에만 PROD migrate → code pull → smoke → invariants). spec 의 단일 절차와 일치
