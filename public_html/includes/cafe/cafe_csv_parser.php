<?php
declare(strict_types=1);

/**
 * paste CSV (조,이름,닉,링크) → row array + errors.
 *
 * 헤더 행(`조,이름,닉,링크`)은 첫 행에서만 skip.
 * RFC 4180 큰따옴표 처리 (fgetcsv).
 *
 * @return array{rows: array<int, array{group:string, name:string, nick:string, url:string}>, errors: array<int, array{row:int, reason:string}>}
 */

function parseCafeCsv(string $csv, int $maxRows = 100): array {
    if (strlen($csv) >= 3 && substr($csv, 0, 3) === "\xEF\xBB\xBF") {
        $csv = substr($csv, 3);
    }

    $rows = [];
    $errors = [];

    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);

    $isFirstLine = true;
    $dataRowNo = 0;
    while (($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if ($cols === null) continue;
        if (count($cols) === 1 && ($cols[0] === null || trim((string)$cols[0]) === '')) {
            $isFirstLine = false;
            continue;
        }

        if ($isFirstLine) {
            $isFirstLine = false;
            if (count($cols) >= 4
                && trim((string)$cols[0]) === '조'
                && trim((string)$cols[1]) === '이름'
                && trim((string)$cols[2]) === '닉'
                && trim((string)$cols[3]) === '링크'
            ) {
                continue;
            }
        }

        $dataRowNo++;
        if ($dataRowNo > $maxRows) {
            $errors[] = ['row' => $dataRowNo, 'reason' => 'batch_too_large'];
            break;
        }

        if (count($cols) < 4) {
            $errors[] = ['row' => $dataRowNo, 'reason' => 'missing_columns'];
            continue;
        }

        $rows[] = [
            'group' => trim((string)$cols[0]),
            'name'  => trim((string)$cols[1]),
            'nick'  => trim((string)$cols[2]),
            'url'   => trim((string)$cols[3]),
        ];
    }
    fclose($fh);

    return ['rows' => $rows, 'errors' => $errors];
}
