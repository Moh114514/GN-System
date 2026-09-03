<?php

namespace App\Modules\Auth\Domain;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case BdManager = 'bd_manager';
    case CustomerService = 'customer_service';

    public function isBusinessRole(): bool
    {
        return $this !== self::SuperAdmin;
    }
}
