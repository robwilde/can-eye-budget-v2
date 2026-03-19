<?php

declare(strict_types=1);

use App\Http\Controllers\BasiqCallbackController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::view('smoke-test', 'smoke-test')->name('smoke-test');
    Route::view('connect-bank', 'connect-bank')->name('connect-bank');
    Route::get('basiq/callback', BasiqCallbackController::class)->name('basiq.callback');
});

require __DIR__.'/settings.php';
