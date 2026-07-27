<?php

use App\Infrastructure\Queue\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

Schedule::command('app:scheduler-heartbeat')
    ->everyMinute()
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
