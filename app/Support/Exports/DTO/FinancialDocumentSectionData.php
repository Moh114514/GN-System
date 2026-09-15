<?php

namespace App\Support\Exports\DTO;

final readonly class FinancialDocumentSectionData
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{label: string, value: scalar|null, type?: string, currency?: string, emphasis?: bool}>  $summaryRows
     */
    public function __construct(
        public string $title,
        public array $rows,
        public array $summaryRows = [],
    ) {}
}
