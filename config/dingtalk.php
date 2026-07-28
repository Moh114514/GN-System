<?php

return [
    'enabled' => (bool) env('DINGTALK_ENABLED', false),
    'webhook_url' => env('DINGTALK_WEBHOOK_URL'),
    'secret' => env('DINGTALK_SECRET'),
];
