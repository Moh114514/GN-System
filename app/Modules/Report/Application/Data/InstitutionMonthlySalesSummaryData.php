<?php

namespace App\Modules\Report\Application\Data;

use Carbon\CarbonImmutable;

final readonly class InstitutionMonthlySalesSummaryData
{
    /**
     * @param  list<InstitutionMonthlySalesRowData>  $rows
     */
    public function __construct(
        public string $month,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $rows,
        public int $totalCustomers,
        public int $totalOrders,
        public int $totalAmountKrw,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'month' => $this->month,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'rows' => array_map(
                static fn (InstitutionMonthlySalesRowData $row): array => $row->toArray(),
                $this->rows,
            ),
            'total_customers' => $this->totalCustomers,
            'total_orders' => $this->totalOrders,
            'total_amount_krw' => $this->totalAmountKrw,
        ];
    }
}
