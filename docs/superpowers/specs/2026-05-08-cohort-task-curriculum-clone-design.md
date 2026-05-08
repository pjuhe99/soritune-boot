# Cohort Task / Curriculum Clone — 기수 전환 시 task·진도 복제 설계

**작성일:** 2026-05-08
**대상:** boot.soritune.com
**배경:** 12기 운영 시작 (2026-05-11) 임박. 11기 task 544건과 진도(curriculum_items) 139건을 12기로 복제해야 함. 12기 시작일 기준 +49일 shift, 인원 매핑은 12기 등록 admin/member 의 role 기반.

---

## 1. 문제 정의

### 1.1 요구사항
- 11기 task / 진도 데이터를 12기에 baseline 으로 복제
- task assignee 는 12기 등록 인원의 role 에 맞춰 매핑 (이름 매칭 X)
- 진도 본문(note) 은 verbatim 복제하여 운영자가 시작 전 어드민에서 보고 수정
- 기준은 **시작일 정렬**: 11기 시작일 → 12기 시작일 day-offset 으로 모든 날짜 shift. 종료일/기간 불일치는 무시

### 1.2 데이터 상태 (PROD, 2026-05-08 기준)

| 항목 | 11기 | 12기 |
|---|---|---|
| `cohorts.start_date` | 2026-03-23 | 2026-05-11 |
| `cohorts.end_date` | 2026-04-23 (32일) | 2026-06-12 (33일) |
| `tasks` row | 544 | 0 |
| `curriculum_items` row | 139 (35일치) | 0 (이전 1건 삭제 완료) |

**Date shift = +49일 (2026-05-11 − 2026-03-23)**

### 1.3 12기 등록 인원

**Admin (admin_roles 기준 다중 역할)**:
| login_id | name | 등록 역할 |
|---|---|---|
| Kel12 | Kel | coach + sub_coach + **head** |
| Lulu12 | Lulu | coach + sub_coach |
| Ella12 | Ella | coach + sub_coach |
| Hyun12 | Hyun | sub_coach |
| Jay12 | Jay | sub_coach |
| Darren12 | Darren | sub_coach |
| Flora12 | Flora | sub_coach |

**Operation (cohort=NULL, 모든 기수 공유)**: binnie4, wannie

**Member (`bootcamp_members` cohort_id=12)**: leader 9명, subleader 8명

**참고**: `admins.role` 컬럼은 primary role 1개만 표시. 권한 / task 매핑은 **반드시 `admin_roles` 테이블 기준** (M:N).

---

## 2. 11기 데이터 패턴 분석

### 2.1 task assignee 패턴

| task.role | 11기 rows | 매핑 패턴 |
|---|---|---|
| head | 143 | 단일 인원(Kel) + 일별 반복. 예: "데일리 체크리스트" 33일 × 1명 = 33 row |
| coach | 37 | Fan-out: 동일 (title, date) 가 3 명에게 복제. 예: "재수강 안내" 3 row |
| sub_coach | 24 | Fan-out: 동일 (title, date) 가 8명에게 복제. 예: "STEP 1. 강의 준비" 8 row |
| operation | 19 | binnie4(18) + wannie(1) — 단일 assignee per task |
| leader | 240 (assigned) + 81 (unassigned) | Fan-out: 동일 (title, date) 가 8명에게. 예: "출석 독려" 5명 fan-out |

**핵심 통찰**: coach/sub_coach/leader 는 fan-out 패턴 → 11기 row 는 (role, title, day-offset, content) 별로 dedupe 후 12기 인원 수만큼 다시 fan-out. head 는 1명이라 fan-out 영향 미미. operation 은 cohort=NULL 인원이므로 11기 assignee 그대로 유지.

### 2.2 curriculum_items 패턴

