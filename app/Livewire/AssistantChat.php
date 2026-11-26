<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\Assistant\MarkReplyUndelivered;
use App\Actions\Assistant\RetryLastMessage;
use App\Actions\Assistant\SendChatMessage;
use App\Enums\AssistantErrorKind;
use App\Enums\ChatMessageRole;
use App\Enums\ChatMessageStatus;
use App\Exceptions\Assistant\AssistantRefusedException;
use App\Livewire\Attributes\NotAudited;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Assistant\AssistantAvailability;
use App\Services\WorkerHealth;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The assistant page: consent, the user's private chats, and a reply that arrives behind a "Thinking…" bubble.
 * Every write re-checks that the chat belongs to the signed-in user, because the locked chat id is only a
 * convenience for the page and never an authority.
 *
 * @property-read Chat|null $chat
 * @property-read Collection<int, ChatMessage> $thread
 * @property-read ChatMessage|null $lastReply
 * @property-read ChatMessage|null $pendingReply
 * @property-read bool $consented
 * @property-read bool $available
 * @property-read bool $workerDelayed
 * @property-read int $clientTimeoutSeconds
 */
final class AssistantChat extends Component
{
    public const int MIN_CLIENT_TIMEOUT_SECONDS = 30;

    public const int TITLE_LENGTH = 60;

    #[Locked]
    public ?int $chatId = null;

    public string $draft = '';

    public string $renameTitle = '';

    public bool $renaming = false;

    public function mount(): void
    {
        $user = $this->user();
        $remembered = $user->preferences->lastViewedChatId;

        $chat = $remembered !== null ? $this->ownedChat($remembered) : null;
        $chat ??= Chat::query()->where('user_id', $user->id)->orderByRaw('last_message_at is null')->orderByDesc('last_message_at')->orderByDesc('id')->first();

        $this->chatId = $chat?->id;
    }

    /** @return Collection<int, Chat> */
    #[Computed]
    public function chats(): Collection
    {
        return Chat::query()->where('user_id', $this->user()->id)->orderByRaw('last_message_at is null')->orderByDesc('last_message_at')->orderByDesc('id')->limit(50)->get();
    }

    #[Computed]
    public function chat(): ?Chat
    {
        return $this->chatId === null ? null : $this->ownedChat($this->chatId);
    }

    /** @return Collection<int, ChatMessage> */
    #[Computed]
    public function thread(): Collection
    {
        $chat = $this->chat;

        return $chat === null ? new Collection : $chat->messages()->get();
    }

    #[Computed]
    public function lastReply(): ?ChatMessage
    {
        $last = $this->thread->last();

        return $last instanceof ChatMessage && $last->role === ChatMessageRole::Assistant ? $last : null;
    }

    #[Computed]
    public function pendingReply(): ?ChatMessage
    {
        $last = $this->lastReply;

        return $last !== null && $last->status === ChatMessageStatus::Pending ? $last : null;
    }

    #[Computed]
    public function consented(): bool
    {
        return $this->user()->hasAiConsent();
    }

    #[Computed]
    public function available(): bool
    {
        return app(AssistantAvailability::class)->available();
    }

    #[Computed]
    public function workerDelayed(): bool
    {
        return ! app(WorkerHealth::class)->healthy();
    }

    #[Computed]
    public function clientTimeoutSeconds(): int
    {
        return max(
            self::MIN_CLIENT_TIMEOUT_SECONDS,
            config()->integer('budget.ai_reply_min_age_seconds', 60) + 1,
            config()->integer('budget.ai_reply_timeout_seconds', 90),
        );
    }

    public function consent(): void
    {
        $this->user()->grantAiConsent();

        unset($this->consented);
    }

    public function newChat(): void
    {
        if (! $this->requireConsent()) {
            return;
        }

        $chat = Chat::query()->create(['user_id' => $this->user()->id, 'title' => __('New chat')]);

        $this->open($chat);
    }

    public function selectChat(int $chatId): void
    {
        if (! $this->requireConsent()) {
            return;
        }

        $chat = $this->ownedChat($chatId);

        if ($chat === null) {
            return;
        }

        $this->open($chat);
    }

