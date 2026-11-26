<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\TagController;
use Illuminate\Support\Facades\Route;

Route::get('tags', [TagController::class, 'index'])->name('tags.index');
Route::post('tags', [TagController::class, 'store'])->name('tags.store');
Route::get('tags/{id}', [TagController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('tags.show');
Route::match(['put', 'patch'], 'tags/{id}', [TagController::class, 'update'])->where('id', '[1-9][0-9]{0,17}')->name('tags.update');
Route::delete('tags/{id}', [TagController::class, 'destroy'])->where('id', '[1-9][0-9]{0,17}')->name('tags.destroy');
