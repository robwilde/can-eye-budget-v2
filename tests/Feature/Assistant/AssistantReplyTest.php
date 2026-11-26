<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Actions\Assistant\MarkReplyUndelivered;
use App\Actions\Assistant\RetryLastMessage;
use App\Actions\Assistant\SendChatMessage;
use App\Contracts\AssistantTool;
use App\Contracts\LlmClientContract;
use App\DTOs\Llm\LlmResponse;
use App\Enums\AssistantErrorKind;
use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Exceptions\Assistant\AssistantRefusedException;
use App\Exceptions\Assistant\LlmException;
use App\Jobs\GenerateAssistantReplyJob;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\LlmUsage;
use App\Models\User;
use App\Services\Assistant\AssistantAvailability;
use App\Services\Assistant\AssistantPromptBuilder;
use App\Services\Assistant\AssistantToolRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config([
        'budget.ai_assistant' => true,
        'services.ai.provider' => 'openai',
        'services.openai.key' => 'sk-test-secret',
        'services.ai.model' => 'gpt-4o-mini',
    ]);
});

function consentedUser(): User
{
    $user = User::factory()->create();
    $user->grantAiConsent();

    return $user->fresh();
}

function openAiReply(string $content = 'Hello there', int $prompt = 1000, int $completion = 500): array
{
    return ['model' => 'gpt-4o-mini', 'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]], 'usage' => ['prompt_tokens' => $prompt, 'completion_tokens' => $completion]];
}

/**
 * @return array{0: Chat, 1: ChatMessage}
 */
function pendingReply(User $user): array
{
    $chat = Chat::factory()->for($user)->create();
    ChatMessage::factory()->for($chat)->create(['content' => 'How much did I spend?']);
    $reply = ChatMessage::factory()->for($chat)->assistant()->create(['content' => '', 'status' => ChatMessageStatus::Pending, 'model' => null]);

    return [$chat, $reply];
}

it('refuses to send a message for a user who has not consented', function () {
    Queue::fake();
    $user = User::factory()->create();
    $chat = Chat::factory()->for($user)->create();

    expect(fn () => app(SendChatMessage::class)->execute($user, $chat, 'hi'))->toThrow(AssistantRefusedException::class);

    expect(ChatMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses to send a message after consent is revoked', function () {
    Queue::fake();
    $user = consentedUser();
    $chat = Chat::factory()->for($user)->create();
    $user->revokeAiConsent();

    expect(fn () => app(SendChatMessage::class)->execute($user->fresh(), $chat, 'hi'))->toThrow(AssistantRefusedException::class);
    expect(ChatMessage::query()->count())->toBe(0);
});

it('creates the user message and a pending reply and queues the job', function () {
    Queue::fake();
    $user = consentedUser();
    $chat = Chat::factory()->for($user)->create();

    $reply = app(SendChatMessage::class)->execute($user, $chat, '  hi there  ');

    expect(ChatMessage::query()->count())->toBe(2)
        ->and($reply->status)->toBe(ChatMessageStatus::Pending)
        ->and($reply->role)->toBe(ChatMessageRole::Assistant)
        ->and(ChatMessage::query()->where('role', 'user')->sole()->content)->toBe('hi there');
    Queue::assertPushed(GenerateAssistantReplyJob::class, fn (GenerateAssistantReplyJob $job): bool => $job->messageId === $reply->id && $job->user->is($user));
});

it('refuses empty, oversized and concurrent messages and another user\'s chat', function () {
    Queue::fake();
    $user = consentedUser();
    $chat = Chat::factory()->for($user)->create();
    $send = fn (string $text, ?Chat $in = null) => app(SendChatMessage::class)->execute($user, $in ?? $chat, $text);

    expect(fn () => $send('   '))->toThrow(AssistantRefusedException::class)
        ->and(fn () => $send(str_repeat('a', SendChatMessage::MAX_LENGTH + 1)))->toThrow(AssistantRefusedException::class)
        ->and(fn () => $send('hi', Chat::factory()->create()))->toThrow(AssistantRefusedException::class);

    $send('first');

    expect(fn () => $send('second'))->toThrow(AssistantRefusedException::class)
        ->and(ChatMessage::query()->count())->toBe(2);
});

it('reports the assistant unavailable without a provider key', function () {
    config(['services.openai.key' => null]);

    expect(app(AssistantAvailability::class)->available())->toBeFalse();

    config(['services.openai.key' => 'k', 'budget.ai_assistant' => false]);

    expect(app(AssistantAvailability::class)->available())->toBeFalse();

    config(['budget.ai_assistant' => true, 'services.ai.provider' => 'anthropic', 'services.anthropic.key' => '']);

    expect(app(AssistantAvailability::class)->available())->toBeFalse();

    config(['services.anthropic.key' => 'k']);

    expect(app(AssistantAvailability::class)->available())->toBeTrue();
});

it('marks the reply failed as rate limited on a 429', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'slow down: secret prompt text']], 429)]);
    $user = consentedUser();
    [, $reply] = pendingReply($user);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    $fresh = $reply->fresh();

    expect($fresh->status)->toBe(ChatMessageStatus::Failed)
        ->and($fresh->error_kind)->toBe(AssistantErrorKind::RateLimited)
        ->and(LlmUsage::query()->count())->toBe(0);
});

