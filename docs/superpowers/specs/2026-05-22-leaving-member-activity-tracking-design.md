# 조에서 빠진 회원 단체 활동 자동체크 — 디자인

- **작성일**: 2026-05-22
- **대상 사이트**: boot.soritune.com (DEV: dev-boot.soritune.com)
- **DB**: SORITUNECOM_BOOT (DEV: SORITUNECOM_DEV_BOOT)

## 1. 배경

부트캠프 운영 화면의 "나가기" 버튼은 현재 두 가지 의미로 혼용되고 있다.

1. **자발적 조 탈퇴**: 조 활동은 부담스러워서 빠지고 싶지만, 줌 특강·카페 과제 등 단체 활동은 계속하고 싶은 회원
2. **(향후) 완전 퇴출**: 운영진이 강제로 내보내는 경우

이번 기수(12기) PROD 데이터(`member_status='leaving'` 6명, 모두 `group_id=NULL`)는 모두 1번 케이스로 확인됐다. 그러나 코드는 이 회원들을 cron의 일일 미션 inbox 생성, 출석률 분모, 후기 작성 권한에서 모두 제외해서 — QR 스캔이나 카페 자동체크가 들어왔을 때만 사후적으로 `member_mission_checks` row가 INSERT되는, 일관성 없는 동작을 보이고 있다.

이 작업의 목표는 `leaving`의 의미를 1번(자발적 조 탈퇴)로 재정의해서 단체 활동이 정상 회원과 동일하게 작동하도록 정렬하는 것이다. 2번(완전 퇴출)은 별도 작업으로 미룬다.

## 2. 비목표

- 새 enum 값 추가 (예: `voluntary_out`) — 안 함
- `out_of_group_management` (점수 미달 자동 강등) 의미 변경 — 그대로 유지
- "완전 퇴출" 별도 경로 추가 — 다음 작업
- "조에서 빠진 회원" 별도 운영 페이지 — active 회원과 같은 화면에 표시
- 운영자 일괄 분류 마이그레이션 — PROD 12기 6명은 새 의미로 자연 흡수
- DB 스키마 변경 — 0건
- DB 데이터 마이그 — 0건

## 3. 의미 재정의

| `member_status` | 새 의미 | 로그인 | 조 활동 | 단체 활동 (zoom/카페/점수/코인) |
|----------------|--------|--------|---------|---------------------------------|
| `active` | 정상 + 조 소속 | ✅ | ✅ (`group_id` 보유) | ✅ |
| `out_of_group_management` | 점수 미달 자동 강등, 조 빠짐, 점수 부활 가능 | ✅ | ❌ (`group_id=NULL`) | ✅ **(NEW)** |
| `leaving` | **자발적 조 탈퇴** (의미 재정의) | ✅ | ❌ (`group_id=NULL`) | ✅ **(NEW)** |
| `refunded` | 환불 | ❌ | ❌ | ❌ |

**핵심 규칙**: "단체 활동 대상 회원" = `is_active = 1 AND member_status != 'refunded'`. 이미 `qr_actions.php:28`, `qr.php:178/258`, `cafe_ingest.php` 의 `resolveMemberByKey`(`is_active=1`)가 쓰는 패턴과 일치한다. 게이트를 이 패턴으로 통일하면 끝.

## 4. 변경 포인트

### 4.1 cron 게이트 풀기 (가장 핵심)

**파일**: `public_html/cron.php`

- 라인 89 (`init_daily_checks`):
  ```sql
  -- 변경 전
  WHERE bm.is_active = 1
    AND bm.member_status = 'active'
    AND c.start_date <= '{$today}' AND c.end_date >= '{$today}'
  -- 변경 후
  WHERE bm.is_active = 1
    AND bm.member_status != 'refunded'
    AND c.start_date <= '{$today}' AND c.end_date >= '{$today}'
  ```

- 라인 158 (`backfillChecks`): 동일한 패턴으로 `member_status = 'active'` → `!= 'refunded'`.

