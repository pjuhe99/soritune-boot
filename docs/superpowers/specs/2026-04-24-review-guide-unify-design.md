---
title: 후기 작성 가이드 통일 + UI 단순화
date: 2026-04-24
status: approved
base_spec: 2026-04-23-review-submission-design.md
---

# 후기 작성 가이드 통일 + UI 단순화

## 1. 배경

후기 작성 기능(`2026-04-23-review-submission-design.md`)은 카페/블로그 가이드를 `review_cafe_guide` / `review_blog_guide` 두 개의 `system_contents` 키로 따로 저장하고, 회원 `/후기작성` 화면에서도 카페 탭/블로그 탭 각각에 중복 렌더하고 있다.

실제 운영 문구를 써보니 작성 위치, 필수 해시태그, 분량/사진 기준, 회수 기준이 카페·블로그 공통이라 한 문서로 안내하는 것이 자연스럽다. 같은 내용을 2번 저장/수정하는 구조도 유지보수에 불리하다.

이번 스펙은 **가이드 문서를 1개로 통합하고 회원 화면 UI를 탭 제거 + 세로 나열로 단순화**하는 범위에 한정한다. 코인 적립/취소, eligibility, reward_group 정산, 제출 이력 스키마는 변경하지 않는다.

## 2. 범위

### 변경 대상
- `system_contents`: 새 키 `review_guide` 1개, 기존 `review_cafe_guide` / `review_blog_guide` 삭제.
- API 3개 응답/요청 필드 정리 (`my_review_status`, `admin_reviews_panel`, `review_settings_save`).
- 회원 화면 `member-review.js`: 탭 제거, 상단 공통 가이드 + 하단 카페/블로그 섹션 세로 나열.
- 마이그 스크립트 신규 (`migrate_review_guide_unify.php`).

### 변경 없음 (명시)
- `review_submissions` 스키마 (type enum 'cafe','blog' 포함).
- 코인 적립/취소 로직, `applyCoinChange`, reward_group_distribute.
- eligibility 판정, 코호트/활성 멤버 필터.
- 기수당 "타입별 active 제출 1건" 중복 방지 제약 — 카페 1 + 블로그 1 = 최대 +10코인.
- 운영 UI의 접수 on/off 토글 2개 (`review_cafe_enabled` / `review_blog_enabled`) — 독립 운영 유지.
- `coin_logs`의 `reason_type` 값 `'review_cafe'` / `'review_blog'` — 과거 이력 읽을 때 필요.

## 3. 데이터 — `system_contents`

### 3.1 마이그 스크립트 `migrate_review_guide_unify.php`

프로젝트 기존 마이그 스타일(`migrate_review_submissions.php` 등) 따름.

- `--dry-run` 지원, 트랜잭션, 실패 시 rollback.
- 시작 시 기존 `review_cafe_guide` / `review_blog_guide` 의 `content_markdown`을 stdout에 덤프 (수동 롤백용 백업).
- 단계:
  1. `INSERT INTO system_contents (content_key, content_markdown) VALUES ('review_guide', ?) ON DUPLICATE KEY UPDATE content_markdown = VALUES(content_markdown)` — 원자적 UPSERT, 재실행 안전.
  2. `DELETE FROM system_contents WHERE content_key IN ('review_cafe_guide', 'review_blog_guide')`
  3. 검증: `review_guide` 1건 존재, 구 키 0건.

### 3.2 가이드 본문 (최종본)

```markdown
🟠 작성 위치
- 카페: 소리튠 공식 카페 "소리블록 부트캠프 경험담"
- 블로그: 본인 네이버 블로그/티스토리 등 (전체공개 필수)

🟠 필수 해시태그
- 제목 또는 본문에 아래 해시태그를 반드시 포함해주세요.
  #소리튠영어 #영어스피킹 #소리블록 #소리튠부트캠프 #소리튠부트캠프방법

🟠 분량/내용 기준
- 글자 수: 공백 포함 600자 이상 (학습 동기, 구체적 훈련 방법 혹은 팁, 변화된 점 포함)
- 사진 첨부: 직접 촬영/캡처한 이미지 3장 이상 필수

🟠 적립 안내
- 작성한 글의 URL을 아래 해당 칸(카페/블로그)에 입력하면 확인 후 5코인이 적립됩니다.
- 12기 수강생에 한해, 12기 코인으로 사용 가능합니다.

⚠️ 코인 회수 및 반려 기준 (필독)
아래 기준에 미달하면 적립된 코인이 회수될 수 있습니다.

- 분량 미달: 글자 수 600자 미만 또는 사진 3장 미만인 경우
- 부정 행위: 타인의 글/사진 도용, 적립 후 3개월 이내 삭제/비공개 전환
- 확인 불가: 링크 오류 또는 친구공개/비공개 글로 설정된 경우
```

