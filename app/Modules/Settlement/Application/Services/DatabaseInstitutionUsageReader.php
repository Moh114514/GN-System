<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Application\Contracts\InstitutionUsageReader;
use App\Modules\Settlement\Infrastructure\Models\AgentCommissionOverride;
use App\Modules\Settlement\Infrastructure\Models\CommissionRule;

final class DatabaseInstitutionUsageReader implements InstitutionUsageReader
{
    public function institutionIsReferenced(int $institutionId): bool
    {
        return CommissionRule::query()->where('institution_id', $institutionId)->exists()
            || AgentCommissionOverride::query()->where('institution_id', $institutionId)->exists();
    }
}
