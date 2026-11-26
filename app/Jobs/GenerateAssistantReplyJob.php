<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AssistantErrorKind;
use App\Exceptions\Assistant\LlmException;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Assistant\AssistantReplyGenerator;
use App\Support\Audit\AttributeJobToUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the assistant's reply to one pending message. A rate-limited or unavailable provider is retried with a
 * delay (retries live here, never in the client); the last attempt, or any other failure, marks the message failed
 * with an error kind. The 85 s timeout stays under the queue connection's 90 s retry_after, so a running job is
 * never handed to a second worker, and one job runs per message at a time. Only ids and the error kind are logged.
 */
final class GenerateAssistantReplyJob implements ShouldQueue
{
    use Queueable;

    public const array BACKOFF_SECONDS = [10, 30];

    public int $tries = 3;

    public int $maxExceptions = 1;

    public int $timeout = 85;

    public function __construct(public readonly User $user, public readonly int $messageId) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new AttributeJobToUser($this->user->id),
            new WithoutOverlapping("assistant-reply-{$this->messageId}")->dontRelease()->expireAfter(90),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return self::BACKOFF_SECONDS;
    }

    public function handle(AssistantReplyGenerator $generator): void
    {
        try {
            $generator->generate($this->user, $this->messageId);
        } catch (LlmException $exception) {
            if ($exception->isRetryable() && ! $this->finalAttempt()) {
                $this->release(self::BACKOFF_SECONDS[min($this->attempts(), count(self::BACKOFF_SECONDS)) - 1] ?? 30);

                return;
            }

            $this->failMessage($generator, $exception->kind);
        } catch (Throwable $exception) {
            $this->failMessage($generator, AssistantErrorKind::Generic);

            throw $exception;
        }
    }

    private function finalAttempt(): bool
    {
        return $this->job === null || $this->job instanceof SyncJob || $this->attempts() >= $this->tries;
    }

    private function failMessage(AssistantReplyGenerator $generator, AssistantErrorKind $kind): void
    {
        $message = ChatMessage::query()->whereKey($this->messageId)->whereHas('chat', fn ($chat) => $chat->where('user_id', $this->user->id))->first();

        if ($message !== null) {
            $generator->fail($message, $kind);
        }

        Log::warning('Assistant reply failed', ['user_id' => $this->user->id, 'message_id' => $this->messageId, 'kind' => $kind->value]);
    }
}
