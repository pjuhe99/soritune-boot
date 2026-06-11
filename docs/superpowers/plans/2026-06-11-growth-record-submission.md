# 성장기록 제출 (Before/After 음성 업로드) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 기존 후기 제출(코인)을 대체하는 '성장기록 제출' — 후기 링크 + Before/After 음성 2개 + 마케팅 동의 체크를 받고, 운영진이 admin에서 확인/재생/취소할 수 있게 한다.

**Architecture:** 기존 review.php 패턴(서비스 fragment + bootcamp.php switch 라우팅 + system_contents 설정)과 bravo_attempts.php 음성 업로드/스트리밍 패턴을 그대로 차용. 신규 테이블 `growth_record_submissions`은 PERSISTENT generated column unique 키로 회원당 활성 제출 1회를 DB 레벨에서 보장. 음성은 docroot 밖 `growth_uploads/`에 저장, PHP 스트리밍으로만 접근.

**Tech Stack:** PHP 8 (PDO/MariaDB 10.5), vanilla JS IIFE 모듈, Apache(apache 계정)+SELinux.

**스펙:** `docs/superpowers/specs/2026-06-11-growth-record-submission-design.md`

**작업 위치: 반드시 `/root/boot-dev` (dev 브랜치). DEV DB만. 운영 클론 절대 금지.**

---

## File Structure

| 파일 | 역할 |
|------|------|
| Create `migrate_growth_record_submissions.php` | 테이블 + system_contents 3키 시드 |
| Create `tests/growth_record_schema_invariants.php` | 스키마 검증 |
| Create `public_html/api/services/growth_record.php` | 검증/제출/취소/스트리밍 코어 + HTTP 핸들러 |
| Create `tests/growth_record_test.php` | 서비스 단위 테스트 |
| Modify `public_html/api/bootcamp.php` | require + 라우팅 case 8개 |
| Create `public_html/js/member-growth-record.js` | 회원 제출 화면 |
| Modify `public_html/js/member.js` | open/close 라우팅 + area div |
| Modify `public_html/js/member-shortcuts.js` | 후기 카드 → 성장기록 카드 교체 |
| Modify `public_html/index.php` | script 태그 추가 |
| Create `public_html/js/admin-growth-records.js` | admin 성장기록 탭 |
| Modify `public_html/js/admin.js` | 탭 버튼/컨테이너/lazy init |
| Modify `public_html/operation/index.php` | script 태그 추가 |
| Modify `public_html/css/member.css` | 회원 화면 스타일 |
| Modify `public_html/css/admin.css` | admin 테이블/오디오 스타일 |

참고할 기존 코드 (구현 전 반드시 읽기):
- `public_html/api/services/review.php` — 서비스/핸들러/jsonSuccess 평탄화 스타일
- `public_html/api/services/bravo_attempts.php` — 업로드 검증(`bravoAnswerValidateUpload`)
- `public_html/api/admin.php:880-921` — 오디오 스트리밍 (`bravo_answer_audio` case)
- `public_html/api/services/bravo_grading.php:55-71` — `bravoAudioRangeParse`
- `public_html/js/member-review.js`, `js/admin-reviews.js` — UI 패턴
- `tests/notice_service_test.php` — 테스트 t()/expectThrow 패턴

주의 (메모리 피드백):
- 마이그/서비스의 require는 전부 `require_once` (silent fatal 방지)
- `jsonSuccess($payload)`는 평탄화 — JS에서 `r.<key>` 직접 접근 (`r.data.<key>` 아님)
- 캐시버스터는 `v()` 헬퍼가 filemtime 기반 자동 — `index.php`/`operation/index.php`에 script 태그만 추가하면 됨

---

### Task 1: 마이그레이션 + 업로드 디렉토리 + 스키마 invariants

**Files:**
- Create: `migrate_growth_record_submissions.php`
- Create: `tests/growth_record_schema_invariants.php`

- [ ] **Step 1: 스키마 invariants 테스트 작성 (실패 확인용)**

`tests/growth_record_schema_invariants.php`:

```php
<?php
/**
 * growth_record_submissions 스키마 invariants.
 * 사용: cd /root/boot-dev && php tests/growth_record_schema_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$db = getDB();
$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// 테이블 존재
t('table exists', (bool)$db->query("SHOW TABLES LIKE 'growth_record_submissions'")->fetch());

// 컬럼
$cols = [];
foreach ($db->query("SHOW COLUMNS FROM growth_record_submissions")->fetchAll() as $c) $cols[$c['Field']] = $c;
foreach (['id','member_id','cohort_id','url','before_file','after_file','before_orig_name','after_orig_name',
          'before_mime','after_mime','consent_agreed_at','submitted_at','cancelled_at','cancelled_by',
          'cancel_reason','active_member_id'] as $f) {
    t("column {$f}", isset($cols[$f]));
}
t('consent_agreed_at NOT NULL', ($cols['consent_agreed_at']['Null'] ?? '') === 'NO');
t('active_member_id is generated', stripos($cols['active_member_id']['Extra'] ?? '', 'GENERATED') !== false
    || stripos($cols['active_member_id']['Extra'] ?? '', 'PERSISTENT') !== false
    || stripos($cols['active_member_id']['Extra'] ?? '', 'STORED') !== false);

// unique 키
$uq = false;
foreach ($db->query("SHOW INDEX FROM growth_record_submissions")->fetchAll() as $ix) {
    if ($ix['Key_name'] === 'uq_active_member' && (int)$ix['Non_unique'] === 0) $uq = true;
}
t('uq_active_member unique key', $uq);

// FK
$fks = $db->query("
    SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'growth_record_submissions'
      AND REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll(PDO::FETCH_COLUMN);
t('fk member', in_array('fk_growth_member', $fks, true));
t('fk cohort', in_array('fk_growth_cohort', $fks, true));

// system_contents 키
$keys = $db->query("SELECT content_key FROM system_contents WHERE content_key LIKE 'growth_record_%'")
           ->fetchAll(PDO::FETCH_COLUMN);
foreach (['growth_record_enabled','growth_record_deadline','growth_record_guide'] as $k) {
    t("system_contents {$k}", in_array($k, $keys, true));
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: 실패 확인**

Run: `cd /root/boot-dev && php tests/growth_record_schema_invariants.php`
Expected: FAIL 다수 (테이블 없음) — `table exists` FAIL이면 충분, exit 1

- [ ] **Step 3: 마이그레이션 작성**

`migrate_growth_record_submissions.php` (기존 `migrate_review_submissions.php` 컨벤션):

```php
<?php
/**
 * boot.soritune.com - 성장기록 제출(growth_record_submissions) 마이그
 * - growth_record_submissions 테이블 생성 (활성 제출 1회 unique 보장)
 * - system_contents에 토글/마감/가이드 키 3개 seed
 * 스펙: docs/superpowers/specs/2026-06-11-growth-record-submission-design.md
 *
 * 실행: php migrate_growth_record_submissions.php
 * Dry-run: php migrate_growth_record_submissions.php --dry-run
 */

require_once __DIR__ . '/public_html/config.php';

$dryRun = in_array('--dry-run', $argv);
$db = getDB();

echo "=== Growth Record Submissions Migration" . ($dryRun ? " [DRY-RUN]" : "") . " ===\n\n";

try {
    if (!$dryRun) $db->beginTransaction();

    echo "[1] growth_record_submissions 테이블 생성...\n";
    $stmt = $db->query("SHOW TABLES LIKE 'growth_record_submissions'");
    if ($stmt->fetch()) {
        echo "  - 이미 존재\n";
    } else {
        $sql = "CREATE TABLE growth_record_submissions (
            id                INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
            member_id         INT UNSIGNED NOT NULL,
            cohort_id         INT UNSIGNED NOT NULL,
            url               VARCHAR(500) NOT NULL,
            before_file       VARCHAR(255) NOT NULL,
            after_file        VARCHAR(255) NOT NULL,
            before_orig_name  VARCHAR(255) NOT NULL,
            after_orig_name   VARCHAR(255) NOT NULL,
            before_mime       VARCHAR(50) NOT NULL,
            after_mime        VARCHAR(50) NOT NULL,
            consent_agreed_at DATETIME NOT NULL,
            submitted_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cancelled_at      DATETIME NULL,
            cancelled_by      INT UNSIGNED NULL,
            cancel_reason     VARCHAR(255) NULL,
            active_member_id  INT UNSIGNED AS (IF(cancelled_at IS NULL, member_id, NULL)) PERSISTENT,
            UNIQUE KEY uq_active_member (active_member_id),
            INDEX idx_submitted_at (submitted_at DESC),
            INDEX idx_member (member_id),
            INDEX idx_cohort (cohort_id),
            CONSTRAINT fk_growth_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id),
            CONSTRAINT fk_growth_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if (!$dryRun) $db->exec($sql);
        echo "  - " . ($dryRun ? "생성 예정" : "생성 완료") . "\n";
    }

    echo "[2] system_contents 시드...\n";
    $guide = <<<MD
## 🌱 성장기록 미션 안내

5주간의 훈련 전후 변화를 **후기 글 + 사진 + Before/After 음성**으로 남겨주세요.
미션 완료 시 **현재 수강 기수 단계의 VOD가 5주 연장**됩니다. (제출 데이터 취합 후 **6월 17일(수) 일괄 반영**)

**제출 마감: 2026년 6월 16일(화)까지**

### 1) 후기 작성 (카페/블로그)

기존 후기 안내 기준대로 카페 또는 블로그에 작성해주세요. **직접 촬영/캡처한 사진 3장 이상 필수.**

