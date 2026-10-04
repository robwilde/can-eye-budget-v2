<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Contracts\ContextDevServiceContract;
use App\DTOs\MerchantBrandData;
use App\Enums\AuditOutcome;
use App\Enums\TransactionDirection;
use App\Jobs\EnrichMerchantBrandsJob;
use App\Jobs\ResolveMerchantBrandJob;
use App\Jobs\RunTransactionAnalysisJob;
use App\Livewire\Attributes\NotAudited;
use App\Livewire\ConnectBank;
use App\Models\AuditEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Audit\AttributeJobToUser;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Livewire;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;

beforeEach(function () {
    SentrySdk::getCurrentHub()->bindClient(ClientBuilder::create([])->getClient());
    SentrySdk::getCurrentHub()->configureScope(fn ($scope) => $scope->clear());
    Http::preventStrayRequests();
    Http::fake(['*/connections' => Http::response(['data' => []])]);
});

function auditEverythingRecorded(): string
{
    $breadcrumbs = array_map(
        fn ($breadcrumb) => [$breadcrumb->getMessage(), $breadcrumb->getMetadata()],
        auditSentryEvent()->getBreadcrumbs(),
    );

    return json_encode([
        AuditEvent::query()->get()->toArray(),
        array_map(fn ($record) => [$record->message, $record->context], auditLogRecords()),
        $breadcrumbs,
    ], JSON_THROW_ON_ERROR);
}

test('an authenticated request carries only the user id and a request id into Sentry', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $event = auditSentryEvent();

    expect($event->getUser()?->getId())->toBe((string) $user->id)
        ->and($event->getUser()?->getEmail())->toBeNull()
        ->and($event->getUser()?->getUsername())->toBeNull()
        ->and($event->getUser()?->getIpAddress())->toBeNull()
        ->and($event->getTags())->toHaveKey('request_id');
});

test('an anonymous request leaves no user in Sentry', function () {
    SentrySdk::getCurrentHub()->configureScope(fn ($scope) => $scope->setUser(['id' => '999']));

    $this->get(route('home'))->assertOk();

    expect(auditSentryEvent()->getUser())->toBeNull();
});

test('a mutating request by a signed-in user is audited by route name and outcome', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'));

    $event = AuditEvent::query()->where('action', 'http.logout')->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($event->request_id)->not->toBeNull();
});

test('a mutating request rejected for a bad csrf token is still audited as a failure', function () {
    $user = User::factory()->create();
    $this->app['env'] = 'production';

    $this->actingAs($user)->post(route('logout'), ['_token' => 'forged'])->assertStatus(419);

    $this->app['env'] = 'testing';

    $event = AuditEvent::query()->where('action', 'http.logout')->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Failure);
});

test('a rejected password confirmation is audited as a failure and a correct one as a success', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password');

    $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'password']);

    expect(AuditEvent::query()->where('action', 'http.password.confirm.store')->orderBy('id')->pluck('outcome')->all())
        ->toBe([AuditOutcome::Failure, AuditOutcome::Success]);
});

test('verifying an email address is audited under the verifying user', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($url)->assertRedirect();

    $event = AuditEvent::query()->where('action', 'auth.email_verified')->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

test('reading a page is not audited as an action', function () {
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();

    expect(AuditEvent::query()->where('action', 'like', 'http.%')->count())->toBe(0);
});

test('signing in and out are audited and a failed sign-in names the account by id only', function () {
    $user = User::factory()->create(['email' => 'audit-subject@example.com']);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('logout'));

    $events = AuditEvent::query()->orderBy('id')->get(['action', 'outcome', 'user_id']);

    expect($events->where('action', 'auth.login')->pluck('outcome')->all())->toBe([AuditOutcome::Failure, AuditOutcome::Success])
        ->and($events->where('action', 'auth.login')->pluck('user_id')->unique()->all())->toBe([$user->id])
        ->and($events->pluck('action')->all())->toContain('auth.logout')
        ->and(auditEverythingRecorded())->not->toContain('audit-subject@example.com');
});

test('an invalid two factor code is audited as a failed sign-in for that user', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'not-a-recovery-code'])
        ->assertSessionHasErrors();

    $event = AuditEvent::query()->where('action', 'auth.login')->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Failure);
});

