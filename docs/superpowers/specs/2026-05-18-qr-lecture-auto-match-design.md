# QR 세션 ↔ 강의 자동 매칭 보강

작성일: 2026-05-18
대상: boot.soritune.com (DEV → PROD)
영향 파일 (예정): `public_html/api/qr.php`, `public_html/api/services/attendance.php` (참조만), `backfill_qr_lecture_session.php` (boot 루트, 신규), `tests/qr_lecture_match_test.php` (신규)

## 1. 배경

운영자 어드민 `/operation/#attendance` 화면은 QR 세션별 출석 현황을 보여주며, 각 세션을 다음 4가지로 분류한다 (`api/services/attendance.php:60-68`):

- **패자부활** — `qs.session_type='revival'`
- **강의** — `qs.lecture_session_id` 채워져 있음 (`lecture_sessions` 와 연결됨)
- **복습스터디** — `study_sessions.qr_session_id` 로 역참조됨
- **기타** — 위 셋 다 아닌 경우

문제는 줌 특강이 분명 진행 중이고 `lecture_sessions` 에 해당 강의가 등록되어 있어도 **자동 매칭이 안 돼서 기타로 빠지는 케이스가 다수 발생** 하고 있다는 점이다.

PROD 데이터(2026-05-18 기준) 분석 결과:

| cohort | NULL row 수 | 같은 날 강의 수 | 같은 admin 강의 수 |
|--------|------------|----------------|-------------------|
| 12기 (활성) | 15건 | 평균 4~5개 | 거의 0건 |
| 11기 (종료) | 35건 | — | — |
| 1기 (종료) | 3건 | — | — |

자동 매칭이 실패하는 원인은 3가지로 분류된다:

### 원인 ① — 동일 코치의 admin 계정 중복

PROD `admins` 테이블에 같은 코치 이름으로 admin_id 두 개가 공존한다 (옛 11기 계정 + 새 12기 계정, 둘 다 `is_active=1`):

| 코치 | 옛 admin_id (3/17) | 새 admin_id (4/30) |
|------|-------------|-------------|
| Kel | 9 | 20 |
| Lulu | 10 | 22 |
| Darren | 16 | 25 |
| Ella | 11 | 21 |
| Hyun | 14 | 23 |

`lecture_sessions` 는 새 admin_id 로 등록되어 있는데, QR 발급 시 코치가 옛 admin_id 로 로그인하면 매칭 SQL의 `coach_admin_id = ?` 가 불일치 → NULL.

### 원인 ② — 다른 코치 강의를 대신 호스팅

5/18에 Ella(21)가 06:36에 QR을 띄웠는데 그날 Ella 등록 강의 없음 (Kel/Darren/Lulu/Hyun 4건만 있음). 다른 코치 시간대에 줌 호스트만 대신 들어가면 `coach_admin_id` 가 발급자와 다름 → 매칭 실패.

### 원인 ③ — admin_id=0 으로 발급되는 QR

5/18 데이터에 4건. `qr.php:84` 의 `$admin['admin_id'] > 0` 가드 때문에 매칭 시도 자체가 skip 됨. `requireAdmin` 통과 후 어떻게 admin_id=0 이 박히는지는 별도 조사 대상 — **이번 spec 범위 외**.

## 2. 목표

- 원인 ① + 원인 ② 의 자동 매칭률을 끌어올린다.
- 잘못된 매칭은 절대 추가하지 않는다 (정확성 > 매칭률).
- 12기 NULL row 15건 중 소급 매칭 가능한 row 를 백필한다.

## 3. 비목표

- 원인 ③ (admin_id=0 발급 경로 자체) 의 원인 추적 — 별도 백로그.
- 11기·1기 NULL row 백필 — 종료 기수, 통계 영향 없다고 사용자 판단.
- 옛 admin 계정 deactivate 같은 데이터 정리 — 사용자가 옵션 1을 거절, 옵션 2(코드 보강)만 진행.
- attendance 통계 페이지 UI 변경.

## 4. 매칭 알고리즘 (3단계 cascade)

`api/qr.php` 의 `create_session` 핸들러 안에서 단일 SQL 매칭을 다음 헬퍼 함수로 추출한다:

