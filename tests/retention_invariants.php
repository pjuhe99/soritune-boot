<?php
/**
 * Retention invariants verification (spec § 6).
 * Runs against the DB credentials in /root/boot-dev/.db_credentials.
 * Iterates all pairs and checks every invariant. Prints PASS/FAIL summary.
 */

require_once __DIR__ . '/../public_html/auth.php';
require_once __DIR__ . '/../public_html/api/services/retention.php';

function _approx_eq(float $a, float $b, float $eps = 0.01): bool { return abs($a - $b) <= $eps; }

$db = getDB();

// load all cohorts in order
$rows = $db->query("SELECT id, cohort, start_date FROM cohorts ORDER BY start_date, id")->fetchAll();

$total = 0; $pass = 0; $fail = 0;
$failures = [];

for ($i = 0; $i < count($rows) - 1; $i++) {
    $anchor = $rows[$i];
    $next   = $rows[$i + 1];
    $anchorId = (int)$anchor['id'];
    $nextId   = (int)$next['id'];

    $uAnchor = retentionUserIdsInCohort($db, $anchorId);
    $uNext   = retentionUserIdsInCohort($db, $nextId);
    $uPast   = retentionPastUserIdSet($db, $anchorId);
    if (count($uAnchor) === 0 || count($uNext) === 0) continue;

    $cards = retentionClassifyNext($uNext, $uAnchor, $uPast);
    $stay = $cards['stay']; $ret = $cards['returning']; $brand = $cards['brand_new'];

    $errors = [];

    // Invariant: stay + returning + brand_new = |U_next|
    if ($stay + $ret + $brand !== count($uNext)) {
        $errors[] = "cards sum != |U_next| ($stay+$ret+$brand vs " . count($uNext) . ")";
    }

    // Invariant: brand_new == count(next user_ids with participation_count=1)
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT bm.user_id)
          FROM bootcamp_members bm
         WHERE bm.cohort_id = ?
           AND bm.participation_count = 1
           AND bm.user_id IS NOT NULL AND bm.user_id <> ''
    ");
    $stmt->execute([$nextId]);
    $pcOne = (int)$stmt->fetchColumn();
    if ($pcOne !== $brand) {
        $errors[] = "brand_new($brand) != participation_count=1 in next($pcOne) — data drift, soft warn";
    }

    // Invariant: curve[0].pct = 100, curve[1].count = stay
    $curve = retentionComputeCurve($db, $anchor, $uAnchor);
    if (!_approx_eq($curve[0]['pct'], 100.0)) $errors[] = "curve[0].pct != 100 ({$curve[0]['pct']})";
    if ($curve[0]['count'] !== count($uAnchor)) $errors[] = "curve[0].count != |U_anchor|";
    if (!isset($curve[1]) || $curve[1]['count'] !== $stay) {
        $errors[] = "curve[1].count != stay (" . ($curve[1]['count'] ?? 'NA') . " vs $stay)";
    }

    // Invariant: participation breakdown total = |U_anchor|, transitioned = stay
    $bp = retentionBreakdownParticipation($db, $anchorId, $uNext);
    $bpTotal = array_sum(array_column($bp['rows'], 'total'));
    $bpTrans = array_sum(array_column($bp['rows'], 'transitioned'));
    if ($bpTotal !== count($uAnchor)) $errors[] = "participation total($bpTotal) != |U_anchor|(" . count($uAnchor) . ")";
    if ($bpTrans !== $stay)           $errors[] = "participation trans($bpTrans) != stay($stay)";

    // Invariant: group breakdown (when active) total = |U_anchor|, trans = stay
    $bg = retentionBreakdownGroup($db, $anchorId, $uNext);
    if ($bg !== null) {
        $bgTotal = array_sum(array_column($bg['rows'], 'total'));
        $bgTrans = array_sum(array_column($bg['rows'], 'transitioned'));
        if ($bgTotal !== count($uAnchor)) $errors[] = "group total($bgTotal) != |U_anchor|";
        if ($bgTrans !== $stay)           $errors[] = "group trans($bgTrans) != stay";
    }

    // Invariant: score breakdown (when active) total ≤ |U_anchor|, trans ≤ stay
    $bs = retentionBreakdownScore($db, $anchorId, count($uAnchor), $uNext);
    if ($bs !== null) {
        $bsTotal = array_sum(array_column($bs['rows'], 'total'));
        $bsTrans = array_sum(array_column($bs['rows'], 'transitioned'));
        if ($bsTotal > count($uAnchor)) $errors[] = "score total($bsTotal) > |U_anchor|";
        if ($bsTrans > $stay)           $errors[] = "score trans($bsTrans) > stay";
        if ($bsTotal !== $bs['scored_total']) $errors[] = "score scored_total mismatch";
    }

    $total++;
    if ($errors) {
        $fail++;
        $failures[] = "pair {$anchor['cohort']}→{$next['cohort']}: " . implode('; ', $errors);
    } else {
        $pass++;
    }
}

echo "RETENTION INVARIANTS\n";
echo "====================\n";
echo "Pairs checked: $total\n";
echo "Passed:        $pass\n";
echo "Failed:        $fail\n";
if ($failures) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "\nAll invariants OK.\n";
exit(0);
