# head 대시보드 '주의 필요 멤버' CSV 내보내기

작성일: 2026-06-01

## 목적
head 대시보드(`/head/#dashboard`)의 **주의 필요 멤버** 섹션을 CSV로 내려받아
오프라인 공유/관리에 쓸 수 있게 한다.

## 범위
화면에 표시되는 경고 멤버 **3개 그룹 전부** 포함:
- 부활 후보 (`approaching`, -8 ~ -9점)
- 부활 대상 (`revival_eligible`, -10 ~ -24점)
- OUT (`out`, -25점 이하)

## 컬럼 (순서 고정)
| # | 헤더 | 출처 |
|---|------|------|
| 1 | 닉네임 | `nickname` |
| 2 | 이름 | `real_name` |
| 3 | 조 | `group_name` |
| 4 | 휴대폰번호 | `phone` → 하이픈 포맷(`010-1234-5678`) |
| 5 | 점수 | `current_score` |
| 6 | 태그 | 멤버가 속한 그룹: `부활 후보` / `부활 대상` / `OUT` |
| 7 | 담당 코치 | 조 담당 코치명 (여러 명이면 `, ` 결합) |

## 접근
**클라이언트 사이드 생성.** 대시보드가 이미 받아둔 `dashboard_stats`의
`score_warnings` 데이터로 브라우저에서 CSV를 만들어 다운로드한다.
현재 기간 필터가 그대로 반영된다. 새 엔드포인트/인증 불필요.

## 변경 사항

### 백엔드 — `api/services/dashboard.php`
score_warnings 빌드 루프(`$info` 배열)에 `coach` 한 필드 추가.
기존 `$coachMap`(group_id → 코치명, `coach_group_assignments` 기반, 이미 빌드됨) 재사용:
```php
'coach' => $coachMap[$mr['group_id']] ?? ''
```
이것이 유일한 서버 변경. 다른 응답 형태 불변.

### 프론트엔드 — `js/bootcamp.js`
1. '주의 필요 멤버' 섹션 제목 줄에 `CSV 내보내기` 버튼 추가 (경고 멤버 있을 때만 노출).
2. 클릭 → `approaching → revival_eligible → out` 순서로 한 표에 모아 CSV 생성·다운로드.
3. CSV 생성은 순수 함수 `buildWarningCsv(score_warnings)` 로 분리(단위 테스트 대상).

## Excel 안전장치 (메모리 피드백 반영)
- **UTF-8 BOM**(`﻿`) 선두 → 한글 깨짐 방지.
- 휴대폰번호 **하이픈 형식** 출력 → 엑셀이 텍스트로 인식, 앞자리 0/과학표기 손상 방지.
  (cf. `feedback_excel_phone_corruption`)
- 필드에 `,` / `"` / 줄바꿈 포함 시 `"`로 감싸고 내부 `"`는 `""`로 이스케이프.

## 다운로드
`Blob`([BOM + csv], `text/csv;charset=utf-8`) + 임시 `<a download>`.
파일명 `주의필요멤버_YYYYMMDD.csv` (오늘 날짜).

## 테스트/검증
- `buildWarningCsv` 순수 함수 단위 테스트(node): 3그룹 결합 순서, 태그 매핑,
  하이픈 포맷, 콤마/따옴표 이스케이프, BOM, 빈 데이터.
- `dashboard.php` PHP lint.
- bootcamp.js JS 문법 체크.

## 캐시버스터
`bootcamp.js`는 `v()` mtime 자동 갱신 + sw.js network-first → 별도 버전 bump 불필요.

## 비범위 (YAGNI)
- 백엔드 CSV 엔드포인트(불필요).
- 정렬 변경/필터 옵션(현행 화면 순서 유지).
- operation/coach 대시보드 별도 처리(같은 bootcamp.js 경로라 자동 동반되면 무방).