아래 질문을 참고해 작성하면 좋아요:
1. 부트캠프 수강 전, 영어 소리에 대한 나의 고민은 무엇이었나요?
2. 5주간 훈련하면서 스스로 느낀 가장 큰 변화는 무엇인가요?
3. 다가올 13기 수강을 고민하는 동료들에게 선배로서 따뜻한 한마디를 남겨주세요!

### 2) Before / After 음성

- **Before 소리**: 소리튠영어를 막 시작했을 때의 가공되지 않은 소리
  (12기가 첫 수강이라면 1주차 말까미션 음성, 기존 수강생이라면 소리튠 시작 전 녹음 파일)
- **After 소리**: 이번 기수 진행 중 가장 자신 있는 과제 음성, 또는 5주차 말까미션 음성

음성 2개는 카페/블로그 글에도 첨부하고, **아래에서 파일로도 제출**해주세요.
카페/블로그 첨부 파일명은 `12기_닉네임_before`, `12기_닉네임_after` 형식을 권장합니다.
(아래에서 제출하는 파일은 시스템이 자동으로 이름을 정리하니 파일명을 맞추지 않아도 됩니다.)
MD;
    $seeds = [
        ['growth_record_enabled', 'on'],
        ['growth_record_deadline', '2026-06-16 23:59:59'],
        ['growth_record_guide', $guide],
    ];
    foreach ($seeds as [$key, $val]) {
        $check = $db->prepare("SELECT id FROM system_contents WHERE content_key = ?");
        $check->execute([$key]);
        if ($check->fetch()) {
            echo "  - {$key} : 이미 존재\n";
        } else {
            if (!$dryRun) {
                $db->prepare("INSERT INTO system_contents (content_key, content_markdown) VALUES (?, ?)")
                   ->execute([$key, $val]);
            }
            echo "  - {$key} : " . ($dryRun ? "삽입 예정" : "삽입 완료") . "\n";
        }
    }

    echo "[3] 검증...\n";
    $check = $db->query("SHOW TABLES LIKE 'growth_record_submissions'")->fetch();
    echo "  - growth_record_submissions 테이블: " . ($check ? "OK" : ($dryRun ? "(dry-run)" : "MISSING")) . "\n";

    if (!$dryRun && $db->inTransaction()) $db->commit();
    echo "\n완료" . ($dryRun ? " (dry-run)" : "") . ".\n";
} catch (Throwable $e) {
    if (!$dryRun && $db->inTransaction()) $db->rollBack();
    echo "\n실패: " . $e->getMessage() . "\n";
    exit(1);
}
```

- [ ] **Step 4: dry-run 후 실행**

Run: `cd /root/boot-dev && php migrate_growth_record_submissions.php --dry-run`
Expected: `생성 예정` + 키 3개 `삽입 예정`, 에러 없음

Run: `cd /root/boot-dev && php migrate_growth_record_submissions.php`
Expected: `생성 완료` + 키 3개 `삽입 완료`

- [ ] **Step 5: invariants 테스트 통과 확인**

Run: `cd /root/boot-dev && php tests/growth_record_schema_invariants.php`
Expected: 전부 PASS, exit 0

- [ ] **Step 6: 업로드 디렉토리 생성 (DEV)**

```bash
mkdir -p /var/www/html/_______site_SORITUNECOM_DEV_BOOT/growth_uploads/tmp
chown -R apache:apache /var/www/html/_______site_SORITUNECOM_DEV_BOOT/growth_uploads
chmod 750 /var/www/html/_______site_SORITUNECOM_DEV_BOOT/growth_uploads
semanage fcontext -a -t httpd_sys_rw_content_t '/var/www/html/_______site_SORITUNECOM_DEV_BOOT/growth_uploads(/.*)?'
restorecon -Rv /var/www/html/_______site_SORITUNECOM_DEV_BOOT/growth_uploads
```

검증: `ls -ldZ /var/www/html/_______site_SORITUNECOM_DEV_BOOT/growth_uploads`
Expected: `apache apache`, context에 `httpd_sys_rw_content_t` 포함 (bravo_uploads와 동일 형태)

- [ ] **Step 7: Commit**

```bash
cd /root/boot-dev && git add migrate_growth_record_submissions.php tests/growth_record_schema_invariants.php
git commit -m "feat: growth_record_submissions 테이블 마이그 + 스키마 invariants"
```

---

### Task 2: 서비스 코어 + 단위 테스트

**Files:**
- Create: `public_html/api/services/growth_record.php`
- Create: `tests/growth_record_test.php`

- [ ] **Step 1: 서비스 파일 작성 (코어 함수부)**

`public_html/api/services/growth_record.php` 전체:

```php
<?php
/**
 * Growth Record Service — 성장기록 제출
 * 후기 링크 + Before/After 음성 업로드 + 마케팅 활용 동의. 코인 지급 없음.
 * 기존 후기(review.php)를 대체하는 별도 기능 — VOD 연장은 운영팀이 admin 목록 보고 수동 반영.
 * 스펙: docs/superpowers/specs/2026-06-11-growth-record-submission-design.md
 *
 * 의존: review.php 의 getSystemContent()/isValidReviewUrl()
 *       (bootcamp.php 에서 review.php 가 먼저 require 됨)
 */

if (!defined('GROWTH_UPLOAD_ROOT')) {
    define('GROWTH_UPLOAD_ROOT', dirname(__DIR__, 3) . '/growth_uploads');
}

const GROWTH_AUDIO_MAX_BYTES = 20 * 1024 * 1024; // 20MB — 폰 녹음 원본 파일 업로드 가정
const GROWTH_ADMIN_ROLES = ['operation', 'coach', 'head', 'subhead1', 'subhead2'];

// ── 업로드 검증 ───────────────────────────────────────────────

/**
 * 실측 MIME → 저장 확장자. 미지원이면 null.
 * (m4a 는 기기별로 audio/mp4·video/mp4·audio/x-m4a 로 갈림 — 전부 수용)
 */
function growthAudioExt(string $mime): ?string {
    $map = [
        'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3',
        'audio/mp4'  => 'm4a', 'video/mp4'  => 'm4a', 'audio/x-m4a' => 'm4a',
        'audio/wav'  => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav',
        'audio/webm' => 'webm', 'video/webm' => 'webm',
        'audio/ogg'  => 'ogg',  'application/ogg' => 'ogg',
    ];
    return $map[$mime] ?? null;
}

/**
 * 업로드 파일 검증 (bravoAnswerValidateUpload 패턴).
 * 성공: ['mime'=>, 'ext'=>, 'orig'=>], 실패: ['error'=>msg].
 */
function growthValidateAudioUpload(array $file, string $label): array {
    if (!isset($file['tmp_name']) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => "{$label} 음성 업로드에 실패했습니다. 파일을 다시 선택해주세요."];
    }
    $size = filesize($file['tmp_name']);
    if ($size === false || $size <= 0) {
        return ['error' => "{$label} 음성 파일이 비어 있습니다."];
    }
    if ($size > GROWTH_AUDIO_MAX_BYTES) {
        return ['error' => "{$label} 음성 파일이 20MB를 초과합니다."];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $ext = growthAudioExt($mime);
    if ($ext === null) {
        return ['error' => "{$label} 음성 형식을 지원하지 않습니다. (mp3/m4a/wav/webm/ogg)"];
    }
    return ['mime' => $mime, 'ext' => $ext, 'orig' => (string)($file['name'] ?? '')];
}

/** 파일명 안전 문자로 정규화. 순수. */
function growthSafeName(string $s): string {
    $s = preg_replace('#[\\\\/:*?"<>|.\s]+#u', '_', trim($s)) ?? '';
    $s = trim($s, '_');
    return $s === '' ? 'member' : mb_substr($s, 0, 40);
}

// ── 설정 ─────────────────────────────────────────────────────

function growthSettings(PDO $db): array {
    return [
        'enabled'  => getSystemContent($db, 'growth_record_enabled', 'off') === 'on',
        'deadline' => getSystemContent($db, 'growth_record_deadline', ''),
        'guide'    => getSystemContent($db, 'growth_record_guide', ''),
    ];
}

/** 접수 불가 사유. 가능하면 null. 순수($now 주입 가능). */
function growthClosedReason(array $settings, ?string $now = null): ?string {
    if (!$settings['enabled']) return 'disabled';
    $now = $now ?? date('Y-m-d H:i:s');
    if ($settings['deadline'] !== '' && $now > $settings['deadline']) return 'deadline_passed';
    return null;
}

// ── 조회 ─────────────────────────────────────────────────────

