# 회원 카드에 네이버 카페 닉네임 표시 — 설계

작성일: 2026-05-20
관련 파일:
- `public_html/js/bootcamp.js` (`memberCellHtml` 헬퍼)
- `public_html/api/services/check.php` (`handleChecklist`, `handleChecklistByMission`, `handleStatusBoard` SELECT 절)
- `public_html/includes/cafe/cafe_ingest.php` (`ingestCafePosts` 동기화 hook)
- `public_html/includes/cafe/cafe_bulk_apply.php` (벌크 매핑 직후 1회 백필 hook)
- 마이그: `migrate_cafe_nickname.php` (boot 루트, CLI 스크립트 — boot 컨벤션)
- 관련 진입점 (변경 없음, 자동 적용 대상):
  - `public_html/operation/index.php`
  - `public_html/coach/index.php`
  - `public_html/head/index.php`
  - `public_html/leader/index.php`

## 1. 배경

`#bc-tab-checklist` 체크리스트(일별/과제별 뷰)와 `#bc-tab-status` 현황판은 같은 헬퍼 `memberCellHtml(m)` 으로 회원 카드를 그린다. 현재 카드에는 **소리튠 닉네임 + 실명** 만 표기되어 있어, 조장/코치/운영자가 네이버 카페 게시글에서 본 닉네임을 부트캠프 회원과 매칭하려면 별도 화면(회원 관리 등)으로 확인해야 한다.

`bootcamp_members.cafe_member_key` 는 이미 카페 유저 식별키를 저장하고 있고, `cafe_posts.nickname` 은 매시 cron poll 에서 각 게시글의 카페 닉을 적재한다. 두 데이터를 묶어 카드에 표시하면 조장이 카페에서 본 글 작성자를 즉시 인지할 수 있다.

12기 활성 320명 중 `cafe_member_key` 가 매핑된 회원은 63명(~20%). 나머지는 카페 닉 표시 대상이 아니다 (매핑이 채워지면 자연 표시됨).

## 2. 목표

1. 회원 카드 sub-line 에 `· ☕ {cafe_nickname}` 을 추가한다 (`memberCellHtml` 한 군데).
2. 카드 표시 대상 4역할 화면 (leader/coach/head/operation) × 3뷰 (체크리스트 일별 / 체크리스트 과제별 / 현황판) 모두에 **자동 전파** 한다.
3. `bootcamp_members` 에 `cafe_nickname VARCHAR(100) NULL` 컬럼을 추가하고, **cafe_posts ingest 시 동기 갱신** 한다.
4. 기존 `cafe_member_key` 매핑 + `cafe_posts.nickname` 데이터로 **1회 백필**.
5. `cafe_member_key` 가 새로 지정될 때 (벌크 매핑 적용 시점) **즉시 1회 백필** — 다음 cron poll 까지 빈 카드로 노출되지 않게.

## 3. 비목표 (out of scope)

- 카페 닉을 카드 외에 다른 화면 (회원 관리, 코인 로그, 응원상, QR, 부활) 에 추가 — v1 범위 아님.
- 카페 닉으로 정렬·필터·검색 — 인덱스 불필요, 단순 표시.
- 실시간(on-demand) Naver Cafe API fetch — 운영자 페이지의 `lookup_cafe_nick` 핸들러는 그대로 두고, 카드 표시용으로는 호출하지 않는다 (hot-path 보호).
- `cafe_nickname` 변경 이력(history) 저장 — `cafe_posts.nickname` 자체가 게시글 시점 닉을 가지므로 별도 보존 불필요. `bootcamp_members.cafe_nickname` 은 항상 "최신값" 이다.
- `cafe_member_key` 가 NULL 인 회원 자동 매핑 — 이번 작업 범위 아님 (별도 벌크 매핑 기능 책임).

## 4. UX 설계

### 회원 카드 (memberCellHtml)

**기존:**
```
[ 소리튜닉 (실명)  12회차 ]
  1조 · 3단계
```

**변경:**
```
[ 소리튜닉 (실명)  12회차 ]
  1조 · 3단계 · ☕ 카페닉
```

