<?php
/**
 * boot.soritune.com - 11-12 묶음 group을 cohort별 단독 group으로 재편
 *
 * Before:
 *   reward_groups: [id=X, name="11-12기 리워드", status=open]
 *     소속 cycles: 11기(id=N1), 12기(id=N2)
 *
 * After:
 *   reward_groups: [id=X, name="11기 리워드", status=open]      ← 이름만 변경
 *                  [id=Y, name="12기 리워드", status=open]      ← 신규
 *   11기 cycle: reward_group_id = X
 *   12기 cycle: reward_group_id = Y
 *
 * 실행:
 *   php migrate_split_11_12_groups.php --dry-run --old-group-id=X
 *   php migrate_split_11_12_groups.php --execute --old-group-id=X
 */

require_once __DIR__ . '/public_html/config.php';

$opts = getopt('', ['dry-run', 'execute', 'old-group-id:']);
$dryRun  = isset($opts['dry-run']);
$execute = isset($opts['execute']);
$oldId   = (int)($opts['old-group-id'] ?? 0);

if ((!$dryRun && !$execute) || !$oldId) {
    fwrite(STDERR, "Usage: php migrate_split_11_12_groups.php --dry-run|--execute --old-group-id=ID\n");
    exit(2);
}

$db = getDB();

echo "=== 11-12 묶음 group 재편 ===\n";
echo "  모드: " . ($dryRun ? 'DRY-RUN' : 'EXECUTE') . "\n";
echo "  대상 group_id: $oldId\n\n";

// 기존 group 확인
$stmt = $db->prepare("SELECT * FROM reward_groups WHERE id = ?");
$stmt->execute([$oldId]);
$oldGroup = $stmt->fetch();
if (!$oldGroup) { fwrite(STDERR, "group id=$oldId 없음\n"); exit(1); }
if ($oldGroup['status'] !== 'open') { fwrite(STDERR, "이미 distributed된 group은 재편 불가\n"); exit(1); }
echo "  [확인] 기존 group: {$oldGroup['name']} (status={$oldGroup['status']})\n";

// 소속 cycles 확인
$cStmt = $db->prepare("SELECT id, name, start_date, end_date, status FROM coin_cycles WHERE reward_group_id = ? ORDER BY start_date");
$cStmt->execute([$oldId]);
$cycles = $cStmt->fetchAll();
echo "  [확인] 소속 cycles: " . count($cycles) . "개\n";
foreach ($cycles as $c) {
    echo "    - id={$c['id']} name={$c['name']} ({$c['start_date']}~{$c['end_date']}) status={$c['status']}\n";
}

if (count($cycles) !== 2) { fwrite(STDERR, "cycle 개수가 2가 아님 — 수동 확인 필요\n"); exit(1); }

// start_date 오름차순: 첫 cycle = 11기(유지), 두 번째 cycle = 12기(이동 대상)
$keepCycle = $cycles[0];
$moveCycle = $cycles[1];

echo "\n  [계획]\n";
echo "    1) group id=$oldId 이름: \"{$oldGroup['name']}\" → \"{$keepCycle['name']} 리워드\"\n";
echo "    2) 새 group INSERT: name=\"{$moveCycle['name']} 리워드\", status=open\n";
echo "    3) cycle id={$moveCycle['id']}의 reward_group_id → 새 group id\n";

if ($dryRun) {
    echo "\n=== DRY-RUN 종료 ===\n";
    exit(0);
}

$db->beginTransaction();
try {
    // 1) 기존 group 이름 변경
    $db->prepare("UPDATE reward_groups SET name = ? WHERE id = ?")
       ->execute(["{$keepCycle['name']} 리워드", $oldId]);

    // 2) 새 group 생성
    $db->prepare("INSERT INTO reward_groups (name, status) VALUES (?, 'open')")
       ->execute(["{$moveCycle['name']} 리워드"]);
    $newId = (int)$db->lastInsertId();
    echo "    → 새 group id=$newId\n";

    // 3) 12기 cycle 이동
    $db->prepare("UPDATE coin_cycles SET reward_group_id = ? WHERE id = ?")
       ->execute([$newId, $moveCycle['id']]);

    // 검증: 각 group에 cycle 1개씩
    $verifyStmt = $db->prepare("SELECT reward_group_id, COUNT(*) AS cnt FROM coin_cycles WHERE reward_group_id IN (?, ?) GROUP BY reward_group_id");
    $verifyStmt->execute([$oldId, $newId]);
    $counts = [];
    foreach ($verifyStmt->fetchAll() as $r) $counts[(int)$r['reward_group_id']] = (int)$r['cnt'];
    if (($counts[$oldId] ?? 0) !== 1 || ($counts[$newId] ?? 0) !== 1) {
        throw new Exception("검증 실패: 각 group에 cycle 1개씩이어야 함. 실제: " . json_encode($counts));
    }

    $db->commit();
    echo "\n=== EXECUTE 완료 ===\n";
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "실패, 롤백됨: " . $e->getMessage() . "\n");
    exit(1);
}
