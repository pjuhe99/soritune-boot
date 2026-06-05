<?php
/**
 * BRAVO 인증서 서비스 (8차 슬라이스).
 * 발급 = 첫 다운로드 시 1행 (uk_bc_attempt). 재다운로드 = 기존 행 재렌더 (번호·이름·날짜 불변).
 * 인증서 행은 영구 보존 — bravoExamDelete cascade 에서 의도적으로 제외 (cert_no 진위 확인 근거).
 * 렌더 = GD 캔버스 → Imagick PDF 변환, 불가 시 PNG 폴백.
 */

require_once __DIR__ . '/bravo.php';

if (!defined('BRAVO_CERT_FONT_DIR')) {
    define('BRAVO_CERT_FONT_DIR', dirname(__DIR__, 2) . '/assets/fonts');
}
// 배경 PNG 훅: 파일이 있으면 배경으로 사용 (디자인 교체는 추후 — 1차는 텍스트 중심)
if (!defined('BRAVO_CERT_BG_PNG')) {
    define('BRAVO_CERT_BG_PNG', dirname(__DIR__, 2) . '/assets/bravo_cert_bg.png');
}

/**
 * 인증번호 생성. BRAVO{level}-{YYYYMMDD}-{seq4}. 순수.
 */
function bravoCertificateCertNo(int $level, string $passedOn, int $seq): string {
    return sprintf('BRAVO%d-%s-%04d', $level, date('Ymd', strtotime($passedOn)), $seq);
}

/**
 * 발급조건 가드: released + 확정 pass. 통과 null, 거부 ['error','code']. 순수.
 * released 검증이 발표 전 직접 호출/불합격/미확정을 모두 거부.
 */
function bravoCertificateEligible(array $exam, ?array $grade): ?array {
    if (($exam['status'] ?? '') !== 'released') {
        return ['error' => '아직 결과가 발표되지 않았습니다.', 'code' => 403];
    }
    if (!$grade || ($grade['result'] ?? '') !== 'pass') {
        return ['error' => '인증서 발급 대상이 아닙니다.', 'code' => 403];
    }
    return null;
}

/**
 * 폰트 파일 경로 (ttf 우선, otf 폴백). 없으면 null.
 */
function bravoCertFontPath(string $weight): ?string {
    foreach (['ttf', 'otf'] as $ext) {
        $p = BRAVO_CERT_FONT_DIR . "/Pretendard-{$weight}.{$ext}";
        if (is_file($p)) return $p;
    }
    return null;
}

/**
 * 발급 행 조회 (없으면 null).
 */