- `m.cafe_nickname` 이 truthy 일 때만 ` · <span class="bc-member-cafe-nick">☕ {nick}</span>` 추가.
- `cafe_nickname` 이 NULL/빈 문자열이면 아무것도 출력 안 함 (자연 생략).
- escape: `App.esc(m.cafe_nickname)`.
- 색상/스타일: 기존 sub-line(`member-sub`) 의 동일 톤 — 별도 CSS 추가 안 함 (긴 닉네임 자동 줄바꿈은 sub-line 의 기존 `text-overflow:ellipsis` 또는 자연 wrap 에 따른다).

### 그룹/단계가 NULL 인 경우 (회귀 0)

기존 `${m.group_name || '-'}` 패턴 그대로 유지. 카페 닉이 있는데 그룹이 NULL 인 케이스는:
```
- · 3단계 · ☕ 카페닉
```
으로 그대로 그려진다. 카드 sub-line 의 첫 segment 가 `'-'` 인 기존 동작을 깨지 않는다.

## 5. 데이터 모델

### 5.1 마이그

boot 컨벤션은 numbered SQL 디렉토리가 아니라 루트의 `migrate_<주제>.php` CLI 스크립트 (`require_once __DIR__ . '/public_html/config.php'` + `columnExists()` 가드 + 트랜잭션). 동일 패턴으로 작성:

```php
// migrate_cafe_nickname.php (boot 루트, CLI 전용)
<?php
if (php_sapi_name() !== 'cli') exit("CLI only\n");
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

// columnExists() 헬퍼는 기존 migrate_tasks_group_kind.php 와 동일 패턴 (INFORMATION_SCHEMA).

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

// 검증 출력
$total      = (int)$db->query("SELECT COUNT(*) FROM bootcamp_members WHERE cafe_member_key IS NOT NULL")->fetchColumn();
$withNick   = (int)$db->query("SELECT COUNT(*) FROM bootcamp_members WHERE cafe_member_key IS NOT NULL AND cafe_nickname IS NOT NULL")->fetchColumn();
echo "검증: cafe_member_key 매핑 {$total} / 그 중 cafe_nickname 채워진 {$withNick}\n";
echo "PASS\n";
```

- 멱등: 컬럼 존재 시 ALTER skip, `<>` 가드로 동일값 UPDATE skip.
- `<>` 조건 덕에 재실행해도 rowCount 가 0 으로 떨어진다 (운영자 재실행 안전).
- MariaDB 10.x window function 호환을 위해 correlated subquery. 약 63개 매핑 회원 대상이라 성능 부담 없음.

- 인덱스 추가 안 함 — 검색/정렬 대상 아님.
- 컬럼 위치: `cafe_member_key` 바로 뒤 (논리적 그룹).
- NULL 허용: 매핑 안 된 회원·post 없는 회원은 NULL 유지.

### 5.2 charset

기존 `bootcamp_members` 가 `utf8mb4` 사용 중. 이모지·특수문자 닉도 안전.

## 6. 동기화 (Sync)

### 6.1 cafe_ingest.php — 매시 cron / 통합 API 공통 경로

`ingestCafePosts()` 의 INSERT loop 안, **memberId 가 resolve 되고 nickname 이 들어왔을 때**, `bootcamp_members.cafe_nickname` 을 갱신한다.

배치 효율을 위해 같은 함수 안에서:
```php
// post 처리 직후, memberId + nickname 둘 다 있고 캐시된 닉과 다르면 1회 UPDATE
if ($memberId && $nickname !== null && $nickname !== '') {
    if (!isset($memberCafeNickCache[$memberId])
        || $memberCafeNickCache[$memberId] !== $nickname) {
        $updateNickStmt->execute([$nickname, $memberId]);
        $memberCafeNickCache[$memberId] = $nickname;
    }
}
```

- `$updateNickStmt` 는 함수 상단에서 prepare: `UPDATE bootcamp_members SET cafe_nickname = ? WHERE id = ? AND (cafe_nickname IS NULL OR cafe_nickname <> ?)`.
- 같은 회원이 한 batch 에 글 여러 개면 첫 1회만 UPDATE (캐시).
- WHERE 절의 `<>` 조건으로 동일값 UPDATE skip (rows_affected = 0 이지만 query cost 는 그대로) — 그래서 PHP 측 캐시로 query 자체를 줄임.
- 트랜잭션 분리 안 함 — cafe_posts insert 와 별 row 라 conflict 없음.

