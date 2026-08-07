<?php

return [
    'errors' => [
        'invitation_already_completed' => '이 사용자는 이미 비밀번호를 설정했으므로 초대장을 다시 보낼 필요가 없습니다.',
        'last_super_admin_role' => '활성화된 마지막 슈퍼 관리자의 권한을 낮출 수 없습니다.',
        'current_account_disable' => '현재 로그인한 계정을 비활성화할 수 없습니다.',
        'last_super_admin_disable' => '활성화된 마지막 슈퍼 관리자를 비활성화할 수 없습니다.',
    ],
    'failed' => '이메일 또는 비밀번호가 올바르지 않습니다.',
    'password' => '비밀번호가 올바르지 않습니다.',
    'throttle' => ':seconds초 후 다시 시도해 주세요.',
    'login' => [
        'eyebrow' => 'CRM 관리 시스템',
        'welcome' => '다시 오신 것을 환영합니다',
        'description' => '워크스페이스에 계속 액세스하려면 계정 정보를 입력하세요',
        'email' => '이메일 주소',
        'password' => '비밀번호',
        'password_placeholder' => '비밀번호를 입력하세요',
        'forgot_password' => '비밀번호를 잊으셨나요?',
        'remember' => '로그인 상태 유지',
        'submit' => '로그인',
        'help' => '승인된 내부 직원만 사용할 수 있습니다',
    ],
    'security_notice' => '기업 수준의 데이터 보안 보호',
    'middleware' => [
        'session_expired' => '로그인 세션이 만료되었습니다. 다시 로그인해 주세요.',
        'account_disabled' => '이 계정은 비활성화되었습니다. 슈퍼 관리자에게 문의하세요.',
        'two_factor_required' => '계속하려면 먼저 2단계 인증을 활성화하고 확인하세요.',
    ],
];
