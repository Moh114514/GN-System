<?php

use App\Infrastructure\Queue\QueueHeartbeat;
use App\Infrastructure\Time\BusinessClock;
use App\Modules\Reminder\Application\Services\ReminderNotificationDispatcher;
use App\Modules\Reminder\Application\Services\ReminderScheduler;
use App\Modules\Settlement\Application\Services\SettlementNotificationDispatcher;
use App\Modules\Settlement\Application\Services\SettlementRunManager;
use App\Modules\Settlement\Application\Services\SettlementRunReconciler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

Artisan::command('app:business-anomalies', function (BusinessClock $clock): int {
    $queries = [
        '已完成订单缺少项目明细' => DB::table('orders as o')
            ->where('o.status', 'completed')
            ->where('o.record_status', 'active')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('order_items as oi')->whereColumn('oi.order_id', 'o.id')),
        '订单金额与项目明细合计不一致' => DB::table('orders')
            ->where('status', 'completed')
            ->where('record_status', 'active')
            ->whereRaw('amount_krw <> (SELECT COALESCE(SUM(oi.amount_krw), 0) FROM order_items oi WHERE oi.order_id = orders.id)'),
        '代理商订单缺少推广费快照' => DB::table('orders as o')
            ->where('o.status', 'completed')
            ->where('o.record_status', 'active')
            ->where('o.source_type', 'agent')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('order_commissions as oc')->whereColumn('oc.order_id', 'o.id')),
        '已完成订单没有关联到院记录' => DB::table('orders')
            ->where('status', 'completed')
            ->where('record_status', 'active')
            ->whereNull('appointment_id'),
        '已完成到院记录缺少实际到院时间' => DB::table('appointments as a')
            ->where('a.status', 'completed')
            ->whereNull('a.arrived_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('orders as o')
                ->whereColumn('o.appointment_id', 'a.id')
                ->where('o.status', 'completed')
                ->where('o.record_status', 'active')),
        '月结缺少付款截止日' => DB::table('settlements')->whereNull('payment_due_on'),
        '已到可结算期但尚未分配的差额' => DB::table('settlement_adjustments')
            ->whereNull('target_settlement_id')
            ->whereDate('eligible_period_start', '<=', $clock->now()->startOfMonth()->toDateString()),
        '负月结余额缺少结转记录' => DB::table('settlements')
            ->where('total_commission_krw', '<', 0)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('settlement_adjustments as sa')
                ->whereRaw("sa.adjustment_key = CONCAT('carry:settlement:', settlements.id)")),
    ];
    $rows = [];
    foreach ($queries as $label => $query) {
        $rows[] = [
            $label,
            (clone $query)->count(),
            (clone $query)->orderBy('id')->limit(100)->pluck('id')->map(static fn ($id): string => (string) $id)->implode(', '),
        ];
    }
    $this->table(['异常类型', '数量', '样本 ID（最多 100 个）'], $rows);
    $this->line('查询只读，不修改订单、到院记录、差额或月结。');

    return 0;
})->purpose('Read-only report of order, visit, commission, and settlement anomalies');

Artisan::command('app:materialize-reminders', function (ReminderScheduler $scheduler): void {
    $this->info('已生成 '.$scheduler->materialize().' 条新提醒。');
})->purpose('Materialize built-in and configured reminder instances');

Artisan::command('app:dispatch-reminder-notifications', function (ReminderNotificationDispatcher $dispatcher): void {
    $this->info('已派发 '.$dispatcher->dispatchDue().' 条到期提醒通知。');
})->purpose('Dispatch due reminder notifications');

Artisan::command('app:dispatch-settlement-notifications', function (SettlementNotificationDispatcher $dispatcher): void {
    $this->info('已派发 '.$dispatcher->dispatchCompleted().' 条月结完成通知。');
})->purpose('Dispatch completed settlement notifications');

Artisan::command('app:reconcile-settlement-runs', function (SettlementRunReconciler $reconciler): void {
    $result = $reconciler->reconcile();
    $this->info(sprintf(
        'Checked %d settlement runs; stalled %d; repaired %d.',
        $result['checked'],
        $result['stalled'],
        $result['repaired'],
    ));
})->purpose('Reconcile settlement member state with Laravel queue batches');

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

Schedule::command('app:purge-report-exports')
    ->hourlyAt(40)
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

Schedule::command('app:reconcile-settlement-runs')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
