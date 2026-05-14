# 운영자 문의 큐 자동 검사 + 일괄 자동 해결

- 작성일: 2026-05-14
- 대상: `boot.soritune.com` 운영자 화면 `/operation/#issues`
- 변경 범위: **운영자 화면만**. 회원 화면 / 회원 알림 변경 없음.

## 배경 / 문제

운영자 페이지 `#issues` 에 들어오는 회원 문의 중 상당수가 **운영자가 보기엔 이미 해결되어 있는 케이스**다.

PROD `issue_reports` 통계 (2026-05-14 기준 12기):

- `naemat33` pending **39건** (가장 많음)
- `malkka` pending 7건
- `zoom` pending 3건
- `daily` pending 2건

대표 시나리오 두 가지:

1. **시나리오 A — 폴링 후 자동 체크된 케이스**: 회원이 카페 인증 후 미체크 상태로 문의 등록 → 그 후 15분 폴링이 카페 글을 매칭해서 `member_mission_checks.status` 가 1로 자동 전환. 문의 자체는 정당했지만 운영자가 큐를 볼 시점에는 이미 해결된 상태.
2. **시나리오 B — 운영자 수동 처리 후 회원 미인지**: 운영자가 카페 댓글이나 대면으로 안내하여 처리는 끝났지만 회원이 다시 문의를 올림.

운영 정책: **회원에게는 추가 알림을 주지 않는다** (의도적으로 문의 발생률을 억제). 따라서 자동화의 목표는 회원에게 친절해지는 것이 아니라 **운영자 큐를 자동으로 정리하는 것**이다.

## 목표

- 운영자 화면 진입 시 pending 문의별로 "회원의 해당 미션이 현재 어떤 상태인지" 자동 표시.
- 이미 모두 체크된 문의는 운영자가 버튼 한 번으로 단건 / 일괄 `resolved` 처리.
- 회원 화면, 회원 알림, DB 스키마 모두 변경 없음.
- 자동 해결 오분류 리스크 최소화 (보수적 룰).

## Out of scope

- 회원 화면 변경 (사전 가드, 안내 배너, 알림톡 등).
- 운영자가 보낸 안내 메시지 추적.
- description 자유 텍스트 파싱 (날짜 추출 등 NLP 시도 안 함).
- mission_types 와 매핑되지 않는 신규 issue_type 도입.
- `hamemmal` (issue_type 매핑 없음) / `study_create` / `study_join` 자동 처리 — bookclub_open/join 은 데이터는 있지만 자동 매칭 source 가 거의 없어 false positive 위험.

## 채택된 접근법 — Read-only inspector + Operator-triggered auto resolve

운영자 화면이 로드될 때 **read-only 판별 로직** 으로 각 pending 문의의 mission 상태를 함께 응답하고, 운영자가 "자동 해결" 버튼을 누른 건에 한해 `status=resolved` 로 전환한다.

- 자동 cron 으로 resolve 하지 않는다 (운영자가 항상 마지막 게이트).
- 회원에게는 알림 / 메시지 / 화면 변화 없음.
- DB 스키마 / `issue_reports` 컬럼 / `member_mission_checks` 로직 변경 없음.

### Architecture

```
GET /api/admin.php?action=issue_admin_list
   ↓
issue_report.handleIssueAdminList()
   ↓
   기존 SELECT … FROM issue_reports
   + (status='pending' 만) inspectIssueMission() 호출
       ↓
       issue_type → mission_types.code 매핑 ($ISSUE_MISSION_MAP)
       ↓
       회원의 최근 7일 member_mission_checks 조회
       ↓ 반환:
       {
         mission_status: 'all_checked' | 'has_unchecked' | 'no_data' | 'unsupported',
         unchecked_dates: ['2026-05-13', ...],
         checked_dates:   ['2026-05-14', ...],
         inspected_range: { from: 'YYYY-MM-DD', to: 'YYYY-MM-DD' }
       }
   ↓
응답에 각 issue row 에 mission_inspection 객체 추가

POST /api/admin.php?action=issue_admin_resolve_auto  (단건)
   ↓
   1) issue_reports.status='pending' 검증
   2) inspectIssueMission() 재조회 → mission_status === 'all_checked' 검증
   3) status='resolved', admin_note='auto: 폴링 후 모두 체크 완료 확인 (range)', resolved_by, resolved_at
   4) issue_report_logs: changed_by_type='admin', note='auto: ...'

POST /api/admin.php?action=issue_admin_resolve_auto_bulk
   ↓
   1) 현재 필터(cohort_id 등) 조건의 status='pending' 문의 일괄 조회
   2) 각 건 inspectIssueMission() 재계산 (race 방지)
   3) all_checked 인 건만 resolve 처리 (트랜잭션 + savepoint per row)
   4) 응답: { resolved_ids: [...], skipped_ids: [...], total_resolved: N }
```

