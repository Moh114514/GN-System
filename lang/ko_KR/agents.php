<?php

return [
    'titles' => ['list' => '에이전트 관리', 'form' => '에이전트 프로필', 'detail' => '에이전트 상세'],
    'list' => [
        'description' => '협력 프로필, 정책 등급 및 주문 프로모션 비용 기준을 통합 관리합니다.', 'configuration' => '에이전트 설정', 'create' => '에이전트 추가',
        'search' => '이름 또는 번호 검색', 'all_statuses' => '모든 상태', 'all_types' => '모든 유형', 'all_policies' => '모든 정책 체계', 'all_grades' => '모든 등급', 'clear' => '지우기',
        'columns' => ['agent' => '에이전트', 'type' => '유형', 'policy' => '정책 체계', 'grade' => '현재 등급', 'status' => '협력 상태', 'created_at' => '등록일'], 'empty' => '조건에 맞는 에이전트가 없습니다.',
    ],
    'form' => [
        'back_detail' => '에이전트 상세로 돌아가기', 'back_list' => '에이전트 관리로 돌아가기', 'profile' => '에이전트 프로필', 'edit' => '에이전트 편집', 'create' => '에이전트 추가',
        'edit_description' => '에이전트 번호는 영구적으로 유지되며 등급 변경은 다음 달부터 적용됩니다.', 'create_description' => '협력 프로필을 만들고 현재 정책 등급을 지정합니다.', 'basic' => '기본 정보', 'cooperation' => '협력 및 정책',
        'type' => '에이전트 유형', 'select' => '선택하세요', 'code_immutable' => '에이전트 번호（변경 불가）', 'code_prefix' => '번호 약칭', 'code_description' => '2–8자의 영문자 또는 숫자, 유형 코드가 자동으로 추가됩니다.', 'name' => '에이전트 이름', 'business_role' => '업무 역할', 'contact_name' => '담당자', 'contact_value' => '연락처', 'policy_grade' => '정책 등급', 'status' => '협력 상태', 'active' => '협력 중', 'paused' => '일시 중지', 'terminated' => '종료됨（영구 읽기 전용）', 'started' => '협력 시작일', 'ended' => '협력 종료일', 'notes' => '메모', 'save' => '에이전트 프로필 저장',
    ],
    'detail' => [
        'back' => '에이전트 관리로 돌아가기', 'profile' => '협력 프로필', 'edit' => '프로필 편집', 'name' => '에이전트 이름', 'code' => '에이전트 번호', 'type' => '유형', 'business_role' => '업무 역할', 'policy_system' => '정책 체계', 'unset_policy' => '정책 미설정', 'grade' => '현재 등급', 'unset_grade' => '등급 미설정', 'contact_name' => '담당자', 'contact_value' => '연락처', 'started' => '협력 시작', 'ended' => '협력 종료', 'effective_month' => '등급 적용 월', 'notes' => '메모', 'customers' => '유입 고객', 'customer' => '고객', 'created_at' => '등록일', 'no_customers' => '유입 고객이 없습니다.', 'orders' => '관련 주문', 'project_amount' => '프로젝트/금액', 'status' => '상태', 'promotion_fee' => '프로모션 비용', 'completed' => '완료', 'pending' => '진행 중', 'no_orders' => '관련 주문이 없습니다.', 'empty' => '—',
    ],
    'messages' => ['created' => '에이전트 프로필이 생성되었습니다.', 'updated' => '에이전트 프로필이 업데이트되었습니다. 등급 변경은 다음 달부터 적용됩니다.'],
];
