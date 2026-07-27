<?php

return [
    'max_file_kilobytes' => 20 * 1024,
    'rollback_hours' => 24,
    'failed_retention_days' => 7,
    'blind_index_key' => env('BLIND_INDEX_KEY', env('APP_KEY')),
    'allowed_extensions' => ['xlsx', 'xls', 'csv'],
];
