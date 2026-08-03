<?php

namespace App\Modules\Audit;

use App\Modules\Audit\Application\Contracts\AuditLogReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Application\Services\DatabaseAuditLogReader;
use App\Modules\Audit\Application\Services\SpatieAuditRecorder;
use App\Modules\Audit\Presentation\Livewire\AuditLogIndex;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditRecorder::class, SpatieAuditRecorder::class);
        $this->app->bind(AuditLogReader::class, DatabaseAuditLogReader::class);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'super-admin', 'super-admin.2fa'])->group(function (): void {
            Route::get('/admin/audit-logs', AuditLogIndex::class)->name('audit-logs.index');
        });
    }
}
