<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Jobs\SyncTransactionsJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('valid state and jobId dispatches sync job and redirects with success', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();
    $state = 'valid-state-token-1234567890abcdefghij';

    $this->actingAs($user)
        ->withSession(['basiq_consent_state' => $state])
        ->get(route('basiq.callback', ['state' => $state, 'jobId' => 'job-123']))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'Bank connected successfully. Your transactions are syncing.');

    Queue::assertPushed(SyncTransactionsJob::class, function (SyncTransactionsJob $job) use ($user) {
        return $job->jobId === 'job-123' && $job->user->is($user);
    });
});

test('invalid state redirects with error and does not dispatch job', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();

    $this->actingAs($user)
        ->withSession(['basiq_consent_state' => 'correct-state'])
        ->get(route('basiq.callback', ['state' => 'wrong-state', 'jobId' => 'job-123']))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error');

    Queue::assertNothingPushed();
});

test('missing state redirects with error and does not dispatch job', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();

    $this->actingAs($user)
        ->withSession(['basiq_consent_state' => 'some-state'])
        ->get(route('basiq.callback', ['jobId' => 'job-123']))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error');

    Queue::assertNothingPushed();
});

test('missing session state redirects with error', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();

    $this->actingAs($user)
        ->get(route('basiq.callback', ['state' => 'some-state', 'jobId' => 'job-123']))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error');

    Queue::assertNothingPushed();
});

test('cancelled result redirects with info message', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();
    $state = 'valid-state-token-1234567890abcdefghij';

    $this->actingAs($user)
        ->withSession(['basiq_consent_state' => $state])
        ->get(route('basiq.callback', ['state' => $state, 'result' => 'cancelled']))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('info', 'Bank connection was cancelled.');

    Queue::assertNothingPushed();
});

test('missing jobId redirects with error message', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();
    $state = 'valid-state-token-1234567890abcdefghij';

    $this->actingAs($user)
        ->withSession(['basiq_consent_state' => $state])
        ->get(route('basiq.callback', ['state' => $state]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error', 'Bank connection failed. Please try again.');

    Queue::assertNothingPushed();
});

test('array jobId is treated as invalid and does not dispatch job', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();
    $state = 'valid-state-token-1234567890abcdefghij';

    $this->actingAs($user)
        ->withSession(['basiq_consent_state' => $state])
        ->get(route('basiq.callback', ['state' => $state, 'jobId' => ['array-value']]))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error', 'Bank connection failed. Please try again.');

    Queue::assertNothingPushed();
});

test('session state is forgotten after validation', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();
    $state = 'valid-state-token-1234567890abcdefghij';

    $this->actingAs($user)
        ->withSession(['basiq_consent_state' => $state])
        ->get(route('basiq.callback', ['state' => $state, 'jobId' => 'job-123']));

    expect(session()->has('basiq_consent_state'))->toBeFalse();
});

test('route requires authentication', function () {
    $this->get(route('basiq.callback'))
        ->assertRedirect(route('login'));
});