11기 139건, 35일치, task_type 7종:
- `progress` 33건: 모든 row 가 note 채워짐 (예: `❤️1단계: 4강 | ?2단계: 1,2강`)
- `event` 6건: note 채워짐 ("개강파티", "온보딩 클래스" 등)
- `lecture` 32 / `hamummal` 21 / `naemat33_mission` 31 / `zoom_or_daily_mission` 11: note 비어있음 (날짜 마크 용도)
- `malkka_mission` 5 (1건만 note)

**복제 정책**: verbatim (note 포함) + date shift. 12기 강 번호 운영자가 어드민에서 시작 전 보정.

---

## 3. 매핑 규칙

### 3.1 Task — Template 추출 + Role 기반 Fan-out

**Step A: Template 추출**

11기 tasks 를 다음 키로 group:
```
(role, title, content_markdown, start_day_offset, end_day_offset)
```
- `start_day_offset` = `start_date` − 11기.start_date
- `end_day_offset` = `end_date` − 11기.start_date

같은 그룹은 1 template 으로 dedupe (assignee_admin_id, assignee_member_id, completed 는 무시).

**Step B: 12기 후보 결정**

| template.role | 12기 후보 도출 | 후보 수 |
|---|---|---|
| head, coach, sub_coach, subhead1, subhead2 | `admins JOIN admin_roles WHERE admin.cohort='12기' AND admin_roles.role=template.role AND admin.is_active=1` | 동적 |
| operation | **fan-out 안 함**. template 의 11기 `assignee_admin_id` 를 그대로 12기 row 의 assignee 로 사용 (cohort=NULL 인 binnie4·wannie 는 모든 기수 공유) | 1 (template 별 11기 원본 assignee) |
| leader, subleader | `bootcamp_members WHERE cohort_id=12 AND member_role=template.role AND is_active=1` | 동적 |
| leader 의 11기 assignee=NULL template | **fan-out 안 함**. assignee=NULL 그대로 12기에 단일 row 복제 | 1 (NULL) |

**Step C: row 생성**

각 template × 후보 조합으로 12기 task row 생성:
```
INSERT INTO tasks (
  title, role, assignee_admin_id, assignee_member_id, completed,
  start_date, end_date, content_markdown, cohort
) VALUES (
  template.title, template.role,
  (admin이면 후보.id else NULL),
  (member면 후보.id else NULL),
  0,                                                       -- completed 항상 0
  12기.start_date + template.start_day_offset,
  12기.start_date + template.end_day_offset,
  template.content_markdown, '12기'
)
```

### 3.2 Edge Case 처리

| 상황 | 처리 |
|---|---|
| 11기 unassigned leader (assignee=NULL) 81 row | 그대로 NULL assignee 로 12기에 단순 date-shift 복제. fan-out 안 함 |
| 11기 head 의 binnie3 행 2 row ("빛나는 응원조원 3명 추천", "소통방 가리기처리") | template dedupe 단계에서 같은 title 의 Kel 행과 1 template 으로 합쳐짐 → 12기 Kel12 1 row |
| operation 의 wannie 1 row ("[메인강사] 메인 강사 설명회") | template 의 11기 assignee 를 그대로 보존하는 sub-rule: operation role 은 fan-out 안 하고 11기 assignee_admin_id 그대로 유지 (binnie4 → binnie4, wannie → wannie). cohort=NULL 이라 같은 admin row 사용 가능 |
| operation template 에 11기 assignee NULL 이 있는 경우 | 12기에도 NULL assignee 로 복제 |
| 12기에 특정 role 후보 0명 (예: subhead1 없는 경우) | abort (사전 검증 단계). `--allow-missing-roles` 옵션 추가 시 해당 role template 을 skip 하고 진행 |
| 11기 일부 leader template 이 5명 등 부분 fan-out 인 경우 (예: "출석 독려" 8명 중 5명만) | dedupe 단계에서 1 template 으로 합쳐짐 → 12기 9명 전원에게 fan-out (= 더 광범위 할당). 사용자님이 "role에 맞게" 요청한 의도와 일치. 부분 할당 정보는 의도적으로 손실 |

### 3.3 Curriculum — Verbatim Date-shift

