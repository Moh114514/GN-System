<?php

use App\Infrastructure\Queue\QueueHeartbeat;
use App\Modules\Reminder\Application\Services\ReminderNotificationDispatcher;
use App\Modules\Reminder\Application\Services\ReminderScheduler;
use App\Modules\Settlement\Application\Services\SettlementNotificationDispatcher;
use App\Modules\Settlement\Application\Services\SettlementRunManager;
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

Artisan::command('app:generate-settlements', function (SettlementRunManager $manager): void {
    $run = $manager->startIfDue();
    $this->info($run === null ? '当前时间无需生成月结。' : "月结批次 {$run->id} 已启动。");
})->purpose('Generate the due monthly settlement run');

Artisan::command('app:materialize-reminders', function (ReminderScheduler $scheduler): void {
    $this->info('已生成 '.$scheduler->materialize().' 条新提醒。');
})->purpose('Materialize built-in and configured reminder instances');

Artisan::command('app:dispatch-reminder-notifications', function (ReminderNotificationDispatcher $dispatcher): void {
    $this->info('已派发 '.$dispatcher->dispatchDue().' 条到期提醒通知。');
})->purpose('Dispatch due reminder notifications');

Artisan::command('app:dispatch-settlement-notifications', function (SettlementNotificationDispatcher $dispatcher): void {
    $this->info('已派发 '.$dispatcher->dispatchCompleted().' 条月结完成通知。');
})->purpose('Dispatch completed settlement notifications');

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
    ->onOneServer()
    ->when(fn (): bool => config('operations.offsite_backup_monitor_enabled'));

Schedule::command('app:purge-imports')
    ->hourlyAt(30)
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:generate-settlements')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:materialize-reminders')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:dispatch-reminder-notifications')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:dispatch-settlement-notifications')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
