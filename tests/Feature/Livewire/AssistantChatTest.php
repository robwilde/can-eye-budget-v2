<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Console\Commands\SchedulerHeartbeatCommand;
use App\Enums\AssistantErrorKind;
use App\Enums\AuditOutcome;
use App\Enums\ChatMessageStatus;
use App\Jobs\GenerateAssistantReplyJob;
use App\Livewire\AssistantChat;
use App\Models\AuditEvent;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\WorkerHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    config(['budget.ai_assistant' => true, 'services.ai.provider' => 'openai', 'services.openai.key' => 'sk-test']);
    fakeWorker(true);
});

function fakeWorker(bool $running): void
{
    $repository = Mockery::mock(MasterSupervisorRepository::class);
    $repository->shouldReceive('all')->andReturn($running ? [(object) ['status' => 'running']] : []);
    app()->instance(MasterSupervisorRepository::class, $repository);
    app()->forgetInstance(WorkerHealth::class);
    Cache::flush();
    Cache::put(SchedulerHeartbeatCommand::CACHE_KEY, time(), 600);
}

function chatUser(bool $consented = true): User
{
    $user = User::factory()->create();

    if ($consented) {
        $user->grantAiConsent();
    }

    return $user->fresh();
}

it('shows the assistant page and the sidebar link only when the flag is on', function () {
    $user = chatUser();

    $this->actingAs($user)->get(route('assistant'))->assertOk()->assertSeeLivewire(AssistantChat::class);
    $this->actingAs($user)->get(route('dashboard'))->assertSee(route('assistant'), false);

    config(['budget.ai_assistant' => false]);

    $this->actingAs($user)->get(route('dashboard'))->assertDontSee(route('assistant'), false);
    $this->get(route('assistant'))->assertOk();
});

it('sends guests to login', function () {
    $this->get(route('assistant'))->assertRedirect(route('login'));
});

