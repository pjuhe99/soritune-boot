<?php
/**
 * BRAVO 스키마/시드 검증. 사용: php tests/bravo_schema_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$db = getDB();
$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

function tableExists(PDO $db, string $name): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn() === 1;
}

t('bravo_levels 테이블 존재', tableExists($db, 'bravo_levels'));
t('bravo_member_settings 테이블 존재', tableExists($db, 'bravo_member_settings'));

$levels = $db->query("SELECT level, name, required_review_count, passing_score, requires_previous_level FROM bravo_levels ORDER BY level")->fetchAll(PDO::FETCH_ASSOC);
t('bravo_levels 시드 3행', count($levels) === 3, 'count=' . count($levels));
$expected = [
    ['level'=>1,'name'=>'BRAVO 1','required_review_count'=>3,'passing_score'=>50,'requires_previous_level'=>0],
    ['level'=>2,'name'=>'BRAVO 2','required_review_count'=>6,'passing_score'=>65,'requires_previous_level'=>1],
    ['level'=>3,'name'=>'BRAVO 3','required_review_count'=>10,'passing_score'=>80,'requires_previous_level'=>1],
];
foreach ($expected as $i => $e) {
    $row = $levels[$i] ?? [];
    $ok = (int)($row['level']??-1)===$e['level']
        && ($row['name']??'')===$e['name']
        && (int)($row['required_review_count']??-1)===$e['required_review_count']
        && (int)($row['passing_score']??-1)===$e['passing_score']
        && (int)($row['requires_previous_level']??-1)===$e['requires_previous_level'];
    t("bravo_levels 시드 level {$e['level']} 값", $ok, json_encode($row, JSON_UNESCAPED_UNICODE));
}

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
