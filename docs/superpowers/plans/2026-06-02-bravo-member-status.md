# BRAVO 3차 슬라이스 (회원 마이페이지 상태) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 회원이 마이페이지의 새 'BRAVO 도전' 탭에서 본인의 등급별 응시 가능/불가 상태와 도전 기간/발표일을 읽기 전용으로 확인한다.

**Architecture:** 신규 테이블 없음. 서비스 `bravo.php`에 회원 식별→자격계산(슬라이스1 순수함수 재사용)→등급별 매칭 시험 조회를 합치는 `bravoMemberStatus`를 추가하고, `api/member.php`의 얇은 case `bravo_status`가 위임. 프론트는 회원 SPA에 'BRAVO 도전' 탭 + `member-bravo.js` 모듈.

**Tech Stack:** PHP 8 (PDO/MariaDB), vanilla JS SPA (MemberTabs register/mount, App.get/esc), CLI 테스트(DEV DB txn rollback).

**작업 규칙:** `/root/boot-dev`(dev 브랜치). DB DEV. **슬라이스별 운영배포 안 함 — dev push 까지만.**

**스펙:** `docs/superpowers/specs/2026-06-02-bravo-member-status-design.md`
**선행:** `api/services/bravo.php`(bravoLoadLevels/bravoEffectiveReviewCount/bravoEligibleLevels/bravoParseGrantedLevels), 테이블 bravo_levels/bravo_member_settings/bravo_exams.

---

## File Structure

- Modify: `public_html/api/services/bravo.php` — `bravoMemberStatus` 추가 (기존 함수 미수정)
- Create: `tests/bravo_member_status_test.php` — 통합 테스트(DEV DB txn rollback)
- Modify: `public_html/api/member.php` — `require_once services/bravo.php` + case `bravo_status`
- Create: `public_html/js/member-bravo.js` — MemberBravo 탭 모듈
- Modify: `public_html/js/member-tabs.js` — TABS 배열에 bravo 항목
- Modify: `public_html/index.php` — member-bravo.js include

---

## Task 1: 회원 상태 서비스 함수 (`bravoMemberStatus`)

**Files:**
- Modify: `public_html/api/services/bravo.php` (APPEND)
- Test: `tests/bravo_member_status_test.php`

- [ ] **Step 1: 통합 테스트 작성 (먼저 실패)**

Create `tests/bravo_member_status_test.php`:

```php
<?php
/**
 * BRAVO 회원 상태 서비스 통합 테스트. DEV DB transaction rollback.
 * 사용: php tests/bravo_member_status_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/bravo.php';

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
    $label = 'TEST_MBRV_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date) VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$label, $label]);
    $cohortId = (int)$db->lastInsertId();

    $uid = 'mbrv_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, member_status, is_active, stage_no, joined_at) VALUES (?, ?, ?, ?, ?, 'active', 1, 1, CURDATE())")
       ->execute([$cohortId, '김회원', '회원닉', '01099998888', $uid]);
    $memberId = (int)$db->lastInsertId();

    // completed 6 (user_id-row) → 자동 eligible [1,2]
    $db->prepare("INSERT INTO member_history_stats (user_id, stage1_participation_count, stage2_participation_count, completed_bootcamp_count, last_calculated_at) VALUES (?, 0, 0, 6, NOW())")
       ->execute([$uid]);

    // 시험 시드
    $insExam = $db->prepare("INSERT INTO bravo_exams (title, bravo_level, exam_mode, start_at, end_at, result_release_at, attempt_limit, target_type, target_cohort_id, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,99)");
    // L1 open (전체)
    $insExam->execute(['L1 오픈', 1, 'period', '2026-06-01 10:00:00', '2026-06-02 10:00:00', '2026-06-12 10:00:00', 3, 'all', null, 'open']);
    // L1 preparing (전체) — 회원 비공개라 선택되면 안 됨
    $insExam->execute(['L1 준비중', 1, 'period', '2026-07-01 10:00:00', '2026-07-02 10:00:00', '2026-07-12 10:00:00', 3, 'all', null, 'preparing']);
    // L2 closed (특정 기수 매칭)
    $insExam->execute(['L2 종료', 2, 'period', '2026-05-01 10:00:00', '2026-05-02 10:00:00', '2026-05-12 10:00:00', 3, 'cohort', $cohortId, 'closed']);
    // L3 시험 없음

    $st = bravoMemberStatus($db, $memberId);
    $by = [];
    foreach ($st['levels'] as $lv) $by[(int)$lv['level']] = $lv;

    t('levels 3개', count($st['levels']) === 3, 'count=' . count($st['levels']));
    t('member effective_review 6', (int)$st['member']['effective_review_count'] === 6);
    t('L1 eligible', $by[1]['eligible'] === true);
    t('L1 exam 존재', $by[1]['exam'] !== null);
    t('L1 exam status open (준비중 아님)', ($by[1]['exam']['status'] ?? '') === 'open', $by[1]['exam']['title'] ?? '(null)');
    t('L2 eligible', $by[2]['eligible'] === true);
    t('L2 exam status closed (기수 매칭)', ($by[2]['exam']['status'] ?? '') === 'closed');
    t('L3 ineligible (6<10)', $by[3]['eligible'] === false);
    t('L3 exam null', $by[3]['exam'] === null);

    // override 10 → L3 eligible (수동 override 반영)
    $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute([$uid]);
    $st2 = bravoMemberStatus($db, $memberId);
    $by2 = [];
    foreach ($st2['levels'] as $lv) $by2[(int)$lv['level']] = $lv;
    t('override 10 → L3 eligible', $by2[3]['eligible'] === true);
    t('override 10 → effective_review 10', (int)$st2['member']['effective_review_count'] === 10);

    $db->rollBack();
} catch (\Throwable $e) {
    $db->rollBack();
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_member_status_test.php`
Expected: FATAL (bravoMemberStatus 미정의).

