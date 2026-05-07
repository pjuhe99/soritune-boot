# 카페 게시글 폴링 자체 구현 (n8n 의존 제거) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** boot의 `cafe_posts` ingestion을 n8n cloud 의 1시간 주기 webhook 대신 boot 서버의 PHP cron 으로 직접 폴링하도록 전환.

**Architecture:** 신규 모듈 두 개 (`includes/cafe/cafe_naver_client.php` Naver 공개 API, `includes/cafe/cafe_ingest.php` UPSERT + 자동 미션 체크). 기존 `cron.php` 라우터에 `cafe_poll` case 추가. 기존 HTTP endpoint `handleIntegrationCafePosts` 는 동일 ingest 함수를 호출하도록 본문만 교체 (rollback 경로 유지).

**Tech Stack:** PHP 8.x, cURL, MariaDB, 네이버 카페 공개 API (`apis.naver.com/cafe-web/cafe2/ArticleListV2dot1.json`), 시스템 crontab.

**Spec:** `docs/superpowers/specs/2026-05-06-cafe-poll-self-hosted-design.md`

**Working directory:** `/root/boot-dev` (dev 브랜치). PROD 반영은 사용자 명시 요청 시에만.

**선행 게이트:** Task 0 — 코드 작성 직전 네이버 API URL 이 200 응답하는지 직접 curl 로 확인. 차단 시 작업 정지.

---

## File Structure

신규
- `public_html/includes/cafe/cafe_naver_client.php` — 네이버 공개 API 호출 (board listing). 인증·DB 의존 없음.
- `public_html/includes/cafe/cafe_ingest.php` — `cafeFetchActiveBoards()`, `cafeArticleExists()`, `ingestCafePosts()`. DB-side 일체.

수정
- `public_html/includes/bootcamp_functions.php` — `resolveMemberByKey()` 추가 (bootcamp.php에서 이전).
- `public_html/api/bootcamp.php` — `resolveMemberByKey()` 정의 삭제 (이전 commit과 짝).
- `public_html/api/services/integration.php` — `handleIntegrationCafePosts()` 본문을 `ingestCafePosts()` 호출로 교체.
- `public_html/cron.php` — `case 'cafe_poll'` + `cafePoll()` 함수 추가.

서버 사이드 (코드 외)
- crontab 라인 등록 — DEV `5 * * * *`, PROD `0 * * * *`.

---

### Task 0: 선행 게이트 — 네이버 API URL 200 확인

**Files:** (없음)

- [ ] **Step 1: curl 검증**

```bash
curl -sS -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36" \
  "https://apis.naver.com/cafe-web/cafe2/ArticleListV2dot1.json?search.clubid=23243775&search.menuid=288&search.queryType=lastArticle&search.page=1&search.perPage=3" \
  | head -c 200
```

Expected: `{"message":{"status":"200","error":{"code":"","msg":""},"result":{"cafeId":23243775,...` 로 시작.

- [ ] **Step 2: 차단 시 정지**

응답이 다른 형태(에러, HTML, errorCode 9999 등)이면 STOP 하고 사용자에게 보고. 네이버가 정책 변경한 것일 수 있어 다른 endpoint 탐색이 필요.

---

### Task 1: `resolveMemberByKey()` 를 `bootcamp_functions.php` 로 이전

**Files:**
- Modify: `/root/boot-dev/public_html/includes/bootcamp_functions.php` (함수 추가)
- Modify: `/root/boot-dev/public_html/api/bootcamp.php` (함수 삭제)

cron 이 require 가능한 위치로 이전. HTTP 경로는 영향 없음 — `bootcamp.php` 가 어차피 `bootcamp_functions.php` 를 require_once 하므로 동일 함수 계속 사용.

- [ ] **Step 1: `bootcamp_functions.php` 끝에 함수 추가**

`/root/boot-dev/public_html/includes/bootcamp_functions.php` 파일 마지막에 (가장 끝 `}` 다음 빈 줄 뒤) 추가:

```php

/**
 * cafe_member_key → member_id 변환.
 * (이전 위치: api/bootcamp.php — cron 이 require 할 수 있도록 includes 로 이전)
 */
function resolveMemberByKey($db, $cafeKey) {
    if (!$cafeKey) return null;
    $stmt = $db->prepare("
        SELECT id FROM bootcamp_members
        WHERE cafe_member_key = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$cafeKey]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}
```

