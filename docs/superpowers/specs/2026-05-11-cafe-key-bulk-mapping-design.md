# 카페 키 일괄 등록 (paste-based bulk mapping) — 설계

날짜: 2026-05-11
범위: boot.soritune.com (boot-dev → boot-prod)
배경: 카톡방에서 카페 게시글 링크를 받아 어드민(=사용자)이 손으로 `회원 추가/수정` 모달에서 게시글 번호를 하나씩 입력해 `cafe_member_key`를 등록하던 흐름을 일괄화한다.

## 배경

현재 카페 키 등록 흐름:

1. 신규/누락 회원이 카톡방에 카페 게시글 링크를 올린다.
2. 어드민이 그 사람의 카톡 닉/이름·조 정보를 사람 머리로 식별한다.
3. 어드민이 어드민 SPA → 회원 관리 → 해당 회원 수정 모달 → 게시글 번호 입력 → 가져오기 → 저장. 1명당 모달 열기·닫기 1회.

문제:
- 카톡에 글 올린 사람 중에 이미 `cafe_member_key`가 있는 사람과 없는 사람이 섞여 있어, 어드민이 매번 회원 카드를 열어 확인해야 함 (이미 등록되어 있으면 작업 낭비).
- 1명씩 모달을 여는 게 느리다. 12기 오픈 직후처럼 미등록자가 한꺼번에 카톡에 올라오는 시점엔 부담이 큼.

대안 검토 (별도 분석에서):

| 옵션 | 평가 |
|---|---|
| cron이 적재한 unmapped 글 매핑 페이지 | ❌ 매칭 신호가 카페 닉네임 1개만 → 정확도 약함. 외부인 글이 잡음으로 섞임. cron 주기로 즉시성 ↓ |
| 회원 추가/수정 모달에 paste 기능 추가 | ❌ 모달 비대화. 단일 회원 단위에서 벗어남 |
| **CSV paste 일괄 등록 페이지 (선택)** | ✅ 사용자가 카톡방에서 이미 (조,이름,닉) 식별 작업을 함 → 그 정보를 그대로 받음. 즉시성·잡음 없음·정확도 높음 |

이미 백필+saveCheck 소급 로직은 `handleCafeRemapUnmapped`(`api/services/integration.php:165-225`)에 존재하므로, 새 페이지는 그 패턴을 재사용한다.

## 정책 결정

사용자 의사 결정 요약:
- **입력 포맷**: CSV (`조,이름,닉,링크`) 4열. 헤더 행 있어도/없어도 OK. RFC 4180 큰따옴표 이스케이프 지원. 닉 비울 수 있음.
- **매핑 액션 범위**: 키 등록 + 같은 키 과거 `cafe_posts.member_id` 백필 + 과거 unmapped 글에 대해 `saveCheck()` 소급 호출.
- **미리보기 UX**: paste → 자동 매칭 결과 테이블 → 어드민이 행별로 검토(확정/수정 dropdown/건너뜀) → "일괄 적용" 한 번.
- **자동 매칭 신호**: 사용자가 준 (조, 이름, 닉) + 카페에서 가져온 (카페닉). HIGH(조+이름 정확 일치)는 기본 체크 ON, 그 외 어드민 명시 선택.
- **권한**: `operation` only (기존 회원 추가/수정과 동일).
- **cohort 컨텍스트**: 현재 chip cohort + stage_no 안의 active 회원을 우선 매칭 대상으로. dropdown 검색은 모든 활성 cohort 회원까지 가능.

## 변경 범위

### 신규 코드