- [ ] **Step 3: 서비스 함수 구현 (bravo.php 끝에 APPEND)**

Append to `public_html/api/services/bravo.php`:

```php

/**
 * 회원(bootcamp_members.id) 의 BRAVO 도전 상태.
 * 슬라이스1 자격 순수함수 재사용 + 등급별 매칭 시험(open 우선, preparing 비공개) 조회.
 * 반환: ['member'=>{real_name,nickname,cohort,effective_review_count}|null, 'levels'=>[{level,name,required_review_count,eligible,status,exam|null}]]
 */
function bravoMemberStatus(PDO $db, int $memberId): array {
    $mStmt = $db->prepare("
        SELECT bm.user_id, bm.phone, bm.cohort_id, bm.real_name, bm.nickname, c.cohort
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.id = ?
    ");
    $mStmt->execute([$memberId]);
    $m = $mStmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        return ['member' => null, 'levels' => []];
    }
    $userId   = $m['user_id'];
    $cohortId = (int)$m['cohort_id'];

    // completed_bootcamp_count (user_id-row 우선, phone-row 폴백) — 슬라이스1/2 동일 패턴
    $cStmt = $db->prepare("
        SELECT COALESCE(mhs_u.completed_bootcamp_count, mhs_p.completed_bootcamp_count, 0) AS completed
        FROM bootcamp_members bm
        LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
        LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
        WHERE bm.id = ?
    ");
    $cStmt->execute([$memberId]);
    $completed = (int)$cStmt->fetchColumn();

    // 회원 설정 (override / 수동부여)
    $override = null; $granted = [];
    if (!empty($userId)) {
        $sStmt = $db->prepare("SELECT review_count_override, granted_levels FROM bravo_member_settings WHERE user_id = ?");
        $sStmt->execute([$userId]);
        $set = $sStmt->fetch(PDO::FETCH_ASSOC);
        if ($set) {
            $override = $set['review_count_override'] !== null ? (int)$set['review_count_override'] : null;
            $granted  = bravoParseGrantedLevels($set['granted_levels']);
        }
    }

    $levels   = bravoLoadLevels($db);
    $eligible = bravoEligibleLevels($override, $completed, $granted, $levels);

    $exStmt = $db->prepare("
        SELECT title, exam_mode, start_at, end_at, result_release_at, status
        FROM bravo_exams
        WHERE bravo_level = ?
          AND (target_type = 'all' OR target_cohort_id = ?)
          AND status IN ('open','closed','released')
        ORDER BY (status = 'open') DESC, id DESC
        LIMIT 1
    ");

    $out = [];
    foreach ($levels as $lv) {
        $L = (int)$lv['level'];
        $isElig = in_array($L, $eligible, true);
        $exStmt->execute([$L, $cohortId]);
        $exam = $exStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $out[] = [
            'level'                 => $L,
            'name'                  => $lv['name'],
            'required_review_count' => (int)$lv['required_review_count'],
            'eligible'              => $isElig,
            'status'                => $isElig ? 'eligible' : 'ineligible',
            'exam'                  => $exam,
        ];
    }

    return [
        'member' => [
            'real_name'              => $m['real_name'],
            'nickname'               => $m['nickname'],
            'cohort'                 => $m['cohort'],
            'effective_review_count' => bravoEffectiveReviewCount($override, $completed),
        ],
        'levels' => $out,
    ];
}
```

- [ ] **Step 4: 통과 확인**

Run: `cd /root/boot-dev && php tests/bravo_member_status_test.php`
Expected: 모든 PASS, "11 pass, 0 fail".

- [ ] **Step 5: 기존 BRAVO 테스트 회귀**

