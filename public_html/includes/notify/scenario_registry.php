<?php
/**
 * boot.soritune.com - Notify 시나리오 등록부
 * scenarios/*.php 자동 로드 + notify_scenario_state UPSERT.
 */

/**
 * 모든 시나리오 정의를 [key => definition] 맵으로 반환.
 * 각 파일은 `return [...];` 형태여야 하며, 'key' 필드가 파일 식별자와 일치할 필요는 없으나
 * 'key'가 중복되면 RuntimeException.
 */
function notifyLoadScenarios(): array {
    $dir = __DIR__ . '/scenarios';
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/*.php') ?: [];
    sort($files);

    $map = [];
    foreach ($files as $file) {
        $def = require $file;
        if (!is_array($def) || empty($def['key'])) {
            throw new RuntimeException("시나리오 파일 형식 오류: {$file} (배열 + 'key' 필드 필수)");
        }
        $key = (string)$def['key'];
        if (isset($map[$key])) {
            throw new RuntimeException("시나리오 key 중복: '{$key}'");
        }
        notifyValidateScenario($def);
        $map[$key] = $def;
    }
    return $map;
}

/** 시나리오 정의 필수 필드 검증. 누락/오류 시 throw. */
function notifyValidateScenario(array $def): void {
    $keyLabel = $def['key'] ?? '(unknown)';
    foreach (['key', 'name', 'source', 'template', 'schedule', 'cooldown_hours', 'max_attempts'] as $f) {
        if (!array_key_exists($f, $def)) {
            throw new RuntimeException("시나리오 '{$keyLabel}': '{$f}' 필드 누락");
        }
    }
    foreach (['type'] as $f) {
        if (empty($def['source'][$f])) {
            throw new RuntimeException("시나리오 '{$keyLabel}': source.{$f} 필수");
        }
    }
    foreach (['templateId', 'variables'] as $f) {
        if (!array_key_exists($f, $def['template'])) {
            throw new RuntimeException("시나리오 '{$keyLabel}': template.{$f} 필수");
        }
    }
}

/**
 * 모든 시나리오 키에 대해 notify_scenario_state row를 UPSERT.
 * 신규 시나리오는 is_active=0으로 생성, 기존 row는 그대로.
 */
function notifyEnsureScenarioStates(PDO $db, array $scenarios): void {
    if (empty($scenarios)) return;
    $stmt = $db->prepare("
        INSERT INTO notify_scenario_state (scenario_key, is_active)
        VALUES (?, 0)
        ON DUPLICATE KEY UPDATE scenario_key = scenario_key
    ");
    foreach ($scenarios as $key => $_) {
        $stmt->execute([$key]);
    }
}
