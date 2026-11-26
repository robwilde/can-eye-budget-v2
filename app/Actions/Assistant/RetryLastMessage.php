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

final readonly class RetryLastMessage
{
    public function __construct(private AssistantAvailability $availability) {}

    /**
     * Puts a failed or undelivered last reply back to pending and queues it again.
     *
     * @throws AssistantRefusedException
     * @throws Throwable
     */
    public function execute(User $user, Chat $chat): ChatMessage
    {
        if (! $user->hasAiConsent()) {
            throw new AssistantRefusedException('AI consent is required before chatting with the assistant.');
        }

        if (! $this->availability->available()) {
            throw new AssistantRefusedException('The assistant is not available.');
        }

        if ($chat->user_id !== $user->id) {
            throw new AssistantRefusedException('That chat is not yours.');
        }

        $reply = DB::transaction(function () use ($chat): ChatMessage {
            $last = ChatMessage::query()->where('chat_id', $chat->id)->orderByDesc('id')->lockForUpdate()->first();

            if ($last === null || $last->role !== ChatMessageRole::Assistant || ! in_array($last->status, [ChatMessageStatus::Failed, ChatMessageStatus::Undelivered], true)) {
                throw new AssistantRefusedException('There is nothing to retry.');
            }

            $last->update(['status' => ChatMessageStatus::Pending, 'content' => '', 'error_kind' => null]);

            return $last;
        });

        GenerateAssistantReplyJob::dispatch($user, $reply->id);

        return $reply;
    }
}
