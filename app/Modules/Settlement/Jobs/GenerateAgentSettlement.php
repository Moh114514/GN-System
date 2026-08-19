<?php

namespace App\Modules\Settlement\Jobs;

use App\Modules\Settlement\Application\Services\SettlementFailureRecorder;
use App\Modules\Settlement\Application\Services\SettlementGenerator;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateAgentSettlement implements ShouldQueue
{
    use Batchable, Queueable;

    public ?int $memberId = null;

    public ?string $runId = null;

    public ?int $agentId = null;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(?int $memberId = null, ?string $runId = null, ?int $agentId = null)
    {
        $this->memberId = $memberId;
        $this->runId = $runId;
        $this->agentId = $agentId;
    }

    public function handle(SettlementGenerator $generator): void
    {
        if ($this->memberId !== null) {
            $generator->generate((string) $this->memberId);

            return;
        }

        if ($this->runId !== null && $this->agentId !== null) {
            $generator->generate($this->runId, $this->agentId);

            return;
        }

        throw new \UnexpectedValueException('结算任务缺少成员或运行批次定位信息。');
    }

    public function failed(Throwable $exception, SettlementFailureRecorder $recorder): void
    {
        if ($this->memberId !== null) {
            $recorder->record((string) $this->memberId, $exception);

            return;
        }

        if ($this->runId !== null && $this->agentId !== null) {
            $recorder->record($this->runId, $exception, $this->agentId);
        }
    }
}
