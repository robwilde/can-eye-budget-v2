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
