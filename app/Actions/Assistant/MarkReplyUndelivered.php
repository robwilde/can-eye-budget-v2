<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Exceptions\Assistant\AssistantRefusedException;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class MarkReplyUndelivered
{
    /**
     * Gives up on a reply that has been pending longer than budget.ai_reply_min_age_seconds. A younger one is refused.
     *
     * @throws AssistantRefusedException
     * @throws Throwable
     */
    public function execute(User $user, Chat $chat): ChatMessage
    {
        if ($chat->user_id !== $user->id) {
            throw new AssistantRefusedException('That chat is not yours.');
        }

        return DB::transaction(function () use ($chat): ChatMessage {
            $last = ChatMessage::query()->where('chat_id', $chat->id)->orderByDesc('id')->lockForUpdate()->first();

            if ($last === null || $last->role !== ChatMessageRole::Assistant || $last->status !== ChatMessageStatus::Pending) {
                throw new AssistantRefusedException('There is no pending reply.');
            }

            if ($last->updated_at->greaterThan(now()->subSeconds(config()->integer('budget.ai_reply_min_age_seconds', 120)))) {
                throw new AssistantRefusedException('The reply is still being written.');
            }

            $last->update(['status' => ChatMessageStatus::Undelivered]);

            return $last;
        });
    }
}
