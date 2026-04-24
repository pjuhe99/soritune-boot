<?php
/**
 * Notify 시스템 단위 테스트 CLI 러너 (PHPUnit 미사용 환경)
 * 사용: php test_notify.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/includes/notify/notify_functions.php';

$pass = 0; $fail = 0;

function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; }
    else { $fail++; echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n"; }
}

// ── notifyNormalizePhone ──────────────────────────
t('phone: dashes',         notifyNormalizePhone('010-1234-5678') === '01012345678');
t('phone: spaces',         notifyNormalizePhone('010 1234 5678') === '01012345678');
t('phone: +82 prefix',     notifyNormalizePhone('+82 10-1234-5678') === '01012345678');
t('phone: +8210 no space', notifyNormalizePhone('+821012345678') === '01012345678');
t('phone: already clean',  notifyNormalizePhone('01012345678') === '01012345678');
t('phone: invalid empty',  notifyNormalizePhone('') === null);
t('phone: invalid letters',notifyNormalizePhone('abcdefg') === null);
t('phone: too short',      notifyNormalizePhone('0101234') === null);
t('phone: 070 office',     notifyNormalizePhone('070-1234-5678') === '07012345678');

// ── notifyRenderVariables ──────────────────────────
$row = ['이름' => '홍길동', '연락처' => '010', 'OT_제출' => 'N'];
$vars = ['#{name}' => 'col:이름', '#{deadline}' => 'const:4월 30일'];
$rendered = notifyRenderVariables($vars, $row);
t('vars: col substitution',   ($rendered['#{name}'] ?? null) === '홍길동');
t('vars: const substitution', ($rendered['#{deadline}'] ?? null) === '4월 30일');

// 누락된 컬럼은 빈 문자열
$rendered2 = notifyRenderVariables(['#{x}' => 'col:없는컬럼'], $row);
t('vars: missing col → empty', ($rendered2['#{x}'] ?? null) === '');

// 컬럼명에 ':' 포함 — 'col:' prefix 4글자만 떼고 나머지는 모두 컬럼명
$rendered3 = notifyRenderVariables(['#{x}' => 'col:a:b'], ['a:b' => 'ok']);
t('vars: col with colon in name', ($rendered3['#{x}'] ?? null) === 'ok');

// 알 수 없는 prefix는 throw
$threw = false;
try {
    notifyRenderVariables(['#{x}' => 'cool:typo'], $row);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
t('vars: unknown prefix throws', $threw);

// ── notifyCronMatches ──────────────────────────────
$ts = strtotime('2026-04-23 21:00:00'); // 목요일 (DOW=4)
t('cron: every minute',         notifyCronMatches('* * * * *', $ts));
t('cron: exact 21:00',          notifyCronMatches('0 21 * * *', $ts));
t('cron: 22:00 not matching',   !notifyCronMatches('0 22 * * *', $ts));
t('cron: list 21,22',           notifyCronMatches('0 21,22 * * *', $ts));
t('cron: range 20-23',          notifyCronMatches('0 20-23 * * *', $ts));
t('cron: step */5 hour 20',     notifyCronMatches('0 */5 * * *', strtotime('2026-04-23 20:00:00')));
t('cron: dow=Thu 4',            notifyCronMatches('0 21 * * 4', $ts));
t('cron: dow=Mon 1 not match',  !notifyCronMatches('0 21 * * 1', $ts));
t('cron: dow Mon-Fri',          notifyCronMatches('0 21 * * 1-5', $ts));

// '*/0' 스텝은 무한루프/0-나누기 방지를 위해 매칭 안 됨
t('cron: */0 step never matches', !notifyCronMatches('*/0 * * * *', $ts));

// 5필드가 아닌 cron 식은 false 반환 (parse 안 함)
t('cron: malformed 4 fields',     !notifyCronMatches('* * * *', $ts));

// ── solapi HMAC ──────────────────────────────
require_once __DIR__ . '/public_html/includes/notify/solapi_client.php';

// 솔라피 공식 헤더 형식: HMAC-SHA256 apiKey=..., date=..., salt=..., signature=...
$header = solapiBuildAuthHeader('TESTKEY', 'TESTSECRET', '2026-04-23T12:00:00Z', 'abcdefgh');
t('solapi: header has scheme',  str_starts_with($header, 'HMAC-SHA256 '));
t('solapi: header has apiKey',  str_contains($header, 'apiKey=TESTKEY'));
t('solapi: header has date',    str_contains($header, 'date=2026-04-23T12:00:00Z'));
t('solapi: header has salt',    str_contains($header, 'salt=abcdefgh'));

// 결정적 시그니처 검증 (HMAC-SHA256(date+salt, secret))
$expected = hash_hmac('sha256', '2026-04-23T12:00:00Z' . 'abcdefgh', 'TESTSECRET');
t('solapi: signature correct', str_contains($header, "signature={$expected}"));

// 페이로드 빌드 (알림톡)
$payload = solapiBuildAlimtalkPayload(
    to: '01012345678',
    from: '025001111',
    pfId: 'KA01PF',
    templateId: 'KA01TP',
    variables: ['#{name}' => '홍길동', '#{deadline}' => '4월 30일']
);
t('payload: type ATA',          $payload['type'] === 'ATA');
t('payload: to normalized',     $payload['to'] === '01012345678');
t('payload: kakao pfId',        $payload['kakaoOptions']['pfId'] === 'KA01PF');
t('payload: kakao templateId',  $payload['kakaoOptions']['templateId'] === 'KA01TP');
t('payload: vars present',      ((array)$payload['kakaoOptions']['variables'])['#{name}'] === '홍길동');

// 빈 variables는 JSON 직렬화 시 '{}' 이어야 함 (솔라피 spec 요구, 빈 []는 4xx)
$emptyPayload = solapiBuildAlimtalkPayload('01000000000', '025001111', 'PF', 'TP', []);
t('payload: empty vars as {}',  str_contains(json_encode($emptyPayload), '"variables":{}'));

// ── dispatcher status decision (단위) ────────────
require_once __DIR__ . '/public_html/includes/notify/dispatcher.php';
t('status: no_targets',    notifyDecideBatchStatus(0, 0, 0, 0) === 'no_targets');
t('status: completed all', notifyDecideBatchStatus(5, 5, 0, 0) === 'completed');
t('status: partial mixed', notifyDecideBatchStatus(5, 3, 2, 0) === 'partial');
t('status: partial unk',   notifyDecideBatchStatus(5, 3, 0, 2) === 'partial');
t('status: failed all',    notifyDecideBatchStatus(5, 0, 5, 0) === 'failed');
t('status: skipped only',  notifyDecideBatchStatus(5, 0, 0, 0) === 'completed');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