it('maps provider failures to error kinds', function (int $status, AssistantErrorKind $kind) {
    Http::fake(['api.openai.com/*' => Http::response([], $status)]);
    $user = consentedUser();
    [, $reply] = pendingReply($user);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    expect($reply->fresh()->error_kind)->toBe($kind);
})->with([
    '500' => [500, AssistantErrorKind::Unavailable],
    '503' => [503, AssistantErrorKind::Unavailable],
    '401' => [401, AssistantErrorKind::Misconfigured],
    '403' => [403, AssistantErrorKind::Misconfigured],
    '400' => [400, AssistantErrorKind::Generic],
    '404' => [404, AssistantErrorKind::Generic],
]);

it('marks the reply unavailable on a connection error and misconfigured without a key', function () {
    Http::fake(['api.openai.com/*' => fn () => throw new Illuminate\Http\Client\ConnectionException('timeout')]);
    $user = consentedUser();
    [, $reply] = pendingReply($user);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    expect($reply->fresh()->error_kind)->toBe(AssistantErrorKind::Unavailable);

    config(['services.openai.key' => null]);
    [, $second] = pendingReply($user);
    Http::fake();

    GenerateAssistantReplyJob::dispatchSync($user, $second->id);

    expect($second->fresh()->error_kind)->toBe(AssistantErrorKind::Misconfigured);
    Http::assertNothingSent();
});

it('retries a rate limited call before failing the message', function () {
    Http::fake(['api.openai.com/*' => Http::response([], 429)]);
    $user = consentedUser();
    [, $reply] = pendingReply($user);
    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(10);
    $job = new GenerateAssistantReplyJob($user, $reply->id);
    $job->setJob($queueJob);

    $job->handle(app(App\Services\Assistant\AssistantReplyGenerator::class));

    expect($reply->fresh()->status)->toBe(ChatMessageStatus::Pending);

    $last = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $last->shouldReceive('attempts')->andReturn(3);
    $job->setJob($last);
    $job->handle(app(App\Services\Assistant\AssistantReplyGenerator::class));

    expect($reply->fresh()->status)->toBe(ChatMessageStatus::Failed)
        ->and($reply->fresh()->error_kind)->toBe(AssistantErrorKind::RateLimited);
});