```php
function findMatchingLectureSession(PDO $db, array $admin, int $cohortId, ?string $atDate = null, ?string $atTime = null): ?int
```

- `$atDate` / `$atTime` 둘 다 null 이면 KST 현재 일자/시각 사용 (실시간).
- 둘 다 지정되면 그 시점 기준으로 매칭 (소급 백필용).

cascade 흐름:

### Tier A — 정확 매칭 (기존 로직, 그대로 유지)

```sql
SELECT id FROM lecture_sessions
WHERE coach_admin_id = :qrAdminId
  AND lecture_date = :atDate
  AND cohort_id = :cohortId
  AND status = 'active'
ORDER BY ABS(TIMESTAMPDIFF(SECOND, start_time, :atTime)) ASC
LIMIT 1
```

조건: `$admin['admin_id'] > 0`. 히트하면 그 id 반환.

### Tier B — 동일 이름 admin 그룹 매칭 (신규)

Tier A 가 0건일 때만 실행. 조건: `$admin['admin_id'] > 0`.

Tier B 도 Tier C 와 동일한 ±60분 시각 가드 — admin 매칭만 되고 시각이 멀리 떨어진 (그 코치의 다른 시간대 강의) 케이스는 NULL 유지하고 Tier C 진입.

```sql
SELECT id FROM lecture_sessions
WHERE coach_admin_id IN (
    SELECT id FROM admins
    WHERE name = (SELECT name FROM admins WHERE id = :qrAdminId)
      AND role IN ('coach','sub_coach','head','subhead1','subhead2')
  )
  AND lecture_date = :atDate
  AND cohort_id = :cohortId
  AND status = 'active'
  AND ABS(TIME_TO_SEC(TIMEDIFF(start_time, :atTime))) / 60 <= 60
ORDER BY ABS(TIME_TO_SEC(TIMEDIFF(start_time, :atTime))) ASC
LIMIT 1
```

- `role` 가드: 코치 권한 5종으로 제한하여 동명 회원/operation 이 끼지 않도록.
- ±60분 가드: PROD 시뮬레이션에서 QR #316 (Kel 11:34 → 20:30 강의 8h56m), QR #310 (Lulu 14:04 → 12:10 강의 1h54m) 처럼 시각 차이가 큰 잘못된 매칭 발생 → Tier A 와 동일하게 가장 가까운 강의를 고르되, ±60분 바깥은 NULL 유지.
- Darren(16) 발급 QR이 Darren(25) 강의에 매칭되는 핵심 케이스.
- 히트하면 그 id 반환.

### Tier C — 시간대 단일 후보 매칭 (신규, 보수적)

Tier B 까지 0건일 때만 실행. admin_id 무관.

```sql
SELECT id FROM lecture_sessions
WHERE lecture_date = :atDate
  AND cohort_id = :cohortId
  AND status = 'active'
  AND ABS(TIMESTAMPDIFF(MINUTE, start_time, :atTime)) <= 60
```

- 결과가 **정확히 1건일 때만** 그 id 사용.
- 0건이면 NULL.
- 2건 이상이면 NULL (잘못된 매칭 위험 회피 — 5/18 Ella 06:36 케이스가 이 경로).

### 어느 단계도 안 잡히면

`lecture_session_id = NULL` 로 INSERT/UPDATE, 통계에서 '기타' 로 분류된다.

## 5. 신규 QR (실시간)

`api/qr.php:82-101` 의 인라인 SQL 한 덩어리를 `findMatchingLectureSession($db, $admin, $cohortId)` 호출로 교체. `$lectureSessionId` 변수만 위 함수 반환값으로 받고 나머지 INSERT 로직은 그대로.

기존 정확 매칭으로 잡히던 케이스(Kel→Kel)는 Tier A 에서 그대로 통과하므로 회귀 0.

## 6. 소급 백필 (12기 NULL 15건)

### 6.1 스크립트

boot 루트 `backfill_qr_lecture_session.php` 신규 (기존 `migrate_*.php` 패턴 동일).

CLI 인자:

| 인자 | 기본값 | 동작 |
|-----|--------|------|
| `--cohort=N` | (필수) | 대상 cohort_id |
| `--dry-run` 또는 `--apply` | (필수, 둘 중 하나) | dry-run 은 출력만, apply 는 실제 UPDATE |