- [ ] **Step 2: `bootcamp.php` 의 정의 삭제**

`/root/boot-dev/public_html/api/bootcamp.php` 의 다음 블록을 통째로 삭제 (line 72-85 부근):

```php
/**
 * cafe_member_key → member_id 변환
 */
function resolveMemberByKey($db, $cafeKey) {
    if (!$cafeKey) return null;
    $stmt = $db->prepare("
        SELECT id FROM bootcamp_members
        WHERE cafe_member_key = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$cafeKey]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}
```

- [ ] **Step 3: 문법 + 회귀 검증**

```bash
php -l /root/boot-dev/public_html/includes/bootcamp_functions.php
php -l /root/boot-dev/public_html/api/bootcamp.php
grep -rn "function resolveMemberByKey" /root/boot-dev/public_html
```
Expected:
- 두 파일 모두 `No syntax errors detected`
- grep: 정의가 정확히 **1군데** (`bootcamp_functions.php`) 만 등장.

```bash
grep -rn "resolveMemberByKey" /root/boot-dev/public_html | grep -v "function resolveMemberByKey"
```
Expected: 호출처 (integration.php 등) 가 그대로 보임 — 제거되지 않음.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && \
git add public_html/includes/bootcamp_functions.php public_html/api/bootcamp.php && \
git commit -m "refactor(bootcamp): resolveMemberByKey 를 includes 로 이전 (cron 접근용)"
```

---

### Task 2: `cafe_naver_client.php` — 네이버 공개 API 호출

**Files:**
- Create: `/root/boot-dev/public_html/includes/cafe/cafe_naver_client.php`

- [ ] **Step 1: 디렉토리 + 파일 생성**

```bash
mkdir -p /root/boot-dev/public_html/includes/cafe
```

파일 내용:

```php
<?php
/**
 * 네이버 카페 공개 listing API 호출.
 * - 인증 없음 (공개 보드)
 * - 응답 파싱하여 cafe_posts 표준 형태로 정규화 [{cafe_article_id, title, member_key, nickname, posted_at}]
 *
 * 검증된 endpoint (2026-05-06 기준):
 *   GET https://apis.naver.com/cafe-web/cafe2/ArticleListV2dot1.json
 *       ?search.clubid={CAFE_CLUB_ID}
 *       &search.menuid={menu_id}
 *       &search.queryType=lastArticle
 *       &search.page=1
 *       &search.perPage={N}
 */

declare(strict_types=1);

const CAFE_CLUB_ID = 23243775;  // 소리튠영어 카페
const CAFE_NAVER_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

/**
 * 보드의 최근 게시글 목록 조회.
 *
 * @return array<int, array{cafe_article_id:string, title:string, member_key:string, nickname:string, posted_at:string}>
 *         memberKey/articleId/timestamp 누락된 article 은 결과에서 제외 (다음 회차 재시도).
 * @throws RuntimeException HTTP/parse 실패 시
 */
