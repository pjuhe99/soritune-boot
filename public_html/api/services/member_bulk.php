<?php
/**
 * Member Bulk Registration Service
 * 회원 일괄 등록: 검증 + 등록 처리
 */

require_once __DIR__ . '/member_stats.php';

/**
 * 참여 횟수 계산 (member.php의 calcParticipationCount와 동일 로직)
 * admin.php 컨텍스트에서 member.php가 로드되지 않으므로 별도 정의
 */
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

/**
 * 일괄 등록용 검증
 * @param array $rows  [['real_name'=>..., 'nickname'=>..., 'user_id'=>..., 'phone'=>..., 'stage_no'=>...], ...]
 * @param int $cohortId
 * @return array ['valid'=>[...], 'errors'=>[...], 'warnings'=>[...], 'summary'=>[...]]
 */
function validateBulkMembers(array $rows, int $cohortId): array {
    $db = getDB();
    $valid = [];
    $errors = [];
    $warnings = [];

    // 기존 DB 전화번호 목록 (같은 cohort)
    $stmt = $db->prepare("SELECT phone FROM bootcamp_members WHERE cohort_id = ? AND phone IS NOT NULL AND phone != ''");
    $stmt->execute([$cohortId]);
    $existingPhones = array_column($stmt->fetchAll(), 'phone');
    $existingPhoneSet = array_flip($existingPhones);

    // 기존 DB 닉네임 목록 (같은 cohort)
    $stmt2 = $db->prepare("SELECT nickname FROM bootcamp_members WHERE cohort_id = ? AND nickname IS NOT NULL AND nickname != ''");
    $stmt2->execute([$cohortId]);
    $existingNicknames = array_column($stmt2->fetchAll(), 'nickname');
    $existingNicknameSet = array_flip($existingNicknames);

    // 기존 DB user_id 목록 (같은 cohort)
    $stmt3 = $db->prepare("SELECT user_id FROM bootcamp_members WHERE cohort_id = ? AND user_id IS NOT NULL AND user_id != ''");
    $stmt3->execute([$cohortId]);
    $existingUserIds = array_column($stmt3->fetchAll(), 'user_id');
    $existingUserIdSet = array_flip($existingUserIds);

    // 업로드 내부 중복 추적
    $seenPhones = [];
    $seenNicknames = [];
    $seenUserIds = [];

    foreach ($rows as $idx => $row) {
        $rowNum = $idx + 1;
        $rowErrors = [];
        $rowWarnings = [];

        $realName = trim($row['real_name'] ?? '');
        $nickname = trim($row['nickname'] ?? '');
        $userId   = trim($row['user_id'] ?? '');
        $phone    = trim($row['phone'] ?? '');
        $stageNo  = trim($row['stage_no'] ?? '1');

        // 전화번호 정규화
        $phoneNormalized = preg_replace('/[^0-9]/', '', $phone);

        // 필수값 검증
        if ($realName === '') {
            $rowErrors[] = '이름이 비어 있습니다.';
        }
        if ($nickname === '') {
            $rowErrors[] = '닉네임이 비어 있습니다.';
        }

        // 이름 길이
        if ($realName !== '' && mb_strlen($realName) > 50) {
            $rowErrors[] = '이름이 50자를 초과합니다.';
        }

        // 닉네임 길이
        if ($nickname !== '' && mb_strlen($nickname) > 50) {
            $rowErrors[] = '닉네임이 50자를 초과합니다.';
        }

        // 아이디 길이
        if ($userId !== '' && mb_strlen($userId) > 100) {
            $rowErrors[] = '아이디가 100자를 초과합니다.';
        }

        // 전화번호 형식 검증
        if ($phoneNormalized !== '') {
            if (strlen($phoneNormalized) < 10 || strlen($phoneNormalized) > 11) {
                $rowErrors[] = "전화번호 형식 오류: '{$phone}' (10~11자리 숫자)";
            }
        } else if ($phone !== '') {
            $rowErrors[] = "전화번호에 숫자가 없습니다: '{$phone}'";
        }

        // 단계 검증
        if (!in_array($stageNo, ['1', '2'])) {
            $rowErrors[] = "단계 값 오류: '{$stageNo}' (1 또는 2만 가능)";
        }

        // 전화번호 중복: DB 기존 데이터
        if ($phoneNormalized !== '' && isset($existingPhoneSet[$phoneNormalized])) {
            $rowErrors[] = "이미 등록된 전화번호입니다: {$phoneNormalized}";
        }

        // 전화번호 중복: 업로드 파일 내부
        if ($phoneNormalized !== '' && isset($seenPhones[$phoneNormalized])) {
            $rowErrors[] = "파일 내 전화번호 중복 ({$seenPhones[$phoneNormalized]}행과 동일)";
        }

        // 아이디 중복: DB 기존 데이터
        if ($userId !== '' && isset($existingUserIdSet[$userId])) {
            $rowErrors[] = "이미 등록된 아이디입니다: {$userId}";
        }

        // 아이디 중복: 업로드 파일 내부
        if ($userId !== '' && isset($seenUserIds[$userId])) {
            $rowErrors[] = "파일 내 아이디 중복 ({$seenUserIds[$userId]}행과 동일)";
        }

        // 닉네임 중복: DB 기존 데이터
        if ($nickname !== '' && isset($existingNicknameSet[$nickname])) {
            $rowWarnings[] = "같은 닉네임이 기존 회원에 있습니다: '{$nickname}'";
        }

        // 닉네임 중복: 업로드 파일 내부
        if ($nickname !== '' && isset($seenNicknames[$nickname])) {
            $rowWarnings[] = "파일 내 닉네임 중복 ({$seenNicknames[$nickname]}행과 동일)";
        }

        // 전화번호 없음 경고
        if ($phoneNormalized === '' && $phone === '') {
            $rowWarnings[] = '전화번호 없음 (로그인 불가)';
        }

        // 추적
        if ($phoneNormalized !== '') $seenPhones[$phoneNormalized] = $rowNum;
        if ($nickname !== '') $seenNicknames[$nickname] = $rowNum;
        if ($userId !== '') $seenUserIds[$userId] = $rowNum;

        $processed = [
            'row_num'    => $rowNum,
            'real_name'  => $realName,
            'nickname'   => $nickname,
            'user_id'    => $userId,
            'phone'      => $phoneNormalized,
            'stage_no'   => (int)$stageNo,
            'errors'     => $rowErrors,
            'warnings'   => $rowWarnings,
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
            'total'    => count($rows),
            'valid'    => count($valid),
            'error'    => count($errors),
            'warnings' => count($warnings),
        ],
    ];
}

/**
 * 검증 통과한 회원 일괄 등록
 * @param array $members  validateBulkMembers()의 valid 배열
 * @param int $cohortId
 * @param int $adminId  등록한 관리자 ID
 * @return array ['inserted'=>int, 'ids'=>int[]]
 */
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

            // 집계 테이블 갱신
            refreshMemberStats($db, $phone, $userId);
        }
        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        throw $e;
    }

    return ['inserted' => count($ids), 'ids' => $ids];
}
