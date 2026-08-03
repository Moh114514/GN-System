<?php

return [
    'max_sync_export_rows' => (int) env('REPORT_MAX_SYNC_EXPORT_ROWS', 2000),
    'max_export_rows' => (int) env('REPORT_MAX_EXPORT_ROWS', 50000),
];
