<?php

namespace App\Modules\Settlement\Jobs;

use App\Modules\Settlement\Application\Services\SettlementGenerator;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateAgentSettlement implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public int $memberId,
        public ?int $agentId = null,
    ) {}

    public function handle(SettlementGenerator $generator): void
    {
        $generator->generate((string) $this->memberId);
    }

    public function failed(Throwable $exception): void
    {
        app(SettlementGenerator::class)->markFailed((string) $this->memberId, $exception);
    }
}