| 파일 | 책임 |
|---|---|
| `public_html/includes/cafe/cafe_link_parser.php` | URL → article_id 추출 (네이버 API 의존 없는 정규식 모음) |
| `public_html/includes/cafe/cafe_csv_parser.php` | CSV 입력 → `[{row, group, name, nick, url, error?}]` 정규화 |
| `public_html/includes/cafe/cafe_article_fetch.php` | `fetchCafeArticleInfo(string $articleId): {memberKey, nick}` 또는 throw. 현재 `api/admin.php:778-829` 의 cURL 로직 추출 + 기존 `fetch_cafe_info` 액션도 이 헬퍼 호출하도록 리팩토링. |
| `public_html/includes/cafe/cafe_bulk_match.php` | (cohort, csvRow, memberKey, cafeNick) → `{status, candidates, existing_member?}` |
| `public_html/includes/cafe/cafe_bulk_apply.php` | 행 단위 트랜잭션 적용. 키 등록 + (공통 헬퍼로 백필+saveCheck) |
| `public_html/includes/cafe/cafe_backfill_helper.php` | `backfillPostsForMembers(PDO $db, array $memberIds): array` — 같은 키의 cafe_posts 백필 + saveCheck. `handleCafeRemapUnmapped` 와 공유. |
| `public_html/js/admin-cafe-bulk.js` | paste 페이지 IIFE (`AdminCafeBulkApp`). `AdminCafeApp` 패턴 따라감 (`/js/admin-cafe.js` 참고). |
| `tests/cafe_link_parser_test.php` | 정규식 단위 테스트 (PC 신/구, 모바일 신/구, 잘못된 URL) |
| `tests/cafe_csv_parser_test.php` | CSV 파서 단위 테스트 (헤더/이스케이프/빈 줄) |
| `tests/cafe_bulk_match_test.php` | 매칭 분기 단위 테스트 (SQLite in-memory) |
| `tests/cafe_bulk_apply_test.php` | DEV DB 통합 — 적용 → 백필 → saveCheck 검증 |
| `tests/cafe_bulk_invariants.php` | PROD smoke 인보리언트 (UNIQUE 위반 0, orphan 0 등) |

### 수정

| 파일 | 변경 |
|---|---|
| `public_html/api/admin.php` | 신규 액션 2개: `cafe_bulk_parse` (POST), `cafe_bulk_apply` (POST). 모두 `requireAdmin(['operation'])`. 기존 `fetch_cafe_info` 액션의 cURL 블록은 `cafe_article_fetch.php` 헬퍼 호출로 축약. |
| `public_html/api/services/integration.php` | `handleCafeRemapUnmapped` 의 백필 루프를 `cafe_backfill_helper.php` 의 공통 헬퍼 호출로 교체 (행위 동일). |
| `public_html/js/admin.js` | 회원 관리 탭 상단 액션바에 `[카페 키 일괄 등록]` 버튼. 클릭 시 `AdminCafeBulkApp.show()` 호출. |
| `public_html/operation/index.php` | `admin-cafe-bulk.js` script 태그 추가 (admin.js 직전, asset_version 사용). |

### 영향 없음

- `includes/cafe/cafe_naver_client.php`, `cafe_ingest.php`, `cron.php` (cafe_poll) — 그대로.
- 기존 `cafe_member_key` 등록 흐름 (`fetch_cafe_info`, `lookup_cafe_nick`, `member_create`/`member_update`) — 그대로 살아 있음. paste는 추가 진입점.
- `cafe_posts`, `bootcamp_members` 스키마 — 변경 없음. 마이그 0건.
- 다른 어드민 페이지 (코치/조장/leader) — 카페 키 등록은 operation 전용이므로 영향 없음.

## 인터페이스

### CSV 입력 포맷

```
조,이름,닉,링크
리사조,김명식,그릭이,https://cafe.naver.com/themysticsoritune/321852
무이조,이서연,서연쓰,https://m.cafe.naver.com/ca-fe/web/cafes/23243775/articles/321900
헤어조,최지원,,https://cafe.naver.com/ca-fe/cafes/23243775/articles/321915
```

