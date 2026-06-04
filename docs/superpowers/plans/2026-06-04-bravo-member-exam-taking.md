# BRAVO 회원 응시 흐름 (OT→응시→제출) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 회원이 자격 있는 BRAVO 시험에 대해 OT(안내+확인체크+마이크테스트)→응시(문제 순차 제시+자동 녹음+즉시 업로드+이어하기)→최종 제출까지 수행할 수 있게 한다.

**Architecture:** 순수 추가형. 신규 테이블 2개(`bravo_attempts` 응시 회차 / `bravo_answers` 문제별 녹음 답안). 횟수 집계 키 = `member_key`(user_id 우선, `p:<phone>` 폴백). 문제 목록은 시작 시점 JSON 스냅샷으로 동결. 녹음은 docroot 밖 `bravo_uploads/`에 문제별 즉시 업로드. 신규 서비스 `bravo_attempts.php`, member.php 얇은 case 4개, 프론트 신규 모듈 `member-bravo-exam.js`(MediaRecorder).

**Tech Stack:** PHP 8 + PDO(MariaDB), vanilla JS SPA(MediaRecorder/getUserMedia), 커스텀 `t()` CLI 테스트 러너(DEV DB 트랜잭션 롤백). 작업·검증은 **DEV(`/root/boot-dev`, dev 브랜치, DB SORITUNECOM_DEV_BOOT)** 에서만. ⚠️ DEV DB 파괴적 op(DROP/TRUNCATE/ALTER/migrate reset) 금지 — 테스트는 트랜잭션 롤백만. PROD 경로(`/root/boot-prod`) 접근 금지. git push 금지(컨트롤러가 최종에 수행).

**참조 스펙:** `docs/superpowers/specs/2026-06-04-bravo-member-exam-taking-design.md`

---

## File Structure

- **Create** `migrate_bravo_attempts.php` — 멱등 마이그(2테이블) + 업로드 디렉토리 셋업/안내.
- **Modify** `.gitignore` — `bravo_uploads/` 추가.
- **Modify** `public_html/api/services/bravo.php` — `bravoMemberContext()` 추출(리팩터), `bravoAttemptMemberKey()`, `bravoStatusAttempts()` 추가, `bravoMemberStatus()` 확장(exam에 id/attempt_limit + levels에 attempts), `bravoExamDelete()`에 attempts/answers/파일 cascade.
- **Create** `public_html/api/services/bravo_attempts.php` — 접근검증/start/questions/answer저장/submit/파일정리.
- **Modify** `public_html/api/member.php` — require_once + case 4개(intro/start/save/submit).
- **Create** `public_html/js/member-bravo-exam.js` — OT/마이크테스트/응시/제출 플로우 + MediaRecorder 래퍼.
- **Modify** `public_html/js/member-bravo.js` — 카드 액션 버튼(도전/이어하기/제출 마무리/상태 표기).
- **Modify** `public_html/index.php` — script include 1줄.
- **Create** `tests/bravo_attempts_schema_invariants.php`, `tests/bravo_attempt_test.php`.

기존 BRAVO 테스트 12파일 회귀 0 유지. PHP 업로드 한도는 2G(확인됨) — 추가 설정 불필요, 앱 레벨 10MB 상한만 적용.

---

## Task 1: 마이그레이션 (bravo_attempts / bravo_answers) + 업로드 디렉토리

**Files:**
- Create: `migrate_bravo_attempts.php`
- Modify: `.gitignore`
- Test: `tests/bravo_attempts_schema_invariants.php`

- [ ] **Step 1: 마이그레이션 파일 작성**

Create `migrate_bravo_attempts.php`:

```php
<?php
/**
 * Migration: BRAVO 6차 슬라이스 — bravo_attempts / bravo_answers (회원 응시)
 * 실행: php migrate_bravo_attempts.php   (DEV DB 먼저)
 * 멱등: CREATE TABLE IF NOT EXISTS. 추가형(기존 테이블 미수정).
 * 업로드 디렉토리(bravo_uploads/)도 생성 — SELinux/소유권 안내 출력.
 */
require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== Migration: bravo_attempts / bravo_answers ===\n\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_attempts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id       INT UNSIGNED NOT NULL COMMENT 'bravo_exams.id',
    member_key    VARCHAR(120) NOT NULL COMMENT '횟수 집계 키: user_id, 없으면 p:<전화> 폴백',
    member_id     INT UNSIGNED NOT NULL COMMENT '세션의 bootcamp_members.id (기수 맥락)',
    attempt_no    TINYINT UNSIGNED NOT NULL COMMENT '이 시험에서 이 사람의 n번째 응시 (1~attempt_limit)',
    question_ids  TEXT NOT NULL COMMENT '시작 시점 배정 문제 스냅샷 JSON [qid,...] (순서 보존)',
    status        ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
    ot_checked_at DATETIME NULL COMMENT '필수확인체크 시각 (require_check=1인 시험만)',
    started_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at  DATETIME NULL,
    UNIQUE KEY uk_ba_exam_user_no (exam_id, member_key, attempt_no),
    KEY idx_ba_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_attempts 생성 완료\n";

$db->exec("
CREATE TABLE IF NOT EXISTS bravo_answers (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id   INT UNSIGNED NOT NULL COMMENT 'bravo_attempts.id',
    question_id  INT UNSIGNED NOT NULL COMMENT 'bravo_questions.id',
    seq          SMALLINT UNSIGNED NOT NULL COMMENT '제시 순서 (스냅샷 인덱스 0-base)',
    audio_path   VARCHAR(255) NOT NULL COMMENT '저장 루트 기준 상대경로',
    audio_mime   VARCHAR(50) NOT NULL COMMENT 'audio/webm | audio/mp4 | audio/ogg',
    duration_ms  INT UNSIGNED NULL COMMENT '녹음 길이 (클라이언트 보고값, 채점 참고)',
    retake_used  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '재녹음 사용 여부',
    answered_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bans_attempt_question (attempt_id, question_id),
    KEY idx_bans_question (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "bravo_answers 생성 완료\n";

// ── 업로드 디렉토리 (docroot 밖 — 직접 URL 접근 불가) ──
$uploadRoot = __DIR__ . '/bravo_uploads/answers';
if (!is_dir($uploadRoot)) {
    if (mkdir($uploadRoot, 0750, true)) {
        echo "업로드 디렉토리 생성: {$uploadRoot}\n";
    } else {
        echo "⚠️ 업로드 디렉토리 생성 실패: {$uploadRoot} — 수동 생성 필요\n";
    }
} else {
    echo "업로드 디렉토리 이미 존재: {$uploadRoot}\n";
}

echo "\n⚠️ 아래 셋업을 root 로 수동 실행하세요 (멱등):\n";
echo "  chown -R apache:apache " . __DIR__ . "/bravo_uploads\n";
echo "  semanage fcontext -a -t httpd_sys_rw_content_t '" . __DIR__ . "/bravo_uploads(/.*)?'\n";
echo "  restorecon -Rv " . __DIR__ . "/bravo_uploads\n";
echo "  (SELinux 미설정 시 PHP-FPM 쓰기 실패 — 과거 vhost 로그 디렉토리 사고 참조)\n";

echo "\n=== Migration 완료 ===\n";
```

- [ ] **Step 2: DEV DB 적용 + 디렉토리 셋업**

Run:
```bash
cd /root/boot-dev && php migrate_bravo_attempts.php
chown -R apache:apache /root/boot-dev/bravo_uploads
semanage fcontext -a -t httpd_sys_rw_content_t '/var/www/html/_______site_SORITUNECOM_DEV_BOOT/bravo_uploads(/.*)?'
restorecon -Rv /var/www/html/_______site_SORITUNECOM_DEV_BOOT/bravo_uploads
```
Expected: `bravo_attempts 생성 완료`, `bravo_answers 생성 완료`, 디렉토리 생성. (`/root/boot-dev`는 `/var/www/html/_______site_SORITUNECOM_DEV_BOOT`의 심볼릭 링크 — semanage에는 실제 경로 사용.)

- [ ] **Step 3: .gitignore에 업로드 디렉토리 추가**

`.gitignore` 파일 끝에 추가 (파일 먼저 읽고 중복 없으면):

```
bravo_uploads/
```

- [ ] **Step 4: 스키마 불변식 테스트 작성**

Create `tests/bravo_attempts_schema_invariants.php`:

```php
<?php
/**
 * bravo_attempts / bravo_answers 스키마 불변식. DEV DB.
 * 사용: php tests/bravo_attempts_schema_invariants.php
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

foreach (['bravo_attempts', 'bravo_answers'] as $tbl) {
    $exists = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$tbl}'")->fetchColumn();
    t("{$tbl} 테이블 존재", (int)$exists === 1);
    if ((int)$exists !== 1) { echo "\n결과: {$pass} pass, {$fail} fail\n"; exit(1); }
}

// bravo_attempts
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_attempts") as $c) $cols[$c['Field']] = $c;
foreach (['id','exam_id','member_key','member_id','attempt_no','question_ids','status','ot_checked_at','started_at','submitted_at'] as $col) {
    t("bravo_attempts.{$col} 존재", isset($cols[$col]));
}
t('attempts.exam_id NOT NULL', $cols['exam_id']['Null'] === 'NO');
t('attempts.member_key NOT NULL', $cols['member_key']['Null'] === 'NO');
t('attempts.member_id NOT NULL', $cols['member_id']['Null'] === 'NO');
t('attempts.question_ids NOT NULL', $cols['question_ids']['Null'] === 'NO');
t('attempts.status ENUM 2값', stripos($cols['status']['Type'], "enum('in_progress','submitted')") === 0);
t('attempts.status 기본 in_progress', $cols['status']['Default'] === 'in_progress');
t('attempts.ot_checked_at NULL 허용', $cols['ot_checked_at']['Null'] === 'YES');
t('attempts.submitted_at NULL 허용', $cols['submitted_at']['Null'] === 'YES');

$idx = $db->query("SHOW INDEX FROM bravo_attempts WHERE Key_name='uk_ba_exam_user_no'")->fetchAll();
t('(exam_id,member_key,attempt_no) UNIQUE', count($idx) === 3 && (int)$idx[0]['Non_unique'] === 0);
$ix1 = $db->query("SHOW INDEX FROM bravo_attempts WHERE Key_name='idx_ba_exam_user'")->fetchAll();
t('idx_ba_exam_user 인덱스 존재', count($ix1) === 2);
$ix2 = $db->query("SHOW INDEX FROM bravo_attempts WHERE Key_name='idx_ba_member'")->fetchAll();
t('idx_ba_member 인덱스 존재', count($ix2) === 1);

// bravo_answers
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM bravo_answers") as $c) $cols[$c['Field']] = $c;
foreach (['id','attempt_id','question_id','seq','audio_path','audio_mime','duration_ms','retake_used','answered_at'] as $col) {
    t("bravo_answers.{$col} 존재", isset($cols[$col]));
}
t('answers.attempt_id NOT NULL', $cols['attempt_id']['Null'] === 'NO');
t('answers.question_id NOT NULL', $cols['question_id']['Null'] === 'NO');
t('answers.audio_path NOT NULL', $cols['audio_path']['Null'] === 'NO');
t('answers.duration_ms NULL 허용', $cols['duration_ms']['Null'] === 'YES');
t('answers.retake_used 기본 0', (string)$cols['retake_used']['Default'] === '0');

$idx = $db->query("SHOW INDEX FROM bravo_answers WHERE Key_name='uk_bans_attempt_question'")->fetchAll();
t('(attempt_id,question_id) UNIQUE', count($idx) === 2 && (int)$idx[0]['Non_unique'] === 0);
$ix3 = $db->query("SHOW INDEX FROM bravo_answers WHERE Key_name='idx_bans_question'")->fetchAll();
t('idx_bans_question 인덱스 존재', count($ix3) === 1);

// 업로드 디렉토리
t('bravo_uploads/answers 디렉토리 존재', is_dir(__DIR__ . '/../bravo_uploads/answers'));

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 5: 테스트 실행 → 통과**

Run: `cd /root/boot-dev && php tests/bravo_attempts_schema_invariants.php`
Expected: 모든 `PASS`, `결과: N pass, 0 fail`

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev && git add migrate_bravo_attempts.php tests/bravo_attempts_schema_invariants.php .gitignore
git commit -m "feat(bravo): 응시 마이그레이션 (bravo_attempts/bravo_answers) + 업로드 디렉토리"
```

