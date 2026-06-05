# BRAVO UI 스타일링 패스 — 설계 스펙

날짜: 2026-06-05
상태: 설계 승인됨 (접근법 A + 영역별 방향 사용자 승인)
선행: slice1~8 (origin/dev `364f648`) — 기능 완성, UI 클래스 ~80개 bare 상태. 1차 MVP 운영 반영 전 마지막 dev 작업.

## 1. 목표와 범위

slice1~8 이 만든 BRAVO 전 화면(회원 탭·응시 플로우·관리자 서브탭 4종·채점 패널)에 기존 boot 디자인 시스템(`design-tokens.css`) 기반 CSS 를 부여하고, GD 인증서 렌더를 코드 레벨에서 다듬는다.

**포함:** ① 회원 BRAVO 탭(등급 카드·결과·인증서 버튼), ② 회원 응시 화면(OT·마이크테스트·문제·녹음·제출, `bx-*`), ③ 관리자 BRAVO 셸(자격/시험 관리/문제은행/OT 서브탭, `bravo-*`/`bq-*`/`bx-dates` 등), ④ 관리자 채점(`grading-*`), ⑤ 인증서 GD 렌더 폴리시(레이아웃·색·장식 — 코드만).

**제외:** 배경 이미지 제작(디자이너 PNG 나오면 기존 훅으로 교체), 기능·로직 변경, 기존 CSS 파일 수정, BRAVO 외 화면.

**순수 추가형:** 신규 CSS 2파일 + `<link>` 2줄 + JS 마크업의 클래스 추가 수준 최소 수정(로직 무변경) + `bravo_certificates.php` 렌더 함수 내부만.

## 2. 확정된 결정

| # | 결정 | 내용 |
|---|------|------|
| 1 | 범위 = 전부 한 번에 | 4영역 + 인증서 코드 폴리시. 운영 반영 전 일괄. |
| 2 | 접근법 A | 신규 `css/bravo.css`(회원) + `css/admin-bravo.css`(관리자). 토큰 변수만 사용, 기존 파일 무수정. |
| 3 | 인증서 = 코드 레벨 개선만 | GD 렌더 안에서 레이아웃·색·장식(이중 테두리 보강, 등급별 포인트 색, 자간). 배경 PNG 훅 유지. |
| 4 | JS 수정 최소 | 클래스 추가/래퍼 수준만. 이벤트·상태·API 로직 무변경. 회귀는 기존 테스트 19파일로 보증. |

## 3. 파일 구조

- **Create** `public_html/css/bravo.css` — 회원: `.member-bravo*`, `.bravo-level-*`, `.bravo-badge`, `.bravo-state`, `.bravo-result-*`, `.bravo-cert` + 응시 `.bravo-exam`, `.bx-*`
- **Create** `public_html/css/admin-bravo.css` — 관리자: `.bravo-subtabs/.bravo-subtab`, `.bravo-admin/.bravo-help/.bravo-table/.bravo-chip/.bravo-grant/.bravo-override/.bravo-notes`, 시험 `.bravo-exam-admin/.bx-dates/.bravo-eq-*`, 문제은행 `.bravo-q*/.bq-*`, OT `.bravo-ot-form/.ot-wide`, 채점 `.grading-*`
- **Modify** `public_html/index.php` — `<link ... /css/bravo.css?{v()}>` 추가 (member-tabs.css 다음)
- **Modify** `public_html/operation/index.php` — `<link ... /css/admin-bravo.css?{v()}>` 추가
- **Modify** `public_html/js/*.js` (필요 시) — 클래스 추가 수준 (예: 결과 카드 래퍼, 채점 패널 그리드 셀 명시)
- **Modify** `public_html/api/services/bravo_certificates.php` — `bravoCertificateRender` 내부 폴리시

## 4. 영역별 디자인

### 4-1. 회원 BRAVO 탭 (`bravo.css`)