/** 제출 가능 회원 row (없거나 비활성/환불이면 null). */
function growthMemberRow(PDO $db, int $memberId): ?array {
    $stmt = $db->prepare("
        SELECT id, cohort_id, nickname, real_name, is_active, member_status
        FROM bootcamp_members WHERE id = ?
    ");
    $stmt->execute([$memberId]);
    $m = $stmt->fetch();
    if (!$m || (int)$m['is_active'] !== 1 || $m['member_status'] === 'refunded') return null;
    return $m;
}

/** 활성(미취소) 제출 1건. 없으면 null. */
function growthFindActive(PDO $db, int $memberId): ?array {
    $stmt = $db->prepare("
        SELECT * FROM growth_record_submissions
        WHERE member_id = ? AND cancelled_at IS NULL
    ");
    $stmt->execute([$memberId]);
    return $stmt->fetch() ?: null;
}

// ── 제출 코어 ─────────────────────────────────────────────────

/**
 * 제출. $before/$after: ['tmp'=>업로드tmp경로, 'mime'=>, 'ext'=>, 'orig'=>].
 * $viaUpload=true 면 move_uploaded_file, false(테스트)면 rename.
 * 원자성: ① staging 이동 → ② BEGIN → ③ INSERT(staging명) → ④ 최종 rename → ⑤ UPDATE → COMMIT.
 *         실패 시 rollback + 파일 삭제. COMMIT 직후 crash 의 고아 staging 파일은 무해.
 * 중복 방어: uq_active_member unique 키가 최종 방어 (23000 → '이미 제출').
 * 성공: ['submission'=>row], 실패: ['error'=>msg].
 */
function growthSubmit(PDO $db, array $member, string $url, bool $consent, array $before, array $after, bool $viaUpload = true): array {
    if (!$consent) return ['error' => '콘텐츠 활용 동의에 체크해야 제출할 수 있습니다.'];
    if (!isValidReviewUrl($url)) {
        return ['error' => 'URL 형식이 올바르지 않습니다. (https:// 로 시작하는 10~500자 링크)'];
    }
    $memberId = (int)$member['id'];
    if (growthFindActive($db, $memberId)) return ['error' => '이미 제출하셨습니다.'];

    $tmpDir = GROWTH_UPLOAD_ROOT . '/tmp';
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0750, true) && !is_dir($tmpDir)) {
        return ['error' => '저장 공간 오류입니다. 관리자에게 문의해주세요.'];
    }

    // ① staging — 업로드 tmp 는 요청 종료 시 소멸 + 최종 위치와 같은 FS 로 rename 보장
    $stage = [];
    foreach (['before' => $before, 'after' => $after] as $which => $f) {
        $t = $tmpDir . "/stage_{$memberId}_{$which}_" . bin2hex(random_bytes(4)) . '.' . $f['ext'];
        $moved = $viaUpload ? move_uploaded_file($f['tmp'], $t) : rename($f['tmp'], $t);
        if (!$moved) {
            foreach ($stage as $s) @unlink($s['path']);
            return ['error' => '음성 파일 저장에 실패했습니다. 다시 시도해주세요.'];
        }
        $stage[$which] = ['path' => $t] + $f;
    }

    $cStmt = $db->prepare("SELECT cohort FROM cohorts WHERE id = ?");
    $cStmt->execute([(int)$member['cohort_id']]);
    $cohortLabel = (string)($cStmt->fetchColumn() ?: 'cohort');

    $finals = [];
    $db->beginTransaction();
    try {
        // ③ INSERT (파일명은 staging명으로 선기록 — id 확보 후 ⑤에서 최종명 UPDATE)
        $db->prepare("
            INSERT INTO growth_record_submissions
                (member_id, cohort_id, url, before_file, after_file,
                 before_orig_name, after_orig_name, before_mime, after_mime, consent_agreed_at)
            VALUES (?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            $memberId, (int)$member['cohort_id'], $url,
            'tmp/' . basename($stage['before']['path']), 'tmp/' . basename($stage['after']['path']),
            mb_substr($stage['before']['orig'], 0, 255), mb_substr($stage['after']['orig'], 0, 255),
            $stage['before']['mime'], $stage['after']['mime'],
        ]);
        $id = (int)$db->lastInsertId();

        // ④ 최종 명명: {기수}_{닉네임}_{before|after}_{id}.{ext}
        $nick = growthSafeName($member['nickname'] ?: ($member['real_name'] ?: ('m' . $memberId)));
        foreach ($stage as $which => $s) {
            $name = growthSafeName($cohortLabel) . "_{$nick}_{$which}_{$id}." . $s['ext'];
            if (!rename($s['path'], GROWTH_UPLOAD_ROOT . '/' . $name)) {
                throw new RuntimeException('final file move failed');
            }
            $finals[$which] = $name;
        }

        // ⑤ 최종 파일명 기록
        $db->prepare("UPDATE growth_record_submissions SET before_file = ?, after_file = ? WHERE id = ?")
           ->execute([$finals['before'], $finals['after'], $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($stage as $s) @unlink($s['path']);
        foreach ($finals as $n) @unlink(GROWTH_UPLOAD_ROOT . '/' . $n);
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            return ['error' => '이미 제출하셨습니다.'];
        }
        throw $e;
    }

    $row = growthFindActive($db, $memberId);
    return ['submission' => $row];
}

// ── 취소 코어 ─────────────────────────────────────────────────

/**
 * 소프트 취소 (review_cancel 패턴, 코인 회수 없음). 파일은 디스크에 보존.
 * 성공: ['cancelled'=>true], 실패: ['error'=>msg].
 */
function growthCancel(PDO $db, int $id, int $adminId, string $reason): array {
    if ($reason === '' || mb_strlen($reason) > 255) return ['error' => '취소 사유는 1~255자여야 합니다.'];
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id, cancelled_at FROM growth_record_submissions WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) { $db->rollBack(); return ['error' => '해당 제출을 찾을 수 없습니다.']; }
        if ($row['cancelled_at'] !== null) { $db->rollBack(); return ['error' => '이미 취소된 제출입니다.']; }
        $db->prepare("
            UPDATE growth_record_submissions
               SET cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ?
             WHERE id = ? AND cancelled_at IS NULL
        ")->execute([$adminId, $reason, $id]);
        $db->commit();
        return ['cancelled' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// ── 스트리밍 ─────────────────────────────────────────────────

/**
 * HTTP Range 헤더 파싱 (bravo_grading.php bravoAudioRangeParse 복사 — bravo 의존 없이 독립).
 * 성공 [start, end], 미적용/비정상 null → 200 전체 폴백. 순수.
 */
function growthAudioRangeParse(?string $header, int $size): ?array {
    if ($header === null || $size <= 0) return null;
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) return null;
    if ($m[1] === '' && $m[2] === '') return null;
    if ($m[1] === '') {
        $len = (int)$m[2];
        if ($len <= 0) return null;
        $start = max(0, $size - $len);
        $end = $size - 1;
    } else {
        $start = (int)$m[1];
        $end = ($m[2] === '') ? $size - 1 : min((int)$m[2], $size - 1);
    }
    if ($start > $end || $start >= $size) return null;
    return [$start, $end];
}

/**
 * 음성 스트리밍 후 exit (admin.php bravo_answer_audio 패턴). 루트 밖 경로 방어.
 * $which: 'before'|'after'. $download=true 면 Content-Disposition attachment.
 */
function growthAudioStream(array $row, string $which, bool $download = false): void {
    $file = $which === 'before' ? $row['before_file'] : $row['after_file'];
    $mime = $which === 'before' ? $row['before_mime'] : $row['after_mime'];
    $path = GROWTH_UPLOAD_ROOT . '/' . $file;
    $real = realpath($path);
    $root = realpath(GROWTH_UPLOAD_ROOT);
    if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        jsonError('음성 파일이 없습니다.', 404);
    }
    $size = (int)filesize($real);
    if ($size < 1) jsonError('음성 파일이 없습니다.', 404);

    $range = growthAudioRangeParse($_SERVER['HTTP_RANGE'] ?? null, $size);
    // JSON 아님 — 바이너리 스트리밍 (라우터 상단의 JSON Content-Type 을 덮어씀)
    $common = function () use ($mime, $download, $file) {
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=3600');
        if ($download) header('Content-Disposition: attachment; filename="' . $file . '"');
    };
    if ($range !== null) {
        [$start, $end] = $range;
        $fp = fopen($real, 'rb');
        if ($fp === false) jsonError('음성 파일을 읽을 수 없습니다.', 500);
        $common();
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$size}");
        header('Content-Length: ' . ($end - $start + 1));
        fseek($fp, $start);
        echo fread($fp, $end - $start + 1);
        fclose($fp);
    } else {
        $common();
        header('Content-Length: ' . $size);
        readfile($real);
    }
    exit;
}

// ── HTTP 핸들러: 회원 ─────────────────────────────────────────

function handleMyGrowthRecordStatus() {
    $session = requireMember();
    $memberId = (int)$session['member_id'];
    $db = getDB();

    $settings = growthSettings($db);
    $member = growthMemberRow($db, $memberId);
    $active = $member ? growthFindActive($db, $memberId) : null;

    jsonSuccess([
        'eligible'      => $member !== null,
        'open'          => growthClosedReason($settings) === null,
        'closed_reason' => growthClosedReason($settings),
        'deadline'      => $settings['deadline'],
        'guide'         => $settings['guide'],
        'submitted'     => $active ? [
            'id'               => (int)$active['id'],
            'url'              => $active['url'],
            'before_orig_name' => $active['before_orig_name'],
            'after_orig_name'  => $active['after_orig_name'],
            'submitted_at'     => $active['submitted_at'],
        ] : null,
    ]);
}

