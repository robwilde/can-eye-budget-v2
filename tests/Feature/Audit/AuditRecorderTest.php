<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Exceptions;

test('an event stores exactly the allow-listed fields', function () {
    $user = User::factory()->create();
    Context::add('request_id', 'req-123');

    $event = app(AuditRecorder::class)->record('rule.create', 'rule', 42, AuditOutcome::Success, $user->id);

    $row = AuditEvent::query()->findOrFail($event->id)->getRawOriginal();

    expect(array_keys($row))->toBe(['id', 'user_id', 'action', 'subject_type', 'subject_id', 'outcome', 'request_id', 'created_at'])
        ->and($row)->toMatchArray([
            'user_id' => $user->id,
            'action' => 'rule.create',
            'subject_type' => 'rule',
            'subject_id' => '42',
            'outcome' => 'success',
            'request_id' => 'req-123',
        ]);
});

test('the actor defaults to the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    app(AuditRecorder::class)->record('rule.create');

    expect(AuditEvent::query()->sole()->user_id)->toBe($user->id);
});

test('the same payload goes to the audit log channel and a Sentry breadcrumb', function () {
    $user = User::factory()->create();
    Sentry\SentrySdk::getCurrentHub()->bindClient(Sentry\ClientBuilder::create([])->getClient());

    app(AuditRecorder::class)->record('rule.create', 'rule', 7, AuditOutcome::Failure, $user->id);

    $expected = [
        'user_id' => $user->id,
        'action' => 'rule.create',
        'subject_type' => 'rule',
        'subject_id' => '7',
        'outcome' => 'failure',
        'request_id' => null,
    ];
    $breadcrumbs = auditSentryEvent()->getBreadcrumbs();

    expect(auditLogRecords())->toHaveCount(1)
        ->and(auditLogRecords()[0]->context)->toBe($expected)
        ->and($breadcrumbs)->toHaveCount(1)
        ->and($breadcrumbs[0]->getCategory())->toBe('audit')
        ->and($breadcrumbs[0]->getMetadata())->toBe($expected);
});

test('names of exactly 100 characters are stored and 101 are refused', function () {
    $name = str_repeat('a', 100);

    expect(app(AuditRecorder::class)->record($name, $name)->action)->toBe($name)
        ->and(AuditRecorder::accepts($name.'a'))->toBeFalse();
});

test('with the Sentry log breadcrumb integration on, one event yields one breadcrumb and still reaches the log', function () {
    Sentry\SentrySdk::getCurrentHub()->bindClient(Sentry\ClientBuilder::create(['integrations' => [new Sentry\Laravel\Integration]])->getClient());
    (new Sentry\Laravel\EventHandler(app(), ['breadcrumbs' => ['logs' => true, 'sql_queries' => false]]))->subscribe(app('events'));

    app(AuditRecorder::class)->record('rule.create', 'rule', 7);

    $breadcrumbs = auditSentryEvent()->getBreadcrumbs();

    expect($breadcrumbs)->toHaveCount(1)
        ->and($breadcrumbs[0]->getCategory())->toBe('audit')
        ->and(auditLogRecords())->toHaveCount(1);
});

test('subject ids accept integer ids, UUIDs and ULIDs', function (int|string $subjectId) {
    $event = app(AuditRecorder::class)->record('rule.create', 'rule', $subjectId);

    expect($event->subject_id)->toBe((string) $subjectId);
})->with([
    'integer' => 42,
    'uuid' => '0b9a7c58-3d2e-4f6a-9c1b-7a5e8d4f2c10',
    'ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
]);

test('values that could carry secrets or personal data are refused and nothing is written', function (array $arguments) {
    expect(fn () => app(AuditRecorder::class)->record(...$arguments))
        ->toThrow(InvalidArgumentException::class);

    expect(AuditEvent::query()->count())->toBe(0)
        ->and(auditLogRecords())->toBeEmpty();
})->with([
    'email as action' => [['action' => 'login.rob@example.com']],
    'sentence as action' => [['action' => 'Paid Coffee Shop 12.50']],
    'redbark key as action' => [['action' => 'rbk_live_0123456789abcdef']],
    'redbark key as subject type' => [['action' => 'connect', 'subjectType' => 'rbk_live_0123456789abcdef']],
    'redbark key as subject id' => [['action' => 'connect', 'subjectType' => 'redbark_feed', 'subjectId' => 'rbk_live_0123456789abcdef']],
    'spaced key as subject id' => [['action' => 'connect', 'subjectId' => 'rbk live 0123']],
    'email as subject id' => [['action' => 'connect', 'subjectType' => 'user', 'subjectId' => 'rob@example.com']],
    'amount as subject id' => [['action' => 'connect', 'subjectId' => '12.50']],
    'overlong subject id' => [['action' => 'connect', 'subjectId' => str_repeat('1', 21)]],
    'empty action' => [['action' => '']],
    'overlong action' => [['action' => str_repeat('A12', 34)]],
    'overlong subject type' => [['action' => 'connect', 'subjectType' => str_repeat('A12', 34)]],
]);

test('events older than the retention window are pruned and newer ones kept', function () {
    config(['audit.retention_days' => 30]);
    $recorder = app(AuditRecorder::class);

    $this->travelTo(now()->subDays(31));
    $old = $recorder->record('rule.create');
    $this->travelBack();
    $recent = $recorder->record('rule.delete');

    $this->artisan('model:prune', ['--model' => [AuditEvent::class]])->assertSuccessful();

    expect(AuditEvent::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(AuditEvent::query()->whereKey($old->id)->exists())->toBeFalse();
});

test('the retention prune is scheduled daily', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('model:prune')
        ->assertSuccessful();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'model:prune')
            && str_contains((string) $event->command, AuditEvent::class));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});

test('deleting a user keeps their events but detaches them', function () {
    $user = User::factory()->create();
    app(AuditRecorder::class)->record('rule.create', actorId: $user->id);

    $user->delete();

    expect(AuditEvent::query()->sole()->user_id)->toBeNull();
});

test('a failing database insert does not stop the audit log line or the breadcrumb', function () {
    Sentry\SentrySdk::getCurrentHub()->bindClient(Sentry\ClientBuilder::create([])->getClient());
    AuditEvent::creating(fn () => throw new RuntimeException('insert failed'));
    Exceptions::fake();

    app(AuditRecorder::class)->recordSafely('rule.create', 'rule', 7);

    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'insert failed');

    expect(AuditEvent::query()->count())->toBe(0)
        ->and(auditLogRecords())->toHaveCount(1)
        ->and(auditSentryEvent()->getBreadcrumbs())->toHaveCount(1);
});

test('recordSafely reports an invalid action instead of throwing and writes nothing', function () {
    Exceptions::fake();

    app(AuditRecorder::class)->recordSafely('login.rob@example.com');

    Exceptions::assertReported(InvalidArgumentException::class);

    expect(AuditEvent::query()->count())->toBe(0)
        ->and(auditLogRecords())->toBeEmpty();
});
