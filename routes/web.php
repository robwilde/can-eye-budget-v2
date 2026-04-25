<?php

declare(strict_types=1);

use App\Http\Controllers\BasiqCallbackController;
use App\Http\Controllers\BasiqWebhookController;
use App\Http\Middleware\VerifyBasiqWebhook;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::post('webhooks/basiq', BasiqWebhookController::class)
    ->middleware(VerifyBasiqWebhook::class)
    ->name('webhooks.basiq');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::view('smoke-test', 'smoke-test')->name('smoke-test');
    Route::view('connect-bank', 'connect-bank')->name('connect-bank');
    Route::view('import-bank', 'import-bank')->name('import-bank');
    Route::view('transactions', 'transactions')->name('transactions');
    Route::view('calendar', 'calendar')->name('calendar');
    Route::view('accounts', 'accounts')->name('accounts');
    Route::view('rules', 'rules')->name('rules');
    Route::get('basiq/callback', BasiqCallbackController::class)->name('basiq.callback');
});

require __DIR__.'/settings.php';
