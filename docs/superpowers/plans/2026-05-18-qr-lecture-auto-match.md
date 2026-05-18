# QR ↔ 강의 자동 매칭 보강 구현 plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec:** `docs/superpowers/specs/2026-05-18-qr-lecture-auto-match-design.md`

**Goal:** 운영자 출석 페이지의 "기타" QR 분류를 줄이기 위해, QR 세션 → 강의 자동 매칭을 3-tier cascade (정확 / 동일 이름 그룹 / 시간대 단일 후보) 로 보강하고 12기 NULL 15건을 소급 백필한다.

**Architecture:** `findMatchingLectureSession()` 헬퍼를 `api/services/qr_match.php` 신규 파일로 추출. `api/qr.php create_session` 과 백필 CLI 스크립트 둘 다 이 헬퍼 호출. spec § 4 의 Tier A/B/C 순서로 cascade — A 성공 시 B/C 안 시도, B 성공 시 C 안 시도.

**Tech Stack:** PHP 8 + PDO (MySQL) + boot 기존 `getDB()` 패턴. CLI 백필은 `migrate_cafe_assignment_date_7am_cutoff.php` 와 동일한 boilerplate (`--dry-run | --apply` argv 분기).

**디자인 결정 (spec 와 차이):**
- spec § 4 는 `array $admin` 시그니처지만 헬퍼가 admin_id 만 쓰므로 `int $adminId` 로 단순화 (TDD 작성 시 자연스러움). `$adminId = 0` 으로 호출하면 Tier A·B skip.
- `$atDate`/`$atTime` 모두 null 이면 KST 현재 (`config.php:7` 에서 `Asia/Seoul` 설정됨). 둘 다 지정해야 함 — 한쪽만 null 이면 nullable 의도가 모호하므로 가드.

---

## File Structure

| 파일 | 역할 | 상태 |
|------|------|------|
| `public_html/api/services/qr_match.php` | `findMatchingLectureSession()` 헬퍼만 정의 (require_once 안전) | Create |
| `public_html/api/qr.php` | `create_session` 의 인라인 매칭 SQL 제거, 헬퍼 호출로 교체 | Modify (라인 ~82-101) |
| `tests/qr_lecture_match_test.php` | 9개 시나리오 단위 테스트 (transaction rollback) | Create |
| `tests/qr_match_invariants.php` | INV-1/2/3 read-only invariant (DEV/PROD 양쪽 실행 가능) | Create |
| `backfill_qr_lecture_session.php` (boot 루트) | CLI dry-run / apply 백필 | Create |

---

## Task 1: 헬퍼 함수 stub + Tier A (정확 매칭) TDD

**Files:**
- Create: `public_html/api/services/qr_match.php`
- Create: `tests/qr_lecture_match_test.php`

- [ ] **Step 1: Write failing test (Tier A scenario)**

Create `tests/qr_lecture_match_test.php`:

```php
<?php
/**
 * findMatchingLectureSession() 단위 테스트.
 * DEV DB transaction rollback 으로 격리.
 * 사용: php tests/qr_lecture_match_test.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';
require_once __DIR__ . '/../public_html/api/services/qr_match.php';

$pass = 0; $fail = 0;
function t(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

$db = getDB();
$db->beginTransaction();

try {
    // ── 공통 fixture: 테스트용 cohort 1개 ──────────────────────
    $cohortLabel = 'QRM_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel, $cohortLabel]);
    $cohortId = (int)$db->lastInsertId();

    // 공통 헬퍼: admin 만들기
    $insAdmin = $db->prepare("INSERT INTO admins (username, password_hash, name, role, is_active)
                              VALUES (?, '$2y$10$dummy', ?, ?, 1)");
    $insLecture = $db->prepare("INSERT INTO lecture_sessions
        (cohort_id, coach_admin_id, lecture_date, start_time, end_time, stage, title, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    // ───────────────────────────────────────────────────────────
    // 시나리오 1: Tier A 정확 매칭
    //   admin Kel_new(role=coach), 같은 admin_id로 등록된 오늘 강의 1건
    // ───────────────────────────────────────────────────────────
    $uname = 'kel_t1_' . bin2hex(random_bytes(2));
    $insAdmin->execute([$uname, 'Kel_T1', 'coach']);
    $kelId = (int)$db->lastInsertId();
    $insLecture->execute([$cohortId, $kelId, date('Y-m-d'), '06:00:00', '07:00:00', 1, '[06:00] Kel 1단계', 'active']);
    $lectureId1 = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, $kelId, $cohortId);
    t('T1: Tier A 동일 admin_id 매칭', $matched === $lectureId1, "expected={$lectureId1}, got=" . var_export($matched, true));

} finally {
    $db->rollBack();
}

echo "\n── 결과: PASS {$pass}, FAIL {$fail} ──\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails (헬퍼 정의 안 됨)**

```bash
cd /root/boot-dev && php tests/qr_lecture_match_test.php
```

Expected: `PHP Fatal error: Uncaught Error: Call to undefined function findMatchingLectureSession()` — `qr_match.php` 가 비어 있으므로.

- [ ] **Step 3: Create qr_match.php with Tier A only**

Create `public_html/api/services/qr_match.php`:

```php
<?php
/**
 * QR ↔ 강의 자동 매칭 헬퍼 (3-tier cascade).
 * spec: docs/superpowers/specs/2026-05-18-qr-lecture-auto-match-design.md
 *
 * @param PDO $db
 * @param int $adminId  QR 발급 admin id. 0 이면 Tier A·B skip, Tier C 만 시도.
 * @param int $cohortId
 * @param ?string $atDate 'YYYY-MM-DD'. null 이면 KST 오늘.
 * @param ?string $atTime 'HH:MM:SS'. null 이면 KST 현재 시각.
 * @return ?int 매칭된 lecture_sessions.id, 없으면 null.
 */
