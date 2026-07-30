<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Order\Application\Contracts\InstitutionUsageReader;
use App\Modules\Order\Infrastructure\Models\Appointment;
use App\Modules\Order\Infrastructure\Models\Order;

final class DatabaseInstitutionUsageReader implements InstitutionUsageReader
{
    public function institutionIsReferenced(int $institutionId): bool
    {
        return Order::query()->where('institution_id', $institutionId)->exists()
            || Appointment::query()->where('institution_id', $institutionId)->exists();
    }
}
