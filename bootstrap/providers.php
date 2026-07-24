<?php

use App\Modules\Agent\AgentServiceProvider;
use App\Modules\Audit\AuditServiceProvider;
use App\Modules\Auth\AuthServiceProvider;
use App\Modules\Config\ConfigServiceProvider;
use App\Modules\Customer\CustomerServiceProvider;
use App\Modules\Order\OrderServiceProvider;
use App\Modules\Reminder\ReminderServiceProvider;
use App\Modules\Report\ReportServiceProvider;
use App\Modules\Settlement\SettlementServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    AuthServiceProvider::class,
    CustomerServiceProvider::class,
    AgentServiceProvider::class,
    OrderServiceProvider::class,
    SettlementServiceProvider::class,
    ReminderServiceProvider::class,
    ReportServiceProvider::class,
    ConfigServiceProvider::class,
    AuditServiceProvider::class,
];
