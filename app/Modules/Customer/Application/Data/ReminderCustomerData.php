<?php

namespace App\Modules\Customer\Application\Data;

use Carbon\CarbonImmutable;

final readonly class ReminderCustomerData
{
    public function __construct(
        public int $id,
        public string $name,
        public ?CarbonImmutable $birthDate,
        public ?CarbonImmutable $wechatAddedOn,
        public CarbonImmutable $createdAt,
        public ?int $ownerId,
        public ?int $sourceAgentId,
        public ?string $agentStatus,
        public ?int $statusId,
        public ?CarbonImmutable $statusChangedAt,
        public ?string $projectIntention,
    ) {}
}
