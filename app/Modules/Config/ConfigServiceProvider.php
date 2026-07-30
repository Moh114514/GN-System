<?php

namespace App\Modules\Config;

use App\Modules\Config\Application\Contracts\CatalogImportGateway;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Config\Application\Contracts\ReferenceConfigurationImportGateway;
use App\Modules\Config\Application\Services\DatabaseCatalogImportGateway;
use App\Modules\Config\Application\Services\DatabaseInstitutionReferenceReader;
use App\Modules\Config\Application\Services\DatabaseReferenceConfigurationImportGateway;
use App\Modules\Config\Presentation\Livewire\ConfigurationCenter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CatalogImportGateway::class, DatabaseCatalogImportGateway::class);
        $this->app->bind(InstitutionReferenceReader::class, DatabaseInstitutionReferenceReader::class);
        $this->app->bind(ReferenceConfigurationImportGateway::class, DatabaseReferenceConfigurationImportGateway::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'super-admin', 'super-admin.2fa'])
            ->get('/admin/configuration', ConfigurationCenter::class)
            ->name('configuration.index');
    }
}
