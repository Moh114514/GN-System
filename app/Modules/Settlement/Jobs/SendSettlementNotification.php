<?php

namespace App\Modules\Settlement\Jobs;

use App\Infrastructure\Localization\SupportedLocale;
use App\Modules\Settlement\Application\Services\SettlementNotifier;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendSettlementNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public string $runId,
        public ?string $locale = null,
    ) {}

    public function handle(SettlementNotifier $notifier): void
    {
        $previousLocale = app()->getLocale();
        app()->setLocale((SupportedLocale::fromCandidate($this->locale) ?? SupportedLocale::default())->value);
        try {
            $notifier->send($this->runId);
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    public function failed(Throwable $exception): void
    {
        SettlementRun::query()->whereKey($this->runId)->update([
            'notification_status' => 'failed',
            'notification_error' => $exception->getMessage(),
        ]);
    }
}