- 헤더 행은 첫 행이 정확히 `조,이름,닉,링크` (공백·순서 그대로) 이면 skip. 아니면 데이터 행으로 처리.
- 빈 줄 무시.
- 큰따옴표로 묶인 필드 안의 쉼표·줄바꿈은 RFC 4180 처리.
- 닉 비울 수 있음 (3번째 컬럼 빈 문자열).
- 한 batch 상한 100행 (서버 보호). 초과 시 422.
- 조 이름은 `bootcamp_groups.name` (예: '리사조') 와 그대로 또는 '조' 글자 빠진 형태('리사')도 허용. 매칭 시 양쪽 LIKE 시도.

### 카페 링크 정규식 (`parseCafeLink(string $url): ?string`)

지원 형식 (모두 article_id만 추출):
- `https://cafe.naver.com/<board>/<articleId>` (PC, 옛 단축형)
- `https://cafe.naver.com/<board>?...&articleid=<articleId>` (PC, 쿼리 형식)
- `https://cafe.naver.com/ca-fe/cafes/23243775/articles/<articleId>` (PC, 신 SPA)
- `https://m.cafe.naver.com/<board>/<articleId>` (모바일 옛)
- `https://m.cafe.naver.com/ca-fe/web/cafes/23243775/articles/<articleId>` (모바일 신)
- `https://m.cafe.naver.com/ca-fe/web/cafes/23243775/menus/<menuId>/articles/<articleId>` (모바일 메뉴 경유)

`cafeClubId` (23243775)가 URL 안에 명시된 경우 일치 검증. 다른 카페 링크면 `WRONG_CAFE` 에러.

### API 1: `cafe_bulk_parse`

POST `/api/admin.php?action=cafe_bulk_parse`

요청:
```json
{ "csv": "<원본 CSV 문자열>" }
```

서버 처리:
1. CSV 파싱 → 행 배열.
2. 각 행에 대해:
   - 링크 파싱. 실패 시 `INVALID_LINK`.
   - 같은 batch 안에서 같은 articleId 중복이면 두 번째부터 `DUPLICATE_IN_BATCH`.
   - `fetch_cafe_info` 헬퍼 호출 (`api/admin.php:778-829`의 cURL 로직을 함수로 추출하거나 직접 호출) → `memberKey`, `cafeNick`. 실패 시 `CAFE_FETCH_FAIL` + 사유.
   - `bootcamp_members` 중 `cafe_member_key = memberKey` 회원 조회:
     - 일치 회원 있음 → `ALREADY_MAPPED_SAME` (행 회색, 적용 불가)
     - 일치 회원 없음 → (조, 이름, 닉)으로 cohort+stage 매칭 (아래 `cafe_bulk_match.php` 로직)
   - 추정 회원이 다른 `cafe_member_key`를 이미 보유 → `existing_key` 부가 정보.
   - 사용자가 paste에 적은 (조, 이름) 이 기존 매핑된 회원과 다른 사람을 가리킨다면 → `ALREADY_MAPPED_DIFF` (적용 시 키 교체 발생).

응답:
```json
{
  "success": true,
  "data": {
    "rows": [
      {
        "row": 1,
        "group": "리사조",
        "name": "김명식",
        "nick": "그릭이",
        "url": "https://...",
        "article_id": "321852",
        "member_key": "abc-uuid",
        "cafe_nick": "그릭이",
        "status": "HIGH",
        "candidates": [
          { "member_id": 123, "real_name": "김명식", "nickname": "그릭이",
            "group_name": "리사조", "stage_no": 1, "score": 1.0 }
        ],
        "existing_member": null
      },
      {
        "row": 8,
        "url": "https://invalid",
        "status": "INVALID_LINK",
        "error": "링크에서 게시글 번호를 추출할 수 없습니다."
      }
    ],
    "summary": { "total": 8, "high": 3, "mid": 2, "low": 1, "fail": 2 }
  }
}
```

