<?php
/**
 * Backfill: member_history_stats 전체 재계산
 *
 * 실행: php backfill_member_stats.php
 *
 * 기존 bootcamp_members 데이터를 기반으로 member_history_stats를 재생성한다.
 * 안전하게 TRUNCATE 후 재삽입하므로 반복 실행 가능.
 */

require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/api/services/member_stats.php';

$db = getDB();

echo "=== Backfill: member_history_stats ===\n\n";

$count = recalcAllMemberStats($db);

echo "처리 완료: {$count}건 생성\n";
echo "\n=== Backfill 완료 ===\n";