it('redirects an unconsented user to the consent screen without creating a message', function () {
    $user = chatUser(false);

    Livewire::actingAs($user)->test(AssistantChat::class)
        ->assertSee('data-test="assistant-consent"', false)
        ->set('draft', 'hello')
        ->call('send')
        ->assertRedirect(route('assistant'));

    expect(ChatMessage::query()->count())->toBe(0)->and(Chat::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('records consent from the consent screen and then shows the chat', function () {
    $user = chatUser(false);

    Livewire::actingAs($user)->test(AssistantChat::class)->call('consent')->assertSee('data-test="new-chat-button"', false);

    expect($user->fresh()->hasAiConsent())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'livewire.assistant-chat.consent')->sole()->user_id)->toBe($user->id);
});

it('does not let an unconsented user create or manage chats', function () {
    $user = chatUser(false);

    Livewire::actingAs($user)->test(AssistantChat::class)->call('newChat')->assertRedirect(route('assistant'));

    expect(Chat::query()->count())->toBe(0);
});

it('shows the assistant as unavailable without a provider key', function () {
    config(['services.openai.key' => null]);
    $user = chatUser();

    Livewire::actingAs($user)->test(AssistantChat::class)
        ->assertSee('data-test="assistant-unavailable"', false)
        ->set('draft', 'hello')
        ->call('send');

    expect(ChatMessage::query()->count())->toBe(0)->and(Chat::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('sends a message, creates the chat from it and queues the reply', function () {
    $user = chatUser();

    $component = Livewire::actingAs($user)->test(AssistantChat::class)
        ->set('draft', 'How much did I spend on food this month?')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('draft', '')
        ->assertSee('Thinking…');

    $chat = Chat::query()->sole();

    expect($chat->user_id)->toBe($user->id)->and($chat->title)->toBe('How much did I spend on food this month?')
        ->and(ChatMessage::query()->count())->toBe(2)
        ->and($user->fresh()->preferences->lastViewedChatId)->toBe($chat->id);
    Queue::assertPushed(GenerateAssistantReplyJob::class, 1);
});

it('records an audit event when a message is sent', function () {
    $user = chatUser();

    Livewire::actingAs($user)->test(AssistantChat::class)->set('draft', 'hi')->call('send');

    $event = AuditEvent::query()->where('action', 'livewire.assistant-chat.send')->sole();

    expect($event->user_id)->toBe($user->id)->and($event->outcome)->toBe(AuditOutcome::Success);
});

it('refuses an empty or oversized draft', function (string $draft) {
    $user = chatUser();

    Livewire::actingAs($user)->test(AssistantChat::class)->set('draft', $draft)->call('send')->assertHasErrors(['draft']);

    expect(ChatMessage::query()->count())->toBe(0);
})->with(['empty' => [''], 'spaces' => ['   '], 'too long' => [str_repeat('a', 4001)]]);

it('refuses a second message while a reply is pending', function () {
    $user = chatUser();
    $component = Livewire::actingAs($user)->test(AssistantChat::class)->set('draft', 'one')->call('send');

    $component->set('draft', 'two')->call('send')->assertHasErrors(['draft']);

    expect(ChatMessage::query()->count())->toBe(2);
});

it('refuses to rename or delete another user\'s chat', function () {
    $owner = chatUser();
    $other = chatUser();
    $chat = Chat::factory()->for($owner)->create(['title' => 'Private']);
    $component = Livewire::actingAs($owner)->test(AssistantChat::class)->assertSet('chatId', $chat->id);

    $this->actingAs($other);
    $component->set('renameTitle', 'Hacked')->call('rename')->call('delete');

    expect($chat->fresh()->title)->toBe('Private')->and(Chat::query()->whereKey($chat->id)->exists())->toBeTrue();
});

it('refuses every write action without consent', function (string $method, array $args) {
    $user = chatUser();
    $chat = Chat::factory()->for($user)->create(['title' => 'Keep']);
    $reply = ChatMessage::factory()->for($chat)->assistant()->create(['content' => '', 'status' => ChatMessageStatus::Pending]);
    $component = Livewire::actingAs($user)->test(AssistantChat::class);
    $user->revokeAiConsent();

    $component->set('renameTitle', 'Changed')->call($method, ...$args)->assertRedirect(route('assistant'));

    expect($chat->fresh()->title)->toBe('Keep')->and($reply->fresh()->status)->toBe(ChatMessageStatus::Pending);
})->with([
    'select' => ['selectChat', [1]],
    'start rename' => ['startRename', []],
    'rename' => ['rename', []],
    'delete' => ['delete', []],
    'mark undelivered' => ['markUndelivered', [1]],
    'retry' => ['retry', []],
    'new chat' => ['newChat', []],
]);

it('only marks the reply the page was timing, not a newer one', function () {
    config(['budget.ai_reply_min_age_seconds' => 60]);
    $user = chatUser();
    $chat = Chat::factory()->for($user)->create();
    ChatMessage::factory()->for($chat)->create();
    $old = ChatMessage::factory()->for($chat)->assistant()->create(['content' => 'done']);
    ChatMessage::factory()->for($chat)->create();
    $current = ChatMessage::factory()->for($chat)->assistant()->create(['content' => '', 'status' => ChatMessageStatus::Pending]);
    $this->travel(120)->seconds();

    Livewire::actingAs($user)->test(AssistantChat::class)->call('markUndelivered', $old->id);

    expect($current->fresh()->status)->toBe(ChatMessageStatus::Pending);

    Livewire::actingAs($user)->test(AssistantChat::class)->call('markUndelivered', $current->id);

    expect($current->fresh()->status)->toBe(ChatMessageStatus::Undelivered);
});

it('rejects a draft or title made only of Unicode blanks and creates no chat', function () {
    $user = chatUser();
    $chat = Chat::factory()->for($user)->create(['title' => 'Keep']);

    Livewire::actingAs($user)->test(AssistantChat::class)->set('draft', "\u{00A0}\u{3000}")->call('send')->assertHasErrors(['draft']);
    Livewire::actingAs($user)->test(AssistantChat::class)->call('startRename')->set('renameTitle', "\u{00A0}")->call('rename')->assertHasErrors(['renameTitle']);

    expect(Chat::query()->count())->toBe(1)->and($chat->fresh()->title)->toBe('Keep');
});

it('refuses to select another user\'s chat and keeps the current one', function () {
    $owner = chatUser();
    $other = chatUser();
    $theirs = Chat::factory()->for($owner)->create();
    $mine = Chat::factory()->for($other)->create();

    Livewire::actingAs($other)->test(AssistantChat::class)->call('selectChat', $theirs->id)->assertSet('chatId', $mine->id);

    expect($other->fresh()->preferences->lastViewedChatId)->not->toBe($theirs->id);
});

it('renames and deletes the user\'s own chat', function () {
    $user = chatUser();
    $chat = Chat::factory()->for($user)->create(['title' => 'Old']);
    ChatMessage::factory()->for($chat)->create();

    $component = Livewire::actingAs($user)->test(AssistantChat::class)
        ->call('startRename')
        ->set('renameTitle', 'New name')
        ->call('rename')
        ->assertHasNoErrors()
        ->assertSee('New name');

    expect($chat->fresh()->title)->toBe('New name');

    $component->call('delete')->assertSet('chatId', null);

    expect(Chat::query()->count())->toBe(0)->and(ChatMessage::query()->count())->toBe(0)->and($user->fresh()->preferences->lastViewedChatId)->toBeNull();
});

it('refuses an empty chat title', function () {
    $user = chatUser();
    $chat = Chat::factory()->for($user)->create(['title' => 'Keep']);

    Livewire::actingAs($user)->test(AssistantChat::class)->call('startRename')->set('renameTitle', '')->call('rename')->assertHasErrors(['renameTitle']);

    expect($chat->fresh()->title)->toBe('Keep');
});

it('reopens the last chat the user viewed', function () {
    $user = chatUser();
    $first = Chat::factory()->for($user)->create(['title' => 'First chat', 'last_message_at' => now()]);
    $second = Chat::factory()->for($user)->create(['title' => 'Second chat', 'last_message_at' => now()->subDay()]);
    $user->updatePreferences(['lastViewedChatId' => $second->id]);

    Livewire::actingAs($user->fresh())->test(AssistantChat::class)->assertSet('chatId', $second->id)->assertSee('Second chat');

    $user->updatePreferences(['lastViewedChatId' => null]);

    Livewire::actingAs($user->fresh())->test(AssistantChat::class)->assertSet('chatId', $first->id);
});

it('ignores a remembered chat that belongs to someone else or no longer exists', function () {
    $user = chatUser();
    $foreign = Chat::factory()->create();
    $mine = Chat::factory()->for($user)->create();
    $user->updatePreferences(['lastViewedChatId' => $foreign->id]);

    Livewire::actingAs($user->fresh())->test(AssistantChat::class)->assertSet('chatId', $mine->id);

    $user->updatePreferences(['lastViewedChatId' => 99999]);

    Livewire::actingAs($user->fresh())->test(AssistantChat::class)->assertSet('chatId', $mine->id);
});

it('refuses an undelivered report before the reply reaches its minimum age', function () {
    config(['budget.ai_reply_min_age_seconds' => 60]);
    $user = chatUser();
    $chat = Chat::factory()->for($user)->create();
    ChatMessage::factory()->for($chat)->create();
    $reply = ChatMessage::factory()->for($chat)->assistant()->create(['content' => '', 'status' => ChatMessageStatus::Pending]);

    Livewire::actingAs($user)->test(AssistantChat::class)->call('markUndelivered', $reply->id)->assertSee('Thinking…');

    expect($reply->fresh()->status)->toBe(ChatMessageStatus::Pending);

    $this->travel(61)->seconds();

    Livewire::actingAs($user)->test(AssistantChat::class)->call('markUndelivered', $reply->id)->assertSee('The reply did not arrive.');

    expect($reply->fresh()->status)->toBe(ChatMessageStatus::Undelivered);
});

it('shows a rate-limited error with a retry button that re-queues the reply', function () {
    $user = chatUser();
    $chat = Chat::factory()->for($user)->create();
    ChatMessage::factory()->for($chat)->create();
    $reply = ChatMessage::factory()->for($chat)->assistant()->create(['content' => '', 'status' => ChatMessageStatus::Failed, 'error_kind' => AssistantErrorKind::RateLimited]);

    Livewire::actingAs($user)->test(AssistantChat::class)
        ->assertSee('too many requests')
        ->assertSee('data-test="retry-button"', false)
        ->call('retry')
        ->assertDontSee('data-test="retry-button"', false);

    expect($reply->fresh()->status)->toBe(ChatMessageStatus::Pending);
    Queue::assertPushed(GenerateAssistantReplyJob::class, fn (GenerateAssistantReplyJob $job): bool => $job->messageId === $reply->id);
});

it('shows distinct copy for each error kind', function (AssistantErrorKind $kind, string $copy) {
    $user = chatUser();
    $chat = Chat::factory()->for($user)->create();
    ChatMessage::factory()->for($chat)->create();
    ChatMessage::factory()->for($chat)->assistant()->create(['content' => '', 'status' => ChatMessageStatus::Failed, 'error_kind' => $kind]);

    Livewire::actingAs($user)->test(AssistantChat::class)->assertSee($copy);
})->with([
    [AssistantErrorKind::RateLimited, 'too many requests'],
    [AssistantErrorKind::Unavailable, 'not responding'],
    [AssistantErrorKind::Misconfigured, 'not set up correctly'],
    [AssistantErrorKind::Generic, 'Something went wrong'],
]);

it('does not offer retry for a complete reply', function () {
    $user = chatUser();
    $chat = Chat::factory()->for($user)->create();
    ChatMessage::factory()->for($chat)->create();
    ChatMessage::factory()->for($chat)->assistant()->create(['content' => 'All good']);

    Livewire::actingAs($user)->test(AssistantChat::class)->assertSee('All good')->assertDontSee('data-test="retry-button"', false);
});

it('polls only while a reply is pending and does not audit the poll', function () {
    $user = chatUser();
    $chat = Chat::factory()->for($user)->create();
    ChatMessage::factory()->for($chat)->create();
    $reply = ChatMessage::factory()->for($chat)->assistant()->create(['content' => '', 'status' => ChatMessageStatus::Pending]);

    $component = Livewire::actingAs($user)->test(AssistantChat::class)->assertSee('wire:poll.2s', false);
    $reply->update(['status' => ChatMessageStatus::Complete, 'content' => 'Here you go']);

    $component->call('pollReply')->assertSee('Here you go')->assertDontSee('wire:poll.2s', false);

    expect(AuditEvent::query()->where('action', 'livewire.assistant-chat.pollReply')->count())->toBe(0);
});

it('never shows another user\'s chats', function () {
    $user = chatUser();
    Chat::factory()->for($user)->create(['title' => 'Mine only']);
    Chat::factory()->create(['title' => 'Somebody else']);

    Livewire::actingAs($user)->test(AssistantChat::class)->assertSee('Mine only')->assertDontSee('Somebody else');
});

it('warns that replies may be slow when the worker is unhealthy', function () {
    fakeWorker(false);

    Livewire::actingAs(chatUser())->test(AssistantChat::class)->assertSee('Replies may be slower than usual');
});

it('keeps the client timeout at least 30 seconds', function () {
    config(['budget.ai_reply_timeout_seconds' => 5, 'budget.ai_reply_min_age_seconds' => 10]);

    $component = Livewire::actingAs(chatUser())->test(AssistantChat::class);

    expect($component->instance()->clientTimeoutSeconds)->toBe(30);

    config(['budget.ai_reply_timeout_seconds' => 120]);

    expect(Livewire::actingAs(chatUser())->test(AssistantChat::class)->instance()->clientTimeoutSeconds)->toBe(120);

    config(['budget.ai_reply_timeout_seconds' => 40, 'budget.ai_reply_min_age_seconds' => 60]);

    expect(Livewire::actingAs(chatUser())->test(AssistantChat::class)->instance()->clientTimeoutSeconds)->toBe(61);
});