it('does not retry an unauthorised call', function () {
    Http::fake(['api.openai.com/*' => Http::response([], 401)]);
    $user = consentedUser();
    [, $reply] = pendingReply($user);
    $queueJob = Mockery::mock(Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldNotReceive('release');
    $job = new GenerateAssistantReplyJob($user, $reply->id);
    $job->setJob($queueJob);

    $job->handle(app(App\Services\Assistant\AssistantReplyGenerator::class));

    expect($reply->fresh()->error_kind)->toBe(AssistantErrorKind::Misconfigured);
});

it('leaves failed replies out of the history sent to the model', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiReply())]);
    $user = consentedUser();
    $chat = Chat::factory()->for($user)->create();
    ChatMessage::factory()->for($chat)->create(['content' => 'first question']);
    ChatMessage::factory()->for($chat)->assistant()->create(['content' => 'FAILED-REPLY-TEXT', 'status' => ChatMessageStatus::Failed]);
    ChatMessage::factory()->for($chat)->assistant()->create(['content' => 'UNDELIVERED-TEXT', 'status' => ChatMessageStatus::Undelivered]);
    ChatMessage::factory()->for($chat)->create(['content' => 'second question']);
    $reply = ChatMessage::factory()->for($chat)->assistant()->create(['content' => '', 'status' => ChatMessageStatus::Pending]);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        return str_contains($body, 'first question') && str_contains($body, 'second question')
            && ! str_contains($body, 'FAILED-REPLY-TEXT') && ! str_contains($body, 'UNDELIVERED-TEXT');
    });
    expect($reply->fresh()->content)->toBe('Hello there')->and($reply->fresh()->status)->toBe(ChatMessageStatus::Complete);
});

it('merges consecutive messages of the same role in the history', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiReply())]);
    $user = consentedUser();
    $chat = Chat::factory()->for($user)->create();
    ChatMessage::factory()->for($chat)->create(['content' => 'one']);
    ChatMessage::factory()->for($chat)->create(['content' => 'two']);
    $reply = ChatMessage::factory()->for($chat)->assistant()->create(['content' => '', 'status' => ChatMessageStatus::Pending]);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    Http::assertSent(function (Request $request): bool {
        $roles = array_column($request->data()['messages'], 'role');

        return $roles === ['system', 'user'] && str_contains($request->data()['messages'][1]['content'], "one\n\ntwo");
    });
});

it('records LLM usage and an audit event for each reply', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiReply('Hi', 2_000_000, 1_000_000))]);
    $user = consentedUser();
    [, $reply] = pendingReply($user);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    $usage = LlmUsage::query()->sole();
    $event = AuditEvent::query()->where('action', 'job.GenerateAssistantReplyJob')->sole();

    expect($usage->user_id)->toBe($user->id)
        ->and($usage->chat_message_id)->toBe($reply->id)
        ->and($usage->provider)->toBe('openai')
        ->and($usage->model)->toBe('gpt-4o-mini')
        ->and($usage->prompt_tokens)->toBe(2_000_000)
        ->and($usage->completion_tokens)->toBe(1_000_000)
        ->and($usage->cost)->toBe(2 * 15 + 60)
        ->and($event->user_id)->toBe($user->id);
});

it('costs an unpriced model at nothing and rounds cents up', function () {
    $calculator = app(App\Services\Assistant\LlmCostCalculator::class);

    expect($calculator->cents('unknown-model', 1000, 1000))->toBe(0)
        ->and($calculator->cents('gpt-4o-mini', 1, 1))->toBe(1)
        ->and($calculator->cents('gpt-4o-mini', 0, 0))->toBe(0);
});

it('does not write a reply to another user\'s chat', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiReply())]);
    $owner = consentedUser();
    $intruder = consentedUser();
    [, $reply] = pendingReply($owner);

    GenerateAssistantReplyJob::dispatchSync($intruder, $reply->id);

    expect($reply->fresh()->status)->toBe(ChatMessageStatus::Pending)->and($reply->fresh()->content)->toBe('')
        ->and(LlmUsage::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('makes no call and fails the message once consent is revoked', function () {
    Http::fake();
    $user = consentedUser();
    [, $reply] = pendingReply($user);
    $user->revokeAiConsent();

    GenerateAssistantReplyJob::dispatchSync($user->fresh(), $reply->id);

    Http::assertNothingSent();
    expect($reply->fresh()->status)->toBe(ChatMessageStatus::Failed)->and(LlmUsage::query()->count())->toBe(0);
});

it('does nothing for a message that is no longer pending', function () {
    Http::fake();
    $user = consentedUser();
    [, $reply] = pendingReply($user);
    $reply->update(['status' => ChatMessageStatus::Complete, 'content' => 'done']);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    Http::assertNothingSent();
    expect($reply->fresh()->content)->toBe('done');
});

it('never logs prompts, replies or keys when a reply fails', function () {
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::response(['error' => 'PROVIDER-BODY-SECRET'], 500)]);
    $user = consentedUser();
    [, $reply] = pendingReply($user);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        $flat = $message.json_encode($context);

        return ! str_contains($flat, 'How much did I spend') && ! str_contains($flat, 'sk-test-secret') && ! str_contains($flat, 'PROVIDER-BODY-SECRET') && $context['kind'] === 'unavailable';
    })->once();
});

