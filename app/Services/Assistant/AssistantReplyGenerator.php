<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Contracts\LlmClientContract;
use App\DTOs\Llm\LlmResponse;
use App\Enums\AssistantErrorKind;
use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Enums\LlmOperation;
use App\Exceptions\Assistant\LlmException;
use App\Models\ChatMessage;
use App\Models\LlmUsage;
use App\Models\User;
use Throwable;

/**
 * Turns a pending assistant message into a reply. Everything is scoped to the user the job was dispatched
 * for: a message in another user's chat is left alone. Failed replies stay out of the history sent to the model,
 * one usage row is written per provider call (counts and cost only), and nothing here logs a prompt, a reply or a key.
 */
final readonly class AssistantReplyGenerator
{
    public function __construct(
        private LlmClientContract $client,
        private AssistantAvailability $availability,
        private AssistantPromptBuilder $prompts,
        private AssistantToolRegistry $tools,
        private LlmCostCalculator $costs,
    ) {}

    /**
     * @throws LlmException when the provider call fails; the caller decides whether to retry or fail the message
     * @throws Throwable
     */
    public function generate(User $user, int $messageId): void
    {
        $reply = ChatMessage::query()
            ->whereKey($messageId)
            ->whereHas('chat', fn ($chat) => $chat->where('user_id', $user->id))
            ->first();

        if ($reply === null || $reply->role !== ChatMessageRole::Assistant || $reply->status !== ChatMessageStatus::Pending) {
            return;
        }

        if (! $user->hasAiConsent()) {
            $this->fail($reply, AssistantErrorKind::Generic);

            return;
        }

        if (! $this->availability->available()) {
            $this->fail($reply, AssistantErrorKind::Misconfigured);

            return;
        }

        $tools = $this->tools->forUser($user);
        $definitions = $this->tools->definitions($tools);
        $conversation = $this->history($reply);
        $system = $this->prompts->build($user);
        $rounds = max(1, config()->integer('services.ai.max_tool_rounds', 5));

        for ($round = 1; ; $round++) {
            $response = $this->client->complete($system, $conversation, $definitions, $round < $rounds);

            $this->record($user, $reply, $response);

            if ($response->toolCalls === [] || $round >= $rounds) {
                break;
            }

            $conversation[] = ['role' => 'assistant', 'content' => $response->content, 'tool_calls' => $response->toolCalls];

            foreach ($response->toolCalls as $call) {
                $conversation[] = ['role' => 'tool', 'tool_call_id' => $call->id, 'name' => $call->name, 'content' => $this->run($user, $tools, $call->name, $call->arguments)];
            }
        }

        if (mb_trim($response->content) === '') {
            $this->fail($reply, AssistantErrorKind::Generic);

            return;
        }

        $completed = ChatMessage::query()
            ->whereKey($reply->id)
            ->where('status', ChatMessageStatus::Pending)
            ->update(['content' => $response->content, 'status' => ChatMessageStatus::Complete->value, 'error_kind' => null, 'model' => $response->model, 'updated_at' => now()]);

        if ($completed === 1) {
            $reply->chat->update(['last_message_at' => now()]);
        }
    }

    public function fail(ChatMessage $reply, AssistantErrorKind $kind): void
    {
        ChatMessage::query()
            ->whereKey($reply->id)
            ->where('status', ChatMessageStatus::Pending)
            ->update(['status' => ChatMessageStatus::Failed->value, 'error_kind' => $kind->value, 'updated_at' => now()]);
    }

    /**
     * The earlier messages of the chat that were completed, merged where the same role repeats.
     *
     * @return list<array<string, mixed>>
     */
    private function history(ChatMessage $reply): array
    {
        $conversation = [];

        $messages = ChatMessage::query()
            ->where('chat_id', $reply->chat_id)
            ->where('id', '<', $reply->id)
            ->where('status', ChatMessageStatus::Complete)
            ->orderBy('id')
            ->get(['role', 'content']);

        foreach ($messages as $message) {
            $last = array_key_last($conversation);

            if ($last !== null && $conversation[$last]['role'] === $message->role->value) {
                $conversation[$last]['content'] .= "\n\n".$message->content;

                continue;
            }

            $conversation[] = ['role' => $message->role->value, 'content' => $message->content];
        }

        return $conversation;
    }

    private function record(User $user, ChatMessage $reply, LlmResponse $response): void
    {
        LlmUsage::query()->create([
            'user_id' => $user->id,
            'chat_message_id' => $reply->id,
            'operation' => LlmOperation::Chat,
            'provider' => $this->client->provider(),
            'model' => $response->model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'cost' => $this->costs->cents($response->model, $response->promptTokens, $response->completionTokens),
        ]);
    }

    /**
     * @param  array<string, \App\Contracts\AssistantTool>  $tools
     * @param  array<string, mixed>  $arguments
     */
    private function run(User $user, array $tools, string $name, array $arguments): string
    {
        $tool = $tools[$name] ?? null;

        if ($tool === null) {
            return 'That is not available.';
        }

        try {
            return json_encode($tool->handle($user, $arguments), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (Throwable) {
            return 'That could not be done.';
        }
    }
}
