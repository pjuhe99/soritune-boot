<?php
/**
 * 시나리오: OT 출석 폼 미제출자 리마인드
 *
 * 운영 적용 전 다음을 실값으로 교체해야 함:
 *   - source.sheet_id, source.tab, source.range, *_col 헤더명
 *   - template.templateId
 *   - schedule (cron 식)
 *
 * 활성화: 운영 화면에서 is_active 토글 (기본 OFF).
 */
return [
    'key'         => 'form_reminder_ot',
    'name'        => 'OT 출석 폼 미제출자 리마인드',
    'description' => '구글시트 OT_제출 컬럼이 N인 회원에게 폼 작성 안내',

    'source' => [
        'type'       => 'google_sheet',
        'sheet_id'   => 'REPLACE_ME_SHEET_ID',
        'tab'        => 'REPLACE_ME_TAB',
        'range'      => 'A1:G500',
        'check_col'  => 'OT_제출',
        'phone_col'  => '연락처',
        'name_col'   => '이름',
    ],

    'template' => [
        'templateId'   => 'REPLACE_ME_TEMPLATE_ID',
        'fallback_lms' => false,
        'variables' => [
            '#{name}'     => 'col:이름',
            '#{deadline}' => 'const:4월 30일',
        ],
    ],

    'schedule'       => '0 21 * * *',
    'cooldown_hours' => 24,
    'max_attempts'   => 3,
];