function handleSubmitGrowthRecord($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $session = requireMember();
    $memberId = (int)$session['member_id'];
    $db = getDB();

    // multipart — getJsonInput 아님: $_POST + $_FILES
    $settings = growthSettings($db);
    $closed = growthClosedReason($settings);
    if ($closed !== null) {
        jsonError($closed === 'deadline_passed' ? '제출 기간이 마감되었습니다.' : '현재 접수 중이 아닙니다.');
    }

    $member = growthMemberRow($db, $memberId);
    if (!$member) jsonError('현재 제출이 불가능한 상태입니다.');

    $url = trim($_POST['url'] ?? '');
    $consent = ($_POST['consent'] ?? '') === '1';

    if (empty($_FILES['before_audio'])) jsonError('Before 음성 파일을 첨부해주세요.');
    if (empty($_FILES['after_audio']))  jsonError('After 음성 파일을 첨부해주세요.');
    $vb = growthValidateAudioUpload($_FILES['before_audio'], 'Before');
    if (isset($vb['error'])) jsonError($vb['error']);
    $va = growthValidateAudioUpload($_FILES['after_audio'], 'After');
    if (isset($va['error'])) jsonError($va['error']);

    $r = growthSubmit($db, $member, $url, $consent,
        ['tmp' => $_FILES['before_audio']['tmp_name']] + $vb,
        ['tmp' => $_FILES['after_audio']['tmp_name']] + $va,
        true);
    if (isset($r['error'])) jsonError($r['error']);

    $s = $r['submission'];
    jsonSuccess(['submission' => [
        'id'               => (int)$s['id'],
        'url'              => $s['url'],
        'before_orig_name' => $s['before_orig_name'],
        'after_orig_name'  => $s['after_orig_name'],
        'submitted_at'     => $s['submitted_at'],
    ]], '성장기록 제출이 완료되었습니다.');
}

/** 회원 본인 음성 재생 (?id=&which=before|after) */
function handleGrowthRecordAudio() {
    $session = requireMember();
    $id = (isset($_GET['id']) && is_numeric($_GET['id'])) ? (int)$_GET['id'] : 0;
    $which = $_GET['which'] ?? '';
    if ($id < 1 || !in_array($which, ['before', 'after'], true)) jsonError('id/which가 필요합니다.');
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM growth_record_submissions WHERE id = ? AND member_id = ?");
    $stmt->execute([$id, (int)$session['member_id']]);
    $row = $stmt->fetch();
    if (!$row) jsonError('제출을 찾을 수 없습니다.', 404);
    growthAudioStream($row, $which);
}

// ── HTTP 핸들러: 운영 ─────────────────────────────────────────

/** 운영진 음성 재생/다운로드 (?id=&which=&download=1) */
function handleGrowthRecordAudioAdmin() {
    requireAdmin(GROWTH_ADMIN_ROLES);
    $id = (isset($_GET['id']) && is_numeric($_GET['id'])) ? (int)$_GET['id'] : 0;
    $which = $_GET['which'] ?? '';
    if ($id < 1 || !in_array($which, ['before', 'after'], true)) jsonError('id/which가 필요합니다.');
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM growth_record_submissions WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('제출을 찾을 수 없습니다.', 404);
    growthAudioStream($row, $which, !empty($_GET['download']));
}

function handleGrowthRecordsList() {
    requireAdmin(GROWTH_ADMIN_ROLES);
    $db = getDB();
    $cohortId = (int)($_GET['cohort_id'] ?? 0);
    $status   = $_GET['status'] ?? 'active'; // active | cancelled | all
    $q        = trim($_GET['q'] ?? '');

    $commonWhere = '1=1';
    $commonParams = [];
    if ($cohortId > 0) { $commonWhere .= " AND gs.cohort_id = ?"; $commonParams[] = $cohortId; }
    if ($q !== '') {
        $commonWhere .= " AND (bm.nickname LIKE ? OR bm.real_name LIKE ?)";
        $commonParams[] = "%{$q}%";
        $commonParams[] = "%{$q}%";
    }

    $cStmt = $db->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN gs.cancelled_at IS NULL THEN 1 ELSE 0 END) AS active_cnt,
               SUM(CASE WHEN gs.cancelled_at IS NOT NULL THEN 1 ELSE 0 END) AS cancelled_cnt
        FROM growth_record_submissions gs
        JOIN bootcamp_members bm ON bm.id = gs.member_id
        WHERE {$commonWhere}
    ");
    $cStmt->execute($commonParams);
    $c = $cStmt->fetch();

    $where = $commonWhere;
    $params = $commonParams;
    if ($status === 'active')        $where .= " AND gs.cancelled_at IS NULL";
    elseif ($status === 'cancelled') $where .= " AND gs.cancelled_at IS NOT NULL";

    $stmt = $db->prepare("
        SELECT gs.id, gs.url, gs.before_orig_name, gs.after_orig_name,
               gs.consent_agreed_at, gs.submitted_at, gs.cancelled_at, gs.cancel_reason,
               bm.id AS member_id, bm.nickname, bm.real_name,
               bg.name AS group_name, c.cohort AS cohort_label,
               a.name AS cancelled_by_name
        FROM growth_record_submissions gs
        JOIN bootcamp_members bm ON bm.id = gs.member_id
        JOIN cohorts c ON c.id = gs.cohort_id
        LEFT JOIN bootcamp_groups bg ON bg.id = bm.group_id
        LEFT JOIN admins a ON a.id = gs.cancelled_by
        WHERE {$where}
        ORDER BY gs.submitted_at DESC
    ");
    $stmt->execute($params);

    jsonSuccess([
        'counts' => [
            'total'     => (int)($c['total'] ?? 0),
            'active'    => (int)($c['active_cnt'] ?? 0),
            'cancelled' => (int)($c['cancelled_cnt'] ?? 0),
        ],
        'items' => $stmt->fetchAll(),
    ]);
}

function handleGrowthRecordCancel($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(GROWTH_ADMIN_ROLES);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id가 필요합니다.');
    $db = getDB();
    $r = growthCancel($db, $id, (int)$admin['admin_id'], trim($input['cancel_reason'] ?? ''));
    if (isset($r['error'])) jsonError($r['error']);
    jsonSuccess([], '제출이 취소되었습니다. 회원은 다시 제출할 수 있습니다.');
}

function handleGrowthRecordSettingsGet() {
    requireAdmin(GROWTH_ADMIN_ROLES);
    $db = getDB();
    $s = growthSettings($db);
    jsonSuccess([
        'enabled'  => $s['enabled'],
        'deadline' => $s['deadline'],
        'guide'    => $s['guide'],
    ]);
}

function handleGrowthRecordSettingsUpdate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(GROWTH_ADMIN_ROLES);
    $input = getJsonInput();
    $key = trim($input['key'] ?? '');
    $value = $input['value'] ?? null;

    $allowed = ['growth_record_enabled', 'growth_record_deadline', 'growth_record_guide'];
    if (!in_array($key, $allowed, true)) jsonError('허용되지 않은 key입니다.');
    if (!is_string($value)) jsonError('value는 문자열이어야 합니다.');

    if ($key === 'growth_record_enabled') {
        if (!in_array($value, ['on', 'off'], true)) jsonError('enabled 값은 on/off여야 합니다.');
    } elseif ($key === 'growth_record_deadline') {
        $d = DateTime::createFromFormat('Y-m-d H:i:s', $value);
        if (!$d || $d->format('Y-m-d H:i:s') !== $value) jsonError('마감일은 YYYY-MM-DD HH:MM:SS 형식이어야 합니다.');
    } else {
        if (mb_strlen($value) > 5000) jsonError('가이드는 5000자 이내여야 합니다.');
    }

    $db = getDB();
    $db->prepare("
        INSERT INTO system_contents (content_key, content_markdown) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE content_markdown = VALUES(content_markdown)
    ")->execute([$key, $value]);
    jsonSuccess(['key' => $key, 'value' => $value], '저장되었습니다.');
}
```

- [ ] **Step 2: lint**

Run: `php -l /root/boot-dev/public_html/api/services/growth_record.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: 단위 테스트 작성**

`tests/growth_record_test.php`:

```php
<?php
/**
 * Growth Record 서비스 단위 테스트.
 * 사용: cd /root/boot-dev && php tests/growth_record_test.php
 *
 * DEV DB 에 임시 cohort/member 를 만들고 마지막에 삭제 (파일 I/O 가 있어 트랜잭션 wrap 불가).
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/review.php';        // getSystemContent, isValidReviewUrl
require_once __DIR__ . '/../public_html/api/services/growth_record.php';

$db = getDB();
$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

/** 테스트용 가짜 음성 파일을 staging 가능한 위치(같은 FS)에 생성 */
function makeAudio(string $ext): array {
    $tmpDir = GROWTH_UPLOAD_ROOT . '/tmp';
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0750, true);
    $p = $tmpDir . '/src_' . bin2hex(random_bytes(4)) . '.' . $ext;
    file_put_contents($p, str_repeat('x', 100));
    return ['tmp' => $p, 'mime' => 'audio/mpeg', 'ext' => $ext, 'orig' => "원본 {$ext}.{$ext}"];
}

// ── 순수 함수 ──
t('ext: mp3', growthAudioExt('audio/mpeg') === 'mp3');
t('ext: m4a video/mp4', growthAudioExt('video/mp4') === 'm4a');
t('ext: x-m4a', growthAudioExt('audio/x-m4a') === 'm4a');
t('ext: wav', growthAudioExt('audio/x-wav') === 'wav');
t('ext: unsupported', growthAudioExt('application/pdf') === null);
t('safeName strips path chars', growthSafeName('a/b\\c:d 김 효주') === 'a_b_c_d_김_효주');
t('safeName empty fallback', growthSafeName('///') === 'member');
t('closed: disabled', growthClosedReason(['enabled' => false, 'deadline' => '']) === 'disabled');
t('closed: deadline', growthClosedReason(['enabled' => true, 'deadline' => '2026-06-16 23:59:59'], '2026-06-17 00:00:00') === 'deadline_passed');
t('closed: open before deadline', growthClosedReason(['enabled' => true, 'deadline' => '2026-06-16 23:59:59'], '2026-06-16 23:59:59') === null);
t('closed: open no deadline', growthClosedReason(['enabled' => true, 'deadline' => '']) === null);
t('range: full', growthAudioRangeParse('bytes=0-', 100) === [0, 99]);
t('range: null', growthAudioRangeParse(null, 100) === null);

// ── Fixture ──
$db->exec("INSERT INTO cohorts (cohort, start_date, end_date) VALUES ('__GRT기', '2026-01-01', '2026-12-31')");
$cohortId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO bootcamp_members (cohort_id, nickname, real_name, is_active) VALUES (?, '__grt닉', '__grt명', 1)")
   ->execute([$cohortId]);
$memberId = (int)$db->lastInsertId();
$member = growthMemberRow($db, $memberId);
$cleanFiles = [];

try {
    t('memberRow active ok', $member !== null);

    // consent 미동의 거부
    $r = growthSubmit($db, $member, 'https://blog.naver.com/test12345', false, makeAudio('mp3'), makeAudio('mp3'), false);
    t('submit: consent required', isset($r['error']) && str_contains($r['error'], '동의'));

    // URL 검증
    $r = growthSubmit($db, $member, 'ftp://bad', true, makeAudio('mp3'), makeAudio('mp3'), false);
    t('submit: bad url', isset($r['error']) && str_contains($r['error'], 'URL'));

    // happy path
    $b = makeAudio('mp3'); $a = makeAudio('m4a');
    $r = growthSubmit($db, $member, 'https://blog.naver.com/test12345', true, $b, $a, false);
    t('submit: ok', isset($r['submission']), $r['error'] ?? '');
    $sub = $r['submission'] ?? null;
    if ($sub) {
        $cleanFiles[] = GROWTH_UPLOAD_ROOT . '/' . $sub['before_file'];
        $cleanFiles[] = GROWTH_UPLOAD_ROOT . '/' . $sub['after_file'];
        t('submit: final filename has id', str_contains($sub['before_file'], "_before_{$sub['id']}."));
        t('submit: file exists', is_file(GROWTH_UPLOAD_ROOT . '/' . $sub['before_file']));
        t('submit: orig name kept', $sub['before_orig_name'] === '원본 mp3.mp3');
        t('submit: consent stamped', !empty($sub['consent_agreed_at']));
        t('submit: staging cleaned', !is_file($b['tmp']) ); // staging 에서 최종으로 이동됨
    }

    // 중복 제출 (앱 레벨)
    $r = growthSubmit($db, $member, 'https://blog.naver.com/test12345', true, makeAudio('mp3'), makeAudio('mp3'), false);
    t('submit: duplicate blocked', isset($r['error']) && str_contains($r['error'], '이미 제출'));

    // 중복 제출 (DB unique — raw INSERT 가 23000)
    $dupErr = null;
    try {
        $db->prepare("
            INSERT INTO growth_record_submissions
                (member_id, cohort_id, url, before_file, after_file,
                 before_orig_name, after_orig_name, before_mime, after_mime, consent_agreed_at)
            VALUES (?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([$memberId, $cohortId, 'https://x.test/aa', 'x', 'y', 'x', 'y', 'audio/mpeg', 'audio/mpeg']);
    } catch (PDOException $e) { $dupErr = $e->getCode(); }
    t('db unique: duplicate active rejected', $dupErr === '23000');

    // 취소 → 재제출 가능
    $admin = $db->query("SELECT id FROM admins LIMIT 1")->fetch();
    $adminId = (int)($admin['id'] ?? 0);
    t('fixture: admin exists', $adminId > 0);
    $r = growthCancel($db, (int)$sub['id'], $adminId, '테스트 취소');
    t('cancel: ok', isset($r['cancelled']));
    $r = growthCancel($db, (int)$sub['id'], $adminId, '재취소');
    t('cancel: double cancel blocked', isset($r['error']));
    t('cancel: file preserved', is_file(GROWTH_UPLOAD_ROOT . '/' . $sub['before_file']));

    $r2 = growthSubmit($db, $member, 'https://blog.naver.com/retry9999', true, makeAudio('mp3'), makeAudio('mp3'), false);
    t('resubmit after cancel: ok', isset($r2['submission']), $r2['error'] ?? '');
    if (isset($r2['submission'])) {
        $cleanFiles[] = GROWTH_UPLOAD_ROOT . '/' . $r2['submission']['before_file'];
        $cleanFiles[] = GROWTH_UPLOAD_ROOT . '/' . $r2['submission']['after_file'];
    }

    // 비활성 회원
    $db->prepare("UPDATE bootcamp_members SET is_active = 0 WHERE id = ?")->execute([$memberId]);
    t('memberRow inactive null', growthMemberRow($db, $memberId) === null);
} finally {
    // ── Cleanup ──
    $db->prepare("DELETE FROM growth_record_submissions WHERE member_id = ?")->execute([$memberId]);
    $db->prepare("DELETE FROM bootcamp_members WHERE id = ?")->execute([$memberId]);
    $db->prepare("DELETE FROM cohorts WHERE id = ?")->execute([$cohortId]);
    foreach ($cleanFiles as $f) @unlink($f);
    foreach (glob(GROWTH_UPLOAD_ROOT . '/tmp/src_*') ?: [] as $f) @unlink($f);
    foreach (glob(GROWTH_UPLOAD_ROOT . '/tmp/stage_*') ?: [] as $f) @unlink($f);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
```

주의: `bootcamp_members` INSERT 컬럼이 실제 스키마와 맞는지 실행 전 확인 (`cohort_id, nickname, real_name, is_active` 외 NOT NULL DEFAULT 없는 컬럼이 있으면 추가). `cohorts` INSERT도 동일 (`code` 컬럼이 NOT NULL이면 `code` 값 추가).

- [ ] **Step 4: 테스트 실행 (apache 권한 주의)**

growth_uploads 가 `apache:apache 750` 이므로 root 로 실행하면 통과하지만 실제 웹 경로와 권한이 다름. 테스트는 root 실행으로 충분 (파일 권한은 Task 6 smoke 에서 확인):

Run: `cd /root/boot-dev && php tests/growth_record_test.php`
Expected: 전부 PASS, exit 0. FAIL 시 스키마/픽스처 컬럼부터 확인.

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/api/services/growth_record.php tests/growth_record_test.php
git commit -m "feat: 성장기록 서비스 코어 (제출/취소/스트리밍) + 단위 테스트"
```

---

### Task 3: 라우팅 (bootcamp.php)

**Files:**
- Modify: `public_html/api/bootcamp.php`

- [ ] **Step 1: require 추가**

`require_once __DIR__ . '/services/review.php';` (31행 근처) **다음 줄에** 추가 (getSystemContent 의존 순서):

```php
require_once __DIR__ . '/services/growth_record.php';
```

- [ ] **Step 2: 라우팅 case 추가**

`// ── Review Submissions ──` 블록(364-371행 근처) 바로 아래에 추가:

```php
// ── Growth Records (성장기록 — 후기+음성, 기존 후기 대체) ────

case 'my_growth_record_status':          handleMyGrowthRecordStatus(); break;
case 'submit_growth_record':             handleSubmitGrowthRecord($method); break;
case 'growth_record_audio':              handleGrowthRecordAudio(); break;
case 'growth_record_audio_admin':        handleGrowthRecordAudioAdmin(); break;
case 'growth_records_list':              handleGrowthRecordsList(); break;
case 'growth_record_cancel':             handleGrowthRecordCancel($method); break;
case 'growth_record_settings':           handleGrowthRecordSettingsGet(); break;
case 'growth_record_settings_save':      handleGrowthRecordSettingsUpdate($method); break;
```

- [ ] **Step 3: lint + 라우팅 smoke**

Run: `php -l /root/boot-dev/public_html/api/bootcamp.php`
Expected: `No syntax errors detected`

Run: `curl -s 'https://dev-boot.soritune.com/api/bootcamp.php?action=my_growth_record_status'`
Expected: `{"success":false,"error":"로그인이 필요합니다."...}` (401 JSON — 액션이 라우팅됨. `Unknown action`이면 case 미등록)

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && git add public_html/api/bootcamp.php
git commit -m "feat: 성장기록 API 라우팅 등록"
```

---

### Task 4: 회원 화면

**Files:**
- Create: `public_html/js/member-growth-record.js`
- Modify: `public_html/js/member.js` (217-218행 area div, 268-288행 open/close, 301행 export)
- Modify: `public_html/js/member-shortcuts.js` (47-63행 후기 카드 블록 교체)
- Modify: `public_html/index.php` (43행 근처 script 태그)
- Modify: `public_html/css/member.css` (끝에 추가)

- [ ] **Step 1: member-growth-record.js 작성**

```js
/* ══════════════════════════════════════════════════════════════
   MemberGrowthRecord — 성장기록 제출 화면 (기존 후기 작성 대체)
   바로가기의 "성장기록 제출" 카드를 탭하면 진입, "← 뒤로"로 복귀.
   스펙: docs/superpowers/specs/2026-06-11-growth-record-submission-design.md
   ══════════════════════════════════════════════════════════════ */
