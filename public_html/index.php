<?php require_once __DIR__ . '/includes/asset_version.php'; ?>
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
    <link rel="stylesheet" href="/css/common.css<?= v('/css/common.css') ?>">
    <link rel="stylesheet" href="/css/calendar.css<?= v('/css/calendar.css') ?>">
    <link rel="stylesheet" href="/css/study.css<?= v('/css/study.css') ?>">
    <link rel="stylesheet" href="/css/lecture.css<?= v('/css/lecture.css') ?>">
    <link rel="stylesheet" href="/css/member.css<?= v('/css/member.css') ?>">
    <link rel="stylesheet" href="/css/member-tabs.css<?= v('/css/member-tabs.css') ?>">
    <link rel="stylesheet" href="/css/member-calendar.css<?= v('/css/member-calendar.css') ?>">
    <link rel="stylesheet" href="/css/member-assignments.css<?= v('/css/member-assignments.css') ?>">
    <link rel="stylesheet" href="/css/member-issue.css<?= v('/css/member-issue.css') ?>">
    <link rel="stylesheet" href="/css/member-progress.css<?= v('/css/member-progress.css') ?>">
    <link rel="stylesheet" href="/css/member-shortcuts.css<?= v('/css/member-shortcuts.css') ?>">
    <link rel="stylesheet" href="/css/member-bootees.css<?= v('/css/member-bootees.css') ?>">
    <link rel="manifest" href="/manifest.json">
</head>
<body>
    <div id="member-root"></div>
    <script src="/js/toast.js<?= v('/js/toast.js') ?>"></script>
    <script src="/js/common.js<?= v('/js/common.js') ?>"></script>
    <script src="/js/calendar.js<?= v('/js/calendar.js') ?>"></script>
    <!-- Member 모듈 (로드 순서 중요: utils → tabs → 각 탭 → 메인) -->
    <script src="/js/member-utils.js<?= v('/js/member-utils.js') ?>"></script>
    <script src="/js/member-tabs.js<?= v('/js/member-tabs.js') ?>"></script>
    <script src="/js/member-home.js<?= v('/js/member-home.js') ?>"></script>
    <script src="/js/member-shortcuts.js<?= v('/js/member-shortcuts.js') ?>"></script>
    <script src="/js/member-calendar-detail.js<?= v('/js/member-calendar-detail.js') ?>"></script>
    <script src="/js/member-calendar.js<?= v('/js/member-calendar.js') ?>"></script>
    <script src="/js/member-issue.js<?= v('/js/member-issue.js') ?>"></script>
    <script src="/js/member-assignments.js<?= v('/js/member-assignments.js') ?>"></script>
    <script src="/js/member-progress.js<?= v('/js/member-progress.js') ?>"></script>
    <script src="/js/member-bootees.js<?= v('/js/member-bootees.js') ?>"></script>
    <script src="/js/member.js<?= v('/js/member.js') ?>"></script>
    <script>
        MemberApp.init();
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        }
    </script>
</body>
</html>
