<?php
/**
 * 다회권 CSV/엑셀 paste 파서.
 *
 * 입력: 텍스트 (CSV)
 * 출력: ['rows' => [{row, user_id, product_name, cohort_labels[], cohort_raw[]}], 'errors' => [{row, reason}]]
 *   - cohort_labels: 각 토큰에서 (\d+) 추출. 매칭 실패 시 원본 그대로.
 *   - cohort_raw: 분리만 한 원본 토큰 (검증 메시지에서 사용).
 */
declare(strict_types=1);

const MULTIPASS_HEADER_KEYWORDS = ['user_id', '아이디', 'product_name', '상품명', 'cohorts', '기수'];

/**
 * @return array{rows: array<int, array{row:int, user_id:string, product_name:string, cohort_labels:array<int,string>, cohort_raw:array<int,string>}>, errors: array<int, array{row:int, reason:string}>}
 */
function parseMultipassCsv(string $csv, int $maxRows = 200): array {
    // BOM 제거
    if (str_starts_with($csv, "\xEF\xBB\xBF")) {
        $csv = substr($csv, 3);
    }

    $rows = [];
    $errors = [];
    $rowNum = 0;
    $dataRowNo = 0;
    $headerSeen = false;

    // RFC 4180 파싱 — fgetcsv 가 빠르고 표준
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);

    while (($cells = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $rowNum++;
        // 빈 행 (모든 셀 빈 문자열)
        if (count($cells) === 1 && trim((string)$cells[0]) === '') continue;
        // null 안전
        $cells = array_map(fn($c) => $c === null ? '' : trim((string)$c), $cells);

        // 컬럼 수 검증 (최소 3)
        if (count($cells) < 3) {
            $errors[] = ['row' => $rowNum, 'reason' => 'missing_columns'];
            continue;
        }

        // 첫 행이 헤더 키워드 포함하면 skip
        if (!$headerSeen) {
            $joined = mb_strtolower(implode(',', array_slice($cells, 0, 3)));
            foreach (MULTIPASS_HEADER_KEYWORDS as $kw) {
                if (str_contains($joined, mb_strtolower($kw))) {
                    $headerSeen = true;
                    continue 2;  // continue while loop
                }
            }
            $headerSeen = true;  // 첫 행 처리 완료, 다음 행부터는 무조건 데이터
        }

        $dataRowNo++;
        if ($dataRowNo > $maxRows) {
            $errors[] = ['row' => $rowNum, 'reason' => 'batch_too_large'];
            break;
        }

        [$userId, $productName, $cohortsStr] = [$cells[0], $cells[1], $cells[2]];

        // cohort 분리 (쉼표/파이프/슬래시), 빈 토큰 제거
        $tokens = preg_split('#[,|/]#', $cohortsStr) ?: [];
        $cohortRaw = [];
        $cohortLabels = [];
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;
            $cohortRaw[] = $tok;
            // (\d+) 추출
            if (preg_match('/(\d+)/', $tok, $m)) {
                $cohortLabels[] = $m[1];
            } else {
                $cohortLabels[] = $tok;  // 식별 실패 — 원본 보존, 검증이 잡음
            }
        }

        $rows[] = [
            'row'           => $rowNum,
            'user_id'       => $userId,
            'product_name'  => $productName,
            'cohort_labels' => $cohortLabels,
            'cohort_raw'    => $cohortRaw,
        ];
    }
    fclose($fh);

    return ['rows' => $rows, 'errors' => $errors];
}