**결과**: 다음 cron 실행부터 12기 `leaving` 6명 + (향후 발생할 OOM) 에게 매일 zoom_daily / daily_mission / inner33 / speak_mission(월) inbox row가 자동 생성된다. 기존 `member_mission_checks` row는 INSERT IGNORE라 영향 없음.

### 4.2 출석률 분모 일관성

**파일**: `public_html/api/services/attendance.php:21`

```sql
-- 변경 전
WHERE cohort_id = ? AND is_active = 1
  AND member_status NOT IN ('refunded','leaving')
-- 변경 후
WHERE cohort_id = ? AND is_active = 1
  AND member_status != 'refunded'
```

**영향**: 12기 분모가 270 → 276 (active + leaving 6명) 으로 늘어서 출석률 % 가 미세하게 떨어진다. 단체 활동 자동체크 대상이 분모에도 잡혀야 일관성이 있어 의도된 변경.

### 4.3 후기 작성 권한

**파일**: `public_html/api/services/review.php:32`

```php
// 변경 전
in_array($member['member_status'], ['refunded', 'leaving', 'out_of_group_management'])
// 변경 후
in_array($member['member_status'], ['refunded'])
```

조에서 빠졌어도 단체 활동의 일환으로 후기는 작성 가능하게 허용.

### 4.3b 같은 기수 부티즈 목록

**파일**: `public_html/api/services/member_page.php:402` (`handleMemberBootees`)

회원이 "같은 기수의 다른 부티즈 목록"을 볼 때 현재 `member_status = 'active'` 만 표시된다. leaving/OOM 회원도 단체 활동 대상이 됐으므로 이 목록에서도 보이게 통일.

```sql
-- 변경 전
WHERE bm.cohort_id = ?
  AND bm.is_active = 1
  AND bm.member_status = 'active'
-- 변경 후
WHERE bm.cohort_id = ?
  AND bm.is_active = 1
  AND bm.member_status != 'refunded'
```

### 4.4 운영 UI 라벨 변경

`leaving` 의 의미가 "나간 회원" 에서 "조에서 빠진 회원"으로 명확화되므로 라벨 정리:

| 파일:라인 | 변경 전 | 변경 후 |
|----------|--------|---------|
| `public_html/api/services/member.php:173` | `'나간 회원'` | `'조에서 빠진 회원'` |
| `public_html/api/admin.php:807` | `'나간 회원'` | `'조에서 빠진 회원'` |
| `public_html/js/memberTable.js:41` | 배지: `'나간 회원'` | `'조에서 빠짐'` |
| `public_html/js/memberTable.js:155` | 버튼: `'나가기'` | `'조에서 빼기'` |
| `public_html/js/admin.js:1122` | `'나간 회원 \${n}'` | `'조에서 빠진 회원 \${n}'` |
| `public_html/js/admin.js:1132` | `'환불·탈락·나간 회원 포함'` | `'환불·탈락·조에서 빠진 회원 포함'` |
| `public_html/js/admin.js:1375` | 버튼: `'나가기'` | `'조에서 빼기'` |
| `public_html/js/bootcamp.js:1672` | 버튼: `'나가기'` | `'조에서 빼기'` |
| `public_html/js/bootcamp.js:2287` | 배지: `'나간 회원'` | `'조에서 빠짐'` |

`member_status` 값 자체와 API 입력값(`'leaving'`)은 그대로 유지. 표시 라벨만 변경.

### 4.5 변경하지 않는 것 (이미 OK 이거나 의미상 그대로)

