<?php
/**
 * boot.soritune.com - 12기 코인 사이클 end_date 연장
 *
 * 12기 cohort 는 2026-05-11 ~ 2026-06-12 운영이지만 coin_cycles.end_date 가
 * 2026-05-17 로 잘못 설정되어 있어 5/18 부터의 미션 체크가 코인 발급
 * 대상에서 제외되고 있었음. 13기 시작 전날(2026-06-28) 까지로 연장.
 *
 * 실행: php migrate_extend_12gi_coin_cycle.php
 *
 * 식별: name='12기' AND start_date='2026-04-20' (DEV id=3, PROD id=5)
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

$NEW_END = '2026-06-28';
$NAME = '12기';
$START = '2026-04-20';
$OLD_END_EXPECTED = '2026-05-17';

echo "=== 12기 coin_cycles end_date 연장 ===\n";

$stmt = $db->prepare("SELECT id, name, start_date, end_date, status FROM coin_cycles WHERE name=? AND start_date=?");
$stmt->execute([$NAME, $START]);
$row = $stmt->fetch();

if (!$row) {
    echo "[ERROR] 대상 cycle 없음 (name={$NAME}, start_date={$START})\n";
    exit(1);
}

echo "현재: id={$row['id']} name={$row['name']} start={$row['start_date']} end={$row['end_date']} status={$row['status']}\n";

if ($row['end_date'] === $NEW_END) {
    echo "이미 end_date={$NEW_END} 로 설정됨. 스킵.\n";
    exit(0);
}

if ($row['end_date'] !== $OLD_END_EXPECTED) {
    echo "[WARN] 기존 end_date 가 예상값({$OLD_END_EXPECTED}) 과 다름: {$row['end_date']}\n";
    echo "그래도 진행하려면 y 입력: ";
    $confirm = trim(fgets(STDIN));
    if (strtolower($confirm) !== 'y') {
        echo "취소.\n";
        exit(1);
    }
}

$db->prepare("UPDATE coin_cycles SET end_date=? WHERE id=?")->execute([$NEW_END, $row['id']]);

$stmt = $db->prepare("SELECT id, name, start_date, end_date, status FROM coin_cycles WHERE id=?");
$stmt->execute([$row['id']]);
$after = $stmt->fetch();
echo "변경 후: id={$after['id']} end={$after['end_date']}\n";
echo "완료.\n";
