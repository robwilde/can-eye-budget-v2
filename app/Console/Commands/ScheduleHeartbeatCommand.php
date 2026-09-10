<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class ScheduleHeartbeatCommand extends Command
{
    public const int TTL_SECONDS = 180;

    protected $signature = 'app:schedule-heartbeat';

    protected $description = 'Record that this container\'s scheduler loop is still ticking';

    public static function cacheKey(): string
    {
        return 'health:schedule-heartbeat:'.Str::slug((string) gethostname());
    }

    public function handle(): int
    {
        Cache::put(self::cacheKey(), now()->toIso8601String(), self::TTL_SECONDS);

        return self::SUCCESS;
    }
}
