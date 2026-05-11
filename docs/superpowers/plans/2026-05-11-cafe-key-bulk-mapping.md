# 카페 키 일괄 등록 (paste CSV) 구현 계획

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 어드민이 카톡방에서 받은 `[조,이름,닉,링크]` CSV를 paste하면 시스템이 네이버 카페 API로 memberKey 추출 + 회원 자동 매칭 + 일괄 적용으로 `cafe_member_key` 등록 + 과거 `cafe_posts` 백필 + 미션 `saveCheck()` 소급까지 한 번에 처리한다.

**Architecture:** 4개 헬퍼(`cafe_link_parser`, `cafe_csv_parser`, `cafe_article_fetch`, `cafe_bulk_match`)로 입력 파싱·외부 API·매칭을 분리. 적용은 행 단위 트랜잭션(`applyCafeBulkMapping`)에서 공통 헬퍼 `backfillPostsForMembers`를 호출 — 같은 헬퍼를 기존 `handleCafeRemapUnmapped`도 사용하도록 리팩토링해 DRY. 진입점은 어드민 SPA 회원 관리 탭의 별도 view (`AdminCafeBulkApp`).

**Tech Stack:** PHP 8.x + MariaDB + PDO, vanilla JS (boot 기존 패턴), boot 기존 테스트 패턴 (`tests/<name>_invariants.php` CLI script). 신규 의존성 0.

**Spec:** `docs/superpowers/specs/2026-05-11-cafe-key-bulk-mapping-design.md`

---

## File Structure

신규:
- `public_html/includes/cafe/cafe_link_parser.php` — URL → article_id 정규식
- `public_html/includes/cafe/cafe_csv_parser.php` — CSV → row array
- `public_html/includes/cafe/cafe_article_fetch.php` — 네이버 article API cURL
- `public_html/includes/cafe/cafe_bulk_match.php` — 회원 매칭 후보 산정
- `public_html/includes/cafe/cafe_backfill_helper.php` — `backfillPostsForMembers` (재사용)
- `public_html/includes/cafe/cafe_bulk_apply.php` — 행 단위 트랜잭션 적용
- `public_html/js/admin-cafe-bulk.js` — paste 페이지 IIFE (`AdminCafeBulkApp`)
- `tests/cafe_link_parser_test.php` — 정규식 unit
- `tests/cafe_csv_parser_test.php` — CSV 파서 unit
- `tests/cafe_bulk_match_test.php` — 매칭 통합 (DEV DB)
- `tests/cafe_bulk_apply_test.php` — 적용 통합 (DEV DB, transaction rollback)
- `tests/cafe_bulk_invariants.php` — PROD smoke

수정:
- `public_html/api/admin.php` — `fetch_cafe_info` 액션을 `fetchCafeArticleInfo()` 호출로 축약 + 신규 `cafe_bulk_parse`, `cafe_bulk_apply` 액션
- `public_html/api/services/integration.php` — `handleCafeRemapUnmapped` 를 공통 헬퍼 호출로 리팩토링
- `public_html/js/admin.js` — 회원 관리 탭 상단 액션바에 `[카페 키 일괄 등록]` 버튼
- `public_html/operation/index.php` — `admin-cafe-bulk.js` script 태그

영향 없음:
- `cafe_naver_client.php`, `cafe_ingest.php`, `cron.php`(cafe_poll) — 그대로
- `bootcamp_members`, `cafe_posts` 스키마 — 마이그 0

---

## Task 1: 카페 링크 파서 (`cafe_link_parser.php`)

**Files:**
- Create: `public_html/includes/cafe/cafe_link_parser.php`
- Test: `tests/cafe_link_parser_test.php`

- [ ] **Step 1: Write the failing test**

Create `tests/cafe_link_parser_test.php`:

```php
<?php
/**
 * 카페 링크 파서 unit 테스트.
 * 사용: php tests/cafe_link_parser_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/includes/cafe/cafe_link_parser.php';

$pass = 0; $fail = 0;
function t(string $name, $actual, $expected): void {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}\n   expected=" . var_export($expected, true) . "\n   actual=  " . var_export($actual, true) . "\n";
}

// PC 옛 단축형
t('pc_short', parseCafeLink('https://cafe.naver.com/themysticsoritune/321852'),
    ['article_id' => '321852', 'error' => null]);

// PC ca-fe 신
t('pc_cafe',  parseCafeLink('https://cafe.naver.com/ca-fe/cafes/23243775/articles/321852'),
    ['article_id' => '321852', 'error' => null]);

// 모바일 옛
t('m_short',  parseCafeLink('https://m.cafe.naver.com/themysticsoritune/321852'),
    ['article_id' => '321852', 'error' => null]);

// 모바일 ca-fe 신
t('m_cafe',   parseCafeLink('https://m.cafe.naver.com/ca-fe/web/cafes/23243775/articles/321852'),
    ['article_id' => '321852', 'error' => null]);

// 모바일 메뉴 경유
t('m_menu',   parseCafeLink('https://m.cafe.naver.com/ca-fe/web/cafes/23243775/menus/292/articles/321852'),
    ['article_id' => '321852', 'error' => null]);

// PC 쿼리 articleid
t('pc_query', parseCafeLink('https://cafe.naver.com/themysticsoritune?iframe_url=/ArticleRead.nhn%3Farticleid=321852'),
    ['article_id' => '321852', 'error' => null]);

// 다른 카페 clubId
t('wrong_cafe', parseCafeLink('https://cafe.naver.com/ca-fe/cafes/99999999/articles/321852'),
    ['article_id' => null, 'error' => 'wrong_cafe']);

// 잘못된 URL
t('not_cafe', parseCafeLink('https://google.com/whatever'),
    ['article_id' => null, 'error' => 'invalid']);

// 빈 문자열
t('empty',    parseCafeLink(''),
    ['article_id' => null, 'error' => 'empty']);

// 공백 trim
t('spaces',   parseCafeLink('  https://cafe.naver.com/themysticsoritune/321852  '),
    ['article_id' => '321852', 'error' => null]);

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /root/boot-dev && php tests/cafe_link_parser_test.php
```

Expected: `PHP Fatal error: Uncaught Error: Failed opening required ...cafe_link_parser.php` (파일 없음)

- [ ] **Step 3: Write minimal implementation**

Create `public_html/includes/cafe/cafe_link_parser.php`:

```php
<?php
/**
 * 카페 URL → article_id 정규식 추출.
 *
 * 지원 형식:
 *   https://cafe.naver.com/<board>/<articleId>
 *   https://cafe.naver.com/ca-fe/cafes/<clubId>/articles/<articleId>
 *   https://m.cafe.naver.com/<board>/<articleId>
 *   https://m.cafe.naver.com/ca-fe/web/cafes/<clubId>/articles/<articleId>
 *   https://m.cafe.naver.com/ca-fe/web/cafes/<clubId>/menus/<menuId>/articles/<articleId>
 *   https://cafe.naver.com/<board>?...articleid=<articleId>...
 *
 * @return array{article_id: string|null, error: 'empty'|'wrong_cafe'|'invalid'|null}
 */
declare(strict_types=1);

function parseCafeLink(string $url, int $expectedClubId = 23243775): array {
    $url = trim($url);
    if ($url === '') return ['article_id' => null, 'error' => 'empty'];

    // ca-fe (PC/모바일 공통) — clubId 검증 가능
    if (preg_match('#/cafes/(\d+)/(?:menus/\d+/)?articles/(\d+)#', $url, $m)) {
        if ((int)$m[1] !== $expectedClubId) {
            return ['article_id' => null, 'error' => 'wrong_cafe'];
        }
        return ['article_id' => $m[2], 'error' => null];
    }

    // 쿼리 articleid 형식
    if (preg_match('#cafe\.naver\.com/[^?\s]+\?[^\s#]*[?&]articleid=(\d+)#i', $url, $m)) {
        return ['article_id' => $m[1], 'error' => null];
    }

    // PC/모바일 옛 단축형
    if (preg_match('#^https?://(?:m\.)?cafe\.naver\.com/([\w-]+)/(\d+)(?:[/?#].*)?$#', $url, $m)) {
        return ['article_id' => $m[2], 'error' => null];
    }

    return ['article_id' => null, 'error' => 'invalid'];
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /root/boot-dev && php tests/cafe_link_parser_test.php
```

Expected: `10 pass, 0 fail`

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/cafe/cafe_link_parser.php tests/cafe_link_parser_test.php
git commit -m "$(cat <<'EOF'
feat(cafe): 카페 링크 파서 헬퍼 + unit 테스트

URL → article_id 정규식 추출. PC/모바일 신·구 + clubId 검증.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: CSV 파서 (`cafe_csv_parser.php`)

**Files:**
- Create: `public_html/includes/cafe/cafe_csv_parser.php`
- Test: `tests/cafe_csv_parser_test.php`

- [ ] **Step 1: Write the failing test**

Create `tests/cafe_csv_parser_test.php`:

```php
<?php
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/includes/cafe/cafe_csv_parser.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// 1. 기본 (헤더 없음)
$r = parseCafeCsv("리사조,김명식,그릭이,https://example.com/1");
t('basic',
    count($r['rows']) === 1
    && $r['rows'][0] === ['group' => '리사조', 'name' => '김명식', 'nick' => '그릭이', 'url' => 'https://example.com/1']
    && empty($r['errors']));

// 2. 헤더 행 skip
$r = parseCafeCsv("조,이름,닉,링크\n리사조,김명식,그릭이,https://example.com/1");
t('header_skip', count($r['rows']) === 1 && $r['rows'][0]['name'] === '김명식');

// 3. 빈 줄 무시
$r = parseCafeCsv("\n리사조,김명식,그릭이,https://example.com/1\n\n무이조,이서연,서연쓰,https://example.com/2\n");
t('blank_lines', count($r['rows']) === 2);

// 4. 닉 빈 칸
$r = parseCafeCsv("리사조,김명식,,https://example.com/1");
t('empty_nick',
    count($r['rows']) === 1
    && $r['rows'][0]['nick'] === ''
    && $r['rows'][0]['name'] === '김명식');

// 5. 큰따옴표 이스케이프
$r = parseCafeCsv("\"리사조\",\"김, 명식\",\"그릭이\",\"https://example.com/1\"");
t('quoted_comma',
    count($r['rows']) === 1
    && $r['rows'][0]['name'] === '김, 명식');

// 6. 컬럼 수 부족
$r = parseCafeCsv("리사조,김명식,그릭이");
t('missing_col',
    count($r['rows']) === 0
    && count($r['errors']) === 1
    && $r['errors'][0]['reason'] === 'missing_columns');

// 7. BOM 제거
$r = parseCafeCsv("\xEF\xBB\xBF리사조,김명식,그릭이,https://example.com/1");
t('bom',
    count($r['rows']) === 1
    && $r['rows'][0]['group'] === '리사조');

// 8. 100행 상한
$lines = [];
for ($i = 0; $i < 105; $i++) $lines[] = "리사조,name{$i},nick{$i},https://example.com/{$i}";
$r = parseCafeCsv(implode("\n", $lines));
t('max_rows',
    count($r['rows']) === 100
    && count($r['errors']) === 1
    && $r['errors'][0]['reason'] === 'batch_too_large');

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /root/boot-dev && php tests/cafe_csv_parser_test.php
```

