<?php

declare(strict_types=1);

use App\Enums\RefreshStatus;
use App\Jobs\SyncTransactionsJob;
use App\Models\BasiqRefreshLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(static fn () => BasiqRefreshLog::query()
    ->where('status', RefreshStatus::Pending)
    ->where('created_at', '<', now()->subSeconds(SyncTransactionsJob::UNIQUE_FOR + 60))
    ->update(['status' => RefreshStatus::Failed]))
    ->everyFiveMinutes()
    ->name('basiq:fail-stuck-refresh-logs')
    ->withoutOverlapping();
