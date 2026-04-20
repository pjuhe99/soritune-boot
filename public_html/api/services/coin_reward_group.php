<?php
/**
 * Coin Reward Group Service
 * 리워드 구간 CRUD, 지급(distribute), 지급 내역 조회
 */

// ── 목록 ────────────────────────────────────────────────────
function handleCoinRewardGroups() {
    requireAdmin();
    $db = getDB();
    $stmt = $db->query("
        SELECT rg.*,
               (SELECT COUNT(*) FROM coin_cycles cc WHERE cc.reward_group_id = rg.id) AS cycle_count,
               (SELECT COALESCE(SUM(mcc.earned_coin - mcc.used_coin), 0)
                  FROM coin_cycles cc
                  JOIN member_cycle_coins mcc ON mcc.cycle_id = cc.id
                 WHERE cc.reward_group_id = rg.id) AS active_total
        FROM reward_groups rg
        ORDER BY rg.created_at DESC
    ");
    $groups = $stmt->fetchAll();

    foreach ($groups as &$g) {
        $cStmt = $db->prepare("SELECT id, name, start_date, end_date, status FROM coin_cycles WHERE reward_group_id = ? ORDER BY start_date");
        $cStmt->execute([$g['id']]);
        $g['cycles'] = $cStmt->fetchAll();
    }
    jsonSuccess(['groups' => $groups]);
}

// ── CRUD ────────────────────────────────────────────────────
function handleCoinRewardGroupCreate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $name = trim($input['name'] ?? '');
    if (!$name) jsonError('name 필요');

    $db = getDB();
    $db->prepare("INSERT INTO reward_groups (name) VALUES (?)")->execute([$name]);
    jsonSuccess(['id' => (int)$db->lastInsertId()], 'Reward group이 생성되었습니다.');
}

function handleCoinRewardGroupUpdate($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    if (!$id || !$name) jsonError('id, name 필요');

    $db = getDB();
    $gStmt = $db->prepare("SELECT status FROM reward_groups WHERE id = ?");
    $gStmt->execute([$id]);
    $row = $gStmt->fetch();
    if (!$row) jsonError('group을 찾을 수 없습니다');
    if ($row['status'] !== 'open') jsonError('이미 지급된 group은 수정 불가');

    $db->prepare("UPDATE reward_groups SET name = ? WHERE id = ?")->execute([$name, $id]);
    jsonSuccess([], '수정되었습니다.');
}