function cafeFetchBoardArticles(string $menuId, int $perPage = 20): array {
    $url = 'https://apis.naver.com/cafe-web/cafe2/ArticleListV2dot1.json'
         . '?search.clubid=' . CAFE_CLUB_ID
         . '&search.menuid=' . urlencode($menuId)
         . '&search.queryType=lastArticle'
         . '&search.page=1'
         . '&search.perPage=' . max(1, $perPage);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . CAFE_NAVER_USER_AGENT],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new RuntimeException("Naver cURL: {$err}");
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Naver HTTP {$code}: " . substr((string)$resp, 0, 300));
    }

    $data   = json_decode((string)$resp, true);
    $status = $data['message']['status'] ?? '';
    if ($status !== '200') {
        $errMsg = $data['message']['error']['msg'] ?? 'unknown';
        throw new RuntimeException("Naver API status={$status}: {$errMsg}");
    }

    $articles = $data['message']['result']['articleList'] ?? [];
    $out = [];
    foreach ($articles as $a) {
        $articleId = $a['articleId']          ?? null;
        $memberKey = $a['memberKey']          ?? null;
        $ts        = $a['writeDateTimestamp'] ?? null;
        if (!$articleId || !$memberKey || !$ts) continue;
        $out[] = [
            'cafe_article_id' => (string)$articleId,
            'title'           => (string)($a['subject']        ?? ''),
            'member_key'      => (string)$memberKey,
            'nickname'        => (string)($a['writerNickname'] ?? ''),
            'posted_at'       => date('Y-m-d H:i:s', (int)floor($ts / 1000)),
        ];
    }
    return $out;
}
```

- [ ] **Step 2: 문법 체크**

```bash
php -l /root/boot-dev/public_html/includes/cafe/cafe_naver_client.php
```
Expected: `No syntax errors detected ...`

- [ ] **Step 3: CLI smoke (실 호출)**

```bash
sudo -u apache php -r '
require "/var/www/html/_______site_SORITUNECOM_DEV_BOOT/public_html/includes/cafe/cafe_naver_client.php";
$arts = cafeFetchBoardArticles("288", 5);
echo "count=" . count($arts) . "\n";
foreach ($arts as $a) {
    echo "  id={$a["cafe_article_id"]} title=" . substr($a["title"], 0, 30) . " mk=" . substr($a["member_key"], 0, 10) . "... posted={$a["posted_at"]}\n";
}
'
```
Expected: `count=N` (N>=1) + 각 article 의 id/title/member_key/posted_at 출력. 실패 시 BLOCKED 으로 보고.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && \
git add public_html/includes/cafe/cafe_naver_client.php && \
git commit -m "feat(cafe): cafe_naver_client.php — 네이버 공개 listing API 래퍼"
```

---

### Task 3: `cafe_ingest.php` — DB 액세스 + ingestCafePosts()

**Files:**
- Create: `/root/boot-dev/public_html/includes/cafe/cafe_ingest.php`

기존 `handleIntegrationCafePosts()` 의 핵심 로직 (board_type 매핑, member_key→member_id 매핑, UPSERT, 자동 미션 체크) 을 그대로 함수로 옮긴다. 새 동작 추가 없음.

- [ ] **Step 1: 파일 생성**