---

## Task 2: bravo.php 헬퍼 — 컨텍스트 추출 리팩터 + 상태 attempts + 삭제 cascade

**Files:**
- Modify: `public_html/api/services/bravo.php`
- Test: 기존 `tests/bravo_member_status_test.php` 회귀 + 신규 단언 추가

기존 `bravoMemberStatus()`(bravo.php:293-367)의 앞부분(회원행+회독수+수동부여+자격 계산)을 `bravoMemberContext()`로 추출하고, status 에 attempts 필드를 추가한다. **출력 계약 유지: 기존 키는 전부 보존, 추가만.**

- [ ] **Step 1: 기존 테스트에 신규 단언 추가 (TDD)**

`tests/bravo_member_status_test.php`를 먼저 읽고 t()/트랜잭션 패턴 확인. 파일 끝부분(정상 경로 `$db->rollBack();` **바로 앞**)에 **자체 셋업을 포함한** 아래 블록을 삽입 (기존 셋업 변수에 의존하지 않음 — `cohorts`/`bootcamp_members` INSERT 컬럼은 기존 파일의 셋업 INSERT 를 그대로 본떠 NOT NULL 컬럼을 맞출 것):

```php
    // ── slice6: levels[].attempts (카드 시험 exam_id 기준 집계) + member_key 헬퍼 ──
    $tag6 = 'ST6_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, is_active) VALUES (?, 1)")->execute(["{$tag6}기"]);
    $cohort6 = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
       ->execute([$cohort6, "{$tag6}회원", "{$tag6}닉", '01099990001', "{$tag6}_uid"]);
    $member6 = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute(["{$tag6}_uid"]);
    $exam6 = bravoExamCreate($db, ['title'=>"{$tag6} 시험",'bravo_level'=>1,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);

    $st = bravoMemberStatus($db, $member6);
    $lv1 = null;
    foreach ($st['levels'] as $lv) { if ((int)$lv['level'] === 1) $lv1 = $lv; }
    t('attempts 필드 존재', $lv1 !== null && array_key_exists('attempts', $lv1));
    t('exam 에 id/attempt_limit 포함', $lv1['exam'] !== null && isset($lv1['exam']['id']) && isset($lv1['exam']['attempt_limit']));
    t('attempts.exam_id = 카드 시험', (int)$lv1['attempts']['exam_id'] === (int)$lv1['exam']['id']);
    t('attempts 초기 used 0', (int)$lv1['attempts']['used'] === 0 && $lv1['attempts']['in_progress'] === null && $lv1['attempts']['submitted'] === false);

    // attempt 행 직접 삽입 후 반영 확인 (응시 서비스는 Task3 — 여기선 SQL 로)
    $db->prepare("INSERT INTO bravo_attempts (exam_id, member_key, member_id, attempt_no, question_ids) VALUES (?,?,?,1,'[1,2]')")
       ->execute([$exam6, "{$tag6}_uid", $member6]);
    $st2 = bravoMemberStatus($db, $member6);
    foreach ($st2['levels'] as $lv) { if ((int)$lv['level'] === 1) $lv1 = $lv; }
    t('in_progress 반영', (int)$lv1['attempts']['used'] === 1 && $lv1['attempts']['in_progress'] !== null && (int)$lv1['attempts']['in_progress']['total'] === 2);

    $db->prepare("UPDATE bravo_attempts SET status='submitted', submitted_at=NOW() WHERE exam_id=? AND member_key=?")->execute([$exam6, "{$tag6}_uid"]);
    $st3 = bravoMemberStatus($db, $member6);
    foreach ($st3['levels'] as $lv) { if ((int)$lv['level'] === 1) $lv1 = $lv; }
    t('submitted 반영', $lv1['attempts']['submitted'] === true && $lv1['attempts']['in_progress'] === null);

    // member_key 헬퍼 (순수)
    t('member_key user_id 우선', bravoAttemptMemberKey(['user_id' => 'abc', 'phone' => '01012345678']) === 'abc');
    t('member_key phone 폴백', bravoAttemptMemberKey(['user_id' => '', 'phone' => '01012345678']) === 'p:01012345678');
    t('member_key user_id 공백 폴백', bravoAttemptMemberKey(['user_id' => '  ', 'phone' => '010']) === 'p:010');
```

(이 블록은 Task 1 의 `bravo_attempts` 테이블이 DEV DB 에 이미 존재함을 전제 — Task 순서대로 진행하면 충족.)

- [ ] **Step 2: 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_member_status_test.php`
Expected: FAIL — `Call to undefined function bravoAttemptMemberKey()` 또는 attempts 키 부재 FAIL.

- [ ] **Step 3: bravo.php 에 헬퍼 추가 + bravoMemberStatus 리팩터**

`public_html/api/services/bravo.php`의 `bravoMemberStatus`(line 288-367) 전체를 아래 3개 함수+리팩터본으로 교체 (기존 doc comment 위치에):

```php
/**
 * 횟수 집계 키: user_id 우선, 없으면 p:<전화> 폴백. 순수.
 */
function bravoAttemptMemberKey(array $memberRow): string {
    $uid = trim((string)($memberRow['user_id'] ?? ''));
    if ($uid !== '') return $uid;
    return 'p:' . trim((string)($memberRow['phone'] ?? ''));
}

/**
 * 회원 BRAVO 컨텍스트: 회원행 + 유효회독 + 수동부여 + 자격 레벨. 회원 없으면 null.
 * row: id, user_id, phone, cohort_id, real_name, nickname, cohort
 */