test('a livewire action produces an event naming the component and method, never its arguments', function () {
    Queue::fake();
    $user = User::factory()->create();
    $key = 'rbk_live_0123456789abcdef';

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->set('api_key', $key)
        ->call('connect');

    $event = AuditEvent::query()->where('action', 'livewire.connect-bank.connect')->sole();
    $everything = auditEverythingRecorded();

    expect($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($everything)->not->toContain($key)
        ->and($everything)->not->toContain($user->email)
        ->and($everything)->not->toContain($user->name);
});

test('a livewire action that fails is audited as a failure', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->set('api_key', '')
        ->call('connect')
        ->assertHasErrors('api_key');

    expect(AuditEvent::query()->where('action', 'livewire.connect-bank.connect')->sole()->outcome)
        ->toBe(AuditOutcome::Failure);
});

test('livewire property updates and framework methods are not audited as actions', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(ConnectBank::class)
        ->set('api_key', 'whatever')
        ->call('$refresh');

    expect(AuditEvent::query()->count())->toBe(0);
});

test('a user job runs with the user in Sentry, clears it afterwards and is audited', function () {
    $user = User::factory()->create();
    $seen = null;

    new AttributeJobToUser($user->id)->handle(new stdClass, function () use (&$seen) {
        $seen = auditSentryEvent()->getUser()?->getId();
    });

    $event = AuditEvent::query()->sole();

    expect($seen)->toBe((string) $user->id)
        ->and(auditSentryEvent()->getUser())->toBeNull()
        ->and($event->action)->toBe('job.stdClass')
        ->and($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Success);
});

test('a failing user job is audited as a failure and the exception propagates with the user still bound for reporting', function () {
    $user = User::factory()->create();

    $run = fn () => new AttributeJobToUser($user->id)->handle(new stdClass, fn () => throw new RuntimeException('boom'));

    expect($run)->toThrow(RuntimeException::class, 'boom')
        ->and(AuditEvent::query()->sole()->outcome)->toBe(AuditOutcome::Failure)
        ->and(auditSentryEvent()->getUser()?->getId())->toBe((string) $user->id);
});

test('a failing audit write never hides the exception thrown by the job', function () {
    $user = User::factory()->create();
    AuditEvent::creating(fn () => throw new LogicException('audit store down'));

    $run = fn () => new AttributeJobToUser($user->id)->handle(new stdClass, fn () => throw new RuntimeException('boom'));

    expect($run)->toThrow(RuntimeException::class, 'boom');
});

test('a failing audit write does not fail a job that succeeded', function () {
    $user = User::factory()->create();
    AuditEvent::creating(fn () => throw new LogicException('audit store down'));
    $ran = 0;

    new AttributeJobToUser($user->id)->handle(new stdClass, function () use (&$ran) {
        $ran++;
    });

    expect($ran)->toBe(1);
});

test('a reported audit failure after a successful job still carries the job user and the scope is cleared afterwards', function () {
    $user = User::factory()->create();
    AuditEvent::creating(fn () => throw new LogicException('audit store down'));
    $reportedUser = null;
    app(ExceptionHandler::class)->reportable(function (LogicException $exception) use (&$reportedUser) {
        $reportedUser = auditSentryEvent()->getUser()?->getId();
    })->stop();

    new AttributeJobToUser($user->id)->handle(new stdClass, fn () => null);

    expect($reportedUser)->toBe((string) $user->id)
        ->and(auditSentryEvent()->getUser())->toBeNull();
});

test('a failing audit write does not turn a successful request into an error', function () {
    $user = User::factory()->create();
    AuditEvent::creating(fn () => throw new LogicException('audit store down'));

    $this->actingAs($user)->post(route('logout'))->assertRedirect();
});

test('a failing audit write does not break a successful livewire action', function () {
    Queue::fake();
    $user = User::factory()->create();
    AuditEvent::creating(fn () => throw new LogicException('audit store down'));

    Livewire::actingAs($user)
        ->test(ConnectBank::class)
        ->set('api_key', 'rbk_live_0123456789abcdef')
        ->call('connect')
        ->assertHasNoErrors();
});

