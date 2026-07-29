<?php

return [
    'offsite_sync_max_age_minutes' => (int) env('OFFSITE_SYNC_MAX_AGE_MINUTES', 90),
    'offsite_backup_monitor_enabled' => (bool) env('OFFSITE_BACKUP_MONITOR_ENABLED', true),
    'alert_email' => env('BACKUP_NOTIFICATION_EMAIL'),
];