```php
<?php
/**
 * cafe_posts ingestion + 보드 메타 조회 헬퍼.
 *
 * 의존:
 *   - getDB(), getSetting() ← config.php
 *   - resolveMemberByKey(), getMissionTypeId(), saveCheck() ← bootcamp_functions.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../bootcamp_functions.php';

/**
 * 활성 보드 목록 (cafe_board_map.is_active=1).
 * @return array<int, array{menu_id:string, board_type:string}>
 */
function cafeFetchActiveBoards(): array {
    $db = getDB();
    $rows = $db->query("SELECT menu_id, board_type FROM cafe_board_map WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => [
        'menu_id'    => (string)$r['menu_id'],
        'board_type' => (string)$r['board_type'],
    ], $rows);
}

/**
 * cafe_article_id 가 cafe_posts 에 이미 존재하는지.
 * 신규 article 만 ingest 로 보내기 위한 사전 필터 (cron 측 최적화).
 */
function cafeArticleExists(string $articleId): bool {
    static $stmt = null;
    if ($stmt === null) {
        $stmt = getDB()->prepare("SELECT 1 FROM cafe_posts WHERE cafe_article_id = ? LIMIT 1");
    }
    $stmt->execute([$articleId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * 카페 게시글 일괄 ingestion.
 * - cafe_posts UPSERT (UNIQUE uk_cafe_article 로 dedupe)
 * - member_key 매칭되면 member_id 채움
 * - member_id + board_type + assignment_date 모두 있으면 saveCheck (source='automation', source_ref="cafe:{id}")
 *
 * `inserted` 의미: UPSERT 실행 횟수 (기존 호환). 실제 신규 INSERT 수 아님.
 * 호출 측에서 사전 필터하면 (cafeArticleExists) 신규 행 수와 일치.
 */
function ingestCafePosts(array $posts): array {
    $db = getDB();
    $results = ['inserted' => 0, 'skipped' => 0, 'error' => 0, 'unmapped' => 0];
    $unmappedKeys = [];

    // menu_id → board_type 매핑 로드
    $boardMapStmt = $db->query("SELECT menu_id, board_type FROM cafe_board_map WHERE is_active = 1");
    $boardMap = [];
    foreach ($boardMapStmt->fetchAll() as $bm) {
        $boardMap[(string)$bm['menu_id']] = $bm['board_type'];
    }

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

    foreach ($posts as $post) {
        $articleId      = $post['cafe_article_id'] ?? $post['article_id'] ?? '';
        $title          = $post['title']           ?? '';
        $memberKey      = $post['member_key']      ?? null;
        $nickname       = $post['nickname']        ?? null;
        $postedAt       = $post['posted_at']       ?? null;
        $missionChecked = (int)($post['mission_checked'] ?? 0);
        $assignmentDate = $post['assignment_date'] ?? null;

        $boardType = $post['board_type'] ?? null;
        if (!$boardType && isset($post['menu_id'])) {
            $boardType = $boardMap[(string)$post['menu_id']] ?? null;
        }

        if (!$articleId) {
            $results['error']++;
            continue;
        }

        $memberId = null;
        if ($memberKey) {
            if (isset($memberKeyCache[$memberKey])) {
                $memberId = $memberKeyCache[$memberKey];
            } else {
                $memberId = resolveMemberByKey($db, $memberKey);
                $memberKeyCache[$memberKey] = $memberId;
            }
            if (!$memberId) {
                $results['unmapped']++;
                $unmappedKeys[$memberKey] = $nickname ?? '';
            }
        }

        try {
            $insertStmt->execute([
                $articleId,
                $title,
                $memberKey,
                $nickname,
                $boardType,
                $postedAt,
                $memberId,
                $missionChecked,
                $assignmentDate,
                !empty($post) ? json_encode($post, JSON_UNESCAPED_UNICODE) : null,
            ]);
            $results['inserted']++;

            if ($memberId && $boardType && $assignmentDate) {
                $missionTypeId = getMissionTypeId($db, $boardType);
                if ($missionTypeId) {
                    saveCheck($db, $memberId, $assignmentDate, $missionTypeId, true, 'automation', "cafe:{$articleId}", null);
                }
            }
        } catch (PDOException $e) {
            $results['error']++;
        }
    }

    if (!empty($unmappedKeys)) {
        $results['unmapped_keys'] = $unmappedKeys;
    }
    return $results;
}
```

- [ ] **Step 2: 문법 체크**

```bash
php -l /root/boot-dev/public_html/includes/cafe/cafe_ingest.php
```
Expected: `No syntax errors detected ...`

- [ ] **Step 3: CLI smoke (DB 액세스)**

