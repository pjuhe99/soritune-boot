# admin.js 부분 분리 — 카페 게시글 탭 추출 — 설계

날짜: 2026-05-07
범위: boot.soritune.com (boot-dev → boot-prod)
배경 audit: 2026-05-07 외부 코드 리뷰 — 12기 오픈 전 보완 항목 #5 (선별적 코드 분리)

## 배경

`public_html/js/admin.js` 는 2050 줄. 최근 6개월 commits 68건으로 boot 전체 churn 1위 파일이다. 12기 오픈 후 운영 중 잦은 수정이 예상되는데, 한 파일 안에 8개 management 탭 + dashboard core + login 이 섞여 있어 변경 충돌과 회귀 위험이 크다.

12기 오픈 전(2026-05-11)에 전부 분리는 위험하므로 **churn 1위 + 자기 완결적인 한 탭만 떼는** 보수적 접근 (사용자 결정, 경로 A 의 #5 부분 분리). 가장 churn 높은 cafe 게시글 탭 (최근 3개월 4건 commits) 을 별도 파일로.

## 정책 결정

사용자 의사 결정 요약:
- 분리 대상: **cafe 게시글 탭만** (1개 탭, ~136 줄)
- 다른 탭 (members/admins/tasks/guides/calendar/cohorts) 은 12기 오픈 후 별도 작업
- 분리 방식: 기존 `GroupAssignmentApp` 패턴 (별도 파일 + 글로벌 IIFE namespace)

## 변경 범위

신규
- `public_html/js/admin-cafe.js` — `AdminCafeApp` 글로벌 IIFE. state (page, filter) + `renderTab()` + `_setPage()`. 약 140 줄 (현재 admin.js 의 cafe 섹션 + 외부 인터페이스).

수정
- `public_html/js/admin.js`:
  - line 1912~1913: `let cafePostPage`, `let cafePostFilter` state 제거
  - line 1915~2037: `loadCafePosts`, `_cafePostPage` 함수 정의 제거
  - line 405: `loadCafePosts();` 호출을 `if (typeof AdminCafeApp !== 'undefined') AdminCafeApp.renderTab();` 로 교체
  - line 2048: `return { ..., _cafePostPage }` 의 `_cafePostPage` 제거
- `public_html/operation/index.php`:
  - admin.js script 태그 직전(또는 직후, 단 호출 시점 전이면 OK) 에 `<script src="/js/admin-cafe.js<?= v('/js/admin-cafe.js') ?>"></script>` 추가

영향 없음
- HTML container `#tab-cafe-posts` (admin.js dashboard render line 208) — 그대로 유지. AdminCafeApp 가 이 ID 로 DOM 찾음.
- API endpoints (`/api/bootcamp.php?action=cafe_posts`, `cafe_remap_unmapped`) — 변경 없음.
- 다른 admin.js 함수 / 다른 management 탭 / 회원 form 의 cafe 닉네임 매칭 UI (members management 안 line 1015, 1034 등) — 손대지 않음.
- 코치/조장 페이지 (`leader/`, `head/`, `coach/`) — cafe 탭은 `isOperation()` 가드 안에서만 렌더링 → 이 페이지에서는 cafe 탭 자체가 안 보임 → AdminCafeApp 호출도 발생 안 함 → 새 script 태그 추가 불필요.

## 인터페이스

`admin-cafe.js`:

```javascript
const AdminCafeApp = (() => {
    let page = 1;
    let filter = {};

    async function renderTab(_el) {
        // _el 은 미래 재사용 위한 인자 — 현재는 무시하고 ID 로 DOM 찾음
        // (group_assignment.js 도 동일 패턴)
        const sec = document.getElementById('tab-cafe-posts');
        if (!sec) return;
        page = 1;  // 진입 시 첫 페이지로
        await load();
    }

    async function load() {
        // 현재 admin.js 의 loadCafePosts 본문 그대로 (state 변수만 page/filter 로 rename)
        // ...
    }

    function _setPage(p) {
        page = p;
        load();
    }

    return { renderTab, _setPage };
})();
```

핵심 변경:
- `cafePostPage` → `page` (모듈 내부 변수)
- `cafePostFilter` → `filter`
- `loadCafePosts(p)` → `load()` (page 인자는 모듈 state 로 흡수). `renderTab` 이 첫 진입에서 호출.
- HTML 인라인 `onclick="AdminApp._cafePostPage(...)"` → `onclick="AdminCafeApp._setPage(...)"`

### admin.js 가 AdminCafeApp 을 모르는 페이지에서 호출되는 시나리오

`leader/`, `head/`, `coach/` 페이지는 admin-cafe.js 를 로드하지 않는다 (operation/index.php 에만 script 태그 추가). 그러나 이 페이지들에서는 cafe 탭이 `isOperation()` 가드로 안 보이므로 `AdminCafeApp.renderTab()` 호출 자체가 발생하지 않는다.

방어: admin.js line 405 호출에 `if (typeof AdminCafeApp !== 'undefined')` 가드 — operation 페이지에서만 통과, 나머지는 silent skip. coverage 외에서도 안전.

## 데이터 플로우

```
1. operation 페이지 로드 → admin.js + admin-cafe.js 둘 다 로드
2. AdminApp.init() → check_session → showDashboard()
3. showDashboard() 가 dashboard render → cafe 탭 button + #tab-cafe-posts container 생성 (line 208)
4. line 405: AdminCafeApp.renderTab() 호출 → AdminCafeApp 가 #tab-cafe-posts 채움
5. 사용자 필터 변경 → filter 모듈 state 갱신 → load() 재호출 → API → 재렌더
6. 사용자 페이지네이션 클릭 → onclick AdminCafeApp._setPage(N) → page 갱신 → load()
7. 수동 반영 버튼 → POST cafe_remap_unmapped → 성공 시 load(1) (필터 유지, 페이지 1)
```

## 에러 핸들링

- API 실패: `r.success === false` 시 `'<div class="empty-state">불러오기 실패</div>'` (현재와 동일)
- AdminCafeApp 미정의 (script 태그 누락 등 환경 문제): typeof 가드로 silent skip — cafe 탭 빈 채로 표시. 운영자가 알아챌 수 있음.
- 페이지 클릭 시 데이터 변경되어 page 가 invalid: 백엔드가 빈 배열 반환 → empty state 표시 (현재와 동일)

## 테스트

자동화 어려움 (frontend UI). 수동 시나리오로 회귀 검증.

DEV 시나리오:
1. **카페 탭 진입**: operation 페이지 → 카페 게시글 탭 클릭 → 데이터 로드, 통계 배지, 테이블 렌더
2. **필터**: 게시판/날짜/매핑/키워드 각각 필터 → 검색 → 결과 정확
3. **필터 초기화**: 초기화 버튼 → 빈 필터로 1페이지 로드
4. **페이지네이션**: 다음/이전 버튼 → 페이지 이동
5. **수동 반영**: 버튼 클릭 → confirm → POST 호출 → 성공 메시지 + 1페이지 재로드
6. **다른 탭 회귀**: members/admins/tasks/guides/calendar/cohorts 탭 정상 동작
7. **권한 회귀**: leader/head/coach 페이지 로그인 → 카페 탭 자체가 안 보임 (현재와 동일)

회귀:
- 백엔드 영향 없음 → `tests/qr_auth_invariants.php` (#2) + `tests/transaction_invariants.php` (#3) 그대로 통과 예상
- API endpoint 변경 없음 → 기타 cafe 관련 자동 cron (cafe_poll) 영향 없음

## 롤아웃

```
1. boot-dev: admin-cafe.js 신규 작성 + admin.js 수정 + operation/index.php script 태그 추가
2. boot-dev: 수동 시나리오 (위 7개) 검증
3. boot-dev: dev push
4. ⛔ 사용자 검증
5. main 머지 + boot-prod git pull (별도 작업, 사용자 명시 요청 시)
```

마이그/스키마 변경 없음. 순수 frontend 리팩토링.

## 인접 spec 과의 관계

- **#2 QR / #3 트랜잭션 / #4 .htaccess** (이미 dev 에 push 됨): 백엔드/HTTP 차원 변경. 이 spec 은 frontend JS only. 충돌 없음.
- **#6 잔여 admin.js / bootcamp.js / admin.php 분리** (12기 오픈 후): 이번 spec 의 GroupAssignmentApp/AdminCafeApp 패턴이 다른 탭 분리에도 그대로 적용 가능. 확장 기반.

## 안 함

- 다른 management 탭 분리 (members/admins/tasks/guides/calendar/cohorts) — 12기 오픈 후
- dashboard render core 분리 (143~650 줄) — 권한/상태/탭 라우팅 얽힘 → 큰 작업, 별도 spec
- cafe 백엔드 (api/bootcamp.php 의 cafe action 들) 분리 — 이번은 frontend 만
- AdminCafeApp 의 ES6 모듈화 (export/import) — boot 의 vanilla 글로벌 패턴 유지
- members management 안의 cafe 닉네임 매칭 UI 이전 — 이건 회원 form 의 일부, cafe 탭과 별개
