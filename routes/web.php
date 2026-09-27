<?php

declare(strict_types=1);

use App\Livewire\ReconcileStatement;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::view('smoke-test', 'smoke-test')->name('smoke-test');
    Route::view('import-bank', 'import-bank')->name('import-bank');
    Route::view('transactions', 'transactions')->name('transactions');
    Route::view('calendar', 'calendar')->name('calendar');
    Route::view('reports', 'reports')->name('reports');
    Route::view('accounts', 'accounts')->name('accounts');
    Route::view('rules', 'rules')->name('rules');
    // Transient page reached from the providers panel, so deliberately not in the sidebar.
    Route::view('redbark/setup', 'redbark-setup')->name('redbark.setup');
    Route::get('accounts/{account}/reconcile', ReconcileStatement::class)->name('accounts.reconcile');
});

require __DIR__.'/settings.php';
require __DIR__.'/fortify.php';
