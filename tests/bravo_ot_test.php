<?php
/**
 * BRAVO 시험별 OT 서비스 테스트. 순수 + DEV DB 통합(트랜잭션 롤백).
 * 사용: php tests/bravo_ot_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/bravo.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// ── 순수: bravoOtValidate ──
t('exam_id 없으면 에러', in_array('시험을 지정해주세요.', bravoOtValidate([]), true));
t('exam_id 0 에러', in_array('시험을 지정해주세요.', bravoOtValidate(['exam_id'=>0]), true));
t('exam_id 유효 통과', bravoOtValidate(['exam_id'=>5]) === []);

// ── 순수: bravoOtPersistData ──
$p = bravoOtPersistData(['title'=>'  OT ','intro_text'=>'','video_url'=>' http://v ','type1_text'=>'유형1','require_check'=>'1']);
t('persist title trim', $p['title'] === 'OT');
t('persist 빈 intro → null', $p['intro_text'] === null);
t('persist video trim', $p['video_url'] === 'http://v');
t('persist require_check 1', $p['require_check'] === 1);
$p2 = bravoOtPersistData([]);
t('persist require_check 미전달 → 0', $p2['require_check'] === 0);

// ── 통합: upsert/get + 시험삭제 정리 (DEV DB, 롤백) ──
$db = getDB();
$db->beginTransaction();
try {
    $tag = 'TOT_' . bin2hex(random_bytes(3));
    $examId = bravoExamCreate($db, [
        'title'=>"{$tag} 시험",'bravo_level'=>1,'exam_mode'=>'always',
        'attempt_limit'=>3,'target_type'=>'all','status'=>'preparing',
    ], 99);
    t('시험 생성', $examId > 0);
    t('OT 최초 없음', bravoOtGet($db, $examId) === null);

    bravoOtUpsert($db, $examId, ['exam_id'=>$examId,'title'=>'OT 제목','intro_text'=>'안내','require_check'=>1], 99);
    $ot1 = bravoOtGet($db, $examId);
    t('OT upsert 생성', $ot1 !== null && $ot1['title'] === 'OT 제목');
    t('require_check 1', (int)$ot1['require_check'] === 1);

    bravoOtUpsert($db, $examId, ['exam_id'=>$examId,'title'=>'OT 수정','type1_text'=>'유형1 안내','require_check'=>0], 99);
    $cnt = (int)$db->query("SELECT COUNT(*) FROM bravo_exam_ot WHERE exam_id=" . (int)$examId)->fetchColumn();
    t('upsert 중복 안생김(1행)', $cnt === 1, 'cnt=' . $cnt);
    $ot2 = bravoOtGet($db, $examId);
    t('OT 갱신 반영', $ot2['title'] === 'OT 수정' && $ot2['type1_text'] === '유형1 안내');
    t('require_check 0 갱신', (int)$ot2['require_check'] === 0);

    bravoExamDelete($db, $examId);
    t('시험 삭제 시 OT 동반 삭제', bravoOtGet($db, $examId) === null);
    t('시험 행도 삭제', (int)$db->query("SELECT COUNT(*) FROM bravo_exams WHERE id=" . (int)$examId)->fetchColumn() === 0);

    $db->rollBack();
} catch (Throwable $e) {
    $db->rollBack();
    t('통합 예외 없음', false, $e->getMessage());
}

echo "\n결과: {$pass} pass, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
