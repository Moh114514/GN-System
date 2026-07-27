<?php

namespace App\Modules\Reminder;

use App\Modules\Reminder\Application\Contracts\CustomerFollowupGateway;
use App\Modules\Reminder\Application\Contracts\FollowupImportGateway;
use App\Modules\Reminder\Application\Services\DatabaseCustomerFollowupGateway;
use App\Modules\Reminder\Application\Services\DatabaseFollowupImportGateway;
use Illuminate\Support\ServiceProvider;

class ReminderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FollowupImportGateway::class, DatabaseFollowupImportGateway::class);
        $this->app->bind(CustomerFollowupGateway::class, DatabaseCustomerFollowupGateway::class);
    }
}