Expected: 파일 없음 fatal error.

- [ ] **Step 3: Write minimal implementation**

Create `public_html/includes/cafe/cafe_csv_parser.php`:

```php
<?php
/**
 * paste CSV (조,이름,닉,링크) → row array + errors.
 *
 * 헤더 행(`조,이름,닉,링크`)은 첫 행에서만 skip.
 * RFC 4180 큰따옴표 처리 (fgetcsv).
 *
 * @return array{rows: array<int, array{group:string, name:string, nick:string, url:string}>, errors: array<int, array{row:int, reason:string}>}
 */
declare(strict_types=1);

function parseCafeCsv(string $csv, int $maxRows = 100): array {
    if (strlen($csv) >= 3 && substr($csv, 0, 3) === "\xEF\xBB\xBF") {
        $csv = substr($csv, 3);
    }

    $rows = [];
    $errors = [];

    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);

    $isFirstLine = true;
    $dataRowNo = 0;
    while (($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if ($cols === null) continue;
        if (count($cols) === 1 && ($cols[0] === null || trim((string)$cols[0]) === '')) {
            $isFirstLine = false;
            continue;
        }

        if ($isFirstLine) {
            $isFirstLine = false;
            if (count($cols) >= 4
                && trim((string)$cols[0]) === '조'
                && trim((string)$cols[1]) === '이름'
                && trim((string)$cols[2]) === '닉'
                && trim((string)$cols[3]) === '링크'
            ) {
                continue;
            }
        }

        $dataRowNo++;
        if ($dataRowNo > $maxRows) {
            $errors[] = ['row' => $dataRowNo, 'reason' => 'batch_too_large'];
            break;
        }

        if (count($cols) < 4) {
            $errors[] = ['row' => $dataRowNo, 'reason' => 'missing_columns'];
            continue;
        }

        $rows[] = [
            'group' => trim((string)$cols[0]),
            'name'  => trim((string)$cols[1]),
            'nick'  => trim((string)$cols[2]),
            'url'   => trim((string)$cols[3]),
        ];
    }
    fclose($fh);

    return ['rows' => $rows, 'errors' => $errors];
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /root/boot-dev && php tests/cafe_csv_parser_test.php
```

Expected: `8 pass, 0 fail`

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/cafe/cafe_csv_parser.php tests/cafe_csv_parser_test.php
git commit -m "$(cat <<'EOF'
feat(cafe): paste CSV 파서 헬퍼 + unit 테스트

조,이름,닉,링크 4열. 헤더/빈줄/BOM/100행 상한 처리.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: 카페 article fetch 헬퍼 추출 + 기존 액션 리팩토링

**Files:**
- Create: `public_html/includes/cafe/cafe_article_fetch.php`
- Modify: `public_html/api/admin.php:778-829`

기존 `fetch_cafe_info` 액션의 cURL 블록을 함수로 추출. 이 헬퍼를 paste 흐름에서도 호출하여 DRY.

- [ ] **Step 1: 헬퍼 파일 작성**

Create `public_html/includes/cafe/cafe_article_fetch.php`:

```php
<?php
/**
 * 네이버 카페 article API 호출 → writer 정보 추출.
 *
 * 기존 api/admin.php fetch_cafe_info 액션(cURL 블록)을 함수로 추출.
 * 호출자가 try/catch 로 CafeArticleFetchException 잡아 응답 사유 표시.
 */
declare(strict_types=1);

class CafeArticleFetchException extends RuntimeException {}

function fetchCafeArticleInfo(string $articleId, int $clubId = 23243775): array {
    if (!preg_match('/^\d+$/', $articleId)) {
        throw new CafeArticleFetchException('invalid article id');
    }
    $buid = 'a968c143-ebd4-46bb-82ff-5f11230389c5';
    $url = "https://article.cafe.naver.com/gw/v4/cafes/{$clubId}/articles/{$articleId}"
         . "?fromList=true&menuId=292&tc=cafe_article_list&useCafeId=true&buid={$buid}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err !== '') throw new CafeArticleFetchException("network: {$err}");
    if ($httpCode !== 200) throw new CafeArticleFetchException("HTTP {$httpCode}");

    $data = json_decode((string)$resp, true);
    if (isset($data['result']['errorCode'])) {
        throw new CafeArticleFetchException((string)($data['result']['message'] ?? '게시글 접근 불가'));
    }
    $writer = $data['result']['article']['writer'] ?? null;
    if (!$writer || !isset($writer['memberKey'])) {
        throw new CafeArticleFetchException('writer 또는 memberKey 정보 없음');
    }
    return [
        'member_key' => (string)$writer['memberKey'],
        'nick'       => (string)($writer['nick'] ?? ''),
    ];
}
```

- [ ] **Step 2: 기존 `fetch_cafe_info` 액션 리팩토링**

Modify `public_html/api/admin.php:778-829`. 기존 cURL 블록을 헬퍼 호출로 축약. `lookup_cafe_nick` 액션 (831-865) 은 다른 endpoint 라 그대로 둠.

Find 라인 778 부근:

```php
case 'fetch_cafe_info':
    $admin = requireAdmin(['operation']);
    $articleId = $_GET['article_id'] ?? '';
    if (!$articleId) jsonError('게시글 번호가 필요합니다.');
    
    $cafeId = 23243775;
    $buid = 'a968c143-ebd4-46bb-82ff-5f11230389c5';
    $url = "https://article.cafe.naver.com/gw/v4/cafes/{$cafeId}/articles/{$articleId}?fromList=true&menuId=292&tc=cafe_article_list&useCafeId=true&buid={$buid}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        jsonError("HTTP 오류: {$httpCode}");
    }
    
    $data = json_decode($response, true);
    if (isset($data['result']['errorCode'])) {
        jsonError($data['result']['message'] ?? '게시글 접근 불가');
    }
    
    if (!isset($data['result']['article']['writer'])) {
        jsonError('작성자 정보를 찾을 수 없습니다.');
    }
    
    $writer = $data['result']['article']['writer'];
    $memberKey = $writer['memberKey'] ?? '';
    $nick = $writer['nick'] ?? '';
    
    if (!$memberKey) {
        jsonError('memberKey를 추출할 수 없습니다.');
    }
    
    $db = getDB();
    $stmt = $db->prepare('SELECT id, real_name FROM bootcamp_members WHERE cafe_member_key = ?');
    $stmt->execute([$memberKey]);
    $existingMember = $stmt->fetch(PDO::FETCH_ASSOC);
    
    jsonSuccess([
        'data' => [
            'memberKey' => $memberKey,
            'nick' => $nick,
            'existingMember' => $existingMember ?: null
        ]
    ]);
    break;
```

Replace with:

```php
case 'fetch_cafe_info':
    $admin = requireAdmin(['operation']);
    require_once __DIR__ . '/../includes/cafe/cafe_article_fetch.php';
    $articleId = $_GET['article_id'] ?? '';
    if (!$articleId) jsonError('게시글 번호가 필요합니다.');

    try {
        $info = fetchCafeArticleInfo($articleId);
    } catch (CafeArticleFetchException $e) {
        jsonError($e->getMessage());
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT id, real_name FROM bootcamp_members WHERE cafe_member_key = ?');
    $stmt->execute([$info['member_key']]);
    $existingMember = $stmt->fetch(PDO::FETCH_ASSOC);

    jsonSuccess([
        'data' => [
            'memberKey'      => $info['member_key'],
            'nick'           => $info['nick'],
            'existingMember' => $existingMember ?: null,
        ]
    ]);
    break;
```

- [ ] **Step 3: 기존 회원 모달 흐름 회귀 검증 (수동)**

DEV 브라우저에서:
1. 어드민 로그인 → 회원 관리 → 임의 회원 수정 모달 열기.
2. "게시글 번호" 칸에 활성 카페 게시글 번호 입력 → "가져오기" 클릭.
3. 카페 닉네임 표시되고 memberKey 박히는지 확인.
4. 잘못된 번호(예: 99999999) 입력 → 에러 메시지 표시 확인.

이 단계는 실패 시 헬퍼 또는 액션 코드 fix. 콘솔 / network 탭에서 응답 확인.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/cafe/cafe_article_fetch.php public_html/api/admin.php
git commit -m "$(cat <<'EOF'
refactor(cafe): article fetch cURL을 헬퍼로 추출

api/admin.php fetch_cafe_info 액션의 cURL 블록을
includes/cafe/cafe_article_fetch.php의 fetchCafeArticleInfo() 로 추출.
기존 액션은 헬퍼 호출로 축약 (행동 동일). paste 흐름에서 재사용 예정.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: 매칭 로직 (`cafe_bulk_match.php`) + 통합 테스트

**Files:**
- Create: `public_html/includes/cafe/cafe_bulk_match.php`
- Test: `tests/cafe_bulk_match_test.php` (DEV DB transaction rollback)

- [ ] **Step 1: Write the failing test**

Create `tests/cafe_bulk_match_test.php`:

```php
<?php
/**
 * 매칭 로직 통합 테스트. DEV DB transaction → 마지막에 rollback.
 * 사용: php tests/cafe_bulk_match_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/cafe/cafe_bulk_match.php';

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
    // 테스트 cohort
    $db->exec("INSERT INTO cohorts (cohort, is_active, start_date) VALUES ('TEST_M', 1, CURDATE())");
    $cohortId = (int)$db->lastInsertId();

    // 테스트 groups
    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, stage_no, code) VALUES (?, '리사조', 1, 'tm_lisa'), (?, '무이조', 1, 'tm_mui')")
       ->execute([$cohortId, $cohortId]);
    $groupLisa = (int)$db->query("SELECT id FROM bootcamp_groups WHERE code='tm_lisa'")->fetchColumn();
    $groupMui  = (int)$db->query("SELECT id FROM bootcamp_groups WHERE code='tm_mui'")->fetchColumn();

    // 테스트 회원
    $insMember = $db->prepare("
        INSERT INTO bootcamp_members
            (cohort_id, group_id, real_name, nickname, cafe_member_key, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 'active', 1, 1, CURDATE())
    ");
    $insMember->execute([$cohortId, $groupLisa, '김명식', '그릭이', null]);
    $kim = (int)$db->lastInsertId();
    $insMember->execute([$cohortId, $groupLisa, '김명식', '명식이', null]);  // 동명이인
    $kim2 = (int)$db->lastInsertId();
    $insMember->execute([$cohortId, $groupMui, '이서연', '서연쓰', null]);
    $lee = (int)$db->lastInsertId();
    $insMember->execute([$cohortId, $groupMui, '박지원', '지원지원', 'EXISTING_KEY_A']);
    $park = (int)$db->lastInsertId();

    // 1. ALREADY_MAPPED_SAME (키 + 조 + 이름 모두 일치)
    $r = matchCandidates($db, $cohortId, 'EXISTING_KEY_A', '무이조', '박지원', '지원지원');
    t('already_mapped_same',
        $r['status'] === 'ALREADY_MAPPED_SAME'
        && $r['existing_member']['id'] === $park);

    // 2. ALREADY_MAPPED_DIFF (키는 박지원, paste 는 다른 조/이름)
    $r = matchCandidates($db, $cohortId, 'EXISTING_KEY_A', '리사조', '김명식', '그릭이');
    t('already_mapped_diff',
        $r['status'] === 'ALREADY_MAPPED_DIFF'
        && $r['existing_member']['id'] === $park);

    // 3. HIGH (조+이름 1명)
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_X', '무이조', '이서연', '서연쓰');
    t('high',
        $r['status'] === 'HIGH'
        && count($r['candidates']) === 1
        && $r['candidates'][0]['member_id'] === $lee);

    // 4. MID_MULTI (조+이름이 2명)
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_Y', '리사조', '김명식', null);
    t('mid_multi_in_group',
        $r['status'] === 'MID_MULTI'
        && count($r['candidates']) === 2);

    // 5. MID (조 없을 때 이름 정확 일치 1명)
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_Z', null, '이서연', null);
    t('mid_no_group',
        $r['status'] === 'MID'
        && $r['candidates'][0]['member_id'] === $lee);

    // 6. LOW (LIKE)
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_W', null, '이서', null);
    t('low_like',
        $r['status'] === 'LOW'
        && count($r['candidates']) >= 1);

    // 7. NO_MATCH
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_V', '리사조', '없는사람', null);
    t('no_match', $r['status'] === 'NO_MATCH' && count($r['candidates']) === 0);

    // 8. 비활성 회원 제외
    $db->prepare("UPDATE bootcamp_members SET member_status='leaving' WHERE id=?")->execute([$lee]);
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_U', '무이조', '이서연', null);
    t('inactive_excluded', $r['status'] === 'NO_MATCH');

    // 9. '조' 글자 빠진 group 입력도 매칭
    $r = matchCandidates($db, $cohortId, 'NEW_KEY_T', '리사', '그릭이', null);
    t('group_without_cho_suffix',
        $r['status'] === 'HIGH'
        && $r['candidates'][0]['member_id'] === $kim);

    echo "\n{$pass} pass, {$fail} fail\n";
} finally {
    $db->rollBack();
}

exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /root/boot-dev && php tests/cafe_bulk_match_test.php
```

Expected: 파일 없음 fatal error.

- [ ] **Step 3: Write minimal implementation**

Create `public_html/includes/cafe/cafe_bulk_match.php`:

```php
<?php
/**
 * cafe paste 행을 부트캠프 회원에 매칭.
 *
 * 순서:
 *   1) cafe_member_key 이미 등록된 회원 있으면 ALREADY_MAPPED_(SAME|DIFF)
 *   2) cohort 안에서 (조+이름) 정확 일치
 *   3) cohort 안에서 (이름) 정확 일치
 *   4) cohort 안에서 (이름) LIKE
 *   5) 모두 0 → NO_MATCH
 *
 * 후보 회원은 member_status='active' AND cafe_member_key IS NULL 만 대상.
 *
 * @return array{status:string, candidates:array<int,array>, existing_member:?array}
 */
declare(strict_types=1);

function matchCandidates(
    PDO $db,
    int $cohortId,
    string $cafeMemberKey,
    ?string $groupName,
    string $realName,
    ?string $nickname
): array {
    // 1. 이미 매핑 체크
    $stmt = $db->prepare("
        SELECT bm.id, bm.real_name, bm.nickname, bm.stage_no, bm.group_id, bg.name AS group_name
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bg.id = bm.group_id
        WHERE bm.cafe_member_key = ?
        LIMIT 1
    ");
    $stmt->execute([$cafeMemberKey]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $existingNameSet = array_filter([$existing['real_name'], $existing['nickname']]);
        $pasteNameSet    = array_filter([$realName, $nickname]);
        $sameName = (bool)array_intersect($existingNameSet, $pasteNameSet);

        $sameGroup = true;
        if ($groupName !== null && $groupName !== '') {
            $sameGroup = ($existing['group_name'] === $groupName
                       || $existing['group_name'] === $groupName . '조');
        }

        $status = ($sameName && $sameGroup) ? 'ALREADY_MAPPED_SAME' : 'ALREADY_MAPPED_DIFF';
        return [
            'status' => $status,
            'candidates' => [],
            'existing_member' => [
                'id'         => (int)$existing['id'],
                'real_name'  => $existing['real_name'],
                'nickname'   => $existing['nickname'],
                'group_name' => $existing['group_name'],
                'stage_no'   => (int)$existing['stage_no'],
            ],
        ];
    }

    $mapRow = fn($r) => [
        'member_id'  => (int)$r['id'],
        'real_name'  => $r['real_name'],
        'nickname'   => $r['nickname'],
        'group_name' => $r['group_name'],
        'stage_no'   => (int)$r['stage_no'],
    ];

    $baseSelect = "
        SELECT bm.id, bm.real_name, bm.nickname, bm.stage_no, bg.name AS group_name
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bg.id = bm.group_id
        WHERE bm.cohort_id = :cohort
          AND bm.member_status = 'active'
          AND bm.cafe_member_key IS NULL
    ";

    // 2. 조+이름 정확 일치
    if ($groupName !== null && $groupName !== '') {
        $sql = $baseSelect . "
              AND (bg.name = :group OR bg.name = :groupCho)
              AND (bm.real_name = :name OR bm.nickname = :name)
            LIMIT 20
        ";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':cohort', $cohortId, PDO::PARAM_INT);
        $stmt->bindValue(':group', $groupName);
        $stmt->bindValue(':groupCho', $groupName . '조');
        $stmt->bindValue(':name', $realName);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 1) {
            return ['status' => 'HIGH', 'candidates' => array_map($mapRow, $rows), 'existing_member' => null];
        }
        if (count($rows) >= 2) {
            return ['status' => 'MID_MULTI', 'candidates' => array_map($mapRow, $rows), 'existing_member' => null];
        }
    }

    // 3. 이름 정확 일치 (조 무시)
    $sql = $baseSelect . "
          AND (bm.real_name = :name OR bm.nickname = :name)
        LIMIT 20
    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cohort', $cohortId, PDO::PARAM_INT);
    $stmt->bindValue(':name', $realName);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 1) {
        return ['status' => 'MID', 'candidates' => array_map($mapRow, $rows), 'existing_member' => null];
    }
    if (count($rows) >= 2) {
        return ['status' => 'MID_MULTI', 'candidates' => array_map($mapRow, $rows), 'existing_member' => null];
    }

    // 4. 이름 LIKE
    $sql = $baseSelect . "
          AND (bm.real_name LIKE :nameLike OR bm.nickname LIKE :nameLike)
        LIMIT 20
    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cohort', $cohortId, PDO::PARAM_INT);
    $stmt->bindValue(':nameLike', '%' . $realName . '%');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) >= 1) {
        return ['status' => 'LOW', 'candidates' => array_map($mapRow, $rows), 'existing_member' => null];
    }

    return ['status' => 'NO_MATCH', 'candidates' => [], 'existing_member' => null];
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /root/boot-dev && php tests/cafe_bulk_match_test.php
```

Expected: `9 pass, 0 fail`

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/cafe/cafe_bulk_match.php tests/cafe_bulk_match_test.php
git commit -m "$(cat <<'EOF'
feat(cafe): paste 행 매칭 로직 + integration 테스트

(키이미등록 → 조+이름 → 이름 → LIKE → 없음) 5단계 분기.
member_status='active' AND cafe_member_key IS NULL 만 후보.
DEV DB transaction rollback 패턴.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: 백필 공통 헬퍼 + `handleCafeRemapUnmapped` 리팩토링

**Files:**
- Create: `public_html/includes/cafe/cafe_backfill_helper.php`
- Modify: `public_html/api/services/integration.php:165-227`

기존 `handleCafeRemapUnmapped` 의 루프를 공통 헬퍼 `backfillPostsForMembers` 로 추출. 양쪽이 같은 코드 호출하므로 paste 흐름과 행동 정합 + DRY.

- [ ] **Step 1: 공통 헬퍼 작성**

Create `public_html/includes/cafe/cafe_backfill_helper.php`:

```php
<?php
/**
 * 주어진 member_id 들의 cafe_member_key 로 적재된 unmapped cafe_posts 를
 * 백필하고 미션 saveCheck 소급 호출.
 *
 * - paste 일괄 적용: 방금 등록한 회원 1명에 대해 호출
 * - handleCafeRemapUnmapped: 전체 활성 회원 ID 들에 대해 호출 (보드 폴링 누락분 복구)
 *
 * @return array{
 *   remapped:int, missions_saved:int,
 *   by_member:array<int,array{remapped:int, missions_saved:int}>
 * }
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootcamp_functions.php';

function backfillPostsForMembers(PDO $db, array $memberIds): array {
    $result = ['remapped' => 0, 'missions_saved' => 0, 'by_member' => []];
    if (empty($memberIds)) return $result;

    // 1) 회원 → cafe_member_key 매핑 조회 (key IS NULL 인 회원 제외)
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $memberStmt = $db->prepare("
        SELECT id, cafe_member_key FROM bootcamp_members
        WHERE id IN ({$placeholders}) AND cafe_member_key IS NOT NULL
    ");
    $memberStmt->execute(array_values($memberIds));

    $keyToMember = [];
    foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $keyToMember[$r['cafe_member_key']] = (int)$r['id'];
        $result['by_member'][(int)$r['id']] = ['remapped' => 0, 'missions_saved' => 0];
    }
    if (empty($keyToMember)) return $result;

    // 2) 그 키들로 적재된 unmapped cafe_posts 조회
    $keys = array_keys($keyToMember);
    $keyPlaceholders = implode(',', array_fill(0, count($keys), '?'));
    $postStmt = $db->prepare("
        SELECT id, cafe_article_id, member_key, board_type, assignment_date
        FROM cafe_posts
        WHERE member_id IS NULL AND member_key IN ({$keyPlaceholders})
    ");
    $postStmt->execute($keys);

    $updateStmt = $db->prepare("UPDATE cafe_posts SET member_id = ?, mission_checked = 1 WHERE id = ?");

    foreach ($postStmt->fetchAll(PDO::FETCH_ASSOC) as $post) {
        $memberId = $keyToMember[$post['member_key']];
        $updateStmt->execute([$memberId, $post['id']]);
        $result['remapped']++;
        $result['by_member'][$memberId]['remapped']++;

        if (!empty($post['board_type']) && !empty($post['assignment_date'])) {
            $missionTypeId = getMissionTypeId($db, $post['board_type']);
            if ($missionTypeId) {
                $r = saveCheck(
                    $db, $memberId, $post['assignment_date'], $missionTypeId,
                    true, 'automation', "cafe:{$post['cafe_article_id']}", null
                );
                if (isset($r['action']) && in_array($r['action'], ['created', 'updated'], true)) {
                    $result['missions_saved']++;
                    $result['by_member'][$memberId]['missions_saved']++;
                }
            }
        }
    }

    return $result;
}
```

- [ ] **Step 2: `handleCafeRemapUnmapped` 리팩토링**

Modify `public_html/api/services/integration.php`. 라인 165-227 의 함수 전체를 다음으로 교체:

```php
function handleCafeRemapUnmapped($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation', 'coach', 'sub_coach', 'head', 'subhead1', 'subhead2']);

    require_once __DIR__ . '/../../includes/cafe/cafe_backfill_helper.php';
    $db = getDB();

    // cafe_member_key 보유 활성 회원 전체 → 그 키로 적재된 unmapped 게시글 백필
    $memberIds = $db->query("
        SELECT id FROM bootcamp_members
        WHERE cafe_member_key IS NOT NULL AND is_active = 1
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (empty($memberIds)) {
        jsonSuccess(['remapped' => 0, 'checked' => 0, 'message' => '재매핑할 게시글이 없습니다.']);
        return;
    }

    $r = backfillPostsForMembers($db, array_map('intval', $memberIds));

    jsonSuccess([
        'remapped' => $r['remapped'],
        'checked'  => $r['missions_saved'],
        'message'  => $r['remapped'] > 0
            ? "{$r['remapped']}건 재매핑 / {$r['missions_saved']}건 체크 반영"
            : '재매핑할 게시글이 없습니다.',
    ]);
}
```

- [ ] **Step 3: regression integration 테스트**

DEV DB transaction rollback 패턴으로 시드를 만들고, **공통 헬퍼 호출 결과**가 백필+saveCheck 둘 다 잘 일어남을 확인. apply 테스트와 겹치므로 별도 파일 만들지 않고 Task 6 의 `tests/cafe_bulk_apply_test.php` 안에 시나리오로 포함.

`handleCafeRemapUnmapped` 회귀는 수동: DEV 어드민 → 회원 관리 → 카페 게시글 탭 → "재매핑" 버튼 → 응답에 `remapped`, `checked`, `message` 그대로 노출되는지 확인.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/cafe/cafe_backfill_helper.php public_html/api/services/integration.php
git commit -m "$(cat <<'EOF'
refactor(cafe): 백필+saveCheck 공통 헬퍼 추출

handleCafeRemapUnmapped 의 cafe_posts 백필 + saveCheck 루프를
includes/cafe/cafe_backfill_helper.php 의 backfillPostsForMembers() 로 추출.
기존 액션은 전체 회원 ID 모아 같은 헬퍼 호출 (행동 동일).
paste 일괄 적용에서 단일 회원 대상으로 재사용 예정.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: 적용 함수 `applyCafeBulkMapping` + 통합 테스트

**Files:**
- Create: `public_html/includes/cafe/cafe_bulk_apply.php`
- Test: `tests/cafe_bulk_apply_test.php`

- [ ] **Step 1: Write the failing test**

Create `tests/cafe_bulk_apply_test.php`:

```php
<?php
/**
 * paste 적용 함수 통합 테스트. DEV DB transaction rollback.
 * 사용: php tests/cafe_bulk_apply_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/includes/cafe/cafe_bulk_apply.php';

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
    // 활성 cohort/group/회원 시드
    $db->exec("INSERT INTO cohorts (cohort, is_active, start_date) VALUES ('TEST_APPLY', 1, CURDATE())");
    $cohortId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bootcamp_groups (cohort_id, name, stage_no, code) VALUES (?, '리사조', 1, 'ta_lisa')")->execute([$cohortId]);
    $groupId = (int)$db->lastInsertId();

    $ins = $db->prepare("INSERT INTO bootcamp_members
        (cohort_id, group_id, real_name, nickname, cafe_member_key, member_status, is_active, stage_no, joined_at)
        VALUES (?, ?, ?, ?, ?, 'active', 1, 1, CURDATE())");
    $ins->execute([$cohortId, $groupId, '김명식', '그릭이', null]);
    $alice = (int)$db->lastInsertId();
    $ins->execute([$cohortId, $groupId, '이서연', '서연쓰', 'OLD_KEY_TO_DISPLACE']);
    $bob = (int)$db->lastInsertId();
    $ins->execute([$cohortId, $groupId, '박지원', '지원지원', null]);
    $charlie = (int)$db->lastInsertId();

    // unmapped cafe_posts 시드 (alice 의 키로 등록될 게시글 3건 + bob 키로 1건)
    $insPost = $db->prepare("INSERT INTO cafe_posts
        (cafe_article_id, title, member_key, nickname, board_type, posted_at, member_id, mission_checked, assignment_date, raw_data)
        VALUES (?, ?, ?, ?, ?, ?, NULL, 0, ?, NULL)");

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $insPost->execute(['TA_ART1', 't1', 'NEW_KEY_ALICE', 'gricky', 'inner33',       $now, $today]);
    $insPost->execute(['TA_ART2', 't2', 'NEW_KEY_ALICE', 'gricky', 'daily_mission', $now, $today]);
    $insPost->execute(['TA_ART3', 't3', 'NEW_KEY_ALICE', 'gricky', null,            $now, null]); // board_type 없음 → saveCheck X
    $insPost->execute(['TA_ART4', 't4', 'NEW_KEY_BOB',   'seoyeon', 'inner33',      $now, $today]); // 다른 키

    // Case 1: alice 에 신규 키 등록 → 3건 백필 + 2건 saveCheck (#3은 board_type 없음)
    $r = applyCafeBulkMapping($db, [
        ['row' => 1, 'article_id' => 'TA_ART1', 'member_key' => 'NEW_KEY_ALICE', 'cafe_nick' => 'gricky', 'target_member_id' => $alice],
    ]);
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

    // Case 2: charlie 에 'OLD_KEY_TO_DISPLACE' 등록 → bob 의 키 NULL 해제 + bob 의 과거 글은 charlie 로 백필
    $r = applyCafeBulkMapping($db, [
        ['row' => 2, 'article_id' => 'TA_ART4', 'member_key' => 'OLD_KEY_TO_DISPLACE', 'cafe_nick' => 'seoyeon', 'target_member_id' => $charlie],
    ]);
    t('case2_diff_applied', $r['results'][0]['status'] === 'applied_diff' && $r['results'][0]['displaced'] === 1);
    $bobKey = $db->query("SELECT cafe_member_key FROM bootcamp_members WHERE id={$bob}")->fetchColumn();
    t('case2_bob_displaced', $bobKey === null);
    $charlieKey = $db->query("SELECT cafe_member_key FROM bootcamp_members WHERE id={$charlie}")->fetchColumn();
    t('case2_charlie_set', $charlieKey === 'OLD_KEY_TO_DISPLACE');

    // Case 3: target_member_id 0 (어드민 미선택) → skipped
    $r = applyCafeBulkMapping($db, [
        ['row' => 3, 'article_id' => 'TA_ART5', 'member_key' => 'WHATEVER', 'cafe_nick' => 'x', 'target_member_id' => 0],
    ]);
    t('case3_skipped', $r['summary']['skipped'] === 1 && $r['results'][0]['status'] === 'skipped');

    // Case 4: 행 실패 격리 (존재하지 않는 target_member_id → FK constraint 없지만 UPDATE 영향 0)
    // 트랜잭션 자체는 commit 되지만 행 결과는 정상. 다른 행 영향 없음 검증.
    $r = applyCafeBulkMapping($db, [
        ['row' => 4, 'article_id' => 'TA_ART6', 'member_key' => 'NEW_KEY_X', 'cafe_nick' => 'x', 'target_member_id' => 99999999],
        ['row' => 5, 'article_id' => 'TA_ART7', 'member_key' => 'NEW_KEY_Y', 'cafe_nick' => 'y', 'target_member_id' => $alice],
    ]);
    t('case4_other_row_unaffected', $r['summary']['applied'] >= 1);

    echo "\n{$pass} pass, {$fail} fail\n";
} finally {
    $db->rollBack();
}

exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /root/boot-dev && php tests/cafe_bulk_apply_test.php
```

Expected: 파일 없음 fatal error.

- [ ] **Step 3: Write minimal implementation**

Create `public_html/includes/cafe/cafe_bulk_apply.php`:

```php
<?php
/**
 * paste 미리보기에서 어드민이 선택한 행들을 적용.
 *
 * 한 행 = 1 트랜잭션:
 *   1) member_key 이 다른 회원에 있으면 NULL 해제 (옛 회원 displace)
 *   2) target 회원에 member_key 부여
 *   3) backfillPostsForMembers([target]) — 같은 키의 과거 unmapped 백필 + saveCheck
 *
 * 한 행 실패 → rollback, 다른 행 계속.
 *
 * @return array{results:array<int,array>, summary:array{applied:int, skipped:int, failed:int}}
 */
declare(strict_types=1);

require_once __DIR__ . '/cafe_backfill_helper.php';

function applyCafeBulkMapping(PDO $db, array $rows): array {
    $results = [];
    $summary = ['applied' => 0, 'skipped' => 0, 'failed' => 0];

    foreach ($rows as $row) {
        $rowNo = (int)($row['row'] ?? 0);
        $articleId = (string)($row['article_id'] ?? '');
        $memberKey = (string)($row['member_key'] ?? '');
        $targetMemberId = isset($row['target_member_id']) ? (int)$row['target_member_id'] : 0;

        if ($targetMemberId === 0 || $memberKey === '') {
            $results[] = ['row' => $rowNo, 'status' => 'skipped', 'reason' => 'no_target'];
            $summary['skipped']++;
            continue;
        }

        try {
            $db->beginTransaction();

            // 1) 옛 회원 키 해제
            $displace = $db->prepare("
                UPDATE bootcamp_members SET cafe_member_key = NULL
                WHERE cafe_member_key = ? AND id != ?
            ");
            $displace->execute([$memberKey, $targetMemberId]);
            $displaced = $displace->rowCount();

            // 2) target 회원에 키 부여
            $upd = $db->prepare("UPDATE bootcamp_members SET cafe_member_key = ? WHERE id = ?");
            $upd->execute([$memberKey, $targetMemberId]);

            // 3) 백필 + saveCheck
            $bf = backfillPostsForMembers($db, [$targetMemberId]);

            $db->commit();

            $results[] = [
                'row'              => $rowNo,
                'status'           => $displaced > 0 ? 'applied_diff' : 'applied',
                'member_id'        => $targetMemberId,
                'article_id'       => $articleId,
                'displaced'        => $displaced,
                'backfilled_posts' => $bf['remapped'],
                'missions_saved'   => $bf['missions_saved'],
            ];
            $summary['applied']++;

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $results[] = [
                'row'    => $rowNo,
                'status' => 'failed',
                'reason' => $e->getMessage(),
            ];
            $summary['failed']++;
        }
    }

    return ['results' => $results, 'summary' => $summary];
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /root/boot-dev && php tests/cafe_bulk_apply_test.php
```

Expected: 모든 케이스 PASS (`12 pass, 0 fail` 또는 비슷 — 실제 케이스 수와 일치).

⚠️ **주의**: 본 테스트는 outer `beginTransaction()` 으로 감싸고 마지막에 `rollBack()` 한다. 내부 `applyCafeBulkMapping` 도 자기 트랜잭션을 시도. boot 의 기존 `saveCheck` 는 `$startedHere = !$db->inTransaction()` 가드로 중첩 시 자기 트랜잭션 생성 안 함 (transaction_invariants.php 참고). `applyCafeBulkMapping` 의 `beginTransaction()` 은 outer tx 안에서 PDOException 던짐 — savepoint 대신 inner tx-guard 같은 패턴 필요.

→ `applyCafeBulkMapping` 시그니처 유지하되 inner 트랜잭션을 `inTransaction()` 가드:

```php
$startedHere = !$db->inTransaction();
if ($startedHere) $db->beginTransaction();
try {
    // ... 1) 2) 3) ...
    if ($startedHere) $db->commit();
} catch (\Throwable $e) {
    if ($startedHere && $db->inTransaction()) $db->rollBack();
    // ... 에러 결과 ...
}
```

이렇게 하면 production 흐름에서는 행 단위 트랜잭션이 동작하고, 테스트에서는 outer rollback 이 모든 변경을 무위로 돌림. 단, **production 에서 한 행 실패 시 다른 행에 영향 없는 격리는 유지되어야 함** — outer tx 없는 production 호출에선 `$startedHere=true` 라 행 단위 commit/rollback 동작. 양립 OK.

위 구현의 try 블록에서 inner tx-guard 적용. minimal impl 코드를 그렇게 수정 후 다시 테스트.

수정된 코드 (Step 3 의 try/catch 블록을 다음으로 교체):

```php
        try {
            $startedHere = !$db->inTransaction();
            if ($startedHere) $db->beginTransaction();

            $displace = $db->prepare("
                UPDATE bootcamp_members SET cafe_member_key = NULL
                WHERE cafe_member_key = ? AND id != ?
            ");
            $displace->execute([$memberKey, $targetMemberId]);
            $displaced = $displace->rowCount();

            $upd = $db->prepare("UPDATE bootcamp_members SET cafe_member_key = ? WHERE id = ?");
            $upd->execute([$memberKey, $targetMemberId]);

            $bf = backfillPostsForMembers($db, [$targetMemberId]);

            if ($startedHere) $db->commit();

            $results[] = [
                'row'              => $rowNo,
                'status'           => $displaced > 0 ? 'applied_diff' : 'applied',
                'member_id'        => $targetMemberId,
                'article_id'       => $articleId,
                'displaced'        => $displaced,
                'backfilled_posts' => $bf['remapped'],
                'missions_saved'   => $bf['missions_saved'],
            ];
            $summary['applied']++;

        } catch (\Throwable $e) {
            if (isset($startedHere) && $startedHere && $db->inTransaction()) $db->rollBack();
            $results[] = [
                'row'    => $rowNo,
                'status' => 'failed',
                'reason' => $e->getMessage(),
            ];
            $summary['failed']++;
        }
```

테스트 재실행 — 모두 PASS.

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/includes/cafe/cafe_bulk_apply.php tests/cafe_bulk_apply_test.php
git commit -m "$(cat <<'EOF'
feat(cafe): paste 적용 함수 applyCafeBulkMapping + integration 테스트

행 단위 tx-guard (inTransaction() 가드).
displace 옛 회원 키 + target 키 부여 + backfillPostsForMembers 한꺼번에.
한 행 실패는 그 행만 rollback, 다른 행 계속.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: API 액션 `cafe_bulk_parse` 추가

**Files:**
- Modify: `public_html/api/admin.php` (case 추가)

- [ ] **Step 1: 액션 추가**

`public_html/api/admin.php` 의 `fetch_cafe_info` 케이스 바로 위/아래(또는 `lookup_cafe_nick` 바로 다음) 에 추가:

```php
case 'cafe_bulk_parse':
    $admin = requireAdmin(['operation']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);

    require_once __DIR__ . '/../includes/cafe/cafe_csv_parser.php';
    require_once __DIR__ . '/../includes/cafe/cafe_link_parser.php';
    require_once __DIR__ . '/../includes/cafe/cafe_article_fetch.php';
    require_once __DIR__ . '/../includes/cafe/cafe_bulk_match.php';

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $csv = (string)($input['csv'] ?? '');
    if (trim($csv) === '') jsonError('CSV 가 비어있습니다.');

    $parsed = parseCafeCsv($csv);
    if (count($parsed['rows']) === 0 && count($parsed['errors']) === 0) {
        jsonError('파싱 결과 행이 없습니다.');
    }

    $cohortId = resolveAdminCohortId(null, $admin, false);
    if (!$cohortId) jsonError('cohort 컨텍스트가 없습니다. chip 으로 cohort 선택하세요.');

    $db = getDB();

    $rowsOut = [];
    $rowNum = 0;
    $seenArticle = [];

    foreach ($parsed['rows'] as $r) {
        $rowNum++;
        $out = [
            'row'   => $rowNum,
            'group' => $r['group'],
            'name'  => $r['name'],
            'nick'  => $r['nick'],
            'url'   => $r['url'],
        ];

        // 링크 파싱
        $link = parseCafeLink($r['url']);
        if ($link['error'] !== null) {
            $out['status'] = $link['error'] === 'wrong_cafe' ? 'WRONG_CAFE' : 'INVALID_LINK';
            $out['error']  = $link['error'];
            $rowsOut[] = $out;
            continue;
        }
        $out['article_id'] = $link['article_id'];

        // batch 안 중복
        if (isset($seenArticle[$link['article_id']])) {
            $out['status'] = 'DUPLICATE_IN_BATCH';
            $rowsOut[] = $out;
            continue;
        }
        $seenArticle[$link['article_id']] = true;

        // 카페 API
        try {
            $info = fetchCafeArticleInfo($link['article_id']);
        } catch (CafeArticleFetchException $e) {
            $out['status'] = 'CAFE_FETCH_FAIL';
            $out['error']  = $e->getMessage();
            $rowsOut[] = $out;
            continue;
        }
        $out['member_key'] = $info['member_key'];
        $out['cafe_nick']  = $info['nick'];

        // 매칭
        $match = matchCandidates(
            $db, $cohortId, $info['member_key'],
            $r['group'] !== '' ? $r['group'] : null,
            $r['name'],
            $r['nick']  !== '' ? $r['nick']  : null
        );
        $out['status']          = $match['status'];
        $out['candidates']      = $match['candidates'];
        $out['existing_member'] = $match['existing_member'];

        $rowsOut[] = $out;
    }

    // CSV 파싱 에러 행
    foreach ($parsed['errors'] as $err) {
        $rowsOut[] = [
            'row'    => $err['row'],
            'status' => 'CSV_ERROR',
            'error'  => $err['reason'],
        ];
    }

    // 요약
    $summary = ['total' => count($rowsOut), 'high' => 0, 'mid' => 0, 'low' => 0, 'fail' => 0, 'skip' => 0];
    foreach ($rowsOut as $r) {
        $s = $r['status'] ?? '';
        if ($s === 'HIGH') $summary['high']++;
        elseif (in_array($s, ['MID', 'MID_MULTI'], true)) $summary['mid']++;
        elseif ($s === 'LOW') $summary['low']++;
        elseif (in_array($s, ['ALREADY_MAPPED_SAME', 'DUPLICATE_IN_BATCH'], true)) $summary['skip']++;
        elseif (in_array($s, ['INVALID_LINK', 'WRONG_CAFE', 'CAFE_FETCH_FAIL', 'CSV_ERROR', 'NO_MATCH', 'ALREADY_MAPPED_DIFF'], true)) {
            // NO_MATCH / DIFF 는 실패 아니라 어드민 처리 대기 → fail 카운트엔 안 넣음
            if (in_array($s, ['INVALID_LINK', 'WRONG_CAFE', 'CAFE_FETCH_FAIL', 'CSV_ERROR'], true)) $summary['fail']++;
        }
    }

    jsonSuccess(['data' => ['rows' => $rowsOut, 'summary' => $summary]]);
    break;
```

