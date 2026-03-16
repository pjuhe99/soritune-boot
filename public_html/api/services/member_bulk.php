<?php
/**
 * Member Bulk Registration Service
 * 회원 일괄 등록: 검증 + 등록 처리
 */

require_once __DIR__ . '/member_stats.php';

if (!function_exists('calcParticipationCount')) {
    function calcParticipationCount($db, $phone, $userId, $cohortId) {
        if (!$phone && !$userId) return 1;
        $conds = [];
        $params = [];
        if ($phone) { $conds[] = "(bm.phone = ? AND bm.phone != '')"; $params[] = $phone; }
        if ($userId) { $conds[] = "(bm.user_id = ? AND bm.user_id != '')"; $params[] = $userId; }
        $params[] = $cohortId;
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT bm.cohort_id) AS cnt FROM bootcamp_members bm WHERE (" . implode(' OR ', $conds) . ") AND bm.cohort_id != ?"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() + 1;
    }
}

// ── 단계 값 정규화 ──────────────────────────────────────────

/**
 * 다양한 단계 표현을 내부 값(1 or 2)으로 변환
 * @return array ['value' => int|null, 'corrected' => bool, 'original' => string]
 */
function normalizeStageNo(string $raw): array {
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return ['value' => 1, 'corrected' => true, 'original' => '(빈 값)'];
    }

    // 정확히 1 또는 2
    if ($trimmed === '1' || $trimmed === '2') {
        return ['value' => (int)$trimmed, 'corrected' => false, 'original' => $trimmed];
    }

    // 패턴 매칭: "1단계", "2단계", "stage1", "Stage 2", "1 단계" 등
    $normalized = mb_strtolower(preg_replace('/\s+/', '', $trimmed));
    $map = [
        '1단계' => 1, '2단계' => 1,  // 2단계는 아래에서 재설정
        'stage1' => 1, 'stage2' => 2,
        '일단계' => 1, '이단계' => 2,
    ];
    // 2단계 수정
    $map['2단계'] = 2;

    if (isset($map[$normalized])) {
        return ['value' => $map[$normalized], 'corrected' => true, 'original' => $trimmed];
    }

    // 숫자만 추출 시도
    if (preg_match('/^[^0-9]*([12])[^0-9]*$/', $trimmed, $m)) {
        return ['value' => (int)$m[1], 'corrected' => true, 'original' => $trimmed];
    }

    return ['value' => null, 'corrected' => false, 'original' => $trimmed];
}

// ── 전화번호 정규화 ──────────────────────────────────────────

/**
 * @return array ['value' => string, 'corrected' => bool, 'original' => string]
 */
function normalizePhoneForBulk(string $raw): array {
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return ['value' => '', 'corrected' => false, 'original' => ''];
    }

    $digits = preg_replace('/[^0-9]/', '', $trimmed);

    // 10자리이고 0으로 시작하지 않으면 앞에 0 추가 (Excel 숫자 변환 보정)
    if (strlen($digits) === 10 && $digits[0] !== '0') {
        $digits = '0' . $digits;
    }

    $corrected = ($digits !== $trimmed);
    return ['value' => $digits, 'corrected' => $corrected, 'original' => $trimmed];
}

// ── 메인 검증 ────────────────────────────────────────────────

/**
 * @param array $rows       업로드된 행 데이터
 * @param int   $cohortId   등록 대상 cohort ID
 * @return array ['valid'=>[...], 'errors'=>[...], 'warnings'=>[...], 'summary'=>[...]]
 */
