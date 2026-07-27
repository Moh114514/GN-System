<?php

namespace App\Modules\Agent;

use App\Modules\Agent\Application\Contracts\AgentImportGateway;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Agent\Application\Services\DatabaseAgentImportGateway;
use App\Modules\Agent\Application\Services\DatabaseAgentReferenceReader;
use Illuminate\Support\ServiceProvider;

class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AgentImportGateway::class, DatabaseAgentImportGateway::class);
        $this->app->bind(AgentReferenceReader::class, DatabaseAgentReferenceReader::class);
    }
}
