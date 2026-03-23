<?php
/**
 * Asset versioning helper
 * 파일 수정 시간 + 수동 버전 기반 캐시 버스팅
 * 사용법: <script src="/js/app.js<?= v('/js/app.js') ?>"></script>
 * 강제 캐시 버스팅이 필요하면 ASSET_BUST 값을 변경
 */
define('ASSET_BUST', 1);

function v(string $path): string {
    $file = $_SERVER['DOCUMENT_ROOT'] . $path;
    $mtime = @filemtime($file);
    return $mtime ? '?v=' . $mtime . '.' . ASSET_BUST : '';
}
