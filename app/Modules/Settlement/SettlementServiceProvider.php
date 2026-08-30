<?php

namespace App\Modules\Settlement;

use App\Modules\Settlement\Application\Contracts\BdCommissionCorrectionGateway;
use App\Modules\Settlement\Application\Contracts\CommissionConfigurationGateway;
use App\Modules\Settlement\Application\Contracts\ConfigurationHistoryGateway;
use App\Modules\Settlement\Application\Contracts\DailyCommissionGateway;
use App\Modules\Settlement\Application\Contracts\InstitutionUsageReader;
use App\Modules\Settlement\Application\Contracts\KrwCnyQuoteProvider;
use App\Modules\Settlement\Application\Contracts\OrderFinancialReader;
use App\Modules\Settlement\Application\Contracts\ReportSettlementReader;
use App\Modules\Settlement\Application\Contracts\SettlementImportGateway;
use App\Modules\Settlement\Application\Services\ApiHzKrwCnyQuoteProvider;
use App\Modules\Settlement\Application\Services\BdQuarterlyCommissionService;
use App\Modules\Settlement\Application\Services\DatabaseCommissionConfigurationGateway;
use App\Modules\Settlement\Application\Services\DatabaseConfigurationHistoryGateway;
use App\Modules\Settlement\Application\Services\DatabaseDailyCommissionGateway;
use App\Modules\Settlement\Application\Services\DatabaseInstitutionUsageReader;
use App\Modules\Settlement\Application\Services\DatabaseOrderFinancialReader;
use App\Modules\Settlement\Application\Services\DatabaseReportSettlementReader;
use App\Modules\Settlement\Application\Services\DatabaseSettlementImportGateway;
use App\Modules\Settlement\Presentation\Http\BdQuarterlyCommissionDocumentController;
use App\Modules\Settlement\Presentation\Http\SettlementDocumentController;
use App\Modules\Settlement\Presentation\Http\SettlementRunFailureController;
use App\Modules\Settlement\Presentation\Livewire\BdQuarterlyCommissionCenter;
use App\Modules\Settlement\Presentation\Livewire\SettlementCenter;
use App\Modules\Settlement\Presentation\Livewire\SettlementDetail;
use App\Modules\Settlement\Presentation\Livewire\SettlementHistory;
use App\Modules\Settlement\Presentation\Livewire\SettlementRunFailureDetail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LogicException;

class SettlementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SettlementImportGateway::class, DatabaseSettlementImportGateway::class);
        $this->app->bind(BdCommissionCorrectionGateway::class, BdQuarterlyCommissionService::class);
        $this->app->bind(DailyCommissionGateway::class, DatabaseDailyCommissionGateway::class);
        $this->app->bind(CommissionConfigurationGateway::class, DatabaseCommissionConfigurationGateway::class);
        $this->app->bind(ReportSettlementReader::class, DatabaseReportSettlementReader::class);
        $this->app->bind(InstitutionUsageReader::class, DatabaseInstitutionUsageReader::class);
        $this->app->bind(OrderFinancialReader::class, DatabaseOrderFinancialReader::class);
        $this->app->bind(ConfigurationHistoryGateway::class, DatabaseConfigurationHistoryGateway::class);
        $this->app->bind(KrwCnyQuoteProvider::class, function (): KrwCnyQuoteProvider {
            return match (config('services.settlement_exchange_rate.provider')) {
                'api_hz' => new ApiHzKrwCnyQuoteProvider,
                default => throw new LogicException('未支持的月结自动汇率报价 provider 配置。'),
            };
        });
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'settlement.read', 'super-admin.2fa'])->group(function (): void {
            Route::get('/settlements', SettlementCenter::class)->name('settlements.index');
            Route::get('/bd-commissions', BdQuarterlyCommissionCenter::class)->name('bd-commissions.index');
            Route::get('/settlements/{settlement}', SettlementDetail::class)->whereNumber('settlement')->name('settlements.show');
            Route::get('/settlement-documents/{document}', [SettlementDocumentController::class, 'document'])
                ->whereNumber('document')->name('settlements.documents.download');
            Route::get('/bd-commissions/{period}/users/{bdUserId}/document/{format}', [BdQuarterlyCommissionDocumentController::class, 'download'])
                ->whereNumber('period')->whereNumber('bdUserId')->whereIn('format', ['xlsx', 'pdf'])
                ->name('bd-commissions.documents.download');
        });
        Route::middleware(['web', 'auth', 'verified', 'super-admin', 'super-admin.2fa'])->group(function (): void {
            Route::get('/settlements/history', SettlementHistory::class)->name('settlements.history');
            Route::get('/settlement-runs/{run}/archive', [SettlementDocumentController::class, 'archive'])
                ->whereUuid('run')->name('settlements.archive');
            Route::get('/settlement-runs/{run}/failures', SettlementRunFailureDetail::class)
                ->whereUuid('run')->name('settlements.runs.failures');
            Route::get('/settlement-runs/{run}/failures/download', [SettlementRunFailureController::class, 'download'])
                ->whereUuid('run')->name('settlements.runs.failures.download');
        });
    }
}
