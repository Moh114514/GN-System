<?php

namespace App\Modules\Order;

use App\Modules\Order\Application\Contracts\BdCommissionOrderReader;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Order\Application\Contracts\DailyOrderGateway;
use App\Modules\Order\Application\Contracts\InstitutionUsageReader;
use App\Modules\Order\Application\Contracts\OrderImportGateway;
use App\Modules\Order\Application\Contracts\OrderLifecycleGateway;
use App\Modules\Order\Application\Contracts\ReminderSourceReader;
use App\Modules\Order\Application\Contracts\ReportOrderReader;
use App\Modules\Order\Application\Contracts\SettlementOrderReader;
use App\Modules\Order\Application\Services\DatabaseBdCommissionOrderReader;
use App\Modules\Order\Application\Services\DatabaseCustomerOrderGateway;
use App\Modules\Order\Application\Services\DatabaseDailyOrderGateway;
use App\Modules\Order\Application\Services\DatabaseInstitutionUsageReader;
use App\Modules\Order\Application\Services\DatabaseOrderImportGateway;
use App\Modules\Order\Application\Services\DatabaseOrderLifecycleGateway;
use App\Modules\Order\Application\Services\DatabaseReminderSourceReader;
use App\Modules\Order\Application\Services\DatabaseReportOrderReader;
use App\Modules\Order\Application\Services\DatabaseSettlementOrderReader;
use App\Modules\Order\Presentation\Http\InstitutionReturnFileController;
use App\Modules\Order\Presentation\Livewire\CustomerOrders;
use App\Modules\Order\Presentation\Livewire\InstitutionReturnCenter;
use App\Modules\Order\Presentation\Livewire\OrderCenter;
use App\Modules\Order\Presentation\Livewire\OrderDetail;
use App\Modules\Order\Presentation\Livewire\OrderEdit;
use App\Modules\Order\Presentation\Livewire\OrderRecycleBin;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderImportGateway::class, DatabaseOrderImportGateway::class);
        $this->app->bind(OrderLifecycleGateway::class, DatabaseOrderLifecycleGateway::class);
        $this->app->bind(CustomerOrderGateway::class, DatabaseCustomerOrderGateway::class);
        $this->app->bind(BdCommissionOrderReader::class, DatabaseBdCommissionOrderReader::class);
        $this->app->bind(DailyOrderGateway::class, DatabaseDailyOrderGateway::class);
        $this->app->bind(SettlementOrderReader::class, DatabaseSettlementOrderReader::class);
        $this->app->bind(ReminderSourceReader::class, DatabaseReminderSourceReader::class);
        $this->app->bind(ReportOrderReader::class, DatabaseReportOrderReader::class);
        $this->app->bind(InstitutionUsageReader::class, DatabaseInstitutionUsageReader::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'super-admin.2fa'])
            ->group(function (): void {
                Route::get('/orders', OrderCenter::class)->name('orders.index');
                Route::get('/institution-returns', InstitutionReturnCenter::class)->name('institution-returns.index');
                Route::get('/institution-returns/{returnFile}/download', [InstitutionReturnFileController::class, 'download'])
                    ->whereUuid('returnFile')
                    ->name('institution-returns.download');
                Route::get('/orders/recycle-bin', OrderRecycleBin::class)->name('orders.recycle-bin');
                Route::get('/orders/{order}', OrderDetail::class)->whereNumber('order')->name('orders.show');
                Route::get('/orders/{order}/edit', OrderEdit::class)->whereNumber('order')->name('orders.edit');
                Route::get('/customers/{customer}/orders', CustomerOrders::class)
                    ->whereNumber('customer')
                    ->name('customers.orders');
            });
    }
}
