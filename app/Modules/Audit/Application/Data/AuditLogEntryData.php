<?php

namespace App\Modules\Audit\Application\Data;

use Carbon\CarbonImmutable;

final readonly class AuditLogEntryData
{
    /** @param array<string, mixed> $properties */
    public function __construct(
        public int $id,
        public CarbonImmutable $occurredAt,
        public string $module,
        public string $action,
        public string $description,
        public ?string $causerName,
        public ?int $targetUserId,
        public array $properties,
        public bool $legacyDescription = false,
    ) {}
}
