<?php

return [
    'agents' => [
        'normal_grade_current_locked' => '普通配置不能覆盖当前月份的等级记录。',
        'normal_grade_future_invalid' => '普通配置的等级只能安排到下个月生效。',
        'historical_grade_month_invalid' => '历史等级补录只能写入当前月份之前的月份。',
        'historical_grade_before_cooperation' => '历史等级生效月份不能早于代理商合作开始月份。',
        'grade_correction_requires_missing_current' => '该代理商已有当前有效等级，请使用正常调级流程。',
        'initial_grade_month_invalid' => '新代理商的初始等级必须从当前月份生效。',
    ],
    'imports' => [
        'historical_reason_required' => '历史配置补录必须填写更正原因。',
    ],
    'settlements' => [
        'historical_reason_required' => '历史费率补录必须填写更正原因。',
    ],
];
