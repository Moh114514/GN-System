<?php

namespace App\Modules\Report\Application\Data;

final readonly class DashboardSnapshotData
{
    /**
     * @param  array<string, array{value: int|float, previous: int|float, change: float|null}>  $metrics
     * @param  array<string, array<int, array<string, int|float|string>>>  $charts
     */
    public function __construct(
        public DashboardRangeData $range,
        public array $metrics,
        public array $charts,
        public string $generatedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'range' => [
                'from' => $this->range->from->toIso8601String(),
                'to' => $this->range->to->toIso8601String(),
                'label' => $this->range->label,
            ],
            'metrics' => $this->metrics,
            'charts' => $this->charts,
            'generated_at' => $this->generatedAt,
        ];
    }
}