### 판별 로직 상세

```
$ISSUE_MISSION_MAP = [
    'naemat33' => 'inner33',
    'malkka'   => 'speak_mission',
    'zoom'     => 'zoom_daily',
    'daily'    => 'daily_mission',
    // study_create / study_join 은 false positive 위험으로 보류
];
```

함수 `inspectIssueMission(int $issueId): array`:

1. `issue_reports` row 조회 (member_id, cohort_id, issue_type, created_at).
2. `issue_type` 이 매핑 테이블에 없으면 `mission_status='unsupported'` 반환.
3. 검사 범위:
   - `from = max(cohort.start_date, KSTDate(issue.created_at) - INTERVAL 7 DAY)`
   - `to   = KSTDate(issue.created_at)`
4. 해당 회원의 `member_mission_checks` 조회 (mission_type_id JOIN, cohort_id 일치, check_date BETWEEN from AND to).
5. 결과 분류:
   - row 0건 → `no_data` (보수적으로 자동 해결 제외)
   - 모든 row `status=1` → `all_checked` (자동 해결 후보)
   - 1건이라도 `status=0` → `has_unchecked`
6. `unchecked_dates[]`, `checked_dates[]`, `inspected_range` 함께 반환.

### 자동 해결 처리

```sql
UPDATE issue_reports
SET status = 'resolved',
    admin_note = CONCAT_WS(' / ', admin_note,
        CONCAT('auto: 폴링 후 모두 체크 완료 확인 (', :range, ')')),
    resolved_by = :admin_id,
    resolved_at = NOW()
WHERE id = :issue_id AND status = 'pending';

INSERT INTO issue_report_logs
  (issue_id, old_status, new_status, changed_by_type, changed_by_id, note)
VALUES (:issue_id, 'pending', 'resolved', 'admin', :admin_id, 'auto: ...');
```

- `WHERE status='pending'` 가드로 race condition 차단.
- `admin_note` 기존 값 있으면 보존 후 append (CONCAT_WS).
- 회원에게는 알림 / 메시지 / 화면 변화 없음.

## UI

### 페이지 로드 후 목록 모양

```
┌──────────────────────────────────────────────────────────┐
│ #239 [naemat33] [✅ 모두 체크됨]  김OO · 12기 · 2분 전    │
│   "5/13일 내맛미션 했으나 미완료로 뜹니다..."             │
│   범위: 2026-05-07 ~ 2026-05-14                          │
│   [자동 해결] [확인 중] [반려]                            │
├──────────────────────────────────────────────────────────┤
│ #238 [zoom]    [❌ 미체크 5/13]   박OO · 12기 · 1시간 전  │
│   "13일 내맛 줌특강 미션완료했는데..."                    │
│   미체크: 5/13                                            │
│   [확인 중] [완료] [반려]                                  │
└──────────────────────────────────────────────────────────┘

[상단 헤더] 🪄 모두 체크된 N건 일괄 자동 해결
```

### Chip 상태

- `mission_status === 'all_checked'` → 녹색 chip "✅ 모두 체크됨"
- `mission_status === 'has_unchecked'` → 빨강 chip "❌ 미체크 {dates}"
- `mission_status === 'no_data'` → 회색 chip "데이터 없음"
- `mission_status === 'unsupported'` → chip 표시 안 함 (study_* 등)

### 버튼 동작

- **자동 해결 (단건)**: `all_checked` 일 때만 활성. `has_unchecked` / `no_data` / `unsupported` 에서는 버튼 자체 표시 안 함. 클릭 → confirm("자동 해결 처리하시겠습니까?") → API → 행 갱신.
- **모두 체크된 N건 일괄 자동 해결**: 현재 화면의 `all_checked` 건수 표시. 클릭 → confirm("N건이 자동 해결됩니다") → API → 결과 토스트("X건 처리, Y건 스킵") → 목록 새로고침.
- 기존 [확인 중] [완료] [반려] [메모] 버튼은 그대로 유지.

### 필터 / 정렬

