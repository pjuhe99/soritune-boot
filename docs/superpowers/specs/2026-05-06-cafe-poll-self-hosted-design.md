# 카페 게시글 폴링 자체 구현 (n8n 의존 제거) — 설계

날짜: 2026-05-06
범위: boot.soritune.com (boot-dev → boot-prod)
선행 작업: `2026-05-06-zoom-self-hosted-design.md` (Zoom outbound 자체 구현, 동일 세션에서 PROD 반영 완료 — main `eac478b`)

## 배경

n8n cloud 인스턴스(`yekong.app.n8n.cloud`) 가 1시간 주기로 네이버 카페 (소리튠영어, cafeId `23243775`) 의 미션 인증 보드 3개를 폴링한 뒤 boot 의 `/api/bootcamp.php?action=integration_cafe_posts` HTTP endpoint 로 push 한다. 사용자 확인: n8n 의 카페 영역은 이 한 가지 동작뿐. `integration_check`, `integration_check_bulk` 등 다른 endpoint 는 이미 boot 자체에서 처리되어 사용 안 됨.

자체 PHP cron 으로 대체하고 n8n cloud 외부 의존을 끊는다. 보수적 접근 (옵션 A) — 정리는 1~2주 후 follow-up PR.

## 폴링 대상

`cafe_board_map` 테이블의 `is_active=1` 행 (현재 PROD 3건):
| menu_id | board_name | board_type |
|---------|-----------|-----------|
| 288 | 1.데일리미션 인증(매일) | daily_mission |
| 290 | [루크조]말까미션 5주차 | speak_mission |
| 322 | 2.내맛33미션 인증(매일) | inner33 |

활동량은 PROD 최근 7일 1~3건/일. 1시간 주기 + per_page=20 면 누락 위험 무시할 수준.

## 변경 범위

신규
- `public_html/includes/cafe/cafe_naver_client.php` — 네이버 카페 공개 API 호출 (board listing). 인증 없음.
- `public_html/includes/cafe/cafe_ingest.php` — `ingestCafePosts(array $posts): array` 핵심 로직.

수정
- `public_html/cron.php` — `case 'cafe_poll'` + `cafePoll()` 함수 추가 (네이버 listing → ingest 호출).
- `public_html/api/services/integration.php` — `handleIntegrationCafePosts()` 본문을 `ingestCafePosts()` 호출로 교체. HTTP endpoint 자체는 보수적 유지 (롤백 경로).

서버 사이드
- crontab DEV `5 * * * *`, PROD `0 * * * *` (5분 분리, PT/notify 패턴 동일).
- `flock` 중첩 차단 + `timeout 240` hang 보호.

영향 없음
- `cafe_posts`, `cafe_board_map`, `bootcamp_members.cafe_member_key` 테이블/컬럼 그대로
- 어드민 UI (`admin.js` 카페 게시글 탭, "수동 반영" 버튼) 그대로
- `sync_cafe_profiles.php` (CSV 수동 매핑) 그대로
- DB 스키마 변경 없음

이번 범위 밖 (follow-up PR)
- `integration_cafe_posts`, `integration_check`, `integration_check_bulk` HTTP endpoint 제거
- `requireApiKey()` + `integration_api_keys` 테이블 정리
- `integration_logs` 테이블 정리 (현재 0건, 사용처 없음)
- n8n cloud 워크플로우 비활성화 (Zoom 작업과 동일하게 PROD 안정 후)

## 폴링 흐름

```
cron.php cafe_poll
  ├─ cafeFetchActiveBoards()
  │   └─ SELECT * FROM cafe_board_map WHERE is_active = 1
  │
  ├─ for each board:
  │   ├─ cafeFetchBoardArticles(menu_id, perPage=20)
  │   │   └─ GET 네이버 공개 listing API → [{cafe_article_id, title, posted_at, member_key, nickname}]
  │   │
  │   └─ for each article:
  │       ├─ cafeArticleExists($id) → true 면 skip (호출 절약)
  │       └─ false 면 menu_id, board_type, assignment_date=DATE(posted_at), mission_checked=1 채워서 $newPosts 에 push
  │
  └─ ingestCafePosts($newPosts)
```

