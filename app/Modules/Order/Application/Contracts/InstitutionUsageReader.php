<?php

namespace App\Modules\Order\Application\Contracts;

interface InstitutionUsageReader
{
    public function institutionIsReferenced(int $institutionId): bool;
}
