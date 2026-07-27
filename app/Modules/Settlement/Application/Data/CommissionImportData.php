<?php

namespace App\Modules\Settlement\Application\Data;

final readonly class CommissionImportData
{
    /**
     * @param  array<string, mixed>  $ruleSnapshot
     */
    public function __construct(
        public int $orderId,
        public int $agentId,
        public int $rateBps,
        public int $amountKrw,
        public array $ruleSnapshot,
        public ?string $overrideReason,
        public ?string $importBatchId,
    ) {}
}