it('summarises accounts and categories as counts when the lists are long', function () {
    config(['services.ai.max_listed' => 3]);
    $user = User::factory()->create();
    Account::factory()->for($user)->count(4)->create();
    Category::factory()->count(2)->create(['user_id' => $user->id, 'name' => 'Groceries-only']);
    $named = Category::factory()->create(['user_id' => $user->id, 'name' => 'Visible-Cat']);

    $prompt = app(AssistantPromptBuilder::class)->build($user);

    expect($prompt)->toContain('The user has 4 accounts.')
        ->and($prompt)->toContain('Visible-Cat')->toContain('Groceries-only')
        ->and($prompt)->toContain($user->today()->format('l j F Y'))->toContain($user->base_currency)
        ->and($prompt)->toContain('Never mention, name or describe the tools');

    config(['services.ai.max_listed' => 40, 'services.ai.context_window' => 8000]);

    expect(app(AssistantPromptBuilder::class)->build($user))->toContain('The user has 4 accounts.')->toContain('The user has 3 categories.');
});

it('lists only the user\'s own accounts and categories in the prompt', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'MY-ACCOUNT']);
    Account::factory()->create(['name' => 'THEIR-ACCOUNT']);
    Category::factory()->create(['name' => 'THEIR-CATEGORY']);

    $prompt = app(AssistantPromptBuilder::class)->build($user);

    expect($prompt)->toContain('MY-ACCOUNT')->not->toContain('THEIR-ACCOUNT')->not->toContain('THEIR-CATEGORY');
});

it('uses the Anthropic client when it is the selected provider', function () {
    config(['services.ai.provider' => 'anthropic', 'services.anthropic.key' => 'ak-test', 'services.ai.model' => 'claude-sonnet-4-5']);
    Http::fake(['api.anthropic.com/*' => Http::response(['model' => 'claude-sonnet-4-5', 'content' => [['type' => 'text', 'text' => 'From Claude']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]])]);
    $user = consentedUser();
    [, $reply] = pendingReply($user);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'ak-test') && str_contains($request->url(), '/messages') && isset($request->data()['system']));
    expect($reply->fresh()->content)->toBe('From Claude')->and(LlmUsage::query()->sole()->provider)->toBe('anthropic');
});

it('runs a tool call loop for the user and records one usage row per call', function () {
    $user = consentedUser();
    [, $reply] = pendingReply($user);
    $seen = [];

    app(AssistantToolRegistry::class)->register(function (User $for) use (&$seen): AssistantTool {
        return new class($for, $seen) implements AssistantTool
        {
            public function __construct(private User $user, private array &$seen) {}

            public function name(): string
            {
                return 'count_accounts';
            }

            public function description(): string
            {
                return 'Counts accounts';
            }

            public function parameters(): array
            {
                return ['type' => 'object', 'properties' => new stdClass];
            }

            public function requiresPreview(): bool
            {
                return false;
            }

            public function handle(User $user, array $arguments): array
            {
                $this->seen[] = $user->id;

                return ['message' => 'You have 7 accounts.'];
            }
        };
    });

    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(['model' => 'gpt-4o-mini', 'choices' => [['message' => ['content' => '', 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'count_accounts', 'arguments' => '{}']]]]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]])
        ->push(openAiReply('You have 7 accounts.'))]);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    expect($seen)->toBe([$user->id])
        ->and($reply->fresh()->content)->toBe('You have 7 accounts.')
        ->and(LlmUsage::query()->count())->toBe(2);
    Http::assertSent(fn (Request $request): bool => collect($request->data()['messages'] ?? [])->contains(fn (array $m): bool => ($m['role'] ?? '') === 'tool' && $m['content'] === '{"message":"You have 7 accounts."}'));
});

