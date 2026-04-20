<?php
/**
 * Backfill: 과거 기수 bootcamp_members.user_id 채우기
 *
 * 실행: php migrate_backfill_user_id.php [--apply]
 *   --apply 없으면 dry-run (변경 없음, 예상 건수만 출력)
 *
 * 11기 이후에만 user_id가 저장되어 과거 기수와 자동 연결이 안 된다.
 * user_id가 있는 기수(보통 최신)의 (정규화 phone → user_id) 매핑을 만들어
 * user_id가 비어있는 과거 행 중 정규화 phone이 일치하는 행을 채운다.
 *
 * 매칭 제외: NULL/빈 phone, 더미 '01012345678', 7자리 미만 번호
 * idempotent: 이미 user_id 있는 행은 스킵하므로 반복 실행 가능.
 */

require_once __DIR__ . '/public_html/config.php';

$apply = in_array('--apply', $argv, true);
$DUMMY_PHONE = '01012345678';

$db = getDB();

echo "=== Backfill: bootcamp_members.user_id ===\n";
echo $apply ? "[APPLY 모드: 실제 업데이트]\n\n" : "[DRY-RUN 모드: --apply 없으면 변경 없음]\n\n";

// 1) user_id가 채워진 기수에서 (정규화 phone -> user_id) 매핑 구성
$rows = $db->query("
    SELECT REPLACE(phone,'-','') AS np, user_id, cohort_id
    FROM bootcamp_members
    WHERE user_id IS NOT NULL AND user_id <> ''
      AND phone IS NOT NULL AND phone <> ''
      AND REPLACE(phone,'-','') <> '{$DUMMY_PHONE}'
      AND LENGTH(REPLACE(phone,'-','')) >= 10
")->fetchAll(PDO::FETCH_ASSOC);

$map = [];
$conflicts = [];
foreach ($rows as $r) {
    $np = $r['np'];
    if (!isset($map[$np])) {
        $map[$np] = $r['user_id'];
    } elseif ($map[$np] !== $r['user_id']) {
        $conflicts[$np][] = $r['user_id'];
    }
}
echo "매핑 대상 전화번호: " . count($map) . "건\n";
if ($conflicts) {
    echo "⚠ 동일 번호에 복수 user_id 매핑(충돌) 번호: " . count($conflicts) . "건 — 해당 번호는 스킵\n";
    foreach ($conflicts as $np => $uids) {
        unset($map[$np]);
    }
}
echo "\n";

// 2) user_id 비어있는 과거 행 조회
$targets = $db->query("
    SELECT id, cohort_id, real_name, phone
    FROM bootcamp_members
    WHERE (user_id IS NULL OR user_id = '')
      AND phone IS NOT NULL AND phone <> ''
      AND REPLACE(phone,'-','') <> '{$DUMMY_PHONE}'
      AND LENGTH(REPLACE(phone,'-','')) >= 10
")->fetchAll(PDO::FETCH_ASSOC);

echo "user_id 미설정 & 매칭 후보 행: " . count($targets) . "건\n";

// 3) 매핑 적용
$toUpdate = [];
$noMatch = 0;
foreach ($targets as $t) {
    $np = str_replace('-', '', $t['phone']);
    if (isset($map[$np])) {
        $toUpdate[] = ['id' => $t['id'], 'user_id' => $map[$np], 'cohort_id' => $t['cohort_id']];
    } else {
        $noMatch++;
    }
}

echo "업데이트 예정: " . count($toUpdate) . "건\n";
echo "매칭 실패(소스에 해당 번호 없음): {$noMatch}건\n\n";

// 기수별 요약
$byCohort = [];
foreach ($toUpdate as $u) {
    $byCohort[$u['cohort_id']] = ($byCohort[$u['cohort_id']] ?? 0) + 1;
}
ksort($byCohort);
echo "기수별 업데이트 예정 분포:\n";
foreach ($byCohort as $c => $cnt) {
    echo "  cohort {$c}: {$cnt}건\n";
}
echo "\n";

if (!$apply) {
    echo "=== DRY-RUN 종료. 실제 적용하려면 --apply ===\n";
    exit(0);
}

$db->beginTransaction();
try {
    $stmt = $db->prepare("UPDATE bootcamp_members SET user_id = :uid WHERE id = :id AND (user_id IS NULL OR user_id = '')");
    $n = 0;
    foreach ($toUpdate as $u) {
        $stmt->execute([':uid' => $u['user_id'], ':id' => $u['id']]);
        $n += $stmt->rowCount();
    }
    $db->commit();
    echo "적용 완료: 실제 업데이트 {$n}건\n";
} catch (Throwable $e) {
    $db->rollBack();
    echo "실패, 롤백됨: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Backfill 완료 ===\n";
