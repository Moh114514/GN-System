<?php

namespace App\Modules\Auth;

use App\Models\User;
use App\Modules\Auth\Application\Contracts\ReportUserReader;
use App\Modules\Auth\Application\Contracts\UserManagementGateway;
use App\Modules\Auth\Application\Services\DatabaseReportUserReader;
use App\Modules\Auth\Application\Services\DatabaseUserManagementGateway;
use App\Modules\Auth\Console\CreateAdminCommand;
use App\Modules\Auth\Console\DisableAdminCommand;
use App\Modules\Auth\Console\EnableAdminCommand;
use App\Modules\Auth\Console\ListAdminsCommand;
use App\Modules\Auth\Console\ResetAdminPasswordCommand;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReportUserReader::class, DatabaseReportUserReader::class);
        $this->app->bind(UserManagementGateway::class, DatabaseUserManagementGateway::class);
    }

    public function boot(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if ($event->user instanceof User && request()->hasSession()) {
                request()->session()->put('auth.session_version', $event->user->session_version);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateAdminCommand::class,
                ListAdminsCommand::class,
                DisableAdminCommand::class,
                EnableAdminCommand::class,
                ResetAdminPasswordCommand::class,
            ]);
        }
    }
}
