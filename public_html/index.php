<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>소리튠 부트캠프</title>
    <meta name="theme-color" content="#2563EB">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    <link rel="stylesheet" href="/css/common.css?v=20260313b">
    <link rel="stylesheet" href="/css/calendar.css?v=20260313">
    <link rel="stylesheet" href="/css/study.css?v=20260313a">
    <link rel="stylesheet" href="/css/lecture.css?v=20260313">
    <link rel="stylesheet" href="/css/member.css?v=20260315">
    <link rel="stylesheet" href="/css/member-tabs.css?v=20260315">
    <link rel="stylesheet" href="/css/member-calendar.css?v=20260315">
    <link rel="stylesheet" href="/css/member-assignments.css?v=20260315">
    <link rel="stylesheet" href="/css/member-progress.css?v=20260315">
    <link rel="stylesheet" href="/css/member-shortcuts.css?v=20260316">
    <link rel="stylesheet" href="/css/member-bootees.css?v=20260315">
    <link rel="manifest" href="/manifest.json">
</head>
<body>
    <div id="member-root"></div>
    <script src="/js/toast.js?v=20260227"></script>
    <script src="/js/common.js?v=20260313c"></script>
    <script src="/js/calendar.js?v=20260312"></script>
    <!-- Member 모듈 (로드 순서 중요: utils → tabs → 각 탭 → 메인) -->
    <script src="/js/member-utils.js?v=20260315"></script>
    <script src="/js/member-tabs.js?v=20260315"></script>
    <script src="/js/member-home.js?v=20260316"></script>
    <script src="/js/member-shortcuts.js?v=20260316"></script>
    <script src="/js/member-calendar-detail.js?v=20260315"></script>
    <script src="/js/member-calendar.js?v=20260315"></script>
    <script src="/js/member-assignments.js?v=20260315"></script>
    <script src="/js/member-progress.js?v=20260315"></script>
    <script src="/js/member-bootees.js?v=20260315"></script>
    <script src="/js/member.js?v=20260315"></script>
    <script>
        MemberApp.init();
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        }
    </script>
</body>
</html>
