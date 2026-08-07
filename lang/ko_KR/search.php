<?php

return [
    'title' => '전체 검색 결과',
    'back' => '개요로 돌아가기',
    'description' => [
        'empty' => '키워드를 입력하면 고객, 주문 프로젝트 및 권한이 있는 에이전시를 함께 검색할 수 있습니다.',
        'results' => '키워드 “:query”의 검색 결과',
    ],
    'summary' => '총 :total개의 검색 결과를 업무 유형별로 표시합니다.',
    'aria' => [
        'customers_table' => '고객 검색 결과',
        'orders_table' => '주문 프로젝트 검색 결과',
        'agents_table' => '에이전시 검색 결과',
    ],
    'groups' => [
        'customers' => [
            'title' => '고객',
            'view_all' => '고객 전체 보기',
            'view_profile' => '프로필 보기',
            'columns' => [
                'name' => '고객',
                'status' => '현재 상태',
                'actions' => '작업',
            ],
            'empty' => '일치하는 고객이 없습니다.',
        ],
        'orders' => [
            'title' => '주문 프로젝트',
            'view_all' => '주문 프로젝트 전체 보기',
            'columns' => [
                'project' => '시술 프로젝트',
                'customer' => '고객',
                'agent' => '에이전시',
                'amount' => '거래 금액',
                'completed_at' => '거래 시간',
            ],
            'empty' => '일치하는 주문 프로젝트가 없습니다.',
        ],
        'agents' => [
            'title' => '에이전시',
            'view_all' => '에이전시 전체 보기',
            'view_profile' => '프로필 보기',
            'columns' => [
                'name' => '에이전시',
                'status' => '협력 상태',
                'actions' => '작업',
            ],
            'empty' => '일치하는 에이전시가 없습니다.',
        ],
    ],
    'empty' => [
        'prompt' => '상단에 검색 키워드를 입력하세요',
        'all' => '“:query”와 일치하는 결과가 없습니다.',
    ],
    'statuses' => [
        'active' => '협력 중',
        'paused' => '일시 중지',
        'terminated' => '종료됨',
    ],
];
