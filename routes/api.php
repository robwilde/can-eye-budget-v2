<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\PayeeController;
use App\Http\Controllers\Api\V1\PlannedTransactionController;
use App\Http\Controllers\Api\V1\RejectedTransferController;
use App\Http\Controllers\Api\V1\RuleController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SyncFeedController;
use App\Http\Controllers\Api\V1\SyncLogController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\TransactionSplitController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\UnmatchedTransferController;
use App\Http\Middleware\EnsureApiEnabled;
use App\Http\Middleware\EnsureApiKeyScope;
use App\Http\Middleware\ThrottleApiByAddress;
use Illuminate\Support\Facades\Route;

/*
| Token-guarded JSON API (prefix api/v1, see bootstrap/app.php). Order matters: the flag answers 404 first,
| the per-IP limiter runs before authentication so token guessing is throttled, then the token is
| verified, the per-key limiter applied, and the read ability checked (flag and address limiter are listed in
| the priority list in bootstrap/app.php so Laravel's sorting keeps them ahead of authentication).
*/
Route::middleware([EnsureApiEnabled::class, ThrottleApiByAddress::class, 'auth:sanctum', 'abilities:read', EnsureApiKeyScope::class, 'throttle:api-key'])
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/{id}', [AccountController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('accounts.show');
        Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('transactions/{id}', [TransactionController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('transactions.show');
        Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
        Route::match(['put', 'patch'], 'transactions/{id}', [TransactionController::class, 'update'])->where('id', '[1-9][0-9]{0,17}')->name('transactions.update');
        Route::delete('transactions/{id}', [TransactionController::class, 'destroy'])->where('id', '[1-9][0-9]{0,17}')->name('transactions.destroy');
        Route::put('transactions/{id}/splits', [TransactionSplitController::class, 'update'])->where('id', '[1-9][0-9]{0,17}')->name('transactions.splits.update');
        Route::delete('transactions/{id}/splits', [TransactionSplitController::class, 'destroy'])->where('id', '[1-9][0-9]{0,17}')->name('transactions.splits.destroy');
        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('categories/{id}', [CategoryController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('categories.show');
        Route::get('payees', [PayeeController::class, 'index'])->name('payees.index');
        Route::get('payees/{id}', [PayeeController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('payees.show');
        Route::get('rules', [RuleController::class, 'index'])->name('rules.index');
        Route::get('rules/{id}', [RuleController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('rules.show');
        Route::get('budgets', [BudgetController::class, 'index'])->name('budgets.index');
        Route::get('budgets/{id}', [BudgetController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('budgets.show');
        Route::get('planned-transactions', [PlannedTransactionController::class, 'index'])->name('planned-transactions.index');
        Route::get('planned-transactions/{id}', [PlannedTransactionController::class, 'show'])->where('id', '[1-9][0-9]{0,17}')->name('planned-transactions.show');
        Route::get('transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::get('transfers/unmatched', [UnmatchedTransferController::class, 'index'])->name('transfers.unmatched');
        Route::get('transfers/rejected', [RejectedTransferController::class, 'index'])->name('transfers.rejected');
        Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::get('feeds', [FeedController::class, 'index'])->name('feeds.index');
        Route::post('feeds/sync', SyncFeedController::class)->name('feeds.sync');
        require __DIR__.'/api/tags.php';
        require __DIR__.'/api/payees.php';
        require __DIR__.'/api/planned-transactions.php';
        require __DIR__.'/api/imports.php';

        Route::get('feeds/sync-logs', [SyncLogController::class, 'index'])->name('feeds.sync-logs');
    });