function bravoMemberContext(PDO $db, int $memberId): ?array {
    $mStmt = $db->prepare("
        SELECT bm.id, bm.user_id, bm.phone, bm.cohort_id, bm.real_name, bm.nickname, c.cohort
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.id = ?
    ");
    $mStmt->execute([$memberId]);
    $m = $mStmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) return null;

    $cStmt = $db->prepare("
        SELECT COALESCE(mhs_u.completed_bootcamp_count, mhs_p.completed_bootcamp_count, 0) AS completed
        FROM bootcamp_members bm
        LEFT JOIN member_history_stats mhs_p ON bm.phone = mhs_p.phone AND bm.phone IS NOT NULL AND bm.phone != ''
        LEFT JOIN member_history_stats mhs_u ON bm.user_id = mhs_u.user_id AND bm.user_id IS NOT NULL AND bm.user_id != ''
        WHERE bm.id = ?
    ");
    $cStmt->execute([$memberId]);
    $completed = (int)$cStmt->fetchColumn();

    $override = null; $granted = [];
    if (!empty($m['user_id'])) {
        $sStmt = $db->prepare("SELECT review_count_override, granted_levels FROM bravo_member_settings WHERE user_id = ?");
        $sStmt->execute([$m['user_id']]);
        $set = $sStmt->fetch(PDO::FETCH_ASSOC);
        if ($set) {
            $override = $set['review_count_override'] !== null ? (int)$set['review_count_override'] : null;
            $granted  = bravoParseGrantedLevels($set['granted_levels']);
        }
    }

    $levels   = bravoLoadLevels($db);
    $eligible = bravoEligibleLevels($override, $completed, $granted, $levels);

    return [
        'row'       => $m,
        'completed' => $completed,
        'override'  => $override,
        'granted'   => $granted,
        'levels'    => $levels,
        'eligible'  => $eligible,
    ];
}

/**
 * 한 시험에 대한 이 사람(member_key)의 응시 현황 (status 카드용).
 */
function bravoStatusAttempts(PDO $db, int $examId, string $memberKey, int $limit): array {
    $stmt = $db->prepare("SELECT id, status, question_ids FROM bravo_attempts WHERE exam_id = ? AND member_key = ? ORDER BY attempt_no");
    $stmt->execute([$examId, $memberKey]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $used = count($rows); $submitted = false; $inProgress = null;
    foreach ($rows as $a) {
        if ($a['status'] === 'submitted') {
            $submitted = true;
        } elseif ($a['status'] === 'in_progress') {
            $total = count(json_decode((string)$a['question_ids'], true) ?: []);
            $cnt = $db->prepare("SELECT COUNT(*) FROM bravo_answers WHERE attempt_id = ?");
            $cnt->execute([(int)$a['id']]);
            $inProgress = ['attempt_id' => (int)$a['id'], 'answered' => (int)$cnt->fetchColumn(), 'total' => $total];
        }
    }
    return ['exam_id' => $examId, 'used' => $used, 'limit' => $limit, 'in_progress' => $inProgress, 'submitted' => $submitted];
}

/**
 * 회원(bootcamp_members.id) 의 BRAVO 도전 상태.
 * 슬라이스1 자격 순수함수 재사용 + 등급별 매칭 시험(open 우선, preparing 비공개) 조회.
 * 반환: ['member'=>{real_name,nickname,cohort,effective_review_count}|null,
 *        'levels'=>[{level,name,required_review_count,eligible,status,exam|null,attempts|null}]]
 * exam 에 id/attempt_limit 포함(slice6), attempts 는 그 카드 시험(exam_id) 기준 집계.
 */
function bravoMemberStatus(PDO $db, int $memberId): array {
    $ctx = bravoMemberContext($db, $memberId);
    if (!$ctx) {
        return ['member' => null, 'levels' => []];
    }
    $m = $ctx['row'];
    $memberKey = bravoAttemptMemberKey($m);

    $exStmt = $db->prepare("
        SELECT id, title, exam_mode, start_at, end_at, result_release_at, status, attempt_limit
        FROM bravo_exams
        WHERE bravo_level = ?
          AND (target_type = 'all' OR target_cohort_id = ?)
          AND status IN ('open','closed','released')
        ORDER BY (status = 'open') DESC, id DESC
        LIMIT 1
    ");

    $out = [];
    foreach ($ctx['levels'] as $lv) {
        $L = (int)$lv['level'];
        $isElig = in_array($L, $ctx['eligible'], true);
        $exStmt->execute([$L, (int)$m['cohort_id']]);
        $exam = $exStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $attempts = $exam ? bravoStatusAttempts($db, (int)$exam['id'], $memberKey, (int)$exam['attempt_limit']) : null;
        $out[] = [
            'level'                 => $L,
            'name'                  => $lv['name'],
            'required_review_count' => (int)$lv['required_review_count'],
            'eligible'              => $isElig,
            'status'                => $isElig ? 'eligible' : 'ineligible',
            'exam'                  => $exam,
            'attempts'              => $attempts,
        ];
    }

    return [
        'member' => [
            'real_name'              => $m['real_name'],
            'nickname'               => $m['nickname'],
            'cohort'                 => $m['cohort'],
            'effective_review_count' => bravoEffectiveReviewCount($ctx['override'], $ctx['completed']),
        ],
        'levels' => $out,
    ];
}
```

- [ ] **Step 4: bravoExamDelete cascade 보강**

같은 파일의 `bravoExamDelete`(line 282-286)를 아래로 교체:

```php
/**
 * 시험 삭제 (하드). 연결된 문제 배정(bravo_exam_questions)·OT(bravo_exam_ot)·
 * 응시 기록(bravo_attempts/bravo_answers + 녹음 파일) 도 함께 삭제.
 */
function bravoExamDelete(PDO $db, int $id): void {
    $aStmt = $db->prepare("SELECT id FROM bravo_attempts WHERE exam_id = ?");
    $aStmt->execute([$id]);
    $attemptIds = array_map('intval', $aStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($attemptIds) {
        $place = implode(',', array_fill(0, count($attemptIds), '?'));
        $db->prepare("DELETE FROM bravo_answers WHERE attempt_id IN ($place)")->execute($attemptIds);
        $db->prepare("DELETE FROM bravo_attempts WHERE exam_id = ?")->execute([$id]);
        // 녹음 파일 정리 — bravo_attempts.php 가 로드된 경우에만 (bravo.php 단독 사용 시 DB 만 정리)
        if (function_exists('bravoAttemptPurgeFiles')) {
            foreach ($attemptIds as $aid) bravoAttemptPurgeFiles($aid);
        }
    }
    $db->prepare("DELETE FROM bravo_exam_questions WHERE exam_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM bravo_exam_ot WHERE exam_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM bravo_exams WHERE id = ?")->execute([$id]);
}
```

- [ ] **Step 5: 통과 + 회귀 확인**

Run:
```bash
cd /root/boot-dev && php -l public_html/api/services/bravo.php && php tests/bravo_member_status_test.php && php tests/bravo_qualification_test.php && php tests/bravo_admin_service_test.php && php tests/bravo_exam_service_test.php && php tests/bravo_ot_test.php && php tests/bravo_exam_question_test.php
```
Expected: 전부 `결과: N pass, 0 fail` (리팩터가 기존 status/자격/시험/OT/배정 테스트 회귀 없음).

- [ ] **Step 6: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/bravo.php tests/bravo_member_status_test.php
git commit -m "feat(bravo): 회원 컨텍스트 추출 + status attempts 확장 + 시험 삭제 응시 cascade"
```

---

## Task 3: 응시 서비스 (bravo_attempts.php — access/start/answer/submit)

**Files:**
- Create: `public_html/api/services/bravo_attempts.php`
- Test: `tests/bravo_attempt_test.php`

- [ ] **Step 1: 통합 테스트 작성 (TDD)**

Create `tests/bravo_attempt_test.php`:

```php
<?php
/**
 * BRAVO 응시 서비스 테스트. DEV DB 통합(트랜잭션 롤백) + tmp 업로드 루트.
 * 사용: php tests/bravo_attempt_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
define('BRAVO_UPLOAD_ROOT', sys_get_temp_dir() . '/bravo_test_uploads_' . getmypid());
require_once __DIR__ . '/../public_html/api/services/bravo.php';
require_once __DIR__ . '/../public_html/api/services/bravo_questions.php';
require_once __DIR__ . '/../public_html/api/services/bravo_exam_questions.php';
require_once __DIR__ . '/../public_html/api/services/bravo_attempts.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

/** tmp 음성 파일 생성 (서비스는 viaUpload=false 경로로 rename) */
function makeTmpAudio(string $content = 'dummy-audio'): string {
    $p = tempnam(sys_get_temp_dir(), 'bta_');
    file_put_contents($p, $content);
    return $p;
}

$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TAT_' . bin2hex(random_bytes(3));

    // ── 셋업: 기수 + 회원 2명 (자격자/무자격자) + 시험 + 문제 3개 + 배정 ──
    $db->prepare("INSERT INTO cohorts (cohort, is_active) VALUES (?, 1)")->execute(["{$tag}기"]);
    $cohortId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
       ->execute([$cohortId, "{$tag}응시자", "{$tag}닉", '01000000001', "{$tag}_uid"]);
    $memberId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bootcamp_members (cohort_id, real_name, nickname, phone, user_id, is_active) VALUES (?,?,?,?,?,1)")
       ->execute([$cohortId, "{$tag}무자격", "{$tag}닉2", '01000000002', "{$tag}_uid2"]);
    $memberId2 = (int)$db->lastInsertId();
    // 자격: override 10회독 → 전 레벨 eligible
    $db->prepare("INSERT INTO bravo_member_settings (user_id, review_count_override) VALUES (?, 10)")->execute(["{$tag}_uid"]);

    $examId = bravoExamCreate($db, [
        'title'=>"{$tag} 시험",'bravo_level'=>1,'exam_mode'=>'always',
        'attempt_limit'=>3,'target_type'=>'all','status'=>'open',
    ], 99);
    $q1 = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>1,'korean_text'=>"{$tag} q1",'english_text'=>'q1','difficulty'=>'easy','is_active'=>1], 99);
    $q2 = bravoQuestionCreate($db, ['question_type'=>1,'bravo_level'=>1,'korean_text'=>"{$tag} q2",'english_text'=>'q2','difficulty'=>'easy','is_active'=>1], 99);
    $q3 = bravoQuestionCreate($db, ['question_type'=>2,'bravo_level'=>1,'korean_text'=>"{$tag} q3",'english_text'=>'q3','difficulty'=>'normal','is_active'=>1], 99);
    bravoExamQuestionSet($db, $examId, [$q1, $q2, $q3]);
    t('셋업 정상', $cohortId>0 && $memberId>0 && $examId>0 && $q1>0);

    // ── 접근 검증 ──
    $acc = bravoAttemptExamAccess($db, $memberId, 99999999);
    t('미존재 시험 거부', isset($acc['error']) && ($acc['code'] ?? 0) === 404);

    $prepId = bravoExamCreate($db, ['title'=>"{$tag} 준비중",'bravo_level'=>1,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'preparing'], 99);
    $acc = bravoAttemptExamAccess($db, $memberId, $prepId);
    t('preparing 시험 거부', isset($acc['error']));

    $otherCohortExam = bravoExamCreate($db, ['title'=>"{$tag} 타기수",'bravo_level'=>1,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'cohort','target_cohort_id'=>$cohortId+999999,'status'=>'open'], 99);
    $acc = bravoAttemptExamAccess($db, $memberId, $otherCohortExam);
    t('cohort 대상 불일치 거부', isset($acc['error']) && ($acc['code'] ?? 0) === 403);

    $acc = bravoAttemptExamAccess($db, $memberId2, $examId);
    t('자격 미달 거부', isset($acc['error']) && ($acc['code'] ?? 0) === 403);

    $pastExam = bravoExamCreate($db, ['title'=>"{$tag} 만료",'bravo_level'=>1,'exam_mode'=>'period','start_at'=>'2020-01-01 00:00','end_at'=>'2020-01-02 00:00','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    $acc = bravoAttemptExamAccess($db, $memberId, $pastExam);
    t('기간 만료 거부', isset($acc['error']));

    $acc = bravoAttemptExamAccess($db, $memberId, $examId);
    t('정상 접근 통과', isset($acc['exam']) && $acc['member_key'] === "{$tag}_uid");
    $exam = $acc['exam']; $mk = $acc['member_key']; $mrow = $acc['ctx']['row'];

    // ── start ──
    $r = bravoAttemptStart($db, $exam, $mrow, $mk, false);
    t('start 정상 (require_check 없음)', isset($r['attempt']) && (int)$r['attempt']['attempt_no'] === 1 && empty($r['resumed']));
    $attempt = $r['attempt'];
    $snap = json_decode($attempt['question_ids'], true);
    t('스냅샷 = 배정 순서', $snap === [$q1, $q2, $q3], json_encode($snap));

    $r2 = bravoAttemptStart($db, $exam, $mrow, $mk, false);
    t('start 재호출 → 동일 attempt (이어하기, 차감 없음)', isset($r2['attempt']) && (int)$r2['attempt']['id'] === (int)$attempt['id'] && !empty($r2['resumed']));

    // 동시 시작 race: 테스트 훅으로 INSERT 직전 경쟁 행 삽입 → dup catch → 기존 행 반환
    $db->prepare("DELETE FROM bravo_attempts WHERE id = ?")->execute([(int)$attempt['id']]);
    $hook = function () use ($db, $examId, $mk, $memberId) {
        $db->prepare("INSERT INTO bravo_attempts (exam_id, member_key, member_id, attempt_no, question_ids) VALUES (?,?,?,1,'[]')")
           ->execute([$examId, $mk, $memberId]);
    };
    $r3 = bravoAttemptStart($db, $exam, $mrow, $mk, false, $hook);
    t('동시 시작 dup catch → 기존 반환', isset($r3['attempt']) && !empty($r3['resumed']));
    $db->prepare("DELETE FROM bravo_attempts WHERE exam_id = ? AND member_key = ?")->execute([$examId, $mk]);

    // require_check=1 시험: 미체크 거부 / 체크 시 ot_checked_at 기록
    bravoOtUpsert($db, $examId, ['exam_id'=>$examId, 'intro_text'=>'안내', 'require_check'=>1], 99);
    $r = bravoAttemptStart($db, $exam, $mrow, $mk, false);
    t('require_check 미체크 거부', isset($r['error']));
    $r = bravoAttemptStart($db, $exam, $mrow, $mk, true);
    t('체크 시 시작 + ot_checked_at', isset($r['attempt']) && $r['attempt']['ot_checked_at'] !== null);
    $attempt = $r['attempt'];

    // 배정 0건 시험 start 거부
    $emptyExam = bravoExamCreate($db, ['title'=>"{$tag} 빈배정",'bravo_level'=>1,'exam_mode'=>'always','attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    $eAcc = bravoAttemptExamAccess($db, $memberId, $emptyExam);
    $r = bravoAttemptStart($db, $eAcc['exam'], $mrow, $mk, false);
    t('배정 0건 거부', isset($r['error']));

    // ── questions 페이로드 ──
    $qs = bravoAttemptQuestions($db, $attempt);
    t('questions 3건 + seq + 정답 미포함', count($qs) === 3 && (int)$qs[0]['seq'] === 0 && (int)$qs[0]['id'] === $q1
        && !isset($qs[0]['english_text']) && !isset($qs[0]['accepted_answers']));

    // ── answer 저장 ──
    $f = makeTmpAudio('rec-1');
    $r = bravoAnswerStore($db, $attempt, $q1, $f, 'audio/webm', 'webm', 4200, false);
    t('answer 저장', !isset($r['error']) && (int)$r['answered_count'] === 1 && $r['all_answered'] === false);
    $saved = $db->prepare("SELECT * FROM bravo_answers WHERE attempt_id=? AND question_id=?");
    $saved->execute([(int)$attempt['id'], $q1]);
    $row = $saved->fetch(PDO::FETCH_ASSOC);
    t('answer 행 내용', $row && (int)$row['seq'] === 0 && (int)$row['retake_used'] === 0 && (int)$row['duration_ms'] === 4200);
    t('파일 저장됨', is_file(BRAVO_UPLOAD_ROOT . '/' . $row['audio_path']));

    // 재녹음 1회 (교체 + retake_used=1)
    $f = makeTmpAudio('rec-1-retake');
    $r = bravoAnswerStore($db, $attempt, $q1, $f, 'audio/mp4', 'm4a', 3100, false);
    t('재녹음 교체', !isset($r['error']) && (int)$r['answered_count'] === 1);
    $saved->execute([(int)$attempt['id'], $q1]);
    $row = $saved->fetch(PDO::FETCH_ASSOC);
    t('재녹음 retake_used=1 + mime 갱신', (int)$row['retake_used'] === 1 && $row['audio_mime'] === 'audio/mp4');
    t('확장자 교체 시 옛 파일 정리', !is_file(BRAVO_UPLOAD_ROOT . '/answers/' . (int)$attempt['id'] . '/' . $q1 . '.webm')
        && is_file(BRAVO_UPLOAD_ROOT . '/answers/' . (int)$attempt['id'] . '/' . $q1 . '.m4a'));

    // 재녹음 2회째 거부
    $f = makeTmpAudio('rec-1-again');
    $r = bravoAnswerStore($db, $attempt, $q1, $f, 'audio/webm', 'webm', 1000, false);
    t('재녹음 2회 거부', isset($r['error']));
    @unlink($f);

    // 스냅샷 밖 문제 거부
    $f = makeTmpAudio('ghost');
    $r = bravoAnswerStore($db, $attempt, 99999999, $f, 'audio/webm', 'webm', 1000, false);
    t('스냅샷 밖 거부', isset($r['error']));
    @unlink($f);

    // ── submit: 미완료 거부 → 완료 후 성공 ──
    $r = bravoAttemptSubmit($db, $attempt);
    t('미완료 submit 거부 + missing', isset($r['error']) && count($r['missing']) === 2);

    foreach ([$q2, $q3] as $q) {
        $f = makeTmpAudio("rec-{$q}");
        $r = bravoAnswerStore($db, $attempt, $q, $f, 'audio/webm', 'webm', 2000, false);
    }
    t('마지막 저장 all_answered', $r['all_answered'] === true && (int)$r['answered_count'] === 3);

    $r = bravoAttemptSubmit($db, $attempt);
    t('submit 성공', !isset($r['error']) && !empty($r['submitted']));
    $cur = bravoAttemptGet($db, (int)$attempt['id']);
    t('status=submitted + submitted_at', $cur['status'] === 'submitted' && $cur['submitted_at'] !== null);

    $r = bravoAttemptSubmit($db, $cur);
    t('중복 submit 거부', isset($r['error']));
    $f = makeTmpAudio('late');
    $r = bravoAnswerStore($db, $cur, $q2, $f, 'audio/webm', 'webm', 1000, false);
    t('submitted 후 save 거부', isset($r['error']));
    @unlink($f);

    // 제출 후 재시작 잠금
    $r = bravoAttemptStart($db, $exam, $mrow, $mk, true);
    t('제출 후 재응시 잠금', isset($r['error']));

    // ── submit 은 기간 무관 (마감 직전 완주 구제) ──
    $pExamId = bravoExamCreate($db, ['title'=>"{$tag} 기간시험",'bravo_level'=>1,'exam_mode'=>'period',
        'start_at'=>date('Y-m-d H:i', strtotime('-1 hour')), 'end_at'=>date('Y-m-d H:i', strtotime('+1 hour')),
        'attempt_limit'=>3,'target_type'=>'all','status'=>'open'], 99);
    bravoExamQuestionSet($db, $pExamId, [$q1]);
    $pAcc = bravoAttemptExamAccess($db, $memberId, $pExamId);
    $r = bravoAttemptStart($db, $pAcc['exam'], $mrow, $mk, false);
    $pAttempt = $r['attempt'];
    $f = makeTmpAudio('p-rec');
    bravoAnswerStore($db, $pAttempt, $q1, $f, 'audio/webm', 'webm', 1000, false);
    // 기간 종료로 강제 변경 후 submit
    $db->prepare("UPDATE bravo_exams SET end_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', strtotime('-10 minutes')), $pExamId]);
    $pExamRow = $db->query("SELECT * FROM bravo_exams WHERE id = " . (int)$pExamId)->fetch(PDO::FETCH_ASSOC);
    t('기간 만료 후 save 게이트 false', bravoAttemptSavePeriodOk($pExamRow) === false);
    $r = bravoAttemptSubmit($db, bravoAttemptGet($db, (int)$pAttempt['id']));
    t('기간 만료 후 완비 submit 성공', !isset($r['error']));

    // ── 소유 검증 ──
    $own = bravoAttemptForMember($db, (int)$pAttempt['id'], $memberId);
    t('소유자 조회 성공', $own !== null);
    $own = bravoAttemptForMember($db, (int)$pAttempt['id'], $memberId2);
    t('타인 attempt 거부', $own === null);

    // ── cascade: 시험 삭제 → attempts/answers/파일 정리 ──
    $delDir = BRAVO_UPLOAD_ROOT . '/answers/' . (int)$pAttempt['id'];
    t('cascade 전 파일 존재', is_dir($delDir));
    bravoExamDelete($db, $pExamId);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM bravo_attempts WHERE exam_id = " . (int)$pExamId)->fetchColumn();
    t('시험 삭제 → attempts 0건', $cnt === 0);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM bravo_answers WHERE attempt_id = " . (int)$pAttempt['id'])->fetchColumn();
    t('시험 삭제 → answers 0건', $cnt === 0);
    t('시험 삭제 → 녹음 디렉토리 정리', !is_dir($delDir));

    // ── 업로드 검증 헬퍼 (finfo 는 실파일 기반이라 dummy 텍스트는 거부되는 것이 정상) ──
    $f = makeTmpAudio('plain-text');
    $v = bravoAnswerValidateUpload(['tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => filesize($f)]);
    t('비오디오 MIME 거부', isset($v['error']));
    @unlink($f);
    $v = bravoAnswerValidateUpload(['tmp_name' => '/nonexistent', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]);
    t('업로드 에러 거부', isset($v['error']));
    t('MIME 매핑', bravoAnswerAudioExt('audio/webm') === 'webm' && bravoAnswerAudioExt('video/webm') === 'webm'
        && bravoAnswerAudioExt('audio/mp4') === 'm4a' && bravoAnswerAudioExt('video/mp4') === 'm4a'
        && bravoAnswerAudioExt('audio/ogg') === 'ogg' && bravoAnswerAudioExt('text/plain') === null);

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

// tmp 업로드 루트 정리
if (is_dir(BRAVO_UPLOAD_ROOT)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BRAVO_UPLOAD_ROOT, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir() ? @rmdir($p->getPathname()) : @unlink($p->getPathname()); }
    @rmdir(BRAVO_UPLOAD_ROOT);
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
```

⚠️ 셋업 INSERT 컬럼(`cohorts`, `bootcamp_members`)은 실제 스키마와 다를 수 있음 — 구현 전 `SHOW COLUMNS`로 NOT NULL 컬럼 확인 후 필요한 컬럼 추가(예: `bootcamp_members.member_status` 등 기본값 없는 컬럼). `bravoExamCreate` 의 period 필드 키(start_at/end_at)와 검증 규칙도 `bravo.php` 의 `bravoValidateExam`/`bravoExamPersistData` 에서 실제 확인(과거 날짜 거부 등 규칙이 있으면 직접 UPDATE 로 우회). 테스트 의도는 유지.

- [ ] **Step 2: 실패 확인**

Run: `cd /root/boot-dev && php tests/bravo_attempt_test.php`
Expected: FAIL — `Failed opening required ... bravo_attempts.php` (파일 미존재).

- [ ] **Step 3: 서비스 구현**

Create `public_html/api/services/bravo_attempts.php`:

```php
<?php
/**
 * BRAVO 응시(attempt) 서비스 (6차 슬라이스).
 * OT→응시(녹음 답안)→제출. 기존 BRAVO 경로와 무관한 추가형.
 * 업로드 루트는 docroot 밖 — 직접 URL 접근 불가 (서빙은 채점 슬라이스에서 PHP 스트리밍).
 */

require_once __DIR__ . '/bravo.php';
require_once __DIR__ . '/bravo_exam_questions.php';

if (!defined('BRAVO_UPLOAD_ROOT')) {
    define('BRAVO_UPLOAD_ROOT', dirname(__DIR__, 3) . '/bravo_uploads');
}

const BRAVO_AUDIO_MAX_BYTES = 10 * 1024 * 1024; // 10MB (1분 opus/aac ≈ 0.5~1MB)

/**
 * 실측 MIME → 저장 확장자. 미지원이면 null.
 * (브라우저 webm 녹음은 finfo 가 video/webm 으로 읽는 경우가 흔함 — 둘 다 수용)
 */
function bravoAnswerAudioExt(string $mime): ?string {
    $map = [
        'audio/webm' => 'webm', 'video/webm' => 'webm',
        'audio/mp4'  => 'm4a',  'video/mp4'  => 'm4a',
        'audio/ogg'  => 'ogg',  'application/ogg' => 'ogg',
    ];
    return $map[$mime] ?? null;
}

/**
 * 업로드 파일 검증. 성공: ['mime'=>, 'ext'=>], 실패: ['error'=>msg].
 */
function bravoAnswerValidateUpload(array $file): array {
    if (!isset($file['tmp_name']) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => '녹음 업로드에 실패했습니다. 다시 시도해주세요.'];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > BRAVO_AUDIO_MAX_BYTES) {
        return ['error' => '녹음 파일 크기가 올바르지 않습니다.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $ext = bravoAnswerAudioExt($mime);
    if ($ext === null) {
        return ['error' => '지원하지 않는 녹음 형식입니다.'];
    }
    return ['mime' => $mime, 'ext' => $ext];
}

/**
 * 회원의 시험 접근 공통 검증 (intro/start/save 용 — submit 은 미사용).
 * 성공: ['exam'=>row, 'ctx'=>bravoMemberContext, 'member_key'=>string]
 * 실패: ['error'=>msg, 'code'=>http]
 */
function bravoAttemptExamAccess(PDO $db, int $memberId, int $examId): array {
    $ctx = bravoMemberContext($db, $memberId);
    if (!$ctx) return ['error' => '회원 정보를 찾을 수 없습니다.', 'code' => 404];

    $stmt = $db->prepare("SELECT * FROM bravo_exams WHERE id = ?");
    $stmt->execute([$examId]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam) return ['error' => '시험을 찾을 수 없습니다.', 'code' => 404];
    if ($exam['status'] !== 'open') return ['error' => '현재 응시할 수 없는 시험입니다.', 'code' => 400];
    if (!bravoAttemptSavePeriodOk($exam)) return ['error' => '응시 기간이 아닙니다.', 'code' => 400];
    if ($exam['target_type'] === 'cohort' && (int)$exam['target_cohort_id'] !== (int)$ctx['row']['cohort_id']) {
        return ['error' => '응시 대상이 아닙니다.', 'code' => 403];
    }
    if (!in_array((int)$exam['bravo_level'], $ctx['eligible'], true)) {
        return ['error' => '응시 자격이 없습니다.', 'code' => 403];
    }
    return ['exam' => $exam, 'ctx' => $ctx, 'member_key' => bravoAttemptMemberKey($ctx['row'])];
}

/**
 * 기간 게이트 (save/접근용 — submit 은 기간 무관). 순수.
 * period 모드에서 start_at 전이거나 end_at 후면 false.
 */
function bravoAttemptSavePeriodOk(array $exam): bool {
    if (($exam['exam_mode'] ?? '') !== 'period') return true;
    $now = date('Y-m-d H:i:s');
    if (!empty($exam['start_at']) && $now < $exam['start_at']) return false;
    if (!empty($exam['end_at']) && $now > $exam['end_at']) return false;
    return true;
}

function bravoAttemptGet(PDO $db, int $id): ?array {
    $stmt = $db->prepare("SELECT * FROM bravo_attempts WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * attempt 소유 검증: 세션 회원(member_id)의 member_key 와 attempt.member_key 일치 시 행 반환.
 * (기수 row 가 달라도 같은 사람이면 접근 가능)
 */
function bravoAttemptForMember(PDO $db, int $attemptId, int $memberId): ?array {
    $attempt = bravoAttemptGet($db, $attemptId);
    if (!$attempt) return null;
    $ctx = bravoMemberContext($db, $memberId);
    if (!$ctx) return null;
    return $attempt['member_key'] === bravoAttemptMemberKey($ctx['row']) ? $attempt : null;
}

/**
 * 진행 중 attempt 조회 (이어하기).
 */
function bravoAttemptFindInProgress(PDO $db, int $examId, string $memberKey): ?array {
    $stmt = $db->prepare("SELECT * FROM bravo_attempts WHERE exam_id = ? AND member_key = ? AND status = 'in_progress' ORDER BY attempt_no DESC LIMIT 1");
    $stmt->execute([$examId, $memberKey]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * 응시 시작 (이어하기 겸용).
 * - in_progress 존재 → 그대로 반환 (resumed=true, 차감 없음)
 * - submitted 존재 → 잠금 (채점 전 보수적 — 불합격 재응시는 결과 공개 슬라이스에서)
 * - 신규: require_check 검증 → 스냅샷 → FOR UPDATE 카운트 → INSERT (dup catch → 기존 반환)
 * 성공: ['attempt'=>row, 'resumed'=>bool], 실패: ['error'=>msg]
 * $testHook: 테스트 전용 — INSERT 직전 호출(동시 시작 race 재현).
 */
function bravoAttemptStart(PDO $db, array $exam, array $memberRow, string $memberKey, bool $otChecked, ?callable $testHook = null): array {
    $examId = (int)$exam['id'];

    $existing = bravoAttemptFindInProgress($db, $examId, $memberKey);
    if ($existing) return ['attempt' => $existing, 'resumed' => true];

    $sub = $db->prepare("SELECT COUNT(*) FROM bravo_attempts WHERE exam_id = ? AND member_key = ? AND status = 'submitted'");
    $sub->execute([$examId, $memberKey]);
    if ((int)$sub->fetchColumn() > 0) {
        return ['error' => '이미 제출한 시험입니다. 결과 발표를 기다려주세요.'];
    }

    $otCheckedAt = null;
    $ot = bravoOtGet($db, $examId);
    if ($ot && (int)$ot['require_check'] === 1) {
        if (!$otChecked) return ['error' => 'OT 안내 확인 체크가 필요합니다.'];
        $otCheckedAt = date('Y-m-d H:i:s');
    }

    $qids = bravoExamQuestionAssignedIds($db, $examId);
    if (!$qids) return ['error' => '아직 출제 준비 중인 시험입니다.'];

    $limit = (int)$exam['attempt_limit'];
    $owns = !$db->inTransaction();
    if ($owns) $db->beginTransaction();
    try {
        // 횟수 차감 race 가드 (코인 한도 FOR UPDATE 패턴)
        $cnt = $db->prepare("SELECT COUNT(*) FROM bravo_attempts WHERE exam_id = ? AND member_key = ? FOR UPDATE");
        $cnt->execute([$examId, $memberKey]);
        $used = (int)$cnt->fetchColumn();
        if ($limit > 0 && $used >= $limit) {
            if ($owns) $db->rollBack();
            return ['error' => "응시 횟수를 모두 사용했습니다. ({$used}/{$limit})"];
        }
        if ($testHook) $testHook();
        $ins = $db->prepare("INSERT INTO bravo_attempts (exam_id, member_key, member_id, attempt_no, question_ids, ot_checked_at) VALUES (?,?,?,?,?,?)");
        $ins->execute([$examId, $memberKey, (int)$memberRow['id'], $used + 1, json_encode($qids), $otCheckedAt]);
        $newId = (int)$db->lastInsertId();
        if ($owns) $db->commit();
        return ['attempt' => bravoAttemptGet($db, $newId), 'resumed' => false];
    } catch (PDOException $e) {
        if ($owns) $db->rollBack();
        if ($e->getCode() === '23000') {
            // 동시 시작: 빈 범위에선 FOR UPDATE 직렬화가 보장되지 않음 → UNIQUE 충돌 catch 후 기존 반환
            $existing = bravoAttemptFindInProgress($db, $examId, $memberKey);
            if ($existing) return ['attempt' => $existing, 'resumed' => true];
        }
        throw $e;
    }
}

/**
 * 스냅샷 순서대로 회원에게 보여줄 문제 페이로드. 정답 필드 미포함.
 * 은행에서 삭제된 문제는 제외(스냅샷엔 남지만 출제 불가 — submit 완비 판정도 동일 기준).
 */
function bravoAttemptQuestions(PDO $db, array $attempt): array {
    $qids = array_map('intval', json_decode((string)$attempt['question_ids'], true) ?: []);
    if (!$qids) return [];
    $place = implode(',', array_fill(0, count($qids), '?'));
    $stmt = $db->prepare("
        SELECT id, question_type, korean_text, target_chunks, reference_speech_sec, response_time_limit_sec
        FROM bravo_questions WHERE id IN ($place)
    ");
    $stmt->execute($qids);
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) $byId[(int)$q['id']] = $q;

    $out = [];
    foreach ($qids as $i => $qid) {
        if (!isset($byId[$qid])) continue;
        $q = $byId[$qid];
        $q['seq'] = $i;
        $out[] = $q;
    }
    return $out;
}

/**
 * 답변 완료된 question_id 목록.
 */
function bravoAttemptAnsweredIds(PDO $db, int $attemptId): array {
    $stmt = $db->prepare("SELECT question_id FROM bravo_answers WHERE attempt_id = ? ORDER BY seq");
    $stmt->execute([$attemptId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * 녹음 답안 저장 (신규 또는 재녹음 1회 교체).
 * $srcPath: 업로드 tmp 파일. $viaUpload=true 면 move_uploaded_file, false(테스트)면 rename.
 * 성공: ['saved'=>true, 'answered_count'=>n, 'all_answered'=>bool], 실패: ['error'=>msg]
 */
function bravoAnswerStore(PDO $db, array $attempt, int $questionId, string $srcPath, string $mime, string $ext, ?int $durationMs, bool $viaUpload = true): array {
    if (($attempt['status'] ?? '') !== 'in_progress') {
        return ['error' => '이미 제출된 응시입니다.'];
    }
    $qids = array_map('intval', json_decode((string)$attempt['question_ids'], true) ?: []);
    $seq = array_search($questionId, $qids, true);
    if ($seq === false) return ['error' => '이 시험의 문제가 아닙니다.'];

    $attemptId = (int)$attempt['id'];
    $ex = $db->prepare("SELECT retake_used FROM bravo_answers WHERE attempt_id = ? AND question_id = ?");
    $ex->execute([$attemptId, $questionId]);
    $prev = $ex->fetch(PDO::FETCH_ASSOC);
    if ($prev && (int)$prev['retake_used'] === 1) {
        return ['error' => '재녹음 기회를 이미 사용했습니다.'];
    }

    $dir = BRAVO_UPLOAD_ROOT . '/answers/' . $attemptId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        return ['error' => '저장 공간 오류입니다. 관리자에게 문의해주세요.'];
    }
    $dest = $dir . '/' . $questionId . '.' . $ext;
    $moved = $viaUpload ? move_uploaded_file($srcPath, $dest) : rename($srcPath, $dest);
    if (!$moved) return ['error' => '녹음 저장에 실패했습니다. 다시 시도해주세요.'];
    // 재녹음이 다른 포맷일 때 옛 확장자 파일 정리
    foreach (glob($dir . '/' . $questionId . '.*') ?: [] as $f) {
        if ($f !== $dest) @unlink($f);
    }

    $relPath = 'answers/' . $attemptId . '/' . $questionId . '.' . $ext;
    try {
        $db->prepare("
            INSERT INTO bravo_answers (attempt_id, question_id, seq, audio_path, audio_mime, duration_ms)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                audio_path = VALUES(audio_path), audio_mime = VALUES(audio_mime),
                duration_ms = VALUES(duration_ms), retake_used = 1, answered_at = CURRENT_TIMESTAMP
        ")->execute([$attemptId, $questionId, (int)$seq, $relPath, $mime, $durationMs]);
    } catch (Throwable $e) {
        @unlink($dest); // 행 실패 시 고아 파일 정리
        throw $e;
    }

    $answered = bravoAttemptAnsweredIds($db, $attemptId);
    $existingQids = array_map(fn($q) => (int)$q['id'], bravoAttemptQuestions($db, $attempt));
    return [
        'saved' => true,
        'answered_count' => count($answered),
        'all_answered' => array_diff($existingQids, $answered) === [],
    ];
}

/**
 * 최종 제출. 기간 체크 없음(의도) — 답안은 기간 내에만 저장되므로 완비 자체가 증명.
 * 완비 판정은 스냅샷 ∩ 현존 문제 기준(은행 삭제 문제로 인한 영구 미완비 방지).
 * 성공: ['submitted'=>true], 실패: ['error'=>msg(, 'missing'=>[qid...])]
 */
function bravoAttemptSubmit(PDO $db, array $attempt): array {
    if (($attempt['status'] ?? '') !== 'in_progress') {
        return ['error' => '이미 제출된 응시입니다.'];
    }
    $attemptId = (int)$attempt['id'];
    $required = array_map(fn($q) => (int)$q['id'], bravoAttemptQuestions($db, $attempt));
    $answered = bravoAttemptAnsweredIds($db, $attemptId);
    $missing = array_values(array_diff($required, $answered));
    if ($missing) {
        return ['error' => '아직 답변하지 않은 문제가 ' . count($missing) . '개 있습니다.', 'missing' => $missing];
    }
    $db->prepare("UPDATE bravo_attempts SET status = 'submitted', submitted_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'in_progress'")
       ->execute([$attemptId]);
    return ['submitted' => true];
}

/**
 * attempt 의 녹음 디렉토리 삭제 (시험 삭제 cascade 용). 루트 밖 경로 방어.
 */
function bravoAttemptPurgeFiles(int $attemptId): void {
    $dir = BRAVO_UPLOAD_ROOT . '/answers/' . $attemptId;
    $real = realpath($dir);
    $root = realpath(BRAVO_UPLOAD_ROOT);
    if ($real === false || $root === false || strpos($real, $root) !== 0) return;
    foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
}
```

- [ ] **Step 4: 통과 확인 + 회귀**

Run:
```bash
cd /root/boot-dev && php tests/bravo_attempt_test.php && php tests/bravo_member_status_test.php && php tests/bravo_exam_service_test.php && php tests/bravo_exam_question_test.php
```
Expected: 전부 `결과: N pass, 0 fail`. (셋업 INSERT 가 스키마와 안 맞으면 Step 1 의 ⚠️ 대로 컬럼 보정.)

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/services/bravo_attempts.php tests/bravo_attempt_test.php
git commit -m "feat(bravo): 응시 서비스 (접근검증/start/answer/submit/파일정리) + 통합 테스트"
```

---

## Task 4: member.php API case 4개 (intro / start / save / submit)

**Files:**
- Modify: `public_html/api/member.php`

- [ ] **Step 1: require_once 추가**

`public_html/api/member.php` 의 기존 `require_once __DIR__ . '/services/bravo.php';` 줄(line 10) **바로 다음**에 추가:

```php
require_once __DIR__ . '/services/bravo_attempts.php';
```

- [ ] **Step 2: 신규 case 4개 추가**

기존 `case 'bravo_status':` 블록(`break;`로 끝남) **바로 다음**에 삽입:

```php
case 'bravo_exam_intro':
    $s = requireMember();
    $examId = (isset($_GET['exam_id']) && is_numeric($_GET['exam_id'])) ? (int)$_GET['exam_id'] : 0;
    if ($examId < 1) jsonError('exam_id가 필요합니다.');
    $db = getDB();
    $acc = bravoAttemptExamAccess($db, (int)$s['member_id'], $examId);
    if (isset($acc['error'])) jsonError($acc['error'], $acc['code'] ?? 400);
    $exam = $acc['exam'];
    $ot = bravoOtGet($db, $examId);
    jsonSuccess([
        'exam' => [
            'id' => (int)$exam['id'], 'title' => $exam['title'], 'bravo_level' => (int)$exam['bravo_level'],
            'exam_mode' => $exam['exam_mode'], 'start_at' => $exam['start_at'], 'end_at' => $exam['end_at'],
            'result_release_at' => $exam['result_release_at'], 'attempt_limit' => (int)$exam['attempt_limit'],
        ],
        'ot' => $ot ? [
            'title' => $ot['title'], 'intro_text' => $ot['intro_text'], 'video_url' => $ot['video_url'],
            'type1_text' => $ot['type1_text'], 'type2_text' => $ot['type2_text'], 'type3_text' => $ot['type3_text'],
            'require_check' => (int)$ot['require_check'],
        ] : null,
        'question_count' => count(bravoExamQuestionAssignedIds($db, $examId)),
        'attempts' => bravoStatusAttempts($db, $examId, $acc['member_key'], (int)$exam['attempt_limit']),
    ]);
    break;

case 'bravo_attempt_start':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $s = requireMember();
    $input = getJsonInput();
    $examId = (isset($input['exam_id']) && is_numeric($input['exam_id'])) ? (int)$input['exam_id'] : 0;
    if ($examId < 1) jsonError('exam_id가 필요합니다.');
    $db = getDB();
    $acc = bravoAttemptExamAccess($db, (int)$s['member_id'], $examId);
    if (isset($acc['error'])) jsonError($acc['error'], $acc['code'] ?? 400);
    $r = bravoAttemptStart($db, $acc['exam'], $acc['ctx']['row'], $acc['member_key'], !empty($input['ot_checked']));
    if (isset($r['error'])) jsonError($r['error']);
    $attempt = $r['attempt'];
    jsonSuccess([
        'attempt_id' => (int)$attempt['id'],
        'attempt_no' => (int)$attempt['attempt_no'],
        'resumed' => !empty($r['resumed']),
        'questions' => bravoAttemptQuestions($db, $attempt),
        'answered_ids' => bravoAttemptAnsweredIds($db, (int)$attempt['id']),
    ]);
    break;

case 'bravo_answer_save':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $s = requireMember();
    // multipart — getJsonInput 아님: $_POST + $_FILES
    $attemptId = (isset($_POST['attempt_id']) && is_numeric($_POST['attempt_id'])) ? (int)$_POST['attempt_id'] : 0;
    $questionId = (isset($_POST['question_id']) && is_numeric($_POST['question_id'])) ? (int)$_POST['question_id'] : 0;
    if ($attemptId < 1 || $questionId < 1) jsonError('attempt_id/question_id가 필요합니다.');
    $db = getDB();
    $attempt = bravoAttemptForMember($db, $attemptId, (int)$s['member_id']);
    if (!$attempt) jsonError('응시 기록을 찾을 수 없습니다.', 404);
    if ($attempt['status'] !== 'in_progress') jsonError('이미 제출된 응시입니다.');
    $exStmt = $db->prepare("SELECT * FROM bravo_exams WHERE id = ?");
    $exStmt->execute([(int)$attempt['exam_id']]);
    $exam = $exStmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam || $exam['status'] !== 'open' || !bravoAttemptSavePeriodOk($exam)) {
        jsonError('응시 기간이 종료되었습니다.');
    }
    if (empty($_FILES['audio'])) jsonError('녹음 파일이 없습니다.');
    $v = bravoAnswerValidateUpload($_FILES['audio']);
    if (isset($v['error'])) jsonError($v['error']);
    $durationMs = (isset($_POST['duration_ms']) && is_numeric($_POST['duration_ms'])) ? (int)$_POST['duration_ms'] : null;
    $r = bravoAnswerStore($db, $attempt, $questionId, $_FILES['audio']['tmp_name'], $v['mime'], $v['ext'], $durationMs, true);
    if (isset($r['error'])) jsonError($r['error']);
    jsonSuccess($r, '저장되었습니다.');
    break;

case 'bravo_attempt_submit':
    if ($method !== 'POST') jsonError('POST만 허용됩니다.', 405);
    $s = requireMember();
    $input = getJsonInput();
    $attemptId = (isset($input['attempt_id']) && is_numeric($input['attempt_id'])) ? (int)$input['attempt_id'] : 0;
    if ($attemptId < 1) jsonError('attempt_id가 필요합니다.');
    $db = getDB();
    $attempt = bravoAttemptForMember($db, $attemptId, (int)$s['member_id']);
    if (!$attempt) jsonError('응시 기록을 찾을 수 없습니다.', 404);
    $r = bravoAttemptSubmit($db, $attempt); // 기간 체크 없음(의도 — 스펙 §5)
    if (isset($r['error'])) jsonError($r['error']);
    jsonSuccess(['submitted' => true], '제출되었습니다.');
    break;
```

⚠️ 삽입 전 실제 파일에서 `$method`/`getJsonInput`/`jsonError`/`jsonSuccess` 사용 패턴이 위와 일치하는지 확인 (member.php:18-100 의 기존 case 참조). save 의 거부 메시지 "응시 기간이 종료되었습니다"는 시험이 closed 로 바뀐 경우도 포함(동일 안내).

- [ ] **Step 3: 문법 검사**

Run: `cd /root/boot-dev && php -l public_html/api/member.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: 전체 BRAVO 테스트 회귀 (14파일)**

Run:
```bash
cd /root/boot-dev && for f in bravo_schema_invariants bravo_qualification_test bravo_admin_service_test bravo_exam_validate_test bravo_exam_service_test bravo_exams_schema_invariants bravo_member_status_test bravo_questions_schema_invariants bravo_question_test bravo_ot_test bravo_exam_questions_schema_invariants bravo_exam_question_test bravo_attempts_schema_invariants bravo_attempt_test; do echo "== $f =="; php tests/$f.php | tail -1; done
```
Expected: 각 줄 `결과: N pass, 0 fail`

- [ ] **Step 5: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/member.php
git commit -m "feat(bravo): 회원 응시 API case (intro/start/answer_save/submit)"
```

---

## Task 5: 프론트 — 응시 플로우 모듈 + 카드 연결

**Files:**
- Create: `public_html/js/member-bravo-exam.js`
- Modify: `public_html/js/member-bravo.js`
- Modify: `public_html/index.php`

먼저 `public_html/js/member-bravo.js` 전체(65줄)와 `public_html/js/common.js` 의 `App.api/get/post`(FormData 지원, 1-55줄), `public_html/js/member-tabs.js` 의 register 패턴을 읽고 구조 확인.

- [ ] **Step 1: member-bravo-exam.js 작성**

Create `public_html/js/member-bravo-exam.js`:

```javascript
/* ══════════════════════════════════════════════════════════════
   MemberBravoExam — BRAVO 응시 플로우 (OT→마이크테스트→응시→제출)
   진입: MemberBravoExam.open(el, examId, onExit)
   - el: BRAVO 탭 컨테이너 (플로우가 내용을 대체, 종료 시 onExit() 로 복귀)
   - 녹음: MediaRecorder (webm/opus → mp4 폴백), 문제 공개와 동시 자동 시작
   ══════════════════════════════════════════════════════════════ */
const MemberBravoExam = (() => {
    const DEFAULT_LIMIT_SEC = 60; // 문제별 제한시간 기본값 (limit/reference 합산 NULL 일 때)

    let el = null, onExit = null;
    let stream = null;          // OT 마이크테스트에서 획득, 플로우 종료 시 해제
    let mimeType = '';
    let recorder = null, chunks = [], recTimer = null, recStartedAt = 0;
    let st = null;              // { examId, intro, attemptId, questions, answered:Set, idx, retakeDone, blob, durationMs }

    function pickMime() {
        if (!window.MediaRecorder || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return '';
        const cands = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'];
        for (const m of cands) {
            try { if (MediaRecorder.isTypeSupported(m)) return m; } catch (e) { /* ignore */ }
        }
        return '';
    }

    function releaseStream() {
        if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
        if (recTimer) { clearInterval(recTimer); recTimer = null; }
        recorder = null;
    }

    function exit() {
        releaseStream();
        st = null;
        if (onExit) onExit();
    }

    // ── 진입: intro 로드 → OT 화면 ──
    async function open(container, examId, exitCb) {
        el = container; onExit = exitCb;
        el.innerHTML = '<div class="bravo-exam"><p>불러오는 중...</p></div>';
        const r = await App.get('/api/member.php?action=bravo_exam_intro', { exam_id: examId });
        if (!r || r.success === false) { exit(); return; }
        st = { examId, intro: r, attemptId: null, questions: [], answered: new Set(), idx: -1, retakeDone: false, blob: null, durationMs: 0 };
        renderOt();
    }

    // ── OT + 마이크테스트 화면 ──
    function renderOt() {
        const { exam, ot, question_count, attempts } = st.intro;
        const resuming = !!(attempts && attempts.in_progress);
        const otHtml = ot ? `
            ${ot.title ? `<h4>${App.esc(ot.title)}</h4>` : ''}
            ${ot.intro_text ? `<p class="bx-pre">${App.esc(ot.intro_text)}</p>` : ''}
            ${ot.video_url ? `<p><a href="${App.esc(ot.video_url)}" target="_blank" rel="noopener">OT 영상 보기</a></p>` : ''}
            ${[1, 2, 3].map(n => ot['type' + n + '_text'] ? `<div class="bx-type-guide"><strong>유형 ${n}</strong><p class="bx-pre">${App.esc(ot['type' + n + '_text'])}</p></div>` : '').join('')}
        ` : '<p>시험 안내가 곧 등록됩니다.</p>';
        const needCheck = !!(ot && parseInt(ot.require_check, 10) === 1) && !resuming;

        el.innerHTML = `
            <div class="bravo-exam">
                <h3>${App.esc(exam.title || 'BRAVO 시험')}</h3>
                <p class="bx-meta">BRAVO ${exam.bravo_level} · ${question_count}문항 · 응시 ${attempts.used}/${attempts.limit}회 사용</p>
                <div class="bx-ot">${otHtml}</div>
                <div class="bx-mic">
                    <h4>🎤 마이크 테스트 (필수)</h4>
                    <p>3초간 녹음 후 재생해 소리를 확인하세요. 통과해야 응시할 수 있습니다.</p>
                    <button class="btn" id="bx-mic-rec">3초 녹음</button>
                    <audio id="bx-mic-play" controls style="display:none"></audio>
                    <label id="bx-mic-ok-wrap" style="display:none"><input type="checkbox" id="bx-mic-ok"> 내 목소리가 잘 들립니다</label>
                    <p id="bx-mic-msg"></p>
                </div>
                ${needCheck ? '<label class="bx-check"><input type="checkbox" id="bx-ot-check"> 위 안내를 모두 확인했습니다 (필수)</label>' : ''}
                ${resuming
                    ? `<p class="bx-warn">진행 중인 응시가 있습니다. (답변 ${attempts.in_progress.answered}/${attempts.in_progress.total})</p>`
                    : '<p class="bx-warn">⚠️ 시작하면 응시 1회가 차감됩니다. 중도 이탈 시 시험 기간 내 이어하기가 가능합니다.</p>'}
                <div class="bx-actions">
                    <button class="btn btn-primary" id="bx-start" disabled>${resuming ? '이어서 응시' : '응시 시작'}</button>
                    <button class="btn" id="bx-back">돌아가기</button>
                </div>
            </div>`;

        const micBtn = el.querySelector('#bx-mic-rec');
        const micMsg = el.querySelector('#bx-mic-msg');
        const startBtn = el.querySelector('#bx-start');
        const otCheck = el.querySelector('#bx-ot-check');

        function refreshStart() {
            const micOk = el.querySelector('#bx-mic-ok');
            const ok = micOk && micOk.checked && (!otCheck || otCheck.checked);
            startBtn.disabled = !ok;
        }
        if (otCheck) otCheck.addEventListener('change', refreshStart);

        micBtn.addEventListener('click', async () => {
            mimeType = pickMime();
            if (!mimeType) { micMsg.textContent = '이 브라우저는 녹음을 지원하지 않습니다. 크롬/사파리 최신 버전을 사용해주세요.'; return; }
            try {
                if (!stream) stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (e) {
                micMsg.textContent = '마이크 권한이 거부되었습니다. 브라우저 설정에서 허용 후 다시 시도해주세요.';
                return;
            }
            micBtn.disabled = true; micMsg.textContent = '녹음 중... (3초)';
            const rec = new MediaRecorder(stream, { mimeType });
            const buf = [];
            rec.ondataavailable = e => { if (e.data && e.data.size) buf.push(e.data); };
            rec.onstop = () => {
                const blob = new Blob(buf, { type: mimeType });
                const player = el.querySelector('#bx-mic-play');
                player.src = URL.createObjectURL(blob);
                player.style.display = '';
                el.querySelector('#bx-mic-ok-wrap').style.display = '';
                el.querySelector('#bx-mic-ok').addEventListener('change', refreshStart);
                micMsg.textContent = '재생해서 확인 후 체크해주세요.';
                micBtn.disabled = false; micBtn.textContent = '다시 녹음';
            };
            rec.start();
            setTimeout(() => { if (rec.state !== 'inactive') rec.stop(); }, 3000);
        });

        startBtn.addEventListener('click', async () => {
            startBtn.disabled = true;
            const r = await App.post('/api/member.php?action=bravo_attempt_start',
                { exam_id: st.examId, ot_checked: otCheck ? (otCheck.checked ? 1 : 0) : 0 });
            if (!r || r.success === false) { startBtn.disabled = false; return; }
            st.attemptId = r.attempt_id;
            st.questions = r.questions || [];
            st.answered = new Set((r.answered_ids || []).map(Number));
            st.idx = -1;
            renderInterstitial();
        });
        el.querySelector('#bx-back').addEventListener('click', exit);
    }

    // ── 다음 미답변 문제 인덱스 (없으면 -1) ──
    function nextIdx() {
        for (let i = 0; i < st.questions.length; i++) {
            if (!st.answered.has(parseInt(st.questions[i].id, 10))) return i;
        }
        return -1;
    }

    // ── 문제 사이 대기 화면 ("다음 문제" → 공개+자동녹음) ──
    function renderInterstitial() {
        const ni = nextIdx();
        if (ni === -1) { renderSubmit(); return; }
        const total = st.questions.length;
        el.innerHTML = `
            <div class="bravo-exam">
                <p class="bx-progress">진행 ${st.answered.size} / ${total}</p>
                <p>다음 문제를 누르면 <strong>문제 공개와 동시에 녹음이 시작</strong>됩니다.<br>준비되면 눌러주세요.</p>
                <div class="bx-actions"><button class="btn btn-primary" id="bx-next">다음 문제</button></div>
            </div>`;
        el.querySelector('#bx-next').addEventListener('click', () => { st.idx = ni; st.retakeDone = false; renderQuestion(); });
    }

    function typeLabel(t) {
        return { 1: '유형1 · 청크 스피드', 2: '유형2 · 문장변형', 3: '유형3 · 한문장' }[parseInt(t, 10)] || '유형 ' + t;
    }

    function limitSecOf(q) {
        const a = parseFloat(q.response_time_limit_sec), b = parseFloat(q.reference_speech_sec);
        const sum = (isNaN(a) ? 0 : a) + (isNaN(b) ? 0 : b);
        return sum > 0 ? Math.ceil(sum) : DEFAULT_LIMIT_SEC;
    }

    // ── 문제 화면: 공개와 동시 자동 녹음 ──
    function renderQuestion() {
        const q = st.questions[st.idx];
        const limitSec = limitSecOf(q);
        el.innerHTML = `
            <div class="bravo-exam">
                <p class="bx-progress">문제 ${st.idx + 1} / ${st.questions.length} · ${App.esc(typeLabel(q.question_type))}</p>
                <div class="bx-question">
                    <p class="bx-korean">${App.esc(q.korean_text || '')}</p>
                    ${q.target_chunks ? `<p class="bx-chunks">청크: ${App.esc(q.target_chunks)}</p>` : ''}
                </div>
                <p class="bx-rec"><span class="bx-rec-dot">●</span> 녹음 중 <span id="bx-elapsed">0</span>s / 최대 ${limitSec}s</p>
                <div class="bx-actions"><button class="btn btn-primary" id="bx-done">말하기 완료</button></div>
            </div>`;
        startRecording(limitSec, () => stopAndUpload());
        el.querySelector('#bx-done').addEventListener('click', () => stopAndUpload());
    }

    function startRecording(limitSec, onLimit) {
        chunks = [];
        recorder = new MediaRecorder(stream, { mimeType });
        recorder.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
        recStartedAt = Date.now();
        recorder.start();
        const elapsedEl = el.querySelector('#bx-elapsed');
        recTimer = setInterval(() => {
            const sec = Math.floor((Date.now() - recStartedAt) / 1000);
            if (elapsedEl) elapsedEl.textContent = sec;
            if (sec >= limitSec) { clearInterval(recTimer); recTimer = null; onLimit(); }
        }, 250);
    }

    function stopAndUpload() {
        if (!recorder || recorder.state === 'inactive') return;
        if (recTimer) { clearInterval(recTimer); recTimer = null; }
        st.durationMs = Date.now() - recStartedAt;
        recorder.onstop = () => {
            st.blob = new Blob(chunks, { type: mimeType });
            uploadCurrent();
        };
        recorder.stop();
    }

    async function uploadCurrent(isManualRetry) {
        const q = st.questions[st.idx];
        el.innerHTML = '<div class="bravo-exam"><p>답안 업로드 중...</p></div>';
        let r = await postAnswer(q);
        if ((!r || r.success === false) && !isManualRetry) r = await postAnswer(q); // 자동 재시도 1회
        if (!r || r.success === false) { renderUploadRetry(); return; }
        st.answered.add(parseInt(q.id, 10));
        renderAfterUpload(r);
    }

    function postAnswer(q) {
        const fd = new FormData();
        fd.append('attempt_id', st.attemptId);
        fd.append('question_id', q.id);
        fd.append('duration_ms', st.durationMs);
        const ext = mimeType.indexOf('mp4') !== -1 ? 'm4a' : 'webm';
        fd.append('audio', st.blob, 'answer.' + ext);
        return App.post('/api/member.php?action=bravo_answer_save', fd);
    }

    function renderUploadRetry() {
        el.innerHTML = `
            <div class="bravo-exam">
                <p class="bx-warn">업로드에 실패했습니다. 네트워크 확인 후 다시 시도해주세요.<br>(녹음은 보관 중 — 화면을 떠나면 사라집니다)</p>
                <div class="bx-actions"><button class="btn btn-primary" id="bx-retry">재전송</button></div>
            </div>`;
        el.querySelector('#bx-retry').addEventListener('click', () => uploadCurrent(true));
    }

    // ── 업로드 성공 후: 재녹음 1회 or 다음 ──
    function renderAfterUpload(r) {
        const canRetake = !st.retakeDone;
        el.innerHTML = `
            <div class="bravo-exam">
                <p class="bx-progress">진행 ${st.answered.size} / ${st.questions.length}</p>
                <p>답안이 저장되었습니다.</p>
                <div class="bx-actions">
                    ${canRetake ? '<button class="btn" id="bx-retake">재녹음 (1회)</button>' : ''}
                    <button class="btn btn-primary" id="bx-go-next">${r.all_answered ? '제출 단계로' : '다음 문제'}</button>
                </div>
            </div>`;
        const rt = el.querySelector('#bx-retake');
        if (rt) rt.addEventListener('click', () => { st.retakeDone = true; renderQuestion(); });
        el.querySelector('#bx-go-next').addEventListener('click', () => renderInterstitial());
    }

    // ── 제출 화면 ──
    function renderSubmit() {
        const rel = st.intro.exam.result_release_at;
        el.innerHTML = `
            <div class="bravo-exam">
                <p>전체 ${st.questions.length}문항 답변 완료 ✅</p>
                <div class="bx-actions"><button class="btn btn-primary" id="bx-submit">최종 제출</button></div>
            </div>`;
        el.querySelector('#bx-submit').addEventListener('click', async () => {
            const btn = el.querySelector('#bx-submit');
            btn.disabled = true;
            const r = await App.post('/api/member.php?action=bravo_attempt_submit', { attempt_id: st.attemptId });
            if (!r || r.success === false) { btn.disabled = false; return; }
            releaseStream();
            el.innerHTML = `
                <div class="bravo-exam">
                    <h3>제출되었습니다 🎉</h3>
                    <p>결과는 발표일에 공개됩니다.${rel ? ' (발표: ' + App.esc(String(rel).slice(0, 10)) + ')' : ''}</p>
                    <div class="bx-actions"><button class="btn" id="bx-finish">확인</button></div>
                </div>`;
            el.querySelector('#bx-finish').addEventListener('click', exit);
        });
    }

    // ── 카드의 [제출 마무리] 전용 (응시 화면 없이 submit 만) ──
    async function finalize(attemptId, exitCb) {
        const r = await App.post('/api/member.php?action=bravo_attempt_submit', { attempt_id: attemptId });
        if (r && r.success !== false) Toast.success('제출되었습니다.');
        if (exitCb) exitCb();
    }

    return { open, finalize };
})();
```

- [ ] **Step 2: member-bravo.js 카드 연결**

`public_html/js/member-bravo.js` 를 아래로 전체 교체 (기존 statusBadge/detailText/구조 보존 + 액션 버튼/바인딩 추가):

```javascript
/* ══════════════════════════════════════════════════════════════
   MemberBravo — BRAVO 도전 탭
   등급 카드(자격/기간) + 응시 액션(도전하기/이어하기/제출 마무리)
   응시 플로우는 MemberBravoExam 모듈로 위임
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

    // 카드 액션 (스펙 §4-1 상태표)
    function actionHtml(lv) {
        const ex = lv.exam, at = lv.attempts;
        if (!lv.eligible || !ex || !at) return '';
        if (at.submitted) {
            return '<p class="bravo-state">제출완료 — 결과 발표 대기</p>';
        }
        if (at.in_progress) {
            const ip = at.in_progress;
            if (ip.answered >= ip.total && ip.total > 0) {
                return `<button class="btn btn-primary bravo-finalize" data-attempt-id="${ip.attempt_id}">제출 마무리</button>`;
            }
            if (ex.status === 'open') {
                return `<button class="btn btn-primary bravo-challenge" data-exam-id="${at.exam_id}">이어하기 (${ip.answered}/${ip.total})</button>`;
            }
            return '<p class="bravo-state">응시 기간 종료 (미제출)</p>';
        }
        if (ex.status === 'open') {
            if (at.used >= at.limit && at.limit > 0) {
                return `<p class="bravo-state">응시 횟수 소진 (${at.used}/${at.limit})</p>`;
            }
            return `<button class="btn btn-primary bravo-challenge" data-exam-id="${at.exam_id}">도전하기 (${at.used}/${at.limit}회 사용)</button>`;
        }
        return '';
    }

    function render(el, member, levels) {
        const cards = levels.map(lv => `
            <div class="bravo-level-card ${lv.eligible ? 'is-eligible' : ''}">
                <div class="bravo-level-head">
                    <span class="bravo-level-name">${App.esc(lv.name || ('BRAVO ' + lv.level))}</span>
                    ${statusBadge(lv)}
                </div>
                <div class="bravo-level-detail">${App.esc(detailText(lv))}</div>
                <div class="bravo-level-action">${actionHtml(lv)}</div>
            </div>`).join('');

        const sub = member
            ? `${App.esc(member.cohort || '')} · 등록 회독 ${parseInt(member.effective_review_count, 10) || 0}회`
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

        el.querySelectorAll('.bravo-challenge').forEach(b =>
            b.addEventListener('click', () =>
                MemberBravoExam.open(el, parseInt(b.dataset.examId, 10), () => mount(el, member))));
        el.querySelectorAll('.bravo-finalize').forEach(b =>
            b.addEventListener('click', () =>
                MemberBravoExam.finalize(parseInt(b.dataset.attemptId, 10), () => mount(el, member))));
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

- [ ] **Step 3: index.php include 추가**

`public_html/index.php` 의 `<script src="/js/member-bravo.js...` 줄 **바로 앞**에 추가 (exam 모듈이 먼저 정의돼야 함):

```php
    <script src="/js/member-bravo-exam.js<?= v('/js/member-bravo-exam.js') ?>"></script>
```

- [ ] **Step 4: 문법 검사 + 커밋**

Run:
```bash
cd /root/boot-dev && node --check public_html/js/member-bravo-exam.js && node --check public_html/js/member-bravo.js && php -l public_html/index.php
```
Expected: JS 출력 없음, PHP `No syntax errors detected`.

```bash
cd /root/boot-dev && git add public_html/js/member-bravo-exam.js public_html/js/member-bravo.js public_html/index.php
git commit -m "feat(bravo): 회원 응시 플로우 프론트 (OT/마이크테스트/자동녹음/이어하기/제출)"
```

- [ ] **Step 5: 브라우저 통합 검증 (controller/사용자)**

https://dev-boot.soritune.com 회원 로그인 → BRAVO 도전 탭:
- [도전하기] → OT/마이크테스트(3초 녹음·재생) → 확인체크 → 응시 시작 → 자동 녹음 → 말하기 완료 → 재녹음 1회 → 다음 문제 → 전 문항 → 최종 제출
- 중도 이탈(새로고침) → [이어하기] → 첫 미답변부터 재개
- PC Chrome / Android Chrome / iPhone Safari 각 1회. (구현 subagent 는 브라우저 실행 불필요.)

---

## 최종 통합 검증 (전 태스크 완료 후)

- [ ] **전체 BRAVO 테스트 재실행 (14파일, 회귀 0)**

Run:
```bash
cd /root/boot-dev && for f in bravo_schema_invariants bravo_qualification_test bravo_admin_service_test bravo_exam_validate_test bravo_exam_service_test bravo_exams_schema_invariants bravo_member_status_test bravo_questions_schema_invariants bravo_question_test bravo_ot_test bravo_exam_questions_schema_invariants bravo_exam_question_test bravo_attempts_schema_invariants bravo_attempt_test; do echo "== $f =="; php tests/$f.php | tail -1; done
```
Expected: 모든 파일 `결과: N pass, 0 fail`

- [ ] **dev push (운영 미반영)**

```bash
cd /root/boot-dev && git push origin dev
```

⛔ **여기서 멈춤.** 운영(main) 반영은 BRAVO 전체 완성 시 1회 일괄 — 사용자 명시 요청 시에만.

---

## 미적용 (다음 슬라이스)

채점(관리자 녹음 청취·점수 입력·검수), 결과 발표(released 시 합격/불합격 공개·불합격 재응시 해제), 인증서, 자동출제, STT/자동평가, OT 영상 업로드, 오류 신고·횟수 복구, 전체 BRAVO UI 스타일링(.bravo-exam/.bx-* 포함 slice1~6 누적).