const MemberGrowthRecord = (() => {
    const API = '/api/bootcamp.php?action=';

    async function render(root, onBack) {
        root.innerHTML = `
            <div class="growth-submit-page">
                <div class="growth-submit-header">
                    <button class="growth-submit-back" id="growth-submit-back-btn">← 뒤로</button>
                    <div class="growth-submit-title">성장기록 제출</div>
                </div>
                <div class="growth-submit-body" id="growth-submit-body">
                    <div class="growth-submit-loading">불러오는 중…</div>
                </div>
            </div>
        `;
        document.getElementById('growth-submit-back-btn').onclick = onBack;
        await load();
    }

    async function load() {
        const body = document.getElementById('growth-submit-body');
        const r = await App.get(API + 'my_growth_record_status');
        if (!r.success) {
            body.innerHTML = '<div class="growth-submit-empty">정보를 불러오지 못했습니다.</div>';
            return;
        }

        // 안내 가이드 — renderMarkdown 은 이스케이프 없이 렌더하는 운영진 신뢰 모델
        // (편집 권한이 admin 가이드 설정으로 한정 — 기존 review_guide 와 동일)
        const guideHtml = (typeof MemberHome !== 'undefined' && typeof MemberHome.renderMarkdown === 'function')
            ? MemberHome.renderMarkdown(r.guide || '')
            : `<pre>${App.esc(r.guide || '')}</pre>`;
        const guideBlock = `
            <details class="growth-guide-top" open>
                <summary>성장기록 미션 안내</summary>
                <div class="growth-guide-body">${guideHtml}</div>
            </details>
        `;

        if (r.submitted) {
            body.innerHTML = guideBlock + renderDone(r.submitted);
            return;
        }
        if (!r.eligible) {
            body.innerHTML = `<div class="growth-submit-empty">현재 제출이 불가능한 상태입니다.</div>`;
            return;
        }
        if (!r.open) {
            const msg = r.closed_reason === 'deadline_passed'
                ? '제출 기간이 마감되었습니다.' : '현재 접수 중이 아닙니다.';
            body.innerHTML = guideBlock + `<div class="growth-submit-empty">${App.esc(msg)}</div>`;
            return;
        }

        body.innerHTML = guideBlock + renderForm();
        attachHandlers();
    }

    function renderDone(s) {
        const d = (s.submitted_at || '').slice(0, 16);
        return `
            <div class="growth-done">
                <div class="growth-done-badge">✓ 제출 완료 · ${App.esc(d)}</div>
                <div class="growth-done-msg">
                    성장기록 제출이 완료되었습니다.<br>
                    소중한 후기와 음성 파일을 남겨주셔서 감사합니다.<br>
                    VOD 5주 연장은 제출 데이터 취합 후 <strong>2026년 6월 17일 수요일</strong>에 일괄 반영될 예정입니다.
                </div>
                <div class="growth-done-detail">
                    <div>후기 링크: <a href="${App.esc(s.url)}" target="_blank" rel="noopener noreferrer">${App.esc(s.url)}</a></div>
                    <div>Before 음성: ${App.esc(s.before_orig_name)}
                        <audio controls preload="none" src="${API}growth_record_audio&id=${s.id}&which=before"></audio></div>
                    <div>After 음성: ${App.esc(s.after_orig_name)}
                        <audio controls preload="none" src="${API}growth_record_audio&id=${s.id}&which=after"></audio></div>
                </div>
            </div>
        `;
    }

    function renderForm() {
        return `
            <div class="growth-form">
                <label class="growth-form-label" for="growth-url">후기 링크 (카페/블로그)</label>
                <input type="url" class="growth-form-input" id="growth-url"
                       placeholder="https://..." maxlength="500" autocomplete="off">

                <label class="growth-form-label">Before 음성 파일</label>
                <input type="file" id="growth-before" accept="audio/*,.mp3,.m4a,.wav,.webm,.ogg">

                <label class="growth-form-label">After 음성 파일</label>
                <input type="file" id="growth-after" accept="audio/*,.mp3,.m4a,.wav,.webm,.ogg">

                <div class="growth-consent-box">
                    🏅 <strong>안내 사항</strong>: 제출해 주신 소중한 후기와 음성 파일은 <strong>'베스트 성장러'</strong>로
                    선정될 경우, 소리튠영어 공식 블로그 및 카페에 우수회원 성장 스토리 콘텐츠로 감사히 재가공되어
                    활용될 예정입니다. 소리튠의 스타가 되어있을 기회! 많은 참여 부탁드립니다.
                </div>
                <label class="growth-consent-check">
                    <input type="checkbox" id="growth-consent">
                    <span>위 안내 사항을 확인했으며, 제출한 후기와 음성 파일이 베스트 성장러 콘텐츠로
                    활용될 수 있음에 동의합니다. <em>(필수)</em></span>
                </label>

                <button class="btn btn-primary growth-form-submit" id="growth-submit-btn" disabled>제출하기</button>
                <div class="growth-form-hint">음성 파일은 mp3/m4a/wav/webm/ogg, 개당 20MB까지 업로드할 수 있어요.</div>
            </div>
        `;
    }

    function attachHandlers() {
        const consent = document.getElementById('growth-consent');
        const btn = document.getElementById('growth-submit-btn');
        consent.addEventListener('change', () => { btn.disabled = !consent.checked; });

        btn.addEventListener('click', async () => {
            const url = (document.getElementById('growth-url').value || '').trim();
            const before = document.getElementById('growth-before').files[0];
            const after = document.getElementById('growth-after').files[0];

            if (!/^https?:\/\//i.test(url) || url.length < 10 || url.length > 500) {
                Toast.error('후기 링크 형식이 올바르지 않습니다 (https://로 시작, 10~500자).');
                return;
            }
            if (!before) { Toast.error('Before 음성 파일을 선택해주세요.'); return; }
            if (!after)  { Toast.error('After 음성 파일을 선택해주세요.'); return; }
            const MAX = 20 * 1024 * 1024;
            if (before.size > MAX || after.size > MAX) {
                Toast.error('음성 파일은 개당 20MB 이하여야 합니다.');
                return;
            }
            if (!consent.checked) { Toast.error('동의에 체크해주세요.'); return; }

            btn.disabled = true;
            btn.textContent = '업로드 중… (수십 초 걸릴 수 있어요)';
            try {
                const fd = new FormData();
                fd.append('url', url);
                fd.append('consent', '1');
                fd.append('before_audio', before);
                fd.append('after_audio', after);
                const res = await fetch(API + 'submit_growth_record', { method: 'POST', body: fd });
                const r = await res.json();
                if (r.success) {
                    Toast.success(r.message || '제출이 완료되었습니다.');
                    await load();
                } else {
                    Toast.error(r.error || r.message || '제출에 실패했습니다.');
                    btn.disabled = false;
                    btn.textContent = '제출하기';
                }
            } catch (_e) {
                Toast.error('네트워크 오류 — 다시 시도해주세요.');
                btn.disabled = false;
                btn.textContent = '제출하기';
            }
        });
    }

    return { render };
})();
```

- [ ] **Step 2: member.js 수정**

217-218행 근처, `member-review-submit-area` div 아래에 추가:

```js
                <div id="member-growth-record-area" style="display:none"></div>
```

`closeReviewSubmit` 함수(278-288행) 아래에 추가:

```js
    function openGrowthRecord() {
        const dashboardContent = root.querySelector('.member-content');
        const area = document.getElementById('member-growth-record-area');
        if (!area) return;
        dashboardContent.style.display = 'none';
        area.style.display = '';
        window.scrollTo({ top: 0, behavior: 'instant' });
        MemberGrowthRecord.render(area, closeGrowthRecord);
    }

    function closeGrowthRecord() {
        const dashboardContent = root.querySelector('.member-content');
        const area = document.getElementById('member-growth-record-area');
        if (!area) return;
        area.style.display = 'none';
        area.innerHTML = '';
        dashboardContent.style.display = '';
        window.scrollTo({ top: 0, behavior: 'instant' });
    }
```

301행 export에 추가:

```js
    return { init, openCoinHistory, closeCoinHistory, openReviewSubmit, closeReviewSubmit, openGrowthRecord, closeGrowthRecord, refreshMember };
```

- [ ] **Step 3: member-shortcuts.js — 후기 카드를 성장기록 카드로 교체**

47-63행의 `// 2) 후기 카드 조건부 prepend` try 블록 전체를 다음으로 교체 (기존 후기 카드 제거 = 회원 진입점 제거):

```js
        // 2) 성장기록 카드 조건부 prepend (기존 후기 카드 대체)
        try {
            const r = await App.get(API + 'my_growth_record_status');
            if (!r.success || !r.eligible) return;
            // 접수 중이거나 이미 제출했으면 노출 (제출 후에도 내역 확인 진입 유지)
            if (!r.open && !r.submitted) return;

            const label = r.submitted ? '성장기록 제출 내역 보기' : '성장기록 제출하고 VOD 5주 연장 받기';
            const growthBtn = `<button class="shortcut-btn shortcut-btn--amber" id="shortcut-growth-record" type="button">
                <span class="shortcut-label">${App.esc(label)}</span>
                <span class="shortcut-arrow">&#8250;</span>
            </button>`;
            const inner = document.getElementById('shortcuts-list-inner');
            if (inner) inner.insertAdjacentHTML('afterbegin', growthBtn);

            const btn = document.getElementById('shortcut-growth-record');
            if (btn) btn.addEventListener('click', () => MemberApp.openGrowthRecord());
        } catch (_e) { /* 네트워크/권한 에러는 조용히 무시 — 카드만 안 보임 */ }
```

- [ ] **Step 4: index.php script 태그**

43행 `member-review.js` 줄 다음에 추가:

```php
    <script src="/js/member-growth-record.js<?= v('/js/member-growth-record.js') ?>"></script>
```

- [ ] **Step 5: css/member.css 끝에 스타일 추가**

