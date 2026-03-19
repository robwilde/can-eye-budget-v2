<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::view('smoke-test', 'smoke-test')->name('smoke-test');
    Route::view('connect-bank', 'connect-bank')->name('connect-bank');
});

require __DIR__.'/settings.php';
