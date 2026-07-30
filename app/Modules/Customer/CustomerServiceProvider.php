<?php

namespace App\Modules\Customer;

use App\Modules\Customer\Application\Contracts\AgentCustomerPortfolioReader;
use App\Modules\Customer\Application\Contracts\CustomerImportGateway;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Customer\Application\Contracts\ReferenceConfigurationImportGateway;
use App\Modules\Customer\Application\Contracts\ReminderCustomerReader;
use App\Modules\Customer\Application\Services\DatabaseAgentCustomerPortfolioReader;
use App\Modules\Customer\Application\Services\DatabaseCustomerImportGateway;
use App\Modules\Customer\Application\Services\DatabaseCustomerOrderReferenceReader;
use App\Modules\Customer\Application\Services\DatabaseReferenceConfigurationImportGateway;
use App\Modules\Customer\Application\Services\DatabaseReminderCustomerReader;
use App\Modules\Customer\Presentation\Livewire\CustomerDetail;
use App\Modules\Customer\Presentation\Livewire\CustomerForm;
use App\Modules\Customer\Presentation\Livewire\CustomerList;
use App\Modules\Customer\Presentation\Livewire\CustomerStatusConfiguration;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CustomerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CustomerImportGateway::class, DatabaseCustomerImportGateway::class);
        $this->app->bind(CustomerOrderReferenceReader::class, DatabaseCustomerOrderReferenceReader::class);
        $this->app->bind(AgentCustomerPortfolioReader::class, DatabaseAgentCustomerPortfolioReader::class);
        $this->app->bind(ReminderCustomerReader::class, DatabaseReminderCustomerReader::class);
        $this->app->bind(ReferenceConfigurationImportGateway::class, DatabaseReferenceConfigurationImportGateway::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'super-admin.2fa'])->group(function (): void {
            Route::get('/customers', CustomerList::class)->name('customers.index');
            Route::get('/customers/create', CustomerForm::class)->name('customers.create');
            Route::get('/customers/{customer}', CustomerDetail::class)->whereNumber('customer')->name('customers.show');
            Route::get('/customers/{customer}/edit', CustomerForm::class)->whereNumber('customer')->name('customers.edit');
            Route::middleware('super-admin')->get('/admin/customer-statuses', CustomerStatusConfiguration::class)
                ->name('customer-statuses.index');
        });
    }
}
