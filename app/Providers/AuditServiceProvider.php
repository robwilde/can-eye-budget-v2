<?php

declare(strict_types=1);

namespace App\Providers;

use App\Livewire\Hooks\AuditComponentCalls;
use App\Models\AuditEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Livewire::componentHook(AuditComponentCalls::class);
    }

    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('model:prune', ['--model' => [AuditEvent::class]])->daily();
        });
    }
}
