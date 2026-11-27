<?php

declare(strict_types=1);

use App\Console\FeedSyncSchedule;
use App\Exceptions\PlanWriteRefusedException;
use App\Exceptions\TransactionWriteRefusedException;
use App\Http\Controllers\HealthCheckController;
use App\Http\Middleware\AttributeRequestToUser;
use App\Http\Middleware\EnsureApiEnabled;
use App\Http\Middleware\EnsureMcpEnabled;
use App\Http\Middleware\EnsurePreviewFeatures;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottleApiByAddress;
use App\Services\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::get('/up', HealthCheckController::class)
                ->middleware('throttle:60,1');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->preventRequestsDuringMaintenance(except: ['up']);

        $middleware->alias([
            'preview' => EnsurePreviewFeatures::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        $middleware->web(append: [SetLocale::class, AttributeRequestToUser::class]);
        $middleware->api(append: [AttributeRequestToUser::class]);

        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ThrottleApiByAddress::class);
        $middleware->prependToPriorityList(before: ThrottleApiByAddress::class, prepend: EnsureApiEnabled::class);
        $middleware->prependToPriorityList(before: EnsureApiEnabled::class, prepend: EnsureMcpEnabled::class);

        $middleware->appendToPriorityList(
            after: StartSession::class,
            append: AttributeRequestToUser::class,
        );
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
        $schedule->command('horizon:status')
            ->everyFiveMinutes()
            ->sentryMonitor(
                monitorSlug: 'horizon-status',
                checkInMargin: 5,
                maxRuntime: 1,
                failureIssueThreshold: 2,
                recoveryThreshold: 1,
            );
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
        FeedSyncSchedule::register($schedule, app(InstanceSettings::class));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['invite_code']);

        $exceptions->dontReport([TransactionWriteRefusedException::class, PlanWriteRefusedException::class]);

        $exceptions->render(static fn (TransactionWriteRefusedException $exception, Request $request) => $request->is('api/*')
            ? response()->json(['message' => $exception->getMessage()], 422)
            : null);

        $exceptions->shouldRenderJsonWhen(static fn (Request $request): bool => $request->is('api/*', 'mcp') || $request->expectsJson());

        // Route every exception Laravel decides to report through to Sentry. Registering
        // via Integration::handles() (rather than a bespoke reportable callback) keeps
        // Laravel's own shouldntReport list authoritative, so validation, authentication
        // and 4xx HTTP exceptions stay out of Sentry. All three container roles share this
        // hook: web requests, Horizon queue workers and scheduled commands all report
        // through the same handler.
        Integration::handles($exceptions);
    })->create();
