<?php

namespace App\Modules\Report\Application\Data;

final readonly class InstitutionMonthlySalesRowData
{
    public function __construct(
        public int $institutionId,
        public string $institutionName,
        public int $customerCount,
        public int $orderCount,
        public int $amountKrw,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'institution_id' => $this->institutionId,
            'institution' => $this->institutionName,
            'customer_count' => $this->customerCount,
            'order_count' => $this->orderCount,
            'amount_krw' => $this->amountKrw,
        ];
    }
}