function validateBulkMembers(array $rows, int $cohortId): array {
    $db = getDB();
    $valid = [];
    $errors = [];
    $warnings = [];

    // ── 기존 DB 데이터 조회 (같은 cohort) ──
    $existingPhoneSet = fetchColumnSet($db, "SELECT phone FROM bootcamp_members WHERE cohort_id = ? AND phone IS NOT NULL AND phone != ''", [$cohortId]);
    $existingNicknameSet = fetchColumnSet($db, "SELECT nickname FROM bootcamp_members WHERE cohort_id = ? AND nickname IS NOT NULL AND nickname != ''", [$cohortId]);
    $existingUserIdSet = fetchColumnSet($db, "SELECT user_id FROM bootcamp_members WHERE cohort_id = ? AND user_id IS NOT NULL AND user_id != ''", [$cohortId]);

    // 다른 cohort의 전화번호 (재참여 감지용)
    $otherCohortPhones = fetchColumnSet($db, "SELECT DISTINCT phone FROM bootcamp_members WHERE cohort_id != ? AND phone IS NOT NULL AND phone != ''", [$cohortId]);

    // ── 파일 내부 중복 추적 ──
    $seenPhones = [];    // phone => rowNum
    $seenNicknames = []; // nickname => rowNum
    $seenUserIds = [];   // user_id => rowNum

    foreach ($rows as $idx => $row) {
        $rowNum = $idx + 1;
        $rowErrors = [];
        $rowWarnings = [];
        $corrections = [];  // 자동 보정 내역

        // ── 기본값 추출 + trim ──
        $realName = trim($row['real_name'] ?? '');
        $nickname = trim($row['nickname'] ?? '');
        $userId   = trim($row['user_id'] ?? '');
        $phoneRaw = trim($row['phone'] ?? '');
        $stageRaw = trim($row['stage_no'] ?? '');

        // ── 1. 필수값 검증 ──
        if ($realName === '') {
            $rowErrors[] = '이름이 비어 있습니다.';
        } elseif (mb_strlen($realName) > 50) {
            $rowErrors[] = '이름이 50자를 초과합니다.';
        }

        if ($nickname === '') {
            $rowErrors[] = '닉네임이 비어 있습니다.';
        } elseif (mb_strlen($nickname) > 50) {
            $rowErrors[] = '닉네임이 50자를 초과합니다.';
        }

        if ($userId !== '' && mb_strlen($userId) > 100) {
            $rowErrors[] = '아이디가 100자를 초과합니다.';
        }

        // ── 2. 전화번호 정규화 + 검증 ──
        $phoneResult = normalizePhoneForBulk($phoneRaw);
        $phoneNormalized = $phoneResult['value'];

        if ($phoneResult['corrected'] && $phoneNormalized !== '') {
            $corrections[] = "전화번호: '{$phoneResult['original']}' → '{$phoneNormalized}'";
        }

        if ($phoneNormalized !== '') {
            $len = strlen($phoneNormalized);
            if ($len < 10 || $len > 11) {
                $rowErrors[] = "전화번호 형식 오류: '{$phoneResult['original']}' (정규화 후 {$len}자리, 10~11자리 필요)";
            } elseif ($phoneNormalized[0] !== '0') {
                $rowErrors[] = "전화번호가 0으로 시작하지 않습니다: '{$phoneNormalized}'";
            }
        } elseif ($phoneRaw !== '') {
            $rowErrors[] = "전화번호에 숫자가 없습니다: '{$phoneRaw}'";
        }

        // ── 3. 단계 값 정규화 + 검증 ──
        $stageResult = normalizeStageNo($stageRaw);

        if ($stageResult['value'] === null) {
            $rowErrors[] = "단계 값 오류: '{$stageResult['original']}' (1, 2, 1단계, 2단계 등 허용)";
        } else if ($stageResult['corrected']) {
            $corrections[] = "단계: '{$stageResult['original']}' → {$stageResult['value']}";
        }

        $stageNo = $stageResult['value'] ?? 1;

        // ── 4. 전화번호 중복 체크 ──
        $isDuplicate = false;
        if ($phoneNormalized !== '') {
            if (isset($existingPhoneSet[$phoneNormalized])) {
                $rowErrors[] = "이미 등록된 전화번호입니다 (같은 기수): {$phoneNormalized}";
                $isDuplicate = true;
            }
            if (isset($seenPhones[$phoneNormalized])) {
                $rowErrors[] = "파일 내 전화번호 중복 ({$seenPhones[$phoneNormalized]}행과 동일)";
                $isDuplicate = true;
            }
            if (isset($otherCohortPhones[$phoneNormalized]) && !isset($existingPhoneSet[$phoneNormalized])) {
                $rowWarnings[] = "다른 기수에서 참여 이력이 있습니다 (재참여)";
            }
        }

        // ── 5. 아이디 중복 체크 ──
        if ($userId !== '') {
            if (isset($existingUserIdSet[$userId])) {
                $rowErrors[] = "이미 등록된 아이디입니다 (같은 기수): {$userId}";
                $isDuplicate = true;
            }
            if (isset($seenUserIds[$userId])) {
                $rowErrors[] = "파일 내 아이디 중복 ({$seenUserIds[$userId]}행과 동일)";
                $isDuplicate = true;
            }
        }

        // ── 6. 닉네임 중복 (경고) ──
        if ($nickname !== '') {
            if (isset($existingNicknameSet[$nickname])) {
                $rowWarnings[] = "같은 닉네임이 기존 회원에 있습니다";
            }
            if (isset($seenNicknames[$nickname])) {
                $rowWarnings[] = "파일 내 닉네임 중복 ({$seenNicknames[$nickname]}행과 동일)";
            }
        }

        // ── 7. 전화번호/아이디 모두 없음 (경고) ──
        if ($phoneNormalized === '' && $userId === '') {
            $rowWarnings[] = '전화번호·아이디 모두 없음 (로그인 불가, 재참여 추적 불가)';
        } elseif ($phoneNormalized === '') {
            $rowWarnings[] = '전화번호 없음 (로그인 불가)';
        }

        // ── 자동 보정 있으면 경고로 표시 ──
        if (!empty($corrections)) {
            foreach ($corrections as $c) {
                $rowWarnings[] = "자동 보정: {$c}";
            }
        }

        // ── 추적 갱신 ──
        if ($phoneNormalized !== '') $seenPhones[$phoneNormalized] = $rowNum;
        if ($nickname !== '') $seenNicknames[$nickname] = $rowNum;
        if ($userId !== '') $seenUserIds[$userId] = $rowNum;

        // ── 결과 조립 ──
        $processed = [
            'row_num'      => $rowNum,
            'real_name'    => $realName,
            'nickname'     => $nickname,
            'user_id'      => $userId,
            'phone'        => $phoneNormalized,
            'phone_raw'    => $phoneRaw,
            'stage_no'     => $stageNo,
            'stage_raw'    => $stageRaw,
            'is_duplicate' => $isDuplicate,
            'corrections'  => $corrections,
            'errors'       => $rowErrors,
            'warnings'     => $rowWarnings,
        ];

        if (!empty($rowErrors)) {
            $processed['status'] = 'error';
            $errors[] = $processed;
        } else {
            $processed['status'] = empty($rowWarnings) ? 'ok' : 'warning';
            $valid[] = $processed;
        }
        if (!empty($rowWarnings)) {
            $warnings = array_merge($warnings, array_map(fn($w) => "{$rowNum}행: {$w}", $rowWarnings));
        }
    }

    return [
        'valid'    => $valid,
        'errors'   => $errors,
        'warnings' => $warnings,
        'summary'  => [
            'total'       => count($rows),
            'valid'       => count($valid),
            'error'       => count($errors),
            'duplicates'  => array_sum(array_map(fn($r) => $r['is_duplicate'] ? 1 : 0, array_merge($valid, $errors))),
            'warnings'    => count($warnings),
            'corrections' => array_sum(array_map(fn($r) => count($r['corrections']), array_merge($valid, $errors))),
        ],
    ];
}