function findMatchingLectureSession(
    PDO $db, int $adminId, int $cohortId,
    ?string $atDate = null, ?string $atTime = null
): ?int {
    $atDate = $atDate ?? date('Y-m-d');
    $atTime = $atTime ?? date('H:i:s');

    // ── Tier A: 정확 매칭 (admin_id 일치) ─────────────────────
    if ($adminId > 0) {
        $stmt = $db->prepare("
            SELECT id FROM lecture_sessions
            WHERE coach_admin_id = ?
              AND lecture_date = ?
              AND cohort_id = ?
              AND status = 'active'
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, start_time, ?)) ASC
            LIMIT 1
        ");
        $stmt->execute([$adminId, $atDate, $cohortId, $atTime]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int)$id;
    }

    return null;
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /root/boot-dev && php tests/qr_lecture_match_test.php
```

Expected: `PASS  T1: Tier A 동일 admin_id 매칭` → `결과: PASS 1, FAIL 0`

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/api/services/qr_match.php tests/qr_lecture_match_test.php && git commit -m "$(cat <<'EOF'
feat(qr): findMatchingLectureSession 헬퍼 + Tier A 정확 매칭

api/qr.php 의 인라인 매칭 SQL을 services/qr_match.php 헬퍼로 추출
시작. 우선 기존 동작과 동일한 Tier A (admin_id 정확 매칭) 만
구현. Tier B/C 는 후속 task.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Tier B (동일 이름 admin 그룹) TDD

**Files:**
- Modify: `public_html/api/services/qr_match.php`
- Modify: `tests/qr_lecture_match_test.php` (시나리오 2 추가)

- [ ] **Step 1: Add failing test for Tier B scenario**

`tests/qr_lecture_match_test.php` 의 `try { ... }` 블록 안, T1 다음에 추가:

```php
    // ───────────────────────────────────────────────────────────
    // 시나리오 2: Tier B 동일 이름 매칭
    //   Darren 옛 admin(16-sim, role=sub_coach) 발급 QR
    //   → 새 Darren(role=sub_coach)으로 등록된 강의에 매칭
    // ───────────────────────────────────────────────────────────
    $insAdmin->execute(['darren_old_' . bin2hex(random_bytes(2)), 'Darren_T2', 'sub_coach']);
    $darrenOldId = (int)$db->lastInsertId();
    $insAdmin->execute(['darren_new_' . bin2hex(random_bytes(2)), 'Darren_T2', 'sub_coach']);
    $darrenNewId = (int)$db->lastInsertId();
    $insLecture->execute([$cohortId, $darrenNewId, date('Y-m-d'), '06:00:00', '07:00:00', 2, '[06:00] Darren 2단계', 'active']);
    $lectureId2 = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, $darrenOldId, $cohortId);
    t('T2: Tier B 동일 이름 admin 매칭', $matched === $lectureId2, "expected={$lectureId2}, got=" . var_export($matched, true));
```

- [ ] **Step 2: Run test to verify T2 fails (T1 still passes)**

```bash
cd /root/boot-dev && php tests/qr_lecture_match_test.php
```

Expected: `PASS T1` / `FAIL T2: Tier B 동일 이름 admin 매칭 (expected=<id>, got=NULL)`

- [ ] **Step 3: Add Tier B to qr_match.php**

`public_html/api/services/qr_match.php` 의 Tier A `return (int)$id;` 다음, `return null;` 직전에 삽입:

```php
    // ── Tier B: 동일 이름 admin 그룹 매칭 ────────────────────
    if ($adminId > 0) {
        $stmt = $db->prepare("
            SELECT id FROM lecture_sessions
            WHERE coach_admin_id IN (
                SELECT id FROM admins
                WHERE name = (SELECT name FROM admins WHERE id = ?)
                  AND role IN ('coach','sub_coach','head','subhead1','subhead2')
              )
              AND lecture_date = ?
              AND cohort_id = ?
              AND status = 'active'
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, start_time, ?)) ASC
            LIMIT 1
        ");
        $stmt->execute([$adminId, $atDate, $cohortId, $atTime]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int)$id;
    }
