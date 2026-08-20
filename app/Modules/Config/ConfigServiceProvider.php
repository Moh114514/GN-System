<?php

namespace App\Modules\Config;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Config\Application\Contracts\CatalogImportGateway;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Config\Application\Contracts\NotificationRecipientGateway;
use App\Modules\Config\Application\Contracts\OrderDictionaryReader;
use App\Modules\Config\Application\Contracts\ReferenceConfigurationImportGateway;
use App\Modules\Config\Application\Contracts\ReportConfigReader;
use App\Modules\Config\Application\Services\DatabaseCatalogImportGateway;
use App\Modules\Config\Application\Services\DatabaseInstitutionReferenceReader;
use App\Modules\Config\Application\Services\DatabaseNotificationRecipientGateway;
use App\Modules\Config\Application\Services\DatabaseOrderDictionaryReader;
use App\Modules\Config\Application\Services\DatabaseReferenceConfigurationImportGateway;
use App\Modules\Config\Application\Services\DatabaseReportConfigReader;
use App\Modules\Config\Presentation\Http\TimeTravelController;
use App\Modules\Config\Presentation\Livewire\CatalogConfiguration;
use App\Modules\Config\Presentation\Livewire\ConfigurationCenter;
use App\Modules\Config\Presentation\Livewire\ConfigurationHistory;
use App\Modules\Config\Presentation\Livewire\DataMaintenanceCenter;
use App\Modules\Config\Presentation\Livewire\TimeTravel;
use App\Modules\Config\Presentation\Livewire\UserAndNotificationSettings;
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
        $this->app->bind(NotificationRecipientGateway::class, DatabaseNotificationRecipientGateway::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'super-admin', 'super-admin.2fa'])->group(function (): void {
            Route::get('/admin/configuration', ConfigurationCenter::class)->name('configuration.index');
            Route::get('/admin/configuration/data-maintenance', DataMaintenanceCenter::class)
                ->name('configuration.data-maintenance');
            Route::get('/admin/configuration/catalog', CatalogConfiguration::class)
                ->name('configuration.catalog');
            Route::get('/admin/configuration/users-and-notifications', UserAndNotificationSettings::class)
                ->name('configuration.users-and-notifications');
            Route::get('/admin/configuration/users', fn () => redirect()->route('configuration.users-and-notifications', ['tab' => 'users']))
                ->name('configuration.users');
            Route::get('/admin/configuration/notifications', fn () => redirect()->route('configuration.users-and-notifications', ['tab' => 'notifications']))
                ->name('configuration.notifications');
            Route::get('/admin/configuration/history', ConfigurationHistory::class)
                ->name('configuration.history');

            if (app(BusinessClock::class)->isAvailable()) {
                Route::get('/admin/configuration/time-travel', TimeTravel::class)
                    ->name('configuration.time-travel');
                Route::post('/admin/configuration/time-travel/disable', [TimeTravelController::class, 'disable'])
                    ->name('configuration.time-travel.disable');
            }
        });
    }
}