    public function send(SendChatMessage $send): void
    {
        if (! $this->requireConsent() || ! $this->available) {
            return;
        }

        $this->draft = mb_trim($this->draft);

        $this->validate(['draft' => ['required', 'string', 'max:'.SendChatMessage::MAX_LENGTH]]);

        $user = $this->user();
        $chat = $this->chatId === null ? null : $this->ownedChat($this->chatId);

        if ($chat === null) {
            $chat = Chat::query()->create(['user_id' => $user->id, 'title' => Str::limit(mb_trim($this->draft), self::TITLE_LENGTH, '…')]);
            $this->open($chat);
        }

        try {
            $send->execute($user, $chat, $this->draft);
        } catch (AssistantRefusedException $exception) {
            $this->addError('draft', __($exception->getMessage()));

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('draft', __('The message could not be sent.'));

            return;
        }

        $this->draft = '';

        $this->forgetMessages();
    }

    public function retry(RetryLastMessage $retry): void
    {
        $chat = $this->ownedCurrentChat();

        if ($chat === null || ! $this->requireConsent()) {
            return;
        }

        try {
            $retry->execute($this->user(), $chat);
        } catch (AssistantRefusedException $exception) {
            $this->addError('draft', __($exception->getMessage()));
        }

        $this->forgetMessages();
    }

    public function startRename(): void
    {
        if (! $this->requireConsent()) {
            return;
        }

        $chat = $this->ownedCurrentChat();

        if ($chat === null) {
            return;
        }

        $this->renameTitle = $chat->title;
        $this->renaming = true;
    }

    public function rename(): void
    {
        if (! $this->requireConsent()) {
            return;
        }

        $chat = $this->ownedCurrentChat();

        if ($chat === null) {
            return;
        }

        $this->renameTitle = mb_trim($this->renameTitle);

        $this->validate(['renameTitle' => ['required', 'string', 'max:120']]);

        $chat->update(['title' => mb_trim($this->renameTitle)]);

        $this->renaming = false;

        unset($this->chats, $this->chat);
    }

    public function delete(): void
    {
        if (! $this->requireConsent()) {
            return;
        }

        $chat = $this->ownedCurrentChat();

        if ($chat === null) {
            return;
        }

        $chat->delete();

        $this->chatId = null;
        $this->renaming = false;
        $this->user()->updatePreferences(['lastViewedChatId' => null]);

        $this->forgetMessages();
        unset($this->chats);
    }

    public function markUndelivered(MarkReplyUndelivered $mark, int $messageId): void
    {
        if (! $this->requireConsent()) {
            return;
        }

        $chat = $this->ownedCurrentChat();

        if ($chat === null || $this->pendingReply?->id !== $messageId) {
            $this->forgetMessages();

            return;
        }

        try {
            $mark->execute($this->user(), $chat);
        } catch (AssistantRefusedException) {
            // still being written, or already settled: the next poll shows the current state
        }

        $this->forgetMessages();
    }

    #[NotAudited]
    public function pollReply(): void
    {
        $this->forgetMessages();
    }

    public function errorCopy(?AssistantErrorKind $kind): string
    {
        return match ($kind) {
            AssistantErrorKind::RateLimited => __('The assistant is getting too many requests right now. Wait a moment and try again.'),
            AssistantErrorKind::Unavailable => __('The assistant service is not responding. Try again in a little while.'),
            AssistantErrorKind::Misconfigured => __('The assistant is not set up correctly on this server. Ask your administrator to check the AI provider settings.'),
            default => __('Something went wrong while writing the reply. Try again.'),
        };
    }

    public function render(): View
    {
        return view('livewire.assistant-chat');
    }

    private function open(Chat $chat): void
    {
        $this->chatId = $chat->id;
        $this->renaming = false;
        $this->user()->updatePreferences(['lastViewedChatId' => $chat->id]);

        unset($this->chats);
        $this->forgetMessages();
    }

    private function requireConsent(): bool
    {
        if ($this->user()->hasAiConsent()) {
            return true;
        }

        $this->redirect(route('assistant'), navigate: true);

        return false;
    }

    private function ownedCurrentChat(): ?Chat
    {
        return $this->chatId === null ? null : $this->ownedChat($this->chatId);
    }

    private function ownedChat(int $chatId): ?Chat
    {
        return Chat::query()->where('user_id', $this->user()->id)->whereKey($chatId)->first();
    }

    private function forgetMessages(): void
    {
        unset($this->chat, $this->thread, $this->lastReply, $this->pendingReply);
    }

    private function user(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
