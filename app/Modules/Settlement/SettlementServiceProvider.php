<?php

namespace App\Modules\Settlement;

use App\Modules\Settlement\Application\Contracts\SettlementImportGateway;
use App\Modules\Settlement\Application\Services\DatabaseSettlementImportGateway;
use Illuminate\Support\ServiceProvider;

class SettlementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SettlementImportGateway::class, DatabaseSettlementImportGateway::class);
    }
}
