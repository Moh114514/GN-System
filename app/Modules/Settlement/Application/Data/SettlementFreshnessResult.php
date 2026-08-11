<?php

namespace App\Modules\Settlement\Application\Data;

final readonly class SettlementFreshnessResult
{
    /**
     * @param  array<int, int>  $currentOrderIds
     * @param  array<int, int>  $settlementOrderIds
     * @param  array<int, int>  $addedOrderIds
     * @param  array<int, int>  $removedOrderIds
     * @param  array<int, int>  $missingCommissionOrderIds
     */
    public function __construct(
        public string $status,
        public int $currentItemCount,
        public int $currentConsumptionKrw,
        public int $currentCommissionKrw,
        public int $settlementItemCount,
        public int $settlementConsumptionKrw,
        public int $settlementCommissionKrw,
        public array $currentOrderIds,
        public array $settlementOrderIds,
        public array $addedOrderIds,
        public array $removedOrderIds,
        public array $missingCommissionOrderIds,
    ) {}

    public function isStale(): bool
    {
        return $this->status === 'stale';
    }
}