원문 대비 보정 내역(승인됨):
1. "패시태그를반드시" → "해시태그를 반드시" (오타 + 띄어쓰기).
2. "#소리튠 부트캠프 방법" → "#소리튠부트캠프방법" (네이버 해시태그는 공백 앞까지만 인식).
3. "작성한 글의 URL을 아래 입력하면" → "아래 해당 칸(카페/블로그)에 입력하면" (UI에 입력 칸이 2개라 명확화).
4. "12기 수강생에 한해, 12기에 코인 사용 가능" → "12기 수강생에 한해, 12기 코인으로 사용 가능합니다" (문법 자연스럽게).

## 4. API 변경

파일: `public_html/api/services/review.php`

### 4.1 `GET my_review_status`

응답:

```json
{
  "success": true,
  "eligible": true,
  "ineligible_reason": null,
  "guide": "...마크다운...",
  "cafe": {
    "enabled": true,
    "submitted": null
  },
  "blog": {
    "enabled": true,
    "submitted": {
      "id": 12,
      "url": "https://blog.naver.com/...",
      "submitted_at": "2026-04-22 14:30:00",
      "coin_amount": 5
    }
  }
}
```

- `guide`: `getSystemContent($db, 'review_guide', '')` 한 번 호출.
- `cafe.guide`, `blog.guide` 제거.

### 4.2 `GET review_settings` (운영 토글/가이드 조회)

응답:

```json
{
  "success": true,
  "cafe_enabled": true,
  "blog_enabled": true,
  "guide": "...마크다운..."
}
```

- `cafe_guide`, `blog_guide` 제거, `guide` 단일 필드 추가.
- `enabled`는 기존과 동일하게 boolean 반환(`on` 문자열을 `true`로 변환).

### 4.3 `POST review_settings_save`

`$allowed` 배열 변경:
```php
$allowed = ['review_cafe_enabled', 'review_blog_enabled', 'review_guide'];
```
- 기존 `review_cafe_guide` / `review_blog_guide` 제거.

### 4.4 `POST submit_review`

변경 없음. `type` 파라미터(`'cafe'` | `'blog'`), 중복 체크 키, 코인 적립 흐름 그대로.

## 5. 회원 UI — `/후기작성`

파일: `public_html/js/member-review.js`, `public_html/css/member-review.css` (또는 관련 스타일 위치).

### 5.1 렌더 구조

```
후기 작성하기
────────────
[← 뒤로]

▼ 작성 가이드 (details, open)
  [공통 가이드 마크다운]

────────────────────────
카페 후기              +5 코인
[URL 입력]
[ 제출하기 ]

────────────────────────
블로그 후기            +5 코인
[URL 입력]
[ 제출하기 ]
```

### 5.2 코드 변경

- `load()`:
  - eligible=true일 때 **먼저 공통 가이드 블록**(`<details class="review-guide-top" open>`)을 `body` 상단에 렌더.
  - 이어서 `renderSection('cafe', '카페 후기', r.cafe)` + `renderSection('blog', '블로그 후기', r.blog)` 를 붙임.
- `renderSection(type, title, sectionData)`:
  - 섹션 내부에서 가이드(`<details>`) 블록을 제거.
  - `sectionData.enabled === false`: "현재 접수 중이 아닙니다" 안내 블록 (현재 동작 유지).
  - `sectionData.submitted != null`: 제출 완료 뱃지 (날짜/링크) — 현재 동작 유지, 가이드만 내부에서 빠짐.
  - `sectionData.submitted == null && enabled`: URL 입력 폼 + 제출 버튼 — 현재 동작 유지.
  - 파라미터에서 `guide` 필드 참조 제거.
- **양쪽 enabled=false**인 엣지 케이스: 상단 가이드 + "카페 접수 중 아님" 섹션 + "블로그 접수 중 아님" 섹션 = 3개 블록 그대로 노출. 별도 병합 처리 안 함 (운영자가 두 토글을 모두 끄는 상황은 임시 상태이므로 단순 동작이 낫다).
- eligible=false: 기존처럼 사유 메시지만 노출, 가이드 숨김.
- `attachHandlers()`: 변경 없음.

### 5.3 CSS

