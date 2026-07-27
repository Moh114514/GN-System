<?php

namespace App\Modules\Order;

use App\Modules\Order\Application\Contracts\OrderImportGateway;
use App\Modules\Order\Application\Services\DatabaseOrderImportGateway;
use Illuminate\Support\ServiceProvider;

class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderImportGateway::class, DatabaseOrderImportGateway::class);
    }
}
