<?php
/**
 * Notify 시스템 단위 테스트 CLI 러너 (PHPUnit 미사용 환경)
 * 사용: php test_notify.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');

require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/includes/notify/notify_functions.php';
require_once __DIR__ . '/public_html/includes/notify/dispatcher.php';

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

// ── notifyMapSolapiResponse — 솔라피 send-many/detail 실 응답 구조 기반 ──
$q2 = [
    ['msg_id' => 1, 'phone' => '01011111111', 'payload' => []],
    ['msg_id' => 2, 'phone' => '01022222222', 'payload' => []],
];

// 5xx → 모두 unknown
$r = notifyMapSolapiResponse(['ok' => false, 'http_code' => 503, 'body' => 'bad gateway', 'parsed' => null], $q2);
t('mapResp: 5xx unknown',       $r[1]['status'] === 'unknown' && $r[2]['status'] === 'unknown');
t('mapResp: 5xx sent_at null',  $r[1]['sent_at'] === null);
t('mapResp: 5xx channel none',  $r[1]['channel_used'] === 'none');

// http_code=0 (timeout/네트워크) → unknown
$r = notifyMapSolapiResponse(['ok' => false, 'http_code' => 0, 'body' => 'timeout', 'parsed' => null], $q2);
t('mapResp: timeout unknown',   $r[1]['status'] === 'unknown');

// 4xx → 모두 failed
$r = notifyMapSolapiResponse(['ok' => false, 'http_code' => 400, 'body' => 'bad request', 'parsed' => null], $q2);
t('mapResp: 4xx failed',        $r[1]['status'] === 'failed' && $r[2]['status'] === 'failed');

// 2xx + 전건 성공 (failedMessageList 비어 있음)
$r = notifyMapSolapiResponse([
    'ok' => true, 'http_code' => 200, 'body' => '',
    'parsed' => [
        'groupInfo' => [
            '_id' => 'GROUP_OK',
            'count' => ['total' => 2, 'registeredSuccess' => 2, 'registeredFailed' => 0],
            'status' => 'SENDING',
        ],
        'failedMessageList' => [],
    ],
], $q2);
t('mapResp: 2xx sent',           $r[1]['status'] === 'sent' && $r[2]['status'] === 'sent');
t('mapResp: 2xx channel ata',    $r[1]['channel_used'] === 'alimtalk');
t('mapResp: 2xx groupId saved',  $r[1]['solapi_message_id'] === 'GROUP_OK');
t('mapResp: 2xx sent_at set',    $r[1]['sent_at'] !== null);

// 2xx + 혼합 (1건 failedMessageList에 들어감)
$r = notifyMapSolapiResponse([
    'ok' => true, 'http_code' => 200, 'body' => '',
    'parsed' => [
        'groupInfo' => [
            '_id' => 'GROUP_MIX',
            'count' => ['total' => 2, 'registeredSuccess' => 1, 'registeredFailed' => 1],
            'status' => 'SENDING',
        ],
        'failedMessageList' => [
            ['to' => '01011111111', 'statusCode' => '4101', 'statusMessage' => '친구가 아닙니다'],
        ],
    ],
], $q2);
t('mapResp: 2xx per-msg fail',   $r[1]['status'] === 'failed' && $r[2]['status'] === 'sent');
t('mapResp: 2xx fail_reason set',str_contains((string)$r[1]['fail_reason'], '4101'));
t('mapResp: 2xx success groupId',$r[2]['solapi_message_id'] === 'GROUP_MIX');

// 2xx + 전건 실패 (failedMessageList에 모두 포함)
$r = notifyMapSolapiResponse([
    'ok' => true, 'http_code' => 200, 'body' => '',
    'parsed' => [
        'groupInfo' => ['_id' => 'GROUP_ALL_FAIL',
            'count' => ['total' => 2, 'registeredSuccess' => 0, 'registeredFailed' => 2]],
        'failedMessageList' => [
            ['to' => '01011111111', 'statusCode' => '4101', 'statusMessage' => 'X'],
            ['to' => '01022222222', 'statusCode' => '4102', 'statusMessage' => 'Y'],
        ],
    ],
], $q2);
t('mapResp: 2xx all failed',     $r[1]['status'] === 'failed' && $r[2]['status'] === 'failed');

// 2xx + parsed에 groupInfo도 failedMessageList도 없음 (malformed) → unknown
$r = notifyMapSolapiResponse([
    'ok' => true, 'http_code' => 200, 'body' => '',
    'parsed' => ['somethingElse' => 1],
], $q2);
t('mapResp: malformed unknown',  $r[1]['status'] === 'unknown' && $r[2]['status'] === 'unknown');
t('mapResp: malformed reason',   $r[1]['fail_reason'] === 'no_response_match');

// ── 쿨다운 가드 분기 ──────────────────────────
t('cooldown: 평소(24h, bypass=false) → 검사함',  notifyShouldCheckCooldown(24, false) === true);
t('cooldown: 0h, bypass=false → 무제한, 검사 안 함', notifyShouldCheckCooldown(0,  false) === false);
t('cooldown: 음수, bypass=false → 검사 안 함',    notifyShouldCheckCooldown(-1, false) === false);
t('cooldown: 24h, bypass=true → 우회',           notifyShouldCheckCooldown(24, true)  === false);
t('cooldown: 0h, bypass=true → 어쨌든 우회',      notifyShouldCheckCooldown(0,  true)  === false);

// ── 최대횟수 가드 분기 ─────────────────────────
t('max_attempts: 평소(3, bypass=false) → 검사함',     notifyShouldCheckMaxAttempts(3,  false) === true);
t('max_attempts: 0, bypass=false → 무제한, 검사 안 함',  notifyShouldCheckMaxAttempts(0,  false) === false);
t('max_attempts: 음수, bypass=false → 검사 안 함',     notifyShouldCheckMaxAttempts(-1, false) === false);
t('max_attempts: 3, bypass=true → 우회',              notifyShouldCheckMaxAttempts(3,  true)  === false);
t('max_attempts: 0, bypass=true → 어쨌든 우회',         notifyShouldCheckMaxAttempts(0,  true)  === false);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
