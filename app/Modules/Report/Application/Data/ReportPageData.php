<?php

namespace App\Modules\Report\Application\Data;

final readonly class ReportPageData
{
    /**
     * @param  array<int, ReportOrderData>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
        public int $lastPage,
        public float $queryMilliseconds,
    ) {}
}