```css
/* ── 성장기록 제출 (member-growth-record.js) ───────────────── */
.growth-submit-page { padding: 16px; }
.growth-submit-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.growth-submit-back { background: none; border: none; color: var(--c-primary, #4f46e5); font-size: 15px; cursor: pointer; padding: 4px 0; }
.growth-submit-title { font-size: 18px; font-weight: 700; }
.growth-submit-loading, .growth-submit-empty { padding: 32px 0; text-align: center; color: #888; }
.growth-guide-top { margin-bottom: 16px; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 14px; background: #fafafa; }
.growth-guide-top summary { font-weight: 700; cursor: pointer; }
.growth-guide-body { margin-top: 8px; font-size: 14px; line-height: 1.6; }
.growth-form { display: flex; flex-direction: column; gap: 10px; }
.growth-form-label { font-weight: 600; font-size: 14px; margin-top: 6px; }
.growth-form-input { padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
.growth-consent-box { background: #fffbe8; border: 1px solid #f5d77a; border-radius: 10px; padding: 12px 14px; font-size: 13px; line-height: 1.6; margin-top: 8px; }
.growth-consent-check { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; line-height: 1.5; }
.growth-consent-check input { margin-top: 2px; flex: none; }
.growth-form-submit { margin-top: 8px; }
.growth-form-submit:disabled { opacity: 0.5; cursor: not-allowed; }
.growth-form-hint { font-size: 12px; color: #888; }
.growth-done { border: 1px solid #d1fadf; background: #f0fdf4; border-radius: 10px; padding: 16px; }
.growth-done-badge { font-weight: 700; color: #15803d; margin-bottom: 8px; }
.growth-done-msg { font-size: 14px; line-height: 1.7; margin-bottom: 12px; }
.growth-done-detail { font-size: 13px; display: flex; flex-direction: column; gap: 8px; word-break: break-all; }
.growth-done-detail audio { display: block; width: 100%; margin-top: 4px; }
```

- [ ] **Step 6: 수동 검증 (DEV)**

1. `node --check /root/boot-dev/public_html/js/member-growth-record.js && node --check /root/boot-dev/public_html/js/member.js && node --check /root/boot-dev/public_html/js/member-shortcuts.js` — 문법 OK
2. https://dev-boot.soritune.com 회원 로그인 → 바로가기에 "성장기록 제출하고 VOD 5주 연장 받기" 카드 (기존 "후기 작성하기" 카드는 없어야 함)
3. 카드 탭 → 가이드 + 폼. 동의 미체크 시 제출 버튼 비활성
4. 링크 + mp3 2개 + 동의 체크 → 제출 → 완료 메시지(6/17 일괄 반영 문구) + 본인 음성 재생 확인
5. 새로고침 → 카드 라벨 "성장기록 제출 내역 보기", 진입 시 제출 내역 표시

- [ ] **Step 7: Commit**

```bash
cd /root/boot-dev && git add public_html/js/member-growth-record.js public_html/js/member.js public_html/js/member-shortcuts.js public_html/index.php public_html/css/member.css
git commit -m "feat: 회원 성장기록 제출 화면 (기존 후기 카드 대체)"
```

---

### Task 5: Admin 화면

**Files:**
- Create: `public_html/js/admin-growth-records.js`
- Modify: `public_html/js/admin.js` (201행 탭 버튼, 227행 컨테이너, 460행 근처 lazy init)
- Modify: `public_html/operation/index.php` (50행 근처 script 태그)
- Modify: `public_html/css/admin.css` (끝에 추가)

- [ ] **Step 1: admin-growth-records.js 작성**

```js
/* ══════════════════════════════════════════════════════════════
   AdminGrowthRecords — /operation 성장기록 탭
   제출 목록(음성 재생/다운로드, 동의 여부) + 취소 + 접수/마감/가이드 설정.
   스펙: docs/superpowers/specs/2026-06-11-growth-record-submission-design.md
   ══════════════════════════════════════════════════════════════ */
const AdminGrowthRecords = (() => {
    const API = '/api/bootcamp.php?action=';

    let state = { cohorts: [], cohortId: 0, status: 'active', q: '' };

    async function init(container) {
        container.innerHTML = `
            <div class="admin-growth-page">
                <div class="admin-growth-settings" id="agr-settings">
                    <div class="agr-settings-title">접수 설정</div>
                    <div class="agr-settings-row">
                        <label class="agr-toggle">
                            <input type="checkbox" id="agr-toggle-enabled" disabled>
                            <span>성장기록 접수</span>
                        </label>
                        <label>마감: <input type="text" id="agr-deadline" placeholder="2026-06-16 23:59:59" size="20"></label>
                        <button class="btn btn-small btn-secondary" id="agr-deadline-save">마감 저장</button>
                        <button class="btn btn-small btn-secondary" id="agr-guide-edit">가이드 수정</button>
                        <span class="agr-settings-hint">마감 이후/접수 OFF 시 회원 제출이 차단됩니다.</span>
                    </div>
                </div>
                <div class="admin-growth-filters">
                    <label>기수: <select id="agr-cohort"><option value="0">전체</option></select></label>
                    <label>상태:
                        <select id="agr-status">
                            <option value="active">활성</option>
                            <option value="cancelled">취소됨</option>
                            <option value="all">전체</option>
                        </select>
                    </label>
                    <label>닉네임: <input type="text" id="agr-q" placeholder="검색"></label>
                    <button class="btn btn-secondary" id="agr-reload">조회</button>
                </div>
                <div class="admin-growth-counts" id="agr-counts"></div>
                <div class="admin-growth-list" id="agr-list"></div>
            </div>
        `;

        await loadSettings();

        const cr = await App.get(API + 'cohorts');
        state.cohorts = cr.cohorts || [];
        const sel = document.getElementById('agr-cohort');
        sel.innerHTML = '<option value="0">전체</option>' + state.cohorts.map(c =>
            `<option value="${c.id}">${App.esc(c.cohort)}</option>`).join('');

        sel.onchange = () => { state.cohortId = parseInt(sel.value); load(); };
        document.getElementById('agr-status').onchange = (e) => { state.status = e.target.value; load(); };
        document.getElementById('agr-reload').onclick = () => {
            state.q = document.getElementById('agr-q').value.trim();
            load();
        };
        document.getElementById('agr-q').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') document.getElementById('agr-reload').click();
        });

        await load();
    }

    async function loadSettings() {
        const toggle = document.getElementById('agr-toggle-enabled');
        const deadline = document.getElementById('agr-deadline');
        const r = await App.get(API + 'growth_record_settings');
        if (!r.success) { toggle.disabled = true; return; }
        toggle.checked = !!r.enabled;
        toggle.disabled = false;
        deadline.value = r.deadline || '';

        toggle.onchange = async () => {
            toggle.disabled = true;
            const res = await App.post(API + 'growth_record_settings_save',
                { key: 'growth_record_enabled', value: toggle.checked ? 'on' : 'off' });
            toggle.disabled = false;
            if (res.success) Toast.success(`접수: ${toggle.checked ? 'ON' : 'OFF'}`);
            else { toggle.checked = !toggle.checked; Toast.error(res.error || '저장 실패'); }
        };
        document.getElementById('agr-deadline-save').onclick = async () => {
            const res = await App.post(API + 'growth_record_settings_save',
                { key: 'growth_record_deadline', value: deadline.value.trim() });
            if (res.success) Toast.success('마감일 저장됨');
            else Toast.error(res.error || '저장 실패');
        };
        document.getElementById('agr-guide-edit').onclick = () => showGuideModal(r.guide || '');
    }

    function showGuideModal(guide) {
        App.openModal('성장기록 가이드 수정', `
            <div class="agr-guide-modal">
                <textarea id="agr-guide-text" rows="16" class="form-input" maxlength="5000">${App.esc(guide)}</textarea>
                <div class="agr-guide-actions">
                    <button class="btn btn-secondary" id="agr-guide-close">닫기</button>
                    <button class="btn btn-primary" id="agr-guide-save">저장</button>
                </div>
            </div>
        `);
        document.getElementById('agr-guide-close').onclick = () => App.closeModal();
        document.getElementById('agr-guide-save').onclick = async () => {
            const value = document.getElementById('agr-guide-text').value;
            const res = await App.post(API + 'growth_record_settings_save',
                { key: 'growth_record_guide', value });
            if (res.success) { Toast.success('가이드 저장됨'); App.closeModal(); }
            else Toast.error(res.error || '저장 실패');
        };
    }

    async function load() {
        const list = document.getElementById('agr-list');
        const counts = document.getElementById('agr-counts');
        list.innerHTML = '<div class="empty-state">조회 중…</div>';

        const params = new URLSearchParams({ status: state.status });
        if (state.cohortId) params.set('cohort_id', String(state.cohortId));
        if (state.q) params.set('q', state.q);

        const r = await App.get(API + 'growth_records_list&' + params.toString());
        if (!r.success) {
            list.innerHTML = '<div class="empty-state">조회 실패</div>';
            counts.innerHTML = '';
            return;
        }

        counts.innerHTML = `전체 ${r.counts.total} · 활성 ${r.counts.active} · 취소 ${r.counts.cancelled}`;
        if (!r.items.length) {
            list.innerHTML = '<div class="empty-state">제출된 성장기록이 없습니다.</div>';
            return;
        }

        list.innerHTML = `
            <table class="admin-growth-table">
                <thead>
                    <tr>
                        <th>제출일</th><th>기수</th><th>조</th><th>닉네임</th><th>후기</th>
                        <th>Before</th><th>After</th><th>동의</th><th>상태</th><th></th>
                    </tr>
                </thead>
                <tbody>${r.items.map(renderRow).join('')}</tbody>
            </table>
        `;

        list.querySelectorAll('.agr-cancel-btn[data-id]').forEach(btn => {
            btn.addEventListener('click', () => showCancelModal(btn.dataset));
        });
    }

    function audioCell(id, which) {
        const src = `${API}growth_record_audio_admin&id=${id}&which=${which}`;
        return `<audio controls preload="none" class="agr-audio" src="${src}"></audio>
                <a href="${src}&download=1" title="다운로드">⬇</a>`;
    }

    function renderRow(item) {
        const submitted = (item.submitted_at || '').slice(5, 16);
        const cancelled = !!item.cancelled_at;
        const statusCell = cancelled
            ? `<span class="agr-status agr-status-cancelled">취소됨</span>`
            : `<span class="agr-status agr-status-active">활성</span>`;
        const actionCell = cancelled ? '' :
            `<button class="btn btn-small btn-danger agr-cancel-btn"
                     data-id="${item.id}" data-nickname="${App.esc(item.nickname || '')}"
                     data-submitted="${App.esc(submitted)}">취소</button>`;
        const cancelRow = cancelled
            ? `<tr class="agr-cancel-meta"><td colspan="10">└ 사유: "${App.esc(item.cancel_reason || '')}" · by ${App.esc(item.cancelled_by_name || '?')} · ${App.esc((item.cancelled_at || '').slice(5, 16))}</td></tr>`
            : '';
        return `
            <tr class="${cancelled ? 'agr-row-cancelled' : ''}">
                <td>${App.esc(submitted)}</td>
                <td>${App.esc(item.cohort_label || '-')}</td>
                <td>${App.esc(item.group_name || '-')}</td>
                <td>${App.esc(item.nickname || '')}</td>
                <td><a href="${App.esc(item.url)}" target="_blank" rel="noopener noreferrer">↗ 링크</a></td>
                <td>${audioCell(item.id, 'before')}</td>
                <td>${audioCell(item.id, 'after')}</td>
                <td title="${App.esc(item.consent_agreed_at || '')}">${item.consent_agreed_at ? '✓' : '✗'}</td>
                <td>${statusCell}</td>
                <td>${actionCell}</td>
            </tr>
            ${cancelRow}
        `;
    }

    function showCancelModal(ds) {
        App.openModal('성장기록 취소', `
            <div class="agr-cancel-modal">
                <div>닉네임: ${App.esc(ds.nickname)} · 제출: ${App.esc(ds.submitted)}</div>
                <div class="agr-cancel-warn">※ 취소하면 회원이 다시 제출할 수 있습니다. 코인 변동은 없습니다.</div>
                <label class="agr-cancel-label">취소 사유 (필수)</label>
                <textarea id="agr-cancel-reason" maxlength="255" rows="3" class="form-input"></textarea>
                <div class="agr-cancel-actions">
                    <button class="btn btn-secondary" id="agr-cancel-close">닫기</button>
                    <button class="btn btn-danger" id="agr-cancel-confirm">취소 처리</button>
                </div>
            </div>
        `);
        document.getElementById('agr-cancel-close').onclick = () => App.closeModal();
        document.getElementById('agr-cancel-confirm').onclick = async () => {
            const reason = document.getElementById('agr-cancel-reason').value.trim();
            if (!reason) { Toast.error('취소 사유를 입력해주세요.'); return; }
            App.showLoading();
            const r = await App.post(API + 'growth_record_cancel', { id: parseInt(ds.id), cancel_reason: reason });
            App.hideLoading();
            if (r.success) { Toast.success(r.message); App.closeModal(); await load(); }
            else Toast.error(r.error || r.message || '취소 실패');
        };
    }

    return { init };
})();
```

