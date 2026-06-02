# 소리블록 BRAVO 도전 시스템 — 3차 슬라이스 (회원 마이페이지 상태) 설계

작성일: 2026-06-02
원본 기능정의서: `/root/260529_bootcamp_bravo_test.docx` (기능정의서 V4, 6장 회원 마이페이지)
선행 슬라이스: 1차(기반+관리자 자격), 2차(관리자 시험 관리) — 둘 다 완료·dev 반영.

## 배경 / 목적

BRAVO 도전 시스템의 세 번째 슬라이스. 회원이 로그인 후 마이페이지에서 **본인의 BRAVO 도전
가능 상태(응시 가능/불가)와 다음 도전 기간/결과 발표일**을 확인하는 읽기 전용 화면. 슬라이스1의
자격 계산과 슬라이스2의 시험 데이터를 회원 관점에서 합쳐 보여주는 첫 회원 화면이다.

## 확정된 선행 결정 (전체 프로젝트)

- **배포 전략: 전 슬라이스를 dev 에서 끝까지 개발 → BRAVO 시스템 완성 시점에 운영 1회 일괄 반영.**
- 로그인은 boot 재사용(회원 세션 = `bootcamp_members.id`). 신규 BRAVO 시스템은 기존
  `member_history_stats.bravo_grade` 와 무관한 추가형.

## 이 슬라이스의 범위 결정 (2026-06-02 브레인스토밍)

1. **배치: 회원 SPA에 새 'BRAVO 도전' 탭 추가** (홈 카드/요약 아님). doc 6장의 전용 상태 화면과 일치.
2. **기존 홈 '브라보' 카드(완주기반 자동 `bravo_grade`)는 그대로 둔다** — 추가형. 새 탭은 별도로
   '응시 가능 등급'을 표시. 두 개념 공존(전환/grandfather 정책은 여전히 보류, 손대지 않음).
3. **상태값은 이번 슬라이스에선 응시불가 / 응시가능만.** 응시기록 테이블이 없으므로 응시완료/합격/
   불합격/결과대기는 다음 슬라이스(응시·채점).

## 기존 boot 회원 아키텍처 (참고)

- 회원 SPA: `member/index.php`(없음 — 루트 `public_html/index.php`) 셸 → `MemberApp.init()`.
  `MemberTabs` 모듈: `TABS` 배열 + `register(tabId, {mount(el, member), unmount?})` + 해시 라우팅.
  탭 콘텐츠 div `#mtab-<id>`. 회원 모듈 IIFE (member-home.js 등), App.get/post/esc, Toast.
- 회원 식별: 세션 `$s['member_id']` → `bootcamp_members`(user_id·phone·cohort_id 보유). `requireMember()`.
- `api/member.php`: `?action=` switch, `getAction`/`getMethod`/`getJsonInput`/`jsonSuccess`/`jsonError`.
- 슬라이스1 산출물 재사용: `api/services/bravo.php`의 `bravoLoadLevels`, `bravoEffectiveReviewCount`,
  `bravoEligibleLevels`, `bravoParseGrantedLevels`. 테이블 `bravo_levels`/`bravo_member_settings`/`bravo_exams`.

## 데이터 흐름 — 회원 상태 엔드포인트

`api/member.php` 신규 case `bravo_status` (`requireMember()`), 서비스 `bravo.php`에
`bravoMemberStatus(PDO $db, int $memberId): array` 추가:

1. member_id → `bootcamp_members`의 `user_id`, `phone`, `cohort_id`, cohort 라벨(JOIN cohorts).
2. `completed_bootcamp_count`: 슬라이스1/2와 동일 조인 — `COALESCE(mhs_u..., mhs_p..., 0)`
   (user_id-row 우선, phone-row 폴백).
3. `bravo_member_settings`(user_id) → `review_count_override`, `granted_levels`.
4. `bravoLoadLevels` + `bravoEligibleLevels(override, completed, granted, levels)` 로 응시가능 등급 집합 계산
   (슬라이스1 순수함수 재사용).
5. 등급별(1/2/3) 매칭 시험 1건 조회:
   `SELECT ... FROM bravo_exams WHERE bravo_level = :L
      AND (target_type = 'all' OR target_cohort_id = :cohortId)
      AND status IN ('open','closed','released')        -- preparing(준비중)은 회원 비공개
    ORDER BY (status = 'open') DESC, id DESC LIMIT 1`
   (open 우선, 그다음 최신.)
6. 반환 구조:
   ```
   {
     member: { real_name, nickname, cohort, effective_review_count },
     levels: [
       { level, name, required_review_count, eligible: bool,
         status: 'eligible'|'ineligible',
         exam: { title, exam_mode, start_at, end_at, result_release_at, status } | null }
     ]   // level 1,2,3 항상 3개
   }
   ```

## 상태값 (이번 슬라이스 한정)

- `eligible=false` → "응시 불가" + 조건 안내("부트캠프 {required_review_count}회독 이상").
- `eligible=true` + exam(open) 있음 → "응시 가능" + 도전 기간(start~end)·결과 발표일.
- `eligible=true` + open exam 없음(또는 exam=null) → "응시 가능 (도전 기간 대기)".

(응시완료/합격/불합격/결과대기 상태는 응시·채점 슬라이스에서 attempts 테이블 도입 후.)

## 프론트 — 새 'BRAVO 도전' 탭

- `js/member-tabs.js` 의 `TABS` 배열에 `{ id: 'bravo', label: 'BRAVO 도전', icon: '🎖️' }` 추가.
- 신규 `public_html/js/member-bravo.js` — `MemberBravo` IIFE, `MemberTabs.register('bravo', { mount(el, member) })`.
  `mount` 에서 `App.get('/api/member.php?action=bravo_status')` 호출 → 등급별 카드 3개 렌더:
  등급명(BRAVO 1/2/3), 상태 배지(응시 가능/불가), 조건 또는 도전 기간/발표일. 읽기 전용(저장/POST 없음).
  사용자 표시 텍스트는 `App.esc`. 로드 실패 시 안내 문구.
- `public_html/index.php`(회원 셸) script 목록에 `<script src="/js/member-bravo.js<?= v('/js/member-bravo.js') ?>">`
  추가 — `member-tabs.js` 뒤, `member.js` 앞 (로드 순서: utils → tabs → 각 탭 → 메인).

## 마이그레이션 / 가드

신규 테이블 없음 (기존 bravo_levels/bravo_member_settings/bravo_exams + member_history_stats 읽기만).
마이그레이션 불필요.

## 테스트 / 검증

- `bravoMemberStatus` 통합 테스트(DEV DB txn rollback): 회원 + member_history_stats(completed) + 시험 시드 →
  등급별 eligible 계산 정확, 매칭 시험(open 우선·target all/cohort·preparing 제외) 정확, override·granted 반영.
- 회원 식별이 user_id 우선 / phone 폴백으로 completed 집계되는지(슬라이스1 패턴) 확인.
- 프론트 node --check + DEV HTTP 스모크(회원 로그인 → BRAVO 도전 탭 → 등급별 상태/기간 표시).
- 기존 회원 탭(캘린더 등)·홈 브라보 카드 회귀 없음.

## 이번 슬라이스에서 명시적으로 제외 (다음 슬라이스)

실제 응시(OT·마이크·문제·녹음·제출) · 채점/검수 · 인증서 · 합격/불합격/응시완료/결과대기 상태 ·
홈 '브라보' 카드 변경 · `bravo_grade` 표시등급 전환·grandfather 정책(미정) · 전체 BRAVO UI 스타일링 ·
개별 회원 타겟 시험 · 알림.
