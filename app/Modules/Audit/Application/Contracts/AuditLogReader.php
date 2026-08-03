<?php

namespace App\Modules\Audit\Application\Contracts;

use App\Modules\Audit\Application\Data\AuditLogEntryData;
use App\Modules\Audit\Application\Data\AuditLogFilterData;
use Illuminate\Pagination\LengthAwarePaginator;

interface AuditLogReader
{
    /** @return LengthAwarePaginator<int, AuditLogEntryData> */
    public function paginate(AuditLogFilterData $filters, int $perPage): LengthAwarePaginator;

    /**
     * @return array{
     *     users: array<int, array{id: int, name: string}>,
     *     modules: array<int, string>,
     *     actions: array<int, string>
     * }
     */
    public function filterOptions(): array;
}