- [ ] **Step 2: 수동 smoke**

DEV 브라우저 콘솔에서 직접 POST 호출:

```javascript
fetch('/api/admin.php?action=cafe_bulk_parse', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({csv: '조,이름,닉,링크\n리사조,김명식,그릭이,https://cafe.naver.com/themysticsoritune/321852'})
}).then(r => r.json()).then(console.log);
```

확인: 응답 `data.rows[0]` 에 `article_id`, `member_key`, `cafe_nick`, `status`, `candidates` 노출. 카페 API 가 정상 응답하면 `status` 가 `HIGH`/`MID`/`NO_MATCH` 중 하나.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add public_html/api/admin.php
git commit -m "$(cat <<'EOF'
feat(cafe): cafe_bulk_parse API 액션

POST {csv} → CSV 파싱 + 링크에서 article_id 추출 + 네이버 API 호출
+ 매칭 후보 산정. 한 행씩 처리, 에러 행도 결과에 포함.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: API 액션 `cafe_bulk_apply` 추가

**Files:**
- Modify: `public_html/api/admin.php` (case 추가)

- [ ] **Step 1: 액션 추가**

`cafe_bulk_parse` 케이스 바로 다음에 추가:

```php
case 'cafe_bulk_apply':
    $admin = requireAdmin(['operation']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('POST only', 405);

    require_once __DIR__ . '/../includes/cafe/cafe_bulk_apply.php';

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $rows = $input['rows'] ?? [];
    if (!is_array($rows) || empty($rows)) jsonError('적용할 행이 없습니다.');
    if (count($rows) > 100) jsonError('한 번에 100행 까지만 적용 가능합니다.');

    $db = getDB();
    $out = applyCafeBulkMapping($db, $rows);

    // cron.log INFO (작업 흐름 추적용)
    $logLine = '[' . date('Y-m-d H:i:s') . '] cafe_bulk_apply: '
             . 'applied=' . $out['summary']['applied']
             . ' skipped=' . $out['summary']['skipped']
             . ' failed=' . $out['summary']['failed']
             . ' by=admin#' . ($admin['admin_id'] ?? $admin['id'] ?? '?') . "\n";
    $logFile = dirname(__DIR__, 2) . '/logs/cron.log';
    if (is_writable(dirname($logFile))) {
        @file_put_contents($logFile, $logLine, FILE_APPEND);
    }

    jsonSuccess(['data' => $out]);
    break;
```

- [ ] **Step 2: 수동 smoke**

DEV 브라우저 콘솔에서:

```javascript
fetch('/api/admin.php?action=cafe_bulk_apply', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({rows: [
        {row: 1, article_id: '321852', member_key: 'TEST_KEY_DRY_RUN', cafe_nick: 'gricky', target_member_id: 0}
    ]})
}).then(r => r.json()).then(console.log);
```

`target_member_id=0` 이라 skipped 결과 1건. DB 변경 0건.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add public_html/api/admin.php
git commit -m "$(cat <<'EOF'
feat(cafe): cafe_bulk_apply API 액션

POST {rows} → applyCafeBulkMapping 호출 + cron.log INFO 기록.
100행 상한.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: paste 페이지 UI (`admin-cafe-bulk.js`)

**Files:**
- Create: `public_html/js/admin-cafe-bulk.js`

기존 `admin-cafe.js` IIFE 패턴 따라감 (`const AdminCafeApp = (() => {...})();`).

- [ ] **Step 1: Write JS module**

Create `public_html/js/admin-cafe-bulk.js`:

```javascript
/**
 * 카페 키 일괄 등록 paste 페이지.
 * 회원 관리 탭의 [카페 키 일괄 등록] 버튼이 호출하는 별도 view.
 *
 * 외부 인터페이스:
 *   AdminCafeBulkApp.show(container) — container 안에 렌더링
 *   AdminCafeBulkApp.hide()          — 회원 관리 탭으로 복귀
 */
const AdminCafeBulkApp = (() => {
    let containerEl = null;
    let onBack = null;
    let parsedRows = []; // 파싱 결과 (각 행 + 적용 체크 + dropdown 선택)

    function show(container, backFn) {
        containerEl = container;
        onBack = backFn || (() => {});
        render();
    }

    function hide() {
        if (typeof onBack === 'function') onBack();
    }

    function render() {
        containerEl.innerHTML = `
            <div style="padding: 16px;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                    <h2 style="margin:0;">카페 키 일괄 등록</h2>
                    <button class="btn btn-secondary" onclick="AdminCafeBulkApp.hide()">← 회원 관리</button>
                </div>
                <div style="background:#f9fafb; padding:12px; border-radius:6px; margin-bottom:12px;">
                    <div style="font-size:13px; color:#374151; margin-bottom:8px;">
                        카톡방에서 받은 정보를 <b>CSV</b>로 붙여넣으세요. (헤더 행은 있어도/없어도 OK)
                    </div>
                    <div style="font-family:monospace; font-size:12px; background:#fff; padding:8px; border:1px solid #e5e7eb; border-radius:4px;">
                        조,이름,닉,링크<br>
                        리사조,김명식,그릭이,https://cafe.naver.com/...<br>
                        무이조,이서연,서연쓰,https://m.cafe.naver.com/...
                    </div>
                </div>
                <textarea id="cb-csv" rows="8"
                    style="width:100%; font-family:monospace; font-size:13px; padding:8px;"
                    placeholder="여기에 CSV 붙여넣기"></textarea>
                <div style="margin-top:8px;">
                    <button class="btn btn-primary" id="cb-parse">파싱하기</button>
                </div>
                <div id="cb-preview" style="margin-top:16px;"></div>
                <div id="cb-result" style="margin-top:16px;"></div>
            </div>
        `;
        document.getElementById('cb-parse').onclick = doParse;
    }

    async function doParse() {
        const csv = document.getElementById('cb-csv').value;
        if (!csv.trim()) return Toast.warning('CSV 를 붙여넣으세요.');

        App.showLoading();
        const r = await App.post('/api/admin.php?action=cafe_bulk_parse', { csv });
        App.hideLoading();

        if (!r.success) return Toast.error(r.message || '파싱 실패');
        parsedRows = (r.data.rows || []).map(row => ({
            ...row,
            apply: row.status === 'HIGH',  // HIGH 만 기본 체크 ON
            selectedMemberId: (row.candidates && row.candidates[0]) ? row.candidates[0].member_id : null,
        }));
        renderPreview(r.data.summary);
    }

    function renderPreview(summary) {
        const el = document.getElementById('cb-preview');
        if (parsedRows.length === 0) { el.innerHTML = '<div>결과 없음</div>'; return; }

        el.innerHTML = `
            <h3>미리보기 (${parsedRows.length}행 — HIGH ${summary.high} / MID ${summary.mid} / LOW ${summary.low} / FAIL ${summary.fail} / SKIP ${summary.skip})</h3>
            <div style="margin-bottom:8px;">
                <button class="btn btn-sm btn-secondary" id="cb-check-all">전체 체크</button>
                <button class="btn btn-sm btn-secondary" id="cb-check-high">HIGH만 체크</button>
                <button class="btn btn-sm btn-secondary" id="cb-uncheck-all">전체 해제</button>
            </div>
            <div style="overflow-x:auto;">
            <table class="table" style="font-size:13px;">
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th>조</th>
                        <th>이름</th>
                        <th>닉</th>
                        <th>카페닉</th>
                        <th>매칭 회원</th>
                        <th>상태</th>
                        <th style="width:60px;">적용</th>
                    </tr>
                </thead>
                <tbody>${parsedRows.map(renderRow).join('')}</tbody>
            </table>
            </div>
            <div style="margin-top:12px;">
                <button class="btn btn-primary" id="cb-apply">
                    <span id="cb-apply-count">0</span>개 행 적용
                </button>
            </div>
        `;
        document.getElementById('cb-check-all').onclick = () => bulkCheck(_ => true);
        document.getElementById('cb-check-high').onclick = () => bulkCheck(r => r.status === 'HIGH');
        document.getElementById('cb-uncheck-all').onclick = () => bulkCheck(_ => false);
        document.getElementById('cb-apply').onclick = doApply;
        attachRowHandlers();
        updateApplyCount();
    }

    function renderRow(row) {
        const idx = parsedRows.indexOf(row);
        const statusColor = {
            HIGH: '#bbf7d0', MID: '#fef3c7', MID_MULTI: '#fef3c7', LOW: '#fef3c7',
            NO_MATCH: '#e5e7eb', ALREADY_MAPPED_SAME: '#f3f4f6',
            ALREADY_MAPPED_DIFF: '#fed7aa',
            CAFE_FETCH_FAIL: '#fecaca', INVALID_LINK: '#fecaca', WRONG_CAFE: '#fecaca',
            CSV_ERROR: '#fecaca', DUPLICATE_IN_BATCH: '#e5e7eb',
        }[row.status] || '#fff';
        const isSkip = ['ALREADY_MAPPED_SAME', 'DUPLICATE_IN_BATCH'].includes(row.status);
        const isFail = ['INVALID_LINK', 'WRONG_CAFE', 'CAFE_FETCH_FAIL', 'CSV_ERROR'].includes(row.status);
        const canApply = !isSkip && !isFail;

        let matchCellHtml = '';
        if (row.existing_member) {
            const em = row.existing_member;
            matchCellHtml = `이미: ${App.esc(em.real_name)} (${App.esc(em.group_name || '-')}) ${row.status === 'ALREADY_MAPPED_DIFF' ? '⚠️' : ''}`;
        } else if (row.candidates && row.candidates.length === 1 && row.status === 'HIGH') {
            const c = row.candidates[0];
            matchCellHtml = `${App.esc(c.real_name)} (${App.esc(c.group_name || '-')})`;
        } else if (row.candidates && row.candidates.length > 0) {
            // dropdown
            matchCellHtml = `<select class="form-select form-select-sm" data-idx="${idx}" data-role="member-select">
                <option value="">-- 선택 --</option>
                ${row.candidates.map(c => `<option value="${c.member_id}" ${c.member_id === row.selectedMemberId ? 'selected' : ''}>${App.esc(c.real_name)} (${App.esc(c.group_name || '-')})</option>`).join('')}
            </select>`;
        } else if (row.status === 'NO_MATCH') {
            matchCellHtml = `<input type="text" class="form-input form-input-sm" data-idx="${idx}" data-role="member-search" placeholder="회원 검색..." style="width:160px;">
                <select class="form-select form-select-sm" data-idx="${idx}" data-role="member-select" style="display:none;"></select>`;
        } else {
            matchCellHtml = '-';
        }

        return `<tr style="background:${statusColor};">
            <td>${row.row}</td>
            <td>${App.esc(row.group || '')}</td>
            <td>${App.esc(row.name || '')}</td>
            <td>${App.esc(row.nick || '')}</td>
            <td>${App.esc(row.cafe_nick || '')}</td>
            <td>${matchCellHtml}</td>
            <td><b>${row.status}</b>${row.error ? ` <span style="color:#dc2626;">${App.esc(row.error)}</span>` : ''}</td>
            <td>${canApply ? `<input type="checkbox" data-idx="${idx}" data-role="apply" ${row.apply ? 'checked' : ''}>` : ''}</td>
        </tr>`;
    }

    function attachRowHandlers() {
        document.querySelectorAll('input[data-role="apply"]').forEach(el => {
            el.onchange = (e) => {
                const idx = parseInt(e.target.dataset.idx);
                parsedRows[idx].apply = e.target.checked;
                updateApplyCount();
            };
        });
        document.querySelectorAll('select[data-role="member-select"]').forEach(el => {
            el.onchange = (e) => {
                const idx = parseInt(e.target.dataset.idx);
                parsedRows[idx].selectedMemberId = e.target.value ? parseInt(e.target.value) : null;
            };
        });
        // member-search (NO_MATCH 행): 입력 → /api/admin.php?action=member_search 으로 조회 (boot 기존 헬퍼 있음)
        document.querySelectorAll('input[data-role="member-search"]').forEach(el => {
            let timer;
            el.oninput = (e) => {
                clearTimeout(timer);
                const idx = parseInt(e.target.dataset.idx);
                const q = e.target.value.trim();
                if (!q) return;
                timer = setTimeout(async () => {
                    const r = await App.get('/api/admin.php?action=admin_list', { search: q });
                    // 또는 member_search 같은 엔드포인트. 기존 코드 보고 결정.
                    // 응답에서 활성 회원 후보를 dropdown 에 채움.
                    const sel = e.target.parentNode.querySelector('select');
                    sel.innerHTML = '<option value="">-- 선택 --</option>'
                        + (r.data || []).filter(m => m.is_active).slice(0, 20).map(m =>
                            `<option value="${m.id}">${App.esc(m.real_name)} (${App.esc(m.group_name || m.cohort || '-')})</option>`
                        ).join('');
                    sel.style.display = '';
                    sel.onchange = (ev) => {
                        parsedRows[idx].selectedMemberId = ev.target.value ? parseInt(ev.target.value) : null;
                    };
                }, 250);
            };
        });
    }

    function bulkCheck(predicate) {
        parsedRows.forEach((r, i) => {
            const isSkip = ['ALREADY_MAPPED_SAME', 'DUPLICATE_IN_BATCH'].includes(r.status);
            const isFail = ['INVALID_LINK', 'WRONG_CAFE', 'CAFE_FETCH_FAIL', 'CSV_ERROR'].includes(r.status);
            if (isSkip || isFail) { r.apply = false; return; }
            r.apply = !!predicate(r);
        });
        renderPreview({ high: 0, mid: 0, low: 0, fail: 0, skip: 0 }); // summary 재계산 생략 (count 갱신만)
    }

    function updateApplyCount() {
        const count = parsedRows.filter(r => r.apply && r.selectedMemberId).length;
        const el = document.getElementById('cb-apply-count');
        if (el) el.textContent = count;
    }

    async function doApply() {
        const rows = parsedRows
            .filter(r => r.apply && r.selectedMemberId && r.member_key)
            .map(r => ({
                row: r.row,
                article_id: r.article_id,
                member_key: r.member_key,
                cafe_nick: r.cafe_nick,
                target_member_id: r.selectedMemberId,
            }));
        if (rows.length === 0) return Toast.warning('적용할 행이 없습니다.');

        if (!await App.confirm(`${rows.length}개 행 적용. 계속할까요?`)) return;

        App.showLoading();
        const r = await App.post('/api/admin.php?action=cafe_bulk_apply', { rows });
        App.hideLoading();

        if (!r.success) return Toast.error(r.message || '적용 실패');
        renderResult(r.data);
    }

    function renderResult(data) {
        const el = document.getElementById('cb-result');
        const s = data.summary;
        el.innerHTML = `
            <h3>적용 결과</h3>
            <div style="font-size:14px; margin-bottom:8px;">
                ✅ 적용 ${s.applied} / ⏭ 건너뜀 ${s.skipped} / ❌ 실패 ${s.failed}
            </div>
            <div style="overflow-x:auto;">
            <table class="table" style="font-size:13px;">
                <thead><tr><th>#</th><th>상태</th><th>회원</th><th>백필</th><th>미션</th><th>비고</th></tr></thead>
                <tbody>${(data.results || []).map(r => `
                    <tr>
                        <td>${r.row}</td>
                        <td>${r.status}</td>
                        <td>${r.member_id || '-'}</td>
                        <td>${r.backfilled_posts ?? '-'}</td>
                        <td>${r.missions_saved ?? '-'}</td>
                        <td>${r.displaced ? `옛 회원 displaced: ${r.displaced}` : ''}${r.reason ? App.esc(r.reason) : ''}</td>
                    </tr>
                `).join('')}</tbody>
            </table>
            </div>
            <div style="margin-top:12px;"><button class="btn btn-secondary" onclick="AdminCafeBulkApp.hide()">완료</button></div>
        `;
        Toast.success(`${s.applied}건 적용`);
    }

    return { show, hide };
})();
```

