<?php

declare(strict_types=1);

use App\Http\Controllers\HealthCheckController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

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
        // Scheduler liveness: write a heartbeat file every minute for the healthcheck to verify
        // freshness without booting Laravel. Threshold 120s tolerates the scheduler's 60s tick
        // interval + jitter within Docker's 15s probe interval, 5 retries (75s worst-case).
        // runInBackground() decouples the beat from unrelated scheduled work: a single
        // schedule:run executes due foreground events sequentially, so a slow sibling
        // (app:sync-redbark-feeds) would otherwise delay that run's heartbeat by its own
        // runtime. schedule:work does start each minute's schedule:run as a separate
        // concurrent process, so this is defence in depth rather than the only barrier,
        // but it makes the beat's timing depend on the scheduler tick alone.
        $schedule->command('scheduler:heartbeat')
            ->everyMinute()
            ->runInBackground();
        // Basiq is stood down in favour of the Redbark feed: its commands still exist and
        // can be run by hand, they are just no longer scheduled.
        $schedule->command('app:sync-redbark-feeds')->everySixHours()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Route every exception Laravel decides to report through to Sentry. Registering
        // via Integration::handles() (rather than a bespoke reportable callback) keeps
        // Laravel's own shouldntReport list authoritative, so validation, authentication
        // and 4xx HTTP exceptions stay out of Sentry. All three container roles share this
        // hook: web requests, Horizon queue workers and scheduled commands all report
        // through the same handler.
        Integration::handles($exceptions);
    })->create();
