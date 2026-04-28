<?php
/**
 * boot.soritune.com - Notify 데이터 어댑터: Google Sheets
 * 기존 cron/GoogleSheets.php(readonly) 재사용.
 */

require_once dirname(__DIR__, 3) . '/cron/GoogleSheets.php';

/**
 * @param array $cfg 시나리오의 source 블록:
 *   ['type'=>'google_sheet', 'sheet_id', 'tab', 'range', 'check_col', 'phone_col', 'name_col', 'check_value'?]
 *   check_value 미지정 시 기본 'N' (대소문자 무시).
 * @return array 발송 후보 행 리스트:
 *   [['row_key'=>..., 'phone'=>..., 'name'=>..., 'columns'=>[헤더=>값,...]], ...]
 *   check_col 값이 check_value 와 일치하는 행만 반환.
 */
function notifySourceGoogleSheet(array $cfg): array {
    $required = ['sheet_id', 'tab', 'range', 'check_col', 'phone_col', 'name_col'];
    foreach ($required as $r) {
        if (empty($cfg[$r])) {
            throw new RuntimeException("source.{$r} 누락");
        }
    }
    $checkValue = isset($cfg['check_value']) && $cfg['check_value'] !== ''
        ? (string)$cfg['check_value']
        : 'N';

    $sheet = new GoogleSheets();
    $rangeFull = $cfg['tab'] . '!' . $cfg['range'];
    $values = $sheet->getValues($cfg['sheet_id'], $rangeFull);

    if (!is_array($values) || count($values) < 2) return [];

    $headers = array_map('strval', $values[0]);
    $headerIdx = array_flip($headers);

    foreach ([$cfg['check_col'], $cfg['phone_col'], $cfg['name_col']] as $h) {
        if (!isset($headerIdx[$h])) {
            throw new RuntimeException("시트에 '{$h}' 헤더가 없습니다");
        }
    }

    $checkIdx = $headerIdx[$cfg['check_col']];

    $results = [];
    $rowCount = count($values);
    for ($i = 1; $i < $rowCount; $i++) {
        $row = $values[$i];
        $checkVal = isset($row[$checkIdx]) ? trim((string)$row[$checkIdx]) : '';
        if (strcasecmp($checkVal, $checkValue) !== 0) continue;

        $columns = [];
        foreach ($headers as $j => $h) {
            $columns[$h] = isset($row[$j]) ? (string)$row[$j] : '';
        }

        $results[] = [
            'row_key' => sprintf('sheet:%s:%s:%d', $cfg['sheet_id'], $cfg['tab'], $i + 1),
            'phone'   => $columns[$cfg['phone_col']] ?? '',
            'name'    => $columns[$cfg['name_col']] ?? '',
            'columns' => $columns,
        ];
    }
    return $results;
}
