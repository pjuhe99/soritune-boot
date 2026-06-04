# 조장/회원 통합 세션 및 페이지 전환 버튼

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 리더가 `/leader`에서 로그인하면 회원 세션도 자동 생성하고, 양쪽 페이지에 상호 이동 버튼을 표시한다.

**Architecture:** `login_phone` 액션에서 admin 세션 생성 후 member 세션도 함께 생성. `member.php` check_session/login 응답에 `member_role`을 추가하여 프론트엔드에서 조장 여부를 판단. admin.js 대시보드에 "내 회원페이지" 버튼, member.js 대시보드에 "조장 페이지" 버튼 추가.

**Tech Stack:** PHP (auth.php, admin.php, member.php), Vanilla JS (admin.js, member.js), CSS (common.css)

---

### Task 1: admin.php `login_phone`에서 회원 세션 동시 생성

**Files:**
- Modify: `/root/boot-dev/public_html/api/admin.php:65-112` (login_phone case)

- [ ] **Step 1: `login_phone` 성공 시 `loginMember()` 호출 추가**

`admin.php`의 `login_phone` case에서, `loginAdmin()` 호출 직후 `loginMember()`도 호출한다. 리더/서브리더는 `bootcamp_members` 테이블 기반이므로 이미 필요한 데이터를 모두 갖고 있다.

```php
// admin.php login_phone case, line 99 뒤에 추가:
// loginAdmin(...) 호출 직후

// 회원 세션도 동시 생성 (리더가 회원페이지에서 별도 로그인 불필요)
loginMember($member['id'], $member['real_name'], $member['cohort'], $member['nickname']);
```

기존 `loginAdmin(...)` 호출(line 99) 바로 아래에 한 줄 추가.

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/admin.php && git commit -m "feat: login_phone에서 회원 세션도 동시 생성"
```

---

### Task 2: member.php 응답에 `member_role` 추가

**Files:**
- Modify: `/root/boot-dev/public_html/api/member.php` (login case + check_session case)

현재 member.php의 login/check_session 응답에는 `member_role`이 포함되어 있지 않다. 회원페이지에서 "조장 페이지로" 버튼을 표시하려면 이 정보가 필요하다.

- [ ] **Step 1: `login` case에서 `member_role` 포함**

`login` case의 `findMemberByPhone()` 결과에는 이미 `bootcamp_members.*`가 포함되어 있으므로 `member_role` 컬럼 값이 있다. 응답 배열에 추가만 하면 된다.

`member.php` line 54-68의 jsonSuccess 응답에서 `'member'` 배열 안에 추가:

```php
// 기존 'needs_nickname' 줄 다음에 추가:
'member_role' => $member['member_role'] ?? 'member',
```

- [ ] **Step 2: `check_session` case에서 `member_role` 포함**

`check_session` case의 SELECT 쿼리(line 76-89)에 `bm.member_role`이 이미 포함되어 있다 (`bm.id, bm.real_name, bm.nickname, bm.phone, bm.user_id` — 모든 컬럼을 SELECT하고 있으므로). 응답 배열에 추가:

```php
// check_session 응답의 'member' 배열 안, 'needs_nickname' 줄 다음에 추가:
'member_role' => $member['member_role'] ?? 'member',
```

- [ ] **Step 3: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/member.php && git commit -m "feat: member API 응답에 member_role 추가"
```

---

### Task 3: admin.js 조장 대시보드에 "내 회원페이지" 버튼 추가

**Files:**
- Modify: `/root/boot-dev/public_html/js/admin.js:143-160` (showDashboard 함수)

- [ ] **Step 1: 조장 대시보드 헤더에 회원페이지 이동 버튼 추가**

`showDashboard()` 함수에서 `admin-header-right` div 안, 로그아웃 버튼 앞에 버튼 추가. 조장/부조장 역할(`role === 'leader'` 페이지)일 때만 표시.

```javascript
// admin.js showDashboard() 내 admin-header-right div:
// 기존:
//   <span class="admin-name">${App.esc(admin.admin_name)}</span>
//   ${role !== 'leader' ? '<button ...' : ''}
//   <button class="btn-logout" id="btn-logout">로그아웃</button>
// 변경:
//   <span class="admin-name">${App.esc(admin.admin_name)}</span>
//   ${role === 'leader' ? '<a href="/" class="btn-member-page" id="btn-goto-member">내 회원페이지</a>' : ''}
//   ${role !== 'leader' ? '<button class="btn-change-pw" id="btn-change-pw">비밀번호 변경</button>' : ''}
//   <button class="btn-logout" id="btn-logout">로그아웃</button>
```

