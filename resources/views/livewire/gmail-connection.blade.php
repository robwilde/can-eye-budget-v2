<div class="space-y-6" data-test="gmail-connection">
    <div>
        <flux:heading size="lg">{{ __('Gmail') }}</flux:heading>
        <flux:subheading>
            {{ __('Connect your own mailbox so Can Eye can find BNPL schedules and receipts. Only you can use it.') }}
        </flux:subheading>
    </div>

    @if ($this->credential)
        <div class="flex flex-wrap items-center gap-3">
            <flux:badge color="green" size="sm" data-test="gmail-status">{{ __('Connected') }}</flux:badge>

            <flux:text size="sm">{{ $this->credential->username }}</flux:text>

            <flux:text size="sm" class="text-zinc-500">
                @if ($this->credential->last_verified_at)
                    {{ __('Last checked :time', ['time' => $this->credential->last_verified_at->diffForHumans()]) }}
                @else
                    {{ __('Never checked') }}
                @endif
            </flux:text>
        </div>

        @if ($testMessage)
            <flux:callout :variant="$testPassed ? 'success' : 'danger'" :icon="$testPassed ? 'check-circle' : 'exclamation-triangle'"
                          data-test="gmail-test-result">
                <flux:callout.text>{{ $testMessage }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex items-center gap-4">
            <flux:button wire:click="test" variant="filled" data-test="test-gmail-button">
                {{ __('Test connection') }}
            </flux:button>

            <flux:modal.trigger name="confirm-gmail-disconnect">
                <flux:button variant="subtle" data-test="disconnect-gmail-button">
                    {{ __('Disconnect') }}
                </flux:button>
            </flux:modal.trigger>

            <x-action-message class="me-3" on="gmail-saved">
                {{ __('Saved.') }}
            </x-action-message>
        </div>
    @endif

    <form wire:submit="connect" class="space-y-6">
        <flux:input
            wire:model="username"
            :label="__('Gmail address')"
            type="email"
            placeholder="you@gmail.com"
            autocomplete="email"
            required
        />

        <flux:input
            wire:model="app_password"
            :label="__('App password')"
            type="password"
            autocomplete="new-password"
            :description="__('Create one at myaccount.google.com/apppasswords. Google requires 2-Step Verification first. It is checked before it is saved, and stored encrypted.')"
            viewable
            required
        />

        <div class="flex items-center gap-4">
            <flux:button variant="primary" type="submit" data-test="save-gmail-button">
                {{ $this->credential ? __('Update credentials') : __('Connect Gmail') }}
            </flux:button>

            @unless ($this->credential)
                <x-action-message class="me-3" on="gmail-saved">
                    {{ __('Saved.') }}
                </x-action-message>
            @endunless
        </div>
    </form>

    <flux:modal name="confirm-gmail-disconnect" class="md:w-96">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Disconnect Gmail?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Receipts you already linked are kept. The stored app password is deleted, and BNPL scans skip you until you reconnect.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:spacer />

                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button wire:click="disconnect" variant="danger" data-test="confirm-disconnect-gmail-button">
                    {{ __('Disconnect') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
