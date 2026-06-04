# Score Freshness: Stale 점수 자동 갱신

## 문제

`member_scores.current_score`는 체크 변경 시에만 재계산된다. 체크 변경이 없으면 점수가 stale 상태로 남아, 대시보드/멤버목록/패자부활 등에서 실제보다 높은(덜 감점된) 점수가 표시된다. 패자부활 시 stale 점수 기반으로 +7을 적용하면 이후 재계산에서 점수가 달라지는 버그 발생.

## 해결 방향

점수를 표시하는 API 진입 시, stale한 멤버만 일괄 재계산하는 `ensureScoresFresh($db, $cohortId)` 함수 도입.

## 설계

### `ensureScoresFresh($db, $cohortId)`

1. 해당 기수의 활성 멤버 중 `member_scores.last_calculated_at < TODAY` 인 멤버 ID 목록 조회 (1 쿼리)
2. 없으면 즉시 return (대부분의 호출은 여기서 끝남)
3. 있으면 해당 멤버들에 대해 `recalculateMemberScore()` 호출
   - 기존 함수를 그대로 재사용하여 로직 일관성 유지

### Staleness 기준

- `last_calculated_at < CURDATE()` — 오늘 날짜 기준으로 한번이라도 계산된 적 없으면 stale
- 하루 중 첫 조회에서만 재계산 발생, 이후는 캐시 활용

### 적용 대상 API

| API | 파일 | 용도 |
|-----|------|------|
| `dashboard_stats` | `api/services/dashboard.php` | 대시보드 |
| `check_list`, `check_list_date`, `check_list_grouped`, `check_member_detail` | `api/services/check.php` | 체크 관리 |
| `members` | `api/services/member.php` | 멤버 목록 |
| `member_page_detail` | `api/services/member_page.php` | 멤버 상세 |
| `revival_candidates` | `api/services/revival.php` | 패자부활 후보 |
| `revival_manual` | `api/services/revival.php` | 패자부활 처리 (처리 전 갱신) |
| QR `revival_record` | `api/qr.php` | QR 패자부활 (처리 전 갱신) |

### 패자부활 추가 수정

부활 처리(`handleManualRevival`, QR `revival_record`)에서는 `ensureScoresFresh` 호출 후, 이미 최신화된 점수를 읽어서 +7 적용. 기존 로직 변경 없이 진입부에 호출만 추가.

### 성능

- 활성 기수 멤버: ~224명
- 최악의 경우(하루 첫 조회): 224명 × 6쿼리 ≈ 1,300쿼리 (1회성)
- 이후 같은 날 조회: 0 추가 쿼리 (stale 멤버 없음)
- 체크 저장 시 해당 멤버는 이미 갱신되므로 중복 계산 없음