```bash
sudo -u apache php -r '
require "/var/www/html/_______site_SORITUNECOM_DEV_BOOT/public_html/includes/cafe/cafe_ingest.php";
$boards = cafeFetchActiveBoards();
echo "boards: " . count($boards) . "\n";
foreach ($boards as $b) echo "  menu={$b["menu_id"]} type={$b["board_type"]}\n";

// 임의 article id 로 cafeArticleExists 동작 확인 (DEV DB cafe_posts 의 실제 1건 가져와 true 인지)
$db = getDB();
$row = $db->query("SELECT cafe_article_id FROM cafe_posts ORDER BY id DESC LIMIT 1")->fetch();
if ($row) {
    $exists = cafeArticleExists((string)$row["cafe_article_id"]);
    echo "exists({$row["cafe_article_id"]}) = " . ($exists ? "true" : "false") . "\n";
}
$nope = cafeArticleExists("NOT_EXIST_999999999");
echo "exists(NOT_EXIST_999999999) = " . ($nope ? "true" : "false") . "\n";
'
```
Expected:
- `boards: 3` 그리고 각 행 (menu=288 type=daily_mission 등)
- `exists(<실제 id>) = true`
- `exists(NOT_EXIST_999999999) = false`

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && \
git add public_html/includes/cafe/cafe_ingest.php && \
git commit -m "feat(cafe): cafe_ingest.php — ingestCafePosts + DB 헬퍼 추출"
```

---

### Task 4: `integration.php` — `handleIntegrationCafePosts()` 본문 교체

**Files:**
- Modify: `/root/boot-dev/public_html/api/services/integration.php` (handler 본문)

기존 함수 본문을 `ingestCafePosts()` 호출로 교체. 응답 shape 동일.

- [ ] **Step 1: 함수 본문 교체**

`/root/boot-dev/public_html/api/services/integration.php` 의 `handleIntegrationCafePosts` 함수 (line 144~240) 를 통째로 아래로 치환.

기존 (Read 로 정확 매칭 확보):

```php
function handleIntegrationCafePosts($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireApiKey();
    $input = getJsonInput();
    $posts = $input['posts'] ?? [];
    if (empty($posts)) jsonError('posts 필요');

    $db = getDB();
    $results = ['inserted' => 0, 'skipped' => 0, 'error' => 0, 'unmapped' => 0];
    $unmappedKeys = [];

    // menu_id → board_type 매핑 로드
    $boardMapStmt = $db->query("SELECT menu_id, board_type FROM cafe_board_map WHERE is_active = 1");
    $boardMap = [];
    foreach ($boardMapStmt->fetchAll() as $bm) {
        $boardMap[$bm['menu_id']] = $bm['board_type'];
    }

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

    foreach ($posts as $post) {
        $articleId = $post['cafe_article_id'] ?? $post['article_id'] ?? '';
        $title = $post['title'] ?? '';
        $memberKey = $post['member_key'] ?? null;
        $nickname = $post['nickname'] ?? null;
        $postedAt = $post['posted_at'] ?? null;
        $missionChecked = (int)($post['mission_checked'] ?? 0);
        $assignmentDate = $post['assignment_date'] ?? null;

        $boardType = $post['board_type'] ?? null;
        if (!$boardType && isset($post['menu_id'])) {
            $boardType = $boardMap[(string)$post['menu_id']] ?? null;
        }

        if (!$articleId) {
            $results['error']++;
            continue;
        }

        $memberId = null;
        if ($memberKey) {
            if (isset($memberKeyCache[$memberKey])) {
                $memberId = $memberKeyCache[$memberKey];
            } else {
                $memberId = resolveMemberByKey($db, $memberKey);
                $memberKeyCache[$memberKey] = $memberId;
            }
            if (!$memberId) {
                $results['unmapped']++;
                $unmappedKeys[$memberKey] = $nickname ?? '';
            }
        }

        try {
            $insertStmt->execute([
                $articleId,
                $title,
                $memberKey,
                $nickname,
                $boardType,
                $postedAt,
                $memberId,
                $missionChecked,
                $assignmentDate,
                !empty($post) ? json_encode($post, JSON_UNESCAPED_UNICODE) : null,
            ]);
            $results['inserted']++;

            // 매핑된 회원 + board_type이 미션코드와 일치 + assignment_date가 있으면 자동 체크
            if ($memberId && $boardType && $assignmentDate) {
                $missionTypeId = getMissionTypeId($db, $boardType);
                if ($missionTypeId) {
                    saveCheck($db, $memberId, $assignmentDate, $missionTypeId, true, 'automation', "cafe:{$articleId}", null);
                }
            }
        } catch (PDOException $e) {
            $results['error']++;
        }
    }

    $response = $results;
    if (!empty($unmappedKeys)) {
        $response['details']['unmapped_keys'] = $unmappedKeys;
    }
    jsonSuccess($response);
}
```

신규:

```php
function handleIntegrationCafePosts($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireApiKey();
    $input = getJsonInput();
    $posts = $input['posts'] ?? [];
    if (empty($posts)) jsonError('posts 필요');

    require_once __DIR__ . '/../../includes/cafe/cafe_ingest.php';
    $r = ingestCafePosts($posts);

    $response = [
        'inserted' => $r['inserted'],
        'skipped'  => $r['skipped'],
        'error'    => $r['error'],
        'unmapped' => $r['unmapped'],
    ];
    if (!empty($r['unmapped_keys']))  $response['details']['unmapped_keys'] = $r['unmapped_keys'];
    if (!empty($r['error_details']))  $response['details']['errors']        = $r['error_details'];
    jsonSuccess($response);
}
```

- [ ] **Step 2: 문법 체크 + 응답 shape 회귀 확인**

```bash
php -l /root/boot-dev/public_html/api/services/integration.php
```
Expected: `No syntax errors detected ...`

- [ ] **Step 3: HTTP endpoint smoke (실제 cron 의존성 없는 검증)**

DEV `integration_api_keys` 의 활성 키 사용:

```bash
KEY=$(source /root/boot-dev/.db_credentials && mysql -N -B -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT api_key FROM integration_api_keys WHERE name='n8n-production' AND is_active=1 LIMIT 1" 2>/dev/null)
curl -sS -X POST -H "X-API-KEY: $KEY" -H "Content-Type: application/json" \
  -d '{"posts":[{"cafe_article_id":"smoke-test-1","title":"smoke","member_key":"smoke","nickname":"smoke","menu_id":"288","posted_at":"2026-05-06 12:00:00","assignment_date":"2026-05-06","mission_checked":1}]}' \
  "https://dev-boot.soritune.com/api/bootcamp.php?action=integration_cafe_posts"
echo ""
```
Expected: `{"success":true,"message":"성공","data":{"inserted":1,"skipped":0,"error":0,"unmapped":1,"details":{"unmapped_keys":{"smoke":"smoke"}}}}` 형태. inserted=1, unmapped=1 (가짜 member_key).

스모크 row cleanup:
```bash
source /root/boot-dev/.db_credentials && mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "DELETE FROM cafe_posts WHERE cafe_article_id='smoke-test-1'"
```

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && \
git add public_html/api/services/integration.php && \
git commit -m "refactor(integration): handleIntegrationCafePosts 본문을 ingestCafePosts 호출로"
```

---

### Task 5: `cron.php` — `cafe_poll` case + `cafePoll()` 함수

**Files:**
- Modify: `/root/boot-dev/public_html/cron.php` (case 추가, 함수 추가, 사용법 docblock 업데이트)

- [ ] **Step 1: switch case 와 사용법 안내 추가**

`cron.php` 의 switch 블록 안, `case 'notify_dispatch'` 의 break; 다음 줄, `default:` 직전에 새 case 추가:

```php
    case 'cafe_poll':
        cafePoll();
        break;
```

파일 상단 docblock 의 Commands 목록 (line 5~10 부근) 에 한 줄 추가:

```
 *   cafe_poll          매시 1회. 네이버 카페 활성 보드 폴링 → cafe_posts UPSERT
```

`default:` 케이스의 사용법 echo 블록 (notify_dispatch 줄 다음) 에도 한 줄 추가:

```php
        echo "  cafe_poll          매시 카페 활성 보드 폴링\n";
```

- [ ] **Step 2: `cafePoll()` 함수 추가**

`cron.php` 파일 끝 (cronLog 함수 다음 또는 바로 위) 에 추가:

```php
// ══════════════════════════════════════════════════════════════
// cafe_poll: 네이버 카페 활성 보드 polling (매시 실행)
// ══════════════════════════════════════════════════════════════

function cafePoll() {
    cronLog('cafe_poll START');
    require_once __DIR__ . '/includes/cafe/cafe_naver_client.php';
    require_once __DIR__ . '/includes/cafe/cafe_ingest.php';

    $boards = cafeFetchActiveBoards();
    if (empty($boards)) { cronLog('cafe_poll: no active boards'); return; }

    $newPosts = [];
    foreach ($boards as $b) {
        try {
            $articles = cafeFetchBoardArticles($b['menu_id'], 20);
        } catch (\Throwable $e) {
            cronLog("cafe_poll fetch fail menu={$b['menu_id']}: " . $e->getMessage());
            continue;
        }
        foreach ($articles as $a) {
            if (cafeArticleExists($a['cafe_article_id'])) continue;
            $a['menu_id']         = $b['menu_id'];
            $a['board_type']      = $b['board_type'];
            $a['assignment_date'] = substr($a['posted_at'], 0, 10);
            $a['mission_checked'] = 1;
            $newPosts[] = $a;
        }
    }

    if (empty($newPosts)) { cronLog('cafe_poll: no new posts'); return; }

    $r = ingestCafePosts($newPosts);
    cronLog(sprintf(
        'cafe_poll DONE: inserted=%d skipped=%d error=%d unmapped=%d',
        $r['inserted'], $r['skipped'], $r['error'], $r['unmapped']
    ));
}
```

- [ ] **Step 3: 문법 + 사용법 출력 회귀 확인**

```bash
php -l /root/boot-dev/public_html/cron.php
sudo -u apache php /var/www/html/_______site_SORITUNECOM_DEV_BOOT/public_html/cron.php
```
Expected:
- 문법 OK
- 사용법 출력에 `cafe_poll` 라인 보임 + exit 1

- [ ] **Step 4: 실제 cron 1회 실행**

```bash
sudo -u apache php /var/www/html/_______site_SORITUNECOM_DEV_BOOT/public_html/cron.php cafe_poll
echo "exit_code=$?"
tail -n 10 /var/www/html/_______site_SORITUNECOM_DEV_BOOT/logs/cron.log
```
Expected:
- exit_code=0 (PHP fatal 없음)
- `cafe_poll START` 와 `cafe_poll DONE: inserted=N skipped=0 error=0 unmapped=N` 형태 (N 은 0~여러 건)
- DB `cafe_posts` 에 새 row 들어옴 (N>0 인 경우)

이 단계에서 의존성 누락 등 fatal 발생 시 BLOCKED 보고.

- [ ] **Step 5: 두 번째 실행 — idempotent 검증**

```bash
sudo -u apache php /var/www/html/_______site_SORITUNECOM_DEV_BOOT/public_html/cron.php cafe_poll
```
Expected: `cafe_poll: no new posts` (cafeArticleExists 사전 필터로 모두 skip).

- [ ] **Step 6: Commit**

```bash
cd /root/boot-dev && \
git add public_html/cron.php && \
git commit -m "feat(cron): cafe_poll — 네이버 카페 활성 보드 1시간 폴링"
```

---

### Task 6: dev push + DEV crontab 등록

**Files:** (코드 외)

- [ ] **Step 1: dev push**

```bash
cd /root/boot-dev && git push origin dev
```

- [ ] **Step 2: DEV crontab 라인 추가**

`crontab -e` 로 편집기 열고 다음 라인 추가 (다른 boot DEV 라인들과 같은 영역):

```
# SoriTune DEV_BOOT - Cafe poll (hourly at :05)
5 * * * * /usr/bin/flock -n /tmp/dev-boot-cafe-poll.lock /usr/bin/timeout 240 /usr/bin/php /var/www/html/_______site_SORITUNECOM_DEV_BOOT/public_html/cron.php cafe_poll >/dev/null 2>>/var/www/html/_______site_SORITUNECOM_DEV_BOOT/logs/cron.log
```

- [ ] **Step 3: 등록 확인**

```bash
crontab -l | grep "cafe_poll"
```
Expected: 위 라인 한 줄만 (DEV).

---

### Task 7: DEV 검증 (auto + UI)

**Files:** (코드 외)

- [ ] **Step 1: 다음 :05 분 자동 실행 후 로그 확인**

다음 :05 분(시계 기준 매시 5분)이 지난 후:

```bash
date
grep cafe_poll /var/www/html/_______site_SORITUNECOM_DEV_BOOT/logs/cron.log | tail -n 10
```

Expected:
- 가장 최근 :05 분 timestamp 의 `cafe_poll START` 와 `cafe_poll DONE: inserted=N skipped=0 error=0 unmapped=N` (또는 `cafe_poll: no new posts`) 가 보임.
- 같은 timestamp 의 `cafe_poll START` 라인이 **한 번씩만** 찍힘 (중복 없음 — stdout `/dev/null` 작동 확인).
- PHP fatal/warning 가 cron.log 에 stderr 로 들어가 있지 않음.

- [ ] **Step 2: HTTP endpoint 회귀 (n8n 시뮬레이션)**

n8n 워크플로우는 아직 살아 있어야 함 (PROD 반영 후 사용자가 별도로 끔). 하지만 DEV 에서도 endpoint 가 ingest 하는지 한 번 더 검증 — Task 4 에서 이미 했지만 cron 작업 후 회귀 확인:

```bash
KEY=$(source /root/boot-dev/.db_credentials && mysql -N -B -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT api_key FROM integration_api_keys WHERE name='n8n-production' AND is_active=1 LIMIT 1" 2>/dev/null)
curl -sS -X POST -H "X-API-KEY: $KEY" -H "Content-Type: application/json" \
  -d '{"posts":[{"cafe_article_id":"smoke-test-2","title":"http smoke","member_key":"smoke","nickname":"smoke","menu_id":"288","posted_at":"2026-05-06 12:00:00","assignment_date":"2026-05-06","mission_checked":1}]}' \
  "https://dev-boot.soritune.com/api/bootcamp.php?action=integration_cafe_posts"
echo ""
source /root/boot-dev/.db_credentials && mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "DELETE FROM cafe_posts WHERE cafe_article_id='smoke-test-2'"
```
Expected: HTTP `success=true`, `inserted=1`. cleanup 으로 row 삭제.

- [ ] **Step 3: 사용자에게 어드민 UI 확인 요청**

운영자에게 다음 확인 요청:
- `dev-boot.soritune.com` 어드민 → 카페 게시글 탭 → 최근 시간 (cron 실행 후) 에 새 row 가 자연스럽게 표시되는지
- 매핑된 회원의 미션 자동 체크가 보이는지 (member_id 채워진 row, 체크리스트 status=완료)

확인 결과 사용자가 "문제 없음" 답변 시 Task 8 진행.

---

### Task 8: 정지 후 사용자 확인 요청

- [ ] **Step 1: 사용자에게 보고**

다음 정리해서 보고:
- DEV 7개 commit 목록 (Task 1-5 + push)
- Task 7 검증 결과 (cron auto-run, HTTP endpoint, UI 확인)
- PROD 반영 시 작업: PROD crontab 1줄, main 머지 + pull, n8n 은 그대로 (이중 호출 무해, 후속 PR 에서 비활성화)
- **"운영 반영해도 될까요?"** 명시적 질문

⛔ 사용자가 "운영 반영해줘" 등 명시적 요청을 하기 전까지 Task 9 시작 금지.

---

### Task 9: PROD 반영 (사용자 명시 요청 시에만)

**Files:** (코드 외)

- [ ] **Step 1: main 머지 + push**

```bash
cd /root/boot-dev && \
git checkout main && \
git merge --no-ff dev -m "merge: 카페 게시글 폴링 자체 cron 으로 이전" && \
git push origin main && \
git checkout dev
```

- [ ] **Step 2: PROD pull**

```bash
cd /root/boot-prod && git pull origin main
```

- [ ] **Step 3: PROD crontab 등록**

`crontab -e` 로 편집기 열고 다음 라인 추가 (DEV cafe_poll 라인 아래):

```
# SoriTune BOOT - Cafe poll (hourly at :00)
0 * * * * /usr/bin/flock -n /tmp/boot-cafe-poll.lock /usr/bin/timeout 240 /usr/bin/php /var/www/html/_______site_SORITUNECOM_BOOT/public_html/cron.php cafe_poll >/dev/null 2>>/var/www/html/_______site_SORITUNECOM_BOOT/logs/cron.log
```

- [ ] **Step 4: PROD 1회 수동 실행 (smoke)**

```bash
sudo -u apache php /var/www/html/_______site_SORITUNECOM_BOOT/public_html/cron.php cafe_poll
tail -n 5 /var/www/html/_______site_SORITUNECOM_BOOT/logs/cron.log
```
Expected: `cafe_poll START` + `cafe_poll DONE: ...` (또는 no new posts) 정상 출력. fatal 없음.

- [ ] **Step 5: PROD 다음 :00 분 자동 실행 확인**

운영자가 다음 정시에 `tail -n 30 /var/www/html/_______site_SORITUNECOM_BOOT/logs/cron.log | grep cafe_poll` 로 자동 실행 확인.

이중 호출 (cron + n8n) 발생하지만 idempotent 라 무해. n8n 비활성화는 1~2주 안정 운영 후 follow-up PR 에서 처리.

---

## 후속 작업 (이번 plan 범위 밖)

별도 PR 로 처리:
- n8n cloud 의 카페 폴링 워크플로우 비활성화 (운영자 직접)
- `integration_cafe_posts`, `integration_check`, `integration_check_bulk` HTTP endpoint 제거
- `requireApiKey()` 함수 + `integration_api_keys` 테이블 정리
- `integration_logs` 테이블 정리 (현재 0건)
- (별건) 기존 boot crontab 의 다른 cron 라인들도 stdout `/dev/null` 패턴으로 통일 — cronLog 중복 방지
