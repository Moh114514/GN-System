<?php

namespace App\Modules\Customer\Application\Contracts;

use Carbon\CarbonImmutable;

interface CustomerTreatmentCompletionGateway
{
    public function completeFromInstitutionReturn(
        int $customerId,
        CarbonImmutable $occurredOn,
        int $actorId,
        ?string $ipAddress,
    ): void;
}