`<a href="/">` 태그를 사용하여 회원페이지(`/`)로 직접 이동. 이미 member 세션이 생성되어 있으므로 별도 로그인 불필요.

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/admin.js && git commit -m "feat: 조장 대시보드에 내 회원페이지 이동 버튼"
```

---

### Task 4: member.js 회원 대시보드에 "조장 페이지" 버튼 추가

**Files:**
- Modify: `/root/boot-dev/public_html/js/member.js:155-185` (showDashboard 함수)

- [ ] **Step 1: 조장/부조장인 경우 조장 페이지 이동 버튼 표시**

`showDashboard()` 함수에서, `member.member_role`이 `'leader'` 또는 `'subleader'`인 경우 로그아웃 버튼 옆에 조장 페이지 이동 버튼을 표시한다.

```javascript
// member.js showDashboard() 내 member-logout-wrap div를:
// 기존:
//   <div class="member-logout-wrap">
//       <button class="btn btn-secondary" id="btn-member-logout">로그아웃</button>
//   </div>
// 변경:
//   <div class="member-logout-wrap">
//       ${member.member_role === 'leader' || member.member_role === 'subleader'
//           ? '<a href="/leader" class="btn btn-primary" id="btn-goto-leader">조장 페이지로</a>'
//           : ''}
//       <button class="btn btn-secondary" id="btn-member-logout">로그아웃</button>
//   </div>
```

`<a href="/leader">` 태그를 사용. 이미 admin 세션이 있으면 바로 대시보드가 뜨고, 없으면 리더 로그인 화면이 나타난다 (Task 5에서 자동 세션 생성 처리).

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/js/member.js && git commit -m "feat: 조장/부조장 회원에게 조장 페이지 이동 버튼"
```

---

### Task 5: member.php `login`에서 조장/부조장일 때 admin 세션도 동시 생성

**Files:**
- Modify: `/root/boot-dev/public_html/api/member.php:16-69` (login case)

Task 1의 역방향. 회원페이지에서 먼저 로그인한 리더가 "조장 페이지로" 버튼을 눌렀을 때 별도 로그인 없이 바로 접근 가능하도록 한다.

- [ ] **Step 1: login 성공 후, member_role이 leader/subleader이면 admin 세션도 생성**

`member.php` login case에서 `loginMember(...)` 호출(line 30) 직후, member_role을 확인하여 admin 세션을 함께 생성한다.

```php
// member.php login case, loginMember() 호출 직후:
loginMember($member['id'], $member['real_name'], $member['cohort'], $member['nickname']);

// 조장/부조장이면 admin 세션도 동시 생성
if (in_array($member['member_role'] ?? '', ['leader', 'subleader'])) {
    $displayName = $member['nickname'] ?: $member['real_name'];
    $bcGroupId = $member['group_id'] ? (int)$member['group_id'] : null;
    loginAdmin($member['id'], $displayName, [$member['member_role']], $member['cohort'], $bcGroupId);
}
```

`findMemberByPhone()` 결과에는 `bootcamp_members.*`가 포함되어 있으므로 `member_role`, `group_id` 등이 이미 존재한다.

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/api/member.php && git commit -m "feat: 회원 로그인 시 조장/부조장이면 admin 세션도 동시 생성"
```

---

### Task 6: 버튼 스타일링

**Files:**
- Modify: `/root/boot-dev/public_html/css/admin.css` (조장 대시보드 헤더 내 버튼)

- [ ] **Step 1: `.btn-member-page` 스타일 추가**

admin.css에 조장 대시보드의 "내 회원페이지" 버튼 스타일을 추가한다. 기존 `.btn-logout` 스타일과 유사하되, 구분되는 색상을 사용한다.

```css
.btn-member-page {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 600;
    color: #2563eb;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
    text-decoration: none;
    white-space: nowrap;
    transition: background 0.15s;
}
.btn-member-page:hover {
    background: #dbeafe;
}
```

- [ ] **Step 2: 커밋**

```bash
cd /root/boot-dev && git add public_html/css/admin.css && git commit -m "style: 내 회원페이지 이동 버튼 스타일"
```

---

### Task 7: 통합 테스트

- [ ] **Step 1: 리더 → 회원 경로 테스트**

1. `/leader` 접속 → 리더 전화번호로 로그인
2. 대시보드에 "내 회원페이지" 버튼 확인
3. 버튼 클릭 → `/` (회원페이지)로 이동
4. 별도 로그인 없이 바로 대시보드 표시 확인

- [ ] **Step 2: 회원 → 리더 경로 테스트**

1. `/` 접속 → 리더 전화번호로 로그인
2. 대시보드 하단에 "조장 페이지로" 버튼 확인
3. 버튼 클릭 → `/leader`로 이동
4. 별도 로그인 없이 바로 조장 대시보드 표시 확인

- [ ] **Step 3: 일반 회원 테스트**

1. `/` 접속 → 일반 회원 전화번호로 로그인
2. "조장 페이지로" 버튼이 표시되지 않음 확인

- [ ] **Step 4: dev push**

```bash
cd /root/boot-dev && git push origin dev
```
