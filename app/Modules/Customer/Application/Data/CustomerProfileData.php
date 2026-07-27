<?php

namespace App\Modules\Customer\Application\Data;

use Carbon\CarbonImmutable;

final readonly class CustomerProfileData
{
    public function __construct(
        public string $name,
        public ?string $gender,
        public CarbonImmutable $birthDate,
        public string $originalChannel,
        public ?int $sourceAgentId,
        public ?int $sourceDirectSalesId,
        public string $contactValue,
        public string $identityDocument,
        public string $projectIntention,
        public ?string $notes,
    ) {}
}
