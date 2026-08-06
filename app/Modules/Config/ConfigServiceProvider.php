<?php

namespace App\Modules\Config;

use App\Modules\Config\Application\Contracts\CatalogImportGateway;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Config\Application\Contracts\OrderDictionaryReader;
use App\Modules\Config\Application\Contracts\ReferenceConfigurationImportGateway;
use App\Modules\Config\Application\Contracts\ReportConfigReader;
use App\Modules\Config\Application\Services\DatabaseCatalogImportGateway;
use App\Modules\Config\Application\Services\DatabaseInstitutionReferenceReader;
use App\Modules\Config\Application\Services\DatabaseOrderDictionaryReader;
use App\Modules\Config\Application\Services\DatabaseReferenceConfigurationImportGateway;
use App\Modules\Config\Application\Services\DatabaseReportConfigReader;
use App\Modules\Config\Presentation\Livewire\CatalogConfiguration;
use App\Modules\Config\Presentation\Livewire\ConfigurationCenter;
use App\Modules\Config\Presentation\Livewire\ConfigurationHistory;
use App\Modules\Config\Presentation\Livewire\DataMaintenanceCenter;
use App\Modules\Config\Presentation\Livewire\UserManagement;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CatalogImportGateway::class, DatabaseCatalogImportGateway::class);
        $this->app->bind(InstitutionReferenceReader::class, DatabaseInstitutionReferenceReader::class);
        $this->app->bind(ReferenceConfigurationImportGateway::class, DatabaseReferenceConfigurationImportGateway::class);
        $this->app->bind(ReportConfigReader::class, DatabaseReportConfigReader::class);
        $this->app->bind(OrderDictionaryReader::class, DatabaseOrderDictionaryReader::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'super-admin', 'super-admin.2fa'])->group(function (): void {
            Route::get('/admin/configuration', ConfigurationCenter::class)->name('configuration.index');
            Route::get('/admin/configuration/data-maintenance', DataMaintenanceCenter::class)
                ->name('configuration.data-maintenance');
            Route::get('/admin/configuration/catalog', CatalogConfiguration::class)
                ->name('configuration.catalog');
            Route::get('/admin/configuration/users', UserManagement::class)
                ->name('configuration.users');
            Route::get('/admin/configuration/history', ConfigurationHistory::class)
                ->name('configuration.history');
        });
    }
}