- [ ] **Step 2: admin.js 수정 (3곳)**

① 201행 `후기` 탭 버튼 다음에:

```js
                            <button class="tab" data-tab="#tab-growth-records" data-hash="growth">성장기록</button>
```

② 227행 `<div class="tab-content" id="tab-reviews"></div>` 다음에:

```js
                        <div class="tab-content" id="tab-growth-records"></div>
```

③ 460행 근처 Reviews lazy load 블록 다음에 (동일 패턴):

```js
            // Growth Records 탭 lazy load
            if (typeof AdminGrowthRecords !== 'undefined') {
                const growthTab = document.getElementById('tab-growth-records');
                if (growthTab) {
                    const growthObserver = new MutationObserver(() => {
                        if (growthTab.classList.contains('active') && !growthTab.dataset.loaded) {
                            growthTab.dataset.loaded = '1';
                            AdminGrowthRecords.init(growthTab);
                        }
                    });
                    growthObserver.observe(growthTab, { attributes: true, attributeFilter: ['class'] });
                }
            }
```

- [ ] **Step 3: operation/index.php script 태그**

50행 `admin-reviews.js` 줄 다음에:

```php
    <script src="/js/admin-growth-records.js<?= v('/js/admin-growth-records.js') ?>"></script>
```

- [ ] **Step 4: css/admin.css 끝에 스타일 추가**

```css
/* ── 성장기록 탭 (admin-growth-records.js) ─────────────────── */
.admin-growth-settings { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; }
.agr-settings-title { font-weight: 700; margin-bottom: 6px; }
.agr-settings-row { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; font-size: 13px; }
.agr-toggle { display: flex; align-items: center; gap: 6px; }
.agr-settings-hint { color: #888; font-size: 12px; }
.admin-growth-filters { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; font-size: 13px; }
.admin-growth-counts { font-size: 13px; color: #555; margin-bottom: 8px; }
.admin-growth-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-growth-table th, .admin-growth-table td { padding: 6px 8px; border-bottom: 1px solid #eee; text-align: left; vertical-align: middle; }
.agr-audio { width: 180px; height: 30px; vertical-align: middle; }
.agr-status-active { color: #15803d; }
.agr-status-cancelled { color: #b91c1c; }
.agr-row-cancelled td { opacity: 0.55; }
.agr-cancel-meta td { font-size: 12px; color: #888; }
.agr-cancel-warn { color: #b91c1c; font-size: 13px; margin: 6px 0; }
.agr-cancel-actions, .agr-guide-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 10px; }
.agr-guide-modal textarea { width: 100%; font-family: monospace; font-size: 13px; }
```

- [ ] **Step 5: 수동 검증 (DEV)**

1. `node --check /root/boot-dev/public_html/js/admin-growth-records.js && node --check /root/boot-dev/public_html/js/admin.js`
2. https://dev-boot.soritune.com/operation 로그인 → "성장기록" 탭 표시
3. Task 4에서 제출한 데이터가 목록에 표시: 기수/조/닉네임/링크/동의 ✓/Before·After 재생 + ⬇ 다운로드
4. 접수 토글 OFF → 회원 화면에서 "현재 접수 중이 아닙니다" → 다시 ON
5. 마감일을 과거로 저장 → 회원 화면 "제출 기간이 마감되었습니다" → 원복(2026-06-16 23:59:59)
6. 취소(사유 입력) → 상태 취소됨 → 회원 화면에서 재제출 가능 확인

- [ ] **Step 6: Commit**

```bash
cd /root/boot-dev && git add public_html/js/admin-growth-records.js public_html/js/admin.js public_html/operation/index.php public_html/css/admin.css
git commit -m "feat: admin 성장기록 탭 (목록/재생/다운로드/취소/접수설정)"
```

---

### Task 6: 최종 검증

- [ ] **Step 1: 전체 테스트**

```bash
cd /root/boot-dev && php tests/growth_record_schema_invariants.php && php tests/growth_record_test.php
php -l public_html/api/services/growth_record.php && php -l public_html/api/bootcamp.php && php -l migrate_growth_record_submissions.php
```
Expected: 전부 PASS / No syntax errors

- [ ] **Step 2: 기존 기능 회귀 확인**

```bash
cd /root/boot-dev && php tests/notice_service_test.php && php tests/transaction_invariants.php
```
Expected: PASS (라우터 require 추가가 기존 액션을 깨지 않았는지)

curl smoke:
```bash
curl -s 'https://dev-boot.soritune.com/api/bootcamp.php?action=my_review_status' | head -c 200   # 기존 후기 API 여전히 동작 (401 JSON)
curl -s 'https://dev-boot.soritune.com/api/bootcamp.php?action=growth_record_audio&id=1&which=before' | head -c 200  # 401 JSON
```

- [ ] **Step 3: HTTP 업로드 smoke (apache 권한으로 실제 경로 검증)**

DEV 회원 계정으로 브라우저에서 제출 1건 (Task 4 Step 6에서 했으면 생략). `ls -la /var/www/html/_______site_SORITUNECOM_DEV_BOOT/growth_uploads/` 에 `12기_닉네임_before_N.mp3` 형태 파일 + apache 소유 확인.

- [ ] **Step 4: 마지막 커밋 + push 후 정지**

```bash
cd /root/boot-dev && git status   # 누락 파일 확인
git push origin dev
```

**⛔ push 후 반드시 멈추고 사용자에게 dev 확인 요청. 운영 반영(main 머지, prod pull, PROD DB 마이그, PROD growth_uploads 디렉토리+SELinux)은 사용자가 명시적으로 요청한 경우에만.**

PROD 반영 시 추가 체크리스트 (운영 반영 지시 받은 뒤):
1. `junior`식 표준 플로우: dev → main 머지 → prod pull
2. PROD DB에 `php migrate_growth_record_submissions.php` (boot-prod 경로에서)
3. PROD `growth_uploads/` 생성 + `apache:apache 750` + SELinux fcontext (Task 1 Step 6과 동일, 경로만 `_______site_SORITUNECOM_BOOT`)
4. 기존 후기 접수 토글(review_cafe_enabled/review_blog_enabled)을 admin에서 OFF (운영 결정)
