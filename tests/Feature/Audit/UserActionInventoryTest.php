<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Http\Middleware\AttributeRequestToUser;
use App\Jobs\EnrichMerchantBrandsJob;
use App\Jobs\ImportCsvTransactionsJob;
use App\Jobs\ResolveMerchantBrandJob;
use App\Jobs\RunTransactionAnalysisJob;
use App\Jobs\SyncRedbarkFeedJob;
use App\Livewire\Hooks\AuditComponentCalls;
use App\Models\AuditEvent;
use App\Models\BankImport;
use App\Models\RedbarkFeed;
use App\Models\User;
use App\Support\Audit\AttributeJobToUser;
use Illuminate\Routing\RedirectController;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Drawer\Utils;

/**
 * @return array<int, string>
 */
function livewireComponentNames(): array
{
    $classes = collect(File::allFiles(app_path('Livewire')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->map(fn ($file): string => 'App\\Livewire\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
        ->filter(fn (string $class): bool => class_exists($class) && is_subclass_of($class, Component::class))
        ->reject(fn (string $class): bool => new ReflectionClass($class)->isAbstract());

    $roots = collect(config('livewire.component_locations', []))
        ->map(fn (string $path): array => ['', $path])
        ->merge(collect(config('livewire.component_namespaces', []))->map(fn (string $path, string $namespace): array => [$namespace.'::', $path]));

    $finder = app('livewire.finder');

    $viewBased = $roots
        ->filter(fn (array $root): bool => is_dir($root[1]))
        ->flatMap(fn (array $root): Collection => collect(File::allFiles($root[1]))
            ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.blade.php'))
            ->flatMap(function ($file) use ($root): array {
                $segments = array_map(
                    fn (string $segment): string => (string) preg_replace('/^\x{26A1}[\x{FE0E}\x{FE0F}]?/u', '', $segment),
                    explode('/', mb_substr($file->getRelativePathname(), 0, -mb_strlen('.blade.php'))),
                );

                $candidates = [$segments];

                if (count($segments) > 1 && in_array(end($segments), ['index', $segments[count($segments) - 2]], true)) {
                    $candidates[] = array_slice($segments, 0, -1);
                }

                return array_map(fn (array $parts): string => $root[0].implode('.', $parts), $candidates);
            }))
        ->unique()
        ->filter(fn (string $name): bool => $finder->resolveSingleFileComponentPath($name) !== null
            || $finder->resolveMultiFileComponentPath($name) !== null);

    return $classes->merge($viewBased)->values()->all();
}

/**
 * @return array<int, string>
 */
function livewireCallableMethods(Component $component): array
{
    $nonActions = ['__construct', '__invoke', 'boot', 'booted', 'mount', 'exception', 'render', 'rendering', 'rendered', 'placeholder', 'scriptSrc'];

    /** @var array<int, string> $methods */
    $methods = Utils::getPublicMethodsDefinedBySubClass($component);

    return collect($methods)
        ->reject(fn (string $method): bool => in_array($method, $nonActions, true)
            || preg_match('/^(hydrate|dehydrate|updating|updated)/', $method) === 1
            || preg_match('/^get.+Property$/', $method) === 1
            || str_contains((string) new ReflectionMethod($component, $method)->getFileName(), '/vendor/')
            || new ReflectionMethod($component, $method)->getAttributes(Computed::class) !== [])
        ->values()
        ->all();
}

test('every callable Livewire method, in class and view-based components, is audited by the hook or explicitly exempt', function () {
    $unaudited = [];

    foreach (livewireComponentNames() as $name) {
        $component = app('livewire')->new($name);

        foreach (livewireCallableMethods($component) as $method) {
            if (AuditComponentCalls::auditedAction($component, $method) === null) {
                $unaudited[] = $name.'::'.$method;
            }
        }
    }

    expect($unaudited)->toEqualCanonicalizing([
        'App\\Livewire\\ImportBank::pollStatus',
        'App\\Livewire\\TransactionList::pollMerchantBrands',
    ]);
});

test('the setup journey actions are recorded under their expected audit names', function () {
    $expected = [
        'connect-bank' => ['connect'],
        'redbark-account-setup' => ['save'],
        'confirm-pay-cycle-step' => ['confirm'],
        'gmail-connection' => ['connect', 'disconnect'],
        'import-bank' => ['confirmImport'],
        'reconcile-statement' => ['reconcile'],
        'analysis-suggestions' => ['acceptUserRule', 'rejectSuggestion'],
        'transaction-modal' => ['save'],
        'pages::settings.providers' => ['save', 'syncNow', 'disconnect'],
        'pages::settings.pay-cycle' => ['save'],
    ];

    foreach ($expected as $name => $methods) {
        $component = app('livewire')->new($name);

        foreach ($methods as $method) {
            expect(AuditComponentCalls::auditedAction($component, $method))->toBe('livewire.'.$name.'.'.$method);
        }
    }
});

test('every app write route is named, in the inventory, and passes through the audit middleware', function () {
    $inventory = [
        'login.store',
        'logout',
        'password.email',
        'password.update',
        'password.confirm.store',
        'register.store',
        'two-factor.login.store',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.regenerate-recovery-codes',
        'verification.send',
    ];
    $infrastructurePrefixes = ['horizon.', 'boost.', 'storage.'];

    $writeRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (IlluminateRoute $route): bool => array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $route->methods()) !== [])
        ->reject(fn (IlluminateRoute $route): bool => mb_ltrim($route->getActionName(), '\\') === RedirectController::class)
        ->reject(fn (IlluminateRoute $route): bool => is_string($route->getName()) && (
            str_contains($route->getName(), 'livewire')
            || collect($infrastructurePrefixes)->contains(fn (string $prefix): bool => str_starts_with($route->getName(), $prefix))
        ))
        ->values();

    $unnamed = $writeRoutes->filter(fn (IlluminateRoute $route): bool => $route->getName() === null)
        ->map(fn (IlluminateRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    $unaudited = $writeRoutes
        ->reject(fn (IlluminateRoute $route): bool => in_array(AttributeRequestToUser::class, app('router')->gatherRouteMiddleware($route), true))
        ->map(fn (IlluminateRoute $route): ?string => $route->getName())
        ->values()
        ->all();

    expect($unnamed)->toBe([])
        ->and($unaudited)->toBe([])
        ->and($writeRoutes->map(fn (IlluminateRoute $route): ?string => $route->getName())->all())->toEqualCanonicalizing($inventory);
});

test('every queued job is in the inventory and carries the audit middleware as declared', function () {
    $user = User::factory()->create();

    $jobs = [
        EnrichMerchantBrandsJob::class => [new EnrichMerchantBrandsJob($user), false],
        ResolveMerchantBrandJob::class => [new ResolveMerchantBrandJob($user, 'merchant'), false],
        ImportCsvTransactionsJob::class => [new ImportCsvTransactionsJob(BankImport::factory()->create()), true],
        RunTransactionAnalysisJob::class => [new RunTransactionAnalysisJob($user), true],
        SyncRedbarkFeedJob::class => [new SyncRedbarkFeedJob(RedbarkFeed::factory()->create()), true],
    ];

    $discovered = collect(File::allFiles(app_path('Jobs')))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->map(fn ($file): string => 'App\\Jobs\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
        ->filter(fn (string $class): bool => class_exists($class) && ! new ReflectionClass($class)->isAbstract())
        ->values()
        ->all();

    expect($discovered)->toEqualCanonicalizing(array_keys($jobs));

    foreach ($jobs as $class => [$job, $audited]) {
        $attribution = collect($job->middleware())->first(fn (object $middleware): bool => $middleware instanceof AttributeJobToUser);

        expect($attribution)->toBeInstanceOf(AttributeJobToUser::class, $class.' has no AttributeJobToUser middleware');

        $attribution->handle($job, fn () => null);

        expect(AuditEvent::query()->where('action', 'job.'.class_basename($class))->exists())
            ->toBe($audited, $class.' audit behaviour differs from the inventory');
    }
});