test('a failing audit write does not break signing in', function () {
    $user = User::factory()->create();
    AuditEvent::creating(fn () => throw new LogicException('audit store down'));

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

test('a job can be attributed without writing an audit event', function () {
    $user = User::factory()->create();

    new AttributeJobToUser($user->id, audit: false)->handle(new stdClass, fn () => null);

    expect(AuditEvent::query()->count())->toBe(0);
});

test('the real user-triggered analysis job is audited under the owning user', function () {
    $user = User::factory()->create();

    RunTransactionAnalysisJob::dispatchSync($user);

    $event = AuditEvent::query()->where('action', 'job.RunTransactionAnalysisJob')->sole();

    expect($event->user_id)->toBe($user->id);
});

test('a mutating request that throws is audited as a failure and the exception propagates', function () {
    Route::post('/audit-boom', fn () => throw new RuntimeException('boom'))->middleware('web')->name('audit.boom');
    $user = User::factory()->create();

    $this->actingAs($user)->post('/audit-boom')->assertServerError();

    $event = AuditEvent::query()->where('action', 'http.audit.boom')->sole();

    expect($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe(AuditOutcome::Failure);
});

test('a failing audit write never hides the exception thrown by the request', function () {
    Route::post('/audit-boom', fn () => throw new RuntimeException('boom'))->middleware('web')->name('audit.boom');
    AuditEvent::creating(fn () => throw new LogicException('audit store down'));
    $user = User::factory()->create();

    $run = fn () => $this->withoutExceptionHandling()->actingAs($user)->post('/audit-boom');

    expect($run)->toThrow(RuntimeException::class, 'boom');
});

test('registration and password reset are audited under their own actions', function () {
    $user = User::factory()->create();

    event(new Registered($user));
    event(new PasswordReset($user));

    $events = AuditEvent::query()->orderBy('id')->get();

    expect($events->pluck('action')->all())->toBe(['auth.register', 'auth.password_reset'])
        ->and($events->pluck('user_id')->unique()->all())->toBe([$user->id]);
});

test('a client-supplied method name is never audited unless the component defines it', function () {
    $user = User::factory()->create();
    $secret = 'rbk_live_0123456789abcdef';

    $run = fn () => Livewire::actingAs($user)->test(ConnectBank::class)->call($secret);

    expect($run)->toThrow(Exception::class)
        ->and(AuditEvent::query()->count())->toBe(0)
        ->and(auditEverythingRecorded())->not->toContain($secret);
});

test('a merchant brand lookup runs as the owning user in Sentry without an audit event', function () {
    config(['services.context_dev.enrichment_enabled' => true, 'services.context_dev.daily_credit_cap' => 500]);
    $user = User::factory()->create();
    $row = Transaction::factory()->for($user)->create([
        'description' => 'VISA WOOLWORTHS 1234 SYDNEY',
        'direction' => TransactionDirection::Debit,
        'merchant_name' => null,
    ]);
    $seen = null;
    $contextDev = Mockery::mock(ContextDevServiceContract::class);
    $contextDev->shouldReceive('brandFromTransaction')->once()->andReturnUsing(function () use (&$seen) {
        $seen = auditSentryEvent()->getUser()?->getId();

        return new MerchantBrandData(title: 'Woolworths', domain: 'woolworths.com.au', logoUrl: 'https://cdn/w.png');
    });
    app()->instance(ContextDevServiceContract::class, $contextDev);

    dispatch_sync(new ResolveMerchantBrandJob($user, $row->merchant_key));

    expect($seen)->toBe((string) $user->id)
        ->and(auditSentryEvent()->getUser())->toBeNull()
        ->and(AuditEvent::query()->count())->toBe(0);
});

test('a merchant enrichment sweep runs as the owning user in Sentry without an audit event', function () {
    config(['services.context_dev.enrichment_enabled' => true, 'services.context_dev.daily_credit_cap' => 500]);
    Queue::fake([ResolveMerchantBrandJob::class]);
    $user = User::factory()->create();
    Transaction::factory()->for($user)->count(2)->create([
        'description' => 'NETFLIX.COM',
        'direction' => TransactionDirection::Debit,
        'merchant_name' => null,
    ]);
    $seen = null;
    Event::listen(QueryExecuted::class, function () use (&$seen) {
        $seen ??= auditSentryEvent()->getUser()?->getId();
    });

    dispatch_sync(new EnrichMerchantBrandsJob($user));

    expect($seen)->toBe((string) $user->id)
        ->and(auditSentryEvent()->getUser())->toBeNull()
        ->and(AuditEvent::query()->count())->toBe(0);
});

final class AuditProbe extends Component
{
    public bool $explodeOnRender = false;

    public function act(): void {}

    public function breakRender(): void
    {
        $this->explodeOnRender = true;
    }

    #[NotAudited]
    public function poll(): void {}

    #[On('probe-event')]
    public function onProbeEvent(string $secret = ''): void {}

    #[On('quiet-event'), NotAudited]
    public function onQuietEvent(): void {}

    public function render(): string
    {
        if ($this->explodeOnRender) {
            throw new RuntimeException('render failed');
        }

        return '<div></div>';
    }
}

test('a method marked not audited produces no event while a normal action still does', function () {
    $component = Livewire::actingAs(User::factory()->create())->test(AuditProbe::class);

    $component->call('poll')->call('poll');

    expect(AuditEvent::query()->count())->toBe(0)
        ->and(auditLogRecords())->toBeEmpty();

    $component->call('act');

    expect(AuditEvent::query()->pluck('action')->all())->toHaveCount(1)
        ->and(AuditEvent::query()->sole()->action)->toEndWith('.act');
});

test('the wire:poll targets in the app are not audited', function (string $component, string $method) {
    Livewire::actingAs(User::factory()->create())->test($component)->call($method);

    expect(AuditEvent::query()->count())->toBe(0);
})->with([
    'import status poll' => [App\Livewire\ImportBank::class, 'pollStatus'],
    'merchant brand poll' => [App\Livewire\TransactionList::class, 'pollMerchantBrands'],
]);

test('a call that succeeds and then fails to render records exactly one outcome', function () {
    $run = fn () => Livewire::actingAs(User::factory()->create())
        ->test(AuditProbe::class)
        ->call('breakRender');

    expect($run)->toThrow(RuntimeException::class, 'render failed');

    $events = AuditEvent::query()->get();

    expect($events)->toHaveCount(1)
        ->and($events->first()->action)->toEndWith('.breakRender')
        ->and($events->first()->outcome)->toBe(AuditOutcome::Success);
});

test('a component whose combined action name is too long still runs and is simply not audited', function () {
    $longName = 'audit-'.str_repeat('probe-', 13).'component';
    Livewire::component($longName, AuditProbe::class);

    Livewire::actingAs(User::factory()->create())
        ->test($longName)
        ->call('act')
        ->assertOk();
    expect(mb_strlen('livewire.'.$longName.'.act'))->toBeGreaterThan(100)
        ->and(AuditEvent::query()->count())->toBe(0);
});

test('actions on a single-file page component are audited under its namespaced name', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.providers')->call('syncNow');

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe('livewire.pages::settings.providers.syncNow')
        ->and($event->user_id)->toBe($user->id);
});

test('a browser dispatched event is audited under the listener method, never its name or arguments', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(AuditProbe::class)
        ->dispatch('probe-event', secret: 'hunter2-secret');

    $event = AuditEvent::query()->sole();

    expect($event->action)->toEndWith('.onProbeEvent')
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and(auditEverythingRecorded())->not->toContain('probe-event')
        ->and(auditEverythingRecorded())->not->toContain('hunter2-secret');
});

test('a dispatched event with no listener, or a not audited listener, produces no event', function () {
    $component = Livewire::actingAs(User::factory()->create())->test(AuditProbe::class);

    $component->dispatch('quiet-event');

    try {
        $component->dispatch('made-up-event', secret: 'hunter2-secret');
    } catch (Throwable) {
    }

    expect(AuditEvent::query()->count())->toBe(0)
        ->and(auditEverythingRecorded())->not->toContain('made-up-event')
        ->and(auditEverythingRecorded())->not->toContain('hunter2-secret');
});

test('a job released because its overlap lock is held records no event and clears the user', function () {
    $job = new RunTransactionAnalysisJob(User::factory()->create())->withFakeQueueInteractions();
    [$attribute, $overlap] = $job->middleware();
    $ran = false;

    $overlap->handle($job, function () use ($attribute, $overlap, $job, &$ran) {
        $attribute->handle($job, fn () => $overlap->handle($job, function () use (&$ran) {
            $ran = true;
        }));
    });

    $job->assertReleased();

    expect($ran)->toBeFalse()
        ->and(AuditEvent::query()->count())->toBe(0)
        ->and(auditSentryEvent()->getUser())->toBeNull();
});
