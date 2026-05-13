<?php
/**
 * 다회권 (multipass) DB 액세스 레이어.
 *
 * 모든 함수는 PDO + 예외 모드. 호출자가 트랜잭션 관리.
 *
 * 핵심 설계:
 *   - has_member_row / joined 두 신호를 매 조회 시 derive (저장 안 함)
 *     - has_member_row = bootcamp_members row 존재
 *     - joined = row 존재 AND member_status IN ('active','leaving','out_of_group_management')
 *   - coupon_issued_at/by 는 toggleCoupon 이 자동 채움/리셋
 *   - createPass / updatePass / deletePass 모두 multipass_cohorts CASCADE 의존
 */
declare(strict_types=1);

/**
 * 다회권 1건 생성. multipass_cohorts UNIQUE 위반 시 PDOException.
 *
 * @return int 신규 pass.id
 */
function createPass(PDO $db, string $userId, string $productName, array $cohortIds, ?string $note, ?int $createdBy): int {
    if (trim($userId) === '') throw new InvalidArgumentException('user_id 필수');
    if (trim($productName) === '') throw new InvalidArgumentException('product_name 필수');
    if (empty($cohortIds)) throw new InvalidArgumentException('cohort_ids 필수');

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO multipass (user_id, product_name, note, created_by) VALUES (?, ?, ?, ?)")
           ->execute([$userId, $productName, $note, $createdBy]);
        $passId = (int)$db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO multipass_cohorts (pass_id, cohort_id) VALUES (?, ?)");
        foreach ($cohortIds as $cid) {
            $stmt->execute([$passId, (int)$cid]);
        }
        $db->commit();
        return $passId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 다회권 수정. 입력 키 (user_id, product_name, note, cohort_ids) 중 set 된 것만 반영.
 * cohort_ids 가 주어지면 diff INSERT/DELETE.
 *
 * @return array{removed_cohort_ids: int[], added_cohort_ids: int[]}
 */
function updatePass(PDO $db, int $passId, array $patch, ?int $updatedBy): array {
    $db->beginTransaction();
    try {
        // 메타 업데이트
        $sets = [];
        $vals = [];
        foreach (['user_id', 'product_name', 'note'] as $k) {
            if (array_key_exists($k, $patch)) {
                $sets[] = "$k = ?";
                $vals[] = $patch[$k];
            }
        }
        if ($sets) {
            $vals[] = $passId;
            $db->prepare("UPDATE multipass SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        }

        $removed = [];
        $added = [];
        if (array_key_exists('cohort_ids', $patch)) {
            $newSet = array_map('intval', $patch['cohort_ids']);
            $existing = array_map('intval', $db->query("SELECT cohort_id FROM multipass_cohorts WHERE pass_id = $passId")->fetchAll(PDO::FETCH_COLUMN));
            $removed = array_values(array_diff($existing, $newSet));
            $added = array_values(array_diff($newSet, $existing));

            if ($removed) {
                $place = implode(',', array_fill(0, count($removed), '?'));
                $stmt = $db->prepare("DELETE FROM multipass_cohorts WHERE pass_id = ? AND cohort_id IN ($place)");
                $stmt->execute(array_merge([$passId], $removed));
            }
            if ($added) {
                $stmt = $db->prepare("INSERT INTO multipass_cohorts (pass_id, cohort_id) VALUES (?, ?)");
                foreach ($added as $cid) $stmt->execute([$passId, $cid]);
            }
        }
        $db->commit();
        return ['removed_cohort_ids' => $removed, 'added_cohort_ids' => $added];
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** 다회권 삭제 (CASCADE 로 multipass_cohorts 함께 삭제). */
function deletePass(PDO $db, int $passId): void {
    $db->prepare("DELETE FROM multipass WHERE id = ?")->execute([$passId]);
}

/**
 * 쿠폰 발급 토글.
 *
 * @return array{coupon_issued: int, coupon_issued_at: ?string, coupon_issued_by: ?int, coupon_issued_by_name: ?string}
 */
function toggleCoupon(PDO $db, int $passId, int $cohortId, bool $issued, int $adminId): array {
    if ($issued) {
        $db->prepare("UPDATE multipass_cohorts SET coupon_issued = 1, coupon_issued_at = NOW(), coupon_issued_by = ? WHERE pass_id = ? AND cohort_id = ?")
           ->execute([$adminId, $passId, $cohortId]);
    } else {
        $db->prepare("UPDATE multipass_cohorts SET coupon_issued = 0, coupon_issued_at = NULL, coupon_issued_by = NULL WHERE pass_id = ? AND cohort_id = ?")
           ->execute([$passId, $cohortId]);
    }
    $stmt = $db->prepare("
        SELECT mc.coupon_issued, mc.coupon_issued_at, mc.coupon_issued_by, a.name AS coupon_issued_by_name
        FROM multipass_cohorts mc
        LEFT JOIN admins a ON mc.coupon_issued_by = a.id
        WHERE mc.pass_id = ? AND mc.cohort_id = ?
    ");
    $stmt->execute([$passId, $cohortId]);
    $row = $stmt->fetch();
    return [
        'coupon_issued'         => (int)$row['coupon_issued'],
        'coupon_issued_at'      => $row['coupon_issued_at'],
        'coupon_issued_by'      => $row['coupon_issued_by'] !== null ? (int)$row['coupon_issued_by'] : null,
        'coupon_issued_by_name' => $row['coupon_issued_by_name'],
    ];
}

/**
 * 다회권 조회. filters: user_id?, user_ids[]?, product_name?, cohort_id?, pass_id?
 * 응답에는 cohorts 배열이 decorate 되어 has_member_row/joined 포함.
 *
 * @return array<int, array{id:int, user_id:string, product_name:string, note:?string, created_at:string, cohorts: array}>
 */
function findPasses(PDO $db, array $filters): array {
    $where = ['1=1'];
    $params = [];
    if (!empty($filters['user_id'])) {
        $where[] = 'p.user_id = ?';
        $params[] = $filters['user_id'];
    }
    if (!empty($filters['user_ids'])) {
        $place = implode(',', array_fill(0, count($filters['user_ids']), '?'));
        $where[] = "p.user_id IN ($place)";
        foreach ($filters['user_ids'] as $u) $params[] = $u;
    }
    if (!empty($filters['product_name'])) {
        $where[] = 'p.product_name = ?';
        $params[] = $filters['product_name'];
    }
    if (!empty($filters['pass_id'])) {
        $where[] = 'p.id = ?';
        $params[] = (int)$filters['pass_id'];
    }
    if (!empty($filters['cohort_id'])) {
        $where[] = 'EXISTS (SELECT 1 FROM multipass_cohorts mc WHERE mc.pass_id = p.id AND mc.cohort_id = ?)';
        $params[] = (int)$filters['cohort_id'];
    }

    $sql = "SELECT p.id, p.user_id, p.product_name, p.note, p.created_at, p.created_by, a.name AS created_by_name
            FROM multipass p
            LEFT JOIN admins a ON p.created_by = a.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.user_id, p.created_at";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $passes = $stmt->fetchAll();
    if (!$passes) return [];

    return decorateCohorts($db, $passes);
}

/**
 * 입력 받은 pass 행들에 cohorts 배열을 채워 반환. 각 cohort row 에 has_member_row/joined 계산.
 */
function decorateCohorts(PDO $db, array $passes): array {
    $passIds = array_column($passes, 'id');
    if (!$passIds) return $passes;

    $place = implode(',', array_fill(0, count($passIds), '?'));
    $stmt = $db->prepare("
        SELECT mc.pass_id, mc.cohort_id, mc.coupon_issued, mc.coupon_issued_at, mc.coupon_issued_by,
               a.name AS coupon_issued_by_name,
               c.cohort, c.start_date, c.is_active
        FROM multipass_cohorts mc
        JOIN cohorts c ON mc.cohort_id = c.id
        LEFT JOIN admins a ON mc.coupon_issued_by = a.id
        WHERE mc.pass_id IN ($place)
        ORDER BY c.start_date
    ");
    $stmt->execute($passIds);
    $cohortRows = $stmt->fetchAll();

    // user_id × cohort_id → has_member_row / joined 일괄 조회
    $userIds = array_unique(array_column($passes, 'user_id'));
    $cohortIds = array_unique(array_column($cohortRows, 'cohort_id'));
    $memberMap = []; // "user_id|cohort_id" => ['has' => bool, 'joined' => bool]
    if ($userIds && $cohortIds) {
        $up = implode(',', array_fill(0, count($userIds), '?'));
        $cp = implode(',', array_fill(0, count($cohortIds), '?'));
        $stmt = $db->prepare("
            SELECT user_id, cohort_id,
                   SUM(member_status IN ('active','leaving','out_of_group_management')) AS joined_cnt
            FROM bootcamp_members
            WHERE user_id IN ($up) AND cohort_id IN ($cp) AND user_id IS NOT NULL AND user_id <> ''
            GROUP BY user_id, cohort_id
        ");
        $stmt->execute(array_merge(array_values($userIds), array_values($cohortIds)));
        foreach ($stmt->fetchAll() as $r) {
            $memberMap[$r['user_id'] . '|' . $r['cohort_id']] = [
                'has'    => true,
                'joined' => (int)$r['joined_cnt'] > 0,
            ];
        }
    }

    // pass.id → user_id 맵
    $passUser = [];
    foreach ($passes as $p) $passUser[$p['id']] = $p['user_id'];

    // pass_id → cohorts[]
    $byPass = [];
    foreach ($cohortRows as $cr) {
        $key = $passUser[$cr['pass_id']] . '|' . $cr['cohort_id'];
        $info = $memberMap[$key] ?? ['has' => false, 'joined' => false];
        $byPass[$cr['pass_id']][] = [
            'cohort_id'             => (int)$cr['cohort_id'],
            'cohort'                => $cr['cohort'],
            'start_date'            => $cr['start_date'],
            'is_active'             => (int)$cr['is_active'],
            'coupon_issued'         => (int)$cr['coupon_issued'],
            'coupon_issued_at'      => $cr['coupon_issued_at'],
            'coupon_issued_by'      => $cr['coupon_issued_by'] !== null ? (int)$cr['coupon_issued_by'] : null,
            'coupon_issued_by_name' => $cr['coupon_issued_by_name'],
            'has_member_row'        => $info['has'],
            'joined'                => $info['joined'],
        ];
    }

    foreach ($passes as &$p) {
        $p['id'] = (int)$p['id'];
        $p['cohorts'] = $byPass[$p['id']] ?? [];
    }
    return $passes;
}

/**
 * 회원 검색 — q 로 user_id/nickname/real_name/phone 부분일치 + 다회권 lookup.
 *
 * 결과는 user_id 별 그룹.
 *
 * @return array<int, array{user_id:string, profiles:array, passes:array}>
 */
function searchMembers(PDO $db, string $q): array {
    $q = trim($q);
    if ($q === '') return [];

    $like = '%' . $q . '%';

    // 1) bootcamp_members 에서 user_id 후보 수집
    $stmt = $db->prepare("
        SELECT bm.user_id, bm.nickname, bm.real_name, bm.phone, c.cohort, c.start_date
        FROM bootcamp_members bm
        JOIN cohorts c ON bm.cohort_id = c.id
        WHERE bm.user_id IS NOT NULL AND bm.user_id <> ''
          AND (bm.user_id LIKE ? OR bm.nickname LIKE ? OR bm.real_name LIKE ? OR bm.phone LIKE ?)
        ORDER BY c.start_date DESC
        LIMIT 200
    ");
    $stmt->execute([$like, $like, $like, $like]);
    $rows = $stmt->fetchAll();

    $byUser = [];
    foreach ($rows as $r) {
        $byUser[$r['user_id']]['profiles'][] = [
            'nickname'      => $r['nickname'],
            'real_name'     => $r['real_name'],
            'phone'         => $r['phone'],
            'latest_cohort' => $r['cohort'],
            'start_date'    => $r['start_date'],
        ];
    }

    // 2) 다회권에만 있는 user_id (멤버 행 없음) — q 가 user_id 부분일치할 때만
    $stmt = $db->prepare("SELECT DISTINCT user_id FROM multipass WHERE user_id LIKE ?");
    $stmt->execute([$like]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = ['profiles' => []];
        }
    }

    if (!$byUser) return [];

    // 3) 각 user_id 의 다회권 가져오기
    $userIds = array_keys($byUser);
    $passes = findPasses($db, ['user_ids' => $userIds]);
    $passByUser = [];
    foreach ($passes as $p) $passByUser[$p['user_id']][] = $p;

    $out = [];
    foreach ($byUser as $uid => $info) {
        if (empty($passByUser[$uid])) continue;  // 다회권 없는 user_id 는 결과에서 제외
        $out[] = [
            'user_id'  => $uid,
            'profiles' => $info['profiles'] ?? [],
            'passes'   => $passByUser[$uid],
        ];
    }
    return $out;
}