function bravoCertificateGet(PDO $db, int $attemptId): ?array {
    $stmt = $db->prepare("SELECT * FROM bravo_certificates WHERE attempt_id = ?");
    $stmt->execute([$attemptId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * 발급 행 조회/생성. 기존 행 있으면 그대로 반환 (번호·이름·날짜 불변).
 * passed_on = exam.result_release_at 의 날짜 (NULL 이면 오늘).
 * seq 채번 = 같은 (bravo_level, passed_on) 발급 수 + 1 — 트랜잭션 + FOR UPDATE,
 * 23000 시 uk_bc_attempt(동시 같은 attempt) 는 기존 행 반환, uk_bc_cert_no 는 재계산 1회 재시도.
 * $testHook: 테스트 전용 — INSERT 직전 호출 (채번 race 재현).
 */
function bravoCertificateIssue(PDO $db, array $attempt, array $exam, string $memberName, ?callable $testHook = null): array {
    $attemptId = (int)$attempt['id'];
    $existing = bravoCertificateGet($db, $attemptId);
    if ($existing) return $existing;

    $level = (int)$exam['bravo_level'];
    $passedOn = !empty($exam['result_release_at'])
        ? date('Y-m-d', strtotime($exam['result_release_at']))
        : date('Y-m-d');

    for ($try = 0; $try < 2; $try++) {
        $owns = !$db->inTransaction();
        if ($owns) $db->beginTransaction();
        try {
            $cnt = $db->prepare("SELECT COUNT(*) FROM bravo_certificates WHERE bravo_level = ? AND passed_on = ? FOR UPDATE");
            $cnt->execute([$level, $passedOn]);
            $seq = (int)$cnt->fetchColumn() + 1;
            $certNo = bravoCertificateCertNo($level, $passedOn, $seq);
            if ($testHook) $testHook();
            $db->prepare("INSERT INTO bravo_certificates (attempt_id, cert_no, member_name, bravo_level, passed_on) VALUES (?,?,?,?,?)")
               ->execute([$attemptId, $certNo, mb_substr($memberName, 0, 50), $level, $passedOn]);
            if ($owns) $db->commit();
            return bravoCertificateGet($db, $attemptId);
        } catch (PDOException $e) {
            if ($owns) $db->rollBack();
            if ($e->getCode() !== '23000') throw $e;
            // 같은 attempt 동시 발급(uk_bc_attempt) → 기존 행 반환
            $existing = bravoCertificateGet($db, $attemptId);
            if ($existing) return $existing;
            // cert_no 충돌(uk_bc_cert_no) → seq 재계산 재시도 (1회)
        }
    }
    throw new RuntimeException('인증서 번호 채번에 실패했습니다.');
}

/**
 * 가운데 정렬 텍스트 (imagettfbbox 폭 계산).
 */
function bravoCertCenteredText($im, string $font, float $size, int $color, int $centerX, int $baselineY, string $text): void {
    $box = imagettfbbox($size, 0, $font, $text);
    $tw = $box[2] - $box[0];
    imagettftext($im, $size, 0, (int)round($centerX - $tw / 2), $baselineY, $color, $font, $text);
}

/**
 * 인증서 렌더. GD 1754×1240 (A4 가로 ~150dpi) → Imagick PDF, 불가 시 PNG.
 * 반환: ['bytes'=>string, 'mime'=>string, 'ext'=>string]
 * $forcePng: 테스트/폴백 검증용 — Imagick 변환 생략.
 */
function bravoCertificateRender(array $cert, bool $forcePng = false): array {
    $bold = bravoCertFontPath('Bold');
    $regular = bravoCertFontPath('Regular');
    if ($bold === null || $regular === null) {
        throw new RuntimeException('인증서 폰트 파일이 없습니다: ' . BRAVO_CERT_FONT_DIR);
    }

    $w = 1754; $h = 1240;
    $im = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($im, 255, 255, 255);
    $ink   = imagecolorallocate($im, 26, 43, 76);    // 본문 네이비
    $gold  = imagecolorallocate($im, 176, 141, 87);  // 테두리
    $gray  = imagecolorallocate($im, 120, 120, 120); // 인증번호

    $bgUsed = false;
    if (is_file(BRAVO_CERT_BG_PNG)) {
        $bg = @imagecreatefrompng(BRAVO_CERT_BG_PNG);
        if ($bg) {
            imagecopyresampled($im, $bg, 0, 0, 0, 0, $w, $h, imagesx($bg), imagesy($bg));
            imagedestroy($bg);
            $bgUsed = true;
        }
    }
    if (!$bgUsed) {
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $white);
        for ($i = 0; $i < 3; $i++) {
            imagerectangle($im, 40 + $i, 40 + $i, $w - 41 - $i, $h - 41 - $i, $gold);
        }
        imagerectangle($im, 56, 56, $w - 57, $h - 57, $gold);
    }

    $level = (int)$cert['bravo_level'];
    $ts = strtotime($cert['passed_on']);
    $passedKo = date('Y', $ts) . '년 ' . date('n', $ts) . '월 ' . date('j', $ts) . '일';

    bravoCertCenteredText($im, $regular, 22, $gray, (int)($w / 2), 140, '제 ' . $cert['cert_no'] . ' 호');
    bravoCertCenteredText($im, $bold, 64, $ink, (int)($w / 2), 300, "BRAVO {$level} 등급 인증서");
    bravoCertCenteredText($im, $bold, 52, $ink, (int)($w / 2), 520, $cert['member_name']);
    bravoCertCenteredText($im, $regular, 32, $ink, (int)($w / 2), 660, "위 사람은 소리튠영어 소리블록 BRAVO {$level} 등급 시험에");
    bravoCertCenteredText($im, $regular, 32, $ink, (int)($w / 2), 720, '합격하였음을 증명합니다.');
    bravoCertCenteredText($im, $regular, 30, $ink, (int)($w / 2), 940, $passedKo);
    bravoCertCenteredText($im, $bold, 44, $ink, (int)($w / 2), 1060, '소리튠영어');

    ob_start();
    imagepng($im);
    $png = ob_get_clean();
    imagedestroy($im);

    if (!$forcePng && class_exists('Imagick')) {
        try {
            $ik = new Imagick();
            $ik->readImageBlob($png);
            $ik->setImageFormat('pdf');
            $pdf = $ik->getImagesBlob();
            $ik->clear();
            return ['bytes' => $pdf, 'mime' => 'application/pdf', 'ext' => 'pdf'];
        } catch (Throwable $e) {
            // PNG 폴백
        }
    }
    return ['bytes' => $png, 'mime' => 'image/png', 'ext' => 'png'];
}