부재 시 usage 출력 후 `exit(1)`. `require_once __DIR__ . '/public_html/config.php'` 로 `getDB()` 사용 (다른 마이그 스크립트와 일관).

다음 row 들을 매칭 대상으로 한다:

```sql
SELECT qs.id, qs.admin_id, qs.cohort_id, qs.created_at,
       DATE(qs.created_at) AS at_date,
       TIME(qs.created_at) AS at_time,
       a.name AS admin_name
FROM qr_sessions qs
LEFT JOIN admins a ON a.id = qs.admin_id
WHERE qs.cohort_id = :cohortId
  AND qs.session_type != 'revival'
  AND qs.lecture_session_id IS NULL
  AND NOT EXISTS (SELECT 1 FROM study_sessions ss WHERE ss.qr_session_id = qs.id)
ORDER BY qs.created_at ASC
```

각 row 마다 `findMatchingLectureSession($db, ['admin_id' => $row['admin_id']], $row['cohort_id'], $row['at_date'], $row['at_time'])` 호출.

### 6.2 Dry-run 출력 형식

```
QR #303  2026-05-08 14:47:08  Kel(9)     → 후보 없음, NULL 유지
QR #305  2026-05-09 23:24:39  Lulu(10)   → 후보 없음, NULL 유지
QR #313  2026-05-12 06:43:34  Darren(16) → Tier B: lecture #518 (Darren/25, 2단계, 06:00) ✓ 매칭
QR #349  2026-05-18 06:36:35  Ella(21)   → Tier C: 후보 2건 (lecture #345, #518), 보수성 차단

요약: 15건 검사 → 9건 매칭, 6건 NULL 유지
  Tier A 매칭: 0건
  Tier B 매칭: 7건
  Tier C 매칭: 2건
```

각 row 가 어느 Tier 에서 잡혔는지 (또는 왜 안 잡혔는지) 사람이 읽을 수 있어야 한다.

### 6.3 Apply 모드

`--apply` 지정 시:

1. 스냅샷 백업: `mysqldump SORITUNECOM_BOOT qr_sessions > /tmp/qr_sessions_backup_YYYYMMDD_HHMMSS.sql`
   - 실패하면 abort.
2. 트랜잭션 시작.
3. dry-run 과 동일한 cascade 로 row 별 UPDATE:
   ```sql
   UPDATE qr_sessions SET lecture_session_id = :matched WHERE id = :qsId AND lecture_session_id IS NULL
   ```
   - `AND lecture_session_id IS NULL` 가드로 race 안전 (이미 매칭된 row 는 덮어쓰지 않음).
4. 모든 UPDATE 성공 시 COMMIT, 실패 시 ROLLBACK + abort.
5. 백필된 id 목록을 stdout 에 출력 + `/tmp/qr_sessions_backfilled_YYYYMMDD_HHMMSS.txt` 로 저장 (롤백 시 사용).

### 6.4 백필 가드

- `lecture_sessions.status = 'active'` 만 매칭 (cancelled 강의 제외).
- 백필 시점에 `lecture_sessions` 에 row 가 없는 day 는 자연스럽게 NULL 유지.

## 7. 엣지 케이스

| 케이스 | 처리 |
|-------|------|
| `qs.admin_id = 0` (시스템 발급) | Tier A·B skip, Tier C 만 시도. 후보 1건이면 매칭, 아니면 NULL |
| 코치가 자기 강의 시간 + 5분 후에 QR 발급 (>60분 안 벗어남) | Tier A 정확 매칭 |
| 동명이인 admin 가 진짜로 동시 활성 (예: 옛/새 Kel 둘 다 active) | Tier B 의도된 동작 — 같은 코치로 간주, 시간 가까운 강의 1개 매칭 |
| `lecture_sessions` 에 같은 코치 같은 시간 강의 2개 등록 (이상 데이터) | Tier B `LIMIT 1` 로 ID 작은 거 매칭 |
| QR 발급 시각이 그 날 모든 강의보다 >60분 떨어짐 | NULL 유지 (기타) |
| Tier B 후보가 모두 cancelled 강의 | `status='active'` 가드로 0건 → Tier C 진행 |
| 동명 admin 중 한쪽이 role='member' (가짜 동명) | Tier B 의 role 가드로 제외 |
| Tier C 후보 2건 중 1건만 active, 1건은 cancelled | active 1건만 카운트 → 매칭 |

