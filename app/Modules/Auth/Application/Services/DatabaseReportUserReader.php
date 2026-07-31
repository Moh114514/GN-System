<?php

namespace App\Modules\Auth\Application\Services;

use App\Models\User;
use App\Modules\Auth\Application\Contracts\ReportUserReader;

final class DatabaseReportUserReader implements ReportUserReader
{
    public function namesByIds(array $ids): array
    {
        return User::query()
            ->whereKey(array_values(array_unique($ids)))
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(int) $id => (string) $name])
            ->all();
    }
}
