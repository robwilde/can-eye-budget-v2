<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\LlmUsage;
use App\Models\User;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('deletes a user\'s chats, messages and usage with the user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $chat = Chat::factory()->for($user)->create();
    $message = ChatMessage::factory()->for($chat)->create();
    LlmUsage::factory()->for($user)->create(['chat_message_id' => $message->id]);
    $keptChat = Chat::factory()->for($other)->create();
    ChatMessage::factory()->for($keptChat)->create();
    LlmUsage::factory()->for($other)->create();

    $user->delete();

    expect(Chat::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(ChatMessage::query()->where('chat_id', $chat->id)->count())->toBe(0)
        ->and(LlmUsage::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Chat::query()->whereKey($keptChat->id)->exists())->toBeTrue()
        ->and(LlmUsage::query()->where('user_id', $other->id)->count())->toBe(1);
});

it('deletes a chat\'s messages with the chat', function () {
    $user = User::factory()->create();
    $chat = Chat::factory()->for($user)->create();
    $message = ChatMessage::factory()->for($chat)->create();
    $usage = LlmUsage::factory()->for($user)->create(['chat_message_id' => $message->id]);

    $chat->delete();

    expect(ChatMessage::query()->count())->toBe(0)
        ->and($usage->fresh()->chat_message_id)->toBeNull()
        ->and(LlmUsage::query()->count())->toBe(1);
});

it('lists only the owner\'s chats', function () {
    $user = User::factory()->create();
    $mine = Chat::factory()->for($user)->create();
    Chat::factory()->for(User::factory()->create())->create();

    expect($user->chats->pluck('id')->all())->toBe([$mine->id])
        ->and($user->llmUsages)->toHaveCount(0);
});

it('stores LLM usage cost as integer cents', function () {
    $usage = LlmUsage::factory()->create(['cost' => 123]);

    expect($usage->fresh()->cost)->toBe(123)
        ->and(DB::table('llm_usages')->where('id', $usage->id)->value('cost'))->toBe(123);
});

it('keeps the AI assistant flag off by default', function () {
    $config = require config_path('budget.php');

    expect($config['ai_assistant'])->toBeFalse()
        ->and(config('budget.ai_assistant'))->toBeFalse();
});

it('keeps chat migration identifiers within the MariaDB limit', function () {
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('getAttribute')->andReturn('11.4.5-MariaDB');
    $connection = new MariaDbConnection($pdo, 'probe', '', ['name' => 'probe', 'driver' => 'mariadb']);
    $original = Schema::getFacadeRoot();
    Schema::swap($connection->getSchemaBuilder());

    try {
        $queries = $connection->pretend(function (): void {
            foreach (glob(database_path('migrations/*_create_{chats,chat_messages,llm_usages}_table.php'), GLOB_BRACE) as $path) {
                (require $path)->up();
            }
        });
    } finally {
        Schema::swap($original);
    }

    $identifiers = collect($queries)->flatMap(fn (array $q) => preg_match_all('/`([^`]+)`/', $q['query'], $m) ? $m[1] : []);

    expect($queries)->not->toBeEmpty()
        ->and($identifiers->filter(fn (string $i) => mb_strlen($i) > 64)->all())->toBe([])
        ->and($identifiers->contains('chats_user_last_message_index'))->toBeTrue();
});

it('casts message role, status and error kind to enums', function () {
    $message = ChatMessage::factory()->assistant()->create(['status' => ChatMessageStatus::Failed, 'error_kind' => App\Enums\AssistantErrorKind::RateLimited]);

    expect($message->fresh()->role)->toBe(ChatMessageRole::Assistant)
        ->and($message->fresh()->status)->toBe(ChatMessageStatus::Failed)
        ->and($message->fresh()->error_kind)->toBe(App\Enums\AssistantErrorKind::RateLimited);
});

it('keeps AI consent per user in the typed preferences', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    expect($user->hasAiConsent())->toBeFalse();

    $user->grantAiConsent();

    expect($user->fresh()->hasAiConsent())->toBeTrue()
        ->and($user->fresh()->preferences->aiConsentedAt)->not->toBeNull()
        ->and($other->fresh()->hasAiConsent())->toBeFalse();

    $user->fresh()->revokeAiConsent();

    expect($user->fresh()->hasAiConsent())->toBeFalse();
});

it('keeps other preferences when consent changes', function () {
    $user = User::factory()->withPreferences(['theme' => 'dark'])->create();

    $user->grantAiConsent();
    $user->fresh()->revokeAiConsent();

    expect($user->fresh()->preferences->theme)->toBe(App\Enums\Theme::Dark);
});

it('clears chats but keeps the usage ledger when the user resets their data', function () {
    $user = User::factory()->create();
    $chat = Chat::factory()->for($user)->create();
    ChatMessage::factory()->for($chat)->create();
    LlmUsage::factory()->for($user)->create();

    app(App\Services\UserDataResetter::class)->reset($user);

    expect(Chat::query()->count())->toBe(0)->and(ChatMessage::query()->count())->toBe(0)->and(LlmUsage::query()->count())->toBe(1);
});