function handleCoinRewardGroupDelete($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('id 필요');

    $db = getDB();
    $gStmt = $db->prepare("
        SELECT rg.status, (SELECT COUNT(*) FROM coin_cycles cc WHERE cc.reward_group_id = rg.id) AS cc
        FROM reward_groups rg WHERE rg.id = ?
    ");
    $gStmt->execute([$id]);
    $row = $gStmt->fetch();
    if (!$row) jsonError('group을 찾을 수 없습니다');
    if ($row['status'] !== 'open') jsonError('지급된 group은 삭제 불가');
    if ((int)$row['cc'] !== 0) jsonError('소속 cycle을 먼저 떼세요');

    $db->prepare("DELETE FROM reward_groups WHERE id = ?")->execute([$id]);
    jsonSuccess([], '삭제되었습니다.');
}

// ── Cycle attach/detach ────────────────────────────────────
function handleCoinRewardGroupAttach($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $groupId = (int)($input['group_id'] ?? 0);
    $cycleId = (int)($input['cycle_id'] ?? 0);
    if (!$groupId || !$cycleId) jsonError('group_id, cycle_id 필요');

    $db = getDB();
    $gStmt = $db->prepare("SELECT status FROM reward_groups WHERE id = ?");
    $gStmt->execute([$groupId]);
    $g = $gStmt->fetch();
    if (!$g) jsonError('group을 찾을 수 없습니다');
    if ($g['status'] !== 'open') jsonError('지급된 group에는 cycle 추가 불가');

    $cStmt = $db->prepare("SELECT reward_group_id FROM coin_cycles WHERE id = ?");
    $cStmt->execute([$cycleId]);
    $c = $cStmt->fetch();
    if (!$c) jsonError('cycle을 찾을 수 없습니다');
    if ($c['reward_group_id']) jsonError('이미 다른 group에 속한 cycle');

    $countStmt = $db->prepare("SELECT COUNT(*) FROM coin_cycles WHERE reward_group_id = ?");
    $countStmt->execute([$groupId]);
    if ((int)$countStmt->fetchColumn() >= 2) jsonError('reward group당 cycle은 최대 2개');

    $db->prepare("UPDATE coin_cycles SET reward_group_id = ? WHERE id = ?")->execute([$groupId, $cycleId]);
    jsonSuccess([], 'Cycle이 group에 추가되었습니다.');
}

function handleCoinRewardGroupDetach($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    requireAdmin(['operation']);
    $input = getJsonInput();
    $groupId = (int)($input['group_id'] ?? 0);
    $cycleId = (int)($input['cycle_id'] ?? 0);
    if (!$groupId || !$cycleId) jsonError('group_id, cycle_id 필요');

    $db = getDB();
    $gStmt = $db->prepare("SELECT status FROM reward_groups WHERE id = ?");
    $gStmt->execute([$groupId]);
    $g = $gStmt->fetch();
    if (!$g) jsonError('group을 찾을 수 없습니다');
    if ($g['status'] !== 'open') jsonError('지급된 group은 detach 불가');

    $db->prepare("UPDATE coin_cycles SET reward_group_id = NULL WHERE id = ? AND reward_group_id = ?")
       ->execute([$cycleId, $groupId]);
    jsonSuccess([], 'Cycle이 group에서 제외되었습니다.');
}

// ── Preview / Distribute ───────────────────────────────────
function handleCoinRewardGroupPreview() {
    requireAdmin(['operation']);
    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$groupId) jsonError('group_id 필요');

    $db = getDB();
    $group = getRewardGroupWithCycles($db, $groupId);
    if (!$group) jsonError('group을 찾을 수 없습니다');

    $prereq = checkDistributePrerequisites($group);

    $cycleIds = array_map(fn($c) => (int)$c['id'], $group['cycles']);
    $members = [];
    if ($cycleIds) {
        $ph = implode(',', array_fill(0, count($cycleIds), '?'));
        $stmt = $db->prepare("
            SELECT bm.id AS member_id, bm.nickname, bm.real_name,
                   cc.name AS cycle_name,
                   (mcc.earned_coin - mcc.used_coin) AS amount
            FROM member_cycle_coins mcc
            JOIN coin_cycles cc ON cc.id = mcc.cycle_id
            JOIN bootcamp_members bm ON bm.id = mcc.member_id
            WHERE mcc.cycle_id IN ($ph)
              AND (mcc.earned_coin - mcc.used_coin) > 0
            ORDER BY bm.nickname
        ");
        $stmt->execute($cycleIds);
        foreach ($stmt->fetchAll() as $r) {
            $mid = (int)$r['member_id'];
            if (!isset($members[$mid])) {
                $members[$mid] = [
                    'member_id' => $mid,
                    'nickname'  => $r['nickname'],
                    'real_name' => $r['real_name'],
                    'per_cycle' => [],
                    'total'     => 0,
                ];
            }
            $members[$mid]['per_cycle'][$r['cycle_name']] = (int)$r['amount'];
            $members[$mid]['total'] += (int)$r['amount'];
        }
    }

    jsonSuccess([
        'group'          => ['id' => (int)$group['id'], 'name' => $group['name'], 'status' => $group['status']],
        'cycles'         => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'status' => $c['status']], $group['cycles']),
        'can_distribute' => $prereq['can_distribute'],
        'blockers'       => $prereq['blockers'],
        'members'        => array_values($members),
    ]);
}

