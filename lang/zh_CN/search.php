<?php

return [
    'title' => '全部搜索结果',
    'back' => '返回总览',
    'description' => [
        'empty' => '输入关键词后，可同时搜索客户、订单项目和有权限查看的代理商。',
        'results' => '关键词“:query”的匹配结果',
    ],
    'summary' => '共找到 :total 条匹配结果，按业务类型分组展示。',
    'aria' => [
        'customers_table' => '客户搜索结果',
        'orders_table' => '订单项目搜索结果',
        'agents_table' => '代理商搜索结果',
    ],
    'groups' => [
        'customers' => [
            'title' => '客户',
            'view_all' => '查看全部客户',
            'view_profile' => '查看档案',
            'columns' => [
                'name' => '客户',
                'status' => '当前状态',
                'actions' => '操作',
            ],
            'empty' => '没有匹配的客户。',
        ],
        'orders' => [
            'title' => '订单项目',
            'view_all' => '查看全部订单项目',
            'columns' => [
                'project' => '施术项目',
                'customer' => '客户',
                'agent' => '代理商',
                'amount' => '成交金额',
                'completed_at' => '成交时间',
            ],
            'empty' => '没有匹配的订单项目。',
        ],
        'agents' => [
            'title' => '代理商',
            'view_all' => '查看全部代理商',
            'view_profile' => '查看档案',
            'columns' => [
                'name' => '代理商',
                'status' => '合作状态',
                'actions' => '操作',
            ],
            'empty' => '没有匹配的代理商。',
        ],
    ],
    'empty' => [
        'prompt' => '请在顶部输入搜索关键词',
        'all' => '没有找到与“:query”匹配的结果。',
    ],
    'statuses' => [
        'active' => '合作中',
        'paused' => '暂停',
        'terminated' => '已终止',
    ],
];
