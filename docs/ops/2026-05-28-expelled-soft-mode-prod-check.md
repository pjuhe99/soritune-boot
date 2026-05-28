# expelled 약한 조치 전환 — PROD 사전 배포 체크

> spec §12.2 의 운영자 체크리스트 운용판.
> dev 검증 완료 후, main 머지·PROD pull 직전에 실행.

## 1. PROD expelled 회원 분포 확인

PROD `_______site_SORITUNECOM_BOOT/.db_credentials` 로 접근:

```sql
SELECT
  COUNT(*) AS total,
  SUM(group_id IS NOT NULL) AS with_group,
  SUM(is_active = 1) AS still_active_flag,
  GROUP_CONCAT(id ORDER BY id) AS member_ids
FROM bootcamp_members
WHERE member_status = 'expelled';
```

판단:
- `total = 0`: 즉시 배포 OK (소급 영향 0)
- `total > 0 AND with_group = 0`: 대부분 group 의존 처리는 0 → 영향 작음. 카페 백필/후기/멤버페이지/cron 만 영향. 운영자 인지 후 배포
- `total > 0 AND with_group > 0`: 보기 드문 케이스. 코인 적립·점수 계산·체크리스트 모두 즉시 영향. 회원 명단 확인 후 진행 판단

## 2. 카페·줌 cron 백필 윈도우 확인

```bash
grep -n "backfill\|INTERVAL\|DATE_SUB" /root/boot-prod/public_html/cron.php | head -20
```

백필 함수가 거슬러 올라가는 기간 확인. 이 윈도우 안에 expelled 회원의 카페 글이 있으면 다음 cron 에서 한꺼번에 ingest 됨.

## 3. opt-out 옵션 (필요 시)

위 1번 결과에 따라 운영자 판단:

(a) 그대로 배포 (Q4 결정 기본 경로)
(b) 기존 expelled 를 사전에 `refunded` 로 정리:
```sql
-- 신중히. 회원 명단 확인 후 개별 실행 권장.
UPDATE bootcamp_members SET member_status='refunded', is_active=0
 WHERE id IN (...);
```
(c) 별도 spec 으로 `expelled_legacy` enum 값 도입 후 마이그 — 추가 작업 필요

## 4. 배포 후 모니터링

배포 직후 첫 cron 사이클 (대략 03:00 한국 시간) 다음 날 아침 확인:

```sql
-- 출석률 통계 분모 변동
SELECT cohort_id, COUNT(*) FROM bootcamp_members
 WHERE is_active=1 AND member_status != 'refunded' GROUP BY cohort_id;

-- 코인 적립 cron 결과 (expelled 회원에게 들어간 코인 있는지)
SELECT bm.id, bm.nickname, bm.member_status, SUM(cl.coin_change) AS coin_change_24h
  FROM bootcamp_members bm
  LEFT JOIN coin_logs cl ON cl.member_id = bm.id
   AND cl.created_at >= NOW() - INTERVAL 1 DAY
 WHERE bm.member_status = 'expelled'
 GROUP BY bm.id HAVING coin_change_24h IS NOT NULL;

-- 카페 ingest 백필 폭
SELECT execution_id, total_success, total_skipped, total_unmapped, created_at
  FROM integration_logs
 WHERE created_at >= NOW() - INTERVAL 1 DAY
 ORDER BY created_at DESC LIMIT 5;
```

이상 수치 (코인 +10 이상, 카페 ingest +100 이상 등) 발견 시 spec §12.4 롤백 + 클린업 SQL 작성.