상태 종류:
- `HIGH` — 조+이름 정확 일치 1명. 기본 적용 체크 ON.
- `MID` — 이름만 정확 일치 1명 (조 정보 없음 또는 조 미일치).
- `MID_MULTI` — 이름 정확 일치 N명 (조로 좁혀도 2+). dropdown.
- `LOW` — 이름 partial 일치 (LIKE).
- `NO_MATCH` — 후보 0.
- `ALREADY_MAPPED_SAME` — 키 이미 등록, paste의 (조, 이름) 과도 일치 → 건너뜀.
- `ALREADY_MAPPED_DIFF` — 키 이미 다른 회원에 등록. 적용 시 옛 회원 키 NULL 해제 + 새 회원에 부여.
- `DUPLICATE_IN_BATCH` — 같은 batch에 같은 articleId 중복.
- `CAFE_FETCH_FAIL` — 게시글 비공개/삭제/카페 API 에러.
- `WRONG_CAFE` — URL의 카페 ID가 소리튠영어(23243775) 아님.
- `INVALID_LINK` — 링크 정규식 실패.

### API 2: `cafe_bulk_apply`

POST `/api/admin.php?action=cafe_bulk_apply`

요청:
```json
{
  "rows": [
    { "row": 1, "article_id": "321852", "member_key": "abc-uuid",
      "cafe_nick": "그릭이", "target_member_id": 123 },
    { "row": 6, "article_id": "321900", "member_key": "def-uuid",
      "cafe_nick": "현주",   "target_member_id": 234 }
  ]
}
```

- `target_member_id` 가 null이면 해당 행 skip (어드민이 미선택한 행).
- 클라이언트는 `cafe_bulk_parse` 의 응답에서 이 4필드만 골라 보낸다.

서버 처리 (행 단위):
1. `BEGIN`
2. `bootcamp_members` 에서 `cafe_member_key = :member_key AND id != :target_member_id` 행을 NULL 해제 (`UPDATE ... SET cafe_member_key = NULL`). DIFF 케이스 자동 처리. 영향 행 수를 `displaced` 로 기록.
3. `UPDATE bootcamp_members SET cafe_member_key = :member_key WHERE id = :target_member_id`.
4. 공통 헬퍼 호출: `backfillPostsForMembers($db, [$target_member_id])` → `{remapped, missions_saved}` 받음.
5. `COMMIT`
6. 행 결과 누적.

한 행 PDOException → 그 행만 ROLLBACK + `status=failed`. 다른 행 계속 진행.

응답: §4-6 그대로.

### 공통 헬퍼: `backfillPostsForMembers`

`includes/cafe/cafe_backfill_helper.php`:

```php
/**
 * 주어진 member_id 리스트에 대해 cafe_posts.member_id 백필 + 미션 saveCheck 소급.
 * handleCafeRemapUnmapped 의 핵심 루프와 동일. 전체 unmapped → 특정 회원으로 범위만 좁힘.
 *
 * @return array{remapped:int, missions_saved:int, by_member:array<int,array>}
 */
function backfillPostsForMembers(PDO $db, array $memberIds): array;
```

내부:
- 회원들의 `cafe_member_key` 일괄 조회.
- `cafe_posts WHERE member_key IN (...) AND member_id IS NULL` 일괄 조회.
- 각 post에 대해 `UPDATE cafe_posts SET member_id, mission_checked=1` + `saveCheck(...)` (board_type, assignment_date 있을 때만).
- `saveCheck` 반환 `action` 이 created/updated 면 missions_saved++.

`handleCafeRemapUnmapped` 도 이 헬퍼를 호출하도록 리팩토링:
- 기존: 전체 unmapped 게시글 조회 → 루프.
- 신규: 전체 `cafe_member_key NOT NULL AND is_active=1` 회원 ID 모아서 `backfillPostsForMembers` 1회 호출.
- 행동 차이 0건.

### 매칭 로직 (`cafe_bulk_match.php`)

