<?php

return [
    'title' => '机构销售额',
    'description' => '按业务发生日期汇总当前权限范围内的有效已完成订单。',
    'scope_note' => '仅统计当前用户业务范围内的数据',
    'period' => '统计月份：:month',
    'fields' => [
        'month' => '统计月份',
        'institution_search' => '机构筛选 / 搜索',
        'institution_search_placeholder' => '输入机构名称',
    ],
    'table' => [
        'title' => '机构月度销售额总览',
        'summary' => '共 :count 家机构',
        'number' => '序号',
        'institution' => '机构',
        'customers' => '客户数',
        'orders' => '有效订单数',
        'amount' => '销售额（KRW）',
        'total' => '合计',
        'total_customers' => '客户数',
        'total_orders' => '有效订单数',
        'total_amount' => '月销售额',
        'empty' => '当前月份和筛选条件下没有符合条件的订单。',
    ],
    'export' => [
        'button' => '导出 Excel',
        'sheet_title' => '机构销售额',
        'period' => '统计月份：:month',
        'filename' => '机构月度销售额_:month',
    ],
    'errors' => [
        'invalid_month' => '请输入有效的统计月份。',
        'generation_failed' => '机构销售额 Excel 生成失败，请稍后重试。',
        'directory_unwritable' => '导出目录不可写，请检查存储权限。',
        'file_missing' => '导出文件未生成，请检查存储权限。',
        'checksum_failed' => '导出文件校验失败。',
        'generic' => '导出失败，请稍后重试。',
    ],
    'fallbacks' => [
        'missing_institution' => '未知机构',
    ],
];
