<?php

namespace App\Modules\Audit\Application\Data;

use Carbon\CarbonImmutable;

final readonly class AuditEntryData
{
    /** @param array<string, mixed> $properties */
    public function __construct(
        public string $description,
        public ?string $event,
        public array $properties,
        public ?int $causerId,
        public CarbonImmutable $occurredAt,
    ) {}
}
