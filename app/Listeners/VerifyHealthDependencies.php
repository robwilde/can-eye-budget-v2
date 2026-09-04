<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

final class VerifyHealthDependencies
{
    public function handle(DiagnosingHealth $event): void
    {
        DB::connection()->select('select 1');

        Redis::connection()->ping();
    }
}
