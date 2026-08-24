<?php

namespace App\Modules\Agent;

use App\Modules\Agent\Application\Contracts\AgentBusinessGroupAssignmentGateway;
use App\Modules\Agent\Application\Contracts\AgentCommissionContextReader;
use App\Modules\Agent\Application\Contracts\AgentImportGateway;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Agent\Application\Contracts\ConfigurationHistoryGateway;
use App\Modules\Agent\Application\Contracts\ReferenceConfigurationImportGateway;
use App\Modules\Agent\Application\Contracts\ReportAgentReader;
use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Agent\Application\Services\DatabaseAgentBusinessGroupAssignmentGateway;
use App\Modules\Agent\Application\Services\DatabaseAgentCommissionContextReader;
use App\Modules\Agent\Application\Services\DatabaseAgentImportGateway;
use App\Modules\Agent\Application\Services\DatabaseAgentReferenceReader;
use App\Modules\Agent\Application\Services\DatabaseConfigurationHistoryGateway;
use App\Modules\Agent\Application\Services\DatabaseReferenceConfigurationImportGateway;
use App\Modules\Agent\Application\Services\DatabaseReportAgentReader;
use App\Modules\Agent\Application\Services\DatabaseSettlementAgentGateway;
use App\Modules\Agent\Presentation\Livewire\AgentConfiguration;
use App\Modules\Agent\Presentation\Livewire\AgentDetail;
use App\Modules\Agent\Presentation\Livewire\AgentForm;
use App\Modules\Agent\Presentation\Livewire\AgentList;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AgentImportGateway::class, DatabaseAgentImportGateway::class);
        $this->app->bind(AgentReferenceReader::class, DatabaseAgentReferenceReader::class);
        $this->app->bind(ReferenceConfigurationImportGateway::class, DatabaseReferenceConfigurationImportGateway::class);
        $this->app->bind(AgentCommissionContextReader::class, DatabaseAgentCommissionContextReader::class);
        $this->app->bind(AgentBusinessGroupAssignmentGateway::class, DatabaseAgentBusinessGroupAssignmentGateway::class);
        $this->app->bind(SettlementAgentGateway::class, DatabaseSettlementAgentGateway::class);
        $this->app->bind(ReportAgentReader::class, DatabaseReportAgentReader::class);
        $this->app->bind(ConfigurationHistoryGateway::class, DatabaseConfigurationHistoryGateway::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'super-admin', 'super-admin.2fa'])->group(function (): void {
            Route::get('/agents', AgentList::class)->name('agents.index');
            Route::get('/agents/create', AgentForm::class)->name('agents.create');
            Route::get('/agents/{agent}', AgentDetail::class)->whereNumber('agent')->name('agents.show');
            Route::get('/agents/{agent}/edit', AgentForm::class)->whereNumber('agent')->name('agents.edit');
            Route::get('/admin/agent-configuration', AgentConfiguration::class)->name('agent-configuration.index');
        });
    }
}