Run: `cd /root/boot-dev && php tests/bravo_exam_service_test.php | tail -1 && php tests/bravo_qualification_test.php | tail -1`
Expected: "14 pass, 0 fail" / "25 pass, 0 fail".

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/services/bravo.php tests/bravo_member_status_test.php
git commit -m "feat(bravo): 회원 BRAVO 상태 서비스 bravoMemberStatus + 통합 테스트"
```

---

## Task 2: 회원 API case (`member.php`)

**Files:**
- Modify: `public_html/api/member.php`

- [ ] **Step 1: require_once 추가** — `public_html/api/member.php` 상단(다른 require_once 들 근처, `$action = getAction();` 위)에 추가. 이미 있으면 생략:

```php
require_once __DIR__ . '/services/bravo.php';
```

- [ ] **Step 2: case 추가** — `switch ($action)` 안, `default:` 앞에 추가:

```php
case 'bravo_status':
    $s = requireMember();
    $db = getDB();
    jsonSuccess(bravoMemberStatus($db, (int)$s['member_id']));
    break;
```

(`jsonSuccess` 가 array_merge 로 평탄화 → 프론트는 `r.member` / `r.levels` 직접 접근.)

- [ ] **Step 3: 문법 검사 + require 확인**

Run: `cd /root/boot-dev && php -l public_html/api/member.php && grep -n "services/bravo.php" public_html/api/member.php`
Expected: "No syntax errors detected" + require_once 라인 1개.

- [ ] **Step 4: 커밋**

```bash
cd /root/boot-dev
git add public_html/api/member.php
git commit -m "feat(bravo): 회원 API case bravo_status"
```

---

## Task 3: 회원 'BRAVO 도전' 탭 프론트

**Files:**
- Create: `public_html/js/member-bravo.js`
- Modify: `public_html/js/member-tabs.js`
- Modify: `public_html/index.php`

- [ ] **Step 1: member-bravo.js 모듈 작성**

Create `public_html/js/member-bravo.js`:

```javascript
/* ══════════════════════════════════════════════════════════════
   MemberBravo — BRAVO 도전 탭 (읽기 전용)
   본인 등급별 응시 가능/불가 + 도전 기간/발표일
   ══════════════════════════════════════════════════════════════ */
const MemberBravo = (() => {
    MemberTabs.register('bravo', { mount });

    function statusBadge(lv) {
        return lv.eligible
            ? '<span class="bravo-badge eligible">응시 가능</span>'
            : '<span class="bravo-badge ineligible">응시 불가</span>';
    }

    function detailText(lv) {
        if (!lv.eligible) {
            return `부트캠프 ${lv.required_review_count}회독 이상이면 도전할 수 있어요.`;
        }
        const ex = lv.exam;
        if (ex && ex.status === 'open') {
            if (ex.exam_mode === 'always') return '상시 도전 가능';
            const s = (ex.start_at || '').slice(0, 16);
            const e = (ex.end_at || '').slice(0, 16);
            const rel = ex.result_release_at ? ` · 결과 발표 ${ex.result_release_at.slice(0, 10)}` : '';
            return `도전 기간: ${s} ~ ${e}${rel}`;
        }
        return '도전 기간이 곧 안내됩니다.';
    }

    function render(el, member, levels) {
        const cards = levels.map(lv => `
            <div class="bravo-level-card ${lv.eligible ? 'is-eligible' : ''}">
                <div class="bravo-level-head">
                    <span class="bravo-level-name">${App.esc(lv.name || ('BRAVO ' + lv.level))}</span>
                    ${statusBadge(lv)}
                </div>
                <div class="bravo-level-detail">${App.esc(detailText(lv))}</div>
            </div>`).join('');

        const sub = member
            ? `${App.esc(member.cohort || '')} · 등록 회독 ${member.effective_review_count}회`
            : '';

        el.innerHTML = `
            <div class="member-bravo">
                <div class="member-bravo-head">
                    <h3 class="member-bravo-title">BRAVO 도전</h3>
                    <p class="member-bravo-sub">${sub}</p>
                </div>
                <div class="bravo-level-cards">${cards}</div>
                <p class="member-bravo-note">합격/불합격 결과는 응시 후 결과 발표일에 공개됩니다.</p>
            </div>`;
    }

    async function mount(el, member) {
        el.innerHTML = '<div class="member-bravo"><p class="member-bravo-loading">불러오는 중...</p></div>';
        const r = await App.get('/api/member.php?action=bravo_status');
        if (!r || r.success === false) {
            el.innerHTML = '<div class="member-bravo"><p class="member-bravo-error">상태를 불러오지 못했습니다.</p></div>';
            return;
        }
        render(el, r.member, r.levels || []);
    }

    return {};
})();
```

> App.get/App.esc 는 common.js 제공(슬라이스1~2에서 확인). 이 탭은 읽기 전용이라 Toast/POST 불필요.

- [ ] **Step 2: member-tabs.js TABS 항목 추가** — `public_html/js/member-tabs.js` 의 `TABS` 배열에 항목 추가 (members 다음):

기존:
```javascript
    const TABS = [
        { id: 'calendar',    label: '캘린더',     icon: '📅' },
        { id: 'assignments', label: '과제 이력',  icon: '✅' },
        { id: 'curriculum',  label: '진도 달력',  icon: '📖' },
        { id: 'members',     label: '부티즈 정보', icon: '👥' },
    ];