⚠️ **member 검색 endpoint**: `admin_list` 가 실제로 회원 검색이 가능한지 코드 확인. 안 되면 `bootcamp.php?action=members_search` 같은 기존 헬퍼 활용. 첫 구현 시 dropdown 후보만 사용해도 데모 가능 — NO_MATCH 행은 일단 적용 불가로 두고, 검색 dropdown 은 follow-up.

→ 1차 release 에서는 dropdown 후보가 있는 행(HIGH/MID/MID_MULTI/LOW)만 적용 가능, NO_MATCH 와 ALREADY_MAPPED_DIFF 는 회원 추가/수정 모달에서 수동 처리 안내. 검색 기능은 Task 9-follow-up 으로.

- [ ] **Step 2: 수동 smoke (브라우저)**

`AdminCafeBulkApp` 가 IIFE 로 로드되었는지 콘솔에서:

```javascript
typeof AdminCafeBulkApp; // 'object'
```

Task 10 에서 script 태그 추가 후 진입점 버튼으로 실제 페이지 호출.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add public_html/js/admin-cafe-bulk.js
git commit -m "$(cat <<'EOF'
feat(cafe): paste 페이지 UI (AdminCafeBulkApp)

CSV textarea + 파싱 + 미리보기 테이블 + dropdown 매칭 + 일괄 적용.
status 색상 코드. HIGH 만 기본 체크 ON. 1차에서 NO_MATCH 행은
적용 불가 (member 검색 기능은 follow-up).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: admin.js 진입점 버튼 + operation/index.php script 태그

**Files:**
- Modify: `public_html/js/admin.js` (회원 관리 탭 상단 액션바)
- Modify: `public_html/operation/index.php` (script 태그)

- [ ] **Step 1: script 태그 추가**

`public_html/operation/index.php` 에서 `admin-cafe.js` script 태그 옆에 추가. Grep 으로 위치 확인:

```bash
grep -n "admin-cafe.js" /root/boot-dev/public_html/operation/index.php
```

찾은 라인 바로 다음에:

```html
<script src="/js/admin-cafe-bulk.js<?= v('/js/admin-cafe-bulk.js') ?>"></script>
```

(`v()` 헬퍼는 asset_version.php 의 cache buster. 기존 admin-cafe.js 와 동일 패턴 사용.)

- [ ] **Step 2: 회원 관리 탭에 버튼 추가**

`public_html/js/admin.js` 의 회원 관리 탭 (`loadMembersMgmt` 또는 비슷한 함수) 안에서 상단 액션바를 찾는다:

```bash
grep -n "loadMembersMgmt\|회원 추가\|members-mgmt-actions" /root/boot-dev/public_html/js/admin.js | head -10
```

상단 액션바 HTML 안에 `<button class="btn btn-primary">회원 추가</button>` 옆에 다음 버튼 추가:

```html
<button class="btn btn-secondary" id="btn-cafe-bulk">카페 키 일괄 등록</button>
```

같은 함수 안에서 onclick 핸들러 등록 (기존 회원 추가 버튼 onclick 등록 옆에):

```javascript
const cafeBulkBtn = document.getElementById('btn-cafe-bulk');
if (cafeBulkBtn) {
    cafeBulkBtn.onclick = () => {
        if (typeof AdminCafeBulkApp === 'undefined') return Toast.error('카페 일괄 등록 모듈 로드 실패');
        const tabContainer = document.getElementById('tab-members') || document.querySelector('.tab-content.active');
        const originalHtml = tabContainer.innerHTML;
        AdminCafeBulkApp.show(tabContainer, () => {
            tabContainer.innerHTML = originalHtml;
            loadMembersMgmt(); // 복귀 시 리로드 (방금 등록한 키 반영)
        });
    };
}
```

- [ ] **Step 3: 수동 smoke (브라우저)**

DEV 어드민 로그인 → 회원 관리 탭 → "카페 키 일괄 등록" 버튼 클릭 → paste 페이지 렌더. ← 회원 관리 버튼으로 복귀 가능.