```
INSERT INTO curriculum_items (cohort, target_date, task_type, note, sort_order, created_by)
SELECT '12기', target_date + INTERVAL 49 DAY, task_type, note, sort_order, NULL
FROM curriculum_items
WHERE cohort='11기'
```
- `created_by` 는 NULL (스크립트 실행)
- `created_at` / `updated_at` 은 default

---

## 4. 실행 형태

### 4.1 PHP migrate 스크립트
- 위치: `boot-dev/migrate_clone_cohort_tasks.php`
- 실행 위치: boot-dev (DEV 검증) → boot-prod (PROD 적용)
- 인자:
  ```
  --from=<cohort>           복제 원본 (예: 11기). 필수
  --to=<cohort>             복제 대상 (예: 12기). 필수
  --dry-run                 실제 INSERT 안 함, 예상 결과만 출력
  --force                   대상 cohort 의 기존 task/curriculum 모두 DELETE 후 진행
  --allow-missing-roles     특정 role 후보 0명일 때 abort 대신 skip
  ```

### 4.2 동작 흐름
1. `.db_credentials` 로드 (실행 위치 기준 boot-dev 또는 boot-prod)
2. CLI 인자 파싱
3. **사전 검증**:
   - cohorts 테이블에 `--from`, `--to` 둘 다 존재? 없으면 abort
   - `--to` cohort 의 기존 tasks / curriculum_items 0 건? 1건 이상이면 `--force` 없이는 abort
   - 각 role 별 `--to` 후보 ≥ 1 (head, coach, sub_coach, leader, subleader, operation, subhead1, subhead2 — 11기 데이터에 등장하는 role 만 검증)
4. day-offset = `--to`.start_date − `--from`.start_date 계산
5. **트랜잭션 시작** (BEGIN)
6. `--force` 시: `DELETE FROM tasks WHERE cohort='--to'`, `DELETE FROM curriculum_items WHERE cohort='--to'`
7. Task 처리:
   - 11기 task 를 (role, title, content_markdown, start_day_offset, end_day_offset) 별 dedupe → templates 추출 + 각 template 의 11기 assignee 정보 보존 (operation 처리용)
   - 각 template 마다 후보 결정
   - INSERT (또는 dry-run 시 카운트만)
8. Curriculum 처리:
   - `INSERT INTO curriculum_items ... SELECT ...` (date shift)
9. **검증 출력**:
   - role 별 11기 templates 수 / 12기 후보 수 / 12기 row 생성 수
   - 11기 unassigned 81 / curriculum 139 의 카운트 일치 확인
10. dry-run 이면 **ROLLBACK**, 아니면 **COMMIT**
11. 완료 메시지 + 통계 출력

### 4.3 예상 출력 (dry-run, PROD 11기 → 12기)
```
[검증]
  11기 cohort: 2026-03-23 ~ 2026-04-23 (✓)
  12기 cohort: 2026-05-11 ~ 2026-06-12 (✓)
  Day shift: +49일

[12기 후보]
  head:        Kel12 (1)
  coach:       Kel12, Lulu12, Ella12 (3)
  sub_coach:   Kel12, Lulu12, Ella12, Hyun12, Jay12, Darren12, Flora12 (7)
  operation:   binnie4, wannie (2 — 11기 assignee 보존)
  leader:      <member 9명> (9)
  subleader:   <member 8명> (8)

[Task 복제 계획]
  role        11기 rows  templates  12기 후보   12기 rows
  head        143        ≈ 142      1           ≈ 142
  coach       37         ≈ 12       3           ≈ 36
  sub_coach   24         ≈ 3        7           ≈ 21
  operation   19         ≈ 19       (보존)      19
  leader      240        ≈ 30       9           ≈ 270
  leader(NULL) 81        81         NULL        81
  ─────────────────────────────────────────────
  TOTAL       544                                ≈ 569

[Curriculum 복제 계획]
  11기 139 row → 12기 139 row (date +49일 shift)

[DRY-RUN] 변경 사항 없음. ROLLBACK
```

