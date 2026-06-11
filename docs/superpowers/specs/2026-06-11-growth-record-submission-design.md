# 성장기록 제출 (Before/After 음성 업로드) 설계

날짜: 2026-06-11
대상: boot.soritune.com (12기 성장기록 VOD 5주 연장 이벤트, 이후 기수에도 재사용)

## 배경 / 목적

부트캠프 수강생이 5주 훈련 전후 변화를 글(카페/블로그 후기) + 사진 + Before/After 음성으로 기록하는 "성장기록 미션". 기존 후기 제출 기능(URL 링크 제출 → 코인 지급)을 **대체**하는 별도 화면이다.

- 후기는 기존처럼 외부 카페/블로그에 작성(사진 3장 포함)하고 **링크만 제출**.
- 음성 파일(Before/After 각 1개)은 카페/블로그 글에도 첨부하지만, **우리 사이트에도 직접 업로드**받는다 (운영팀 원본 확보 목적).
- 혜택: VOD 5주 연장 — 시스템 밖에서 운영팀이 2026-06-17 수동 일괄 반영. **코인 지급 없음.**
- 우수 제출자는 "베스트 성장러" 마케팅 콘텐츠로 활용 → 제출 전 동의 체크 필수.

## 확정된 결정 사항

| 항목 | 결정 |
|------|------|
| 후기 방식 | 기존과 동일: 카페/블로그 작성 + 링크 제출 |
| 기능 구조 | 별도 '성장기록 제출' 화면. **기존 후기 제출 기능을 대체** — 기존 회원용 후기 화면/진입점 제거 |
| 코인 | 지급 없음 |
| 자격 검증 | 시스템 강제 안 함 — 활성 회원 누구나 제출 가능. 완주/감점(0~-15) 필터링은 운영팀이 admin 화면에서 수동 확인 |
| 제출 횟수 | 회원당 활성 제출 1회. 수정 불가, 운영자 취소 → 재제출 가능 (기존 후기 패턴) |

## DB

### 신규 테이블 `growth_record_submissions`

```sql
CREATE TABLE growth_record_submissions (
    id                INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    member_id         INT UNSIGNED NOT NULL,
    cohort_id         INT UNSIGNED NOT NULL,          -- 제출 시점 회원 cohort 스냅샷
    url               VARCHAR(500) NOT NULL,          -- 카페/블로그 후기 링크
    before_file       VARCHAR(255) NOT NULL,          -- 서버 저장 파일명
    after_file        VARCHAR(255) NOT NULL,
    before_orig_name  VARCHAR(255) NOT NULL,          -- 업로드 당시 원본 파일명
    after_orig_name   VARCHAR(255) NOT NULL,
    before_mime       VARCHAR(50) NOT NULL,           -- finfo 실측 MIME (스트리밍 Content-Type)
    after_mime        VARCHAR(50) NOT NULL,
    consent_agreed_at DATETIME NOT NULL,              -- 마케팅 활용 동의 시각 (체크 필수)
    submitted_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_at      DATETIME NULL,
    cancelled_by      INT UNSIGNED NULL,
    cancel_reason     VARCHAR(255) NULL,
    -- 활성(미취소) 제출 1회를 DB 레벨에서 보장: 취소되면 NULL이 되어 unique에서 빠짐
    active_member_id  INT UNSIGNED AS (IF(cancelled_at IS NULL, member_id, NULL)) PERSISTENT,
    UNIQUE KEY uq_active_member (active_member_id),
    INDEX idx_submitted_at (submitted_at DESC),
    INDEX idx_member (member_id),
    INDEX idx_cohort (cohort_id),
    CONSTRAINT fk_growth_member FOREIGN KEY (member_id) REFERENCES bootcamp_members(id),
    CONSTRAINT fk_growth_cohort FOREIGN KEY (cohort_id) REFERENCES cohorts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- 활성 제출 1회 제한: 애플리케이션 검사(친절한 에러 메시지용) + **PERSISTENT generated column unique 키로 DB 레벨 보장** (MariaDB 10.5 확인됨). 동시 요청이 들어와도 한쪽은 duplicate key로 실패 → "이미 제출됨" 응답으로 매핑.
- `cohort_id`: `bootcamp_members.cohort_id`가 NOT NULL이고 활성 2,079명 중 NULL 0건 확인 (DEV DB 2026-06-11) — NOT NULL FK 유지. 기수 라벨은 `cohorts.cohort` join.
- 조(group)는 스냅샷하지 않음 — admin 목록에서 기존 reviews_list와 동일하게 `LEFT JOIN bootcamp_groups bg ON bg.id = bm.group_id`로 **현재 조 기준** 표시 (기존 후기 관리와 같은 동작).
- URL 검증은 기존 review와 동일 (`https?://`, 10~500자).

