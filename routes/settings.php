<?php

use App\Http\Controllers\UpdateLocaleController;
use Illuminate\Support\Facades\Route;

Route::post('language', UpdateLocaleController::class)->name('locale.update');

Route::middleware(['auth', 'super-admin.2fa'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::view('settings/language', 'pages.settings.language')->name('language.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});
