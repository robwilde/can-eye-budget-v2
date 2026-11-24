<?php

declare(strict_types=1);

use App\Enums\RefreshStatus;
use App\Jobs\SyncRedbarkFeedJob;
use App\Models\RedbarkSyncLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(static fn () => RedbarkSyncLog::query()
    ->where('status', RefreshStatus::Pending)
    ->where('created_at', '<', now()->subSeconds(SyncRedbarkFeedJob::UNIQUE_FOR + 60))
    ->update(['status' => RefreshStatus::Failed]))
    ->everyFiveMinutes()
    ->name('redbark:fail-stuck-sync-logs')
    ->withoutOverlapping();

Schedule::command('app:expire-redbark-holds')
    ->dailyAt('03:15')
    ->name('redbark:expire-holds')
    ->withoutOverlapping();

Schedule::command('app:settle-pending-payments')
    ->everyFifteenMinutes()
    ->name('payments:settle-pending')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:recurring-maintenance')
    ->dailyAt('02:30')
    ->name('recurring:maintenance')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:scan-bnpl-emails')
    ->dailyAt('04:00')
    ->name('bnpl:scan-emails')
    ->withoutOverlapping();

Schedule::command('exchange-rates:fetch')
    ->dailyAt('05:00')
    ->name('exchange-rates:fetch')
    ->withoutOverlapping();

Schedule::command('insights:generate')
    ->dailyAt('02:00')
    ->name('insights:generate')
    ->withoutOverlapping();

Schedule::command('securities:sync-prices')
    ->dailyAt('06:00')
    ->name('securities:sync-prices')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:send-bill-alerts')
    ->dailyAt('08:00')
    ->name('bill-alerts:send')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:generate-planned-occurrences')
    ->dailyAt('03:00')
    ->name('planned-occurrences:generate')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('goals:maintain')
    ->dailyAt('02:00')
    ->name('goals:maintain')
    ->withoutOverlapping();

if (config('budget.redbark.nightly_sync_time') !== null) {
    Schedule::command('app:sync-redbark-feeds')
        ->dailyAt((string) config('budget.redbark.nightly_sync_time'))
        ->timezone((string) config('budget.redbark.nightly_sync_timezone'))
        ->name('redbark:nightly-sync')
        ->withoutOverlapping();
}

Schedule::call(static fn () => App\Models\DataExport::failStuck())
    ->everyFiveMinutes()
    ->name('data-exports:fail-stuck')
    ->withoutOverlapping();
