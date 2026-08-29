<?php

use App\Modules\Auth\Presentation\Http\Controllers\AccountInvitationController;
use App\Modules\Auth\Presentation\Http\Controllers\AccountPasswordResetController;
use App\Modules\Auth\Presentation\Http\Controllers\UserImpersonationController;
use App\Modules\Report\Presentation\Livewire\Dashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check()
    ? redirect()->route('dashboard')
    : redirect()->route('login'))->name('home');

Route::middleware(['throttle:10,1'])->group(function (): void {
    Route::get('account/invitation/accepted', [AccountInvitationController::class, 'accepted'])
        ->name('account.invitation.accepted');
    Route::get('account/invitation/{token}', [AccountInvitationController::class, 'create'])
        ->name('account.invitation');
    Route::post('account/invitation/{token}', [AccountInvitationController::class, 'store'])
        ->name('account.invitation.store');
    Route::get('account/password-reset/{token}', [AccountPasswordResetController::class, 'create'])
        ->name('account.password-reset');
    Route::post('account/password-reset/{token}', [AccountPasswordResetController::class, 'store'])
        ->name('account.password-reset.store');
});

Route::middleware(['auth', 'verified', 'super-admin.2fa'])->group(function () {
    Route::get('dashboard', Dashboard::class)->name('dashboard');
});

Route::middleware(['auth', 'verified'])->prefix('_test')->group(function (): void {
    Route::post('impersonation', [UserImpersonationController::class, 'store'])
        ->name('test.impersonation.store');
    Route::delete('impersonation', [UserImpersonationController::class, 'destroy'])
        ->name('test.impersonation.destroy');
});

require __DIR__.'/settings.php';
