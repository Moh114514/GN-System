<?php

return [
    'max_sync_export_rows' => (int) env('REPORT_MAX_SYNC_EXPORT_ROWS', 2000),
    'max_export_rows' => (int) env('REPORT_MAX_EXPORT_ROWS', 50000),
    'pdf' => [
        'font_regular_path' => env('REPORT_PDF_FONT_REGULAR_PATH', '/usr/local/share/fonts/gn-system/GNSystemCJK-Regular.ttf'),
        'font_bold_path' => env('REPORT_PDF_FONT_BOLD_PATH', '/usr/local/share/fonts/gn-system/GNSystemCJK-Bold.ttf'),
    ],
];