it('answers an unknown tool call without running anything', function () {
    $user = consentedUser();
    [, $reply] = pendingReply($user);
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(['model' => 'gpt-4o-mini', 'choices' => [['message' => ['content' => '', 'tool_calls' => [['id' => 'c', 'type' => 'function', 'function' => ['name' => 'delete_everything', 'arguments' => '{}']]]]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])
        ->push(openAiReply('Done'))]);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    Http::assertSent(fn (Request $request): bool => collect($request->data()['messages'] ?? [])->contains(fn (array $m): bool => ($m['role'] ?? '') === 'tool' && $m['content'] === 'That is not available.'));
});

it('retries a failed or undelivered last reply', function () {
    Queue::fake();
    $user = consentedUser();
    $chat = Chat::factory()->for($user)->create();
    ChatMessage::factory()->for($chat)->create();
    $failed = ChatMessage::factory()->for($chat)->assistant()->create(['content' => 'x', 'status' => ChatMessageStatus::Failed, 'error_kind' => AssistantErrorKind::Generic]);

    $retried = app(RetryLastMessage::class)->execute($user, $chat);

    expect($retried->id)->toBe($failed->id)->and($failed->fresh()->status)->toBe(ChatMessageStatus::Pending)->and($failed->fresh()->content)->toBe('')->and($failed->fresh()->error_kind)->toBeNull();
    Queue::assertPushed(GenerateAssistantReplyJob::class, 1);

    expect(fn () => app(RetryLastMessage::class)->execute($user, $chat))->toThrow(AssistantRefusedException::class);
    expect(fn () => app(RetryLastMessage::class)->execute($user, Chat::factory()->create()))->toThrow(AssistantRefusedException::class);
});

it('refuses to mark a reply undelivered until it is old enough', function () {
    $user = consentedUser();
    [$chat, $reply] = pendingReply($user);
    config(['budget.ai_reply_min_age_seconds' => 120]);

    expect(fn () => app(MarkReplyUndelivered::class)->execute($user, $chat))->toThrow(AssistantRefusedException::class);

    $this->travel(121)->seconds();

    $marked = app(MarkReplyUndelivered::class)->execute($user, $chat);

    expect($marked->status)->toBe(ChatMessageStatus::Undelivered)->and($reply->fresh()->status)->toBe(ChatMessageStatus::Undelivered);
    expect(fn () => app(MarkReplyUndelivered::class)->execute($user, Chat::factory()->create()))->toThrow(AssistantRefusedException::class);
});

it('keeps the provider exception message free of bodies and keys', function () {
    $exception = LlmException::forStatus(500);

    expect($exception->getMessage())->toBe('LLM call failed: unavailable (HTTP 500)')->and($exception->isRetryable())->toBeTrue()
        ->and((new LlmException(AssistantErrorKind::Misconfigured))->isRetryable())->toBeFalse();
});

it('uses a fake LLM client through the contract', function () {
    $this->app->bind(LlmClientContract::class, fn () => new class implements LlmClientContract
    {
        public function complete(string $system, array $conversation, array $tools = [], bool $allowTools = true): LlmResponse
        {
            return new LlmResponse('from the fake', [], 3, 4, 'fake-model');
        }

        public function provider(): string
        {
            return 'fake';
        }
    });
    Http::fake();
    $user = consentedUser();
    [, $reply] = pendingReply($user);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    expect($reply->fresh()->content)->toBe('from the fake')->and(LlmUsage::query()->sole()->provider)->toBe('fake');
    Http::assertNothingSent();
});

it('prices a dated snapshot of a model by its base model', function () {
    $calculator = app(App\Services\Assistant\LlmCostCalculator::class);

    expect($calculator->cents('gpt-4o-mini-2024-07-18', 1_000_000, 0))->toBe(15)
        ->and($calculator->cents('gpt-4o-mini-20240718', 1_000_000, 0))->toBe(15)
        ->and($calculator->cents('gpt-4o-mini-preview', 1_000_000, 0))->toBe(0);

    config(['services.ai.prices' => ['gpt-4.1-mini' => ['prompt' => 40, 'completion' => 160]]]);

    expect($calculator->cents('gpt-4.1-mini', 1_000_000, 0))->toBe(40);
});