핵심 결정
- **1-단계 fetch** — listing 응답에 `writerInfo.memberKey/nick` 가 포함됨. per-article 추가 호출 불필요. memberKey 가 누락된 article 은 그 회차 스킵, 다음 회차에서 재시도 (드문 케이스).
- **per_page=20** — 일평균 1~3건 대비 충분한 여유, cron 누락 시에도 복구 가능.
- **dedupe** — `cafe_posts.uk_cafe_article` UNIQUE 가 자동 dedupe. cron 안 `cafeArticleExists()` 는 ingest 호출 줄이는 작은 최적화 (필수 아님).
- **assignment_date** — `DATE(posted_at)` (KST 기준). n8n 도 동일 식 (raw_data 샘플 확인).

## 네이버 API endpoint

후보 (구현 시 첫 단계로 직접 curl 검증, 통과한 것 픽스):
1. `https://apis.naver.com/cafe-web/cafe-articleapi/v3/cafes/{cafeId}/menus/{menuId}/articles?per_page=20&sort_by=write_dt`
2. `https://apis.naver.com/cafe-web/cafe-articleapi/v2.1/...` (구버전)
3. `https://article.cafe.naver.com/gw/v4/cafes/{cafeId}/articles?menu_id={menuId}` (front-facing API, `sync_cafe_profiles.php` 와 같은 host)

공개 보드라 인증 헤더·쿠키 불필요. User-Agent 헤더만 일반 브라우저 형식으로 (sync_cafe_profiles.php 와 동일 패턴):
```
Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36
```

응답 파싱
- `articleList[].item.articleId` → cafe_article_id
- `articleList[].item.subject` → title
- `articleList[].item.writeDateTimestamp` (epoch ms) → posted_at (KST)
- `articleList[].item.writerInfo.memberKey` → member_key
- `articleList[].item.writerInfo.nick` → nickname

응답 shape 이 후보별로 다를 수 있어 정확한 path 는 검증 후 픽스.

## ingestCafePosts() 인터페이스

```php
// public_html/includes/cafe/cafe_ingest.php

/**
 * 카페 게시글 일괄 ingestion.
 * - cafe_posts 테이블에 UPSERT
 * - member_key 가 bootcamp_members.cafe_member_key 와 매칭되면 member_id 채움
 * - member_id + board_type + assignment_date 모두 있으면 미션 자동 체크
 *   (saveCheck source='automation', source_ref="cafe:{articleId}")
 *
 * @param array $posts  각 항목: {
 *   cafe_article_id, title, member_key, nickname,
 *   menu_id|board_type, posted_at, assignment_date, mission_checked
 * }
 * @return array {
 *   inserted, skipped, error, unmapped: int,
 *   unmapped_keys: array<member_key, nickname>,
 *   error_details: array
 * }
 */
function ingestCafePosts(array $posts): array;
```

기존 `handleIntegrationCafePosts()` 본문에서 핵심 로직(board_type 매핑, member_key→member_id 매핑, UPSERT, 자동 체크)을 그대로 옮긴다. 새 동작 추가 없음.

