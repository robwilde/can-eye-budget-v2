<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Exceptions\Assistant\AssistantRefusedException;
use App\Jobs\GenerateAssistantReplyJob;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Assistant\AssistantAvailability;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class SendChatMessage
{
    public const int MAX_LENGTH = 4000;

    public function __construct(private AssistantAvailability $availability) {}

    /**
     * Stores the user's message and a pending assistant message in one transaction and queues the reply.
     *
     * @throws AssistantRefusedException
     * @throws Throwable
     */
    public function execute(User $user, Chat $chat, string $content): ChatMessage
    {
        $content = mb_trim($content);

        if (! $user->hasAiConsent()) {
            throw new AssistantRefusedException('AI consent is required before chatting with the assistant.');
        }

        if (! $this->availability->available()) {
            throw new AssistantRefusedException('The assistant is not available.');
        }

        if ($chat->user_id !== $user->id) {
            throw new AssistantRefusedException('That chat is not yours.');
        }

        if ($content === '' || mb_strlen($content) > self::MAX_LENGTH) {
            throw new AssistantRefusedException('A message must be between 1 and '.self::MAX_LENGTH.' characters.');
        }

        $reply = DB::transaction(function () use ($chat, $content): ChatMessage {
            Chat::query()->whereKey($chat->id)->lockForUpdate()->firstOrFail();

            $waiting = ChatMessage::query()->where('chat_id', $chat->id)->where('status', ChatMessageStatus::Pending)->exists();

            if ($waiting) {
                throw new AssistantRefusedException('Wait for the current reply first.');
            }

            ChatMessage::query()->create(['chat_id' => $chat->id, 'role' => ChatMessageRole::User, 'content' => $content, 'status' => ChatMessageStatus::Complete]);
            $reply = ChatMessage::query()->create(['chat_id' => $chat->id, 'role' => ChatMessageRole::Assistant, 'content' => '', 'status' => ChatMessageStatus::Pending]);
            $chat->update(['last_message_at' => now()]);

            return $reply;
        });

        GenerateAssistantReplyJob::dispatch($user, $reply->id);

        return $reply;
    }
}