---

## 5. 안전장치

### 5.1 트랜잭션
모든 INSERT / DELETE 는 단일 트랜잭션. 어떤 단계든 예외 발생 시 ROLLBACK.

### 5.2 사전 검증 abort 조건
| 조건 | 메시지 |
|---|---|
| `--from` cohort 없음 | "원본 cohort '11기' 가 cohorts 테이블에 없습니다" |
| `--to` cohort 없음 | "대상 cohort '12기' 가 cohorts 테이블에 없습니다" |
| `--to` 에 task/curriculum 이미 존재, `--force` 없음 | "12기 에 이미 task N건 / curriculum M건 존재. --force 사용하거나 수동 삭제 필요" |
| 특정 role 후보 0명, `--allow-missing-roles` 없음 | "role 'head' 의 12기 후보가 없습니다. admin_roles 등록 후 재실행 또는 --allow-missing-roles" |

### 5.3 멱등성
재실행 시:
- 정상: abort (12기 이미 채워짐 감지)
- 의도된 재실행: `--force` (DELETE + 재생성)

### 5.4 검증 출력
스크립트 종료 시점에 다음 통계 출력:
- 11기 source row 수 (role 별)
- 12기 생성 row 수 (role 별)
- 모든 12기 task 의 assignee 검증 (role 별 fan-out 인원 수와 일치)
- 12기 curriculum_items 수 = 11기 수 일치

---

## 6. 배포 플로우 (메모리 규칙 준수)

1. **boot-dev에서 작성**: `migrate_clone_cohort_tasks.php` + 단위 테스트는 미작성 (1회성 + dry-run 으로 검증)
2. **DEV 검증**: DEV 11기 데이터는 stub 14건/9건 이지만 스크립트 흐름 확인은 가능. dry-run + force + 실 실행 모두 DEV 에서 동작 확인
3. **commit + push**: dev 브랜치
4. ⛔ **사용자 확인 게이트**: dev push 후 멈춤. 사용자님 확인
5. **운영 반영 명시 요청 시**: dev → main merge → push origin main → boot-prod 에서 git pull
6. **PROD dry-run**: `cd /root/boot-prod && php migrate_clone_cohort_tasks.php --from=11기 --to=12기 --dry-run`. 결과 사용자님 확인
7. **PROD 본 실행**: 사용자님 OK 후 dry-run 빼고 실행
8. **결과 검증**: PROD `tasks` / `curriculum_items` row 수 / role 별 분포 확인
9. **운영자 보정**: 어드민 페이지에서 12기 task / 진도 본문 검토 + 강 번호 등 보정

---

## 7. 비범위 (Out of Scope)

- 어드민 UI 버튼 (재사용성 ↑ 작업 시간 ↑ — YAGNI)
- task 본문 자동 업데이트 (예: "00기" 같은 placeholder 치환)
- 12기 → 13기 자동 복제 자동화 (현재 스크립트로 수동 실행 가능)
- 멤버 leader/subleader 의 task 부담 균등 검증 (단순 fan-out 만)
- 11기 → 12기 task 본문 내 링크/노션 URL 의 11기→12기 자동 치환

---

## 8. 검증 체크리스트 (PROD 실행 후)

- [ ] `SELECT cohort, COUNT(*) FROM tasks GROUP BY cohort` → 12기 ≈ 569
- [ ] `SELECT role, COUNT(*) FROM tasks WHERE cohort='12기' GROUP BY role` → head ≈ 142, coach ≈ 36, sub_coach ≈ 21, operation 19, leader ≈ 351
- [ ] `SELECT COUNT(*) FROM curriculum_items WHERE cohort='12기'` → 139
- [ ] `SELECT MIN(start_date), MAX(end_date) FROM tasks WHERE cohort='12기'` → 11기 범위 +49일과 일치
- [ ] 어드민 /head 대시보드에서 12기 task 시각 확인
- [ ] 어드민 progress / curriculum 페이지에서 12기 진도 시각 확인
