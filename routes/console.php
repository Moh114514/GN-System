<?php

use App\Infrastructure\Queue\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:queue-heartbeat', function (): void {
    QueueHeartbeat::dispatch();
})->purpose('Dispatch a short-lived queue health heartbeat');

Artisan::command('app:scheduler-heartbeat', function (): void {
    Cache::put(
        'gn-system:scheduler-heartbeat',
        now()->toIso8601String(),
        now()->addMinutes(5),
    );
})->purpose('Record a short-lived scheduler health heartbeat');

Artisan::command('app:offsite-backup-monitor', function (): void {
    $marker = storage_path('app/backups/.offsite-sync-success');
    $maximumAge = (int) config('operations.offsite_sync_max_age_minutes');

    if (! is_file($marker) || filemtime($marker) < now()->subMinutes($maximumAge)->timestamp) {
        $alertEmail = config('operations.alert_email');

        if (is_string($alertEmail) && $alertEmail !== '') {
            Mail::raw(
                'GN-System offsite backup synchronization is missing or stale.',
                fn ($message) => $message
                    ->to($alertEmail)
                    ->subject('GN-System offsite backup alert'),
            );
        }

        throw new RuntimeException('The offsite backup sync marker is missing or stale.');
    }
})->purpose('Fail when the host offsite backup sync is stale');

Schedule::command('app:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:queue-heartbeat')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('backup:run --only-db')
    ->cron('0 0-1,3-23 * * *')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('backup:monitor')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:offsite-backup-monitor')
    ->hourlyAt(15)
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:purge-imports')
    ->hourlyAt(30)
    ->withoutOverlapping()
    ->onOneServer();
