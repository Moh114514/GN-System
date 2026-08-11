<?php

namespace App\Modules\Agent\Application\Data;

use Carbon\CarbonImmutable;

final readonly class AgentProfileData
{
    public function __construct(
        public int $typeCodeId,
        public string $codePrefix,
        public string $name,
        public ?string $businessRole,
        public ?string $contactName,
        public ?string $contactValue,
        public CarbonImmutable $cooperationStartedOn,
        public ?CarbonImmutable $cooperationEndedOn,
        public string $cooperationStatus,
        public ?int $policyGradeId,
        public ?string $notes,
    ) {}
}
