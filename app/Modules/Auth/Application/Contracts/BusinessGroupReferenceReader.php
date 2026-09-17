<?php

namespace App\Modules\Auth\Application\Contracts;

interface BusinessGroupReferenceReader
{
    /** @return array<int, array{id: int, code: string, name: string, is_active: bool}> */
    public function businessGroups(): array;

    public function exists(int $businessGroupId, bool $activeOnly = true): bool;
}
