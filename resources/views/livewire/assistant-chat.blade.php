<div class="mx-auto flex w-full max-w-5xl flex-col gap-6 lg:flex-row" data-test="assistant-chat">
    @if (! $this->available)
        <div class="w-full space-y-3" data-test="assistant-unavailable">
            <flux:heading size="xl">{{ __('Assistant') }}</flux:heading>
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('The assistant is not available') }}</flux:callout.heading>
                <flux:callout.text>{{ __('It is switched off or has no AI provider configured on this server.') }}</flux:callout.text>
            </flux:callout>
        </div>
    @elseif (! $this->consented)
        <div class="w-full space-y-4" data-test="assistant-consent">
            <flux:heading size="xl">{{ __('Before you chat') }}</flux:heading>
            <flux:text>{{ __('The assistant sends your questions, and the parts of your budget data it needs to answer them, to an AI provider. Your chats stay private to you. You can stop at any time by asking your administrator to turn the assistant off.') }}</flux:text>
            <flux:text>{{ __('Only continue if you are comfortable with that.') }}</flux:text>
            <flux:button variant="primary" wire:click="consent" data-test="assistant-consent-button">{{ __('I agree, start chatting') }}</flux:button>
        </div>
    @else
        <aside class="w-full shrink-0 space-y-3 lg:w-64">
            <flux:button variant="primary" class="w-full" wire:click="newChat" data-test="new-chat-button">{{ __('New chat') }}</flux:button>
            <div class="space-y-1" data-test="chat-list">
                @forelse ($this->chats as $item)
                    <button type="button" wire:click="selectChat({{ $item->id }})" wire:key="chat-{{ $item->id }}" data-test="chat-item"
                            class="block w-full truncate rounded-lg border-2 px-3 py-2 text-start text-sm {{ $item->id === $chatId ? 'border-cib-black bg-cib-yellow-400 font-bold' : 'border-transparent hover:bg-zinc-100' }}">
                        {{ $item->title }}
                    </button>
                @empty
                    <flux:text size="sm">{{ __('No chats yet.') }}</flux:text>
                @endforelse
            </div>
        </aside>

        <section class="min-w-0 flex-1 space-y-4" @if ($this->pendingReply) wire:poll.2s="pollReply" @endif>
            @if ($this->workerDelayed)
                <flux:callout variant="warning" icon="clock" data-test="assistant-delayed">
                    <flux:callout.text>{{ __('Replies may be slower than usual right now.') }}</flux:callout.text>
                </flux:callout>
            @endif

            @if ($this->chat)
                <div class="flex items-center justify-between gap-3">
                    @if ($renaming)
                        <form wire:submit="rename" class="flex flex-1 items-center gap-2">
                            <flux:input wire:model="renameTitle" maxlength="120" data-test="rename-input" class="flex-1" />
                            <flux:button type="submit" size="sm" variant="primary" data-test="rename-save">{{ __('Save') }}</flux:button>
                        </form>
                        @error('renameTitle') <flux:text size="sm" class="text-red-600">{{ $message }}</flux:text> @enderror
                    @else
                        <flux:heading size="lg" data-test="chat-title">{{ $this->chat->title }}</flux:heading>
                        <div class="flex gap-2">
                            <flux:button size="sm" variant="ghost" wire:click="startRename" data-test="rename-button">{{ __('Rename') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="delete" wire:confirm="{{ __('Delete this chat and its messages?') }}" data-test="delete-chat-button">{{ __('Delete') }}</flux:button>
                        </div>
                    @endif
                </div>
            @endif

            <div class="space-y-3" data-test="messages">
                @foreach ($this->thread as $message)
                    <div wire:key="message-{{ $message->id }}" data-test="message-{{ $message->role->value }}"
                         class="max-w-[85%] whitespace-pre-wrap text-start rounded-xl border-2 border-cib-black px-4 py-2 text-sm {{ $message->role === App\Enums\ChatMessageRole::User ? 'ms-auto bg-cib-yellow-400' : 'bg-white' }}">
                        @if ($message->status === App\Enums\ChatMessageStatus::Pending)
                            <span data-test="thinking" class="italic text-zinc-500"
                                  x-data="{ timer: null, destroy() { clearTimeout(this.timer) } }"
                                  x-init="clearTimeout(timer); timer = setTimeout(() => $wire.markUndelivered({{ $message->id }}), {{ max(1, $this->clientTimeoutSeconds - (int) $message->updated_at->diffInSeconds(now(), true)) * 1000 }})">{{ __('Thinking…') }}</span>
                        @elseif ($message->status === App\Enums\ChatMessageStatus::Failed)
                            <span data-test="reply-error" class="text-red-700">{{ $this->errorCopy($message->error_kind) }}</span>
                        @elseif ($message->status === App\Enums\ChatMessageStatus::Undelivered)
                            <span data-test="reply-undelivered" class="text-zinc-600">{{ __('The reply did not arrive.') }}</span>
                        @else
                            {{ $message->content }}
                        @endif
                    </div>
                @endforeach
            </div>

            @if ($this->lastReply && in_array($this->lastReply->status, [App\Enums\ChatMessageStatus::Failed, App\Enums\ChatMessageStatus::Undelivered], true))
                <flux:button size="sm" wire:click="retry" data-test="retry-button">{{ __('Retry') }}</flux:button>
            @endif

            <form wire:submit="send" class="space-y-2">
                <flux:textarea wire:model="draft" rows="3" maxlength="{{ App\Actions\Assistant\SendChatMessage::MAX_LENGTH }}" :placeholder="__('Ask about your budget')" data-test="chat-input" />
                @error('draft') <flux:text size="sm" class="text-red-600" data-test="chat-error">{{ $message }}</flux:text> @enderror
                <flux:button type="submit" variant="primary" data-test="send-button">{{ __('Send') }}</flux:button>
            </form>
        </section>
    @endif
</div>
