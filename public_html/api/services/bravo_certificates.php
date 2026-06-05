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
 * 자간(tracking) 적용 가운데 정렬 텍스트.
 * GD 는 letter-spacing 미지원 — 문자 단위로 폭을 재며 전진 그리기 (커닝 무시는 디스플레이용 허용).
 */
function bravoCertTrackedCenteredText($im, string $font, float $size, int $color, int $centerX, int $baselineY, string $text, float $tracking): void {
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$chars) return;
    $widths = [];
    $total = 0.0;
    foreach ($chars as $ch) {
        $b = imagettfbbox($size, 0, $font, $ch);
        $cw = $b[2] - $b[0];
        $widths[] = $cw;
        $total += $cw;
    }
    $total += $tracking * (count($chars) - 1);
    $x = $centerX - $total / 2;
    foreach ($chars as $i => $ch) {
        imagettftext($im, $size, 0, (int)round($x), $baselineY, $color, $font, $ch);
        $x += $widths[$i] + $tracking;
    }
}

/**
 * 인증서 렌더. GD 1754×1240 (A4 가로 ~150dpi) → Imagick PDF, 불가 시 PNG.
 * 반환: ['bytes'=>string, 'mime'=>string, 'ext'=>string]
 * $forcePng: 테스트/폴백 검증용 — Imagick 변환 생략.
 *
 * 장식 규칙 (스펙 2026-06-05 §4-5):
 * - 프레임 장식(테두리·모서리 액센트)은 기본(무배경) 디자인 전용 — 배경 PNG 가 있으면 PNG 가 완성 디자인.
 * - 텍스트 폴리시(등급별 포인트 색·자간·이름 구분선·서명선)는 배경 유무와 무관하게 항상 적용.
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
    $ink   = imagecolorallocate($im, 26, 43, 76);     // #1A2B4C 본문 네이비
    $gold  = imagecolorallocate($im, 176, 141, 87);   // #B08D57 골드 (보더·장식)
    $gray  = imagecolorallocate($im, 100, 116, 139);  // #64748B 인증번호 (--color-gray-500)
    // 등급별 타이틀 포인트 색 (스펙 고정값: B1 primary-600 / B2 accent-600 / B3 골드)
    $levelRgb = [1 => [37, 99, 235], 2 => [217, 119, 6], 3 => [176, 141, 87]];
    $lv = $levelRgb[(int)$cert['bravo_level']] ?? [26, 43, 76];
    $accent = imagecolorallocate($im, $lv[0], $lv[1], $lv[2]);

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
        // 이중 테두리: 외곽 3px 띠 + 내곽 1px (간격 보강: 36 / 52)
        for ($i = 0; $i < 3; $i++) {
            imagerectangle($im, 36 + $i, 36 + $i, $w - 37 - $i, $h - 37 - $i, $gold);
        }
        imagerectangle($im, 52, 52, $w - 53, $h - 53, $gold);
        // 모서리 L 액센트 (내곽 모서리 4곳, 직선 조합)
        $cl = 36;
        imagesetthickness($im, 3);
        foreach ([[52, 52, 1, 1], [$w - 53, 52, -1, 1], [52, $h - 53, 1, -1], [$w - 53, $h - 53, -1, -1]] as $c) {
            [$cx, $cy, $dx, $dy] = $c;
            imageline($im, $cx + $dx * 8, $cy + $dy * 8, $cx + $dx * ($cl + 8), $cy + $dy * 8, $gold);
            imageline($im, $cx + $dx * 8, $cy + $dy * 8, $cx + $dx * 8, $cy + $dy * ($cl + 8), $gold);
        }
        imagesetthickness($im, 1);
    }

    $level = (int)$cert['bravo_level'];
    $ts = strtotime($cert['passed_on']);
    $passedKo = date('Y', $ts) . '년 ' . date('n', $ts) . '월 ' . date('j', $ts) . '일';

    // 텍스트 폴리시 — 배경 유무와 무관하게 항상 적용
    bravoCertCenteredText($im, $regular, 22, $gray, (int)($w / 2), 140, '제 ' . $cert['cert_no'] . ' 호');
    bravoCertTrackedCenteredText($im, $bold, 64, $accent, (int)($w / 2), 300, "BRAVO {$level} 등급 인증서", 6.0);
    bravoCertCenteredText($im, $bold, 52, $ink, (int)($w / 2), 520, $cert['member_name']);
    // 이름 아래 구분선 (이름 폭 + 양쪽 40px)
    $nb = imagettfbbox(52, 0, $bold, $cert['member_name']);
    $nw = $nb[2] - $nb[0];
    imagesetthickness($im, 2);
    imageline($im, (int)($w / 2 - $nw / 2 - 40), 552, (int)($w / 2 + $nw / 2 + 40), 552, $gold);
    imagesetthickness($im, 1);
    bravoCertCenteredText($im, $regular, 32, $ink, (int)($w / 2), 660, "위 사람은 소리튠영어 소리블록 BRAVO {$level} 등급 시험에");
    bravoCertCenteredText($im, $regular, 32, $ink, (int)($w / 2), 720, '합격하였음을 증명합니다.');
    bravoCertCenteredText($im, $regular, 30, $ink, (int)($w / 2), 940, $passedKo);
    // 발급처 위 서명선 느낌의 가는 선
    imageline($im, (int)($w / 2 - 150), 1000, (int)($w / 2 + 150), 1000, $gold);
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