## 8. 테스트

### 8.1 Unit (`tests/qr_lecture_match_test.php` 신규)

`findMatchingLectureSession` 직접 호출, 인메모리 fixture 또는 트랜잭션 롤백 패턴으로:

| # | 시나리오 | 기대 |
|---|---------|------|
| 1 | Kel(20) admin, Kel(20) 강의 1건 동일 코치 | Tier A 매칭 (lecture id 반환) |
| 2 | Darren(16) admin, Darren(25) 강의만 존재 | Tier B 매칭 |
| 3 | Ella(21) admin, 그날 Ella 강의 없음, ±60분 내 강의 1건 | Tier C 매칭 |
| 4 | Ella(21) admin, 그날 Ella 강의 없음, ±60분 내 강의 2건 | NULL |
| 5 | Ella(21) admin, ±60분 내 강의 0건 | NULL |
| 6 | admin_id=0, ±60분 내 강의 1건 | Tier C 매칭 |
| 7 | admin_id=0, ±60분 내 강의 0건 | NULL |
| 8 | 동명이인이 role='member' (가짜 동명) | Tier B 가드로 제외 → Tier C 진행 |
| 9 | Tier B 후보 cancelled 강의만 존재 | Tier B 0건 → Tier C 진행 |

### 8.2 Invariant (DEV/PROD 양쪽)

- **INV-1**: 신규 매칭 로직 적용 후 기존 정확 매칭(Tier A) row 카운트가 줄어들지 않음 (회귀 0).
- **INV-2**: `qr_sessions.lecture_session_id IS NOT NULL` row 의 모든 `lecture_sessions` 참조가 유효 (FK 일관성).
- **INV-3**: 백필 후 cohort_id=12 의 `getAttendanceStats` 응답이 200 + JSON 파싱 가능.

### 8.3 DEV 통합 검증

1. DEV DB 에 시나리오 1~9 매칭하는 fixture row 시드 (이름 중복 admin 2쌍 + lecture/qr).
2. `qr.php?action=create_session` POST 호출 → 응답의 `lecture_session_id` 검증.
3. `backfill_qr_lecture_session.php --cohort=12` dry-run 출력 사람이 읽고 검토.
4. `--apply` 후 DB diff 확인 + INV-1/2/3 PASS.

## 9. PROD 적용 절차

1. DEV 검증 완료 후 사용자 명시 시 main 머지 + prod pull.
2. PROD CLI: `php /var/www/html/_______site_SORITUNECOM_BOOT/backfill_qr_lecture_session.php --cohort=12 --dry-run` 출력을 사용자 확인.
3. 사용자 명시 승인 후 `--cohort=12 --apply` 실행.
4. PROD `/operation/#attendance` 페이지에서 12기 통계 한 번 더 눈으로 확인.

## 10. 롤백 전략

- **코드**: revert commit + main push + prod pull.
- **데이터**: 백필 시 저장된 `/tmp/qr_sessions_backfilled_*.txt` 의 id 목록으로
  ```sql
  UPDATE qr_sessions SET lecture_session_id = NULL WHERE id IN (...)
  ```
  또는 `/tmp/qr_sessions_backup_*.sql` 전체 복원.

## 11. 마이그레이션

스키마 변경 없음. DB 마이그레이션 0건.

## 12. 영향 범위 정리

| 영역 | 변경 | 회귀 위험 |
|------|-----|----------|
| `qr.php create_session` | 인라인 SQL → 헬퍼 함수, Tier B/C 추가 | Tier A 동작 동일 — 회귀 0 |
| `attendance.php` | 변경 없음 (참조만) | 0 |
| 회원 화면 | 변경 없음 | 0 |
| 코치 화면 | 변경 없음 | 0 |
| 운영자 출석 통계 | 매칭률 상승, 코치별/단계별 집계에 더 많은 row 포함 | 의도된 변화 |
| `qr_sessions` 12기 NULL 15건 | 일부 lecture_session_id 채워짐 | 통계에 반영 — 의도된 변화 |
| `qr_sessions` 11기·1기 NULL 38건 | 변경 없음 | 0 |
