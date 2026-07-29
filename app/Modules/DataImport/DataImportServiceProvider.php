<?php

namespace App\Modules\DataImport;

use App\Modules\DataImport\Console\PurgeExpiredImportsCommand;
use App\Modules\DataImport\Presentation\Livewire\ImportManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DataImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config()->set('livewire.temporary_file_upload.rules', [
            'required',
            'file',
            'max:'.config('data-import.max_file_kilobytes'),
        ]);
    }

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'verified', 'super-admin', 'super-admin.2fa'])
            ->get('/admin/data-imports', ImportManager::class)
            ->name('data-imports.index');

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeExpiredImportsCommand::class]);
        }
    }
}
