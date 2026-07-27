<?php

return [
    'offsite_sync_max_age_minutes' => (int) env('OFFSITE_SYNC_MAX_AGE_MINUTES', 90),
    'alert_email' => env('BACKUP_NOTIFICATION_EMAIL'),
];
