<?php
/**
 * Review Service
 * 후기 제출/조회/취소 엔드포인트
 * 스펙: docs/superpowers/specs/2026-04-23-review-submission-design.md
 *       docs/superpowers/specs/2026-04-24-review-guide-unify-design.md
 */

// ── 헬퍼 ─────────────────────────────────────────────────────

/**
 * system_contents 값 조회. 없으면 $default 반환.
 */
function getSystemContent($db, $key, $default = '') {
    $stmt = $db->prepare("SELECT content_markdown FROM system_contents WHERE content_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? $val : $default;
}

/**
 * 회원 eligibility 판정.
 * @return array ['eligible' => bool, 'reason' => string|null, 'active_cycle' => array|null, 'member' => array]
 */
function evaluateReviewEligibility($db, $memberId) {
    // 회원 상태
    $mStmt = $db->prepare("SELECT id, is_active, member_status FROM bootcamp_members WHERE id = ?");
    $mStmt->execute([$memberId]);
    $member = $mStmt->fetch();
    if (!$member) return ['eligible' => false, 'reason' => 'member_inactive', 'active_cycle' => null, 'member' => null];
    if ((int)$member['is_active'] !== 1 ||
        in_array($member['member_status'], ['refunded', 'leaving', 'out_of_group_management'])) {
        return ['eligible' => false, 'reason' => 'member_inactive', 'active_cycle' => null, 'member' => $member];
    }

    // active cycle
    $cycle = getActiveCycle($db);
    if (!$cycle) {
        return ['eligible' => false, 'reason' => 'no_active_cycle', 'active_cycle' => null, 'member' => $member];
    }

    // cohort 매칭은 coin_cycles에 cohort_id가 없어 MVP에서는 스킵.
    // 잘못된 기수 제출은 12기 정산 시 reward_forfeited 로직이 걸러냄.
    return ['eligible' => true, 'reason' => null, 'active_cycle' => $cycle, 'member' => $member];
}

/**
 * URL 포맷 검증. 유효하면 true.
 */
function isValidReviewUrl($url) {
    if (!is_string($url)) return false;
    $len = strlen($url);
    if ($len < 10 || $len > 500) return false;
    return (bool)preg_match('#^https?://#i', $url);
}

// ── 회원: my_review_status ───────────────────────────────────

function handleMyReviewStatus() {
    $session = requireMember();
    $memberId = (int)$session['member_id'];

    $db = getDB();
    $elig = evaluateReviewEligibility($db, $memberId);

    // 현재 active cycle의 active 제출 조회 (eligible이 아니어도 과거 cycle은 반환 안 함)
    $cafeSubmitted = null;
    $blogSubmitted = null;
    if ($elig['active_cycle']) {
        $cycleId = (int)$elig['active_cycle']['id'];
        $sStmt = $db->prepare("
            SELECT id, type, url, coin_amount, submitted_at
            FROM review_submissions
            WHERE member_id = ? AND cycle_id = ? AND cancelled_at IS NULL
        ");
        $sStmt->execute([$memberId, $cycleId]);
        foreach ($sStmt->fetchAll() as $row) {
            $out = [
                'id' => (int)$row['id'],
                'url' => $row['url'],
                'submitted_at' => $row['submitted_at'],
                'coin_amount' => (int)$row['coin_amount'],
            ];
            if ($row['type'] === 'cafe') $cafeSubmitted = $out;
            else $blogSubmitted = $out;
        }
    }

    jsonSuccess([
        'eligible' => $elig['eligible'],
        'ineligible_reason' => $elig['reason'],
        'guide' => getSystemContent($db, 'review_guide', ''),
        'cafe' => [
            'enabled'   => getSystemContent($db, 'review_cafe_enabled', 'off') === 'on',
            'submitted' => $cafeSubmitted,
        ],
        'blog' => [
            'enabled'   => getSystemContent($db, 'review_blog_enabled', 'off') === 'on',
            'submitted' => $blogSubmitted,
        ],
    ]);
}

// ── 회원: submit_review ──────────────────────────────────────

function handleSubmitReview($method) {
    if ($method !== 'POST') jsonError('POST only', 405);

    $session = requireMember();
    $memberId = (int)$session['member_id'];

    $input = getJsonInput();
    $type = trim($input['type'] ?? '');
    $url  = trim($input['url'] ?? '');

    if (!in_array($type, ['cafe', 'blog'], true)) jsonError('type은 cafe 또는 blog여야 합니다.');

    $db = getDB();

    // 토글 확인
    $enabled = getSystemContent($db, "review_{$type}_enabled", 'off');
    if ($enabled !== 'on') jsonError('현재 접수 중이 아닙니다.');

    // eligibility
    $elig = evaluateReviewEligibility($db, $memberId);
    if (!$elig['eligible']) {
        $msg = [
            'no_active_cycle' => '현재 진행 중인 기수가 없습니다.',
            'cohort_mismatch' => '이번 기수 후기 접수 대상이 아닙니다.',
            'member_inactive' => '현재 후기 제출이 불가능한 상태입니다.',
        ][$elig['reason']] ?? '후기 제출이 불가능합니다.';
        jsonError($msg);
    }

    // URL 검증
    if (!isValidReviewUrl($url)) jsonError('URL 형식이 올바르지 않습니다. (https:// 로 시작하는 10~500자 링크)');

    $cycleId = (int)$elig['active_cycle']['id'];

    // 트랜잭션: 동시 제출 방어 + 원자적 적립
    $db->beginTransaction();
    try {
        // active 제출 있는지 lock하며 확인
        $dup = $db->prepare("
            SELECT id FROM review_submissions
            WHERE member_id = ? AND cycle_id = ? AND type = ? AND cancelled_at IS NULL
            FOR UPDATE
        ");
        $dup->execute([$memberId, $cycleId, $type]);
        if ($dup->fetch()) {
            $db->rollBack();
            jsonError('이미 제출하셨습니다.');
        }

        // row INSERT (coin_amount=0, 뒤에서 UPDATE)
        $db->prepare("
            INSERT INTO review_submissions (member_id, cycle_id, type, url, coin_amount)
            VALUES (?, ?, ?, ?, 0)
        ")->execute([$memberId, $cycleId, $type, $url]);
        $insertId = (int)$db->lastInsertId();

        // 코인 적립
        $result = applyCoinChange(
            $db, $memberId, $cycleId, 5,
            "review_{$type}",
            "review_submission_id:{$insertId}",
            null
        );
        $applied = (int)$result['applied'];

        // applied === 0 → 제출 rollback
        if ($applied === 0) {
            $db->rollBack();
            jsonError('이번 기수 코인이 이미 최대치에 도달하여 후기 제출을 처리할 수 없습니다.');
        }

        // coin_amount를 실제 적립액으로 UPDATE
        $db->prepare("UPDATE review_submissions SET coin_amount = ? WHERE id = ?")
           ->execute([$applied, $insertId]);

        $db->commit();

        $submission = [
            'id' => $insertId,
            'url' => $url,
            'submitted_at' => date('Y-m-d H:i:s'),
            'coin_amount' => $applied,
        ];
        $message = $applied < 5
            ? "이번 기수 최대치에 가까워 {$applied}코인만 적립되었습니다."
            : "+{$applied} 코인이 적립되었습니다.";
        jsonSuccess([
            'applied_coin' => $applied,
            'submission' => $submission,
        ], $message);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// ── 운영: reviews_list ───────────────────────────────────────

function handleReviewsList() {
    $admin = requireAdmin(['operation','coach','head','subhead1','subhead2']);

    $db = getDB();
    $active = getActiveCycle($db);
    $cycleId = (int)($_GET['cycle_id'] ?? ($active ? $active['id'] : 0));
    $type    = $_GET['type'] ?? 'all';   // cafe | blog | all
    $status  = $_GET['status'] ?? 'active'; // active | cancelled | all
    $q       = trim($_GET['q'] ?? '');

    // 공통 필터: cycle_id, type, q (status는 counts/items 각각 다르게 적용)
    $commonWhere = "rs.cycle_id = ?";
    $commonParams = [$cycleId];
    if (in_array($type, ['cafe','blog'], true)) {
        $commonWhere .= " AND rs.type = ?";
        $commonParams[] = $type;
    }
    if ($q !== '') {
        $commonWhere .= " AND (bm.nickname LIKE ? OR bm.real_name LIKE ?)";
        $commonParams[] = "%{$q}%";
        $commonParams[] = "%{$q}%";
    }

    // counts — status 미적용
    $cStmt = $db->prepare("
        SELECT
          COUNT(*) AS total,
          SUM(CASE WHEN rs.cancelled_at IS NULL THEN 1 ELSE 0 END) AS active_cnt,
          SUM(CASE WHEN rs.cancelled_at IS NOT NULL THEN 1 ELSE 0 END) AS cancelled_cnt
        FROM review_submissions rs
        JOIN bootcamp_members bm ON bm.id = rs.member_id
        WHERE {$commonWhere}
    ");
    $cStmt->execute($commonParams);
    $c = $cStmt->fetch();
    $counts = [
        'total'     => (int)($c['total'] ?? 0),
        'active'    => (int)($c['active_cnt'] ?? 0),
        'cancelled' => (int)($c['cancelled_cnt'] ?? 0),
    ];

    // items — 전 필터 적용
    $where = $commonWhere;
    $params = $commonParams;
    if ($status === 'active')         { $where .= " AND rs.cancelled_at IS NULL"; }
    elseif ($status === 'cancelled')  { $where .= " AND rs.cancelled_at IS NOT NULL"; }

    $sql = "
        SELECT rs.id, rs.type, rs.url, rs.coin_amount, rs.submitted_at,
               rs.cancelled_at, rs.cancel_reason,
               bm.id AS member_id, bm.nickname, bm.real_name,
               bg.name AS group_name,
               a.name AS cancelled_by_name
        FROM review_submissions rs
        JOIN bootcamp_members bm ON bm.id = rs.member_id
        LEFT JOIN bootcamp_groups bg ON bg.id = bm.group_id
        LEFT JOIN admins a ON a.id = rs.cancelled_by
        WHERE {$where}
        ORDER BY rs.submitted_at DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    jsonSuccess(['counts' => $counts, 'items' => $items]);
}

// ── 운영: review_cancel ──────────────────────────────────────

function handleReviewCancel($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation','coach','head','subhead1','subhead2']);

    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    $reason = trim($input['cancel_reason'] ?? '');

    if (!$id) jsonError('id가 필요합니다.');
    if ($reason === '' || mb_strlen($reason) > 255) {
        jsonError('취소 사유는 1~255자여야 합니다.');
    }

    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            SELECT id, member_id, cycle_id, type, coin_amount, cancelled_at
            FROM review_submissions WHERE id = ? FOR UPDATE
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            $db->rollBack();
            jsonError('해당 후기를 찾을 수 없습니다.');
        }
        if ($row['cancelled_at'] !== null) {
            $db->rollBack();
            jsonError('이미 취소된 후기입니다.');
        }

        $upd = $db->prepare("
            UPDATE review_submissions
               SET cancelled_at = NOW(),
                   cancelled_by = ?,
                   cancel_reason = ?
             WHERE id = ? AND cancelled_at IS NULL
        ");
        $upd->execute([$admin['admin_id'], $reason, $id]);
        if ($upd->rowCount() !== 1) {
            $db->rollBack();
            jsonError('취소 처리에 실패했습니다. 다시 시도해주세요.');
        }

        $coinAmount = (int)$row['coin_amount'];
        if ($coinAmount > 0) {
            applyCoinChange(
                $db, (int)$row['member_id'], (int)$row['cycle_id'],
                -$coinAmount,
                "review_{$row['type']}",
                "cancel:review_submission_id:{$id} reason:{$reason}",
                $admin['admin_id']
            );
        }

        $db->commit();
        jsonSuccess([
            'applied_coin' => -$coinAmount,
            'coin_amount'  => $coinAmount,
        ], "후기가 취소되고 코인 {$coinAmount}이 회수되었습니다.");
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// ── 운영: review_settings ────────────────────────────────────

/**
 * 토글/가이드 값 조회.
 */
function handleReviewSettingsGet() {
    requireAdmin(['operation','coach','head','subhead1','subhead2']);
    $db = getDB();
    jsonSuccess([
        'cafe_enabled' => getSystemContent($db, 'review_cafe_enabled', 'off') === 'on',
        'blog_enabled' => getSystemContent($db, 'review_blog_enabled', 'off') === 'on',
        'guide'        => getSystemContent($db, 'review_guide', ''),
    ]);
}

/**
 * 단일 키 UPDATE. 허용 키: review_cafe_enabled / review_blog_enabled / review_guide.
 */
function handleReviewSettingsUpdate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation','coach','head','subhead1','subhead2']);

    $input = getJsonInput();
    $key = trim($input['key'] ?? '');
    $value = $input['value'] ?? null;

    $allowed = ['review_cafe_enabled', 'review_blog_enabled', 'review_guide'];
    if (!in_array($key, $allowed, true)) jsonError('허용되지 않은 key입니다.');
    if (!is_string($value)) jsonError('value는 문자열이어야 합니다.');

    if (str_ends_with($key, '_enabled')) {
        if (!in_array($value, ['on','off'], true)) jsonError('enabled 값은 on/off여야 합니다.');
    } else {
        // guide: 마크다운 텍스트, 길이 제한만
        if (mb_strlen($value) > 5000) jsonError('가이드는 5000자 이내여야 합니다.');
    }

    $db = getDB();
    // UPSERT — 키가 없으면 insert, 있으면 update
    $db->prepare("
        INSERT INTO system_contents (content_key, content_markdown) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE content_markdown = VALUES(content_markdown)
    ")->execute([$key, $value]);

    jsonSuccess(['key' => $key, 'value' => $value], '저장되었습니다.');
}
