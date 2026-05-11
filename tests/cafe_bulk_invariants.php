<?php
/**
 * 카페 키 일괄 등록 배포 후 PROD 인보리언트 smoke.
 * 사용: php tests/cafe_bulk_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();

// INV-1: cafe_member_key UNIQUE (DB 레벨 UNIQUE 인덱스가 보장하지만 확인)
$dupes = $db->query("
    SELECT cafe_member_key, COUNT(*) c
    FROM bootcamp_members
    WHERE cafe_member_key IS NOT NULL
    GROUP BY cafe_member_key
    HAVING c > 1
")->fetchAll();
t('INV-1 cafe_member_key 중복 없음', empty($dupes), count($dupes) . ' duplicates');

// INV-2: cafe_posts.member_id orphan (회원이 사라진 글)
$orphan = (int)$db->query("
    SELECT COUNT(*) FROM cafe_posts cp
    WHERE cp.member_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM bootcamp_members bm WHERE bm.id = cp.member_id)
")->fetchColumn();
t('INV-2 cafe_posts orphan 0', $orphan === 0, "{$orphan} orphan posts");

// INV-3: 매핑된 cafe_posts 의 mission_checked=1 (체크 표시) — 매핑 후 reset 없음
$unflagged = (int)$db->query("
    SELECT COUNT(*) FROM cafe_posts
    WHERE member_id IS NOT NULL AND mission_checked = 0
")->fetchColumn();
t('INV-3 매핑된 posts 의 mission_checked=1', $unflagged === 0, "{$unflagged} unflagged");

// INV-4: 활성 cohort 회원의 cafe_member_key 있는 사람 수가 합리적
$total = (int)$db->query("SELECT COUNT(*) FROM bootcamp_members WHERE is_active=1 AND member_status='active'")->fetchColumn();
$mapped = (int)$db->query("SELECT COUNT(*) FROM bootcamp_members WHERE is_active=1 AND member_status='active' AND cafe_member_key IS NOT NULL")->fetchColumn();
echo "INFO  활성 회원 {$total} 명 중 cafe_member_key 보유 {$mapped} 명\n";

echo "\n{$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