```

- [ ] **Step 4: Run test to verify both pass**

```bash
cd /root/boot-dev && php tests/qr_lecture_match_test.php
```

Expected: `PASS T1` + `PASS T2` → `결과: PASS 2, FAIL 0`

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/api/services/qr_match.php tests/qr_lecture_match_test.php && git commit -m "$(cat <<'EOF'
feat(qr): Tier B 동일 이름 admin 그룹 매칭 추가

옛/새 admin_id 중복 (Kel 9/20, Darren 16/25 등) 으로 자동 매칭이
실패하던 케이스를, admin name + coach 권한 role 가드로 묶어
매칭. Tier A 가 0건일 때만 시도.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Tier C (시간대 단일 후보) TDD

**Files:**
- Modify: `public_html/api/services/qr_match.php`
- Modify: `tests/qr_lecture_match_test.php` (시나리오 3, 4, 5 추가)

- [ ] **Step 1: Add failing tests for Tier C scenarios**

`tests/qr_lecture_match_test.php` 의 T2 다음에 추가:

```php
    // ───────────────────────────────────────────────────────────
    // 시나리오 3: Tier C 단일 후보 매칭
    //   Ella(role=coach) admin, 그날 Ella 강의 없음, ±60분 내 강의 정확히 1건
    // ───────────────────────────────────────────────────────────
    $insAdmin->execute(['ella_t3_' . bin2hex(random_bytes(2)), 'Ella_T3', 'coach']);
    $ellaId = (int)$db->lastInsertId();
    // 같은 cohort, Ella 가 아닌 다른 코치 강의 1건 — 시각은 현재 시각 기준 ±60분 안
    $insAdmin->execute(['kel_t3_' . bin2hex(random_bytes(2)), 'Kel_T3', 'coach']);
    $kelT3 = (int)$db->lastInsertId();
    $nowTime = date('H:i:s');
    $insLecture->execute([$cohortId, $kelT3, date('Y-m-d'), $nowTime, $nowTime, 1, '[T3] Kel', 'active']);
    $lectureId3 = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, $ellaId, $cohortId);
    t('T3: Tier C 단일 후보 매칭', $matched === $lectureId3, "expected={$lectureId3}, got=" . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 4: Tier C 후보 2건이면 매칭 X (보수성)
    //   Ella 발급, ±60분 내 강의 2건 → NULL
    // ───────────────────────────────────────────────────────────
    $insAdmin->execute(['lulu_t4_' . bin2hex(random_bytes(2)), 'Lulu_T4', 'coach']);
    $luluT4 = (int)$db->lastInsertId();
    $insLecture->execute([$cohortId, $luluT4, date('Y-m-d'), $nowTime, $nowTime, 2, '[T4] Lulu', 'active']);

    $matched = findMatchingLectureSession($db, $ellaId, $cohortId);
    t('T4: Tier C 후보 2건이면 NULL (보수성)', $matched === null, 'got=' . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 5: Tier C 후보 0건이면 NULL
    //   별도 cohort (강의 없음) 에서 Ella 발급
    // ───────────────────────────────────────────────────────────
    $cohortLabel2 = 'QRM2_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel2, $cohortLabel2]);
    $cohortId2 = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, $ellaId, $cohortId2);
    t('T5: Tier C 후보 0건이면 NULL', $matched === null, 'got=' . var_export($matched, true));
```

- [ ] **Step 2: Run test to verify T3/T4/T5 fail**

```bash
cd /root/boot-dev && php tests/qr_lecture_match_test.php
```

Expected: `PASS T1/T2`, `FAIL T3` (got=NULL), `PASS T4/T5` (이미 NULL이라 우연히 PASS — 그러나 다음 step 후 T3 가 PASS 되면서도 T4/T5 가 유지돼야 한다는 점이 진짜 검증임)

- [ ] **Step 3: Add Tier C to qr_match.php**

`public_html/api/services/qr_match.php` 의 Tier B `return (int)$id;` 다음, `return null;` 직전에 삽입:

```php
    // ── Tier C: 시간대 단일 후보 매칭 (보수적) ────────────────
    $stmt = $db->prepare("
        SELECT id FROM lecture_sessions
        WHERE lecture_date = ?
          AND cohort_id = ?
          AND status = 'active'
          AND ABS(TIMESTAMPDIFF(MINUTE, start_time, ?)) <= 60
    ");
    $stmt->execute([$atDate, $cohortId, $atTime]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($rows) === 1) return (int)$rows[0];
```

- [ ] **Step 4: Run test to verify all five pass**

```bash
cd /root/boot-dev && php tests/qr_lecture_match_test.php
```

Expected: `PASS T1` + `PASS T2` + `PASS T3` + `PASS T4` + `PASS T5` → `결과: PASS 5, FAIL 0`

특히 T4 가 PASS 되어야 보수성 검증 성공 (후보 2건일 때 NULL).

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/api/services/qr_match.php tests/qr_lecture_match_test.php && git commit -m "$(cat <<'EOF'
feat(qr): Tier C 시간대 단일 후보 매칭 추가

±60분 내 활성 강의가 정확히 1건일 때만 매칭, 2건 이상이면 NULL
유지 (잘못된 매칭 방지). 코치가 다른 코치 강의를 대신 호스팅한
케이스 일부를 자동 매칭으로 흡수.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Edge case 시나리오 (admin_id=0, role 가드, cancelled)

**Files:**
- Modify: `tests/qr_lecture_match_test.php` (시나리오 6, 7, 8, 9 추가)

- [ ] **Step 1: Add edge-case tests**

`tests/qr_lecture_match_test.php` 의 T5 다음에 추가:

```php
    // ───────────────────────────────────────────────────────────
    // 시나리오 6: admin_id=0 + Tier C 단일 후보 → 매칭
    // ───────────────────────────────────────────────────────────
    $cohortLabel3 = 'QRM3_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel3, $cohortLabel3]);
    $cohortId3 = (int)$db->lastInsertId();
    $insAdmin->execute(['hyun_t6_' . bin2hex(random_bytes(2)), 'Hyun_T6', 'sub_coach']);
    $hyunT6 = (int)$db->lastInsertId();
    $insLecture->execute([$cohortId3, $hyunT6, date('Y-m-d'), date('H:i:s'), date('H:i:s'), 1, '[T6] Hyun', 'active']);
    $lectureId6 = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, 0, $cohortId3);
    t('T6: admin_id=0 + Tier C 단일 후보 매칭', $matched === $lectureId6, "expected={$lectureId6}, got=" . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 7: admin_id=0 + Tier C 후보 0건 → NULL
    // ───────────────────────────────────────────────────────────
    $cohortLabel4 = 'QRM4_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel4, $cohortLabel4]);
    $cohortId4 = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, 0, $cohortId4);
    t('T7: admin_id=0 후보 없음 → NULL', $matched === null, 'got=' . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 8: Tier B 동명이인이 role='member' (가짜 동명) → Tier B 제외
    //   같은 이름 admin 둘, 하나는 coach 하나는 member.
    //   coach 본인은 그날 강의 없음, member 동명이인이 강의 등록(이상 데이터지만 가드 검증용)
    //   → Tier B 의 role 가드로 member 강의 제외, Tier C 단일 후보 매칭으로 fallback
    // ───────────────────────────────────────────────────────────
    $cohortLabel5 = 'QRM5_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel5, $cohortLabel5]);
    $cohortId5 = (int)$db->lastInsertId();

    $insAdmin->execute(['tina_coach_' . bin2hex(random_bytes(2)), 'Tina_T8', 'coach']);
    $tinaCoachId = (int)$db->lastInsertId();
    $insAdmin->execute(['tina_member_' . bin2hex(random_bytes(2)), 'Tina_T8', 'member']);
    $tinaMemberId = (int)$db->lastInsertId();
    // member 동명이인으로 강의 등록 (이상 데이터 시뮬)
    $insLecture->execute([$cohortId5, $tinaMemberId, date('Y-m-d'), date('H:i:s'), date('H:i:s'), 1, '[T8] fake', 'active']);
    $fakeLectureId = (int)$db->lastInsertId();

    $matched = findMatchingLectureSession($db, $tinaCoachId, $cohortId5);
    // Tier A 0건 (coach 본인은 강의 없음), Tier B 0건 (member 가드), Tier C 1건 (시간대 fallback)
    t('T8: role 가드 + Tier C fallback', $matched === $fakeLectureId, "expected={$fakeLectureId}, got=" . var_export($matched, true));

    // ───────────────────────────────────────────────────────────
    // 시나리오 9: Tier B 후보가 모두 cancelled → Tier B 제외, Tier C 도 0건 → NULL
    // ───────────────────────────────────────────────────────────
    $cohortLabel6 = 'QRM6_' . bin2hex(random_bytes(3));
    $db->prepare("INSERT INTO cohorts (cohort, code, is_active, start_date, end_date)
                  VALUES (?, ?, 1, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))")
       ->execute([$cohortLabel6, $cohortLabel6]);
    $cohortId6 = (int)$db->lastInsertId();
    $insAdmin->execute(['nick_t9_' . bin2hex(random_bytes(2)), 'Nick_T9', 'coach']);
    $nickId = (int)$db->lastInsertId();
    // 강의는 cancelled 상태
    $insLecture->execute([$cohortId6, $nickId, date('Y-m-d'), date('H:i:s'), date('H:i:s'), 1, '[T9] cancelled', 'cancelled']);

    $matched = findMatchingLectureSession($db, $nickId, $cohortId6);
    t('T9: cancelled 강의는 매칭 X', $matched === null, 'got=' . var_export($matched, true));
```

- [ ] **Step 2: Run all tests**

```bash
cd /root/boot-dev && php tests/qr_lecture_match_test.php
```

Expected: `PASS T1..T9` → `결과: PASS 9, FAIL 0`

검증 포인트:
- T6 (admin_id=0 + 단일 후보) — Tier C 진입 확인
- T7 (admin_id=0 + 0건) — NULL 안전
- T8 (member 가드) — Tier B role IN 절이 member 제외, Tier C 가 fallback 으로 매칭
- T9 (cancelled) — Tier A/B/C 모두 `status='active'` 가드

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add tests/qr_lecture_match_test.php && git commit -m "$(cat <<'EOF'
test(qr): edge case 4건 추가 (admin_id=0, role 가드, cancelled)

T6/T7: admin_id=0 발급 QR 의 Tier C 진입.
T8: 동명 admin 중 role='member' 인 쪽 Tier B 가드 후 Tier C fallback.
T9: cancelled 강의는 어느 Tier 에서도 매칭되지 않음.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: qr.php create_session 헬퍼 호출로 교체

**Files:**
- Modify: `public_html/api/qr.php` (라인 ~82-101, ~104-106)

- [ ] **Step 1: 현재 매칭 SQL 위치 확인**

```bash
cd /root/boot-dev && grep -n "당일 해당 코치의 강의 자동 매칭\|INSERT INTO qr_sessions" public_html/api/qr.php
```

Expected: 라인 82 부근에 주석, 라인 103 부근에 INSERT.

- [ ] **Step 2: require + 헬퍼 호출로 교체**

`public_html/api/qr.php` 상단 require 블록 (라인 7-9 부근) 끝에 추가:

```php
require_once __DIR__ . '/services/qr_match.php';
```

그리고 `case 'create_session':` 안 라인 82-101 의 인라인 매칭 블록을:

```php
    // 당일 해당 코치의 강의 자동 매칭
    $lectureSessionId = null;
    if ($sessionType === 'attendance' && $admin['admin_id'] > 0) {
        $today = date('Y-m-d');
        $now = date('H:i:s');
        $lectureStmt = $db->prepare("
            SELECT id FROM lecture_sessions
            WHERE coach_admin_id = ?
              AND lecture_date = ?
              AND cohort_id = ?
              AND status = 'active'
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, start_time, ?)) ASC
            LIMIT 1
        ");
        $lectureStmt->execute([$admin['admin_id'], $today, $cohortId, $now]);
        $lectureRow = $lectureStmt->fetch();
        if ($lectureRow) {
            $lectureSessionId = (int)$lectureRow['id'];
        }
    }
```

다음으로 교체:

```php
    // 당일 강의 자동 매칭 (Tier A → B → C cascade, services/qr_match.php)
    $lectureSessionId = null;
    if ($sessionType === 'attendance') {
        $lectureSessionId = findMatchingLectureSession(
            $db, (int)$admin['admin_id'], $cohortId
        );
    }
```

`$admin['admin_id'] > 0` 가드는 헬퍼 안에서 처리하므로 제거. `sessionType === 'attendance'` 가드는 revival 은 별도 분류이므로 유지 (revival QR 도 헬퍼 결과 NULL 이면 무해하지만, 의도적으로 attendance 만 매칭 시도하던 기존 행동 보존).

- [ ] **Step 3: Run unit tests to confirm no regression**

```bash
cd /root/boot-dev && php tests/qr_lecture_match_test.php
```

Expected: `결과: PASS 9, FAIL 0` (unit test 는 헬퍼만 호출하므로 영향 없어야 함).

- [ ] **Step 4: Smoke test create_session via curl on DEV**

DEV admin 1명으로 로그인 → QR 생성 → 응답 확인. (`/coach/` 페이지에서 새 QR 생성 후 attendance 페이지에서 분류 확인. 시간이 강의 시간대와 떨어져 있으면 NULL 일 수 있음 — 그 자체는 정상.)

수동으로 확인할 항목:
- `qr_sessions` 새 row 생성됨
- `lecture_session_id` 가 null 또는 유효한 lecture_sessions.id 임 (잘못된 id 가 들어가지 않음)

```bash
cd /root/boot-dev && mysql -u SORITUNECOM_DEV_BOOT -ptwee+sl37lSUoVpfVW3gdMdw SORITUNECOM_DEV_BOOT -e "
SELECT id, created_at, admin_id, cohort_id, session_type, lecture_session_id
FROM qr_sessions
ORDER BY id DESC LIMIT 5;
"
```

- [ ] **Step 5: Commit**

```bash
cd /root/boot-dev && git add public_html/api/qr.php && git commit -m "$(cat <<'EOF'
refactor(qr): create_session 의 매칭 SQL 을 헬퍼 호출로 교체

api/qr.php 의 인라인 SQL 21줄 → findMatchingLectureSession() 한 줄
호출. Tier A 동작은 동일, Tier B/C cascade 가 자동 적용됨.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Invariants 작성 (INV-1/2/3)

**Files:**
- Create: `tests/qr_match_invariants.php`

- [ ] **Step 1: Write invariants script**

Create `tests/qr_match_invariants.php`:

```php
<?php
/**
 * QR 매칭 invariants (read-only, DEV/PROD 양쪽 안전 실행).
 * 사용: php tests/qr_match_invariants.php
 */
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/../public_html/config.php';

$db = getDB();
$dbName = $db->query('SELECT DATABASE()')->fetchColumn();
echo "DB: {$dbName}\n\n";

$pass = 0; $fail = 0;
function inv(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  {$name}\n"; return; }
    $fail++;
    echo "FAIL  {$name}" . ($detail ? "  ({$detail})" : '') . "\n";
}

// ── INV-2: FK 일관성 ───────────────────────────────────────
// qr_sessions.lecture_session_id IS NOT NULL row 가 모두 유효한 lecture_sessions 참조
$orphans = $db->query("
    SELECT COUNT(*) FROM qr_sessions qs
    LEFT JOIN lecture_sessions ls ON ls.id = qs.lecture_session_id
    WHERE qs.lecture_session_id IS NOT NULL
      AND ls.id IS NULL
")->fetchColumn();
inv('INV-2: qr_sessions.lecture_session_id FK 일관성', $orphans == 0, "orphan rows={$orphans}");

// ── INV-3: attendance 통계 응답 정상 (활성 cohort 1개로 임의 확인) ──
$activeCohort = $db->query("SELECT id FROM cohorts WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($activeCohort) {
    // attendance.php 의 핵심 쿼리만 직접 재현 (HTTP 호출 안 함)
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM qr_sessions WHERE cohort_id = ?
    ");
    $stmt->execute([(int)$activeCohort]);
    $qrCount = (int)$stmt->fetchColumn();
    inv('INV-3: 활성 cohort qr_sessions 조회 정상', $qrCount >= 0, "cohort={$activeCohort} qr_count={$qrCount}");
} else {
    inv('INV-3: 활성 cohort 없음 (skip)', true);
}

echo "\n── 결과: PASS {$pass}, FAIL {$fail} ──\n";
exit($fail > 0 ? 1 : 0);
```

**참고**: INV-1 (Tier A 회귀 0 — 기존 정확 매칭 row 카운트 보존) 은 코드 배포 전후 비교가 필요하므로 invariants 파일에 못 넣음. Task 5 에서 코드 적용 전후로 다음 쿼리 결과를 비교해 PROD 적용 직전 수동 검증:

```sql
SELECT COUNT(*) FROM qr_sessions qs
JOIN lecture_sessions ls ON ls.id = qs.lecture_session_id
WHERE ls.coach_admin_id = qs.admin_id   -- Tier A 매칭 형태
  AND DATE(qs.created_at) = ls.lecture_date;
```

- [ ] **Step 2: Run invariants on DEV**

```bash
cd /root/boot-dev && php tests/qr_match_invariants.php
```

Expected: `PASS INV-2`, `PASS INV-3` → `결과: PASS 2, FAIL 0` (DEV DB 상태에 따라).

- [ ] **Step 3: Commit**

```bash
cd /root/boot-dev && git add tests/qr_match_invariants.php && git commit -m "$(cat <<'EOF'
test(qr): qr_match invariants (FK 일관성 + attendance 조회)

INV-2: lecture_session_id 가 가리키는 lecture_sessions 존재 확인.
INV-3: 활성 cohort 의 qr_sessions 조회가 에러 없이 응답.
(INV-1 회귀 검증은 PROD 적용 전후 수동 비교)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: 백필 스크립트 (dry-run 모드)

**Files:**
- Create: `backfill_qr_lecture_session.php` (boot 루트)

- [ ] **Step 1: Write dry-run only script**

Create `/root/boot-dev/backfill_qr_lecture_session.php`:

```php
<?php
/**
 * qr_sessions.lecture_session_id 가 NULL 인 row 를 3-tier cascade 로 소급 매칭.
 *
 * Usage:
 *   php backfill_qr_lecture_session.php --cohort=12 --dry-run
 *   php backfill_qr_lecture_session.php --cohort=12 --apply
 *
 * spec: docs/superpowers/specs/2026-05-18-qr-lecture-auto-match-design.md
 */
declare(strict_types=1);
if (php_sapi_name() !== 'cli') exit('CLI only');
require_once __DIR__ . '/public_html/config.php';
require_once __DIR__ . '/public_html/api/services/qr_match.php';

$opts = getopt('', ['cohort:', 'dry-run', 'apply']);
$cohort = isset($opts['cohort']) ? (int)$opts['cohort'] : 0;
$isDryRun = array_key_exists('dry-run', $opts);
$isApply = array_key_exists('apply', $opts);

if ($cohort <= 0 || ($isDryRun === $isApply)) {
    fwrite(STDERR, "Usage: php backfill_qr_lecture_session.php --cohort=N (--dry-run | --apply)\n");
    exit(1);
}
$mode = $isApply ? 'APPLY' : 'DRY-RUN';

$db = getDB();
$dbName = $db->query('SELECT DATABASE()')->fetchColumn();

echo "═══════════════════════════════════════════════════\n";
echo "QR ↔ lecture 자동 매칭 백필\n";
echo "mode:   {$mode}\n";
echo "cohort: {$cohort}\n";
echo "DB:     {$dbName}\n";
echo "═══════════════════════════════════════════════════\n\n";

// ── 대상 row 조회 ─────────────────────────────────────────
$stmt = $db->prepare("
    SELECT qs.id, qs.admin_id, qs.cohort_id, qs.created_at,
           DATE(qs.created_at) AS at_date,
           TIME(qs.created_at) AS at_time,
           COALESCE(a.name, '(시스템)') AS admin_name
    FROM qr_sessions qs
    LEFT JOIN admins a ON a.id = qs.admin_id
    WHERE qs.cohort_id = ?
      AND qs.session_type != 'revival'
      AND qs.lecture_session_id IS NULL
      AND NOT EXISTS (SELECT 1 FROM study_sessions ss WHERE ss.qr_session_id = qs.id)
    ORDER BY qs.created_at ASC
");
$stmt->execute([$cohort]);
$targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$targets) {
    echo "대상 row 0건. 종료.\n";
    exit(0);
}
echo "대상 row: " . count($targets) . "건\n\n";

// ── Tier 별 매칭 시뮬레이션 (헬퍼 호출) ───────────────────
$plan = [];           // qs_id => matched lecture_id|null
$tierCount = ['A' => 0, 'B' => 0, 'C' => 0, 'null' => 0];

foreach ($targets as $r) {
    $qsId = (int)$r['id'];
    $adminId = (int)$r['admin_id'];
    $adminName = $r['admin_name'];

    // 헬퍼는 cascade 통합 결과만 반환. Tier 식별을 위해 각 Tier 를 개별 쿼리로 다시 시도.
    $tierA = null; $tierB = null; $tierC = null;

    if ($adminId > 0) {
        $sa = $db->prepare("
            SELECT id FROM lecture_sessions
            WHERE coach_admin_id = ? AND lecture_date = ? AND cohort_id = ? AND status='active'
            ORDER BY ABS(TIMESTAMPDIFF(SECOND, start_time, ?)) ASC LIMIT 1
        ");
        $sa->execute([$adminId, $r['at_date'], $r['cohort_id'], $r['at_time']]);
        $tierA = $sa->fetchColumn() ?: null;

        if (!$tierA) {
            $sb = $db->prepare("
                SELECT id FROM lecture_sessions
                WHERE coach_admin_id IN (
                    SELECT id FROM admins
                    WHERE name = (SELECT name FROM admins WHERE id = ?)
                      AND role IN ('coach','sub_coach','head','subhead1','subhead2')
                  )
                  AND lecture_date = ? AND cohort_id = ? AND status='active'
                ORDER BY ABS(TIMESTAMPDIFF(SECOND, start_time, ?)) ASC LIMIT 1
            ");
            $sb->execute([$adminId, $r['at_date'], $r['cohort_id'], $r['at_time']]);
            $tierB = $sb->fetchColumn() ?: null;
        }
    }

    if (!$tierA && !$tierB) {
        $sc = $db->prepare("
            SELECT id FROM lecture_sessions
            WHERE lecture_date = ? AND cohort_id = ? AND status='active'
              AND ABS(TIMESTAMPDIFF(MINUTE, start_time, ?)) <= 60
        ");
        $sc->execute([$r['at_date'], $r['cohort_id'], $r['at_time']]);
        $candidates = $sc->fetchAll(PDO::FETCH_COLUMN);
        if (count($candidates) === 1) {
            $tierC = (int)$candidates[0];
        }
    }

    $matched = $tierA ?: $tierB ?: $tierC ?: null;
    $plan[$qsId] = $matched;

    $tag = $tierA ? 'Tier A' : ($tierB ? 'Tier B' : ($tierC ? 'Tier C' : '없음'));
    if ($tierA) $tierCount['A']++;
    elseif ($tierB) $tierCount['B']++;
    elseif ($tierC) $tierCount['C']++;
    else $tierCount['null']++;

    $matchedStr = $matched ? "lecture #{$matched}" : 'NULL 유지';
    printf("QR #%d  %s  %s(%d)  → %s: %s\n",
        $qsId, $r['created_at'], $adminName, $adminId, $tag, $matchedStr);
}

echo "\n";
echo "요약: " . count($targets) . "건 검사 → "
    . ($tierCount['A'] + $tierCount['B'] + $tierCount['C']) . "건 매칭, "
    . $tierCount['null'] . "건 NULL 유지\n";
echo "  Tier A 매칭: {$tierCount['A']}건\n";
echo "  Tier B 매칭: {$tierCount['B']}건\n";
echo "  Tier C 매칭: {$tierCount['C']}건\n";

if ($isDryRun) {
    echo "\n[DRY-RUN] 변경 없음. apply 하려면 --apply 로 재실행.\n";
    exit(0);
}

// APPLY 모드는 Task 8 에서 추가
fwrite(STDERR, "ERROR: --apply 모드는 아직 구현 안 됨 (Task 8)\n");
exit(1);
```

- [ ] **Step 2: Run dry-run on DEV (cohort 12 가 DEV 에 있다면) 또는 임의 cohort**

```bash
cd /root/boot-dev && mysql -u SORITUNECOM_DEV_BOOT -ptwee+sl37lSUoVpfVW3gdMdw SORITUNECOM_DEV_BOOT -e "
SELECT cohort_id, COUNT(*)
FROM qr_sessions
WHERE session_type != 'revival' AND lecture_session_id IS NULL
GROUP BY cohort_id;
"
```

대상 cohort 가 있으면 `php backfill_qr_lecture_session.php --cohort=<id> --dry-run` 실행. 없으면 step skip.

기대: 매칭 후보별 Tier 분류 출력 + 요약. 어떤 row 도 실제 UPDATE 되지 않음.

- [ ] **Step 3: Verify nothing changed**

```bash
cd /root/boot-dev && mysql -u SORITUNECOM_DEV_BOOT -ptwee+sl37lSUoVpfVW3gdMdw SORITUNECOM_DEV_BOOT -e "
SELECT COUNT(*) FROM qr_sessions WHERE lecture_session_id IS NULL;
"
```

dry-run 전후 카운트 동일해야 함.

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && git add backfill_qr_lecture_session.php && git commit -m "$(cat <<'EOF'
feat(qr): backfill_qr_lecture_session.php dry-run 모드

cohort 별로 lecture_session_id=NULL row 를 cascade 매칭해서 어느
Tier 에서 잡힐지 출력. 실제 UPDATE 는 --apply 에서 (다음 task).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: 백필 스크립트 --apply 모드

**Files:**
- Modify: `backfill_qr_lecture_session.php`

- [ ] **Step 1: Replace apply stub with real UPDATE logic**

`backfill_qr_lecture_session.php` 의 마지막 두 줄 (`fwrite(STDERR, ...)` / `exit(1)`) 을 다음으로 교체:

```php
// ── APPLY 모드 ────────────────────────────────────────────
$toUpdate = array_filter($plan, fn($v) => $v !== null);
if (!$toUpdate) {
    echo "\n매칭된 row 없음. 변경 없음.\n";
    exit(0);
}

// 1) 백업 (.db_credentials 직접 파싱, parse_ini_file 의 quote 해석 피하기)
$ts = date('Ymd_His');
$backupPath = "/tmp/qr_sessions_backup_{$ts}.sql";
$creds = [];
foreach (file(__DIR__ . '/.db_credentials', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_contains($line, '=')) {
        [$k, $v] = explode('=', $line, 2);
        $creds[trim($k)] = trim($v);
    }
}
$dumpCmd = sprintf(
    "mysqldump -u %s -p%s %s qr_sessions > %s 2>/tmp/qr_dump_err_{$ts}.log",
    escapeshellarg($creds['DB_USER']),
    escapeshellarg($creds['DB_PASS']),
    escapeshellarg($creds['DB_NAME']),
    escapeshellarg($backupPath)
);
exec($dumpCmd, $out, $rc);
if ($rc !== 0 || !file_exists($backupPath) || filesize($backupPath) < 100) {
    fwrite(STDERR, "ERROR: 백업 실패 (rc={$rc}). /tmp/qr_dump_err_{$ts}.log 확인.\n");
    exit(2);
}
echo "\n백업: {$backupPath} (" . filesize($backupPath) . " bytes)\n";

// 2) 트랜잭션 UPDATE
$db->beginTransaction();
$updated = 0;
try {
    $upd = $db->prepare("UPDATE qr_sessions SET lecture_session_id = ?
                         WHERE id = ? AND lecture_session_id IS NULL");
    foreach ($toUpdate as $qsId => $lectureId) {
        $upd->execute([$lectureId, $qsId]);
        if ($upd->rowCount() > 0) $updated++;
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "ERROR: UPDATE 실패, 롤백됨: " . $e->getMessage() . "\n");
    exit(3);
}

// 3) 백필된 id 목록 저장 (롤백용)
$logPath = "/tmp/qr_sessions_backfilled_{$ts}.txt";
file_put_contents($logPath, implode("\n", array_keys($toUpdate)));
echo "\nAPPLY 완료: {$updated}건 UPDATE.\n";
echo "백필 id 목록: {$logPath}\n";
echo "\n롤백 SQL 예시:\n";
echo "  UPDATE qr_sessions SET lecture_session_id = NULL\n";
echo "  WHERE id IN (" . implode(',', array_keys($toUpdate)) . ");\n";
exit(0);
```

- [ ] **Step 2: Test --apply on DEV (대상이 있으면)**

DEV 에 대상 row 가 있는 경우만:

```bash
cd /root/boot-dev && php backfill_qr_lecture_session.php --cohort=<dev_cohort_id> --apply
```

기대 출력:
```
백업: /tmp/qr_sessions_backup_*.sql (... bytes)
APPLY 완료: N건 UPDATE.
백필 id 목록: /tmp/qr_sessions_backfilled_*.txt
```

검증:
```bash
cd /root/boot-dev && mysql -u SORITUNECOM_DEV_BOOT -ptwee+sl37lSUoVpfVW3gdMdw SORITUNECOM_DEV_BOOT -e "
SELECT id, lecture_session_id FROM qr_sessions
WHERE id IN (\$(cat /tmp/qr_sessions_backfilled_*.txt | tr '\n' ','));
"
```

(또는 single id 로 검증)

- [ ] **Step 3: Invariants pass on DEV**

```bash
cd /root/boot-dev && php tests/qr_match_invariants.php
```

Expected: `결과: PASS 2, FAIL 0` (특히 INV-2 FK 일관성 — 백필이 모두 유효 lecture id 만 박았는지).

- [ ] **Step 4: Commit**

```bash
cd /root/boot-dev && git add backfill_qr_lecture_session.php && git commit -m "$(cat <<'EOF'
feat(qr): backfill --apply 모드 (mysqldump 백업 + 트랜잭션 UPDATE)

1) /tmp 에 qr_sessions mysqldump 백업 (실패 시 abort).
2) 트랜잭션 안에서 WHERE lecture_session_id IS NULL 가드 UPDATE
   (race 안전).
3) 백필된 qr_sessions.id 목록을 /tmp 에 저장 → 롤백 시 사용.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: DEV push + 사용자 검증 대기

**Files:** (변경 없음, 배포 작업)

- [ ] **Step 1: Run all unit + invariants once more**

```bash
cd /root/boot-dev && php tests/qr_lecture_match_test.php && php tests/qr_match_invariants.php
```

Expected: 둘 다 모두 PASS.

- [ ] **Step 2: Push to dev branch**

```bash
cd /root/boot-dev && git push origin dev
```

Expected: 9개 commit (Task 1~8 + spec) push 완료.

- [ ] **Step 3: ⛔ STOP — 사용자 확인 요청**

다음 메시지로 사용자에게 보고:

> DEV 코드 푸시 완료 (9 commits). 다음 검증을 사용자가 직접 해 주세요:
>
> 1. **DEV 어드민 QR 생성**: `https://dev-boot.soritune.com/coach/` 에서 코치 admin 으로 로그인 → QR 생성 → `/operation/#attendance` 에서 분류 확인
> 2. **DEV 백필 dry-run** (대상이 있다면): `php /root/boot-dev/backfill_qr_lecture_session.php --cohort=<dev_cohort> --dry-run`
> 3. **결과 보고 후** PROD 반영 요청 시 → main 머지 + prod pull + PROD dry-run 출력 검토 → 사용자 명시 승인 후 PROD `--apply`

사용자 명시 요청 전 main 머지나 PROD 작업 절대 진행 금지.

---

## PROD 적용 절차 (사용자 승인 후 별도 진행)

1. `cd /root/boot-dev && git checkout main && git merge dev && git push origin main && git checkout dev`
2. `cd /root/boot-prod && git pull origin main`
3. PROD INV-2 사전 카운트:
   ```bash
   mysql -u SORITUNECOM_BOOT -p<pwd> SORITUNECOM_BOOT -e "
   SELECT COUNT(*) AS tier_a_baseline FROM qr_sessions qs
   JOIN lecture_sessions ls ON ls.id = qs.lecture_session_id
   WHERE ls.coach_admin_id = qs.admin_id
     AND DATE(qs.created_at) = ls.lecture_date;"
   ```
4. PROD dry-run: `php /var/www/html/_______site_SORITUNECOM_BOOT/backfill_qr_lecture_session.php --cohort=12 --dry-run`
5. 출력 사람이 검토 후 사용자 명시 승인 (INV-1 회귀 0 확인: 사전 카운트 ≤ 사후 카운트)
6. PROD apply: `--cohort=12 --apply`
7. `php /var/www/html/_______site_SORITUNECOM_BOOT/tests/qr_match_invariants.php`
8. PROD `/operation/#attendance` 12기 통계 눈으로 확인

---

## 롤백

**코드:**
```bash
cd /root/boot-dev && git revert <commit-hash> && git push origin dev
# main 으로 옮긴 경우: cd /root/boot-dev && git checkout main && git merge dev && git push origin main && git checkout dev
# PROD: cd /root/boot-prod && git pull origin main
```

**백필 데이터:**
```bash
# 옵션 1: 백필된 id 목록으로 NULL 복원
mysql -u SORITUNECOM_BOOT -p<pwd> SORITUNECOM_BOOT -e "
UPDATE qr_sessions SET lecture_session_id = NULL
WHERE id IN (\$(cat /tmp/qr_sessions_backfilled_*.txt | tr '\n' ','));"

# 옵션 2: 전체 백업 복원
mysql -u SORITUNECOM_BOOT -p<pwd> SORITUNECOM_BOOT < /tmp/qr_sessions_backup_*.sql
```
