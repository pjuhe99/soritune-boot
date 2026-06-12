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
 * (m4a 는 컨테이너 brand 에 따라 libmagic 판정이 갈림 — M4A→audio/x-m4a,
 *  mp42/isom→video/mp4, 3gp4(삼성 음성녹음)→video/3gpp, qt→video/quicktime.
 *  raw AAC(ADTS) 를 .m4a 로 저장하는 녹음앱도 있어 aac 까지 수용)
 */
function growthAudioExt(string $mime): ?string {
    $map = [
        'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3',
        'audio/mp4'  => 'm4a', 'video/mp4'  => 'm4a', 'audio/x-m4a' => 'm4a',
        'video/3gpp' => 'm4a', 'audio/3gpp' => 'm4a', 'video/quicktime' => 'm4a',
        'audio/aac'  => 'aac', 'audio/x-hx-aac-adts' => 'aac',
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

// ── 본인 취소 / 파일 교체 ────────────────────────────────────

/**
 * 회원 본인 취소. 접수 게이트는 핸들러에서. 파일 보존.
 * 성공: ['cancelled'=>true], 실패: ['error'=>msg].
 */
function growthSelfCancel(PDO $db, int $memberId): array {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            SELECT id FROM growth_record_submissions
            WHERE member_id = ? AND cancelled_at IS NULL FOR UPDATE
        ");
        $stmt->execute([$memberId]);
        $row = $stmt->fetch();
        if (!$row) { $db->rollBack(); return ['error' => '취소할 제출이 없습니다.']; }
        $db->prepare("
            UPDATE growth_record_submissions
               SET cancelled_at = NOW(), cancelled_by = NULL, cancel_reason = '회원 본인 취소'
             WHERE id = ? AND cancelled_at IS NULL
        ")->execute([(int)$row['id']]);
        $db->commit();
        return ['cancelled' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * 본인 활성 제출의 음성 1개 교체. $file: ['tmp'=>,'mime'=>,'ext'=>,'orig'=>].
 * 새 파일명 ..._{id}_r{rev}.{ext} 로 저장 → UPDATE → 옛 파일 삭제 (교체본은 보관 안 함).
 * 직렬화: BEGIN → SELECT ... FOR UPDATE → UPDATE → COMMIT.
 * 취소 race: 잠근 row 없으면 rollback + 새 파일 unlink (old 파일 절대 건드리지 않음).
 * 성공: ['submission'=>row], 실패: ['error'=>msg].
 */
function growthReplaceAudio(PDO $db, array $member, string $which, array $file, bool $viaUpload = true): array {
    $memberId = (int)$member['id'];

    $tmpDir = GROWTH_UPLOAD_ROOT . '/tmp';
    if (!is_dir($tmpDir) && !mkdir($tmpDir, 0750, true) && !is_dir($tmpDir)) {
        return ['error' => '저장 공간 오류입니다. 관리자에게 문의해주세요.'];
    }

    // ① staging 이동 (트랜잭션 앞 — 실패해도 DB 미변경)
    $t = $tmpDir . "/stage_{$memberId}_{$which}_" . bin2hex(random_bytes(4)) . '.' . $file['ext'];
    $moved = $viaUpload ? move_uploaded_file($file['tmp'], $t) : rename($file['tmp'], $t);
    if (!$moved) return ['error' => '음성 파일 저장에 실패했습니다. 다시 시도해주세요.'];

    $col = $which === 'before' ? 'before' : 'after';
    $name = null; // final 파일명 (트랜잭션 안에서 결정)

    $db->beginTransaction();
    try {
        // ② FOR UPDATE — 취소 race 직렬화
        $stmt = $db->prepare("
            SELECT * FROM growth_record_submissions
            WHERE member_id = ? AND cancelled_at IS NULL FOR UPDATE
        ");
        $stmt->execute([$memberId]);
        $row = $stmt->fetch();
        if (!$row) {
            $db->rollBack();
            @unlink($t);
            return ['error' => '변경할 제출이 없습니다.'];
        }

        $old = $row["{$col}_file"];

        // ③ staging → final 이동 (row id 확보 후 최종 명명)
        $cStmt = $db->prepare("SELECT cohort FROM cohorts WHERE id = ?");
        $cStmt->execute([(int)$row['cohort_id']]);
        $cohortLabel = (string)($cStmt->fetchColumn() ?: 'cohort');
        $nick = growthSafeName($member['nickname'] ?: ($member['real_name'] ?: ('m' . $memberId)));
        $name = growthSafeName($cohortLabel) . "_{$nick}_{$which}_{$row['id']}_r" . bin2hex(random_bytes(3)) . '.' . $file['ext'];

        if (!rename($t, GROWTH_UPLOAD_ROOT . '/' . $name)) {
            $db->rollBack();
            @unlink($t);
            return ['error' => '음성 파일 저장에 실패했습니다. 다시 시도해주세요.'];
        }

        // ④ UPDATE + rowCount 확인
        $upStmt = $db->prepare("
            UPDATE growth_record_submissions
               SET {$col}_file = ?, {$col}_orig_name = ?, {$col}_mime = ?
             WHERE id = ? AND cancelled_at IS NULL
        ");
        $upStmt->execute([$name, mb_substr($file['orig'], 0, 255), $file['mime'], (int)$row['id']]);
        if ($upStmt->rowCount() !== 1) {
            $db->rollBack();
            @unlink(GROWTH_UPLOAD_ROOT . '/' . $name);
            return ['error' => '변경할 제출이 없습니다.'];
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        @unlink($t);
        if ($name !== null) @unlink(GROWTH_UPLOAD_ROOT . '/' . $name);
        throw $e;
    }

    // ⑤ COMMIT 후 old 파일 삭제 (old 와 new 가 같으면 skip — 동일명 교체 방어)
    if ($old !== $name) @unlink(GROWTH_UPLOAD_ROOT . '/' . $old);

    $submission = growthFindActive($db, $memberId);
    return ['submission' => $submission];
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

function handleGrowthRecordSelfCancel($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $session = requireMember();
    $db = getDB();
    if (growthClosedReason(growthSettings($db)) !== null) {
        jsonError('접수 기간에만 취소할 수 있습니다.');
    }
    $r = growthSelfCancel($db, (int)$session['member_id']);
    if (isset($r['error'])) jsonError($r['error']);
    jsonSuccess([], '제출이 취소되었습니다. 다시 제출할 수 있습니다.');
}

function handleGrowthRecordReplaceAudio($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $session = requireMember();
    $db = getDB();
    if (growthClosedReason(growthSettings($db)) !== null) {
        jsonError('접수 기간에만 파일을 변경할 수 있습니다.');
    }
    $member = growthMemberRow($db, (int)$session['member_id']);
    if (!$member) jsonError('현재 변경이 불가능한 상태입니다.');
    $which = $_POST['which'] ?? '';
    if (!in_array($which, ['before', 'after'], true)) jsonError('which가 필요합니다.');
    if (empty($_FILES['audio'])) jsonError('음성 파일을 첨부해주세요.');
    $label = $which === 'before' ? 'Before' : 'After';
    $v = growthValidateAudioUpload($_FILES['audio'], $label);
    if (isset($v['error'])) jsonError($v['error']);
    $r = growthReplaceAudio($db, $member, $which, ['tmp' => $_FILES['audio']['tmp_name']] + $v, true);
    if (isset($r['error'])) jsonError($r['error']);
    $s = $r['submission'];
    if ($s === null) jsonError('변경 처리에 실패했습니다. 새로고침 후 다시 시도해주세요.');
    jsonSuccess(['submission' => [
        'id'               => (int)$s['id'],
        'url'              => $s['url'],
        'before_orig_name' => $s['before_orig_name'],
        'after_orig_name'  => $s['after_orig_name'],
        'submitted_at'     => $s['submitted_at'],
    ]], "{$label} 음성이 변경되었습니다.");
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
               gs.consent_agreed_at, gs.submitted_at, gs.cancelled_at, gs.cancelled_by, gs.cancel_reason,
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