`matchCandidates(PDO $db, int $cohortId, string $cafeMemberKey, ?string $groupName, string $realName, ?string $nickname): array`

cohort 안의 모든 stage (1·2 단계) 회원을 동일하게 매칭 대상으로 둠 — 같은 chip cohort 안에 단계가 섞여 있을 수 있고, paste 행에 단계 정보 없음.

순서대로 시도:

1. **이미 매핑 체크**:
   ```sql
   SELECT id, real_name, group_id, ... FROM bootcamp_members WHERE cafe_member_key = :key
   ```
   결과 있으면 → 그 회원이 paste 의 (조, 이름)과 일치하면 `ALREADY_MAPPED_SAME`, 아니면 `ALREADY_MAPPED_DIFF`. 종료.

2. **조+이름 정확 일치** (chip cohort 안):
   ```sql
   SELECT bm.id, bm.real_name, bm.nickname, bg.name AS group_name, bm.stage_no
   FROM bootcamp_members bm
   JOIN bootcamp_groups bg ON bg.id = bm.group_id
   WHERE bm.cohort_id = :cohort
     AND bm.member_status = 'active'
     AND bm.cafe_member_key IS NULL
     AND (bg.name = :group OR bg.name = CONCAT(:group, '조'))   -- '리사' or '리사조' 모두 처리
     AND (bm.real_name = :name OR bm.nickname = :name)
   ```
   N==1 → `HIGH`. N>=2 → `MID_MULTI` (조 안 동명이인). 종료시 후보 반환.

3. **이름만 일치** (cohort 안, 조 무시):
   ```sql
   AND (bm.real_name = :name OR bm.nickname = :name)
   ```
   N==1 → `MID`. N>=2 → `MID_MULTI`. 종료.

4. **이름 LIKE** (`%name%`):
   N==1 → `LOW`. N>=2 → `LOW` (후보 N개 dropdown).

5. **모두 0** → `NO_MATCH`.

`existing_key` (추정 회원이 이미 다른 키 보유) 정보는 후보별로 같이 채움. 매칭 후보엔 `cafe_member_key IS NULL` 조건을 두지만, dropdown 검색에서는 이미 키 보유한 회원도 노출 가능 (apply 시 키 교체 시나리오).

### UI (`admin-cafe-bulk.js`)

회원 관리 탭 상단 액션바에 `[카페 키 일괄 등록]` 버튼 추가. 클릭 시 별도 view (모달 아님 — 화면 전체):

```
┌──────────────────────────────────────────────────────────────────┐
│ 카페 키 일괄 등록                                  [← 회원 관리] │
├──────────────────────────────────────────────────────────────────┤
│ 1) 카톡방에서 받은 정보를 CSV로 붙여넣으세요 (조,이름,닉,링크)    │
│ ┌────────────────────────────────────────────────────────────┐   │
│ │ 리사조,김명식,그릭이,https://cafe.naver.com/...            │   │
│ │ ...                                                        │   │
│ └────────────────────────────────────────────────────────────┘   │
│ [파싱하기]                                                       │
│                                                                  │
│ 2) 미리보기 (8행 — HIGH 3 / MID 2 / LOW 1 / FAIL 2)              │
│ [전체 체크] [HIGH만 체크] [전체 해제]                            │
│ ┌──────────────────────────────────────────────────────────┐    │
│ │ # 조  이름  닉    카페닉  매칭회원       상태   액션      │    │
│ │ 1 리사 김명식 그릭이 그릭이 김명식(리사조) HIGH   [☑]      │    │
│ │ 2 무이 이서연 서연쓰 서연s  이서연(무이조) MID    [☑]▼     │    │
│ │ ...                                                       │    │
│ └──────────────────────────────────────────────────────────┘    │
│ [4개 행 적용]                                                    │
│                                                                  │
│ 3) 적용 결과 (적용 후 노출)                                      │
│ - 적용: 4건 / 건너뜀: 1건 / 실패: 2건                            │
│ - 행 6: 김선영(헤어조) 키 교체 (옛 회원: 정민지)                 │
│ - 행 7: 게시글 접근 불가 - 비공개 글                             │
└──────────────────────────────────────────────────────────────────┘
```

