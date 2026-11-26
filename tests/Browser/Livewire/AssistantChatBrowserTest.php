<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

use App\Enums\ChatMessageStatus;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('a user consents, asks a question and sees the thinking bubble', function () {
    Queue::fake();
    config(['budget.ai_assistant' => true, 'services.ai.provider' => 'openai', 'services.openai.key' => 'sk-test']);
    $user = User::factory()->create();

    $this->actingAs($user);

    visit('/assistant')
        ->assertSee('Before you chat')
        ->screenshot(filename: 'assistant-consent')
        ->click('[data-test="assistant-consent-button"]')
        ->wait(1)
        ->fill('[data-test="chat-input"]', 'How much did I spend on food?')
        ->click('[data-test="send-button"]')
        ->wait(1)
        ->assertSee('Thinking…')
        ->screenshot(filename: 'assistant-chat')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->hasAiConsent())->toBeTrue()->and(ChatMessage::query()->count())->toBe(2);
});

test('a failed reply shows its error and a retry button', function () {
    config(['budget.ai_assistant' => true, 'services.ai.provider' => 'openai', 'services.openai.key' => 'sk-test']);
    $user = User::factory()->create();
    $user->grantAiConsent();
    $chat = Chat::factory()->for($user)->create(['title' => 'Spending']);
    ChatMessage::factory()->for($chat)->create(['content' => 'Question']);
    ChatMessage::factory()->for($chat)->assistant()->create(['content' => '', 'status' => ChatMessageStatus::Failed, 'error_kind' => App\Enums\AssistantErrorKind::RateLimited]);

    $this->actingAs($user);

    visit('/assistant')
        ->assertSee('too many requests')
        ->assertVisible('[data-test="retry-button"]')
        ->screenshot(filename: 'assistant-error')
        ->assertNoJavaScriptErrors();
});
