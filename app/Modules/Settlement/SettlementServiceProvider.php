<?php

namespace App\Modules\Settlement;

use App\Modules\Settlement\Application\Contracts\CommissionConfigurationGateway;
use App\Modules\Settlement\Application\Contracts\ConfigurationHistoryGateway;
use App\Modules\Settlement\Application\Contracts\DailyCommissionGateway;
use App\Modules\Settlement\Application\Contracts\InstitutionUsageReader;
use App\Modules\Settlement\Application\Contracts\ReportSettlementReader;
use App\Modules\Settlement\Application\Contracts\SettlementImportGateway;
use App\Modules\Settlement\Application\Services\DatabaseCommissionConfigurationGateway;
use App\Modules\Settlement\Application\Services\DatabaseConfigurationHistoryGateway;
use App\Modules\Settlement\Application\Services\DatabaseDailyCommissionGateway;
use App\Modules\Settlement\Application\Services\DatabaseInstitutionUsageReader;
use App\Modules\Settlement\Application\Services\DatabaseReportSettlementReader;
use App\Modules\Settlement\Application\Services\DatabaseSettlementImportGateway;
use App\Modules\Settlement\Presentation\Http\SettlementDocumentController;
use App\Modules\Settlement\Presentation\Livewire\SettlementCenter;
use App\Modules\Settlement\Presentation\Livewire\SettlementDetail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SettlementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SettlementImportGateway::class, DatabaseSettlementImportGateway::class);
        $this->app->bind(DailyCommissionGateway::class, DatabaseDailyCommissionGateway::class);
        $this->app->bind(CommissionConfigurationGateway::class, DatabaseCommissionConfigurationGateway::class);
        $this->app->bind(ReportSettlementReader::class, DatabaseReportSettlementReader::class);
        $this->app->bind(InstitutionUsageReader::class, DatabaseInstitutionUsageReader::class);
        $this->app->bind(ConfigurationHistoryGateway::class, DatabaseConfigurationHistoryGateway::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'super-admin', 'super-admin.2fa'])->group(function (): void {
            Route::get('/settlements', SettlementCenter::class)->name('settlements.index');
            Route::get('/settlements/{settlement}', SettlementDetail::class)->whereNumber('settlement')->name('settlements.show');
            Route::get('/settlement-documents/{document}', [SettlementDocumentController::class, 'document'])
                ->whereNumber('document')->name('settlements.documents.download');
            Route::get('/settlement-runs/{run}/archive', [SettlementDocumentController::class, 'archive'])
                ->whereUuid('run')->name('settlements.archive');
        });
    }
}
