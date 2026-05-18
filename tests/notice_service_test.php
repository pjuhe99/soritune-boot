<?php
/**
 * Notice service 단위 테스트.
 *
 * 사용:
 *   cd /root/boot-dev && php tests/notice_service_test.php
 *
 * DEV DB 에 임시 cohort/admin/notice 를 만들고 마지막에 삭제.
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/notice.php';

$db = getDB();
$pass = 0; $fail = 0;

function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

function expectThrow(callable $fn, string $name): void {
    try {
        $fn();
        t($name, false, 'expected exception, none thrown');
    } catch (\InvalidArgumentException $e) {
        t($name, true);
    } catch (\Throwable $e) {
        t($name, false, 'wrong exception: ' . get_class($e) . ' ' . $e->getMessage());
    }
}

// ── Fixture: 임시 cohort + admin ──
$db->beginTransaction();

$db->exec("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active)
           VALUES ('__test_n1', '__tn1', '2099-01-01', '2099-02-01', 0)");
$cohortIdA = (int)$db->lastInsertId();

$db->exec("INSERT INTO cohorts (cohort, code, start_date, end_date, is_active)
           VALUES ('__test_n2', '__tn2', '2099-03-01', '2099-04-01', 0)");
$cohortIdB = (int)$db->lastInsertId();

$db->exec("INSERT INTO admins (login_id, name, password_hash, role, is_active)
           VALUES ('__test_n_admin', '테스트관리자', 'x', 'operation', 1)");
$adminId = (int)$db->lastInsertId();

// ── 1. create + listAdmin ──
$id1 = noticeCreate($db, $cohortIdA, $adminId, '테스트관리자', '첫 공지', '본문 *마크다운*', 1);
t('create 반환 id > 0', $id1 > 0);

$rowsA = noticeListAdmin($db, $cohortIdA);
t('listAdmin 1건', count($rowsA) === 1);
t('listAdmin title 일치', $rowsA[0]['title'] === '첫 공지');
t('listAdmin body 일치', $rowsA[0]['body_markdown'] === '본문 *마크다운*');
t('listAdmin is_visible=1', (int)$rowsA[0]['is_visible'] === 1);
t('listAdmin admin name', $rowsA[0]['created_by_admin_name'] === '테스트관리자');

// ── 2. update ──
noticeUpdate($db, $cohortIdA, $id1, '수정된 제목', '본문 수정');
$rowsA = noticeListAdmin($db, $cohortIdA);
t('update title 반영', $rowsA[0]['title'] === '수정된 제목');
t('update body 반영', $rowsA[0]['body_markdown'] === '본문 수정');

// ── 3. toggle visible ──
$newV = noticeToggleVisible($db, $cohortIdA, $id1, 0);
t('toggle to 0', $newV === 0);
$rowsM = noticeListMember($db, $cohortIdA);
t('listMember: hidden 제외', count($rowsM) === 0);

$newV = noticeToggleVisible($db, $cohortIdA, $id1, 1);
t('toggle to 1', $newV === 1);
$rowsM = noticeListMember($db, $cohortIdA);
t('listMember: 1건', count($rowsM) === 1);
t('listMember: is_visible 필드 미노출', !array_key_exists('is_visible', $rowsM[0]));
t('listMember: admin_id 필드 미노출', !array_key_exists('created_by_admin_id', $rowsM[0]));

// ── 4. cohort 분리 ──
$id2 = noticeCreate($db, $cohortIdB, $adminId, '테스트관리자', 'B 공지', 'B 본문', 1);
$rowsA_after = noticeListAdmin($db, $cohortIdA);
$rowsB_after = noticeListAdmin($db, $cohortIdB);
t('cohort A 는 1건', count($rowsA_after) === 1);
t('cohort B 는 1건', count($rowsB_after) === 1);
t('cohort B title', $rowsB_after[0]['title'] === 'B 공지');

// ── 5. cohort mismatch 가드 ──
expectThrow(fn() => noticeUpdate($db, $cohortIdB, $id1, 'x', 'y'),
    'update: 다른 cohort id → throw');
expectThrow(fn() => noticeToggleVisible($db, $cohortIdB, $id1, 0),
    'toggle: 다른 cohort id → throw');
expectThrow(fn() => noticeDelete($db, $cohortIdB, $id1),
    'delete: 다른 cohort id → throw');

// ── 6. 검증 위반 ──
expectThrow(fn() => noticeCreate($db, $cohortIdA, $adminId, '관', '', 'body', 1),
    'create: title 빈문자 → throw');
expectThrow(fn() => noticeCreate($db, $cohortIdA, $adminId, '관', '제목', '', 1),
    'create: body 빈문자 → throw');
expectThrow(fn() => noticeCreate($db, $cohortIdA, $adminId, '관', str_repeat('가', 256), 'body', 1),
    'create: title 256자 → throw');
expectThrow(fn() => noticeCreate($db, $cohortIdA, $adminId, '관', '제목', 'body', 2),
    'create: is_visible=2 → throw');
expectThrow(fn() => noticeToggleVisible($db, $cohortIdA, $id1, 5),
    'toggle: is_visible=5 → throw');

// ── 7. delete ──
noticeDelete($db, $cohortIdA, $id1);
$rowsA = noticeListAdmin($db, $cohortIdA);
t('delete 후 cohort A 0건', count($rowsA) === 0);

noticeDelete($db, $cohortIdB, $id2);

// ── 8. 정렬: listAdmin 은 is_visible DESC, created_at DESC ──
$ida = noticeCreate($db, $cohortIdA, $adminId, '관', '오래된 노출', 'b1', 1);
usleep(1100000); // 1.1s — DATETIME 초 단위 비교를 위해
$idb = noticeCreate($db, $cohortIdA, $adminId, '관', '숨김', 'b2', 0);
usleep(1100000);
$idc = noticeCreate($db, $cohortIdA, $adminId, '관', '최신 노출', 'b3', 1);

$rows = noticeListAdmin($db, $cohortIdA);
t('listAdmin 3건', count($rows) === 3);
t('listAdmin 0번: 최신 노출', $rows[0]['title'] === '최신 노출');
t('listAdmin 1번: 오래된 노출', $rows[1]['title'] === '오래된 노출');
t('listAdmin 2번: 숨김', $rows[2]['title'] === '숨김');

$rowsM = noticeListMember($db, $cohortIdA);
t('listMember 2건 (숨김 제외)', count($rowsM) === 2);
t('listMember 0번: 최신', $rowsM[0]['title'] === '최신 노출');

// ── cleanup ──
$db->rollBack();

echo "\n=== {$pass} PASS / {$fail} FAIL ===\n";
exit($fail === 0 ? 0 : 1);