행 클릭 → 카페 글 새 탭 열기 (검증용).
dropdown 검색: 초기 후보 표시 + 사용자가 타이핑하면 모든 활성 cohort 회원 검색 (debounce 200ms).

상태 색 (CSS 변수 활용):
- HIGH: 연두
- MID/LOW: 노랑
- NO_MATCH: 회색
- ALREADY_MAPPED_SAME: 옅은 회색 (선택 불가)
- ALREADY_MAPPED_DIFF: 주황 + 옛/새 회원 모두 표시
- FAIL: 빨강

## 데이터 흐름

```
[카톡방] → 사용자 정리 → [페이지에 paste]
   → [cafe_bulk_parse]
       → CSV 파싱
       → for 각 행: 링크 파싱 → fetch_cafe_info (네이버 API) → matchCandidates
   → [미리보기 테이블]
   → 어드민 검토 (체크 / dropdown 수정 / 건너뜀)
   → [cafe_bulk_apply]
       → for 각 행 (트랜잭션):
           UPDATE 옛 키 보유 회원 → NULL
           UPDATE 새 target 회원 cafe_member_key = memberKey
           backfillPostsForMembers([target]) → cafe_posts.member_id 채움 + saveCheck
   → [결과 모달]
```

## 엣지 케이스 / 정책

- **batch 안 같은 memberKey 중복**: 두 번째 행부터 `DUPLICATE_IN_BATCH`, 적용 불가.
- **batch 안 같은 target_member_id 중복** (memberKey는 다른데 회원 dropdown으로 같은 사람 지정): UI에서 적용 전 노란 경고 표시. 적용은 허용하되 회원에 마지막 키만 남음. 결과 모달에 명시.
- **카페 게시글 비공개/삭제**: `CAFE_FETCH_FAIL`. 다른 행 영향 없음.
- **네이버 API rate limit / timeout**: 그 행 `CAFE_FETCH_FAIL`. 50+ 행에서 sleep 옵션은 deferred (운영상 100행 미만 예상).
- **WRONG_CAFE** (다른 카페 링크): 거부.
- **paste 회원이 chip cohort 밖**: 자동 매칭은 후보 0 (`NO_MATCH`). dropdown 검색은 모든 활성 cohort까지 가능.
- **회원 status가 active 아님**: 매칭/검색 대상 제외.
- **stage 변경 직후 paste**: 백필 saveCheck는 회원 현재 stage 기준. 운영상 거의 없을 예정이라 deferred.
- **감사 로그**: 결과 모달 + 한 줄 cron.log INFO (`cafe_bulk_apply: applied=4 skipped=1 failed=2 by=admin#{id}`). 별도 테이블 안 만듦. paste 원문은 사용자가 외부 보관.

## 보안

- `requireAdmin(['operation'])` only. 코치/조장은 접근 불가.
- CSV는 100행 상한, batch 단일 호출 timeout = 240s (서버 PHP timeout 범위).
- 네이버 API URL 인자에 사용자 입력 직접 끼우지 않음 (article_id는 정규식으로 추출된 숫자 또는 영숫자만).
- SQL은 prepared statement.
- DIFF 케이스의 옛 회원 키 해제는 `UPDATE ... WHERE cafe_member_key = ? AND id != ?` 패턴 (기존 admin.php:696-699 패턴 그대로). 단일 키가 여러 회원에 있는 데이터 깨짐이 있어도 모두 NULL 해제 후 새 회원에 부여.

## 테스트

### Unit (boot `tests/`)

