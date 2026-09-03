<?php

namespace App\Support\Exports\DTO;

/**
 * A presentation-neutral snapshot for a formal financial document.
 *
 * Business modules provide already-calculated values. Renderers only format
 * those values and must never recalculate financial amounts.
 */
final readonly class FinancialDocumentData
{
    /**
     * @param  list<array{label: string, value: scalar|null}>  $metadata
     * @param  list<array{key: string, label: string, type?: string, width?: float}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{label: string, value: scalar|null, type?: string, currency?: string, emphasis?: bool}>  $summaryRows
     * @param  list<string>  $remarks
     */
    public function __construct(
        public string $title,
        public string $documentNumber,
        public string $documentDate,
        public string $subject,
        public string $period,
        public int|float $primaryAmount,
        public string $currency,
        public array $metadata,
        public array $columns,
        public array $rows,
        public array $summaryRows,
        public array $remarks = [],
        public ?string $primaryAmountLabel = null,
        public ?int $currencyDecimals = null,
    ) {}
}