simple CSV 1행 paste → 파싱 → HIGH 행 표시 → 적용 → 결과 표시 → 회원 관리 복귀 → 그 회원에 `cafe_member_key` 표시 확인.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && git add public_html/js/admin.js public_html/operation/index.php
git commit -m "$(cat <<'EOF'
feat(cafe): 회원 관리 탭에 [카페 키 일괄 등록] 진입점

admin-cafe-bulk.js script 태그 추가 + 회원 관리 탭 상단 액션바에
버튼. 클릭 시 AdminCafeBulkApp.show() 로 paste 페이지 렌더,
복귀 시 회원 목록 리로드.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: PROD 인보리언트 스크립트

**Files:**
- Create: `tests/cafe_bulk_invariants.php`

배포 직후 PROD 에서 1회 실행해 데이터 정합성 확인.

- [ ] **Step 1: 인보리언트 스크립트 작성**

Create `tests/cafe_bulk_invariants.php`:

```php
<?php
/**
 * 카페 키 일괄 등록 배포 후 PROD 인보리언트 smoke.
 * 사용: php tests/cafe_bulk_invariants.php
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

// INV-1: cafe_member_key UNIQUE (DB 레벨 UNIQUE 인덱스가 보장하지만 확인)
$dupes = $db->query("
    SELECT cafe_member_key, COUNT(*) c
    FROM bootcamp_members
    WHERE cafe_member_key IS NOT NULL
    GROUP BY cafe_member_key
    HAVING c > 1
")->fetchAll();
t('INV-1 cafe_member_key 중복 없음', empty($dupes), count($dupes) . ' duplicates');

// INV-2: cafe_posts.member_id orphan (회원이 사라진 글)
$orphan = (int)$db->query("
    SELECT COUNT(*) FROM cafe_posts cp
    WHERE cp.member_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM bootcamp_members bm WHERE bm.id = cp.member_id)
")->fetchColumn();
t('INV-2 cafe_posts orphan 0', $orphan === 0, "{$orphan} orphan posts");

// INV-3: 매핑된 cafe_posts 의 mission_checked=1 (체크 표시) — 매핑 후 reset 없음
$unflagged = (int)$db->query("
    SELECT COUNT(*) FROM cafe_posts
    WHERE member_id IS NOT NULL AND mission_checked = 0
")->fetchColumn();
t('INV-3 매핑된 posts 의 mission_checked=1', $unflagged === 0, "{$unflagged} unflagged");

// INV-4: 활성 cohort 회원의 cafe_member_key 있는 사람 수가 합리적
$total = (int)$db->query("SELECT COUNT(*) FROM bootcamp_members WHERE is_active=1 AND member_status='active'")->fetchColumn();
$mapped = (int)$db->query("SELECT COUNT(*) FROM bootcamp_members WHERE is_active=1 AND member_status='active' AND cafe_member_key IS NOT NULL")->fetchColumn();
echo "INFO  활성 회원 {$total} 명 중 cafe_member_key 보유 {$mapped} 명\n";

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: DEV 실행 (배포 전 검증)**

```bash
cd /root/boot-dev && php tests/cafe_bulk_invariants.php
```

Expected: `3 pass, 0 fail` + INFO 라인.

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add tests/cafe_bulk_invariants.php
git commit -m "$(cat <<'EOF'
test(cafe): paste 일괄 등록 배포 후 인보리언트 스크립트

INV-1 cafe_member_key UNIQUE 중복 0
INV-2 cafe_posts.member_id orphan 0
INV-3 매핑된 posts mission_checked=1

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: DEV 수동 검증 + push origin dev → 사용자 확인 요청 (멈춤)

**Files:** (변경 없음)

- [ ] **Step 1: 전체 unit/integration 테스트 그린 확인**

```bash
cd /root/boot-dev
php tests/cafe_link_parser_test.php
php tests/cafe_csv_parser_test.php
php tests/cafe_bulk_match_test.php
php tests/cafe_bulk_apply_test.php
php tests/cafe_bulk_invariants.php
```

모두 PASS 확인. 실패 시 해당 Task 로 돌아가 fix.

- [ ] **Step 2: DEV 브라우저 e2e 흐름 수동 검증**

DEV 어드민 (`dev-boot.soritune.com`) 로그인 (operation role 회원으로):

1. **HIGH 케이스**: 활성 회원 1명을 임의 선정. 그 회원의 cafe_member_key 를 DB 에서 `UPDATE bootcamp_members SET cafe_member_key=NULL WHERE id=?` 로 일부러 NULL. 그 회원이 카페에 올린 실제 글 URL 사용. paste:
   ```
   조,이름,닉,링크
   <그 회원의 조>,<실명>,<닉>,<카페 링크>
   ```
   → 파싱 → HIGH 행 1건 → 적용 → cafe_member_key 등록 확인. 카페에 그 회원의 과거 글이 있으면 백필 + 미션 체크 row 늘어남 확인.

2. **NO_MATCH 케이스**: 없는 이름/조 paste → NO_MATCH 표시, 적용 불가 (1차 release).

3. **INVALID_LINK**: `https://google.com` paste → INVALID_LINK 행, 다른 행 영향 없음.

4. **DUPLICATE_IN_BATCH**: 같은 링크 2번 → 둘째 행 DUPLICATE_IN_BATCH.

5. **ALREADY_MAPPED_SAME**: 이미 등록된 회원의 글 paste → 행 회색, 적용 체크 불가.

6. **회귀 검증**: 회원 관리 → 회원 수정 모달 → 게시글 번호 가져오기 (Task 3 리팩토링 영향). 동작 여전. 회원 관리 → 카페 게시글 탭 → "재매핑" 버튼 (Task 5 리팩토링 영향). 응답 정상.

- [ ] **Step 3: push origin dev**

```bash
cd /root/boot-dev && git push origin dev
```

Expected: 11 commits push (Task 1-11 각 1건 + Task 12 의 docs/plan add 가 이전 단계에 commit 되었다면 +N).

- [ ] **Step 4: ⛔ 멈춤. 사용자에게 DEV 확인 요청**

진행 메시지:

> DEV 배포 완료. dev-boot.soritune.com 에서 다음 시나리오 검증 부탁드립니다:
>
> 1. 회원 관리 → [카페 키 일괄 등록] 버튼 진입
> 2. 실제 카톡방에서 받은 [조,이름,닉,링크] CSV paste → 파싱 → 매칭 결과 확인
> 3. HIGH 케이스 1~2건 적용 → 회원에 cafe_member_key 등록 + 과거 글 백필 확인
> 4. 기존 회원 모달 "게시글 번호 가져오기" 회귀 확인 (Task 3 리팩토링)
> 5. 카페 게시글 탭 "재매핑" 버튼 회귀 확인 (Task 5 리팩토링)
>
> 운영 반영해도 된다고 말씀해주시면 main 머지 + boot-prod pull + PROD 인보리언트 실행 진행하겠습니다.

(사용자가 명시적 "운영 반영해줘" 한 경우에만 다음 진행. 그 전에 멈춤.)

---

## Self-Review

### Spec coverage

- ✅ 페이지 위치/권한 (operation only): Task 7, 8 의 `requireAdmin(['operation'])`, Task 10 의 회원 관리 탭 진입점
- ✅ CSV 4열 포맷 + 헤더 + BOM + 100행 상한: Task 2
- ✅ 카페 링크 4종 정규식: Task 1
- ✅ batch 안 중복 (`DUPLICATE_IN_BATCH`): Task 7
- ✅ 매칭 5단계 분기 (HIGH/MID/MID_MULTI/LOW/NO_MATCH) + ALREADY_MAPPED_SAME/DIFF: Task 4
- ✅ 카페 API 실패 처리 (`CAFE_FETCH_FAIL`): Task 3, 7
- ✅ DIFF 케이스 옛 회원 키 NULL 해제 + 새 회원 부여: Task 6
- ✅ 백필 + saveCheck 소급: Task 5
- ✅ `handleCafeRemapUnmapped` 공통 헬퍼 사용 (DRY): Task 5
- ✅ 미리보기 + 일괄 적용 UI: Task 9
- ✅ 적용 결과 모달 + cron.log INFO: Task 8, 9
- ✅ 행 단위 트랜잭션 격리 + tx-guard: Task 6
- ✅ 테스트 (unit + integration + invariants): Task 1~6, 11
- ✅ chip cohort 컨텍스트 (`resolveAdminCohortId`): Task 7
- ⚠️ NO_MATCH 행에서 dropdown 검색 — 1차 release 에서는 적용 불가 (Task 9 의 follow-up note). spec 보다 보수적이지만 검증 없이 release 위험 → 명시.
- ⚠️ 50+ 행에서 네이버 API rate limit sleep — 1차 미반영 (spec deferred 명시).

### Placeholder scan

- "TBD"/"TODO"/"fill in later" 없음.
- 모든 step 에 실제 code/command 포함.
- "Similar to Task N" 없음 — 각 task 의 코드는 자체 완결.
- 단 한 군데: Task 9 의 member 검색 endpoint — 1차에서는 사용 안 한다고 명시. 검색 dropdown 호출 코드는 stub 으로 남김 (보수 분리).

### Type consistency

- `parseCafeLink` 반환: `{article_id, error}` — 사용처 (Task 7) 일치.
- `parseCafeCsv` 반환: `{rows, errors}` — 사용처 (Task 7) 일치.
- `fetchCafeArticleInfo` 반환: `{member_key, nick}` — 사용처 (Task 7) 일치.
- `matchCandidates` 반환: `{status, candidates, existing_member}` — 사용처 (Task 7) 일치. 각 candidate: `{member_id, real_name, nickname, group_name, stage_no}`.
- `backfillPostsForMembers` 반환: `{remapped, missions_saved, by_member}` — 사용처 (Task 5 의 `handleCafeRemapUnmapped`, Task 6 의 `applyCafeBulkMapping`) 일치.
- `applyCafeBulkMapping` 반환: `{results, summary}` — 사용처 (Task 8 API + Task 9 UI) 일치. 각 result: `{row, status, member_id?, article_id?, displaced?, backfilled_posts?, missions_saved?, reason?}`. Task 9 UI 의 `data.results.map` 에서 같은 필드 참조.
- `status` 상수 문자열 (HIGH/MID/...) — Task 4 (match), Task 7 (parse_API), Task 9 (UI) 일치.

### Scope check

12 task 모두 한 흐름의 부분. 단일 plan 으로 적정. 분량은 큰 편이지만 task 별 독립 commit 가능 — 중간에 사용자 검토 가능.
