<?php

namespace App\Modules\Config;

use App\Modules\Config\Application\Contracts\CatalogImportGateway;
use App\Modules\Config\Application\Services\DatabaseCatalogImportGateway;
use Illuminate\Support\ServiceProvider;

class ConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CatalogImportGateway::class, DatabaseCatalogImportGateway::class);
    }
}
