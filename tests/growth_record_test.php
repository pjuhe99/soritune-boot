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
// cohorts.code は NOT NULL UNIQUE — テスト用ユニーク値を付与
$db->exec("INSERT INTO cohorts (cohort, code, start_date, end_date) VALUES ('__GRT기', '__grt_c1', '2026-01-01', '2026-12-31')");
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
        t('submit: staging cleaned', !is_file($b['tmp']));
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

    // ── growthSelfCancel ──
    // 재제출(r2) 후 본인 취소
    $r2Active = growthFindActive($db, $memberId);
    t('selfCancel: fixture active row exists', $r2Active !== null);
    if ($r2Active) {
        $r = growthSelfCancel($db, $memberId);
        t('selfCancel: ok', isset($r['cancelled']) && $r['cancelled'] === true);
        // cancelled_by NULL, cancel_reason 확인
        $stmt = $db->prepare("SELECT cancelled_by, cancel_reason FROM growth_record_submissions WHERE id = ?");
        $stmt->execute([(int)$r2Active['id']]);
        $cancelledRow = $stmt->fetch();
        t('selfCancel: cancelled_by NULL', $cancelledRow !== false && $cancelledRow['cancelled_by'] === null);
        t('selfCancel: cancel_reason is 회원 본인 취소', $cancelledRow !== false && $cancelledRow['cancel_reason'] === '회원 본인 취소');
        // 파일 보존 확인
        t('selfCancel: file preserved', is_file(GROWTH_UPLOAD_ROOT . '/' . $r2Active['before_file']));
        // 대상 없을 때 error (방금 취소됐으므로 활성 없음)
        $r = growthSelfCancel($db, $memberId);
        t('selfCancel: no active submission → error', isset($r['error']));
        // 재제출 가능 확인
        $r3 = growthSubmit($db, $member, 'https://blog.naver.com/resubmit3', true, makeAudio('mp3'), makeAudio('mp3'), false);
        t('selfCancel: resubmit after self cancel ok', isset($r3['submission']), $r3['error'] ?? '');
        if (isset($r3['submission'])) {
            $cleanFiles[] = GROWTH_UPLOAD_ROOT . '/' . $r3['submission']['before_file'];
            $cleanFiles[] = GROWTH_UPLOAD_ROOT . '/' . $r3['submission']['after_file'];
        }
    }

    // ── growthReplaceAudio ──
    $activeForReplace = growthFindActive($db, $memberId);
    t('replace: fixture active row exists', $activeForReplace !== null);
    if ($activeForReplace) {
        // happy path: before 교체
        $oldBeforeFile = $activeForReplace['before_file'];
        $oldBeforePath = GROWTH_UPLOAD_ROOT . '/' . $oldBeforeFile;
        $newFile = makeAudio('mp3');
        $r = growthReplaceAudio($db, $member, 'before', $newFile, false);
        t('replace: ok', isset($r['submission']), $r['error'] ?? '');
        if (isset($r['submission'])) {
            $cleanFiles[] = GROWTH_UPLOAD_ROOT . '/' . $r['submission']['before_file'];
            t('replace: new filename has _r suffix', (bool)preg_match('/_r[0-9a-f]{6}\./', $r['submission']['before_file']));
            t('replace: new file exists', is_file(GROWTH_UPLOAD_ROOT . '/' . $r['submission']['before_file']));
            t('replace: old file deleted', !is_file($oldBeforePath));
            t('replace: orig_name updated', $r['submission']['before_orig_name'] === '원본 mp3.mp3');
            // ext 변경(mp3 → m4a) happy path: after 교체
            $oldAfterFile = $r['submission']['after_file'];
            $oldAfterPath = GROWTH_UPLOAD_ROOT . '/' . $oldAfterFile;
            $newM4a = makeAudio('m4a');
            $newM4a['mime'] = 'audio/mp4';
            $r2r = growthReplaceAudio($db, $member, 'after', $newM4a, false);
            t('replace ext change: ok', isset($r2r['submission']), $r2r['error'] ?? '');
            if (isset($r2r['submission'])) {
                $cleanFiles[] = GROWTH_UPLOAD_ROOT . '/' . $r2r['submission']['after_file'];
                t('replace ext change: new ext is m4a', str_ends_with($r2r['submission']['after_file'], '.m4a'));
                t('replace ext change: old after file deleted', !is_file($oldAfterPath));
            }
        }

        // 활성 제출 없을 때 error
        $memberNoSub = ['id' => 99999999, 'cohort_id' => $cohortId, 'nickname' => '__nope', 'real_name' => ''];
        $r = growthReplaceAudio($db, $memberNoSub, 'before', makeAudio('mp3'), false);
        t('replace: no active submission → error', isset($r['error']));
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
