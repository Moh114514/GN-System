<?php

return [
    'title' => '线上团队概览',
    'description' => '按订单消费日期统计线上直客团队的成交业绩。',
    'date_basis' => '统计口径：消费日期 :from 至 :to；仅含有效已完成的直客订单。',
    'month' => '统计月份',
    'owner' => '负责人',
    'all_owners' => '全部负责人',
    'performance' => '负责人业绩',
    'actions' => '操作',
    'view_orders' => '查看订单',
    'empty' => '所选月份暂无直客成交订单。',
    'back' => '返回直客管理',
    'metrics' => [
        'heading' => '线上业绩指标',
        'amount' => '直客成交额',
        'orders' => '成交订单数',
        'customers' => '成交客户数',
    ],
    'errors' => [
        'invalid_month' => '请选择有效的统计月份。',
    ],
];
