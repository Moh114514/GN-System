<?php

namespace App\Modules\Report;

use App\Modules\Report\Console\PurgeExpiredReportExportsCommand;
use App\Modules\Report\Presentation\Http\ReportExportController;
use App\Modules\Report\Presentation\Livewire\ReportSearchPage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ReportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeExpiredReportExportsCommand::class]);
        }

        Route::middleware(['web', 'auth', 'verified', 'super-admin.2fa'])->group(function (): void {
            Route::get('/reports/search', ReportSearchPage::class)->name('reports.search');
            Route::get('/reports/exports/{export}', ReportExportController::class)
                ->name('reports.exports.download');
        });
    }
}
