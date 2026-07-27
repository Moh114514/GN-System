<?php

namespace App\Modules\Agent\Application\Data;

use Carbon\CarbonImmutable;

final readonly class AgentImportData
{
    public function __construct(
        public string $code,
        public string $name,
        public string $businessRole,
        public ?string $contactName,
        public ?string $contactValue,
        public ?string $policySystem,
        public ?string $policyGrade,
        public ?CarbonImmutable $gradeEffectiveMonth,
        public ?CarbonImmutable $cooperationStartedOn,
        public string $cooperationStatus,
        public ?string $notes,
        public ?string $contractNumber,
        public ?CarbonImmutable $contractValidFrom,
        public ?CarbonImmutable $contractValidUntil,
        public ?string $importBatchId,
    ) {}
}
