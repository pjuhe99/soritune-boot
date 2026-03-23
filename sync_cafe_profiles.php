<?php
/**
 * 카페 유저 키 동기화 스크립트 (CSV 파일 기반)
 * 
 * 사용법: php sync_cafe_profiles.php <csv_file_path>
 * CSV 형식: 1열 - 아이디(user_id) 또는 이름(real_name), 2열 - 카페게시글번호(article_id)
 * 
 * 동작 방식:
 * 1. CSV에서 식별자와 게시글 번호를 읽어옵니다.
 * 2. 게시글 번호를 바탕으로 네이버 카페 API를 호출하여 memberKey와 닉네임을 추출합니다.
 * 3. 식별자로 매칭된 bootcamp_members 테이블의 회원 정보를 업데이트합니다.
 */

require_once __DIR__ . '/public_html/config.php';
$db = getDB();

if ($argc < 2) {
    die("사용법: php sync_cafe_profiles.php <csv_file_path>\n");
}

$csvFile = $argv[1];
if (!file_exists($csvFile)) {
    die("오류: 파일을 찾을 수 없습니다 - {$csvFile}\n");
}

// 1. DB에서 활성 멤버 불러오기 (user_id 및 real_name 매핑)
$stmt = $db->query("SELECT id, user_id, real_name FROM bootcamp_members WHERE is_active = 1");
$membersByUserId = [];
$membersByName = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $uid = trim($m['user_id'] ?? '');
    $name = trim($m['real_name'] ?? '');
    
    if ($uid) {
        $membersByUserId[$uid] = $m['id'];
    }
    if ($name) {
        $membersByName[$name][] = $m['id'];
    }
}

$updateStmt = $db->prepare("UPDATE bootcamp_members SET cafe_member_key = ? WHERE id = ?");

function getCafeUserInfo($articleId) {
    // 네이버 카페 게시글 조회 API (인증 불필요)
    $cafeId = 23243775; // 소리튠영어 카페 ID
    $buid = 'a968c143-ebd4-46bb-82ff-5f11230389c5';
    $url = "https://article.cafe.naver.com/gw/v4/cafes/{$cafeId}/articles/{$articleId}?fromList=true&menuId=292&tc=cafe_article_list&useCafeId=true&buid={$buid}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    // User-Agent를 설정해야 정상적으로 응답을 받을 수 있습니다.
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode !== 200) {
        return ['error' => "HTTP 오류: {$httpCode}"];
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['result']['errorCode'])) {
        return ['error' => $data['result']['message'] ?? '게시글 접근 불가'];
    }
    
    // 정상 경우
    if (isset($data['result']['article']['writer'])) {
        $writer = $data['result']['article']['writer'];
        return [
            'memberKey' => $writer['memberKey'] ?? '',
            'nick' => $writer['nick'] ?? ''
        ];
    }
    
    return ['error' => '작성자 정보를 찾을 수 없습니다.'];
}

if (($handle = fopen($csvFile, "r")) !== FALSE) {
    $headerSkipped = false;
    $successCount = 0;
    $failCount = 0;
    $skipCount = 0;
    $notFound = [];
    $duplicates = [];
    
    echo "동기화 시작...\n";
    echo str_repeat('-', 60) . "\n";
    
    while (($data = fgetcsv($handle, 1000, ",", '"', "\\")) !== FALSE) {
        if (empty(trim($data[0] ?? '')) && empty(trim($data[1] ?? ''))) continue;
        
        $identifier = trim($data[0]);
        $articleId = trim($data[1] ?? '');
        
        // 헤더 로직 판단
        if (!$headerSkipped) {
            $headerSkipped = true;
            if (in_array($identifier, ['아이디', '이름', 'user_id', 'id', 'real_name', '회원명']) || 
                in_array($articleId, ['글 아이디', '글번호', 'article_id', '카페 게시물 링크 번호'])) {
                continue; // 헤더이므로 건너뜁니다
            }
        }
        
        if (empty($articleId)) {
            echo "  [SKIP] {$identifier} - 게시글 번호 없음\n";
            $skipCount++;
            continue;
        }

        // 회원 매칭 찾기
        $memberId = null;
        if (isset($membersByUserId[$identifier])) {
            $memberId = $membersByUserId[$identifier];
        } elseif (isset($membersByName[$identifier])) {
            $ids = $membersByName[$identifier];
            if (count($ids) > 1) {
                $duplicates[] = "{$identifier} (동명이인)";
                echo "  [FAIL] {$identifier} - 동명이인이 2명 이상 존재합니다. 아이디(user_id)를 사용해주세요.\n";
                $failCount++;
                continue;
            }
            $memberId = $ids[0];
        }

        if (!$memberId) {
            $notFound[] = $identifier;
            echo "  [FAIL] {$identifier} - 매칭되는 회원을 DB에서 찾을 수 없습니다.\n";
            $failCount++;
            continue;
        }

        // API 호출하여 정보 가져오기
        $userInfo = getCafeUserInfo($articleId);
        if (isset($userInfo['error'])) {
            echo "  [FAIL] {$identifier} (게시글 {$articleId}) - API 오류: {$userInfo['error']}\n";
            $failCount++;
            continue;
        }

        $cafeKey = $userInfo['memberKey'];
        $nick = $userInfo['nick'];

        if (!$cafeKey) {
            echo "  [FAIL] {$identifier} (게시글 {$articleId}) - memberKey 추출 실패\n";
            $failCount++;
            continue;
        }

        // DB 업데이트 (cafe_member_key만 업데이트, 닉네임은 유저가 설정한 값 유지)
        try {
            $updateStmt->execute([$cafeKey, $memberId]);
            $successCount++;
            echo "  [OK] {$identifier} -> cafe_nick: {$nick} (Key: {$cafeKey})\n";
        } catch (PDOException $e) {
            echo "  [FAIL] {$identifier} - DB 업데이트 오류: {$e->getMessage()}\n";
            $failCount++;
        }
        
        // 네이버 API 레이트 리밋 방지를 위한 딜레이 (n8n wait 대체)
        usleep(300000); // 0.3초 (n8n 1.1버전에서 설정한 Wait 0.3초 반영)
    }
    fclose($handle);
    
    echo "\n=== 동기화 완료 ===\n";
    echo "성공: {$successCount} 명\n";
    echo "건너뜀 (게시글 번호 없음 등): {$skipCount} 명\n";
    echo "실패 (API오류, 회원정보없음 등): {$failCount} 명\n";
    if (!empty($notFound)) {
        echo "  DB 회원 없음: " . implode(', ', array_unique($notFound)) . "\n";
    }
    if (!empty($duplicates)) {
        echo "  동명이인 (처리불가): " . implode(', ', array_unique($duplicates)) . "\n";
    }

} else {
    echo "오류: CSV 파일을 읽을 수 없습니다.\n";
}
