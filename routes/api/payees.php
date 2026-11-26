<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\StorePayeeController;
use Illuminate\Support\Facades\Route;

Route::post('payees', StorePayeeController::class)->name('payees.store');
