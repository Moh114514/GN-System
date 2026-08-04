<?php

namespace App\Modules\Settlement\Application\Data;

use App\Modules\Settlement\Infrastructure\Models\SettlementRun;

final readonly class SettlementRunStartResult
{
    /**
     * @param  string  $outcome  One of the created/existing settlement start outcomes.
     */
    public function __construct(
        public SettlementRun $run,
        public string $outcome,
    ) {}
}