## handleIntegrationCafePosts() 변경 후

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
    if (!empty($r['unmapped_keys'])) $response['details']['unmapped_keys'] = $r['unmapped_keys'];
    if (!empty($r['error_details'])) $response['details']['errors']        = $r['error_details'];
    jsonSuccess($response);
}
```

응답 shape 동일 → n8n 이 끊기기 전까지 계속 push 해도 무해, 어드민 UI 영향 없음.

## cron.php cafePoll()

```php
function cafePoll() {
    cronLog('cafe_poll START');
    require_once __DIR__ . '/includes/cafe/cafe_naver_client.php';
    require_once __DIR__ . '/includes/cafe/cafe_ingest.php';

    $boards = cafeFetchActiveBoards();
    if (empty($boards)) { cronLog('no active boards'); return; }

    $newPosts = [];
    foreach ($boards as $b) {
        try {
            $articles = cafeFetchBoardArticles((string)$b['menu_id'], 20);
        } catch (\Throwable $e) {
            cronLog("fetch fail menu={$b['menu_id']}: " . $e->getMessage());
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

    if (empty($newPosts)) { cronLog('no new posts'); return; }

    $r = ingestCafePosts($newPosts);
    cronLog(sprintf(
        'cafe_poll DONE: inserted=%d skipped=%d error=%d unmapped=%d',
        $r['inserted'], $r['skipped'], $r['error'], $r['unmapped']
    ));
}
```

## Crontab 라인

```
# SoriTune DEV_BOOT - Cafe poll (hourly at :05)
5 * * * * /usr/bin/flock -n /tmp/dev-boot-cafe-poll.lock /usr/bin/timeout 240 /usr/bin/php /var/www/html/_______site_SORITUNECOM_DEV_BOOT/public_html/cron.php cafe_poll >> /var/www/html/_______site_SORITUNECOM_DEV_BOOT/logs/cron.log 2>&1

# SoriTune BOOT - Cafe poll (hourly at :00)
0 * * * * /usr/bin/flock -n /tmp/boot-cafe-poll.lock /usr/bin/timeout 240 /usr/bin/php /var/www/html/_______site_SORITUNECOM_BOOT/public_html/cron.php cafe_poll >> /var/www/html/_______site_SORITUNECOM_BOOT/logs/cron.log 2>&1
```

`flock -n` 으로 이전 회차 진행 중이면 즉시 종료, `timeout 240` 4분 hang 시 강제 종료. 기존 boot crontab `notify_dispatch` 패턴과 동일.

## 실패 처리

| 시나리오 | 동작 |
|---------|-----|
| 네이버 API timeout/network | `cronLog("fetch fail menu=...")`, 그 board 스킵, 다음 board 계속 |
| 네이버 API 5xx | 동일 — 다음 cron 회차에서 자연 복구 |
| listing 응답 파싱 실패 | 동일 — 그 board 스킵 |
| 개별 article memberKey 누락 | 그 article 스킵, 다음 회차에서 재시도 (UPSERT 안전) |
| `cafe_article_id` 누락 | ingest 안에서 `error++`, 그 row 만 스킵 |
| DB 오류 (PDOException) | ingest 안에서 catch → `error++`, 다른 row 들 계속 처리 |
| cron 자체 fatal | crontab stderr → cron.log. 별도 알림 없음 (notify_dispatch 등도 동일 정책) |

invariant — ingest 는 idempotent. cron 회차 겹쳐도 (flock 풀린 희박한 경우) 데이터 손상 없음.

모니터링은 `logs/cron.log` `grep cafe_poll` 만. 일반:
```
[2026-05-06 14:00:01] cafe_poll START
[2026-05-06 14:00:03] cafe_poll DONE: inserted=2 skipped=0 error=0 unmapped=0
```

## 마이그레이션 & 배포

DEV (`boot-dev`)
1. 코드 작성·commit (task 단위 분할):
   - `cafe_naver_client.php` (listing 함수)
   - `cafe_ingest.php` (`ingestCafePosts()` 추출)
   - `cron.php` `case 'cafe_poll'` 추가
   - `integration.php` `handleIntegrationCafePosts()` 본문 교체
2. push origin dev
3. DEV 검증:
   - 3-1 네이버 API endpoint 검증 — CLI 에서 `cafeFetchBoardArticles('288', 20)` 직접 호출, 200 OK + articleList 정상인지. 실패 시 v2.1/gw fallback 시도, 통과한 endpoint 픽스.
   - 3-2 cron CLI 1회 직접 실행 — `php cron.php cafe_poll`, DB `cafe_posts` 새 row 들어가는지. 같은 article 두 번째 실행 시 inserted=0 (idempotent).
   - 3-3 HTTP endpoint 회귀 — `curl -X POST -H "X-API-KEY: ..." -d '{"posts":[...]}' /api/bootcamp.php?action=integration_cafe_posts`, 응답 shape 동일·DB 처리 동일.
   - 3-4 crontab 등록, 다음 :05 분에 실행되는지 logs/cron.log 확인.
4. **사용자 확인 요청 후 정지** — 어드민 카페 게시글 탭에서 새 row 정상 표기 확인.

PROD (`boot-prod`) — 사용자 명시 요청 후에만
5. main 머지 + push + boot-prod git pull
6. PROD crontab 등록
7. n8n 워크플로우는 즉시 끊지 않고 그대로 (이중 호출 무해, idempotent)
8. PROD cron 첫 1~2회차 logs/cron.log 확인

n8n 비활성화는 별도 follow-up (1~2주 안정 운영 후 — 위 "이번 범위 밖" 항목들과 함께)

롤백
- cron 사고 시 — crontab 라인 주석 처리 → n8n 재활성화. 코드 git revert 까진 불필요.
- ingestion 로직 사고 시 — `integration.php` `handleIntegrationCafePosts()` git revert (HTTP endpoint·cron 양쪽 영향).

테스트 자동화 — 이번 범위 밖
- Naver API mocking 가치 낮음 (외부 API 응답 형태가 코어 동작 결정)
- ingestCafePosts 자체는 기존 PROD 7497건 검증된 로직을 그대로 함수로 옮긴 것