### `system_contents` 시드 (운영자가 admin에서 수정 가능)

| key | 초기값 | 용도 |
|-----|--------|------|
| `growth_record_enabled` | `on` | 접수 ON/OFF 토글 |
| `growth_record_deadline` | `2026-06-16 23:59:59` | 마감 시각 (지나면 제출 차단) |
| `growth_record_guide` | 아래 참조 | 회원 화면 안내 마크다운 |

`growth_record_guide` 초기값에 포함할 내용 (기획안 5번 "노출 필요"):
- 성장기록 미션 안내, 후기 작성 안내(가이드 질문 3개), 사진 3장 첨부 안내
- Before/After 음성 안내 (Before: 시작 시점 가공 안 된 소리 — 12기 첫 수강이면 1주차 말까미션 음성, 기존 수강생이면 소리튠 시작 전 녹음 / After: 가장 자신 있는 과제 음성 또는 5주차 말까미션 음성)
- 카페/블로그 첨부용 파일명 양식 안내 (`12기_닉네임_before`, `12기_닉네임_after`) — 사이트 업로드 파일은 서버가 자동 명명하므로 사용자가 맞출 필요 없음을 명시
- 제출 마감일 (2026-06-16 화), 혜택 요약 (VOD 5주 연장, 6-17 수요일 일괄 반영)
- 대상/감점 기준 문구는 운영팀 확정 후 운영자가 직접 수정 (초기값에는 자리만 잡아둠)

## 음성 업로드

- before/after 각 1개 **필수**.
- 허용 형식: **mp3, m4a(mp4), wav, webm, ogg** — 폰 녹음 파일 업로드를 가정해 bravo보다 넓게.
- 검증은 확장자가 아니라 **finfo 실측 MIME 기준** (bravo의 `bravoAnswerValidateUpload` 패턴): MIME→저장 확장자 매핑 테이블 (`audio/mpeg`→mp3, `audio/mp4`·`video/mp4`→m4a, `audio/x-m4a`→m4a, `audio/wav`·`audio/x-wav`→wav, `audio/webm`·`video/webm`→webm, `audio/ogg`→ogg). 매핑에 없으면 거부. 실측 MIME을 DB에 저장하고 스트리밍 시 그 값을 Content-Type으로 사용.
- 개당 최대 **20MB**.
- 저장 위치: docroot 밖 `<site_root>/growth_uploads/` (bravo_uploads와 동일 구조, SELinux 컨텍스트 `httpd_sys_rw_content_t` 설정 — [[feedback_selinux_upload]]).
- 서버 자동 명명: `{cohort}_{닉네임}_{before|after}_{submission_id}.{ext}` — 파일시스템 안전 문자로 정규화 (임시 저장 → DB INSERT로 id 확보 → 최종 명명 이동). 원본 파일명은 DB `*_orig_name`에 보존.
- 직접 URL 접근 불가. PHP 스트리밍 엔드포인트로 **본인 + 운영진(admin 권한)**만 재생/다운로드. bravo_attempts.php의 검증/스트리밍 헬퍼 패턴 차용.

## API (`api/bootcamp.php` fragment — $handlerMap 등록 필수)

신규 서비스 파일 `api/services/growth_record.php`:

| action | method | 권한 | 동작 |
|--------|--------|------|------|
| `my_growth_record_status` | GET | 회원 | 접수 여부(enabled+deadline), 가이드, 내 제출 내역 |
| `submit_growth_record` | POST (multipart) | 회원 | url + before/after 파일 + consent 검증 후 저장. consent 미체크 시 거부 |
| `growth_record_audio` | GET | 본인 또는 운영진 | 음성 스트리밍/다운로드 (`id`, `which=before\|after`) |
| `growth_records_list` | GET | 운영진 | 목록 + 카운트 (필터: cohort, 닉네임, 상태) |
| `growth_record_cancel` | POST | 운영진 | 소프트 취소 (사유 1~255자) |
| `growth_record_settings_save` | POST | 운영진 | enabled / deadline / guide 저장 |

운영진 권한 범위는 기존 reviews와 동일: operation, coach, head, subhead1, subhead2.

## 회원 화면 (신규 `js/member-growth-record.js`)

