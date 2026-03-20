<?php require_once __DIR__ . '/../includes/asset_version.php'; ?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>조장 - 소리튠 부트캠프</title>
    <meta name="theme-color" content="#2563EB">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css">
    <link rel="stylesheet" href="/css/common.css<?= v('/css/common.css') ?>">
    <link rel="stylesheet" href="/css/admin.css<?= v('/css/admin.css') ?>">
    <link rel="stylesheet" href="/css/bootcamp.css<?= v('/css/bootcamp.css') ?>">
    <link rel="manifest" href="/manifest.json">
</head>
<body>
    <div id="admin-root" data-role="leader"></div>
    <script src="/js/toast.js<?= v('/js/toast.js') ?>"></script>
    <script src="/js/common.js<?= v('/js/common.js') ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked@15/marked.min.js"></script>
    <script src="/js/memberTable.js<?= v('/js/memberTable.js') ?>"></script>
    <script src="/js/bootcamp.js<?= v('/js/bootcamp.js') ?>"></script>
    <script src="/js/admin.js<?= v('/js/admin.js') ?>"></script>
    <script src="/js/coin.js<?= v('/js/coin.js') ?>"></script>
    <script>AdminApp.init();</script>
</body>
</html>
