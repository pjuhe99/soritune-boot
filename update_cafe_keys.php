<?php
/**
 * 카페 유저 키 일괄 업데이트
 * real_name 기반으로 bootcamp_members.cafe_member_key 설정
 */
require_once __DIR__ . '/public_html/config.php';
$db = getDB();

// 시트 데이터: [이름, 카페유저키]
$rows = [
    ['고금영', 'rE4aDGgtt3h685H7Ox9WGmPaIOF_G-NYv8IR4sBRPOk'],
    ['김경민', ''],
    ['김윤서', '-wWktT9X4I1AlRLeeThjkAcIrW5-L0m23YeUb_kLMsA'],
    ['박세준', 'Oh4C2568ziQP7P6yQKTOsg'],
    ['박수연', '4dxKEIJ3o56jnnBfM3undeUraBHg0SPv-mpF30X16Zo'],
    ['서숙희', 'Zp_OJugE7jNXMk2uArw3mMKAAOdBox1fcUhOP-94PwM'],
    ['조형찬', 'jvgCJfXzstgG6W2CW3uAHPKKugeOr1LTkYLMr1mDyYs'],
    ['KeeSook Burks (장기숙)', ''],
    ['이재하', 'C-B-T6QgC9_pyG89iRcDyyQ6N2phmNgvqyfxAHirv6k'],
    ['장혜영', ''],
    ['김보연', 'UT7-dI4SVsxHIwZ8ultiqw'],
    ['유소희', 'Q5v0uxufjUBh38rfdAVKA-9Tmp--Rypi04NbzaNzkDQ'],
    ['박수진', 'X0M-9e1z2FBoZ4wXtzq4B0wQ2AZnDtpnYl-cmBjpF28'],
    ['주자영', 'TyJRRPqV2V8lhqQGtZZ_VdLNGLNTvX5QA8RstdsvAP0'],
    ['조영주', 'JCgmhd_sk80JBDsCUkXfotYirlZrMVKKEK6qFYG5yyM'],
    ['최종원', 'ATQWWBIrjzN_M8uZJBwRz6UDu2hDYhEDOHE_2_4gk4c'],
    ['방숙현', 'm4Rtxv0fapqr8wONrma57dOCU8ReQ3T5TU01cYP0zuI'],
    ['김수련', 'bdaUBMBjl9P6mk6v1hikMuLeh5WonllYr5OHYyZNubA'],
    ['전지혜', 'z4MedqIBVmqvI0xwLph9OQ'],
    ['김도현', 'wSt6GjPzh8wiGXw3xaPTSHIae2jVzpMRpJZYJgENZ7Y'],
    ['김민조', 'tHO8TG06Z9jpqK7wAKW9nXM3Ap7XSfppKymCm_BMooM'],
    ['김서아', 'qYlhJAkjVIqeXM3_YD9Utg'],
    ['김성훈', 'wqTkWZ5R3g7woQVbFTcfy6fNjLvdA_J1-DwWqmANyuA'],
    ['남정은', 'CnkM_lXYRFzuqJ9zM-MdZgCI6C_b8aY4fyFjVlgfYxw'],
    ['김은영', 'XEY6PSY-w7IHNMke5Uog9AV_7P-FNB6zEF7xa7o4nyo'],
    ['지수정', 'LEBTp__jNxhdTaVZzdId5wvEBCBF7BNersqcujt5XvU'],
    ['김성은', 'Xwh56iewHPjSBe8S_IjNXH3atFN-Mk3m_aqwcINZTKk'],
    ['조도현', 'O74b1z6ZNTkYkq8r9QeJwXctDxE0sjFv-OXUsfN4csw'],
    ['최윤희', 'PBw0syHvUEpSJkz6lpkEEUK2X0XpoIu5yW-kN8LOlp4'],
    ['맹진아', 'rlkLOwfiAtlishzXYRDtQWZIy7r_pcDmQqtuzzg_jKQ'],
    ['조미리', 'ph4lwi5gG01Juiwt5Dd3sizMDhRSkWzjB6HWJJEWzJ4'],
    ['이지영', 'Oqjk_oDCLOdbN_IKLjQJtA'],
    ['주지혜', 'vStwmW-3I-CnpBBYHzkPfqhaJUo-4Yg3n3P4zyDqICE'],
    ['한은령', 'dwq3Y8X1U2tRqs8ewy3Qu0Ci-4aExzlD_Vc2Ij25DpU'],
    ['서지혜', 'IfjO62gvziwzXD8LbWNkJFu8tDtCuyOPZBbMgvmTHvs'],
    ['김난주', 'IhkZjo1-kI0iHmvODzRBNMUa70yQRe0Y-K3DIFeycyc'],
    ['이영숙', 'PPz7tgk8qwCEIkpcp7dXjA'],
    ['김은미', '4r_RD17OuWPDOVmfPgxBnhTM6XQ_YvgC7FeT8E9gGIU'],
    ['김태경', 'I1ilOOIMUR64KgMpEckjp4szXpYQx33QTZaSSuP7yOY'],
    ['임보아', 'fD1lN29ViisNXV2PER-NyXUZ8-bXMtCcqdhUKFST-z8'],
    ['이수향', 'RtsOpvHC1_yjPjt54PIpE_Qze9OgpyJhE-hckvbp_gY'],
    ['서주희', 'TqvEt19uhzSL5FE78hwfITNEgsM8M1PsksyoqjfiyNM'],
    ['김민화', 'LQz2KIEu82xm6va9Dej8wA'],
    ['송지수', 'dMjTxIXfvi3-UIwhgMnHxEv6GG8TjWQ26kenbdyiWwI'],
    ['정미영(Miyoung Jung)', 'PY8NicgNI9R93uyfDAmdtg'],
    ['김준웅', 'y9yBs6AsdG7rkStS_wF01xkBh0NmL0GKyqGOav7JCBw'],
    ['최명옥', 'CnWfzHBCKFCjGrLMaPVwXKoZqUTeSmIUeooifSULLqQ'],
    ['이지연', 'OYoAG2bsWRR1rM88W6IvoGjahyQvO0BqH2SBVRfRYhQ'],
    ['김아람', '2F123q3ijOL0ncFSrOngJ8pupsKFtBilJRtIXpxo6UA'],
    ['안보영', 'zJX4zrMk-s4UZ7f6dtjK0ppx-9MciG_dVXPQkEkkY6Y'],
    ['손수빈', 'wczHUyP10ESuWbQyMywMZw'],
    ['김미선', 'Waj5QLgvRuwNsPKIdz6RH8o97pytSr734CiPytuIzms'],
    ['윤유선', 'VEeAV7oUQvjXtXN-3UFA0w'],
    ['김희진', 'nNrISbd0TEewFbF6OqhCSKgf3AZpUW-4W93mwV-doHk'],
    ['홍진서', ''],
    ['김옥경', 's-IlQI6k23H9R6boOzsCRyGwwmrGDLW0imP9roFPYdg'],
    ['홍가히', 'k65AirvMXEd9gYLh-Vx9s6I_lxw7DPxuFARBKyfNwa0'],
    ['윤미연', '5DQOtpgp6qgEea-Xr2A13szd9veKNI9Gxpd2uZ8xEpE'],
    ['배정은', '3bEQkJwjgcJ81BuuAr6pjA'],
    ['진혜련', '7_SWU-emnWyNqdfM8Hpne4yGtNYfnTf99YYrYMQB_JM'],
    ['이광현', 'eiEUpK7ghxEj-AiTMh81nvi70lru3fzvOdLBra23WPo'],
    ['나종철', 'PqdGT1qrSieY_2vjWsz7qEYTDvKBwcBJwAURBCvDob0'],
    ['문기명', ''],
    ['박효근', 'JLOZiYSTVdUsgZ15LdtSIoVgn8KSS8bqnsGUti1JWZg'],
    ['김윤지', 'oMRi3Nmk-MB1BRs58xUJ-5ekdgNoDuOIDK2pI5b4_OM'],
    ['서현주', 'hCH-gkw7nICjZr2OS0Ah5Vs3T3bFklP9sxbwKp0TL2w'],
    ['강현', 'AidIFFxuHK61Usu91q0kLA'],
    ['김태은', 'J-q16JSm6zlp7_cgSNM3LYbiCnPF9v32VKam_hlkdww'],
    ['황해성', 'iVUi4QV-JhPCBhG-XQaYpU64XtwsE1LzWxKqARC_qFg'],
    ['주영인', ''],
    ['정지애', 'ZcD3BweZjqTh7qlyXcWxww'],
    ['장혁진', ''],
    ['김미영', 'dBytGVmxD39mQGs6ADrBmmH6gfdxXt9blpFk60804Vc'],
    ['김우성', 'TY0H2nZaEjXiRc5u8_OjU03aApwNsmEc2UzM71r3Mgo'],
    ['김주민', 'nCd3CqZ7O0Lqg1bHPqWJaIQ6U1r83n1-lPOHOn8JKPI'],
    ['최혜미', 'fFgHh0gx-EGA9gEhvxEU_Q51qJTCeoFuSt_0Pi7PV5k'],
    ['강민영', 'ANzHltW7IjzTyTcXmKLiWAzHgzs-QhHTgqAeyB34MQs'],
    ['오미현', 'QQAT96bXDzOHQmnsh-xggE8qChLzE-7eZDEx7BvzliY'],
    ['정찬환', '5js0CCIzhXBu0kR1wX4Y3zjUYyuEbomY6yMhxXZquO4'],
    ['배성미', ''],
    ['임정민', 'mGk_9d4YC1pXO8ulwWM2yEZsK0q4e4tyvyvcjf2gaTc'],
    ['박진봉', 'fpK4gE_Ru1zUVe8enGDpAkrEQNdHHXrJMxTgSAkKfmk'],
    ['장은솔', 'zqwUZz84JGNelJy8umtUOQG02nuSa_3LXetSX80CJlo'],
    ['이선형', ''],
    ['하누리', 'UVM1ez6EZaqFo02Ufm_vNBPgIWL975IYLQ3SzTHD_h8'],
    ['김공명', '4j9NKULgPFsryWMZPnDqBQQ95H9YwRuNDnkN0XDhcbw'],
    ['김미숙', 'G-CTruMHCLKMPUBw_0MtcR78Tcir5ajnOElSo0VvPbY'],
    ['윤상희', 'JVd02Znz8ydCMxQybelh1_RtZy2tC2kSj-6uQV6sSZE'],
    ['유용미', 'Ts3C_S47EEwIBbp4UuTx3HuibGxUHxKpLEIdP059HLY'],
    ['위혜지', 'cxlYeWzEbgvxmJSzcbNZNR6LPDmdl4ZxffM6dYCkuDM'],
    ['박지민', 'nHv-7JRO011izIjEXmMEylEZsnRov8xuWPDtdgCbdMc'],
    ['서민기', '0msbx2zuzv_mbim1IvCXtA'],
    ['박규현', 'hmhNl0FeDg4WxUPN7UGPcxOt7O7k9AJHepAmqovAB7E'],
    ['조민혜', '9Ko3tjytxqFa55-sjwzvikLn0LnvEyrfReOrXTvjnak'],
    ['강연오', ''],
    ['성혜원', '9NqWIscAQg9ewCke5uW-CIbZfnDP2IEN7o7_FK29o2g'],
    ['최승희', 'Al5GZz2J5UdeMlztTBTqx6g3woX-U66i-JRnHsY_Fek'],
    ['박현준', '-c0qrKdz0oZSQlZ1xFijZbUjDbdw8IkIJSoJ5-uZjPk'],
    ['윤지혁', 'lLIZYipNUgNQAc-XGem4biEELULXjOUTe3JKSrwwrvg'],
    ['이미영', 'zp4i8NdB-GATZkwJCPIXIkMFqcUk-YYpb0njfy2SbJs'],
    ['김혜승', 'aWHN8WKXPY4E2jswr646LPshqeQ6sbZcskYpNstKSKw'],
    ['서승원', 'b4a4DNcmpNRIR7JWNGe3pOm8axgkTFlE15ASrr-wmj8'],
    ['신민희', 'sY5Drqk8pvs3wgMpQtZYVltO17hj9ruUMGXlTYEP6Nk'],
    ['홍효정', 'S-ceKkya93EPVwSUtc0FRg'],
    ['조나영', 'sIKdNfSC1ULRS2rj8mC-FQ'],
    ['신상호', 'n0egNqNrykcAXUZlMjcaGkv3laOGCi9R8jx1vVvxQJo'],
    ['서설원', 'NJJF8buWnnERKVdZJr2qjw'],
    ['박영주', 'Zw6QkP2fwZo4Gwcgi_KGcg'],
    ['송지영', '4KQtSUD44XO8OC2TzcZKrkfU_9KM8BVf8U8XrvLgYR0'],
    ['김수진', 'sHqCuG69Bkd_kb6GxqCp3UnCmmRvhhEYMI1WcOobhJU'],
    ['김은아', 'MTnqVfdfTLyNsiZfHL4hsknRqo1Xs9gBGKMNHQ-VQ74'],
    ['강민정', 'IiRg8S7FGehdP8QzivnQTJpBGKXbIcNMWMsjZ6cSMx0'],
    ['박대근', ''],
    ['이택주', 'f30w-zY8zOvhD7tXrbTumhBwy-d8A7JJiCaUjCJevDY'],
    ['김서인', 'tUvlq-03br89JzLNaSCwML16BAL_OgOVgo-HR5AZ_Y4'],
    ['장송영', 'RKbI6B-gioFghYe-hnM5ESjryWxH1UZ1NnrtL046MVY'],
    ['서은숙', 'Yb2FV9h8R5WQmDWhCvBUQUFOrU_4rJTgHZbioIda1PM'],
    ['박지훈', 'sHqCuG69Bkd_kb6GxqCp3UnCmmRvhhEYMI1WcOobhJU'],
    ['차윤경', 'DsZgKPrdiU5ktL5sRdN20hjXL7hecC-QLMHlyISCUd0'],
    ['임병을', 'e3pbA6WnqHt9iJLJYM88zFjfe38AYvTI5WiPblUaHw0'],
    ['권혁주', 'UvFxPwtREFG1tLylPC5ngJpKumFNFzF-B9GT6QihiZ8'],
    ['권율', 'WBDucoc7iOdiLKtX_SA4HqRvBxmpjgtL_z2WOPS8v_A'],
    ['배윤성', '4_27imLWW5U89Qr-zh29oF0R6oZrdvP842ONi2XOK2E'],
    ['김상수', ''],
    ['김형선', 'Xcy2_LCZATjUK-TIzlSSvCLQi9hxgv_wiT5T12fvSLM'],
    ['박슬기', 'GV_V34veHBOnaskUlvddctEIJ4UObkFF-Z_dDQFdF_E'],
    ['강신래', 'Qs8b1bmmkxGmwxilvSjI9Q57FMmkjrO7pgkXyemMEr8'],
    ['유예정', 'ODsQwnMaEXBGMgjvk4FHGr6kQBRKgD2t187-DJzk51w'],
    ['이재진', ''],
    ['안정훈', '8iYkvgBQ1SSWJkmEkrY3yZe5gZyFz-Sg2jUoxgEDy3c'],
    ['이재덕', '-Gw7frWOdi67DKAR6OjYSw'],
    ['박선욱', 'eUKURrLH6jALsGVxHqUH07sBW0p2HqAGq7lP0LmOH8o'],
    ['김지혜', 'EHKgy7QpPKOfRYTa9MC5miTHHPSaYmd35jR11p5iEXA'],
    ['유숙현', 'VaZQJd8-piqC1Yq2FvK19LIJIts1EcYXUeQCYyiCM3A'],
    ['허제호', '2exrsV6gn8bkeA5UfJFo5CuioavFAlD2Zxlw8ojIuow'],
    ['이혜숙', 'IOMCZoimkjV-1Atb5UefR5u14WnILNNlVq-MlVeKZWg'],
    ['박종은', 'KDa_dfnW28GUFmU_GrsM-btrpju9CkJJXQLTAr4cufo'],
    ['고진현', 'XH_wVYrdawDgw0OFqRThERHab2fVqiEbsjUH-gpUdwg'],
    ['이나영', 'Osy54xDm2ZLSTTHJkFrQrhRwsjKmTyueqDjFD4z6_D0'],
    ['김주연', '1tK9jFy7IXOKTHnPSxARlYrvLLGLLLi6D3LB9yDnrIk'],
    ['이가현', 'B7bYxltO5YkUCoMBfou5Tu6ncYEx5LF6qWIPEsDQVOA'],
    ['홍혜진', ''],
    ['김용수', 'EYFghtkqVNGEJiSplRDWCg'],
    ['류지나', ''],
    ['유은영', 'dcx3HE7VJjpd7E0j4kpo2g'],
    ['김도원', '6F_vELY9-kbtMs_yhd77cSq-KQKabCkzuR7LfNg2uzQ'],
    ['김지원', 'vL3Thuxiax-yVnasi7HQvwPVV3-QSRKovvN25VcXn7E'],
    ['최재원', 'K4g6elqYPAfy-6OfutPdhFhfqFXQWvBaZPmR0EVr8qU'],
    ['박지현', 'vxFcNrVP8d84-dsewHBu9XnRbnLGrSOK_GGXiQYi5Q0'],
    ['이준서', 'AxKs2UiUu936A_qUhxKiM8lhYb7Qahy7wvYK13-j_NM'],
    ['곽혜진', 'XLg6r9KYM13RPsn0ATadthLhtCWQljJfl7qP7TOMjqc'],
    ['배찬미', 'SKl58wsk_zY7lWQCNcqVp4FfQZdPYpDoHGgB00J6aNE'],
    ['정종식', ''],
    ['초도용', 'YoaJIv8EoDVbMljN6IGdyQ'],
    ['김진수', 'K-ixGhP5rPGNMG1t-gI5Sw'],
    ['김원태', 'GvYlamEIFN74o21nNV1-wlxrMo7umtMDqdJQSuyXfrA'],
    ['이경은', 'SgGSlFSMLSIsP-Xh4BK-fnw3AgFq5ApBWIhn54XxY7g'],
    ['김동수', '9RMY40-WbjoCU4xR_WMdwuoMhrg7RUyb6IEtIp6zgzs'],
    ['이종찬', ''],
    ['노일호', 'wbJB1dYWbIafr0QG6BZ4CrkxogSSL2DNohNCkCUbD0w'],
    ['임유정', 'JF4VTLgG-QGUWlb1ApNEiVbUY5IePaQ8mQ6XB0mogpQ'],
    ['정승우', 'euvZLCjBL4_hJt3kKWo1lQiruhtLsPmBm7TTunU5Bmk'],
    ['송은주', 'vkxhHGCvMS7c1mLlhuKePCaV4-oF9TH2D7HeHWUr9vs'],
    ['김다미', 'Bmowxp-Rt18lPQVS4QjHYSFlmEGgzVlsjoW2zuK9Ye0'],
    ['임성민', 'MSfv1ZGn2WuLQEpjzYyTDDBizusSE8L4ENPkD_HQTxg'],
    ['김재훈', 'Bxhzr857Jn1BQqlJbsWheaFVzSTCvHmkzMyWt3S4L3c'],
    ['노예리', 'mPS2tTOw03C2onlX0atHMGMIWFKQHeJvgeOWWFqczbY'],
    ['이현주', ''],
    ['김윤정', '0DNvoPLhzLhKGQXmXySw2A'],
    ['박은미', 'X0xiLtnriCuMmTB18UUZ1dFb0Y2CBFnHdWHhvFF418g'],
    ['박상원', 'xFi-5555Mzu53cCPJVRvK3eO9yGMawQS7KVpTaPtevY'],
    ['박상현', 'gwUWGbHWP5ZcZ9PW8mPDCw'],
    ['김정화', '2gF7XHb7DiHoAhUZolta0w'],
    ['한승훈', 'TG2sQKk4F8DTWBwKuBf1eA'],
    ['김홍명', 'S5NmZsr5-XSlqQMcQlBGNz3YeJdHnzjkV--D8-tvpWw'],
    ['곽현경', 'WB7a3-e5PvdrkyN1c_ATcHw87FCu6OjwJOC_CPql-DY'],
    ['이진실', 'ZMmq591QHuQdVNC_y-qkUbhccUMNnKSV85Qp6Lxz2Gw'],
    ['박세환', ''],
    ['이민구', 'ruM4ivFRvbM5OfQbE3YcgYLV9zdlxceT8EhTZWvRG1k'],
    ['정진경', '4ged8dh3WFptmshfRlM7DS21Hzu0JUmsia09g5HfUgw'],
    ['유치현', '6kthYhRkYnob_kR99y0yahA8XNGphvNjXnsXU47Jvvw'],
];

