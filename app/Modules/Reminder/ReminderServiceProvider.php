<?php

namespace App\Modules\Reminder;

use App\Modules\Reminder\Application\Contracts\AppointmentReminderGateway;
use App\Modules\Reminder\Application\Contracts\CustomerFollowupGateway;
use App\Modules\Reminder\Application\Contracts\FollowupImportGateway;
use App\Modules\Reminder\Application\Contracts\OrderReminderReader;
use App\Modules\Reminder\Application\Contracts\ReportReminderReader;
use App\Modules\Reminder\Application\Contracts\StaffNotificationSender;
use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use App\Modules\Reminder\Application\Services\DatabaseAppointmentReminderGateway;
use App\Modules\Reminder\Application\Services\DatabaseCustomerFollowupGateway;
use App\Modules\Reminder\Application\Services\DatabaseFollowupImportGateway;
use App\Modules\Reminder\Application\Services\DatabaseOrderReminderReader;
use App\Modules\Reminder\Application\Services\DatabaseReportReminderReader;
use App\Modules\Reminder\Application\Services\DatabaseTreatmentReminderGateway;
use App\Modules\Reminder\Infrastructure\Notifications\DingTalkClient;
use App\Modules\Reminder\Presentation\Livewire\ReminderCenter;
use App\Modules\Reminder\Presentation\Livewire\ReminderConfiguration;
use App\Modules\Reminder\Presentation\Livewire\ReminderCreate;
use App\Modules\Reminder\Presentation\Livewire\ReminderHistory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ReminderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FollowupImportGateway::class, DatabaseFollowupImportGateway::class);
        $this->app->bind(OrderReminderReader::class, DatabaseOrderReminderReader::class);
        $this->app->bind(CustomerFollowupGateway::class, DatabaseCustomerFollowupGateway::class);
        $this->app->bind(TreatmentReminderGateway::class, DatabaseTreatmentReminderGateway::class);
        $this->app->bind(AppointmentReminderGateway::class, DatabaseAppointmentReminderGateway::class);
        $this->app->bind(StaffNotificationSender::class, DingTalkClient::class);
        $this->app->bind(ReportReminderReader::class, DatabaseReportReminderReader::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'super-admin.2fa'])->group(function (): void {
            Route::get('/reminders', ReminderCenter::class)->name('reminders.index');
            Route::get('/reminders/create', ReminderCreate::class)->name('reminders.create');
            Route::get('/reminders/history', ReminderHistory::class)->name('reminders.history');
            Route::middleware('super-admin')->get('/admin/reminder-configuration', ReminderConfiguration::class)
                ->name('reminder-configuration.index');
        });
    }
}