`cafe_link_parser_test.php` (`php tests/cafe_link_parser_test.php`):
- PC 신/구, 모바일 신/구, 메뉴 경유, 쿼리 articleid, 다른 카페, 잘못된 URL, 빈 문자열 — 10 케이스.

`cafe_csv_parser_test.php`:
- 헤더 있음/없음, 빈 줄, 큰따옴표 이스케이프, 컬럼 수 부족/초과, 닉 비움, BOM, 100행 초과 거부 — 8 케이스.

`cafe_bulk_match_test.php`:
- SQLite in-memory + 시드. HIGH / MID / MID_MULTI / LOW / NO_MATCH / ALREADY_MAPPED_SAME / ALREADY_MAPPED_DIFF / 다른 stage / 비활성 회원 제외 — 9 케이스.

### Integration (DEV DB)

`cafe_bulk_apply_test.php`:
- 시드: cohort 1개 + groups 2개 + 회원 5명 + cafe_posts unmapped 더미 8건 (다양한 memberKey).
- 가짜 paste batch → apply → 검증:
  - `bootcamp_members.cafe_member_key` 등록됨.
  - 같은 키의 과거 `cafe_posts.member_id` 모두 채워짐.
  - `mission_checks` 등 saveCheck 결과 row 생성됨 (구체 테이블명은 `saveCheck` 구현 확인 후).
  - DIFF 케이스: 옛 회원 키 NULL + 새 회원 키 설정.
  - 트랜잭션 격리: 일부러 한 행 실패시켰을 때 다른 행 영향 없음.
  - `handleCafeRemapUnmapped` 도 같은 헬퍼를 쓰는데 행동 변화 없음 확인 (regression).

### PROD 인보리언트 (`tests/cafe_bulk_invariants.php`)

배포 직후 PROD에서:
- `cafe_member_key` UNIQUE 위반 0건.
- `cafe_posts.member_id IS NOT NULL` 인데 `bootcamp_members.id` 에 대응 없는 orphan 0건.
- 같은 `cafe_member_key` 가 여러 `bootcamp_members` 에 동시 설정된 행 0건 (UNIQUE 위반 동치, 이중 검증).
- DEV 테스트 회원 5명 random spot check — cafe_posts 매핑된 글 수와 mission_checks 행 수 일치.

### 수동 검증 (DEV)

배포 전 어드민이 직접:
- 더미 회원 1명 키 일부러 NULL 처리 → paste로 재등록 → 미션 체크 자동 적용.
- 일부러 잘못된 링크 paste → FAIL 상태 + 다른 행 영향 없음.
- DIFF 시뮬레이션 (한 키를 다른 회원에 강제 부여 후 paste) → 키 교체 확인.
- ALREADY_MAPPED_SAME 회원의 글 paste → "건너뜀" 표시, 회색 행, 적용 영향 없음.

## 배포 순서

1. DEV_BOOT (`dev` 브랜치) 에서 위 변경 구현 + 테스트.
2. unit/integration 그린.
3. DEV에서 사용자 수동 검증 (UI + DIFF/FAIL/SKIP 케이스).
4. **사용자에게 dev 확인 요청 후 멈춤.** 운영 반영 명시적 요청 시에만 main 머지 + prod pull.
5. PROD 배포 후 `cafe_bulk_invariants.php` 실행.

마이그 0건이므로 schema lock 없음. 코드 deploy 만으로 활성화.

## 향후 변경 가능성

- 50+ 행 batch에서 네이버 API rate limit 보호용 sleep — 사용 패턴 보면서.
- 외부 카페 (다른 cafeClubId) 지원 — 현재는 23243775만. 다중 카페로 확장 시 `cafe_board_map` 에 `club_id` 컬럼 추가.
- `change_logs` 등 별도 감사 테이블 — 키 교체 빈도가 많아지면 그때.
- paste 페이지에서 직접 게시글 번호만 입력해도 받을 수 있게 (링크 없이) — 현재 사용자 흐름이 카톡 링크 위주라 deferred.
