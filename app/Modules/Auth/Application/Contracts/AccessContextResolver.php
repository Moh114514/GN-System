<?php

namespace App\Modules\Auth\Application\Contracts;

use App\Models\User;
use App\Modules\Auth\Application\Data\AccessContext;
use Closure;

interface AccessContextResolver
{
    public function current(): AccessContext;

    public function forUser(User $user): AccessContext;

    /** @param array<string, mixed> $snapshot */
    public function fromSnapshot(array $snapshot): AccessContext;

    public function using(AccessContext $context, Closure $callback): mixed;
}
