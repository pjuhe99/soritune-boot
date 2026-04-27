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
        'sheet_id'   => '1dPL3914LVhOfmKsJFUcZ43T5e3-4rGb6n3vys1zH7UM',
        'tab'        => '알림톡 테스트',
        'range'      => 'A1:G500',
        'check_col'  => 'OT 제출 여부',
        'phone_col'  => '연락처',
        'name_col'   => '이름',
    ],

    'template' => [
        'templateId'   => 'KA01TP260422072245496GEsFZhpWlbA',
        'fallback_lms' => false,
        'variables' => [
            '#{이름}'         => 'col:이름',
            '#{입학원서링크}' => 'const:https://forms.gle/3Uc7QthRuVYGUV3S6',
            '#{마감날짜}'     => 'const:5월 3일(일)까지',
            '#{일정}'         => 'const:5월 11일 ~ 6월 12일 (5주간)',
        ],
    ],

    'schedule'       => '0 21 * * *',
    'cooldown_hours' => 24,
    'max_attempts'   => 3,
];
