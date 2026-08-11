<?php

return [
    'required' => ':attribute不能为空。',
    'email' => ':attribute必须是有效的邮箱地址。',
    'string' => ':attribute必须是字符串。',
    'min' => [
        'string' => ':attribute至少需要 :min 个字符。',
    ],
    'confirmed' => ':attribute两次输入不一致。',
    'attributes' => [
        'email' => '邮箱',
        'password' => '密码',
        'password_confirmation' => '确认密码',
    ],
];
