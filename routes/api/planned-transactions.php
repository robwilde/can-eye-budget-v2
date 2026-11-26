<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ManagePlannedTransactionController;
use Illuminate\Support\Facades\Route;

Route::post('planned-transactions', [ManagePlannedTransactionController::class, 'store'])->name('planned-transactions.store');
Route::match(['put', 'patch'], 'planned-transactions/{id}', [ManagePlannedTransactionController::class, 'update'])->where('id', '[1-9][0-9]{0,17}')->name('planned-transactions.update');
Route::delete('planned-transactions/{id}', [ManagePlannedTransactionController::class, 'destroy'])->where('id', '[1-9][0-9]{0,17}')->name('planned-transactions.destroy');