// DB에서 real_name → id 매핑
$stmt = $db->query("SELECT id, real_name FROM bootcamp_members WHERE is_active = 1");
$dbMembers = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $name = trim($m['real_name'] ?? '');
    if ($name) $dbMembers[$name][] = (int)$m['id'];
}

$updateStmt = $db->prepare("UPDATE bootcamp_members SET cafe_member_key = ? WHERE id = ?");

$updated = 0;
$noKey = 0;
$notFound = [];
$duplicates = [];

foreach ($rows as [$name, $cafeKey]) {
    if (!$cafeKey) { $noKey++; continue; }

    if (!isset($dbMembers[$name])) {
        $notFound[] = $name;
        continue;
    }

    $ids = $dbMembers[$name];
    if (count($ids) > 1) {
        $duplicates[] = "$name (IDs: " . implode(', ', $ids) . ")";
    }

    $memberId = $ids[0];
    try {
        $updateStmt->execute([$cafeKey, $memberId]);
        $updated++;
        echo "  [OK] id={$memberId} {$name} -> {$cafeKey}\n";
    } catch (PDOException $e) {
        echo "  [FAIL] {$name}: {$e->getMessage()}\n";
    }
}

echo "\n=== 결과 ===\n";
echo "업데이트 성공: {$updated}건\n";
echo "카페키 없음 (스킵): {$noKey}건\n";
echo "DB 매칭 실패: " . count($notFound) . "건\n";
if ($notFound) echo "  매칭 실패 이름: " . implode(', ', $notFound) . "\n";
if ($duplicates) echo "  동명이인 주의: " . implode(', ', $duplicates) . "\n";

// 최종 매핑 현황
$total = $db->query("SELECT COUNT(*) FROM bootcamp_members WHERE is_active = 1")->fetchColumn();
$mapped = $db->query("SELECT COUNT(*) FROM bootcamp_members WHERE is_active = 1 AND cafe_member_key IS NOT NULL")->fetchColumn();
echo "\n전체 회원: {$total}명, 매핑 완료: {$mapped}명, 미매핑: " . ($total - $mapped) . "명\n";
