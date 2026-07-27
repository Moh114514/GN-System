<?php

namespace App\Modules\Customer;

use App\Modules\Customer\Application\Contracts\CustomerImportGateway;
use App\Modules\Customer\Application\Services\DatabaseCustomerImportGateway;
use Illuminate\Support\ServiceProvider;

class CustomerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CustomerImportGateway::class, DatabaseCustomerImportGateway::class);
    }
}
