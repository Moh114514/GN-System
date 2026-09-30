<?php

namespace App\Modules\Auth\Domain;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case BdManager = 'bd_manager';
    case CustomerService = 'customer_service';
    case DirectCustomerManager = 'direct_customer_manager';

    public function isBusinessRole(): bool
    {
        return in_array($this, [self::BdManager, self::CustomerService], true);
    }
}
