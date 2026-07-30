<?php

namespace App\Modules\Report\Application\Data;

final readonly class DashboardSnapshotData
{
    /**
     * @param  array<string, array{value: int|float, previous: int|float, change: float|null}>  $metrics
     * @param  array<string, array<int, array<string, int|float|string>>>  $charts
     * @param  array<string, mixed>  $panels
     */
    public function __construct(
        public DashboardRangeData $range,
        public array $metrics,
        public array $charts,
        public array $panels,
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
            'panels' => $this->panels,
            'generated_at' => $this->generatedAt,
        ];
    }
}
