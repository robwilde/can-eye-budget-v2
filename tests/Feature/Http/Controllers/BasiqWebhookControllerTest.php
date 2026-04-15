<?php

/** @noinspection JsonEncodingApiUsageInspection */

/** @noinspection StaticClosureCanBeUsedInspection */

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Jobs\SyncTransactionsJob;
use App\Models\BasiqRefreshLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function signWebhookPayload(string $body, ?string $secret = null, ?string $webhookId = null, ?int $timestamp = null): array
{
    $secret ??= config('services.basiq.webhook_secret');
    $webhookId ??= 'msg_'.fake()->uuid();
    $timestamp ??= time();

    $secretBytes = base64_decode(str_replace('whsec_', '', $secret));
    $signedContent = "{$webhookId}.{$timestamp}.{$body}";
    $signature = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, binary: true));

    return [
        'webhook-id' => $webhookId,
        'webhook-timestamp' => (string) $timestamp,
        'webhook-signature' => "v1,{$signature}",
    ];
}

function webhookPayload(string $eventType, string $basiqUserId): string
{
    return json_encode([
        'eventTypeId' => $eventType,
        'links' => [
            'eventEntity' => "https://au-api.basiq.io/users/{$basiqUserId}/connections/conn-123",
        ],
    ], JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    config(['services.basiq.webhook_secret' => 'whsec_'.base64_encode(random_bytes(32))]);
});

test('connection.created webhook dispatches SyncTransactionsJob and creates a Webhook-triggered pending log', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();
    $body = webhookPayload('connection.created', $user->basiq_user_id);
    $headers = signWebhookPayload($body);

    $this->postJson('/webhooks/basiq', json_decode($body, true), $headers)
        ->assertNoContent();

    $log = BasiqRefreshLog::sole();
    expect($log)
        ->user_id->toBe($user->id)
        ->trigger->toBe(RefreshTrigger::Webhook)
        ->status->toBe(RefreshStatus::Pending);

    Queue::assertPushed(SyncTransactionsJob::class, static function (SyncTransactionsJob $job) use ($user, $log) {
        return $job->user->id === $user->id
            && $job->jobId === null
            && $job->log?->is($log);
    });
});

test('transactions.updated webhook dispatches SyncTransactionsJob', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();
    $body = webhookPayload('transactions.updated', $user->basiq_user_id);
    $headers = signWebhookPayload($body);

    $this->postJson('/webhooks/basiq', json_decode($body, true), $headers)
        ->assertNoContent();

    Queue::assertPushed(SyncTransactionsJob::class, function (SyncTransactionsJob $job) use ($user) {
        return $job->user->id === $user->id && $job->jobId === null;
    });
});

test('unknown basiq_user_id returns 204 without dispatching', function () {
    Queue::fake();

    $body = webhookPayload('connection.created', 'nonexistent-user-id');
    $headers = signWebhookPayload($body);

    $this->postJson('/webhooks/basiq', json_decode($body, true), $headers)
        ->assertNoContent();

    Queue::assertNothingPushed();
});

test('unknown event type returns 204 without dispatching', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();
    $body = json_encode([
        'eventTypeId' => 'account.deleted',
        'links' => [
            'eventEntity' => "https://au-api.basiq.io/users/{$user->basiq_user_id}/accounts/acc-1",
        ],
    ]);
    $headers = signWebhookPayload($body);

    $this->postJson('/webhooks/basiq', json_decode($body, true), $headers)
        ->assertNoContent();

    Queue::assertNothingPushed();
});

test('missing signature headers returns 403', function () {
    $this->postJson('/webhooks/basiq', ['eventTypeId' => 'connection.created'])
        ->assertForbidden();
});

test('missing individual signature headers returns 403', function (array $headers) {
    $this->postJson('/webhooks/basiq', ['eventTypeId' => 'connection.created'], $headers)
        ->assertForbidden();
})->with([
    'missing webhook-id' => [['webhook-timestamp' => '1234567890', 'webhook-signature' => 'v1,invalid']],
    'missing webhook-timestamp' => [['webhook-id' => 'msg_123', 'webhook-signature' => 'v1,invalid']],
    'missing webhook-signature' => [['webhook-id' => 'msg_123', 'webhook-timestamp' => '1234567890']],
]);

test('invalid HMAC signature returns 403', function () {
    $body = webhookPayload('connection.created', 'some-user-id');
    $headers = [
        'webhook-id' => 'msg_123',
        'webhook-timestamp' => (string) time(),
        'webhook-signature' => 'v1,aW52YWxpZHNpZ25hdHVyZQ==',
    ];

    $this->postJson('/webhooks/basiq', json_decode($body, true), $headers)
        ->assertForbidden();
});

test('expired timestamp returns 403', function () {
    $user = User::factory()->withBasiq()->create();
    $body = webhookPayload('connection.created', $user->basiq_user_id);
    $expiredTimestamp = time() - 400;
    $headers = signWebhookPayload($body, timestamp: $expiredTimestamp);

    $this->postJson('/webhooks/basiq', json_decode($body, true), $headers)
        ->assertForbidden();
});

test('valid signature passes verification', function () {
    Queue::fake();

    $user = User::factory()->withBasiq()->create();
    $body = webhookPayload('connection.created', $user->basiq_user_id);
    $headers = signWebhookPayload($body);

    $this->postJson('/webhooks/basiq', json_decode($body, true), $headers)
        ->assertNoContent();
});

test('malformed entity URL returns 204 without dispatching', function () {
    Queue::fake();

    $body = json_encode([
        'eventTypeId' => 'connection.created',
        'links' => [
            'eventEntity' => 'not-a-valid-url',
        ],
    ]);
    $headers = signWebhookPayload($body);

    $this->postJson('/webhooks/basiq', json_decode($body, true), $headers)
        ->assertNoContent();

    Queue::assertNothingPushed();
});