/**
 * 단일 컬럼을 flip된 Set으로 가져오기
 */
function fetchColumnSet(PDO $db, string $sql, array $params): array {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $values = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    return array_flip($values);
}

// ── 일괄 등록 ────────────────────────────────────────────────

function insertBulkMembers(array $members, int $cohortId, int $adminId): array {
    $db = getDB();
    $ids = [];

    $insertStmt = $db->prepare("
        INSERT INTO bootcamp_members (cohort_id, group_id, user_id, nickname, real_name, phone, member_role, stage_no, joined_at, participation_count)
        VALUES (?, NULL, ?, ?, ?, ?, 'member', ?, CURDATE(), ?)
    ");
    $scoreStmt = $db->prepare("INSERT INTO member_scores (member_id, current_score) VALUES (?, ?)");
    $coinStmt  = $db->prepare("INSERT INTO member_coin_balances (member_id, current_coin) VALUES (?, 0)");

    $scoreStart = defined('SCORE_START') ? SCORE_START : 0;

    $db->beginTransaction();
    try {
        foreach ($members as $m) {
            $phone  = $m['phone'] ?: null;
            $userId = !empty($m['user_id']) ? $m['user_id'] : null;
            $participationCount = calcParticipationCount($db, $phone, $userId, $cohortId);

            $insertStmt->execute([
                $cohortId,
                $userId,
                $m['nickname'],
                $m['real_name'],
                $phone,
                $m['stage_no'],
                $participationCount,
            ]);
            $newId = (int)$db->lastInsertId();
            $ids[] = $newId;

            $scoreStmt->execute([$newId, $scoreStart]);
            $coinStmt->execute([$newId]);

            refreshMemberStats($db, $phone, $userId);
        }
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        throw $e;
    }

    return ['inserted' => count($ids), 'ids' => $ids];
}
