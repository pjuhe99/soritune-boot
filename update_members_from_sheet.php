<?php
/**
 * One-time script: Update bootcamp_members with data from Google Sheets CSV
 * Matches by nickname (case-insensitive), uses real_name for disambiguation of duplicates
 * Updates: phone, real_name (if #N/A), score (via member_scores)
 */

require_once __DIR__ . '/public_html/config.php';

$csvFile = '/tmp/member_sheet.csv';
if (!file_exists($csvFile)) {
    die("CSV file not found: {$csvFile}\n");
}

// Parse CSV
$csvRows = [];
$handle = fopen($csvFile, 'r');
$headers = fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
    $data = array_combine($headers, $row);
    $name = trim($data['주문자명'] ?? '');
    $nickname = trim($data['닉네임'] ?? '');
    $phone = str_replace('-', '', trim($data['휴대폰번호_보정'] ?? ''));
    $scoreStr = trim($data['내 점수'] ?? '');
    $score = is_numeric($scoreStr) ? (int)$scoreStr : 0;

    if ($name && $nickname) {
        $csvRows[] = [
            'name' => $name,
            'nickname' => $nickname,
            'phone' => $phone,
            'score' => $score,
        ];
    }
}
fclose($handle);
echo "CSV rows loaded: " . count($csvRows) . "\n";

// Build lookup: lowercase nickname -> csv rows
$csvByNick = [];
foreach ($csvRows as $r) {
    $key = mb_strtolower($r['nickname']);
    $csvByNick[$key][] = $r;
}

// Load all DB members
$db = getDB();
$stmt = $db->query('SELECT id, nickname, real_name, phone FROM bootcamp_members ORDER BY id');
$dbMembers = $stmt->fetchAll();
echo "DB members loaded: " . count($dbMembers) . "\n\n";

$updated = 0;
$scoreUpdated = 0;
$notFound = [];
$realNameFixed = 0;

$updateStmt = $db->prepare('UPDATE bootcamp_members SET phone = ?, real_name = ? WHERE id = ?');
$scoreCheckStmt = $db->prepare('SELECT id FROM member_scores WHERE member_id = ?');
$scoreInsertStmt = $db->prepare('INSERT INTO member_scores (member_id, current_score) VALUES (?, ?)');
$scoreUpdateStmt = $db->prepare('UPDATE member_scores SET current_score = ?, last_calculated_at = NOW() WHERE member_id = ?');

foreach ($dbMembers as $dbm) {
    $nickKey = mb_strtolower(trim($dbm['nickname']));

    if (!isset($csvByNick[$nickKey])) {
        $notFound[] = "ID={$dbm['id']} nick={$dbm['nickname']} real_name={$dbm['real_name']}";
        continue;
    }

    $candidates = $csvByNick[$nickKey];

    // If only one match, use it directly
    if (count($candidates) === 1) {
        $match = $candidates[0];
    } else {
        // Multiple candidates: match by real_name
        $match = null;
        foreach ($candidates as $c) {
            if ($dbm['real_name'] && $dbm['real_name'] !== '#N/A' && $c['name'] === $dbm['real_name']) {
                $match = $c;
                break;
            }
        }
        if (!$match) {
            // If real_name is #N/A, try first unmatched candidate
            // This is a best-effort fallback
            $match = $candidates[0];
            echo "WARNING: Multiple CSV matches for nick={$dbm['nickname']}, using first: {$match['name']}\n";
        }
    }

    // Update phone and real_name
    $newRealName = $dbm['real_name'];
    if (!$newRealName || $newRealName === '#N/A') {
        $newRealName = $match['name'];
        $realNameFixed++;
    }

    $newPhone = $match['phone'] ?: $dbm['phone'];

    $updateStmt->execute([$newPhone ?: null, $newRealName, $dbm['id']]);
    $updated++;

    // Update score
    $scoreCheckStmt->execute([$dbm['id']]);
    $existingScore = $scoreCheckStmt->fetch();
    if ($existingScore) {
        $scoreUpdateStmt->execute([$match['score'], $dbm['id']]);
    } else {
        $scoreInsertStmt->execute([$dbm['id'], $match['score']]);
    }
    $scoreUpdated++;
}

echo "\n=== Results ===\n";
echo "Updated members: {$updated}\n";
echo "Scores updated/inserted: {$scoreUpdated}\n";
echo "Real names fixed (#N/A → name): {$realNameFixed}\n";
echo "Not found in CSV: " . count($notFound) . "\n";

if ($notFound) {
    echo "\nNot found details:\n";
    foreach ($notFound as $nf) {
        echo "  {$nf}\n";
    }
}

// Verify
echo "\n=== Verification ===\n";
$stmt = $db->query('SELECT COUNT(*) FROM bootcamp_members WHERE phone IS NOT NULL AND phone != ""');
echo "Members with phone: " . $stmt->fetchColumn() . "\n";
$stmt = $db->query('SELECT COUNT(*) FROM bootcamp_members WHERE real_name = "#N/A"');
echo "Members still with #N/A: " . $stmt->fetchColumn() . "\n";
$stmt = $db->query('SELECT COUNT(*) FROM member_scores');
echo "Member scores rows: " . $stmt->fetchColumn() . "\n";

echo "\nSample data (first 5):\n";
$stmt = $db->query('
    SELECT bm.id, bm.nickname, bm.real_name, bm.phone, ms.current_score
    FROM bootcamp_members bm
    LEFT JOIN member_scores ms ON bm.id = ms.member_id
    ORDER BY bm.id LIMIT 5
');
foreach ($stmt->fetchAll() as $r) {
    echo "  ID={$r['id']} nick={$r['nickname']} name={$r['real_name']} phone={$r['phone']} score={$r['current_score']}\n";
}