```
변경:
```javascript
    const TABS = [
        { id: 'calendar',    label: '캘린더',     icon: '📅' },
        { id: 'assignments', label: '과제 이력',  icon: '✅' },
        { id: 'curriculum',  label: '진도 달력',  icon: '📖' },
        { id: 'members',     label: '부티즈 정보', icon: '👥' },
        { id: 'bravo',       label: 'BRAVO 도전', icon: '🎖️' },
    ];
```

- [ ] **Step 3: index.php include 추가** — `public_html/index.php` 의 script 목록에서 `member-bootees.js` 줄 다음, `member.js` 줄 앞에 추가:

```php
    <script src="/js/member-bravo.js<?= v('/js/member-bravo.js') ?>"></script>
```

(로드 순서: member-tabs.js 가 먼저 로드되어 `MemberTabs.register` 가 정의되어 있어야 함 — member.js(MemberApp.init) 보다 앞이면 OK.)

- [ ] **Step 4: JS 문법 검사**

Run: `cd /root/boot-dev && node --check public_html/js/member-bravo.js && node --check public_html/js/member-tabs.js`
Expected: 둘 다 출력 없음.

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev
git add public_html/js/member-bravo.js public_html/js/member-tabs.js public_html/index.php
git commit -m "feat(bravo): 회원 'BRAVO 도전' 탭 프론트"
```

---

## Task 4: 검증 + dev push (운영 반영 안 함)

**Files:** (검증 전용)

- [ ] **Step 1: 전체 BRAVO 테스트**

Run:
```bash
cd /root/boot-dev
for f in bravo_schema_invariants bravo_qualification_test bravo_admin_service_test bravo_exams_schema_invariants bravo_exam_validate_test bravo_exam_service_test bravo_member_status_test; do
  printf "%-30s " "$f:"; php tests/$f.php | tail -1
done
```
Expected: 모든 파일 "0 fail".

- [ ] **Step 2: JS 문법 (회원 모듈 3개)**

Run: `cd /root/boot-dev && node --check public_html/js/member-bravo.js && node --check public_html/js/member-tabs.js && echo OK`
Expected: "OK".

- [ ] **Step 3: HTTP 스모크 (DEV)**

DEV 회원 계정으로 `https://dev-boot.soritune.com` 로그인 → 'BRAVO 도전' 탭:
- 등급별 카드 3개(BRAVO 1/2/3), 자격 충족 등급은 "응시 가능"·미충족은 "응시 불가" + 회독 조건
- open 시험이 있는 등급은 도전 기간/발표일 표시
- 기존 탭(캘린더 등)·홈 '브라보' 카드 정상(회귀 없음)

- [ ] **Step 4: dev push**

```bash
cd /root/boot-dev
git push origin dev
```

- [ ] **Step 5: 운영 반영 안 함 — 멈춤**

이 프로젝트는 슬라이스별 운영 배포를 하지 않는다. dev push 까지만. (BRAVO 전체 완성 시 일괄)

---

## Self-Review (작성자 체크 완료)

- **Spec coverage:** bravoMemberStatus(회원식별·completed 조인·override/granted·자격계산·등급별 매칭시험 open우선·preparing제외)(T1) ✓ / member.php bravo_status case + require(T2) ✓ / 새 탭 + member-bravo.js 읽기전용 카드 + 상태값 응시불가/응시가능 + 도전기간/발표일(T3) ✓ / 신규 테이블 없음 ✓ / dev-only(T4) ✓ / 홈 카드 미변경(어느 task도 member-home 손대지 않음) ✓.
- **Placeholder scan:** 모든 코드 스텝 실제 코드. App.get/esc 는 검증된 헬퍼.
- **Type consistency:** 응답 키 `member`(real_name/nickname/cohort/effective_review_count) + `levels`(level/name/required_review_count/eligible/status/exam) 가 서비스(T1)·API 평탄화(T2)·프론트 소비(T3) 전반 일치. exam 객체 키(title/exam_mode/start_at/end_at/result_release_at/status) 가 서비스 SELECT·프론트 detailText 일치. `bravoMemberStatus`/`bravoEligibleLevels`/`bravoEffectiveReviewCount`/`bravoParseGrantedLevels`/`bravoLoadLevels` 시그니처 일치.