- 진입점: member-shortcuts 카드 — 기존 '후기 작성하기' 카드를 '성장기록 제출' 카드로 **교체**.
- 구성:
  1. 안내 영역 — `growth_record_guide`를 기존 `MemberHome.renderMarkdown`으로 렌더. 이 렌더러는 HTML 이스케이프를 하지 않는 운영진 신뢰 모델(기존 review_guide·score_guide와 동일: 편집 권한이 운영진 admin으로 한정). 신규 sanitizer 도입은 하지 않되, 이 신뢰 전제를 코드 주석으로 명시.
  2. 후기 링크 입력
  3. Before 음성 파일 선택 + After 음성 파일 선택 (`<input type=file accept="audio/*,.mp3,.m4a,.wav,.webm,.ogg">`)
  4. 동의 안내 박스 + 필수 체크박스 (기획안 4번 문구 그대로). **미체크 시 제출 버튼 비활성** + 서버에서도 재검증
  5. 제출 버튼
- 제출 완료 / 기제출 시: 기획안 7번 완료 메시지 + 제출 내역(링크, 원본 파일명, 제출일시) 표시. 재생 버튼으로 본인 업로드 확인 가능.
- 마감 후 / 토글 OFF: 접수 종료 안내만 표시.
- 업로드 진행 표시 (20MB×2라 수 초 걸릴 수 있음) + 실패 시 명확한 에러 (형식/용량 초과 구분).

## Admin 화면 (신규 `js/admin-growth-records.js`, 운영 탭에 '성장기록' 추가)

- 목록 컬럼: 제출일시, 기수, 조, 닉네임, 후기 링크(새 탭), Before/After 인라인 `<audio>` 재생 + 다운로드 링크, 동의 시각, 상태(active/cancelled).
- 필터: 기수, 닉네임 검색, 상태. 카운트(전체/활성/취소).
- 설정: 접수 ON/OFF, 마감일, 가이드 수정 (textarea).
- 취소: 사유 입력 후 소프트 취소. 코인 회수 없음(지급 자체가 없으므로).
- 기존 '후기 관리' 탭은 과거 데이터 조회용으로 유지. 기존 후기 접수 토글(`review_cafe_enabled`/`review_blog_enabled`)은 OFF로 운영 (코드 삭제 없음 — 회원 진입점만 제거).

## 마이그레이션

`migrate_growth_record_submissions.php` — 기존 컨벤션 (dry-run 지원, 트랜잭션, 재실행 안전, `require_once` 사용 — [[feedback_php_require_silent_fatal]]):
1. `growth_record_submissions` CREATE TABLE IF NOT EXISTS
2. `system_contents` 3개 키 INSERT IGNORE
3. (별도 수동 단계) `growth_uploads/` 디렉토리 생성 + 권한/SELinux 컨텍스트

## 에러 처리 / 엣지 케이스

- multipart 업로드 부분 실패(한 파일만 도착, 용량 초과로 PHP가 드롭) → 명확한 에러 반환, DB/파일 어느 쪽도 부분 저장 안 함.
- **제출 원자성 (순서 고정)**: ① 두 파일 모두 검증 + 임시 저장 → ② DB 트랜잭션 BEGIN → ③ INSERT로 submission_id 확보 → ④ 임시 파일을 최종 파일명으로 이동(rename) → ⑤ 이동 성공 시 UPDATE로 파일명 기록 후 COMMIT. ④~⑤ 실패 시 ROLLBACK + 이동된/임시 파일 삭제. COMMIT 직후 crash로 남는 고아 임시 파일은 무해(주기 정리 불요, 수동 청소 가능).
- `upload_max_filesize`/`post_max_size` php.ini 값이 20MB×2 + 폼 오버헤드(≥45MB)를 수용하는지 확인, 부족하면 vhost/풀 단위 조정.
- 중복 제출 race: `uq_active_member` unique 키가 최종 방어 — duplicate key 예외를 "이미 제출하셨습니다" 응답으로 매핑.
- 취소된 제출의 파일은 디스크에 보존 (운영 분쟁 대비).
- **동의 철회**: `consent_agreed_at`으로 동의 시각 보존. 제출 후 콘텐츠 활용 동의 철회 요청은 시스템 기능 없이 운영팀 수동 처리(필요 시 운영자가 제출 취소 + 사유 기록).

## 테스트

- 서비스 레벨: 제출 검증(URL/consent/형식/용량), 1회 제한, 취소→재제출, 권한(타인 음성 접근 차단), 마감/토글 차단.
- HTTP smoke: 회원 제출 happy path + 마감 후 + 기제출 + admin 목록/재생.

## 작업 순서 (배포 규칙)

1. boot-dev (dev 브랜치)에서만 코드 작업, DEV DB에 마이그레이션 먼저.
2. dev push 후 **멈추고 사용자 확인 요청**. 운영 반영은 명시 요청 시에만.