- `.bravo-level-cards`: 그리드 — 모바일 1열, 768px+ 3열 (`gap: var(--space-4)`).
- `.bravo-level-card`: `components.css` `.card` 문법 차용(흰 배경, `--radius-lg`, `--shadow-sm`). `is-eligible` 이면 좌측 4px primary 보더 + 살짝 강조. 비자격은 채도 낮춤(`--color-gray-*`).
- `.bravo-badge.eligible` = success 칩(50 배경/600 텍스트), `.ineligible` = gray 칩. pill 라운드.
- `.bravo-state` 기본 = gray 안내문. `.bravo-result-pass` = success 톤 박스(🎉 강조, `--color-success-50` 배경). `.bravo-result-fail` = **차분한 gray 박스** (danger 빨강 과용 금지 — 학습 동기 보호).
- `.bravo-cert` 버튼 = `.btn-accent`(앰버 = 성취) 문법의 자체 스타일 (마크업이 `btn btn-primary bravo-cert` 이므로 `.bravo-cert` 가 primary 를 앰버로 override — JS 수정 없이 CSS 로).
- `.member-bravo-head/-title/-sub/-note`: 기존 member 탭 헤더 톤과 일치.

### 4-2. 회원 응시 화면 (`bravo.css`, 모바일 우선)

- `.bravo-exam`: 중앙 정렬 단일 칼럼, `max-width: 560px`, 카드형. 모바일 풀폭.
- `.bx-ot`: OT 안내 박스(gray-50 배경), `.bx-pre` 는 `white-space: pre-wrap`. `.bx-type-guide` 는 좌 보더 안내 블록.
- `.bx-mic`: 점선 보더 카드(관문 느낌), 통과 후 체크 라벨 강조.
- `.bx-warn`: warning-50 배경 + warning 좌 보더 안내문.
- `.bx-question`: 문제 카드 — `.bx-korean` 은 큰 글씨(모바일 22px+, PC 28px) 중앙, `.bx-chunks` 는 보조 gray.
- `.bx-rec`: 녹음 상태 줄 — `.bx-rec-dot` 에 red pulse 애니메이션(`@keyframes` opacity), 경과초 강조.
- `.bx-progress`: 상단 진행 표시(칩 또는 얇은 텍스트 + 폭 비율 바는 텍스트만으로 충분 — YAGNI, 텍스트 칩만).
- `.bx-actions`: 버튼 행 — 모바일에서 풀폭 세로 스택, PC 가로.
- 큰 터치 타겟: 응시 흐름의 `.btn` 은 `.bravo-exam` 스코프에서 min-height 48px.

### 4-3. 관리자 BRAVO 셸 (`admin-bravo.css`)

- `.bravo-subtabs/.bravo-subtab`: 기존 `.multipass-subtab` 선례(pill, active=primary 채움) 와 동일 문법으로 자체 정의 (공통 승격 안 함 — YAGNI).
- 자격: `.bravo-table` 은 `.data-table` 위 최소 보정(입력 폭, `.num` 우측 정렬). `.bravo-chip` = 등급 칩(lv1/lv2/lv3 색 단계 — primary 계열 농도 차), `.none` = gray. `.bravo-grant` 라벨 간격. `.bravo-help` = 안내문 박스(info-50).
- 시험 관리: `.bravo-exam-toolbar` 필터 행 정렬, `.bravo-exam-fields` 폼 그리드(2열, 모바일 1열), `.bx-dates` 날짜 3필드 행, status 별 칩 색(preparing=gray/open=success/closed=warning/released=primary).
- 문제은행: `.bravo-q-filters` 행, `.bravo-q-form` 그리드, `.bq-wide` 풀폭 textarea, `.bravo-q-table` 보정.
- OT 폼: `.bravo-ot-form` 그리드, `.ot-wide` 풀폭.
- `.bravo-eq-panel/.bravo-eq-row`: 배정 패널 — 체크박스 행 hover, 요약(`.eq-summary`) 강조 박스.