- 신규 클래스 `.review-guide-top` — 섹션 밖 상단 가이드용. `max-width`, `margin-bottom` 등은 기존 `.review-section`과 어울리게.
- 기존 `.review-guide` (섹션 내부 가이드용) 제거 또는 재사용. 삭제 방향 권장 (죽은 스타일 남기지 않음).
- 섹션 간 구분선(`border-top` 또는 `margin-top: 12px`)이 필요하면 `.review-section + .review-section`에 적용.

## 6. 운영 UI — `/operation > 후기` 탭

현재 `admin-reviews.js`에는 가이드 편집 UI가 없다 (접수 토글 2개만). 이번 변경에서도 **admin UI는 건드리지 않음**:

- 토글 2개 유지 — 저장 키 변동 없음 (`review_cafe_enabled`, `review_blog_enabled`).
- 가이드 편집은 여전히 DB 직접 UPDATE.
- 운영자가 웹 UI로 가이드를 바꿔야 할 필요가 생기면 별도 과제로 `review_guide` textarea 에디터 추가.

`review_settings` 응답에서 `guide` 필드가 새로 추가되긴 하지만, 프론트가 사용하지 않아도 무해. API 일관성 차원에서 넣어둠(추후 에디터 추가 시 재사용).

## 7. 마이그 / 배포

### 7.1 DEV
1. `cd /root/boot-dev && git checkout dev` (이미 dev).
2. 코드 수정 (마이그 스크립트 + review.php + member-review.js + CSS).
3. `php migrate_review_guide_unify.php --dry-run` → 실제 실행.
4. 브라우저 smoke test:
   - 회원 `/후기작성` 진입 → 상단 가이드 1개, 하단 카페/블로그 섹션 2개 확인.
   - 아직 미제출 상태에서 카페 URL 제출 → 완료 뱃지, 블로그 섹션은 입력 폼 유지, 코인 +5 확인.
   - 블로그 제출 → 완료 뱃지, 코인 +5 확인.
   - 운영 `/operation > 후기` → 토글 ON/OFF 동작, 제출 목록 표시 확인.
   - `review_settings_save` 에 `review_guide`로 POST 해서 가이드 업데이트되는지 확인 (curl 또는 콘솔에서).
5. dev 브랜치 push.
6. **사용자 승인 대기** — 메모리 규칙에 따라 운영 반영은 명시적 요청 시에만.

### 7.2 PROD (사용자 승인 후)
1. `boot-dev`에서 `checkout main → merge dev → push → checkout dev`.
2. `boot-prod`에서 `git pull origin main`.
3. `cd /root/boot-prod && php migrate_review_guide_unify.php --dry-run` → 확인 → 실제 실행.
4. 운영 회원 1명으로 실제 제출 스모크 테스트.

### 7.3 롤백
- 가이드 문구만 문제라면: 마이그 실행 시 stdout에 덤프된 구 값으로 DB UPDATE.
- 구조 전체 롤백: git revert + 마이그 역 스크립트(`INSERT INTO system_contents ('review_cafe_guide', ...)` + `DELETE FROM system_contents WHERE content_key='review_guide'`).

## 8. 리스크 / 주의

1. **기존 회원 세션에 캐시된 guide**: SPA라 페이지 리로드 전까지 이전 API 응답이 쓰일 수 있음. 배포 후 사용자에게 새로고침 안내. (크리티컬하지 않음 — 제출/적립 로직은 그대로.)
2. **"12기 수강생에 한해, 12기 코인으로 사용 가능" 안내와 시스템 동작의 간극**: 현재 eligibility/reward_group 로직은 `member_status`만으로 소각 판단한다. 12기 미수강생이 후기를 제출하면 코인이 일단 적립된다. 운영자가 해당 회원의 `member_status`를 `out_of_group_management` 로 바꿔야 12기 정산 시 `reward_forfeited`로 소실된다 — 이 안내 문구는 정책 약속이지 자동 집행이 아니다. (사용자가 명시적으로 "그 부분은 수동 운영"이라고 확인.)
3. **해시태그 공백 제거(#소리튠부트캠프방법)**: 원문에 있던 공백을 제거했다. 운영 팀이 카페/블로그에서 실제로 태그 인식되는지 운영 반영 전 한 번 검증 권장.

## 9. 스코프 외 (후속 과제 후보)

- 운영 UI에 `review_guide` textarea 에디터 추가.
- 가이드 프리뷰 기능(마크다운 렌더 미리보기).
- 후기 기능에도 cohort-cycle 매핑 기반 eligibility 엄격화 (현재는 member_status 기반 소각에 의존).
