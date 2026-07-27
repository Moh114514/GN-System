<?php

namespace App\Modules\Settlement\Application\Data;

use Carbon\CarbonImmutable;

final readonly class SettlementImportData
{
    public function __construct(
        public int $agentId,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public ?CarbonImmutable $settledOn,
        public ?string $exchangeRateKrwPerCny,
        public int $totalConsumptionKrw,
        public int $totalCommissionKrw,
        public int $payoutAmountCnyFen,
        public string $status,
        public ?string $importBatchId,
    ) {}
}
