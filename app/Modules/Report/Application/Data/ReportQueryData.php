<?php

namespace App\Modules\Report\Application\Data;

use Carbon\CarbonImmutable;

final readonly class ReportQueryData
{
    public function __construct(
        public ?CarbonImmutable $completedFrom = null,
        public ?CarbonImmutable $completedTo = null,
        public ?string $timeFrom = null,
        public ?string $timeTo = null,
        public ?int $customerId = null,
        public ?int $agentId = null,
        public ?int $institutionId = null,
        public ?string $projectName = null,
        public ?string $translatorName = null,
        public ?int $amountMin = null,
        public ?int $amountMax = null,
        public string $sortField = 'completed_at',
        public string $sortDirection = 'desc',
        /** @var array<int, int> */
        public array $sortReferenceIds = [],
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'completed_from' => $this->completedFrom?->toIso8601String(),
            'completed_to' => $this->completedTo?->toIso8601String(),
            'time_from' => $this->timeFrom,
            'time_to' => $this->timeTo,
            'customer_id' => $this->customerId,
            'agent_id' => $this->agentId,
            'institution_id' => $this->institutionId,
            'project_name' => $this->projectName,
            'translator_name' => $this->translatorName,
            'amount_min' => $this->amountMin,
            'amount_max' => $this->amountMax,
            'sort_field' => $this->sortField,
            'sort_direction' => $this->sortDirection,
        ];
    }
}