- `public_html/auth.php` — `is_active=1 OR member_status='leaving'` 로 이미 leaving 로그인 허용. OOM 은 `is_active=1` 이라 자연 통과.
- `public_html/includes/qr_actions.php:28`, `public_html/api/qr.php:178/258` — 이미 `!= 'refunded'` 만 차단
- `public_html/includes/cafe/cafe_ingest.php` + `resolveMemberByKey` — 이미 `is_active=1` 만 체크 (member_status 무관)
- `public_html/includes/bootcamp_functions.php:283-287` (`saveCheck` INSERT 경로) — member_status 게이트 없음
- `public_html/api/services/coin_reward_group.php:221` — **조별 단체 보상** 이라 `group_id=NULL` 인 leaving/OOM 은 자연스럽게 제외 (의도된 동작). 단체 활동마다 적립되는 일반 코인 (`processCoinForCheck`) 은 `saveCheck` 내부에서 호출돼 모든 상태에 적용됨.
- `public_html/includes/bootcamp_functions.php:220` — 점수 자동 강등 (active → OOM) 로직 유지
- `public_html/tests/member_session_cohort_invariants.php:104` — "활성 cohort 의 OOM 회원도 `is_active=1` 이면 세션 통과" invariant 는 본 정책 변경과 일치하므로 그대로 유지
- `public_html/api/services/member_page.php:72, 181` — 회원 본인 페이지 진입 조건. `is_active=1 OR member_status='leaving'` 이미 leaving 허용 + OOM 자연 통과. 변경 불필요

## 5. 동작 시나리오 검증

### 5.1 12기 `leaving` 6명 — cron 인입 후

- 다음 02시 cron 실행 → `member_mission_checks` 에 6명 × 3~4개 미션 = 18~24 row INSERT (status=0)
- 운영 대시보드에서 12기 active 회원과 함께 표시 (조 미배정 분류)
- 줌 QR 스캔 시 `zoom_daily` row UPDATE → 정상 체크
- 카페 게시물 인제스트 시 해당 보드 미션 row UPDATE → 정상 체크
- 코인 사이클 / 점수 반영 → active 와 동일

### 5.2 12기 OOM (현재 0명, 향후 발생 시)

- 점수 미달로 active → OOM 자동 전환
- 다음 cron부터 OOM 회원도 inbox 생성 (단체 활동 대상)
- 점수가 다시 임계값 위로 오르면 OOM → active 자동 복원 (기존 로직 유지)

### 5.3 `refunded` 회원

- 모든 게이트에서 차단 (기존과 동일, 변경 없음)

## 6. 테스트 / 회귀 가드

### 6.1 자동 테스트

`tests/` 디렉토리에 invariants 스크립트 추가 (기존 패턴 따름):

1. **`leaving_cron_invariants.php`**: 12기에 `leaving` 회원 fixture 생성 → `init_daily_checks` 함수 호출 → 해당 회원에게 inbox row 가 생성됐는지 확인 (cohort 활성 기간 내)
2. **`leaving_qr_scan_invariants.php`**: leaving 회원 fixture + zoom QR 세션 → `qrRecordAttendance` 호출 → `qr_attendance` 와 `member_mission_checks(zoom_daily, status=1)` 둘 다 생성됐는지 확인
3. **`leaving_cafe_ingest_invariants.php`**: leaving 회원 fixture + cafe_member_key 매핑 + cafe 게시물 → cafe ingest → `member_mission_checks(해당 board mission, status=1)` 생성됐는지 확인

### 6.2 수동 검증 (DEV)

- DEV 12기에 leaving fixture 1명 생성 → cron 트리거 → DEV dashboard 노출 확인
- 같은 회원으로 zoom QR 스캔 → 체크 화면에 표시 확인
- 출석률 분모 변동 (변경 전 / 후 비교)

## 7. 배포 영향 / 롤백

- **DB 변경**: 0건
- **즉시 영향**: 다음 02시 cron부터 12기 leaving 6명에게 inbox 생성. 운영 대시보드에 "조 미배정 회원" 으로 노출. 운영자에게 사전 안내 필요.
- **출석률**: 분모 270 → 276 (12기). % 미세 하락.
- **롤백**: cron 게이트 4곳 + attendance.php + review.php + UI 라벨 원복. DB 영향 없으므로 코드 revert 만으로 즉시 가능.

## 8. 향후 작업 (이번 범위 밖)

- "완전 퇴출" 경로: 새 enum 값 추가 또는 별도 컬럼 (`leaving_reason: voluntary | kicked` 등) 도입
- 운영자가 점수 자동 강등(OOM) 발생 시 알림받는 기능
- "조에서 빠진 회원" 그룹별 통계/리포트 분리
