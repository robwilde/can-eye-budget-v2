<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ImportController;
use Illuminate\Support\Facades\Route;

Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
Route::get('imports/{id}', [ImportController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('imports.show');
