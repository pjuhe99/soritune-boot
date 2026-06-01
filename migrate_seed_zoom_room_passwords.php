<?php
/**
 * settings.zoom_room_passwords (JSON 방별 비번 맵) 시드/갱신.
 *
 * 회의 ID 는 줌 링크의 /j/<숫자> 가 권위이고, 공유/고정방은 zoom_meeting_id/zoom_password
 * 컬럼이 버려진 회의 값이라 신뢰 불가. 그런 방들은 운영자가 숫자 비번을 직접 등록해야 한다.
 *
 * 이 스크립트는 study_sessions / lecture_schedules / lecture_events 를 스캔해
 * "컬럼 회의ID == 링크 방ID 인 row 가 하나도 없는 방"(= 컬럼 비번을 신뢰할 수 없는 방)을
 * 찾아 zoom_room_passwords 맵의 키로 보장한다. 기존 값은 절대 덮어쓰지 않는다(빈 키만 추가).
 *
 *   php migrate_seed_zoom_room_passwords.php --db=dev          # 스캔 + 빈 키 시드 + 현황 출력
 *   php migrate_seed_zoom_room_passwords.php --db=prod
 *
 * 멱등: 이미 있는 방 키/값은 보존, 새 방만 빈 값으로 추가. 옛 study_fixed_zoom_password 키는 제거.
 */
$opts = getopt('', ['db:']);
$dbTarget = $opts['db'] ?? 'dev';

$path = $dbTarget === 'prod' ? '/root/boot-prod/.db_credentials' : '/root/boot-dev/.db_credentials';
if (!is_readable($path)) die("Credentials not found: {$path}\n");
$env = [];
foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_contains($line, '=')) {
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\"'");
    }
}
$dsn = "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4";
$pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// 링크 /j/ 방ID 추출식 (MySQL)
$ROOM = "SUBSTRING_INDEX(SUBSTRING_INDEX(zoom_join_url,'/j/',-1),'?',1)";

// 방별: 컬럼 회의ID == 링크 방ID 인 (=신뢰 가능한 컬럼 비번이 존재하는) row 가 있는지
$sql = "
SELECT room_id, MAX(col_matches_and_has_pw) AS has_trusted_col_pw, SUM(uses) AS uses
FROM (
  SELECT CONVERT($ROOM USING utf8mb4) AS room_id,
         MAX(zoom_meeting_id = $ROOM AND zoom_password IS NOT NULL AND zoom_password <> '') AS col_matches_and_has_pw,
         COUNT(*) AS uses
  FROM study_sessions   WHERE zoom_join_url LIKE '%/j/%' GROUP BY room_id
  UNION ALL
  SELECT CONVERT($ROOM USING utf8mb4),
         MAX(zoom_meeting_id = $ROOM AND zoom_password IS NOT NULL AND zoom_password <> ''),
         COUNT(*)
  FROM lecture_schedules WHERE zoom_join_url LIKE '%/j/%' GROUP BY $ROOM
  UNION ALL
  SELECT CONVERT($ROOM USING utf8mb4),
         MAX(zoom_meeting_id = $ROOM AND zoom_password IS NOT NULL AND zoom_password <> ''),
         COUNT(*)
  FROM lecture_events    WHERE zoom_join_url LIKE '%/j/%' GROUP BY $ROOM
) t
GROUP BY room_id
";
$rooms = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// 비번 수동 등록이 필요한 방 = 신뢰 가능한 컬럼 비번이 하나도 없는 방
$needPw = [];
foreach ($rooms as $r) {
    if ((int)$r['has_trusted_col_pw'] === 0) {
        $needPw[$r['room_id']] = (int)$r['uses'];
    }
}

// 기존 맵 로드 (값 보존)
$cur = $pdo->query("SELECT `value` FROM settings WHERE `key`='zoom_room_passwords'")->fetchColumn();
$map = [];
if ($cur) {
    $decoded = json_decode($cur, true);
    if (is_array($decoded)) $map = $decoded;
}

// 필요한 방을 빈 값으로 보장 (기존 값 보존)
$added = [];
foreach ($needPw as $roomId => $uses) {
    if (!array_key_exists($roomId, $map)) {
        $map[$roomId] = '';
        $added[] = $roomId;
    }
}

$json = json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$pdo->prepare("
    INSERT INTO settings (`key`, `value`, `description`)
    VALUES ('zoom_room_passwords', :v, '줌 공유/고정방 수동 입장용 숫자 비번 맵 {\"<회의ID>\":\"<비번>\"}')
    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `description` = VALUES(`description`)
")->execute([':v' => $json]);

// 옛 단일 키 정리
$pdo->prepare("DELETE FROM settings WHERE `key`='study_fixed_zoom_password'")->execute();

// 현황 출력
echo "[{$dbTarget}] zoom_room_passwords 갱신 완료\n";
echo "비번 등록 필요한 방 (신뢰 컬럼 비번 없음):\n";
foreach ($needPw as $roomId => $uses) {
    $val = ($map[$roomId] ?? '') === '' ? '(미설정)' : '(설정됨)';
    echo sprintf("  - %-13s  사용 %2d회  %s\n", $roomId, $uses, $val);
}
if ($added) echo "새로 추가된 빈 키: " . implode(', ', $added) . "\n";
echo "\n현재 맵:\n{$json}\n";
echo "\n→ 비번 채우기: UPDATE settings SET value='<JSON>' WHERE `key`='zoom_room_passwords';\n";
echo "  (또는 이 스크립트가 출력한 맵에서 빈 값을 채워 그대로 UPDATE)\n";
