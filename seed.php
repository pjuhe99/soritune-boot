<?php
/**
 * boot.soritune.com - Seed Data
 * Run once after migrate.php: php seed.php
 * Then delete this file.
 */

require_once __DIR__ . '/public_html/config.php';

$db = getDB();

echo "=== boot.soritune.com Seed Data ===\n\n";

// ── Settings ──
$settings = [
    ['current_cohort', '1기', '현재 활성 기수'],
    ['site_name', '소리튠 부트캠프', '사이트 이름'],
];

$stmt = $db->prepare('INSERT IGNORE INTO settings (`key`, `value`, description) VALUES (?, ?, ?)');
foreach ($settings as $s) {
    $stmt->execute($s);
}
echo "[OK] Settings seeded\n";

// ── Admin Accounts ──
$defaultPassword = 'boot2026!';

$admins = [
    ['운영팀', 'operation', $defaultPassword, 'operation', null, null, null],
    ['총괄코치', 'head1', $defaultPassword, 'head', '1기', null, null],
    ['코치A', 'coach1', $defaultPassword, 'coach', '1기', null, '월수금 10:00'],
    ['팀장A', 'leader1', $defaultPassword, 'leader', '1기', 'A조', null],
];

$stmt = $db->prepare('INSERT IGNORE INTO admins (name, login_id, password_hash, role, cohort, team, class_time) VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ($admins as $a) {
    $a[2] = password_hash($a[2], PASSWORD_DEFAULT);
    $stmt->execute($a);
}
echo "[OK] Admin accounts seeded (default password: {$defaultPassword})\n";

// ── Sample Calendar ──
$stmt = $db->prepare('INSERT IGNORE INTO calendar (week_label, start_date, end_date, content, cohort) VALUES (?, ?, ?, ?, ?)');
$stmt->execute(['1주차', '2026-02-23', '2026-03-01', 'OT 및 오리엔테이션, 기본 과제 배포', '1기']);
$stmt->execute(['2주차', '2026-03-02', '2026-03-08', '본격 수업 시작, 팀별 미션 진행', '1기']);
echo "[OK] Calendar seeded\n";

// ── Sample Tasks ──
$stmt = $db->prepare('INSERT INTO tasks (title, role, start_date, end_date, content_markdown, cohort) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->execute(['출석 체크 완료', 'leader', '2026-02-23', '2026-03-01', '매일 팀원 출석 현황을 확인하고 미출석자에게 연락합니다.', '1기']);
$stmt->execute(['주간 피드백 작성', 'coach', '2026-02-23', '2026-03-01', '담당 수업 학생들의 주간 피드백을 작성합니다.', '1기']);
$stmt->execute(['운영 회의 준비', 'head', '2026-02-23', '2026-03-01', '주간 운영 회의 안건을 준비합니다.', '1기']);
$stmt->execute(['시스템 점검', 'operation', '2026-02-23', '2026-03-01', '사이트 정상 동작 여부를 확인합니다.', '1기']);
echo "[OK] Tasks seeded\n";

// ── Sample Guides ──
$stmt = $db->prepare('INSERT INTO guides (title, url, role, note, cohort, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->execute(['팀장 업무 매뉴얼', 'https://docs.google.com/document/d/example1', 'leader', '팀장 기본 업무 프로세스', '1기', 1]);
$stmt->execute(['코치 수업 가이드', 'https://docs.google.com/document/d/example2', 'coach', '수업 운영 가이드라인', '1기', 1]);
$stmt->execute(['총괄 운영 가이드', 'https://docs.google.com/document/d/example3', 'head', '총괄 업무 매뉴얼', '1기', 1]);
$stmt->execute(['운영팀 매뉴얼', 'https://docs.google.com/document/d/example4', 'operation', '전체 운영 매뉴얼', '1기', 1]);
echo "[OK] Guides seeded\n";

// ── Sample Members ──
$stmt = $db->prepare('INSERT INTO members (name, phone, cohort) VALUES (?, ?, ?)');
$stmt->execute(['홍길동', '01012345678', '1기']);
$stmt->execute(['김철수', '01098765432', '1기']);
echo "[OK] Sample members seeded\n";

echo "\nSeed complete.\n";
