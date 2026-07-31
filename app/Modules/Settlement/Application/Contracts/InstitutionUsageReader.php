<?php

namespace App\Modules\Settlement\Application\Contracts;

interface InstitutionUsageReader
{
    public function institutionIsReferenced(int $institutionId): bool;
}
