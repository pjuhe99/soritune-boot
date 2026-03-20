<?php
/**
 * Asset versioning helper
 * 파일 수정 시간 기반 자동 캐시 버스팅
 * 사용법: <script src="/js/app.js<?= v('/js/app.js') ?>"></script>
 */
function v(string $path): string {
    $file = $_SERVER['DOCUMENT_ROOT'] . $path;
    $mtime = @filemtime($file);
    return $mtime ? '?v=' . $mtime : '';
}
