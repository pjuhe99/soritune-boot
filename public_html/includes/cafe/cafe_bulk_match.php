<?php
declare(strict_types=1);
/**
 * cafe paste 행을 부트캠프 회원에 매칭.
 *
 * 순서:
 *   1) cafe_member_key 이미 등록된 회원 있으면 ALREADY_MAPPED_(SAME|DIFF)
 *   2) cohort 안에서 (조+이름) 정확 일치
 *   3) cohort 안에서 (이름) 정확 일치
 *   4) cohort 안에서 (이름) LIKE
 *   5) 모두 0 → NO_MATCH
 *
 * 후보 회원은 member_status='active' AND cafe_member_key IS NULL 만 대상.
 *
 * @return array{status:string, candidates:array<int,array>, existing_member:?array}
 */

function matchCandidates(
    PDO $db,
    int $cohortId,
    string $cafeMemberKey,
    ?string $groupName,
    string $realName,
    ?string $nickname
): array {
    // 1. 이미 매핑 체크
    $stmt = $db->prepare("
        SELECT bm.id, bm.real_name, bm.nickname, bm.stage_no, bm.group_id, bg.name AS group_name
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bg.id = bm.group_id
        WHERE bm.cafe_member_key = ?
        LIMIT 1
    ");
    $stmt->execute([$cafeMemberKey]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $existingNameSet = array_filter([$existing['real_name'], $existing['nickname']]);
        $pasteNameSet    = array_filter([$realName, $nickname]);
        $sameName = (bool)array_intersect($existingNameSet, $pasteNameSet);

        $sameGroup = true;
        if ($groupName !== null && $groupName !== '') {
            $sameGroup = ($existing['group_name'] === $groupName
                       || $existing['group_name'] === $groupName . '조');
        }

        $status = ($sameName && $sameGroup) ? 'ALREADY_MAPPED_SAME' : 'ALREADY_MAPPED_DIFF';
        return [
            'status' => $status,
            'candidates' => [],
            'existing_member' => [
                'id'         => (int)$existing['id'],
                'real_name'  => $existing['real_name'],
                'nickname'   => $existing['nickname'],
                'group_name' => $existing['group_name'],
                'stage_no'   => (int)$existing['stage_no'],
            ],
        ];
    }

    $mapRow = fn($r) => [
        'member_id'  => (int)$r['id'],
        'real_name'  => $r['real_name'],
        'nickname'   => $r['nickname'],
        'group_name' => $r['group_name'],
        'stage_no'   => (int)$r['stage_no'],
    ];

    $baseSelect = "
        SELECT bm.id, bm.real_name, bm.nickname, bm.stage_no, bg.name AS group_name
        FROM bootcamp_members bm
        LEFT JOIN bootcamp_groups bg ON bg.id = bm.group_id
        WHERE bm.cohort_id = :cohort
          AND bm.member_status = 'active'
          AND bm.cafe_member_key IS NULL
    ";

    // 2. 조+이름 정확 일치
    if ($groupName !== null && $groupName !== '') {
        $sql = $baseSelect . "
              AND (bg.name = :group OR bg.name = :groupCho)
              AND (bm.real_name = :nameReal OR bm.nickname = :nameNick)
            LIMIT 20
        ";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':cohort', $cohortId, PDO::PARAM_INT);
        $stmt->bindValue(':group', $groupName);
        $stmt->bindValue(':groupCho', $groupName . '조');
        $stmt->bindValue(':nameReal', $realName);
        $stmt->bindValue(':nameNick', $realName);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 1) {
            return ['status' => 'HIGH', 'candidates' => array_map($mapRow, $rows), 'existing_member' => null];
        }
        if (count($rows) >= 2) {
            return ['status' => 'MID_MULTI', 'candidates' => array_map($mapRow, $rows), 'existing_member' => null];
        }
    }

    // 3. 이름 정확 일치 (조 무시)
    $sql = $baseSelect . "
          AND (bm.real_name = :nameReal OR bm.nickname = :nameNick)
        LIMIT 20
    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cohort', $cohortId, PDO::PARAM_INT);
    $stmt->bindValue(':nameReal', $realName);
    $stmt->bindValue(':nameNick', $realName);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 1) {
        return ['status' => 'MID', 'candidates' => array_map($mapRow, $rows), 'existing_member' => null];
    }
    if (count($rows) >= 2) {
        return ['status' => 'MID_MULTI', 'candidates' => array_map($mapRow, $rows), 'existing_member' => null];
    }

    // 4. 이름 LIKE
    $sql = $baseSelect . "
          AND (bm.real_name LIKE :nameLikeReal OR bm.nickname LIKE :nameLikeNick)
        LIMIT 20
    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cohort', $cohortId, PDO::PARAM_INT);
    $stmt->bindValue(':nameLikeReal', '%' . $realName . '%');
    $stmt->bindValue(':nameLikeNick', '%' . $realName . '%');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) >= 1) {
        return ['status' => 'LOW', 'candidates' => array_map($mapRow, $rows), 'existing_member' => null];
    }

    return ['status' => 'NO_MATCH', 'candidates' => [], 'existing_member' => null];
}
