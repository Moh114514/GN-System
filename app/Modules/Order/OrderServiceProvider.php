<?php

namespace App\Modules\Order;

use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Order\Application\Contracts\DailyOrderGateway;
use App\Modules\Order\Application\Contracts\OrderImportGateway;
use App\Modules\Order\Application\Contracts\ReminderSourceReader;
use App\Modules\Order\Application\Contracts\SettlementOrderReader;
use App\Modules\Order\Application\Services\DatabaseCustomerOrderGateway;
use App\Modules\Order\Application\Services\DatabaseDailyOrderGateway;
use App\Modules\Order\Application\Services\DatabaseOrderImportGateway;
use App\Modules\Order\Application\Services\DatabaseReminderSourceReader;
use App\Modules\Order\Application\Services\DatabaseSettlementOrderReader;
use App\Modules\Order\Presentation\Livewire\CustomerOrders;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderImportGateway::class, DatabaseOrderImportGateway::class);
        $this->app->bind(CustomerOrderGateway::class, DatabaseCustomerOrderGateway::class);
        $this->app->bind(DailyOrderGateway::class, DatabaseDailyOrderGateway::class);
        $this->app->bind(SettlementOrderReader::class, DatabaseSettlementOrderReader::class);
        $this->app->bind(ReminderSourceReader::class, DatabaseReminderSourceReader::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'super-admin.2fa'])
            ->get('/customers/{customer}/orders', CustomerOrders::class)
            ->whereNumber('customer')
            ->name('customers.orders');
    }
}
