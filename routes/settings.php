<?php

declare(strict_types=1);

use App\Http\Controllers\DataExportDownloadController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/password', 'pages::settings.password')->name('user-password.edit');
    Route::livewire('settings/pay-cycle', 'pages::settings.pay-cycle')->name('pay-cycle.edit');
    Route::livewire('settings/currency', 'pages::settings.currency')->name('currency.edit');
    Route::livewire('settings/preferences', 'pages::settings.preferences')->name('preferences.edit');
    Route::livewire('settings/providers', 'pages::settings.providers')->name('providers.edit');
    Route::livewire('settings/exports', 'pages::settings.exports')->name('exports.edit');
    Route::get('settings/exports/{export}/download', DataExportDownloadController::class)->where('export', '[0-9]{1,18}')->name('exports.download');

    Route::livewire('settings/two-factor', 'pages::settings.two-factor')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                static fn () => ['password.confirm'],
                static fn () => [],
            ),
        )
        ->name('two-factor.show');
});
