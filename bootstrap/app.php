<?php

declare(strict_types=1);

use App\Http\Controllers\HealthCheckController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::get('/up', HealthCheckController::class)
                ->middleware('throttle:60,1');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->preventRequestsDuringMaintenance(except: ['up']);

        $middleware->validateCsrfTokens(except: ['webhooks/basiq']);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
        // Basiq is stood down in favour of the Redbark feed: its commands still exist and
        // can be run by hand, they are just no longer scheduled.
        $schedule->command('app:sync-redbark-feeds')->everySixHours()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