function handleCoinRewardGroupDistribute($method) {
    if ($method !== 'POST') jsonError('POST only', 405);
    $admin = requireAdmin(['operation']);
    $input = getJsonInput();
    $groupId = (int)($input['group_id'] ?? 0);
    if (!$groupId) jsonError('group_id 필요');

    $db = getDB();
    $group = getRewardGroupWithCycles($db, $groupId);
    if (!$group) jsonError('group을 찾을 수 없습니다');

    $prereq = checkDistributePrerequisites($group);
    if (!$prereq['can_distribute']) {
        jsonError('지급 불가: ' . implode(', ', $prereq['blockers']));
    }

    $cycleIds = array_map(fn($c) => (int)$c['id'], $group['cycles']);
    $cycleNames = [];
    foreach ($group['cycles'] as $c) $cycleNames[(int)$c['id']] = $c['name'];

    $db->beginTransaction();
    try {
        $ph = implode(',', array_fill(0, count($cycleIds), '?'));
        $stmt = $db->prepare("
            SELECT mcc.member_id, mcc.cycle_id, mcc.earned_coin, mcc.used_coin
            FROM member_cycle_coins mcc
            WHERE mcc.cycle_id IN ($ph)
        ");
        $stmt->execute($cycleIds);
        $rows = $stmt->fetchAll();

        $perMember = [];
        foreach ($rows as $r) {
            $mid = (int)$r['member_id'];
            $active = (int)$r['earned_coin'] - (int)$r['used_coin'];
            if ($active <= 0) continue;
            $cname = $cycleNames[(int)$r['cycle_id']];
            if (!isset($perMember[$mid])) $perMember[$mid] = ['breakdown' => [], 'total' => 0];
            $perMember[$mid]['breakdown'][$cname] = $active;
            $perMember[$mid]['total'] += $active;
        }

        $grantedCount = 0;
        foreach ($perMember as $mid => $info) {
            $db->prepare("
                INSERT INTO reward_group_distributions (reward_group_id, member_id, total_amount, cycle_breakdown)
                VALUES (?, ?, ?, ?)
            ")->execute([$groupId, $mid, $info['total'], json_encode($info['breakdown'], JSON_UNESCAPED_UNICODE)]);
            $grantedCount++;
        }

        foreach ($rows as $r) {
            $mid = (int)$r['member_id'];
            $cid = (int)$r['cycle_id'];
            $earnedBefore = (int)$r['earned_coin'];
            $usedBefore = (int)$r['used_coin'];
            $active = $earnedBefore - $usedBefore;
            if ($active <= 0) continue;

            $db->prepare("UPDATE member_cycle_coins SET used_coin = earned_coin WHERE member_id = ? AND cycle_id = ?")
               ->execute([$mid, $cid]);

            $db->prepare("
                INSERT INTO coin_logs (member_id, cycle_id, coin_change, before_coin, after_coin, reason_type, reason_detail, created_by)
                VALUES (?, ?, ?, ?, ?, 'reward_distribution', ?, ?)
            ")->execute([$mid, $cid, -$active, $active, 0, "리워드 지급 ({$group['name']})", $admin['admin_id']]);
        }

        $db->prepare("UPDATE reward_groups SET status='distributed', distributed_at=NOW(), distributed_by=? WHERE id = ?")
           ->execute([$admin['admin_id'], $groupId]);

        foreach (array_keys($perMember) as $mid) {
            syncMemberCoinBalance($db, $mid);
        }

        $db->commit();
        jsonSuccess(['granted' => $grantedCount], "리워드 지급 완료: {$grantedCount}명");
    } catch (Throwable $e) {
        $db->rollBack();
        jsonError('지급 실패: ' . $e->getMessage());
    }
}

// ── 지급 내역 ────────────────────────────────────────────────
function handleCoinRewardGroupDistributionDetail() {
    requireAdmin();
    $groupId = (int)($_GET['group_id'] ?? 0);
    if (!$groupId) jsonError('group_id 필요');

    $db = getDB();
    $gStmt = $db->prepare("
        SELECT rg.*, a.name AS distributor_name
        FROM reward_groups rg
        LEFT JOIN admins a ON rg.distributed_by = a.id
        WHERE rg.id = ?
    ");
    $gStmt->execute([$groupId]);
    $group = $gStmt->fetch();
    if (!$group) jsonError('group을 찾을 수 없습니다');

    $dStmt = $db->prepare("
        SELECT rgd.*, bm.nickname, bm.real_name
        FROM reward_group_distributions rgd
        JOIN bootcamp_members bm ON bm.id = rgd.member_id
        WHERE rgd.reward_group_id = ?
        ORDER BY bm.nickname
    ");
    $dStmt->execute([$groupId]);
    $distributions = $dStmt->fetchAll();
    foreach ($distributions as &$d) {
        $d['cycle_breakdown'] = json_decode($d['cycle_breakdown'], true);
    }

    jsonSuccess(['group' => $group, 'distributions' => $distributions]);
}