### 6.2 cafe_bulk_apply.php — 벌크 매핑 적용 시점

운영자가 카페 키 일괄 등록을 적용할 때, `cafe_member_key` 가 새로 채워지는 회원에 대해 `cafe_posts.nickname` 최신값을 즉시 백필.

```php
// 기존: UPDATE bootcamp_members SET cafe_member_key = ? WHERE id = ?
// 추가: 같은 트랜잭션 안에서 cafe_posts 최신 닉으로 cafe_nickname 도 백필
$nickStmt = $db->prepare("
    SELECT nickname FROM cafe_posts
    WHERE member_key = ? AND nickname IS NOT NULL
    ORDER BY posted_at DESC, id DESC LIMIT 1
");
$nickStmt->execute([$cafeMemberKey]);
$latestNick = $nickStmt->fetchColumn();
if ($latestNick) {
    $db->prepare("UPDATE bootcamp_members SET cafe_nickname = ? WHERE id = ?")
       ->execute([$latestNick, $memberId]);
}
```

post 가 아직 없는 매핑은 다음 cron poll 에서 자동 채워진다.

## 7. API 변경

`SELECT` 절에 `bm.cafe_nickname` 1줄 추가 (3 핸들러):

- `handleChecklist()` (check.php:28)
- `handleChecklistByMission()` (check.php:201)
- `handleStatusBoard()` (check.php:334)

JOIN/WHERE/ORDER BY 무변경. 응답 JSON 의 `members[]` 각 행에 `cafe_nickname` 필드 추가.

`memberTable.js` 또는 회원 관리 등 다른 핸들러는 v1 범위 아님 (필요 시 후속 작업).

## 8. 테스트 / 검증

### 8.1 PHP 단위 (services/check.php)

- `handleChecklist` 응답에 `cafe_nickname` 필드 존재 + 매핑된 회원은 값이 들어오고 미매핑은 NULL.
- `handleStatusBoard` 동일.
- `ingestCafePosts` 단위: 신규 글 1개 ingest → `bootcamp_members.cafe_nickname` 이 해당 닉으로 업데이트되는지.
- `ingestCafePosts` 동일 회원 글 여러 개 batch → UPDATE 가 1회만 실행되는지 (PHP cache).

### 8.2 통합

- DEV 마이그 적용 → 백필 행 수 확인 (`COUNT(*) WHERE cafe_nickname IS NOT NULL`).
- `/leader/#status`, `/leader/#checklist` 에서 카드에 `☕ 닉` 표시 확인.
- `/coach/#status`, `/head/#status` 동일 확인.
- 카페 닉 있는 회원/없는 회원 혼재 화면에서 자연 생략 확인.

### 8.3 회귀 0

- `m.group_name` NULL · `m.cafe_nickname` NULL → 기존 출력 동일.
- 카드 클릭 → 회원 팝업 정상 (id 만 dataset 으로 전달, 변경 없음).
- score-cell, 코인 컬럼, 체크박스 위치 unchanged.

## 9. 마이그 / 롤백 안전성

- 신규 컬럼 + 백필 UPDATE 만. 기존 데이터 손실 0.
- 롤백: `ALTER TABLE bootcamp_members DROP COLUMN cafe_nickname;` (UI/API 코드와 같이 revert 필요).
- DEV 먼저, 사용자 확인 후 PROD.

## 10. 작업 순서 (개요)

1. `migrate_cafe_nickname.php` 작성 + DEV `php migrate_cafe_nickname.php` 실행 (컬럼 + 백필).
2. `check.php` 3 SELECT 에 컬럼 추가.
3. `memberCellHtml` 에 sub-line segment 추가.
4. `cafe_ingest.php` 에 sync hook 추가.
5. `cafe_bulk_apply.php` 에 즉시 백필 hook 추가.
6. DEV 검증 → 사용자 OK → PROD 머지 + PROD 마이그 실행.

세부 task 분할은 후속 plan 문서에서.