### 4-4. 관리자 채점 (`admin-bravo.css`)

- `.grading-toolbar`: 시험 선택 행. `.grading-table` = `.data-table` 보정.
- `.grading-panel`: 상세 패널 — 문항 카드 `.grading-q` 세로 나열.
- **레이아웃 시프트 0 원칙**: 판정 인라인 갱신(slice7 — 오디오 재생 보존) 위에 얹으므로 `.grading-q` 높이를 판정 상태와 무관하게 유지 (오디오 행 고정 높이, 판정 버튼 영역 고정).
- `.grading-judges/.grading-judge`: 판정 토글 버튼 그룹 — 미선택=outline, 선택=primary 채움 (`.on` 또는 `[aria-pressed]` 등 기존 JS 가 쓰는 셀렉터를 구현 시 확인해 그대로 매칭).
- `.grading-score`: 실시간 총점 강조(앰버). `.grading-confirm`: 확정 영역 — 확정=success 버튼, 취소=ghost. `.grading-chip`: 카운트 칩 4종 색.
- `.grading-answer-key`: 정답·허용답 박스(관리자 전용 정보 — info-50 배경). `.grading-audio-missing` = danger 텍스트.

### 4-5. 인증서 GD 렌더 폴리시 (`bravo_certificates.php` 내부만)

- 이중 골드 테두리 굵기·간격 보강 + 모서리 장식(직선 조합 수준).
- 등급별 포인트 색: B1/B2/B3 타이틀 색 차등(토큰 팔레트의 HEX 직접 사용 — CSS 변수 불가 환경).
- 제목/이름 자간(레터 스페이싱은 GD 미지원 → 문자 사이 공백 또는 위치 계산), 이름 아래 구분선, "소리튠영어" 위 서명선 느낌의 가는 선.
- **계약 유지**: `bravoCertificateRender(array, bool $forcePng=false): array{bytes,mime,ext}` 시그니처·PNG/PDF 시그니처 테스트(`bravo_certificates_test.php`) 그대로 통과해야 함. 캔버스 1754×1240 유지.

## 5. 원칙·제약

- 색·간격·라운드·그림자 전부 `var(--...)` 토큰만 (인증서 GD 제외 — HEX 직접, 토큰 값과 일치시킬 것).
- 기존 CSS 파일(`admin.css`/`member.css`/`components.css` 등) 무수정.
- `@media` 보다 base 룰 먼저 (cascade 순서 — feedback_css_base_media_order).
- 유니코드 글리프 의존 금지 신규 도입 없음 (기존 ●/🎉/✅ 는 텍스트 콘텐츠라 유지 — 레이아웃 의존 아님).
- JS 수정은 클래스 추가/래퍼 수준만. 수정 시 해당 페이지 `v()` 캐시버스터 자동 (mtime).

## 6. 테스트·검증

- CSS 는 자동 테스트 불가 — **회귀 가드**: 기존 BRAVO 테스트 19파일 전부 pass (특히 JS 마크업 수정 시 `bravo_certificates_test.php` 렌더 단언, 인증서 폴리시 후 PNG/PDF 시그니처).
- `node --check` (수정한 JS), `php -l` (수정한 PHP).
- 인증서 시각 확인: 렌더 PNG 를 파일로 떨궈 컨트롤러가 Read 로 확인(이미지 뷰) 후 사용자 검증으로.
- 사용자 브라우저 검증(게이트): dev-boot.soritune.com — 회원 탭/응시 풀플로우(모바일 폭 시뮬 포함)/관리자 4서브탭/채점/인증서 PDF. slice6~8 기능 검증과 병행 가능.

## 7. 배포

dev 에만 반영. 운영(main)은 BRAVO 전체 완성 시 1회 일괄 — 사용자 명시 요청 시에만 (기존 게이트 유지).
