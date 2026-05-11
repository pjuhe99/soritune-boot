<?php
declare(strict_types=1);
/**
 * paste 미리보기에서 어드민이 선택한 행들을 적용.
 *
 * 한 행 = 1 트랜잭션 (이미 outer tx 안에 있으면 그것을 사용):
 *   1) member_key 이 다른 회원에 있으면 NULL 해제 (옛 회원 displace)
 *   2) target 회원에 member_key 부여
 *   3) backfillPostsForMembers([target]) — 같은 키의 과거 unmapped 백필 + saveCheck
 *
 * 한 행 실패 → 그 행만 rollback, 다른 행 계속.
 *
 * @return array{results:array<int,array>, summary:array{applied:int, skipped:int, failed:int}}
 */

require_once __DIR__ . '/cafe_backfill_helper.php';

function applyCafeBulkMapping(PDO $db, array $rows): array {
    $results = [];
    $summary = ['applied' => 0, 'skipped' => 0, 'failed' => 0];

    foreach ($rows as $row) {
        $rowNo = (int)($row['row'] ?? 0);
        $articleId = (string)($row['article_id'] ?? '');
        $memberKey = (string)($row['member_key'] ?? '');
        $targetMemberId = isset($row['target_member_id']) ? (int)$row['target_member_id'] : 0;

        if ($targetMemberId === 0 || $memberKey === '') {
            $results[] = ['row' => $rowNo, 'status' => 'skipped', 'reason' => 'no_target'];
            $summary['skipped']++;
            continue;
        }

        $startedHere = false;
        try {
            $startedHere = !$db->inTransaction();
            if ($startedHere) $db->beginTransaction();

            // 1) 옛 회원 키 해제
            $displace = $db->prepare("
                UPDATE bootcamp_members SET cafe_member_key = NULL
                WHERE cafe_member_key = ? AND id != ?
            ");
            $displace->execute([$memberKey, $targetMemberId]);
            $displaced = $displace->rowCount();

            // 2) target 회원에 키 부여
            $upd = $db->prepare("UPDATE bootcamp_members SET cafe_member_key = ? WHERE id = ?");
            $upd->execute([$memberKey, $targetMemberId]);

            // 3) 백필 + saveCheck
            $bf = backfillPostsForMembers($db, [$targetMemberId]);

            if ($startedHere) $db->commit();

            $results[] = [
                'row'              => $rowNo,
                'status'           => $displaced > 0 ? 'applied_diff' : 'applied',
                'member_id'        => $targetMemberId,
                'article_id'       => $articleId,
                'displaced'        => $displaced,
                'backfilled_posts' => $bf['remapped'],
                'missions_saved'   => $bf['missions_saved'],
            ];
            $summary['applied']++;

        } catch (\Throwable $e) {
            if ($startedHere && $db->inTransaction()) $db->rollBack();
            $results[] = [
                'row'    => $rowNo,
                'status' => 'failed',
                'reason' => $e->getMessage(),
            ];
            $summary['failed']++;
        }
    }

    return ['results' => $results, 'summary' => $summary];
}