- 현재 필터 (cohort_id / status / issue_type) 그대로.
- 일괄 자동 해결 대상은 **현재 적용된 필터 + status='pending' + mission_status='all_checked'** 의 교집합 (admin_list 와 동일 LIMIT 200 안에서). 200건을 넘는 누적이 쌓이면 운영자가 필터를 좁혀 여러 번 실행.

## 안전장치

1. **자동 해결 직전 재계산**: 단건/일괄 모두 resolve 직전에 `inspectIssueMission` 을 다시 호출하여 race 방지. 페이지 로드 후 폴링이 추가로 status 를 바꾸는 경우(시나리오: 화면 열어둔 채 5분 지나서 무관 row 추가 미체크 발생) 자동 해결되지 않도록.
2. **status='pending' 가드**: SQL `WHERE status='pending'` 으로 이미 처리된 row 재처리 방지.
3. **트랜잭션 + savepoint** (일괄): 한 row 실패해도 나머지 진행. 결과에 skipped_ids 포함.
4. **no_data 자동 처리 X**: 회원이 cohort 매칭 잘못된 문의 등록한 경우 보호.
5. **admin_note CONCAT_WS**: 기존 운영자 메모가 있어도 덮어쓰지 않음.
6. **audit log**: `issue_report_logs` 에 자동 처리 건도 모두 기록. note 에 'auto: ' prefix 로 수동/자동 구분 가능.
7. **operation 권한 필수**: 기존 `requireAdmin(['operation'])` 그대로 적용.

## 테스트

### invariants

- `inv_issue_auto_resolve_safety`:
  - `no_data` / `has_unchecked` / `unsupported` 상태인 issue 는 자동 해결 API 호출 시 거부.
  - 자동 해결 직후 동일 row 재호출 시 "이미 처리됨" 응답.
- `inv_issue_inspect_range`:
  - 검사 범위가 cohort.start_date 이전으로 넘어가지 않음.
  - 검사 범위가 issue.created_at 이후를 포함하지 않음.
- `inv_issue_log_audit`:
  - 자동 해결 시 `issue_report_logs` row 가 정확히 1건 추가됨.
  - `changed_by_type='admin'`, `note LIKE 'auto: %'`.

### regression

- 기존 status 변경 (pending → in_progress → resolved → rejected) 동작 영향 없음.
- 기존 admin_note 저장 / 조회 영향 없음.
- 사용자 등록 / 사용자 목록 / 사용자 상세 영향 없음.

### smoke (PROD 검증용)

- DEV 에서 `naemat33` pending 1건 자동 해결 → 회원 화면 변화 없음 확인.
- 일괄 자동 해결 → 예상 건수 ≈ 실제 처리 건수.
- has_unchecked 건은 버튼 비활성, API 직접 호출 시 거부.

## 영향 범위

| 항목 | 변경 |
|------|------|
| DB 마이그 | **없음** |
| `issue_reports` 컬럼 | 변경 없음 |
| `member_mission_checks` | read-only |
| `issue_report.php` | 헬퍼 1개 추가 (`inspectIssueMission`), admin_list 응답 확장, 신규 핸들러 2개 (`handleIssueAdminResolveAuto`, `handleIssueAdminResolveAutoBulk`) |
| `api/admin.php` | 라우팅 case 2개 추가 |
| `admin-issues.js` | chip 렌더링, 자동 해결 버튼, 일괄 모달 |
| `admin-issues.css` | chip 스타일 (green/red/gray) |
| 회원 화면 | **변경 없음** |
| 회원 알림 | **없음** |

## 롤백

- 코드 revert 만으로 즉시 롤백.
- 자동 해결로 변경된 `issue_reports.status` 는 audit log 의 `auto: ` prefix 로 식별 가능 → 필요 시 SQL 한 줄로 pending 복구 가능.

## 미해결 / 후속

- `study_create` / `study_join` (bookclub_open/join) 자동 매칭 카운트가 매우 적어 false positive 위험. 운영 데이터 더 쌓인 후 별도 spec.
- description 텍스트에서 날짜 자동 추출 (예: "5/13") 은 NLP 모호성과 한국어 양식 다양성 때문에 이번 spec 에서 제외.
- 시나리오 B (운영자 수동 처리 후 회원 인지 못함) 는 본 spec 으로 해결되지 않음 — 회원에게 알림을 주지 않는다는 정책 결정에 따라 운영자 사이드에서만 큐가 정리됨. 본 spec 효과는 시나리오 A 중심.