it('keeps tool definitions on the last round and tells the provider not to call them', function (string $provider) {
    config(['services.ai.max_tool_rounds' => 2, 'services.ai.provider' => $provider, 'services.anthropic.key' => 'ak', 'services.ai.model' => 'm']);
    $user = consentedUser();
    [, $reply] = pendingReply($user);

    app(AssistantToolRegistry::class)->register(fn (User $for): AssistantTool => new class implements AssistantTool
    {
        public function name(): string
        {
            return 'noop';
        }

        public function description(): string
        {
            return 'Does nothing';
        }

        public function parameters(): array
        {
            return ['type' => 'object', 'properties' => new stdClass];
        }

        public function requiresPreview(): bool
        {
            return false;
        }

        public function handle(User $user, array $arguments): array
        {
            return ['ok' => true];
        }
    });

    $toolTurn = $provider === 'openai'
        ? ['model' => 'm', 'choices' => [['message' => ['content' => '', 'tool_calls' => [['id' => 'c', 'type' => 'function', 'function' => ['name' => 'noop', 'arguments' => '{}']]]]]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]
        : ['model' => 'm', 'content' => [['type' => 'tool_use', 'id' => 'c', 'name' => 'noop', 'input' => []]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
    $final = $provider === 'openai' ? openAiReply('final') : ['model' => 'm', 'content' => [['type' => 'text', 'text' => 'final']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];

    Http::fake(['*' => Http::sequence()->push($toolTurn)->push($final)]);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    $requests = Http::recorded();
    $second = $requests[1][0]->data();
    $choice = $provider === 'openai' ? ($second['tool_choice'] ?? null) : ($second['tool_choice']['type'] ?? null);

    expect($reply->fresh()->content)->toBe('final')
        ->and($second['tools'])->not->toBeEmpty()
        ->and($choice)->toBe('none')
        ->and($requests[0][0]->data())->not->toHaveKey('tool_choice');
})->with(['openai', 'anthropic']);

it('fails an empty reply instead of saving a blank message', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiReply('   '))]);
    $user = consentedUser();
    [, $reply] = pendingReply($user);

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    expect($reply->fresh()->status)->toBe(ChatMessageStatus::Failed)->and($reply->fresh()->error_kind)->toBe(AssistantErrorKind::Generic);
});

it('does not overwrite a reply that was changed while the job ran', function () {
    $user = consentedUser();
    [, $reply] = pendingReply($user);
    $this->app->bind(LlmClientContract::class, fn () => new class($reply) implements LlmClientContract
    {
        public function __construct(private ChatMessage $reply) {}

        public function complete(string $system, array $conversation, array $tools = [], bool $allowTools = true): LlmResponse
        {
            ChatMessage::query()->whereKey($this->reply->id)->update(['status' => 'undelivered']);

            return new LlmResponse('late reply', [], 1, 1, 'fake');
        }

        public function provider(): string
        {
            return 'fake';
        }
    });

    GenerateAssistantReplyJob::dispatchSync($user, $reply->id);

    expect($reply->fresh()->status)->toBe(ChatMessageStatus::Undelivered)->and($reply->fresh()->content)->toBe('');
});

it('measures the undelivered age from the last retry', function () {
    Queue::fake();
    $user = consentedUser();
    $chat = Chat::factory()->for($user)->create();
    ChatMessage::factory()->for($chat)->create();
    $failed = ChatMessage::factory()->for($chat)->assistant()->create(['content' => '', 'status' => ChatMessageStatus::Failed, 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

    app(RetryLastMessage::class)->execute($user, $chat);

    expect(fn () => app(MarkReplyUndelivered::class)->execute($user, $chat))->toThrow(AssistantRefusedException::class)
        ->and($failed->fresh()->status)->toBe(ChatMessageStatus::Pending);
});

it('serialises jobs per message and stops the queue retrying a failed message', function () {
    $job = new GenerateAssistantReplyJob(User::factory()->create(), 5);

    expect($job->maxExceptions)->toBe(1)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and(collect($job->middleware())->contains(fn (object $m): bool => $m instanceof Illuminate\Queue\Middleware\WithoutOverlapping))->toBeTrue();
});
