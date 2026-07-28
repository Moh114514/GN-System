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
        public string $runId,
        public int $agentId,
    ) {}

    public function handle(SettlementGenerator $generator): void
    {
        $generator->generate($this->runId, $this->agentId);
    }

    public function failed(Throwable $exception): void
    {
        app(SettlementGenerator::class)->markFailed($this->runId, $this->agentId, $exception);
    }
}
