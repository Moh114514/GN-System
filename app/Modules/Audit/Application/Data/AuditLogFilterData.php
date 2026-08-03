<?php

namespace App\Modules\Audit\Application\Data;

final readonly class AuditLogFilterData
{
    public function __construct(
        public ?string $occurredOn = null,
        public ?int $causerId = null,
        public ?int $targetUserId = null,
        public ?string $module = null,
        public ?string $action = null,
    ) {}
}
